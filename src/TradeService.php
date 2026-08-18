<?php
declare(strict_types=1);

/* V8.18.1 - PDO Placeholder Hotfix */
final class TradeService
{
    public function __construct(private PDO $pdo) {}

    /**
     * Create a proposed trade after validating that it is still mutually useful
     * against the current inventories.
     *
     * Returns the proposal id. If the exact same proposal is already open,
     * returns the existing proposal id instead of creating a duplicate.
     */
    public function createProposal(
        int $initiatorPlayerId,
        int $otherPlayerId,
        int $giveCardId,
        int $giveQty,
        int $receiveCardId,
        int $receiveQty
    ): int {
        if ($initiatorPlayerId <= 0 || $otherPlayerId <= 0 || $initiatorPlayerId === $otherPlayerId) {
            throw new RuntimeException('Invalid players for trade.');
        }
        if ($giveCardId <= 0 || $receiveCardId <= 0 || $giveCardId === $receiveCardId) {
            throw new RuntimeException('Invalid cards for trade.');
        }
        if ($giveQty <= 0 || $receiveQty <= 0) {
            throw new RuntimeException('Trade quantities must be positive.');
        }

        $this->assertTradeStillAvailable(
            $initiatorPlayerId,
            $otherPlayerId,
            $giveCardId,
            $giveQty,
            $receiveCardId,
            $receiveQty
        );

        $existing = $this->pdo->prepare(
            'SELECT id
             FROM trade_proposals
             WHERE initiator_player_id = :initiator_player_id
               AND other_player_id = :other_player_id
               AND give_card_id = :give_card_id
               AND give_qty = :give_qty
               AND receive_card_id = :receive_card_id
               AND receive_qty = :receive_qty
               AND status = \'proposed\'
             ORDER BY id DESC
             LIMIT 1'
        );
        $existing->execute([
            ':initiator_player_id' => $initiatorPlayerId,
            ':other_player_id' => $otherPlayerId,
            ':give_card_id' => $giveCardId,
            ':give_qty' => $giveQty,
            ':receive_card_id' => $receiveCardId,
            ':receive_qty' => $receiveQty,
        ]);

        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            return (int)$existingId;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO trade_proposals (
                initiator_player_id,
                other_player_id,
                give_card_id,
                give_qty,
                receive_card_id,
                receive_qty,
                status
             ) VALUES (
                :initiator_player_id,
                :other_player_id,
                :give_card_id,
                :give_qty,
                :receive_card_id,
                :receive_qty,
                \'proposed\'
             )'
        );
        $stmt->execute([
            ':initiator_player_id' => $initiatorPlayerId,
            ':other_player_id' => $otherPlayerId,
            ':give_card_id' => $giveCardId,
            ':give_qty' => $giveQty,
            ':receive_card_id' => $receiveCardId,
            ':receive_qty' => $receiveQty,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function listForPlayer(int $playerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                tp.id,
                tp.initiator_player_id,
                initiator.display_name AS initiator_player_name,
                tp.other_player_id,
                other.display_name AS other_player_name,
                tp.give_card_id,
                give_card.name AS give_card_name,
                tp.give_qty,
                tp.receive_card_id,
                receive_card.name AS receive_card_name,
                tp.receive_qty,
                tp.status,
                tp.created_at,
                tp.updated_at
             FROM trade_proposals tp
             JOIN players initiator ON initiator.id = tp.initiator_player_id
             JOIN players other ON other.id = tp.other_player_id
             JOIN cards give_card ON give_card.id = tp.give_card_id
             JOIN cards receive_card ON receive_card.id = tp.receive_card_id
             WHERE tp.initiator_player_id = :initiator_player_id
                OR tp.other_player_id = :other_player_id
             ORDER BY
                CASE tp.status
                    WHEN \'proposed\' THEN 0
                    WHEN \'completed\' THEN 1
                    ELSE 2
                END,
                tp.updated_at DESC,
                tp.id DESC'
        );
        $stmt->execute([
            ':initiator_player_id' => $playerId,
            ':other_player_id' => $playerId,
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Cancel an open proposal. Either player participating in the trade can
     * cancel it.
     */
    public function cancelProposal(int $tradeId, int $actingPlayerId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE trade_proposals
             SET status = \'cancelled\'
             WHERE id = :trade_id
               AND status = \'proposed\'
               AND (
                    initiator_player_id = :initiator_player_id
                    OR other_player_id = :other_player_id
               )'
        );
        $stmt->execute([
            ':trade_id' => $tradeId,
            ':initiator_player_id' => $actingPlayerId,
            ':other_player_id' => $actingPlayerId,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Trade could not be cancelled.');
        }
    }

    /**
     * Complete a proposed trade and apply both sides of the exchange in one
     * transaction. Inventory and proposal status either all update or none do.
     */
    public function completeProposal(int $tradeId, int $actingPlayerId): void
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT
                    id,
                    initiator_player_id,
                    other_player_id,
                    give_card_id,
                    give_qty,
                    receive_card_id,
                    receive_qty,
                    status
                 FROM trade_proposals
                 WHERE id = :trade_id
                 FOR UPDATE'
            );
            $stmt->execute([':trade_id' => $tradeId]);
            $trade = $stmt->fetch();

            if (!$trade) {
                throw new RuntimeException('Trade proposal was not found.');
            }

            if (
                (int)$trade['initiator_player_id'] !== $actingPlayerId
                && (int)$trade['other_player_id'] !== $actingPlayerId
            ) {
                throw new RuntimeException('Player is not part of this trade.');
            }

            if ((string)$trade['status'] !== 'proposed') {
                throw new RuntimeException('Only proposed trades can be completed.');
            }

            $initiatorId = (int)$trade['initiator_player_id'];
            $otherId = (int)$trade['other_player_id'];
            $giveCardId = (int)$trade['give_card_id'];
            $giveQty = (int)$trade['give_qty'];
            $receiveCardId = (int)$trade['receive_card_id'];
            $receiveQty = (int)$trade['receive_qty'];

            // Revalidate immediately before changing inventories.
            $this->assertTradeStillAvailable(
                $initiatorId,
                $otherId,
                $giveCardId,
                $giveQty,
                $receiveCardId,
                $receiveQty
            );

            // Lock the four inventory rows in a stable order where they exist.
            $lock = $this->pdo->prepare(
                'SELECT player_id, card_id, owned_qty
                 FROM player_cards
                 WHERE (player_id = :p1 AND card_id IN (:c1a, :c1b))
                    OR (player_id = :p2 AND card_id IN (:c2a, :c2b))
                 ORDER BY player_id, card_id
                 FOR UPDATE'
            );
            $lock->execute([
                ':p1' => $initiatorId,
                ':c1a' => $giveCardId,
                ':c1b' => $receiveCardId,
                ':p2' => $otherId,
                ':c2a' => $giveCardId,
                ':c2b' => $receiveCardId,
            ]);
            $lock->fetchAll();

            $this->adjustInventory($initiatorId, $giveCardId, -$giveQty);
            $this->adjustInventory($otherId, $giveCardId, $giveQty);
            $this->adjustInventory($otherId, $receiveCardId, -$receiveQty);
            $this->adjustInventory($initiatorId, $receiveCardId, $receiveQty);

            $done = $this->pdo->prepare(
                'UPDATE trade_proposals
                 SET status = \'completed\'
                 WHERE id = :trade_id
                   AND status = \'proposed\''
            );
            $done->execute([':trade_id' => $tradeId]);

            if ($done->rowCount() !== 1) {
                throw new RuntimeException('Trade status changed before completion.');
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function assertTradeStillAvailable(
        int $initiatorPlayerId,
        int $otherPlayerId,
        int $giveCardId,
        int $giveQty,
        int $receiveCardId,
        int $receiveQty
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT
                give_card.required_qty AS give_required,
                receive_card.required_qty AS receive_required,
                COALESCE(my_give.owned_qty, 0) AS my_give_owned,
                COALESCE(their_receive.owned_qty, 0) AS their_receive_owned,
                COALESCE(their_give.owned_qty, 0) AS their_give_owned,
                COALESCE(my_receive.owned_qty, 0) AS my_receive_owned
             FROM cards give_card
             JOIN cards receive_card ON receive_card.id = :receive_card_id
             LEFT JOIN player_cards my_give
               ON my_give.player_id = :initiator_give_player_id
              AND my_give.card_id = give_card.id
             LEFT JOIN player_cards their_receive
               ON their_receive.player_id = :other_receive_player_id
              AND their_receive.card_id = give_card.id
             LEFT JOIN player_cards their_give
               ON their_give.player_id = :other_give_player_id
              AND their_give.card_id = receive_card.id
             LEFT JOIN player_cards my_receive
               ON my_receive.player_id = :initiator_receive_player_id
              AND my_receive.card_id = receive_card.id
             WHERE give_card.id = :give_card_id'
        );
        $stmt->execute([
            ':initiator_give_player_id' => $initiatorPlayerId,
            ':other_receive_player_id' => $otherPlayerId,
            ':other_give_player_id' => $otherPlayerId,
            ':initiator_receive_player_id' => $initiatorPlayerId,
            ':give_card_id' => $giveCardId,
            ':receive_card_id' => $receiveCardId,
        ]);

        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Trade cards were not found.');
        }

        $myGiveExtra = max((int)$row['my_give_owned'] - (int)$row['give_required'], 0);
        $theirNeed = max((int)$row['give_required'] - (int)$row['their_receive_owned'], 0);
        $theirGiveExtra = max((int)$row['their_give_owned'] - (int)$row['receive_required'], 0);
        $myNeed = max((int)$row['receive_required'] - (int)$row['my_receive_owned'], 0);

        if ($giveQty > min($myGiveExtra, $theirNeed)) {
            throw new RuntimeException('The give side of this trade is no longer available.');
        }
        if ($receiveQty > min($theirGiveExtra, $myNeed)) {
            throw new RuntimeException('The receive side of this trade is no longer available.');
        }
    }

    private function adjustInventory(int $playerId, int $cardId, int $delta): void
    {
        if ($delta >= 0) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO player_cards (player_id, card_id, owned_qty)
                 VALUES (:player_id, :card_id, :qty)
                 ON DUPLICATE KEY UPDATE
                    owned_qty = owned_qty + VALUES(owned_qty)'
            );
            $stmt->execute([
                ':player_id' => $playerId,
                ':card_id' => $cardId,
                ':qty' => $delta,
            ]);
            return;
        }

        $qty = abs($delta);
        $stmt = $this->pdo->prepare(
            'UPDATE player_cards
             SET owned_qty = owned_qty - :subtract_qty
             WHERE player_id = :player_id
               AND card_id = :card_id
               AND owned_qty >= :minimum_qty'
        );
        $stmt->execute([
            ':subtract_qty' => $qty,
            ':minimum_qty' => $qty,
            ':player_id' => $playerId,
            ':card_id' => $cardId,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Inventory changed and the trade can no longer be completed.');
        }
    }
}

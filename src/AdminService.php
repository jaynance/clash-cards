<?php
declare(strict_types=1);

final class AdminService
{
    public function __construct(private PDO $pdo) {}

    public function getPlayers(): array
    {
        $sql = "SELECT
                    p.id,
                    p.display_name,
                    p.created_at,
                    COUNT(pc.card_id) AS saved_card_rows,
                    COALESCE(SUM(pc.owned_qty), 0) AS total_owned,
                    SUM(CASE WHEN pc.owned_qty > c.required_qty THEN 1 ELSE 0 END) AS cards_with_extras,
                    SUM(CASE WHEN pc.owned_qty < c.required_qty THEN 1 ELSE 0 END) AS cards_needed
                FROM players p
                LEFT JOIN player_cards pc
                  ON pc.player_id = p.id
                LEFT JOIN cards c
                  ON c.id = pc.card_id
                GROUP BY p.id, p.display_name, p.created_at
                ORDER BY LOWER(p.display_name), p.id";

        return $this->pdo->query($sql)->fetchAll();
    }

    public function getPlayer(int $playerId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, display_name, created_at
             FROM players
             WHERE id = :player_id'
        );
        $stmt->execute([':player_id' => $playerId]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getPlayerCards(int $playerId): array
    {
        $sql = "SELECT
                    c.id,
                    c.name,
                    c.category,
                    c.required_qty,
                    COALESCE(pc.owned_qty, 0) AS owned_qty,
                    CASE
                        WHEN COALESCE(pc.owned_qty, 0) < c.required_qty
                        THEN c.required_qty - COALESCE(pc.owned_qty, 0)
                        ELSE 0
                    END AS need_qty,
                    CASE
                        WHEN COALESCE(pc.owned_qty, 0) > c.required_qty
                        THEN COALESCE(pc.owned_qty, 0) - c.required_qty
                        ELSE 0
                    END AS extra_qty,
                    pc.updated_at
                FROM cards c
                LEFT JOIN player_cards pc
                  ON pc.card_id = c.id
                 AND pc.player_id = :player_id
                ORDER BY
                    CASE c.category
                        WHEN 'Elixir' THEN 1
                        WHEN 'Dark Elixir' THEN 2
                        WHEN 'Builder Base' THEN 3
                        WHEN 'Super' THEN 4
                        ELSE 99
                    END,
                    c.name";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':player_id' => $playerId]);
        return $stmt->fetchAll();
    }

    public function getPlayerTradeCounts(int $playerId): array
    {
        // V8.17 introduced trade_proposals. If the migration has not been run,
        // keep the admin page usable and simply report zeros.
        try {
            $stmt = $this->pdo->prepare(
                "SELECT
                    SUM(status = 'proposed') AS proposed,
                    SUM(status = 'completed') AS completed,
                    SUM(status = 'cancelled') AS cancelled
                 FROM trade_proposals
                 WHERE initiator_player_id = :player_id
                    OR other_player_id = :player_id"
            );
            $stmt->execute([':player_id' => $playerId]);
            $row = $stmt->fetch() ?: [];

            return [
                'proposed' => (int)($row['proposed'] ?? 0),
                'completed' => (int)($row['completed'] ?? 0),
                'cancelled' => (int)($row['cancelled'] ?? 0),
            ];
        } catch (PDOException $e) {
            return ['proposed' => 0, 'completed' => 0, 'cancelled' => 0];
        }
    }

    public function deletePlayer(int $playerId): ?string
    {
        $player = $this->getPlayer($playerId);
        if (!$player) {
            return null;
        }

        $this->pdo->beginTransaction();

        try {
            // player_cards and V8.17 trade_proposals both use ON DELETE CASCADE.
            $stmt = $this->pdo->prepare(
                'DELETE FROM players WHERE id = :player_id'
            );
            $stmt->execute([':player_id' => $playerId]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Player could not be deleted.');
            }

            $this->pdo->commit();
            return (string)$player['display_name'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}

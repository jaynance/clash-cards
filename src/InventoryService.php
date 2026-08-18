<?php
declare(strict_types=1);

final class InventoryService
{
    public function __construct(private PDO $pdo) {}

    public function getOrCreatePlayer(string $displayName): int
    {
        $displayName = trim($displayName);
        if ($displayName === '') {
            throw new InvalidArgumentException('Display name is required.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO players (display_name)
             VALUES (?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $stmt->execute([$displayName]);

        return (int)$this->pdo->lastInsertId();
    }

    public function getPlayer(int $playerId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, display_name FROM players WHERE id = :player_id'
        );
        $stmt->execute([':player_id' => $playerId]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getCards(): array
    {
        return $this->pdo
            ->query(
                "SELECT id, name, category, required_qty
                 FROM cards
                 ORDER BY
                    CASE category
                        WHEN 'Elixir' THEN 1
                        WHEN 'Dark Elixir' THEN 2
                        WHEN 'Builder Base' THEN 3
                        WHEN 'Super' THEN 4
                        ELSE 99
                    END,
                    name"
            )
            ->fetchAll();
    }

    public function saveInventory(int $playerId, array $quantities): void
    {
        $sql = 'INSERT INTO player_cards (player_id, card_id, owned_qty)
                VALUES (:player_id, :card_id, :owned_qty)
                ON DUPLICATE KEY UPDATE owned_qty = VALUES(owned_qty)';

        $stmt = $this->pdo->prepare($sql);

        $this->pdo->beginTransaction();
        try {
            foreach ($quantities as $cardId => $ownedQty) {
                $stmt->execute([
                    ':player_id' => $playerId,
                    ':card_id' => (int)$cardId,
                    ':owned_qty' => max(0, (int)$ownedQty),
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getInventory(int $playerId): array
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
                    END AS extra_qty

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
}

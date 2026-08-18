<?php
declare(strict_types=1);

final class MatchmakingService
{
    public function __construct(private PDO $pdo) {}

    public function findReciprocalTrades(int $playerId): array
    {
        // Find every other player who:
        // 1) has an extra card the current player needs, AND
        // 2) needs an extra card the current player has.
        //
        // One result is returned per useful give/receive card combination.

        $sql = '
            SELECT
                other.id AS other_player_id,
                other.display_name AS other_player_name,

                give_card.id AS give_card_id,
                give_card.name AS give_card_name,

                LEAST(
                    CASE
                        WHEN my_give.owned_qty > give_card.required_qty
                        THEN my_give.owned_qty - give_card.required_qty
                        ELSE 0
                    END,
                    CASE
                        WHEN give_card.required_qty > COALESCE(other_give.owned_qty, 0)
                        THEN give_card.required_qty - COALESCE(other_give.owned_qty, 0)
                        ELSE 0
                    END
                ) AS give_qty,

                receive_card.id AS receive_card_id,
                receive_card.name AS receive_card_name,

                LEAST(
                    CASE
                        WHEN their_give.owned_qty > receive_card.required_qty
                        THEN their_give.owned_qty - receive_card.required_qty
                        ELSE 0
                    END,
                    CASE
                        WHEN receive_card.required_qty > COALESCE(my_receive.owned_qty, 0)
                        THEN receive_card.required_qty - COALESCE(my_receive.owned_qty, 0)
                        ELSE 0
                    END
                ) AS receive_qty

            FROM players other

            JOIN player_cards my_give
              ON my_give.player_id = :player_id_1

            JOIN cards give_card
              ON give_card.id = my_give.card_id
             AND my_give.owned_qty > give_card.required_qty

            LEFT JOIN player_cards other_give
              ON other_give.player_id = other.id
             AND other_give.card_id = give_card.id

            JOIN player_cards their_give
              ON their_give.player_id = other.id

            JOIN cards receive_card
              ON receive_card.id = their_give.card_id
             AND their_give.owned_qty > receive_card.required_qty

            LEFT JOIN player_cards my_receive
              ON my_receive.player_id = :player_id_2
             AND my_receive.card_id = receive_card.id

            WHERE other.id <> :player_id_3
              AND COALESCE(other_give.owned_qty, 0) < give_card.required_qty
              AND COALESCE(my_receive.owned_qty, 0) < receive_card.required_qty

            ORDER BY
                other.display_name,
                give_card.name,
                receive_card.name
        ';

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            ':player_id_1' => $playerId,
            ':player_id_2' => $playerId,
            ':player_id_3' => $playerId,
        ]);

        return array_values(array_filter(
            $stmt->fetchAll(),
            fn(array $row) => (int)$row['give_qty'] > 0 && (int)$row['receive_qty'] > 0
        ));
    }
}

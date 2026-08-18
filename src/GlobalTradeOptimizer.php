<?php
declare(strict_types=1);

/**
 * V8.21 Global Trade Optimizer
 *
 * Objective order:
 *   1. Maximize total needed card units fulfilled.
 *   2. Among maximum-fulfillment plans, maximize distinct players helped.
 *   3. Prefer reciprocal/balanced player relationships when fulfillment and
 *      player coverage are unchanged.
 *   4. Heuristically minimize distinct player-to-player handoff relationships
 *      by reusing pair relationships while assigning donors.
 *
 * Objectives 1 and 2 are exact. Objectives 3 and 4 are deterministic
 * tie-breaking heuristics over equally useful card allocations.
 */
final class GlobalTradeOptimizer
{
    public function __construct(private PDO $pdo) {}

    public function optimize(): array
    {
        $cardCount = (int)$this->pdo->query('SELECT COUNT(*) FROM cards')->fetchColumn();

        $playerRows = $this->pdo->query(
            'SELECT
                p.id,
                p.display_name,
                COUNT(DISTINCT pc.card_id) AS saved_card_rows
             FROM players p
             LEFT JOIN player_cards pc ON pc.player_id = p.id
             GROUP BY p.id, p.display_name
             ORDER BY LOWER(p.display_name), p.id'
        )->fetchAll();

        $eligiblePlayers = [];
        $excludedPlayers = [];

        foreach ($playerRows as $row) {
            $player = [
                'id' => (int)$row['id'],
                'name' => (string)$row['display_name'],
                'saved_card_rows' => (int)$row['saved_card_rows'],
            ];

            if ($cardCount > 0 && (int)$row['saved_card_rows'] === $cardCount) {
                $eligiblePlayers[$player['id']] = $player;
            } else {
                $excludedPlayers[] = $player;
            }
        }

        if (!$eligiblePlayers || $cardCount === 0) {
            return $this->emptyResult($cardCount, $eligiblePlayers, $excludedPlayers);
        }

        $ids = array_keys($eligiblePlayers);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT
                p.id AS player_id,
                p.display_name AS player_name,
                c.id AS card_id,
                c.name AS card_name,
                c.category,
                c.required_qty,
                pc.owned_qty
             FROM players p
             JOIN player_cards pc ON pc.player_id = p.id
             JOIN cards c ON c.id = pc.card_id
             WHERE p.id IN ($placeholders)
             ORDER BY c.id, p.id"
        );
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();

        $cards = [];
        $needs = [];       // [cardId][playerId] => qty
        $surpluses = [];   // [cardId][playerId] => qty
        $totalNeed = 0;

        foreach ($rows as $row) {
            $playerId = (int)$row['player_id'];
            $cardId = (int)$row['card_id'];
            $required = (int)$row['required_qty'];
            $owned = (int)$row['owned_qty'];

            $cards[$cardId] = [
                'id' => $cardId,
                'name' => (string)$row['card_name'],
                'category' => (string)$row['category'],
                'required_qty' => $required,
            ];

            if ($owned < $required) {
                $qty = $required - $owned;
                $needs[$cardId][$playerId] = $qty;
                $totalNeed += $qty;
            } elseif ($owned > $required) {
                $surpluses[$cardId][$playerId] = $owned - $required;
            }
        }

        $supplyByCard = [];
        $needByCard = [];
        foreach ($cards as $cardId => $_card) {
            $supplyByCard[$cardId] = array_sum($surpluses[$cardId] ?? []);
            $needByCard[$cardId] = array_sum($needs[$cardId] ?? []);
        }

        // Stage 1: max-flow gives one unit to as many distinct players as possible,
        // subject to per-card supply. This exactly maximizes "players helped".
        $seed = $this->maximizeDistinctPlayersHelped($supplyByCard, $needs);

        // Receiver allocations: [cardId][playerId] => quantity that should arrive.
        $allocations = [];
        $helpedPlayers = [];

        foreach ($seed as $cardId => $players) {
            foreach ($players as $playerId => $qty) {
                if ($qty <= 0) {
                    continue;
                }
                $allocations[$cardId][$playerId] = $qty;
                $helpedPlayers[$playerId] = true;
            }
        }

        // Stage 2: fill every remaining satisfiable need. Since card types are
        // independent resources, total optimum for each card is min(supply, need).
        foreach ($cards as $cardId => $_card) {
            $remainingSupply = $supplyByCard[$cardId] - array_sum($allocations[$cardId] ?? []);
            if ($remainingSupply <= 0) {
                continue;
            }

            $receivers = [];
            foreach ($needs[$cardId] ?? [] as $playerId => $needQty) {
                $already = (int)($allocations[$cardId][$playerId] ?? 0);
                $remainingNeed = $needQty - $already;
                if ($remainingNeed > 0) {
                    $receivers[] = [
                        'player_id' => $playerId,
                        'remaining_need' => $remainingNeed,
                        'already_helped' => isset($helpedPlayers[$playerId]),
                    ];
                }
            }

            // Unhelped first as a defensive tie-breaker, then larger needs.
            usort($receivers, static function (array $a, array $b): int {
                return ($a['already_helped'] <=> $b['already_helped'])
                    ?: ($b['remaining_need'] <=> $a['remaining_need'])
                    ?: ($a['player_id'] <=> $b['player_id']);
            });

            foreach ($receivers as $receiver) {
                if ($remainingSupply <= 0) {
                    break;
                }

                $qty = min($remainingSupply, (int)$receiver['remaining_need']);
                if ($qty <= 0) {
                    continue;
                }

                $playerId = (int)$receiver['player_id'];
                $allocations[$cardId][$playerId] =
                    (int)($allocations[$cardId][$playerId] ?? 0) + $qty;

                $helpedPlayers[$playerId] = true;
                $remainingSupply -= $qty;
            }
        }

        // Stage 3: assign actual donors to the receiver allocations. Prefer an
        // already-used undirected pair relationship, then largest donor surplus.
        $transfers = [];
        $pairUsage = [];
        $directedUsage = [];
        $donorRemaining = $surpluses;

        foreach ($cards as $cardId => $card) {
            $receiverList = $allocations[$cardId] ?? [];
            if (!$receiverList) {
                continue;
            }

            // Larger allocations first gives the consolidator more room.
            arsort($receiverList);

            foreach ($receiverList as $receiverId => $receiveQty) {
                $remaining = (int)$receiveQty;

                while ($remaining > 0) {
                    $donors = [];
                    foreach ($donorRemaining[$cardId] ?? [] as $donorId => $available) {
                        if ($available <= 0 || $donorId === $receiverId) {
                            continue;
                        }

                        $pairKey = $this->pairKey($donorId, $receiverId);
                        $reverseKey = $receiverId . ':' . $donorId;
                        $donors[] = [
                            'player_id' => (int)$donorId,
                            'available' => (int)$available,
                            'reciprocal_pair' => isset($directedUsage[$reverseKey]),
                            'existing_pair' => isset($pairUsage[$pairKey]),
                            'pair_key' => $pairKey,
                        ];
                    }

                    if (!$donors) {
                        // Should not happen because receiver allocations were bounded
                        // by total supply, but keep the plan safe if data changes.
                        break;
                    }

                    usort($donors, static function (array $a, array $b): int {
                        // First prefer a donor who has already received something
                        // from this receiver: that converts a one-way handoff into
                        // a reciprocal exchange without sacrificing fulfillment.
                        return ($b['reciprocal_pair'] <=> $a['reciprocal_pair'])
                            ?: ($b['existing_pair'] <=> $a['existing_pair'])
                            ?: ($b['available'] <=> $a['available'])
                            ?: ($a['player_id'] <=> $b['player_id']);
                    });

                    $donor = $donors[0];
                    $qty = min($remaining, (int)$donor['available']);
                    $donorId = (int)$donor['player_id'];

                    $transfers[] = [
                        'from_player_id' => $donorId,
                        'from_player_name' => $eligiblePlayers[$donorId]['name'],
                        'to_player_id' => (int)$receiverId,
                        'to_player_name' => $eligiblePlayers[$receiverId]['name'],
                        'card_id' => $cardId,
                        'card_name' => $card['name'],
                        'category' => $card['category'],
                        'qty' => $qty,
                    ];

                    $donorRemaining[$cardId][$donorId] -= $qty;
                    $remaining -= $qty;
                    $pairUsage[$donor['pair_key']] = true;
                    $directedUsage[$donorId . ':' . $receiverId] = true;
                }
            }
        }

        // Aggregate same donor -> receiver -> card lines.
        $aggregatedTransfers = [];
        foreach ($transfers as $transfer) {
            $key = $transfer['from_player_id'] . ':' .
                $transfer['to_player_id'] . ':' . $transfer['card_id'];

            if (!isset($aggregatedTransfers[$key])) {
                $aggregatedTransfers[$key] = $transfer;
            } else {
                $aggregatedTransfers[$key]['qty'] += $transfer['qty'];
            }
        }
        $transfers = array_values($aggregatedTransfers);

        usort($transfers, static function (array $a, array $b): int {
            return strcasecmp($a['from_player_name'], $b['from_player_name'])
                ?: strcasecmp($a['to_player_name'], $b['to_player_name'])
                ?: strcasecmp($a['card_name'], $b['card_name']);
        });

        // Group transfer lines into relationships. Opposite directions between the
        // same two people live in one "handoff" card in the UI.
        $relationships = [];
        foreach ($transfers as $transfer) {
            $pairKey = $this->pairKey(
                (int)$transfer['from_player_id'],
                (int)$transfer['to_player_id']
            );

            if (!isset($relationships[$pairKey])) {
                $aId = min((int)$transfer['from_player_id'], (int)$transfer['to_player_id']);
                $bId = max((int)$transfer['from_player_id'], (int)$transfer['to_player_id']);

                $relationships[$pairKey] = [
                    'pair_key' => $pairKey,
                    'player_a_id' => $aId,
                    'player_a_name' => $eligiblePlayers[$aId]['name'],
                    'player_b_id' => $bId,
                    'player_b_name' => $eligiblePlayers[$bId]['name'],
                    'transfers' => [],
                    'unit_count' => 0,
                ];
            }

            $relationships[$pairKey]['transfers'][] = $transfer;
            $relationships[$pairKey]['unit_count'] += (int)$transfer['qty'];
        }

        $reciprocalRelationshipCount = 0;
        foreach ($relationships as &$relationship) {
            $directions = [];
            foreach ($relationship['transfers'] as $transfer) {
                $directions[$transfer['from_player_id'] . ':' . $transfer['to_player_id']] = true;
            }
            $aToB = $relationship['player_a_id'] . ':' . $relationship['player_b_id'];
            $bToA = $relationship['player_b_id'] . ':' . $relationship['player_a_id'];
            $relationship['reciprocal'] = isset($directions[$aToB], $directions[$bToA]);
            if ($relationship['reciprocal']) {
                $reciprocalRelationshipCount++;
            }
        }
        unset($relationship);

        uasort($relationships, static function (array $a, array $b): int {
            return ($b['reciprocal'] <=> $a['reciprocal'])
                ?: ($b['unit_count'] <=> $a['unit_count'])
                ?: strcasecmp($a['player_a_name'], $b['player_a_name'])
                ?: strcasecmp($a['player_b_name'], $b['player_b_name']);
        });

        $fulfilled = array_sum(array_map(
            static fn(array $t): int => (int)$t['qty'],
            $transfers
        ));

        $scarcity = [];
        foreach ($cards as $cardId => $card) {
            $need = (int)$needByCard[$cardId];
            $supply = (int)$supplyByCard[$cardId];
            $canFulfill = min($need, $supply);
            $unmet = max($need - $supply, 0);

            if ($need > 0) {
                $scarcity[] = [
                    'card_id' => $cardId,
                    'card_name' => $card['name'],
                    'category' => $card['category'],
                    'needed' => $need,
                    'available_extras' => $supply,
                    'satisfiable' => $canFulfill,
                    'unmet' => $unmet,
                ];
            }
        }

        usort($scarcity, static function (array $a, array $b): int {
            return ($b['unmet'] <=> $a['unmet'])
                ?: ($b['needed'] <=> $a['needed'])
                ?: strcasecmp($a['card_name'], $b['card_name']);
        });

        $playersWithNeed = [];
        foreach ($needs as $playerNeeds) {
            foreach ($playerNeeds as $playerId => $qty) {
                if ($qty > 0) {
                    $playersWithNeed[$playerId] = true;
                }
            }
        }

        return [
            'card_count' => $cardCount,
            'eligible_players' => array_values($eligiblePlayers),
            'excluded_players' => $excludedPlayers,
            'eligible_player_count' => count($eligiblePlayers),
            'excluded_player_count' => count($excludedPlayers),
            'players_with_need' => count($playersWithNeed),
            'players_helped' => count($helpedPlayers),
            'total_need_units' => $totalNeed,
            'fulfilled_units' => $fulfilled,
            'fulfillment_pct' => $totalNeed > 0 ? ($fulfilled / $totalNeed) * 100 : 100.0,
            'transfer_line_count' => count($transfers),
            'relationship_count' => count($relationships),
            'reciprocal_relationship_count' => $reciprocalRelationshipCount,
            'transfers' => $transfers,
            'relationships' => array_values($relationships),
            'scarcity' => $scarcity,
            'unmet_units' => max($totalNeed - $fulfilled, 0),
        ];
    }

    private function maximizeDistinctPlayersHelped(array $supplyByCard, array $needs): array
    {
        $source = 'S';
        $sink = 'T';
        $capacity = [];
        $adj = [];

        $addEdge = static function (
            string $u,
            string $v,
            int $cap
        ) use (&$capacity, &$adj): void {
            if ($cap <= 0) {
                return;
            }

            if (!isset($capacity[$u][$v])) {
                $capacity[$u][$v] = 0;
            }
            if (!isset($capacity[$v][$u])) {
                $capacity[$v][$u] = 0;
            }

            $capacity[$u][$v] += $cap;

            $adj[$u] ??= [];
            $adj[$v] ??= [];

            if (!in_array($v, $adj[$u], true)) {
                $adj[$u][] = $v;
            }
            if (!in_array($u, $adj[$v], true)) {
                $adj[$v][] = $u;
            }
        };

        $originalCardPlayerCaps = [];
        $playerSeen = [];

        foreach ($supplyByCard as $cardId => $supply) {
            if ($supply <= 0) {
                continue;
            }

            $cardNode = 'C' . $cardId;
            $addEdge($source, $cardNode, (int)$supply);

            foreach ($needs[$cardId] ?? [] as $playerId => $needQty) {
                if ($needQty <= 0) {
                    continue;
                }

                $playerNode = 'P' . $playerId;
                $addEdge($cardNode, $playerNode, 1);
                $originalCardPlayerCaps[$cardId][$playerId] = 1;

                if (!isset($playerSeen[$playerId])) {
                    $addEdge($playerNode, $sink, 1);
                    $playerSeen[$playerId] = true;
                }
            }
        }

        // Edmonds-Karp; graph is tiny for a clan-sized dataset.
        while (true) {
            $parent = [$source => null];
            $queue = [$source];
            $head = 0;

            while ($head < count($queue) && !array_key_exists($sink, $parent)) {
                $u = $queue[$head++];

                foreach ($adj[$u] ?? [] as $v) {
                    if (array_key_exists($v, $parent)) {
                        continue;
                    }

                    if (($capacity[$u][$v] ?? 0) <= 0) {
                        continue;
                    }

                    $parent[$v] = $u;
                    $queue[] = $v;

                    if ($v === $sink) {
                        break;
                    }
                }
            }

            if (!array_key_exists($sink, $parent)) {
                break;
            }

            $flow = PHP_INT_MAX;
            for ($v = $sink; $v !== $source; $v = $parent[$v]) {
                $u = $parent[$v];
                $flow = min($flow, (int)$capacity[$u][$v]);
            }

            for ($v = $sink; $v !== $source; $v = $parent[$v]) {
                $u = $parent[$v];
                $capacity[$u][$v] -= $flow;
                $capacity[$v][$u] += $flow;
            }
        }

        $result = [];
        foreach ($originalCardPlayerCaps as $cardId => $players) {
            foreach ($players as $playerId => $originalCap) {
                $cardNode = 'C' . $cardId;
                $playerNode = 'P' . $playerId;
                $remaining = (int)($capacity[$cardNode][$playerNode] ?? 0);
                $used = $originalCap - $remaining;

                if ($used > 0) {
                    $result[$cardId][$playerId] = $used;
                }
            }
        }

        return $result;
    }

    private function pairKey(int $a, int $b): string
    {
        return min($a, $b) . ':' . max($a, $b);
    }

    private function emptyResult(
        int $cardCount,
        array $eligiblePlayers,
        array $excludedPlayers
    ): array {
        return [
            'card_count' => $cardCount,
            'eligible_players' => array_values($eligiblePlayers),
            'excluded_players' => $excludedPlayers,
            'eligible_player_count' => count($eligiblePlayers),
            'excluded_player_count' => count($excludedPlayers),
            'players_with_need' => 0,
            'players_helped' => 0,
            'total_need_units' => 0,
            'fulfilled_units' => 0,
            'fulfillment_pct' => 100.0,
            'transfer_line_count' => 0,
            'relationship_count' => 0,
            'reciprocal_relationship_count' => 0,
            'transfers' => [],
            'relationships' => [],
            'scarcity' => [],
            'unmet_units' => 0,
        ];
    }
}

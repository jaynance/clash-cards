<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/GlobalTradeOptimizer.php';

function failTest(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        failTest($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec('
    CREATE TABLE players (
        id INTEGER PRIMARY KEY,
        display_name TEXT NOT NULL
    );

    CREATE TABLE cards (
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        category TEXT NOT NULL,
        required_qty INTEGER NOT NULL
    );

    CREATE TABLE player_cards (
        player_id INTEGER NOT NULL,
        card_id INTEGER NOT NULL,
        owned_qty INTEGER NOT NULL,
        PRIMARY KEY (player_id, card_id)
    );
');

$players = [
    1 => 'CrossA',
    2 => 'CrossB',
    3 => 'ValidA',
    4 => 'ValidB',
];

foreach ($players as $id => $name) {
    $stmt = $pdo->prepare('INSERT INTO players (id, display_name) VALUES (?, ?)');
    $stmt->execute([$id, $name]);
}

/*
 * Four cards, two groups.
 *
 * Cards 1/2 = Dark Elixir
 * Cards 3/4 = Super
 *
 * CrossA/CrossB have a tempting CROSS-GROUP exchange only:
 *   CrossA can give Dark One to CrossB
 *   CrossB can give Super One to CrossA
 * This MUST NOT become a trade.
 *
 * ValidA/ValidB have a legal Dark Elixir exchange:
 *   ValidA gives Dark One
 *   ValidB gives Dark Two
 * This MUST become a trade.
 */
$cards = [
    [1, 'Dark One',  'Dark Elixir', 1],
    [2, 'Dark Two',  'Dark Elixir', 1],
    [3, 'Super One', 'Super',       1],
    [4, 'Super Two', 'Super',       1],
];

foreach ($cards as $card) {
    $stmt = $pdo->prepare(
        'INSERT INTO cards (id, name, category, required_qty) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute($card);
}

$inventory = [
    // CrossA: extra Dark One, needs Super One.
    1 => [1 => 2, 2 => 1, 3 => 0, 4 => 1],

    // CrossB: needs Dark One, extra Super One.
    2 => [1 => 0, 2 => 1, 3 => 2, 4 => 1],

    // ValidA: extra Dark One, needs Dark Two.
    3 => [1 => 2, 2 => 0, 3 => 1, 4 => 1],

    // ValidB: needs Dark One, extra Dark Two.
    4 => [1 => 0, 2 => 2, 3 => 1, 4 => 1],
];

foreach ($inventory as $playerId => $quantities) {
    foreach ($quantities as $cardId => $qty) {
        $stmt = $pdo->prepare(
            'INSERT INTO player_cards (player_id, card_id, owned_qty) VALUES (?, ?, ?)'
        );
        $stmt->execute([$playerId, $cardId, $qty]);
    }
}

$optimizer = new GlobalTradeOptimizer($pdo);
$result = $optimizer->optimize();

assertTrue(
    ($result['optimizer_mode'] ?? '') === 'group-constrained-bilateral',
    'optimizer must run in group-constrained-bilateral mode'
);

assertTrue(
    (int)$result['trade_count'] === 1,
    'fixture should produce exactly one executable same-group trade'
);

$cardCategory = [];
foreach ($cards as [$id, $_name, $category, $_required]) {
    $cardCategory[(int)$id] = $category;
}

foreach ($result['trade_units'] as $trade) {
    $category = (string)$trade['category'];
    $aCard = (int)$trade['player_a_gives_card_id'];
    $bCard = (int)$trade['player_b_gives_card_id'];

    assertTrue(
        ($cardCategory[$aCard] ?? null) === $category,
        "player A card {$aCard} must match trade category {$category}"
    );
    assertTrue(
        ($cardCategory[$bCard] ?? null) === $category,
        "player B card {$bCard} must match trade category {$category}"
    );
    assertTrue(
        ($cardCategory[$aCard] ?? null) === ($cardCategory[$bCard] ?? null),
        'both sides of every trade must be in the same category'
    );
}

// Explicitly prove the tempting CrossA/CrossB relationship was rejected.
foreach ($result['relationships'] as $relationship) {
    $ids = [
        (int)$relationship['player_a_id'],
        (int)$relationship['player_b_id'],
    ];
    sort($ids);

    assertTrue(
        $ids !== [1, 2],
        'cross-group-only CrossA/CrossB relationship must not be emitted'
    );
}

// Every grouped relationship must also be internally category-consistent.
foreach ($result['relationships'] as $relationship) {
    foreach ($relationship['trade_groups'] ?? [] as $group) {
        $category = (string)$group['category'];

        foreach ($group['trades'] ?? [] as $trade) {
            assertTrue(
                ($cardCategory[(int)$trade['player_a_gives_card_id']] ?? null) === $category,
                'relationship group contains a mismatched player A card'
            );
            assertTrue(
                ($cardCategory[(int)$trade['player_b_gives_card_id']] ?? null) === $category,
                'relationship group contains a mismatched player B card'
            );
        }

        foreach ($group['transfers'] ?? [] as $transfer) {
            assertTrue(
                (string)$transfer['category'] === $category,
                'relationship transfer escaped its category group'
            );
        }
    }
}

fwrite(
    STDOUT,
    "PASS: V8.35 optimizer emitted only executable same-group bilateral trades.\n"
);

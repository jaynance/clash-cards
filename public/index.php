<?php
declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    exit('Missing config.php. Copy config.example.php to config.php and fill in the database settings.');
}

$config = require $configPath;

require dirname(__DIR__) . '/src/Production.php';
Production::configure($config['app'] ?? []);

session_start();

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/InventoryService.php';
require dirname(__DIR__) . '/src/TradeService.php';
require dirname(__DIR__) . '/src/GlobalTradeOptimizer.php';

$pdo = Database::connect($config['db']);
$inventoryService = new InventoryService($pdo);
$tradeService = new TradeService($pdo);
$globalTradeOptimizer = new GlobalTradeOptimizer($pdo);

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout_player'])) {
    unset($_SESSION['player_id'], $_SESSION['display_name']);
    session_regenerate_id(true);
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['display_name'])) {
    $_SESSION['player_id'] = $inventoryService->getOrCreatePlayer($_POST['display_name']);
    $_SESSION['display_name'] = trim($_POST['display_name']);
    header('Location: index.php');
    exit;
}

$playerId = isset($_SESSION['player_id']) ? (int)$_SESSION['player_id'] : null;

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && $playerId
    && array_key_exists('ajax_player_inventory', $_GET)
) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    $lookupName = trim((string)$_GET['ajax_player_inventory']);
    if ($lookupName === '') {
        echo json_encode([
            'found' => false,
            'display_name' => null,
            'quantities' => new stdClass(),
        ]);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT id, display_name
         FROM players
         WHERE display_name = :display_name
         LIMIT 1'
    );
    $stmt->execute([':display_name' => $lookupName]);
    $lookupPlayer = $stmt->fetch();

    if (!$lookupPlayer) {
        echo json_encode([
            'found' => false,
            'display_name' => null,
            'quantities' => new stdClass(),
        ]);
        exit;
    }

    $qtyStmt = $pdo->prepare(
        'SELECT card_id, owned_qty
         FROM player_cards
         WHERE player_id = :player_id'
    );
    $qtyStmt->execute([':player_id' => (int)$lookupPlayer['id']]);

    $quantities = [];
    foreach ($qtyStmt->fetchAll() as $row) {
        $quantities[(string)(int)$row['card_id']] = (int)$row['owned_qty'];
    }

    echo json_encode([
        'found' => true,
        'display_name' => (string)$lookupPlayer['display_name'],
        'quantities' => $quantities,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// The logged-in player controls the session, but the page can display another
// player's post-scan results without changing that login.
$viewPlayerId = $playerId;

if ($playerId && isset($_GET['view_player_id'])) {
    $requestedViewId = (int)$_GET['view_player_id'];
    if ($requestedViewId > 0 && $inventoryService->getPlayer($requestedViewId)) {
        $viewPlayerId = $requestedViewId;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $playerId && isset($_POST['propose_trade'])) {
    $tradeViewPlayerId = $playerId;

    try {
        $proposalId = $tradeService->createProposal(
            $tradeViewPlayerId,
            (int)($_POST['other_player_id'] ?? 0),
            (int)($_POST['give_card_id'] ?? 0),
            max(0, (int)($_POST['give_qty'] ?? 0)),
            (int)($_POST['receive_card_id'] ?? 0),
            max(0, (int)($_POST['receive_qty'] ?? 0))
        );

        header(
            'Location: index.php?view_player_id=' . $playerId .
            '&trade=proposed&trade_id=' . $proposalId . '&tab=trades'
        );
        exit;
    } catch (Throwable $e) {
        Production::report($e, 'Trade proposal failed');
        $message = 'Trade could not be proposed. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $playerId && isset($_POST['complete_trade'])) {
    $tradeViewPlayerId = $playerId;
    $tradeId = max(0, (int)($_POST['trade_id'] ?? 0));

    try {
        $tradeService->completeProposal($tradeId, $tradeViewPlayerId);
        header(
            'Location: index.php?view_player_id=' . $playerId .
            '&trade=completed&trade_id=' . $tradeId . '&tab=trades'
        );
        exit;
    } catch (Throwable $e) {
        Production::report($e, 'Trade completion failed');
        $message = 'Trade could not be completed. The inventory may have changed.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $playerId && isset($_POST['cancel_trade'])) {
    $tradeViewPlayerId = $playerId;
    $tradeId = max(0, (int)($_POST['trade_id'] ?? 0));

    try {
        $tradeService->cancelProposal($tradeId, $tradeViewPlayerId);
        header(
            'Location: index.php?view_player_id=' . $playerId .
            '&trade=cancelled&trade_id=' . $tradeId . '&tab=trades'
        );
        exit;
    } catch (Throwable $e) {
        Production::report($e, 'Trade cancellation failed');
        $message = 'Trade could not be cancelled. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $playerId && isset($_POST['inventory'])) {
    $inventoryService->saveInventory($playerId, $_POST['inventory']);
    header('Location: index.php?view_player_id=' . $playerId . '&saved=manual&tab=cards');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $playerId && isset($_POST['save_detected'])) {
    $quantities = [];

    foreach ($_POST['detected'] ?? [] as $cardId => $ownedQty) {
        $quantities[(int)$cardId] = max(0, (int)$ownedQty);
    }

    $scannedDisplayName = trim((string)($_POST['scanned_display_name'] ?? ''));
    $scannedDisplayName = preg_replace('/^\d+\s+/', '', $scannedDisplayName) ?? $scannedDisplayName;
    $scannedDisplayName = preg_replace('/\s+[_|~\-]+\s*[A-Za-z0-9]*\s*$/', '', $scannedDisplayName) ?? $scannedDisplayName;
    $scannedDisplayName = trim($scannedDisplayName);

    if ($scannedDisplayName === '') {
        $message = 'Detected inventory was not saved because the scanned player name is blank.';
    } elseif ($quantities) {
        $scannedPlayerId = $inventoryService->getOrCreatePlayer($scannedDisplayName);
        $inventoryService->saveInventory($scannedPlayerId, $quantities);

        // Redirect prevents an accidental browser refresh from resubmitting the
        // inventory and opens the post-scan summary for the scanned player.
        header(
            'Location: index.php?view_player_id=' . $scannedPlayerId .
            '&saved=scan&tab=cards'
        );
        exit;
    }
}

$cards = $inventoryService->getCards();

// Existing player names are exposed to the client only so username OCR can
// reconcile small recognition errors (for example "Raptor Due" -> "RaptorDude")
// without creating an accidental duplicate player.
$knownPlayerNames = array_values(array_map(
    static fn(array $row): string => (string)$row['display_name'],
    $pdo->query('SELECT display_name FROM players ORDER BY display_name')->fetchAll()
));

$viewPlayer = $viewPlayerId ? $inventoryService->getPlayer($viewPlayerId) : null;
if (!$viewPlayer && $playerId) {
    $viewPlayerId = $playerId;
    $viewPlayer = $inventoryService->getPlayer($playerId);
}

if (isset($_GET['saved'])) {
    if ($_GET['saved'] === 'scan' && $viewPlayer) {
        $message = 'Inventory saved for ' . $viewPlayer['display_name'] . '. Showing updated summary and trade matches below.';
    } elseif ($_GET['saved'] === 'manual') {
        $message = 'Inventory saved.';
    }
}

if (isset($_GET['admin_login_as']) && $_GET['admin_login_as'] === '1') {
    $message = 'Admin switched session to ' . (string)($_SESSION['display_name'] ?? '') . '.';
}

if (isset($_GET['trade'])) {
    if ($_GET['trade'] === 'proposed') {
        $message = 'Trade proposal created.';
    } elseif ($_GET['trade'] === 'completed') {
        $message = 'Trade completed. Both players’ inventories were updated.';
    } elseif ($_GET['trade'] === 'cancelled') {
        $message = 'Trade proposal cancelled.';
    }
}

$inventory = $viewPlayerId ? $inventoryService->getInventory($viewPlayerId) : [];

// Trades always use the logged-in player's perspective, even when My Cards is
// temporarily showing a scanned player's inventory.
$tradePlayer = $playerId ? $inventoryService->getPlayer($playerId) : null;
$trades = $playerId ? $tradeService->listForPlayer($playerId) : [];

$optimization = $playerId ? $globalTradeOptimizer->optimize() : null;
$optimizedRelationships = [];
$optimizerEligible = false;

if ($optimization && $playerId) {
    foreach ($optimization['eligible_players'] as $eligiblePlayer) {
        if ((int)$eligiblePlayer['id'] === $playerId) {
            $optimizerEligible = true;
            break;
        }
    }

    foreach ($optimization['relationships'] as $relationship) {
        if (
            (int)$relationship['player_a_id'] === $playerId
            || (int)$relationship['player_b_id'] === $playerId
        ) {
            $optimizedRelationships[] = $relationship;
        }
    }
}

$openTrades = [];
$tradeHistory = [];
foreach ($trades as $trade) {
    if ((string)$trade['status'] === 'proposed') {
        $openTrades[] = $trade;
    } else {
        $tradeHistory[] = $trade;
    }
}

$groupedInventory = [];
$needs = [];
$extras = [];
$complete = [];

foreach ($inventory as $row) {
    $category = $row['category'] ?: 'Other';
    $groupedInventory[$category][] = $row;

    if ((int)$row['need_qty'] > 0) {
        $needs[] = $row;
    } elseif ((int)$row['extra_qty'] > 0) {
        $extras[] = $row;
    } else {
        $complete[] = $row;
    }
}

$allowedTabs = ['scan', 'cards', 'trades'];
$activeTab = isset($_GET['tab']) ? (string)$_GET['tab'] : '';
if (!in_array($activeTab, $allowedTabs, true)) {
    if (isset($_GET['trade'])) $activeTab = 'trades';
    elseif (isset($_GET['saved']) || isset($_GET['admin_login_as'])) $activeTab = 'cards';
    else $activeTab = 'scan';
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Clash Cards Matchmaker</title>
<!-- Production build: V8.37.1 How-To Tab Fix -->
<style>
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:1100px;margin:40px auto;padding:0 20px 50px;background:#f7f7f9;color:#222}
h1{margin-bottom:8px}h2{margin-top:34px}
.panel,.summary-card,.category-section,.trade-card{background:#fff;border:1px solid #ddd;border-radius:12px}
.panel{padding:18px;margin:18px 0}.summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin:20px 0 28px}
.summary-card,.category-section{overflow:hidden}.summary-card h3,.category-title{margin:0;padding:14px 16px;background:#ececf1;border-bottom:1px solid #ddd}
.summary-list{list-style:none;padding:0;margin:0}.summary-list li{display:flex;justify-content:space-between;gap:16px;padding:10px 16px;border-bottom:1px solid #eee}
.summary-list li:last-child{border-bottom:0}.summary-empty{padding:16px;margin:0;color:#666}.qty,.need-positive,.extra-positive{font-weight:700}
.category-section{margin:22px 0}table{border-collapse:collapse;width:100%}th,td{border-bottom:1px solid #eee;padding:10px 14px;text-align:left}tr:last-child td{border-bottom:0}th{background:#fafafa}
input[type=number]{width:80px;padding:7px}input[type=file]{max-width:100%}button{padding:10px 16px;cursor:pointer;border-radius:8px;border:1px solid #aaa;background:#fff}
button:disabled{opacity:.55;cursor:not-allowed}.primary{font-weight:700}.save-row{margin-top:18px}.message{padding:10px 12px;border:1px solid #ccc;border-radius:8px;background:#fff}
.trade-card{padding:16px;margin:12px 0}.trade-option{padding:10px 0;border-top:1px solid #eee}.trade-option:first-of-type{margin-top:8px}
.trade-option-form{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.trade-option-text{flex:1;min-width:280px}.trade-actions{display:flex;gap:8px;flex-wrap:wrap}
.trade-status{display:inline-block;font-size:.78rem;font-weight:800;padding:3px 8px;border-radius:999px;text-transform:uppercase;letter-spacing:.03em}
.trade-status-proposed{background:#fff3cd;color:#705400}.trade-status-completed{background:#e8f6eb;color:#216b2a}.trade-status-cancelled{background:#f0f0f0;color:#666}
.trade-ledger{display:grid;gap:12px}.trade-ledger-card{background:#fff;border:1px solid #ddd;border-radius:12px;padding:14px 16px}
.trade-ledger-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.trade-ledger-meta{color:#666;font-size:.9rem;margin-top:7px}.view-banner{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:12px 14px;border:1px solid #cfd7e6;border-radius:10px;background:#f5f8ff;margin:16px 0}.view-banner p{margin:0}.pill{display:inline-block;font-size:.78rem;font-weight:700;padding:3px 8px;border-radius:999px;background:#ececf1}.upload-help{color:#555;margin-bottom:14px}.privacy-note{font-size:.92rem;color:#555}
#ocrProgressWrap{margin-top:16px}progress{width:100%;height:18px}#ocrStatus{margin:7px 0 0;color:#555}
#learningPanel{margin:14px 0;padding:12px;border:1px solid #d7d7d7;border-radius:8px;background:#fafafa}
#learningPanel p{margin:6px 0}.learn-example{white-space:nowrap}.muted{opacity:.55}
.learning-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.save-target{padding:10px 12px;border:1px solid #cfd7e6;border-radius:8px;background:#f5f8ff;margin:10px 0 14px}
.scan-completeness{padding:10px 12px;border:1px solid #ccc;border-radius:8px;margin:14px 0 0;background:#fafafa}
.scan-complete{border-color:#8fb996;background:#f3fbf4}
.scan-incomplete{border-color:#d4a3a3;background:#fff5f5;font-weight:600}
#detectedReview{margin-top:22px}.confidence{font-size:.85rem;font-weight:700}.confidence-high{color:#246b2d}.confidence-medium{color:#7a5a00}.confidence-low{color:#9b2c2c}.confidence-previous{color:#315da8}.confidence-manual{color:#9a5b00}.scan-fallback-row{background:#fffaf0}.scan-fallback-row input{border-color:#d7a94b}.partial-note{margin:8px 0;color:#6f5100}
details{margin-top:18px}pre{white-space:pre-wrap;word-break:break-word;background:#f4f4f6;border:1px solid #ddd;border-radius:8px;padding:12px;max-height:360px;overflow:auto}
@media(max-width:700px){.summary-grid{grid-template-columns:1fr}th,td{padding:9px 8px}}
.app-header{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:14px}.app-header h1{margin:0}.player-session{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.player-chip{background:#f3f6fb;border:1px solid #ccd7e8;border-radius:999px;padding:8px 13px;white-space:nowrap}.logout-player-form{margin:0}.logout-player-button{background:white;border:1px solid #b9c2cf;color:#444;padding:7px 11px;border-radius:999px;font-weight:700;cursor:pointer}.logout-player-button:hover{background:#f3f4f6;border-color:#8c98a8}.tabs{display:flex;gap:6px;border-bottom:1px solid #d9dce3;margin:18px 0 20px;overflow-x:auto}.tab-button{appearance:none;border:0;border-bottom:3px solid transparent;background:transparent;padding:11px 16px;margin:0;color:#555;font:inherit;font-weight:750;cursor:pointer;white-space:nowrap}.tab-button:hover{background:#f6f7f9;color:#222}.tab-button.active{color:#244f91;border-bottom-color:#315da8;background:#f5f8ff}.tab-panel{display:none}.tab-panel.active{display:block}.tab-intro{color:#666;margin-top:-8px;margin-bottom:18px}.admin-tab-link{margin-left:auto;text-decoration:none;color:#555;font-weight:750;padding:11px 16px;white-space:nowrap}.admin-tab-link:hover{background:#f6f7f9;color:#222}@media(max-width:700px){.player-chip{white-space:normal}.tabs{gap:0}.tab-button,.admin-tab-link{padding:10px 12px}}

.player-opt-summary{display:grid;grid-template-columns:repeat(4,minmax(110px,1fr));gap:10px;margin:14px 0 18px}.player-opt-metric{background:#f6f8fb;border:1px solid #dde3ec;border-radius:10px;padding:11px;text-align:center}.player-opt-metric strong{display:block;font-size:1.25rem}.player-opt-metric span{font-size:.8rem;color:#666}.player-opt-note{padding:11px 13px;background:#f5f8ff;border:1px solid #c8d6ed;border-radius:9px;margin:12px 0}.player-opt-warning{padding:11px 13px;background:#fff8e5;border:1px solid #dfc981;border-radius:9px;margin:12px 0}.player-network-wrap{background:#fff;border:1px solid #ddd;border-radius:12px;padding:10px;overflow:auto;margin:14px 0}.player-network-wrap svg{width:100%;min-width:680px;height:440px}.pgraph-edge{stroke:#9aa7bd;stroke-width:3;cursor:pointer}.pgraph-node{fill:#f5f8ff;stroke:#315da8;stroke-width:2;cursor:pointer}.pgraph-node-center{fill:#eaf2ff;stroke-width:4}.pgraph-node-label{font-size:12px;font-weight:700;text-anchor:middle;dominant-baseline:middle;pointer-events:none}.pgraph-label-bg{fill:white;stroke:#d7dce5;stroke-width:1;opacity:.97}.pgraph-edge-label{font-size:11px;fill:#444;text-anchor:middle;cursor:pointer}.optimized-relations{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:14px 0}.optimized-relation{border:1px solid #ddd;border-radius:11px;padding:14px;background:white;cursor:pointer}.optimized-relation:hover,.optimized-relation.selected{border-color:#315da8;box-shadow:0 0 0 2px rgba(49,93,168,.10)}.optimized-relation h3{margin:0 0 8px}.reciprocal-badge{display:inline-block;margin-left:6px;padding:2px 7px;border-radius:999px;background:#e8f1ff;color:#244f91;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em}.optimized-transfer{padding:6px 0;border-top:1px solid #eee}.optimized-transfer:first-of-type{border-top:0}.optimizer-detail{border:1px solid #b9c9e2;border-radius:12px;padding:15px;background:white;margin:16px 0}.optimizer-detail.empty{border-style:dashed;color:#666}.optimizer-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.optimizer-side{background:#f8f9fb;border-radius:9px;padding:10px}.optimized-proposal{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:10px 0;border-top:1px solid #eee}.optimized-proposal-text{flex:1;min-width:280px}.one-way-explain{background:#fff8e5;border:1px solid #dfc981;border-radius:8px;padding:10px;margin-top:12px}@media(max-width:850px){.player-opt-summary{grid-template-columns:repeat(2,1fr)}.optimized-relations{grid-template-columns:1fr}.optimizer-detail-grid{grid-template-columns:1fr}}

.username-review-warning{margin:0 0 10px;padding:9px 11px;border:1px solid #d59b29;border-radius:8px;background:#fff8e5;color:#664d00}
.username-review-required{border:2px solid #d59b29!important;background:#fffdf5}

.trade-builder{border:1px solid #c9ced8;border-radius:12px;background:#fff;padding:16px;margin:16px 0}
.trade-builder h4{margin:0 0 10px}
.trade-builder-grid{display:grid;grid-template-columns:1fr auto 1fr;gap:16px;align-items:start}
.trade-choice-panel{background:#f7f8fa;border:1px solid #dde1e7;border-radius:10px;padding:12px;min-height:170px}
.trade-choice-panel h5{margin:0 0 9px;font-size:.95rem}
.trade-choice-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:8px}
.trade-choice{position:relative;display:block}
.trade-choice input{position:absolute;opacity:0;pointer-events:none}
.trade-choice-card{display:block;border:2px solid #d8dce3;border-radius:9px;background:#fff;padding:10px;cursor:pointer;min-height:74px;transition:border-color .15s,box-shadow .15s,background .15s}
.trade-choice-card strong{display:block;margin-bottom:4px}
.trade-choice-card small{color:#666}
.trade-choice input:checked + .trade-choice-card{border-color:#315da8;background:#eef4ff;box-shadow:0 0 0 2px rgba(49,93,168,.12)}
.trade-arrow{font-size:2rem;font-weight:800;align-self:center;color:#777;padding-top:42px}
.trade-builder-summary{margin-top:12px;padding:10px 12px;border-radius:8px;background:#f5f8ff;border:1px solid #c8d6ed}
.trade-builder-actions{display:flex;justify-content:flex-end;margin-top:12px}
.trade-builder-actions button:disabled{opacity:.5;cursor:not-allowed}
@media(max-width:800px){.trade-builder-grid{grid-template-columns:1fr}.trade-arrow{transform:rotate(90deg);padding:0;text-align:center}}


.howto-hero{background:#f5f8ff;border:1px solid #c7d6ee;border-radius:12px;padding:16px 18px;margin-bottom:16px}
.howto-hero h2{margin-top:0}
.howto-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.howto-card{background:#fff;border:1px solid #ddd;border-radius:11px;padding:15px}
.howto-card h3{margin:0 0 8px}
.howto-step{display:flex;gap:12px;align-items:flex-start}
.howto-number{flex:0 0 32px;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#315da8;color:white;font-weight:800}
.howto-card p{margin:6px 0;color:#444}
.howto-note{background:#fff8e5;border:1px solid #dfc981;border-radius:9px;padding:11px 13px;margin-top:14px}
.howto-good{background:#f3fbf4;border:1px solid #9bc5a1;border-radius:9px;padding:11px 13px;margin-top:14px}
.howto-mini{font-size:.9rem;color:#666}
@media(max-width:800px){.howto-grid{grid-template-columns:1fr}}

</style>
</head>
<body data-logged-in-player="<?= h($_SESSION['display_name'] ?? '') ?>">

<?php if (!$playerId): ?>
<h1>Clash Cards Matchmaker</h1>
<h2>Choose your player name</h2>
<form method="post">
    <input name="display_name" maxlength="80" required>
    <button type="submit">Continue</button>
</form>
<?php else: ?>

<header class="app-header">
    <h1>Clash Cards Matchmaker</h1>
    <div class="player-session">
        <div class="player-chip">Playing as: <strong><?= h((string)($_SESSION['display_name'] ?? '')) ?></strong></div>
        <form method="post" class="logout-player-form">
            <input type="hidden" name="logout_player" value="1">
            <button type="submit" class="logout-player-button">Log out / change player</button>
        </form>
    </div>
</header>
<nav class="tabs" aria-label="Player workflow">
    <button type="button" class="tab-button" data-tab="scan">📷 Scan Cards</button>
    <button type="button" class="tab-button" data-tab="cards">🃏 My Cards</button>
    <button type="button" class="tab-button" data-tab="trades">🤝 Trades</button>
    <button type="button" class="tab-button" data-tab="help">❓ How To</button>
    <a class="admin-tab-link" href="admin.php">🔧 Admin</a>
</nav>

<?php if ($viewPlayer): ?>
<div class="view-banner">
    <p>
        Viewing inventory and matches for:
        <strong><?= h((string)$viewPlayer['display_name']) ?></strong>
        <?php if ((int)$viewPlayer['id'] !== $playerId): ?>
            <span class="pill">scanned player</span>
        <?php endif; ?>
    </p>
    <?php if ((int)$viewPlayer['id'] !== $playerId): ?>
        <a href="index.php?view_player_id=<?= $playerId ?>">Back to my inventory</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($message): ?><p class="message"><strong><?= h($message) ?></strong></p><?php endif; ?>

<section id="tab-scan" class="tab-panel" data-tab-panel="scan"><p class="tab-intro">Scan and review a five-page Clash Cards inventory set.</p><h2>Scan screenshots</h2>
<section class="panel">
    <p class="upload-help">
        Choose one or more Clash of Cards screenshots, then click Analyze screenshots.
    </p>
    <p class="privacy-note">
        OCR runs in your browser. The screenshots are not posted to this PHP server;
        only the card quantities you approve are submitted when you click Save inventory for scanned player.
    </p>

    <label for="detectedUsername"><strong>Scanned player</strong></label><br>
    <input
        id="detectedUsername"
        name="scanned_display_name"
        form="detectedReview"
        type="text"
        maxlength="80"
        value="<?= h($_SESSION['display_name'] ?? '') ?>"
        placeholder="Player whose screenshots are being scanned"
        autocomplete="off"
        style="margin:6px 0 6px;padding:8px;min-width:260px"
        required
    >
    <p id="usernameReviewWarning" class="username-review-warning" hidden>
        <strong>Please verify the player name.</strong>
        OCR confidence was too low to safely create a new player. Correct the name above before saving.
    </p>
    <p class="privacy-note" style="margin-top:0">
        This is the player whose inventory will be updated. Correct it if OCR gets the name wrong.
    </p>

    <input
        id="screenshots"
        type="file"
        accept="image/jpeg,image/png,image/webp"
        multiple
    >
    <button id="analyzeScreenshots" class="primary" type="button">
        Analyze screenshots
    </button>

    <div id="ocrProgressWrap" hidden>
        <progress id="ocrProgress" max="100" value="0"></progress>
        <p id="ocrStatus"></p>
    </div>

    <form method="post" id="detectedReview" hidden>
        <input type="hidden" name="save_detected" value="1">

        <h3>Detected cards — review before saving</h3>
        <p class="save-target">
            Saving inventory for:
            <strong id="saveTargetName"><?= h($_SESSION['display_name'] ?? '') ?></strong>
        </p>
        <table>
            <thead>
            <tr>
                <th>Card</th>
                <th>Category</th>
                <th>Owned</th>
                <th>Confidence</th>
                <th>Learn</th>
            </tr>
            </thead>
            <tbody id="detectedBody"></tbody>
        </table>

        <div id="learningPanel">
            <label>
                <input type="checkbox" id="learningConsent">
                I approve teaching the scanner from the checked examples
            </label>
            <p>
                Correct the Owned quantity first, then check <strong>use</strong> for examples you verified.
                Teaching stores only the approved glyph examples in this browser and does <strong>not</strong> save inventory.
            </p>
            <p id="learningStatus"></p>
            <div class="learning-actions">
                <button id="teachSelectedExamples" class="primary" type="button" disabled>Teach selected examples</button>
                <button id="exportLearning" type="button">Export learned examples</button>
                <button id="clearLearning" type="button">Clear learned examples</button>
            </div>
        </div>

        <p id="scanCompleteness" class="scan-completeness">
            Analyze screenshots. If a page cannot be read, its cards will use previous saved values or start at 0 for manual review.
        </p>

        <div class="save-row">
            <button id="saveDetectedButton" class="primary" type="submit" disabled>
                Save detected inventory
            </button>
        </div>
    </form>

    <details id="rawOcrWrap" hidden>
        <summary>Raw OCR text (debug)</summary>
        <pre id="rawOcrText"></pre>
    </details>
</section>

</section>
<section id="tab-cards" class="tab-panel" data-tab-panel="cards"><p class="tab-intro">Inventory summary and full card collection for the player currently being viewed.</p><h2>Post-scan inventory summary<?php if ($viewPlayer): ?> — <?= h((string)$viewPlayer['display_name']) ?><?php endif; ?></h2>
<div class="summary-grid">
<section class="summary-card">
<h3>Need <span class="pill"><?= count($needs) ?></span></h3>
<?php if (!$needs): ?><p class="summary-empty">Nothing needed.</p>
<?php else: ?><ul class="summary-list"><?php foreach ($needs as $row): ?><li><span><?= h($row['name']) ?></span><span class="qty">×<?= (int)$row['need_qty'] ?></span></li><?php endforeach; ?></ul><?php endif; ?>
</section>

<section class="summary-card">
<h3>Extras <span class="pill"><?= count($extras) ?></span></h3>
<?php if (!$extras): ?><p class="summary-empty">No extras available.</p>
<?php else: ?><ul class="summary-list"><?php foreach ($extras as $row): ?><li><span><?= h($row['name']) ?></span><span class="qty">×<?= (int)$row['extra_qty'] ?></span></li><?php endforeach; ?></ul><?php endif; ?>
</section>

<section class="summary-card">
<h3>Complete <span class="pill"><?= count($complete) ?></span></h3>
<?php if (!$complete): ?><p class="summary-empty">No cards are exactly complete yet.</p>
<?php else: ?><ul class="summary-list"><?php foreach ($complete as $row): ?><li><span><?= h($row['name']) ?></span><span class="qty">✓</span></li><?php endforeach; ?></ul><?php endif; ?>
</section>
</div>

<h2>Full inventory<?php if ($viewPlayer): ?> — <?= h((string)$viewPlayer['display_name']) ?><?php endif; ?></h2>
<?php if ($viewPlayerId === $playerId): ?>
<form method="post">
<?php foreach ($groupedInventory as $category => $rows): ?>
<section class="category-section">
<h3 class="category-title"><?= h($category) ?></h3>
<table>
<thead><tr><th>Card</th><th>Owned</th><th>Need</th><th>Extra</th></tr></thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr>
<td><?= h($row['name']) ?></td>
<td><input type="number" min="0" name="inventory[<?= (int)$row['id'] ?>]" value="<?= (int)$row['owned_qty'] ?>"></td>
<td class="<?= (int)$row['need_qty'] > 0 ? 'need-positive' : '' ?>"><?= (int)$row['need_qty'] ?></td>
<td class="<?= (int)$row['extra_qty'] > 0 ? 'extra-positive' : '' ?>"><?= (int)$row['extra_qty'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</section>
<?php endforeach; ?>
<div class="save-row"><button type="submit">Save inventory</button></div>
</form>
<?php else: ?>
<?php foreach ($groupedInventory as $category => $rows): ?>
<section class="category-section">
<h3 class="category-title"><?= h($category) ?></h3>
<table>
<thead><tr><th>Card</th><th>Owned</th><th>Need</th><th>Extra</th></tr></thead>
<tbody>
<?php foreach ($rows as $row): ?>
<tr>
<td><?= h($row['name']) ?></td>
<td><?= (int)$row['owned_qty'] ?></td>
<td class="<?= (int)$row['need_qty'] > 0 ? 'need-positive' : '' ?>"><?= (int)$row['need_qty'] ?></td>
<td class="<?= (int)$row['extra_qty'] > 0 ? 'extra-positive' : '' ?>"><?= (int)$row['extra_qty'] ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</section>
<?php endforeach; ?>
<?php endif; ?>

</section>
<section id="tab-trades" class="tab-panel" data-tab-panel="trades">
<p class="tab-intro">
    Your recommended trades come from the same clan-wide optimizer used by Admin. V8.35 only recommends executable reciprocal trades where both cards are in the same Clash card group.
</p>

<h2>Optimized trades<?php if ($tradePlayer): ?> — <?= h((string)$tradePlayer['display_name']) ?><?php endif; ?></h2>

<?php if ($viewPlayerId !== $playerId): ?>
<div class="player-opt-note">
    <strong>My Cards is currently showing <?= h((string)($viewPlayer['display_name'] ?? 'another player')) ?>,</strong>
    but Trades always uses your logged-in identity:
    <strong><?= h((string)($tradePlayer['display_name'] ?? '')) ?></strong>.
</div>
<?php endif; ?>

<?php if (!$optimizerEligible): ?>
<div class="player-opt-warning">
    This player is not currently eligible for optimized trading because their saved inventory is incomplete.
    Scan/save all card pages first.
</div>
<?php elseif (!$optimizedRelationships): ?>
<p>No useful optimized transfers are currently available for this player.</p>
<?php else: ?>
<?php
    $optimizedGiveUnits = 0;
    $optimizedReceiveUnits = 0;
    $optimizedReciprocalCount = 0;

    foreach ($optimizedRelationships as $relationship) {
        if (!empty($relationship['reciprocal'])) {
            $optimizedReciprocalCount++;
        }
        foreach ($relationship['transfers'] as $transfer) {
            if ((int)$transfer['from_player_id'] === $playerId) {
                $optimizedGiveUnits += (int)$transfer['qty'];
            }
            if ((int)$transfer['to_player_id'] === $playerId) {
                $optimizedReceiveUnits += (int)$transfer['qty'];
            }
        }
    }
?>

<div class="player-opt-summary">
    <div class="player-opt-metric"><strong><?= count($optimizedRelationships) ?></strong><span>people to coordinate with</span></div>
    <div class="player-opt-metric"><strong><?= $optimizedGiveUnits ?></strong><span>cards to give</span></div>
    <div class="player-opt-metric"><strong><?= $optimizedReceiveUnits ?></strong><span>cards to receive</span></div>
    <div class="player-opt-metric"><strong><?= $optimizedReciprocalCount ?></strong><span>reciprocal relationships</span></div>
</div>

<div class="player-opt-note">
    This is your slice of the <strong>group-constrained clan plan</strong>. Every recommendation below has a reciprocal card in the same group, so it can be created as a real in-game trade.
</div>

<h3>My optimized network</h3>
<div class="player-network-wrap">
    <svg id="playerOptimizedGraph" viewBox="0 0 1000 440" role="img" aria-label="Optimized trade network for logged-in player"></svg>
</div>

<h3>Recommended handoffs</h3>
<div class="optimized-relations">
<?php foreach ($optimizedRelationships as $relationship): ?>
<?php
    $otherPlayerName = (int)$relationship['player_a_id'] === $playerId
        ? (string)$relationship['player_b_name']
        : (string)$relationship['player_a_name'];
?>
<article class="optimized-relation" data-relationship-key="<?= h((string)$relationship['pair_key']) ?>" tabindex="0">
    <h3>
        <?= h((string)$tradePlayer['display_name']) ?> ↔ <?= h($otherPlayerName) ?>
        <?php if (!empty($relationship['reciprocal'])): ?><span class="reciprocal-badge">reciprocal</span><?php endif; ?>
    </h3>
    <?php foreach (($relationship['trade_groups'] ?? []) as $tradeGroup): ?>
    <div class="optimized-transfer">
        <strong><?= h((string)$tradeGroup['category']) ?></strong> —
        <?= (int)$tradeGroup['trade_count'] ?> valid trade<?= (int)$tradeGroup['trade_count'] === 1 ? '' : 's' ?>
    </div>
    <?php endforeach; ?>
</article>
<?php endforeach; ?>
</div>

<h3>Trade detail</h3>
<div id="playerOptimizerDetail" class="optimizer-detail empty">
    Click a person, graph edge, or recommended handoff to see the exact optimized exchange.
</div>
<?php endif; ?>

<h2>Trade workflow<?php if ($tradePlayer): ?> — <?= h((string)$tradePlayer['display_name']) ?><?php endif; ?></h2>

<h3>Open proposals <span class="pill"><?= count($openTrades) ?></span></h3>
<?php if (!$openTrades): ?>
<p>No open trade proposals for this player.</p>
<?php else: ?>
<div class="trade-ledger">
<?php foreach ($openTrades as $trade): ?>
<?php
    $isInitiator = (int)$trade['initiator_player_id'] === (int)$playerId;
    $counterparty = $isInitiator
        ? (string)$trade['other_player_name']
        : (string)$trade['initiator_player_name'];

    $giveName = $isInitiator
        ? (string)$trade['give_card_name']
        : (string)$trade['receive_card_name'];
    $giveQty = $isInitiator
        ? (int)$trade['give_qty']
        : (int)$trade['receive_qty'];

    $receiveName = $isInitiator
        ? (string)$trade['receive_card_name']
        : (string)$trade['give_card_name'];
    $receiveQty = $isInitiator
        ? (int)$trade['receive_qty']
        : (int)$trade['give_qty'];
?>
<div class="trade-ledger-card">
    <div class="trade-ledger-head">
        <div>
            <strong><?= h($counterparty) ?></strong>
            <span class="trade-status trade-status-proposed">Proposed</span>
        </div>
        <div class="trade-actions">
            <form method="post">
                <input type="hidden" name="complete_trade" value="1">
                <input type="hidden" name="trade_id" value="<?= (int)$trade['id'] ?>">
                <input type="hidden" name="view_player_id" value="<?= (int)$playerId ?>">
                <button type="submit" class="primary"
                    onclick="return confirm('Complete this trade and update both players’ inventories?')">
                    Mark completed
                </button>
            </form>
            <form method="post">
                <input type="hidden" name="cancel_trade" value="1">
                <input type="hidden" name="trade_id" value="<?= (int)$trade['id'] ?>">
                <input type="hidden" name="view_player_id" value="<?= (int)$playerId ?>">
                <button type="submit">Cancel</button>
            </form>
        </div>
    </div>

    <div class="trade-option">
        <strong><?= h((string)$tradePlayer['display_name']) ?> gives:</strong>
        <?= $giveQty ?> × <?= h($giveName) ?>
        &nbsp;→&nbsp;
        <strong>receives:</strong>
        <?= $receiveQty ?> × <?= h($receiveName) ?>
    </div>

    <div class="trade-ledger-meta">
        Proposal #<?= (int)$trade['id'] ?> · created <?= h((string)$trade['created_at']) ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<h3>Trade history <span class="pill"><?= count($tradeHistory) ?></span></h3>
<?php if (!$tradeHistory): ?>
<p>No completed or cancelled trades yet.</p>
<?php else: ?>
<div class="trade-ledger">
<?php foreach ($tradeHistory as $trade): ?>
<?php
    $isInitiator = (int)$trade['initiator_player_id'] === (int)$playerId;
    $counterparty = $isInitiator
        ? (string)$trade['other_player_name']
        : (string)$trade['initiator_player_name'];

    $giveName = $isInitiator
        ? (string)$trade['give_card_name']
        : (string)$trade['receive_card_name'];
    $giveQty = $isInitiator
        ? (int)$trade['give_qty']
        : (int)$trade['receive_qty'];

    $receiveName = $isInitiator
        ? (string)$trade['receive_card_name']
        : (string)$trade['give_card_name'];
    $receiveQty = $isInitiator
        ? (int)$trade['receive_qty']
        : (int)$trade['give_qty'];

    $status = (string)$trade['status'];
?>
<div class="trade-ledger-card">
    <div class="trade-ledger-head">
        <strong><?= h($counterparty) ?></strong>
        <span class="trade-status trade-status-<?= h($status) ?>"><?= h($status) ?></span>
    </div>

    <div class="trade-option">
        <strong>Give:</strong> <?= $giveQty ?> × <?= h($giveName) ?>
        &nbsp;→&nbsp;
        <strong>Receive:</strong> <?= $receiveQty ?> × <?= h($receiveName) ?>
    </div>

    <div class="trade-ledger-meta">
        Proposal #<?= (int)$trade['id'] ?> · updated <?= h((string)$trade['updated_at']) ?>
    </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

</section>

<section id="tab-help" class="tab-panel" data-tab-panel="help">
    <div class="howto-hero">
        <h2>How to use Clash Cards Matchmaker</h2>
        <p>
            The app compares saved card inventories and recommends only trades that can actually be
            made in Clash of Clans. Both sides of a recommended trade are always from the same card group.
        </p>
    </div>

    <div class="howto-grid">
        <article class="howto-card">
            <div class="howto-step">
                <div class="howto-number">1</div>
                <div>
                    <h3>Choose your player</h3>
                    <p>Select or enter your Clash player name when you open the site.</p>
                    <p class="howto-mini">Use <strong>Log out / change player</strong> at the top whenever you need to switch accounts.</p>
                </div>
            </div>
        </article>

        <article class="howto-card">
            <div class="howto-step">
                <div class="howto-number">2</div>
                <div>
                    <h3>Take five screenshots</h3>
                    <p>Open the Clash of Cards event and capture the five card pages so all 60 card slots are represented.</p>
                    <p class="howto-mini">Keep the full card grid visible. The screenshots can be selected together in any order.</p>
                </div>
            </div>
        </article>

        <article class="howto-card">
            <div class="howto-step">
                <div class="howto-number">3</div>
                <div>
                    <h3>Scan the screenshots</h3>
                    <p>Open <strong>Scan Cards</strong>, select the screenshots, and click <strong>Analyze screenshots</strong>.</p>
                    <p class="howto-mini">OCR and image analysis run in your browser. The screenshots themselves are not saved to the server.</p>
                </div>
            </div>
        </article>

        <article class="howto-card">
            <div class="howto-step">
                <div class="howto-number">4</div>
                <div>
                    <h3>Review before saving</h3>
                    <p>Check the detected player name and any quantities marked low-confidence or manual.</p>
                    <p class="howto-mini">If the scanner is unsure, correct the quantity before saving. Your correction becomes the inventory value used by matchmaking.</p>
                </div>
            </div>
        </article>

        <article class="howto-card">
            <div class="howto-step">
                <div class="howto-number">5</div>
                <div>
                    <h3>Save your inventory</h3>
                    <p>Save the reviewed scan. <strong>My Cards</strong> then shows the inventory currently stored for that player.</p>
                    <p class="howto-mini">For the optimizer to use a player, the database must contain all 60 card rows for that inventory.</p>
                </div>
            </div>
        </article>

        <article class="howto-card">
            <div class="howto-step">
                <div class="howto-number">6</div>
                <div>
                    <h3>Open Trades</h3>
                    <p>The <strong>Trades</strong> tab shows your part of the clan-wide optimized trading plan.</p>
                    <p class="howto-mini">The graph and recommended handoffs only show players involved in executable same-group trades.</p>
                </div>
            </div>
        </article>

        <article class="howto-card">
            <div class="howto-step">
                <div class="howto-number">7</div>
                <div>
                    <h3>Create a trade offer</h3>
                    <p>Choose one card you will <strong>offer</strong> and one card you want to <strong>receive</strong>.</p>
                    <p class="howto-mini">The game requires both cards to be in the same group: Elixir, Dark Elixir, Builder Base, or Super.</p>
                </div>
            </div>
        </article>

        <article class="howto-card">
            <div class="howto-step">
                <div class="howto-number">8</div>
                <div>
                    <h3>Complete the trade</h3>
                    <p>After the trade happens in Clash of Clans, mark it completed in the app so both saved inventories are updated.</p>
                    <p class="howto-mini">If the trade does not happen, cancel the proposal instead.</p>
                </div>
            </div>
        </article>
    </div>

    <div class="howto-good">
        <strong>What “optimized” means:</strong>
        the app looks across all eligible players and tries to create the greatest useful set of
        reciprocal trades. A recommendation is only counted when both players can exchange cards
        from the same Clash card group.
    </div>

    <div class="howto-note">
        <strong>Important:</strong>
        the app does not make trades inside Clash of Clans for you. It tells you which player to
        coordinate with and which card to offer/request. You still create the actual trade in the game.
    </div>
</section>

<?php if ($optimizerEligible && $optimizedRelationships): ?>
<script>
(function(){
    const playerId=<?= (int)$playerId ?>;
    const playerName=<?= json_encode((string)($tradePlayer['display_name'] ?? '')) ?>;
    const relationships=<?= json_encode($optimizedRelationships, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const svg=document.getElementById('playerOptimizedGraph');
    const detail=document.getElementById('playerOptimizerDetail');
    if(!svg||!relationships.length)return;
    const ns='http://www.w3.org/2000/svg';
    const make=(tag,attrs={})=>{const el=document.createElementNS(ns,tag);Object.entries(attrs).forEach(([k,v])=>el.setAttribute(k,String(v)));return el;};
    const byKey=new Map(relationships.map(r=>[String(r.pair_key),r]));
    const otherFor=r=>Number(r.player_a_id)===playerId?{id:Number(r.player_b_id),name:String(r.player_b_name)}:{id:Number(r.player_a_id),name:String(r.player_a_name)};
    const esc=v=>String(v).replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));

    function addLabel(x,y,lines,key){
        const g=make('g'); const text=make('text',{x,y,class:'pgraph-edge-label'});
        lines.forEach((line,i)=>{const span=make('tspan',{x,dy:i===0?'0':'1.3em'});span.textContent=line;text.appendChild(span);});
        g.appendChild(text);svg.appendChild(g);const b=text.getBBox();
        g.insertBefore(make('rect',{x:b.x-6,y:b.y-4,width:b.width+12,height:b.height+8,rx:5,ry:5,class:'pgraph-label-bg'}),text);
        g.addEventListener('click',()=>showDetail(key));
    }
    function addNode(id,name,x,y,center=false,key=null){
        const g=make('g');g.append(make('circle',{cx:x,cy:y,r:center?42:38,class:'pgraph-node '+(center?'pgraph-node-center':'')}),make('text',{x,y,class:'pgraph-node-label'}));
        g.lastChild.textContent=name.length>14?name.slice(0,13)+'…':name;svg.appendChild(g);if(key)g.addEventListener('click',()=>showDetail(key));
    }

    const center={x:500,y:220},others=relationships.map(r=>({...otherFor(r),relationship:r})),radius=180,pos=new Map();
    others.forEach((o,i)=>{const a=-Math.PI/2+(Math.PI*2*i/others.length);pos.set(o.id,{x:center.x+Math.cos(a)*radius,y:center.y+Math.sin(a)*radius});});
    others.forEach(o=>{
        const p=pos.get(o.id),r=o.relationship,key=String(r.pair_key),line=make('line',{x1:center.x,y1:center.y,x2:p.x,y2:p.y,class:'pgraph-edge'});
        line.addEventListener('click',()=>showDetail(key));svg.appendChild(line);
        addLabel(center.x+(p.x-center.x)*.65,center.y+(p.y-center.y)*.65,r.transfers.map(t=>(Number(t.from_player_id)===playerId?'→':'←')+` ${t.qty}× ${t.card_name}`),key);
    });
    addNode(playerId,playerName,center.x,center.y,true);
    others.forEach(o=>{const p=pos.get(o.id);addNode(o.id,o.name,p.x,p.y,false,String(o.relationship.pair_key));});

    function showDetail(key){
        const r=byKey.get(String(key));if(!r||!detail)return;
        document.querySelectorAll('.optimized-relation').forEach(card=>card.classList.toggle('selected',card.dataset.relationshipKey===String(key)));
        const outgoing=r.transfers.filter(t=>Number(t.from_player_id)===playerId),incoming=r.transfers.filter(t=>Number(t.to_player_id)===playerId),other=otherFor(r);
        const lines=list=>list.length?list.map(t=>`<div class="optimized-transfer">${Number(t.qty)} × <strong>${esc(t.card_name)}</strong></div>`).join(''):'<div class="player-meta">None</div>';
        const groups=(r.trade_groups||[]).map(group=>{
          const groupOutgoing=(group.transfers||[]).filter(t=>Number(t.from_player_id)===playerId);
          const groupIncoming=(group.transfers||[]).filter(t=>Number(t.to_player_id)===playerId);
          return {...group,outgoing:groupOutgoing,incoming:groupIncoming};
        }).filter(group=>group.outgoing.length&&group.incoming.length);

        const action=groups.map((group,groupIndex)=>{
          const giveChoices=group.outgoing.map((give,i)=>`
            <label class="trade-choice">
              <input type="radio" name="optimizer_give_${groupIndex}" value="${i}" ${i===0?'checked':''}>
              <span class="trade-choice-card">
                <strong>${Number(give.qty)} × ${esc(give.card_name)}</strong>
                <small>Offer to ${esc(other.name)}</small>
              </span>
            </label>`).join('');

          const receiveChoices=group.incoming.map((receive,i)=>`
            <label class="trade-choice">
              <input type="radio" name="optimizer_receive_${groupIndex}" value="${i}" ${i===0?'checked':''}>
              <span class="trade-choice-card">
                <strong>${Number(receive.qty)} × ${esc(receive.card_name)}</strong>
                <small>Request from ${esc(other.name)}</small>
              </span>
            </label>`).join('');

          return `
            <div class="trade-builder" data-trade-builder data-group-index="${groupIndex}">
              <h4>${esc(group.category)} Trade Offer</h4>
              <div class="trade-builder-grid">
                <div class="trade-choice-panel">
                  <h5>Card to offer</h5>
                  <div class="trade-choice-list">${giveChoices}</div>
                </div>
                <div class="trade-arrow">→</div>
                <div class="trade-choice-panel">
                  <h5>Select ${esc(group.category)} card to receive</h5>
                  <div class="trade-choice-list">${receiveChoices}</div>
                </div>
              </div>
              <div class="trade-builder-summary" data-trade-summary></div>
              <form method="post" data-trade-form>
                <input type="hidden" name="propose_trade" value="1">
                <input type="hidden" name="view_player_id" value="${playerId}">
                <input type="hidden" name="other_player_id" value="${Number(other.id)}">
                <input type="hidden" name="give_card_id">
                <input type="hidden" name="give_qty">
                <input type="hidden" name="receive_card_id">
                <input type="hidden" name="receive_qty">
                <div class="trade-builder-actions">
                  <button type="submit" class="primary">Create ${esc(group.category)} Trade Offer</button>
                </div>
              </form>
            </div>`;
        }).join('');

        detail.className='optimizer-detail';
        detail.innerHTML=`<h3>${esc(playerName)} ↔ ${esc(other.name)} <span class="reciprocal-badge">same-group only</span></h3>${action || '<div class="one-way-explain">No executable same-group trade is available in this relationship.</div>'}`;

        detail.querySelectorAll('[data-trade-builder]').forEach(builder=>{
          const groupIndex=Number(builder.dataset.groupIndex);
          const group=groups[groupIndex];
          const form=builder.querySelector('[data-trade-form]');
          const summary=builder.querySelector('[data-trade-summary]');

          const refresh=()=>{
            const giveIndex=Number(builder.querySelector(`input[name="optimizer_give_${groupIndex}"]:checked`)?.value ?? -1);
            const receiveIndex=Number(builder.querySelector(`input[name="optimizer_receive_${groupIndex}"]:checked`)?.value ?? -1);
            const give=group.outgoing[giveIndex];
            const receive=group.incoming[receiveIndex];
            const button=form.querySelector('button[type="submit"]');

            if(!give||!receive){
              button.disabled=true;
              summary.textContent='Choose one card to offer and one card to receive.';
              return;
            }

            if(give.category!==receive.category || give.category!==group.category){
              button.disabled=true;
              summary.textContent='Invalid cross-group pairing blocked.';
              return;
            }

            button.disabled=false;
            form.querySelector('input[name="give_card_id"]').value=String(give.card_id);
            form.querySelector('input[name="give_qty"]').value='1';
            form.querySelector('input[name="receive_card_id"]').value=String(receive.card_id);
            form.querySelector('input[name="receive_qty"]').value='1';
            summary.innerHTML=`<strong>You offer:</strong> 1 × ${esc(give.card_name)} &nbsp; → &nbsp; <strong>You request:</strong> 1 × ${esc(receive.card_name)}`;
          };

          builder.querySelectorAll('input[type="radio"]').forEach(input=>input.addEventListener('change',refresh));
          refresh();
        });

        detail.scrollIntoView({behavior:'smooth',block:'nearest'});
    }
    document.querySelectorAll('.optimized-relation[data-relationship-key]').forEach(card=>{const open=()=>showDetail(card.dataset.relationshipKey);card.addEventListener('click',open);card.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();open();}});});
})();
</script>
<?php endif; ?>

<script>
window.CLASH_KNOWN_PLAYERS = <?= json_encode(
    $knownPlayerNames,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) ?>;
</script>
<script>
(function(){
 const allowed=new Set(['scan','cards','trades','help']); const serverDefault=<?= json_encode($activeTab) ?>;
 function requested(){const p=new URLSearchParams(location.search),t=p.get('tab');return allowed.has(t)?t:serverDefault;}
 function activate(t,push=true){if(!allowed.has(t))t='scan';document.querySelectorAll('[data-tab-panel]').forEach(x=>{const a=x.dataset.tabPanel===t;x.classList.toggle('active',a);x.hidden=!a;});document.querySelectorAll('.tab-button[data-tab]').forEach(x=>{const a=x.dataset.tab===t;x.classList.toggle('active',a);x.setAttribute('aria-selected',a?'true':'false');});if(push){const u=new URL(location.href);u.searchParams.set('tab',t);history.pushState({tab:t},'',u);}}
 document.querySelectorAll('.tab-button[data-tab]').forEach(b=>b.addEventListener('click',()=>activate(b.dataset.tab)));window.addEventListener('popstate',()=>activate(requested(),false));activate(requested(),false);
})();

window.CLASH_CARDS = <?= json_encode(
    array_map(
        fn($card) => [
            'id' => (int)$card['id'],
            'name' => (string)$card['name'],
            'category' => (string)($card['category'] ?? 'Other'),
        ],
        $cards
    ),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
) ?>;
</script>
<!-- Tesseract is used ONLY for the small player-name crop, not card detection. -->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@7/dist/tesseract.min.js"></script>
<script src="js/card-scanner.js?v=8.33"></script>

<?php endif; ?>
</body>
</html>

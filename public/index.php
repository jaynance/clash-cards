<?php
declare(strict_types=1);

session_start();

$configPath = dirname(__DIR__) . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    exit('Missing config.php. Copy config.example.php to config.php and fill in the database settings.');
}

$config = require $configPath;

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/InventoryService.php';
require dirname(__DIR__) . '/src/MatchmakingService.php';
require dirname(__DIR__) . '/src/TradeService.php';

$pdo = Database::connect($config['db']);
$inventoryService = new InventoryService($pdo);
$matchmakingService = new MatchmakingService($pdo);
$tradeService = new TradeService($pdo);

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['display_name'])) {
    $_SESSION['player_id'] = $inventoryService->getOrCreatePlayer($_POST['display_name']);
    $_SESSION['display_name'] = trim($_POST['display_name']);
    header('Location: index.php');
    exit;
}

$playerId = isset($_SESSION['player_id']) ? (int)$_SESSION['player_id'] : null;

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
    $tradeViewPlayerId = max(0, (int)($_POST['view_player_id'] ?? 0));

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
            'Location: index.php?view_player_id=' . $tradeViewPlayerId .
            '&trade=proposed&trade_id=' . $proposalId . '&tab=trades'
        );
        exit;
    } catch (Throwable $e) {
        $message = 'Trade could not be proposed: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $playerId && isset($_POST['complete_trade'])) {
    $tradeViewPlayerId = max(0, (int)($_POST['view_player_id'] ?? 0));
    $tradeId = max(0, (int)($_POST['trade_id'] ?? 0));

    try {
        $tradeService->completeProposal($tradeId, $tradeViewPlayerId);
        header(
            'Location: index.php?view_player_id=' . $tradeViewPlayerId .
            '&trade=completed&trade_id=' . $tradeId . '&tab=trades'
        );
        exit;
    } catch (Throwable $e) {
        $message = 'Trade could not be completed: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $playerId && isset($_POST['cancel_trade'])) {
    $tradeViewPlayerId = max(0, (int)($_POST['view_player_id'] ?? 0));
    $tradeId = max(0, (int)($_POST['trade_id'] ?? 0));

    try {
        $tradeService->cancelProposal($tradeId, $tradeViewPlayerId);
        header(
            'Location: index.php?view_player_id=' . $tradeViewPlayerId .
            '&trade=cancelled&trade_id=' . $tradeId . '&tab=trades'
        );
        exit;
    } catch (Throwable $e) {
        $message = 'Trade could not be cancelled: ' . $e->getMessage();
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
$matches = $viewPlayerId ? $matchmakingService->findReciprocalTrades($viewPlayerId) : [];
$trades = $viewPlayerId ? $tradeService->listForPlayer($viewPlayerId) : [];

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

// Group reciprocal combinations by player so the user sees one useful card per
// person instead of a long flat list of repeated names.
$tradeGroups = [];
foreach ($matches as $match) {
    $otherId = (int)$match['other_player_id'];

    if (!isset($tradeGroups[$otherId])) {
        $tradeGroups[$otherId] = [
            'other_player_id' => $otherId,
            'other_player_name' => (string)$match['other_player_name'],
            'options' => [],
        ];
    }

    $tradeGroups[$otherId]['options'][] = $match;
}

uasort(
    $tradeGroups,
    fn(array $a, array $b) => count($b['options']) <=> count($a['options'])
        ?: strcasecmp($a['other_player_name'], $b['other_player_name'])
);

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
<!-- Workflow build: V8.20 Tabbed Player UI -->
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
#detectedReview{margin-top:22px}.confidence{font-size:.85rem;font-weight:700}.confidence-high{color:#246b2d}.confidence-medium{color:#7a5a00}.confidence-low{color:#9b2c2c}
details{margin-top:18px}pre{white-space:pre-wrap;word-break:break-word;background:#f4f4f6;border:1px solid #ddd;border-radius:8px;padding:12px;max-height:360px;overflow:auto}
@media(max-width:700px){.summary-grid{grid-template-columns:1fr}th,td{padding:9px 8px}}
.app-header{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:14px}.app-header h1{margin:0}.player-chip{background:#f3f6fb;border:1px solid #ccd7e8;border-radius:999px;padding:8px 13px;white-space:nowrap}.tabs{display:flex;gap:6px;border-bottom:1px solid #d9dce3;margin:18px 0 20px;overflow-x:auto}.tab-button{appearance:none;border:0;border-bottom:3px solid transparent;background:transparent;padding:11px 16px;margin:0;color:#555;font:inherit;font-weight:750;cursor:pointer;white-space:nowrap}.tab-button:hover{background:#f6f7f9;color:#222}.tab-button.active{color:#244f91;border-bottom-color:#315da8;background:#f5f8ff}.tab-panel{display:none}.tab-panel.active{display:block}.tab-intro{color:#666;margin-top:-8px;margin-bottom:18px}.admin-tab-link{margin-left:auto;text-decoration:none;color:#555;font-weight:750;padding:11px 16px;white-space:nowrap}.admin-tab-link:hover{background:#f6f7f9;color:#222}@media(max-width:700px){.player-chip{white-space:normal}.tabs{gap:0}.tab-button,.admin-tab-link{padding:10px 12px}}
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
    <div class="player-chip">Playing as: <strong><?= h((string)($_SESSION['display_name'] ?? '')) ?></strong></div>
</header>
<nav class="tabs" aria-label="Player workflow">
    <button type="button" class="tab-button" data-tab="scan">📷 Scan Cards</button>
    <button type="button" class="tab-button" data-tab="cards">🃏 My Cards</button>
    <button type="button" class="tab-button" data-tab="trades">🤝 Trades</button>
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
            Analyze screenshots to verify all five pages before saving.
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
<section id="tab-trades" class="tab-panel" data-tab-panel="trades"><p class="tab-intro">Find reciprocal matches and manage trade proposals.</p><h2>Reciprocal trade matches<?php if ($viewPlayer): ?> — <?= h((string)$viewPlayer['display_name']) ?><?php endif; ?></h2>
<?php if (!$tradeGroups): ?>
<p>No reciprocal trades found yet for this player.</p>
<?php else: foreach ($tradeGroups as $group): ?>
<div class="trade-card">
    <strong><?= h($group['other_player_name']) ?></strong>
    <span class="pill"><?= count($group['options']) ?> option<?= count($group['options']) === 1 ? '' : 's' ?></span>

    <?php foreach ($group['options'] as $option): ?>
    <div class="trade-option">
        <form method="post" class="trade-option-form">
            <input type="hidden" name="propose_trade" value="1">
            <input type="hidden" name="view_player_id" value="<?= (int)$viewPlayerId ?>">
            <input type="hidden" name="other_player_id" value="<?= (int)$option['other_player_id'] ?>">
            <input type="hidden" name="give_card_id" value="<?= (int)$option['give_card_id'] ?>">
            <input type="hidden" name="give_qty" value="<?= (int)$option['give_qty'] ?>">
            <input type="hidden" name="receive_card_id" value="<?= (int)$option['receive_card_id'] ?>">
            <input type="hidden" name="receive_qty" value="<?= (int)$option['receive_qty'] ?>">

            <div class="trade-option-text">
                <strong>Give:</strong> <?= (int)$option['give_qty'] ?> × <?= h($option['give_card_name']) ?>
                &nbsp;→&nbsp;
                <strong>Receive:</strong> <?= (int)$option['receive_qty'] ?> × <?= h($option['receive_card_name']) ?>
            </div>

            <button type="submit" class="primary">Propose trade</button>
        </form>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; endif; ?>

<h2>Trade workflow<?php if ($viewPlayer): ?> — <?= h((string)$viewPlayer['display_name']) ?><?php endif; ?></h2>

<h3>Open proposals <span class="pill"><?= count($openTrades) ?></span></h3>
<?php if (!$openTrades): ?>
<p>No open trade proposals for this player.</p>
<?php else: ?>
<div class="trade-ledger">
<?php foreach ($openTrades as $trade): ?>
<?php
    $isInitiator = (int)$trade['initiator_player_id'] === (int)$viewPlayerId;
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
                <input type="hidden" name="view_player_id" value="<?= (int)$viewPlayerId ?>">
                <button type="submit" class="primary"
                    onclick="return confirm('Complete this trade and update both players’ inventories?')">
                    Mark completed
                </button>
            </form>
            <form method="post">
                <input type="hidden" name="cancel_trade" value="1">
                <input type="hidden" name="trade_id" value="<?= (int)$trade['id'] ?>">
                <input type="hidden" name="view_player_id" value="<?= (int)$viewPlayerId ?>">
                <button type="submit">Cancel</button>
            </form>
        </div>
    </div>

    <div class="trade-option">
        <strong><?= h((string)$viewPlayer['display_name']) ?> gives:</strong>
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
    $isInitiator = (int)$trade['initiator_player_id'] === (int)$viewPlayerId;
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
<script>
(function(){
 const allowed=new Set(['scan','cards','trades']); const serverDefault=<?= json_encode($activeTab) ?>;
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
<script src="js/card-scanner.js?v=8.15"></script>

<?php endif; ?>
</body>
</html>

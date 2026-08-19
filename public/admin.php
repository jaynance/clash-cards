<?php
declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    exit('Missing config.php.');
}

$config = require $configPath;

require dirname(__DIR__) . '/src/Production.php';
Production::configure($config['app'] ?? []);

session_start();

require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/AdminService.php';
require dirname(__DIR__) . '/src/GlobalTradeOptimizer.php';
require dirname(__DIR__) . '/src/TradeService.php';

$adminPasswordHash = trim((string)($config['admin']['password_hash'] ?? ''));
if ($adminPasswordHash === '') {
    http_response_code(503);
    exit('Admin password is not configured. Set admin.password_hash in config.php.');
}

if (isset($_POST['admin_logout'])) {
    unset($_SESSION['is_admin']);
    header('Location: admin.php');
    exit;
}

$adminLoginError = null;
if (empty($_SESSION['is_admin'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
        if (password_verify((string)$_POST['admin_password'], $adminPasswordHash)) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = true;
            header('Location: admin.php');
            exit;
        }
        $adminLoginError = 'Incorrect admin password.';
    }

    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Clash Cards — Admin Login</title>
        <style>
            body{font-family:system-ui;background:#f6f7f9;margin:0;padding:24px;color:#202124}
            .box{max-width:440px;margin:10vh auto;background:white;border:1px solid #ddd;border-radius:12px;padding:24px}
            input{width:100%;box-sizing:border-box;padding:10px;margin:8px 0 14px;border:1px solid #bbb;border-radius:7px}
            button{background:#315da8;color:white;border:0;border-radius:7px;padding:10px 14px;font-weight:700;cursor:pointer}
            .error{background:#fff2f2;border:1px solid #dfa5a5;padding:10px;border-radius:8px}
        </style>
    </head>
    <body>
    <div class="box">
        <h1>Player Admin</h1>
        <p>Enter the separate administrator password.</p>
        <?php if ($adminLoginError): ?><p class="error"><?= htmlspecialchars($adminLoginError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="post">
            <label>Admin password
                <input type="password" name="admin_password" autofocus required>
            </label>
            <button type="submit">Enter Admin</button>
        </form>
        <p><div style="display:flex;gap:10px;align-items:center">
        <a href="index.php">← Back to matcher</a>
        <form method="post" style="margin:0">
            <input type="hidden" name="admin_logout" value="1">
            <button type="submit" style="padding:7px 10px">Admin logout</button>
        </form>
    </div></p>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$pdo = Database::connect($config['db']);
$adminService = new AdminService($pdo);
$globalTradeOptimizer = new GlobalTradeOptimizer($pdo);
$tradeService = new TradeService($pdo);

// Temporary V8.18 admin gate: require an existing logged-in player session.
// Replace this with real role-based authorization before exposing the app publicly.
$loggedInPlayerId = isset($_SESSION['player_id']) ? (int)$_SESSION['player_id'] : 0;
if ($loggedInPlayerId <= 0) {
    http_response_code(403);
    exit('Admin page requires a logged-in player session. Return to index.php and select a player first.');
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['admin_csrf'];

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_optimizer_proposal'])) {
    $postedCsrf = (string)($_POST['csrf'] ?? '');

    if (!hash_equals($csrf, $postedCsrf)) {
        $error = 'Trade proposal rejected because the security token was invalid.';
    } else {
        try {
            $proposalId = $tradeService->createProposal(
                max(0, (int)($_POST['initiator_player_id'] ?? 0)),
                max(0, (int)($_POST['other_player_id'] ?? 0)),
                max(0, (int)($_POST['give_card_id'] ?? 0)),
                max(0, (int)($_POST['give_qty'] ?? 0)),
                max(0, (int)($_POST['receive_card_id'] ?? 0)),
                max(0, (int)($_POST['receive_qty'] ?? 0))
            );

            header(
                'Location: admin.php?section=optimizer' .
                '&proposal_created=' . $proposalId
            );
            exit;
        } catch (Throwable $e) {
            Production::report($e, 'Admin optimizer proposal failed');
            $error = 'Optimizer proposal could not be created.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_as_player'])) {
    $postedCsrf = (string)($_POST['csrf'] ?? '');

    if (!hash_equals($csrf, $postedCsrf)) {
        $error = 'Login switch rejected because the security token was invalid.';
    } else {
        $switchPlayerId = max(0, (int)($_POST['player_id'] ?? 0));
        $switchPlayer = $switchPlayerId ? $adminService->getPlayer($switchPlayerId) : null;

        if (!$switchPlayer) {
            $error = 'Player was not found.';
        } else {
            $_SESSION['player_id'] = (int)$switchPlayer['id'];
            $_SESSION['display_name'] = (string)$switchPlayer['display_name'];

            header(
                'Location: index.php?view_player_id=' . (int)$switchPlayer['id'] .
                '&admin_login_as=1'
            );
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_player'])) {
    $postedCsrf = (string)($_POST['csrf'] ?? '');

    if (!hash_equals($csrf, $postedCsrf)) {
        $error = 'Delete request rejected because the security token was invalid.';
    } else {
        $deletePlayerId = max(0, (int)($_POST['player_id'] ?? 0));
        $confirmName = trim((string)($_POST['confirm_name'] ?? ''));
        $player = $deletePlayerId ? $adminService->getPlayer($deletePlayerId) : null;

        if (!$player) {
            $error = 'Player was not found.';
        } elseif ($confirmName !== (string)$player['display_name']) {
            $error = 'Player was not deleted. Type the exact player name to confirm deletion.';
        } else {
            try {
                $deletedName = $adminService->deletePlayer($deletePlayerId);

                if ($deletePlayerId === $loggedInPlayerId) {
                    unset($_SESSION['player_id'], $_SESSION['display_name']);
                }

                header('Location: admin.php?deleted=' . rawurlencode((string)$deletedName));
                exit;
            } catch (Throwable $e) {
                Production::report($e, 'Admin player delete failed');
                $error = 'Player could not be deleted.';
            }
        }
    }
}

if (isset($_GET['deleted']) && $_GET['deleted'] !== '') {
    $message = 'Deleted player ' . (string)$_GET['deleted'] . '.';
}

if (isset($_GET['proposal_created'])) {
    $proposalId = max(0, (int)$_GET['proposal_created']);
    if ($proposalId > 0) {
        $message = 'Created optimized trade proposal #' . $proposalId . '.';
    }
}

$adminSection = isset($_GET['section']) ? (string)$_GET['section'] : 'players';
if (!in_array($adminSection, ['players', 'optimizer'], true)) {
    $adminSection = 'players';
}

$optimization = $adminSection === 'optimizer'
    ? $globalTradeOptimizer->optimize()
    : null;

$players = $adminService->getPlayers();
$selectedPlayerId = isset($_GET['player_id']) ? max(0, (int)$_GET['player_id']) : 0;
$selectedPlayer = $selectedPlayerId ? $adminService->getPlayer($selectedPlayerId) : null;
$selectedCards = $selectedPlayer ? $adminService->getPlayerCards($selectedPlayerId) : [];
$tradeCounts = $selectedPlayer
    ? $adminService->getPlayerTradeCounts($selectedPlayerId)
    : ['proposed' => 0, 'completed' => 0, 'cancelled' => 0];

$groupedCards = [];
$needCount = 0;
$extraCount = 0;
$completeCount = 0;

foreach ($selectedCards as $card) {
    $category = $card['category'] ?: 'Other';
    $groupedCards[$category][] = $card;

    if ((int)$card['need_qty'] > 0) {
        $needCount++;
    } elseif ((int)$card['extra_qty'] > 0) {
        $extraCount++;
    } else {
        $completeCount++;
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Clash Cards — Admin</title>
<!-- Production build: V8.36 Release Hardening -->
<style>
:root{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#202124;background:#f6f7f9}
*{box-sizing:border-box}body{margin:0}.shell{max-width:1240px;margin:0 auto;padding:24px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:18px}.topbar h1{margin:0}.topbar a{color:#315da8;text-decoration:none}.notice{padding:11px 14px;border-radius:9px;margin:12px 0}.notice-ok{background:#eef9f0;border:1px solid #a6cfad}.notice-error{background:#fff2f2;border:1px solid #dfa5a5}.warning{background:#fff8e5;border:1px solid #dfc981;color:#5e4a00;padding:11px 14px;border-radius:9px;margin-bottom:18px}.trade-status{display:inline-block;font-size:.78rem;font-weight:800;padding:4px 8px;border-radius:999px;text-transform:uppercase;letter-spacing:.03em}.trade-status-completed{background:#e8f6eb;color:#216b2a}.layout{display:grid;grid-template-columns:minmax(280px,360px) 1fr;gap:20px}.panel{background:white;border:1px solid #ddd;border-radius:12px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04)}.players{max-height:76vh;overflow:auto}.player-row{display:block;padding:11px 12px;border:1px solid #e2e2e2;border-radius:9px;margin:8px 0;color:inherit;text-decoration:none}.player-row:hover{background:#f7f9ff;border-color:#b9c8e5}.player-row.active{background:#eef4ff;border-color:#7596d2}.player-name{font-weight:750}.player-meta{color:#666;font-size:.84rem;margin-top:4px}.summary{display:grid;grid-template-columns:repeat(6,minmax(90px,1fr));gap:8px;margin:12px 0 18px}.metric{background:#f6f7f9;border-radius:9px;padding:10px;text-align:center}.metric strong{display:block;font-size:1.15rem}.metric span{display:block;color:#666;font-size:.8rem;margin-top:2px}.category{margin-top:20px}.category h3{margin:0 0 7px}table{border-collapse:collapse;width:100%;background:white}th,td{border-bottom:1px solid #e6e6e6;padding:8px;text-align:left}th{background:#fafafa;position:sticky;top:0}td.num{text-align:right;font-variant-numeric:tabular-nums}.need{font-weight:700;color:#a33}.extra{font-weight:700;color:#18733a}.delete-zone{margin-top:26px;padding:16px;border:1px solid #d9a2a2;background:#fff7f7;border-radius:10px}.delete-zone h3{color:#9a2525;margin-top:0}.delete-form{display:flex;gap:9px;align-items:end;flex-wrap:wrap}.delete-form label{display:grid;gap:5px;flex:1;min-width:220px}.delete-form input{padding:8px;border:1px solid #bbb;border-radius:7px}.danger{background:#b3261e;color:white;border:0;border-radius:7px;padding:9px 13px;font-weight:700;cursor:pointer}.empty{color:#666}@media(max-width:850px){.layout{grid-template-columns:1fr}.players{max-height:none}.summary{grid-template-columns:repeat(3,1fr)}}
.admin-tabs{display:flex;gap:7px;border-bottom:1px solid #d9dce3;margin:4px 0 20px}.admin-tab{display:inline-block;padding:10px 14px;text-decoration:none;color:#555;font-weight:750;border-bottom:3px solid transparent}.admin-tab.active{background:#f5f8ff;border-bottom-color:#315da8;color:#244f91}.optimizer-summary{display:grid;grid-template-columns:repeat(6,minmax(100px,1fr));gap:10px;margin:16px 0}.optimizer-card{background:white;border:1px solid #ddd;border-radius:11px;padding:12px;text-align:center}.optimizer-card strong{font-size:1.35rem;display:block}.optimizer-card span{color:#666;font-size:.82rem}.progress{height:12px;background:#e7e9ee;border-radius:999px;overflow:hidden;margin:8px 0 3px}.progress-fill{height:100%;background:#4777bd}.plan-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.handoff{border:1px solid #ddd;border-radius:11px;background:white;padding:13px;cursor:pointer;transition:border-color .15s,box-shadow .15s,transform .15s}.handoff:hover{border-color:#8eabd4;box-shadow:0 2px 8px rgba(49,93,168,.12);transform:translateY(-1px)}.handoff.selected{border-color:#315da8;box-shadow:0 0 0 2px rgba(49,93,168,.13)}.handoff-reciprocal{border-color:#9ab7df}.reciprocal-badge{display:inline-block;margin-left:7px;padding:2px 7px;border-radius:999px;background:#e8f1ff;color:#244f91;font-size:.68rem;text-transform:uppercase;letter-spacing:.04em;vertical-align:middle}.graph-controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0}.graph-controls select{padding:7px 9px;border:1px solid #bbb;border-radius:7px;background:white}.graph-legend{font-size:.84rem;color:#666}.graph-edge.dim,.graph-node.dim,.graph-edge-label.dim,.graph-node-label.dim{opacity:.12}.graph-edge.focus{stroke-width:4}.graph-node.focus{stroke-width:4}#playerTradeGraph{width:100%;min-width:720px;height:460px}.handoff h3{margin:0 0 8px}.transfer-line{padding:6px 0;border-top:1px solid #eee}.transfer-line:first-of-type{border-top:0}.optimizer-note{background:#f5f8ff;border:1px solid #c8d6ed;padding:12px 14px;border-radius:9px}.scarce-high{font-weight:800;color:#a33}.graph-wrap{background:white;border:1px solid #ddd;border-radius:12px;padding:12px;margin:14px 0;overflow:auto}#tradeGraph{width:100%;min-width:720px;height:520px}.graph-edge{stroke:#9aa7bd;stroke-width:2}.graph-edge-label{font-size:11px;fill:#555}.graph-node{fill:#f5f8ff;stroke:#315da8;stroke-width:2;cursor:pointer}.graph-edge{cursor:pointer}.graph-edge-label{cursor:pointer}.graph-label-bg{fill:white;stroke:#d7dce5;stroke-width:1;opacity:.96}.relationship-detail{background:white;border:1px solid #b9c9e2;border-radius:12px;padding:16px;margin:16px 0}.relationship-detail.empty-detail{color:#666;border-style:dashed}.relationship-detail h3{margin:0 0 8px}.relationship-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.relationship-side{background:#f8f9fb;border-radius:9px;padding:11px}.proposal-options{margin-top:14px;border-top:1px solid #e4e7ec;padding-top:12px}.proposal-option{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:9px 0;border-top:1px solid #eee}.proposal-option:first-of-type{border-top:0}.proposal-text{flex:1;min-width:300px}.proposal-button{background:#315da8;color:white;border:0;border-radius:7px;padding:8px 12px;font-weight:700;cursor:pointer}.one-way-note{background:#fff8e5;border:1px solid #dfc981;border-radius:8px;padding:10px 12px;margin-top:12px}.relationship-jump{font-size:.85rem;color:#315da8;cursor:pointer;text-decoration:underline}.graph-instruction{font-size:.84rem;color:#666;margin-top:5px}@media(max-width:700px){.relationship-detail-grid{grid-template-columns:1fr}}.graph-node-label{font-size:12px;font-weight:700;text-anchor:middle;dominant-baseline:middle}.excluded{font-size:.88rem;color:#666}.optimizer-table th{position:static}@media(max-width:950px){.optimizer-summary{grid-template-columns:repeat(3,1fr)}.plan-grid{grid-template-columns:1fr}}@media(max-width:600px){.optimizer-summary{grid-template-columns:repeat(2,1fr)}}
</style>
</head>
<body>
<div class="shell">
<div class="topbar">
    <div>
        <h1>Player Admin</h1>
        <div>Logged in as <strong><?= h((string)($_SESSION['display_name'] ?? '')) ?></strong></div>
    </div>
    <a href="index.php">← Back to matcher</a>
</div>

<div class="warning">
    <strong>Admin mode:</strong> this area is protected by the separate administrator password.
    “Log in as player” is still intended for testing trade perspectives.
</div>

<nav class="admin-tabs">
    <a class="admin-tab <?= $adminSection === 'players' ? 'active' : '' ?>" href="admin.php?section=players">Players</a>
    <a class="admin-tab <?= $adminSection === 'optimizer' ? 'active' : '' ?>" href="admin.php?section=optimizer">Optimized Trading</a>
</nav>

<?php if ($message): ?><div class="notice notice-ok"><?= h($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error"><?= h($error) ?></div><?php endif; ?>

<?php if ($adminSection === 'optimizer' && $optimization): ?>
<section>
    <h2>Global Trade Optimizer</h2>
    <p class="optimizer-note">
        This plan changes <strong>nothing</strong> in the database. V8.35 counts only executable in-game trades:
        both players must exchange cards from the <strong>same card group</strong>. Cross-group and one-way handoffs
        are excluded from fulfillment.
    </p>

    <div class="optimizer-summary">
        <div class="optimizer-card">
            <strong><?= (int)$optimization['eligible_player_count'] ?></strong>
            <span>eligible players</span>
        </div>
        <div class="optimizer-card">
            <strong><?= (int)$optimization['players_helped'] ?>/<?= (int)$optimization['players_with_need'] ?></strong>
            <span>players helped</span>
        </div>
        <div class="optimizer-card">
            <strong><?= (int)$optimization['fulfilled_units'] ?></strong>
            <span>need units fulfilled by valid trades</span>
        </div>
        <div class="optimizer-card">
            <strong><?= number_format((float)$optimization['fulfillment_pct'], 1) ?>%</strong>
            <span>need fulfillment</span>
        </div>
        <div class="optimizer-card">
            <strong><?= (int)$optimization['reciprocal_relationship_count'] ?>/<?= (int)$optimization['relationship_count'] ?></strong>
            <span>reciprocal / total handoffs</span>
        </div>
        <div class="optimizer-card">
            <strong><?= (int)$optimization['unmet_units'] ?></strong>
            <span>units still unmet</span>
        </div>
    </div>

    <div class="progress" aria-label="Need fulfillment">
        <div class="progress-fill" style="width:<?= min(100, max(0, (float)$optimization['fulfillment_pct'])) ?>%"></div>
    </div>
    <div class="player-meta">
        <?= (int)$optimization['fulfilled_units'] ?> of <?= (int)$optimization['total_need_units'] ?> needed card units are fulfilled through executable same-group trades.
    </div>

    <?php if ((int)$optimization['excluded_player_count'] > 0): ?>
    <p class="excluded">
        Excluded <?= (int)$optimization['excluded_player_count'] ?> player(s) because their saved inventory does not contain all
        <?= (int)$optimization['card_count'] ?> cards:
        <?php foreach ($optimization['excluded_players'] as $i => $player): ?>
            <?= $i ? ', ' : '' ?><?= h((string)$player['name']) ?>
            (<?= (int)$player['saved_card_rows'] ?>/<?= (int)$optimization['card_count'] ?>)
        <?php endforeach; ?>.
    </p>
    <?php endif; ?>

    <h2>Recommended handoffs</h2>
    <?php if (!$optimization['relationships']): ?>
        <p class="empty">No useful card transfers are currently available.</p>
    <?php else: ?>
    <div class="plan-grid">
        <?php foreach ($optimization['relationships'] as $relationship): ?>
        <article
            class="handoff <?= !empty($relationship['reciprocal']) ? 'handoff-reciprocal' : '' ?>"
            data-relationship-key="<?= h((string)$relationship['pair_key']) ?>"
            tabindex="0"
            role="button"
            aria-label="Inspect optimized relationship between <?= h((string)$relationship['player_a_name']) ?> and <?= h((string)$relationship['player_b_name']) ?>"
        >
            <h3>
                <?= h((string)$relationship['player_a_name']) ?>
                ↔
                <?= h((string)$relationship['player_b_name']) ?>
                <small>(<?= (int)$relationship['unit_count'] ?> card units)</small>
                <?php if (!empty($relationship['reciprocal'])): ?><span class="reciprocal-badge">reciprocal</span><?php endif; ?>
            </h3>

            <?php foreach (($relationship['trade_groups'] ?? []) as $tradeGroup): ?>
            <div class="transfer-line">
                <strong><?= h((string)$tradeGroup['category']) ?></strong>:
                <?= (int)$tradeGroup['trade_count'] ?> executable trade<?= (int)$tradeGroup['trade_count'] === 1 ? '' : 's' ?>
            </div>
            <?php endforeach; ?>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2>Global network</h2>
    <p class="player-meta">
        The full optimized network. Each line is a recommended player-to-player handoff relationship;
        the number is total card units moving between that pair.
    </p>
    <p class="graph-instruction"><strong>Tip:</strong> click a player to open their per-player graph, or click a line to inspect that relationship.</p>
    <div class="graph-wrap">
        <svg id="tradeGraph" viewBox="0 0 1000 520" role="img" aria-label="Global optimized player trade network"></svg>
    </div>

    <h2>Per-player network</h2>
    <div class="graph-controls">
        <label for="graphPlayer"><strong>Player:</strong></label>
        <select id="graphPlayer"></select>
        <span class="graph-legend">Shows only this player's optimized handoffs, with card details on each relationship.</span>
    </div>
    <div class="graph-wrap">
        <svg id="playerTradeGraph" viewBox="0 0 1000 460" role="img" aria-label="Selected player optimized trade network"></svg>
    </div>

    <h2>Relationship detail</h2>
    <div id="relationshipDetail" class="relationship-detail empty-detail">
        Click a recommended handoff or a graph relationship to inspect the cards and create a reciprocal trade proposal.
    </div>

    <h2>Scarcity after optimization</h2>
    <?php
        $scarceRows = array_values(array_filter(
            $optimization['scarcity'],
            static fn(array $row): bool => (int)$row['unmet'] > 0
        ));
    ?>
    <?php if (!$scarceRows): ?>
        <p>All currently needed card units can be covered by extras somewhere in the eligible player pool.</p>
    <?php else: ?>
    <table class="optimizer-table">
        <thead>
        <tr>
            <th>Card</th>
            <th>Category</th>
            <th>Needed</th>
            <th>Extras available</th>
            <th>Fulfilled by valid trades</th>
            <th>Still unmet</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($scarceRows as $row): ?>
        <tr>
            <td><?= h((string)$row['card_name']) ?></td>
            <td><?= h((string)$row['category']) ?></td>
            <td class="num"><?= (int)$row['needed'] ?></td>
            <td class="num"><?= (int)$row['available_extras'] ?></td>
            <td class="num"><?= (int)$row['satisfiable'] ?></td>
            <td class="num scarce-high"><?= (int)$row['unmet'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<script>
(function(){
    const relationships = <?= json_encode($optimization['relationships'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const csrf = <?= json_encode($csrf) ?>;
    const globalSvg = document.getElementById('tradeGraph');
    const playerSvg = document.getElementById('playerTradeGraph');
    const playerSelect = document.getElementById('graphPlayer');
    const detail = document.getElementById('relationshipDetail');
    if (!globalSvg || !relationships.length) return;

    const ns = 'http://www.w3.org/2000/svg';
    const make = (tag, attrs={}) => {
        const el = document.createElementNS(ns, tag);
        Object.entries(attrs).forEach(([k,v]) => el.setAttribute(k, String(v)));
        return el;
    };
    const players = new Map();
    const relationshipByKey = new Map();

    relationships.forEach(r => {
        players.set(Number(r.player_a_id), String(r.player_a_name));
        players.set(Number(r.player_b_id), String(r.player_b_name));
        relationshipByKey.set(String(r.pair_key), r);
    });

    const nodes = Array.from(players, ([id,name]) => ({id,name}))
        .sort((a,b) => a.name.localeCompare(b.name));

    function addSvgLabel(svg, x, y, lines, options={}) {
        const group = make('g', {class:options.groupClass || ''});
        if (options.relationshipKey) group.dataset.relationshipKey = options.relationshipKey;

        const text = make('text', {
            x, y,
            class:options.textClass || 'graph-edge-label',
            'text-anchor':'middle'
        });

        lines.forEach((line, i) => {
            const span = make('tspan', {x, dy:i===0 ? '0' : '1.25em'});
            span.textContent = line;
            text.appendChild(span);
        });
        group.appendChild(text);
        svg.appendChild(group);

        const box = text.getBBox();
        const rect = make('rect', {
            x:box.x-6, y:box.y-4,
            width:box.width+12, height:box.height+8,
            rx:5, ry:5,
            class:'graph-label-bg'
        });
        group.insertBefore(rect, text);
        return group;
    }

    function nodeLabel(svg, node, p, options={}) {
        const group=make('g',{class:'graph-node-group'});
        group.dataset.playerId=String(node.id);
        if(options.relationshipKey) group.dataset.relationshipKey=options.relationshipKey;

        const circle=make('circle',{cx:p.x,cy:p.y,r:38,class:'graph-node'});
        const label=make('text',{x:p.x,y:p.y,class:'graph-node-label'});
        label.textContent=node.name.length>14 ? node.name.slice(0,13)+'…' : node.name;
        group.append(circle,label);
        svg.appendChild(group);

        group.addEventListener('click', (event)=>{
            event.stopPropagation();
            if(options.relationshipKey){
                selectRelationship(options.relationshipKey);
            }else{
                selectPlayer(node.id, true);
            }
        });
        return group;
    }

    function relationshipDirections(r) {
        const aId=Number(r.player_a_id), bId=Number(r.player_b_id);
        const aToB=r.transfers.filter(t=>Number(t.from_player_id)===aId && Number(t.to_player_id)===bId);
        const bToA=r.transfers.filter(t=>Number(t.from_player_id)===bId && Number(t.to_player_id)===aId);
        return {aToB,bToA};
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, ch => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
        })[ch]);
    }

    function proposalForm(r, give, receive) {
        const initiatorId=Number(give.from_player_id);
        const otherId=Number(give.to_player_id);

        return `
          <form method="post">
            <input type="hidden" name="create_optimizer_proposal" value="1">
            <input type="hidden" name="csrf" value="${escapeHtml(csrf)}">
            <input type="hidden" name="initiator_player_id" value="${initiatorId}">
            <input type="hidden" name="other_player_id" value="${otherId}">
            <input type="hidden" name="give_card_id" value="${Number(give.card_id)}">
            <input type="hidden" name="give_qty" value="${Number(give.qty)}">
            <input type="hidden" name="receive_card_id" value="${Number(receive.card_id)}">
            <input type="hidden" name="receive_qty" value="${Number(receive.qty)}">
            <button type="submit" class="proposal-button">Create Trade Proposal</button>
          </form>`;
    }

    function selectRelationship(key) {
        const r=relationshipByKey.get(String(key));
        if(!r || !detail) return;

        document.querySelectorAll('.handoff').forEach(card=>{
            card.classList.toggle('selected',card.dataset.relationshipKey===String(key));
        });

        const groupHtml=(r.trade_groups||[]).map(group=>{
            const trades=(group.trades||[]).map(trade=>`
              <div class="proposal-option">
                <div class="proposal-text">
                  <strong>${escapeHtml(group.category)}</strong>:
                  ${escapeHtml(trade.player_a_name)} gives
                  <strong>1 × ${escapeHtml(trade.player_a_gives_card_name)}</strong>
                  and ${escapeHtml(trade.player_b_name)} gives
                  <strong>1 × ${escapeHtml(trade.player_b_gives_card_name)}</strong>.
                </div>
                ${proposalForm(r,{
                    from_player_id:trade.player_a_id,
                    to_player_id:trade.player_b_id,
                    card_id:trade.player_a_gives_card_id,
                    qty:1
                },{
                    from_player_id:trade.player_b_id,
                    to_player_id:trade.player_a_id,
                    card_id:trade.player_b_gives_card_id,
                    qty:1
                })}
              </div>`).join('');

            return `
              <div class="relationship-side" style="margin-top:10px">
                <strong>${escapeHtml(group.category)} — ${Number(group.trade_count)} valid trade${Number(group.trade_count)===1?'':'s'}</strong>
                ${trades}
              </div>`;
        }).join('');

        detail.className='relationship-detail';
        detail.innerHTML=`
          <h3>${escapeHtml(r.player_a_name)} ↔ ${escapeHtml(r.player_b_name)}
              <span class="reciprocal-badge">same-group only</span></h3>
          <div class="player-meta">${Number(r.trade_count||0)} executable in-game trade${Number(r.trade_count||0)===1?'':'s'} across this relationship.</div>
          <div class="proposal-options">${groupHtml || '<div class="one-way-note">No executable same-group trades.</div>'}</div>`;

        detail.scrollIntoView({behavior:'smooth',block:'nearest'});
    }

    function selectPlayer(playerId, scroll=false) {
        if(!players.has(Number(playerId)) || !playerSelect) return;
        playerSelect.value=String(playerId);
        drawPlayer(Number(playerId));
        if(scroll){
            document.getElementById('playerTradeGraph')?.scrollIntoView({behavior:'smooth',block:'center'});
        }
    }

    function drawGlobal() {
        globalSvg.replaceChildren();
        const cx=500,cy=260,radius=Math.min(205,Math.max(115,48*nodes.length));
        const pos=new Map();
        nodes.forEach((n,i)=>{
            const a=-Math.PI/2+(Math.PI*2*i/nodes.length);
            pos.set(n.id,{x:cx+Math.cos(a)*radius,y:cy+Math.sin(a)*radius});
        });

        relationships.forEach(r=>{
            const a=pos.get(Number(r.player_a_id)),b=pos.get(Number(r.player_b_id));
            const line=make('line',{x1:a.x,y1:a.y,x2:b.x,y2:b.y,class:'graph-edge'});
            line.dataset.relationshipKey=String(r.pair_key);
            line.addEventListener('click',()=>selectRelationship(r.pair_key));
            globalSvg.appendChild(line);

            const group=addSvgLabel(
                globalSvg,
                (a.x+b.x)/2,
                (a.y+b.y)/2-5,
                [String(r.unit_count)+(r.reciprocal?' ↔':'')],
                {relationshipKey:String(r.pair_key)}
            );
            group.addEventListener('click',()=>selectRelationship(r.pair_key));
        });

        nodes.forEach(n=>nodeLabel(globalSvg,n,pos.get(n.id)));
    }

    function drawPlayer(playerId) {
        if(!playerSvg) return;
        playerSvg.replaceChildren();

        const center={x:500,y:230};
        const rels=relationships.filter(r=>Number(r.player_a_id)===playerId||Number(r.player_b_id)===playerId);
        const selected={id:playerId,name:players.get(playerId)||'Player'};
        const others=rels.map(r=>{
            const id=Number(r.player_a_id)===playerId?Number(r.player_b_id):Number(r.player_a_id);
            return {id,name:players.get(id)||String(id),rel:r};
        });

        const pos=new Map([[playerId,center]]);
        const radius=180;
        others.forEach((o,i)=>{
            const angle=-Math.PI/2+(Math.PI*2*i/Math.max(others.length,1));
            pos.set(o.id,{x:center.x+Math.cos(angle)*radius,y:center.y+Math.sin(angle)*radius});
        });

        others.forEach(o=>{
            const b=pos.get(o.id),r=o.rel;
            const line=make('line',{x1:center.x,y1:center.y,x2:b.x,y2:b.y,class:'graph-edge focus'});
            line.dataset.relationshipKey=String(r.pair_key);
            line.addEventListener('click',()=>selectRelationship(r.pair_key));
            playerSvg.appendChild(line);

            // Put labels toward the outer player instead of directly over the
            // center node. This substantially reduces the collisions seen in V8.22.
            const labelX=center.x+(b.x-center.x)*0.64;
            const labelY=center.y+(b.y-center.y)*0.64;
            const lines=r.transfers.map(t=>{
                const arrow=Number(t.from_player_id)===playerId?'→':'←';
                return `${arrow} ${t.qty}× ${t.card_name}`;
            });

            const group=addSvgLabel(
                playerSvg,labelX,labelY,lines,
                {relationshipKey:String(r.pair_key)}
            );
            group.addEventListener('click',()=>selectRelationship(r.pair_key));
        });

        nodeLabel(playerSvg,selected,center);
        others.forEach(o=>nodeLabel(
            playerSvg,o,pos.get(o.id),
            {relationshipKey:String(o.rel.pair_key)}
        ));
    }

    drawGlobal();

    if(playerSelect && playerSvg){
        nodes.forEach(n=>{
            const option=document.createElement('option');
            option.value=String(n.id);
            option.textContent=n.name;
            playerSelect.appendChild(option);
        });
        const loggedInId=<?= (int)$loggedInPlayerId ?>;
        if(players.has(loggedInId)) playerSelect.value=String(loggedInId);
        drawPlayer(Number(playerSelect.value));
        playerSelect.addEventListener('change',()=>drawPlayer(Number(playerSelect.value)));
    }

    document.querySelectorAll('.handoff[data-relationship-key]').forEach(card=>{
        const open=()=>selectRelationship(card.dataset.relationshipKey);
        card.addEventListener('click',open);
        card.addEventListener('keydown',event=>{
            if(event.key==='Enter'||event.key===' '){
                event.preventDefault();
                open();
            }
        });
    });
})();
</script>
<?php else: ?>
<div class="layout">
<section class="panel players">
    <h2>Players <small>(<?= count($players) ?>)</small></h2>

    <?php if (!$players): ?>
        <p class="empty">No players in the database.</p>
    <?php else: ?>
        <?php foreach ($players as $player): ?>
        <a class="player-row <?= (int)$player['id'] === $selectedPlayerId ? 'active' : '' ?>"
           href="admin.php?player_id=<?= (int)$player['id'] ?>">
            <div class="player-name"><?= h((string)$player['display_name']) ?></div>
            <div class="player-meta">
                ID <?= (int)$player['id'] ?> ·
                <?= (int)$player['saved_card_rows'] ?> saved rows ·
                <?= (int)$player['total_owned'] ?> total cards
            </div>
        </a>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<section class="panel">
<?php if (!$selectedPlayer): ?>
    <h2>Select a player</h2>
    <p class="empty">Click a player on the left to inspect their cards or delete the player.</p>
<?php else: ?>
    <div style="display:flex;justify-content:space-between;align-items:start;gap:14px;flex-wrap:wrap">
        <div>
            <h2 style="margin-bottom:4px"><?= h((string)$selectedPlayer['display_name']) ?></h2>
            <p class="player-meta" style="margin-top:0">
                Player ID <?= (int)$selectedPlayer['id'] ?> · created <?= h((string)$selectedPlayer['created_at']) ?>
            </p>
        </div>

        <?php if ((int)$selectedPlayer['id'] === $loggedInPlayerId): ?>
            <span class="trade-status trade-status-completed">Currently logged in</span>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="login_as_player" value="1">
                <input type="hidden" name="player_id" value="<?= (int)$selectedPlayer['id'] ?>">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <button type="submit"
                        style="background:#315da8;color:white;border:0;border-radius:7px;padding:9px 13px;font-weight:700;cursor:pointer">
                    Log in as this player
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div class="summary">
        <div class="metric"><strong><?= count($selectedCards) ?></strong><span>cards</span></div>
        <div class="metric"><strong><?= $needCount ?></strong><span>need</span></div>
        <div class="metric"><strong><?= $extraCount ?></strong><span>extras</span></div>
        <div class="metric"><strong><?= $completeCount ?></strong><span>complete</span></div>
        <div class="metric"><strong><?= (int)$tradeCounts['proposed'] ?></strong><span>open trades</span></div>
        <div class="metric"><strong><?= (int)$tradeCounts['completed'] ?></strong><span>completed trades</span></div>
    </div>

    <?php foreach ($groupedCards as $category => $cards): ?>
    <div class="category">
        <h3><?= h((string)$category) ?></h3>
        <table>
            <thead>
            <tr>
                <th>Card</th>
                <th>Required</th>
                <th>Owned</th>
                <th>Need</th>
                <th>Extra</th>
                <th>Last updated</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($cards as $card): ?>
            <tr>
                <td><?= h((string)$card['name']) ?></td>
                <td class="num"><?= (int)$card['required_qty'] ?></td>
                <td class="num"><?= (int)$card['owned_qty'] ?></td>
                <td class="num <?= (int)$card['need_qty'] > 0 ? 'need' : '' ?>"><?= (int)$card['need_qty'] ?></td>
                <td class="num <?= (int)$card['extra_qty'] > 0 ? 'extra' : '' ?>"><?= (int)$card['extra_qty'] ?></td>
                <td><?= $card['updated_at'] ? h((string)$card['updated_at']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <div class="delete-zone">
        <h3>Delete player</h3>
        <p>
            This permanently deletes <strong><?= h((string)$selectedPlayer['display_name']) ?></strong>.
            Their saved card rows and trade proposals are also deleted by database cascades.
        </p>
        <form method="post" class="delete-form"
              onsubmit="return confirm('Permanently delete <?= h((string)$selectedPlayer['display_name']) ?> and their inventory?');">
            <input type="hidden" name="delete_player" value="1">
            <input type="hidden" name="player_id" value="<?= (int)$selectedPlayer['id'] ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <label>
                Type <strong><?= h((string)$selectedPlayer['display_name']) ?></strong> to confirm
                <input type="text" name="confirm_name" autocomplete="off" required>
            </label>
            <button class="danger" type="submit">Delete player</button>
        </form>
    </div>
<?php endif; ?>
</section>

<?php endif; ?>
</div>
</div>
</body>
</html>

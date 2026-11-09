<?php
// Prices — "CPD – מחירי רשתות" (CPD = Chain Price Database): look barcodes up in the chains' official price
// files (C:\RetailAgents\PriceTransparency, data/cpd.sqlite, updated by
// update.js). For each barcode: the product card built from all chains
// (name, manufacturer, size), min / max, and the price in every store.
// Read-only. This computer only (like WaFetch).
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    http_response_code(403);
    exit('Available on this computer only.');
}

define('PRICES_DIR', 'C:/RetailAgents/PriceTransparency');
define('UPDATE_LOG', PRICES_DIR . '/data/update.log');
define('UPDATE_LOCK', PRICES_DIR . '/data/update.running');   // {pid, started} while update.js runs (it removes it on exit)
define('NODE_EXE', 'C:\\Program Files\\nodejs\\node.exe');
require_once __DIR__ . '/../commercialLayer/cpd_client.php';

// Is an update running? update.js writes its pid into the lock; a lock whose
// process is gone is from a run that died (computer stuck / turned off) — drop it.
$updateRunning = function () {
    if (!is_file(UPDATE_LOCK)) return false;
    $lock = json_decode((string) @file_get_contents(UPDATE_LOCK), true);
    if (empty($lock['pid'])) return time() - filemtime(UPDATE_LOCK) < 60;   // just started — node hasn't written its pid yet
    $alive = stripos((string) shell_exec('tasklist /FI "PID eq ' . (int) $lock['pid'] . '" /NH'), 'node.exe') !== false;
    if (!$alive) @unlink(UPDATE_LOCK);
    return $alive;
};

// "עדכן עכשיו": start update.js in the background (it takes ~12 minutes).
if (($_POST['action'] ?? '') === 'update') {
    header('Content-Type: application/json; charset=utf-8');
    if ($updateRunning()) exit(json_encode(['ok' => true, 'already' => true]));
    file_put_contents(UPDATE_LOCK, '{}');
    $dir = str_replace('/', '\\', PRICES_DIR);
    $cmd = 'cd /d "' . $dir . '" && "' . NODE_EXE . '" --no-warnings update.js > data\\update.log 2>&1 & del data\\update.running';
    pclose(popen('start "" /B cmd /c "' . $cmd . '"', 'r'));
    exit(json_encode(['ok' => true]));
}
// Progress of the background update: running? + the log's last lines.
if (($_GET['action'] ?? '') === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    $log = is_file(UPDATE_LOG) ? preg_split('/\r?\n/', trim((string) file_get_contents(UPDATE_LOG))) : [];
    exit(json_encode(['running' => $updateRunning(), 'log' => array_slice($log, -12)], JSON_UNESCAPED_UNICODE));
}

$chainNames = [];
foreach ((json_decode((string) @file_get_contents(PRICES_DIR . '/chains.json'), true)['chains'] ?? []) as $c) {
    $chainNames[$c['key']] = $c['name'];
}

$error = null;
$db = null;
try {
    $db = new PDO('sqlite:' . PRICES_DIR . '/data/cpd.sqlite', null, null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
} catch (Throwable $e) {
    $error = 'מאגר CPD לא נמצא — הרץ את העדכון (node update.js) ב-C:\RetailAgents\PriceTransparency';
}

$input = trim((string) ($_REQUEST['b'] ?? ''));
$barcodes = array_values(array_unique(array_filter(preg_split('/[^0-9]+/', $input), fn($b) => strlen($b) >= 4)));
$barcodes = array_slice($barcodes, 0, 200);

$results = [];
$lastRun = null;
$counts = null;
if ($db) {
    $lastRun = $db->query('SELECT * FROM runs ORDER BY finished DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
    $hasActive = (bool) $db->query("SELECT 1 FROM pragma_table_info('stores') WHERE name = 'active'")->fetchColumn();
    $activeOnly = $hasActive ? 'COALESCE(s.active, 1) = 1' : '1';   // hidden stores (closed / silent 14 days) are left out
    $counts = $db->query("SELECT (SELECT COUNT(*) FROM stores s WHERE $activeOnly) stores, (SELECT COUNT(*) FROM products) products,
        (SELECT MAX(updated) FROM stores s WHERE $activeOnly) updated")->fetch(PDO::FETCH_ASSOC);
    $qCard = $db->prepare('SELECT * FROM products WHERE barcode = ?');
    $qPrices = $db->prepare("SELECT p.chain, p.store_id, p.price, p.date, s.name store FROM current_prices p
        LEFT JOIN stores s ON s.chain = p.chain AND s.store_id = p.store_id WHERE p.barcode = ? AND $activeOnly ORDER BY p.price, p.chain");
    $qVariants = $db->prepare('SELECT chain, name, manufacturer FROM variants WHERE barcode = ?');
    foreach ($barcodes as $b) {
        // Chains sometimes write a barcode with leading zeros dropped or added.
        $keys = array_unique([$b, ltrim($b, '0')]);
        $card = null;
        foreach ($keys as $k) {
            $qCard->execute([$k]);
            if ($card = $qCard->fetch(PDO::FETCH_ASSOC)) {
                break;
            }
        }
        $prices = $variants = [];
        if ($card) {
            $qPrices->execute([$card['barcode']]);
            $prices = $qPrices->fetchAll(PDO::FETCH_ASSOC);
            $qVariants->execute([$card['barcode']]);
            $variants = $qVariants->fetchAll(PDO::FETCH_ASSOC);
        }
        $results[] = ['barcode' => $b, 'card' => $card, 'prices' => $prices, 'variants' => $variants];
    }
}

// CSV (opens in Excel): one row per barcode.
if (($_GET['format'] ?? '') === 'csv' && $results) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chain-prices-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ברקוד', 'שם מוצר', 'יצרן', 'גודל', 'מחיר מינימלי', 'מחיר מקסימלי', 'מספר סניפים', 'רשתות']);
    foreach ($results as $r) {
        $p = array_column($r['prices'], 'price');
        fputcsv($out, [$r['barcode'], $r['card']['display_name'] ?? 'לא נמצא', $r['card']['manufacturer'] ?? '', $r['card']['size'] ?? '',
            $p ? min($p) : '', $p ? max($p) : '', count($p),
            implode(', ', array_map(fn($c) => $chainNames[$c] ?? $c, array_unique(array_column($r['prices'], 'chain'))))]);
    }
    exit;
}

$money = fn($v) => number_format((float) $v, 2);
$found = count(array_filter($results, fn($r) => $r['card']));
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CPD – מחירי רשתות</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 32px 0; display: flex; justify-content: center; }
    .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,.15); padding: 36px 48px; width: 1100px; max-width: 95vw; box-sizing: border-box; }
    h2 { margin: 0 0 6px; color: #333; text-align: center; font-size: 30px; }
    .status { text-align: center; color: #777; font-size: 15px; margin-bottom: 22px; }
    .status.warn { color: #8a4b00; font-weight: bold; }
    textarea { width: 100%; height: 90px; font-size: 18px; padding: 10px; border: 1px solid #ccc; border-radius: 8px; box-sizing: border-box; direction: ltr; text-align: right; font-family: Consolas, monospace; }
    .row { display: flex; gap: 10px; margin-top: 10px; }
    button, .btn { padding: 12px 22px; background: #2575a8; color: #fff; border: none; border-radius: 8px; font-size: 18px; cursor: pointer; text-decoration: none; }
    .btn.secondary { background: #888; }
    .summary { margin: 22px 0 8px; font-size: 18px; }
    .item { border: 1px solid #ddd; border-radius: 8px; margin: 14px 0; overflow: hidden; }
    .item-head { display: flex; gap: 16px; align-items: center; padding: 12px 16px; background: #f6f9fc; flex-wrap: wrap; }
    .item-head .bc { font-family: Consolas, monospace; direction: ltr; color: #555; }
    .item-head .name { flex: 1; font-size: 19px; font-weight: bold; min-width: 260px; }
    .item-head .range { font-size: 19px; color: #155724; font-weight: bold; white-space: nowrap; }
    .item.missing .item-head { background: #fdf3f3; }
    .item.missing .name { color: #a33; font-weight: normal; }
    details { padding: 0 16px 12px; }
    summary { cursor: pointer; color: #2575a8; padding: 8px 0; }
    table { width: 100%; border-collapse: collapse; font-size: 15px; }
    th, td { border: 1px solid #e3e3e3; padding: 6px 8px; text-align: right; } th { background: #f0f4f8; }
    .muted { color: #888; font-size: 14px; }
    .status.ok { color: #2e7d32; margin-top: -14px; }
    .status.warn + .status.warn { margin-top: -14px; }
    .run-errors { margin: -10px 0 16px; color: #8a4b00; font-size: 14px; text-align: center; }
    .run-errors ul { text-align: right; }
    .update-row { display: flex; gap: 12px; align-items: center; justify-content: center; margin: -8px 0 18px; flex-wrap: wrap; }
    .btn-update { padding: 8px 16px; font-size: 15px; background: #2e7d32; }
    .btn-update:disabled { background: #999; cursor: default; }
    .upd-log { direction: ltr; text-align: left; background: #1e1e1e; color: #ddd; font-size: 13px; padding: 10px 14px; border-radius: 8px; margin: 0 0 18px; white-space: pre-wrap; max-height: 260px; overflow: auto; }
</style>
<script>
    let wasRunning = false;
    async function startUpdate() {
        document.getElementById('updBtn').disabled = true;
        await fetch('?', { method: 'POST', body: new URLSearchParams({ action: 'update' }) });
        wasRunning = true;
        poll();
    }
    async function poll() {
        const s = await (await fetch('?action=status')).json();
        const btn = document.getElementById('updBtn'), log = document.getElementById('updLog');
        if (s.running) {
            wasRunning = true;
            btn.disabled = true;
            btn.textContent = '⟳ מעדכן…';
            log.style.display = 'block';
            log.textContent = s.log.join('\n') || 'מתחיל…';
            log.scrollTop = log.scrollHeight;
            setTimeout(poll, 3000);
        } else if (wasRunning) {
            location.reload();   // done — show the new date and counts
        }
    }
    document.addEventListener('DOMContentLoaded', poll);   // a run started earlier is still shown
</script>
</head>
<body>
<div class="card">
    <h2>CPD – מחירי רשתות</h2>
    <?php if ($error): ?>
        <div class="status warn"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($counts): ?>
        <?php $stale = $counts['updated'] < date('Y-m-d'); ?>
        <div class="status <?= $stale ? 'warn' : '' ?>">
            תל אביב · <?= (int) $counts['stores'] ?> סניפים · <?= number_format((int) $counts['products']) ?> מוצרים ·
            עודכן: <?= htmlspecialchars(date('d/m/Y', strtotime($counts['updated']))) ?>
            <?php if ($lastRun): ?>(ריצה אחרונה <?= htmlspecialchars(date('d/m H:i', strtotime($lastRun['finished']))) ?>)<?php endif; ?>
            <?= $stale ? ' — הנתונים לא מהיום' : '' ?>
        </div>
        <?php
        $cov = (new CPDClient())->coverage();
        $runErrors = $lastRun ? (json_decode((string) $lastRun['errors'], true) ?: []) : [];
        ?>
        <?php $storeList = fn($rows) => htmlspecialchars(implode(' · ', array_map(fn($s) => "{$s['chain']} {$s['name']} ({$s['days']} ימים)", $rows))); ?>
        <?php if ($cov['warn']): ?>
            <div class="status warn">⚠ <?= count($cov['warn']) ?> סניפים לא התעדכנו <?= CPD_WARN_DAYS ?> ימים ויותר: <?= $storeList($cov['warn']) ?>
                <br>(אחרי 14 ימים סניף מוסתר אוטומטית — כנראה נסגר)</div>
        <?php endif; ?>
        <?php $minor = array_filter($cov['behind'], fn($s) => $s['days'] < CPD_WARN_DAYS); ?>
        <?php if ($minor): ?>
            <div class="status">מפגרים ביום-יומיים (רגיל — לפעמים רשת לא מפרסמת קובץ): <?= $storeList($minor) ?></div>
        <?php elseif (!$cov['warn'] && !$stale): ?>
            <div class="status ok">✓ כל <?= $cov['total'] ?> הסניפים עודכנו היום</div>
        <?php endif; ?>
        <?php if ($cov['hidden']): ?>
            <details class="run-errors"><summary><?= count($cov['hidden']) ?> סניפים מוסתרים (נסגרו / לא מפרסמים) — פירוט</summary>
                <ul><?php foreach ($cov['hidden'] as $h): ?>
                    <li><?= htmlspecialchars("{$h['chain']} {$h['name']}") ?> — <?= str_starts_with((string) $h['reason'], 'not in') ? 'הוסר מרשימת הסניפים של הרשת' : 'לא פרסם מחירים 14 ימים ויותר' ?>
                        (מ-<?= htmlspecialchars(date('d/m/Y', strtotime((string) $h['since']))) ?>)</li>
                <?php endforeach; ?></ul>
                <span class="muted">סניף מוסתר חוזר לבד כשהוא מפרסם מחירים שוב.</span></details>
        <?php endif; ?>
        <?php if ($runErrors): ?>
            <details class="run-errors"><summary>בריצה האחרונה נכשלו <?= count($runErrors) ?> — פירוט</summary>
                <ul><?php foreach ($runErrors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></details>
        <?php endif; ?>
    <?php endif; ?>
    <div class="update-row">
        <button type="button" id="updBtn" class="btn-update" onclick="startUpdate()">⟳ עדכן עכשיו מהרשתות</button>
        <span class="muted">הורדת המחירים העדכניים מכל הרשתות — כ-10 דקות. אפשר להמשיך לעבוד בינתיים.</span>
    </div>
    <pre id="updLog" class="upd-log" style="display:none"></pre>

    <form method="get">
        <textarea name="b" placeholder="הדבק ברקודים — אחד בשורה, או מופרדים בפסיק/רווח"><?= htmlspecialchars($input) ?></textarea>
        <div class="row">
            <button type="submit">חפש</button>
            <?php if ($results): ?>
                <a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['b' => $input, 'format' => 'csv'])) ?>">⬇ ייצא לאקסל (CSV)</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($results): ?>
        <div class="summary">נמצאו <b><?= $found ?></b> מתוך <?= count($results) ?> ברקודים.</div>
        <?php foreach ($results as $r): ?>
            <?php $p = array_column($r['prices'], 'price'); ?>
            <div class="item <?= $r['card'] ? '' : 'missing' ?>">
                <div class="item-head">
                    <span class="bc"><?= htmlspecialchars($r['barcode']) ?></span>
                    <span class="name"><?= $r['card'] ? htmlspecialchars($r['card']['display_name']) : 'לא נמצא באף רשת' ?></span>
                    <?php if ($p): ?>
                        <span class="range"><?= min($p) == max($p) ? '₪' . $money(min($p)) : '₪' . $money(min($p)) . ' – ₪' . $money(max($p)) ?></span>
                        <span class="muted"><?= count($p) ?> סניפים</span>
                    <?php endif; ?>
                </div>
                <?php if ($r['card']): ?>
                    <details>
                        <summary>פירוט לפי סניף ושמות ברשתות</summary>
                        <p class="muted">
                            יצרן: <?= htmlspecialchars($r['card']['manufacturer'] ?: '—') ?> · גודל: <?= htmlspecialchars($r['card']['size'] ?: '—') ?><br>
                            השם בכל רשת: <?= htmlspecialchars(implode(' | ', array_map(fn($v) => ($chainNames[$v['chain']] ?? $v['chain']) . ': ' . $v['name'], $r['variants']))) ?>
                        </p>
                        <table>
                            <tr><th>מחיר</th><th>רשת</th><th>סניף</th><th>עודכן</th></tr>
                            <?php foreach ($r['prices'] as $row): ?>
                                <tr><td>₪<?= $money($row['price']) ?></td><td><?= htmlspecialchars($chainNames[$row['chain']] ?? $row['chain']) ?></td>
                                    <td><?= htmlspecialchars($row['store'] ?? $row['store_id']) ?></td><td><?= htmlspecialchars(date('d/m', strtotime($row['date']))) ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>

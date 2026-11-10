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

// The two background jobs (one at a time — they write the same database):
//   update   update.js  — Tel Aviv prices, daily (~12 minutes)
//   catalog  catalog.js — every store in the country, for product names + a national price range, weekly
$jobs = ['update' => 'update.js', 'catalog' => 'catalog.js'];
if (isset($jobs[$_POST['action'] ?? ''])) {
    header('Content-Type: application/json; charset=utf-8');
    $job = $_POST['action'];
    if ($updateRunning()) exit(json_encode(['ok' => true, 'already' => true]));
    file_put_contents(UPDATE_LOCK, json_encode(['job' => $job]));
    if ($job === 'catalog') @unlink(PRICES_DIR . '/data/progress.json');
    $dir = str_replace('/', '\\', PRICES_DIR);
    $cmd = 'cd /d "' . $dir . '" && "' . NODE_EXE . '" --no-warnings ' . $jobs[$job] . ' > data\\' . $job . '.log 2>&1 & del data\\update.running';
    pclose(popen('start "" /B cmd /c "' . $cmd . '"', 'r'));
    exit(json_encode(['ok' => true]));
}
// Progress of the background job: running? which job, the log's last lines, and catalog.js's progress.
if (($_GET['action'] ?? '') === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    $running = $updateRunning();
    $job = $running ? (json_decode((string) @file_get_contents(UPDATE_LOCK), true)['job'] ?? 'update') : null;
    $logFile = PRICES_DIR . '/data/' . ($job ?: 'update') . '.log';
    $log = is_file($logFile) ? preg_split('/\r?\n/', trim((string) file_get_contents($logFile))) : [];
    $progress = $job === 'catalog' ? json_decode((string) @file_get_contents(PRICES_DIR . '/data/progress.json'), true) : null;
    exit(json_encode(['running' => $running, 'job' => $job, 'log' => array_slice($log, -12), 'progress' => $progress], JSON_UNESCAPED_UNICODE));
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
    // The national catalog (catalog.js) — absent until its first run.
    $hasCatalog = (bool) $db->query("SELECT 1 FROM sqlite_master WHERE name = 'catalog_runs'")->fetchColumn();
    $qNational = $hasCatalog ? $db->prepare('SELECT * FROM national_prices WHERE barcode = ?') : null;
    $lastCatalog = $hasCatalog ? ($db->query('SELECT * FROM catalog_runs ORDER BY finished DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null) : null;
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
        $national = null;
        if ($card) {
            $qPrices->execute([$card['barcode']]);
            $prices = $qPrices->fetchAll(PDO::FETCH_ASSOC);
            $qVariants->execute([$card['barcode']]);
            $variants = $qVariants->fetchAll(PDO::FETCH_ASSOC);
            if ($qNational) {
                $qNational->execute([$card['barcode']]);
                $national = $qNational->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
        $results[] = ['barcode' => $b, 'card' => $card, 'prices' => $prices, 'variants' => $variants, 'national' => $national];
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
    .btn-update { padding: 8px 16px; font-size: 15px; background: #2e7d32; }
    .btn-update:disabled { background: #999; cursor: default; }
    .runs { display: flex; gap: 16px; flex-wrap: wrap; margin: 0 0 14px; }
    .run-box { flex: 1; min-width: 300px; border: 1px solid #e3e3e3; border-radius: 10px; padding: 14px 18px; background: #fafbfc; font-size: 15px; line-height: 1.7; color: #444; }
    .run-box h4 { margin: 0 0 6px; font-size: 18px; color: #333; }
    .run-box .btn-update { margin-top: 10px; }
    .warn-text { color: #8a4b00; font-weight: bold; }
    .range.national { color: #8a6d00; }
    .btn-update.catalog { background: #6a4c93; }
    .bar { height: 22px; background: #e6e6e6; border-radius: 11px; overflow: hidden; margin: 0 0 8px; }
    .bar > div { height: 100%; background: linear-gradient(90deg, #6a4c93, #8e6cc0); width: 0; transition: width .6s; }
    .bar-text { text-align: center; font-size: 15px; color: #444; margin-bottom: 6px; }
    .bar-chains { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 4px 14px; font-size: 13px; color: #666; margin-bottom: 18px; }
    .catalog-sum { margin: 0 0 18px; padding: 0; }
    .catalog-sum summary { text-align: center; }
    .catalog-sum .cols { display: flex; gap: 18px; flex-wrap: wrap; }
    .catalog-sum .cols > div { flex: 1; min-width: 300px; }
    .catalog-sum td.num, .catalog-sum th.num { text-align: left; direction: ltr; }
    .upd-log { direction: ltr; text-align: left; background: #1e1e1e; color: #ddd; font-size: 13px; padding: 10px 14px; border-radius: 8px; margin: 0 0 18px; white-space: pre-wrap; max-height: 260px; overflow: auto; }
</style>
<script>
    let wasRunning = false;
    const $ = id => document.getElementById(id);
    const fmt = n => Number(n || 0).toLocaleString('en-US');
    async function startJob(job) {
        document.querySelectorAll('.btn-update').forEach(b => b.disabled = true);
        await fetch('?', { method: 'POST', body: new URLSearchParams({ action: job }) });
        wasRunning = true;
        poll();
    }
    // catalog.js's progress: a bar over all stores in the country, and each chain's count
    function showProgress(p) {
        const box = $('catProgress');
        box.style.display = 'block';
        if (!p || p.phase === 'stores') {
            $('barFill').style.width = '2%';
            $('barText').textContent = 'מוריד את רשימות הסניפים מכל הרשתות…' + (p && p.total ? ` (${fmt(p.total)} סניפים עד כה)` : '');
        } else if (p.phase === 'download') {
            $('barFill').style.width = (p.total ? Math.round(100 * p.done / p.total) : 0) + '%';
            $('barText').textContent = `${fmt(p.done)} / ${fmt(p.total)} סניפים (${p.total ? Math.round(100 * p.done / p.total) : 0}%) · `
                + `${fmt(p.barcodes)} ברקודים · ${fmt(p.newBarcodes)} חדשים (לא בתל אביב) · ${Math.round(p.mb)} MB`
                + (p.failed ? ` · ${p.failed} נכשלו` : '');
        } else if (p.phase === 'saving') {
            $('barFill').style.width = '100%';
            $('barText').textContent = 'שומר במאגר ובונה כרטיסי מוצר…';
        }
        $('barChains').innerHTML = p ? Object.values(p.chains).map(c => {
            const t = c.error ? '✗ נכשלה' : c.total && c.done === c.total ? '✓ ' + fmt(c.barcodes) + ' ברקודים' : `${c.done}/${c.total || '?'}`;
            return `<div>${c.name}: ${t}</div>`;
        }).join('') : '';
    }
    async function poll() {
        const s = await (await fetch('?action=status')).json();
        if (s.running) {
            wasRunning = true;
            document.querySelectorAll('.btn-update').forEach(b => b.disabled = true);
            $(s.job === 'catalog' ? 'catBtn' : 'updBtn').textContent = '⟳ מעדכן…';
            if (s.job === 'catalog') {
                showProgress(s.progress);
            } else {
                const log = $('updLog');
                log.style.display = 'block';
                log.textContent = s.log.join('\n') || 'מתחיל…';
                log.scrollTop = log.scrollHeight;
            }
            setTimeout(poll, 2000);
        } else if (wasRunning) {
            location.reload();   // done — show the new date, counts and summary
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
        <?php
        // Run times are stored in UTC (ISO); shown in Israel time.
        $il   = new DateTimeZone('Asia/Jerusalem');
        $when = fn($iso) => (new DateTime($iso))->setTimezone($il)->format('d/m/Y H:i');
        $dur  = function ($from, $to) {
            $s = max(0, strtotime($to) - strtotime($from));
            return $s < 60 ? "{$s} שניות" : ($s < 3600 ? intdiv($s, 60) . ':' . sprintf('%02d', $s % 60) . ' דקות'
                : intdiv($s, 3600) . ':' . sprintf('%02d', intdiv($s % 3600, 60)) . ' שעות');
        };
        $num   = fn($v) => $v === null ? '—' : number_format((int) $v);
        $stale = $counts['updated'] < CPDClient::today();
        $cs    = !empty($lastCatalog) ? json_decode($lastCatalog['summary'], true) : null;
        $age   = $cs ? (int) floor((time() - strtotime($lastCatalog['finished'])) / 86400) : null;
        ?>
        <div class="runs">
            <div class="run-box">
                <h4>מחירי תל אביב <span class="muted">· יומי</span></h4>
                <?php if ($lastRun): ?>
                    <div>ריצה אחרונה: <b><?= $when($lastRun['finished']) ?></b> · משך <?= $dur($lastRun['started'], $lastRun['finished']) ?></div>
                    <div><?= (int) $lastRun['stores'] ?> סניפים · <?= $num($lastRun['items']) ?> מחירים</div>
                    <div><b><?= $num($lastRun['changed'] ?? null) ?></b> מחירים השתנו · <b><?= $num($lastRun['new_products'] ?? null) ?></b> מוצרים חדשים</div>
                <?php else: ?>
                    <div class="muted">עוד לא רץ</div>
                <?php endif; ?>
                <?php if ($stale): ?><div class="warn-text">הנתונים לא מהיום — כדאי לעדכן</div><?php endif; ?>
                <button type="button" id="updBtn" class="btn-update" onclick="startJob('update')">⟳ עדכן מחירי תל אביב</button>
            </div>
            <div class="run-box">
                <h4>קטלוג ארצי <span class="muted">· שבועי, כל הסניפים בארץ</span></h4>
                <?php if ($cs): $t = $cs['totals']; ?>
                    <div>ריצה אחרונה: <b><?= $when($lastCatalog['finished']) ?></b> · משך <?= $dur($lastCatalog['started'], $lastCatalog['finished']) ?></div>
                    <div><?= $num($t['stores']) ?> מתוך <?= $num($t['listed']) ?> סניפים · <?= $num($t['barcodes']) ?> ברקודים</div>
                    <div><b><?= $num($t['productsAfter'] - $t['productsBefore']) ?></b> מוצרים חדשים נוספו · <?= $num($t['newBarcodes']) ?> לא נמכרים בתל אביב</div>
                    <?php if ($age >= 7): ?><div class="warn-text">עברו <?= $age ?> ימים — כדאי לעדכן</div><?php endif; ?>
                <?php else: ?>
                    <div class="muted">עוד לא רץ</div>
                <?php endif; ?>
                <button type="button" id="catBtn" class="btn-update catalog" onclick="startJob('catalog')">⟳ עדכן קטלוג ארצי</button>
            </div>
        </div>
        <div class="status">במאגר: <?= number_format((int) $counts['products']) ?> מוצרים · אפשר להמשיך לעבוד בזמן עדכון, גם לסגור את הדף.</div>
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
            <div class="status ok">✓ כל <?= $cov['total'] ?> הסניפים בתל אביב עודכנו היום</div>
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
    <pre id="updLog" class="upd-log" style="display:none"></pre>
    <div id="catProgress" style="display:none">
        <div class="bar"><div id="barFill"></div></div>
        <div class="bar-text" id="barText"></div>
        <div class="bar-chains" id="barChains"></div>
    </div>

    <?php if (!empty($cs)): ?>
        <details class="catalog-sum">
            <summary>קטלוג ארצי — פירוט לפי רשת ומחוז</summary>
            <p class="muted">מוצרים במאגר: <?= number_format($t['productsBefore']) ?> → <?= number_format($t['productsAfter']) ?> ·
                <?= (int) $t['mb'] ?> MB<?= $t['failed'] ? " · {$t['failed']} סניפים בלי קובץ" : '' ?></p>
            <div class="cols">
                <div><table>
                    <tr><th>רשת</th><th class="num">סניפים</th><th class="num">ברקודים</th><th class="num">לא בתל אביב</th></tr>
                    <?php foreach ($cs['chains'] as $c): ?>
                        <tr><td><?= htmlspecialchars($c['name']) ?><?= $c['error'] ? ' <span class="muted">(נכשלה)</span>' : '' ?></td>
                            <td class="num"><?= $c['done'] - $c['failed'] ?><?= $c['failed'] ? "/{$c['total']}" : '' ?></td>
                            <td class="num"><?= number_format($c['barcodes']) ?></td><td class="num"><?= number_format($c['newBarcodes']) ?></td></tr>
                    <?php endforeach; ?>
                </table></div>
                <div><table>
                    <tr><th>מחוז</th><th class="num">סניפים</th><th class="num">ברקודים</th><th class="num">לא בתל אביב</th></tr>
                    <?php foreach ($cs['districts'] as $d): ?>
                        <tr><td><?= htmlspecialchars($d['district']) ?></td><td class="num"><?= $d['stores'] ?></td>
                            <td class="num"><?= number_format($d['barcodes']) ?></td><td class="num"><?= number_format($d['newBarcodes']) ?></td></tr>
                    <?php endforeach; ?>
                </table></div>
            </div>
            <p class="muted">"לא בתל אביב" — ברקודים שאין להם מחיר בסניפי תל אביב: בזכותם מוצר שלא נמכר בתל אביב מקבל שם, ו-NP מקבל עבורו טווח מחירים ארצי (מסומן בצהוב).</p>
        </details>
    <?php endif; ?>

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
                    <?php elseif ($n = $r['national']): ?>
                        <span class="range national"><?= $n['min'] == $n['max'] ? '₪' . $money($n['min']) : '₪' . $money($n['min']) . ' – ₪' . $money($n['max']) ?></span>
                        <span class="muted">ארצי · לא בתל אביב · <?= (int) $n['stores'] ?> סניפים
                            (<?= htmlspecialchars(implode(', ', array_map(fn($c) => $chainNames[$c] ?? $c, explode(',', $n['chains'])))) ?>)</span>
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

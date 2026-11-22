<?php
/**
 * Retailomatics Invoice Scan — step 2: the AI reads the chosen files.
 *
 * POST shop + files[] (names in preProcessDir) → a batch: every file read on its own, a few
 *   at a time. Then: pages of the same supplier and date → group.php (one document or
 *   several?); none → each file is a document → batch.php (the list).
 * POST id + again → one document read again, all its pages together (confirm.php's "קרא שוב").
 * GET resume=<batch> → a batch whose reading was left (the window closed): reading → its progress;
 *   read → on to the documents (group.php / the list).
 *
 * The reading streams its progress (done / total, time, a line per file) and goes on when the
 * window is closed (ignore_user_abort); the batch is saved after every file, so index.php can
 * offer it again ("קריאות שלא הושלמו").
 */
session_start();
require_once __DIR__ . '/lib/common.php';
set_time_limit(900);

if (!empty($_GET['resume'])) {   // a batch left open
    $batch = scan_batch_load((string) $_GET['resume']);
    $_SESSION['invoiceScanShop'] = $batch['shop'];
    $left = count($batch['files'] ?? []) - count($batch['pages']);
    if ($left > 0 && empty($batch['finished'])) {
        scan_page_start('הקריאה עדיין רצה');
        echo '<div class="card"><h2>הקריאה עדיין רצה — נקראו ' . count($batch['pages']) . ' מתוך ' . count($batch['files']) . '</h2>'
            . '<p>רענן בעוד כמה שניות.</p><a class="btn second" href="scan.php?resume=' . scan_h($batch['id']) . '">רענן</a></div>';
        scan_page_end();
        exit;
    }
    if (!$batch['docs'] && scan_batch_groups($batch)) { header('Location: group.php?b=' . $batch['id']); exit; }
    header('Location: ' . scan_batch_next_url($batch['docs'] ? $batch : scan_batch_build_docs($batch, [])));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!empty($_POST['id'])) {   // one document again
    $work = scan_load((string) $_POST['id']);
    try {
        $work['read'] = InvoiceReader::read(scan_source_paths($work), $work['shop']);
        $work['read_at'] = date('c');
    } catch (Throwable $e) {
        scan_page_start('הקריאה נכשלה');
        echo '<div class="card"><h2 class="warn">הקריאה נכשלה</h2><p>' . scan_h($e->getMessage()) . '</p>'
            . '<a class="btn second" href="confirm.php?id=' . scan_h($work['id']) . '">חזרה</a></div>';
        scan_page_end();
        exit;
    }
    scan_save($work);
    header('Location: confirm.php?id=' . $work['id']);
    exit;
}

$shop = (string) ($_POST['shop'] ?? '');
$files = array_values(array_unique(array_map('basename', (array) ($_POST['files'] ?? []))));
if (!in_array($shop, scan_shops(), true) || !$files || array_diff($files, scan_waiting_files())) {
    http_response_code(400);
    exit('בחרו חנות וקבצים מהרשימה. <a href="index.php">חזרה</a>');
}
$_SESSION['invoiceScanShop'] = $shop;
$day = scan_day_or_today((string) ($_POST['day'] ?? ''));   // the processing day — for every document of this read
$_SESSION['invoiceScanDay'] = $day;
natcasesort($files);
$files = array_values($files);
$docs = [];
foreach ($files as $f) $docs[$f] = [SCAN_IN_DIR . "/$f"];
$batch = ['id' => bin2hex(random_bytes(6)), 'shop' => $shop, 'day' => $day, 'created' => date('c'), 'files' => $files, 'pages' => [], 'docs' => []];
scan_batch_save($batch);
session_write_close();   // other pages of the site stay usable while this one reads
ignore_user_abort(true);  // closing the window doesn't stop the reading — it's saved, index.php offers it

// ── the progress page, streamed ──
header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) ob_end_flush();
ob_implicit_flush(true);
$flush = function () { echo str_pad('', 1024, ' '), "\n"; @flush(); };
scan_page_start('קורא ' . count($files) . ' מסמכים');
$total = count($files);
?>
<style>
.prog{height:14px;background:#e3e8ef;border-radius:7px;overflow:hidden;margin:10px 0}.prog div{height:100%;background:#1d3557;width:0;transition:width .4s}
#plist td{font-size:14px}#plist tr.wait td{color:#9aa3af}#plist tr.err td{background:#fde8e6}
</style>
<div class="card">
    <h2>קורא <?= $total ?> מסמכים — <?= scan_h($shop) ?> <span class="mute" style="font-weight:400">· יום עיבוד <?= scan_h(date('d/m/Y', strtotime($day))) ?></span></h2>
    <div class="prog"><div id="pBar"></div></div>
    <div id="pText" class="mute">מתחיל… (6 מסמכים במקביל; כל מסמך כ-20–40 שניות)</div>
    <p class="mute" style="margin-top:8px">אפשר לסגור את החלון — הקריאה ממשיכה ונשמרת. אחר כך: מסך הפתיחה ← "קריאות שלא הושלמו".</p>
</div>
<div class="card" style="overflow-x:auto">
    <table id="plist" style="width:100%">
        <tr><th>#</th><th>קובץ</th><th>ספק</th><th>תאריך</th><th>מספר</th><th>שורות</th><th>זמן</th></tr>
        <?php foreach ($files as $i => $f): ?>
            <tr class="wait" id="r<?= $i ?>"><td><?= $i + 1 ?></td><td dir="ltr" style="text-align:left"><?= scan_h($f) ?></td><td colspan="5">ממתין…</td></tr>
        <?php endforeach; ?>
    </table>
</div>
<script>
function done(i, n, total, cells, err, elapsed) {
    const tr = document.getElementById('r' + i);
    tr.className = err ? 'err' : '';
    tr.innerHTML = tr.children[0].outerHTML + tr.children[1].outerHTML + cells;
    document.getElementById('pBar').style.width = Math.round(100 * n / total) + '%';
    const left = n < total ? Math.round(elapsed / n * (total - n)) : 0;
    const mmss = s => Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    document.getElementById('pText').textContent = 'נקראו ' + n + ' מתוך ' + total + ' · עבר ' + mmss(elapsed) + (n < total ? ' · נותר בערך ' + mmss(left) : '');
}
</script>
<?php
$flush();
$t0 = microtime(true);
$index = array_flip($files);
$pages = InvoiceReader::readMany($docs, $shop, 6, function ($key, $result, $n, $total) use (&$batch, $index, $t0, $flush) {
    $batch['pages'][$key] = $result;
    scan_batch_save($batch);
    if (isset($result['error'])) {
        $cells = '<td colspan="5" class="warn">' . scan_h($result['error']) . '</td>';
    } else {
        $d = $result['data'];
        $m = InvoiceReader::matchSupplier($d);
        $cells = '<td>' . scan_h($m['supplier']['supplierName'] ?? ($d['supplier_name_printed'] ?: '—')) . '</td>'
            . '<td>' . ($d['doc_date'] !== '' ? scan_h(date('d/m/Y', strtotime($d['doc_date']))) : '—') . '</td>'
            . '<td class="num">' . scan_h($d['doc_number'] ?: '—') . '</td>'
            . '<td>' . count($d['items']) . '</td>'
            . '<td class="num">' . scan_h((string) ($result['seconds'] ?? '')) . ' שנ׳</td>';
    }
    echo '<script>done(' . (int) $index[$key] . ',' . $n . ',' . $total . ',' . json_encode($cells, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ','
        . (isset($result['error']) ? 'true' : 'false') . ',' . (int) round(microtime(true) - $t0) . ');</script>';
    $flush();
});
$batch['pages'] = $pages;   // in the files' order
$batch['finished'] = date('c');
scan_batch_save($batch);

$next = scan_batch_groups($batch) ? 'group.php?b=' . $batch['id'] : scan_batch_next_url(scan_batch_build_docs($batch, []));
$errors = count(array_filter($pages, fn($p) => isset($p['error'])));
echo '<div class="card"><h2 class="ok">✔ הקריאה הסתיימה' . ($errors ? ' — <span class="warn">' . $errors . ' קבצים לא נקראו</span>' : '') . '</h2>'
    . '<a class="btn" href="' . scan_h($next) . '">המשך</a></div>';
if (!$errors) echo '<script>setTimeout(function () { location.href = ' . json_encode($next) . '; }, 1500);</script>';
scan_page_end();

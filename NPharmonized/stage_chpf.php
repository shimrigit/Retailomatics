<?php
// Stage 2 – chain prices (CPD; was CHP Fetch — the file names keep "_CHPF")
set_time_limit(0);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/xlsx_retry.php';
require_once __DIR__ . '/flow_mode.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Fill;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['np_dir'])) {
    header('Location: index.php'); exit;
}

$npDir    = $_POST['np_dir'];
$xlsxFile = $_POST['xlsx_file'];   // _BR.xlsx
$shop     = $_POST['shop']      ?? '';
$date     = $_POST['date']      ?? '';
$deptFile = $_POST['dept_file'] ?? '';
$mode     = npFlowMode();

// ── Load xlsx and collect barcodes from col A ─────────────────────────────────
try {
    $spreadsheet = loadSpreadsheetWithRetry($npDir . '/' . $xlsxFile);
} catch (Exception $e) {
    die('<p style="color:red;font-family:Arial;font-size:22px;padding:30px">שגיאה בטעינת Excel: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

$sheet    = $spreadsheet->getActiveSheet();
$highRow  = $sheet->getHighestRow();
$barcodes = [];
for ($row = 2; $row <= $highRow; $row++) {
    $val = $sheet->getCell('A' . $row)->getValue();
    if ($val === null || $val === '') break;
    $barcodes[] = [
        'row'       => $row,
        'barcode'   => trim((string)$val),
        'salePrice' => (float)$sheet->getCell('F' . $row)->getValue(),
    ];
}

// ── Price source ──────────────────────────────────────────────────────────────
// 'cpd' = the chains' official price files (CPD, commercialLayer/cpd_client.php);
// 'chp' = the old chp.co.il scraper (blocked for headless requests since Nov 2026).
// Both return the same shape, so parseCHP() and the later stages don't change.
const NP_PRICE_SOURCE = 'cpd';
// With CPD the product name is the AI name (cpd_namer.php, CHP style
// "product, size, brand"), named once per barcode and kept; without it, the
// longest chain name + size.
require_once __DIR__ . '/../commercialLayer/cpd_namer.php';
$cpd     = new CPDClient();
$aiNames = [];
function fetchPrices($barcode, $city) {
    global $cpd, $aiNames;
    if (NP_PRICE_SOURCE === 'cpd') {
        $data = $cpd->searchPrices($barcode, $city, $aiNames[$barcode] ?? null);
        return $data === false ? null : $data;
    }
    return callCHP($barcode, $city);
}

// ── CHP scraper call ──────────────────────────────────────────────────────────
function callCHP($barcode, $city = 'תל אביב') {
    $scriptPath = realpath(__DIR__ . '/../commercialLayer/chp_scraper.js');
    if (!$scriptPath) return null;
    $cmd = 'node ' . escapeshellarg($scriptPath)
         . ' ' . escapeshellarg((string)$barcode)
         . ' ' . escapeshellarg($city) . ' 2>&1';
    $raw = shell_exec($cmd);
    if (empty($raw)) return null;
    $jsonLines = [];
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        if (preg_match('/^(Navigating|Page loaded|Entering|Clicking|Waiting|Extracting|Starting|Search completed|Using Node|Launching|Searching)/i', $line)) continue;
        $jsonLines[] = $line;
    }
    $data = json_decode(implode('', $jsonLines), true);
    return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
}

function parseCHP($data) {
    $out = ['hebrewName' => 'NA', 'returnedBarcode' => 'NA', 'maxPrice' => 'NA', 'minPrice' => 'NA'];
    if (!$data) return $out;
    $productInfo = $data['productInfo'] ?? $data['productName'] ?? '';
    $productName = $manufacturer = $barcode = '';
    if (preg_match('/^(.+?)\s*\(יצרן\/מותג:\s*(.+?),\s*ברקוד:\s*(\d+)\)/u', $productInfo, $m)) {
        $productName = trim($m[1]); $manufacturer = trim($m[2]); $barcode = trim($m[3]);
    } elseif (preg_match('/^(.+?)\s*\(ברקוד:\s*(\d+)\)/u', $productInfo, $m)) {
        $productName = trim($m[1]); $barcode = trim($m[2]);
    } else {
        $productName = trim($productInfo);
    }
    $out['hebrewName']      = !empty($manufacturer) ? "{$productName}, {$manufacturer}" : $productName;
    if (empty($out['hebrewName'])) $out['hebrewName'] = 'NA';
    $out['returnedBarcode'] = $barcode ?: 'NA';
    $prices = [];
    foreach ($data['prices'] ?? [] as $entry) {
        $p = str_replace(',', '', trim($entry['price'] ?? ''));
        if (is_numeric($p)) $prices[] = (float)$p;
    }
    if (!empty($prices)) {
        $out['maxPrice'] = number_format(max($prices), 2);
        $out['minPrice'] = number_format(min($prices), 2);
    }
    // CPD: not sold in Tel Aviv — the min / max are the national range
    $out['national'] = !empty($data['national']);
    return $out;
}

// ── Streaming ─────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) ob_end_flush();
ob_implicit_flush(true);
function sf() { echo str_pad('', 512, ' '); flush(); }
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>NP Harmonized – שלב 2: מחירי רשתות (CPD)</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 45px; }
        h2   { color: #2575a8; font-size: 36px; margin-bottom: 9px; }
        .sub { color: #777; font-size: 20px; margin-bottom: 36px; }
        .progress-box { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 24px 32px; max-width: 1100px; margin-bottom: 36px; }
        .pl      { font-size: 20px; color: #555; margin: 6px 0; }
        .pl.done { color: #2a7d2a; }
        .pl.err  { color: #c0392b; }
        h3 { color: #555; font-size: 27px; margin-bottom: 18px; }
        table { border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,.1); max-width: 1400px; width: 100%; margin-bottom: 36px; }
        th { background: #2575a8; color: #fff; padding: 14px 18px; font-size: 20px; text-align: right; }
        td { padding: 12px 18px; border-bottom: 1px solid #eee; font-size: 20px; color: #333; }
        tr:last-child td { border-bottom: none; }
        .saved-box { background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 8px; padding: 21px 28px; max-width: 1100px; margin-bottom: 36px; font-size: 21px; color: #2a7d2a; font-weight: bold; }
        .btn-next { padding: 17px 50px; background: #2575a8; color: #fff; border: none; border-radius: 8px; font-size: 24px; cursor: pointer; }
        .btn-next:hover { background: #1a5c87; }
        .price-ok    { color: #2a7d2a; font-weight: bold; }
        .price-above { color: #c0392b; font-weight: bold; }
        .price-below { color: #e67e22; font-weight: bold; }
        .price-na    { color: #999; font-style: italic; }
        .ok-box  { background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 8px; padding: 21px 28px; max-width: 1100px; margin-bottom: 36px; font-size: 21px; color: #2a7d2a; font-weight: bold; }
        .warn-box { background: #fdecea; border: 2px solid #e57373; border-radius: 8px; padding: 21px 28px; max-width: 1100px; margin-bottom: 36px; }
        .warn-box h4 { color: #c0392b; font-size: 24px; margin: 0 0 14px; }
        .warn-row { font-size: 20px; color: #c0392b; margin: 6px 0; }
        .price-stats { font-size: 20px; color: #555; margin-bottom: 16px; }
        .price-stats span { margin-left: 28px; }
    </style>
</head>
<body>
<h2>NP Harmonized – שלב 2: <?= NP_PRICE_SOURCE === 'cpd' ? 'מחירי רשתות (CPD)' : 'CHP Fetch' ?></h2>
<p class="sub">חנות: <?= htmlspecialchars($shop) ?> | תאריך: <?= htmlspecialchars($date) ?> | <?= count($barcodes) ?> ברקודים</p>
<?php if (NP_PRICE_SOURCE === 'cpd'): ?>
    <?php if (!$cpd->available()): ?>
<div class="warn-box"><h4>⚠ <?= htmlspecialchars($cpd->error) ?></h4><div class="warn-row">כל הברקודים יסומנו NA.</div></div>
    <?php elseif ($cpd->isStale()): ?>
        <?php $cov = $cpd->coverage(); ?>
<div class="warn-box">
    <?php if (!$cov['newest'] || $cov['newest'] < date('Y-m-d')): ?>
    <h4>⚠ מחירי CPD לא מהיום — עודכנו לאחרונה <?= htmlspecialchars($cov['newest'] ? date('d/m/Y', strtotime($cov['newest'])) : '—') ?></h4>
    <?php endif; ?>
    <?php if ($cov['warn']): ?>
    <h4>⚠ <?= count($cov['warn']) ?> סניפים לא התעדכנו <?= CPD_WARN_DAYS ?> ימים ויותר (המחירים שלהם ישנים)</h4>
    <div class="warn-row"><?= htmlspecialchars(implode(' · ', array_map(fn($s) => "{$s['chain']} {$s['name']} ({$s['days']} ימים)", array_slice($cov['warn'], 0, 12)))) ?><?= count($cov['warn']) > 12 ? ' …' : '' ?></div>
    <?php endif; ?>
<div class="warn-row">כדי לעדכן: <a href="/website/Prices/" target="_blank">דף מחירי רשתות</a> ← "עדכן עכשיו" (כ-12 דקות). אפשר להמשיך עם המחירים הקיימים.</div></div>
    <?php endif; ?>
<?php endif; ?>

<?php if (empty($barcodes)): ?>
<p style="color:#c0392b;font-size:22px;">לא נמצאו ברקודים בעמודה A. ודא שהשלב הקודם הושלם.</p>
<a href="index.php" style="font-size:20px;">חזור לתחילה</a>
<?php echo '</body></html>'; exit; endif; ?>

<div class="progress-box">
<?php
sf();
$results = [];
$city    = 'תל אביב';

if (NP_PRICE_SOURCE === 'cpd' && $cpd->available()) {
    echo '<p class="pl" id="pnames">יוצר שמות מוצרים (AI) לברקודים חדשים...</p>';
    sf();
    $namer   = new CPDNamer($cpd);
    $aiNames = $namer->names(array_column($barcodes, 'barcode'));
    $u       = $namer->usage;
    $msg     = $namer->error
        ? '⚠ ' . $namer->error . ' — לברקודים בלי שם AI נלקח שם הרשת + גודל'
        : '✔ שמות מוצרים: ' . count($aiNames) . ' ברקודים' . ($u['calls'] ? " ({$u['calls']} קריאות AI, " . ($u['prompt_tokens'] + $u['completion_tokens']) . ' טוקנים)' : ' (כולם כבר היו שמורים)');
    echo '<script>var el = document.getElementById("pnames"); el.className = "pl ' . ($namer->error ? 'err' : 'done') . '"; el.textContent = '
        . json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';</script>';
    sf();
}

foreach ($barcodes as $idx => $item) {
    $z = $idx + 1;
    echo '<p class="pl" id="p' . $z . '">מעבד ברקוד ' . $z . ' מתוך ' . count($barcodes) . ': ' . htmlspecialchars($item['barcode']) . '...</p>';
    sf();

    $chpData = fetchPrices($item['barcode'], $city);
    $parsed  = parseCHP($chpData);

    echo '<script>
        var el = document.getElementById("p' . $z . '");
        el.className = "pl done";
        el.textContent = ' . json_encode('✔ ברקוד ' . $z . ': ' . $item['barcode'] . ' ← ' . $parsed['hebrewName'] . ' | מקס: ' . $parsed['maxPrice'] . ' | מין: ' . $parsed['minPrice'] . ($parsed['national'] ? ' (ארצי — לא בתל אביב)' : ''), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';
    </script>';
    sf();

    $results[] = array_merge(['row' => $item['row'], 'inputBarcode' => $item['barcode'], 'salePrice' => $item['salePrice']], $parsed);
}
?>
</div>

<?php
// ── Write results to xlsx ──────────────────────────────────────────────────────
foreach ($results as $r) {
    $row = $r['row'];
    $sheet->setCellValue('B' . $row, $r['hebrewName']);
    $sheet->setCellValueExplicit('C' . $row, $r['returnedBarcode'], DataType::TYPE_STRING);
    $sheet->setCellValue('D' . $row, $r['maxPrice']);
    $sheet->setCellValue('E' . $row, $r['minPrice']);
    if (!empty($r['national'])) {   // national range (no Tel Aviv store sells it): yellow + a note
        $sheet->getStyle("D{$row}:E{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF3B0');
        $sheet->getComment("D{$row}")->getText()->createTextRun('מחיר ארצי — המוצר לא נמכר בתל אביב');
    }
}

foreach (range('B', 'G') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Save as _CHPF.xlsx
$base      = pathinfo($xlsxFile, PATHINFO_FILENAME);
$chpfFile  = $base . '_CHPF.xlsx';
$chpfPath  = $npDir . '/' . $chpfFile;
try {
    (new Xlsx($spreadsheet))->save($chpfPath);
    $saveOk = true;
} catch (Exception $e) {
    $saveOk  = false;
    $saveErr = $e->getMessage();
}
?>

<div class="saved-box">
    <?php if ($saveOk): ?>
        ✅ נשמר: <?= htmlspecialchars($chpfFile) ?>
    <?php else: ?>
        ❌ שגיאה בשמירה: <?= htmlspecialchars($saveErr) ?>
    <?php endif; ?>
</div>

<?php
// ── Price comparison ──────────────────────────────────────────────────────────
$naCount    = 0;
$inRange    = 0;
$outOfRange = [];
foreach ($results as $r) {
    if ($r['maxPrice'] === 'NA' || $r['minPrice'] === 'NA') { $naCount++; continue; }
    $sale = $r['salePrice'];
    $max  = (float)str_replace(',', '', $r['maxPrice']);
    $min  = (float)str_replace(',', '', $r['minPrice']);
    if ($sale > $max)      $outOfRange[] = ['r' => $r, 'issue' => 'above_max', 'max' => $max, 'min' => $min];
    elseif ($sale < $min)  $outOfRange[] = ['r' => $r, 'issue' => 'below_min', 'max' => $max, 'min' => $min];
    else                   $inRange++;
}
$compared = $inRange + count($outOfRange);

function priceStatus($r) {
    if ($r['maxPrice'] === 'NA' || $r['minPrice'] === 'NA') return ['na', 'NA'];
    $sale = $r['salePrice'];
    $max  = (float)str_replace(',', '', $r['maxPrice']);
    $min  = (float)str_replace(',', '', $r['minPrice']);
    if ($sale > $max) return ['above', '▲ גבוה ממקסימום'];
    if ($sale < $min) return ['below', '▼ נמוך ממינימום'];
    return ['ok', '✔ בטווח'];
}
?>

<h3>תוצאות מחירי רשתות (CPD)</h3>
<table>
    <tr><th>#</th><th>ברקוד קלט</th><th>שם מוצר</th><th>ברקוד CPD</th><th>מחיר מקסימלי</th><th>מחיר מינימלי</th><th>מחיר מכירה</th><th>סטטוס</th></tr>
    <?php foreach ($results as $idx => $r):
        [$cls, $label] = priceStatus($r);
        $tdCls = $cls === 'above' ? 'price-above' : ($cls === 'below' ? 'price-below' : ($cls === 'na' ? 'price-na' : 'price-ok'));
    ?>
    <tr>
        <td><?= $idx + 1 ?></td>
        <td><?= htmlspecialchars($r['inputBarcode']) ?></td>
        <td><?= htmlspecialchars($r['hebrewName']) ?></td>
        <td><?= htmlspecialchars($r['returnedBarcode']) ?></td>
        <td><?= htmlspecialchars($r['maxPrice']) ?></td>
        <td><?= htmlspecialchars($r['minPrice']) ?></td>
        <td><?= number_format($r['salePrice'], 2) ?></td>
        <td class="<?= $tdCls ?>"><?= $label ?><?= !empty($r['national']) ? ' <small>(מחיר ארצי)</small>' : '' ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<!-- ── Price range summary ───────────────────────────────────────────────────── -->
<div class="price-stats">
    <span>סה״כ שורות: <?= count($results) ?></span>
    <span>הושוו: <?= $compared ?></span>
    <?php if ($naCount > 0): ?>
    <span>ללא מחירי CPD (NA): <?= $naCount ?></span>
    <?php endif; ?>
</div>

<?php if (empty($outOfRange) && $compared > 0): ?>
<div class="ok-box">✅ כל המחירים בטווח</div>
<?php elseif (!empty($outOfRange)): ?>
<div class="warn-box">
    <h4>⚠ שים לב ל <?= count($outOfRange) ?> מוצרים עם מחירים מחוץ לטווח CPD</h4>
    <?php foreach ($outOfRange as $w):
        $r = $w['r'];
        if ($w['issue'] === 'above_max')
            $msg = "{$r['hebrewName']} ({$r['inputBarcode']}) — מחיר מכירה " . number_format($r['salePrice'],2) . " גבוה ממקסימום " . number_format($w['max'],2) . " ב CPD";
        else
            $msg = "{$r['hebrewName']} ({$r['inputBarcode']}) — מחיר מכירה " . number_format($r['salePrice'],2) . " נמוך ממינימום " . number_format($w['min'],2) . " ב CPD";
    ?>
    <div class="warn-row"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<form id="advanceForm" action="stage_dcl.php" method="post">
    <input type="hidden" name="np_dir"    value="<?= htmlspecialchars($npDir) ?>">
    <input type="hidden" name="xlsx_file" value="<?= htmlspecialchars($chpfFile) ?>">
    <input type="hidden" name="shop"      value="<?= htmlspecialchars($shop) ?>">
    <input type="hidden" name="date"      value="<?= htmlspecialchars($date) ?>">
    <input type="hidden" name="dept_file" value="<?= htmlspecialchars($deptFile) ?>">
    <input type="hidden" name="mode"      value="<?= htmlspecialchars($mode) ?>">
    <button type="submit" class="btn-next" <?= $saveOk ? '' : 'disabled' ?>>אישור – עבור לשלב 3 – סיווג מחלקות ←</button>
</form>
<?php if ($saveOk) renderFlowAutoAdvance($mode, 'advanceForm'); ?>

</body>
</html>

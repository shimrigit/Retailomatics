<?php
// Stage 3 save — applies the user's edits (barcode / product name / department
// number) from the final review screen, writes them into the xlsx, and saves
// the definitive _DCL.xlsx. This is the last step of the NP process.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/barcode_validate.php';
require_once __DIR__ . '/xlsx_retry.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['np_dir']) || empty($_POST['barcode'])) {
    header('Location: index.php'); exit;
}

$npDir    = $_POST['np_dir'];
$xlsxFile = $_POST['xlsx_file'];   // _BR_CHPF.xlsx
$shop     = $_POST['shop']      ?? '';
$date     = $_POST['date']      ?? '';
$deptFile = $_POST['dept_file'] ?? '';

$barcodes = $_POST['barcode'] ?? [];
$names    = $_POST['name']    ?? [];
$deptNums = $_POST['deptnum'] ?? [];

// ── Load departments ──────────────────────────────────────────────────────────
if (!file_exists($deptFile)) {
    die('<p style="color:red;font-family:Arial;font-size:22px;padding:30px">שגיאה: קובץ מחלקות לא נמצא: ' . htmlspecialchars($deptFile) . '</p>');
}
$departments = json_decode(file_get_contents($deptFile), true) ?? [];
$deptLookup  = [];  // name => number
foreach ($departments as $d) {
    if (isset($d['DepartmentName'], $d['DepartmentNumber'])) {
        $deptLookup[$d['DepartmentName']] = $d['DepartmentNumber'];
    }
}
$numToName = array_flip($deptLookup); // number => name

// ── Load the (pristine) _BR_CHPF xlsx ─────────────────────────────────────────
try {
    $spreadsheet = loadSpreadsheetWithRetry($npDir . '/' . $xlsxFile);
} catch (Exception $e) {
    die('<p style="color:red;font-family:Arial;font-size:22px;padding:30px">שגיאה בטעינת Excel: ' . htmlspecialchars($e->getMessage()) . '</p>');
}
$sheet = $spreadsheet->getActiveSheet();

// ── Apply edits + recompute price-range status per row (price itself is never
//    edited on the review screen, so re-read D/E/F straight from the file) ───
$rows      = array_keys($barcodes);
sort($rows, SORT_NUMERIC);

$finalRows = [];
$pcWarn    = [];
$pcNa      = 0;
$pcOk      = 0;

foreach ($rows as $row) {
    $barcode  = trim($barcodes[$row] ?? '');
    $name     = trim($names[$row] ?? '');
    $deptNum  = trim($deptNums[$row] ?? '');
    $deptName = $numToName[$deptNum] ?? '';

    // Final save only: copy the (possibly corrected) barcode into both A and C
    // so they end up identical in the finished NP file.
    $sheet->getStyle('A' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
    $sheet->setCellValueExplicit('A' . $row, $barcode, DataType::TYPE_STRING);
    $sheet->getStyle('C' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
    $sheet->setCellValueExplicit('C' . $row, $barcode, DataType::TYPE_STRING);
    $sheet->setCellValue('B' . $row, $name);
    $sheet->setCellValue('G' . $row, $deptNum);
    // Department NAME is shown on screen for reference only — the final NP
    // file carries just the department number (G), so column H stays clear.
    $sheet->setCellValue('H' . $row, null);

    $maxP  = trim((string)$sheet->getCell('D' . $row)->getValue());
    $minP  = trim((string)$sheet->getCell('E' . $row)->getValue());
    $saleP = (float)$sheet->getCell('F' . $row)->getValue();

    if ($maxP === '' || $maxP === 'NA' || $minP === '' || $minP === 'NA') {
        $status = 'na'; $pcNa++;
    } else {
        $max = (float)str_replace(',', '', $maxP);
        $min = (float)str_replace(',', '', $minP);
        if ($saleP > $max)     { $status = 'above'; $pcWarn[] = ['name' => $name, 'barcode' => $barcode, 'sale' => $saleP, 'max' => $max, 'min' => $min]; }
        elseif ($saleP < $min) { $status = 'below'; $pcWarn[] = ['name' => $name, 'barcode' => $barcode, 'sale' => $saleP, 'max' => $max, 'min' => $min, 'isBelow' => true]; }
        else                   { $status = 'ok'; $pcOk++; }
    }

    // The CHP min/max were only needed to compute the status above — the
    // final NP file should not carry them in columns D/E. Column K (source
    // image filename, used to re-show FTP photos on the review screen) is
    // also working data, not meant for the final deliverable.
    $sheet->setCellValue('D' . $row, null);
    $sheet->setCellValue('E' . $row, null);
    $sheet->setCellValue('K' . $row, null);

    $finalRows[] = [
        'barcode'    => $barcode,
        'name'       => $name,
        'price'      => $saleP,
        'status'     => $status,
        'deptNum'    => $deptNum,
        'deptName'   => $deptName,
        'checksumOk' => validateBarcode($barcode),
    ];
}
$pcCompared = $pcOk + count($pcWarn);

foreach (['A', 'B', 'C', 'G'] as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Note: the full department reference list is no longer written to the
// spreadsheet (previously columns I/J) — it's shown in the app's side panel
// instead, so the final NP file stays clean.

// ── Save as _DCL.xlsx (final) ─────────────────────────────────────────────────
$base    = pathinfo($xlsxFile, PATHINFO_FILENAME);
$dclFile = $base . '_DCL.xlsx';
$dclPath = $npDir . '/' . $dclFile;
try {
    (new Xlsx($spreadsheet))->save($dclPath);
    $saveOk = true;
} catch (Exception $e) {
    $saveOk  = false;
    $saveErr = $e->getMessage();
}

$statusLabel = ['ok' => 'בטווח', 'above' => 'מעל המקסימום', 'below' => 'מתחת למינימום', 'na' => 'אין נתוני השוואה'];
$statusClass = ['ok' => 'st-ok', 'above' => 'st-bad', 'below' => 'st-bad', 'na' => 'st-na'];
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>NP Harmonized – התהליך הושלם</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 45px; }
        h2   { color: #2575a8; font-size: 36px; margin-bottom: 9px; }
        .sub { color: #777; font-size: 20px; margin-bottom: 36px; }
        .saved-box { background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 8px; padding: 21px 28px; max-width: 1100px; margin-bottom: 36px; font-size: 21px; color: #2a7d2a; font-weight: bold; }
        .saved-box.err { background: #fdecea; border-color: #f5c6c6; color: #c0392b; }
        table { border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,.1); max-width: 1300px; width: 100%; margin-bottom: 36px; table-layout: fixed; }
        th { background: #2575a8; color: #fff; padding: 14px 18px; font-size: 20px; text-align: right; }
        td { padding: 12px 18px; border-bottom: 1px solid #eee; font-size: 20px; color: #333; overflow-wrap: break-word; }
        tr:last-child td { border-bottom: none; }
        .col-barcode { width: calc(13ch + 30px); }
        .col-name    { width: calc(13ch * 2.5); }
        .col-deptnum { width: calc(3ch + 40px); }
        .checksum-cell { text-align: center; font-size: 22px; font-weight: bold; }
        .checksum-cell.cs-ok  { color: #2a7d2a; }
        .checksum-cell.cs-bad { color: #c0392b; }
        .st-ok  { color: #2a7d2a; font-weight: bold; }
        .st-bad { color: #c0392b; font-weight: bold; }
        .st-na  { color: #999; }
        .price-stats { font-size: 20px; color: #555; margin-bottom: 16px; }
        .price-stats span { margin-left: 28px; }
        .ok-box  { background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 8px; padding: 21px 28px; max-width: 1100px; margin-bottom: 36px; font-size: 21px; color: #2a7d2a; font-weight: bold; }
        .warn-box { background: #fdecea; border: 2px solid #e57373; border-radius: 8px; padding: 21px 28px; max-width: 1100px; margin-bottom: 36px; }
        .warn-box h4 { color: #c0392b; font-size: 24px; margin: 0 0 14px; }
        .warn-row { font-size: 20px; color: #c0392b; margin: 6px 0; }
        .btn-copy { margin-top: 18px; padding: 12px 30px; background: #2575a8; color: #fff; border: none; border-radius: 7px; font-size: 20px; cursor: pointer; }
        .btn-copy:hover { background: #1a5c87; }
        .finish-banner { background: #e8f5e9; border: 2px solid #2a7d2a; border-radius: 10px; padding: 28px 36px; max-width: 1100px; margin-bottom: 36px; font-size: 27px; color: #2a7d2a; font-weight: bold; text-align: center; }
        .btn-home  { display: inline-block; padding: 17px 50px; background: #2575a8; color: #fff; border: none; border-radius: 8px; font-size: 24px; cursor: pointer; text-decoration: none; }
        .btn-home:hover { background: #1a5c87; }
    </style>
</head>
<body>
<h2>NP Harmonized – שלב 3 הושלם: סיווג מחלקות נשמר</h2>
<p class="sub">חנות: <?= htmlspecialchars($shop) ?> | תאריך: <?= htmlspecialchars($date) ?></p>

<div class="saved-box <?= $saveOk ? '' : 'err' ?>">
    <?php if ($saveOk): ?>
        ✅ קובץ סופי נשמר: <?= htmlspecialchars($dclFile) ?>
    <?php else: ?>
        ❌ שגיאה בשמירה: <?= htmlspecialchars($saveErr) ?>
    <?php endif; ?>
</div>

<table>
    <colgroup>
        <col style="width:40px">
        <col class="col-barcode">
        <col style="width:80px">
        <col class="col-name">
        <col style="width:110px">
        <col style="width:150px">
        <col class="col-deptnum">
        <col style="width:140px">
    </colgroup>
    <tr><th>#</th><th>ברקוד</th><th>ביקורת ספרה</th><th>שם מוצר</th><th>מחיר מכירה</th><th>מחיר מול CPD</th><th>מספר מחלקה</th><th>שם מחלקה</th></tr>
    <?php foreach ($finalRows as $i => $r): ?>
    <tr>
        <td><?= $i + 1 ?></td>
        <td><?= htmlspecialchars($r['barcode']) ?></td>
        <td class="checksum-cell <?= $r['checksumOk'] ? 'cs-ok' : 'cs-bad' ?>"><?= $r['checksumOk'] ? '✔' : '✘' ?></td>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td><?= number_format($r['price'], 2) ?></td>
        <td class="<?= $statusClass[$r['status']] ?>"><?= $statusLabel[$r['status']] ?></td>
        <td><?= htmlspecialchars((string)$r['deptNum']) ?></td>
        <td><?= htmlspecialchars($r['deptName'] ?: '—') ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<!-- ── Price range summary ───────────────────────────────────────────────────── -->
<h3 style="color:#555;font-size:27px;margin-bottom:18px">סיכום מחירים מול CPD</h3>
<div class="price-stats">
    <span>סה״כ שורות: <?= count($finalRows) ?></span>
    <span>הושוו: <?= $pcCompared ?></span>
    <?php if ($pcNa > 0): ?>
    <span>ללא מחירי CPD (NA): <?= $pcNa ?></span>
    <?php endif; ?>
</div>

<?php if (empty($pcWarn) && $pcCompared > 0): ?>
<div class="ok-box">✅ כל המחירים בטווח</div>
<?php elseif (!empty($pcWarn)):
    $warnLines = [];
    foreach ($pcWarn as $w) {
        if (!empty($w['isBelow']))
            $warnLines[] = "{$w['name']} ({$w['barcode']}) — מחיר מכירה " . number_format($w['sale'],2) . " נמוך ממינימום " . number_format($w['min'],2) . " ב CPD";
        else
            $warnLines[] = "{$w['name']} ({$w['barcode']}) — מחיר מכירה " . number_format($w['sale'],2) . " גבוה ממקסימום " . number_format($w['max'],2) . " ב CPD";
    }
    $copyText = "שים לב ל " . count($pcWarn) . " מוצרים עם מחירים מחוץ לטווח CPD\n" . implode("\n", $warnLines);
?>
<div class="warn-box">
    <h4>⚠ שים לב ל <?= count($pcWarn) ?> מוצרים עם מחירים מחוץ לטווח CPD</h4>
    <?php foreach ($warnLines as $line): ?>
    <div class="warn-row"><?= htmlspecialchars($line) ?></div>
    <?php endforeach; ?>
    <button type="button" class="btn-copy" onclick="copyWarn()">📋 העתק להודעת WhatsApp</button>
</div>
<script>
function copyWarn() {
    var text = <?= json_encode($copyText, JSON_UNESCAPED_UNICODE) ?>;
    navigator.clipboard.writeText(text).then(function() {
        var btn = document.querySelector('.btn-copy');
        var orig = btn.textContent;
        btn.textContent = '✔ הועתק!';
        setTimeout(function() { btn.textContent = orig; }, 2500);
    });
}
</script>
<?php endif; ?>

<div class="finish-banner">
    🎉 תהליך NP Harmonized הושלם בהצלחה!<br>
    <span style="font-size:20px;font-weight:normal">קבצים שנשמרו בתיקיית NP: _BR.xlsx → _CHPF.xlsx → _DCL.xlsx</span>
</div>

<a class="btn-home" href="index.php">חזור לתחילה</a>

</body>
</html>

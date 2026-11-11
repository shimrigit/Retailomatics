<?php
// Stage 1 save – writes barcodes + prices to xlsx, saves _BR.xlsx
session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/xlsx_retry.php';
require_once __DIR__ . '/flow_mode.php';
require_once __DIR__ . '/shop_city.php';
require_once __DIR__ . '/../commercialLayer/cpd_client.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['br_results'])) {
    header('Location: index.php'); exit;
}

$results  = $_SESSION['br_results'];
$npDir    = $_SESSION['br_npdir'];
$xlsxFile = $_SESSION['br_xlsx'];
$shop     = $_SESSION['br_shop'];
$date     = $_SESSION['br_date'];
$deptFile = $_SESSION['br_deptfile'];
$barcodes = $_POST['barcode'] ?? [];
$mode     = npFlowMode();

// ── Load xlsx and write data ──────────────────────────────────────────────────
try {
    $spreadsheet = loadSpreadsheetWithRetry($npDir . '/' . $xlsxFile);
} catch (Exception $e) {
    die('<p style="color:red;font-family:Arial;font-size:22px;padding:30px">שגיאה בטעינת Excel: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

$sheet = $spreadsheet->getActiveSheet();
$rows  = [];

foreach ($results as $i => $item) {
    $row     = $i + 2;
    $barcode = trim($barcodes[$i] ?? $item['barcode']);
    $price   = $item['price'];

    $sheet->getStyle('A' . $row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
    $sheet->setCellValueExplicit('A' . $row, $barcode, DataType::TYPE_STRING);
    $sheet->setCellValue('B' . $row, $price);
    // Column K carries the source image filename through the pipeline so the
    // final review screen (stage_dcl.php) can show it again for rows that
    // fail final verification. Cleared before the final _DCL.xlsx save.
    $sheet->setCellValue('K' . $row, $item['fname']);

    $rows[] = ['barcode' => $barcode, 'price' => $price, 'fname' => $item['fname']];
}

foreach (['A', 'B', 'K'] as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Save as _BR.xlsx
$base    = pathinfo($xlsxFile, PATHINFO_FILENAME);
$brFile  = $base . '_BR.xlsx';
$brPath  = $npDir . '/' . $brFile;

try {
    (new Xlsx($spreadsheet))->save($brPath);
} catch (Exception $e) {
    die('<p style="color:red;font-family:Arial;font-size:22px;padding:30px">שגיאה בשמירת Excel: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

// Clear session BR data
unset($_SESSION['br_results'], $_SESSION['br_npdir'], $_SESSION['br_xlsx'],
      $_SESSION['br_shop'], $_SESSION['br_date'], $_SESSION['br_deptfile']);
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>NP Harmonized – שלב 1 הושלם</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; padding: 45px; margin: 0; }
        h2   { color: #2575a8; font-size: 36px; margin-bottom: 9px; }
        .sub { color: #777; font-size: 20px; margin-bottom: 36px; }
        .saved-box { background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 8px; padding: 21px 28px; max-width: 900px; margin-bottom: 36px; font-size: 21px; color: #2a7d2a; font-weight: bold; }
        table { border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,.1); max-width: 900px; width: 100%; margin-bottom: 36px; }
        th { background: #2575a8; color: #fff; padding: 14px 18px; font-size: 20px; text-align: right; }
        td { padding: 12px 18px; border-bottom: 1px solid #eee; font-size: 20px; color: #333; }
        tr:last-child td { border-bottom: none; }
        .btn-next { padding: 17px 50px; background: #2575a8; color: #fff; border: none; border-radius: 8px; font-size: 24px; cursor: pointer; }
        .btn-next:hover { background: #1a5c87; }
    </style>
</head>
<body>
<h2>NP Harmonized – שלב 1 הושלם: ברקודים נשמרו</h2>
<p class="sub">חנות: <?= htmlspecialchars($shop) ?> | תאריך: <?= htmlspecialchars($date) ?></p>

<div class="saved-box">
    ✅ נשמר: <?= htmlspecialchars($brFile) ?><br>
    <?= count($rows) ?> ברקודים → עמודה A &nbsp;|&nbsp; <?= count($rows) ?> מחירים → עמודה B
</div>

<table>
    <tr><th>#</th><th>ברקוד</th><th>מחיר</th><th>קובץ</th></tr>
    <?php foreach ($rows as $i => $r): ?>
    <tr>
        <td><?= $i + 1 ?></td>
        <td><?= htmlspecialchars($r['barcode']) ?></td>
        <td><?= htmlspecialchars($r['price']) ?></td>
        <td style="font-size:16px;color:#777"><?= htmlspecialchars($r['fname']) ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<form id="advanceForm" action="stage_chpf.php" method="post">
    <input type="hidden" name="np_dir"    value="<?= htmlspecialchars($npDir) ?>">
    <input type="hidden" name="xlsx_file" value="<?= htmlspecialchars($brFile) ?>">
    <input type="hidden" name="shop"      value="<?= htmlspecialchars($shop) ?>">
    <input type="hidden" name="date"      value="<?= htmlspecialchars($date) ?>">
    <input type="hidden" name="dept_file" value="<?= htmlspecialchars($deptFile) ?>">
    <input type="hidden" name="mode"      value="<?= htmlspecialchars($mode) ?>">
    <?php // The CPD price region: the shop's city if CPD has it, else the first (Tel Aviv). ?>
    <?php $cpdRegions = new CPDClient(); $cpdRegion = $cpdRegions->regionKey(npShopCity($shop)); ?>
    <label style="font-size:20px;margin-left:12px">אזור מחירים:
        <select name="city" style="font-size:20px">
            <?php foreach ($cpdRegions->regions() as $key => $name): ?>
                <option value="<?= htmlspecialchars($name) ?>" <?= $key === $cpdRegion ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button type="submit" class="btn-next">המשך לשלב 2 – מחירי רשתות (CPD) ←</button>
</form>
<?php renderFlowAutoAdvance($mode, 'advanceForm'); ?>
</body>
</html>

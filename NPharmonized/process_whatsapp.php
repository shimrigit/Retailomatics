<?php
// process_whatsapp.php — "WhatsApp" image-harvest counterpart to process.php.
//
// Copies the images staged by the chosen WhatsApp source (wa_source.php: the
// group download's output/images, or the webhook's whatsapp_app/whatsapp_images)
// into the NP directory (ordered by their serial number, not their embedded
// timestamp) — the originals are left in place for debugging (a group
// download's are recorded as taken, see WaFetch) — generates a fresh New
// Products List (NPL) with the required headers, and writes each image's
// caption ("y" value) into column F, compacting any gaps caused by missing
// serial numbers. From here on the flow rejoins the Manual method at
// stage_br.php.
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/flow_mode.php';
require_once __DIR__ . '/wa_source.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['np_dir'])) {
    header('Location: index.php');
    exit;
}

$npDir      = $_POST['np_dir'];
$shop       = $_POST['shop']      ?? '';
$date       = $_POST['date']      ?? '';
$deptFile   = $_POST['dept_file'] ?? '';
$rootLetter = $_POST['root_letter'] ?? '';
$mode       = npFlowMode();

$waSource          = npWaSource();
$whatsappImagesDir = npWaImagesDir($waSource);

// ── Ensure the NP directory exists (defensive re-check) ────────────────────────
if (!is_dir($npDir) && !@mkdir($npDir, 0777, true)) {
    die('<p style="color:red;font-family:Arial;padding:20px">שגיאה: לא ניתן ליצור את תיקיית ה-NP: ' . htmlspecialchars($npDir) . '</p>');
}

// ── Re-validate the staged WhatsApp images (defensive re-check) ────────────────
$waPattern = '/^\d{6}-\d{6} (\d+) (.+)\.jpe?g$/i';
$waFiles   = glob($whatsappImagesDir . '/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE) ?: [];

$items        = []; // ['fullPath','fname','serial','caption']
$invalidNames = [];
$seenSerials  = [];
$duplicates   = [];

foreach ($waFiles as $fullPath) {
    $fname = basename($fullPath);
    if (preg_match($waPattern, $fname, $m) && ctype_digit($m[1])) {
        $serial = (int)$m[1];
        if (isset($seenSerials[$serial])) {
            $duplicates[] = $serial;
        }
        $seenSerials[$serial] = true;
        $items[] = ['fullPath' => $fullPath, 'fname' => $fname, 'serial' => $serial, 'caption' => $m[2]];
    } else {
        $invalidNames[] = $fname;
    }
}
$duplicates = array_values(array_unique($duplicates));

if (empty($items) || !empty($invalidNames) || !empty($duplicates)) {
    ?>
    <!DOCTYPE html>
    <html lang="he" dir="rtl">
    <head><meta charset="UTF-8"><title>NP Harmonized – שגיאה</title>
    <style>body{font-family:Arial,sans-serif;background:#f4f4f4;padding:45px}
    .card{background:#fff;border-radius:10px;padding:36px 48px;max-width:900px;box-shadow:0 2px 8px rgba(0,0,0,.12)}
    h2{color:#c0392b} .warn{color:#c0392b;font-size:20px} .filelist{font-size:16px;color:#c0392b;margin-top:10px}
    a.btn{display:inline-block;margin-top:24px;padding:14px 36px;background:#888;color:#fff;border-radius:8px;text-decoration:none;font-size:20px}</style>
    </head><body><div class="card">
    <h2>⚠️ לא ניתן להמשיך</h2>
    <?php if (empty($items) && empty($invalidNames)): ?>
        <p class="warn">לא נמצאו תמונות בתיקיית <?= htmlspecialchars($whatsappImagesDir) ?></p>
    <?php endif; ?>
    <?php if (!empty($invalidNames)): ?>
        <p class="warn">שמות קבצים לא תואמים למוסכמה "ddmmyy-hhmmss n y":</p>
        <div class="filelist"><?php foreach ($invalidNames as $bad): ?><div><?= htmlspecialchars($bad) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if (!empty($duplicates)): ?>
        <p class="warn">מספרים סידוריים כפולים: <?= htmlspecialchars(implode(', ', $duplicates)) ?></p>
    <?php endif; ?>
    <a class="btn" href="index.php">חזור לתחילה</a>
    </div></body></html>
    <?php
    exit;
}

// ── Sort by serial number (not by embedded timestamp) ───────────────────────────
usort($items, fn($a, $b) => $a['serial'] <=> $b['serial']);

// ── Transfer (copy) images into the NP directory ─────────────────────────────────
// Originals are kept in the source folder for debugging — not moved.
$log      = []; // ['type'=>'ok'|'skip'|'err','msg'=>'...']
$copied   = 0;
$skipped  = 0;

foreach ($items as &$item) {
    $destPath = $npDir . '/' . $item['fname'];

    if (file_exists($destPath)) {
        $log[]   = ['type' => 'skip', 'msg' => "דילוג על '{$item['fname']}': הקובץ כבר קיים ביעד"];
        $skipped++;
        $item['fullPath'] = $destPath; // already there — use existing copy
        continue;
    }

    if (copy($item['fullPath'], $destPath)) {
        $log[]  = ['type' => 'ok', 'msg' => "{$item['fname']}  →  " . $npDir];
        $copied++;
        $item['fullPath'] = $destPath;
    } else {
        $log[]   = ['type' => 'err', 'msg' => "שגיאה בהעתקת קובץ: {$item['fname']}"];
        $skipped++;
    }
}
unset($item);

// A group download's images are now in NP: WaFetch's next download may
// archive them without warning (WaFetch/config.php).
if ($waSource === 'group') {
    $inNp = array_map(fn($i) => $i['fname'], array_filter($items, fn($i) => strpos($i['fullPath'], $npDir) === 0));
    waf_mark_consumed(array_values($inNp), "NP {$shop} {$date}");
}

// ── Generate the NPL workbook with the required headers ─────────────────────────
$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();

$headers = [
    'A' => 'מס פריט',
    'B' => 'שם פריט',
    'C' => 'ברקוד',
    'D' => 'מחיר קניה',
    'E' => 'ספק',
    'F' => 'מחיר מכירה',
    'G' => 'מחלקה',
];
foreach ($headers as $col => $text) {
    $sheet->setCellValue("{$col}1", $text);
}
$sheet->getStyle('A1:G1')->getFont()->setBold(true);

// Column F, rows 2..N+1, in serial order — gaps (missing serials) are never
// materialized since rows are written compacted/contiguous from the start.
$rowsWritten = 0;
foreach ($items as $i => $item) {
    $row = $i + 2;
    $sheet->setCellValue("F{$row}", $item['caption']);
    $rowsWritten++;
}

foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$dt          = DateTime::createFromFormat('Y-m-d', $date);
$dateFolder  = $dt ? ($dt->format('d') . '-' . $dt->format('m') . '-' . $dt->format('y') . ' NP') : 'NP';
$xlsxFile    = $dateFolder . '.xlsx';
$xlsxPath    = $npDir . '/' . $xlsxFile;

try {
    (new Xlsx($spreadsheet))->save($xlsxPath);
} catch (Exception $e) {
    die('<p style="color:red;font-family:Arial;padding:20px">שגיאה בשמירת קובץ ה-NPL: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

$serials    = array_column($items, 'serial');
$minSerial  = min($serials);
$maxSerial  = max($serials);
$gapsFound  = ($maxSerial - $minSerial + 1) !== count($serials);
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>NP Harmonized – קליטת תמונות WhatsApp</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; padding: 45px; margin: 0; }
        h2 { color: #2575a8; margin-bottom: 9px; font-size: 36px; }
        .sub { color: #777; font-size: 20px; margin-bottom: 36px; }
        .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.12); padding: 36px 48px; max-width: 1350px; margin-bottom: 36px; }
        .log-line { font-size: 20px; padding: 8px 0; border-bottom: 1px solid #f0f0f0; font-family: monospace; }
        .log-line:last-child { border-bottom: none; }
        .ok   { color: #2a7d2a; }
        .skip { color: #b57a00; }
        .err  { color: #c0392b; }
        .summary { padding: 21px 30px; border-radius: 8px; font-size: 21px; font-weight: bold; }
        .summary.all-ok  { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2a7d2a; }
        .summary.has-err { background: #fff8e1; border: 1px solid #ffe082; color: #7a5800; }
        .count-row { display: flex; gap: 42px; margin-top: 18px; font-size: 20px; font-weight: normal; color: #555; }
        .actions { margin-top: 27px; display: flex; gap: 21px; }
        .btn { display: inline-block; padding: 17px 42px; border-radius: 8px; font-size: 22px; cursor: pointer; border: none; text-decoration: none; color: #fff; }
        .btn-next { background: #2575a8; }
        .btn-next:hover { background: #1a5c87; }
        .btn-back { background: #888; }
        .btn-back:hover { background: #666; }
        .note { font-size: 17px; color: #888; margin-top: 8px; }
    </style>
</head>
<body>

<h2>NP Harmonized – קליטת תמונות WhatsApp</h2>
<p class="sub">חנות: <?= htmlspecialchars($shop) ?> | תאריך: <?= htmlspecialchars($date) ?></p>

<div class="card">
    <h3 style="margin-top:0;color:#555">יומן העתקת תמונות</h3>
    <?php foreach ($log as $entry): ?>
        <div class="log-line <?= htmlspecialchars($entry['type']) ?>">
            <?= htmlspecialchars($entry['msg']) ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <h3 style="margin-top:0;color:#555">קובץ NPL שנוצר</h3>
    <p class="sub" style="margin-bottom:0">
        <strong><?= htmlspecialchars($xlsxFile) ?></strong> — <?= $rowsWritten ?> שורות מחיר בעמודה F
    </p>
    <?php if ($gapsFound): ?>
        <div class="note">ℹ️ נמצאו פערים במספרים הסידוריים (<?= $minSerial ?>–<?= $maxSerial ?>) — השורות נכתבו ברצף ללא פערים.</div>
    <?php endif; ?>
</div>

<div class="summary <?= $skipped === 0 ? 'all-ok' : 'has-err' ?>">
    <?php if ($skipped === 0): ?>
        ✅ הושלם. <?= $copied ?> תמונות הועתקו בהצלחה, קובץ NPL נוצר.
    <?php else: ?>
        ⚠️ הושלם עם הערות. <?= $copied ?> הועתקו, <?= $skipped ?> דולגו/נכשלו.
    <?php endif; ?>
    <div class="count-row">
        <span>✔ הועתקו: <?= $copied ?></span>
        <?php if ($skipped > 0): ?><span>⚠ דולגו/נכשלו: <?= $skipped ?></span><?php endif; ?>
        <span>סה״כ תמונות: <?= count($items) ?></span>
    </div>
</div>

<div class="actions">
    <a class="btn btn-back" href="index.php">חזור לתחילה</a>
    <form id="advanceForm" action="stage_br.php" method="post" style="display:inline">
        <input type="hidden" name="np_dir"       value="<?= htmlspecialchars($npDir) ?>">
        <input type="hidden" name="xlsx_file"    value="<?= htmlspecialchars($xlsxFile) ?>">
        <input type="hidden" name="shop"         value="<?= htmlspecialchars($shop) ?>">
        <input type="hidden" name="date"         value="<?= htmlspecialchars($date) ?>">
        <input type="hidden" name="dept_file"    value="<?= htmlspecialchars($deptFile) ?>">
        <input type="hidden" name="harvest_mode" value="whatsapp">
        <input type="hidden" name="expected_images" value="<?= count($items) ?>">
        <input type="hidden" name="mode"         value="<?= htmlspecialchars($mode) ?>">
        <button type="submit" class="btn btn-next">אישור – עבור לקריאת ברקודים ←</button>
    </form>
</div>
<?php if ($skipped === 0) renderFlowAutoAdvance($mode, 'advanceForm'); ?>

</body>
</html>

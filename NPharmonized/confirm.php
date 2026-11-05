<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/flow_mode.php';
require_once __DIR__ . '/wa_source.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['shop']) || empty($_POST['date'])) {
    header('Location: index.php');
    exit;
}

$date        = $_POST['date'];
$shop        = basename($_POST['shop']);
$rootLetter  = strtoupper(trim($_POST['root_letter'] ?? 'Z'));
if (!preg_match('/^[A-Za-z]$/', $rootLetter)) $rootLetter = 'Z';
$harvestMode = ($_POST['harvest_mode'] ?? 'manual') === 'whatsapp' ? 'whatsapp' : 'manual';
$waSource    = npWaSource();
$mode        = npFlowMode();

// ── Date parts ────────────────────────────────────────────────────────────────
$dt          = DateTime::createFromFormat('Y-m-d', $date);
$yyyy        = $dt->format('Y');
$mm          = $dt->format('m');
$mmm         = $dt->format('F');   // June
$dd          = $dt->format('d');
$yy          = $dt->format('y');   // 26

$dateFolder  = "{$dd}-{$mm}-{$yy} NP";   // 23-06-26 NP
$monthFolder = "{$mm}_{$mmm}";            // 06_June

// ── Build paths (forward slashes — PHP on Windows handles both) ───────────────
$npDir    = "{$rootLetter}:/RetailomaticsCloud/RetailomaticsArchive/{$shop}/NewProducts/{$yyyy}/{$monthFolder}/{$dateFolder}";
$deptFile = __DIR__ . "/../configDir/{$shop}_Departments.json";

// Display versions (Windows backslash style)
$npDirDisplay    = "{$rootLetter}:\\RetailomaticsCloud\\RetailomaticsArchive\\{$shop}\\NewProducts\\{$yyyy}\\{$monthFolder}\\{$dateFolder}";
$deptFileDisplay = "C:\\xampp\\htdocs\\website\\configDir\\{$shop}_Departments.json";

$deptExists = file_exists($deptFile);

function ok($cond) {
    return $cond
        ? '<span style="color:#2a7d2a;font-size:17px">✅</span>'
        : '<span style="color:#c0392b;font-size:17px">❌</span>';
}

if ($harvestMode === 'whatsapp') {

    // ── WhatsApp mode: create the NP directory if it doesn't exist yet ────────
    $npDirExisted = is_dir($npDir);
    $npDirCreated = false;
    if (!$npDirExisted) {
        $npDirCreated = @mkdir($npDir, 0777, true);
    }
    $npDirExists = is_dir($npDir);

    // ── Validate the images staged by the chosen WhatsApp source ──────────────
    $whatsappImagesDir = npWaImagesDir($waSource);
    $waPattern = '/^\d{6}-\d{6} (\d+) (.+)\.jpe?g$/i';
    $waFiles   = glob($whatsappImagesDir . '/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE) ?: [];

    $waItems         = [];   // valid: ['fname','serial','caption']
    $waInvalidNames  = [];   // filenames that don't match the convention
    $seenSerials     = [];
    $waDuplicates    = [];

    foreach ($waFiles as $fullPath) {
        $fname = basename($fullPath);
        if (preg_match($waPattern, $fname, $m) && ctype_digit($m[1])) {
            $serial = (int)$m[1];
            if (isset($seenSerials[$serial])) {
                $waDuplicates[] = $serial;
            }
            $seenSerials[$serial] = true;
            $waItems[] = ['fname' => $fname, 'serial' => $serial, 'caption' => $m[2]];
        } else {
            $waInvalidNames[] = $fname;
        }
    }
    $waDuplicates = array_values(array_unique($waDuplicates));

    $waCount      = count($waItems);
    $waNamingOk   = empty($waInvalidNames);
    $waSerialsOk  = empty($waDuplicates);
    $waHasImages  = $waCount > 0;

    $allOk = $npDirExists && $deptExists && $waHasImages && $waNamingOk && $waSerialsOk;

} else {

    // ── Manual mode: original pre-flight checks (unchanged) ───────────────────
    $npDirExists = is_dir($npDir);

    // Find xlsx file in NP directory
    $xlsxFiles = $npDirExists ? (glob($npDir . '/*.xlsx') ?: []) : [];
    $xlsxFile  = !empty($xlsxFiles) ? basename($xlsxFiles[0]) : null;
    $xlsxExists = $xlsxFile !== null;

    // Count JPEG files
    $jpegFiles = $npDirExists
        ? (glob($npDir . '/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE) ?: [])
        : [];
    $jpegCount = count($jpegFiles);

    // ── Read column F from xlsx ───────────────────────────────────────────────
    $fCount      = 0;
    $fAllNumeric = true;
    $fError      = null;

    if ($xlsxExists) {
        try {
            $spreadsheet = IOFactory::load($npDir . '/' . $xlsxFile);
            $sheet       = $spreadsheet->getActiveSheet();
            for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
                $val = $sheet->getCell('F' . $row)->getValue();
                if ($val === null || $val === '') break;
                $fCount++;
                if (!is_numeric($val)) $fAllNumeric = false;
            }
        } catch (Exception $e) {
            $fError = $e->getMessage();
        }
    }

    $countsMatch = ($fCount > 0 && $jpegCount === $fCount);
    $allOk       = $npDirExists && $deptExists && $xlsxExists && !$fError && $fAllNumeric && $fCount > 0 && $countsMatch;
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>NP Harmonized – אישור</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; padding: 45px; margin: 0; }
        h2 { color: #2575a8; margin-bottom: 36px; font-size: 36px; }
        .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,.12); padding: 42px 54px; max-width: 1350px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 42px; }
        th { background: #2575a8; color: #fff; padding: 15px 21px; font-size: 20px; text-align: right; }
        td { padding: 15px 21px; border-bottom: 1px solid #eee; font-size: 20px; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        td.lbl { font-weight: bold; color: #555; white-space: nowrap; width: 345px; }
        td.val { font-family: monospace; font-size: 18px; color: #333; word-break: break-all; }
        td.ico { width: 60px; text-align: center; }
        .note { font-size: 17px; color: #888; margin-top: 5px; }
        .warn { color: #c0392b; }
        .good { color: #2a7d2a; }
        .summary { padding: 21px 27px; border-radius: 8px; margin-bottom: 33px; font-weight: bold; font-size: 21px; }
        .summary.ok  { background: #e8f5e9; border: 1px solid #a5d6a7; color: #2a7d2a; }
        .summary.err { background: #fdecea; border: 1px solid #f5c6c6; color: #c0392b; }
        .actions { display: flex; align-items: center; gap: 21px; margin-top: 12px; }
        .btn-back { padding: 17px 42px; background: #888; color: #fff; border: none; border-radius: 8px; font-size: 22px; cursor: pointer; text-decoration: none; }
        .btn-back:hover { background: #666; }
        .btn-approve { padding: 20px 66px; background: #2a7d2a; color: #fff; border: none; border-radius: 8px; font-size: 27px; cursor: pointer; }
        .btn-approve:hover:not(:disabled) { background: #1e5c1e; }
        .btn-approve:disabled { background: #aaa; cursor: not-allowed; }
        .filelist { font-size: 16px; color: #c0392b; margin-top: 6px; }
        .filelist div { padding: 2px 0; }
    </style>
</head>
<body>
<h2>NP Harmonized – אישור מקורות</h2>
<div class="card">

<!-- ── Process parameters ─────────────────────────────────────────────────── -->
<table>
    <tr><th colspan="3">פרטי תהליך</th></tr>
    <tr>
        <td class="lbl">תאריך עיבוד</td>
        <td class="val"><?= htmlspecialchars("{$dd}/{$mm}/{$yyyy}") ?></td>
        <td class="ico"></td>
    </tr>
    <tr>
        <td class="lbl">חנות</td>
        <td class="val"><?= htmlspecialchars($shop) ?></td>
        <td class="ico"></td>
    </tr>
    <tr>
        <td class="lbl">כונן ראשי</td>
        <td class="val"><?= htmlspecialchars($rootLetter) ?>:\</td>
        <td class="ico"></td>
    </tr>
    <tr>
        <td class="lbl">אופן איסוף תמונות</td>
        <td class="val"><?= $harvestMode === 'whatsapp' ? htmlspecialchars(npWaSourceLabel($waSource)) : 'ידני' ?></td>
        <td class="ico"></td>
    </tr>
</table>

<?php if ($harvestMode === 'whatsapp'): ?>
<!-- ── WhatsApp mode checks ──────────────────────────────────────────────── -->
<table>
    <tr><th colspan="3">בדיקת קבצים ותיקיות – WhatsApp</th></tr>

    <tr>
        <td class="lbl">NP_working_directory</td>
        <td class="val">
            <?= htmlspecialchars($npDirDisplay) ?>
            <?php if (!$npDirExisted && $npDirCreated): ?>
                <div class="note good">✔ התיקייה נוצרה כעת</div>
            <?php elseif (!$npDirExisted && !$npDirCreated): ?>
                <div class="note warn">⚠️ לא ניתן היה ליצור את התיקייה</div>
            <?php endif; ?>
        </td>
        <td class="ico"><?= ok($npDirExists) ?></td>
    </tr>

    <tr>
        <td class="lbl">Departments_config_file</td>
        <td class="val"><?= htmlspecialchars($deptFileDisplay) ?></td>
        <td class="ico"><?= ok($deptExists) ?></td>
    </tr>

    <tr>
        <td class="lbl">תמונות בתיקיית WhatsApp</td>
        <td class="val">
            <div style="direction:ltr;text-align:right;font-size:14px;color:#777"><?= htmlspecialchars(str_replace('/', '\\', $whatsappImagesDir)) ?></div>
            <strong><?= $waCount ?></strong> תמונות תקינות
            <?php if (!$waNamingOk): ?>
                <div class="note warn">⚠️ שמות קבצים לא תואמים למוסכמה "ddmmyy-hhmmss n y":</div>
                <div class="filelist"><?php foreach ($waInvalidNames as $bad): ?><div><?= htmlspecialchars($bad) ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <?php if (!$waSerialsOk): ?>
                <div class="note warn">⚠️ מספרים סידוריים כפולים: <?= htmlspecialchars(implode(', ', $waDuplicates)) ?></div>
            <?php endif; ?>
            <?php if ($waNamingOk && $waSerialsOk && $waHasImages): ?>
                <div class="note good">✔ כל השמות תקינים ומספרים סידוריים ייחודיים</div>
            <?php endif; ?>
            <?php if (!$waHasImages): ?>
                <div class="note warn">⚠️ לא נמצאו תמונות בתיקיית <?= htmlspecialchars($whatsappImagesDir) ?></div>
            <?php endif; ?>
        </td>
        <td class="ico"><?= ok($waHasImages && $waNamingOk && $waSerialsOk) ?></td>
    </tr>
</table>
<?php else: ?>
<!-- ── Manual mode checks (unchanged) ───────────────────────────────────────── -->
<table>
    <tr><th colspan="3">בדיקת קבצים ותיקיות</th></tr>

    <tr>
        <td class="lbl">NP_working_directory</td>
        <td class="val"><?= htmlspecialchars($npDirDisplay) ?></td>
        <td class="ico"><?= ok($npDirExists) ?></td>
    </tr>

    <tr>
        <td class="lbl">Departments_config_file</td>
        <td class="val"><?= htmlspecialchars($deptFileDisplay) ?></td>
        <td class="ico"><?= ok($deptExists) ?></td>
    </tr>

    <tr>
        <td class="lbl">New_products_list file</td>
        <td class="val">
            <?php if ($xlsxFile): ?>
                <?= htmlspecialchars($xlsxFile) ?>
            <?php elseif ($npDirExists): ?>
                <span class="warn">לא נמצא קובץ xlsx בתיקייה</span>
            <?php else: ?>
                <span style="color:#aaa">— (תיקייה לא קיימת)</span>
            <?php endif; ?>
        </td>
        <td class="ico"><?= ok($xlsxExists) ?></td>
    </tr>

    <?php if ($fError): ?>
    <tr>
        <td class="lbl">שגיאה בטעינת Excel</td>
        <td class="val warn"><?= htmlspecialchars($fError) ?></td>
        <td class="ico"><?= ok(false) ?></td>
    </tr>
    <?php endif; ?>

    <?php if ($xlsxExists && !$fError): ?>
    <tr>
        <td class="lbl">מחירים בעמודה F (מ-F2)</td>
        <td class="val">
            <strong><?= $fCount ?></strong> שורות מלאות
            <?php if (!$fAllNumeric): ?>
                <div class="note warn">⚠️ לא כל הערכים הם מספרים</div>
            <?php else: ?>
                <div class="note good">✔ כל הערכים מספריים</div>
            <?php endif; ?>
        </td>
        <td class="ico"><?= ok($fAllNumeric && $fCount > 0) ?></td>
    </tr>
    <?php endif; ?>

    <tr>
        <td class="lbl">קבצי JPEG בתיקייה</td>
        <td class="val">
            <?php if ($npDirExists): ?>
                <strong><?= $jpegCount ?></strong> קבצים
            <?php else: ?>
                <span style="color:#aaa">— (תיקייה לא קיימת)</span>
            <?php endif; ?>
        </td>
        <td class="ico"></td>
    </tr>

    <?php if ($xlsxExists && !$fError && $npDirExists): ?>
    <tr>
        <td class="lbl">התאמת מספרים</td>
        <td class="val">
            <?php if ($countsMatch): ?>
                <span class="good">✔ <?= $jpegCount ?> תמונות = <?= $fCount ?> מחירים</span>
            <?php else: ?>
                <span class="warn">⚠️ <?= $jpegCount ?> תמונות ≠ <?= $fCount ?> מחירים</span>
            <?php endif; ?>
        </td>
        <td class="ico"><?= ok($countsMatch) ?></td>
    </tr>
    <?php endif; ?>
</table>
<?php endif; ?>

<!-- ── Summary & approve ──────────────────────────────────────────────────── -->
<div class="summary <?= $allOk ? 'ok' : 'err' ?>">
    <?= $allOk ? '✅ כל הבדיקות עברו בהצלחה – ניתן לאשר' : '❌ יש שגיאות – יש לתקן לפני האישור' ?>
</div>

<div class="actions">
    <a class="btn-back" href="index.php">חזור</a>
    <?php if ($harvestMode === 'whatsapp'): ?>
    <form id="advanceForm" action="process_whatsapp.php" method="post" style="display:inline">
        <input type="hidden" name="date"         value="<?= htmlspecialchars($date) ?>">
        <input type="hidden" name="shop"         value="<?= htmlspecialchars($shop) ?>">
        <input type="hidden" name="root_letter"  value="<?= htmlspecialchars($rootLetter) ?>">
        <input type="hidden" name="np_dir"       value="<?= htmlspecialchars($npDir) ?>">
        <input type="hidden" name="dept_file"    value="<?= htmlspecialchars($deptFile) ?>">
        <input type="hidden" name="harvest_mode" value="whatsapp">
        <input type="hidden" name="wa_source"    value="<?= htmlspecialchars($waSource) ?>">
        <input type="hidden" name="mode"         value="<?= htmlspecialchars($mode) ?>">
        <button class="btn-approve" <?= $allOk ? '' : 'disabled' ?>>אישור</button>
    </form>
    <?php else: ?>
    <form id="advanceForm" action="process.php" method="post" style="display:inline">
        <input type="hidden" name="date"         value="<?= htmlspecialchars($date) ?>">
        <input type="hidden" name="shop"         value="<?= htmlspecialchars($shop) ?>">
        <input type="hidden" name="root_letter"  value="<?= htmlspecialchars($rootLetter) ?>">
        <input type="hidden" name="np_dir"       value="<?= htmlspecialchars($npDir) ?>">
        <input type="hidden" name="xlsx_file"    value="<?= htmlspecialchars($xlsxFile ?? '') ?>">
        <input type="hidden" name="dept_file"    value="<?= htmlspecialchars($deptFile) ?>">
        <input type="hidden" name="harvest_mode" value="manual">
        <input type="hidden" name="mode"         value="<?= htmlspecialchars($mode) ?>">
        <button class="btn-approve" <?= $allOk ? '' : 'disabled' ?>>אישור</button>
    </form>
    <?php endif; ?>
</div>
<?php if ($allOk) renderFlowAutoAdvance($mode, 'advanceForm'); ?>

</div>
</body>
</html>

<?php
// Stage 3 – Department Classification (review & edit — no save yet)
set_time_limit(0);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/barcode_validate.php';
require_once __DIR__ . '/xlsx_retry.php';
require_once __DIR__ . '/flow_mode.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['np_dir'])) {
    header('Location: index.php'); exit;
}

$npDir    = $_POST['np_dir'];
$xlsxFile = $_POST['xlsx_file'];   // _BR_CHPF.xlsx
$shop     = $_POST['shop']      ?? '';
$date     = $_POST['date']      ?? '';
$deptFile = $_POST['dept_file'] ?? '';
$mode     = npFlowMode();

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
$deptNames  = array_keys($deptLookup);
$numToName  = array_flip($deptLookup); // number => name (for editable dept-number sync)

// ── Load xlsx and read product names from col B ───────────────────────────────
try {
    $spreadsheet = loadSpreadsheetWithRetry($npDir . '/' . $xlsxFile);
} catch (Exception $e) {
    die('<p style="color:red;font-family:Arial;font-size:22px;padding:30px">שגיאה בטעינת Excel: ' . htmlspecialchars($e->getMessage()) . '</p>');
}

$sheet    = $spreadsheet->getActiveSheet();
$products = [];
for ($row = 2; $row <= $sheet->getHighestRow(); $row++) {
    $val = $sheet->getCell('B' . $row)->getValue();
    if ($val === null || trim((string)$val) === '') break;
    $products[] = ['row' => $row, 'name' => trim((string)$val)];
}

if (empty($products)) {
    die('<p style="color:red;font-family:Arial;font-size:22px;padding:30px">שגיאה: לא נמצאו שמות מוצרים בעמודה B.</p>');
}

// ── Read price comparison data (col D=max, E=min, F=sale) ────────────────────
$priceChecks = []; // row => [...]
foreach ($products as $p) {
    $row      = $p['row'];
    $barcode  = trim((string)$sheet->getCell('A' . $row)->getValue());
    $maxP     = trim((string)$sheet->getCell('D' . $row)->getValue());
    $minP     = trim((string)$sheet->getCell('E' . $row)->getValue());
    $saleP    = (float)$sheet->getCell('F' . $row)->getValue();
    if ($maxP === '' || $maxP === 'NA' || $minP === '' || $minP === 'NA') {
        $priceChecks[$row] = ['barcode' => $barcode, 'sale' => $saleP, 'status' => 'na'];
    } else {
        $max  = (float)str_replace(',', '', $maxP);
        $min  = (float)str_replace(',', '', $minP);
        if ($saleP > $max)     $priceChecks[$row] = ['barcode' => $barcode, 'sale' => $saleP, 'max' => $max, 'min' => $min, 'status' => 'above'];
        elseif ($saleP < $min) $priceChecks[$row] = ['barcode' => $barcode, 'sale' => $saleP, 'max' => $max, 'min' => $min, 'status' => 'below'];
        else                   $priceChecks[$row] = ['barcode' => $barcode, 'sale' => $saleP, 'max' => $max, 'min' => $min, 'status' => 'ok'];
    }
}
$pcNa       = count(array_filter($priceChecks, fn($c) => $c['status'] === 'na'));
$pcOk       = count(array_filter($priceChecks, fn($c) => $c['status'] === 'ok'));
$pcWarn     = array_filter($priceChecks, fn($c) => in_array($c['status'], ['above', 'below']));
$pcCompared = $pcOk + count($pcWarn);

$statusLabel = [
    'ok'    => 'בטווח',
    'above' => 'מעל המקסימום',
    'below' => 'מתחת למינימום',
    'na'    => 'אין נתוני השוואה',
];
$statusClass = ['ok' => 'st-ok', 'above' => 'st-bad', 'below' => 'st-bad', 'na' => 'st-na'];

// ── Load OpenAI key ───────────────────────────────────────────────────────────
$config = json_decode(file_get_contents(__DIR__ . '/../AIocr/config.json'), true);
$apiKey = $config['openai_api_key'] ?? null;
if (empty($apiKey)) die('Error: OpenAI API key not configured.');

// ── Build prompt ──────────────────────────────────────────────────────────────
$productList = implode("\n", array_map(fn($i, $p) => ($i + 1) . '. ' . $p['name'], array_keys($products), $products));
$deptList    = implode("\n", array_map(fn($i, $n) => ($i + 1) . '. ' . $n,          array_keys($deptNames), $deptNames));

$systemPrompt = <<<PROMPT
You are a supermarket product classification assistant.
You will receive a numbered list of products and a numbered list of supermarket departments.
Classify each product into the single most appropriate department.

Rules:
- The "department" field MUST exactly match one of the provided department names (copy verbatim).
- Maintain the same order as the input product list.
- For "note", write a very short reason (one sentence max).

Return ONLY valid JSON:
{"classifications": [{"index": 1, "product": "...", "department": "...", "note": "..."}, ...]}
PROMPT;

// ── Call OpenAI ───────────────────────────────────────────────────────────────
$payload = [
    'model'           => 'gpt-4.1-mini',
    'messages'        => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => "Departments:\n{$deptList}\n\nProducts to classify:\n{$productList}"],
    ],
    'max_tokens'      => 4000,
    'temperature'     => 0.0,
    'response_format' => ['type' => 'json_object'],
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $httpCode !== 200) {
    $err = json_decode($response, true);
    die('<p style="color:red;font-family:Arial;font-size:22px;padding:30px">שגיאת OpenAI: ' . htmlspecialchars($err['error']['message'] ?? 'HTTP ' . $httpCode) . '</p>');
}

$result  = json_decode($response, true);
$content = $result['choices'][0]['message']['content'] ?? null;
$aiData  = json_decode($content, true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($aiData['classifications'])) {
    die('<p style="color:red;font-family:Arial;font-size:22px;padding:30px">שגיאה בניתוח תשובת OpenAI.</p>');
}

$classifications = $aiData['classifications'];
$usage           = $result['usage'] ?? [];

// ── Classification lookup by row (nothing is written to the file yet) ─────────
$clByRow = [];
foreach ($classifications as $cl) {
    $idx = (int)($cl['index'] ?? 0) - 1;
    if (!isset($products[$idx])) continue;
    $row = $products[$idx]['row'];
    $clByRow[$row] = [
        'department' => $cl['department'] ?? '',
        'note'       => $cl['note'] ?? '',
    ];
}

// ── Unified per-row data for the editable review screen ──────────────────────
$finalRows = [];
foreach ($products as $p) {
    $row        = $p['row'];
    $barcode    = $priceChecks[$row]['barcode'] ?? trim((string)$sheet->getCell('A' . $row)->getValue());
    $price      = $priceChecks[$row]['sale']    ?? (float)$sheet->getCell('F' . $row)->getValue();
    $status     = $priceChecks[$row]['status']  ?? 'na';
    $deptName   = $clByRow[$row]['department']  ?? '';
    $deptNum    = $deptLookup[$deptName]        ?? '';
    $note       = $clByRow[$row]['note']        ?? '';
    $checksumOk = validateBarcode($barcode);
    $fname      = trim((string)$sheet->getCell('K' . $row)->getValue());

    // "Failed to pass" (FTP): checksum failed, OR CHPF returned no data (na),
    // OR the sale price is outside the CHP min/max range.
    $isFtp = !$checksumOk || $status !== 'ok';

    $finalRows[] = [
        'row'        => $row,
        'barcode'    => $barcode,
        'name'       => $p['name'],
        'price'      => $price,
        'status'     => $status,
        'deptNum'    => $deptNum,
        'deptName'   => $deptName,
        'note'       => $note,
        'checksumOk' => $checksumOk,
        'fname'      => $fname,
        'isFtp'      => $isFtp,
    ];
}
$ftpRows = array_values(array_filter($finalRows, fn($r) => $r['isFtp']));
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>NP Harmonized – שלב 3: סיווג מחלקות – אימות סופי</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 45px; }
        h2   { color: #2575a8; font-size: 36px; margin-bottom: 9px; }
        .sub { color: #777; font-size: 20px; margin-bottom: 36px; }
        .meta { display: flex; gap: 30px; flex-wrap: wrap; margin-bottom: 33px; }
        .meta-box { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 14px 24px; font-size: 20px; color: #555; }
        .meta-box strong { color: #333; }
        .layout { display: flex; gap: 30px; align-items: flex-start; }
        .main-col { flex: 1; min-width: 0; }
        .side-col { width: 300px; flex-shrink: 0; position: sticky; top: 20px; }
        table { border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,.1); width: 100%; margin-bottom: 36px; table-layout: fixed; }
        th { background: #2575a8; color: #fff; padding: 14px 18px; font-size: 19px; text-align: right; }
        td { padding: 10px 14px; border-bottom: 1px solid #eee; font-size: 19px; color: #333; vertical-align: middle; overflow-wrap: break-word; }
        tr:last-child td { border-bottom: none; }
        .col-barcode { width: calc(13ch + 30px); }
        .col-name    { width: calc(13ch * 2.5); }
        .col-deptnum { width: calc(3ch + 40px); }
        .col-note    { width: 12ch; }
        .note-cell { color: #777; font-style: italic; font-size: 16px; white-space: normal; overflow-wrap: break-word; }
        input.edit { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #bbb; border-radius: 6px; font-size: 18px; }
        input.edit:focus { border-color: #2575a8; outline: none; }
        .price-cell { font-weight: bold; color: #2575a8; white-space: nowrap; }
        .st-ok  { color: #2a7d2a; font-weight: bold; }
        .st-bad { color: #c0392b; font-weight: bold; }
        .st-na  { color: #999; }
        .dept-name-cell.warn { color: #c0392b; }
        .checksum-cell { text-align: center; font-size: 24px; font-weight: bold; }
        .checksum-cell.cs-ok  { color: #2a7d2a; }
        .checksum-cell.cs-bad { color: #c0392b; }
        .dept-list-box { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 18px 22px; box-shadow: 0 1px 6px rgba(0,0,0,.1); max-height: 80vh; overflow-y: auto; }
        .dept-list-box h3 { margin: 0 0 14px; color: #555; font-size: 22px; }
        .dept-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f0f0f0; font-size: 17px; }
        .dept-row:last-child { border-bottom: none; }
        .dept-row .num { font-weight: bold; color: #2575a8; margin-left: 12px; }
        .price-stats { font-size: 20px; color: #555; margin-bottom: 16px; }
        .price-stats span { margin-left: 28px; }
        .ok-box  { background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 8px; padding: 21px 28px; margin-bottom: 36px; font-size: 21px; color: #2a7d2a; font-weight: bold; }
        .warn-box { background: #fdecea; border: 2px solid #e57373; border-radius: 8px; padding: 21px 28px; margin-bottom: 36px; }
        .warn-box h4 { color: #c0392b; font-size: 24px; margin: 0 0 14px; }
        .warn-row { font-size: 20px; color: #c0392b; margin: 6px 0; }
        .btn-approve { padding: 20px 66px; background: #2a7d2a; color: #fff; border: none; border-radius: 8px; font-size: 27px; cursor: pointer; margin-top: 12px; }
        .btn-approve:hover { background: #1e5c1e; }
        .ftp-table { border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,.1); width: 100%; max-width: 1100px; margin-bottom: 36px; table-layout: auto; }
        .ftp-table th { background: #c0392b; color: #fff; padding: 14px 18px; font-size: 19px; text-align: right; }
        .ftp-table td { padding: 14px 18px; border-bottom: 1px solid #eee; font-size: 19px; color: #333; vertical-align: top; }
        .ftp-table tr:last-child td { border-bottom: none; }
        .ftp-reason { color: #c0392b; font-weight: bold; }
        .inline-img { width: 480px; display: block; border-radius: 6px; border: 1px solid #ddd; transition: width .2s; }
        .zoom-btns { margin-top: 8px; display: flex; gap: 8px; }
        .zoom-btns button { padding: 6px 18px; font-size: 22px; font-weight: bold; border: 1px solid #bbb; border-radius: 6px; background: #f0f0f0; cursor: pointer; line-height: 1; }
        .zoom-btns button:hover { background: #ddd; }
    </style>
</head>
<body>
<h2>NP Harmonized – שלב 3: סיווג מחלקות – אימות סופי</h2>
<p class="sub">חנות: <?= htmlspecialchars($shop) ?> | תאריך: <?= htmlspecialchars($date) ?></p>
<?php renderFlowStopNotice($mode); ?>

<div class="meta">
    <div class="meta-box"><strong>חנות:</strong> <?= htmlspecialchars($shop) ?></div>
    <div class="meta-box"><strong>מוצרים:</strong> <?= count($products) ?></div>
    <div class="meta-box"><strong>מחלקות:</strong> <?= count($deptNames) ?></div>
    <?php if (!empty($usage)): ?>
    <div class="meta-box"><strong>Tokens:</strong> <?= ($usage['total_tokens'] ?? '?') ?> (prompt: <?= ($usage['prompt_tokens'] ?? '?') ?>, completion: <?= ($usage['completion_tokens'] ?? '?') ?>)</div>
    <?php endif; ?>
</div>

<div class="layout">
<div class="main-col">

<form action="stage_dcl_save.php" method="post">
    <input type="hidden" name="np_dir"    value="<?= htmlspecialchars($npDir) ?>">
    <input type="hidden" name="xlsx_file" value="<?= htmlspecialchars($xlsxFile) ?>">
    <input type="hidden" name="shop"      value="<?= htmlspecialchars($shop) ?>">
    <input type="hidden" name="date"      value="<?= htmlspecialchars($date) ?>">
    <input type="hidden" name="dept_file" value="<?= htmlspecialchars($deptFile) ?>">
    <input type="hidden" name="mode"      value="<?= htmlspecialchars($mode) ?>">

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
            <col class="col-note">
        </colgroup>
        <tr>
            <th>#</th>
            <th>ברקוד</th>
            <th>ביקורת ספרה</th>
            <th>שם מוצר</th>
            <th>מחיר מכירה</th>
            <th>מחיר מול CPD</th>
            <th>מספר מחלקה</th>
            <th>שם מחלקה</th>
            <th>הערת AI</th>
        </tr>
        <?php foreach ($finalRows as $i => $r): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td>
                <input class="edit" type="text" name="barcode[<?= $r['row'] ?>]" value="<?= htmlspecialchars($r['barcode']) ?>"
                       data-row="<?= $r['row'] ?>" oninput="syncChecksum(this)">
            </td>
            <td class="checksum-cell <?= $r['checksumOk'] ? 'cs-ok' : 'cs-bad' ?>" id="checksum-<?= $r['row'] ?>">
                <?= $r['checksumOk'] ? '✔' : '✘' ?>
            </td>
            <td><input class="edit" type="text" name="name[<?= $r['row'] ?>]" value="<?= htmlspecialchars($r['name']) ?>"></td>
            <td class="price-cell"><?= number_format($r['price'], 2) ?></td>
            <td class="<?= $statusClass[$r['status']] ?>"><?= $statusLabel[$r['status']] ?></td>
            <td>
                <input class="edit" type="text" name="deptnum[<?= $r['row'] ?>]" value="<?= htmlspecialchars((string)$r['deptNum']) ?>"
                       data-row="<?= $r['row'] ?>" oninput="syncDeptName(this)">
            </td>
            <td class="dept-name-cell" id="deptname-<?= $r['row'] ?>"><?= htmlspecialchars($r['deptName'] ?: '—') ?></td>
            <td class="note-cell"><?= htmlspecialchars($r['note']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <button type="submit" class="btn-approve">אישור – שמור וסיים תהליך ←</button>
</form>

<!-- ── Price range summary ───────────────────────────────────────────────────── -->
<h3 style="color:#555;font-size:27px;margin-bottom:18px">סיכום מחירים מול CPD</h3>
<div class="price-stats">
    <span>סה״כ שורות: <?= count($priceChecks) ?></span>
    <span>הושוו: <?= $pcCompared ?></span>
    <?php if ($pcNa > 0): ?>
    <span>ללא מחירי CPD (NA): <?= $pcNa ?></span>
    <?php endif; ?>
</div>

<?php if (empty($pcWarn) && $pcCompared > 0): ?>
<div class="ok-box">✅ כל המחירים בטווח</div>
<?php elseif (!empty($pcWarn)):
    $warnLines = [];
    foreach ($pcWarn as $row => $w) {
        $rowName = null;
        foreach ($finalRows as $fr) { if ($fr['row'] === $row) { $rowName = $fr['name']; break; } }
        if ($w['status'] === 'above')
            $warnLines[] = "{$rowName} ({$w['barcode']}) — מחיר מכירה " . number_format($w['sale'],2) . " גבוה ממקסימום " . number_format($w['max'],2) . " ב CPD";
        else
            $warnLines[] = "{$rowName} ({$w['barcode']}) — מחיר מכירה " . number_format($w['sale'],2) . " נמוך ממינימום " . number_format($w['min'],2) . " ב CPD";
    }
?>
<div class="warn-box">
    <h4>⚠ שים לב ל <?= count($pcWarn) ?> מוצרים עם מחירים מחוץ לטווח CPD</h4>
    <?php foreach ($warnLines as $line): ?>
    <div class="warn-row"><?= htmlspecialchars($line) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div>

<div class="side-col">
    <div class="dept-list-box">
        <h3>רשימת מחלקות – <?= htmlspecialchars($shop) ?></h3>
        <?php foreach ($deptLookup as $name => $num): ?>
        <div class="dept-row"><span class="num"><?= htmlspecialchars((string)$num) ?></span><span><?= htmlspecialchars($name) ?></span></div>
        <?php endforeach; ?>
    </div>
</div>

</div>

<?php if (!empty($ftpRows)):
    function ftpReasons(array $r): array {
        $reasons = [];
        if (!$r['checksumOk'])       $reasons[] = 'ביקורת ספרה נכשלה';
        if ($r['status'] === 'na')   $reasons[] = 'לא נמצאו נתוני CPD';
        if ($r['status'] === 'above') $reasons[] = 'מחיר גבוה ממקסימום CPD';
        if ($r['status'] === 'below') $reasons[] = 'מחיר נמוך ממינימום CPD';
        return $reasons;
    }
?>
<!-- ── FTP (Failed To Pass) — re-inspect the source photo ─────────────────────── -->
<h3 style="color:#c0392b;font-size:27px;margin:36px 0 9px">⚠ ברקודים שלא עברו את הבדיקה הסופית (<?= count($ftpRows) ?>)</h3>
<p class="sub" style="margin-bottom:18px">ביקורת ספרה נכשלה, ו/או לא נמצאו נתוני CPD, ו/או המחיר מחוץ לטווח CPD. ניתן לקרוא שוב את הברקוד מהתמונה ולתקן בטבלה למעלה.</p>
<table class="ftp-table">
    <tr>
        <th>#</th>
        <th>ברקוד שנקרא</th>
        <th>סיבה</th>
        <th>תמונה</th>
    </tr>
    <?php foreach ($ftpRows as $i => $r): ?>
    <tr>
        <td><?= $i + 1 ?></td>
        <td class="price-cell"><?= htmlspecialchars($r['barcode'] ?: '—') ?></td>
        <td class="ftp-reason"><?= htmlspecialchars(implode(' · ', ftpReasons($r))) ?></td>
        <td>
            <?php if ($r['fname']): ?>
            <img class="inline-img" id="ftpimg<?= $r['row'] ?>"
                 src="serve_image.php?np_dir=<?= urlencode($npDir) ?>&fname=<?= urlencode($r['fname']) ?>"
                 alt="<?= htmlspecialchars($r['fname']) ?>">
            <div class="zoom-btns">
                <button type="button" onclick="zoom('ftpimg<?= $r['row'] ?>',1.3)">＋</button>
                <button type="button" onclick="zoom('ftpimg<?= $r['row'] ?>',0.77)">－</button>
            </div>
            <?php else: ?>
            <span class="note-cell">תמונת מקור לא נמצאה</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<script>
function zoom(id, f) {
    var img = document.getElementById(id);
    img.style.width = Math.round(img.offsetWidth * f) + 'px';
}

const NUM_TO_NAME = <?= json_encode($numToName, JSON_UNESCAPED_UNICODE) ?>;

function syncDeptName(input) {
    var row  = input.dataset.row;
    var num  = input.value.trim();
    var cell = document.getElementById('deptname-' + row);
    cell.classList.remove('warn');
    if (num === '') {
        cell.textContent = '—';
    } else if (NUM_TO_NAME.hasOwnProperty(num)) {
        cell.textContent = NUM_TO_NAME[num];
    } else {
        cell.textContent = '⚠ מחלקה לא מוכרת';
        cell.classList.add('warn');
    }
}

// Mirrors PHP's validateBarcode() in barcode_validate.php — EAN-13/UPC-A/EAN-8
// mod-10 checksum. Keeps the checksum column in sync as the barcode is edited.
function validateBarcodeChecksum(code) {
    if (!/^\d+$/.test(code)) return false;
    var len = code.length;
    if (len !== 8 && len !== 12 && len !== 13) return false;
    var digits = code.split('').map(Number);
    var checkDigit = digits.pop();
    var sum = 0;
    digits.forEach(function(d, i) {
        var pos = i + 1;
        if (len === 13) {
            sum += (pos % 2 === 1) ? d * 1 : d * 3;
        } else {
            sum += (pos % 2 === 1) ? d * 3 : d * 1;
        }
    });
    var calculated = (10 - (sum % 10)) % 10;
    return calculated === checkDigit;
}

function syncChecksum(input) {
    var cell = document.getElementById('checksum-' + input.dataset.row);
    if (!cell) return;
    var ok = validateBarcodeChecksum(input.value.trim());
    cell.textContent = ok ? '✔' : '✘';
    cell.classList.toggle('cs-ok', ok);
    cell.classList.toggle('cs-bad', !ok);
}
</script>
</body>
</html>

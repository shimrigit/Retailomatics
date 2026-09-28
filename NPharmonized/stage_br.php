<?php
// Stage 1 – Barcode Reading
set_time_limit(0);
session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/barcode_validate.php';
require_once __DIR__ . '/flow_mode.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['np_dir'])) {
    header('Location: index.php'); exit;
}

$npDir       = $_POST['np_dir'];
$xlsxFile    = $_POST['xlsx_file'];
$shop        = $_POST['shop']      ?? '';
$date        = $_POST['date']      ?? '';
$deptFile    = $_POST['dept_file'] ?? '';
$harvestMode = ($_POST['harvest_mode'] ?? 'manual') === 'whatsapp' ? 'whatsapp' : 'manual';
$mode        = npFlowMode();

// ── OpenAI key ────────────────────────────────────────────────────────────────
$config = json_decode(file_get_contents(__DIR__ . '/../AIocr/config.json'), true);
$apiKey = $config['openai_api_key'] ?? null;
if (empty($apiKey)) die('Error: OpenAI API key not configured.');

// ── Collect JPEGs and order them ─────────────────────────────────────────────
// Manual:   "yyyy-mm-dd at hh.mm.ss X.jpeg" — sorted by the embedded date/time
// WhatsApp: "ddmmyy-hhmmss n y.jpeg"         — sorted by serial number n,
//           NEVER by the embedded timestamp (arrival order is the source of
//           truth; two images can share the same second).
function collectJpegItems(string $npDir, string $harvestMode): array {
    $allFiles  = glob($npDir . '/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE) ?: [];
    $jpegItems = [];

    if ($harvestMode === 'whatsapp') {
        $pattern = '/^\d{6}-\d{6} (\d+) (.+)\.jpe?g$/i';
        foreach ($allFiles as $fullPath) {
            $fname = basename($fullPath);
            if (preg_match($pattern, $fname, $m) && ctype_digit($m[1])) {
                $jpegItems[] = [
                    'fullPath'     => $fullPath,
                    'fname'        => $fname,
                    'serial'       => (int)$m[1],
                    'dateTimePart' => $fname,
                    'price'        => $m[2],
                ];
            }
        }
        usort($jpegItems, fn($a, $b) => $a['serial'] <=> $b['serial']);
    } else {
        $pattern = '/^(\d{4}-\d{2}-\d{2} at \d{2}\.\d{2}\.\d{2}) ([\d.]+)\.jpe?g$/i';
        foreach ($allFiles as $fullPath) {
            $fname = basename($fullPath);
            if (preg_match($pattern, $fname, $m)) {
                preg_match('/^(\d{4})-(\d{2})-(\d{2}) at (\d{2})\.(\d{2})\.(\d{2})/', $m[1], $dt);
                $sortKey = $dt[1].$dt[2].$dt[3].$dt[4].$dt[5].$dt[6];
                $jpegItems[] = [
                    'fullPath'     => $fullPath,
                    'fname'        => $fname,
                    'sortKey'      => $sortKey,
                    'dateTimePart' => $m[1],
                    'price'        => $m[2],
                ];
            }
        }
        usort($jpegItems, fn($a, $b) => strcmp($a['sortKey'], $b['sortKey']));
    }
    return $jpegItems;
}

// The NP directory is on the Z:\ cloud-synced network drive, where Windows
// caches directory listings for several seconds (SMB directory cache, ~10s
// by default). Images the previous stage just copied in can therefore be
// invisible to glob() for a few seconds — the page then showed "no JPEG
// files found" even though they were there (going back and continuing
// worked). So re-scan once a second, up to 20s, until the expected number of
// images is visible: process_whatsapp.php passes how many it put there
// (expected_images); without that (Manual mode), wait until at least one
// appears. No delay at all when the files are already visible.
$expectedImages = max(0, (int)($_POST['expected_images'] ?? 0));
$jpegItems      = collectJpegItems($npDir, $harvestMode);
for ($waited = 0; $waited < 20 && count($jpegItems) < max(1, $expectedImages); $waited++) {
    sleep(1);
    clearstatcache();
    $jpegItems = collectJpegItems($npDir, $harvestMode);
}

// ── GPT barcode call ──────────────────────────────────────────────────────────
function askBarcode($imagePath, $apiKey) {
    $payload = [
        'model'    => 'gpt-4.1-mini',
        'messages' => [
            ['role' => 'system', 'content' => 'You are a barcode reader. Find the barcode number on the product image (EAN-13, EAN-8, UPC-A, or any numeric barcode). Return ONLY valid JSON: {"barcode": "<digits>"}. If none visible: {"barcode": "NOT_FOUND"}.'],
            ['role' => 'user',   'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . base64_encode(file_get_contents($imagePath))]]]],
        ],
        'max_tokens' => 100, 'temperature' => 0.0,
        'response_format' => ['type' => 'json_object'],
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $raw = curl_exec($ch); curl_close($ch);
    if (!$raw) return 'ERROR';
    $res = json_decode($raw, true);
    $con = json_decode($res['choices'][0]['message']['content'] ?? '{}', true);
    return $con['barcode'] ?? 'NOT_FOUND';
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
    <title>NP Harmonized – שלב 1: קריאת ברקודים</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 45px; }
        h2   { color: #2575a8; font-size: 36px; margin-bottom: 9px; }
        .sub { color: #777; font-size: 20px; margin-bottom: 36px; }
        .progress-box { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 24px 32px; max-width: 1100px; margin-bottom: 36px; }
        .pl      { font-size: 20px; color: #555; margin: 6px 0; }
        .pl.wait { color: #999; font-style: italic; }
        .pl.done { color: #2a7d2a; }
        .pl.err  { color: #c0392b; }
        h3 { color: #555; font-size: 27px; margin-bottom: 18px; }
        table.vtbl { border-collapse: collapse; width: 100%; max-width: 1400px; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,.1); }
        table.vtbl th { background: #2575a8; color: #fff; padding: 14px 18px; font-size: 20px; }
        table.vtbl td { padding: 12px 18px; border-bottom: 1px solid #eee; vertical-align: middle; font-size: 20px; }
        table.vtbl tr:last-child td { border-bottom: none; }
        input.bc { width: 280px; padding: 10px 14px; border: 1px solid #bbb; border-radius: 6px; font-size: 24px; font-weight: bold; }
        .fname { font-size: 18px; color: #555; }
        .price-val { font-size: 24px; font-weight: bold; color: #2575a8; }
        .inline-img { width: 480px; display: block; border-radius: 6px; border: 1px solid #ddd; transition: width .2s; }
        .zoom-btns { margin-top: 8px; display: flex; gap: 8px; }
        .zoom-btns button { padding: 6px 18px; font-size: 22px; font-weight: bold; border: 1px solid #bbb; border-radius: 6px; background: #f0f0f0; cursor: pointer; line-height: 1; }
        .zoom-btns button:hover { background: #ddd; }
        .btn-approve { margin-top: 30px; padding: 17px 50px; background: #2a7d2a; color: #fff; border: none; border-radius: 8px; font-size: 24px; cursor: pointer; }
        .btn-approve:hover { background: #1e5c1e; }
        .bc-invalid  { border-color: #e74c3c !important; background: #fdecea !important; }
        .bc-warn     { color: #c0392b; font-size: 18px; margin-top: 4px; display: none; }
        .bc-warn.show { display: block; }
        .bc-checksum-warn { color: #e67e22; font-size: 18px; margin-top: 4px; display: none; }
        .bc-checksum-warn.show { display: block; }
        #blockMsg    { display: none; background: #fdecea; border: 2px solid #e74c3c; border-radius: 8px; padding: 16px 24px; color: #c0392b; font-size: 22px; font-weight: bold; margin-bottom: 20px; max-width: 1100px; }
    </style>
</head>
<body>
<h2>NP Harmonized – שלב 1: קריאת ברקודים</h2>
<p class="sub">חנות: <?= htmlspecialchars($shop) ?> | תאריך: <?= htmlspecialchars($date) ?> | <?= count($jpegItems) ?> תמונות</p>

<?php if (empty($jpegItems)): ?>
<p style="color:#c0392b;font-size:22px;">לא נמצאו קבצי JPEG תואמים בתיקייה. ודא שהקבצים שונו שמות בשלב הקודם.</p>
<a href="index.php" style="font-size:20px;">חזור לתחילה</a>
<?php echo '</body></html>'; exit; endif; ?>

<div class="progress-box" id="progressBox">
<?php
sf();
$results = [];
foreach ($jpegItems as $idx => $item) {
    $z   = $idx + 1;
    $fn  = htmlspecialchars($item['fname']);
    echo "<p class='pl wait' id='p{$z}'>מעבד תמונה {$z} מתוך " . count($jpegItems) . ": {$fn}...</p>";
    sf();

    $barcode = askBarcode($item['fullPath'], $apiKey);

    echo "<script>
        var el = document.getElementById('p{$z}');
        el.className = 'pl " . ($barcode === 'ERROR' || $barcode === 'NOT_FOUND' ? 'err' : 'done') . "';
        el.textContent = '✔ תמונה {$z}: {$fn}  ←  ברקוד: {$barcode}';
    </script>";
    sf();

    $results[] = [
        'fname'        => $item['fname'],
        'fullPath'     => $item['fullPath'],
        'price'        => $item['price'],
        'barcode'      => $barcode,
        'dateTimePart' => $item['dateTimePart'],
    ];
}

// Store in session for stage_br_save.php
$_SESSION['br_results']  = $results;
$_SESSION['br_npdir']    = $npDir;
$_SESSION['br_xlsx']     = $xlsxFile;
$_SESSION['br_shop']     = $shop;
$_SESSION['br_date']     = $date;
$_SESSION['br_deptfile'] = $deptFile;
?>
</div>

<!-- ── Verification table ─────────────────────────────────────────────────── -->
<h3>אמת ברקודים – ניתן לערוך לפני האישור</h3>
<form id="advanceForm" action="stage_br_save.php" method="post">
<input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
<div id="blockMsg"></div>
<table class="vtbl">
    <tr>
        <th>#</th>
        <th>ברקוד (ניתן לעריכה)</th>
        <th>מחיר</th>
        <th>שם קובץ</th>
        <th>תמונה</th>
    </tr>
    <?php $anyLong = false; foreach ($results as $i => $r): ?>
    <tr>
        <td><?= $i + 1 ?></td>
        <td>
            <?php
                $isLong     = preg_match('/^\d+$/', $r['barcode']) && strlen($r['barcode']) > 13;
                $checksumOk = validateBarcode($r['barcode']);
                if ($isLong) $anyLong = true;
            ?>
            <input class="bc<?= $isLong ? ' bc-invalid' : '' ?>" type="text"
                   name="barcode[<?= $i ?>]"
                   value="<?= htmlspecialchars($r['barcode']) ?>"
                   data-idx="<?= $i ?>"
                   oninput="validateBc(this); validateChecksum(this)">
            <div class="bc-warn<?= $isLong ? ' show' : '' ?>">⚠ <?= strlen($r['barcode']) ?> ספרות – עד 13 בלבד</div>
            <div class="bc-checksum-warn<?= $checksumOk ? '' : ' show' ?>" id="checksum-warn-<?= $i ?>">⚠ ברקוד לא תקין (אורך או ביקורת ספרה)</div>
        </td>
        <td class="price-val"><?= htmlspecialchars($r['price']) ?></td>
        <td class="fname"><?= htmlspecialchars($r['fname']) ?></td>
        <td>
            <img class="inline-img" id="img<?= $i ?>"
                 src="serve_image.php?np_dir=<?= urlencode($npDir) ?>&fname=<?= urlencode($r['fname']) ?>"
                 alt="<?= htmlspecialchars($r['fname']) ?>">
            <div class="zoom-btns">
                <button type="button" onclick="zoom('img<?= $i ?>',1.3)">＋</button>
                <button type="button" onclick="zoom('img<?= $i ?>',0.77)">－</button>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<button type="submit" class="btn-approve">אישור – שמור ועבור לשלב 2 ←</button>
</form>
<?php if (!$anyLong) renderFlowAutoAdvance($mode, 'advanceForm', 6); // extra time here — most sensitive manual-check point in the flow ?>

<script>
function zoom(id, f) {
    var img = document.getElementById(id);
    img.style.width = Math.round(img.offsetWidth * f) + 'px';
}

function validateBc(input) {
    var val  = input.value.trim();
    var warn = input.nextElementSibling;
    var bad  = /^\d+$/.test(val) && val.length > 13;
    input.classList.toggle('bc-invalid', bad);
    if (warn) {
        if (bad) {
            warn.textContent = '⚠ ' + val.length + ' ספרות – עד 13 בלבד';
            warn.classList.add('show');
        } else {
            warn.classList.remove('show');
        }
    }
    updateBlockMsg();
}

// Mirrors PHP's validateBarcode() in barcode_validate.php — EAN-13/UPC-A/EAN-8
// mod-10 checksum. Non-blocking: only toggles the advisory warning below the
// field, never disables the approve button.
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

function validateChecksum(input) {
    var warnEl = document.getElementById('checksum-warn-' + input.dataset.idx);
    if (!warnEl) return;
    var ok = validateBarcodeChecksum(input.value.trim());
    warnEl.classList.toggle('show', !ok);
}

function updateBlockMsg() {
    var bad = Array.from(document.querySelectorAll('input.bc')).filter(function(inp) {
        var v = inp.value.trim();
        return /^\d+$/.test(v) && v.length > 13;
    }).length;
    var msg = document.getElementById('blockMsg');
    var btn = document.querySelector('.btn-approve');
    if (bad > 0) {
        msg.textContent = '⚠ יש ' + bad + ' ברקוד' + (bad === 1 ? '' : 'ים') + ' עם יותר מ-13 ספרות – יש לתקן לפני המשך.';
        msg.style.display = 'block';
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.style.cursor = 'not-allowed';
    } else {
        msg.style.display = 'none';
        btn.disabled = false;
        btn.style.opacity = '';
        btn.style.cursor = '';
    }
}

document.querySelector('form').addEventListener('submit', function(e) {
    var bad = Array.from(document.querySelectorAll('input.bc')).some(function(inp) {
        var v = inp.value.trim();
        return /^\d+$/.test(v) && v.length > 13;
    });
    if (bad) { e.preventDefault(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
});

document.addEventListener('DOMContentLoaded', updateBlockMsg);
</script>
</body>
</html>

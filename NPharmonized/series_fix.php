<?php
// series_fix.php — "תן מחיר לפי סדרה" for an NP file already saved (_DCL.xlsx) whose rows still
// have the price X: the same pricing as on the final screen (series_price.js), then the file is
// saved again — priced rows get their price (column F), rows left without a price leave the file
// and go to the shop's "מוצרים שמחכים למחיר" list.
//   GET  ?shop=YBC&date=2026-11-21[&root=Z]   (the NP folder of that date)
//   POST shop, date, root, price[row], noprice[row]
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Series/store.php';
require_once __DIR__ . '/series_summary.php';
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$shop = basename((string) ($_REQUEST['shop'] ?? ''));
$date = (string) ($_REQUEST['date'] ?? '');
$root = strtoupper(substr((string) ($_REQUEST['root'] ?? 'Z'), 0, 1));
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
if (!in_array($shop, seriesShops(), true) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !preg_match('/^[A-Z]$/', $root)) {
    http_response_code(400);
    exit('חסרים חנות / תאריך (series_fix.php?shop=YBC&date=2026-11-21)');
}
// the NP folder, as confirm.php builds it: {root}:/RetailomaticsCloud/RetailomaticsArchive/{shop}/NewProducts/{YYYY}/{MM_Month}/{dd-mm-yy} NP
$npDir = "$root:/RetailomaticsCloud/RetailomaticsArchive/$shop/NewProducts/{$m[1]}/" . $m[2] . '_' . date('F', mktime(0, 0, 0, (int) $m[2], 1)) . '/' . "{$m[3]}-{$m[2]}-" . substr($m[1], 2) . ' NP';
$dcl = glob($npDir . '/*_DCL.xlsx') ?: [];
if (!$dcl) exit('לא נמצא קובץ _DCL.xlsx ב-' . $h($npDir));
$file = $dcl[0];

$ss = IOFactory::load($file);
$sheet = $ss->getActiveSheet();
$rows = [];
for ($r = 2; $r <= $sheet->getHighestRow(); $r++) {
    $bc = trim((string) $sheet->getCell('A' . $r)->getValue());
    if ($bc === '') continue;
    $f = trim((string) $sheet->getCell('F' . $r)->getValue());
    if ($f !== '' && !is_numeric($f)) $rows[$r] = ['barcode' => $bc, 'name' => trim((string) $sheet->getCell('B' . $r)->getValue())];
}

$done = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $priced = 0; $waiting = []; $pricedList = [];
    foreach ($rows as $r => $it) {
        $newName = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'][$r] ?? '')));
        if ($newName !== '' && $newName !== $it['name']) { $sheet->setCellValue('B' . $r, $newName); $it['name'] = $newName; }
        if (($_POST['noprice'][$r] ?? '') === '1') { $waiting[$r] = $it; continue; }
        $p = trim(str_replace(',', '.', (string) ($_POST['price'][$r] ?? '')));
        if ($p !== '' && is_numeric($p) && (float) $p > 0) {
            $sheet->setCellValue('F' . $r, round((float) $p, 2));
            if (trim((string) ($_POST['source'][$r] ?? '')) !== '') $sheet->setCellValue('H' . $r, NP_NOTE_SERIES);   // priced by a series
            $priced++;
            $pricedList[] = ['name' => $it['name'], 'price' => (float) $p, 'source' => (string) ($_POST['source'][$r] ?? '')];
        }
    }
    foreach (array_reverse(array_keys($waiting)) as $r) $sheet->removeRow($r);
    if ($waiting) npWriteDroppedFooter($sheet, array_values($waiting));   // below the last row, column H
    (new Xlsx($ss))->save($file);
    if ($waiting) seriesWaitingAdd($shop, array_map(fn($it) => $it + ['date' => $date, 'npDir' => $npDir, 'image' => ''], array_values($waiting)), $root);
    $done = ['priced' => $priced, 'waiting' => array_values($waiting), 'summary' => npSeriesSummary($shop, $date, $pricedList, array_values($waiting))];
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<title>מחיר לפי סדרה – <?= $h($shop) ?></title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 40px; }
    h2 { color: #2575a8; font-size: 32px; margin: 0 0 8px; }
    .sub { color: #777; font-size: 18px; margin-bottom: 24px; }
    table { border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,.1); width: 100%; max-width: 1300px; margin-bottom: 24px; }
    th { background: #2575a8; color: #fff; padding: 12px 16px; font-size: 18px; text-align: right; }
    td { padding: 10px 16px; border-bottom: 1px solid #eee; font-size: 18px; }
    .dcl-box { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; background: #fff3e0; border: 1px solid #ffb74d; border-radius: 8px; padding: 12px 20px; margin-bottom: 18px; font-size: 19px; max-width: 1260px; }
    .btn { padding: 12px 30px; background: #2a7d2a; color: #fff; border: none; border-radius: 8px; font-size: 20px; cursor: pointer; }
    .btn-dcl { padding: 10px 26px; background: #2575a8; color: #fff; border: none; border-radius: 6px; font-size: 18px; cursor: pointer; }
    .box { background: #e8f5e9; border: 1px solid #a5d6a7; border-radius: 8px; padding: 18px 24px; max-width: 1260px; margin-bottom: 20px; font-size: 19px; }
    .warn { background: #fdecea; border-color: #e57373; }
</style>
</head>
<body>
<h2>מחיר לפי סדרה – <?= $h($shop) ?> · <?= $h(date('d/m/Y', strtotime($date))) ?></h2>
<p class="sub"><?= $h($file) ?></p>

<?php if ($done): ?>
    <div class="box">✅ הקובץ נשמר: <?= $done['priced'] ?> מוצרים קיבלו מחיר<?= $done['waiting'] ? ', ' . count($done['waiting']) . ' יצאו מהקובץ ונשמרו ב<a href="../Series/waiting.php?shop=' . urlencode($shop) . '">מוצרים שמחכים למחיר</a>' : '' ?>.</div>
    <?php npSeriesSummaryBox($done['summary']); ?>
    <a href="index.php">חזור ל-NP</a>
<?php elseif (!$rows): ?>
    <div class="box">✓ אין בקובץ מוצרים במחיר X.</div>
<?php else: ?>
<div class="dcl-box" id="spBox">
    <span><b><?= count($rows) ?> מוצרים במחיר X</b></span>
    <label><input type="checkbox" id="spAll" checked> כולם</label>
    <button type="button" class="btn-dcl" id="spGo">תן מחיר לפי סדרה</button>
    <span id="spMsg"></span>
</div>
<p class="sub">אפשר לתקן את שם המוצר (למשל להוסיף משקל מהתמונה) — ההצעות לשורה מתעדכנות לפי השם החדש, והשם נשמר בקובץ.</p>
<form method="post">
    <input type="hidden" name="shop" value="<?= $h($shop) ?>">
    <input type="hidden" name="date" value="<?= $h($date) ?>">
    <input type="hidden" name="root" value="<?= $h($root) ?>">
    <table>
        <tr><th>שורה</th><th>ברקוד</th><th>שם מוצר</th><th>מחיר</th></tr>
        <?php foreach ($rows as $r => $it): ?>
        <tr data-xrow data-row="<?= $r ?>" data-barcode="<?= $h($it['barcode']) ?>" data-name="<?= $h($it['name']) ?>">
            <td><?= $r ?></td>
            <td><?= $h($it['barcode']) ?></td>
            <td><input type="text" name="name[<?= $r ?>]" value="<?= $h($it['name']) ?>" style="width:100%;box-sizing:border-box;font-size:17px;padding:6px 8px;border:1px solid #bbb;border-radius:6px"></td>
            <td><label style="display:flex;gap:6px;align-items:center"><input type="checkbox" class="sp-pick" checked><input class="sp-price" type="text" inputmode="decimal" name="price[<?= $r ?>]" placeholder="X"></label>
                <input type="hidden" class="sp-noprice" name="noprice[<?= $r ?>]" value=""></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <button class="btn">שמור את הקובץ</button>
</form>
<script>window.SERIES_PRICE = { shop: <?= json_encode($shop, JSON_UNESCAPED_UNICODE) ?>, drive: <?= json_encode($root) ?>, cols: 4 };</script>
<script src="series_price.js"></script>
<?php endif; ?>
</body>
</html>

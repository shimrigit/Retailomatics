<?php
// rename.php — rename a price-list item: the series are rebuilt with the new name (so an item
// the chain named a little differently joins its series), and the rename waits in
// Series\name_fixes.json for the Comax Gateway (until a new download shows Comax has it).
//   GET  ?shop=YBC&q=<barcode or part of the name>
//   POST shop, q, barcode, name   (an empty name = cancel the rename)
set_time_limit(300);
ini_set('memory_limit', '1G');
require_once __DIR__ . '/common.php';
$shop = seriesRequestShop();
$q = trim((string) ($_REQUEST['q'] ?? ''));
$data = seriesLoad($shop);
if (!$data) { header('Location: index.php'); exit; }

$note = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bc = (string) ($_POST['barcode'] ?? '');
    $new = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));
    $d = $data['decisions'][$bc] ?? [];
    if ($new === '') unset($d['rename'], $d['renameAt']);
    else { $d['rename'] = $new; $d['renameAt'] = date('Y-m-d H:i:s'); }
    if ($d) $data['decisions'][$bc] = $d; else unset($data['decisions'][$bc]);
    seriesSave($shop, $data);
    $data = seriesSplit($shop, 'Z', true);   // the same download, split again with the new name
    $note = $bc;
}

// every item, with its series (after the user's decisions)
$applied = seriesApply($data);
$items = [];
foreach ($applied['series'] as $s) foreach ($s['items'] as $it) $items[] = $it + ['series' => $s];
foreach ($applied['loose'] as $it) $items[] = $it + ['series' => null];
$found = $q === '' ? [] : array_slice(array_values(array_filter($items, fn($it) =>
    $it['barcode'] === $q || mb_stripos($it['name'], $q) !== false || mb_stripos($it['comaxName'] ?? '', $q) !== false)), 0, 40);
$renames = array_filter($data['decisions'] ?? [], fn($d) => isset($d['rename']));

seriesPageHead("שינוי שם פריט – $shop");
?>
<h2>שינוי שם פריט – <?= h($shop) ?></h2>
<p class="sub">שם חדש מצרף את הפריט לסדרה שמתאימה לו (החלוקה נבנית מחדש — כ-15 שניות), ונשמר ב-name_fixes.json לשינוי בקומקס דרך ה-Gateway.
    · <a href="index.php">סדרות</a> · <a href="view.php?shop=<?= urlencode($shop) ?>">הסדרות של <?= h($shop) ?></a></p>
<form class="box" method="get">
    <input type="hidden" name="shop" value="<?= h($shop) ?>">
    חיפוש: <input type="text" name="q" value="<?= h($q) ?>" placeholder="ברקוד או חלק מהשם" style="font-size:18px;padding:6px 10px;width:340px">
    <button class="btn">חפש</button>
    <?php if ($renames): ?><span class="muted" style="margin-right:20px"><?= count($renames) ?> שינויי שם ממתינים לקומקס</span><?php endif; ?>
</form>
<?php if ($q !== '' && !$found): ?><div class="err">לא נמצא פריט</div><?php endif; ?>
<?php if ($found): ?>
<table>
    <tr><th>ברקוד</th><th>שם</th><th>מחיר</th><th>סדרה</th><th></th></tr>
    <?php foreach ($found as $it): $s = $it['series']; ?>
    <tr<?= $note === $it['barcode'] ? ' style="background:#e8f5e9"' : '' ?>>
        <td><?= h($it['barcode']) ?></td>
        <td>
            <form method="post" style="display:flex;gap:8px;align-items:center" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='בונה מחדש…'">
                <input type="hidden" name="shop" value="<?= h($shop) ?>">
                <input type="hidden" name="q" value="<?= h($q) ?>">
                <input type="hidden" name="barcode" value="<?= h($it['barcode']) ?>">
                <input type="text" name="name" value="<?= h($it['name']) ?>" style="flex:1;font-size:17px;padding:6px 8px;min-width:320px">
                <button class="btn green">שמור שם</button>
            </form>
            <?php if (!empty($it['comaxName'])): ?><div class="muted" style="font-size:15px">בקומקס: <?= h($it['comaxName']) ?> — השם החדש ממתין לקומקס</div><?php endif; ?>
        </td>
        <td><?= money($it['effPrice'] ?? $it['price']) ?></td>
        <td><?= $s ? '#' . h($s['id']) . ' ' . h($s['brand']) . ' · ' . h($s['line']) . ' · ' . h($s['sizeText']) . ' — ' . money($s['price']) . ' (' . count($s['items']) . ')' : '<span class="muted">ללא סדרה' . (!empty($it['why']) ? ' — ' . h($it['why']) : '') . '</span>' ?></td>
        <td><?php if (!empty($it['comaxName'])): ?>
            <form method="post"><input type="hidden" name="shop" value="<?= h($shop) ?>"><input type="hidden" name="q" value="<?= h($q) ?>"><input type="hidden" name="barcode" value="<?= h($it['barcode']) ?>"><input type="hidden" name="name" value="">
                <button class="btn light" onclick="return confirm('לבטל את שינוי השם?')">בטל</button></form>
        <?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>
</body>
</html>

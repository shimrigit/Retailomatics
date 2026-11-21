<?php
// A shop's products waiting for a price: left without a price on NP's final screen (no fitting
// series, or "ללא מחיר"). Remove one once it has a price.
require_once __DIR__ . '/common.php';
$shop = seriesRequestShop();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['remove'])) {
    seriesWaitingRemove($shop, (array) $_POST['remove']);
    header('Location: waiting.php?shop=' . urlencode($shop));
    exit;
}
$list = seriesWaitingLoad($shop);
usort($list, fn($a, $b) => strcmp($b['added'] ?? '', $a['added'] ?? ''));
seriesPageHead("מחכים למחיר – $shop");
?>
<h2>מוצרים שמחכים למחיר – <?= h($shop) ?></h2>
<p class="sub">מוצרים שיצאו מקובץ ה-NP בלי מחיר (לא נמצאה סדרה / סומנו "ללא מחיר"). אחרי שנקבע להם מחיר — הסר מהרשימה. ·
    <a href="index.php">סדרות</a> · <a href="../NPharmonized/">NP</a></p>
<?php if (!$list): ?>
<div class="box ok">✓ אין מוצרים שמחכים למחיר</div>
<?php else: ?>
<form method="post">
    <input type="hidden" name="shop" value="<?= h($shop) ?>">
    <table>
        <tr><th>ברקוד</th><th>שם מוצר</th><th>תאריך NP</th><th>נוסף</th><th>תמונה</th><th></th></tr>
        <?php foreach ($list as $it): ?>
        <tr>
            <td><?= h($it['barcode']) ?></td>
            <td><?= h($it['name']) ?></td>
            <td><?= h($it['date'] ?? '') ?></td>
            <td><?= h(substr($it['added'] ?? '', 0, 16)) ?></td>
            <td><?php if (!empty($it['image']) && !empty($it['npDir'])): ?><a target="_blank" href="../NPharmonized/serve_image.php?np_dir=<?= urlencode($it['npDir']) ?>&fname=<?= urlencode($it['image']) ?>">תמונה</a><?php endif; ?></td>
            <td><button class="btn light" name="remove[]" value="<?= h($it['barcode']) ?>" onclick="return confirm('להסיר מהרשימה?')">הסר</button></td>
        </tr>
        <?php endforeach; ?>
    </table>
</form>
<?php endif; ?>
</body>
</html>

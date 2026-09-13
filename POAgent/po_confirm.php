<?php
// PO flow, step 3 — confirm screen (spec §4.1). Prices/names are always
// re-derived from the SupplierStore catalog by barcode here, never trusted
// from client-submitted hidden fields.
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/SupplierStore.php';
$generatorId = poagent_require_generator();

$supplierId = $_POST['supplier_id'] ?? '';
$qtyPosted = $_POST['qty'] ?? [];

if ($supplierId === '' || !SupplierStore::exists($supplierId) || !is_array($qtyPosted)) {
    header('Location: po_supplier.php');
    exit;
}

$catalog = SupplierStore::getCatalog($supplierId);
$catalogByBarcode = [];
foreach ($catalog as $item) {
    $catalogByBarcode[$item['barcode']] = $item;
}

$selected = [];
foreach ($qtyPosted as $barcode => $qtyRaw) {
    $qty = (int) $qtyRaw;
    if ($qty <= 0 || !isset($catalogByBarcode[$barcode])) {
        continue; // ignore zero/blank rows and unknown/tampered barcodes
    }
    $catalogItem = $catalogByBarcode[$barcode];
    $selected[] = [
        'barcode'           => $catalogItem['barcode'],
        'name'              => $catalogItem['name'],
        'qty'               => $qty,
        'unit_price_agorot' => $catalogItem['price_agorot'],
    ];
}

if (empty($selected)) {
    header('Location: po_items.php?supplier_id=' . urlencode($supplierId));
    exit;
}

$totalAgorot = 0;
foreach ($selected as $line) {
    $totalAgorot += $line['qty'] * $line['unit_price_agorot'];
}

poagent_render_head("POAgent – אישור הזמנה: {$supplierId}");
?>
<h2>אישור הזמנת רכש — <?= htmlspecialchars($supplierId) ?></h2>

<table class="responsive-table">
    <thead><tr><th>ברקוד</th><th>שם פריט</th><th>כמות</th><th>מחיר יח'</th><th>סה"כ</th></tr></thead>
    <?php foreach ($selected as $line): $lineTotal = $line['qty'] * $line['unit_price_agorot']; ?>
    <tr>
        <td data-label="ברקוד"><?= htmlspecialchars($line['barcode']) ?></td>
        <td data-label="שם פריט"><?= htmlspecialchars($line['name']) ?></td>
        <td data-label="כמות"><?= (int) $line['qty'] ?></td>
        <td data-label="מחיר יח'"><?= number_format($line['unit_price_agorot'] / 100, 2) ?> ₪</td>
        <td data-label="סה&quot;כ"><?= number_format($lineTotal / 100, 2) ?> ₪</td>
    </tr>
    <?php endforeach; ?>
    <tr>
        <td colspan="4" style="text-align:left"><strong>סה"כ להזמנה</strong></td>
        <td data-label="סה&quot;כ להזמנה"><strong><?= number_format($totalAgorot / 100, 2) ?> ₪</strong></td>
    </tr>
</table>

<form action="po_create.php" method="post">
    <input type="hidden" name="supplier_id" value="<?= htmlspecialchars($supplierId) ?>">
    <?php foreach ($selected as $line): ?>
        <input type="hidden" name="qty[<?= htmlspecialchars($line['barcode']) ?>]" value="<?= (int) $line['qty'] ?>">
    <?php endforeach; ?>
    <button type="submit">✅ אשר וצור הזמנה</button>
</form>

<form action="po_items.php" method="post" class="row-gap">
    <input type="hidden" name="supplier_id" value="<?= htmlspecialchars($supplierId) ?>">
    <?php foreach ($selected as $line): ?>
        <input type="hidden" name="qty[<?= htmlspecialchars($line['barcode']) ?>]" value="<?= (int) $line['qty'] ?>">
    <?php endforeach; ?>
    <button type="submit" class="btn secondary">חזרה לעריכת כמויות</button>
</form>
<?php poagent_render_foot(); ?>

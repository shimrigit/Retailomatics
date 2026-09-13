<?php
// PO flow, step 4 — actually writes the PO (spec §4.2). Re-derives prices
// from the catalog exactly like po_confirm.php (never trusts client data),
// then hands off to po_success.php via a one-time session flash
// (POST/redirect/GET — refreshing po_success.php won't recreate a PO).
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/SupplierStore.php';
require_once __DIR__ . '/lib/POStore.php';
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

$items = [];
foreach ($qtyPosted as $barcode => $qtyRaw) {
    $qty = (int) $qtyRaw;
    if ($qty <= 0 || !isset($catalogByBarcode[$barcode])) {
        continue;
    }
    $catalogItem = $catalogByBarcode[$barcode];
    $items[] = [
        'barcode'           => $catalogItem['barcode'],
        'name'              => $catalogItem['name'],
        'qty'               => $qty,
        'unit_price_agorot' => $catalogItem['price_agorot'],
    ];
}

if (empty($items)) {
    header('Location: po_supplier.php');
    exit;
}

$record = POStore::createPO($generatorId, $supplierId, $items, $_SESSION['poagent_display_name'] ?? null);

$_SESSION['poagent_last_po'] = $record;
header('Location: po_success.php');
exit;

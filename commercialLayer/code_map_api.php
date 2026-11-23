<?php
// The conversion table's start number, for InvoiceScan's first screen (code_map.php).
//  GET  ?shop=            → {ok, last, pending: [{supplier, key, code, name, since}], count}
//  POST shop=&last=1237   → sets the last number given (the next new product without a barcode gets the next free one)
require_once __DIR__ . '/code_map.php';
header('Content-Type: application/json; charset=utf-8');

$shops = array_column(json_decode((string) file_get_contents(__DIR__ . '/../configDir/shops_V2.json'), true) ?: [], 'ShopName');
$shop = (string) ($_REQUEST['shop'] ?? '');
if (!in_array($shop, $shops, true)) {
    echo json_encode(['ok' => false, 'error' => 'חנות לא מוכרת'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $last = trim((string) ($_POST['last'] ?? ''));
    if ($last !== '' && !ctype_digit($last)) {
        echo json_encode(['ok' => false, 'error' => 'מספר בלבד'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    cmUpdate($shop, function (array &$cm) use ($last) { $cm['last'] = $last === '' ? null : $last; });
}

// The copy on Z: brought up to date if Z was down at the last change.
$z = null;   // null = no table yet
if (is_file(cmPath($shop))) {
    $zf = cmZDir($shop) . '/codemap.json';
    $z = (is_file($zf) && md5_file($zf) === md5_file(cmPath($shop))) || cmBackupToZ($shop);
}

$cm = cmLoad($shop);
$pending = [];
$count = 0;
foreach ($cm['map'] as $supplier => $rows) {
    foreach ($rows as $key => $r) {
        $count++;
        if (($r['status'] ?? '') === 'new') $pending[] = ['supplier' => $supplier, 'key' => $key] + $r;
    }
}
echo json_encode(['ok' => true, 'last' => $cm['last'], 'pending' => $pending, 'count' => $count,
    'z' => $z, 'zDir' => cmZDir($shop)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

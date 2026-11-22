<?php
// The shop's price list for the commercial layer (price_list_source.php):
//   GET  ?shop=X        → {ok, current: label | null, items: {name, when} | null, newer: bool}
//   POST shop=X         → makes price_list_files\X ddmmyy_hhmmss.xlsx from the newest items file
//                         → {ok, message, current}
// Used by InvoiceScan's start screen ("עדכן רשימת מחירים מקובץ פריטים").
set_time_limit(300);
ini_set('memory_limit', '1G');
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/price_list_source.php';
date_default_timezone_set('Asia/Jerusalem');
header('Content-Type: application/json; charset=utf-8');

$shops = array_column(json_decode((string) @file_get_contents(__DIR__ . '/../configDir/shops_V2.json'), true) ?: [], 'ShopName');
$shop = (string) ($_REQUEST['shop'] ?? '');
if (!in_array($shop, $shops, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'חנות לא מוכרת'], JSON_UNESCAPED_UNICODE);
    exit;
}

function status(string $shop): array
{
    $pl = clFindPriceList($shop);
    $items = clLatestItemsFile($shop);
    return [
        'current' => $pl ? clPriceListLabel($pl) : null,
        'items'   => $items ? ['name' => $items['name'], 'when' => date('d/m/Y H:i', strtotime($items['when']))] : null,
        // an items file newer than the price list in use (a made one: a different download time)
        'newer'   => $items && (!$pl || strcmp($items['when'], $pl['when']) > 0),
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $r = clMakePriceList($shop);
        $when = date('d/m/Y H:i', strtotime($r['when']));
        $msg = $r['existed']
            ? "רשימת המחירים של $shop כבר מעודכנת לפי קובץ הפריטים מ-$when ({$r['name']})"
            : "רשימת המחירים של $shop עודכנה לפי קובץ הפריטים מ-$when — {$r['items']} פריטים ({$r['name']})";
        echo json_encode(['ok' => true, 'message' => $msg] + status($shop), JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => true] + status($shop), JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()] + status($shop), JSON_UNESCAPED_UNICODE);
}

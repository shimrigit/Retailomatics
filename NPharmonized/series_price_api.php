<?php
// "תן מחיר לפי סדרה" (series_price.js): the pricing options for products without a price, from
// the shop's series (Series/, ProductsList\Series\series.json).
// POST shop, drive, items = JSON [{barcode, name}] → {ok, split: built date | null, options: {barcode: [...]}}.
set_time_limit(120);
ini_set('memory_limit', '1G');
require_once __DIR__ . '/../Series/store.php';
header('Content-Type: application/json; charset=utf-8');

$shop  = (string) ($_POST['shop'] ?? '');
$drive = strtoupper(substr((string) ($_POST['drive'] ?? 'Z'), 0, 1));
$items = json_decode((string) ($_POST['items'] ?? ''), true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !in_array($shop, seriesShops(), true) || !preg_match('/^[A-Z]$/', $drive) || !is_array($items)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad request']);
    exit;
}
try {
    $data = seriesLoad($shop, $drive);
    $options = $data ? seriesPriceOptions($shop, $items, $drive) : null;
    echo json_encode(['ok' => true, 'split' => $data['meta']['built'] ?? null, 'options' => $options ?? new stdClass()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

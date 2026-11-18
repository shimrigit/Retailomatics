<?php
// Saves one NP start-screen checkbox for one shop (index.php, on change).
require_once __DIR__ . '/shop_settings.php';
header('Content-Type: application/json; charset=utf-8');

$shop  = basename((string) ($_POST['shop'] ?? ''));
$key   = (string) ($_POST['key'] ?? '');
$value = ($_POST['value'] ?? '') === '1';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $shop === '' || !in_array($key, ['noDeptClassify', 'customName'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad request']);
    exit;
}
try {
    npSetShopSetting($shop, $key, $value);
    echo json_encode(['ok' => true, 'settings' => npShopSettings($shop)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

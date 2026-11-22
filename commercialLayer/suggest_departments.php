<?php
// "המלץ מחלקות" on the new-products screen (verify_new_products.php): a department for each
// product name, by AI, from the shop's departments — the same classification as NP's stage 3
// (NPharmonized/dcl_classify_lib.php). The screen fills them in as suggestions to check.
// POST JSON {shop, names: [...]} → {ok, departments: [name | ''], usage}
set_time_limit(180);
require_once __DIR__ . '/../NPharmonized/dcl_classify_lib.php';
header('Content-Type: application/json; charset=utf-8');

$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$shop = basename((string) ($in['shop'] ?? ''));
$names = array_map('strval', (array) ($in['names'] ?? []));
$deptFile = __DIR__ . "/../configDir/{$shop}_Departments.json";
if ($shop === '' || !$names || !is_file($deptFile)) {
    echo json_encode(['ok' => false, 'error' => $shop === '' || !$names ? 'bad request' : "אין קובץ מחלקות לחנות $shop"], JSON_UNESCAPED_UNICODE);
    exit;
}
$lookup = npLoadDepartments($deptFile);
$cl = npClassifyDepartments($names, array_keys($lookup));
if ($cl['error']) {
    echo json_encode(['ok' => false, 'error' => $cl['error']], JSON_UNESCAPED_UNICODE);
    exit;
}
$out = [];
foreach (array_values($names) as $i => $n) {
    $d = $cl['byIndex'][$i]['department'] ?? '';
    $out[] = isset($lookup[$d]) ? $d : '';   // only a department of the shop
}
echo json_encode(['ok' => true, 'departments' => $out, 'usage' => $cl['usage']], JSON_UNESCAPED_UNICODE);

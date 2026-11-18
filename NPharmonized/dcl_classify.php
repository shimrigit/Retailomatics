<?php
// The "סווג מחלקות עכשיו" button on the final screen (stage_dcl.php, for a shop
// with "ללא סיווג למחלקות"): classifies the names as they are on the screen now.
// POST dept_file, names[] → JSON {ok, items: [{deptNum, deptName, note}], usage}.
set_time_limit(0);
require_once __DIR__ . '/dcl_classify_lib.php';
header('Content-Type: application/json; charset=utf-8');

$deptFile = (string) ($_POST['dept_file'] ?? '');
$names    = array_map('strval', (array) ($_POST['names'] ?? []));
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$names || !is_file($deptFile) || !preg_match('/_Departments\.json$/', $deptFile)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad request']);
    exit;
}
$lookup = npLoadDepartments($deptFile);
$cl = npClassifyDepartments($names, array_keys($lookup));
if ($cl['error']) {
    echo json_encode(['ok' => false, 'error' => $cl['error']], JSON_UNESCAPED_UNICODE);
    exit;
}
$items = [];
foreach (array_values($names) as $i => $n) {
    $dept = $cl['byIndex'][$i]['department'] ?? '';
    $items[] = ['deptNum' => (string) ($lookup[$dept] ?? ''), 'deptName' => $dept, 'note' => $cl['byIndex'][$i]['note'] ?? ''];
}
echo json_encode(['ok' => true, 'items' => $items, 'usage' => $cl['usage']], JSON_UNESCAPED_UNICODE);

<?php
// The files waiting for Z (ZpendingDir — see invoice_archive.php).
// GET  → {ok, reachable, count, files: [{shop, day, name}]}
// POST → moves them to the shops' archive on Z: {ok, moved, left, errors, count, reachable}
require_once __DIR__ . '/invoice_archive.php';
header('Content-Type: application/json; charset=utf-8');

$out = ['ok' => true];
if ($_SERVER['REQUEST_METHOD'] === 'POST') $out += invPendingFlush();
$list = invPendingList();
$out['reachable'] = invArchiveReachable();
$out['count'] = count($list);
$out['files'] = array_map(fn($p) => ['shop' => $p['shop'], 'day' => $p['day'], 'name' => $p['name']], $list);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

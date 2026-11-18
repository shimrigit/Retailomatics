<?php
// "ייצוא לאקסל": the series with the user's decisions, saved in the shop's
// Series folder (series_<date>.xlsx) and downloaded.
ini_set('memory_limit', '1G');
set_time_limit(300);
require_once __DIR__ . '/common.php';
$shop = seriesRequestShop();
$data = seriesLoad($shop);
if (!$data) { header('Location: index.php'); exit; }
$file = seriesDir($shop) . '/series_' . date('Y-m-d_His') . '.xlsx';
seriesExportXlsx(seriesApply($data), $file, $shop);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="series_' . $shop . '.xlsx"; filename*=UTF-8\'\'' . rawurlencode("סדרות $shop " . date('d-m-y') . '.xlsx'));
header('Content-Length: ' . filesize($file));
readfile($file);

<?php
// "חלק לסדרות" / "עדכן חלוקה": splits the newest download, keeping the user's decisions.
set_time_limit(300);
ini_set('memory_limit', '1G');
require_once __DIR__ . '/common.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
$shop = seriesRequestShop();
try {
    $data = seriesSplit($shop);
} catch (Throwable $e) {
    header('Location: index.php?error=' . urlencode($e->getMessage()));
    exit;
}
header('Location: view.php?shop=' . urlencode($shop) . ($data['changes'] ? '&show=changes' : ''));

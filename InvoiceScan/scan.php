<?php
/**
 * Retailomatics Invoice Scan — step 2: the AI reads the chosen files.
 *
 * POST shop + files[] (names in preProcessDir) → a batch: every file read on its own, a few
 *   at a time. Then: pages of the same supplier and date → group.php (one document or
 *   several?); none → each file is a document → batch.php (the list).
 * POST id + again → one document read again, all its pages together (confirm.php's "קרא שוב").
 */
session_start();
require_once __DIR__ . '/lib/common.php';
set_time_limit(900);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!empty($_POST['id'])) {   // one document again
    $work = scan_load((string) $_POST['id']);
    try {
        $work['read'] = InvoiceReader::read(scan_source_paths($work), $work['shop']);
        $work['read_at'] = date('c');
    } catch (Throwable $e) {
        scan_page_start('הקריאה נכשלה');
        echo '<div class="card"><h2 class="warn">הקריאה נכשלה</h2><p>' . scan_h($e->getMessage()) . '</p>'
            . '<a class="btn second" href="confirm.php?id=' . scan_h($work['id']) . '">חזרה</a></div>';
        scan_page_end();
        exit;
    }
    scan_save($work);
    header('Location: confirm.php?id=' . $work['id']);
    exit;
}

$shop = (string) ($_POST['shop'] ?? '');
$files = array_values(array_unique(array_map('basename', (array) ($_POST['files'] ?? []))));
if (!in_array($shop, scan_shops(), true) || !$files || array_diff($files, scan_waiting_files())) {
    http_response_code(400);
    exit('בחרו חנות וקבצים מהרשימה. <a href="index.php">חזרה</a>');
}
$_SESSION['invoiceScanShop'] = $shop;
natcasesort($files);
$docs = [];
foreach ($files as $f) $docs[$f] = [SCAN_IN_DIR . "/$f"];
$batch = ['id' => bin2hex(random_bytes(6)), 'shop' => $shop, 'created' => date('c'), 'pages' => InvoiceReader::readMany($docs, $shop), 'docs' => []];
scan_batch_save($batch);

if (scan_batch_groups($batch)) {
    header('Location: group.php?b=' . $batch['id']);
} else {
    header('Location: ' . scan_batch_next_url(scan_batch_build_docs($batch, [])));
}

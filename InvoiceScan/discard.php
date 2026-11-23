<?php
/**
 * Retailomatics Invoice Scan — put a document aside: it will not go into Comax.
 *
 * POST id, reason → the document's source files (photos / PDF, still in preProcessDir) — or, for a
 * document approved and not finished, its PDF + OCRjson (the OCRsanity / CL files of it are deleted) move to the processing day's archive folder on Z, in a Discard
 * subfolder:  <archive>\<shop>\Invoices\YYYY\MM_Month\dd-mm-yy IN\Discard\
 * named "<supplier> <number> - <original name>", with "<supplier> <number> - סיבה.txt" (who / when /
 * what was read / the reason). Z down → ZpendingDir\<shop>\<day>\Discard\ (moved to Z with the rest).
 * The work file keeps 'discarded'; the document leaves the lists (its files are no longer in preProcessDir).
 */
session_start();
require_once __DIR__ . '/lib/common.php';
require_once SCAN_ROOT . '/harmonizedFlow/invoice_archive.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
$work = scan_load((string) ($_POST['id'] ?? ''));
$back = !empty($work['batch']) ? 'batch.php?b=' . $work['batch'] : 'index.php';

$fail = function (string $msg) use ($back) {
    scan_page_start('הוצאת מסמך');
    echo '<div class="card"><p class="warn">' . scan_h($msg) . '</p><a class="btn second" href="' . scan_h($back) . '">חזרה</a></div>';
    scan_page_end();
    exit;
};
if (!empty($work['discarded'])) { header('Location: ' . $back); exit; }
$ap = $work['approved'] ?? null;
if ($ap && !is_file(SCAN_IN_DIR . '/' . $ap['pdfFile'])) $fail('המסמך כבר סיים את התהליך (ה-PDF שלו כבר בארכיון) — אי אפשר להוציא אותו מכאן.');

$d = $work['read']['data'] ?? [];
$match = InvoiceReader::matchSupplier($d);
$supplier = $match['supplier']['supplierName'] ?? ($d['supplier_name_printed'] ?? '') ?: 'ספק לא ידוע';
$label = trim(preg_replace('/[\\\\\/:*?"<>|]+/u', '-', $supplier . ' ' . ($d['doc_number'] ?? '')));
$day = scan_day_or_today($work['day'] ?? '');
$dayTime = strtotime("$day 12:00");
$reason = trim((string) ($_POST['reason'] ?? ''));

// Not approved: its source files (photos / PDF). Approved, the flow not finished: the PDF approve.php
// made and the OCRjson; what the flow made from them (OCRsanity, CL, PRICE-CHANGE, NEW-PRODUCTS) is
// deleted — else BOpack would take the document in with the day's others.
$moves = [];     // [src, name]
$derived = [];   // files deleted
if ($ap) {
    $base = pathinfo($ap['pdfFile'], PATHINFO_FILENAME);   // "Gad 22-11-26 A"
    $label = $base;
    $moves[] = [SCAN_IN_DIR . '/' . $ap['pdfFile'], $ap['pdfFile']];
    $ocrJson = SCAN_ROOT . '/AIocr/ocr_extracted/' . $ap['ocrJsonFile'];
    if (is_file($ocrJson)) $moves[] = [$ocrJson, "$base - OCRjson.json"];
    if (preg_match('/^OCRjson_(.+_\d{2}-\d{2}-\d{4}_[A-Z]+)_\d{8}_\d{6}\.json$/', $ap['ocrJsonFile'], $m)) {
        $prefix = 'OCRsanity_' . $m[1] . '_';
        foreach ([SCAN_ROOT . '/OCRsanity/sanity_files', SCAN_ROOT . '/commercialLayer/commercial_invoice_files'] as $dir) {
            foreach (scandir($dir) ?: [] as $f) {
                if (str_starts_with($f, $prefix) || str_starts_with($f, 'temp_' . $prefix)) $derived[] = "$dir/$f";
            }
        }
    }
} else {
    foreach (scan_source_paths($work) as $p) $moves[] = [$p, "$label - " . basename($p)];
}
foreach ($moves as [$p]) if (!is_file($p)) $fail('הקובץ לא נמצא: ' . basename($p));

$placed = [];
$errors = [];
foreach ($moves as [$p, $name]) {
    $r = invArchiveStore($work['shop'], $p, $name, true, $dayTime, 'Discard');
    if (isset($r['error'])) $errors[] = $r['error'];
    else $placed[] = $r;
}
if (!$errors) {
    foreach ($derived as $f) @unlink($f);
    if (($_SESSION['harmonizedFlow']['pdfFile'] ?? '') === ($ap['pdfFile'] ?? null)) unset($_SESSION['harmonizedFlow']);   // the flow was on it
}
if ($placed) {
    $note = tempnam(sys_get_temp_dir(), 'dsc');
    file_put_contents($note, "\xEF\xBB\xBF" . str_replace("\n", "\r\n", implode("\n", [
        'מסמך שהוצא — לא נקלט לקומקס',
        'הוצא: ' . date('d/m/Y H:i'),
        'יום עיבוד: ' . date('d/m/Y', $dayTime),
        'ספק: ' . $supplier . ($d['supplier_vat_id'] ?? '' ? ' (ח.פ. ' . $d['supplier_vat_id'] . ')' : ''),
        'מספר: ' . ($d['doc_number'] ?? ''),
        'תאריך במסמך: ' . ($d['doc_date'] ?? ''),
        'שורות: ' . count($d['items'] ?? []),
        'קבצים: ' . implode(', ', array_column($placed, 'name')),
        'סיבה: ' . ($reason !== '' ? $reason : '—'),
        ...($ap ? ['הוצא אחרי אישור; נמחקו: ' . ($derived ? implode(', ', array_map('basename', $derived)) : '—')] : []),
    ]) . "\n"));
    invArchiveStore($work['shop'], $note, "$label - סיבה.txt", true, $dayTime, 'Discard');
}
$work['discarded'] = ['at' => date('c'), 'reason' => $reason, 'where' => $placed[0]['where'] ?? '', 'dir' => $placed[0]['dir'] ?? '',
    'files' => array_column($placed, 'name'), 'deleted' => array_map('basename', $errors ? [] : $derived), 'errors' => $errors];
scan_save($work);
if ($errors) $fail('חלק מהקבצים לא הועברו: ' . implode(' · ', $errors));

$_SESSION['invoiceScanFlash'] = '🗑 הוצא: ' . $label . ' → ' . ($placed[0]['where'] === 'archive' ? $placed[0]['dir'] : 'ZpendingDir (Z לא זמין — יועבר ל-Z עם השאר)');
if (!empty($work['batch'])) { header('Location: ' . scan_batch_next_url(scan_batch_load($work['batch']))); exit; }
header('Location: index.php');

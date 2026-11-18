<?php
/**
 * Retailomatics Invoice Scan — step 4: the identification is approved → the existing OCRsanity step.
 *
 * 1. The supplier: an existing suppliers.json entry learns its ע.מ. and the name printed on
 *    the document (so next time it is found by ע.מ.); a new supplier is added to the file.
 *    The entry's own OCRsanityMethod / jsonToOcrSanity (the old flow's) are not touched.
 * 2. The document becomes "<Supplier> dd-mm-yy <Letter>.pdf" in preProcessDir — photos are
 *    put into a PDF (one page each), a PDF is copied — and the source files move to
 *    preProcessDir/TempPP, as PreProcess2 does.
 * 3. The rows are written as an OCRjson file in AIocr/ocr_extracted, in the format the old
 *    OCR step writes (table rows with numbered properties).
 * 4. The harmonizedFlow session is set as its step 3 leaves it, plus scanOverride — the
 *    method and column mapping approved here, which OCRsanity/process_ocrsanity.php uses
 *    instead of the supplier's configuration — and the browser goes to
 *    harmonizedFlow/step4_create_ocr_sanity.php. From there on it is the existing flow.
 */
session_start();
require_once __DIR__ . '/lib/common.php';
require_once SCAN_ROOT . '/vendor/autoload.php';

function approve_fail(string $msg, string $id): never
{
    scan_page_start('האישור נכשל');
    echo '<div class="card"><h2 class="warn">האישור לא בוצע</h2><p>' . scan_h($msg) . '</p>'
        . '<a class="btn second" href="confirm.php?id=' . scan_h($id) . '">חזרה לאישור הזיהוי</a></div>';
    scan_page_end();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
$work = scan_load((string) ($_POST['id'] ?? ''));
$id = $work['id'];
$read = $work['read']['data'];

// The rows as the user left them in confirm.php (corrected, added, deleted). What the AI
// read is kept once, beside them, in read.ai_items.
if (is_array($_POST['items'] ?? null)) {
    $in = $_POST['items'];
    $items = [];
    foreach (array_keys((array) ($in['description'] ?? [])) as $i) {
        $num = function (string $k) use ($in, $i) {
            $s = trim((string) ($in[$k][$i] ?? ''));
            return is_numeric($s) ? (float) $s : null;
        };
        $it = ['barcode' => trim((string) ($in['barcode'][$i] ?? '')), 'description' => trim((string) ($in['description'][$i] ?? '')),
            'qty' => $num('qty'), 'unit_price' => $num('unit_price'), 'discount1_pct' => $num('discount1_pct'),
            'discount2_pct' => $num('discount2_pct'), 'line_total' => $num('line_total')];
        if (array_filter($it, fn($x) => $x !== null && $x !== '')) $items[] = $it;   // an empty row is dropped
    }
    if ($items != $read['items']) {
        $work['read']['ai_items'] ??= $read['items'];
        $work['read']['data']['items'] = $read['items'] = $items;
    }
}

$method = (string) ($_POST['method'] ?? '');
$date = DateTime::createFromFormat('!Y-m-d', (string) ($_POST['doc_date'] ?? ''));
$vat = preg_replace('/\D/', '', (string) ($_POST['vat'] ?? ''));
$docNumber = trim((string) ($_POST['doc_number'] ?? ''));
$invoiceTotal = is_numeric($_POST['invoice_total'] ?? null) ? (float) $_POST['invoice_total'] : 0.0;
if (!in_array($method, InvoiceReader::METHODS, true)) approve_fail('שיטה לא מוכרת.', $id);
if (!$date) approve_fail('חסר תאריך למסמך.', $id);
if (!$read['items']) approve_fail('לא נקראו שורות מהמסמך — אין מה להעביר ל-OCRsanity.', $id);

// ── 1. the supplier ─────────────────────────────────────────────────────────
$suppliers = InvoiceReader::suppliers();
$key = (string) ($_POST['supplier'] ?? '');
$printed = $read['supplier_name_printed'];
if ($key === '__new__') {
    $key = trim((string) ($_POST['new_key'] ?? ''));
    $hebrew = trim((string) ($_POST['new_hebrew'] ?? ''));
    if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $key)) approve_fail('שם הספק באנגלית: אותיות וספרות בלבד, בלי רווחים.', $id);
    if ($hebrew === '') approve_fail('חסר שם הספק בעברית.', $id);
    foreach ($suppliers as $s) {
        if (strcasecmp($s['supplierName'], $key) === 0) approve_fail("כבר יש ספק בשם {$s['supplierName']} — בחרו אותו מהרשימה.", $id);
    }
    $suppliers[] = array_filter(['supplierName' => $key, 'OCRsanityMethod' => $method, 'hebrewName' => $hebrew, 'vatId' => $vat]);
} else {
    $found = false;
    foreach ($suppliers as &$s) {
        if ($s['supplierName'] !== $key) continue;
        $found = true;
        if ($vat !== '' && empty($s['vatId'])) $s['vatId'] = $vat;
        // The name as this supplier prints it, when it is not the one on file.
        $plain = fn($n) => preg_replace('/["״׳\x27\s.]+/u', '', (string) $n);   // quote marks and spacing aside
        if ($printed !== '' && !in_array($plain($printed), array_map($plain, array_merge([$s['hebrewName'] ?? ''], (array) ($s['aliases'] ?? []))), true)) {
            $s['aliases'] = array_slice(array_merge((array) ($s['aliases'] ?? []), [$printed]), -5);
        }
    }
    unset($s);
    if (!$found) approve_fail('בחרו ספק מהרשימה, או "ספק חדש".', $id);
}
foreach ($suppliers as $s) {   // an ע.מ. belongs to one supplier
    if ($vat !== '' && ($s['vatId'] ?? '') === $vat && $s['supplierName'] !== $key) {
        approve_fail("ע.מ. {$vat} כבר שמור לספק {$s['supplierName']}. בחרו אותו, או תקנו את המספר.", $id);
    }
}
$json = json_encode($suppliers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents(InvoiceReader::suppliersFile(), str_replace("\n", "\r\n", $json), LOCK_EX);   // the file's line endings

// ── 2. the PDF, named as the flow expects ───────────────────────────────────
$dmy = $date->format('d-m-y');
$pdfFile = $work['approved']['pdfFile'] ?? '';
if ($pdfFile === '' || !str_starts_with($pdfFile, "$key $dmy ")) {   // first approval, or the supplier / date was changed
    if ($pdfFile !== '') @unlink(SCAN_IN_DIR . "/$pdfFile");
    $pdfFile = '';
    foreach (range('A', 'Z') as $letter) {
        $candidate = "$key $dmy $letter.pdf";
        if (!file_exists(SCAN_IN_DIR . "/$candidate") && !file_exists(SCAN_ROOT . "/uploads/$candidate")) {
            $pdfFile = $candidate;
            break;
        }
    }
    if ($pdfFile === '') approve_fail("יש כבר 26 מסמכים של $key מתאריך $dmy.", $id);
}
$letter = substr(pathinfo($pdfFile, PATHINFO_FILENAME), -1);
$pdfPath = SCAN_IN_DIR . "/$pdfFile";
$sources = scan_source_paths($work);
try {
    if (strtolower(pathinfo($sources[0], PATHINFO_EXTENSION)) === 'pdf') {
        if (!copy($sources[0], $pdfPath)) throw new RuntimeException('copy failed');
    } else {
        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false);
        foreach ($sources as $img) {
            [$w, $h] = getimagesize($img) ?: [0, 0];
            if (!$w || !$h) throw new RuntimeException('not an image: ' . basename($img));
            $pageH = 210 * $h / $w;   // page as wide as A4, as tall as the photo
            $pdf->AddPage('P', [210, $pageH]);
            $pdf->Image($img, 0, 0, 210, $pageH);
        }
        $pdf->Output('F', $pdfPath);
    }
} catch (Throwable $e) {
    approve_fail('לא הצלחתי ליצור את קובץ ה-PDF: ' . $e->getMessage(), $id);
}
// The source files leave the waiting list (kept in TempPP).
if ($work['subdir'] === '') {
    @mkdir(SCAN_IN_DIR . '/TempPP', 0777, true);
    foreach ($work['files'] as $f) {
        if (!@rename(SCAN_IN_DIR . "/$f", SCAN_IN_DIR . "/TempPP/$f")) approve_fail("לא הצלחתי להעביר את $f ל-TempPP.", $id);
    }
    $work['subdir'] = 'TempPP';
}

// ── 3. the OCRjson, as the old OCR step writes it ───────────────────────────
// Row properties "1", "2", … = the method's fields in InvoiceReader::FIELDS order.
$fields = match ($method) {
    'Simple'    => ['Barcode', 'ItemName', 'Qty'],
    'LineTotal' => ['Barcode', 'ItemName', 'Qty', 'UnitPrice', 'LineTotal'],
    'Discount1' => ['Barcode', 'ItemName', 'Qty', 'UnitPrice', 'LineTotal', 'Discount1'],
    'Discount2' => ['Barcode', 'ItemName', 'Qty', 'UnitPrice', 'LineTotal', 'Discount1', 'Discount2'],
};
$mapping = [];
foreach ($fields as $i => $f) $mapping[$f] = $i + 1;
$table = [];
foreach ($read['items'] as $i => $it) {
    $row = ['index' => $i + 1];
    foreach ($fields as $j => $f) {
        $v = $it[InvoiceReader::FIELDS[$f]];
        if ($v === null) $v = str_starts_with($f, 'Discount') ? 0 : '';   // a property must exist in every row
        $row[(string) ($j + 1)] = $v;
    }
    $table[] = $row;
}
$dateFull = $date->format('d-m-Y');
$ocrJsonFile = "OCRjson_{$key}_{$dateFull}_{$letter}_" . date('dmY_His') . '.json';
$ocrJsonPath = SCAN_ROOT . '/AIocr/ocr_extracted/' . $ocrJsonFile;
@mkdir(dirname($ocrJsonPath), 0755, true);
file_put_contents($ocrJsonPath, json_encode([
    'invoice_number' => $docNumber, 'invoice_date' => $date->format('Y-m-d'), 'invoice_total' => $invoiceTotal, 'table' => $table,
    'invoice_scan' => ['source' => 'Retailomatics Invoice Scan', 'model' => $work['read']['model'], 'doc_type' => $read['doc_type'],
        'supplier_printed' => $printed, 'supplier_vat_id' => $vat, 'columns' => $read['columns'], 'totals' => $read['totals']],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$work['approved'] = ['supplier' => $key, 'method' => $method, 'pdfFile' => $pdfFile, 'ocrJsonFile' => $ocrJsonFile, 'at' => date('c')];
scan_save($work);

// ── 4. into the existing flow, at its step 4 ────────────────────────────────
$_SESSION['harmonizedFlow'] = [
    'pdfFile' => $pdfFile,
    'pdfPath' => $pdfPath,
    'shopName' => $work['shop'],
    'supplierName' => $key,
    'processDate' => $dmy,
    'processDateFull' => $dateFull,
    'sequenceIdentifier' => $letter,
    'step' => 3,
    'startTime' => time(),
    'demoMode' => false,
    'ocrJsonFile' => $ocrJsonFile,
    'ocrJsonPath' => $ocrJsonPath,
    'invoiceScanId' => $id,
    'scanOverride' => ['method' => $method, 'mapping' => $mapping],
];
foreach (json_decode((string) @file_get_contents(SCAN_ROOT . '/configDir/shops_V2.json'), true) ?: [] as $shop) {
    if (($shop['ShopName'] ?? '') === $work['shop']) $_SESSION['harmonizedFlow']['shopConfig'] = $shop;
}
$_SESSION['invoiceScanReturn'] = $work['batch'] ?? '-';   // the flow's last page returns to InvoiceScan/next.php
header('Location: ../harmonizedFlow/step4_create_ocr_sanity.php');

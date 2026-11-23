<?php
/**
 * common.php — Retailomatics Invoice Scan: paths, the work file of a scan, the page frame.
 *
 * A scan's state lives in InvoiceScan/work/<id>.json (git-ignored): the shop, the source
 * files and where they are now, and what the AI read. confirm.php shows it, approve.php
 * hands it to the existing OCRsanity step — and can be opened again to go one step back.
 */

require_once __DIR__ . '/InvoiceReader.php';
date_default_timezone_set('Asia/Jerusalem');   // "today" (the processing day) is the shop's day; PHP here defaults to Berlin

const SCAN_ROOT = __DIR__ . '/../..';
const SCAN_IN_DIR = SCAN_ROOT . '/preProcessDir';
const SCAN_WORK_DIR = __DIR__ . '/../work';
const SCAN_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

function scan_h($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES);
}

function scan_shops(): array
{
    $shops = json_decode((string) @file_get_contents(SCAN_ROOT . '/configDir/shops_V2.json'), true) ?: [];
    return array_values(array_filter(array_column($shops, 'ShopName')));
}

/**
 * The new documents waiting in preProcessDir (its top level only), by name. A PDF that
 * approve.php made of an approved document is not one of them — it is that document, in the
 * middle of the flow (see scan_started()).
 */
function scan_waiting_files(): array
{
    $made = [];
    foreach (scan_started() as $w) if (!empty($w['approved'])) $made[] = $w['approved']['pdfFile'];
    $files = [];
    foreach (scandir(SCAN_IN_DIR) ?: [] as $f) {
        if (is_file(SCAN_IN_DIR . "/$f") && in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), SCAN_EXTENSIONS, true) && !in_array($f, $made, true)) $files[] = $f;
    }
    natcasesort($files);
    return array_values($files);
}

/**
 * The documents that were started and not finished, newest first:
 *  - read, not approved yet — its source files are still in preProcessDir;
 *  - approved, the flow after it not finished — its PDF is still in preProcessDir (the flow's
 *    cleanup moves the PDF to the shop's archive on Z — or ZpendingDir — when the document is done).
 * One work file per document: of several readings of the same file, the latest.
 */
function scan_started(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $byDoc = [];
    foreach (glob(SCAN_WORK_DIR . '/*.json') ?: [] as $path) {
        if (str_starts_with(basename($path), 'batch_')) continue;
        $w = json_decode((string) @file_get_contents($path), true);
        if (!is_array($w) || empty($w['files'])) continue;
        if (!empty($w['approved'])) {
            if (!is_file(SCAN_IN_DIR . '/' . $w['approved']['pdfFile'])) continue;   // finished (or removed)
        } else {
            if ($w['subdir'] !== '') continue;
            foreach ($w['files'] as $f) if (!is_file(SCAN_IN_DIR . "/$f")) continue 2;
        }
        $key = empty($w['approved']) ? 'src:' . implode('|', $w['files']) : 'pdf:' . $w['approved']['pdfFile'];
        $w['_time'] = $w['approved']['at'] ?? $w['read_at'] ?? $w['created'];
        if (!isset($byDoc[$key]) || strcmp($w['_time'], $byDoc[$key]['_time']) > 0) $byDoc[$key] = $w;
    }
    // A file that is a page of an approved document is not also a "read, not approved" one.
    usort($byDoc, fn($a, $b) => strcmp($b['_time'], $a['_time']));
    return $cache = array_values($byDoc);
}

function scan_work_path(string $id): string
{
    if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
        http_response_code(400);
        exit('Bad scan id');
    }
    return SCAN_WORK_DIR . "/$id.json";
}

function scan_load(string $id): array
{
    $work = json_decode((string) @file_get_contents(scan_work_path($id)), true);
    if (!is_array($work)) {
        http_response_code(404);
        exit('הסריקה לא נמצאה. <a href="index.php">חזרה</a>');
    }
    return $work;
}

function scan_save(array $work): void
{
    @mkdir(SCAN_WORK_DIR, 0777, true);
    scan_write_json(scan_work_path($work['id']), $work);
}

/** JSON to a file — never an empty one: bad UTF-8 (typed text) is replaced, and a failed encoding throws. */
function scan_write_json(string $path, array $data): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('שמירת ' . basename($path) . ' נכשלה: ' . json_last_error_msg());
    file_put_contents($path, $json, LOCK_EX);
}

/** Full paths of a scan's source files, wherever they are now (preProcessDir or its TempPP). */
function scan_source_paths(array $work): array
{
    return array_map(fn($f) => SCAN_IN_DIR . ($work['subdir'] !== '' ? "/{$work['subdir']}" : '') . "/$f", $work['files']);
}

function scan_source_url(array $work, string $file): string
{
    return '../preProcessDir/' . ($work['subdir'] !== '' ? rawurlencode($work['subdir']) . '/' : '') . rawurlencode($file);
}

function scan_page_start(string $title, bool $wide = false): void
{
    echo '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . scan_h($title) . ' · Retailomatics Invoice Scan</title><style>
body{font-family:Segoe UI,Arial,sans-serif;margin:0;background:#f4f5f7;color:#1c2330;font-size:15px}
header{background:#1d3557;color:#fff;padding:12px 24px;display:flex;gap:16px;align-items:baseline}
header b{font-size:18px}header span{opacity:.8}header a{color:#cfe0ff;margin-inline-start:auto}
main{padding:18px 24px;max-width:1500px;margin:0 auto}main.wide{max-width:none;padding:12px 16px}
.card{background:#fff;border:1px solid #d9dde3;border-radius:8px;padding:16px;margin-bottom:16px}
h2{font-size:17px;margin:0 0 12px}
table{border-collapse:collapse}th,td{border:1px solid #d9dde3;padding:5px 9px;text-align:right;vertical-align:top}
th{background:#eef1f5;font-weight:600;white-space:nowrap}
.num{direction:ltr;text-align:left;white-space:nowrap;font-variant-numeric:tabular-nums}
input[type=text],input[type=date],input[type=number],select{font:inherit;padding:6px 8px;border:1px solid #b9c0cb;border-radius:5px;background:#fff;min-width:200px}
button,.btn{font:inherit;padding:9px 18px;border-radius:6px;border:1px solid #1d3557;background:#1d3557;color:#fff;cursor:pointer;text-decoration:none;display:inline-block}
button.second,.btn.second{background:#fff;color:#1d3557}
button:disabled{opacity:.5;cursor:wait}
.ok{color:#1b7a3a;font-weight:600}.warn{color:#b3261e;font-weight:600}.mute{color:#75808f}
.bad td{background:#fde8e6}
.files{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}
.file{border:1px solid #d9dde3;border-radius:6px;padding:8px;background:#fafbfc;display:flex;flex-direction:column;gap:6px;cursor:pointer}
.file img,.file .pdf{width:100%;height:300px;object-fit:cover;object-position:top;border-radius:4px;background:#e7eaef}
.file .pdf{display:flex;align-items:center;justify-content:center;font-size:34px;color:#8a93a0}
.file span{font-size:12px;word-break:break-all;direction:ltr;text-align:left}
.file:has(input:checked){border-color:#1d3557;background:#e8eefb;outline:2px solid #1d3557}
.split{display:flex;gap:16px;align-items:flex-start}
.split .src{flex:0 0 50%;min-width:0;position:sticky;top:8px;height:calc(100vh - 16px);overflow:auto;background:#fff;border:1px solid #c8cdd5;border-radius:6px}
.split .src img{width:100%;display:block;margin-bottom:6px;cursor:zoom-in}.split .src.zoom img{width:200%;max-width:none;cursor:zoom-out}
.split .src .tools{position:sticky;top:0;background:#eef1f5;padding:5px 10px;font-size:13px;border-bottom:1px solid #c8cdd5;z-index:2}
.split .src iframe{width:100%;height:calc(100vh - 60px);border:0}
.split .data{flex:1 1 50%;min-width:0}
@media(max-width:1000px){.split{flex-direction:column}.split .src{flex:none;width:100%;position:static;height:70vh}}
.form th{width:1%}.form td small{display:block;color:#75808f;margin-top:3px}
#busy{display:none;position:fixed;inset:0;background:rgba(244,245,247,.92);z-index:9;align-items:center;justify-content:center;flex-direction:column;gap:14px;font-size:18px}
#busy.on{display:flex}.spin{width:44px;height:44px;border:5px solid #c8d2e3;border-top-color:#1d3557;border-radius:50%;animation:sp 1s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}
</style></head><body><header><b>Retailomatics Invoice Scan</b><span>' . scan_h($title) . '</span>'
        . (empty($_SESSION['invoiceScanBatch']) ? '' : '<a style="margin-inline-start:24px" href="batch.php?b=' . scan_h($_SESSION['invoiceScanBatch']) . '">רשימת המסמכים</a>')
        . '<a href="../harmonizedFlow/start_harmonized_flow.php">התהליך הקודם (Harmonized Flow)</a></header><main' . ($wide ? ' class="wide"' : '') . '>';
    if (!empty($_SESSION['invoiceScanFlash'])) {   // one message from the step before (e.g. a document put aside)
        echo '<div class="card" style="border-color:#9aa5b4;background:#f0f2f5">' . scan_h($_SESSION['invoiceScanFlash']) . '</div>';
        unset($_SESSION['invoiceScanFlash']);
    }
}

/** The form that puts a document aside (discard.php): asks for the reason, then posts. '' when it can't be (finished / put aside). */
function scan_discard_button(array $work): string
{
    if (!empty($work['discarded']) || (!empty($work['approved']) && !is_file(SCAN_IN_DIR . '/' . $work['approved']['pdfFile']))) return '';
    return '<form method="post" action="discard.php" style="display:inline" onsubmit="const r = prompt(\'להוציא את המסמך? הוא לא ייקלט לקומקס.\\nהקבצים יעברו לתיקיית Discard של יום העיבוד ב-Z.\\n\\nסיבה (לא חובה):\', \'\'); if (r === null) return false; this.reason.value = r;">'
        . '<input type="hidden" name="id" value="' . scan_h($work['id']) . '"><input type="hidden" name="reason">'
        . '<button type="submit" class="second" style="border-color:#b3261e;color:#b3261e">🗑 הוצא (לא לקומקס)</button></form>';
}

function scan_page_end(): void
{
    echo '</main><div id="busy"><div class="spin"></div><div id="busyText">קורא את המסמך…</div></div></body></html>';
}

// ── a batch: the documents chosen together in index.php ─────────────────────
// InvoiceScan/work/batch_<id>.json = {id, shop, created, pages: {file: read | {error}}, docs: [work ids]}.
// Every file is read on its own; pages of one supplier and date are shown to the user to say
// whether they are one document or several (group.php); then each document gets its work file.

function scan_batch_path(string $id): string
{
    if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
        http_response_code(400);
        exit('Bad batch id');
    }
    return SCAN_WORK_DIR . "/batch_$id.json";
}

function scan_batch_load(string $id): array
{
    $batch = json_decode((string) @file_get_contents(scan_batch_path($id)), true);
    if (!is_array($batch)) {
        http_response_code(404);
        exit('Batch not found. <a href="index.php">index</a>');
    }
    return $batch;
}

function scan_batch_save(array $batch): void
{
    @mkdir(SCAN_WORK_DIR, 0777, true);
    scan_write_json(scan_batch_path($batch['id']), $batch);
}

/** Who issued a page, for grouping: the suppliers.json key, else the VAT id, else the printed name. Empty = unknown. */
function scan_issuer(array $data): string
{
    $match = InvoiceReader::matchSupplier($data);
    if ($match['supplier']) return 'key:' . $match['supplier']['supplierName'];
    if ($data['supplier_vat_id'] !== '') return 'vat:' . $data['supplier_vat_id'];
    $name = preg_replace('/[^\p{L}\p{N}]+/u', '', $data['supplier_name_printed']);
    return $name !== '' ? 'name:' . $name : '';
}

/**
 * The pages that may belong together — over the whole batch, so a day's photos should be read
 * in one batch: a document of several pages is only joined when all its pages are in it.
 * Photos only (a PDF is always a whole document). Pages are linked when:
 *  - the same issuer and date were read on them;
 *  - the same document number was read on them (also when one has no issuer / date);
 *  - a page without a header (the rest of a table: "continuation", or a page number above 1)
 *    follows a page in the batch order (the photos' order) — it is suggested with that page.
 * Groups of two or more pages: [[file, ...], ...], each in the batch order.
 */
function scan_batch_groups(array $batch): array
{
    $files = [];
    foreach ($batch['pages'] as $file => $read) {
        if (isset($read['error']) || strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf') continue;
        $files[] = $file;
    }
    $parent = array_combine($files, $files);
    $find = function ($x) use (&$parent, &$find) { return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x])); };
    $link = function ($x, $y) use (&$parent, $find) { $parent[$find($y)] = $find($x); };

    $byKey = [];
    foreach ($files as $i => $file) {
        $d = $batch['pages'][$file]['data'];
        $issuer = scan_issuer($d);
        if ($issuer !== '' && $d['doc_date'] !== '') $byKey['id:' . $issuer . '|' . $d['doc_date']][] = $file;
        $num = preg_replace('/\s+/', '', $d['doc_number']);
        if (strlen($num) >= 3) $byKey['no:' . $num][] = $file;
        $headerless = $issuer === '' || $d['doc_date'] === '';
        if ($i > 0 && $headerless && (!empty($d['continuation']) || ($d['page_number'] ?? 0) > 1)) $link($files[$i - 1], $file);
    }
    foreach ($byKey as $group) foreach (array_slice($group, 1) as $file) $link($group[0], $file);

    $groups = [];
    foreach ($files as $file) $groups[$find($file)][] = $file;
    return array_values(array_filter($groups, fn($g) => count($g) > 1));
}

/**
 * Where a page goes in its document, for sorting: the printed page number; else a page with a
 * header first (1.5 — after a printed page 1), a continuation page last.
 */
function scan_page_rank(array $d): float
{
    if (!empty($d['page_number'])) return (float) $d['page_number'];
    return scan_page_is_continuation($d) || !empty($d['continuation']) ? 99.0 : 1.5;
}

/** A page without a header of its own — it is the rest of a document begun on another page. */
function scan_page_is_continuation(array $d): bool
{
    return (scan_issuer($d) === '' || $d['doc_date'] === '') && (!empty($d['continuation']) || ($d['page_number'] ?? 0) > 1);
}

/**
 * The batch's documents, each with its work file. $assign: file => a label; files with the
 * same label are the pages of one document (in the order of the batch); a file without a
 * label is a document of its own.
 */
function scan_batch_build_docs(array $batch, array $assign, array $order = []): array
{
    $docs = [];
    foreach ($batch['pages'] as $file => $read) {
        if (isset($read['error'])) continue;
        $docs[isset($assign[$file]) ? 'L:' . $assign[$file] : 'F:' . $file][] = $file;
    }
    $batch['docs'] = [];
    foreach ($docs as $files) {
        if (count($files) > 1) {
            // the order the user set on the grouping screen ($order: file => page), else the suggestion
            $rank = array_map(fn($f) => isset($order[$f]) ? (float) $order[$f] : scan_page_rank($batch['pages'][$f]['data']), $files);
            $pos = array_keys($files);
            array_multisort($rank, $pos, $files);   // ties keep the batch order
        }
        $reads = array_map(fn($f) => $batch['pages'][$f], $files);
        $work = ['id' => bin2hex(random_bytes(6)), 'shop' => $batch['shop'], 'day' => scan_day_or_today($batch['day'] ?? ''),
            'files' => $files, 'subdir' => '', 'created' => date('c'),
            'batch' => $batch['id'], 'read' => count($reads) === 1 ? $reads[0] : InvoiceReader::merge($reads), 'read_at' => date('c')];
        scan_save($work);
        $batch['docs'][] = $work['id'];
    }
    scan_batch_save($batch);
    return $batch;
}

/** Where a batch continues: the first document not approved yet (photo next to its data), else the list. */
function scan_batch_next_url(array $batch): string
{
    foreach ($batch['docs'] as $docId) {
        $w = scan_load($docId);
        if (empty($w['approved']) && empty($w['discarded'])) return 'confirm.php?id=' . $docId;
    }
    return 'batch.php?b=' . $batch['id'];
}

/**
 * The processing day ("יום עיבוד", Y-m-d): the day the documents go into Comax, chosen on index.php
 * (a batch's 'day', copied to each document's work file, changeable on confirm.php). The archive
 * on Z is by it (harmonizedFlow/invoice_archive.php). Anything not a valid date → today.
 */
function scan_day_or_today(string $day): string
{
    $d = DateTime::createFromFormat('!Y-m-d', $day);
    return $d && $d->format('Y-m-d') === $day ? $day : date('Y-m-d');
}

/**
 * Batches whose documents were never built — the window was closed while reading, or right after:
 * [{id, shop, created, files, read, errors, finished}], newest first (index.php offers them again).
 */
function scan_open_batches(): array
{
    $out = [];
    foreach (glob(SCAN_WORK_DIR . '/batch_*.json') ?: [] as $path) {
        $b = json_decode((string) @file_get_contents($path), true);
        if (!is_array($b) || !empty($b['docs']) || empty($b['pages']) && empty($b['files'])) continue;
        $out[] = ['id' => $b['id'], 'shop' => $b['shop'], 'created' => $b['created'], 'day' => $b['day'] ?? '',
            'files' => count($b['files'] ?? $b['pages']), 'read' => count($b['pages']),
            'errors' => count(array_filter($b['pages'], fn($p) => isset($p['error']))),
            'finished' => !empty($b['finished']) || !isset($b['files'])];
    }
    usort($out, fn($a, $b) => strcmp($b['created'], $a['created']));
    return $out;
}

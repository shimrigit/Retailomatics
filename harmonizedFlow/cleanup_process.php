<?php
/**
 * Cleanup Process — the end of a document.
 *
 * Archives the document in the shop's folder on Z (invoice_archive.php):
 *   the PDF and OCRjson (moved), OCRsanity, PRICE-CHANGE and NEW-PRODUCTS (copied — the work
 *   copies stay in OCRsanity/sanity_files and commercialLayer/commercial_invoice_files).
 * When Z can't be reached they wait in ZpendingDir/ and the result says so (pendingDir /
 * pendingTotal); "העבר ל-Z" (z_pending.php) moves them later.
 *
 * GET clFile / pcFile / npFile — whichever the finishing screen knows; the others are found
 * by the CL file's name (PC / NP are named "<CL base>_PRICE-CHANGE_…" / "_NEW-PRODUCTS_…").
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start();
session_start();
require_once __DIR__ . '/invoice_archive.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

/** The newest "<base>_<kind>_*.xlsx" in $dir, or null. */
function cleanupLatest(string $dir, string $base, string $kind): ?string
{
    $best = null;
    foreach (glob($dir . glob_escape($base) . "_{$kind}_*.xlsx") ?: [] as $f) {
        if (!$best || filemtime($f) > filemtime($best)) $best = $f;
    }
    return $best ? basename($best) : null;
}

function glob_escape(string $s): string
{
    return preg_replace('/([*?\[])/', '[$1]', $s);
}

try {
    if (!isset($_SESSION['harmonizedFlow'])) {
        throw new Exception("No harmonized flow session found");
    }

    $flowData = $_SESSION['harmonizedFlow'];
    $archivedFiles = [];
    $errors = [];

    $preProcessDir = __DIR__ . '/../preProcessDir/';
    $ocrExtractedDir = __DIR__ . '/../AIocr/ocr_extracted/';
    $sanityDir = __DIR__ . '/../OCRsanity/sanity_files/';
    $clDir = __DIR__ . '/../commercialLayer/commercial_invoice_files/';

    // ── which files belong to this document ──────────────────────────────────
    $clean = fn($v) => ($v = basename((string) $v)) === '' ? null : $v;
    $pcFile = $clean($_GET['pcFile'] ?? '');
    $npFile = $clean($_GET['npFile'] ?? '');
    $clFile = $clean($_GET['clFile'] ?? '');
    if (!$clFile) {
        foreach ([$npFile => '_NEW-PRODUCTS_', $pcFile => '_PRICE-CHANGE_'] as $f => $kind) {
            if ($f && ($p = strpos($f, $kind)) !== false) { $clFile = substr($f, 0, $p) . '.xlsx'; break; }
        }
    }
    if (!$clFile) $clFile = $clean($_SESSION['clFileName'] ?? '');
    $clBase = $clFile ? preg_replace('/\.xlsx$/i', '', $clFile) : null;
    if ($clBase) {
        $pcFile = $pcFile ?: cleanupLatest($clDir, $clBase, 'PRICE-CHANGE');
        $npFile = $npFile ?: cleanupLatest($clDir, $clBase, 'NEW-PRODUCTS');
    }
    $sanityFile = $clean($flowData['ocrSanityFile'] ?? '');
    if (!$sanityFile && $clBase) $sanityFile = preg_replace('/_CL_\d+_\d+$/', '', $clBase) . '.xlsx';
    $pdfFile = $clean($flowData['pdfFile'] ?? '');
    $shop = basename((string) ($flowData['shopName'] ?? $_GET['shop'] ?? ''));
    // the processing day chosen in Invoice Scan (approve.php → workDay); today otherwise
    $dayTime = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($flowData['workDay'] ?? '')) ? strtotime($flowData['workDay'] . ' 12:00') : time();

    // ── the files of this document → the shop's archive on Z (or the waiting folder) ──
    $base = $pdfFile ? pathinfo($pdfFile, PATHINFO_FILENAME)
                     : ($sanityFile ? preg_replace('/^OCRsanity_|_\d{8}_\d{6}\.xlsx$/', '', $sanityFile) : 'document');
    // the PDF: in preProcessDir (a new document), or the copy "המשך מקובץ OCRsanity" made in sanity_files
    $pdfSrc = null;
    if ($pdfFile) {
        if (is_file($preProcessDir . $pdfFile)) $pdfSrc = $preProcessDir . $pdfFile;
        elseif (!empty($flowData['pdfPath']) && is_file($flowData['pdfPath'])) $pdfSrc = $flowData['pdfPath'];
        else $errors[] = "PDF file not found: $pdfFile";
    }
    $ocrJsonFile = $clean($flowData['ocrJsonFile'] ?? '');
    $jobs = [   // [kind, source, archive name, move?]
        ['PDF', $pdfSrc, "$base.pdf", true],
        ['OCRsanity', $sanityFile ? $sanityDir . $sanityFile : null, "$base - OCRsanity.xlsx", false],
        ['PRICE-CHANGE', $pcFile ? $clDir . $pcFile : null, "$base - שינויי מחיר.xlsx", false],
        ['NEW-PRODUCTS', $npFile ? $clDir . $npFile : null, "$base - מוצרים חדשים.xlsx", false],
        ['OCRjson', $ocrJsonFile && is_file($ocrExtractedDir . $ocrJsonFile) ? $ocrExtractedDir . $ocrJsonFile : null, "$base - OCRjson.json", true],
    ];
    $archiveDir = null;
    $pendingDir = null;
    if ($shop === '') {
        $errors[] = 'לא ידוע לאיזו חנות המסמך — לא נשמר בארכיון';
    } else {
        foreach ($jobs as [$kind, $src, $name, $move]) {
            if (!$src) continue;
            $r = invArchiveStore($shop, $src, $name, $move, $dayTime);
            if (isset($r['error'])) { $errors[] = $r['error']; continue; }
            if ($r['where'] === 'archive') $archiveDir = $r['dir']; else $pendingDir = $r['dir'];
            $archivedFiles[] = ['kind' => $kind, 'fileName' => $r['name'], 'source' => basename($src), 'where' => $r['where']];
        }
    }

    // ── what stays in the work folders ───────────────────────────────────────
    $stayingFiles = [];
    foreach (['cl' => $clFile, 'pc' => $pcFile, 'np' => $npFile] as $k => $f) {
        if ($f) $stayingFiles[$k] = ['fileName' => $f, 'location' => 'commercialLayer/commercial_invoice_files/'];
    }

    echo json_encode([
        'success' => true,
        'archiveDir' => $archiveDir ?? ($shop !== '' ? invArchiveDir($shop, $dayTime) : null),
        'pendingDir' => $pendingDir ? 'ZpendingDir/' . substr($pendingDir, strlen(INV_PENDING_DIR) + 1) : null,
        'pendingTotal' => count(invPendingList()),   // all the files waiting for Z, of any document
        'archivedFiles' => $archivedFiles,
        'stayingFiles' => $stayingFiles,
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

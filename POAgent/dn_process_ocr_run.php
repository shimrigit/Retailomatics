<?php
// BOU-only AJAX endpoint — the actual OCR call for dn_process_ocr.php's
// waiting page below. Split out for the exact same reason dn_capture.php's
// upload got split into dn_upload_photo.php/dn_ocr_process.php: a plain
// synchronous page with no feedback during a slow OCR call (confirmed for
// real — see the "Maximum execution time of 120 seconds exceeded" fatal
// errors logged Sept 13, from a bad connection) reads as "the page stopped
// responding," not "still working." Kept a separate request from the
// waiting page so a retry after a failure re-runs only the OCR call — the
// photo itself never moves/changes, so this is always safe to repeat.
session_start();
require_once __DIR__ . '/lib/POStore.php';
require_once __DIR__ . '/lib/DNStore.php';
require_once __DIR__ . '/lib/DNOcr.php';
require_once __DIR__ . '/lib/DNSanity.php';
require_once __DIR__ . '/lib/UserRoles.php';

// Extra headroom over the default 120s max_execution_time — this call has
// already been observed exceeding that on a bad connection (see above); this
// doesn't fix a slow connection, just gives a slow-but-real OCR call more
// room to actually finish instead of being killed mid-flight.
set_time_limit(240);

header('Content-Type: application/json; charset=utf-8');

function poagent_process_ocr_json(bool $ok, array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['poagent_generator_id'])) {
    http_response_code(401);
    poagent_process_ocr_json(false, ['error' => 'החיבור פג — רענן/י את הדף והתחבר/י מחדש.']);
}
if (poagent_is_fu($_SESSION['poagent_generator_id'])) {
    http_response_code(403);
    poagent_process_ocr_json(false, ['error' => 'פעולה זו זמינה רק למשתמשי משרד (BOU).']);
}

$poCoreName = $_POST['po_core_name'] ?? '';
if (!preg_match('/^[A-Za-z0-9_-]+$/', $poCoreName)) {
    http_response_code(400);
    poagent_process_ocr_json(false, ['error' => 'הזמנה לא תקינה.']);
}
$po = POStore::loadByCoreName($poCoreName);
if ($po === null) {
    http_response_code(404);
    poagent_process_ocr_json(false, ['error' => 'ההזמנה לא נמצאה.']);
}

// Same source of truth as dn_process_ocr.php (see its comment) — a pending
// photo's existence, not the PO's status string, which can drift.
$pending = DNStore::findPendingImage($poCoreName);
if ($pending === null) {
    http_response_code(409);
    poagent_process_ocr_json(false, ['error' => 'לא נמצאה תמונה ממתינה עבור הזמנה זו.']);
}

$ocrStartedAt = microtime(true);
try {
    $ocrRaw = DNOcr::extract($pending['image_path']);
} catch (Throwable $e) {
    http_response_code(502);
    poagent_process_ocr_json(false, ['error' => 'שלב ה-OCR נכשל: ' . $e->getMessage()]);
}
$ocrMs = (int) round((microtime(true) - $ocrStartedAt) * 1000);
$sanity = DNSanity::check($ocrRaw);

$_SESSION['poagent_dn_draft'] = [
    'po_core_name'      => $poCoreName,
    'dn_core_name'      => $pending['dn_core_name'],
    'image_filename'    => $pending['image_filename'],
    'supplier_name_ocr' => $ocrRaw['supplier_name'],
    'dn_number_ocr'     => $ocrRaw['dn_number'],
    'dn_date_ocr'       => $ocrRaw['dn_date'],
    'dn_total'          => $sanity['dn_total'],
    'items'             => $sanity['items'],
];

poagent_process_ocr_json(true, ['redirect' => 'dn_review.php', 'ocr_ms' => $ocrMs]);

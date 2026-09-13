<?php
// DN flow, mobile — AJAX stage 1 of 2: save the uploaded photo only (fast;
// no OCR here). Called by dn_capture.php via fetch() instead of a plain
// form POST, specifically so the capture screen can show "מעלה תמונה…" and
// then "שולח ל-OCR…" as two honest, sequential stages instead of one long
// silent wait while a normal form-submit navigation sits blank until the
// OCR call (the actually slow part) finishes.
//
// Desktop's dn_import.php/dn_browse.php flow is untouched — this endpoint
// only ever handles a mobile $_FILES['photo'] upload. dn_ocr_process.php is
// stage 2 (the OCR call itself).
session_start();
require_once __DIR__ . '/lib/POStore.php';
require_once __DIR__ . '/lib/DNStore.php';
require_once __DIR__ . '/lib/UserRoles.php';

header('Content-Type: application/json; charset=utf-8');

function poagent_dn_upload_json(bool $ok, array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// Not poagent_require_generator() here — that redirects, which just
// confuses a fetch() caller. Answer with plain JSON instead.
if (empty($_SESSION['poagent_generator_id'])) {
    http_response_code(401);
    poagent_dn_upload_json(false, ['error' => 'החיבור פג — רענן/י את הדף והתחבר/י מחדש.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['po_core_name'])) {
    http_response_code(400);
    poagent_dn_upload_json(false, ['error' => 'בקשה לא תקינה.']);
}

$poCoreName = $_POST['po_core_name'];
if (!preg_match('/^[A-Za-z0-9_-]+$/', $poCoreName)) {
    http_response_code(400);
    poagent_dn_upload_json(false, ['error' => 'הזמנה לא תקינה.']);
}
if (POStore::loadByCoreName($poCoreName) === null) {
    http_response_code(404);
    poagent_dn_upload_json(false, ['error' => 'ההזמנה לא נמצאה.']);
}

if (!isset($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    http_response_code(400);
    poagent_dn_upload_json(false, ['error' => 'לא התקבלה תמונה.']);
}

// Timed separately from the fetch() round trip the client measures: PHP only
// starts running THIS script after the whole upload has already arrived (the
// webserver receives + buffers it first) — so this number is purely the
// server-side disk write, never network transfer. The client-side duration
// minus this is (almost entirely) how long the upload itself took over the
// phone's connection — see dn_capture.php's status display.
$serverStartedAt = microtime(true);
try {
    $imported = DNStore::importUploadedImage($poCoreName, $_FILES['photo']);
} catch (Throwable $e) {
    http_response_code(422);
    poagent_dn_upload_json(false, ['error' => $e->getMessage()]);
}
$serverMs = (int) round((microtime(true) - $serverStartedAt) * 1000);

// FU (field user, see lib/UserRoles.php): stop right here, same as
// desktop's dn_import.php — the photo is saved, but OCR is reserved for a
// BOU to run later via dn_process_ocr.php. No stage 2 for this role, so no
// need to stash a "pending" session record either.
if (poagent_is_fu($_SESSION['poagent_generator_id'])) {
    POStore::setStatus($poCoreName, 'preocr');
    poagent_dn_upload_json(true, ['done' => true, 'image_filename' => $imported['image_filename']]);
}

// Lightweight pre-OCR record — dn_ocr_process.php (stage 2) picks this up
// next. Deliberately separate from $_SESSION['poagent_dn_draft'], which only
// exists once OCR has actually run (dn_review.php's precondition) — kept
// around (not cleared) if stage 2 fails, so a retry there re-runs just the
// OCR call, not the upload.
$_SESSION['poagent_dn_pending'] = [
    'po_core_name'    => $poCoreName,
    'dn_core_name'    => $imported['dn_core_name'],
    'image_path'      => $imported['image_path'],
    'image_filename'  => $imported['image_filename'],
    'bytes'           => (int) ($_FILES['photo']['size'] ?? 0),
    'upload_server_ms' => $serverMs, // carried through to the dn_timing_*.log entry stage 2 writes
];

poagent_dn_upload_json(true, [
    'image_filename' => $imported['image_filename'],
    'server_ms'       => $serverMs,
    'bytes'           => (int) ($_FILES['photo']['size'] ?? 0),
]);

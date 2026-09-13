<?php
// DN flow, mobile — AJAX stage 2 of 2: run OCR + sanity check on the photo
// dn_upload_photo.php (stage 1) already saved, then stash the same draft
// shape dn_review.php has always expected (identical to what dn_import.php
// builds for desktop). Kept as its own request specifically so the capture
// screen can report "שולח ל-OCR…" as a distinct, honest stage from the
// (fast) upload — see dn_capture.php.
session_start();
require_once __DIR__ . '/lib/DNOcr.php';
require_once __DIR__ . '/lib/DNSanity.php';
require_once __DIR__ . '/lib/NetworkMode.php';

header('Content-Type: application/json; charset=utf-8');

function poagent_dn_ocr_json(bool $ok, array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * One JSON line per successful OCR call — plain historical record of how
 * long the AI call itself actually took (server-side, so independent of any
 * one phone's connection quality), to look back at trends rather than rely
 * on reading it off a single phone screen each time.
 */
function poagent_dn_timing_log(array $entry): void
{
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(
        $dir . '/dn_timing_' . date('Y-m-d') . '.log',
        json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

if (empty($_SESSION['poagent_generator_id'])) {
    http_response_code(401);
    poagent_dn_ocr_json(false, ['error' => 'החיבור פג — רענן/י את הדף והתחבר/י מחדש.']);
}

$pending = $_SESSION['poagent_dn_pending'] ?? null;
if (!$pending || empty($pending['image_path']) || !is_file($pending['image_path'])) {
    http_response_code(409);
    poagent_dn_ocr_json(false, ['error' => 'לא נמצאה תמונה ממתינה — יש להעלות תמונה מחדש.']);
}

// Timed around just the OpenAI call itself — this is (almost) the entire
// duration of the client-measured stage 2 fetch() too, since the request
// carries no file body this time; a slow stage 2 is this number, not the
// phone's connection. See dn_capture.php's status display.
$ocrStartedAt = microtime(true);
try {
    $ocrRaw = DNOcr::extract($pending['image_path']);
} catch (Throwable $e) {
    // The photo itself is already safely saved (stage 1 succeeded, and
    // $_SESSION['poagent_dn_pending'] is left in place) — only OCR failed,
    // so the caller can retry just this stage 2 call, not the whole upload.
    http_response_code(502);
    poagent_dn_ocr_json(false, ['error' => 'שלב ה-OCR נכשל: ' . $e->getMessage()]);
}
$ocrMs = (int) round((microtime(true) - $ocrStartedAt) * 1000);
$sanity = DNSanity::check($ocrRaw);

// upload_client_ms is the one number only the browser can measure (real
// wall-clock time for the whole stage-1 fetch, network included) — sent
// along by dn_capture.php purely so it ends up in this same log line
// alongside everything the server already knows about that same delivery.
$mode = poagent_network_mode_get();
poagent_dn_timing_log([
    'ts'                => date('c'),
    'mode'              => $mode['mode'] === 'lan' ? 'lan:' . $mode['value'] : 'ngrok',
    'dn_core_name'      => $pending['dn_core_name'],
    'image_filename'    => $pending['image_filename'],
    'bytes'             => $pending['bytes'] ?? null,
    'upload_server_ms'  => $pending['upload_server_ms'] ?? null,
    'upload_client_ms'  => isset($_POST['upload_client_ms']) ? (int) $_POST['upload_client_ms'] : null,
    'ocr_ms'            => $ocrMs,
    'item_count'        => count($sanity['items'] ?? []),
]);

$_SESSION['poagent_dn_draft'] = [
    'po_core_name'      => $pending['po_core_name'],
    'dn_core_name'      => $pending['dn_core_name'],
    'image_filename'    => $pending['image_filename'],
    'supplier_name_ocr' => $ocrRaw['supplier_name'],
    'dn_number_ocr'     => $ocrRaw['dn_number'],
    'dn_date_ocr'       => $ocrRaw['dn_date'],
    'dn_total'          => $sanity['dn_total'], // nullable — see DNOcr::extract()
    'items'             => $sanity['items'],
];
unset($_SESSION['poagent_dn_pending']);

poagent_dn_ocr_json(true, ['redirect' => 'dn_review.php', 'ocr_ms' => $ocrMs]);

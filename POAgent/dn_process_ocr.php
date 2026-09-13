<?php
// BOU-only (see lib/UserRoles.php) — picks up a PO's "preocr" pending photo
// (an FU uploaded it via dn_import.php/dn_upload_photo.php, which stopped
// right after saving the image) and actually runs OCR + sanity on it for
// the first time. From here on this is the EXACT SAME tail as the normal
// desktop/mobile flow — stash into $_SESSION['poagent_dn_draft'] and hand
// off to dn_review.php, which doesn't know or care that this photo was
// uploaded by someone else, possibly a while ago. dn_confirm.php's
// DNPipeline::finalizeDelivery() then resolves the PO out of "preocr" into
// prcv/closed exactly like any other delivery.
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/POStore.php';
require_once __DIR__ . '/lib/DNStore.php';
require_once __DIR__ . '/lib/DNOcr.php';
require_once __DIR__ . '/lib/DNSanity.php';
require_once __DIR__ . '/lib/UserRoles.php';
$generatorId = poagent_require_generator();

if (poagent_is_fu($generatorId)) {
    // Not a role an FU is allowed to trigger — bounce quietly rather than
    // erroring; there's nothing sensitive to leak, just an action to deny.
    header('Location: po_list.php');
    exit;
}

$poCoreName = $_GET['po_core_name'] ?? '';
if (!preg_match('/^[A-Za-z0-9_-]+$/', $poCoreName)) {
    header('Location: po_list.php');
    exit;
}
$po = POStore::loadByCoreName($poCoreName);
if ($po === null || ($po['status'] ?? '') !== 'preocr') {
    header('Location: po_list.php');
    exit;
}

$pending = DNStore::findPendingImage($poCoreName);
if ($pending === null) {
    // Shouldn't happen (status says preocr but no pending image left) —
    // nothing to process; send back rather than error.
    header('Location: po_list.php');
    exit;
}

function poagent_process_ocr_error(string $message, string $poCoreName): void
{
    poagent_render_head('POAgent – שגיאה בעיבוד OCR');
    ?>
    <h2>⚠️ שגיאה בעיבוד OCR</h2>
    <p class="muted"><?= htmlspecialchars($message) ?></p>
    <a class="btn secondary" href="po_list.php">חזרה לרשימת ההזמנות</a>
    <?php
    poagent_render_foot();
    exit;
}

try {
    $ocrRaw = DNOcr::extract($pending['image_path']);
} catch (Throwable $e) {
    poagent_process_ocr_error('שלב ה-OCR נכשל: ' . $e->getMessage(), $poCoreName);
}
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

header('Location: dn_review.php');
exit;

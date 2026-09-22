<?php
// Deletes a PO's pending, not-yet-OCR'd DN photo (see
// DNStore::findPendingImage()) and reverts the PO's status to whatever its
// finalized VS history alone implies (DiffEngine::statusFromHistory() —
// 'open' if nothing has ever been received across any delivery, or
// whatever prcv/closed state prior FINALIZED deliveries already
// established — never a blind reset to 'open', which would silently lose a
// prior delivery's progress if this was a second pending delivery on an
// already-prcv PO).
//
// Available to both FU and BOU — deleting an unprocessed photo doesn't
// touch any OCR'd/finalized data, so this doesn't need the office-only gate
// dn_process_ocr.php has. This is how po_view.php's "🗑️ מחק ותעלה מחדש"
// button (shown while a delivery is pending, replacing the normal upload
// button — a PO can only ever have one pending delivery at a time, see
// dn_select_po.php) lets someone fix a bad photo without waiting on a BOU.
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/POStore.php';
require_once __DIR__ . '/lib/DNStore.php';
require_once __DIR__ . '/lib/VSStore.php';
require_once __DIR__ . '/lib/DiffEngine.php';
$generatorId = poagent_require_generator();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['po_core_name'])) {
    header('Location: po_list.php');
    exit;
}
$poCoreName = $_POST['po_core_name'];
if (!preg_match('/^[A-Za-z0-9_-]+$/', $poCoreName)) {
    header('Location: po_list.php');
    exit;
}
$po = POStore::loadByCoreName($poCoreName);
if ($po === null) {
    header('Location: po_list.php');
    exit;
}

$pending = DNStore::findPendingImage($poCoreName);
if ($pending !== null) {
    @unlink($pending['image_path']);
    $allVs = VSStore::listForPo($poCoreName);
    $newStatus = DiffEngine::statusFromHistory($po, $allVs);
    if ($newStatus !== $po['status']) {
        POStore::setStatus($poCoreName, $newStatus);
    }
}

header('Location: po_view.php?core_name=' . urlencode($poCoreName));
exit;

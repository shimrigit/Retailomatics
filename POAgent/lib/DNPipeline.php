<?php
// DNPipeline.php — shared tail of the DN flow: finalize the (reviewed/
// corrected) DN record, run the Diff Engine, write the VS, and apply the
// resulting PO status transition. Factored out of dn_import.php's original
// single-shot version so there's exactly one implementation of "finalize →
// diff → VS → status", matching this codebase's "one code path per state
// transition" convention (see POStore::setStatus()).

require_once __DIR__ . '/DNStore.php';
require_once __DIR__ . '/VSStore.php';
require_once __DIR__ . '/DiffEngine.php';
require_once __DIR__ . '/POStore.php';

class DNPipeline
{
    /**
     * $po: PO record. $dnRecord: finalized-shape DN record — items already
     * numeric-coerced (DNSanity::check() shape, or dn_confirm.php's
     * re-validated equivalent after human review).
     * Returns ['dn'=>$dnRecord, 'vs'=>$vs, 'old_status'=>string, 'new_status'=>string].
     */
    public static function finalizeDelivery(array $po, array $dnRecord): array
    {
        DNStore::finalize($dnRecord['dn_core_name'], $dnRecord);

        $poCoreName = $po['core_name'];
        $priorVs = VSStore::listForPo($poCoreName);
        $deliveryIndex = VSStore::nextDeliveryIndex($poCoreName);
        $vs = DiffEngine::compare($po, $dnRecord, $deliveryIndex, $priorVs);
        VSStore::write($poCoreName, $deliveryIndex, $vs);

        // Status transition off the cumulative remaining-quantity picture
        // across ALL of this PO's VS records to date (including this one) —
        // DiffEngine::statusFromHistory() (shared with dn_delete_pending.php,
        // which needs the identical computation to revert a discarded
        // "preocr" delivery to the right status, not just a hardcoded guess).
        // Always resolves to a real status (open/prcv/closed) even from
        // "preocr", which was never itself a real fulfillment status.
        $allVsForPo = VSStore::listForPo($poCoreName);
        $newStatus = DiffEngine::statusFromHistory($po, $allVsForPo);
        if ($newStatus !== $po['status']) {
            POStore::setStatus($poCoreName, $newStatus);
        }

        return [
            'po'         => $po, // original record — unique_id/supplier_id/core_name don't
                                  // change; status display uses old_status/new_status below
            'dn'         => $dnRecord,
            'vs'         => $vs,
            'old_status' => $po['status'],
            'new_status' => $newStatus,
        ];
    }
}

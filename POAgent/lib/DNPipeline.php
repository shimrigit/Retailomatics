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
        // across ALL of this PO's VS records to date (including this one).
        $allVsForPo = VSStore::listForPo($poCoreName);
        $receivedTotal = [];
        foreach ($allVsForPo as $v) {
            foreach ($v['line_items'] ?? [] as $line) {
                $b = $line['barcode'] ?? '';
                $receivedTotal[$b] = ($receivedTotal[$b] ?? 0) + (int) ($line['dn_qty'] ?? 0);
            }
        }
        $anyReceived = false;
        $allFulfilled = true;
        foreach ($po['items'] ?? [] as $item) {
            $received = $receivedTotal[$item['barcode']] ?? 0;
            if ($received > 0) {
                $anyReceived = true;
            }
            if ($received < (int) $item['qty']) {
                $allFulfilled = false;
            }
        }
        // If nothing on the PO was actually received, leave status
        // unchanged — a VS was generated, but no PO progress happened.
        // Exception: 'preocr' (an FU-uploaded photo awaiting BOU processing,
        // see UserRoles.php) is never a real fulfillment status to fall back
        // to — it must always resolve to something real once processed,
        // whether or not this particular delivery advanced anything.
        $newStatus = $po['status'] === 'preocr' ? 'open' : $po['status'];
        if ($anyReceived) {
            $newStatus = $allFulfilled ? 'closed' : 'prcv';
        }
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

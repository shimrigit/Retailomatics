<?php
// DiffEngine.php — Stage 4 (spec §9 step 6, §4.4/§5): fused quantity+price
// comparison, cumulative across a PO's delivery history. ALWAYS produces a
// VS body — no skip-if-matched branch (spec §4.4/§8.8). The caller always
// calls compare() and always writes the result via VSStore::write(),
// even when every diff comes out zero.

class DiffEngine
{
    /**
     * $po: PO record (POStore shape).
     * $finalizedDn: DN record (DNStore::finalize() shape) — items already
     *   numeric-coerced by DNSanity::check().
     * $priorVs: VSStore::listForPo($po['core_name']) taken BEFORE this
     *   delivery's VS is written — every VS already on record for this PO,
     *   used for the cumulative remaining-quantity math (spec §5).
     */
    public static function compare(array $po, array $finalizedDn, int $deliveryIndex, array $priorVs): array
    {
        // PO items indexed by barcode.
        $poItems = [];
        foreach ($po['items'] ?? [] as $item) {
            $poItems[$item['barcode']] = $item;
        }

        // Cumulative qty already received per barcode, from every prior VS's line_items.
        $receivedSoFar = [];
        foreach ($priorVs as $vs) {
            foreach ($vs['line_items'] ?? [] as $line) {
                $barcode = $line['barcode'] ?? '';
                $receivedSoFar[$barcode] = ($receivedSoFar[$barcode] ?? 0) + (int) ($line['dn_qty'] ?? 0);
            }
        }

        $lineItems = [];
        $unmatched = [];
        $coveredBarcodes = []; // PO barcodes this DN actually mentions (i.e. present in the DN's own items)
        $dnComputedTotalAgorot = 0; // sum of ALL DN rows (matched + unmatched) — what the
                                    // document's own printed total is supposed to add up to

        foreach ($finalizedDn['items'] ?? [] as $dnItem) {
            $barcode = (string) ($dnItem['barcode'] ?? '');
            $dnQty = (int) round((float) ($dnItem['qty'] ?? 0));
            $dnPriceAgorot = (int) round(((float) ($dnItem['unit_price'] ?? 0)) * 100);
            $dnComputedTotalAgorot += $dnQty * $dnPriceAgorot;

            if (isset($poItems[$barcode])) {
                $poItem = $poItems[$barcode];
                $poQtyTotal = (int) ($poItem['qty'] ?? 0);
                $poPriceAgorot = (int) ($poItem['unit_price_agorot'] ?? 0);
                $alreadyReceived = $receivedSoFar[$barcode] ?? 0;
                $remainingBefore = $poQtyTotal - $alreadyReceived;

                $qtyDiff = $dnQty - $remainingBefore;
                $priceDiff = $dnPriceAgorot - $poPriceAgorot;

                $coveredBarcodes[$barcode] = true;
                $lineItems[] = [
                    'barcode'                 => $barcode,
                    'po_qty_remaining_before' => $remainingBefore,
                    'dn_qty'                  => $dnQty,
                    'qty_diff'                => $qtyDiff,
                    'qty_flagged'             => $qtyDiff !== 0,
                    'po_price_agorot'         => $poPriceAgorot,
                    'dn_price_agorot'         => $dnPriceAgorot,
                    'price_diff_agorot'       => $priceDiff,
                    'price_flagged'           => $priceDiff !== 0,
                    'not_delivered'           => false,
                ];
            } else {
                $unmatched[] = [
                    'barcode' => $barcode,
                    'dn_qty'  => $dnQty,
                    'note'    => 'לא ברשימת ההזמנה — נמסר שלא הוזמן',
                ];
            }
        }

        // PO items still owed (remaining > 0 going into this delivery) that
        // this DN doesn't mention AT ALL — added as explicit zero-delivery
        // line items rather than left silently absent. An under-delivery
        // must be exactly as visible as an over-delivery (no tolerance,
        // no gap hidden — spec §4.4). Skipped when remaining is already 0
        // (fully settled by a PRIOR delivery) — that's not this delivery's
        // business to re-report every time.
        foreach ($poItems as $barcode => $poItem) {
            // PHP silently casts purely-numeric array keys to int — re-cast
            // back to string so this loop's 'barcode' field always matches
            // the type the DN-item loop above emits (explicit (string) cast
            // there), not just its value.
            $barcode = (string) $barcode;
            if (isset($coveredBarcodes[$barcode])) {
                continue;
            }
            $poQtyTotal = (int) ($poItem['qty'] ?? 0);
            $poPriceAgorot = (int) ($poItem['unit_price_agorot'] ?? 0);
            $alreadyReceived = $receivedSoFar[$barcode] ?? 0;
            $remainingBefore = $poQtyTotal - $alreadyReceived;

            if ($remainingBefore <= 0) {
                continue;
            }

            $lineItems[] = [
                'barcode'                 => $barcode,
                'po_qty_remaining_before' => $remainingBefore,
                'dn_qty'                  => 0,
                'qty_diff'                => -$remainingBefore,
                'qty_flagged'             => true,
                'po_price_agorot'         => $poPriceAgorot,
                'dn_price_agorot'         => $poPriceAgorot, // nothing delivered — no DN price to compare against
                'price_diff_agorot'       => 0,
                'price_flagged'           => false,
                'not_delivered'           => true,
            ];
        }

        // Total verification (explicit request): does the DN's own declared
        // total agree with (a) what this delivery was expected to be worth —
        // the remaining PO value for every item still owed, whether this DN
        // addressed it or not (that's exactly what the "not delivered"
        // entries above contribute here) — and (b) the DN's own row-by-row
        // sum (which only reflects what was actually itemized as delivered).
        // An unordered item still isn't counted into "expected" — that
        // divergence is exactly what this check exists to surface, not hide.
        $poExpectedTotalAgorot = array_sum(array_map(
            fn($l) => $l['po_qty_remaining_before'] * $l['po_price_agorot'],
            $lineItems
        ));
        $dnDeclaredTotalRaw = $finalizedDn['dn_total'] ?? null;
        $dnDeclaredTotalAgorot = $dnDeclaredTotalRaw !== null ? (int) round($dnDeclaredTotalRaw * 100) : null;

        $poVsDeclaredDiff = $dnDeclaredTotalAgorot !== null ? $dnDeclaredTotalAgorot - $poExpectedTotalAgorot : null;
        $declaredVsComputedDiff = $dnDeclaredTotalAgorot !== null ? $dnDeclaredTotalAgorot - $dnComputedTotalAgorot : null;

        $totalCheck = [
            'po_expected_total_agorot'          => $poExpectedTotalAgorot,
            'dn_declared_total_agorot'           => $dnDeclaredTotalAgorot, // null = no total visible on the document
            'dn_computed_total_agorot'            => $dnComputedTotalAgorot,
            'po_vs_declared_diff_agorot'          => $poVsDeclaredDiff,
            'po_vs_declared_flagged'              => $poVsDeclaredDiff !== null && $poVsDeclaredDiff !== 0,
            'declared_vs_computed_diff_agorot'    => $declaredVsComputedDiff,
            'declared_vs_computed_flagged'        => $declaredVsComputedDiff !== null && $declaredVsComputedDiff !== 0,
        ];

        $hasVariance = $unmatched !== []
            || $totalCheck['po_vs_declared_flagged']
            || $totalCheck['declared_vs_computed_flagged']
            || array_reduce(
                $lineItems,
                fn($carry, $l) => $carry || $l['qty_flagged'] || $l['price_flagged'],
                false
            );

        return [
            'po_core_name'       => $po['core_name'],
            'delivery_index'     => $deliveryIndex,
            'dn_reference'       => $finalizedDn['dn_core_name'] ?? '',
            'generated_at'       => date('c'),
            'status'             => $hasVariance ? 'variance' : 'matched',
            'line_items'         => $lineItems,
            'unmatched_dn_items' => $unmatched,
            'total_check'        => $totalCheck,
        ];
    }

    /**
     * Cumulative status implied purely by a PO's existing VS history
     * (ignoring any transient wrapper status like "preocr" — see
     * lib/UserRoles.php) — 'open' (nothing received yet across any
     * delivery), 'prcv' (some but not all), 'closed' (everything owed
     * received). Shared by DNPipeline::finalizeDelivery()'s status
     * transition and dn_delete_pending.php (what status a PO reverts to
     * once a pending, not-yet-processed delivery is discarded — must
     * reflect any PRIOR finalized deliveries, not just default to 'open'
     * unconditionally).
     */
    public static function statusFromHistory(array $po, array $allVs): string
    {
        $receivedTotal = [];
        foreach ($allVs as $v) {
            foreach ($v['line_items'] ?? [] as $line) {
                $barcode = (string) ($line['barcode'] ?? '');
                $receivedTotal[$barcode] = ($receivedTotal[$barcode] ?? 0) + (int) ($line['dn_qty'] ?? 0);
            }
        }
        $anyReceived = false;
        $allFulfilled = true;
        foreach ($po['items'] ?? [] as $item) {
            $received = $receivedTotal[(string) $item['barcode']] ?? 0;
            if ($received > 0) {
                $anyReceived = true;
            }
            if ($received < (int) $item['qty']) {
                $allFulfilled = false;
            }
        }
        if (!$anyReceived) {
            return 'open';
        }
        return $allFulfilled ? 'closed' : 'prcv';
    }

    /**
     * Plain-language variance summary, rolled up across ALL of a PO's VS
     * records passed in (not just one delivery) — explicit request: the raw
     * diff tables alone weren't "clear enough." Cumulative by design: the
     * caller (po_view.php) calls this once per delivery row with an
     * increasingly complete slice of VS records, so each delivery's summary
     * reflects everything received up to and including that point — same
     * "remaining" philosophy as compare() itself, just rolled up to
     * per-barcode findings instead of per-delivery line items.
     *
     * Five finding types: an item delivered but never ordered
     * ("unordered_item"), an ordered item never delivered at all across any
     * of the given VS records ("missing_item"), an ordered item delivered
     * in the wrong quantity ("qty_mismatch" — mutually exclusive with
     * missing_item: zero received is "missing", not "0 of N"), and a price
     * mismatch ("price_mismatch", one row per distinct wrong price actually
     * seen — independent of the qty findings, since a product can have
     * either, both, or neither). Returns
     * ['fully_matched' => bool, 'findings' => [ [...], ... ]].
     */
    public static function summarizeForPo(array $po, array $allVs): array
    {
        $poItems = [];
        foreach ($po['items'] ?? [] as $item) {
            $poItems[(string) $item['barcode']] = $item;
        }

        $receivedQty = [];
        $priceMismatches = []; // barcode => [dn_price_agorot => true, ...], dedup by distinct wrong price
        $unmatchedBarcodes = [];

        foreach ($allVs as $vs) {
            foreach ($vs['line_items'] ?? [] as $line) {
                $barcode = (string) ($line['barcode'] ?? '');
                if (empty($line['not_delivered'])) {
                    $receivedQty[$barcode] = ($receivedQty[$barcode] ?? 0) + (int) ($line['dn_qty'] ?? 0);
                }
                if (!empty($line['price_flagged'])) {
                    $priceMismatches[$barcode][(int) $line['dn_price_agorot']] = true;
                }
            }
            foreach ($vs['unmatched_dn_items'] ?? [] as $u) {
                $unmatchedBarcodes[(string) ($u['barcode'] ?? '')] = true;
            }
        }

        $findings = [];

        foreach ($poItems as $barcode => $poItem) {
            // Same PHP numeric-array-key coercion as compare() hits — see
            // the comment there. Re-cast so 'barcode' in the findings below
            // always comes out a string, not silently an int.
            $barcode = (string) $barcode;
            $received = $receivedQty[$barcode] ?? 0;
            $poQty = (int) ($poItem['qty'] ?? 0);

            if ($received === 0) {
                $findings[] = ['type' => 'missing_item', 'barcode' => $barcode];
            } elseif ($received !== $poQty) {
                $findings[] = [
                    'type'         => 'qty_mismatch',
                    'barcode'      => $barcode,
                    'po_qty'       => $poQty,
                    'received_qty' => $received,
                ];
            }

            foreach (array_keys($priceMismatches[$barcode] ?? []) as $dnPriceAgorot) {
                $findings[] = [
                    'type'              => 'price_mismatch',
                    'barcode'           => $barcode,
                    'po_price_agorot'   => (int) ($poItem['unit_price_agorot'] ?? 0),
                    'dn_price_agorot'   => $dnPriceAgorot,
                ];
            }
        }

        foreach (array_keys($unmatchedBarcodes) as $barcode) {
            $findings[] = ['type' => 'unordered_item', 'barcode' => (string) $barcode];
        }

        return [
            'fully_matched' => empty($findings),
            'findings'      => $findings,
        ];
    }
}

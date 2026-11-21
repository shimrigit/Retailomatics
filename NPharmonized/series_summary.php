<?php
// series_summary.php — the series pricing decisions as a WhatsApp message for the shop owner:
// each product priced on the screen with its price and why ("כמו <a product of the series>",
// or a price set by hand), then the products left without a price.

/**
 * $priced: [{name, price, source}] (source = "כמו …" from the chosen option, '' = typed by hand);
 * $waiting: [{barcode, name}]. '' when there is nothing to tell.
 */
function npSeriesSummary(string $shop, string $date, array $priced, array $waiting): string
{
    if (!$priced && !$waiting) return '';
    $d = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : $date;
    $lines = ["מחירים למוצרים שסומנו \"סדרה\" — $shop $d"];
    foreach ($priced as $p) {
        $lines[] = '• ' . $p['name'] . ' — ' . number_format((float) $p['price'], 2) . ' — '
            . (trim((string) ($p['source'] ?? '')) !== '' ? trim($p['source']) : 'מחיר שנקבע ידנית');
    }
    if ($waiting) {
        $lines[] = '';
        $lines[] = 'בלי מחיר — אין סדרה מתאימה, צריך לקבוע מחיר:';
        foreach ($waiting as $w) $lines[] = '• ' . $w['name'] . ' (' . $w['barcode'] . ')';
    }
    return implode("\n", $lines);
}

/** The message box with a copy button (one per page). */
function npSeriesSummaryBox(string $text): void
{
    if ($text === '') return;
    $h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    echo '<div style="background:#fff;border:2px solid #2575a8;border-radius:8px;padding:18px 24px;max-width:1100px;margin-bottom:30px">'
        . '<h4 style="margin:0 0 10px;color:#2575a8;font-size:22px">הודעה לבעל החנות — ההחלטות על המחירים</h4>'
        . '<div style="white-space:pre-line;font-size:18px;line-height:1.6">' . $h($text) . '</div>'
        . '<button type="button" id="copySummary" style="margin-top:14px;padding:10px 26px;background:#2575a8;color:#fff;border:none;border-radius:7px;font-size:19px;cursor:pointer">📋 העתק להודעת WhatsApp</button></div>'
        . '<script>document.getElementById("copySummary").addEventListener("click", function () { var b = this; navigator.clipboard.writeText('
        . json_encode($text, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ').then(function () { b.textContent = "✔ הועתק!"; setTimeout(function () { b.textContent = "📋 העתק להודעת WhatsApp"; }, 2500); }); });</script>';
}

// ── Notes in the final file (_DCL.xlsx), column H — the Comax import reads A–G only ──────
const NP_NOTE_SERIES  = 'שונה על פי מחיר סדרה';
const NP_NOTE_DROPPED = 'מוצרים שירדו בגלל חוסר מחיר : ';

/** The last row with a barcode (column A). */
function npLastDataRow($sheet): int
{
    for ($r = $sheet->getHighestRow(); $r >= 2; $r--) {
        if (trim((string) $sheet->getCell('A' . $r)->getValue()) !== '') return $r;
    }
    return 1;
}

/**
 * Below the last product: the products that left the file for lack of a price, in column H —
 * "מוצרים שירדו בגלל חוסר מחיר : <name> <barcode>" on the first row, one product per row.
 * Products already listed there (an earlier save) are kept; the list is written again.
 * $dropped: [{name, barcode}].
 */
function npWriteDroppedFooter($sheet, array $dropped): void
{
    $last = npLastDataRow($sheet);
    $lines = [];
    for ($r = $last + 1; $r <= $sheet->getHighestRow(); $r++) {
        $v = trim((string) $sheet->getCell('H' . $r)->getValue());
        if ($v !== '') $lines[] = str_starts_with($v, trim(NP_NOTE_DROPPED)) ? trim(mb_substr($v, mb_strlen(trim(NP_NOTE_DROPPED)))) : $v;
        $sheet->setCellValue('H' . $r, null);
    }
    foreach ($dropped as $d) {
        $line = trim($d['name'] . ' ' . $d['barcode']);
        if (!in_array($line, $lines, true)) $lines[] = $line;
    }
    foreach ($lines as $i => $line) {
        $sheet->setCellValue('H' . ($last + 1 + $i), ($i === 0 ? NP_NOTE_DROPPED : '') . $line);
    }
}

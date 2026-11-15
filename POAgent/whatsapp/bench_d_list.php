<?php
/**
 * bench_d_list.php — WhatsApp ordering, method D: the whole order typed as a
 * free list. Loaded by bench_common.php; a phone set to method D in users.php.
 *
 * It is method A's conversation (bench_a_chat.php: the first question, the
 * supplier, "order again", the cart, the summary, the PO) with one difference —
 * once the supplier is chosen the bot asks for the order itself:
 *
 *     בצל 5
 *     מלפפון 12
 *
 * One product per line, the quantity is the last thing on the line. Each line
 * is matched against the supplier's price list (CatalogIndex::searchItemsLoose — forgiving):
 *   one product (or a name / barcode written exactly)   → into the cart
 *   2 to POAGENT_BENCH_D_MAX_OPTIONS products           → a conflict: the user picks (state a_resolve)
 *   more than that                                      → "not clear which product — write more details"
 *   none                                                → "no product in the list fits this description"
 *   no quantity at the end                              → "the quantity is missing"
 * All the conflicts are sent together, a message each with its options to tap
 * (2–3: buttons on screen; 4–5: a list); typing their numbers ("2 4") works too. Lines that did not go
 * in are simply written again — a list is accepted at any point of the order.
 * When nothing is left open: method A's summary ([אשר] [ערוך] [בטל]).
 *
 * A single line with no quantity (in a_build) is method A's search, as today.
 * data.pending = the open conflicts [{k, text, qty, options: [barcode, ...]}] (k: its key in button ids);
 * data.resolve_map = {number shown: [k, barcode]}; data.shown = the keys sent and not yet answered.
 */

const POAGENT_BENCH_D_MAX_OPTIONS = 5;      // more products than this for one line: "write more details"
const POAGENT_BENCH_D_MAX_LINES   = 80;     // lines read from one message
const POAGENT_BENCH_D_MAX_SHOWN   = 8;      // conflict messages sent at a time

function poagent_bench_d_handle(array $event, string $name): void
{
    poagent_bench_a_handle($event, $name, 'd');
}

/** Is this order a method-D one (the list prompt, lists accepted)? */
function poagent_bench_d_on(array $data): bool
{
    return in_array($data['bench']['mode'] ?? '', ['d', 'e'], true);   // e: method D with a recording (bench_e_voice.php)
}

/**
 * The lines of a typed list: [['text' => product as written, 'qty' => int|null], ...].
 * The quantity is a whole number at the end of the line ("בצל 5", "בצל - 5", "בצל x5", "בצל 5 יח'");
 * "קולה 1.5" has none. A bullet or "1." at the start of a line is dropped.
 */
function poagent_bench_d_parse(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim(preg_replace('/^\s*(?:[-•*·]+|\d{1,2}[.)])\s+/u', '', $line));
        if ($line === '') continue;
        if (preg_match('/^(.+?)(?:\s+[xX×*]?\s*|\s*[-–:,*×]\s*)(\d{1,4})\s*(?:יח\S*)?$/u', $line, $m) && (int) $m[2] >= 1) {
            $out[] = ['text' => trim($m[1], " \t-–:,"), 'qty' => (int) $m[2]];
        } else {
            $out[] = ['text' => $line, 'qty' => null];
        }
    }
    return $out;
}

/** A list (and not one product to search for): several lines, or one line that ends with a quantity. */
function poagent_bench_d_is_list(string $text): bool
{
    $lines = poagent_bench_d_parse($text);
    return count($lines) > 1 || (count($lines) === 1 && $lines[0]['qty'] !== null);
}

/**
 * The price-list products a written line can mean, best first. A name written
 * exactly, or a full barcode, is that product alone even when others contain it.
 */
function poagent_bench_d_match(string $supplier, string $q): array
{
    $found = CatalogIndex::searchItemsLoose($supplier, $q);   // singular / plural and a one-letter slip forgiven
    if (count($found) < 2) return $found;
    $qn = CatalogIndex::normalize($q);
    if (preg_match('/^\d{4,}$/', $qn) && ltrim($found[0]['barcode'], '0') === ltrim($qn, '0')) return [$found[0]];
    $exact = array_values(array_filter($found, fn($it) => CatalogIndex::normalize($it['name']) === $qn));
    return count($exact) === 1 ? $exact : $found;
}

/** A typed list: every line into the cart / the conflicts / the report of what did not go in. */
function poagent_bench_d_list(array $ctx, array $data, string $text, ?array $lines = null): void
{
    // $lines: already read ([['text', 'qty'], ...] — a dictated order, method E) instead of $text.
    $lines = array_slice($lines ?? poagent_bench_d_parse($text), 0, POAGENT_BENCH_D_MAX_LINES);
    $pending = $data['pending'] ?? [];
    $added = $unclear = $none = $noQty = [];
    foreach ($lines as $l) {
        if ($l['qty'] === null) {
            $noQty[] = $l['text'];
            continue;
        }
        $data['bench']['searches']++;
        $found = poagent_bench_d_match($data['supplier'], $l['text']);
        if (!$found) {
            $data['bench']['zero_result_searches']++;
            $none[] = "{$l['text']} {$l['qty']}";
        } elseif (count($found) === 1) {
            $data = poagent_bench_d_add($data, $found[0]['barcode'], $l['qty']);
            $added[] = "{$found[0]['name']} × {$l['qty']}";
        } elseif (count($found) <= POAGENT_BENCH_D_MAX_OPTIONS) {
            $data['pend_seq'] = (int) ($data['pend_seq'] ?? 0) + 1;
            $pending[] = ['k' => $data['pend_seq'], 'text' => $l['text'], 'qty' => $l['qty'], 'options' => array_column($found, 'barcode')];
        } else {
            $unclear[] = "{$l['text']} {$l['qty']} (" . count($found) . ' מוצרים מתאימים)';
        }
    }
    $report = [];
    if ($added) $report[] = '✓ נכנסו ' . count($added) . " שורות:\n• " . implode("\n• ", $added);
    if ($unclear) $report[] = "❓ לא ברור איזה מוצר נדרש — אנא רשמו פרטים נוספים:\n• " . implode("\n• ", $unclear);
    if ($none) $report[] = "🚫 אין מוצר ברשימה שעונה על התיאור הזה:\n• " . implode("\n• ", $none);
    if ($noQty) $report[] = "⚠️ חסרה כמות — כתבו שוב עם מספר בסוף השורה:\n• " . implode("\n• ", $noQty);
    $report = implode("\n\n", $report);
    if (mb_strlen($report) > 3800) $report = mb_substr($report, 0, 3800) . '…';

    $data['pending'] = $pending;
    // Lines that did not go in and wait to be written again (asked for once the conflicts are done).
    $data['fix'] = ($pending ? (int) ($data['fix'] ?? 0) : 0) + count($unclear) + count($none) + count($noQty);
    if ($report !== '') WaClient::text($ctx['pnid'], $ctx['from'], $report);
    poagent_bench_d_next($ctx, $data);
}

function poagent_bench_d_add(array $data, string $barcode, int $qty): array
{
    $data['cart'][$barcode] = min(POAGENT_BENCH_A_MAX_QTY, (int) ($data['cart'][$barcode] ?? 0) + $qty);
    return $data;
}

/** What comes after a list / a choice: the open conflicts, else the lines to write again, else the summary. */
function poagent_bench_d_next(array $ctx, array $data): void
{
    if (!empty($data['pending'])) {
        poagent_bench_d_show_conflicts($ctx, $data);
        return;
    }
    unset($data['pending'], $data['resolve_map'], $data['shown']);
    $fix = (int) ($data['fix'] ?? 0);
    unset($data['fix']);
    if ($fix > 0) {
        $body = ($fix === 1 ? 'שורה אחת לא נכנסה' : "{$fix} שורות לא נכנסו") . ' (למעלה). אפשר '
            . (poagent_bench_e_on($data) ? 'להקליט או לכתוב אותן שוב' : 'לכתוב שוב — מוצר וכמות בכל שורה');
        if (poagent_bench_a_lines($data)[0]) {
            WaClient::buttons($ctx['pnid'], $ctx['from'], "{$body}, או:", POAGENT_BENCH_A_DONE_BUTTONS);
        } else {
            WaClient::text($ctx['pnid'], $ctx['from'], "{$body}.");
        }
        poagent_bench_a_save($ctx, 'a_build', $data);
        return;
    }
    poagent_bench_a_confirm($ctx, $data);   // everything matched: the list and quantities to approve
}

/**
 * The open conflicts, each as a message of its own to tap — what was written, the
 * options under it (numbered through all the messages), and:
 *   2–3 options   reply buttons, on screen (with 2: a [דלג] button too)
 *   4–5 options   a list to open (WhatsApp shows at most 3 buttons), with a "דלג" row
 * All of them are sent together (POAGENT_BENCH_D_MAX_SHOWN at a time) and tapped in any order.
 */
function poagent_bench_d_show_conflicts(array $ctx, array $data, string $intro = ''): void
{
    $pending = array_values($data['pending']);
    $batch = array_slice($pending, 0, POAGENT_BENCH_D_MAX_SHOWN);
    $more = count($pending) - count($batch);
    $head = count($pending) === 1
        ? 'שורה אחת מתאימה לכמה מוצרים במחירון. לאיזה מוצר התכוונתם?'
        : count($pending) . ' שורות מתאימות לכמה מוצרים במחירון. בחרו מוצר לכל אחת:'
            . ($more > 0 ? "\n(מוצגות " . count($batch) . ' הראשונות, השאר אחריהן)' : '');
    $prefix = '';
    if (count($batch) > 1 || $intro !== '') {
        WaClient::text($ctx['pnid'], $ctx['from'], $intro . $head);
    } else {
        $prefix = "{$head}\n\n";
    }
    $short = fn(string $s, int $max) => mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    $map = $shown = [];
    $n = 0;
    foreach ($batch as $p) {
        $k = (int) $p['k'];
        $body = ["*{$p['text']}* × {$p['qty']}"];
        $buttons = $rows = [];
        foreach ($p['options'] as $bc) {
            $it = CatalogIndex::item($data['supplier'], $bc);
            if (!$it) continue;
            $map[++$n] = [$k, $it['barcode']];
            $label = "{$n}. {$it['name']}";
            $price = poagent_bench_ils($it['price_agorot']);
            $body[] = "{$label} · {$price}";
            $buttons[] = ['id' => "pick:{$k}:{$it['barcode']}", 'title' => $short($label, 20)];
            $rows[] = ['id' => "pick:{$k}:{$it['barcode']}", 'title' => $short($label, 24), 'description' => "{$price} · {$it['barcode']} · {$it['name']}"];
        }
        if (!$buttons) continue;   // none of its products is in the price list any more
        $shown[] = $k;
        if (count($buttons) <= 3) {
            if (count($buttons) < 3) {
                $buttons[] = ['id' => "skip:{$k}", 'title' => 'דלג'];
            } else {
                $body[] = '';
                $body[] = 'אף אחד לא מתאים? כתבו: דלג';
            }
            WaClient::buttons($ctx['pnid'], $ctx['from'], $prefix . implode("\n", $body), $buttons);
        } else {
            $rows[] = ['id' => "skip:{$k}", 'title' => 'דלג על השורה', 'description' => 'אף אחד מהמוצרים לא מתאים'];
            WaClient::list($ctx['pnid'], $ctx['from'], $prefix . implode("\n", $body) . "\n\nפתחו את הרשימה ובחרו.", $rows, 'בחירת מוצר');
        }
    }
    if (!$shown) {   // nothing of this batch could be shown: on to the rest
        $data['pending'] = array_slice($pending, count($batch));
        poagent_bench_d_next($ctx, $data);
        return;
    }
    $data['pending'] = array_values(array_filter($pending, fn($p) => in_array((int) $p['k'], $shown, true) || !in_array($p, $batch, true)));
    $data['resolve_map'] = $map;
    $data['shown'] = $shown;
    poagent_bench_a_save($ctx, 'a_resolve', $data);
}

/** The reply to the conflicts: a tap on a product / [דלג], typed numbers ("2 4"), "דלג", or more lines written. */
function poagent_bench_d_resolve(array $ctx, array $data, array $event): void
{
    $text = trim((string) ($event['text'] ?? ''));
    $id = (string) ($event['reply_id'] ?? '');
    $map = $data['resolve_map'] ?? [];
    $byKey = array_column($data['pending'] ?? [], null, 'k');
    $shown = $data['shown'] ?? [];
    $take = function (int $k, string $bc) use (&$data, &$byKey): string {   // a line's product chosen → the cart
        $p = $byKey[$k];
        unset($byKey[$k]);
        $data = poagent_bench_d_add($data, $bc, (int) $p['qty']);
        $it = CatalogIndex::item($data['supplier'], $bc);
        return ($it['name'] ?? $bc) . " × {$p['qty']}";
    };

    if (preg_match('/^pick:(\d+):(.+)$/', $id, $m)) {
        if (!isset($byKey[(int) $m[1]]) || !in_array($m[2], $byKey[(int) $m[1]]['options'], true)) {
            WaClient::text($ctx['pnid'], $ctx['from'], 'השורה הזו כבר טופלה.');
            poagent_bench_a_save($ctx, 'a_resolve', $data);
            return;
        }
        $msg = '✓ נכנס: ' . $take((int) $m[1], $m[2]);
    } elseif (preg_match('/^skip:(\d+)$/', $id, $m)) {
        $msg = isset($byKey[(int) $m[1]]) ? "דילגתי על \"{$byKey[(int) $m[1]]['text']}\"." : 'השורה הזו כבר טופלה.';
        unset($byKey[(int) $m[1]]);
    } elseif ($id === '' && in_array($text, ['דלג', 'דלג על השורה', 'דלג על השורות'], true)) {
        $n = 0;
        foreach ($shown as $k) {
            if (isset($byKey[$k])) $n++;
            unset($byKey[$k]);
        }
        $msg = $n === 1 ? 'דילגתי על השורה.' : "דילגתי על {$n} שורות.";
    } elseif ($id === '' && preg_match('/^\d[\d\s,.]*$/', $text)) {
        $nums = array_map('intval', preg_split('/[\s,.]+/', $text, -1, PREG_SPLIT_NO_EMPTY));
        $bad = array_values(array_filter($nums, fn($n) => !isset($map[$n]) || !isset($byKey[$map[$n][0]])));
        if ($bad) {
            WaClient::text($ctx['pnid'], $ctx['from'], 'אין אפשרות מספר ' . implode(', ', $bad) . ' בין השורות הפתוחות.');
            poagent_bench_a_save($ctx, 'a_resolve', $data);
            return;
        }
        $picked = [];
        foreach ($nums as $n) $picked[$map[$n][0]] = $map[$n][1];   // one product per line; the last number wins
        $done = [];
        foreach ($picked as $k => $bc) $done[] = $take($k, $bc);
        $msg = "✓ נכנסו:\n• " . implode("\n• ", $done);
    } elseif ($id === '' && $text !== '') {   // more lines, or a line written again
        poagent_bench_d_list($ctx, $data, $text);
        return;
    } else {   // a tap on something else (an older message's button)
        $msg = '';
    }

    $data['pending'] = array_values($byKey);
    $open = count(array_intersect($shown, array_keys($byKey)));
    if ($open > 0) {
        WaClient::text($ctx['pnid'], $ctx['from'], trim("{$msg}\n" . ($open === 1 ? 'נשארה שורה אחת לבחירה' : "נשארו {$open} שורות לבחירה")
            . ' — בחרו מוצר בהודעות למעלה, או כתבו: דלג'));
        poagent_bench_a_save($ctx, 'a_resolve', $data);
        return;
    }
    if ($msg !== '') WaClient::text($ctx['pnid'], $ctx['from'], $msg);
    poagent_bench_d_next($ctx, $data);   // the next batch of conflicts / lines to write again / the summary
}

<?php
/**
 * bench_a_chat.php — WhatsApp ordering, method A: search in the chat + "order
 * again" (spec "אפיון בנצ'מרק הזמנת רכש בוואטסאפ", method A). Loaded by
 * bench_common.php; poagent_bench_a_handle() gets every message of a phone
 * set to method A in users.php. Grew out of the parked po_flow.php (kept as it
 * was), on the shared infrastructure: CatalogIndex search, one PO path
 * (poagent_wa_commit_cart), the measurement counters.
 *
 * Conversation states (WaSessionStore state; data.cart = {barcode: qty}):
 *   a_supplier  "which supplier?" — recent suppliers as a list, or type part of a name
 *   a_start     the supplier's last PO: [הזמן שוב] → a_confirm with its lines / [הזמנה חדשה] → a_build
 *   a_build     type a product name or barcode → a list of up to 10 to tap; "3*2" = result 3, quantity 2;
 *               once the cart has something: [סיום והזמנה] → a_confirm, [הצג עגלה] → a_cart
 *               (finishing is always a button; the typed words סיום / עגלה still work)
 *   a_qty       "how many?" — a typed number, no preset buttons → added (or replaced, when editing a line)
 *   a_cart      the cart's lines to tap + a "סיום והזמנה" row (10+ lines: numbered text + the button) → a_line
 *   a_line      [שנה כמות] [הסר] [חזרה]
 *   a_confirm   summary + delivery date (a typed date like 151126 changes it) — [אשר] [ערוך] [בטל]
 *   a_cancel    "cancel the order?" — asked when ביטול / תפריט comes with something in the cart
 * After a PO (or a cancel) the conversation is parked; the next message starts a new order.
 * List row ids: sup:<index into data.sup_map> / item:<barcode> / cart:<barcode> — never a Hebrew name.
 */

const POAGENT_BENCH_A_LIST_MAX = 10;   // WhatsApp's list message limit
const POAGENT_BENCH_A_MAX_QTY  = 9999;
// Finishing is always a button, never a word to type.
const POAGENT_BENCH_A_DONE_BUTTONS = [['id' => 'done', 'title' => 'סיום והזמנה'], ['id' => 'cart', 'title' => 'הצג עגלה']];

function poagent_bench_a_handle(array $event, string $name): void
{
    $ctx = ['from' => (string) $event['from'], 'pnid' => (string) ($event['business_phone_number_id'] ?? ''), 'name' => $name];
    $session = WaSessionStore::get($ctx['from']);
    $state = (string) ($session['state'] ?? '');
    $data = $session['data'] ?? [];
    $text = trim((string) ($event['text'] ?? ''));
    $id = (string) ($event['reply_id'] ?? '');

    // No order in progress: this message starts one.
    if (!str_starts_with($state, 'a_') || empty($data['bench'])) {
        // Whatever it says — even "היי" would match a supplier named "…הייב…" — it only opens the order.
        $data = poagent_bench_a_new_order($data);
        poagent_bench_note($data['bench'], $event);
        poagent_bench_a_ask_supplier($ctx, $data, 'שלום' . ($name !== '' ? " {$name}" : '') . " 👋 הזמנת רכש חדשה.\n");
        return;
    }
    poagent_bench_note($data['bench'], $event);

    if ($id === '' && in_array($text, ['ביטול', 'בטל', 'תפריט'], true) && $state !== 'a_cancel') {
        poagent_bench_a_ask_cancel($ctx, $data, $state);
        return;
    }

    switch ($state) {
        case 'a_supplier':
            if (preg_match('/^sup:(\d+)$/', $id, $m) && isset($data['sup_map'][(int) $m[1]])) {
                poagent_bench_a_pick_supplier($ctx, $data, $data['sup_map'][(int) $m[1]]);
            } elseif ($text !== '') {
                poagent_bench_a_supplier_search($ctx, $data, $text);
            } else {
                poagent_bench_a_ask_supplier($ctx, $data);
            }
            return;
        case 'a_start':
            if ($id === 'again' || in_array($text, ['הזמן שוב', 'שוב'], true)) {
                poagent_bench_a_order_again($ctx, $data);
            } elseif ($id === 'new' || in_array($text, ['הזמנה חדשה', 'חדשה'], true)) {
                poagent_bench_a_build_prompt($ctx, $data);
            } else {
                poagent_bench_a_build($ctx, $data, $event);   // typed a product right away
            }
            return;
        case 'a_qty':
            poagent_bench_a_qty($ctx, $data, $event);
            return;
        case 'a_cart':
            poagent_bench_a_cart_pick($ctx, $data, $event);
            return;
        case 'a_line':
            poagent_bench_a_line($ctx, $data, $event);
            return;
        case 'a_confirm':
            poagent_bench_a_confirm_reply($ctx, $data, $event);
            return;
        case 'a_cancel':
            poagent_bench_a_cancel_reply($ctx, $data, $event);
            return;
        default:   // a_build
            poagent_bench_a_build($ctx, $data, $event);
    }
}

function poagent_bench_a_new_order(array $data): array
{
    $scenario = (string) ($data['scenario'] ?? '');
    return ['scenario' => $scenario, 'bench' => poagent_bench_new('a', $scenario), 'cart' => []];
}

function poagent_bench_a_save(array $ctx, string $state, array $data): void
{
    poagent_bench_save($ctx['from'], $state, $data, $ctx['pnid']);
}

// ── supplier ────────────────────────────────────────────────────────────────

function poagent_bench_a_ask_supplier(array $ctx, array $data, string $intro = ''): void
{
    $recent = array_values(array_filter(POStore::recentSuppliersFor($ctx['from'], POAGENT_BENCH_A_LIST_MAX), [CatalogIndex::class, 'exists']));
    if ($recent) {
        $data['sup_map'] = $recent;
        WaClient::list($ctx['pnid'], $ctx['from'], "{$intro}איזה ספק?\nכתבו חלק משם הספק, או בחרו מהספקים האחרונים שלכם.",
            poagent_bench_a_supplier_rows($recent), 'ספקים אחרונים');
    } else {
        $data['sup_map'] = [];
        WaClient::text($ctx['pnid'], $ctx['from'], "{$intro}איזה ספק? כתבו חלק משם הספק.");
    }
    poagent_bench_a_save($ctx, 'a_supplier', $data);
}

function poagent_bench_a_supplier_rows(array $names): array
{
    $rows = [];
    foreach ($names as $i => $n) {
        $rows[] = ['id' => "sup:{$i}", 'title' => $n, 'description' => mb_strlen($n) > 24 ? $n : ''];
    }
    return $rows;
}

function poagent_bench_a_supplier_search(array $ctx, array $data, string $q): void
{
    $data['bench']['searches']++;
    $found = CatalogIndex::searchSuppliers($q);
    if (!$found) {
        $data['bench']['zero_result_searches']++;
        WaClient::text($ctx['pnid'], $ctx['from'], "לא נמצא ספק בשם \"{$q}\". נסו חלק אחר של השם.");
        poagent_bench_a_save($ctx, 'a_supplier', $data);
        return;
    }
    if (count($found) === 1) {
        poagent_bench_a_pick_supplier($ctx, $data, $found[0]);
        return;
    }
    $shown = array_slice($found, 0, POAGENT_BENCH_A_LIST_MAX);
    $data['sup_map'] = $shown;
    $body = count($found) > count($shown)
        ? 'נמצאו ' . count($found) . " ספקים ל-\"{$q}\" — מוצגים 10 הראשונים. כתבו עוד אותיות כדי לצמצם, או בחרו:"
        : 'נמצאו ' . count($found) . " ספקים ל-\"{$q}\":";
    WaClient::list($ctx['pnid'], $ctx['from'], $body, poagent_bench_a_supplier_rows($shown), 'בחירת ספק');
    poagent_bench_a_save($ctx, 'a_supplier', $data);
}

function poagent_bench_a_pick_supplier(array $ctx, array $data, string $supplier): void
{
    $data['supplier'] = $supplier;
    $data['cart'] = [];
    $data['results'] = [];
    unset($data['sup_map']);
    $last = POStore::lastPoFor($ctx['from'], $supplier);
    if ($last && !empty($last['items'])) {
        $total = POStore::totalAgorot($last);
        WaClient::buttons($ctx['pnid'], $ctx['from'],
            "ספק: {$supplier}\n\nההזמנה האחרונה שלכם: {$last['unique_id']} מ-" . date('d/m/y', strtotime($last['date_generated']))
            . ' — ' . count($last['items']) . ' שורות, ' . poagent_bench_ils($total) . ".\nלהזמין שוב את אותם מוצרים?",
            [['id' => 'again', 'title' => 'הזמן שוב'], ['id' => 'new', 'title' => 'הזמנה חדשה']]);
        poagent_bench_a_save($ctx, 'a_start', $data);
        return;
    }
    poagent_bench_a_build_prompt($ctx, $data, "ספק: {$supplier}\n");
}

function poagent_bench_a_order_again(array $ctx, array $data): void
{
    $last = POStore::lastPoFor($ctx['from'], $data['supplier']);
    $cart = [];
    $missing = [];
    foreach ($last['items'] ?? [] as $it) {
        if (CatalogIndex::item($data['supplier'], (string) $it['barcode'])) {
            $cart[(string) $it['barcode']] = ($cart[(string) $it['barcode']] ?? 0) + (int) $it['qty'];
        } else {
            $missing[] = (string) $it['name'];
        }
    }
    $data['cart'] = $cart;
    $data['notice'] = $missing ? 'לא במחירון יותר, לא נכנסו: ' . implode(', ', $missing) : '';
    if (!$cart) {
        WaClient::text($ctx['pnid'], $ctx['from'], "אף אחד מהמוצרים של ההזמנה הקודמת כבר לא במחירון.");
        poagent_bench_a_build_prompt($ctx, $data);
        return;
    }
    poagent_bench_a_confirm($ctx, $data);
}

// ── products ────────────────────────────────────────────────────────────────

/** "Type a product"; with something in the cart, also the [סיום והזמנה] [הצג עגלה] buttons. */
function poagent_bench_a_build_prompt(array $ctx, array $data, string $intro = ''): void
{
    $body = "{$intro}כתבו שם מוצר או ברקוד.\nמתוצאות החיפוש אפשר גם 3*2 (תוצאה 3, כמות 2).";
    if (poagent_bench_a_lines($data)[0]) {
        WaClient::buttons($ctx['pnid'], $ctx['from'], $body, POAGENT_BENCH_A_DONE_BUTTONS);
    } else {
        WaClient::text($ctx['pnid'], $ctx['from'], $body);
    }
    poagent_bench_a_save($ctx, 'a_build', $data);
}

function poagent_bench_a_build(array $ctx, array $data, array $event): void
{
    $text = trim((string) ($event['text'] ?? ''));
    $id = (string) ($event['reply_id'] ?? '');
    $results = $data['results'] ?? [];

    if (preg_match('/^item:(.+)$/', $id, $m)) {
        poagent_bench_a_ask_qty($ctx, $data, $m[1], false);
    } elseif ($id === 'done' || in_array($text, ['סיום', 'סיימתי', 'הזמן'], true)) {
        poagent_bench_a_confirm($ctx, $data);
    } elseif ($id === 'cart' || in_array($text, ['עגלה', 'הצג עגלה'], true)) {
        poagent_bench_a_show_cart($ctx, $data);
    } elseif (preg_match('/^(\d{1,2})\s*[*xX×]\s*(\d+)$/u', $text, $m) && (int) $m[1] >= 1 && (int) $m[1] <= count($results)) {
        poagent_bench_a_set_qty($ctx, $data, $results[(int) $m[1] - 1], (int) $m[2], false);
    } elseif (preg_match('/^\d{1,2}$/', $text) && (int) $text >= 1 && (int) $text <= count($results)) {
        poagent_bench_a_ask_qty($ctx, $data, $results[(int) $text - 1], false);
    } elseif ($text !== '') {
        poagent_bench_a_item_search($ctx, $data, $text);
    } else {
        poagent_bench_a_build_prompt($ctx, $data);
    }
}

function poagent_bench_a_item_search(array $ctx, array $data, string $q): void
{
    $data['bench']['searches']++;
    $found = CatalogIndex::searchItems($data['supplier'], $q);
    if (!$found) {
        $data['bench']['zero_result_searches']++;
        WaClient::text($ctx['pnid'], $ctx['from'], "לא נמצא מוצר ל-\"{$q}\" אצל {$data['supplier']}. נסו מילה אחרת או ברקוד.");
        poagent_bench_a_save($ctx, 'a_build', $data);
        return;
    }
    if (count($found) === 1) {
        poagent_bench_a_ask_qty($ctx, $data, $found[0]['barcode'], false);
        return;
    }
    $shown = array_slice($found, 0, POAGENT_BENCH_A_LIST_MAX);
    $data['results'] = array_column($shown, 'barcode');
    $rows = [];
    foreach ($shown as $i => $it) {
        // Title: the name's start; description: price · barcode, then the full name.
        $rows[] = ['id' => "item:{$it['barcode']}", 'title' => ($i + 1) . '. ' . $it['name'],
            'description' => poagent_bench_ils($it['price_agorot']) . " · {$it['barcode']} · {$it['name']}"];
    }
    $body = count($found) > count($shown)
        ? 'נמצאו ' . count($found) . " מוצרים ל-\"{$q}\" — מוצגים 10 הראשונים. דייקו את החיפוש, או בחרו:"
        : 'נמצאו ' . count($found) . " מוצרים ל-\"{$q}\":";
    WaClient::list($ctx['pnid'], $ctx['from'], $body, $rows, 'בחירת מוצר');
    poagent_bench_a_save($ctx, 'a_build', $data);
}

/** "How many?" for a product; $replace = editing a cart line (the number replaces, not adds). */
function poagent_bench_a_ask_qty(array $ctx, array $data, string $barcode, bool $replace): void
{
    $it = CatalogIndex::item($data['supplier'], $barcode);
    if (!$it) {
        WaClient::text($ctx['pnid'], $ctx['from'], 'המוצר הזה כבר לא במחירון. חפשו שוב.');
        poagent_bench_a_save($ctx, 'a_build', $data);
        return;
    }
    $data['qty_for'] = $it['barcode'];
    $data['qty_replace'] = $replace;
    $now = (int) ($data['cart'][$it['barcode']] ?? 0);
    // The quantity is typed — no preset buttons.
    WaClient::text($ctx['pnid'], $ctx['from'], "{$it['name']}\n" . poagent_bench_ils($it['price_agorot']) . " ליחידה · {$it['barcode']}"
        . ($now ? "\nכרגע בעגלה: {$now}" . ($replace ? ' (0 = הסרה)' : ' — יתווסף') : '')
        . "\n\n" . ($replace ? 'כתבו את הכמות החדשה:' : 'כמה יחידות? כתבו מספר:'));
    poagent_bench_a_save($ctx, 'a_qty', $data);
}

function poagent_bench_a_qty(array $ctx, array $data, array $event): void
{
    $text = trim((string) ($event['text'] ?? ''));
    $qty = ($event['reply_id'] ?? '') === '' && preg_match('/^\d+$/', $text) ? (int) $text : null;
    if ($qty === null) {   // not a number: a new search / button instead
        unset($data['qty_for'], $data['qty_replace']);
        poagent_bench_a_build($ctx, $data, $event);
        return;
    }
    $replace = !empty($data['qty_replace']);
    if ($qty > POAGENT_BENCH_A_MAX_QTY || ($qty < 1 && !$replace)) {
        WaClient::text($ctx['pnid'], $ctx['from'], 'שלחו כמות בין 1 ל-' . POAGENT_BENCH_A_MAX_QTY . '.');
        poagent_bench_a_save($ctx, 'a_qty', $data);
        return;
    }
    poagent_bench_a_set_qty($ctx, $data, (string) $data['qty_for'], $qty, $replace);
}

/** Adds $qty of a product to the cart (or sets it, $replace; 0 removes) and says so. */
function poagent_bench_a_set_qty(array $ctx, array $data, string $barcode, int $qty, bool $replace): void
{
    $it = CatalogIndex::item($data['supplier'], $barcode);
    unset($data['qty_for'], $data['qty_replace']);
    if (!$it || $qty < 0 || $qty > POAGENT_BENCH_A_MAX_QTY || (!$replace && $qty < 1)) {
        WaClient::text($ctx['pnid'], $ctx['from'], $it ? 'שלחו כמות בין 1 ל-' . POAGENT_BENCH_A_MAX_QTY . '.' : 'המוצר הזה כבר לא במחירון.');
        poagent_bench_a_save($ctx, 'a_build', $data);
        return;
    }
    $bc = $it['barcode'];
    if ($replace) {
        $data['bench']['edits']++;
        if ($qty === 0) unset($data['cart'][$bc]); else $data['cart'][$bc] = $qty;
        $what = $qty === 0 ? "✓ הוסר: {$it['name']}" : "✓ עודכן: {$it['name']} — {$qty} יח'";
    } else {
        $data['cart'][$bc] = min(POAGENT_BENCH_A_MAX_QTY, (int) ($data['cart'][$bc] ?? 0) + $qty);
        $what = "✓ נוסף: {$it['name']} × {$qty}";
    }
    [$lines, $total] = poagent_bench_a_lines($data);
    $body = "{$what}\nבעגלה: " . count($lines) . ' שורות · ' . poagent_bench_ils($total);
    if ($lines) {
        WaClient::buttons($ctx['pnid'], $ctx['from'], "{$body}\n\nכתבו מוצר נוסף לחיפוש, או:", POAGENT_BENCH_A_DONE_BUTTONS);
    } else {
        WaClient::text($ctx['pnid'], $ctx['from'], "{$what}\nהעגלה ריקה. כתבו שם מוצר או ברקוד.");
    }
    poagent_bench_a_save($ctx, 'a_build', $data);
}

// ── cart ────────────────────────────────────────────────────────────────────

/** The cart's lines as items (names and prices from the price list) and their total in agorot. */
function poagent_bench_a_lines(array $data): array
{
    $lines = [];
    $total = 0;
    foreach ($data['cart'] ?? [] as $bc => $qty) {
        $it = CatalogIndex::item((string) $data['supplier'], (string) $bc);
        if (!$it || (int) $qty < 1) continue;
        $lines[] = $it + ['qty' => (int) $qty];
        $total += (int) $qty * $it['price_agorot'];
    }
    return [$lines, $total];
}

function poagent_bench_a_show_cart(array $ctx, array $data): void
{
    [$lines, $total] = poagent_bench_a_lines($data);
    if (!$lines) {
        WaClient::text($ctx['pnid'], $ctx['from'], 'העגלה ריקה. כתבו שם מוצר או ברקוד.');
        poagent_bench_a_save($ctx, 'a_build', $data);
        return;
    }
    $data['cart_order'] = array_column($lines, 'barcode');
    $head = '🛒 העגלה — ' . count($lines) . ' שורות · ' . poagent_bench_ils($total);
    // Up to 9 lines: a list, its last row "סיום והזמנה". More: numbered text, then the button.
    if (count($lines) < POAGENT_BENCH_A_LIST_MAX) {
        $rows = [];
        foreach ($lines as $l) {
            $rows[] = ['id' => "cart:{$l['barcode']}", 'title' => $l['name'],
                'description' => "{$l['qty']} × " . poagent_bench_ils($l['price_agorot']) . ' = ' . poagent_bench_ils($l['qty'] * $l['price_agorot']) . " · {$l['name']}"];
        }
        $rows[] = ['id' => 'done', 'title' => '✅ סיום והזמנה', 'description' => 'לסיכום ההזמנה ולאישור'];
        WaClient::list($ctx['pnid'], $ctx['from'], "{$head}\nבחרו שורה כדי לשנות כמות או להסיר, או סיום והזמנה.\nלהוספה: כתבו שם מוצר.",
            $rows, 'העגלה');
    } else {
        $txt = [$head, ''];
        foreach ($lines as $i => $l) {
            $txt[] = ($i + 1) . ". {$l['name']} — {$l['qty']} × " . poagent_bench_ils($l['price_agorot']);
        }
        $txt[] = '';
        $txt[] = 'לעריכה שלחו מספר שורה · להוספה: כתבו שם מוצר';
        WaClient::text($ctx['pnid'], $ctx['from'], implode("\n", $txt));
        WaClient::buttons($ctx['pnid'], $ctx['from'], 'או:', [['id' => 'done', 'title' => 'סיום והזמנה']]);
    }
    poagent_bench_a_save($ctx, 'a_cart', $data);
}

function poagent_bench_a_cart_pick(array $ctx, array $data, array $event): void
{
    $text = trim((string) ($event['text'] ?? ''));
    $id = (string) ($event['reply_id'] ?? '');
    $order = $data['cart_order'] ?? [];
    $bc = preg_match('/^cart:(.+)$/', $id, $m) ? $m[1]
        : (preg_match('/^\d{1,3}$/', $text) && (int) $text >= 1 && (int) $text <= count($order) ? $order[(int) $text - 1] : null);
    if ($bc === null || !isset($data['cart'][$bc])) {
        poagent_bench_a_build($ctx, $data, $event);   // סיום / a search / anything else
        return;
    }
    $it = CatalogIndex::item($data['supplier'], $bc);
    $data['line'] = $bc;
    WaClient::buttons($ctx['pnid'], $ctx['from'],
        "{$it['name']}\n{$data['cart'][$bc]} × " . poagent_bench_ils($it['price_agorot']),
        [['id' => 'qtychg', 'title' => 'שנה כמות'], ['id' => 'remove', 'title' => 'הסר'], ['id' => 'back', 'title' => 'חזרה']]);
    poagent_bench_a_save($ctx, 'a_line', $data);
}

function poagent_bench_a_line(array $ctx, array $data, array $event): void
{
    $id = (string) ($event['reply_id'] ?? '');
    $text = trim((string) ($event['text'] ?? ''));
    $bc = (string) ($data['line'] ?? '');
    unset($data['line']);
    if ($id === 'qtychg' || $text === 'שנה כמות') {
        poagent_bench_a_ask_qty($ctx, $data, $bc, true);
    } elseif ($id === 'remove' || $text === 'הסר') {
        poagent_bench_a_set_qty($ctx, $data, $bc, 0, true);
    } elseif ($id === 'back' || $text === 'חזרה') {
        poagent_bench_a_build_prompt($ctx, $data);
    } else {
        poagent_bench_a_build($ctx, $data, $event);
    }
}

// ── confirm ─────────────────────────────────────────────────────────────────

function poagent_bench_a_confirm(array $ctx, array $data): void
{
    [$lines, $total] = poagent_bench_a_lines($data);
    if (!$lines) {
        WaClient::text($ctx['pnid'], $ctx['from'], 'העגלה ריקה — עוד לא נבחרו מוצרים. כתבו שם מוצר או ברקוד.');
        poagent_bench_a_save($ctx, 'a_build', $data);
        return;
    }
    $data['delivery'] ??= poagent_wa_next_business_day();
    $txt = ['📋 סיכום הזמנה', "ספק: {$data['supplier']}", ''];
    foreach ($lines as $i => $l) {
        $txt[] = ($i + 1) . ". {$l['name']}";
        $txt[] = "   {$l['qty']} × " . poagent_bench_ils($l['price_agorot']) . ' = ' . poagent_bench_ils($l['qty'] * $l['price_agorot']);
    }
    $txt[] = '';
    $txt[] = 'סה"כ: ' . poagent_bench_ils($total);
    $txt[] = 'תאריך אספקה: ' . poagent_bench_a_day($data['delivery']) . ' — לשינוי שלחו תאריך, למשל ' . date('dmy', strtotime('+3 days'));
    if (!empty($data['notice'])) {
        $txt[] = '';
        $txt[] = '⚠️ ' . $data['notice'];
    }
    WaClient::text($ctx['pnid'], $ctx['from'], implode("\n", $txt));
    WaClient::buttons($ctx['pnid'], $ctx['from'], 'לאשר את ההזמנה?',
        [['id' => 'yes', 'title' => 'אשר'], ['id' => 'edit', 'title' => 'ערוך'], ['id' => 'no', 'title' => 'בטל']]);
    poagent_bench_a_save($ctx, 'a_confirm', $data);
}

function poagent_bench_a_confirm_reply(array $ctx, array $data, array $event): void
{
    $id = (string) ($event['reply_id'] ?? '');
    $text = trim((string) ($event['text'] ?? ''));
    if ($id === 'yes' || in_array($text, ['אשר', 'אישור', 'כן'], true)) {
        poagent_bench_a_commit($ctx, $data);
    } elseif ($id === 'edit' || in_array($text, ['ערוך', 'עריכה'], true)) {
        poagent_bench_a_show_cart($ctx, $data);
    } elseif ($id === 'no' || $text === 'לא') {
        poagent_bench_a_ask_cancel($ctx, $data, 'a_confirm');
    } elseif (($date = poagent_bench_a_parse_date($text)) !== null) {
        $min = (new DateTimeImmutable('today', new DateTimeZone('Asia/Jerusalem')))->format('Y-m-d');
        $max = (new DateTimeImmutable('today +1 year', new DateTimeZone('Asia/Jerusalem')))->format('Y-m-d');
        if ($date < $min || $date > $max) {
            WaClient::text($ctx['pnid'], $ctx['from'], 'תאריך האספקה צריך להיות מהיום ועד שנה קדימה.');
            poagent_bench_a_save($ctx, 'a_confirm', $data);
            return;
        }
        $data['delivery'] = $date;
        poagent_bench_a_confirm($ctx, $data);
    } else {
        WaClient::buttons($ctx['pnid'], $ctx['from'], 'לאשר את ההזמנה? (אפשר גם לשלוח תאריך אספקה אחר, למשל ' . date('dmy', strtotime('+3 days')) . ')',
            [['id' => 'yes', 'title' => 'אשר'], ['id' => 'edit', 'title' => 'ערוך'], ['id' => 'no', 'title' => 'בטל']]);
        poagent_bench_a_save($ctx, 'a_confirm', $data);
    }
}

function poagent_bench_a_commit(array $ctx, array $data): void
{
    try {
        $r = poagent_wa_commit_cart($ctx['from'], $ctx['name'], $data['supplier'], $data['cart'], $data['delivery'] ?? null, 'a');
    } catch (Throwable $e) {
        poagent_whatsapp_log(['ts' => date('c'), 'event' => 'bench_a_po_failed', 'from' => $ctx['from'], 'error' => $e->getMessage()]);
        WaClient::text($ctx['pnid'], $ctx['from'], 'לא הצלחתי ליצור את ההזמנה. נסו שוב בעוד רגע, או כתבו *אתר* והזמינו באתר.');
        poagent_bench_a_save($ctx, 'a_confirm', $data);
        return;
    }
    $po = $r['po'];
    poagent_bench_finish($ctx['from'], $data['bench'], 'created', $po['unique_id'], count($po['items']));
    poagent_whatsapp_log(['ts' => date('c'), 'direction' => 'bench_a_po_created', 'from' => $ctx['from'], 'po' => $po['unique_id'],
        'supplier' => $data['supplier'], 'lines' => count($po['items'])]);
    WaClient::text($ctx['pnid'], $ctx['from'], "✅ הזמנה {$po['unique_id']} נוצרה\n"
        . "ספק: {$po['supplier_id']}\n" . count($po['items']) . ' שורות · ' . poagent_bench_ils(POStore::totalAgorot($po))
        . "\nאספקה: " . poagent_bench_a_day($po['delivery_date'])
        . ($r['skipped'] ? "\n⚠️ " . count($r['skipped']) . ' מוצרים כבר לא במחירון ולא נכנסו' : '')
        . "\n\nלהזמנה נוספת — שלחו הודעה.");
    poagent_bench_save($ctx['from'], 'a_done', $data, $ctx['pnid'], true);
}

// ── cancel ──────────────────────────────────────────────────────────────────

function poagent_bench_a_ask_cancel(array $ctx, array $data, string $from): void
{
    if (empty($data['supplier']) && empty($data['cart'])) {   // nothing chosen yet: just start over
        poagent_bench_a_ask_supplier($ctx, $data);
        return;
    }
    $data['cancel_from'] = $from;
    $n = count($data['cart'] ?? []);
    WaClient::buttons($ctx['pnid'], $ctx['from'], 'לבטל את ההזמנה?' . ($n ? " העגלה ({$n} שורות) תימחק." : ''),
        [['id' => 'cancel_yes', 'title' => 'כן, לבטל'], ['id' => 'cancel_no', 'title' => 'המשך הזמנה']]);
    poagent_bench_a_save($ctx, 'a_cancel', $data);
}

function poagent_bench_a_cancel_reply(array $ctx, array $data, array $event): void
{
    $id = (string) ($event['reply_id'] ?? '');
    $text = trim((string) ($event['text'] ?? ''));
    $back = (string) ($data['cancel_from'] ?? 'a_build');
    unset($data['cancel_from']);
    if ($id === 'cancel_yes' || in_array($text, ['כן', 'כן, לבטל', 'ביטול', 'בטל'], true)) {
        poagent_bench_finish($ctx['from'], $data['bench'], 'cancelled', null, count($data['cart'] ?? []));
        WaClient::text($ctx['pnid'], $ctx['from'], 'ההזמנה בוטלה.');
        poagent_bench_a_ask_supplier($ctx, poagent_bench_a_new_order($data));
        return;
    }
    if ($back === 'a_confirm') {
        poagent_bench_a_confirm($ctx, $data);
    } elseif ($back === 'a_cart') {
        poagent_bench_a_show_cart($ctx, $data);
    } elseif (empty($data['supplier'])) {
        poagent_bench_a_ask_supplier($ctx, $data);
    } else {
        poagent_bench_a_build_prompt($ctx, $data, "ממשיכים.\n");
    }
}

// ── dates ───────────────────────────────────────────────────────────────────

/** "151126", "15/11/26", "15.11.2026", "15/11" → Y-m-d, or null. */
function poagent_bench_a_parse_date(string $t): ?string
{
    $t = trim($t);
    if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $t, $m)) {
        [$d, $mo, $y] = [(int) $m[1], (int) $m[2], 2000 + (int) $m[3]];
    } elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})(?:[\/.\-](\d{2}|\d{4}))?$/', $t, $m)) {
        [$d, $mo] = [(int) $m[1], (int) $m[2]];
        $y = isset($m[3]) ? (int) $m[3] + (strlen($m[3]) === 2 ? 2000 : 0) : (int) date('Y');
        if (!isset($m[3]) && sprintf('%04d-%02d-%02d', $y, $mo, $d) < date('Y-m-d')) $y++;   // "5/1" in November = next January
    } else {
        return null;
    }
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/** "יום ראשון 15/11/26" */
function poagent_bench_a_day(string $ymd): string
{
    $days = ['ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת'];
    $t = strtotime($ymd);
    return 'יום ' . $days[(int) date('w', $t)] . ' ' . date('d/m/y', $t);
}

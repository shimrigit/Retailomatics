<?php
/**
 * POAgent — WhatsApp PO conversation flow.
 *
 * ┌───────────────────────────────────────────────────────────────────────┐
 * │ PARKED — NOT WIRED IN.  bot.php no longer requires this file. The      │
 * │ phone user now drives the real PO web pages via a login link          │
 * │ (bot.php → mobile_link.php → POAgent/m/). This in-chat flow is kept    │
 * │ intact in case a conversational path is wanted again; to re-enable,    │
 * │ require it from bot.php and restore the state dispatch there.          │
 * └───────────────────────────────────────────────────────────────────────┘
 *
 * The mobile counterpart of the laptop PO screens
 * (po_supplier.php → po_items.php → po_confirm.php → po_create.php) and
 * po_list.php. Same brain: every write goes through POStore::createPO(), and
 * every price/name is re-derived from SupplierStore by barcode (the user's
 * typed text is never trusted for pricing) — so a PO created here is
 * byte-identical in shape to a laptop one and appears in po_list.php /
 * po_view.php with no special-casing.
 *
 * Identity: the PO's generator_id is the sender's PHONE NUMBER ($from, digits)
 * — the stable, unique key (and the hook a future step will use to match a
 * laptop user to their phone). The apps.json name is only a display nickname
 * for greetings, carried on the session as data.user_name; it may change or
 * repeat and is never the stored identity.
 *
 * Conversation states (WaSessionStore `state` + `data`):
 *   main_menu        — 4-option list sent; waiting for a pick            (bot.php)
 *   po_pick_supplier — supplier list sent; data.supplier_list = ordered ids
 *   po_build         — waiting for a search term, an "<n>" / "<n>*<qty>" pick
 *                      from data.last_results (ordered barcodes), or 'סיום' /
 *                      'ביטול'. data.cart = { barcode: qty }
 *   po_confirm       — order summary + [אשר] [הוסף פריט] [ביטול] sent; waiting
 *
 * bot.php owns dispatch, the menu send (poagent_wa_send_main_menu), the
 * idle-timeout sweep, and poagent_whatsapp_log(); this file is required from
 * there, so those symbols are always available at call time.
 */

require_once __DIR__ . '/../../whatsapp_app/lib/WaClient.php';
require_once __DIR__ . '/../../whatsapp_app/lib/WaSessionStore.php';
require_once __DIR__ . '/../lib/SupplierStore.php';
require_once __DIR__ . '/../lib/POStore.php';

const POAGENT_WA_SEARCH_LIMIT  = 9;  // max catalog matches shown per search
const POAGENT_WA_HISTORY_LIMIT = 10; // max POs listed in "היסטוריית הזמנות"

/* ── shared session write ─────────────────────────────────────────────────── */

/**
 * Persist state + data, always refreshing the idle-timeout bookkeeping the
 * sweep in bot.php relies on: every inbound message resets the idle clock and
 * cancels any reminder already sent.
 */
function poagent_wa_set_state(string $from, string $state, array $data, string $phoneNumberId): void
{
    $data['business_phone_number_id'] = $phoneNumberId;
    $data['last_inbound_at']          = date('c');
    $data['reminder_sent_at']         = null;
    WaSessionStore::set($from, POAGENT_WA_APP_ID, $state, $data);
}

/** Words that mean "take me back to the main menu" from anywhere in the flow. */
function poagent_wa_is_menu_word(string $low): bool
{
    return in_array($low, ['תפריט', 'menu', 'ביטול', 'בטל', 'cancel', 'חזרה'], true);
}

/** Words that mean "I'm done adding items — show me the order to confirm". */
function poagent_wa_is_done_word(string $low): bool
{
    return in_array($low, ['סיום', 'סיים', 'סיימתי', 'אישור', 'אשר', 'done', 'ok'], true);
}

/* ── main menu (state: main_menu) ─────────────────────────────────────────── */

function poagent_wa_handle_main_menu(array $event, string $from, string $phoneNumberId, array $data): void
{
    $replyId = (string) ($event['reply_id'] ?? '');
    $text    = trim((string) ($event['text'] ?? ''));
    $low     = mb_strtolower($text);

    // Numbered / keyword fallback for a client that renders the list as plain
    // text, or a user who just types. Check היסטור before הזמנה — "היסטוריית
    // הזמנות" contains both.
    $choice = $replyId;
    if ($choice === '') {
        $numbered = [
            '1' => POAGENT_WA_MENU_PO_NEW,  '2' => POAGENT_WA_MENU_DN,
            '3' => POAGENT_WA_MENU_HISTORY, '4' => POAGENT_WA_MENU_END,
        ];
        if (isset($numbered[$text])) {
            $choice = $numbered[$text];
        } elseif (mb_strpos($low, 'היסטור') !== false) {
            $choice = POAGENT_WA_MENU_HISTORY;
        } elseif (mb_strpos($low, 'תעוד') !== false || mb_strpos($low, 'משלוח') !== false) {
            $choice = POAGENT_WA_MENU_DN;
        } elseif (mb_strpos($low, 'הזמנה') !== false || mb_strpos($low, 'הזמנת') !== false) {
            $choice = POAGENT_WA_MENU_PO_NEW;
        } elseif (in_array($low, ['סיום', 'סיים', 'end', 'bye'], true)) {
            $choice = POAGENT_WA_MENU_END;
        }
    }

    switch ($choice) {
        case POAGENT_WA_MENU_PO_NEW:
            poagent_wa_start_po($from, $phoneNumberId, $data);
            return;

        case POAGENT_WA_MENU_DN:
            WaClient::text($phoneNumberId, $from,
                "העלאת תעודת משלוח מתבצעת כרגע דרך ממשק הדפדפן במחשב.\n" .
                'כאן ניתן להקים הזמנות רכש ולצפות בהיסטוריה.');
            poagent_wa_send_main_menu($from, $phoneNumberId, $data);
            return;

        case POAGENT_WA_MENU_HISTORY:
            WaClient::text($phoneNumberId, $from, poagent_wa_format_history(
                poagent_wa_list_pos_for($from),          // keyed by phone number
                (string) ($data['user_name'] ?? '')      // display nick only
            ));
            poagent_wa_send_main_menu($from, $phoneNumberId, $data);
            return;

        case POAGENT_WA_MENU_END:
            WaClient::text($phoneNumberId, $from,
                'השיחה הסתיימה. כדי להתחיל מחדש, שלח/י לנו הודעה כלשהי. 👋');
            WaSessionStore::clear($from);
            poagent_whatsapp_log(['ts' => date('c'), 'direction' => 'session_ended_by_user', 'from' => $from]);
            return;

        default:
            WaClient::text($phoneNumberId, $from, 'לא זיהיתי את הבחירה.');
            poagent_wa_send_main_menu($from, $phoneNumberId, $data);
            return;
    }
}

/* ── start PO: send the supplier list ─────────────────────────────────────── */

function poagent_wa_start_po(string $from, string $phoneNumberId, array $data): void
{
    $suppliers = SupplierStore::listSuppliers();
    if (empty($suppliers)) {
        WaClient::text($phoneNumberId, $from, 'לא הוגדרו ספקים במערכת. פנה/י למנהל המערכת.');
        poagent_wa_send_main_menu($from, $phoneNumberId, $data);
        return;
    }

    $sent = poagent_wa_send_supplier_list($from, $phoneNumberId, $suppliers);

    $data['supplier_list'] = array_values($suppliers);
    $data['cart']          = [];
    $data['last_results']  = [];
    unset($data['supplier_id']);
    poagent_wa_set_state($from, 'po_pick_supplier', $data, $phoneNumberId);

    poagent_whatsapp_log(['ts' => date('c'), 'direction' => 'supplier_list_sent',
        'from' => $from, 'count' => count($suppliers), 'reply_sent' => $sent]);
}

function poagent_wa_send_supplier_list(string $from, string $phoneNumberId, array $suppliers): bool
{
    $rows = [];
    foreach (array_slice($suppliers, 0, 10) as $s) {
        $rows[] = ['id' => 'sup:' . $s, 'title' => $s];
    }
    $body = 'בחר/י ספק להזמנה:';
    if (count($suppliers) > 10) {
        $body .= "\n(מוצגים 10 הראשונים — או הקלד/י שם ספק מדויק)";
    }
    return WaClient::list($phoneNumberId, $from, $body, $rows, 'ספקים', 'ספקים');
}

/* ── state: po_pick_supplier ─────────────────────────────────────────────── */

function poagent_wa_handle_pick_supplier(array $event, string $from, string $phoneNumberId, array $data): void
{
    $replyId = (string) ($event['reply_id'] ?? '');
    $text    = trim((string) ($event['text'] ?? ''));
    $low     = mb_strtolower($text);

    if (poagent_wa_is_menu_word($low)) {
        poagent_wa_send_main_menu($from, $phoneNumberId, $data);
        return;
    }

    $supplierList = is_array($data['supplier_list'] ?? null) && $data['supplier_list']
        ? $data['supplier_list']
        : SupplierStore::listSuppliers();

    $picked = '';
    if (strpos($replyId, 'sup:') === 0) {
        $picked = substr($replyId, 4);
    } elseif (ctype_digit($text) && (int) $text >= 1 && (int) $text <= count($supplierList)) {
        $picked = (string) $supplierList[(int) $text - 1];
    } else {
        foreach ($supplierList as $s) {
            if (mb_strtolower((string) $s) === $low) {
                $picked = (string) $s;
                break;
            }
        }
    }

    if ($picked === '' || !SupplierStore::exists($picked)) {
        WaClient::text($phoneNumberId, $from, 'לא זיהיתי ספק. בחר/י מהרשימה או שלח/י מספר.');
        poagent_wa_send_supplier_list($from, $phoneNumberId, $supplierList);
        poagent_wa_set_state($from, 'po_pick_supplier', $data, $phoneNumberId);
        return;
    }

    $data['supplier_id']  = $picked;
    $data['cart']         = [];
    $data['last_results'] = [];
    poagent_wa_set_state($from, 'po_build', $data, $phoneNumberId);

    WaClient::text($phoneNumberId, $from,
        "ספק נבחר: {$picked}\n\n" .
        "חפש/י פריט לפי שם או ברקוד (הקלד/י כמה תווים).\n" .
        "להוספה מהתוצאות: מספר, ואפשר גם כמות — למשל 2 או 2*3.\n" .
        "'סיום' לאישור ההזמנה · 'ביטול' לתפריט.");

    poagent_whatsapp_log(['ts' => date('c'), 'direction' => 'supplier_picked',
        'from' => $from, 'supplier' => $picked]);
}

/* ── state: po_build (search / add items) ────────────────────────────────── */

function poagent_wa_handle_build(array $event, string $from, string $phoneNumberId, array $data): void
{
    $text = trim((string) ($event['text'] ?? ''));
    $low  = mb_strtolower($text);

    $supplierId  = (string) ($data['supplier_id'] ?? '');
    $cart        = is_array($data['cart'] ?? null) ? $data['cart'] : [];
    $lastResults = is_array($data['last_results'] ?? null) ? array_values($data['last_results']) : [];

    if ($supplierId === '' || !SupplierStore::exists($supplierId)) {
        WaClient::text($phoneNumberId, $from, 'משהו השתבש בבחירת הספק. נחזור לתפריט.');
        poagent_wa_send_main_menu($from, $phoneNumberId, $data);
        return;
    }

    if (poagent_wa_is_menu_word($low)) {
        poagent_wa_send_main_menu($from, $phoneNumberId, $data);
        return;
    }

    if (poagent_wa_is_done_word($low)) {
        poagent_wa_present_confirmation($from, $phoneNumberId, $data, $supplierId, $cart);
        return;
    }

    // "<n>" / "<n>*<qty>" / "<n> <qty>" / "<n>x<qty>" — a pick from the last
    // result set. Only a pick when <n> actually indexes that set; otherwise it
    // falls through to a search (so pasting a full barcode still searches).
    if (preg_match('/^(\d+)\s*(?:[*xX×]\s*(\d+))?$/u', $text, $m)
        && (int) $m[1] >= 1 && (int) $m[1] <= count($lastResults)) {
        $barcode = (string) $lastResults[(int) $m[1] - 1];
        $qty     = (isset($m[2]) && $m[2] !== '') ? max(1, (int) $m[2]) : 1;

        $item = poagent_wa_catalog_item($supplierId, $barcode);
        if ($item === null) {
            WaClient::text($phoneNumberId, $from, 'הפריט לא נמצא יותר בקטלוג הספק. חפש/י שוב.');
            poagent_wa_set_state($from, 'po_build', $data, $phoneNumberId);
            return;
        }

        $cart[$barcode] = (int) ($cart[$barcode] ?? 0) + $qty;
        $data['cart']   = $cart;
        poagent_wa_set_state($from, 'po_build', $data, $phoneNumberId);

        $items    = poagent_wa_derive_items($supplierId, $cart);
        $subtotal = poagent_wa_items_total($items);
        WaClient::text($phoneNumberId, $from,
            "✓ נוסף: {$item['name']} × {$qty}  (מחיר יח' " . poagent_wa_ils((int) $item['price_agorot']) . ")\n" .
            'בסל: ' . count($items) . ' פריטים · סה"כ ביניים ' . poagent_wa_ils($subtotal) . "\n\n" .
            "חפש/י פריט נוסף, או שלח/י 'סיום' לאישור.");

        poagent_whatsapp_log(['ts' => date('c'), 'direction' => 'item_added', 'from' => $from,
            'supplier' => $supplierId, 'barcode' => $barcode, 'qty' => $qty]);
        return;
    }

    // Otherwise: treat as a search term.
    if ($text === '') {
        WaClient::text($phoneNumberId, $from, 'הקלד/י מונח לחיפוש פריט, או שלח/י \'סיום\'.');
        poagent_wa_set_state($from, 'po_build', $data, $phoneNumberId);
        return;
    }

    $matches = poagent_wa_search_catalog($supplierId, $text);
    if (empty($matches)) {
        WaClient::text($phoneNumberId, $from, "לא נמצאו פריטים עבור \"{$text}\". נסה/י מונח אחר.");
        poagent_wa_set_state($from, 'po_build', $data, $phoneNumberId);
        return;
    }

    $shown = array_slice($matches, 0, POAGENT_WA_SEARCH_LIMIT);
    $data['last_results'] = array_map(fn($it) => (string) $it['barcode'], $shown);
    poagent_wa_set_state($from, 'po_build', $data, $phoneNumberId);

    $lines = ["תוצאות עבור \"{$text}\":", ''];
    foreach ($shown as $i => $it) {
        $lines[] = ($i + 1) . ". {$it['name']} — {$it['barcode']} — " . poagent_wa_ils((int) $it['price_agorot']);
    }
    $lines[] = '';
    if (count($matches) > count($shown)) {
        $lines[] = 'מוצגות ' . count($shown) . ' תוצאות ראשונות — צמצם/י את החיפוש.';
        $lines[] = '';
    }
    $lines[] = 'להוספה שלח/י מספר (ואפשר כמות): למשל 2 או 2*3.';
    WaClient::text($phoneNumberId, $from, implode("\n", $lines));

    poagent_whatsapp_log(['ts' => date('c'), 'direction' => 'search', 'from' => $from,
        'supplier' => $supplierId, 'term' => $text, 'matches' => count($matches)]);
}

function poagent_wa_present_confirmation(string $from, string $phoneNumberId, array $data, string $supplierId, array $cart): void
{
    $items = poagent_wa_derive_items($supplierId, $cart);
    if (empty($items)) {
        WaClient::text($phoneNumberId, $from,
            "עדיין לא נבחרו פריטים. חפש/י פריט, או שלח/י 'ביטול' לחזרה לתפריט.");
        poagent_wa_set_state($from, 'po_build', $data, $phoneNumberId);
        return;
    }

    WaClient::text($phoneNumberId, $from, poagent_wa_format_order_summary($supplierId, $items));
    WaClient::buttons($phoneNumberId, $from, 'לאישור ההזמנה:', [
        ['id' => 'po_confirm_yes', 'title' => 'אשר הזמנה'],
        ['id' => 'po_confirm_add', 'title' => 'הוסף פריט'],
        ['id' => 'po_confirm_no',  'title' => 'ביטול'],
    ]);
    poagent_wa_set_state($from, 'po_confirm', $data, $phoneNumberId);
}

/* ── state: po_confirm ──────────────────────────────────────────────────── */

function poagent_wa_handle_confirm(array $event, string $from, string $phoneNumberId, array $data): void
{
    $replyId = (string) ($event['reply_id'] ?? '');
    $low     = mb_strtolower(trim((string) ($event['text'] ?? '')));

    $supplierId = (string) ($data['supplier_id'] ?? '');
    $cart       = is_array($data['cart'] ?? null) ? $data['cart'] : [];

    $add = $replyId === 'po_confirm_add' || in_array($low, ['הוסף', 'הוסף פריט', 'עוד'], true);
    $no  = $replyId === 'po_confirm_no'  || in_array($low, ['לא', 'no'], true) || poagent_wa_is_menu_word($low);
    $yes = $replyId === 'po_confirm_yes' || in_array($low, ['אשר', 'אשר הזמנה', 'אישור', 'כן', 'yes'], true);

    if ($add) {
        poagent_wa_set_state($from, 'po_build', $data, $phoneNumberId);
        WaClient::text($phoneNumberId, $from, "חזרה לבחירת פריטים. חפש/י פריט, או 'סיום' לאישור.");
        return;
    }

    if ($no) {
        WaClient::text($phoneNumberId, $from, 'ההזמנה בוטלה.');
        poagent_wa_send_main_menu($from, $phoneNumberId, $data);
        return;
    }

    if (!$yes) {
        WaClient::text($phoneNumberId, $from,
            "השב/י 'אשר' לאישור ההזמנה, 'הוסף' להוספת פריט, או 'ביטול'.");
        poagent_wa_set_state($from, 'po_confirm', $data, $phoneNumberId);
        return;
    }

    // Confirmed → create. Re-derive once more; never trust the cart blindly.
    $items = poagent_wa_derive_items($supplierId, $cart);
    if ($supplierId === '' || !SupplierStore::exists($supplierId) || empty($items)) {
        WaClient::text($phoneNumberId, $from,
            'לא ניתן ליצור את ההזמנה (חסר ספק או פריטים). נחזור לתפריט.');
        poagent_wa_send_main_menu($from, $phoneNumberId, $data);
        return;
    }

    try {
        // generator_id = the phone number ($from): the unique, stable identity.
        // data.user_name is a display nick only and is never stored as identity.
        $record = POStore::createPO($from, $supplierId, $items);
    } catch (\Throwable $e) {
        poagent_whatsapp_log(['ts' => date('c'), 'event' => 'po_create_failed',
            'from' => $from, 'error' => $e->getMessage()]);
        WaClient::text($phoneNumberId, $from, 'אירעה שגיאה ביצירת ההזמנה. נסה/י שוב מאוחר יותר.');
        poagent_wa_send_main_menu($from, $phoneNumberId, $data);
        return;
    }

    WaClient::text($phoneNumberId, $from,
        poagent_wa_format_po_created($record, (string) ($data['user_name'] ?? '')));
    poagent_whatsapp_log(['ts' => date('c'), 'direction' => 'po_created', 'from' => $from,
        'generator_id' => $from, 'display_name' => (string) ($data['user_name'] ?? ''),
        'po' => $record['unique_id'] ?? '', 'core_name' => $record['core_name'] ?? '',
        'supplier' => $supplierId, 'items' => count($items)]);

    poagent_wa_send_main_menu($from, $phoneNumberId, $data);
}

/* ── catalog / pricing helpers (mirror po_confirm.php / po_create.php) ────── */

function poagent_wa_ils(int $agorot): string
{
    return number_format($agorot / 100, 2) . ' ₪';
}

function poagent_wa_catalog_item(string $supplierId, string $barcode): ?array
{
    foreach (SupplierStore::getCatalog($supplierId) as $it) {
        if ((string) $it['barcode'] === $barcode) {
            return $it;
        }
    }
    return null;
}

/**
 * Re-derive PO line items from a { barcode: qty } cart — names and prices
 * straight from the supplier catalog, exactly like po_confirm.php /
 * po_create.php. Unknown barcodes and non-positive quantities are dropped.
 *
 * @return list<array{barcode:string,name:string,qty:int,unit_price_agorot:int}>
 */
function poagent_wa_derive_items(string $supplierId, array $cart): array
{
    if ($supplierId === '' || !SupplierStore::exists($supplierId)) {
        return [];
    }
    $byBarcode = [];
    foreach (SupplierStore::getCatalog($supplierId) as $it) {
        $byBarcode[(string) $it['barcode']] = $it;
    }
    $items = [];
    foreach ($cart as $barcode => $qtyRaw) {
        $barcode = (string) $barcode;
        $qty     = (int) $qtyRaw;
        if ($qty <= 0 || !isset($byBarcode[$barcode])) {
            continue;
        }
        $c = $byBarcode[$barcode];
        $items[] = [
            'barcode'           => (string) $c['barcode'],
            'name'              => (string) $c['name'],
            'qty'               => $qty,
            'unit_price_agorot' => (int) $c['price_agorot'],
        ];
    }
    return $items;
}

function poagent_wa_items_total(array $items): int
{
    $total = 0;
    foreach ($items as $it) {
        $total += (int) $it['qty'] * (int) $it['unit_price_agorot'];
    }
    return $total;
}

/**
 * Catalog search: prefix matches (name or barcode) ranked above substring
 * matches — same ranking as po_items.php's client-side typeahead. Returns the
 * full ranked list; the caller slices to POAGENT_WA_SEARCH_LIMIT.
 */
function poagent_wa_search_catalog(string $supplierId, string $term): array
{
    $term    = mb_strtolower(trim($term));
    $catalog = SupplierStore::getCatalog($supplierId);
    if ($term === '') {
        return $catalog;
    }

    $starts   = [];
    $contains = [];
    foreach ($catalog as $it) {
        $name    = mb_strtolower((string) $it['name']);
        $barcode = mb_strtolower((string) $it['barcode']);
        if (strpos($name, $term) === 0 || strpos($barcode, $term) === 0) {
            $starts[] = $it;
        } elseif (strpos($name, $term) !== false || strpos($barcode, $term) !== false) {
            $contains[] = $it;
        }
    }
    return array_merge($starts, $contains);
}

/* ── text renderers ─────────────────────────────────────────────────────── */

function poagent_wa_format_order_summary(string $supplierId, array $items): string
{
    $lines = ["📋 סיכום הזמנה — ספק {$supplierId}", ''];
    $total = 0;
    foreach ($items as $i => $it) {
        $lineTotal = (int) $it['qty'] * (int) $it['unit_price_agorot'];
        $total    += $lineTotal;
        $lines[]   = ($i + 1) . ". {$it['name']}";
        $lines[]   = '   ' . (int) $it['qty'] . ' × ' . poagent_wa_ils((int) $it['unit_price_agorot'])
                   . ' = ' . poagent_wa_ils($lineTotal);
    }
    $lines[] = '';
    $lines[] = 'סה"כ להזמנה: ' . poagent_wa_ils($total);
    return implode("\n", $lines);
}

function poagent_wa_format_po_created(array $record, string $displayName = ''): string
{
    $by = $displayName !== '' ? $displayName : (string) ($record['generator_id'] ?? '');
    $lines = [
        "✅ הזמנת רכש {$record['unique_id']} נוצרה בהצלחה.",
        '',
        "ספק: {$record['supplier_id']}",
        'נוצר ע"י: ' . $by,
        '',
    ];
    $total = 0;
    foreach ($record['items'] ?? [] as $it) {
        $lineTotal = (int) $it['qty'] * (int) $it['unit_price_agorot'];
        $total    += $lineTotal;
        $lines[]   = "• {$it['name']}  (" . (int) $it['qty'] . ' × '
                   . poagent_wa_ils((int) $it['unit_price_agorot']) . ' = ' . poagent_wa_ils($lineTotal) . ')';
    }
    $lines[] = '';
    $lines[] = 'סה"כ: ' . poagent_wa_ils($total);
    return implode("\n", $lines);
}

function poagent_wa_status_he(string $status): string
{
    return [
        'open'      => 'פתוחה',
        'prcv'      => 'נקלטה חלקית',
        'closed'    => 'נסגרה',
        'cancelled' => 'בוטלה',
    ][$status] ?? $status;
}

/**
 * This mobile user's own POs, newest first — keyed by phone number, matched
 * against the exact generator_id stored in each PO's JSON body.
 */
function poagent_wa_list_pos_for(string $generatorId): array
{
    $out = [];
    foreach (POStore::listPOs(null) as $po) {
        if ((string) ($po['generator_id'] ?? '') === $generatorId) {
            $out[] = $po;
        }
    }
    return $out; // listPOs() already sorts newest-first
}

function poagent_wa_format_history(array $pos, string $displayName): string
{
    if (empty($pos)) {
        return 'אין הזמנות קודמות' . ($displayName !== '' ? " עבור {$displayName}" : '') . '.';
    }

    $lines = ['📋 ההזמנות האחרונות שלך:', ''];
    foreach (array_slice($pos, 0, POAGENT_WA_HISTORY_LIMIT) as $po) {
        $raw  = (string) ($po['date_generated'] ?? '');
        $ts   = $raw !== '' ? strtotime($raw) : false;
        $date = $ts !== false ? date('d/m/Y', $ts) : $raw;

        $total = 0;
        foreach ($po['items'] ?? [] as $it) {
            $total += (int) ($it['qty'] ?? 0) * (int) ($it['unit_price_agorot'] ?? 0);
        }

        $lines[] = ($po['unique_id'] ?? '') . ' · ' . ($po['supplier_id'] ?? '') . ' · ' . $date;
        $lines[] = '   ' . poagent_wa_status_he((string) ($po['status'] ?? ''))
                 . ' · ' . count($po['items'] ?? []) . ' פריטים · ' . poagent_wa_ils($total);
    }
    return implode("\n", $lines);
}

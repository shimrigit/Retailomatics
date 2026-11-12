<?php
/**
 * bench_b_flow.php — WhatsApp ordering, method B: a WhatsApp Flow (a form
 * inside WhatsApp; spec "אפיון בנצ'מרק הזמנת רכש בוואטסאפ", method B).
 *
 * The chat only opens it: a message to a phone set to method B (users.php)
 * gets a Flow message with an "פתח הזמנה" button (poagent_bench_b_handle).
 * The order itself happens in the Flow's screens, served by the data endpoint
 * (whatsapp_app/flow_endpoint.php → poagent_bench_b_endpoint):
 *   SUPPLIER  every supplier in a dropdown, the recent ones first; "start from the previous order"
 *   PRODUCTS  the cart, a search box, the results (or the cart's lines) in a dropdown, a quantity;
 *             "חפש / הוסף": a product + quantity sets that line (0 removes), else searches;
 *             "לסיכום ואישור" → CONFIRM. Coming back to it (BACK) refreshes it.
 *   CONFIRM   summary + delivery date → "שלח הזמנה" completes the Flow
 * (Flow JSON: whatsapp/flows/po_order.flow.json. Meta allows only forward
 * routes between screens, so the cart is part of PRODUCTS, not a screen of its own.)
 * The completion comes back to the webhook as an nfm_reply message; the PO is
 * made from the cart kept here in the session — never from what the form sent.
 *
 * The flow_token: "b." + base64url({p: phone, r: the order's ref, exp}) + HMAC
 * (mobile_link.php's secret). A token whose ref isn't the phone's open order
 * (an old message's button, an order already sent) is refused (endpoint 427).
 *
 * The Flow's id and mode come from whatsapp_app/config.json: "flow_id_po_order",
 * "flow_mode" (draft until it's published in WhatsApp Manager).
 */

require_once __DIR__ . '/../../whatsapp_app/lib/FlowCrypto.php';   // WaFlowTokenError

const POAGENT_BENCH_B_TOKEN_TTL   = 172800;   // 48 hours
const POAGENT_BENCH_B_RESULTS_MAX = 50;       // dropdown options per search (Meta allows 200)
const POAGENT_BENCH_B_CART_SHOWN  = 30;       // cart lines written out on PRODUCTS / CONFIRM

/** ['flow_id' => ..., 'mode' => 'draft'|'published']; tests define POAGENT_BENCH_B_FLOW_ID. */
function poagent_bench_b_config(): array
{
    if (defined('POAGENT_BENCH_B_FLOW_ID')) return ['flow_id' => POAGENT_BENCH_B_FLOW_ID, 'mode' => 'draft'];
    $cfg = json_decode((string) @file_get_contents(__DIR__ . '/../../whatsapp_app/config.json'), true) ?: [];
    return ['flow_id' => (string) ($cfg['flow_id_po_order'] ?? ''), 'mode' => ($cfg['flow_mode'] ?? 'draft') === 'published' ? 'published' : 'draft'];
}

function poagent_bench_b_token(string $phone, string $ref): string
{
    $body = 'b.' . poagent_wa_b64url_encode(json_encode(['p' => $phone, 'r' => $ref, 'exp' => time() + POAGENT_BENCH_B_TOKEN_TTL]));
    return $body . '.' . poagent_wa_b64url_encode(hash_hmac('sha256', $body, poagent_wa_link_secret(), true));
}

/** ['p' => phone, 'r' => ref] or null (malformed / forged / expired). */
function poagent_bench_b_verify(string $token): ?array
{
    if (!preg_match('/^(b\.[A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $token, $m)) return null;
    if (!hash_equals(hash_hmac('sha256', $m[1], poagent_wa_link_secret(), true), poagent_wa_b64url_decode($m[2]))) return null;
    $p = json_decode(poagent_wa_b64url_decode(substr($m[1], 2)), true);
    return is_array($p) && ($p['exp'] ?? 0) > time() && !empty($p['p']) && !empty($p['r']) ? ['p' => (string) $p['p'], 'r' => (string) $p['r']] : null;
}

// ── the chat side ───────────────────────────────────────────────────────────

function poagent_bench_b_handle(array $event, string $name): void
{
    $from = (string) $event['from'];
    $pnid = (string) ($event['business_phone_number_id'] ?? '');
    $raw = $event['raw_message'] ?? [];
    if (($raw['interactive']['type'] ?? '') === 'nfm_reply') {
        poagent_bench_b_complete($from, $pnid, $name, $event, $raw['interactive']['nfm_reply'] ?? []);
        return;
    }
    $cfg = poagent_bench_b_config();
    if ($cfg['flow_id'] === '') {
        WaClient::text($pnid, $from, "שיטה B (טופס) עוד לא הופעלה — חסר מזהה הטופס (flow_id_po_order).\nכדי להיכנס לאתר כתבו: אתר");
        return;
    }
    $session = WaSessionStore::get($from);
    $data = $session['data'] ?? [];
    $again = ($session['state'] ?? '') === 'b_open' && !empty($data['bench']) && !empty($data['b_ref']);
    if (!$again) {
        $scenario = (string) ($data['scenario'] ?? '');
        $data = ['scenario' => $scenario, 'bench' => poagent_bench_new('b', $scenario), 'b_ref' => bin2hex(random_bytes(6)),
            'cart' => [], 'supplier' => ''];
    }
    poagent_bench_note($data['bench'], $event);
    $body = $again
        ? "הטופס פתוח — ההזמנה והעגלה שמורות. לחצו להמשך 👇"
        : 'שלום' . ($name !== '' ? " {$name}" : '') . " 👋\nהזמנת רכש חדשה — הכול בטופס אחד: ספק, מוצרים, כמויות ואישור.";
    $sent = WaClient::flow($pnid, $from, $body, 'פתח הזמנה', $cfg['flow_id'], poagent_bench_b_token($from, $data['b_ref']), $cfg['mode'],
        'אפשר לסגור ולפתוח שוב — העגלה נשמרת');
    if (!$sent) {
        // Meta refused the Flow message (e.g. #139000 "Blocked by Integrity" — the business not
        // verified yet; the reason is in whatsapp_app/logs/send_*.log). Say so instead of silence.
        poagent_whatsapp_log(['ts' => date('c'), 'event' => 'bench_b_flow_not_sent', 'from' => $from]);
        WaClient::text($pnid, $from, "הטופס של שיטה B לא זמין כרגע (וואטסאפ לא אישר לשלוח אותו).\nכדי להזמין באתר כתבו: אתר");
        return;
    }
    poagent_bench_save($from, 'b_open', $data, $pnid);
}

/** The Flow was sent ("שלח הזמנה"): the PO from the kept cart. A second completion of the same order does nothing. */
function poagent_bench_b_complete(string $from, string $pnid, string $name, array $event, array $nfm): void
{
    $resp = json_decode((string) ($nfm['response_json'] ?? ''), true) ?: [];
    $tok = poagent_bench_b_verify((string) ($resp['flow_token'] ?? ''));
    $session = WaSessionStore::get($from);
    $data = $session['data'] ?? [];
    if (!$tok || $tok['p'] !== $from || ($session['state'] ?? '') !== 'b_open' || ($data['b_ref'] ?? '') !== $tok['r']) {
        poagent_whatsapp_log(['ts' => date('c'), 'event' => 'bench_b_completion_ignored', 'from' => $from,
            'reason' => !$tok ? 'bad token' : 'not the open order (already sent?)']);
        return;
    }
    poagent_bench_note($data['bench'], $event);
    $date = (string) ($resp['delivery_date'] ?? '');
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    try {
        $r = poagent_wa_commit_cart($from, $name, (string) $data['supplier'], $data['cart'] ?? [], $date, 'b');
    } catch (Throwable $e) {
        poagent_whatsapp_log(['ts' => date('c'), 'event' => 'bench_b_po_failed', 'from' => $from, 'error' => $e->getMessage()]);
        WaClient::text($pnid, $from, 'לא הצלחתי ליצור את ההזמנה. פתחו את הטופס שוב ונסו שוב, או כתבו *אתר* והזמינו באתר.');
        poagent_bench_save($from, 'b_open', $data, $pnid);
        return;
    }
    $po = $r['po'];
    poagent_bench_finish($from, $data['bench'], 'created', $po['unique_id'], count($po['items']));
    poagent_whatsapp_log(['ts' => date('c'), 'direction' => 'bench_b_po_created', 'from' => $from, 'po' => $po['unique_id'],
        'supplier' => $po['supplier_id'], 'lines' => count($po['items'])]);
    $lines = [];
    foreach (array_slice($po['items'], 0, POAGENT_BENCH_B_CART_SHOWN) as $it) {
        $lines[] = "• {$it['name']} × {$it['qty']}";
    }
    if (count($po['items']) > POAGENT_BENCH_B_CART_SHOWN) $lines[] = '…ועוד ' . (count($po['items']) - POAGENT_BENCH_B_CART_SHOWN);
    WaClient::text($pnid, $from, "✅ הזמנה {$po['unique_id']} נוצרה\nספק: {$po['supplier_id']}\n" . implode("\n", $lines)
        . "\n\n" . count($po['items']) . ' שורות · ' . poagent_bench_ils(POStore::totalAgorot($po))
        . "\nאספקה: " . date('d/m/y', strtotime($po['delivery_date']))
        . ($r['skipped'] ? "\n⚠️ " . count($r['skipped']) . ' מוצרים כבר לא במחירון ולא נכנסו' : '')
        . "\n\nלהזמנה נוספת — שלחו הודעה.");
    poagent_bench_save($from, 'b_done', $data, $pnid, true);
}

// ── the endpoint side (whatsapp_app/flow_endpoint.php) ─────────────────────

/** A decrypted endpoint request (INIT / BACK / data_exchange) → {screen, data}. */
function poagent_bench_b_endpoint(array $req): array
{
    $tok = poagent_bench_b_verify((string) ($req['flow_token'] ?? ''));
    if (!$tok) throw new WaFlowTokenError('bad or expired token');
    $from = $tok['p'];
    $session = WaSessionStore::get($from);
    $data = $session['data'] ?? [];
    if (($session['state'] ?? '') !== 'b_open' || ($data['b_ref'] ?? '') !== $tok['r']) {
        throw new WaFlowTokenError('not the phone\'s open order');
    }
    $in = is_array($req['data'] ?? null) ? $req['data'] : [];
    $data['bench']['inbound_msgs']++;
    $data['bench']['taps']++;   // opening the form, a button, coming back

    switch ($req['action'] ?? '') {
        case 'INIT':
            $resp = poagent_bench_b_supplier_screen($from, $data);
            break;
        case 'BACK':
            $resp = poagent_bench_b_products_screen($data);
            break;
        default:   // data_exchange
            $resp = ($req['screen'] ?? '') === 'SUPPLIER'
                ? poagent_bench_b_pick_supplier($from, $data, $in)
                : poagent_bench_b_products_action($data, $in);
    }
    poagent_bench_save($from, 'b_open', $data, (string) ($data['business_phone_number_id'] ?? ''));
    return $resp;
}

function poagent_bench_b_supplier_screen(string $from, array &$data, string $error = ''): array
{
    $recent = array_values(array_filter(POStore::recentSuppliersFor($from, 10), [CatalogIndex::class, 'exists']));
    $all = array_values(array_unique(array_merge($recent, CatalogIndex::suppliers())));
    $data['sup_map'] = $all;
    $options = [];
    foreach ($all as $i => $name) {
        $opt = ['id' => (string) $i, 'title' => mb_substr($name, 0, 30)];
        $desc = trim((in_array($name, $recent, true) ? 'הזמנתם לאחרונה' : '') . (mb_strlen($name) > 30 ? " · {$name}" : ''), ' ·');
        if ($desc !== '') $opt['description'] = $desc;
        $options[] = $opt;
    }
    $open = count($data['cart'] ?? []);
    $intro = $open && ($data['supplier'] ?? '') !== ''
        ? "יש לכם עגלה פתוחה אצל {$data['supplier']} ({$open} שורות) — בחרו בו כדי להמשיך."
        : 'בחרו ספק. הספקים שהזמנתם מהם לאחרונה — ראשונים ברשימה.';
    $out = ['screen' => 'SUPPLIER', 'data' => ['suppliers' => $options, 'intro' => $intro]];
    if ($error !== '') $out['data']['error_message'] = $error;
    return $out;
}

function poagent_bench_b_pick_supplier(string $from, array &$data, array $in): array
{
    $supplier = $data['sup_map'][(int) ($in['supplier'] ?? -1)] ?? null;
    if (($in['supplier'] ?? '') === '' || $supplier === null || !CatalogIndex::exists($supplier)) {
        return poagent_bench_b_supplier_screen($from, $data, 'בחרו ספק מהרשימה');
    }
    $notice = '';
    if (filter_var($in['from_last'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $last = POStore::lastPoFor($from, $supplier);
        $data['cart'] = [];
        $missing = 0;
        foreach ($last['items'] ?? [] as $it) {
            if (CatalogIndex::item($supplier, (string) $it['barcode'])) {
                $data['cart'][(string) $it['barcode']] = ($data['cart'][(string) $it['barcode']] ?? 0) + (int) $it['qty'];
            } else {
                $missing++;
            }
        }
        $notice = $last ? "✓ נטענה ההזמנה הקודמת {$last['unique_id']}" . ($missing ? " ({$missing} מוצרים כבר לא במחירון)" : '')
            : 'אין הזמנה קודמת לספק הזה — מתחילים עגלה ריקה';
    } elseif ($supplier !== ($data['supplier'] ?? '')) {
        $data['cart'] = [];
    }
    $data['supplier'] = $supplier;
    return poagent_bench_b_products_screen($data, null, $notice);
}

/** "חפש / הוסף" (cmd go) or "לסיכום ואישור" (cmd confirm) on PRODUCTS. */
function poagent_bench_b_products_action(array &$data, array $in): array
{
    $q = trim((string) ($in['q'] ?? ''));
    $item = trim((string) ($in['item'] ?? ''));
    $qtyRaw = trim((string) ($in['qty'] ?? ''));
    if ($q !== '') $data['bench']['typed_msgs']++;
    if ($qtyRaw !== '') $data['bench']['typed_msgs']++;

    if (($in['cmd'] ?? '') === 'confirm') {
        return poagent_bench_a_lines($data)[0]
            ? poagent_bench_b_confirm_screen($data)
            : poagent_bench_b_products_screen($data, null, '', 'העגלה ריקה — הוסיפו מוצר לפני האישור');
    }
    if ($item !== '' && $item !== 'none') {
        $it = CatalogIndex::item($data['supplier'], $item);
        if (!$it) return poagent_bench_b_products_screen($data, null, '', 'המוצר כבר לא במחירון');
        if (!preg_match('/^\d+$/', $qtyRaw) || (int) $qtyRaw > 9999) {
            return poagent_bench_b_products_screen($data, $data['b_results'] ?? null, '', 'כתבו כמות (0 עד 9999)');
        }
        $qty = (int) $qtyRaw;
        $had = isset($data['cart'][$it['barcode']]);
        if ($had) $data['bench']['edits']++;
        if ($qty === 0) {
            unset($data['cart'][$it['barcode']]);
            $msg = $had ? "✓ הוסר: {$it['name']}" : '';
        } else {
            $data['cart'][$it['barcode']] = $qty;
            $msg = ($had ? '✓ עודכן: ' : '✓ נוסף: ') . "{$it['name']} × {$qty}";
        }
        unset($data['b_results']);
        return poagent_bench_b_products_screen($data, null, $msg);
    }
    if ($q !== '') {
        $data['bench']['searches']++;
        $found = array_slice(CatalogIndex::searchItems($data['supplier'], $q), 0, POAGENT_BENCH_B_RESULTS_MAX);
        if (!$found) {
            $data['bench']['zero_result_searches']++;
            return poagent_bench_b_products_screen($data, null, '', "לא נמצא מוצר ל-\"{$q}\"");
        }
        $data['b_results'] = array_column($found, 'barcode');
        return poagent_bench_b_products_screen($data, $data['b_results'], 'נמצאו ' . count($found) . " ל-\"{$q}\" — בחרו מוצר וכתבו כמות");
    }
    return poagent_bench_b_products_screen($data, null, '', 'כתבו מוצר לחיפוש, או בחרו שורה וכתבו כמות');
}

/**
 * PRODUCTS: the cart, and in the dropdown either search results ($results =
 * barcodes) or — with none — the cart's own lines, to change or remove.
 */
function poagent_bench_b_products_screen(array $data, ?array $results = null, string $notice = '', string $error = ''): array
{
    [$lines, $total] = poagent_bench_a_lines($data);
    $inCart = array_column($lines, 'qty', 'barcode');
    $summary = $lines ? '🛒 ' . count($lines) . ' שורות · ' . poagent_bench_ils($total) : 'העגלה ריקה.';
    foreach (array_slice($lines, 0, POAGENT_BENCH_B_CART_SHOWN) as $l) $summary .= "\n• {$l['name']} × {$l['qty']}";
    if (count($lines) > POAGENT_BENCH_B_CART_SHOWN) $summary .= "\n…ועוד " . (count($lines) - POAGENT_BENCH_B_CART_SHOWN);
    if ($notice !== '') $summary = "{$notice}\n\n{$summary}";

    $options = [];
    if ($results !== null) {
        foreach ($results as $bc) {
            $it = CatalogIndex::item($data['supplier'], (string) $bc);
            if (!$it) continue;
            $options[] = ['id' => $it['barcode'], 'title' => mb_substr($it['name'], 0, 30),
                'description' => poagent_bench_ils($it['price_agorot']) . " · {$it['barcode']}"
                    . (isset($inCart[$it['barcode']]) ? " · בעגלה: {$inCart[$it['barcode']]}" : '')
                    . (mb_strlen($it['name']) > 30 ? " · {$it['name']}" : '')];
        }
        $hint = 'בחרו מוצר מהתוצאות וכתבו כמות.';
    } else {
        foreach ($lines as $l) {
            $options[] = ['id' => $l['barcode'], 'title' => mb_substr($l['name'], 0, 30),
                'description' => "בעגלה: {$l['qty']} × " . poagent_bench_ils($l['price_agorot'])];
        }
        $hint = $lines ? 'לשינוי שורה: בחרו אותה וכתבו כמות (0 = הסרה). להוספה: חפשו מוצר.' : 'חפשו מוצר לפי שם, ברקוד מלא, או לפחות 6 הספרות האחרונות.';
    }
    $out = ['screen' => 'PRODUCTS', 'data' => [
        'supplier'     => "ספק: {$data['supplier']}",
        'cart_summary' => $summary,
        'hint'         => $hint,
        'results'      => $options ?: [['id' => 'none', 'title' => '—']],   // a dropdown needs an option even hidden
        'has_results'  => (bool) $options,
    ]];
    if ($error !== '') $out['data']['error_message'] = $error;
    return $out;
}

function poagent_bench_b_confirm_screen(array &$data): array
{
    [$lines, $total] = poagent_bench_a_lines($data);
    $txt = "ספק: {$data['supplier']}\n";
    foreach (array_slice($lines, 0, POAGENT_BENCH_B_CART_SHOWN) as $l) {
        $txt .= "\n• {$l['name']}\n   {$l['qty']} × " . poagent_bench_ils($l['price_agorot']) . ' = ' . poagent_bench_ils($l['qty'] * $l['price_agorot']);
    }
    if (count($lines) > POAGENT_BENCH_B_CART_SHOWN) $txt .= "\n…ועוד " . (count($lines) - POAGENT_BENCH_B_CART_SHOWN) . ' שורות';
    $txt .= "\n\nסה\"כ: " . poagent_bench_ils($total) . ' · ' . count($lines) . ' שורות';
    $tz = new DateTimeZone('Asia/Jerusalem');
    return ['screen' => 'CONFIRM', 'data' => [
        'summary'      => $txt,
        'min_date'     => (new DateTimeImmutable('today', $tz))->format('Y-m-d'),
        'max_date'     => (new DateTimeImmutable('today +1 year', $tz))->format('Y-m-d'),
        'default_date' => poagent_wa_next_business_day(),
        'cart_ref'     => (string) $data['b_ref'],
    ]];
}

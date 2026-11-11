<?php
/**
 * dn_chat.php — a delivery note from WhatsApp, for phones on method A
 * (users.php): the first message's [📄 תעודת משלוח], or simply sending a
 * photo. Loaded by bench_common.php; bench_a_chat.php hands over to it.
 *
 *   d_po     "which PO?" — the open POs (status open / prcv) to tap, the phone's own first,
 *            then everyone's (like dn_select_po.php); more than 10: type a PO number or supplier
 *   d_pages  a photo per page (camera or gallery; a document that is a JPEG/PNG too) —
 *            after each: "✓ עמוד N התקבל" [✅ סיום] [ביטול]
 * A photo sent first is kept, and then the PO is asked for.
 *
 * The pages wait in DNdir/_incoming/ until סיום; then they become one ordinary
 * pending DN of that PO (DNStore::importPages — page 1 as always, _p2, _p3…)
 * and the PO goes to "preocr": the office runs the OCR (on all pages at once)
 * as for any upload. Nothing reaches the PO before סיום; ביטול or an abandoned
 * conversation leaves nothing behind.
 */

require_once __DIR__ . '/../lib/DNStore.php';

if (!defined('POAGENT_DN_INCOMING')) define('POAGENT_DN_INCOMING', POAGENT_DNDIR . '/_incoming');
const POAGENT_DN_MAX_PAGES = 10;
const POAGENT_DN_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png'];

/** A message on a DN conversation (state d_*). */
function poagent_dn_chat_handle(array $ctx, array $event, array $data, string $state): void
{
    $text = trim((string) ($event['text'] ?? ''));
    $id = (string) ($event['reply_id'] ?? '');
    if (in_array($event['type'] ?? '', ['image', 'document'], true)) {
        poagent_dn_chat_add_page($ctx, $data, $event);
        return;
    }
    if ($id === 'dn_cancel' || in_array($text, ['ביטול', 'בטל'], true)) {
        poagent_dn_chat_cancel($ctx, $data);
        return;
    }
    if ($state === 'd_po') {
        if (preg_match('/^po:(\d+)$/', $id, $m) && isset($data['dn_map'][(int) $m[1]])) {
            poagent_dn_chat_pick_po($ctx, $data, $data['dn_map'][(int) $m[1]]);
        } elseif ($text !== '') {
            poagent_dn_chat_ask_po($ctx, $data, $text);
        } else {
            poagent_dn_chat_ask_po($ctx, $data);
        }
        return;
    }
    // d_pages
    if ($id === 'dn_done' || in_array($text, ['סיום', 'סיימתי'], true)) {
        poagent_dn_chat_finish($ctx, $data);
        return;
    }
    poagent_dn_chat_buttons($ctx, 'שלחו צילום של עמוד נוסף (מצלמה 📷 או גלריה 📎), או:');
    poagent_bench_save($ctx['from'], 'd_pages', $data, $ctx['pnid']);
}

/** [📄 תעודת משלוח], or a photo sent with nothing in progress ($event = that photo). */
function poagent_dn_chat_start(array $ctx, array $data, ?array $event = null): void
{
    $data = ['scenario' => (string) ($data['scenario'] ?? ''), 'dn_pages' => []];
    if ($event) {
        poagent_dn_chat_add_page($ctx, $data, $event);
        return;
    }
    poagent_dn_chat_ask_po($ctx, $data);
}

// ── which PO ────────────────────────────────────────────────────────────────

/** Open POs, the phone's own first, newest first; $q: a PO number or part of a supplier name. */
function poagent_dn_chat_open_pos(string $from, string $q = ''): array
{
    $open = array_values(array_filter(POStore::listPOs(null), fn($po) => in_array($po['status'] ?? '', ['open', 'prcv'], true)));
    usort($open, fn($a, $b) => [($b['generator_id'] ?? '') === $from, $b['date_generated'] ?? ''] <=> [($a['generator_id'] ?? '') === $from, $a['date_generated'] ?? '']);
    if ($q === '') return $open;
    $digits = preg_match('/^(?:po)?\s*0*(\d+)$/i', $q, $m) ? $m[1] : null;
    $nq = CatalogIndex::normalize($q);
    return array_values(array_filter($open, fn($po) => $digits !== null
        ? ltrim(substr((string) $po['unique_id'], 2), '0') === $digits
        : str_contains(CatalogIndex::normalize((string) $po['supplier_id']), $nq)));
}

function poagent_dn_chat_ask_po(array $ctx, array $data, string $q = ''): void
{
    $pos = poagent_dn_chat_open_pos($ctx['from'], $q);
    $got = count($data['dn_pages'] ?? []) ? '✓ קיבלתי ' . (count($data['dn_pages']) === 1 ? 'את התמונה' : count($data['dn_pages']) . ' תמונות') . ".\n" : '';
    if (!$pos) {
        WaClient::text($ctx['pnid'], $ctx['from'], $got . ($q !== ''
            ? "לא נמצאה הזמנה פתוחה ל-\"{$q}\". כתבו מספר הזמנה (למשל 87) או חלק משם הספק."
            : 'אין כרגע הזמנות פתוחות שאפשר לשייך אליהן תעודת משלוח.'));
        if ($q === '' && !count($data['dn_pages'] ?? [])) {
            poagent_bench_save($ctx['from'], 'a_done', $data, $ctx['pnid'], true);
            return;
        }
        poagent_bench_save($ctx['from'], 'd_po', $data, $ctx['pnid']);
        return;
    }
    $shown = array_slice($pos, 0, 10);
    $data['dn_map'] = array_column($shown, 'core_name');
    $rows = [];
    foreach ($shown as $i => $po) {
        $rows[] = ['id' => "po:{$i}", 'title' => "{$po['unique_id']} · {$po['supplier_id']}",
            'description' => date('d/m', strtotime($po['date_generated'])) . ' · ' . count($po['items'] ?? []) . ' שורות · '
                . (($po['generator_id'] ?? '') === $ctx['from'] ? 'שלך' : ($po['generator_name'] ?? $po['generator_id'] ?? ''))
                . (mb_strlen("{$po['unique_id']} · {$po['supplier_id']}") > 24 ? " · {$po['supplier_id']}" : '')];
    }
    $body = $got . '📄 לאיזו הזמנה שייכת תעודת המשלוח?'
        . (count($pos) > 10 ? "\nמוצגות 10 האחרונות מתוך " . count($pos) . ' — אפשר לכתוב מספר הזמנה או שם ספק.' : '');
    WaClient::list($ctx['pnid'], $ctx['from'], $body, $rows, 'בחירת הזמנה');
    poagent_bench_save($ctx['from'], 'd_po', $data, $ctx['pnid']);
}

function poagent_dn_chat_pick_po(array $ctx, array $data, string $coreName): void
{
    $po = POStore::loadByCoreName($coreName);
    if (!$po || !in_array($po['status'] ?? '', ['open', 'prcv'], true)) {
        WaClient::text($ctx['pnid'], $ctx['from'], 'ההזמנה הזאת כבר לא פתוחה לתעודה חדשה (אולי ממתינה תעודה קודמת לעיבוד). בחרו הזמנה אחרת.');
        poagent_dn_chat_ask_po($ctx, $data);
        return;
    }
    $data['dn_po'] = $coreName;
    $data['dn_po_label'] = "{$po['unique_id']} ({$po['supplier_id']})";
    unset($data['dn_map']);
    $n = count($data['dn_pages'] ?? []);
    if ($n) {
        poagent_dn_chat_buttons($ctx, "התעודה תשויך להזמנה {$data['dn_po_label']} — {$n} " . ($n === 1 ? 'עמוד' : 'עמודים')
            . " עד עכשיו.\nיש עוד עמוד? צלמו ושלחו אותו. אחרת:");
    } else {
        WaClient::text($ctx['pnid'], $ctx['from'], "הזמנה {$data['dn_po_label']}.\n📷 צלמו את תעודת המשלוח ושלחו — עמוד אחרי עמוד."
            . "\n(לחצו על המצלמה או על 📎 בתחתית הצ'אט)");
    }
    poagent_bench_save($ctx['from'], 'd_pages', $data, $ctx['pnid']);
}

// ── pages ───────────────────────────────────────────────────────────────────

function poagent_dn_chat_add_page(array $ctx, array $data, array $event): void
{
    $raw = $event['raw_message'] ?? [];
    $media = $raw['image'] ?? $raw['document'] ?? [];
    $mime = strtolower((string) ($media['mime_type'] ?? ''));
    $state = !empty($data['dn_po']) ? 'd_pages' : 'd_po';
    if (!isset(POAGENT_DN_IMAGE_TYPES[$mime])) {
        WaClient::text($ctx['pnid'], $ctx['from'], 'שלחו את התעודה כצילום (תמונה) — ' . ($mime === 'application/pdf' ? 'PDF' : 'קובץ מהסוג הזה') . ' לא נתמך.');
        poagent_bench_save($ctx['from'], $state, $data, $ctx['pnid']);
        return;
    }
    if (count($data['dn_pages'] ?? []) >= POAGENT_DN_MAX_PAGES) {
        poagent_dn_chat_buttons($ctx, 'אפשר עד ' . POAGENT_DN_MAX_PAGES . ' עמודים לתעודה. לחצו סיום כדי לשמור אותה.');
        poagent_bench_save($ctx['from'], $state, $data, $ctx['pnid']);
        return;
    }
    $file = WaClient::downloadMedia((string) ($media['id'] ?? ''));
    if (!$file) {
        WaClient::text($ctx['pnid'], $ctx['from'], 'לא הצלחתי לקבל את התמונה. נסו לשלוח אותה שוב.');
        poagent_bench_save($ctx['from'], $state, $data, $ctx['pnid']);
        return;
    }
    @mkdir(POAGENT_DN_INCOMING, 0777, true);
    $path = POAGENT_DN_INCOMING . '/' . $ctx['from'] . '_' . date('dmy-His') . '_' . bin2hex(random_bytes(3)) . '.' . POAGENT_DN_IMAGE_TYPES[$mime];
    file_put_contents($path, $file['bytes']);
    $data['dn_pages'][] = $path;
    $n = count($data['dn_pages']);
    poagent_whatsapp_log(['ts' => date('c'), 'direction' => 'dn_page_received', 'from' => $ctx['from'], 'page' => $n, 'bytes' => strlen($file['bytes'])]);
    if ($state === 'd_po') {
        poagent_dn_chat_ask_po($ctx, $data);   // a photo before the PO: ask for the PO now
        return;
    }
    poagent_dn_chat_buttons($ctx, "✓ עמוד {$n} התקבל" . ($n === 1 ? " — הזמנה {$data['dn_po_label']}" : '') . ".\nיש עוד עמוד? צלמו ושלחו אותו. אחרת:");
    poagent_bench_save($ctx['from'], 'd_pages', $data, $ctx['pnid']);
}

function poagent_dn_chat_buttons(array $ctx, string $body): void
{
    WaClient::buttons($ctx['pnid'], $ctx['from'], $body, [['id' => 'dn_done', 'title' => '✅ סיום'], ['id' => 'dn_cancel', 'title' => 'ביטול']]);
}

function poagent_dn_chat_finish(array $ctx, array $data): void
{
    $paths = array_values(array_filter($data['dn_pages'] ?? [], 'is_file'));
    if (!$paths) {
        WaClient::text($ctx['pnid'], $ctx['from'], 'עוד לא התקבל אף צילום. צלמו את התעודה ושלחו.');
        poagent_bench_save($ctx['from'], 'd_pages', $data, $ctx['pnid']);
        return;
    }
    $po = POStore::loadByCoreName((string) ($data['dn_po'] ?? ''));
    if (!$po || !in_array($po['status'] ?? '', ['open', 'prcv'], true)) {
        unset($data['dn_po']);
        WaClient::text($ctx['pnid'], $ctx['from'], 'ההזמנה כבר לא פתוחה לתעודה חדשה. בחרו הזמנה אחרת — הצילומים נשמרו.');
        poagent_dn_chat_ask_po($ctx, $data);
        return;
    }
    $pages = array_map(fn($p) => ['bytes' => (string) file_get_contents($p), 'ext' => strtolower(pathinfo($p, PATHINFO_EXTENSION))], $paths);
    try {
        $dn = DNStore::importPages($po['core_name'], $pages);
        POStore::setStatus($po['core_name'], 'preocr');
    } catch (Throwable $e) {
        poagent_whatsapp_log(['ts' => date('c'), 'event' => 'dn_save_failed', 'from' => $ctx['from'], 'error' => $e->getMessage()]);
        WaClient::text($ctx['pnid'], $ctx['from'], 'לא הצלחתי לשמור את התעודה. נסו ללחוץ סיום שוב בעוד רגע.');
        poagent_bench_save($ctx['from'], 'd_pages', $data, $ctx['pnid']);
        return;
    }
    foreach ($paths as $p) @unlink($p);
    poagent_whatsapp_log(['ts' => date('c'), 'direction' => 'dn_saved', 'from' => $ctx['from'], 'po' => $po['unique_id'],
        'dn_core_name' => $dn['dn_core_name'], 'pages' => count($paths)]);
    $n = count($paths);
    WaClient::text($ctx['pnid'], $ctx['from'], "✅ תעודת המשלוח נשמרה להזמנה {$data['dn_po_label']} — " . ($n === 1 ? 'עמוד אחד' : "{$n} עמודים")
        . ".\nהמשרד יעבד אותה.\n\nלהזמנה או לתעודה נוספת — שלחו הודעה.");
    poagent_bench_save($ctx['from'], 'a_done', $data, $ctx['pnid'], true);
}

function poagent_dn_chat_cancel(array $ctx, array $data): void
{
    foreach ($data['dn_pages'] ?? [] as $p) @unlink($p);
    WaClient::text($ctx['pnid'], $ctx['from'], 'תעודת המשלוח בוטלה — לא נשמר כלום.' . "\nלהזמנה או לתעודה — שלחו הודעה.");
    poagent_bench_save($ctx['from'], 'a_done', $data, $ctx['pnid'], true);
}

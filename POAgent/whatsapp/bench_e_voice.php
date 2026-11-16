<?php
/**
 * bench_e_voice.php — WhatsApp ordering, method E: the order dictated in a voice
 * message. Loaded by bench_common.php; a phone set to method E in users.php.
 *
 * It is method D (bench_d_list.php — itself method A's conversation) with one
 * more way to give the list: once the supplier is chosen the bot asks for a
 * recording. lib/VoiceOrder.php turns it into lines {product, qty}; the bot
 * writes back what it understood, and from there everything is method D — the
 * matching against the price list, the conflicts to tap, the lines that did
 * not go in, the summary. Typing a list works as well (to fix a line, or
 * instead of recording), and so does another recording at any point.
 *
 * The recording goes to OpenAI (as the delivery note photos do); it is not kept.
 */

require_once __DIR__ . '/../lib/VoiceOrder.php';

function poagent_bench_e_handle(array $event, string $name): void
{
    poagent_bench_a_handle($event, $name, 'e');
}

function poagent_bench_e_on(array $data): bool
{
    return ($data['bench']['mode'] ?? '') === 'e';
}

/** A voice message in a method-E conversation ($ordering: an order is in progress). */
function poagent_bench_e_audio(array $ctx, array $event, array $data, string $state, bool $ordering): void
{
    $send = fn(string $t) => WaClient::text($ctx['pnid'], $ctx['from'], $t);
    if ($ordering && $state === 'a_supplier') {
        poagent_bench_e_supplier_audio($ctx, $event, $data);
        return;
    }
    if (!$ordering || empty($data['supplier']) || $state === 'a_cancel') {
        $send($ordering ? 'קודם ענו על השאלה למעלה — ואז הקליטו.' : 'כדי להזמין בהקלטה: שלחו הודעה ובחרו "הזמנה חדשה" — ואז הקליטו את שם הספק ואת ההזמנה.');
        return;   // the conversation stays where it was
    }
    poagent_bench_note($data['bench'], $event);
    $media = $event['raw_message']['audio'] ?? [];
    $file = WaClient::downloadMedia((string) ($media['id'] ?? ''));
    if (!$file || $file['bytes'] === '' || strlen($file['bytes']) > VoiceOrder::MAX_BYTES) {
        $send($file ? 'ההקלטה ארוכה מדי. הקליטו אותה בכמה חלקים.' : 'לא הצלחתי לקבל את ההקלטה. נסו להקליט שוב.');
        poagent_bench_a_save($ctx, $state, $data);
        return;
    }
    try {
        $heard = VoiceOrder::fromAudio($file['bytes'], $file['mime'] ?: (string) ($media['mime_type'] ?? ''));
    } catch (Throwable $e) {
        poagent_whatsapp_log(['ts' => date('c'), 'event' => 'bench_e_voice_failed', 'from' => $ctx['from'], 'error' => $e->getMessage()]);
        $send('לא הצלחתי לפענח את ההקלטה. נסו להקליט שוב, או כתבו את ההזמנה.');
        poagent_bench_a_save($ctx, $state, $data);
        return;
    }
    poagent_whatsapp_log(['ts' => date('c'), 'event' => 'bench_e_voice', 'from' => $ctx['from'],
        'transcript' => mb_substr($heard['transcript'], 0, 1000), 'lines' => count($heard['lines'])]);
    if (!$heard['lines']) {
        $send(($heard['transcript'] !== '' ? "🎙️ שמעתי: \"{$heard['transcript']}\"\n" : '')
            . 'לא זיהיתי מוצרים בהקלטה. הקליטו שוב — שם מוצר ואחריו כמות, מוצר אחרי מוצר.');
        poagent_bench_a_save($ctx, $state, $data);
        return;
    }
    $lines = array_slice($heard['lines'], 0, POAGENT_BENCH_D_MAX_LINES);
    $send("🎙️ הבנתי:\n" . implode("\n", array_map(fn($l) => '• ' . $l['text'] . ($l['qty'] !== null ? " — {$l['qty']}" : ''), $lines)));
    poagent_bench_d_list($ctx, $data, '', $lines);
}

/**
 * "Which supplier?" answered with a recording: the name as heard is searched like a typed
 * one, forgivingly (CatalogIndex::searchSuppliersLoose) — one supplier is chosen, several
 * are a list to tap, none says so.
 */
function poagent_bench_e_supplier_audio(array $ctx, array $event, array $data): void
{
    $send = fn(string $t) => WaClient::text($ctx['pnid'], $ctx['from'], $t);
    poagent_bench_note($data['bench'], $event);
    $media = $event['raw_message']['audio'] ?? [];
    $file = WaClient::downloadMedia((string) ($media['id'] ?? ''));
    $heard = '';
    try {
        if ($file && $file['bytes'] !== '' && strlen($file['bytes']) <= VoiceOrder::MAX_BYTES) {
            $heard = VoiceOrder::transcribe($file['bytes'], $file['mime'] ?: (string) ($media['mime_type'] ?? ''), 'שם של ספק.');
        }
    } catch (Throwable $e) {
        poagent_whatsapp_log(['ts' => date('c'), 'event' => 'bench_e_voice_failed', 'from' => $ctx['from'], 'error' => $e->getMessage()]);
    }
    // The name alone: no punctuation, no "הספק" / "מספק" / "אני רוצה להזמין מ..." around it.
    $name = trim(preg_replace('/[.,!?:;"״׳\']+/u', ' ', $heard));
    $name = trim(preg_replace('/^(?:(?:אני\s+)?(?:רוצה|רוצים)\s+להזמין\s+|הזמנה\s+)?(?:מ?ה?ספק|מאת|של)\s+/u', '', $name));
    poagent_whatsapp_log(['ts' => date('c'), 'event' => 'bench_e_voice_supplier', 'from' => $ctx['from'], 'transcript' => mb_substr($heard, 0, 300)]);
    if ($name === '') {
        $send('לא הצלחתי לשמוע את שם הספק. הקליטו שוב, או כתבו חלק מהשם.');
        poagent_bench_a_save($ctx, 'a_supplier', $data);
        return;
    }
    $send("🎙️ שמעתי: {$name}");
    poagent_bench_a_supplier_search($ctx, $data, $name, true);
}
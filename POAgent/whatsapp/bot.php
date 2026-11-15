<?php
/**
 * POAgent — WhatsApp bot handler (mobile web launcher).
 *
 * Registered in whatsapp_app/apps.json for the POAgent business line
 * (052-2649555 / 972522649555). WaRouter matches the number, enforces the
 * sender allowlist, normalizes the payload, and calls
 * poagent_whatsapp_handle_event() once per inbound message.
 *
 * The phone user does NOT run the PO flow inside WhatsApp. Any inbound
 * message gets a short greeting plus a one-tap link into the real PO web
 * pages — POAgent/m/?t=<token> — already authenticated as the sender's phone
 * number. Token issue/verify + link building: mobile_link.php. Landing
 * script: POAgent/m/index.php.
 *
 * Parked, not wired in: po_flow.php (the earlier in-chat supplier/search/
 * confirm conversation). The idle-session sweep below
 * (poagent_whatsapp_sweep_idle_sessions, still invoked by session_sweeper.php)
 * is dormant too — there is no multi-step chat state to time out now. Both
 * are kept for a future chat flow, if one is wanted again.
 *
 * Identity: the token — and every PO created through the link — is keyed on
 * the sender's phone number. The apps.json allowlist name is a display nick
 * only. allowlist_mode "enforced" still decides who gets a link at all.
 *
 * The WhatsApp ordering benchmark: a phone set in users.php to method A / B / C
 * orders inside WhatsApp instead (bench_common.php poagent_bench_handle());
 * everyone else gets the link as above. The idle sweep below runs for those
 * conversations.
 */

require_once __DIR__ . '/../../whatsapp_app/lib/WaClient.php';
require_once __DIR__ . '/../../whatsapp_app/lib/WaSessionStore.php';
require_once __DIR__ . '/mobile_link.php';
require_once __DIR__ . '/../lib/UserAccess.php';
require_once __DIR__ . '/bench_common.php';

const POAGENT_WA_APP_ID  = 'poagent';
if (!defined('POAGENT_WA_LOG_DIR')) define('POAGENT_WA_LOG_DIR', __DIR__ . '/logs');   // tests point it elsewhere

// Message types that count as "the user said something" → send a link.
// Anything else (reactions, location, system, unsupported…) is logged and ignored.
// 'order' = a WhatsApp catalog cart (benchmark method C). image / document = a
// delivery note photo — only for a phone on a benchmark method (dn_chat.php);
// for everyone else they're ignored, as before.
const POAGENT_WA_ACTIONABLE_TYPES = ['text', 'interactive', 'button', 'order', 'image', 'document', 'audio'];

// Idle sweep defaults; a benchmark conversation uses bench_common.php's longer ones.
const POAGENT_WA_IDLE_REMINDER_AFTER_SECONDS = 300; // 5 minutes
const POAGENT_WA_IDLE_CLOSE_AFTER_SECONDS    = 60;  // +1 minute after the reminder

/**
 * Registry handler. $event is WaRouter's normalized event. Must not throw
 * (the router catches, but keep it clean).
 *
 * Behaviour: greet + send a fresh tokenized login link. No session is
 * written — the link is self-contained and short-lived, so a new message
 * simply yields a new link.
 */
function poagent_whatsapp_handle_event(array $event): void
{
    $from = (string) ($event['from'] ?? '');
    $type = (string) ($event['type'] ?? '');
    if ($from === '') {
        return;
    }

    if (!in_array($type, POAGENT_WA_ACTIONABLE_TYPES, true)) {
        poagent_whatsapp_log([
            'ts'      => date('c'),
            'event'   => 'ignored_type',
            'from'    => $from,
            'in_type' => $type,
        ]);
        return;
    }

    $phoneNumberId = (string) ($event['business_phone_number_id'] ?? '');
    $name = poagent_whatsapp_resolve_display_name($event);

    // Blocked in users.php: no login link.
    if (poagent_access_is_blocked($from)) {
        $sent = WaClient::text($phoneNumberId, $from, 'הגישה שלך ל-POAgent חסומה. לפרטים פנה/י למשרד.');
        poagent_whatsapp_log(['ts' => date('c'), 'direction' => 'blocked', 'from' => $from, 'reply_sent' => $sent]);
        return;
    }

    // A 6-digit number is a desktop login code (index.php shows it): log that
    // computer in as this sender. Anything else gets the phone login link —
    // or, for a phone set to a benchmark method, goes to that method. While a
    // method waits for a search / quantity, digits are that, not a code
    // (unless sent as "כניסה 123456", like the QR code does).
    $mode = poagent_access_wa_mode($from);
    // (audio = a dictated order: only a phone on method E.)
    if (($mode === '' && in_array($type, ['image', 'document'], true)) || ($type === 'audio' && $mode !== 'e')) {
        poagent_whatsapp_log(['ts' => date('c'), 'event' => 'ignored_type', 'from' => $from, 'in_type' => $type]);
        return;
    }
    $text = (string) ($event['text'] ?? '');
    $isCode = $mode === '' || !poagent_bench_takes_digits($event['session'] ?? null)
        ? preg_match('/(?<!\d)(\d{6})(?!\d)/', $text, $m)
        : preg_match('/^\s*כניסה\s*(\d{6})\s*$/u', $text, $m);
    if ($isCode) {
        $result = poagent_access_claim_code($m[1], $from, $name);
        poagent_login_log('desktop_code', $from, "code_{$result}");
        $reply = [
            'ok'      => '✅ נכנסת למערכת במחשב' . ($name !== '' ? " בשם {$name}" : '') . '.',
            'invalid' => 'הקוד לא תקף או שפג תוקפו. רענן/י את דף הכניסה במחשב ושלח/י את הקוד החדש.',
            'limited' => 'יותר מדי ניסיונות עם קוד שגוי. נסה/י שוב בעוד כמה דקות.',
        ][$result];
        $sent = WaClient::text($phoneNumberId, $from, $reply);
        poagent_whatsapp_log(['ts' => date('c'), 'direction' => 'desktop_code', 'from' => $from, 'result' => $result, 'reply_sent' => $sent]);
        return;
    }

    if ($mode !== '') {
        poagent_bench_handle($event, $mode, $name);
        return;
    }
    poagent_whatsapp_send_login_link($phoneNumberId, $from, $name);
}

/** The greeting with the one-tap login link into the PO web pages. */
function poagent_whatsapp_send_login_link(string $phoneNumberId, string $from, string $name): void
{
    $link   = poagent_wa_mobile_link($from, $name);
    $ttlMin = max(1, (int) round(POAGENT_WA_LINK_TTL / 60));
    $body   = 'שלום' . ($name !== '' ? " $name" : '') . " 👋\n"
            . "מערכת ההזמנות של POAgent.\n\n"
            . "לחצו על הכפתור למטה כדי להיכנס למערכת.";
    $footer = "הקישור בתוקף ל-{$ttlMin} דקות";

    // cta_url (not text()) — keeps the actual tokenized URL off the chat
    // bubble entirely, behind a plain button label, instead of a raw link
    // wrapped across several lines (see the screenshot that prompted this).
    $sent = WaClient::ctaUrl($phoneNumberId, $from, $body, 'כניסה למערכת', $link, $footer);
    if ($sent) poagent_access_note_link($from);

    poagent_whatsapp_log([
        'ts'         => date('c'),
        'direction'  => 'mobile_link_sent',
        'from'       => $from,
        'display'    => $name,
        'reply_sent' => $sent,
    ]);
}

/**
 * The mobile user's DISPLAY nickname (never the identity — that's the phone
 * number). apps.json allowlist name → WhatsApp profile name → bare number.
 */
function poagent_whatsapp_resolve_display_name(array $event): string
{
    foreach ([$event['allowlist_name'] ?? '', $event['contact_name'] ?? ''] as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '') {
            return $candidate;
        }
    }
    return (string) ($event['from'] ?? '');
}

/** First idle nudge; $closeAfter = seconds until the close. */
function poagent_whatsapp_idle_reminder_text(int $closeAfter = POAGENT_WA_IDLE_CLOSE_AFTER_SECONDS): string
{
    $minutes = max(1, (int) round($closeAfter / 60));
    return 'עדיין שם/ה? אם לא נמשיך את השיחה, היא תיסגר בעוד ' . ($minutes === 1 ? 'דקה' : "{$minutes} דקות") . '.';
}

/** Idle close message. */
function poagent_whatsapp_idle_closed_text(): string
{
    return 'השיחה הסתיימה. כדי להתחיל שיחה חדשה, פשוט שלח/י לנו הודעה.';
}

/**
 * Idle-timeout sweep (session_sweeper.php). The link itself writes no session;
 * the benchmark conversations do — they get longer times (bench_common.php),
 * and one closed half-way is logged as an abandoned attempt.
 *
 * @return array{reminders_sent:int, sessions_closed:int}
 */
function poagent_whatsapp_sweep_idle_sessions(): array
{
    $remindersSent  = 0;
    $sessionsClosed = 0;

    foreach (WaSessionStore::allForApp(POAGENT_WA_APP_ID) as $session) {
        $waId           = (string) ($session['wa_id'] ?? '');
        $data           = $session['data'] ?? [];
        $phoneNumberId  = (string) ($data['business_phone_number_id'] ?? '');
        $lastInboundAt  = (string) ($data['last_inbound_at'] ?? $session['updated_at'] ?? '');
        $reminderSentAt = $data['reminder_sent_at'] ?? null;

        if ($waId === '' || $lastInboundAt === '' || !empty($data['idle'])) {
            continue;   // idle = a benchmark conversation parked between orders
        }
        $bench = $data['bench'] ?? null;
        $dn = str_starts_with((string) ($session['state'] ?? ''), 'd_');   // a delivery note being photographed
        [$remindAfter, $closeAfter] = $bench || $dn
            ? [POAGENT_BENCH_IDLE_REMINDER_AFTER_SECONDS, POAGENT_BENCH_IDLE_CLOSE_AFTER_SECONDS]
            : [POAGENT_WA_IDLE_REMINDER_AFTER_SECONDS, POAGENT_WA_IDLE_CLOSE_AFTER_SECONDS];

        if ($reminderSentAt) {
            $idleSinceReminder = time() - strtotime((string) $reminderSentAt);
            if ($idleSinceReminder >= $closeAfter) {
                $sent = WaClient::text($phoneNumberId, $waId, poagent_whatsapp_idle_closed_text());
                if ($bench) poagent_bench_finish($waId, $bench, 'abandoned');
                foreach ($data['dn_pages'] ?? [] as $page) @unlink($page);   // a delivery note never finished
                WaSessionStore::clear($waId);
                $sessionsClosed++;
                poagent_whatsapp_log([
                    'ts' => date('c'), 'event' => 'session_closed_idle', 'from' => $waId, 'reply_sent' => $sent,
                ]);
            }
            continue;
        }

        $idleSinceInbound = time() - strtotime($lastInboundAt);
        if ($idleSinceInbound >= $remindAfter) {
            $sent = WaClient::text($phoneNumberId, $waId, poagent_whatsapp_idle_reminder_text($closeAfter));
            WaSessionStore::patchData($waId, ['reminder_sent_at' => date('c')]);
            $remindersSent++;
            poagent_whatsapp_log([
                'ts' => date('c'), 'event' => 'idle_reminder_sent', 'from' => $waId, 'reply_sent' => $sent,
            ]);
        }
    }

    return ['reminders_sent' => $remindersSent, 'sessions_closed' => $sessionsClosed];
}

/** One JSON object per line, one file per day. */
function poagent_whatsapp_log(array $entry): void
{
    if (!is_dir(POAGENT_WA_LOG_DIR)) {
        @mkdir(POAGENT_WA_LOG_DIR, 0775, true);
    }
    @file_put_contents(
        POAGENT_WA_LOG_DIR . '/bot_' . date('Y-m-d') . '.log',
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

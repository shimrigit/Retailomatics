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
 */

require_once __DIR__ . '/../../whatsapp_app/lib/WaClient.php';
require_once __DIR__ . '/../../whatsapp_app/lib/WaSessionStore.php';
require_once __DIR__ . '/mobile_link.php';

const POAGENT_WA_APP_ID  = 'poagent';
const POAGENT_WA_LOG_DIR  = __DIR__ . '/logs';

// Message types that count as "the user said something" → send a link.
// Anything else (reactions, location, system, unsupported…) is logged and ignored.
const POAGENT_WA_ACTIONABLE_TYPES = ['text', 'interactive', 'button'];

// Dormant — kept for session_sweeper.php and a possible future chat flow.
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

    $link   = poagent_wa_mobile_link($from, $name);
    $ttlMin = max(1, (int) round(POAGENT_WA_LINK_TTL / 60));
    $body   = 'שלום' . ($name !== '' ? " $name" : '') . " 👋\n"
            . "מערכת ההזמנות של POAgent.\n\n"
            . "להיכנס למערכת:\n" . $link . "\n\n"
            . "הקישור בתוקף ל-{$ttlMin} דקות. לאחר מכן שלח/י הודעה כדי לקבל קישור חדש.";

    $sent = WaClient::text($phoneNumberId, $from, $body);

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

/** First idle nudge - dormant; kept for session_sweeper.php / a future chat flow. */
function poagent_whatsapp_idle_reminder_text(): string
{
    return 'עדיין שם/ה? אם לא נמשיך את השיחה, היא תיסגר בעוד דקה.';
}

/** Idle close message - dormant; see above. */
function poagent_whatsapp_idle_closed_text(): string
{
    return 'השיחה הסתיימה. כדי להתחיל שיחה חדשה, פשוט שלח/י לנו הודעה.';
}

/**
 * Idle-timeout sweep. Dormant while the bot only sends stateless links (it
 * writes no sessions, so there is nothing for this to find), but kept intact
 * so session_sweeper.php still runs and a future chat flow can rely on it.
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

        if ($waId === '' || $lastInboundAt === '') {
            continue;
        }

        if ($reminderSentAt) {
            $idleSinceReminder = time() - strtotime((string) $reminderSentAt);
            if ($idleSinceReminder >= POAGENT_WA_IDLE_CLOSE_AFTER_SECONDS) {
                $sent = WaClient::text($phoneNumberId, $waId, poagent_whatsapp_idle_closed_text());
                WaSessionStore::clear($waId);
                $sessionsClosed++;
                poagent_whatsapp_log([
                    'ts' => date('c'), 'event' => 'session_closed_idle', 'from' => $waId, 'reply_sent' => $sent,
                ]);
            }
            continue;
        }

        $idleSinceInbound = time() - strtotime($lastInboundAt);
        if ($idleSinceInbound >= POAGENT_WA_IDLE_REMINDER_AFTER_SECONDS) {
            $sent = WaClient::text($phoneNumberId, $waId, poagent_whatsapp_idle_reminder_text());
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

<?php
// WaRouter.php — the one dispatcher behind the single Meta webhook.
//
// Meta allows exactly ONE callback URL per app/WABA, and it fans EVERY event
// for EVERY business number to it. webhook.php ack's Meta, appends the raw
// payload to whatsapp_images/webhook_log.txt (unchanged - NP's
// MessageFetching.php still scrapes that), then hands the body here.
//
// This class:
//   1. parses the Meta envelope once,
//   2. for each inbound message, matches the *business* number it was sent to
//      against whatsapp_app/apps.json,
//   3. builds a normalized event and calls the matching app's handler.
//
// Routing key is the business number only (phone_number_id, or
// display_phone_number as a fallback until a number's id is known). No
// content sniffing, no session-based routing. One app per number.
//
// Handler contract:  function handler(array $event): void
// Event shape is documented on normalizeEvent() below.

require_once __DIR__ . '/WaSessionStore.php';
require_once __DIR__ . '/WaClient.php';

define('WA_REGISTRY_FILE', __DIR__ . '/../apps.json');
define('WA_ROUTER_LOG_DIR', __DIR__ . '/../logs');

class WaRouter
{
    /** Entry point. $rawBody = the raw JSON string of the Meta webhook POST. */
    public static function handle(string $rawBody): void
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            self::log(['ts' => date('c'), 'event' => 'bad_payload']);
            return;
        }

        $registry = self::loadRegistry();

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue; // account_update, etc. - nothing routes on those yet
                }
                $value    = $change['value'] ?? [];
                $metadata = $value['metadata'] ?? [];

                // Status callbacks (sent/delivered/read) carry no 'messages'.
                $messages = $value['messages'] ?? [];
                if ($messages === []) {
                    continue;
                }

                $app = self::matchApp($registry, $metadata);
                if ($app === null) {
                    self::log([
                        'ts'                  => date('c'),
                        'event'               => 'unrouted',
                        'phone_number_id'     => $metadata['phone_number_id'] ?? null,
                        'display_phone_number'=> $metadata['display_phone_number'] ?? null,
                    ]);
                    continue;
                }

                $fn = $app['handler']['function'] ?? null;
                if (!$fn) {
                    // Matched an app with no live handler (e.g. NP). The raw
                    // payload is already logged upstream; nothing else to do.
                    self::log([
                        'ts'    => date('c'),
                        'event' => 'no_handler',
                        'app'   => $app['id'],
                        'count' => count($messages),
                    ]);
                    continue;
                }

                self::loadHandlerFile($app);
                $contacts = self::indexContacts($value['contacts'] ?? []);

                foreach ($messages as $message) {
                    $event = self::normalizeEvent($app, $metadata, $contacts, $message);

                    if (!self::isAllowed($app, $event['from'])) {
                        self::log([
                            'ts'    => date('c'),
                            'event' => 'unauthorized',
                            'app'   => $app['id'],
                            'from'  => $event['from'],
                        ]);
                        continue; // silent drop - no reply, doesn't touch the app's quality score
                    }

                    if (!is_callable($fn)) {
                        self::log(['ts' => date('c'), 'event' => 'handler_missing', 'app' => $app['id'], 'function' => $fn]);
                        break;
                    }

                    try {
                        $fn($event);
                        self::log([
                            'ts'    => date('c'),
                            'event' => 'dispatch',
                            'app'   => $app['id'],
                            'from'  => $event['from'],
                            'type'  => $event['type'],
                            'msg_id'=> $event['message_id'],
                        ]);
                    } catch (\Throwable $e) {
                        // One app blowing up must not stop the others.
                        self::log([
                            'ts'    => date('c'),
                            'event' => 'handler_error',
                            'app'   => $app['id'],
                            'from'  => $event['from'] ?? null,
                            'error' => $e->getMessage(),
                            'where' => $e->getFile() . ':' . $e->getLine(),
                        ]);
                    }
                }
            }
        }
    }

    // ── registry ────────────────────────────────────────────────────────────

    private static function loadRegistry(): array
    {
        if (!is_file(WA_REGISTRY_FILE)) {
            return [];
        }
        $data = json_decode((string) file_get_contents(WA_REGISTRY_FILE), true);
        return is_array($data) && isset($data['apps']) && is_array($data['apps'])
            ? $data['apps']
            : [];
    }

    /**
     * First enabled app whose match rules cover this business number.
     * phone_number_id is checked first (exact, stable); display_phone_number
     * is a digits-only fallback for numbers whose id we haven't recorded yet.
     */
    private static function matchApp(array $registry, array $metadata): ?array
    {
        $pnid    = (string) ($metadata['phone_number_id'] ?? '');
        $display = preg_replace('/\D/', '', (string) ($metadata['display_phone_number'] ?? ''));

        foreach ($registry as $app) {
            if (!($app['enabled'] ?? false)) {
                continue;
            }
            $rule = $app['match'] ?? [];

            $idList = array_map('strval', $rule['phone_number_id'] ?? []);
            if ($pnid !== '' && in_array($pnid, $idList, true)) {
                return $app;
            }

            $dispList = array_map(
                fn($n) => preg_replace('/\D/', '', (string) $n),
                $rule['display_phone_number'] ?? []
            );
            if ($display !== '' && in_array($display, $dispList, true)) {
                return $app;
            }
        }
        return null;
    }

    /**
     * Mode 1 (default) - "open": allowlist not enforced, every sender is
     * handled (current benchmark behavior).
     * Mode 2 - "enforced": only wa_ids listed in the app's allowed_senders
     * are handled; everyone else is silently dropped (logged as
     * 'unauthorized', no reply sent - avoids spending messages on spam and
     * avoids confirming to a prober that the number is a live bot).
     */
    private static function isAllowed(array $app, string $from): bool
    {
        $mode = $app['allowlist_mode'] ?? 'open';
        if ($mode !== 'enforced') {
            return true;
        }
        return $from !== '' && in_array($from, self::allowedNumbers($app), true);
    }

    /**
     * Digits-only list of permitted wa_ids. An allowed_senders entry may be a
     * bare string ("972...") or an object ({"number":"972...","name":"..."}) —
     * both are accepted, so a registry can move to named entries without a
     * router change.
     */
    private static function allowedNumbers(array $app): array
    {
        $out = [];
        foreach ($app['allowed_senders'] ?? [] as $entry) {
            $num = is_array($entry) ? ($entry['number'] ?? '') : $entry;
            $num = preg_replace('/\D/', '', (string) $num);
            if ($num !== '') {
                $out[] = $num;
            }
        }
        return $out;
    }

    /**
     * The name a registry associates with this sender's number, or '' if the
     * entry is a bare string / not listed. Surfaced to handlers as
     * event['allowlist_name'] — POAgent uses it as the PO generator_id.
     */
    private static function senderNameFor(array $app, string $from): string
    {
        foreach ($app['allowed_senders'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $num = preg_replace('/\D/', '', (string) ($entry['number'] ?? ''));
            if ($num !== '' && $num === $from) {
                return trim((string) ($entry['name'] ?? ''));
            }
        }
        return '';
    }

    private static function loadHandlerFile(array $app): void
    {
        $file = $app['handler']['file'] ?? null;
        if (!$file) {
            return;
        }
        $path = self::resolvePath($file);
        if ($path !== null && is_file($path)) {
            require_once $path;
        } else {
            self::log(['ts' => date('c'), 'event' => 'handler_file_missing', 'app' => $app['id'], 'file' => $file]);
        }
    }

    /** Registry paths are relative to whatsapp_app/. */
    private static function resolvePath(string $file): ?string
    {
        if ($file === '') {
            return null;
        }
        $base = realpath(__DIR__ . '/..');
        $candidate = $base . '/' . ltrim($file, '/\\');
        $real = realpath($candidate);
        return $real ?: $candidate;
    }

    // ── event normalization ─────────────────────────────────────────────────

    private static function indexContacts(array $contacts): array
    {
        $byWaId = [];
        foreach ($contacts as $c) {
            $waId = (string) ($c['wa_id'] ?? '');
            if ($waId !== '') {
                $byWaId[$waId] = (string) ($c['profile']['name'] ?? '');
            }
        }
        return $byWaId;
    }

    /**
     * Turn one raw Meta message object into the flat event every handler gets:
     *
     *   app_id                    string   matched app id ("poagent")
     *   business_phone_number_id  string   our number's id (send replies "from" this)
     *   business_display_number   string   our number, digits only
     *   from                      string   sender's wa_id (phone, digits)
     *   wa_id                     string   alias of `from`
     *   contact_name              string   sender's WhatsApp profile name ('' if unknown)
     *   message_id                string   wamid... (for dedup / read receipts)
     *   timestamp                 int      unix seconds
     *   type                      string   text|interactive|button|image|audio|document|...
     *   text                      string   best-effort text: body / button title / reply title|id
     *   reply_id                  string   interactive button/list reply id, '' otherwise
     *   raw_message               array    the untouched message object
     *   session                  ?array   current WaSessionStore record for this wa_id, or null
     *   allowlist_name            string   name the registry maps to this sender's number, '' if none
     */
    private static function normalizeEvent(array $app, array $metadata, array $contacts, array $message): array
    {
        $type  = (string) ($message['type'] ?? '');
        $from  = preg_replace('/\D/', '', (string) ($message['from'] ?? ''));

        [$text, $replyId] = self::extractText($message);

        return [
            'app_id'                   => $app['id'] ?? '',
            'business_phone_number_id' => (string) ($metadata['phone_number_id'] ?? ''),
            'business_display_number'  => preg_replace('/\D/', '', (string) ($metadata['display_phone_number'] ?? '')),
            'from'                     => $from,
            'wa_id'                    => $from,
            'contact_name'             => $contacts[$from] ?? '',
            'message_id'               => (string) ($message['id'] ?? ''),
            'timestamp'                => (int) ($message['timestamp'] ?? time()),
            'type'                     => $type,
            'text'                     => $text,
            'reply_id'                 => $replyId,
            'raw_message'              => $message,
            'session'                  => WaSessionStore::get($from),
            'allowlist_name'           => self::senderNameFor($app, $from),
        ];
    }

    /** @return array{0:string,1:string} [normalized text, interactive reply id] */
    private static function extractText(array $message): array
    {
        switch ($message['type'] ?? '') {
            case 'text':
                return [trim((string) ($message['text']['body'] ?? '')), ''];
            case 'button': // template quick-reply
                return [trim((string) ($message['button']['text'] ?? '')), (string) ($message['button']['payload'] ?? '')];
            case 'interactive':
                $i    = $message['interactive'] ?? [];
                $br   = $i['button_reply'] ?? null;
                $lr   = $i['list_reply'] ?? null;
                $node = $br ?? $lr ?? [];
                return [trim((string) ($node['title'] ?? $node['id'] ?? '')), (string) ($node['id'] ?? '')];
            default:
                return ['', ''];
        }
    }

    // ── logging ─────────────────────────────────────────────────────────────

    private static function log(array $entry): void
    {
        if (!is_dir(WA_ROUTER_LOG_DIR)) {
            @mkdir(WA_ROUTER_LOG_DIR, 0775, true);
        }
        @file_put_contents(
            WA_ROUTER_LOG_DIR . '/router_' . date('Y-m-d') . '.log',
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

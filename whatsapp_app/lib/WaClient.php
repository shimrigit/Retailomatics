<?php
// WaClient.php — outbound WhatsApp Cloud API calls, shared by every app.
//
// Before this existed each app rolled its own curl POST to the Graph API.
// Now there is one place that knows the endpoint, the token, and the payload
// shapes. The access token is read once from whatsapp_app/config.json
// ("meta_key") — the same token the NP media-download path uses.

define('WA_GRAPH_VERSION', 'v21.0');
define('WA_CONFIG_FILE', __DIR__ . '/../config.json');
define('WA_SEND_LOG_DIR', __DIR__ . '/../logs');

class WaClient
{
    /**
     * Send a plain text message. Returns true on HTTP 200.
     * $phoneNumberId is the *business* number's id (event's
     * business_phone_number_id) — i.e. which of our numbers it's sent "from".
     */
    public static function text(string $phoneNumberId, string $to, string $body): bool
    {
        return self::send($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['preview_url' => false, 'body' => $body],
        ]);
    }

    /**
     * Send an interactive reply-buttons message (max 3 buttons).
     * $buttons: [ ['id' => 'opt_a', 'title' => 'הקם הזמנה'], ... ]
     * Kept here for when the apps move past the plain-text menu.
     */
    public static function buttons(string $phoneNumberId, string $to, string $bodyText, array $buttons): bool
    {
        $rows = [];
        foreach (array_slice($buttons, 0, 3) as $b) {
            $rows[] = ['type' => 'reply', 'reply' => [
                'id'    => (string) ($b['id'] ?? ''),
                'title' => mb_substr((string) ($b['title'] ?? ''), 0, 20),
            ]];
        }
        return self::send($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'button',
                'body'   => ['text' => $bodyText],
                'action' => ['buttons' => $rows],
            ],
        ]);
    }

    /**
     * Send an interactive list message — a single tappable section with up to
     * 10 rows. Use when there are more than 3 options (buttons() caps at 3).
     * $rows: [ ['id' => 'menu_po_new', 'title' => 'צור הזמנה', 'description' => '...'], ... ]
     *   - title       ≤ 24 chars (Meta limit), required
     *   - description ≤ 72 chars, optional
     * $buttonLabel is the label on the button that opens the list (≤ 20 chars).
     * The chosen row comes back as an interactive `list_reply` — WaRouter maps
     * it to event['reply_id'] (the row id) and event['text'] (the row title).
     */
    public static function list(
        string $phoneNumberId,
        string $to,
        string $bodyText,
        array $rows,
        string $buttonLabel = 'בחירה',
        string $sectionTitle = ''
    ): bool {
        $listRows = [];
        foreach (array_slice($rows, 0, 10) as $r) {
            $row = [
                'id'    => (string) ($r['id'] ?? ''),
                'title' => mb_substr((string) ($r['title'] ?? ''), 0, 24),
            ];
            $desc = trim((string) ($r['description'] ?? ''));
            if ($desc !== '') {
                $row['description'] = mb_substr($desc, 0, 72);
            }
            $listRows[] = $row;
        }

        $section = ['rows' => $listRows];
        if ($sectionTitle !== '') {
            $section['title'] = mb_substr($sectionTitle, 0, 24);
        }

        return self::send($phoneNumberId, [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'list',
                'body'   => ['text' => $bodyText],
                'action' => [
                    'button'   => mb_substr($buttonLabel, 0, 20),
                    'sections' => [$section],
                ],
            ],
        ]);
    }

    // ── internals ───────────────────────────────────────────────────────────

    private static function send(string $phoneNumberId, array $payload): bool
    {
        $token = self::token();
        if ($token === '' || $phoneNumberId === '') {
            self::log([
                'ts'     => date('c'),
                'event'  => 'send_skipped',
                'to'     => $payload['to'] ?? null,
                'reason' => $token === '' ? 'no access token in config.json' : 'no phone_number_id',
            ]);
            return false;
        }

        $url = 'https://graph.facebook.com/' . WA_GRAPH_VERSION . '/' . $phoneNumberId . '/messages';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $resp   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($status !== 200) {
            self::log([
                'ts'         => date('c'),
                'event'      => 'send_failed',
                'to'         => $payload['to'] ?? null,
                'type'       => $payload['type'] ?? null,
                'status'     => $status,
                'curl_error' => $err,
                'response'   => is_string($resp) ? mb_substr($resp, 0, 800) : null,
            ]);
            return false;
        }
        return true;
    }

    private static function token(): string
    {
        static $token = null;
        if ($token !== null) {
            return $token;
        }
        $token = '';
        if (is_file(WA_CONFIG_FILE)) {
            $cfg = json_decode((string) file_get_contents(WA_CONFIG_FILE), true);
            if (is_array($cfg)) {
                $token = (string) ($cfg['meta_key'] ?? '');
            }
        }
        return $token;
    }

    private static function log(array $entry): void
    {
        if (!is_dir(WA_SEND_LOG_DIR)) {
            @mkdir(WA_SEND_LOG_DIR, 0775, true);
        }
        @file_put_contents(
            WA_SEND_LOG_DIR . '/send_' . date('Y-m-d') . '.log',
            json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

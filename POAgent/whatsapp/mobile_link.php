<?php
/**
 * POAgent — mobile web login links.
 *
 * The WhatsApp bot (bot.php) does not run the PO flow in chat; it sends the
 * user a one-tap link into the real PO web pages. This file issues and
 * verifies the token that link carries, and builds the absolute URL.
 *
 *   token  = base64url(json{p,n,iat,exp}) . "." . base64url(HMAC-SHA256(body, secret))
 *   p      = sender's phone number (digits) — the identity the PO is keyed on
 *   n      = display nickname (from apps.json), shown in the UI, not an identity
 *   exp    = unix expiry (POAGENT_WA_LINK_TTL seconds after issue)
 *
 * Secret: auto-generated once into POAgent/POcounter/.link_secret (that whole
 * directory is already git-ignored). No configuration needed.
 *
 * Landing script: POAgent/m/index.php — verifies the token, sets the PHP
 * session to that phone number, redirects into main_menu.php.
 */

require_once __DIR__ . '/../lib/NetworkMode.php';

const POAGENT_WA_LINK_TTL = 900; // 15 minutes

/** Stable HMAC secret; created on first use, reused thereafter. */
function poagent_wa_link_secret(): string
{
    $path = __DIR__ . '/../POcounter/.link_secret';
    if (is_file($path)) {
        $s = trim((string) file_get_contents($path));
        if ($s !== '') {
            return $s;
        }
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $s   = bin2hex(random_bytes(32));
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $s) !== false) {
        @rename($tmp, $path); // last writer wins; a losing race just self-heals on the next message
    }
    return $s;
}

function poagent_wa_b64url_encode(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function poagent_wa_b64url_decode(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/'));
}

/** Issue a signed login token for a phone number + display name. */
function poagent_wa_issue_token(string $phone, string $name, int $ttl = POAGENT_WA_LINK_TTL): string
{
    $now  = time();
    $body = poagent_wa_b64url_encode(json_encode([
        'p'   => preg_replace('/\D/', '', $phone),
        'n'   => $name,
        'iat' => $now,
        'exp' => $now + max(60, $ttl),
    ], JSON_UNESCAPED_UNICODE));
    $sig = poagent_wa_b64url_encode(hash_hmac('sha256', $body, poagent_wa_link_secret(), true));
    return $body . '.' . $sig;
}

/**
 * Verify a token. Returns ['phone'=>, 'name'=>, 'exp'=>] on success, or null
 * if malformed / bad signature / expired.
 */
function poagent_wa_verify_token(string $token): ?array
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return null;
    }
    [$body, $sig] = $parts;

    $expected = poagent_wa_b64url_encode(hash_hmac('sha256', $body, poagent_wa_link_secret(), true));
    if (!hash_equals($expected, $sig)) {
        return null;
    }

    $payload = json_decode(poagent_wa_b64url_decode($body), true);
    if (!is_array($payload)) {
        return null;
    }
    $phone = preg_replace('/\D/', '', (string) ($payload['p'] ?? ''));
    if ($phone === '' || (int) ($payload['exp'] ?? 0) < time()) {
        return null;
    }
    return ['phone' => $phone, 'name' => (string) ($payload['n'] ?? ''), 'exp' => (int) $payload['exp']];
}

/**
 * Scheme+host for the link. Order: POAGENT_WA_LINK_BASE_URL env var →
 * NetworkMode.php's "lan" setting (POAgent/POcounter/.link_base_url — see
 * POAgent/tools/network_mode.php for the switch UI) → the host of the
 * current request (i.e. the public host Meta hit the webhook on, normally
 * ngrok's domain).
 */
function poagent_wa_link_base_url(): string
{
    $env = getenv('POAGENT_WA_LINK_BASE_URL');
    if (is_string($env) && trim($env) !== '') {
        return rtrim(trim($env), '/');
    }
    $mode = poagent_network_mode_get();
    if ($mode['mode'] === 'lan') {
        return $mode['value'];
    }
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ($_SERVER['REQUEST_SCHEME'] ?? 'https');
    $host  = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host  = trim(explode(',', (string) $host)[0]);
    return $proto . '://' . $host;
}

/** Web path to the app root ("/website"), derived from the webhook request. */
function poagent_wa_app_web_base(): string
{
    $sn = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $marker = '/whatsapp_app/';
    if ($sn !== '' && strpos($sn, $marker) !== false) {
        return rtrim(substr($sn, 0, strpos($sn, $marker)), '/');
    }
    return '/website';
}

/** Full login URL to text to the user. */
function poagent_wa_mobile_link(string $phone, string $name): string
{
    return poagent_wa_link_base_url()
        . poagent_wa_app_web_base()
        . '/POAgent/m/?t=' . rawurlencode(poagent_wa_issue_token($phone, $name));
}

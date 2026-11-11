<?php
// WaSessionStore.php — per-user WhatsApp conversation state, shared by every
// app behind the webhook.
//
// One JSON file per wa_id under whatsapp_app/wa_sessions/. This is deliberately
// dumb (a file per phone number, whole-file rewrite, TTL on read) — good enough
// for the current single-laptop scale. Swap the guts for SQLite/Redis later
// without changing callers.
//
// The router does NOT route by session (routing is by business phone number
// only, per design). This exists so an app can remember where a given user is
// in its own flow — e.g. POAgent, after sending the A/B menu, records
// state="awaiting_menu_choice" so the *next* message from that user can be read
// as the answer.

if (!defined('WA_SESSION_DIR')) define('WA_SESSION_DIR', __DIR__ . '/../wa_sessions');   // tests point it elsewhere
define('WA_SESSION_DEFAULT_TTL', 3600); // seconds

class WaSessionStore
{
    /**
     * Current session for a user, or null if none / expired. An expired file
     * is deleted as a side effect so the directory self-cleans.
     *
     * Shape: ['wa_id'=>, 'app'=>, 'state'=>, 'data'=>[], 'updated_at'=>ISO8601,
     *         'expires_at'=>ISO8601]
     */
    public static function get(string $waId): ?array
    {
        $path = self::pathFor($waId);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $session = json_decode((string) file_get_contents($path), true);
        if (!is_array($session)) {
            @unlink($path);
            return null;
        }
        if (($session['expires_at'] ?? '') !== '' && strtotime($session['expires_at']) < time()) {
            @unlink($path);
            return null;
        }
        return $session;
    }

    /**
     * Create/replace the session for a user. Returns the stored record.
     * $ttlSeconds resets the expiry window on every call (sliding TTL).
     */
    public static function set(
        string $waId,
        string $app,
        string $state,
        array $data = [],
        int $ttlSeconds = WA_SESSION_DEFAULT_TTL
    ): array {
        $path = self::pathFor($waId);
        if ($path === null) {
            throw new InvalidArgumentException('Invalid wa_id for session key');
        }
        if (!is_dir(WA_SESSION_DIR)) {
            @mkdir(WA_SESSION_DIR, 0775, true);
        }

        $now = time();
        $session = [
            'wa_id'      => $waId,
            'app'        => $app,
            'state'      => $state,
            'data'       => $data,
            'updated_at' => date('c', $now),
            'expires_at' => date('c', $now + max(60, $ttlSeconds)),
        ];

        $tmp = $path . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, json_encode($session, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        rename($tmp, $path); // atomic replace on same filesystem

        return $session;
    }

    /** Merge fields into the existing session's `data` (no-op if no session). */
    public static function patchData(string $waId, array $patch): ?array
    {
        $session = self::get($waId);
        if ($session === null) {
            return null;
        }
        return self::set(
            $waId,
            $session['app'],
            $session['state'],
            array_merge($session['data'] ?? [], $patch),
            WA_SESSION_DEFAULT_TTL
        );
    }

    /**
     * All non-expired sessions currently belonging to a given app. For a
     * background sweeper (e.g. an idle-timeout reminder) that needs to scan
     * every open conversation instead of reacting to one inbound event.
     * Expired sessions are cleaned up as a side effect, same as get().
     */
    public static function allForApp(string $app): array
    {
        if (!is_dir(WA_SESSION_DIR)) {
            return [];
        }
        $result = [];
        foreach (glob(WA_SESSION_DIR . '/*.json') ?: [] as $path) {
            $waId    = basename($path, '.json');
            $session = self::get($waId); // self-cleans if expired/corrupt
            if ($session !== null && ($session['app'] ?? '') === $app) {
                $result[] = $session;
            }
        }
        return $result;
    }

    /** End a user's session. Safe to call when there is none. */
    public static function clear(string $waId): void
    {
        $path = self::pathFor($waId);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    /** wa_id is a phone number — keep digits only, use as the filename. */
    private static function pathFor(string $waId): ?string
    {
        $key = preg_replace('/\D/', '', $waId);
        if ($key === '' || strlen($key) > 20) {
            return null;
        }
        return WA_SESSION_DIR . '/' . $key . '.json';
    }
}

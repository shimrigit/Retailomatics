<?php
// UserAccess.php — who is logged in, and who is blocked. One file,
// POAgent/user_access.json (runtime state, not in git):
//   blocked   {generator_id: unix time}   no login link, no login, sessions closed
//   revoked   {generator_id: unix time}   sessions logged in before this are closed ("נתק")
//   sessions  {sha1(session id): {user, device, login, seen}}   seen = last page view
//   links     {generator_id: unix time}   the bot's last login link
//   codes     {6 digits: {sid, created, user, name}}   desktop login codes (index.php); the bot fills in user
//   fails     {phone: [unix times]}       wrong codes sent to the bot (a few per 10 minutes, then refused)
//   used      {sha1(link token): its expiry}   phone login links already used (each logs in once)
//   remember  {sha1(token): {user, name, created, expires}}   "remember this computer" after a code login
//             (cookie POAGENT_REMEMBER; lib/access_guard.php logs the browser back in from it)
// Written by lib/access_guard.php (every page view, at most once a minute per
// session), m/index.php / select_user.php / login_poll.php (login),
// whatsapp/bot.php (link sent, code claimed) and users.php (block / disconnect).

define('POAGENT_ACCESS_FILE', __DIR__ . '/../user_access.json');
define('POAGENT_ACTIVE_SECS', 600);            // "active now": a page view in the last 10 minutes
define('POAGENT_MOBILE_SESSION_SECS', 86400);  // m/index.php's session lifetime
define('POAGENT_DESKTOP_SESSION_SECS', 43200); // a desktop browser session — no fixed end; 12h idle counts as gone
define('POAGENT_CODE_SECS', 300);              // a desktop login code is good for 5 minutes
define('POAGENT_CODE_MAX_FAILS', 5);           // wrong codes per phone per 10 minutes
define('POAGENT_REMEMBER_SECS', 30 * 86400);   // a computer stays logged in 30 days after a code login
define('POAGENT_REMEMBER_COOKIE', 'POAGENT_REMEMBER');

/**
 * The login log — POAgent/logs/login_YYYY-MM-DD.log, one JSON line per event
 * (Israel time): every link opened / used, code sent / claimed, session
 * closed, remembered login, logout. For "why couldn't X log in".
 *   kind    m_link | desktop_code | desktop_fallback | session | remember | logout
 *   result  e.g. ok, opened, preview, used, expired, blocked, reopen, invalid, limited, closed
 */
function poagent_login_log(string $kind, string $user, string $result, array $extra = []): void
{
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $now = new DateTime('now', new DateTimeZone('Asia/Jerusalem'));
    $fwd = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
    $line = ['ts' => $now->format('Y-m-d H:i:s'), 'kind' => $kind, 'user' => $user, 'result' => $result]
        + $extra
        + ['ip' => $fwd !== '' ? $fwd : (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'),
           'ua' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160)];
    @file_put_contents("{$dir}/login_" . $now->format('Y-m-d') . '.log',
        json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

/** The newest login-log lines of the last $days days, newest first; $user narrows to one generator_id. */
function poagent_login_log_recent(int $limit = 50, ?string $user = null, int $days = 7): array
{
    $out = [];
    $day = new DateTime('now', new DateTimeZone('Asia/Jerusalem'));
    for ($i = 0; $i < $days && count($out) < $limit; $i++, $day->modify('-1 day')) {
        $lines = @file(__DIR__ . '/../logs/login_' . $day->format('Y-m-d') . '.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_reverse($lines) as $line) {
            $e = json_decode($line, true);
            if (!is_array($e) || ($user !== null && (string) ($e['user'] ?? '') !== $user)) continue;
            $out[] = $e;
            if (count($out) >= $limit) break;
        }
    }
    return $out;
}

function poagent_access_read(): array
{
    $d = json_decode((string) @file_get_contents(POAGENT_ACCESS_FILE), true);
    $d = is_array($d) ? $d : [];
    foreach (['blocked', 'revoked', 'sessions', 'links', 'codes', 'fails', 'used', 'remember'] as $k) $d[$k] = (array) ($d[$k] ?? []);
    return $d;
}

/** Read-modify-write under a lock; $fn gets the state by reference. */
function poagent_access_update(callable $fn): void
{
    $lock = fopen(POAGENT_ACCESS_FILE . '.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $d = poagent_access_read();
        $fn($d);
        // Sessions unseen for 2 days are long gone.
        $d['sessions'] = array_filter($d['sessions'], fn($s) => ($s['seen'] ?? 0) > time() - 172800);
        $d['codes'] = array_filter($d['codes'], fn($c) => $c['created'] > time() - 2 * POAGENT_CODE_SECS);
        $d['remember'] = array_filter($d['remember'], fn($r) => $r['expires'] > time());
        $d['used'] = array_filter($d['used'], fn($exp) => $exp > time());   // an expired link fails anyway
        $d['fails'] = array_filter(array_map(fn($f) => array_values(array_filter($f, fn($t) => $t > time() - 600)), $d['fails']));
        $tmp = POAGENT_ACCESS_FILE . '.tmp';
        file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        rename($tmp, POAGENT_ACCESS_FILE);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function poagent_access_is_blocked(string $id): bool
{
    return isset(poagent_access_read()['blocked'][$id]);
}

/** False when the session must be closed: the user is blocked, or was disconnected after this login. */
function poagent_access_session_ok(string $id, int $loginAt): bool
{
    $d = poagent_access_read();
    return !isset($d['blocked'][$id]) && $loginAt >= (int) ($d['revoked'][$id] ?? 0);
}

/** A page view: records the session's last activity (at most once a minute). */
function poagent_access_touch(string $id, string $sessionId, bool $mobile, int $loginAt): void
{
    $key = sha1($sessionId);
    $s = poagent_access_read()['sessions'][$key] ?? null;
    if ($s && $s['user'] === $id && time() - (int) $s['seen'] < 60) return;
    poagent_access_update(function (&$d) use ($key, $id, $mobile, $loginAt) {
        $d['sessions'][$key] = ['user' => $id, 'device' => $mobile ? 'mobile' : 'desktop', 'login' => $loginAt, 'seen' => time()];
    });
}

function poagent_access_note_link(string $id): void
{
    poagent_access_update(function (&$d) use ($id) { $d['links'][$id] = time(); });
}

/** "נתק": every current session of $id is closed on its next page view; logging in again works. */
function poagent_access_disconnect(string $id): void
{
    poagent_access_update(function (&$d) use ($id) {
        $d['revoked'][$id] = time();
        $d['sessions'] = array_filter($d['sessions'], fn($s) => $s['user'] !== $id);
        $d['remember'] = array_filter($d['remember'], fn($r) => $r['user'] !== $id);
    });
}

function poagent_access_set_blocked(string $id, bool $blocked): void
{
    poagent_access_update(function (&$d) use ($id, $blocked) {
        if ($blocked) {
            $d['blocked'][$id] = time();
            $d['sessions'] = array_filter($d['sessions'], fn($s) => $s['user'] !== $id);
            $d['remember'] = array_filter($d['remember'], fn($r) => $r['user'] !== $id);
        } else {
            unset($d['blocked'][$id]);
        }
    });
}

/** A new desktop login code for this browser session (replaces the session's earlier one). */
function poagent_access_new_code(string $sessionId): string
{
    $code = '';
    poagent_access_update(function (&$d) use ($sessionId, &$code) {
        $sid = sha1($sessionId);
        $d['codes'] = array_filter($d['codes'], fn($c) => $c['sid'] !== $sid);
        do { $code = (string) random_int(100000, 999999); } while (isset($d['codes'][$code]));
        $d['codes'][$code] = ['sid' => $sid, 'created' => time(), 'user' => null, 'name' => ''];
    });
    return $code;
}

/** The bot got $code from $from: 'ok' | 'invalid' (unknown, expired or used) | 'limited' (too many wrong codes). */
function poagent_access_claim_code(string $code, string $from, string $name): string
{
    $result = 'invalid';
    poagent_access_update(function (&$d) use ($code, $from, $name, &$result) {
        if (count($d['fails'][$from] ?? []) >= POAGENT_CODE_MAX_FAILS) { $result = 'limited'; return; }
        $c = $d['codes'][$code] ?? null;
        if ($c && $c['user'] === null && $c['created'] > time() - POAGENT_CODE_SECS) {
            $d['codes'][$code]['user'] = $from;
            $d['codes'][$code]['name'] = $name;
            $result = 'ok';
        } else {
            $d['fails'][$from][] = time();
        }
    });
    return $result;
}

/** A phone login link's first use: true (and it's used up now), or false if it was used before. */
function poagent_access_use_link(string $token, string $phone, int $exp): bool
{
    $first = false;
    poagent_access_update(function (&$d) use ($token, $phone, $exp, &$first) {
        $key = sha1($token);
        if (isset($d['used'][$key])) return;
        $d['used'][$key] = $exp;
        unset($d['links'][$phone]);   // the users screen no longer shows a usable link
        $first = true;
    });
    return $first;
}

/** "Remember this computer": a new token for $user, set as a 30-day cookie on this browser. */
function poagent_access_remember(string $user, string $name): void
{
    $token = bin2hex(random_bytes(32));
    poagent_access_update(function (&$d) use ($token, $user, $name) {
        $d['remember'][sha1($token)] = ['user' => $user, 'name' => $name, 'created' => time(), 'expires' => time() + POAGENT_REMEMBER_SECS];
    });
    setcookie(POAGENT_REMEMBER_COOKIE, $token, ['expires' => time() + POAGENT_REMEMBER_SECS, 'path' => '/website/POAgent/',
        'httponly' => true, 'samesite' => 'Lax']);
}

/** This browser's remembered login ({user, name, created}), or null — expired, blocked or disconnected since. */
function poagent_access_remembered(): ?array
{
    $token = (string) ($_COOKIE[POAGENT_REMEMBER_COOKIE] ?? '');
    if ($token === '') return null;
    $d = poagent_access_read();
    $r = $d['remember'][sha1($token)] ?? null;
    if (!$r || $r['expires'] <= time() || isset($d['blocked'][$r['user']]) || $r['created'] < (int) ($d['revoked'][$r['user']] ?? 0)) {
        return null;
    }
    return $r;
}

/** Logout: forget this browser. */
function poagent_access_forget(): void
{
    $token = (string) ($_COOKIE[POAGENT_REMEMBER_COOKIE] ?? '');
    if ($token !== '') {
        poagent_access_update(function (&$d) use ($token) { unset($d['remember'][sha1($token)]); });
    }
    setcookie(POAGENT_REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/website/POAgent/']);
}

/** Lets a phone send codes again after too many wrong ones (users.php). */
function poagent_access_clear_fails(string $id): void
{
    poagent_access_update(function (&$d) use ($id) { unset($d['fails'][$id]); });
}

/**
 * The desktop page asks whether its code was sent: ['status' => 'wait'] |
 * ['status' => 'expired'] | ['status' => 'ok', 'user' => phone, 'name' => …] (the code is used up).
 */
function poagent_access_poll_code(string $sessionId): array
{
    $out = ['status' => 'expired'];
    poagent_access_update(function (&$d) use ($sessionId, &$out) {
        $sid = sha1($sessionId);
        foreach ($d['codes'] as $code => $c) {
            if ($c['sid'] !== $sid) continue;
            if ($c['user'] !== null) {
                $out = ['status' => 'ok', 'user' => $c['user'], 'name' => $c['name']];
                unset($d['codes'][$code]);
            } elseif ($c['created'] > time() - POAGENT_CODE_SECS) {
                $out = ['status' => 'wait'];
            }
            return;
        }
    });
    return $out;
}

/**
 * A user's status for the users screen:
 *   state     'blocked' | 'active' (a page view in the last 10 min) | 'connected' (a live session) | 'off'
 *   seen      last page view (unix) or null;  device  'mobile' | 'desktop' of that view
 *   sessions  live sessions;  link / linkValid  the bot's last login link, and whether it still works
 *   blockedAt
 *   fails     wrong desktop login codes in the last 10 minutes
 *   remembered  computers that remember them ("connected" even with no open session)
 */
function poagent_access_status(string $id, ?array $d = null): array
{
    $d = $d ?? poagent_access_read();
    $now = time();
    $revoked = (int) ($d['revoked'][$id] ?? 0);
    $seen = null; $device = null; $live = 0;
    foreach ($d['sessions'] as $s) {
        if ($s['user'] !== $id || (int) $s['login'] < $revoked) continue;
        if ($seen === null || $s['seen'] > $seen) { $seen = (int) $s['seen']; $device = $s['device']; }
        $ttl = $s['device'] === 'mobile' ? POAGENT_MOBILE_SESSION_SECS : POAGENT_DESKTOP_SESSION_SECS;
        // A phone session ends 24h after login; a desktop one when it's idle that long.
        $end = $s['device'] === 'mobile' ? (int) $s['login'] + $ttl : (int) $s['seen'] + $ttl;
        if ($end > $now) $live++;
    }
    $remembered = count(array_filter($d['remember'], fn($r) => $r['user'] === $id && $r['expires'] > $now && $r['created'] >= $revoked));
    $link = isset($d['links'][$id]) ? (int) $d['links'][$id] : null;
    $ttl = defined('POAGENT_WA_LINK_TTL') ? POAGENT_WA_LINK_TTL : 900;
    $state = isset($d['blocked'][$id]) ? 'blocked'
        : ($live && $seen > $now - POAGENT_ACTIVE_SECS ? 'active' : ($live || $remembered ? 'connected' : 'off'));
    return ['state' => $state, 'seen' => $seen, 'device' => $device, 'sessions' => $state === 'blocked' ? 0 : $live,
        'link' => $link, 'linkValid' => $link && $link + $ttl > $now && $link > $revoked,
        'blockedAt' => $d['blocked'][$id] ?? null,
        'remembered' => isset($d['blocked'][$id]) ? 0 : $remembered,   // computers that keep them logged in
        // Wrong desktop codes in the last 10 minutes; at POAGENT_CODE_MAX_FAILS the bot stops taking codes.
        'fails' => count(array_filter((array) ($d['fails'][$id] ?? []), fn($t) => $t > $now - 600))];
}

<?php
// access_guard.php — runs before every POAgent page (auto_prepend_file in
// ../.htaccess).
//  1. From outside (the ngrok tunnel, or a phone in LAN mode): only a phone
//     session — logged in through the WhatsApp bot's link (m/index.php) — gets
//     in. The desktop login (index.php → user1/2/3) and the desktop pages are
//     refused from outside.
//  2. On this computer, a browser remembered at a code login is logged back in.
//  3. Any logged-in session: closed if its user was blocked or disconnected
//     (users.php), otherwise its activity is recorded (lib/UserAccess.php).

require_once __DIR__ . '/UserAccess.php';

(function () {
    $remote = !empty($_SERVER['HTTP_X_FORWARDED_FOR'])
        || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

    // The link login and logout must stay reachable; they make the session.
    if (preg_match('#/POAgent/m/(index|logout)\.php$#', $script)) return;
    // The desktop login would let a phone session switch to user1/2/3.
    $desktopLogin = (bool) preg_match('#/POAgent/(index|select_user)\.php$#', $script);

    // Peek at the session without keeping it open — the page starts it again.
    // No session cookie → nothing to peek at (and don't create one).
    $sess = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sess = $_SESSION;
    } elseif (isset($_COOKIE[session_name()])) {
        session_start();
        $sess = $_SESSION;
        session_write_close();
    }
    $mobile = !empty($sess['poagent_mobile']);
    $id = (string) ($sess['poagent_generator_id'] ?? '');

    if ($remote && (!$mobile || $desktopLogin)) {
        poagent_guard_page(403, 'אין גישה', 'מחוץ למחשב המשרד אפשר להיכנס רק דרך הקישור שהבוט של POAgent שולח ב-WhatsApp.');
    }
    // Not logged in, but this computer was remembered at a code login (index.php): log back in.
    if ($id === '' && !$remote && ($r = poagent_access_remembered())) {
        session_start();
        $_SESSION['poagent_generator_id'] = $id = $r['user'];
        $_SESSION['poagent_display_name'] = $r['name'] !== '' ? $r['name'] : $r['user'];
        $_SESSION['poagent_login_at']     = (int) $r['created'];   // "נתק" after it closes it again
        $_SESSION['poagent_wa_desktop']   = true;
        $sess = $_SESSION;
        session_write_close();
        poagent_login_log('remember', $id, 'ok');
    }
    if ($id === '') return;

    if (!poagent_access_session_ok($id, (int) ($sess['poagent_login_at'] ?? 0))) {
        poagent_login_log('session', $id, poagent_access_is_blocked($id) ? 'closed_blocked' : 'closed_disconnected');
        session_start();
        $_SESSION = [];
        session_destroy();
        if (!$mobile) {   // desktop: back to the user picker
            header('Location: index.php');
            exit;
        }
        poagent_guard_page(403, 'נותקת מהמערכת', poagent_access_is_blocked($id)
            ? 'הגישה שלך ל-POAgent חסומה. לפרטים פנה/י למשרד.'
            : 'כדי להיכנס מחדש, שלח/י הודעה לבוט של POAgent ב-WhatsApp לקבלת קישור חדש.');
    }
    poagent_access_touch($id, (string) session_id(), $mobile, (int) ($sess['poagent_login_at'] ?? 0));
})();

function poagent_guard_page(int $code, string $title, string $text): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><html dir="rtl" lang="he"><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<body style="font-family:sans-serif;padding:24px"><h2>' . htmlspecialchars($title) . '</h2>'
        . '<p>' . htmlspecialchars($text) . '</p></body></html>');
}

<?php
/**
 * POAgent — mobile web entry point.
 *
 * The WhatsApp bot texts the user a link to here carrying a short-lived
 * signed token (see ../whatsapp/mobile_link.php). This script verifies it,
 * logs the user in as their phone number (that becomes the PO's
 * generator_id, exactly like the desktop `generator_id`), marks the session
 * as mobile, and drops them into the normal PO flow.
 *
 * No token / bad token / expired token → a short "ask the bot for a new
 * link" page. Nothing here is reachable without a valid token.
 *
 * Single use: the first login uses the link up (lib/UserAccess.php). Opening
 * the link (GET) only shows a page that posts the token back — the POST logs
 * in — so a scanner that only fetches the URL doesn't use it up; preview
 * crawlers that run scripts are turned away by user agent (below).
 * Every step goes to the login log (poagent_login_log → POAgent/logs/login_*.log).
 */

require_once __DIR__ . '/../whatsapp/mobile_link.php';
require_once __DIR__ . '/../lib/ui_common.php';
require_once __DIR__ . '/../lib/UserAccess.php';

$token   = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$payload = $token !== '' ? poagent_wa_verify_token($token) : null;
// For the login log only: whose link it claims to be, even when it fails verification.
$claimed = $payload['phone'] ?? (string) (json_decode(poagent_wa_b64url_decode(explode('.', $token)[0]), true)['p'] ?? '');

// Link-preview crawlers. WhatsApp's (facebookexternalhit) runs the page's
// script too — it posted the token and used Tal's first link up (Nov 10) — so
// they get a plain page, never the login.
$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
if (preg_match('/facebookexternalhit|facebot|meta-externalagent|whatsapp|bot\b|crawler|spider|preview|slurp|curl\/|wget/i', $ua)) {
    poagent_login_log('m_link', $claimed, 'preview');
    http_response_code(200);
    exit('<!doctype html><html dir="rtl" lang="he"><meta charset="utf-8"><title>POAgent – כניסה</title><body>POAgent</body></html>');
}

// A link sent before the user was blocked must not log them in.
if ($payload !== null && poagent_access_is_blocked((string) $payload['phone'])) {
    poagent_login_log('m_link', $claimed, 'blocked');
    session_start();
    poagent_render_head('POAgent – אין גישה');
    echo '<h2>הגישה שלך ל-POAgent חסומה</h2><p class="muted">לפרטים פנה/י למשרד.</p>';
    poagent_render_foot();
    exit;
}

if ($payload === null) {
    poagent_login_log('m_link', $claimed, $token === '' ? 'no_token' : 'expired_or_invalid');
    session_start();
    poagent_render_head('POAgent – קישור לא תקין');
    ?>
    <h2>הקישור אינו תקין או שפג תוקפו</h2>
    <p class="muted">
        שלח/י הודעה לבוט של POAgent ב-WhatsApp כדי לקבל קישור כניסה חדש
        (הקישור בתוקף ל-15 דקות מרגע השליחה).
    </p>
    <?php
    poagent_render_foot();
    exit;
}

// Valid token → give this phone a longer-lived session so a tab reopened
// later still works, then log in.
$life = 86400; // 24h
session_set_cookie_params(['lifetime' => $life, 'samesite' => 'Lax']);
ini_set('session.gc_maxlifetime', (string) $life);
session_start();

// The link reopened on a phone that's already logged in as that number: just go in.
if (($_SESSION['poagent_generator_id'] ?? '') === $payload['phone'] && !empty($_SESSION['poagent_mobile'])) {
    poagent_login_log('m_link', $claimed, 'reopen');
    header('Location: ../main_menu.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    poagent_login_log('m_link', $claimed, 'opened');
    poagent_render_head('POAgent – כניסה');
    ?>
    <form id="go" method="post" action="index.php">
        <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
        <h2>נכנס למערכת…</h2>
        <button type="submit">כניסה למערכת</button>
    </form>
    <script>document.getElementById('go').submit();</script>
    <?php
    poagent_render_foot();
    exit;
}

if (!poagent_access_use_link($token, (string) $payload['phone'], (int) $payload['exp'])) {
    poagent_login_log('m_link', $claimed, 'used');
    poagent_render_head('POAgent – הקישור כבר נוצל');
    ?>
    <h2>הקישור הזה כבר שימש לכניסה</h2>
    <p class="muted">כל קישור כניסה טוב לפעם אחת. שלח/י הודעה לבוט של POAgent ב-WhatsApp כדי לקבל קישור חדש.</p>
    <?php
    poagent_render_foot();
    exit;
}
session_regenerate_id(true); // session-fixation guard on privilege change

$_SESSION['poagent_generator_id'] = $payload['phone']; // identity = phone number
$_SESSION['poagent_display_name'] = $payload['name'];   // nickname for the UI only
$_SESSION['poagent_mobile']       = true;
$_SESSION['poagent_link_exp']     = $payload['exp'];
$_SESSION['poagent_login_at']     = time();   // users.php's "נתק" closes sessions logged in before it
poagent_login_log('m_link', $claimed, 'ok');

header('Location: ../main_menu.php');
exit;

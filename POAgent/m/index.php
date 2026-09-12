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
 */

require_once __DIR__ . '/../whatsapp/mobile_link.php';
require_once __DIR__ . '/../lib/ui_common.php';

$token   = (string) ($_GET['t'] ?? '');
$payload = $token !== '' ? poagent_wa_verify_token($token) : null;

if ($payload === null) {
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
session_regenerate_id(true); // session-fixation guard on privilege change

$_SESSION['poagent_generator_id'] = $payload['phone']; // identity = phone number
$_SESSION['poagent_display_name'] = $payload['name'];   // nickname for the UI only
$_SESSION['poagent_mobile']       = true;
$_SESSION['poagent_link_exp']     = $payload['exp'];

header('Location: ../main_menu.php');
exit;

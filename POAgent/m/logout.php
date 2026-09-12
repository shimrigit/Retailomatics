<?php
// POAgent — mobile logout. Clears the session created by m/index.php.
require_once __DIR__ . '/../lib/ui_common.php';

session_start();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

poagent_render_head('POAgent – יציאה');
?>
<h2>יצאת מהמערכת</h2>
<p class="muted">
    כדי להיכנס מחדש, שלח/י הודעה לבוט של POAgent ב-WhatsApp לקבלת קישור כניסה חדש.
</p>
<?php
poagent_render_foot();

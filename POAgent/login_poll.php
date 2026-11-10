<?php
// Asked every 2 seconds by the desktop login page (index.php): did the code
// shown there reach the bot? When it did, this browser session is logged in
// as the phone that sent it — the same identity (generator_id = phone number)
// and role as that user's phone logins, only with the desktop menu.
session_start();
require_once __DIR__ . '/lib/UserAccess.php';
header('Content-Type: application/json');

$r = poagent_access_poll_code(session_id());
if ($r['status'] === 'ok') {
    if (poagent_access_is_blocked($r['user'])) {
        poagent_login_log('desktop_code', $r['user'], 'blocked');
        echo json_encode(['status' => 'blocked']);
        exit;
    }
    session_regenerate_id(true); // session-fixation guard on privilege change
    $_SESSION = [
        'poagent_generator_id' => $r['user'],
        'poagent_display_name' => $r['name'] !== '' ? $r['name'] : $r['user'],
        'poagent_login_at'     => time(),
        'poagent_wa_desktop'   => true,
    ];
    poagent_access_remember($r['user'], $r['name']);   // 30 days on this computer, until "יציאה"
    poagent_login_log('desktop_code', $r['user'], 'ok');
}
echo json_encode(['status' => $r['status']]);

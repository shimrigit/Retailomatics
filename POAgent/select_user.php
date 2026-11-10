<?php
// Handles Screen 1 submission — validates against the fixed user list and stores generator_id in session.
session_start();
require_once __DIR__ . '/lib/ui_common.php';

$generatorId = $_POST['generator_id'] ?? '';
require_once __DIR__ . '/lib/UserAccess.php';
if (!in_array($generatorId, POAGENT_USERS, true) || poagent_access_is_blocked($generatorId)) {
    poagent_login_log('desktop_fallback', $generatorId, 'refused');
    header('Location: index.php' . (in_array($generatorId, POAGENT_USERS, true) ? '?blocked=' . rawurlencode($generatorId) : ''));
    exit;
}

$_SESSION['poagent_generator_id'] = $generatorId;
$_SESSION['poagent_login_at']     = time();   // users.php's "נתק" closes sessions logged in before it
poagent_login_log('desktop_fallback', $generatorId, 'ok');
header('Location: main_menu.php');
exit;

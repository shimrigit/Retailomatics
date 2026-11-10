<?php
// Serves a delivery-note photo from DNdir/ to a logged-in session. DNdir/
// itself is closed to outside requests (../.htaccess), so the phone gets the
// photos through here.
session_start();
if (empty($_SESSION['poagent_generator_id'])) {
    http_response_code(403);
    exit;
}
session_write_close();

$name = basename((string) ($_GET['f'] ?? ''));
$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
$type = $types[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? null;
$path = __DIR__ . '/DNdir/' . $name;
if ($name === '' || !$type || !is_file($path)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);

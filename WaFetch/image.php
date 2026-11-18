<?php
// Serves one image from a shop's folder in the fetcher's output/images (outside
// the web root) for the page's thumbnails: ?s=<shop>&f=<file>. Only a plain file
// name, in the folder waf_images_dir() makes of the shop name.
require_once __DIR__ . '/lib.php';

$name = (string) ($_GET['f'] ?? '');
$path = waf_images_dir((string) ($_GET['s'] ?? '')) . '/' . $name;
if ($name === '' || $name !== basename($name) || !preg_match('/\.(jpe?g|png|webp)$/i', $name) || !is_file($path)) {
    http_response_code(404);
    exit;
}
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
header('Content-Type: ' . ($ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg')));
header('Cache-Control: max-age=300');
readfile($path);

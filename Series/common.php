<?php
// Shared by the Series pages: local access only (the shops' price lists; the
// pages write to Z:) — refused through the ngrok tunnel or from another machine.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    http_response_code(403);
    exit('מסך הסדרות זמין רק מהמחשב עצמו');
}
require_once __DIR__ . '/store.php';

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function money($p): string { return number_format((float) $p, 2); }

/** The page's shop from GET/POST, only one of the configured shops. */
function seriesRequestShop(): string
{
    $shop = (string) ($_REQUEST['shop'] ?? '');
    if (!in_array($shop, seriesShops(), true)) { http_response_code(400); exit('חנות לא מוכרת'); }
    return $shop;
}

function seriesPageHead(string $title): void
{ ?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<title><?= h($title) ?></title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 36px 45px; color: #333; }
    h2 { color: #2575a8; font-size: 34px; margin: 0 0 8px; }
    .sub { color: #777; font-size: 19px; margin-bottom: 26px; }
    a { color: #2575a8; }
    table { border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,.1); width: 100%; }
    th { background: #2575a8; color: #fff; padding: 12px 14px; font-size: 18px; text-align: right; }
    td { padding: 9px 14px; border-bottom: 1px solid #eee; font-size: 18px; vertical-align: middle; }
    .btn { display: inline-block; padding: 11px 26px; background: #2575a8; color: #fff; border: none; border-radius: 7px; font-size: 18px; cursor: pointer; text-decoration: none; }
    .btn:hover { background: #1a5c87; }
    .btn.green { background: #2a7d2a; } .btn.green:hover { background: #1e5c1e; }
    .btn.light { background: #e8f1f8; color: #2575a8; border: 1px solid #2575a8; }
    .btn:disabled { background: #999; cursor: wait; }
    .ok { color: #2a7d2a; font-weight: bold; } .warn { color: #c0392b; font-weight: bold; } .muted { color: #888; }
    .box { background: #fff; border: 1px solid #ddd; border-radius: 10px; padding: 18px 24px; margin-bottom: 22px; font-size: 19px; }
    .err { background: #fdecea; border: 2px solid #e57373; border-radius: 8px; padding: 16px 22px; margin-bottom: 22px; font-size: 19px; color: #c0392b; }
</style>
</head>
<body>
<?php }

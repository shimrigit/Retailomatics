<?php
// גיול חובות — the page's calls (lib.php). Local only: it reads and writes Z:.
//  GET  ?action=list&shop=          → {exports, settings, today}
//  POST {action:'settings', shop, settings}
//  POST {action:'preview', shop, path, day}  → {header, rows, total, marks, messages, existing}
//  POST {action:'save', shop, path, day, png} → writes the xlsx, the messages (.txt) and the picture (.png)
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    http_response_code(403);
    exit('זמין רק מהמחשב עצמו');
}
require_once __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');

function giyulOut(array $a): never
{
    echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $in = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : $_GET;
    $action = (string) ($in['action'] ?? '');
    $shop = (string) ($in['shop'] ?? '');
    if (!in_array($shop, giyulShops(), true)) throw new RuntimeException('חנות לא מוכרת');

    if ($action === 'list') {
        if (!is_dir(GIYUL_ARCHIVE)) throw new RuntimeException('Z לא זמין (' . GIYUL_ARCHIVE . ')');
        giyulOut(['ok' => true, 'exports' => giyulExports($shop), 'settings' => giyulSettings($shop), 'today' => date('Y-m-d')]);
    }
    if ($action === 'settings') {
        giyulSaveSettings($shop, (array) ($in['settings'] ?? []));
        giyulOut(['ok' => true, 'settings' => giyulSettings($shop)]);
    }

    $path = giyulCheckExport($shop, (string) ($in['path'] ?? ''));
    $day = (string) ($in['day'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !strtotime($day)) throw new RuntimeException('יום הרצה לא תקין');
    $set = giyulSettings($shop);
    $data = giyulRead($path);
    $marks = giyulMarks($data, (float) $set['threshold']);
    $dir = dirname($path);
    $base = giyulBase($day);

    if ($action === 'preview') {
        $existing = array_values(array_filter(array_map('basename', glob($dir . '/*') ?: []), fn($n) => str_starts_with($n, $base)));
        giyulOut(['ok' => true, 'header' => $data['header'], 'rows' => $data['rows'], 'total' => $data['total'], 'marks' => $marks,
            'messages' => giyulMessages($data, $set, $day), 'months' => giyulMonths($day), 'existing' => $existing, 'base' => $base, 'folder' => $dir]);
    }
    if ($action === 'save') {
        $saved = [giyulWriteXlsx($data, $marks, $dir, $day)];
        $m = giyulMessages($data, $set, $day);
        foreach (['big', 'old'] as $k) if (is_string($in['messages'][$k] ?? null)) $m[$k] = $in['messages'][$k];   // as edited on the page
        $txt = giyulFreeName($dir, "$base - הודעות.txt");
        file_put_contents("$dir/$txt", "\xEF\xBB\xBF" . str_replace("\n", "\r\n", "חובות גדולים:\n\n{$m['big']}\n\n\nחובות ישנים:\n\n{$m['old']}\n"));
        $saved[] = $txt;
        if (preg_match('#^data:image/png;base64,(.+)$#', (string) ($in['png'] ?? ''), $p) && ($bin = base64_decode($p[1], true))) {
            $png = giyulFreeName($dir, "$base.png");
            file_put_contents("$dir/$png", $bin);
            $saved[] = $png;
        }
        giyulOut(['ok' => true, 'saved' => $saved, 'folder' => $dir]);
    }
    throw new RuntimeException('פעולה לא מוכרת');
} catch (Throwable $e) {
    giyulOut(['ok' => false, 'error' => $e->getMessage()]);
}

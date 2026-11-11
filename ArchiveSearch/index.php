<?php
// ArchiveSearch — "איתור ברקוד בארכיון": in which archived Excel file (and so
// on which date) a barcode came in, for a customer's folder under
// Z:\RetailomaticsCloud\RetailomaticsArchive. Replaces
// scripts/find barcode in archive in excel.ps1 (Excel COM, file by file):
// .xlsx / .xlsm are read straight from their XML (zip), .xls with PhpSpreadsheet.
// A cell matches when it IS the barcode (leading zeros ignored), like the
// script's whole-cell Find. The date comes from the file's folder name
// ("31-08-26 NP"), else the file name, else the file's modified date.
// Read-only. This computer only (like Prices).
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    http_response_code(403);
    exit('Available on this computer only.');
}
set_time_limit(0);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/archive_search.php';

define('ARCHIVE_ROOT', 'Z:/RetailomaticsCloud/RetailomaticsArchive');

$customers = is_dir(ARCHIVE_ROOT)
    ? array_values(array_filter(scandir(ARCHIVE_ROOT), fn($d) => $d[0] !== '.' && is_dir(ARCHIVE_ROOT . "/$d")))
    : [];

// A file from the results, downloaded (only inside the archive).
if (isset($_GET['dl'])) {
    $path = realpath(ARCHIVE_ROOT . '/' . $_GET['dl']);
    $root = realpath(ARCHIVE_ROOT);
    if (!$path || !$root || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
        http_response_code(404);
        exit('Not found');
    }
    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode(basename($path)));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

$customer = in_array($_GET['c'] ?? '', $customers, true) ? $_GET['c'] : '';
$barcode  = preg_replace('/\D/', '', (string) ($_GET['b'] ?? ''));
$key      = ltrim($barcode, '0');   // what a cell is compared with

// The date a file came in: dd-mm-yy / dd.mm.yyyy in its folder names (nearest first), else its name, else modified.
function fileDate(string $rel, int $mtime): array
{
    $parts = explode('/', $rel);
    $name = array_pop($parts);
    foreach (array_merge(array_reverse($parts), [$name]) as $p) {
        if (preg_match('/(?<!\d)(\d{1,2})[-.](\d{1,2})[-.](\d{4}|\d{2})(?!\d)/', $p, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3] + (strlen($m[3]) === 2 ? 2000 : 0))) {
            $y = (int) $m[3] + (strlen($m[3]) === 2 ? 2000 : 0);
            return [sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]), 'folder'];
        }
    }
    return [date('Y-m-d', $mtime), 'modified'];
}

// The customer's index: per file (path, size, modified) the numbers in it (fileKeys()).
// Built on the first search, kept in cache/ (git-ignored — client data); a changed or
// new file is read again. A search then opens only the files that have the barcode.
$results = $failed = [];
$scanned = $opened = $indexed = 0;
$started = microtime(true);
if ($customer !== '' && strlen($key) >= 4) {
    $base = ARCHIVE_ROOT . '/' . $customer;
    $indexFile = __DIR__ . '/cache/' . preg_replace('/[^\w-]/', '_', $customer) . '.json';
    $index = json_decode((string) @file_get_contents($indexFile), true) ?: [];
    $newIndex = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $name = $f->getFilename();
        $ext = strtolower($f->getExtension());
        if (!in_array($ext, ['xlsx', 'xlsm', 'xls'], true) || str_starts_with($name, '~$')) continue;
        $scanned++;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
        $stamp = $f->getSize() . '/' . $f->getMTime();
        try {
            $entry = $index[$rel] ?? null;
            if (!$entry || $entry['stamp'] !== $stamp) {
                $entry = ['stamp' => $stamp, 'keys' => fileKeys($f->getPathname())];
                $indexed++;
            }
            $newIndex[$rel] = $entry;
            if ($entry['keys'] !== null && strpos($entry['keys'], " $key ") === false) continue;
            $opened++;
            $hits = searchFile($f->getPathname(), $key);
        } catch (Throwable $e) {
            $failed[] = "$rel: " . $e->getMessage();
            continue;
        }
        if ($hits) {
            [$date, $dateFrom] = fileDate($rel, $f->getMTime());
            foreach ($hits as $h) $results[] = $h + ['rel' => $rel, 'date' => $date, 'dateFrom' => $dateFrom];
        }
    }
    if ($indexed || count($newIndex) !== count($index)) {
        @mkdir(dirname($indexFile), 0777, true);
        file_put_contents($indexFile, json_encode($newIndex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    usort($results, fn($a, $b) => [$a['date'], $a['rel']] <=> [$b['date'], $b['rel']]);
}
// One row per folder — an NP day keeps every stage's file (NP, _BR, _BR_CHPF, _DCL…).
// The row shown is the most processed file's (the longest name: _BR_CHPF_DCL).
$folders = [];
foreach ($results as $r) {
    $dir = dirname($r['rel']);
    $folders[$dir] ??= ['dir' => $dir, 'date' => $r['date'], 'dateFrom' => $r['dateFrom'], 'files' => [], 'main' => $r];
    $folders[$dir]['files'][$r['rel']] = true;
    $main = $folders[$dir]['main'];
    if ([mb_strlen(basename($r['rel'])), $r['rel']] > [mb_strlen(basename($main['rel'])), $main['rel']]) $folders[$dir]['main'] = $r;
}
$folders = array_values($folders);
$seconds = round(microtime(true) - $started, 1);
$h = fn($s) => htmlspecialchars((string) $s);
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>איתור ברקוד בארכיון</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 32px 0; display: flex; justify-content: center; }
    .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,.15); padding: 32px 44px; width: 1150px; max-width: 95vw; box-sizing: border-box; }
    h2 { margin: 0 0 6px; color: #333; text-align: center; font-size: 30px; }
    .sub { text-align: center; color: #777; margin-bottom: 22px; }
    form { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; justify-content: center; }
    select, input[type=text] { font-size: 20px; padding: 10px; border: 1px solid #ccc; border-radius: 8px; }
    input[type=text] { direction: ltr; width: 260px; font-family: Consolas, monospace; }
    button { padding: 11px 24px; background: #2575a8; color: #fff; border: none; border-radius: 8px; font-size: 20px; cursor: pointer; }
    button:disabled { background: #999; }
    .summary { margin: 24px 0 10px; font-size: 18px; }
    table { width: 100%; border-collapse: collapse; font-size: 15px; }
    th, td { border: 1px solid #e3e3e3; padding: 7px 9px; text-align: right; vertical-align: top; } th { background: #f0f4f8; }
    td.date { white-space: nowrap; font-weight: bold; }
    .path { direction: ltr; text-align: left; font-family: Consolas, monospace; font-size: 13px; color: #555; word-break: break-all; }
    .row-vals { font-size: 13px; color: #444; }
    .row-vals span { display: inline-block; margin: 0 0 2px 10px; }
    .row-vals b { color: #2575a8; font-weight: normal; }
    .muted { color: #888; font-size: 13px; }
    .warn { color: #8a4b00; }
    a { color: #2575a8; }
</style>
</head>
<body>
<div class="card">
    <h2>איתור ברקוד בארכיון</h2>
    <div class="sub">באיזה קובץ בארכיון של הלקוח מופיע הברקוד — ובאיזה תאריך הוא התקבל</div>
    <?php if (!$customers): ?>
        <p class="warn" style="text-align:center">התיקייה <?= $h(str_replace('/', '\\', ARCHIVE_ROOT)) ?> לא נמצאה — האם כונן Z מחובר?</p>
    <?php endif; ?>
    <form method="get" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'מחפש…'">
        <select name="c" required>
            <option value="">— בחר לקוח —</option>
            <?php foreach ($customers as $c): ?>
                <option value="<?= $h($c) ?>" <?= $c === $customer ? 'selected' : '' ?>><?= $h($c) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="b" value="<?= $h($barcode) ?>" placeholder="ברקוד" inputmode="numeric" required autofocus>
        <button type="submit">חפש</button>
    </form>

    <?php if ($customer !== '' && $barcode !== ''): ?>
        <?php if (strlen($key) < 4): ?>
            <p class="warn">ברקוד קצר מדי.</p>
        <?php else: ?>
            <div class="summary">
                <?php if ($results): ?>
                    הברקוד <b dir="ltr"><?= $h($barcode) ?></b> נמצא ב-<b><?= count($folders) ?></b> תיקיות
                    (<?= count(array_unique(array_column($results, 'rel'))) ?> קבצים) · הופעה ראשונה: <b><?= $h(date('d/m/Y', strtotime($results[0]['date']))) ?></b>
                <?php else: ?>
                    הברקוד <b dir="ltr"><?= $h($barcode) ?></b> לא נמצא בארכיון של <?= $h($customer) ?>.
                <?php endif; ?>
                <div class="muted"><?= $scanned ?> קבצי אקסל · <?= $opened ?> נפתחו לחיפוש<?= $indexed ? " · $indexed נוספו לאינדקס (חדשים / השתנו)" : '' ?> · <?= $seconds ?> שניות</div>
            </div>
            <?php if ($results): ?>
                <table>
                    <tr><th>תאריך</th><th>תיקייה וקבצים</th><th>גיליון / תא</th><th>השורה בקובץ</th></tr>
                    <?php foreach ($folders as $g): $r = $g['main']; ?>
                        <tr>
                            <td class="date"><?= $h(date('d/m/Y', strtotime($g['date']))) ?>
                                <?php if ($g['dateFrom'] !== 'folder'): ?><div class="muted" title="אין תאריך בשם התיקייה או הקובץ">לפי תאריך שינוי</div><?php endif; ?></td>
                            <td><div class="path"><?= $h(str_replace('/', '\\', ARCHIVE_ROOT . "/$customer/" . $g['dir'])) ?></div>
                                <?php foreach (array_keys($g['files']) as $rel): ?>
                                    <div><a href="?<?= $h(http_build_query(['dl' => "$customer/$rel"])) ?>"><?= $h(basename($rel)) ?></a></div>
                                <?php endforeach; ?></td>
                            <td><?= $h($r['sheet']) ?><br><span class="muted" dir="ltr"><?= $h($r['cell']) ?></span>
                                <div class="muted"><?= $h(basename($r['rel'])) ?></div></td>
                            <td class="row-vals">
                                <?php foreach ($r['row'] as $col => $v): if (trim($v) === '') continue; ?>
                                    <?php $label = trim((string) ($r['header'][$col] ?? '')); ?>
                                    <?php // Excel's stored floats ("3.9909230769230799") — shown to 4 decimals.
                                    if (preg_match('/^-?\d+\.\d{5,}$/', $v)) $v = rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.'); ?>
                                    <span><?php if ($label !== '' && $label !== $v): ?><b><?= $h($label) ?>:</b> <?php endif; ?><?= $h($v) ?></span>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
            <?php if ($failed): ?>
                <details class="muted" style="margin-top:14px"><summary><?= count($failed) ?> קבצים לא נקראו</summary>
                    <ul><?php foreach ($failed as $f): ?><li><?= $h($f) ?></li><?php endforeach; ?></ul></details>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>

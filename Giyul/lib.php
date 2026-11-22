<?php
/**
 * גיול חובות — processing the debts-aging Export.xls the Comax Gateway downloads (actions/giyul.js)
 * into {drive}:\RetailomaticsCloud\RetailomaticsArchive\{shop}\Collection\{yyyy}\{mm_Month}\{dd-mm-yy CO}\.
 *
 * Done by hand before with a prompt in Claude; now here (index.php):
 *  1. the processed xlsx "גיול חובות - <run day dd-mm-yy>-מעובד.xlsx" in the same CO folder:
 *     only לקוח | שם לקוח | יתרה | the month columns (the 4 latest months + "עד …");
 *     the header row 25 high; יתרה > threshold → light yellow (not the total row);
 *     the 4 latest months all empty → light purple (over the yellow).
 *  2. two messages for the management group (big debts / old debts), by customer number,
 *     without the shop's excluded customers; the texts are editable per shop.
 *  3. a picture of the table (made in the browser) saved next to it.
 * The run day (default today) gives the months in the texts: XXXX = the month before it,
 * YYYY = 4 months before it.
 */

date_default_timezone_set('Asia/Jerusalem');
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

const GIYUL_ARCHIVE = 'Z:/RetailomaticsCloud/RetailomaticsArchive';
const GIYUL_SETTINGS = __DIR__ . '/../configDir/giyul_settings.json';
const GIYUL_MONTHS_HE = ['ינואר', 'פברואר', 'מרץ', 'אפריל', 'מאי', 'יוני', 'יולי', 'אוגוסט', 'ספטמבר', 'אוקטובר', 'נובמבר', 'דצמבר'];
const GIYUL_YELLOW = 'FFFF99';
const GIYUL_PURPLE = 'E4D4F4';

function giyulShops(): array
{
    $shops = json_decode((string) @file_get_contents(__DIR__ . '/../configDir/shops_V2.json'), true) ?: [];
    $out = [];
    foreach ($shops as $s) if (!empty($s['ShopName'])) $out[] = $s['ShopName'];
    return $out;
}

/** A shop's settings (the defaults filled in). */
function giyulSettings(string $shop): array
{
    $all = json_decode((string) @file_get_contents(GIYUL_SETTINGS), true) ?: [];
    $s = $all[$shop] ?? [];
    return $s + [
        'recipients' => ['דין'],
        'recipient' => $s['recipients'][0] ?? 'דין',
        'exclude' => [],
        'threshold' => 1000,
        'signature' => 'שמרי',
        'bigIntro' => 'להלן הלקוחות עם חובות מעל 1,000 ₪  נכון לחודש {XXXX} {XXXX_YEAR}:',
        'oldIntro' => 'להלן הלקוחות עם חובות ישנים שלא היו פעילים מאז {YYYY} {YYYY_YEAR} או לפני כן:',
    ];
}

function giyulSaveSettings(string $shop, array $s): void
{
    $all = json_decode((string) @file_get_contents(GIYUL_SETTINGS), true) ?: [];
    $clean = fn($list) => array_values(array_unique(array_filter(array_map(fn($x) => trim((string) $x), (array) $list), 'strlen')));
    $recipients = $clean($s['recipients'] ?? []);
    $recipient = trim((string) ($s['recipient'] ?? ''));
    if ($recipient !== '' && !in_array($recipient, $recipients, true)) $recipients[] = $recipient;
    $all[$shop] = [
        'recipients' => $recipients,
        'recipient' => $recipient !== '' ? $recipient : ($recipients[0] ?? ''),
        'exclude' => $clean($s['exclude'] ?? []),
        'threshold' => is_numeric($s['threshold'] ?? null) ? (float) $s['threshold'] : 1000,
        'signature' => trim((string) ($s['signature'] ?? '')),
        'bigIntro' => trim((string) ($s['bigIntro'] ?? '')),
        'oldIntro' => trim((string) ($s['oldIntro'] ?? '')),
    ];
    file_put_contents(GIYUL_SETTINGS, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** A CO folder's date "dd-mm-yy CO" → Y-m-d, or null. */
function giyulFolderDay(string $dir): ?string
{
    return preg_match('/^(\d{2})-(\d{2})-(\d{2}) CO$/', basename($dir), $m) ? "20{$m[3]}-{$m[2]}-{$m[1]}" : null;
}

/**
 * The shop's exports, newest first: [['path', 'folder', 'day' => Y-m-d (the folder's), 'file', 'mtime', 'processed' => [names]]].
 */
function giyulExports(string $shop, int $limit = 20): array
{
    $out = [];
    foreach (glob(GIYUL_ARCHIVE . '/' . basename($shop) . '/Collection/*/*/* CO', GLOB_ONLYDIR) ?: [] as $dir) {
        $day = giyulFolderDay($dir);
        if (!$day) continue;
        $files = array_filter(glob($dir . '/Export*.xls') ?: [], 'is_file');
        if (!$files) continue;
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $processed = array_map('basename', array_merge(glob($dir . '/*מעובד*.xlsx') ?: []));
        $out[] = ['path' => $files[0], 'folder' => $dir, 'day' => $day, 'file' => basename($files[0]),
            'mtime' => filemtime($files[0]), 'processed' => $processed];
    }
    usort($out, fn($a, $b) => strcmp($b['day'], $a['day']) ?: $b['mtime'] <=> $a['mtime']);
    return array_slice($out, 0, $limit);
}

/** An export path, only inside the shop's Collection folder. */
function giyulCheckExport(string $shop, string $path): string
{
    $real = realpath($path);
    $root = realpath(GIYUL_ARCHIVE . '/' . basename($shop) . '/Collection');
    if (!$real || !$root || !str_starts_with($real, $root) || !preg_match('/^Export.*\.xls$/i', basename($real))) {
        throw new RuntimeException('קובץ Export לא תקין');
    }
    return $real;
}

function giyulNum($v): ?float
{
    $v = trim(str_replace([',', '₪', "\xC2\xA0"], '', (string) $v));
    return $v === '' || !is_numeric($v) ? null : (float) $v;
}

/**
 * Read Export.xls (Comax's HTML in windows-1255): ['header' => [...], 'rows' => [[num, name, balance, m1..m4, older]], 'total' => row|null].
 * The table is found by its header ("לקוח" … "יתרה"), not by fixed rows/columns.
 */
function giyulRead(string $path): array
{
    $html = (string) file_get_contents($path);
    if (!mb_check_encoding($html, 'UTF-8')) $html = iconv('windows-1255', 'UTF-8//IGNORE', $html);   // mbstring has no 1255
    $html = preg_replace('/charset=[\w-]+/i', 'charset=utf-8', $html, 1);
    $tmp = tempnam(sys_get_temp_dir(), 'giyul') . '.html';
    file_put_contents($tmp, $html);
    libxml_use_internal_errors(true);
    try {
        $rows = IOFactory::createReader('Html')->load($tmp)->getActiveSheet()->toArray(null, false, false, false);
    } finally {
        libxml_clear_errors();
        @unlink($tmp);
    }
    $h = null;
    foreach ($rows as $i => $r) {
        if (trim((string) ($r[0] ?? '')) === 'לקוח' && in_array('יתרה', array_map(fn($x) => trim((string) $x), $r), true)) { $h = $i; break; }
    }
    if ($h === null) throw new RuntimeException('לא נמצאה בקובץ שורת הכותרות ("לקוח" … "יתרה")');
    $head = array_map(fn($x) => trim((string) $x), $rows[$h]);
    $bal = array_search('יתרה', $head, true);
    $last = $bal;
    for ($c = $bal + 1; $c < count($head); $c++) if ($head[$c] !== '') $last = $c;
    $cols = array_merge([0, 1], range($bal, $last));   // לקוח, שם, יתרה, the months
    if (count($cols) !== 8) throw new RuntimeException('מספר עמודות החודשים בקובץ שונה מהצפוי (' . (count($cols) - 3) . ' במקום 5)');
    $pick = fn($r) => array_map(fn($c) => $r[$c] ?? null, $cols);
    $out = ['header' => $pick($head), 'rows' => [], 'total' => null];
    foreach (array_slice($rows, $h + 1) as $r) {
        $a = trim((string) ($r[0] ?? ''));
        if ($a === '' && trim((string) ($r[1] ?? '')) === '') continue;
        $row = $pick($r);
        $row[0] = $a;
        $row[1] = trim((string) $row[1]);
        for ($i = 2; $i < 8; $i++) $row[$i] = giyulNum($row[$i]);
        if (str_contains($a, 'סה')) { $row[1] = ''; $out['total'] = $row; continue; }
        $out['rows'][] = $row;
    }
    return $out;
}

/** The 4 latest months empty (columns D–G). */
function giyulIsOld(array $row): bool
{
    for ($i = 3; $i <= 6; $i++) if ($row[$i] !== null && $row[$i] != 0) return false;
    return true;
}

/** Each row's mark: 'old' (purple) / 'big' (yellow) / ''. */
function giyulMarks(array $data, float $threshold): array
{
    return array_map(fn($r) => giyulIsOld($r) ? 'old' : (($r[2] ?? 0) > $threshold ? 'big' : ''), $data['rows']);
}

/** The month before / 4 months before the run day: ['XXXX', 'XXXX_YEAR', 'YYYY', 'YYYY_YEAR']. */
function giyulMonths(string $day): array
{
    $t = new DateTime($day);
    $prev = (clone $t)->modify('first day of -1 month');
    $old = (clone $t)->modify('first day of -4 month');
    return ['XXXX' => GIYUL_MONTHS_HE[(int) $prev->format('n') - 1], 'XXXX_YEAR' => $prev->format('Y'),
            'YYYY' => GIYUL_MONTHS_HE[(int) $old->format('n') - 1], 'YYYY_YEAR' => $old->format('Y')];
}

/** The two messages: ['big' => text, 'old' => text, 'bigCount', 'oldCount']. */
function giyulMessages(array $data, array $set, string $day): array
{
    $ex = array_map('trim', $set['exclude']);
    $rows = array_filter($data['rows'], fn($r) => !in_array($r[1], $ex, true));
    usort($rows, fn($a, $b) => (int) $a[0] <=> (int) $b[0] ?: strcmp($a[0], $b[0]));   // by customer number
    $line = fn($r) => "{$r[1]} ({$r[0]}) – " . number_format(round($r[2])) . ' ₪';
    $big = array_map($line, array_filter($rows, fn($r) => ($r[2] ?? 0) > $set['threshold']));
    $old = array_map($line, array_filter($rows, fn($r) => ($r[2] ?? 0) > 0 && giyulIsOld($r)));
    $vars = giyulMonths($day);
    $fill = fn($t) => strtr($t, array_combine(array_map(fn($k) => '{' . $k . '}', array_keys($vars)), $vars));
    $msg = fn($intro, $lines) => "{$set['recipient']} שלום,\n" . $fill($intro) . "\n" . ($lines ? implode("\n", $lines) : 'אין') . "\n\nבברכה,\n{$set['signature']}";
    return ['big' => $msg($set['bigIntro'], $big), 'old' => $msg($set['oldIntro'], $old), 'bigCount' => count($big), 'oldCount' => count($old)];
}

/** $name in $dir, or "<stem> (2).ext"… when taken. */
function giyulFreeName(string $dir, string $name): string
{
    if (!file_exists("$dir/$name")) return $name;
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $stem = pathinfo($name, PATHINFO_FILENAME);
    for ($i = 2; file_exists("$dir/$stem ($i).$ext"); $i++);
    return "$stem ($i).$ext";
}

/** The output base for a run day: "גיול חובות - dd-mm-yy". */
function giyulBase(string $day): string
{
    return 'גיול חובות - ' . date('d-m-y', strtotime($day));
}

/** Write the processed xlsx; returns its name. */
function giyulWriteXlsx(array $data, array $marks, string $dir, string $day): string
{
    $sp = new Spreadsheet();
    $sh = $sp->getActiveSheet();
    $sh->setTitle('גיול חובות');
    $sh->setRightToLeft(true);
    $sh->fromArray($data['header'], null, 'A1');
    $r = 2;
    foreach ($data['rows'] as $i => $row) {
        $sh->fromArray($row, null, "A$r");
        $sh->getCell("A$r")->setValueExplicit($row[0], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        if ($marks[$i] !== '') {
            $sh->getStyle("C$r")->getFill()->setFillType('solid')->getStartColor()->setRGB($marks[$i] === 'old' ? GIYUL_PURPLE : GIYUL_YELLOW);
        }
        $r++;
    }
    if ($data['total']) { $sh->fromArray($data['total'], null, "A$r"); $sh->getStyle("A$r:H$r")->getFont()->setBold(true); }
    $sh->getRowDimension(1)->setRowHeight(25);
    $sh->getStyle('A1:H1')->getFont()->setBold(true);
    $sh->getStyle('A1:H1')->getAlignment()->setVertical('center');
    $sh->getStyle("C2:H$r")->getNumberFormat()->setFormatCode('#,##0');
    foreach (['A' => 8, 'B' => 28, 'C' => 11, 'D' => 10, 'E' => 10, 'F' => 10, 'G' => 10, 'H' => 12] as $c => $w) $sh->getColumnDimension($c)->setWidth($w);
    $name = giyulFreeName($dir, giyulBase($day) . '-מעובד.xlsx');
    IOFactory::createWriter($sp, 'Xlsx')->save("$dir/$name");
    return $name;
}

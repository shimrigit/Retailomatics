<?php
/**
 * price_list_source.php — the commercial layer's price lists (commercialLayer/price_list_files),
 * one or more per shop, and making one from the shop's items file.
 *
 * Updating a shop's prices is two steps, both by the user:
 *   1. the Comax Gateway's "ייצוא פריטים מורחב" downloads the items file to
 *      {drive}:\RetailomaticsCloud\RetailomaticsArchive\{shop}\ProductsList\רשימת מוצרים ומחירים ddmmyy hhmmss.xlsx;
 *   2. "עדכן רשימת מחירים מקובץ פריטים" (InvoiceScan start screen → update_price_list.php →
 *      clMakePriceList) turns the newest items file into price_list_files\{shop} ddmmyy_hhmmss.xlsx
 *      — the time is the items file's download time — in the manual files' format:
 *      פריט | שם פריט | ברקוד | מחלקה | שם מחלקה | מחיר מחירון 1 | מחירון קניה אחרונה.
 *
 * The commercial layer takes the shop's newest price list (clFindPriceList): a made one by the time
 * in its name, a manual one ({shop}*.xlsx, e.g. "BernardYahud 26-04-26.xlsx") by its file time.
 * Its columns are found by the header row (clPriceListColumns), with the shop's PriceListColumns
 * as the fallback for a file without the headers.
 */

const CL_PRICE_LIST_DIR = __DIR__ . '/price_list_files';
const CL_ITEMS_FILE_PREFIX = 'רשימת מוצרים ומחירים';

/** The header of each field, as Comax writes it. */
const CL_PRICE_LIST_HEADERS = [
    'ItemERPName' => ['שם פריט'],
    'Barcode'     => ['ברקוד'],
    'Department'  => ['מחלקה'],
    'SalesPrice'  => ['מחיר מחירון 1'],
    'CostPrice'   => ['מחירון קניה אחרונה'],
];
/** The columns of a made price list, in order (the manual files' format). */
const CL_PRICE_LIST_OUT = ['פריט', 'שם פריט', 'ברקוד', 'מחלקה', 'שם מחלקה', 'מחיר מחירון 1', 'מחירון קניה אחרונה'];

/** "211126 165906" → "2026-11-21 16:59:06". */
function clStampToTime(string $ddmmyy, string $hhmmss): string
{
    return '20' . substr($ddmmyy, 4, 2) . '-' . substr($ddmmyy, 2, 2) . '-' . substr($ddmmyy, 0, 2)
        . ' ' . substr($hhmmss, 0, 2) . ':' . substr($hhmmss, 2, 2) . ':' . substr($hhmmss, 4, 2);
}

/** The shop's newest price list: ['path', 'name', 'made' => bool, 'when' => 'Y-m-d H:i:s'], or null. */
function clFindPriceList(string $shop): ?array
{
    $shop = basename($shop);
    $best = null;
    foreach (glob(CL_PRICE_LIST_DIR . '/*.xlsx') ?: [] as $f) {
        $name = basename($f);
        if (stripos($name, $shop) !== 0 || str_starts_with($name, '~$')) continue;
        $made = (bool) preg_match('/^' . preg_quote($shop, '/') . ' (\d{6})_(\d{6})\.xlsx$/iu', $name, $m);
        $when = $made ? clStampToTime($m[1], $m[2]) : date('Y-m-d H:i:s', filemtime($f));
        if (!$best || strcmp($when, $best['when']) > 0) $best = ['path' => $f, 'name' => $name, 'made' => $made, 'when' => $when];
    }
    return $best;
}

/** The shop's newest items file from the Gateway: ['path', 'name', 'stamp' => 'ddmmyy_hhmmss', 'when'], or null. */
function clLatestItemsFile(string $shop, string $drive = 'Z'): ?array
{
    $best = null;
    foreach (glob(strtoupper($drive) . ':/RetailomaticsCloud/RetailomaticsArchive/' . basename($shop) . '/ProductsList/*.xlsx') ?: [] as $f) {
        if (!preg_match('/^' . preg_quote(CL_ITEMS_FILE_PREFIX, '/') . ' (\d{6}) (\d{6})\.xlsx$/u', basename($f), $m)) continue;
        $when = clStampToTime($m[1], $m[2]);
        if (!$best || strcmp($when, $best['when']) > 0) $best = ['path' => $f, 'name' => basename($f), 'stamp' => "{$m[1]}_{$m[2]}", 'when' => $when];
    }
    return $best;
}

/**
 * Makes price_list_files\{shop} ddmmyy_hhmmss.xlsx from the shop's newest items file.
 * Returns ['name', 'when', 'items', 'existed' => true when that items file was already made].
 * Throws when there is no items file, or it lacks a needed column.
 */
function clMakePriceList(string $shop, string $drive = 'Z'): array
{
    $src = clLatestItemsFile($shop, $drive);
    if (!$src) throw new RuntimeException('אין קובץ פריטים מורחב של ' . $shop . ' — הורד אותו מה-Gateway של קומקס ("ייצוא פריטים מורחב")');
    $name = basename($shop) . " {$src['stamp']}.xlsx";
    $out = CL_PRICE_LIST_DIR . '/' . $name;
    if (is_file($out)) {
        return ['name' => $name, 'when' => $src['when'], 'items' => null, 'existed' => true];
    }

    $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($src['path'])->getActiveSheet()->toArray(null, false, false, false);
    $head = array_map(fn($h) => trim(preg_replace('/\s+/u', ' ', (string) $h)), array_shift($rows) ?? []);
    $col = [];
    foreach (CL_PRICE_LIST_OUT as $h) {
        $i = array_search($h, $head, true);
        if ($i === false) throw new RuntimeException("בקובץ הפריטים {$src['name']} אין עמודה \"$h\"");
        $col[$h] = $i;
    }

    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sh = $ss->getActiveSheet();
    $sh->setRightToLeft(true);
    $sh->fromArray(CL_PRICE_LIST_OUT, null, 'A1');
    $r = 2;
    foreach ($rows as $row) {
        $barcode = trim((string) ($row[$col['ברקוד']] ?? ''));
        if ($barcode === '') continue;
        foreach (CL_PRICE_LIST_OUT as $c => $h) {
            $v = $row[$col[$h]] ?? null;
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1) . $r;
            if (in_array($h, ['פריט', 'ברקוד'], true)) $sh->setCellValueExplicit($cell, trim((string) $v), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);   // codes stay text
            elseif ($v !== null && $v !== '') $sh->setCellValue($cell, $v);
        }
        $r++;
    }
    $tmp = $out . '.tmp.xlsx';
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($tmp);
    rename($tmp, $out);
    return ['name' => $name, 'when' => $src['when'], 'items' => $r - 2, 'existed' => false];
}

/**
 * The column (1-based) of each field in a price list sheet: by its header row; a sheet with none of
 * the headers uses $configured (the shop's PriceListColumns). Throws when only some are found.
 */
function clPriceListColumns($sheet, array $configured): array
{
    $headers = [];
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
    for ($c = 1; $c <= $lastCol; $c++) {
        $h = trim(preg_replace('/\s+/u', ' ', (string) $sheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . '1')->getValue()));
        if ($h !== '' && !isset($headers[$h])) $headers[$h] = $c;
    }
    $cols = []; $missing = [];
    foreach (CL_PRICE_LIST_HEADERS as $field => $names) {
        $found = null;
        foreach ($names as $n) if (isset($headers[$n])) { $found = $headers[$n]; break; }
        if ($found) $cols[$field] = $found; else $missing[] = $names[0];
    }
    if (!$cols) return $configured;
    if ($missing) {
        throw new RuntimeException('בקובץ המחירון חסרות העמודות: ' . implode(', ', $missing) . ' (נמצאו: ' . implode(', ', array_keys($headers)) . ')');
    }
    return $cols;
}

/** One line for the screen: which price list, from when. */
function clPriceListLabel(array $pl): string
{
    $when = date('d/m/Y H:i', strtotime($pl['when']));
    return $pl['made'] ? "מחירון: {$pl['name']} — לפי קובץ הפריטים מקומקס מ-$when" : "מחירון: {$pl['name']} (קובץ ידני, עודכן $when)";
}

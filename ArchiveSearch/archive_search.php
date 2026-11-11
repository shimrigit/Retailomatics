<?php
// archive_search.php — finds a barcode in one archived Excel file (ArchiveSearch/index.php).
// A file is read by what it really is, not by its extension:
//   zip  (.xlsx / .xlsm)  the sheets' XML, read straight from the zip
//   OLE  (a real .xls)    PhpSpreadsheet
//   else (.xls that is an HTML table — Comax "Export.xls", Windows-1255 or UTF-16)  the table's rows
// A cell matches when it IS the barcode, leading zeros ignored (the old
// script's whole-cell Find). Each hit: {sheet, cell, row: [col => value], header: [col => value]}.

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// A cell's text as a barcode key: digits without leading zeros ("8.411037885331E+12" → "8411037885331").
function cellKey(string $v): string
{
    $v = trim($v);
    if (preg_match('/^\d+(\.0+)?$/', $v)) return ltrim(preg_replace('/\.0+$/', '', $v), '0');
    if (is_numeric($v) && stripos($v, 'e') !== false && (float) $v == floor((float) $v)) return ltrim(sprintf('%.0f', (float) $v), '0');
    return '';
}

function xmlText(string $s): string
{
    return trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * Every number in the file that could be a barcode (4+ digits, leading zeros
 * dropped), as " k1 k2 … " — for the index (index.php), so a search opens only
 * the files that have it. Read straight from the raw XML / HTML, so it may
 * hold a few numbers that aren't cells (that only costs a full search that
 * finds nothing). null for a real .xls (binary — always searched in full).
 */
function fileKeys(string $file): ?string
{
    $magic = (string) file_get_contents($file, false, null, 0, 4);
    $texts = [];
    if (str_starts_with($magic, 'PK')) {
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::RDONLY) !== true) throw new RuntimeException('לא נפתח (zip)');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === 'xl/sharedStrings.xml' || str_starts_with($name, 'xl/worksheets/')) $texts[] = $zip->getFromIndex($i);
        }
        $zip->close();
    } elseif ($magic === "\xD0\xCF\x11\xE0") {
        return null;
    } else {
        $texts[] = htmlUtf8((string) file_get_contents($file));
    }
    $keys = [];
    foreach ($texts as $t) {
        preg_match_all('#>(?:\s|&nbsp;)*0*(\d{4,})(?:\.0+)?(?:\s|&nbsp;)*<#', $t, $m);
        foreach ($m[1] as $k) $keys[$k] = true;
        preg_match_all('#>\s*(\d(?:\.\d+)?E\+\d+)\s*<#i', $t, $m);   // 8.411037885331E+12
        foreach ($m[1] as $v) if (($k = cellKey($v)) !== '') $keys[$k] = true;
    }
    return ' ' . implode(' ', array_keys($keys)) . ' ';
}

function searchFile(string $file, string $key): array
{
    $magic = (string) file_get_contents($file, false, null, 0, 4);
    if (str_starts_with($magic, "PK")) return searchXlsx($file, $key);
    if ($magic === "\xD0\xCF\x11\xE0") return searchXls($file, $key);
    return searchHtml($file, $key);
}

function searchXlsx(string $file, string $key): array
{
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::RDONLY) !== true) throw new RuntimeException('לא נפתח (zip)');
    // Shared strings stay raw XML; only those that could be the barcode are checked.
    $strings = [];
    $matchIdx = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        preg_match_all('#<si>(.*?)</si>#s', $ss, $m);
        $strings = $m[1];
        foreach ($strings as $i => $s) {
            if (strpos($s, $key) !== false && cellKey(xmlText($s)) === $key) $matchIdx[$i] = true;
        }
    }
    // Sheet names → their XML files.
    $wb = (string) $zip->getFromName('xl/workbook.xml');
    $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
    $target = [];
    preg_match_all('#<Relationship\b[^>]*>#', $rels, $rm);
    foreach ($rm[0] as $r) {
        if (preg_match('#\bId="([^"]+)"#', $r, $id) && preg_match('#\bTarget="([^"]+)"#', $r, $t)) $target[$id[1]] = $t[1];
    }
    preg_match_all('#<sheet\b[^>]*?name="([^"]*)"[^>]*?r:id="([^"]+)"#', $wb, $sm, PREG_SET_ORDER);

    // Quick skip: a sheet without the number itself or a matching shared string's index can't have it.
    $numRe = '#>0*' . preg_quote($key, '#') . '(\.0+)?<#';
    $found = [];
    foreach ($sm as [, $sheetName, $rid]) {
        $xml = $zip->getFromName(ltrim(preg_replace('#^/?(xl/)?#', 'xl/', $target[$rid] ?? ''), '/'));
        if ($xml === false) continue;
        $maybe = preg_match($numRe, $xml);
        foreach (array_keys($matchIdx) as $i) $maybe = $maybe || strpos($xml, "<v>$i</v>") !== false;
        if (!$maybe) continue;
        $header = null;
        preg_match_all('#<row\b[^>]*>(.*?)</row>#s', $xml, $rows);
        foreach ($rows[1] as $rowXml) {
            preg_match_all('#<c r="([A-Z]+)(\d+)"([^>]*?)(?:/>|>(.*?)</c>)#s', $rowXml, $cells, PREG_SET_ORDER);
            $values = [];
            $hit = null;
            foreach ($cells as $c) {
                [$col, $rowNo, $attrs, $inner] = [$c[1], $c[2], $c[3], $c[4] ?? ''];
                $type = preg_match('#t="(\w+)"#', $attrs, $t) ? $t[1] : 'n';
                if ($type === 'inlineStr') {
                    $v = preg_match('#<is>(.*?)</is>#s', $inner, $x) ? xmlText($x[1]) : '';
                } else {
                    $raw = preg_match('#<v>(.*?)</v>#s', $inner, $x) ? $x[1] : '';
                    $v = $type === 's' ? xmlText($strings[(int) $raw] ?? '') : xmlText($raw);
                    if ($type === 's' && isset($matchIdx[(int) $raw])) $hit = $col . $rowNo;
                }
                if ($type !== 's' && $hit === null && cellKey($v) === $key) $hit = $col . $rowNo;
                $values[$col] = $v;
            }
            $header ??= $values;   // the sheet's first row
            if ($hit) $found[] = ['sheet' => $sheetName, 'cell' => $hit, 'row' => $values, 'header' => $header];
        }
    }
    $zip->close();
    return $found;
}

function searchXls(string $file, string $key): array
{
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xls');
    $reader->setReadDataOnly(true);
    $found = [];
    foreach ($reader->load($file)->getWorksheetIterator() as $sheet) {
        $header = null;
        foreach ($sheet->toArray(null, false, false, true) as $rowNo => $values) {
            $values = array_map(fn($v) => (string) $v, $values);
            $header ??= $values;
            foreach ($values as $col => $v) {
                if (cellKey($v) === $key) {
                    $found[] = ['sheet' => $sheet->getTitle(), 'cell' => $col . $rowNo, 'row' => $values, 'header' => $header];
                    break;
                }
            }
        }
    }
    return $found;
}

// An HTML export as UTF-8: UTF-16 (with its BOM), Windows-1255 (Comax), or already UTF-8.
function htmlUtf8(string $html): string
{
    if (str_starts_with($html, "\xFF\xFE")) return mb_convert_encoding(substr($html, 2), 'UTF-8', 'UTF-16LE');
    if (!mb_check_encoding($html, 'UTF-8')) return (string) iconv('CP1255', 'UTF-8//IGNORE', $html);
    return $html;
}

function searchHtml(string $file, string $key): array
{
    $html = htmlUtf8((string) file_get_contents($file));
    if (strpos($html, $key) === false) return [];
    $found = [];
    $header = null;
    preg_match_all('#<tr\b[^>]*>(.*?)</tr>#is', $html, $rows);
    foreach ($rows[1] as $n => $rowHtml) {
        preg_match_all('#<t[dh]\b[^>]*>(.*?)</t[dh]>#is', $rowHtml, $cells);
        $values = [];
        $hit = null;
        foreach ($cells[1] as $i => $cellHtml) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $values[$col] = $v = xmlText(str_replace('&nbsp;', ' ', $cellHtml));
            if ($hit === null && cellKey($v) === $key) $hit = $col . ($n + 1);
        }
        // The header: the first row with several filled cells (exports start with title rows).
        if ($header === null && count(array_filter($values, fn($v) => $v !== '')) >= 3) $header = $values;
        if ($hit) $found[] = ['sheet' => '(HTML)', 'cell' => $hit, 'row' => $values, 'header' => $header ?? []];
    }
    return $found;
}

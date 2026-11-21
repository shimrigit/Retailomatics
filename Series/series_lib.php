<?php
/**
 * series_lib.php — splits a shop's price list (the Comax items export) into
 * series: products a shop prices alike. A series is
 *   - the same brand (the name's "-<brand>" ending — YBC's names carry it; else
 *     the brand that CPD's manufacturer for the barcode maps to, learned from
 *     the items that have both),
 *   - the same product line (the name's first word: קליק, פינוק, פסטה …; for an
 *     item with no brand the first two words, to stay strict),
 *   - the same unit and pack count, and a size within ±5% of the series' first
 *     (chains write 500 / 495 גרם for the same pack).
 * Items without a size, or alone in their group, aren't in a series.
 * The series price is the most common price; a series with more than one
 * price is a conflict for the user to resolve (split A–J / fix a price).
 */

require_once __DIR__ . '/../vendor/autoload.php';

const SERIES_SIZE_TOLERANCE = 0.05;
if (!defined('SERIES_GENERIC_BRANDS')) define('SERIES_GENERIC_BRANDS', 0);   // >0: a first word used under that many brands makes the line two words (tested on YBC: breaks פסטה אסם / ברילה, so off)

/** Items of the Comax export: [{code, barcode, name, price}] (price = "מחיר מחירון 1"). */
function seriesReadComax(string $xlsxPath): array
{
    $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($xlsxPath)->getActiveSheet();
    $rows  = $sheet->toArray(null, false, false, false);
    $head  = array_map(fn($h) => trim((string) $h), array_shift($rows) ?? []);
    $col   = fn($name) => array_search($name, $head, true);
    $iCode = $col('פריט'); $iName = $col('שם פריט'); $iBc = $col('ברקוד'); $iPrice = $col('מחיר מחירון 1');
    if ($iName === false || $iBc === false || $iPrice === false) {
        throw new RuntimeException('הקובץ לא נראה כמו ייצוא פריטים מקומקס (חסרות עמודות שם פריט / ברקוד / מחיר מחירון 1)');
    }
    $items = [];
    foreach ($rows as $r) {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($r[$iName] ?? '')));
        $bc   = trim((string) ($r[$iBc] ?? ''));
        if ($name === '' || $bc === '') continue;
        $items[] = ['code' => trim((string) ($r[$iCode] ?? '')), 'barcode' => $bc, 'name' => $name, 'price' => round((float) ($r[$iPrice] ?? 0), 2)];
    }
    return $items;
}

/** The brand written at the end of the name after a dash ("קליק טבלה 85 גרם -תלמה" → "תלמה"), or ''. */
function seriesBrandFromName(string $name): string
{
    if (preg_match('/-\s*([^\-\d][^\-]*?)\s*$/u', $name, $m) && mb_strlen(trim($m[1])) >= 2) return seriesNorm($m[1]);
    return '';
}

/** Lower-noise text for comparing: no quotes/punctuation, single spaces. */
function seriesNorm(string $s): string
{
    $s = preg_replace('/["\'״׳`.,()]/u', '', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/**
 * The size in the name: ['qty' => base quantity (גרם / מ"ל / יחידות), 'unit' => g|ml|u, 'pack' => count, 'text' => as written] or null.
 */
function seriesParseSize(string $name): ?array
{
    $units = [
        'kg' => ['re' => 'ק"?ג|קג|קילו(?:גרם)?', 'unit' => 'g',  'mul' => 1000],
        'g'  => ['re' => 'גרם|גר\'?|ג\'|ג(?![א-ת])', 'unit' => 'g', 'mul' => 1],
        'ml' => ['re' => 'מ"?ל|מל(?![א-ת])', 'unit' => 'ml', 'mul' => 1],
        'l'  => ['re' => 'ליטר|ל\'|ל(?![א-ת])', 'unit' => 'ml', 'mul' => 1000],
        'u'  => ['re' => 'יחידות|יח\'?(?![א-ת])', 'unit' => 'u', 'mul' => 1],
    ];
    $n = str_replace(["''", '״', '׳'], ['"', '"', "'"], $name);   // מ''ל → מ"ל
    foreach ($units as $u) {
        if (!preg_match('/(?:(\d+)\s*[*xX×]\s*)?(\d+(?:\.\d+)?)\s*(?:' . $u['re'] . ')(?:\s*[*xX×]\s*(\d+))?/u', $n, $m)) continue;
        $pack = (int) (($m[1] ?? '') !== '' ? $m[1] : (($m[3] ?? '') !== '' ? $m[3] : 1));
        if ($pack === 1) $pack = seriesPackWord($n);
        return ['qty' => (float) $m[2] * $u['mul'], 'unit' => $u['unit'], 'pack' => max(1, $pack), 'text' => trim($m[0])];
    }
    return null;
}

/** Pack count from words (זוג, שלישייה, רביעייה, שישייה, מארז N). */
function seriesPackWord(string $name): int
{
    if (preg_match('/מארז\s*(\d+)/u', $name, $m)) return (int) $m[1];
    foreach (['זוג' => 2, 'שלישי' => 3, 'רביעי' => 4, 'שישי' => 6, 'שמיני' => 8] as $w => $k) {
        if (preg_match('/(?<![א-ת])' . $w . '(?:יה|ייה|ית|יית)(?![א-ת])/u', $name)) return $k;
    }
    return 1;
}

/** The product line: the first word (two words when the brand is unknown), without the brand and size. */
function seriesLine(string $name, string $brand, array $generic = []): string
{
    $base = preg_replace('/-\s*[^\-\d][^\-]*$/u', '', $name);      // drop "-brand"
    $base = preg_replace('/\d.*$/u', '', $base);                     // drop from the first digit (size, numbers)
    $words = array_values(array_filter(explode(' ', seriesNorm($base)), fn($w) => mb_strlen($w) >= 2));
    if (!$words) return '';
    // a generic first word (חטיף, בקבוק, מארז, גבינת — used under many brands): the line is the first two words
    $two = $brand === '' || (isset($generic[$words[0]]) && count($words) > 1);
    return $two ? implode(' ', array_slice($words, 0, 2)) : $words[0];
}

/**
 * Builds the series. $manufacturerOf: callable(barcode) => CPD manufacturer or '' (optional).
 * Returns ['series' => [{id, brand, line, unit, pack, sizeText, items: [...], prices: [price => count], price, conflict}],
 *          'loose' => items in no series (with 'why'), 'stats' => [...]].
 */
function seriesBuild(array $items, ?callable $manufacturerOf = null): array
{
    // 1. brand from the name; learn CPD manufacturer → name brand from the items that have both
    $votes = [];
    foreach ($items as &$it) {
        $it['brand'] = seriesBrandFromName($it['name']);
        $it['brandFrom'] = $it['brand'] !== '' ? 'name' : '';
        $it['manufacturer'] = $manufacturerOf ? seriesNorm((string) $manufacturerOf($it['barcode'])) : '';
        if ($it['brand'] !== '' && $it['manufacturer'] !== '') $votes[$it['manufacturer']][$it['brand']] = ($votes[$it['manufacturer']][$it['brand']] ?? 0) + 1;
    }
    unset($it);
    $manToBrand = [];
    foreach ($votes as $man => $brands) {
        arsort($brands);
        $top = array_key_first($brands);
        if ($brands[$top] >= 2 && $brands[$top] / array_sum($brands) >= 0.6) $manToBrand[$man] = $top;   // only a clear mapping
    }

    // generic first words: the first word of items of at least SERIES_GENERIC_BRANDS different brands
    $firstBrands = [];
    foreach ($items as &$it) {
        if ($it['brand'] === '' && isset($manToBrand[$it['manufacturer']])) { $it['brand'] = $manToBrand[$it['manufacturer']]; $it['brandFrom'] = 'cpd'; }
        $w = explode(' ', seriesNorm(preg_replace('/\d.*$/u', '', $it['name'])))[0] ?? '';
        if ($it['brand'] !== '' && $w !== '') $firstBrands[$w][$it['brand']] = true;
    }
    unset($it);
    $generic = SERIES_GENERIC_BRANDS > 0 ? array_filter($firstBrands, fn($b) => count($b) >= SERIES_GENERIC_BRANDS) : [];

    // 2. group by brand + line + unit + pack
    $groups = []; $loose = [];
    foreach ($items as $it) {
        $it['size'] = seriesParseSize($it['name']);
        $it['line'] = seriesLine($it['name'], $it['brand'], $generic);
        if (!$it['size'])        { $it['why'] = 'אין גודל בשם'; $loose[] = $it; continue; }
        if ($it['line'] === '')  { $it['why'] = 'אין שם מוצר'; $loose[] = $it; continue; }
        if ($it['price'] <= 0)   { $it['why'] = 'אין מחיר'; $loose[] = $it; continue; }
        $groups[implode('|', [$it['brand'], $it['line'], $it['size']['unit'], $it['size']['pack']])][] = $it;
    }

    // 3. within a group: sizes within ±5% of the cluster's first (sorted)
    $series = [];
    foreach ($groups as $g) {
        usort($g, fn($a, $b) => $a['size']['qty'] <=> $b['size']['qty']);
        $clusters = []; $cur = []; $first = null;
        foreach ($g as $it) {
            if ($cur && $it['size']['qty'] > $first * (1 + SERIES_SIZE_TOLERANCE)) { $clusters[] = $cur; $cur = []; }
            if (!$cur) $first = $it['size']['qty'];
            $cur[] = $it;
        }
        if ($cur) $clusters[] = $cur;
        foreach ($clusters as $c) {
            if (count($c) < 2) { $c[0]['why'] = 'אין מוצר דומה'; $loose[] = $c[0]; continue; }
            $prices = [];
            foreach ($c as $it) { $k = number_format($it['price'], 2, '.', ''); $prices[$k] = ($prices[$k] ?? 0) + 1; }
            arsort($prices);
            usort($c, fn($a, $b) => [$a['price'], $a['name']] <=> [$b['price'], $b['name']]);
            $series[] = [
                'brand' => $c[0]['brand'], 'line' => $c[0]['line'], 'unit' => $c[0]['size']['unit'], 'pack' => $c[0]['size']['pack'],
                'sizeText' => seriesSizeLabel($c), 'items' => $c, 'prices' => $prices,
                'price' => (float) array_key_first($prices), 'conflict' => count($prices) > 1,
            ];
        }
    }
    usort($series, fn($a, $b) => [$a['brand'] === '' ? 1 : 0, $a['brand'], $a['line'], $a['unit'], $a['pack'], $a['items'][0]['size']['qty']]
                               <=> [$b['brand'] === '' ? 1 : 0, $b['brand'], $b['line'], $b['unit'], $b['pack'], $b['items'][0]['size']['qty']]);
    foreach ($series as $i => &$s) $s['id'] = $i + 1;
    unset($s);

    $inSeries = array_sum(array_map(fn($s) => count($s['items']), $series));
    return ['series' => $series, 'loose' => $loose, 'manToBrand' => $manToBrand, 'generic' => array_keys($generic), 'stats' => [
        'items' => count($items), 'series' => count($series), 'inSeries' => $inSeries,
        'conflicts' => count(array_filter($series, fn($s) => $s['conflict'])),
        'brandFromName' => count(array_filter($items, fn($i) => seriesBrandFromName($i['name']) !== '')),
        'manufacturerMap' => count($manToBrand),
        'genericWords' => count($generic),
    ]];
}

/** "500 גרם", "6 * 1.5 ליטר", "485–500 גרם". */
function seriesSizeLabel(array $items): string
{
    $s = $items[0]['size'];
    $qs = array_map(fn($i) => $i['size']['qty'], $items);
    $fmt = function ($q) use ($s) {
        if ($s['unit'] === 'g')  return $q >= 1000 ? rtrim(rtrim(number_format($q / 1000, 2, '.', ''), '0'), '.') . ' ק"ג' : rtrim(rtrim(number_format($q, 1, '.', ''), '0'), '.') . ' גרם';
        if ($s['unit'] === 'ml') return $q >= 1000 ? rtrim(rtrim(number_format($q / 1000, 2, '.', ''), '0'), '.') . ' ליטר' : rtrim(rtrim(number_format($q, 1, '.', ''), '0'), '.') . ' מ"ל';
        return rtrim(rtrim(number_format($q, 1, '.', ''), '0'), '.') . ' יחידות';
    };
    $txt = min($qs) == max($qs) ? $fmt(min($qs)) : $fmt(min($qs)) . '–' . $fmt(max($qs));
    return $s['pack'] > 1 ? $s['pack'] . ' * ' . $txt : $txt;
}

/**
 * The series to Excel, for an outside check (sheet "סדרות": one row per item, a
 * series' rows together; conflicts in red; sheet "ללא סדרה": the rest and why).
 */
function seriesExportXlsx(array $result, string $path, string $shop = ''): void
{
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sh = $ss->getActiveSheet();
    $sh->setTitle('סדרות');
    $sh->setRightToLeft(true);
    $sh->fromArray(['סדרה', 'יצרן', 'שם סדרה', 'גודל', 'מחיר סדרה', 'מחירים בסדרה', 'ברקוד', 'שם פריט', 'מחיר', 'שונה ממחיר הסדרה', 'יצרן לפי', 'מחיר מתוקן'], null, 'A1');
    $row = 2; $n = 0;
    foreach ($result['series'] as $s) {
        $first = $row; $n++;
        foreach ($s['items'] as $it) {
            $eff = (float) ($it['effPrice'] ?? $it['price']);   // the user's fixed price (seriesApply), else Comax's
            $sh->fromArray([$s['id'], $s['brand'] ?: '—', $s['line'], $s['sizeText'], $s['price'],
                $s['conflict'] ? implode(' / ', array_map(fn($p, $n) => "$p ×$n", array_keys($s['prices']), $s['prices'])) : '', null,
                $it['name'], $it['price'], abs($eff - $s['price']) > 0.001 ? 'כן' : '',
                ['name' => 'שם הפריט', 'cpd' => 'CPD'][$it['brandFrom']] ?? '', !empty($it['fixed']) ? $eff : null], null, 'A' . $row);
            $sh->setCellValueExplicit('G' . $row, $it['barcode'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            if (abs($eff - $s['price']) > 0.001) $sh->getStyle("H{$row}:J{$row}")->getFont()->getColor()->setRGB('C0392B');
            $row++;
        }
        $fill = $s['conflict'] ? 'FDECEA' : ($n % 2 ? 'F4F8FB' : 'FFFFFF');
        $sh->getStyle("A{$first}:L" . ($row - 1))->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
        $sh->getStyle("A" . ($row - 1) . ":L" . ($row - 1))->getBorders()->getBottom()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    }
    $sh->getStyle('A1:L1')->getFont()->setBold(true);
    $sh->freezePane('A2');
    $sh->setAutoFilter('A1:L' . max(1, $row - 1));
    foreach (range('A', 'L') as $c) $sh->getColumnDimension($c)->setAutoSize(true);

    $lo = $ss->createSheet();
    $lo->setTitle('ללא סדרה');
    $lo->setRightToLeft(true);
    $lo->fromArray(['ברקוד', 'שם פריט', 'מחיר', 'יצרן', 'סיבה'], null, 'A1');
    $row = 2;
    foreach ($result['loose'] as $it) {
        $lo->setCellValueExplicit('A' . $row, $it['barcode'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $lo->fromArray([$it['name'], $it['price'], $it['brand'] ?? '', $it['why'] ?? ''], null, 'B' . $row);
        $row++;
    }
    $lo->getStyle('A1:E1')->getFont()->setBold(true);
    $lo->freezePane('A2');
    $lo->setAutoFilter('A1:E' . max(1, $row - 1));
    foreach (range('A', 'E') as $c) $lo->getColumnDimension($c)->setAutoSize(true);

    $ss->setActiveSheetIndex(0);
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($path);
}

/**
 * Candidates for pricing a new product (the NP final screen) — every option the
 * user may choose from, best first:
 *   - a series of the same brand + line, unit and pack, whose sizes the product's
 *     size is within ±5% of (a product without a size: every such series);
 *   - a single item (in no series) of the same brand + line whose size fits, or
 *     that has no size in its name — it may become a new series with this product.
 * Several candidates happen (sub-series A/B after a split, a size between two
 * series, single items) — the user picks; nothing is chosen automatically.
 * $item: ['barcode', 'name', 'brand' (optional: e.g. CPD's AI brand), 'manufacturer' (optional)].
 * Returns [{type: series|single, series|item, price, score, sizeFit}].
 */
function seriesCandidates(array $item, array $result): array
{
    $brand = seriesBrandFromName($item['name']);
    if ($brand === '') $brand = seriesNorm((string) ($item['brand'] ?? ''));
    if ($brand === '' && !empty($item['manufacturer'])) $brand = $result['manToBrand'][seriesNorm($item['manufacturer'])] ?? '';
    $generic = array_flip($result['generic'] ?? []);
    $line = seriesLine($item['name'], $brand, $generic);
    $size = seriesParseSize($item['name']);
    if ($line === '') return [];

    $fits = function (?array $a, ?array $b) {
        if (!$a || !$b) return null;                       // unknown — still an option
        if ($a['unit'] !== $b['unit'] || $a['pack'] !== $b['pack']) return false;
        return abs($a['qty'] - $b['qty']) <= SERIES_SIZE_TOLERANCE * min($a['qty'], $b['qty']);
    };
    $out = [];
    foreach ($result['series'] as $s) {
        if ($s['brand'] !== $brand || $s['line'] !== $line) continue;
        $fit = null;
        if ($size) {
            $fit = false;
            foreach ($s['items'] as $it) if ($fits($size, $it['size'])) { $fit = true; break; }
            if (!$fit) continue;
        }
        $score = max(array_map(fn($it) => seriesNameSimilarity($item['name'], $it['name']), $s['items']));
        $out[] = ['type' => 'series', 'series' => $s, 'price' => $s['price'], 'score' => $score, 'sizeFit' => $fit];
    }
    foreach ($result['loose'] as $it) {
        if (($it['brand'] ?? '') !== $brand || ($it['line'] ?? '') !== $line || $it['price'] <= 0 || $it['barcode'] === $item['barcode']) continue;
        $fit = $fits($size, $it['size'] ?? null);
        if ($fit === false) continue;
        $out[] = ['type' => 'single', 'item' => $it, 'price' => $it['price'], 'score' => seriesNameSimilarity($item['name'], $it['name']), 'sizeFit' => $fit];
    }
    // Nothing of the same brand + line: the shop may name it otherwise — another brand (the
    // product's brand is a sub-brand: דוריטוס of עלית) or another first word ("חטיף דוריטוס").
    // Then: series / single items whose names contain the product's line word, size fitting.
    // Marked 'loose' — a weaker match, for the user to judge.
    if (!$out) {
        $word = explode(' ', $line)[0];
        $has = fn($name) => mb_strlen($word) >= 3 && preg_match('/(^|\s)' . preg_quote($word, '/') . '(\s|$)/u', seriesNorm($name));
        foreach ($result['series'] as $s) {
            $hit = array_filter($s['items'], fn($it) => $has($it['name']) && $fits($size, $it['size']) !== false);
            if (!$hit) continue;
            $fit = $size ? (bool) array_filter($hit, fn($it) => $fits($size, $it['size']) === true) : null;
            $score = max(array_map(fn($it) => seriesNameSimilarity($item['name'], $it['name']), $hit));
            $out[] = ['type' => 'series', 'series' => $s, 'price' => $s['price'], 'score' => $score, 'sizeFit' => $fit, 'loose' => true];
        }
        foreach ($result['loose'] as $it) {
            if ($it['price'] <= 0 || $it['barcode'] === $item['barcode'] || !$has($it['name'])) continue;
            $fit = $fits($size, $it['size'] ?? null);
            if ($fit === false) continue;
            $out[] = ['type' => 'single', 'item' => $it, 'price' => $it['price'], 'score' => seriesNameSimilarity($item['name'], $it['name']), 'sizeFit' => $fit, 'loose' => true];
        }
    }
    // a series with a known size fit first, then by how alike the names are
    usort($out, fn($a, $b) => [$b['sizeFit'] === true, $b['type'] === 'series', $b['score']] <=> [$a['sizeFit'] === true, $a['type'] === 'series', $a['score']]);
    return $out;
}

/** How alike two product names are (0–1): shared words, without the brand ending and the numbers. */
function seriesNameSimilarity(string $a, string $b): float
{
    $words = function ($s) {
        $s = preg_replace('/-\s*[^\-\d][^\-]*$/u', '', $s);
        $s = preg_replace('/\d+(\.\d+)?/u', ' ', $s);
        return array_unique(array_filter(explode(' ', seriesNorm($s)), fn($w) => mb_strlen($w) >= 2));
    };
    $wa = $words($a); $wb = $words($b);
    if (!$wa || !$wb) return 0.0;
    return count(array_intersect($wa, $wb)) / count(array_unique(array_merge($wa, $wb)));
}

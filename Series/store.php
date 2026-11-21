<?php
/**
 * store.php — a shop's series on disk, in the shop's ProductsList folder:
 *
 *   {drive}:\RetailomaticsCloud\RetailomaticsArchive\{shop}\ProductsList\
 *       רשימת מוצרים ומחירים ddmmyy hhmmss.xlsx   ← the Comax Gateway's latest download (not ours)
 *       Old\                                       ← the Gateway's earlier downloads (not ours)
 *       Series\series.json                         ← the split + the user's decisions
 *       Series\series_<date>.xlsx                  ← exports for an outside check
 *       Series\price_fixes.json                    ← price fixes for the Gateway to prepare in Comax
 *       Series\History\                            ← earlier series.json files
 *
 * Nothing here writes into ProductsList itself (the Gateway moves files there
 * whose name starts with "רשימת מוצרים ומחירים" to Old).
 *
 * The user's decisions are kept per barcode, so a new split (a new download)
 * keeps them: assign '' (the series as found) | 'A'..'J' (a sub-series) |
 * 'none' (not in a series), and fixPrice (the series' price for the item —
 * Comax isn't changed until the Gateway's price fix is approved).
 */

require_once __DIR__ . '/series_lib.php';
date_default_timezone_set('Asia/Jerusalem');   // PHP here runs on Europe/Berlin; the dates on the page are Israel's

const SERIES_LIST_PREFIX = 'רשימת מוצרים ומחירים';
const SERIES_LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];

function seriesShops(): array
{
    $shops = array_column(json_decode((string) @file_get_contents(__DIR__ . '/../configDir/shops_V2.json'), true) ?: [], 'ShopName');
    sort($shops);
    return $shops;
}

function seriesListDir(string $shop, string $drive = 'Z'): string
{
    return strtoupper($drive) . ':/RetailomaticsCloud/RetailomaticsArchive/' . basename($shop) . '/ProductsList';
}

function seriesDir(string $shop, string $drive = 'Z'): string
{
    return seriesListDir($shop, $drive) . '/Series';
}

/** The newest Gateway download: ['path', 'name', 'mtime'] or null. */
function seriesLatestDownload(string $shop, string $drive = 'Z'): ?array
{
    $best = null;
    foreach (glob(seriesListDir($shop, $drive) . '/*.xlsx') ?: [] as $f) {
        $name = basename($f);
        if (strpos($name, SERIES_LIST_PREFIX) !== 0 || strpos($name, '~$') === 0) continue;
        $m = filemtime($f);
        if (!$best || $m > $best['mtime']) $best = ['path' => $f, 'name' => $name, 'mtime' => $m];
    }
    return $best;
}

function seriesLoad(string $shop, string $drive = 'Z'): ?array
{
    $f = seriesDir($shop, $drive) . '/series.json';
    if (!is_file($f)) return null;
    $data = json_decode((string) file_get_contents($f), true);
    return is_array($data) ? $data : null;
}

function seriesSave(string $shop, array $data, string $drive = 'Z'): void
{
    $dir = seriesDir($shop, $drive);
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) throw new RuntimeException("לא ניתן ליצור את $dir");
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('שגיאה בשמירת החלוקה: ' . json_last_error_msg());
    $tmp = $dir . '/series.json.tmp';
    if (file_put_contents($tmp, $json) === false || !rename($tmp, $dir . '/series.json')) throw new RuntimeException("לא ניתן לשמור את $dir/series.json");
}

/**
 * Splits the shop's newest download. Keeps the decisions of the previous split
 * (by barcode), moves the previous series.json to History, and returns the
 * new data with 'changes' against the previous split.
 */
function seriesSplit(string $shop, string $drive = 'Z', bool $rebuild = false): array
{
    $dl = seriesLatestDownload($shop, $drive);
    if (!$dl) throw new RuntimeException('אין הורדה של רשימת מוצרים ומחירים ב-' . seriesListDir($shop, $drive) . ' — הורד מה-Gateway של קומקס');

    $items = seriesReadComax($dl['path']);
    $manufacturerOf = null;
    if (is_file(__DIR__ . '/../commercialLayer/cpd_client.php')) {
        require_once __DIR__ . '/../commercialLayer/cpd_client.php';
        $cpd = new CPDClient();
        if ($cpd->available()) $manufacturerOf = fn($bc) => strlen($bc) >= 7 ? (string) ($cpd->card($bc)['manufacturer'] ?? '') : '';
    }
    $prev = seriesLoad($shop, $drive);
    $decisions = $prev['decisions'] ?? [];
    // Renamed items (rename.php): split by the new name — it is the name the shop wants; the
    // rename waits in name_fixes.json for the Gateway, and is dropped once Comax shows it.
    foreach ($items as &$it) {
        $ren = $decisions[$it['barcode']]['rename'] ?? null;
        if ($ren === null) continue;
        if (seriesNorm($ren) === seriesNorm($it['name'])) { unset($decisions[$it['barcode']]['rename'], $decisions[$it['barcode']]['renameAt']); continue; }
        $it['comaxName'] = $it['name'];
        $it['name'] = $ren;
    }
    unset($it);
    $result = seriesBuild($items, $manufacturerOf);

    $changes = $rebuild ? ($prev['changes'] ?? null) : null;
    if ($prev && !$rebuild) {
        $old = [];
        foreach (array_merge(...array_map(fn($s) => $s['items'], $prev['result']['series'] ?? [])) as $it) $old[$it['barcode']] = $it;
        foreach ($prev['result']['loose'] ?? [] as $it) $old[$it['barcode']] = $it;
        $new = array_column($items, null, 'barcode');
        $changes = ['added' => [], 'removed' => [], 'priceChanged' => []];
        foreach ($new as $bc => $it) {
            if (!isset($old[$bc])) $changes['added'][] = ['barcode' => $bc, 'name' => $it['name'], 'price' => $it['price']];
            elseif (abs($old[$bc]['price'] - $it['price']) > 0.001) $changes['priceChanged'][] = ['barcode' => $bc, 'name' => $it['name'], 'old' => $old[$bc]['price'], 'new' => $it['price']];
        }
        foreach ($old as $bc => $it) if (!isset($new[$bc])) $changes['removed'][] = ['barcode' => $bc, 'name' => $it['name'], 'price' => $it['price']];
        // a fixed price that Comax now has: the fix is done
        foreach ($decisions as $bc => &$d) {
            if (isset($d['fixPrice'], $new[$bc]) && abs($new[$bc]['price'] - $d['fixPrice']) < 0.001) unset($d['fixPrice']);
        }
        unset($d);

        $hist = seriesDir($shop, $drive) . '/History';
        if (!is_dir($hist)) mkdir($hist, 0777, true);
        @copy(seriesDir($shop, $drive) . '/series.json', $hist . '/series ' . date('Y-m-d His', strtotime($prev['meta']['built'] ?? 'now')) . '.json');
    }

    $decisions = array_filter($decisions, fn($d) => ($d['assign'] ?? '') !== '' || isset($d['fixPrice']) || isset($d['rename']));

    $data = [
        'meta' => ['shop' => $shop, 'built' => date('Y-m-d H:i:s'), 'source' => $dl['name'], 'sourceTime' => date('Y-m-d H:i:s', $dl['mtime'])],
        'result' => $result,
        'decisions' => $decisions,
        'changes' => $changes,
    ];
    seriesSave($shop, $data, $drive);
    seriesWritePriceFixes($shop, $data, $drive);
    seriesWriteNameFixes($shop, $data, $drive);
    return $data;
}

/**
 * Series
ame_fixes.json — item renames for the Comax Gateway (rename.php), rewritten after every change:
 *   {"shop", "updated", "fixes": [{"barcode", "code", "oldName", "newName", "created"}]}
 * A rename leaves the file when a new download shows Comax has the new name.
 */
function seriesWriteNameFixes(string $shop, array $data, string $drive = 'Z'): void
{
    $byBc = [];
    foreach ($data['result']['series'] as $s) foreach ($s['items'] as $it) $byBc[$it['barcode']] = $it;
    foreach ($data['result']['loose'] as $it) $byBc[$it['barcode']] = $it;
    $fixes = [];
    foreach ($data['decisions'] ?? [] as $bc => $d) {
        if (!isset($d['rename'], $byBc[$bc])) continue;
        $it = $byBc[$bc];
        $fixes[] = ['barcode' => (string) $bc, 'code' => (string) $it['code'], 'oldName' => $it['comaxName'] ?? $it['name'],
            'newName' => $d['rename'], 'created' => $d['renameAt'] ?? ''];
    }
    file_put_contents(seriesDir($shop, $drive) . '/name_fixes.json', json_encode(['shop' => $shop, 'updated' => date('Y-m-d H:i:s'), 'fixes' => $fixes], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** The item's price for the series: the fixed price when the user set one. */
function seriesItemPrice(array $it, array $decisions): float
{
    return isset($decisions[$it['barcode']]['fixPrice']) ? (float) $decisions[$it['barcode']]['fixPrice'] : (float) $it['price'];
}

/**
 * The split with the user's decisions applied: each series is cut into its
 * sub-series ("140", "140A", "140B"), items marked 'none' go to loose; prices
 * and conflicts are recomputed with the fixed prices.
 */
function seriesApply(array $data): array
{
    $dec = $data['decisions'] ?? [];
    $result = $data['result'];
    $series = []; $loose = $result['loose'];
    foreach ($result['series'] as $s) {
        $parts = [];
        foreach ($s['items'] as $it) {
            $a = $dec[$it['barcode']]['assign'] ?? '';
            if ($a === 'none') { $it['why'] = 'סומן "לא מסדרה"'; $loose[] = $it; continue; }
            $it['effPrice'] = seriesItemPrice($it, $dec);
            $it['fixed'] = isset($dec[$it['barcode']]['fixPrice']);
            $parts[$a][] = $it;
        }
        ksort($parts);
        foreach ($parts as $letter => $items) {
            $prices = [];
            foreach ($items as $it) { $k = number_format($it['effPrice'], 2, '.', ''); $prices[$k] = ($prices[$k] ?? 0) + 1; }
            arsort($prices);
            $series[] = array_merge($s, [
                'id' => $s['id'] . $letter, 'baseId' => $s['id'], 'letter' => $letter, 'items' => $items,
                'sizeText' => seriesSizeLabel($items), 'prices' => $prices,
                'price' => (float) array_key_first($prices), 'conflict' => count($prices) > 1,
                'split' => count($parts) > 1,
            ]);
        }
    }
    $result['series'] = $series;
    $result['loose'] = $loose;
    return $result;
}

/**
 * Series\price_fixes.json — the price fixes for the Comax Gateway, rewritten
 * from the decisions after every change. Format (agreed with the Gateway):
 *   {"shop": "YBC", "updated": "2026-11-18 19:30:00",
 *    "fixes": [{"barcode": "...", "code": "<Comax פריט>", "name": "...",
 *               "oldPrice": 14.9, "newPrice": 12.9, "created": "2026-11-18 19:29:11"}]}
 * A fix leaves the file when a new download shows Comax has the new price.
 */
function seriesWritePriceFixes(string $shop, array $data, string $drive = 'Z'): void
{
    $byBc = [];
    foreach ($data['result']['series'] as $s) foreach ($s['items'] as $it) $byBc[$it['barcode']] = $it;
    $fixes = [];
    foreach ($data['decisions'] ?? [] as $bc => $d) {
        if (!isset($d['fixPrice'], $byBc[$bc])) continue;
        $fixes[] = ['barcode' => (string) $bc, 'code' => (string) $byBc[$bc]['code'], 'name' => $byBc[$bc]['name'],
            'oldPrice' => (float) $byBc[$bc]['price'], 'newPrice' => (float) $d['fixPrice'], 'created' => $d['fixAt'] ?? ''];
    }
    $out = ['shop' => $shop, 'updated' => date('Y-m-d H:i:s'), 'fixes' => $fixes];
    file_put_contents(seriesDir($shop, $drive) . '/price_fixes.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// ── Products waiting for a price (NP's final screen: no series fits and no price given) ──
// Series\waiting_price.json: [{barcode, name, date, npDir, image, added}] — kept per barcode.

function seriesWaitingPath(string $shop, string $drive = 'Z'): string
{
    return seriesDir($shop, $drive) . '/waiting_price.json';
}

function seriesWaitingLoad(string $shop, string $drive = 'Z'): array
{
    $list = json_decode((string) @file_get_contents(seriesWaitingPath($shop, $drive)), true);
    return is_array($list) ? $list : [];
}

/** Adds (or refreshes, by barcode) products waiting for a price. */
function seriesWaitingAdd(string $shop, array $items, string $drive = 'Z'): void
{
    if (!$items) return;
    $dir = seriesDir($shop, $drive);
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) throw new RuntimeException("לא ניתן ליצור את $dir");
    $list = array_column(seriesWaitingLoad($shop, $drive), null, 'barcode');
    foreach ($items as $it) $list[(string) $it['barcode']] = $it + ['added' => date('Y-m-d H:i:s')];
    file_put_contents(seriesWaitingPath($shop, $drive), json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function seriesWaitingRemove(string $shop, array $barcodes, string $drive = 'Z'): void
{
    $list = array_values(array_filter(seriesWaitingLoad($shop, $drive), fn($it) => !in_array((string) $it['barcode'], array_map('strval', $barcodes), true)));
    file_put_contents(seriesWaitingPath($shop, $drive), json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * The pricing options for products without a price (NP's final screen), from the shop's split:
 * $items [{barcode, name}] → [barcode => [{kind, title, price, prices, conflict, loose, sizeFit, count, examples}]].
 * null when the shop has no split.
 */
function seriesPriceOptions(string $shop, array $items, string $drive = 'Z', int $max = 6): ?array
{
    $data = seriesLoad($shop, $drive);
    if (!$data) return null;
    $result = seriesApply($data);
    $cpd = null;
    if (is_file(__DIR__ . '/../commercialLayer/cpd_client.php')) {
        require_once __DIR__ . '/../commercialLayer/cpd_client.php';
        $cpd = new CPDClient();
        if (!$cpd->available()) $cpd = null;
    }
    $out = [];
    foreach ($items as $it) {
        $bc = (string) $it['barcode'];
        $man = $cpd && strlen($bc) >= 7 ? (string) ($cpd->card($bc)['manufacturer'] ?? '') : '';
        $opts = [];
        foreach (array_slice(seriesCandidates(['barcode' => $bc, 'name' => (string) $it['name'], 'manufacturer' => $man], $result), 0, $max) as $c) {
            if ($c['type'] === 'series') {
                $s = $c['series'];
                $opts[] = ['kind' => 'series', 'title' => 'סדרה ' . $s['id'] . ': ' . ($s['brand'] ?: 'ללא יצרן') . ' · ' . $s['line'] . ' · ' . $s['sizeText'],
                    'price' => $s['price'], 'prices' => $s['prices'], 'conflict' => $s['conflict'], 'loose' => !empty($c['loose']),
                    'sizeFit' => $c['sizeFit'], 'count' => count($s['items']),
                    'like' => 'כמו ' . seriesLikeName($s['items'], (string) $it['name'], $bc) . (count($s['items']) > 1 ? ' (סדרה של ' . count($s['items']) . ' מוצרים)' : ''),
                    'examples' => array_map(fn($i) => $i['name'] . ' — ' . number_format((float) ($i['effPrice'] ?? $i['price']), 2), array_slice($s['items'], 0, 4))];
            } else {
                $i = $c['item'];
                $opts[] = ['kind' => 'single', 'barcode' => (string) $i['barcode'], 'title' => 'פריט בודד: ' . $i['name'], 'like' => 'כמו ' . $i['name'], 'price' => $i['price'], 'prices' => [], 'conflict' => false,
                    'loose' => !empty($c['loose']), 'sizeFit' => $c['sizeFit'], 'count' => 1, 'examples' => []];
            }
        }
        $out[$bc] = $opts;
    }
    return $out;
}

/** The series' product most like $name (for "כמו <product>" in the summary to the shop owner). */
function seriesLikeName(array $items, string $name, string $barcode = ''): string
{
    // not the product itself (it may already be in the price list, in that series)
    $others = array_values(array_filter($items, fn($i) => (string) $i['barcode'] !== $barcode));
    if ($others) $items = $others;
    usort($items, fn($a, $b) => seriesNameSimilarity($name, $b['name']) <=> seriesNameSimilarity($name, $a['name']));
    return $items[0]['name'];
}

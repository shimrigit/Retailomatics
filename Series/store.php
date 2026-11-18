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
function seriesSplit(string $shop, string $drive = 'Z'): array
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
    $result = seriesBuild($items, $manufacturerOf);

    $prev = seriesLoad($shop, $drive);
    $decisions = $prev['decisions'] ?? [];
    $changes = null;
    if ($prev) {
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
        $decisions = array_filter($decisions, fn($d) => ($d['assign'] ?? '') !== '' || isset($d['fixPrice']));

        $hist = seriesDir($shop, $drive) . '/History';
        if (!is_dir($hist)) mkdir($hist, 0777, true);
        @copy(seriesDir($shop, $drive) . '/series.json', $hist . '/series ' . date('Y-m-d His', strtotime($prev['meta']['built'] ?? 'now')) . '.json');
    }

    $data = [
        'meta' => ['shop' => $shop, 'built' => date('Y-m-d H:i:s'), 'source' => $dl['name'], 'sourceTime' => date('Y-m-d H:i:s', $dl['mtime'])],
        'result' => $result,
        'decisions' => $decisions,
        'changes' => $changes,
    ];
    seriesSave($shop, $data, $drive);
    seriesWritePriceFixes($shop, $data, $drive);
    return $data;
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

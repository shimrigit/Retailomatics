<?php
// Paths and small helpers shared by WaFetch (lib.php) and NP harmonized
// (NPharmonized/wa_source.php). No access check here — lib.php adds it for
// WaFetch's own pages.

define('WAF_FETCHER_DIR', 'C:/RetailAgents/WhatsAppMessageFetcher');
define('WAF_NODE_EXE', 'C:/Program Files/nodejs/node.exe');
define('WAF_WEB_DIR', WAF_FETCHER_DIR . '/output/web');
define('WAF_JOBS_DIR', WAF_WEB_DIR . '/jobs');
define('WAF_SETTINGS', WAF_WEB_DIR . '/settings.json');
// The downloaded images: one folder per shop, so NP runs of several shops
// don't take each other's images — output/images/<shop>. NP harmonized takes
// them from there. (Images left directly in output/images are from before.)
define('WAF_IMAGES_ROOT', WAF_FETCHER_DIR . '/output/images');
define('WAF_IMAGES_DIR', WAF_IMAGES_ROOT);   // the old single folder — kept for old callers
define('WAF_ARCHIVE_DIR', WAF_FETCHER_DIR . '/output/archive');
// The images NP has already taken, per shop (so a new download's archiving
// can warn about images NP never processed): output/web/consumed/<shop>.json.
define('WAF_CONSUMED_DIR', WAF_WEB_DIR . '/consumed');
define('WAF_DEFAULT_NAMES', ['שמרי', 'שימרי']);
// The folder of a run that has no shop (a group not linked to a shop, run from the page itself).
define('WAF_NO_SHOP', 'ללא חנות');

/** The shop's folder name: letters, digits, space, _ . - only (never a path). */
function waf_shop_key(string $shop): string
{
    $k = trim((string) preg_replace('/[^\p{L}\p{N} _.\-]+/u', '', $shop), " .");
    return $k !== '' ? $k : WAF_NO_SHOP;
}

function waf_images_dir(string $shop): string
{
    return WAF_IMAGES_ROOT . '/' . waf_shop_key($shop);
}

/** The shops whose folder holds images now. */
function waf_inbox_shops(): array
{
    return array_values(array_filter(scandir(WAF_IMAGES_ROOT) ?: [],
        fn($d) => $d[0] !== '.' && is_dir(WAF_IMAGES_ROOT . "/$d") && waf_inbox_files($d)));
}

function waf_write_json(string $path, array $data): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    rename($tmp, $path);
}

function waf_read_json(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

/** File names currently in the shop's images folder. */
function waf_inbox_files(string $shop): array
{
    $dir = waf_images_dir($shop);
    return is_dir($dir) ? array_values(array_filter(scandir($dir) ?: [], fn($f) => is_file("$dir/$f"))) : [];
}

function waf_consumed_path(string $shop): string
{
    return WAF_CONSUMED_DIR . '/' . waf_shop_key($shop) . '.json';
}

/** NP harmonized took these images of the shop (process_whatsapp.php). */
function waf_mark_consumed(array $fileNames, string $by, string $shop): void
{
    $c = waf_read_json(waf_consumed_path($shop)) ?? ['files' => []];
    $c['files'] = array_values(array_unique(array_merge($c['files'], $fileNames)));
    $c['last'] = ['at' => date('c'), 'by' => $by, 'count' => count($fileNames)];
    waf_write_json(waf_consumed_path($shop), $c);
}

/** Images in the shop's folder that NP hasn't taken yet. */
function waf_unprocessed_files(string $shop): array
{
    $consumed = (waf_read_json(waf_consumed_path($shop)) ?? [])['files'] ?? [];
    return array_values(array_diff(waf_inbox_files($shop), $consumed));
}

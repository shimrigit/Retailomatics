<?php
// Paths and small helpers shared by WaFetch (lib.php) and NP harmonized
// (NPharmonized/wa_source.php). No access check here — lib.php adds it for
// WaFetch's own pages.

define('WAF_FETCHER_DIR', 'C:/RetailAgents/WhatsAppMessageFetcher');
define('WAF_NODE_EXE', 'C:/Program Files/nodejs/node.exe');
define('WAF_WEB_DIR', WAF_FETCHER_DIR . '/output/web');
define('WAF_JOBS_DIR', WAF_WEB_DIR . '/jobs');
define('WAF_SETTINGS', WAF_WEB_DIR . '/settings.json');
// The images of the last download — NP harmonized takes them from here.
define('WAF_IMAGES_DIR', WAF_FETCHER_DIR . '/output/images');
// The images NP has already taken (so a new download's archiving can warn
// about images NP never processed).
define('WAF_CONSUMED', WAF_WEB_DIR . '/consumed.json');
define('WAF_DEFAULT_NAMES', ['שמרי', 'שימרי']);

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

/** File names currently in the images folder. */
function waf_inbox_files(): array
{
    return array_values(array_filter(scandir(WAF_IMAGES_DIR) ?: [],
        fn($f) => is_file(WAF_IMAGES_DIR . '/' . $f)));
}

/** NP harmonized took these images (process_whatsapp.php). */
function waf_mark_consumed(array $fileNames, string $by): void
{
    $c = waf_read_json(WAF_CONSUMED) ?? ['files' => []];
    $c['files'] = array_values(array_unique(array_merge($c['files'], $fileNames)));
    $c['last'] = ['at' => date('c'), 'by' => $by, 'count' => count($fileNames)];
    waf_write_json(WAF_CONSUMED, $c);
}

/** Images in the folder that NP hasn't taken yet. */
function waf_unprocessed_files(): array
{
    $consumed = (waf_read_json(WAF_CONSUMED) ?? [])['files'] ?? [];
    return array_values(array_diff(waf_inbox_files(), $consumed));
}

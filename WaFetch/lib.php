<?php
// WaFetch — web front for C:\RetailAgents\WhatsAppMessageFetcher (the
// `npm start` WhatsApp image fetcher). The page picks a saved group and a
// time window; web-run.js does the work in the background on this computer
// and reports through a status file the page polls.
//
// Settings (saved groups, "messages for me" names) and run folders live under
// the fetcher's output/web/ (gitignored there, like the rest of output/).
// Also step 1 of NP harmonized's "WhatsApp — download from a group" mode:
// NP posts its choices here, and NP takes the images straight from the
// shop's own folder output/images/<shop> (config.php: waf_images_dir).
// Runs of several shops can be started together: they queue for the one
// WhatsApp connection (web-run.js), one shop at a time.

// It starts programs and opens windows on this computer: only from the
// computer itself — not through the ngrok tunnel the WhatsApp webhook uses
// (ngrok adds X-Forwarded-For) or from another machine.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    http_response_code(403);
    exit('WaFetch is available on this computer only.');
}

require_once __DIR__ . '/config.php';

/**
 * ['groups' => [key => ['name', 'shop' (NP shop, set on its first NP run), 'lastEnd' (unix|null), 'added']],
 *  'names' => [...]].
 * name = the chat's name as in WhatsApp (the runner finds the chat by it);
 * key = the name in lower case.
 */
function waf_settings(): array
{
    $s = waf_read_json(WAF_SETTINGS) ?? [];
    return ['groups' => $s['groups'] ?? [], 'names' => $s['names'] ?? WAF_DEFAULT_NAMES];
}

function waf_save_settings(array $s): void
{
    waf_write_json(WAF_SETTINGS, $s);
}

function waf_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    $out = shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');
    return is_string($out) && strpos($out, (string) $pid) !== false;
}

/**
 * The runs not finished yet (running, or queued for the WhatsApp connection),
 * oldest first, as [id => ['status' => ..., 'shop' => ...]]. A run just
 * started (no pid yet, under a minute old) counts too.
 */
function waf_active_jobs(): array
{
    $out = [];
    foreach (glob(WAF_JOBS_DIR . '/*/status.json') ?: [] as $path) {
        $st = waf_read_json($path);
        if (!$st || in_array($st['state'] ?? '', ['done', 'error'], true)) {
            continue;
        }
        $pid = (int) ($st['pid'] ?? 0);
        if ($pid ? !waf_pid_alive($pid) : filemtime($path) < time() - 60) {
            continue;
        }
        $job = waf_read_json(dirname($path) . '/job.json') ?? [];
        $out[basename(dirname($path))] = ['status' => $st, 'shop' => (string) ($job['shop'] ?? '')];
    }
    ksort($out);
    return $out;
}

/** The shop's run not finished yet, as [id, status], or null. One run per shop at a time. */
function waf_active_job_for(string $shop): ?array
{
    foreach (waf_active_jobs() as $id => $j) {
        if (waf_shop_key($j['shop']) === waf_shop_key($shop)) {
            return [$id, $j['status']];
        }
    }
    return null;
}

/** Starts web-run.js in the background for $job; returns the job id. */
function waf_start_job(array $job, array $meta = []): string
{
    $id = date('ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 4);
    $dir = WAF_JOBS_DIR . '/' . $id;
    mkdir($dir, 0777, true);
    $job['jobDir'] = str_replace('/', '\\', $dir);
    waf_write_json("$dir/job.json", $job);
    waf_write_json("$dir/meta.json", $meta);
    waf_write_json("$dir/status.json", ['state' => 'starting', 'message' => 'מתחיל…', 'pid' => 0]);
    $win = fn(string $p) => str_replace('/', '\\', $p);
    $cmd = 'start "" /B /D "' . $win(WAF_FETCHER_DIR) . '" "' . $win(WAF_NODE_EXE) . '" web-run.js "'
        . $win("$dir/job.json") . '" > "' . $win("$dir/log.txt") . '" 2>&1';
    pclose(popen($cmd, 'r'));
    return $id;
}

/** Starts relink.js (a visible Chrome with WhatsApp Web's QR) in the background; its output goes to output/web/relink.log. */
function waf_start_relink(bool $stayOpen = false): void
{
    $win = fn(string $p) => str_replace('/', '\\', $p);
    $cmd = 'start "" /B /D "' . $win(WAF_FETCHER_DIR) . '" "' . $win(WAF_NODE_EXE) . '" relink.js' . ($stayOpen ? ' --open' : '') . ' > "' . $win(WAF_WEB_DIR . '/relink.log') . '" 2>&1';
    pclose(popen($cmd, 'r'));
}

function waf_job_dir(string $id): ?string
{
    return preg_match('/^\d{6}-\d{6}-[0-9a-f]{4}$/', $id) && is_dir(WAF_JOBS_DIR . "/$id") ? WAF_JOBS_DIR . "/$id" : null;
}

/** Opens the shop's images folder in Explorer with large icons (as at the end of a run). */
function waf_open_images_folder(string $shop): void
{
    if (!is_dir(waf_images_dir($shop))) {
        mkdir(waf_images_dir($shop), 0777, true);
    }
    $dir = str_replace('/', '\\', waf_images_dir($shop));
    $ps = "\$dir = '" . str_replace("'", "''", $dir) . "'\n"
        . "Start-Process explorer.exe -ArgumentList ('\"' + \$dir + '\"')\n"
        . "\$sh = New-Object -ComObject Shell.Application\n"
        . "for (\$i = 0; \$i -lt 20; \$i++) { Start-Sleep -Milliseconds 500\n"
        . "  foreach (\$w in \$sh.Windows()) { try { if (\$w.Document.Folder.Self.Path -eq \$dir) { \$w.Document.CurrentViewMode = 1; \$w.Document.IconSize = 96; exit } } catch {} } }";
    $file = WAF_WEB_DIR . '/open-folder.ps1';
    file_put_contents($file, "\xEF\xBB\xBF" . $ps);
    pclose(popen('start "" /B powershell.exe -NoProfile -ExecutionPolicy Bypass -File "' . str_replace('/', '\\', $file) . '"', 'r'));
}

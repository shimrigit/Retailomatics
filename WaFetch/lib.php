<?php
// WaFetch — web front for C:\RetailAgents\WhatsAppMessageFetcher (the
// `npm start` WhatsApp image fetcher). The page picks a saved group and a
// time window; web-run.js does the work in the background on this computer
// and reports through a status file the page polls.
//
// Settings (saved groups, "messages for me" names) and run folders live under
// the fetcher's output/web/ (gitignored there, like the rest of output/).
// Also step 1 of NP harmonized's "WhatsApp — download from a group" mode:
// NP posts its choices here, and NP takes the images straight from
// output/images (config.php: WAF_IMAGES_DIR).

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

/** The job still running, as [id, status], or null. One run at a time (one WhatsApp session). */
function waf_running_job(): ?array
{
    foreach (glob(WAF_JOBS_DIR . '/*/status.json') ?: [] as $path) {
        $st = waf_read_json($path);
        if ($st && !in_array($st['state'] ?? '', ['done', 'error'], true) && waf_pid_alive((int) ($st['pid'] ?? 0))) {
            return [basename(dirname($path)), $st];
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

function waf_job_dir(string $id): ?string
{
    return preg_match('/^\d{6}-\d{6}-[0-9a-f]{4}$/', $id) && is_dir(WAF_JOBS_DIR . "/$id") ? WAF_JOBS_DIR . "/$id" : null;
}

/** Opens output/images in Explorer with large icons (as at the end of a run). */
function waf_open_images_folder(): void
{
    $dir = str_replace('/', '\\', WAF_IMAGES_DIR);
    $ps = "\$dir = '" . str_replace("'", "''", $dir) . "'\n"
        . "Start-Process explorer.exe -ArgumentList ('\"' + \$dir + '\"')\n"
        . "\$sh = New-Object -ComObject Shell.Application\n"
        . "for (\$i = 0; \$i -lt 20; \$i++) { Start-Sleep -Milliseconds 500\n"
        . "  foreach (\$w in \$sh.Windows()) { try { if (\$w.Document.Folder.Self.Path -eq \$dir) { \$w.Document.CurrentViewMode = 1; \$w.Document.IconSize = 96; exit } } catch {} } }";
    $file = WAF_WEB_DIR . '/open-folder.ps1';
    file_put_contents($file, "\xEF\xBB\xBF" . $ps);
    pclose(popen('start "" /B powershell.exe -NoProfile -ExecutionPolicy Bypass -File "' . str_replace('/', '\\', $file) . '"', 'r'));
}

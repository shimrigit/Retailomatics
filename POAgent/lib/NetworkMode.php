<?php
// NetworkMode.php — reads/writes the same POcounter/.link_base_url file
// mobile_link.php's poagent_wa_link_base_url() already falls back to, so
// there's exactly one place that knows this file's path/shape. Two modes:
//   - "ngrok" (file empty/missing) — poagent_wa_mobile_link() derives the
//     base URL from the live webhook request's host (i.e. the ngrok domain).
//   - "lan" (file holds a scheme+host) — every link built after this points
//     straight at that address instead, bypassing ngrok's relay entirely.
// See POAgent/tools/network_mode.php for the switch UI.

define('POAGENT_NETWORK_MODE_FILE', __DIR__ . '/../POcounter/.link_base_url');

/** @return array{mode: 'ngrok'|'lan', value: string} */
function poagent_network_mode_get(): array
{
    if (is_file(POAGENT_NETWORK_MODE_FILE)) {
        $v = trim((string) file_get_contents(POAGENT_NETWORK_MODE_FILE));
        if ($v !== '') {
            return ['mode' => 'lan', 'value' => rtrim($v, '/')];
        }
    }
    return ['mode' => 'ngrok', 'value' => ''];
}

function poagent_network_mode_set_lan(string $baseUrl): void
{
    $dir = dirname(POAGENT_NETWORK_MODE_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    file_put_contents(POAGENT_NETWORK_MODE_FILE, rtrim(trim($baseUrl), '/') . "\n");
}

function poagent_network_mode_clear(): void
{
    if (is_file(POAGENT_NETWORK_MODE_FILE)) {
        unlink(POAGENT_NETWORK_MODE_FILE);
    }
}

/**
 * Local IPv4 candidates to suggest for LAN mode — parses `ipconfig` (no
 * external dependency, works without admin rights, unlike the PowerShell
 * cmdlets used to diagnose this by hand). Loopback/APIPA excluded. Each
 * entry: ['adapter' => label from ipconfig, 'ip' => address].
 */
function poagent_network_mode_local_ip_candidates(): array
{
    $out = @shell_exec('ipconfig');
    if (!is_string($out) || $out === '') {
        return [];
    }
    $candidates = [];
    $adapter = '';
    foreach (preg_split('/\r?\n/', $out) as $line) {
        if (preg_match('/^\S.*adapter (.+):\s*$/i', $line, $m)) {
            $adapter = trim($m[1]);
            continue;
        }
        if (preg_match('/IPv4 Address[.\s]*:\s*([\d.]+)/i', $line, $m)) {
            $ip = $m[1];
            if (strpos($ip, '127.') === 0 || strpos($ip, '169.254.') === 0) {
                continue;
            }
            $candidates[] = ['adapter' => $adapter, 'ip' => $ip];
        }
    }
    return $candidates;
}

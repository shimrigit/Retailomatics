<?php
// WaFetch JSON API (called by index.php's page script).
//   GET  ?action=status&job=<id>         → the run's status (+ applies a finished fetch's end time to its group)
//   POST action=add_group  name          → saves a group (its name exactly as in WhatsApp)
//   POST action=remove_group  key
//   POST action=fetch  key start end names [shop] [confirm_archive] → starts a run
//        (→ {needConfirm, unprocessed} first if the folder holds images NP never took)
//   POST action=open_folder              → opens output/images in Explorer again
require_once __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_REQUEST['action'] ?? '';
$settings = waf_settings();

if ($action === 'status') {
    $dir = waf_job_dir((string) ($_GET['job'] ?? ''));
    if (!$dir) {
        out(['error' => 'no such run'], 404);
    }
    $st = waf_read_json("$dir/status.json") ?? ['state' => 'starting'];
    // The runner died without saying so (closed, crashed before writing).
    if (!in_array($st['state'] ?? '', ['done', 'error'], true) && ($st['pid'] ?? 0) && !waf_pid_alive((int) $st['pid'])) {
        $log = is_file("$dir/log.txt") ? (string) file_get_contents("$dir/log.txt") : '';
        $st = ['state' => 'error', 'message' => 'הריצה הפסיקה באמצע', 'error' => mb_substr(trim($log), -1500)];
    }
    $meta = waf_read_json("$dir/meta.json") ?? [];
    if (($st['state'] ?? '') === 'done' && ($meta['mode'] ?? '') === 'fetch' && empty($meta['applied'])) {
        if (isset($settings['groups'][$meta['key']])) {
            $settings['groups'][$meta['key']]['lastEnd'] = (int) $meta['end'];
            waf_save_settings($settings);
        }
        $meta['applied'] = true;
        waf_write_json("$dir/meta.json", $meta);
    }
    out($st);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['error' => 'POST required'], 405);
}

if ($action === 'fetch' && ($running = waf_running_job())) {
    out(['error' => 'ריצה אחרת עדיין פעילה — המתן שתסתיים', 'job' => $running[0]], 409);
}

switch ($action) {
    case 'add_group':
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 100) {
            out(['error' => 'כתוב את שם הקבוצה כפי שהוא מופיע ב-WhatsApp'], 400);
        }
        $key = mb_strtolower($name);
        if (!isset($settings['groups'][$key])) {
            $settings['groups'][$key] = ['name' => $name, 'lastEnd' => null, 'added' => date('c')];
            waf_save_settings($settings);
        }
        out(['ok' => true, 'key' => $key, 'groups' => $settings['groups']]);

    case 'remove_group':
        unset($settings['groups'][(string) ($_POST['key'] ?? '')]);
        waf_save_settings($settings);
        out(['ok' => true, 'groups' => $settings['groups']]);

    case 'fetch':
        $key = (string) ($_POST['key'] ?? '');
        $group = $settings['groups'][$key] ?? null;
        $start = (int) ($_POST['start'] ?? 0);
        $end = (int) ($_POST['end'] ?? 0);
        if (!$group) {
            out(['error' => 'בחר קבוצה'], 400);
        }
        if ($start <= 0 || $end <= $start) {
            out(['error' => 'זמן הסיום חייב להיות אחרי זמן ההתחלה'], 400);
        }
        if ($end > time() + 60) {
            $end = time();
        }
        // The run moves the folder's images to the archive first: warn about
        // images NP harmonized never took (a batch would leave the NP inbox).
        $unprocessed = waf_unprocessed_files();
        if ($unprocessed && empty($_POST['confirm_archive'])) {
            out(['needConfirm' => true, 'unprocessed' => count($unprocessed)]);
        }
        $names = array_values(array_filter(array_map('trim', preg_split('/[,،\n]+/u', (string) ($_POST['names'] ?? '')))));
        $settings['names'] = $names;
        // From NP: the group belongs to this shop (set once — a group serves one shop).
        $shop = trim((string) ($_POST['shop'] ?? ''));
        if ($shop !== '' && empty($settings['groups'][$key]['shop'])) {
            $settings['groups'][$key]['shop'] = $shop;
        }
        waf_save_settings($settings);
        out(['job' => waf_start_job(
            ['chatName' => $group['name'], 'start' => $start, 'end' => $end, 'names' => $names],
            ['mode' => 'fetch', 'key' => $key, 'end' => $end])]);

    case 'open_folder':
        waf_open_images_folder();
        out(['ok' => true]);
}
out(['error' => 'unknown action'], 400);

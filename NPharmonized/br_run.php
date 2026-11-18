<?php
// br_run.php — the barcode reading's results, passed from stage_br.php to
// stage_br_save.php under the run's own id (a hidden field), in a file of their
// own — not in the PHP session.
//
// Why: the session is one per browser, shared by every tab. With NP runs of
// several shops open at once, one tab's reading overwrote the other's (the save
// could write one shop's barcodes into the other shop's folder), and PHP's
// session lock made the other tabs wait while a reading ran.
//
// Files: the system temp folder, np_br/<id>.json; ones older than 2 days are removed.

function npBrRunDir(): string
{
    $dir = sys_get_temp_dir() . '/np_br';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir;
}

function npBrRunPath(string $id): ?string
{
    return preg_match('/^[0-9a-f]{16}$/', $id) ? npBrRunDir() . "/$id.json" : null;
}

/** Saves the run's data; returns its id. */
function npBrRunSave(array $data): string
{
    foreach (glob(npBrRunDir() . '/*.json') ?: [] as $old) {
        if (filemtime($old) < time() - 2 * 86400) {
            @unlink($old);
        }
    }
    $id = bin2hex(random_bytes(8));
    file_put_contents(npBrRunPath($id), json_encode($data, JSON_UNESCAPED_UNICODE));
    return $id;
}

function npBrRunLoad(string $id): ?array
{
    $path = npBrRunPath($id);
    if (!$path || !is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

function npBrRunDelete(string $id): void
{
    $path = npBrRunPath($id);
    if ($path && is_file($path)) {
        @unlink($path);
    }
}

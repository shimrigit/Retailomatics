<?php
/**
 * The shop's conversion table: a supplier's own item code (not a barcode — "M3100"), or a row with no
 * code at all (then its description), → the shop's code in Comax. And the numbering of new products
 * that have no barcode: the next free number after the last one given ("last", set on InvoiceScan's
 * first screen).
 *
 * One file per shop: configDir/codemaps/<shop>.json (git-ignored — the shop's data):
 *   { "last": "1237", "free": ["1240"],   // free: numbers given back on the verify screen, used first
 *     "map": { "<supplier key>": { "<key>": { "code": "1238", "name": "…", "since": "2026-11-22",
 *                                              "status": "new" | "confirmed" } } } }
 * key = the printed code (trimmed, upper case), or "שם:" + the description when there is no code.
 * status "new" = a number we gave that is not yet in the shop's Comax items (price list); it turns
 * "confirmed" when it appears there. A conversion the user made on the verify screen is "confirmed".
 *
 * Used by process_commercial_layer.php (the CL) and retrieve_commercial_layer.php (Retrieve).
 */

const CM_DIR = __DIR__ . '/../configDir/codemaps';
/** A copy on Z after every change: <archive>/<shop>/CodeMap/codemap.json + one per day (codemap YYYY-MM-DD.json). */
const CM_Z_ROOT = 'Z:/RetailomaticsCloud/RetailomaticsArchive';

function cmPath(string $shop): string
{
    return CM_DIR . '/' . basename($shop) . '.json';
}

function cmZDir(string $shop): string
{
    return CM_Z_ROOT . '/' . basename($shop) . '/CodeMap';
}

/** Copies the shop's table to Z (when the shop's archive folder is there); false when it could not. */
function cmBackupToZ(string $shop): bool
{
    if (!is_file(cmPath($shop)) || !is_dir(CM_Z_ROOT . '/' . basename($shop))) return false;
    $dir = cmZDir($shop);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) return false;
    return @copy(cmPath($shop), "$dir/codemap.json") && @copy(cmPath($shop), "$dir/codemap " . date('Y-m-d') . '.json');
}

function cmLoad(string $shop): array
{
    $src = is_file(cmPath($shop)) ? cmPath($shop) : (is_file(cmZDir($shop) . '/codemap.json') ? cmZDir($shop) . '/codemap.json' : null);   // the local file lost → Z's copy
    $j = $src ? json_decode((string) file_get_contents($src), true) : null;
    return ['last' => isset($j['last']) && $j['last'] !== '' ? (string) $j['last'] : null, 'map' => (array) ($j['map'] ?? []),
            'free' => array_values(array_map('strval', (array) ($j['free'] ?? [])))];
}

/** Reads the table fresh, applies $fn(&$cm) and writes it — under a lock, so two runs never give the same number. */
function cmUpdate(string $shop, callable $fn, bool $dry = false)
{
    if ($dry) { $cm = cmLoad($shop); return $fn($cm); }   // a demo: the same answers, nothing written
    if (!is_dir(CM_DIR)) mkdir(CM_DIR, 0777, true);
    $lock = fopen(CM_DIR . '/.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $cm = cmLoad($shop);
        $before = $cm;
        $out = $fn($cm);
        if ($cm !== $before) {
            file_put_contents(cmPath($shop), json_encode($cm, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            cmBackupToZ($shop);   // Z down: the next change copies it (and the screen says so)
        }
        return $out;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Not a barcode: empty, or has a letter (M3100). Such a code is never matched by the end of a barcode. */
function cmIsInternal(string $code): bool
{
    $c = trim($code);
    return $c === '' || preg_match('/[^0-9\s\-.]/u', $c) === 1;
}

function cmKey(string $code, string $name): string
{
    $c = mb_strtoupper(preg_replace('/\s+/u', '', trim($code)));
    return $c !== '' ? $c : 'שם:' . preg_replace('/\s+/u', ' ', trim($name));
}

function cmLookup(array $cm, string $supplier, string $key): ?array
{
    return $cm['map'][$supplier][$key] ?? null;
}

/** All the codes in the shop's price list (digits only), as a set. */
function cmPriceListCodes($plSheet, string $col, int $highestRow): array
{
    $set = [];
    for ($m = 2; $m <= $highestRow; $m++) {
        $c = preg_replace('/[^0-9]/', '', (string) $plSheet->getCell("{$col}{$m}")->getValue());
        if ($c !== '') $set[$c] = true;
    }
    return $set;
}

/** The numbers we gave that now appear in the Comax items → "confirmed". */
function cmConfirmArrived(array &$cm, array $plCodes): void
{
    foreach ($cm['map'] as &$rows) {
        foreach ($rows as &$r) {
            if (($r['status'] ?? '') === 'new' && isset($plCodes[preg_replace('/[^0-9]/', '', (string) $r['code'])])) $r['status'] = 'confirmed';
        }
    }
}

/**
 * A new product with no barcode: the next number after "last" that is neither in the price list nor
 * already given — so a number someone opened in Comax by hand is skipped. Null when "last" is not set.
 */
function cmAllocate(array &$cm, string $supplier, string $key, string $name, array $plCodes): ?string
{
    if ($cm['last'] === null || !ctype_digit($cm['last'])) return null;
    $given = [];
    foreach ($cm['map'] as $rows) foreach ($rows as $r) $given[(string) $r['code']] = true;
    $free = array_values(array_filter($cm['free'] ?? [], fn($c) => !isset($plCodes[$c]) && !isset($given[$c])));
    sort($free, SORT_NUMERIC);
    if ($free) {   // a number given back on the verify screen goes first
        $n = (int) array_shift($free);
        $cm['free'] = $free;
    } else {
        $cm['free'] = [];
        $n = (int) $cm['last'];
        do { $n++; } while (isset($plCodes[(string) $n]) || isset($given[(string) $n]));
        $cm['last'] = (string) $n;
    }
    $old = $cm['map'][$supplier][$key] ?? null;
    if ($old && ($old['status'] ?? '') === 'new') $cm['free'][] = (string) $old['code'];   // never lose a number
    $cm['map'][$supplier][$key] = ['code' => (string) $n, 'name' => $name, 'since' => date('Y-m-d'), 'status' => 'new'];
    return (string) $n;
}

/**
 * The user converted a row on the verify screen (typed the Comax code over the printed one): remember it.
 * A number we had given to that row and was the last one is given back.
 */
function cmLearn(array &$cm, string $supplier, string $key, string $code, string $name): void
{
    $old = $cm['map'][$supplier][$key] ?? null;
    if ($old && (string) $old['code'] === $code) return;
    if ($old && ($old['status'] ?? '') === 'new') {   // the number we gave it is free again
        if ((string) $old['code'] === $cm['last'] && ctype_digit($cm['last'])) $cm['last'] = (string) ((int) $cm['last'] - 1);
        else $cm['free'][] = (string) $old['code'];
    }
    $cm['map'][$supplier][$key] = ['code' => $code, 'name' => $name, 'since' => date('Y-m-d'), 'status' => 'confirmed'];
}

/**
 * One CL row: the printed code → the code to match, and how it got there.
 * Returns [code, how] — how: 'map' (from the table), 'new' (a number given now), 'internal' (not a
 * barcode, no number: no start number set), 'none' (no code and not in the table: left as before —
 * such a row may be a deposit / discount line; on the verify screen "חדש" in the code cell gives it a
 * number), '' (a barcode as printed).
 * $forceNew: the user typed "חדש" — a new number even for a row without a code.
 */
function cmResolve(array &$cm, string $supplier, string $printed, string $name, array $plCodes, bool $forceNew = false): array
{
    $hit = cmLookup($cm, $supplier, cmKey($printed, $name));
    if ($hit) return [(string) $hit['code'], 'map'];
    if (!$forceNew && !cmIsInternal($printed)) return [$printed, ''];
    if (!$forceNew && trim($printed) === '') return ['', 'none'];
    $code = cmAllocate($cm, $supplier, cmKey($printed, $name), $name, $plCodes);
    return $code !== null ? [$code, 'new'] : [$printed, 'internal'];
}

/** Light blue = converted by the table; light green = a new number given now. */
const CM_FILL = ['map' => 'FFBDD7EE', 'new' => 'FFC6EFCE'];

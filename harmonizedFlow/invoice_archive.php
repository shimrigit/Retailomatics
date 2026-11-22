<?php
/**
 * The archive of a processed invoice, per shop and processing day (the "יום עיבוד" chosen in
 * Invoice Scan — session harmonizedFlow.workDay; today when none):
 *   Z:\RetailomaticsCloud\RetailomaticsArchive\{shop}\Invoices\{YYYY}\{MM_Month}\{dd-mm-yy} IN\
 * Per document (named by the PDF, "<Supplier> dd-mm-yy <Letter>"):
 *   <base>.pdf                      the approved document (all its pages)
 *   <base> - OCRsanity.xlsx         the data after checking and correcting
 *   <base> - שינויי מחיר.xlsx       PRICE-CHANGE, as approved for Comax
 *   <base> - מוצרים חדשים.xlsx      NEW-PRODUCTS, as approved for Comax
 *   <base> - OCRjson.json           the AI's raw reading
 * Written by cleanup_process.php at the end of a document.
 *
 * When Z can't be reached, the files wait in ZpendingDir/{shop}/{YYYY-MM-DD}/{name} — the path
 * itself says where each one goes — and invPendingFlush() moves them to Z later (the
 * "העבר ל-Z" button, z_pending.php). ZpendingDir should be empty; ZpendingDir/old/ holds the
 * files of the former uploads/ folder (no shop known), untouched.
 */

date_default_timezone_set('Asia/Jerusalem');   // the day of the folder is the shop's day (PHP here defaults to Berlin)
if (!defined('INV_ARCHIVE_ROOT')) define('INV_ARCHIVE_ROOT', 'Z:/RetailomaticsCloud/RetailomaticsArchive');   // a test may set another
define('INV_PENDING_DIR', dirname(__DIR__) . '/ZpendingDir');

/** The day's folder for a shop (not created). */
function invArchiveDir(string $shop, ?int $time = null): string
{
    $t = $time ?? time();
    return INV_ARCHIVE_ROOT . '/' . basename($shop) . '/Invoices/' . date('Y', $t) . '/' . date('m_F', $t) . '/' . date('d-m-y', $t) . ' IN';
}

/** The day's waiting folder for a shop (not created). */
function invPendingDir(string $shop, ?int $time = null): string
{
    return INV_PENDING_DIR . '/' . basename($shop) . '/' . date('Y-m-d', $time ?? time());
}

/** Z is there (its archive root can be read). */
function invArchiveReachable(): bool
{
    return is_dir(INV_ARCHIVE_ROOT);
}

/** $name in $dir, or "<name> (2).ext"… when taken. */
function invArchiveFreeName(string $dir, string $name): string
{
    if (!file_exists("$dir/$name")) return $name;
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $stem = pathinfo($name, PATHINFO_FILENAME);
    for ($i = 2; ; $i++) {
        $n = "$stem ($i)" . ($ext !== '' ? ".$ext" : '');
        if (!file_exists("$dir/$n")) return $n;
    }
}

/** A name already in $dir with exactly $src's content (an archived file processed again), or null. */
function invSameFileIn(string $dir, string $name, string $src): ?string
{
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $stem = pathinfo($name, PATHINFO_FILENAME);
    $size = filesize($src);
    $hash = null;
    foreach (array_merge(["$dir/$name"], glob($dir . '/' . preg_replace('/([*?\[])/', '[$1]', $stem) . ' (*)' . ($ext !== '' ? ".$ext" : '')) ?: []) as $f) {
        if (!is_file($f) || filesize($f) !== $size) continue;
        $hash ??= md5_file($src);
        if (md5_file($f) === $hash) return basename($f);
    }
    return null;
}

/** Copy $src to $dir/$name (a free name); the same content already there is reused. Returns the name, or null. */
function invPlace(string $src, string $dir, string $name, bool $move): ?string
{
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) return null;
    $final = invSameFileIn($dir, $name, $src);
    if ($final === null) {
        $final = invArchiveFreeName($dir, $name);
        if (!@copy($src, "$dir/$final") || filesize("$dir/$final") !== filesize($src)) {
            @unlink("$dir/$final");
            return null;
        }
    }
    if ($move && realpath($src) !== realpath("$dir/$final")) @unlink($src);
    return $final;
}

/**
 * Store one file of a document: to the shop's archive on Z, or — Z unreachable — to the
 * waiting folder. ['where' => 'archive'|'pending', 'dir', 'name'], or ['error' => …].
 */
function invArchiveStore(string $shop, string $src, string $name, bool $move, ?int $time = null): array
{
    if (!is_file($src)) return ['error' => "הקובץ לא נמצא: " . basename($src)];
    if (invArchiveReachable()) {
        $dir = invArchiveDir($shop, $time);
        if (($n = invPlace($src, $dir, $name, $move)) !== null) return ['where' => 'archive', 'dir' => $dir, 'name' => $n];
    }
    $dir = invPendingDir($shop, $time);
    if (($n = invPlace($src, $dir, $name, $move)) !== null) return ['where' => 'pending', 'dir' => $dir, 'name' => $n];
    return ['error' => "לא ניתן לשמור את $name — לא ב-Z ולא בתיקיית ההמתנה"];
}

/** The files waiting for Z: [['shop', 'day' => 'Y-m-d', 'name', 'path']]. (old/ is not listed.) */
function invPendingList(): array
{
    $out = [];
    foreach (glob(INV_PENDING_DIR . '/*', GLOB_ONLYDIR) ?: [] as $shopDir) {
        $shop = basename($shopDir);
        if ($shop === 'old') continue;
        foreach (glob($shopDir . '/*', GLOB_ONLYDIR) ?: [] as $dayDir) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', basename($dayDir))) continue;
            foreach (scandir($dayDir) ?: [] as $f) {
                if (is_file("$dayDir/$f")) $out[] = ['shop' => $shop, 'day' => basename($dayDir), 'name' => $f, 'path' => "$dayDir/$f"];
            }
        }
    }
    return $out;
}

/** Move every waiting file to its day's folder on Z. ['moved' => n, 'left' => n, 'errors' => [...]]. */
function invPendingFlush(): array
{
    $list = invPendingList();
    if ($list && !invArchiveReachable()) return ['moved' => 0, 'left' => count($list), 'errors' => ['Z עדיין לא זמין']];
    $moved = 0;
    $errors = [];
    foreach ($list as $p) {
        $dir = invArchiveDir($p['shop'], strtotime($p['day'] . ' 12:00'));
        if (invPlace($p['path'], $dir, $p['name'], true) !== null) $moved++;
        else $errors[] = "{$p['shop']} / {$p['name']}: ההעברה נכשלה";
    }
    // the emptied folders
    foreach (glob(INV_PENDING_DIR . '/*', GLOB_ONLYDIR) ?: [] as $shopDir) {
        if (basename($shopDir) === 'old') continue;
        foreach (glob($shopDir . '/*', GLOB_ONLYDIR) ?: [] as $dayDir) @rmdir($dayDir);
        @rmdir($shopDir);
    }
    return ['moved' => $moved, 'left' => count($list) - $moved, 'errors' => $errors];
}

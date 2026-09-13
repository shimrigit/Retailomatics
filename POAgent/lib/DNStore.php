<?php
// DNStore.php — DataStore adapter (spec §7) for DN images/records in DNdir/.
// Stage 1 (spec §9 step 3): image import only — copy a chosen local photo
// into DNdir/, named per spec §6.3. OCR extraction (DNOcr, Stage 2) and
// writing the finalized DN JSON record (finalize(), Stage 3) land later.

require_once __DIR__ . '/filename_utils.php';

define('POAGENT_DNDIR', __DIR__ . '/../DNdir');

class DNStore
{
    private const ALLOWED_IMAGE_EXT = ['jpg', 'jpeg', 'png'];

    /**
     * Resolve the default folder the DN folder-browser opens in: the real
     * (possibly OneDrive-redirected) Desktop's "DN pictures" folder if it
     * exists, else the same folder name under a non-redirected Desktop,
     * else Desktop itself, else the user's home directory as a last resort
     * — so a machine that hasn't set up "DN pictures" yet still opens
     * somewhere sane instead of erroring.
     */
    public static function defaultBrowseDir(): string
    {
        $home = getenv('USERPROFILE') ?: getenv('HOME') ?: '';
        $candidates = [
            $home . '\\OneDrive\\Desktop\\DN pictures',
            $home . '\\Desktop\\DN pictures',
            $home . '\\OneDrive\\Desktop',
            $home . '\\Desktop',
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_dir($candidate)) {
                return $candidate;
            }
        }
        return $home !== '' ? $home : sys_get_temp_dir();
    }

    /**
     * Copy a chosen local image file into DNdir/, named per spec §6.3:
     * <PO_core_name>_DN_<ddmmyy-hhmmss>.<ext> — original extension kept
     * (real WhatsApp-sourced photos are .jpeg; see NPharmonized/process.php
     * for the same convention elsewhere in this codebase).
     *
     * Returns ['dn_core_name', 'image_path', 'image_filename'].
     */
    public static function importImage(string $poCoreName, string $sourcePath): array
    {
        // Same core-name whitelist convention as po_view.php's core_name
        // guard — PO core names are always plain sanitized segments, never
        // containing anything outside this set.
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $poCoreName)) {
            throw new InvalidArgumentException('Invalid PO core name');
        }
        if (!is_file($sourcePath)) {
            throw new RuntimeException("קובץ המקור לא נמצא: $sourcePath");
        }

        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_IMAGE_EXT, true)) {
            throw new RuntimeException(
                "סוג קובץ לא נתמך: .$ext (נתמכים: " . implode(', ', self::ALLOWED_IMAGE_EXT) . ')'
            );
        }

        [$dnCoreName, $destPath] = self::allocateDestPath($poCoreName, $ext);

        if (!copy($sourcePath, $destPath)) {
            throw new RuntimeException('שגיאה בהעתקת הקובץ ל-DNdir');
        }

        return [
            'dn_core_name'   => $dnCoreName,
            'image_path'     => $destPath,
            'image_filename' => basename($destPath),
        ];
    }

    /**
     * Mobile counterpart of importImage() — the photo arrives as an HTTP
     * upload (phone camera/gallery via dn_capture.php) instead of a path on
     * this machine's own filesystem. $file is one $_FILES[...] entry.
     * Same naming convention, same return shape, so the caller (dn_import.php)
     * doesn't need to know which path a given delivery came in through.
     */
    public static function importUploadedImage(string $poCoreName, array $file): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $poCoreName)) {
            throw new InvalidArgumentException('Invalid PO core name');
        }

        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('לא נבחרה תמונה');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException("שגיאה בהעלאת התמונה (קוד $error)");
        }
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('קובץ שהועלה לא תקין');
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_IMAGE_EXT, true)) {
            // A phone camera capture sometimes reports a filename with no
            // real extension (e.g. "image.tmp" / a bare blob name) — sniff
            // the actual image type from its bytes instead of rejecting a
            // perfectly good photo over a missing/odd name.
            $info = @getimagesize($tmpPath);
            $ext = match ($info[2] ?? null) {
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_PNG  => 'png',
                default        => $ext,
            };
        }
        if (!in_array($ext, self::ALLOWED_IMAGE_EXT, true)) {
            throw new RuntimeException(
                'סוג קובץ לא נתמך' . ($ext !== '' ? ": .$ext" : '')
                . ' (נתמכים: ' . implode(', ', self::ALLOWED_IMAGE_EXT) . ')'
            );
        }

        [$dnCoreName, $destPath] = self::allocateDestPath($poCoreName, $ext);

        if (!move_uploaded_file($tmpPath, $destPath)) {
            throw new RuntimeException('שגיאה בשמירת התמונה שהועלתה ל-DNdir');
        }

        return [
            'dn_core_name'   => $dnCoreName,
            'image_path'     => $destPath,
            'image_filename' => basename($destPath),
        ];
    }

    /**
     * Shared by importImage()/importUploadedImage() — the spec §6.3 naming
     * convention (<PO_core_name>_DN_<ddmmyy-hhmmss>.<ext>), plus a same-second
     * collision guard. Ensures DNdir/ exists.
     *
     * @return array{0: string, 1: string} [dn_core_name, dest_path]
     */
    private static function allocateDestPath(string $poCoreName, string $ext): array
    {
        if (!is_dir(POAGENT_DNDIR)) {
            mkdir(POAGENT_DNDIR, 0777, true);
        }

        $timestamp = date('dmy-His');
        $dnCoreName = "{$poCoreName}_DN_{$timestamp}";
        $destPath = POAGENT_DNDIR . "/{$dnCoreName}.{$ext}";

        if (file_exists($destPath)) {
            $dnCoreName .= '-' . substr(uniqid(), -4);
            $destPath = POAGENT_DNDIR . "/{$dnCoreName}.{$ext}";
        }

        return [$dnCoreName, $destPath];
    }

    /**
     * Write the finalized DN JSON record to DNdir/ (spec §6.3), alongside
     * the already-copied image from importImage(). Stage 3 (barcode
     * matching + manual review UI) is simplified for now — see caller
     * (dn_import.php) — but the write path itself is the real one the
     * eventual Review screen will also use.
     */
    public static function finalize(string $dnCoreName, array $data): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $dnCoreName)) {
            throw new InvalidArgumentException('Invalid DN core name');
        }
        $path = POAGENT_DNDIR . "/{$dnCoreName}.json";
        poagent_write_json_atomic($path, $data);
    }

    /**
     * All finalized DN records for a PO (spec §6.3 filename convention),
     * ordered oldest-first by extracted_at. Filename-glob based, same
     * convention as POStore::listPOs()/VSStore::listForPo() (spec §8.1).
     * Matches on "_DN_" only, so VS records ("_VS_...") for the same PO are
     * never picked up here.
     */
    public static function listForPo(string $poCoreName): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $poCoreName)) {
            return [];
        }
        $records = [];
        foreach (glob(POAGENT_DNDIR . "/{$poCoreName}_DN_*.json") as $path) {
            $data = json_decode(file_get_contents($path), true);
            if ($data !== null) {
                $records[] = $data;
            }
        }
        usort($records, fn($a, $b) => strcmp($a['extracted_at'] ?? '', $b['extracted_at'] ?? ''));
        return $records;
    }
}

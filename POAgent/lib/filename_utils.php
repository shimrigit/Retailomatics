<?php
// filename_utils.php — shared helpers for POAgent's filesystem-backed
// DataStore adapters (SupplierStore / POStore / future DNStore / VSStore).

/**
 * Sanitize a value before it's used as a filename segment: keeps only ASCII
 * letters, digits and "-". Everything else goes — underscores (the filename
 * field separator), spaces, Hebrew, and punctuation such as "." "(" "'" —
 * so the resulting core_name always matches the /^[A-Za-z0-9_-]+$/ whitelist
 * every page checks before opening a PO/DN (a Hebrew supplier like
 * "א.עקנין" used to leave ".." behind, making that PO unopenable). The raw
 * value is still stored in the JSON body — never reconstructed from the
 * sanitized filename (spec §6.2).
 */
function poagent_sanitize_segment(string $value): string
{
    $value = preg_replace('/[^A-Za-z0-9-]/', '', $value);
    return $value !== '' ? $value : 'x'; // guard against a fully-stripped value
}

/** Write JSON to $path via write-to-temp-then-rename (spec §8.6). */
function poagent_write_json_atomic(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $tmpPath = $path . '.tmp' . uniqid('', true);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($tmpPath, $json) === false) {
        throw new RuntimeException("Failed to write temp file: $tmpPath");
    }
    if (!rename($tmpPath, $path)) {
        @unlink($tmpPath);
        throw new RuntimeException("Failed to rename temp file into place: $path");
    }
}

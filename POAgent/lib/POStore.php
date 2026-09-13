<?php
// POStore.php — DataStore adapter (spec §7) for PO records in POdir/.
// Owns the only code path that renames a PO's status suffix (spec §8.7)
// and the atomic PO##### counter (spec §8.2).

require_once __DIR__ . '/filename_utils.php';

define('POAGENT_PODIR', __DIR__ . '/../POdir');

// The counter lives in its own directory, deliberately separate from
// POdir/ — POdir/ is gitignored (generated PO records, not source), and the
// counter must never disappear as a side effect of that (e.g. someone
// treating POdir/ as disposable and wiping it). If the counter file is ever
// lost/deleted anyway, nextPoId() below does NOT try to reconstruct it from
// existing PO records — it silently restarts at PO00001 (see
// POAgentNotes.md "what happens if the counter file gets deleted"). Worst
// case, restore it manually: create POcounter/.po_counter containing the
// last-used number as plain text.
define('POAGENT_COUNTER_DIR', __DIR__ . '/../POcounter');
define('POAGENT_PO_COUNTER_FILE', POAGENT_COUNTER_DIR . '/.po_counter');

// Cap on the human-readable name stored alongside generator_id (mobile POs
// only — desktop's generator_id, "user1"/"user2"/"user3", is already
// display-friendly and never gets a separate name). 40 chars: generous
// compared to WhatsApp's own profile-name limit (25 chars) — the closest
// "industry standard" reference, since this name usually originates as
// either a WhatsApp contact name or the apps.json allowlist nickname — but
// this renders on our own screens, not a WhatsApp UI element with WhatsApp's
// tighter space constraints, so there's no reason to match it exactly.
define('POAGENT_GENERATOR_NAME_MAX_CHARS', 40);

class POStore
{
    /** Atomically allocate the next PO##### id, e.g. "PO0004" (spec §8.2). */
    public static function nextPoId(): string
    {
        if (!is_dir(POAGENT_PODIR)) {
            mkdir(POAGENT_PODIR, 0777, true);
        }
        if (!is_dir(POAGENT_COUNTER_DIR)) {
            mkdir(POAGENT_COUNTER_DIR, 0777, true);
        }

        $fh = fopen(POAGENT_PO_COUNTER_FILE, 'c+');
        if ($fh === false) {
            throw new RuntimeException('Cannot open PO counter file');
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                throw new RuntimeException('Cannot lock PO counter file');
            }
            $current = (int) trim((string) stream_get_contents($fh));
            $next = $current + 1;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) $next);
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }

        return 'PO' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Create a PO: allocates the ID, builds the core name, writes the JSON
     * record (status "open"), returns the full record including core_name.
     *
     * $items: [ ['barcode'=>, 'name'=>, 'qty'=>, 'unit_price_agorot'=>], ... ]
     *
     * $generatorName is a human-readable display nickname (e.g. a WhatsApp
     * contact/allowlist name for a mobile-created PO) — display only, never
     * an identity: generator_id (phone number for mobile, "user1"/"user2"/
     * "user3" for desktop) stays the sole key used for matching/filtering
     * POs. Stored once at creation time (a snapshot, like every other field
     * here) so a later rename in apps.json doesn't rewrite history. Null/
     * blank (desktop, or no session name to pull from) falls back to
     * generator_id itself, which is already display-friendly there.
     */
    public static function createPO(string $generatorId, string $supplierId, array $items, ?string $generatorName = null): array
    {
        $poId = self::nextPoId();
        $timestamp = date('dmy-His');

        $userSeg = poagent_sanitize_segment($generatorId);
        $supplierSeg = poagent_sanitize_segment($supplierId);
        $coreName = "{$userSeg}_{$supplierSeg}_{$timestamp}_{$poId}";

        $generatorName = trim((string) $generatorName);
        if ($generatorName === '') {
            $generatorName = $generatorId;
        }
        $generatorName = mb_substr($generatorName, 0, POAGENT_GENERATOR_NAME_MAX_CHARS);

        $record = [
            'unique_id'      => $poId,
            'core_name'      => $coreName,
            'generator_id'   => $generatorId, // raw value kept in JSON body, never reconstructed from filename (§6.2)
            'generator_name' => $generatorName, // display only — see the doc comment above
            'supplier_id'    => $supplierId,
            'date_generated' => date('c'),
            'status'         => 'open',
            'items'          => $items,
        ];

        $path = POAGENT_PODIR . "/{$coreName}_open.json";
        poagent_write_json_atomic($path, $record);

        return $record;
    }

    /**
     * List PO records, optionally filtered by generator/status.
     * Filename-glob based — the naming convention IS the index (spec §8.1).
     */
    public static function listPOs(?string $generatorId = null, ?string $status = null): array
    {
        $userSeg = $generatorId !== null ? poagent_sanitize_segment($generatorId) : '*';
        $statusSeg = $status ?? '*';
        $pattern = POAGENT_PODIR . "/{$userSeg}_*_*_PO*_{$statusSeg}.json";

        $records = [];
        foreach (glob($pattern) as $path) {
            $data = json_decode(file_get_contents($path), true);
            if ($data !== null) {
                $records[] = $data;
            }
        }

        // Newest first for the history/status view.
        usort($records, fn($a, $b) => strcmp($b['date_generated'] ?? '', $a['date_generated'] ?? ''));
        return $records;
    }

    /** Sum of qty * unit_price_agorot across a PO record's line items. */
    public static function totalAgorot(array $po): int
    {
        $total = 0;
        foreach ($po['items'] ?? [] as $item) {
            $total += (int) ($item['qty'] ?? 0) * (int) ($item['unit_price_agorot'] ?? 0);
        }
        return $total;
    }

    /** Load a PO by its stable core name, regardless of current status suffix (spec §6.2). */
    public static function loadByCoreName(string $coreName): ?array
    {
        foreach (glob(POAGENT_PODIR . "/{$coreName}_*.json") as $path) {
            $data = json_decode(file_get_contents($path), true);
            if ($data !== null) {
                return $data;
            }
        }
        return null;
    }

    /**
     * Rename a PO's status suffix (open/prcv/closed/cancelled) — the ONLY
     * code path allowed to change it (spec §8.7). Core name is unaffected,
     * so nothing referencing it by core name ever breaks.
     */
    public static function setStatus(string $coreName, string $newStatus): void
    {
        $current = null;
        foreach (glob(POAGENT_PODIR . "/{$coreName}_*.json") as $path) {
            $current = $path;
            break;
        }
        if ($current === null) {
            throw new RuntimeException("PO not found: $coreName");
        }

        $data = json_decode(file_get_contents($current), true);
        $data['status'] = $newStatus;

        $newPath = POAGENT_PODIR . "/{$coreName}_{$newStatus}.json";
        poagent_write_json_atomic($newPath, $data);
        if ($newPath !== $current) {
            unlink($current);
        }
    }
}

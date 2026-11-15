<?php
// CatalogIndex.php — every supplier's price list in one JSON file, for fast
// search from WhatsApp (the bot answers within a message; a WhatsApp Flow
// endpoint must answer within 10 seconds — reading the xlsx files each time
// is far too slow).
//
// The index is built from SupplierStore (the only reader of SuppliersDB/) and
// kept in POAgent/cache/catalog_index.json (git-ignored). A supplier whose
// file is new or changed (size / modified time) is read again on the next
// use; a removed one drops out. Building from scratch reads every file once
// (tools/build_catalog_index.php does it ahead of time).
//
// Items: [barcode, name, price_agorot, normalized name]. Search (spec
// "אפיון בנצ'מרק", shared infrastructure §3): normalized text, every word of
// the query must appear; rank = exact > start of the name > start of a word >
// anywhere; a full barcode matches directly.

require_once __DIR__ . '/SupplierStore.php';

if (!defined('POAGENT_CATALOG_INDEX_FILE')) define('POAGENT_CATALOG_INDEX_FILE', __DIR__ . '/../cache/catalog_index.json');

class CatalogIndex
{
    private static ?array $data = null;

    /** Hebrew-friendly search form: no quotes / geresh / dots / dashes, final letters as regular, single spaces. */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = str_replace(['״', '"', '׳', "'", '`', '.', '-', '־'], '', $s);
        $s = strtr($s, ['ם' => 'מ', 'ן' => 'נ', 'ץ' => 'צ', 'ף' => 'פ', 'ך' => 'כ']);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** All supplier names in the index. */
    public static function suppliers(): array
    {
        return array_keys(self::data());
    }

    /**
     * Suppliers whose name matches $q, best first: [name, ...] (all of them —
     * the caller shows the first few and can say how many there were).
     */
    public static function searchSuppliers(string $q): array
    {
        $q = self::normalize($q);
        if ($q === '') return [];
        $words = explode(' ', $q);
        $hits = [];
        foreach (array_keys(self::data()) as $name) {
            $rank = self::rank(self::normalize($name), $q, $words);
            if ($rank !== null) $hits[] = [$rank, mb_strlen($name), $name];
        }
        sort($hits);
        return array_column($hits, 2);
    }

    /**
     * A supplier's items matching $q, best first: [['barcode','name','price_agorot'], ...].
     * Digits: the item whose full barcode it is first, then those whose barcode ends
     * with them (6 digits or more), then any name matches.
     */
    public static function searchItems(string $supplierId, string $q): array
    {
        $items = self::data()[$supplierId]['items'] ?? [];
        $qn = self::normalize($q);
        if ($qn === '') return [];
        $words = explode(' ', $qn);
        // Digits: the full barcode (leading zeros ignored), or its end — at least the last 6
        // digits, typed from a label. A code of 6 digits or fewer is matched whole.
        $digits = preg_match('/^\d{4,}$/', $qn) ? ltrim($qn, '0') : null;
        $tail = preg_match('/^\d{6,}$/', $qn) ? $qn : null;
        $hits = [];
        foreach ($items as $it) {
            $rank = match (true) {
                $digits !== null && ltrim($it[0], '0') === $digits                                 => -2,
                $tail !== null && strlen($it[0]) > strlen($tail) && str_ends_with($it[0], $tail) => -1,
                default                                                                            => self::rank($it[3], $qn, $words),
            };
            if ($rank !== null) $hits[] = [$rank, mb_strlen($it[1]), $it[1], $it];
        }
        usort($hits, fn($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        return array_map(fn($h) => self::item3($h[3]), $hits);
    }

    /**
     * searchItems, forgiving — for a product written from memory (the free list, method D):
     *   1. what searchItems finds, in its order;
     *   2. then singular / plural: every word matched by its stem at the start of a word ("עגבניות" finds "עגבניה", "בצלים" finds "בצל");
     *   3. only when 1 and 2 found nothing: one letter missing, extra or wrong in a word of 4+ letters
     *      ("מלפון" finds "מלפפון"), fewest slips first.
     * Digits alone (a barcode) get searchItems only.
     */
    public static function searchItemsLoose(string $supplierId, string $q): array
    {
        $strict = self::searchItems($supplierId, $q);
        $qn = self::normalize($q);
        if ($qn === '' || preg_match('/^\d+$/', $qn)) return $strict;
        $words = explode(' ', $qn);
        $stems = array_map([self::class, 'stem'], $words);
        $have = array_flip(array_column($strict, 'barcode'));
        $byStem = $rest = [];
        foreach (self::data()[$supplierId]['items'] ?? [] as $it) {
            if (isset($have[$it[0]])) continue;
            $all = true;
            foreach ($stems as $s) {
                if (!self::startsWord($it[3], $s)) { $all = false; break; }
            }
            if ($all) $byStem[] = [mb_strlen($it[1]), $it[1], $it]; else $rest[] = $it;
        }
        usort($byStem, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $out = array_merge($strict, array_map(fn($h) => self::item3($h[2]), $byStem));
        if ($out) return $out;

        $typo = [];
        foreach ($rest as $it) {
            $nameWords = explode(' ', $it[3]);
            $slips = 0;
            foreach ($words as $i => $w) {
                if (self::startsWord($it[3], $stems[$i])) continue;
                $near = false;
                if (mb_strlen($w) >= 4 && !preg_match('/\d/', $w)) {
                    foreach ($nameWords as $nw) {
                        if (self::within1($w, $nw) || self::within1($stems[$i], self::stem($nw))) { $near = true; break; }
                    }
                }
                if (!$near) continue 2;
                $slips++;
            }
            $typo[] = [$slips, mb_strlen($it[1]), $it[1], $it];
        }
        usort($typo, fn($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        return array_map(fn($h) => self::item3($h[3]), $typo);
    }

    /** A normalized Hebrew word without its plural / feminine ending (ים, ות, ה, ת) — when 3+ letters are left. */
    public static function stem(string $w): string
    {
        if (preg_match('/\d/', $w)) return $w;
        foreach (['ימ', 'ות', 'ה', 'ת'] as $end) {
            if (str_ends_with($w, $end)) {
                $s = mb_substr($w, 0, mb_strlen($w) - mb_strlen($end));
                return mb_strlen($s) >= 3 ? $s : $w;
            }
        }
        return $w;
    }

    /** Does a word of the (normalized) name start with $s? */
    private static function startsWord(string $name, string $s): bool
    {
        return str_starts_with($name, $s) || str_contains($name, " " . $s);
    }

    /** Are two words the same but for one letter (missing, extra, or different)? */
    private static function within1(string $a, string $b): bool
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        [$n, $m] = [count($x), count($y)];
        if (abs($n - $m) > 1 || min($n, $m) < 4) return false;   // shorter words: one letter is too much of the word
        $i = 0;
        while ($i < $n && $i < $m && $x[$i] === $y[$i]) $i++;
        if ($n === $m) return array_slice($x, $i + 1) === array_slice($y, $i + 1);
        return $n > $m ? array_slice($x, $i + 1) === array_slice($y, $i) : array_slice($x, $i) === array_slice($y, $i + 1);
    }

    /** One item of a supplier by barcode (leading zeros ignored), or null. */
    public static function item(string $supplierId, string $barcode): ?array
    {
        $key = ltrim(trim($barcode), '0');
        foreach (self::data()[$supplierId]['items'] ?? [] as $it) {
            if (ltrim($it[0], '0') === $key) return self::item3($it);
        }
        return null;
    }

    public static function exists(string $supplierId): bool
    {
        return isset(self::data()[$supplierId]);
    }

    public static function itemCount(string $supplierId): int
    {
        return count(self::data()[$supplierId]['items'] ?? []);
    }

    /** Builds / refreshes the index now; returns how many suppliers were (re)read. */
    public static function refresh(): int
    {
        self::$data = null;
        return self::load(true);
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** 0 exact, 1 start of the name, 2 every word at a word start, 3 every word anywhere; null = no match. */
    private static function rank(string $name, string $q, array $words): ?int
    {
        if ($name === $q) return 0;
        if (str_starts_with($name, $q)) return 1;
        $atStart = true;
        foreach ($words as $w) {
            $pos = mb_strpos($name, $w);
            if ($pos === false) return null;
            if ($pos !== 0 && !preg_match('/(^| )' . preg_quote($w, '/') . '/u', $name)) $atStart = false;
        }
        return $atStart ? 2 : 3;
    }

    private static function item3(array $it): array
    {
        return ['barcode' => $it[0], 'name' => $it[1], 'price_agorot' => $it[2]];
    }

    private static function data(): array
    {
        if (self::$data === null) self::load(false);
        return self::$data;
    }

    /** Loads the index; reads again the suppliers whose file changed. Returns how many were read. */
    private static function load(bool $force): int
    {
        $file = POAGENT_CATALOG_INDEX_FILE;
        $index = json_decode((string) @file_get_contents($file), true) ?: [];
        $stamps = [];
        foreach (SupplierStore::listSuppliers() as $name) {
            $path = SupplierStore::pathFor($name);
            $stamps[$name] = filesize($path) . '/' . filemtime($path);
        }
        $stale = array_keys(array_filter($stamps, fn($st, $name) => $force || ($index[$name]['stamp'] ?? null) !== $st, ARRAY_FILTER_USE_BOTH));
        if (!$stale && count($index) === count($stamps)) {
            self::$data = $index;
            return 0;
        }
        // One builder at a time; another request that was building meanwhile may have done it.
        @mkdir(dirname($file), 0777, true);
        $lock = fopen($file . '.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $index = json_decode((string) @file_get_contents($file), true) ?: [];
            $read = 0;
            $fresh = [];
            foreach ($stamps as $name => $st) {
                if (!$force && ($index[$name]['stamp'] ?? null) === $st) {
                    $fresh[$name] = $index[$name];
                    continue;
                }
                $items = [];
                $seen = [];
                foreach (SupplierStore::getCatalog($name) as $it) {
                    if ($it['barcode'] === '' || isset($seen[$it['barcode']])) continue;   // a barcode twice in one list: the first
                    $seen[$it['barcode']] = true;
                    $items[] = [$it['barcode'], $it['name'], $it['price_agorot'], self::normalize($it['name'])];
                }
                $fresh[$name] = ['stamp' => $st, 'items' => $items];
                $read++;
            }
            $tmp = $file . '.tmp';
            file_put_contents($tmp, json_encode($fresh, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            rename($tmp, $file);
            self::$data = $fresh;
            return $read;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

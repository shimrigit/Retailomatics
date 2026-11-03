<?php
// SupplierStore.php — DataStore adapter (spec §7) for supplier catalogs.
// Filesystem-backed today (SuppliersDB/<supplier name>.xlsx); this is the
// only place that knows that detail, so swapping to a Priority API later
// touches only this file, not callers.
//
// Naming convention: the file name IS the supplier name, exactly as shown
// everywhere in POAgent (screens, PO records, reports). Files are named with
// the pure supplier name — no "מחירון" prefix, no "בע״מ":
//   "פנזה שש שיווק.xlsx"  → supplier "פנזה שש שיווק"
// (The Priority export names them "מחירון <name> בע״מ.xlsx"; they were
// renamed once to this convention — rename new exports the same way.)
// Only files directly in SuppliersDB/ count (sub-folders such as Temp/ are
// ignored), and Excel lock files (~$...) and the names in
// POAGENT_SUPPLIER_IGNORE (not suppliers) are skipped.
//
// Columns are located by their header text in row 1, not by position:
//   barcode — "ברקוד" / "Barcode"
//   name    — "שם מוצר" / "שם פריט" / "ItemName"
//   price   — "מחיר מחירון" / "מחיר" / "Price"
// (the Priority export has ברקוד | שם מוצר | ספק מועדף | מספר ספק מועדף |
// מחיר מחירון; legacy files have Barcode | ItemName | Price). Files without
// recognizable headers fall back to A/B/C.

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!defined('POAGENT_SUPPLIERS_DIR')) {
    define('POAGENT_SUPPLIERS_DIR', __DIR__ . '/../SuppliersDB');
}

// Files in SuppliersDB/ that are not supplier catalogs, in case they're
// dropped in alongside the price lists.
const POAGENT_SUPPLIER_IGNORE = [
    'דוח ספקים',              // summary report from the Priority export
    'ללא ספק מועדף',          // items with no preferred supplier — not a supplier
    'מחירון ללא ספק מועדף',
];

class SupplierStore
{
    /** Supplier name → catalog file path, cached per request. */
    private static ?array $index = null;

    /** List supplier names available in SuppliersDB/, derived from filenames. */
    public static function listSuppliers(): array
    {
        $names = array_keys(self::index());
        sort($names, SORT_STRING);
        return $names;
    }

    /**
     * Read a supplier's item catalog.
     * Returns: [ ['barcode' => string, 'name' => string, 'price_agorot' => int], ... ]
     */
    public static function getCatalog(string $supplierId): array
    {
        if (!self::exists($supplierId)) {
            throw new RuntimeException("Unknown supplier: $supplierId");
        }

        $sheet = IOFactory::load(self::pathFor($supplierId))->getActiveSheet();
        [$colBarcode, $colName, $colPrice] = self::findColumns($sheet);

        $items = [];
        $highestRow = $sheet->getHighestDataRow();

        for ($row = 2; $row <= $highestRow; $row++) { // row 1 = headers
            $barcode = trim((string)$sheet->getCell("$colBarcode$row")->getValue());
            $name    = trim((string)$sheet->getCell("$colName$row")->getValue());
            $priceRaw = $sheet->getCell("$colPrice$row")->getValue();

            if ($barcode === '' && $name === '') {
                continue; // skip blank rows
            }

            $items[] = [
                'barcode'      => $barcode,
                'name'         => $name,
                // Price column is stored in whole currency units (shekels);
                // convert to integer agorot here so everything downstream
                // (PO, VS) works in exact integer agorot per spec §4.4/§8.3.
                'price_agorot' => (int) round(((float)$priceRaw) * 100),
            ];
        }

        return $items;
    }

    /**
     * The supplier's Priority supplier number, read from the catalog's
     * "מספר ספק מועדף" column (same value on every row of the Priority
     * export — the first non-empty one is used). Null if the column or
     * value is missing (e.g. hand-made catalogs without it).
     */
    public static function getPrioritySupplierNumber(string $supplierId): ?string
    {
        if (!self::exists($supplierId)) {
            return null;
        }
        $sheet = IOFactory::load(self::pathFor($supplierId))->getActiveSheet();
        $lastIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn(1));
        for ($i = 1; $i <= $lastIdx; $i++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            if (!str_contains((string)$sheet->getCell("{$col}1")->getValue(), 'מספר ספק')) {
                continue;
            }
            for ($row = 2, $last = $sheet->getHighestDataRow(); $row <= $last; $row++) {
                $value = $sheet->getCell("$col$row")->getValue();
                if ($value !== null && trim((string)$value) !== '') {
                    return is_float($value) ? sprintf('%.0f', $value) : trim((string)$value);
                }
            }
        }
        return null;
    }

    /**
     * True if the supplier exists in the store. Only names that came from an
     * actual file listing are accepted, so a posted supplier value can never
     * be turned into an arbitrary file path.
     */
    public static function exists(string $supplierId): bool
    {
        return isset(self::index()[$supplierId]);
    }

    public static function pathFor(string $supplierId): string
    {
        $index = self::index();
        if (!isset($index[$supplierId])) {
            throw new RuntimeException("Unknown supplier: $supplierId");
        }
        return $index[$supplierId];
    }

    /** Supplier name = the catalog file's name without ".xlsx". */
    public static function nameFromFilename(string $filename): string
    {
        return trim(preg_replace('/\.xlsx$/i', '', $filename));
    }

    /** Builds (once per request) the supplier name → file path map. */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }
        $index = [];
        foreach (glob(POAGENT_SUPPLIERS_DIR . '/*.xlsx') ?: [] as $path) {
            $file = basename($path);
            if (str_starts_with($file, '~$')) {
                continue; // Excel lock file of an open workbook
            }
            $name = self::nameFromFilename($file);
            if ($name === '' || in_array($name, POAGENT_SUPPLIER_IGNORE, true)) {
                continue;
            }
            $index[$name] = $path;
        }
        return self::$index = $index;
    }

    /** Returns [barcodeCol, nameCol, priceCol] letters, located by row-1 header text. */
    private static function findColumns($sheet): array
    {
        $found = ['barcode' => null, 'name' => null, 'price' => null];
        $lastCol = $sheet->getHighestDataColumn(1);
        $lastIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($lastCol);

        for ($i = 1; $i <= $lastIdx; $i++) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $header = mb_strtolower(trim((string)$sheet->getCell("{$col}1")->getValue()));
            if ($header === '') {
                continue;
            }
            if ($found['barcode'] === null && (str_contains($header, 'ברקוד') || str_contains($header, 'barcode'))) {
                $found['barcode'] = $col;
            } elseif ($found['name'] === null && (str_contains($header, 'שם מוצר') || str_contains($header, 'שם פריט')
                    || str_contains($header, 'itemname') || $header === 'name')) {
                $found['name'] = $col;
            } elseif (str_contains($header, 'מחיר') || str_contains($header, 'price')) {
                // Prefer "מחיר מחירון" (list price) if there are several price columns.
                if ($found['price'] === null || str_contains($header, 'מחירון')) {
                    $found['price'] = $col;
                }
            }
        }

        return [$found['barcode'] ?? 'A', $found['name'] ?? 'B', $found['price'] ?? 'C'];
    }
}

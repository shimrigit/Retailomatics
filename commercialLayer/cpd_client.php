<?php
/**
 * CPD (Chain Price Database) client — a drop-in replacement for
 * CHPHeadlessClient (chp_headless_client.php).
 *
 * Reads the chains' official price files that C:\RetailAgents\PriceTransparency
 * downloads daily (update.js → data/cpd.sqlite; Tel Aviv stores only).
 * searchPrices() returns the same shape as chp_scraper.js, so code that parses
 * CHP results works unchanged:
 *   productInfo  "<name> (יצרן/מותג: <brand>, ברקוד: <barcode>)"
 *   productName  "<name>, <size>"
 *   prices       [{store, address, price}]   (price as a "12.90" string)
 * plus CPD's own fields (barcode, supplier, size, displayName, aiName, source).
 *
 * The name: the AI name (cpd_namer.php — CHP style "product, size, brand")
 * when the caller has one, else the longest chain name + size. The chains'
 * "manufacturer" field is mostly the legal supplier / importer, not the brand,
 * so it isn't put into the name.
 */

define('CPD_DB_PATH', 'C:/RetailAgents/PriceTransparency/data/cpd.sqlite');
define('CPD_CHAINS_PATH', 'C:/RetailAgents/PriceTransparency/chains.json');
// A store this many days behind the newest data gets a warning (update.js hides it at 14).
define('CPD_WARN_DAYS', 3);

class CPDClient {
    private $db = null;
    private $chainNames = [];
    public $error = null;

    public function __construct() {
        try {
            $this->db = new PDO('sqlite:' . CPD_DB_PATH, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            ]);
        } catch (Throwable $e) {
            $this->error = 'מאגר CPD לא נמצא (' . CPD_DB_PATH . ') — הרץ node update.js ב-C:\RetailAgents\PriceTransparency';
        }
        foreach ((json_decode((string) @file_get_contents(CPD_CHAINS_PATH), true)['chains'] ?? []) as $c) {
            $this->chainNames[$c['key']] = $c['name'];
        }
    }

    public function available() {
        return $this->db !== null;
    }

    public function chainName($key) {
        return $this->chainNames[$key] ?? $key;
    }

    /** Date (Y-m-d) of the newest store data, or null. */
    public function lastUpdated() {
        if (!$this->db) return null;
        return $this->db->query('SELECT MAX(updated) FROM stores')->fetchColumn() ?: null;
    }

    /**
     * The stores' freshness, measured against the newest data in CPD (not the
     * calendar — a week without pressing "update" isn't a store problem):
     *   total / today   active stores, and how many have today's prices
     *   newest          the newest store's date
     *   behind          active stores with older prices than newest: [{chain, name, updated, days}]
     *   warn            those CPD_WARN_DAYS or more days behind (a store, or a whole chain, not publishing)
     *   hidden          stores update.js hid (closed / silent 14 days): [{chain, name, reason, since}]
     */
    public function coverage() {
        $out = ['total' => 0, 'today' => 0, 'newest' => null, 'behind' => [], 'warn' => [], 'hidden' => []];
        if (!$this->db) return $out;
        $stores = $this->db->query('SELECT * FROM stores ORDER BY updated, chain')->fetchAll(PDO::FETCH_ASSOC);
        $active = array_filter($stores, fn($s) => ($s['active'] ?? 1) == 1);
        $out['newest'] = $active ? max(array_column($active, 'updated')) : null;
        foreach ($stores as $s) {
            if (($s['active'] ?? 1) != 1) {
                $out['hidden'][] = ['chain' => $this->chainName($s['chain']), 'name' => $s['name'],
                    'reason' => $s['inactive_reason'], 'since' => $s['inactive_since']];
                continue;
            }
            $out['total']++;
            if (substr((string) $s['updated'], 0, 10) === date('Y-m-d')) $out['today']++;
            $days = (int) round((strtotime($out['newest']) - strtotime($s['updated'])) / 86400);
            if ($days >= 1) {
                $row = ['chain' => $this->chainName($s['chain']), 'name' => $s['name'], 'updated' => $s['updated'], 'days' => $days];
                $out['behind'][] = $row;
                if ($days >= CPD_WARN_DAYS) $out['warn'][] = $row;
            }
        }
        return $out;
    }

    /** True when the data isn't from today, or some stores are CPD_WARN_DAYS+ days behind. */
    public function isStale() {
        $c = $this->coverage();
        return !$c['newest'] || $c['newest'] < date('Y-m-d') || $c['warn'] !== [];
    }

    /** The product card (products row) for a barcode, or null. */
    public function card($barcode) {
        if (!$this->db) return null;
        $barcode = trim((string) $barcode);
        $q = $this->db->prepare('SELECT * FROM products WHERE barcode = ?');
        // Chains sometimes write a barcode with its leading zeros dropped.
        foreach (array_unique([$barcode, ltrim($barcode, '0')]) as $key) {
            if ($key === '') continue;
            $q->execute([$key]);
            if ($card = $q->fetch(PDO::FETCH_ASSOC)) return $card;
        }
        return null;
    }

    /** What each chain calls the product: [{chain, name, manufacturer, quantity, unit, unit_of_measure}]. */
    public function variants($cpdBarcode) {
        if (!$this->db) return [];
        $q = $this->db->prepare('SELECT chain, name, manufacturer, quantity, unit, unit_of_measure FROM variants WHERE barcode = ?');
        $q->execute([$cpdBarcode]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Same signature as CHPHeadlessClient::searchPrices(). $city is ignored —
     * CPD holds the Tel Aviv stores only. $aiName: ['product','size','brand']
     * from CPDNamer, if the caller has it.
     * Returns false when the database can't be read; a barcode that isn't in
     * CPD returns an empty result (no productInfo, no prices), like CHP did.
     */
    public function searchPrices($barcode, $city = null, $aiName = null) {
        if (!$this->db) return false;
        $empty = ['productInfo' => '', 'productName' => '', 'prices' => [], 'source' => 'CPD'];
        $card = $this->card($barcode);
        if (!$card) return $empty;

        $q = $this->db->prepare('SELECT p.chain, p.store_id, p.price, s.name store, s.address FROM current_prices p
            LEFT JOIN stores s ON s.chain = p.chain AND s.store_id = p.store_id
            WHERE p.barcode = ? AND p.price > 0 AND COALESCE(s.active, 1) = 1 ORDER BY p.price, p.chain');
        $q->execute([$card['barcode']]);
        $prices = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $prices[] = [
                'store'   => $this->chainName($p['chain']) . ' – ' . ($p['store'] ?: $p['store_id']),
                'address' => (string) $p['address'],
                'price'   => number_format((float) $p['price'], 2, '.', ''),
                'chain'   => $p['chain'],
            ];
        }

        // Not sold in Tel Aviv: the national range (catalog.js, all stores in the country)
        // as two "stores", so callers that take min / max keep working; 'national' marks it.
        $national = null;
        if (!$prices) {
            try {
                $q = $this->db->prepare('SELECT * FROM national_prices WHERE barcode = ?');
                $q->execute([$card['barcode']]);
                $national = $q->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {}   // no catalog run yet
            if ($national) {
                $national['chains'] = implode(', ', array_map([$this, 'chainName'], explode(',', $national['chains'])));
                foreach (array_unique([$national['min'], $national['max']]) as $p) {
                    $prices[] = ['store' => "ארצי – {$national['stores']} סניפים ({$national['chains']})", 'address' => '',
                        'price' => number_format((float) $p, 2, '.', ''), 'chain' => ''];
                }
            }
        }

        if (!empty($aiName['product'])) {
            $productName = $aiName['size'] !== '' ? "{$aiName['product']}, {$aiName['size']}" : $aiName['product'];
            $brand = $aiName['brand'];
        } else {
            $name = trim(preg_replace('/\s+/u', ' ', $card['name']));
            $hasSize = preg_match('/\d+(\.\d+)?\s*(מ"?ל|מל|ל\'?|ליטר|גר?ם?|ג\'|ק"?ג|קג)(?![א-ת])/u', $name);
            $productName = ($card['size'] && !$hasSize) ? "{$name}, {$card['size']}" : $name;
            $brand = '';
        }
        // The brand goes inside "(יצרן/מותג: …, ברקוד: …)" — a comma in it would cut it short.
        $brand = trim(str_replace(',', ' ', $brand));
        $productInfo = $brand !== ''
            ? "{$productName} (יצרן/מותג: {$brand}, ברקוד: {$card['barcode']})"
            : "{$productName} (ברקוד: {$card['barcode']})";

        return [
            'productInfo'  => $productInfo,
            'productName'  => $productName,
            'prices'       => $prices,
            'barcode'      => $card['barcode'],
            'brand'        => $brand,
            'supplier'     => (string) $card['manufacturer'],
            'size'         => (string) $card['size'],
            'displayName'  => (string) $card['display_name'],
            'aiName'       => !empty($aiName['product']),
            'national'     => $national,   // set when the prices are the national range, not Tel Aviv's
            'source'       => 'CPD',
        ];
    }
}

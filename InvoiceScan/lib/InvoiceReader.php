<?php
/**
 * InvoiceReader.php — Retailomatics Invoice Scan: one supplier document (photos or a PDF,
 * as they came) read whole by the AI — no crop rectangles, no per-supplier prompt.
 *
 *   InvoiceReader::read($paths, $shopName)   → what is printed (supplier, number, date, rows, totals)
 *   InvoiceReader::matchSupplier($read)      → the suppliers.json entry, by ע.מ. first, else the AI's sure pick
 *   InvoiceReader::detectMethod($items)      → Simple / LineTotal / Discount1 / Discount2, by which
 *                                              formula the rows actually satisfy (the OCRsanity methods)
 *
 * The model returns what is printed and never calculates; every check is done here.
 * Key: AIocr/config.json (the one the old flow uses).
 */

class InvoiceReader
{
    public const MODEL = 'gpt-5.5';
    public const METHODS = ['Simple', 'LineTotal', 'Discount1', 'Discount2'];
    // The fields of a row, in the order they are written to the OCRjson table ("1", "2", ...).
    public const FIELDS = ['Barcode' => 'barcode', 'ItemName' => 'description', 'Qty' => 'qty', 'UnitPrice' => 'unit_price',
        'LineTotal' => 'line_total', 'Discount1' => 'discount1_pct', 'Discount2' => 'discount2_pct'];

    public static function suppliersFile(): string
    {
        return __DIR__ . '/../../suppliers.json';
    }

    public static function suppliers(): array
    {
        return json_decode((string) file_get_contents(self::suppliersFile()), true) ?: [];
    }

    /**
     * @param string[] $paths  the document's pages in order: images (jpg / png), or one PDF
     * @return array{data: array, seconds: float, usage: array, model: string}
     */
    public static function read(array $paths, string $shopName): array
    {
        $ch = self::request($paths, $shopName);
        $response = curl_exec($ch);
        $out = self::parse($ch, $response);
        curl_close($ch);
        return $out;
    }

    /**
     * Several documents at once, each read on its own (a few requests side by side).
     * @param array<string, string[]> $docs  key => the document's pages
     * @return array<string, array>  key => what read() returns, or ['error' => message]
     */
    public static function readMany(array $docs, string $shopName, int $parallel = 6): array
    {
        $out = [];
        foreach (array_chunk($docs, $parallel, true) as $batch) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($batch as $key => $paths) {
                try {
                    $handles[$key] = self::request($paths, $shopName);
                    curl_multi_add_handle($mh, $handles[$key]);
                } catch (Throwable $e) {
                    $out[$key] = ['error' => $e->getMessage()];
                }
            }
            do {
                curl_multi_exec($mh, $running);
                if ($running) curl_multi_select($mh, 1.0);
            } while ($running > 0);
            foreach ($handles as $key => $ch) {
                try {
                    $out[$key] = self::parse($ch, curl_multi_getcontent($ch));
                } catch (Throwable $e) {
                    $out[$key] = ['error' => $e->getMessage()];
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }
        return $out;
    }

    /**
     * Pages that were read one by one and turned out to be one document: the header from the
     * first page that has each field, the rows of all pages in order, each total from the last
     * page that prints it.
     * @param array[] $reads  what read() returned for each page, in page order
     */
    public static function merge(array $reads): array
    {
        $data = $reads[0]['data'];
        $seconds = 0.0;
        foreach ($reads as $i => $r) {
            $seconds += (float) ($r['seconds'] ?? 0);
            if ($i === 0) continue;
            $d = $r['data'];
            foreach (['doc_type_printed', 'supplier_name_printed', 'supplier_vat_id', 'supplier_key', 'doc_number', 'doc_date'] as $k) {
                if ($data[$k] === '' && $d[$k] !== '') $data[$k] = $d[$k];
            }
            if ($data['supplier_match'] !== 'high' && $d['supplier_match'] === 'high') {
                $data['supplier_match'] = 'high';
                $data['supplier_key'] = $d['supplier_key'];
            }
            foreach ($d['columns'] as $k => $v) if ($data['columns'][$k] === '' && $v !== '') $data['columns'][$k] = $v;
            foreach ($d['totals'] as $k => $v) if ($v !== null) $data['totals'][$k] = $v;
            $data['items'] = array_merge($data['items'], $d['items']);
            $data['unreadable'] = array_merge($data['unreadable'], array_map(fn($u) => 'עמוד ' . ($i + 1) . ': ' . $u, $d['unreadable']));
            if ($d['notes'] !== '') $data['notes'] = trim($data['notes'] . ' · עמוד ' . ($i + 1) . ': ' . $d['notes'], ' ·');
        }
        $data['rows_printed'] = null;   // each page states its own count, if any
        return ['data' => $data, 'seconds' => round($seconds, 1), 'usage' => [], 'model' => $reads[0]['model'] ?? self::MODEL, 'merged_pages' => count($reads)];
    }

    /** The request for one document, ready to run. */
    private static function request(array $paths, string $shopName): CurlHandle
    {
        $apiKey = json_decode((string) @file_get_contents(__DIR__ . '/../../AIocr/config.json'), true)['openai_api_key'] ?? '';
        if ($apiKey === '') throw new RuntimeException('openai_api_key missing in AIocr/config.json');

        $content = [['type' => 'text', 'text' => count($paths) === 1 ? 'Read this document:'
            : 'These ' . count($paths) . ' images are the pages of ONE document, in order. Read it as one document: all the rows of all pages in "items" (page 1 first), the totals from wherever they are printed:']];
        foreach ($paths as $p) {
            $bytes = file_get_contents($p);
            if ($bytes === false) throw new RuntimeException('Cannot read ' . basename($p));
            $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
            if ($ext === 'pdf') {
                $content[] = ['type' => 'file', 'file' => ['filename' => 'document.pdf', 'file_data' => 'data:application/pdf;base64,' . base64_encode($bytes)]];
            } else {
                $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => "data:$mime;base64," . base64_encode($bytes), 'detail' => 'high']];
            }
        }
        $payload = [
            'model' => self::MODEL,
            'messages' => [['role' => 'system', 'content' => self::prompt($shopName)], ['role' => 'user', 'content' => $content]],
            'response_format' => ['type' => 'json_object'],
            'max_completion_tokens' => 24000,
            'reasoning_effort' => 'low',
        ];
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 300, CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS => json_encode($payload)]);
        return $ch;
    }

    /** The answer of a finished request, checked and normalized. */
    private static function parse(CurlHandle $ch, $response): array
    {
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $seconds = round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME), 1);
        if (!is_string($response) || $response === '') throw new RuntimeException('The reading request failed: ' . curl_error($ch));
        $result = json_decode($response, true);
        $data = json_decode((string) ($result['choices'][0]['message']['content'] ?? ''), true);
        if ($status !== 200 || !is_array($data)) {
            throw new RuntimeException('The reading failed: ' . ($result['error']['message'] ?? "HTTP $status"));
        }
        return ['data' => self::normalize($data), 'seconds' => $seconds, 'usage' => $result['usage'] ?? [], 'model' => self::MODEL];
    }

    /** Every key present, numbers as numbers or null — whatever the model left out. */
    private static function normalize(array $d): array
    {
        $num = fn($v) => is_numeric($v) ? (float) $v : null;
        $str = fn($v) => $v === null ? '' : trim((string) $v);
        $items = [];
        foreach ((array) ($d['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $items[] = [
                'barcode' => $str($it['barcode'] ?? ''), 'description' => $str($it['description'] ?? ''),
                'qty' => $num($it['qty'] ?? null), 'unit_price' => $num($it['unit_price'] ?? null),
                'discount1_pct' => $num($it['discount1_pct'] ?? null), 'discount2_pct' => $num($it['discount2_pct'] ?? null),
                'line_total' => $num($it['line_total'] ?? null),
            ];
        }
        $totals = [];
        foreach (['before_discount', 'discount', 'before_vat', 'vat', 'total'] as $k) $totals[$k] = $num($d['totals'][$k] ?? null);
        $columns = [];
        foreach (self::FIELDS as $key) $columns[$key] = $str($d['columns'][$key] ?? '');
        return [
            'doc_type' => $str($d['doc_type'] ?? 'other'), 'doc_type_printed' => $str($d['doc_type_printed'] ?? ''),
            'supplier_name_printed' => $str($d['supplier_name_printed'] ?? ''),
            'supplier_vat_id' => preg_replace('/\D/', '', $str($d['supplier_vat_id'] ?? '')),
            'supplier_key' => $str($d['supplier_key'] ?? ''), 'supplier_match' => $str($d['supplier_match'] ?? 'none'),
            'doc_number' => $str($d['doc_number'] ?? ''), 'doc_date' => $str($d['doc_date'] ?? ''),
            'columns' => $columns, 'items' => $items, 'totals' => $totals,
            'rows_printed' => $num($d['rows_printed'] ?? null),
            'unreadable' => array_values(array_filter(array_map($str, (array) ($d['unreadable'] ?? [])))),
            'notes' => $str($d['notes'] ?? ''),
            // the page as printed ("עמוד 2 מתוך 3"), and a page without the letterhead / document header (the rest of a table)
            'page_number' => is_numeric($d['page']['number'] ?? null) ? (int) $d['page']['number'] : null,
            'page_of' => is_numeric($d['page']['of'] ?? null) ? (int) $d['page']['of'] : null,
            'continuation' => !empty($d['continuation']),
        ];
    }

    private static function prompt(string $shopName): string
    {
        $list = [];
        foreach (self::suppliers() as $s) {
            $list[] = $s['supplierName'] . ' | ' . ($s['hebrewName'] ?? '') . (empty($s['vatId']) ? '' : ' | ע.מ. ' . $s['vatId'])
                . (empty($s['aliases']) ? '' : ' | also printed as: ' . implode(' ; ', (array) $s['aliases']));
        }
        $list = implode("\n", $list);
        $year = date('Y');
        return <<<PROMPT
You read a document an Israeli supermarket got from a supplier (tax invoice, delivery note, credit / returns note), printed in Hebrew — photographed or scanned — and return what is printed on it as JSON.

Return exactly this shape:
{
  "doc_type": "invoice" | "delivery_note" | "credit_or_return" | "other",
  "doc_type_printed": "<the document's title as printed, e.g. חשבונית מס, תעודת משלוח>",
  "supplier_name_printed": "<the name of the company that ISSUED the document, as printed>",
  "supplier_vat_id": "<the issuer's עוסק מורשה / ח.פ. number, digits only, or null>",
  "supplier_key": "<the key (first column) of the issuer in the supplier list below, or null when it is not there>",
  "supplier_match": "high" | "medium" | "low" | "none",
  "doc_number": "<the document's number as printed, or null>",
  "doc_date": "<the document's date, YYYY-MM-DD, or null>",
  "columns": { "barcode": "<the printed header of the column you took barcode from, or empty>", "description": "...", "qty": "...", "unit_price": "...", "discount1_pct": "...", "discount2_pct": "...", "line_total": "..." },
  "rows_printed": <the number of item rows the document itself states ("סה"כ שורות", "פריטים"), or null>,
  "items": [ { "barcode": "<see rules>", "description": "<as printed>", "qty": <number or null>, "unit_price": <number or null>, "discount1_pct": <number or null>, "discount2_pct": <number or null>, "line_total": <number or null> } ],
  "totals": { "before_discount": <number or null>, "discount": <number or null>, "before_vat": <number or null>, "vat": <number or null>, "total": <number or null> },
  "unreadable": [ "<what you could not read with confidence, and where>" ],
  "notes": "<anything a checker should know: handwriting, a cut-off page, two quantity columns, prices with or without VAT, etc.>",
  "page": { "number": <the page number as printed (עמוד / דף 2 מתוך 3, 2/3), or null>, "of": <the number of pages as printed, or null> },
  "continuation": <true when this page continues a document begun on another page: no letterhead / document title / number at the top, the table goes on, or the totals are only here; else false>
}

Rules:
- Copy numbers digit by digit as printed, including minus signs. Never calculate, complete or round a number. Not printed or not readable → null, and say so in "unreadable".
- Every item row of the table, in order, none skipped and none added; keep all the keys in every item. Count the rows on the page before answering and check that "items" has that many; a printed row count must agree.
- "barcode": the product's barcode (EAN, usually 13 digits starting 729) when the row prints one; otherwise the supplier's item code as printed. Digits and letters exactly, no spaces or asterisks around it.
- "qty": the number of units supplied in the row (if there are both cartons and units columns, the units; mention the other in "notes").
- "unit_price": the price of one unit as printed in the row. "line_total": the row's total as printed.
- "discount1_pct" / "discount2_pct": the row's discount percentages when the table has one / two discount columns, else null. A discount printed as an amount, not a percent, goes to "notes", not here.
- "columns": for each field, the header text of the printed column it came from — so a person can check you took the right column. Empty string for a field the table does not have.
- "totals.total": the document's final amount to pay. A printed "total" that is a count of units (delivery notes without prices) is not money: leave it null and say so in "notes".
- Supplier and customer: the supplier is the company in the letterhead / logo at the top. The customer is the supermarket the document is addressed to (after "לכבוד" / "שם לקוח"; this shop: {$shopName}). The customer is NEVER the supplier, and its ע.מ. is never supplier_vat_id. If the supplier appears only as a logo, read the name from the logo.
- "supplier_match": "high" when the printed name or ע.מ. clearly is that list entry; "none" when no entry fits (then supplier_key is null). Do not force a match.
- Dates are printed day/month/year (dd/mm/yy or dd/mm/yyyy), and these documents are from around {$year}: "09/11/26" is 2026-11-09. "doc_date" is the document's own date (תאריך / תאריך חשבונית / תאריך אסמכתא) — not a due or value date (תאריך ערך, לתשלום עד) and not the print time.

The supplier list (key | Hebrew name | known ע.מ. | other printed names):
{$list}
PROMPT;
    }

    /**
     * The suppliers.json entry of the document's issuer and how it was found:
     * ['supplier' => entry|null, 'by' => 'vat' | 'ai' | '']. The ע.מ. is trusted over the name.
     */
    public static function matchSupplier(array $read): array
    {
        $suppliers = self::suppliers();
        $vat = $read['supplier_vat_id'] ?? '';
        if ($vat !== '') {
            foreach ($suppliers as $s) {
                if (!empty($s['vatId']) && preg_replace('/\D/', '', (string) $s['vatId']) === $vat) return ['supplier' => $s, 'by' => 'vat'];
            }
        }
        if (($read['supplier_match'] ?? '') === 'high') {
            foreach ($suppliers as $s) {
                if ($s['supplierName'] === ($read['supplier_key'] ?? '')) return ['supplier' => $s, 'by' => 'ai'];
            }
        }
        return ['supplier' => null, 'by' => ''];
    }

    /** Does a row satisfy a method's formula? null = the row has no numbers to check. */
    public static function rowFits(array $it, string $method): ?bool
    {
        [$q, $p, $t] = [$it['qty'], $it['unit_price'], $it['line_total']];
        if ($q === null || $p === null || $t === null) return null;
        $d1 = (float) ($it['discount1_pct'] ?? 0);
        $d2 = (float) ($it['discount2_pct'] ?? 0);
        if (($method === 'Discount2') && ($d1 >= 100 || $d2 >= 100)) return false;
        $calc = match ($method) {
            'LineTotal' => $q * $p,
            'Discount1' => $q * $p * (1 - $d1 / 100),
            'Discount2' => $q * $p / (1 - $d1 / 100) / (1 - $d2 / 100),   // the old flow's formula (Osem)
            default     => $t,
        };
        return abs(abs($calc) - abs($t)) <= max(0.1, abs($t) * 0.002);
    }

    /**
     * The OCRsanity method the rows fit: ['method', 'fit' => [method => rows that fit], 'checked' => rows with numbers].
     * No prices at all → Simple. Otherwise the simplest method that fits the most rows.
     */
    public static function detectMethod(array $items): array
    {
        $fit = ['LineTotal' => 0, 'Discount1' => 0, 'Discount2' => 0];
        $checked = 0;
        $hasD1 = $hasD2 = false;
        foreach ($items as $it) {
            if (self::rowFits($it, 'LineTotal') === null) continue;
            $checked++;
            if (($it['discount1_pct'] ?? null) !== null) $hasD1 = true;
            if (($it['discount2_pct'] ?? null) !== null) $hasD2 = true;
            foreach (array_keys($fit) as $m) if (self::rowFits($it, $m)) $fit[$m]++;
        }
        if ($checked === 0) return ['method' => 'Simple', 'fit' => $fit, 'checked' => 0];
        $method = 'LineTotal';
        if ($hasD1 && $fit['Discount1'] > $fit[$method]) $method = 'Discount1';
        if ($hasD2 && $fit['Discount2'] > $fit[$method]) $method = 'Discount2';
        return ['method' => $method, 'fit' => $fit, 'checked' => $checked];
    }

    /**
     * The document's total WITHOUT VAT — the number the rows should add up to — and the sum of
     * the rows: ['sum', 'key', 'value', 'equal'].
     * Of the printed totals without VAT (before the discount = the top number of the totals box,
     * and before VAT) the one the rows' sum equals; if neither, the top one. The amount to pay
     * (with VAT) is used only when the document prints no other total.
     */
    public static function compareTotal(array $read): array
    {
        $sum = 0.0;
        foreach ($read['items'] as $it) $sum += (float) ($it['line_total'] ?? 0);
        $sum = round($sum, 2);
        $equal = fn($v) => $v !== null && abs(abs($v) - abs($sum)) <= 0.06;
        $t = $read['totals'];
        foreach (['before_discount', 'before_vat'] as $k) {
            if ($equal($t[$k] ?? null)) return ['sum' => $sum, 'key' => $k, 'value' => $t[$k], 'equal' => true];
        }
        foreach (['before_discount', 'before_vat', 'total'] as $k) {
            if (($t[$k] ?? null) !== null) return ['sum' => $sum, 'key' => $k, 'value' => $t[$k], 'equal' => $equal($t[$k])];
        }
        return ['sum' => $sum, 'key' => '', 'value' => null, 'equal' => false];
    }
}

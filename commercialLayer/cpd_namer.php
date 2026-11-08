<?php
/**
 * CPDNamer — one clean product name per barcode, in CHP's style
 * ("<product>, <size>, <brand>"), written by AI from all the chains' names
 * for that barcode in CPD (the chains cut names at ~20 characters, abbreviate,
 * and their "manufacturer" field is mostly the legal supplier, not the brand).
 *
 * Each barcode is named once and kept in data/cpd_names.sqlite as its parts
 * (product, size, brand) — the base for other naming styles later (per customer).
 * Uses the OpenAI key NP already uses (AIocr/config.json).
 */

require_once __DIR__ . '/cpd_client.php';

define('CPD_NAMES_DB_PATH', 'C:/RetailAgents/PriceTransparency/data/cpd_names.sqlite');
define('CPD_NAMER_MODEL', 'gpt-4.1');
define('CPD_NAMER_BATCH', 25);

class CPDNamer {
    private $cpd;
    private $db = null;
    public $error = null;
    public $usage = ['calls' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0];

    public function __construct(CPDClient $cpd) {
        $this->cpd = $cpd;
        try {
            $this->db = new PDO('sqlite:' . CPD_NAMES_DB_PATH, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->db->exec('PRAGMA busy_timeout = 5000');
            $this->db->exec('CREATE TABLE IF NOT EXISTS ai_names (barcode TEXT PRIMARY KEY, product TEXT, size TEXT, brand TEXT,
                sources TEXT, model TEXT, created TEXT)');
        } catch (Throwable $e) {
            $this->db = null;
            $this->error = 'לא ניתן לפתוח את ' . CPD_NAMES_DB_PATH . ': ' . $e->getMessage();
        }
    }

    /**
     * Names for these barcodes: [input barcode => ['product','size','brand']].
     * Barcodes already named come from the cache; the rest go to AI in batches.
     * Barcodes not in CPD are left out. On an AI failure the barcodes are left
     * out and $this->error says why (the caller falls back to the chain name).
     */
    public function names(array $barcodes) {
        if (!$this->db || !$this->cpd->available()) return [];
        $out = $todo = [];
        $get = $this->db->prepare('SELECT product, size, brand FROM ai_names WHERE barcode = ?');
        foreach (array_unique(array_map('trim', $barcodes)) as $input) {
            $card = $this->cpd->card($input);
            if (!$card) continue;
            $get->execute([$card['barcode']]);
            if ($row = $get->fetch(PDO::FETCH_ASSOC)) {
                $out[$input] = $row;
            } else {
                $todo[$card['barcode']][] = $input;
            }
        }
        foreach (array_chunk(array_keys($todo), CPD_NAMER_BATCH) as $batch) {
            foreach ($this->nameBatch($batch) as $cpdBarcode => $parts) {
                foreach ($todo[$cpdBarcode] as $input) $out[$input] = $parts;
            }
        }
        return $out;
    }

    private function nameBatch(array $cpdBarcodes) {
        $config = json_decode((string) @file_get_contents(__DIR__ . '/../AIocr/config.json'), true);
        $apiKey = $config['openai_api_key'] ?? null;
        if (empty($apiKey)) { $this->error = 'מפתח OpenAI לא מוגדר (AIocr/config.json)'; return []; }

        $items = [];
        foreach ($cpdBarcodes as $bc) {
            $card = $this->cpd->card($bc);
            $variants = $this->cpd->variants($bc);
            $items[] = [
                'barcode'   => (string) $bc,
                'names'     => array_values(array_unique(array_filter(array_map(fn($v) => trim(preg_replace('/\s+/u', ' ', $v['name'])), $variants)))),
                'suppliers' => array_values(array_unique(array_filter(array_map(fn($v) => trim($v['manufacturer']), $variants),
                    fn($m) => $m !== '' && !preg_match('/^(לא ידוע|unknown|,|0|-)$/iu', $m)))),
                'size'      => (string) ($card['size'] ?? ''),
            ];
        }

        $system = <<<'PROMPT'
You name supermarket products in Hebrew for an Israeli grocery store's product file.
For each barcode you get the names that several chains use for the same product (often cut off at ~20 characters, abbreviated, or with codes), the chains' "supplier" field (usually the legal company or importer, NOT the brand), and a size taken from the chains' unit fields (may be empty or wrong).

Return for each barcode:
- "product": a full Hebrew product description as a shopper reads it: the product line / model name, the product type, and EVERY distinguishing detail that any of the names shows — flavor, scent, variety, fat %, protein amount ("40 גרם חלבון"), ללא לקטוז / ללא סוכר / דל שומן, כתית מעולה, מנופה, מסורתית, for whom (לתינוק, לגבר), size letter S/M/L, percent. Take the most complete name as the base and add details found only in other names; repair cut-off words and abbreviations (ט. → בטעם, ש. → שוקולד, ללתס → ללא לקטוז). Keep the product line name even when it starts with the brand (מולר פרוטאין, ריטר ספורט, קוקה קולה, מילקי, צ'יטוס, פיצי, טיון). Don't shorten for its own sake — a longer, complete name is better than a short one that loses a detail. Pack words (שלישייה/רביעייה/שישייה/מארז) only when the names say so. No size (it goes in "size"), no promo words, no store codes, no "PC"/"יבוא מקביל", no stray symbols.
- "size": "<number> <unit>" using גרם, ק"ג, מ"ל, ליטר, יחידות. Multipacks as "<count> * <each>", e.g. "10 * 20 גרם", "3 * 290 גרם" for a שלישייה of 290 גרם. A size written in the names wins over the "size" field (that field is sometimes wrong); when the names show no size, use the "size" field. Empty string for a single item ("1 יחידות"/"1 יח'") or when unknown.
- "brand": the consumer brand printed on the product (e.g. עלית, שוופס, קנור, דאב, תלמה, יטבתה, פרג, אסם, תנובה, ריטר ספורט, מיסטר בראוני, אקס). Never a company, importer or distributor (e.g. יפאורה תבורי, יוניליוור, שטראוס גרופ, שטראוס פריטו ליי, אנטר אי אס, זנלכל, נטו מלינדה, לרו, מגדלנס לזארו, פרוקטר & גמבל, מרס, BAT, דיפלומט are companies) — the supplier field is almost always a company. The brand is not the product line either: for a sub-brand give its maker's consumer brand when you are sure of it (תפוצ'יפס, צ'יטוס, דוריטוס, מילקי → עלית; פיצי → תלמה; ננסים → שטראוס). If unsure, "".
Use only what the inputs show (plus brand knowledge you are sure of); don't invent flavors, details or sizes (e.g. never add "0% שומן" unless a name says it), and don't drop a size that the names show. Write Hebrew, brands too when they have a common Hebrew form (דורקס, ג'ילט, סקיטלס, פאל מאל — not Durex, Gillette); keep Latin only for brands usually written in Latin (WIN, UFC, ZIP, B&D).

Examples of the wanted style (product, size, brand):
מילקי אצבעות שוקולד חלב במילוי וופל | 108 גרם | עלית
צ'יטוס פאפס בטעם גבינה | 10 * 20 גרם | עלית
קוקטייל בטעם רום אבטיח | 330 מ"ל | שוופס
פסטה ברוטב עגבניות | 130 גרם | קנור
תחליב רחצה זוהר קטיפתי | 750 מ"ל | דאב
פטריות חתוכות שלישייה | 3 * 290 גרם | ויליגר
מרלבורו טיון |  | מרלבורו
מולר פרוטאין משקה חלבון בטעם וניל ללא לקטוז 40 גרם חלבון | 350 מ"ל | מולר
תרסיס שמן זית כתית מעולה | 150 מ"ל | יד מרדכי

Return ONLY valid JSON: {"items": [{"barcode": "...", "product": "...", "size": "...", "brand": "..."}, ...]} with every barcode given, in the same order.
PROMPT;

        $payload = [
            'model'           => CPD_NAMER_MODEL,
            'messages'        => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => json_encode(['items' => $items], JSON_UNESCAPED_UNICODE)],
            ],
            'max_tokens'      => 4000,
            'temperature'     => 0.0,
            'response_format' => ['type' => 'json_object'],
        ];
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!$response || $httpCode !== 200) {
            $err = json_decode((string) $response, true);
            $this->error = 'שגיאת OpenAI בשמות המוצרים: ' . ($err['error']['message'] ?? 'HTTP ' . $httpCode);
            return [];
        }
        $result = json_decode($response, true);
        $this->usage['calls']++;
        $this->usage['prompt_tokens']     += $result['usage']['prompt_tokens'] ?? 0;
        $this->usage['completion_tokens'] += $result['usage']['completion_tokens'] ?? 0;
        $ai = json_decode($result['choices'][0]['message']['content'] ?? '', true);
        if (!isset($ai['items']) || !is_array($ai['items'])) {
            $this->error = 'שגיאה בניתוח תשובת OpenAI בשמות המוצרים';
            return [];
        }

        $sources = array_column($items, null, 'barcode');
        $put = $this->db->prepare('INSERT OR REPLACE INTO ai_names (barcode, product, size, brand, sources, model, created) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $out = [];
        foreach ($ai['items'] as $it) {
            $bc = (string) ($it['barcode'] ?? '');
            $product = trim(preg_replace('/\s+/u', ' ', (string) ($it['product'] ?? '')));
            if (!isset($sources[$bc]) || $product === '') continue;   // a barcode AI didn't return / made up
            $parts = [
                'product' => $product,
                'size'    => trim((string) ($it['size'] ?? '')),
                'brand'   => trim((string) ($it['brand'] ?? '')),
            ];
            $put->execute([$bc, $parts['product'], $parts['size'], $parts['brand'],
                json_encode($sources[$bc], JSON_UNESCAPED_UNICODE), CPD_NAMER_MODEL, date('Y-m-d H:i:s')]);
            $out[$bc] = $parts;
        }
        return $out;
    }
}

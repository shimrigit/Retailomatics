<?php
// DNOcr.php — Stage 2 (spec §9 step 4): single-shot OpenAI vision OCR call
// on the whole DN photo. Deliberately NOT the harmonizedFlow pipeline (that
// one needs manual crop-marking + suppliers.json column-mapping + an xlsx
// verification screen — see POAgentNotes.md for why it wasn't reused
// as-is). Same API-call shape/model as harmonizedFlow/step3_process_ocr.php
// though, reusing the same AIocr/config.json key.

class DNOcr
{
    private const MODEL = 'gpt-4.1-mini';

    private const SYSTEM_PROMPT = <<<PROMPT
You are an OCR extraction system reading a photo of a Hebrew-language delivery
note. Extract the document reference number, supplier name, date, the full
items table, and the document's own printed grand total if one is visible.

The items table columns, right-to-left as they appear in the image, are
typically: ברקוד (barcode), שם פריט (item name), כמות (quantity), מחיר יח'
(unit price in ILS). There is often also a grand-total line/row near the
bottom, commonly labeled something like סה"כ or סה"כ להזמנה — read its
printed value into "dn_total" (do NOT calculate it yourself by summing the
rows — read the number as printed, even if it looks wrong).

Respond ONLY with JSON in exactly this shape, no extra text:
{
  "supplier_name": "string",
  "dn_number": "string",
  "dn_date": "string",
  "dn_total": number or null,
  "items": [
    {"barcode": "string", "name": "string", "qty": number, "unit_price": number}
  ]
}

Rules:
- Extract EVERY row of the items table, in order.
- "barcode" must be the digits exactly as shown — never add or drop leading zeros.
- "qty" and "unit_price" must be plain numbers (no currency symbols, no commas, no percent signs).
- "dn_total" must be the printed grand-total number if visible anywhere on the document, read
  as-is (no currency symbol); use JSON null if no grand total is visible anywhere in the image.
- If any other field cannot be read, use an empty string ("") for text fields or 0 for numbers —
  never omit the key.
PROMPT;

    /**
     * Send the DN photo to the vision model in a single call — no crop
     * marking, no per-region calls. Returns the decoded JSON per
     * SYSTEM_PROMPT's shape (keys always present, defensively normalized).
     * $imagePaths: one photo, or the pages of one DN in order (DNStore::pagePaths()) —
     * all of them in the same call, read as one document.
     */
    public static function extract(string|array $imagePaths): array
    {
        $imagePaths = array_values((array) $imagePaths);
        $configFile = __DIR__ . '/../../AIocr/config.json';
        if (!file_exists($configFile)) {
            throw new RuntimeException('AIocr/config.json not found');
        }
        $config = json_decode(file_get_contents($configFile), true);
        $apiKey = $config['openai_api_key'] ?? null;
        if (empty($apiKey)) {
            throw new RuntimeException('openai_api_key missing in AIocr/config.json');
        }

        $content = [['type' => 'text', 'text' => count($imagePaths) === 1
            ? 'Extract the delivery note data from this image:'
            : 'These ' . count($imagePaths) . ' images are the pages of ONE delivery note, in order. '
              . 'Extract its data as one document: the header from wherever it appears, ALL pages\' item rows '
              . 'in one "items" list (page 1 first), and the printed grand total (usually on the last page):']];
        foreach ($imagePaths as $imagePath) {
            $imageData = file_get_contents($imagePath);
            if ($imageData === false) {
                throw new RuntimeException("Cannot read DN image: $imagePath");
            }
            $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
            $mime = $ext === 'png' ? 'image/png' : 'image/jpeg';
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64," . base64_encode($imageData)]];
        }

        $payload = [
            'model' => self::MODEL,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $content],
            ],
            'max_tokens' => 4000,
            'temperature' => 0.0,
            'response_format' => ['type' => 'json_object'],
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '') {
            throw new RuntimeException("OCR request failed: $curlError");
        }

        $result = json_decode($response, true);
        if ($httpCode !== 200 || !isset($result['choices'][0]['message']['content'])) {
            $errorMsg = $result['error']['message'] ?? "HTTP $httpCode";
            throw new RuntimeException("OCR API error: $errorMsg");
        }

        $extracted = json_decode($result['choices'][0]['message']['content'], true);
        if ($extracted === null) {
            throw new RuntimeException('OCR response was not valid JSON');
        }

        // Normalize shape defensively — always return the expected keys,
        // regardless of what the model actually included. dn_total is
        // deliberately nullable (not defaulted to 0) — a missing/unreadable
        // total must stay distinguishable from a genuine total of zero, so
        // the total-verification checks (DiffEngine) know to skip rather
        // than false-flag when no total was ever visible on the document.
        $dnTotal = $extracted['dn_total'] ?? null;
        return [
            'supplier_name' => (string) ($extracted['supplier_name'] ?? ''),
            'dn_number'     => (string) ($extracted['dn_number'] ?? ''),
            'dn_date'       => (string) ($extracted['dn_date'] ?? ''),
            'dn_total'      => is_numeric($dnTotal) ? (float) $dnTotal : null,
            'items'         => is_array($extracted['items'] ?? null) ? $extracted['items'] : [],
        ];
    }
}

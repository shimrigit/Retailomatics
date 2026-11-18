<?php
// name_style.php — product names in the shop's own style (stage 2, when the
// shop's "שם מוצר לפי העדפת משתמש" is on). The shop's rule (namePrompt in
// configDir/np_shop_settings.json) is given to the AI as is, with the names in
// the usual style ("product, size, brand"); the AI only re-formats them.
//
// Guard: a styled name must keep the same numbers as the original (size, %,
// count) — otherwise the original name is kept and counted in 'kept'.

const NP_NAME_STYLE_MODEL = 'gpt-4.1';
const NP_NAME_STYLE_BATCH = 40;

/**
 * $names: [key => name]. Returns ['names' => [key => styled name] (every key;
 * the original where styling failed), 'kept' => [key, ...] (kept as is because
 * the AI changed a number or returned nothing), 'error' => string|null, 'usage' => [...]].
 */
function npStyleNames(array $names, string $prompt): array
{
    $out = ['names' => $names, 'kept' => [], 'error' => null, 'usage' => ['calls' => 0, 'tokens' => 0]];
    $todo = array_filter($names, fn($n) => trim((string) $n) !== '' && $n !== 'NA');
    if (!$todo || trim($prompt) === '') return $out;

    $config = json_decode((string) @file_get_contents(__DIR__ . '/../AIocr/config.json'), true);
    $apiKey = $config['openai_api_key'] ?? null;
    if (empty($apiKey)) { $out['error'] = 'מפתח OpenAI לא מוגדר (AIocr/config.json)'; return $out; }

    $system = "You re-format Hebrew supermarket product names to one shop's naming rule.\n"
        . "Each input name is in the form \"<product>, <size>, <brand>\" (the size or the brand may be missing).\n"
        . "Apply the shop's rule below. Change only the format (order, commas, separators) — never add, drop, translate or reword words, and keep every number exactly.\n\n"
        . "The shop's rule:\n" . trim($prompt) . "\n\n"
        . 'Return ONLY valid JSON: {"items": [{"id": "...", "name": "..."}, ...]} with every id given, in the same order.';

    foreach (array_chunk($todo, NP_NAME_STYLE_BATCH, true) as $batch) {
        $items = [];
        foreach ($batch as $key => $name) $items[] = ['id' => (string) $key, 'name' => (string) $name];
        $payload = [
            'model'           => NP_NAME_STYLE_MODEL,
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
            $out['error'] = 'שגיאת OpenAI בשמות לפי סגנון החנות: ' . ($err['error']['message'] ?? 'HTTP ' . $httpCode);
            $out['kept'] = array_merge($out['kept'], array_map('strval', array_keys($batch)));
            continue;
        }
        $result = json_decode($response, true);
        $out['usage']['calls']++;
        $out['usage']['tokens'] += ($result['usage']['total_tokens'] ?? 0);
        $ai = json_decode($result['choices'][0]['message']['content'] ?? '', true);
        $styled = [];
        foreach ($ai['items'] ?? [] as $it) $styled[(string) ($it['id'] ?? '')] = trim(preg_replace('/\s+/u', ' ', (string) ($it['name'] ?? '')));

        foreach ($batch as $key => $name) {
            $new = $styled[(string) $key] ?? '';
            if ($new !== '' && npNameNumbers($new) === npNameNumbers($name)) {
                $out['names'][$key] = $new;
            } else {
                $out['kept'][] = (string) $key;
            }
        }
    }
    return $out;
}

/** The numbers in a name, in order ("3 * 290 גרם 1.5%" → "3|290|1.5"). */
function npNameNumbers(string $name): string
{
    preg_match_all('/\d+(?:\.\d+)?/', $name, $m);
    return implode('|', $m[0]);
}

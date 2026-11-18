<?php
// dcl_classify_lib.php — department classification by AI (stage 3), used by
// stage_dcl.php when the page opens and by dcl_classify.php (the button on the
// final screen, for a shop with "ללא סיווג למחלקות").

/** [department name => number] from the shop's departments file. */
function npLoadDepartments(string $deptFile): array
{
    $lookup = [];
    foreach (json_decode((string) @file_get_contents($deptFile), true) ?? [] as $d) {
        if (isset($d['DepartmentName'], $d['DepartmentNumber'])) $lookup[$d['DepartmentName']] = $d['DepartmentNumber'];
    }
    return $lookup;
}

/**
 * Classifies product names into the given departments.
 * Returns ['byIndex' => [0-based index => ['department', 'note']], 'usage' => [...], 'error' => string|null].
 */
function npClassifyDepartments(array $productNames, array $deptNames): array
{
    $out = ['byIndex' => [], 'usage' => [], 'error' => null];
    $config = json_decode((string) @file_get_contents(__DIR__ . '/../AIocr/config.json'), true);
    $apiKey = $config['openai_api_key'] ?? null;
    if (empty($apiKey)) { $out['error'] = 'OpenAI API key not configured.'; return $out; }

    $productNames = array_values($productNames);
    $productList = implode("\n", array_map(fn($i, $n) => ($i + 1) . '. ' . $n, array_keys($productNames), $productNames));
    $deptList    = implode("\n", array_map(fn($i, $n) => ($i + 1) . '. ' . $n, array_keys($deptNames), $deptNames));

    $systemPrompt = <<<PROMPT
You are a supermarket product classification assistant.
You will receive a numbered list of products and a numbered list of supermarket departments.
Classify each product into the single most appropriate department.

Rules:
- The "department" field MUST exactly match one of the provided department names (copy verbatim).
- Maintain the same order as the input product list.
- For "note", write a very short reason (one sentence max).

Return ONLY valid JSON:
{"classifications": [{"index": 1, "product": "...", "department": "...", "note": "..."}, ...]}
PROMPT;

    $payload = [
        'model'           => 'gpt-4.1-mini',
        'messages'        => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => "Departments:\n{$deptList}\n\nProducts to classify:\n{$productList}"],
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
        $out['error'] = 'שגיאת OpenAI: ' . ($err['error']['message'] ?? 'HTTP ' . $httpCode);
        return $out;
    }
    $result = json_decode($response, true);
    $aiData = json_decode($result['choices'][0]['message']['content'] ?? '', true);
    if (!isset($aiData['classifications'])) { $out['error'] = 'שגיאה בניתוח תשובת OpenAI.'; return $out; }

    $out['usage'] = $result['usage'] ?? [];
    foreach ($aiData['classifications'] as $cl) {
        $idx = (int) ($cl['index'] ?? 0) - 1;
        if (!isset($productNames[$idx])) continue;
        $out['byIndex'][$idx] = ['department' => (string) ($cl['department'] ?? ''), 'note' => (string) ($cl['note'] ?? '')];
    }
    return $out;
}

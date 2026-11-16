<?php
// VoiceOrder.php — a dictated order (a WhatsApp voice message) → order lines.
// WhatsApp ordering method E (whatsapp/bench_e_voice.php).
//
// Two calls to OpenAI, with the key DNOcr already uses (AIocr/config.json):
//   1. speech → Hebrew text        (audio/transcriptions)
//   2. the text → {product, qty}   (chat/completions, JSON) — number words to digits,
//      "no, six" corrections applied, a size that is part of the name left in the name.
// Nothing is matched to the price list here: the lines go through the same
// matching as a typed list (bench_d_list.php), so a product is never invented.
//
// VoiceOrder::$fake — set by the tests: fn(string $bytes, string $mime): array
// returning what fromAudio() would; nothing is sent.

class VoiceOrder
{
    private const TRANSCRIBE_MODEL = 'gpt-4o-transcribe';
    private const LINES_MODEL      = 'gpt-4.1-mini';
    public const MAX_BYTES         = 8_000_000;   // a voice message of several minutes is well under this

    /** @var callable|null */
    public static $fake = null;

    private const LINES_PROMPT = <<<'PROMPT'
You get the Hebrew transcript of a voice message in which a shop worker dictates a purchase order to a supplier: product names and quantities, one after the other.
Return JSON: {"lines": [{"product": "<product as said>", "qty": <whole number or null>}]}
Rules:
- One line per product, in the order said.
- "product": the product's words as said, in Hebrew, without the quantity and without filler words ("תביא לי", "ועוד", "בבקשה", "יחידות", "קרטונים").
  A size or weight that describes the product stays in the name, in digits ("קולה ליטר וחצי" → "קולה 1.5", "קמח קילו" → "קמח 1 קג").
- "qty": how many to order, as a whole number ("חמש" → 5, "שתים עשרה" → 12, "שני" → 2). null when no quantity was said for that product.
- Two different products joined by "ו" are two lines, also when the first has no quantity ("מלפפונים ומרק בצל שלוש" → מלפפונים: null, מרק בצל: 3). Words that together name one product stay one line ("מלח ופלפל" is two products; "שמן זית" is one).
- A correction replaces what it corrects ("בצל חמש, לא, שש" → qty 6; "תמחק את הבצל" → no line).
- Do not add products that were not said, do not guess brand names, do not translate.
- Nothing that is an order in the transcript → {"lines": []}
PROMPT;

    /**
     * @return array{transcript: string, lines: array<int, array{text: string, qty: ?int}>}
     * @throws RuntimeException when a call fails
     */
    public static function fromAudio(string $bytes, string $mime): array
    {
        if (self::$fake !== null) return (self::$fake)($bytes, $mime);
        $transcript = self::transcribe($bytes, $mime);
        return ['transcript' => $transcript, 'lines' => $transcript === '' ? [] : self::lines($transcript)];
    }

    /** Speech → Hebrew text. $about: a few words on what is being said (helps the recognition). */
    public static function transcribe(string $bytes, string $mime, string $about = 'הזמנת סחורה מספק: שמות מוצרים וכמויות.'): string
    {
        if (self::$fake !== null) return (string) ((self::$fake)($bytes, $mime)['transcript'] ?? '');
        $ext = match (true) {
            str_contains($mime, 'ogg') || str_contains($mime, 'opus') => 'ogg',
            str_contains($mime, 'mpeg') || str_contains($mime, 'mp3') => 'mp3',
            str_contains($mime, 'mp4') || str_contains($mime, 'm4a') || str_contains($mime, 'aac') => 'm4a',
            str_contains($mime, 'wav')  => 'wav',
            str_contains($mime, 'webm') => 'webm',
            default => 'ogg',
        };
        $res = self::post('https://api.openai.com/v1/audio/transcriptions', [
            'model'    => self::TRANSCRIBE_MODEL,
            'language' => 'he',
            'prompt'   => $about,
            'file'     => new CURLStringFile($bytes, "voice.{$ext}", explode(';', $mime)[0] ?: 'audio/ogg'),
        ], false);
        return trim((string) ($res['text'] ?? ''));
    }

    /** The transcript as order lines: [['text' => product, 'qty' => int|null], ...]. */
    public static function lines(string $transcript): array
    {
        $res = self::post('https://api.openai.com/v1/chat/completions', [
            'model' => self::LINES_MODEL,
            'messages' => [
                ['role' => 'system', 'content' => self::LINES_PROMPT],
                ['role' => 'user', 'content' => $transcript],
            ],
            'max_tokens' => 3000,
            'temperature' => 0.0,
            'response_format' => ['type' => 'json_object'],
        ], true);
        $parsed = json_decode((string) ($res['choices'][0]['message']['content'] ?? ''), true);
        if (!is_array($parsed)) throw new RuntimeException('Voice order: the lines came back as invalid JSON');
        $out = [];
        foreach ((array) ($parsed['lines'] ?? []) as $l) {
            $text = trim((string) ($l['product'] ?? ''));
            if ($text === '') continue;
            $qty = isset($l['qty']) && is_numeric($l['qty']) && (int) $l['qty'] >= 1 && (int) $l['qty'] <= 9999 ? (int) $l['qty'] : null;
            $out[] = ['text' => $text, 'qty' => $qty];
        }
        return $out;
    }

    private static function post(string $url, array $payload, bool $json): array
    {
        $configFile = __DIR__ . '/../../AIocr/config.json';
        $apiKey = is_file($configFile) ? (json_decode((string) file_get_contents($configFile), true)['openai_api_key'] ?? null) : null;
        if (empty($apiKey)) throw new RuntimeException('openai_api_key missing in AIocr/config.json');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array_merge(['Authorization: Bearer ' . $apiKey], $json ? ['Content-Type: application/json'] : []),
            CURLOPT_POSTFIELDS => $json ? json_encode($payload) : $payload,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($response) || $response === '') throw new RuntimeException("Voice order request failed: $error");
        $result = json_decode($response, true);
        if ($status !== 200 || !is_array($result)) {
            throw new RuntimeException('Voice order API error: ' . ($result['error']['message'] ?? "HTTP $status"));
        }
        return $result;
    }
}

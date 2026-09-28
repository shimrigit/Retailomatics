<?php
// MessageFetching.php — subprocess of the NP (WhatsApp) process.
//
// Parses whatsapp_images/webhook_log.txt (where webhook.php logs every raw
// Meta webhook POST body), flattens every message object found across all
// logged payloads into one ordered list, assigns each a serial number in
// arrival order (1, 2, 3, ...), and downloads any image attachments while
// the Meta-hosted media URL is still valid (Meta only keeps it live for a
// few minutes).
//
// Image files are saved to whatsapp_images/ as:
//   ddmmyy-hhmmss X Y.ext
//     ddmmyy-hhmmss = the message's timestamp in Israel time (Asia/Jerusalem)
//     X             = serial number of the message among all logged objects
//     Y             = the numeric price extracted from the image's caption
//                     (any extra text alongside the number, e.g. "13.9 מבצע",
//                     is discarded — only the first number found is kept)
//   e.g. "050826-080931 2 13.9.jpeg"
//
// SERIES PLACEHOLDER: some store owners don't know the price at send time
// and write "סדרה" or "סידרה" (Hebrew for "product line/series" — price to
// be set later by hand, based on similar items already in that line)
// instead of a number. Anywhere a number would normally be accepted, that
// Hebrew word is accepted too (only when the text has NO number in it) and
// produces the placeholder "X" in the price slot instead of a number, e.g.
// "050826-080931 2 X.jpeg" (capital X, not the Hebrew word itself — kept
// out of the filename/code to avoid Hebrew-encoding issues in Windows and
// other tools downstream). This is a manual TODO marker, not a computed
// price: it must be replaced with a real number by the time the item
// reaches the CHP-price-check stage in NPharmonized — later stages there
// float-cast that column, which would silently turn an unresolved
// placeholder into 0.00 with no error. Honored identically in both modes
// below (via the shared resolveCaptionValue()).
//
// TWO MODES — must be chosen before anything runs (web: a chooser screen,
// `?mode=classic`/`?mode=advanced` once picked; CLI: first argv, e.g.
// `php MessageFetching.php advanced`). See resolveCaptionSourcesClassic()
// and resolveCaptionSourcesAdvanced() for the implementations.
//
// CLASSIC — every image is saved, price is borrowed from a nearby text
// when the image has no caption of its own, in three passes:
//   1. Forward  — the very next message, if it's a number-only text.
//   2. Backward — the immediately preceding message, if it's a number-only
//                 text AND it wasn't already claimed by pass 1 for a
//                 different (earlier) image.
//   3. By order — any image still uncaptioned after passes 1-2 is paired,
//                 in arrival order, with any number-only text still not
//                 consumed: the 1st leftover text with the 1st leftover
//                 image, the 2nd with the 2nd, and so on. This covers
//                 customers who send a batch of images and a separate batch
//                 of prices (same relative order, but the two batches can
//                 interleave arbitrarily — including all texts before all
//                 images — since large photos take longer to upload than
//                 short texts).
// Forward always resolves first, so a text sitting between two textless
// images goes to the one before it (the normal case), never both, and the
// backward pass only picks up texts nothing else already used — it exists
// specifically for the case where a caption's text message reaches the
// webhook slightly before its own image. Pass 3 is the last resort for
// whatever adjacency alone couldn't pair up.
//
// ADVANCED — an image is only saved if it resolves to a price/placeholder;
// otherwise it's skipped entirely (not downloaded), and counted separately
// in the final report. Two ways to resolve, no others:
//   1. Own caption — identical rule to classic (same resolveCaptionValue()).
//   2. A WhatsApp "reply" — a text message whose `context.id` (the
//      WhatsApp Cloud API's quoted-message reference) points at this
//      image's own message `id`. The reply's body is resolved exactly like
//      a caption (number, or the סדרה/סידרה placeholder).
// No forward/backward/positional borrowing here: a text that is neither an
// image's own caption nor a reply that specifically targets one image is
// ignored completely, even if it sits right next to an uncaptioned image.
//
// messages.jsonl is no longer produced — webhook_log.txt is the sole record.
//
// Run manually: php MessageFetching.php <classic|advanced>   (or open in a
// browser and pick a mode on the chooser screen)

$IMAGES_DIR  = __DIR__ . '/whatsapp_images';
$LOG_FILE    = $IMAGES_DIR . '/webhook_log.txt';
$CONFIG_FILE = __DIR__ . '/config.json';

define('SERIES_PLACEHOLDER', 'X');

$isWeb = PHP_SAPI !== 'cli';

// ── Mode must be chosen before any processing runs ─────────────────────────────
$mode = $isWeb ? ($_GET['mode'] ?? $_POST['mode'] ?? null) : ($argv[1] ?? null);
if ($mode !== 'classic' && $mode !== 'advanced') {
    renderModeChooser($isWeb);
    exit($isWeb ? 0 : 1);
}

// When run in a browser, PHP's output is treated as HTML: a bare "\n" in an
// echo doesn't start a new line and the default font isn't enlarged. Wrap the
// report in a <pre> block (one real line per echoed line) at double size.
// Plain CLI runs (php MessageFetching.php <mode>) are left as ordinary text.
if ($isWeb) {
    echo "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>MessageFetching (" . htmlspecialchars($mode) . ")</title></head><body>";
    echo "<pre style=\"font-size:2em; line-height:1.4;\">";
}

if (!is_file($LOG_FILE)) {
    if ($isWeb) {
        echo "No webhook_log.txt found at {$LOG_FILE}\n</pre></body></html>";
    } else {
        fwrite(STDERR, "No webhook_log.txt found at {$LOG_FILE}\n");
    }
    exit(1);
}

$config      = is_file($CONFIG_FILE) ? json_decode(file_get_contents($CONFIG_FILE), true) : [];
$accessToken = $config['meta_key'] ?? '';

$objects        = array_values(extractObjects($LOG_FILE));
$captionSources = $mode === 'advanced'
    ? resolveCaptionSourcesAdvanced($objects)
    : resolveCaptionSourcesClassic($objects);

$serial     = 0;
$downloaded = 0;
$skipped    = 0;
$failed     = 0;
$noReply    = 0; // advanced mode only: no own caption AND no matching reply
$count      = count($objects);

for ($i = 0; $i < $count; $i++) {
    $message = $objects[$i];
    $serial++;

    if (($message['type'] ?? '') !== 'image' || empty($message['image'])) {
        continue;
    }

    if ($mode === 'advanced' && !isset($captionSources[$i])) {
        $noReply++;
        echo "SKIPPED #{$serial}: no caption and no reply\n";
        continue;
    }

    $captionSource = $captionSources[$i] ?? resolveCaptionValue($message['image']['caption'] ?? '');

    $filename = buildImageFilename($message, $serial, $captionSource);
    $destPath = $IMAGES_DIR . '/' . $filename;

    if (is_file($destPath)) {
        $skipped++;
        continue;
    }

    if (downloadImage($message['image'], $accessToken, $destPath)) {
        $downloaded++;
        echo "Saved #{$serial}: {$filename}\n";
    } else {
        $failed++;
        echo "FAILED #{$serial}: could not download image (id=" . ($message['image']['id'] ?? '?') . ")\n";
    }
}

if ($mode === 'advanced') {
    $withPrice = $downloaded + $skipped + $failed;
    echo "Done (advanced mode). " . ($withPrice + $noReply) . " image message(s) in log: "
        . "{$withPrice} with a price or X ({$downloaded} downloaded, {$skipped} already present, {$failed} failed), "
        . "{$noReply} without a reply — skipped.\n";
} else {
    echo "Done. " . count($objects) . " message object(s) in log, {$downloaded} image(s) downloaded, "
        . "{$skipped} already present, {$failed} failed.\n";
}

if ($isWeb) {
    echo "</pre></body></html>";
}

/**
 * Read webhook_log.txt and return a flat, arrival-ordered list of every
 * message object found across all logged webhook payloads.
 */
function extractObjects(string $logFile): array {
    $messages = [];

    $lines = file($logFile, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $line) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} - (\{.*\})\s*$/', $line, $m)) {
            continue; // blank separator line or unrecognized content
        }

        $payload = json_decode($m[1], true);
        if (!is_array($payload)) {
            continue;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['messages'] ?? [] as $message) {
                    $messages[] = $message;
                }
            }
        }
    }

    return $messages;
}

/**
 * CLASSIC mode. Resolves the final price-slot VALUE to use for every image
 * in $objects, per index — already a resolved number or the
 * SERIES_PLACEHOLDER, never raw message text. A textless/priceless image
 * may borrow one from a text-only message (no image of its own) in three
 * passes — forward adjacency, then backward adjacency, then positional
 * matching by arrival order for whatever neither adjacency pass could pair
 * up. See the file-level comment above for why all three are needed, and
 * for the series-placeholder rule applied by resolveCaptionValue(). Every
 * image gets an entry here (possibly '' if nothing resolved) — classic mode
 * saves every image regardless.
 */
function resolveCaptionSourcesClassic(array $objects): array {
    $count          = count($objects);
    $captionSources = [];
    $consumedText   = []; // index => true, once a text message has been claimed

    // Pass 1: own caption, or borrow from the very next message.
    for ($i = 0; $i < $count; $i++) {
        $message = $objects[$i];
        if (($message['type'] ?? '') !== 'image' || empty($message['image'])) {
            continue;
        }

        $value = resolveCaptionValue($message['image']['caption'] ?? '');
        if ($value === '') {
            $next = $objects[$i + 1] ?? null;
            if ($next !== null && ($next['type'] ?? '') === 'text' && empty($next['image'])) {
                $nextValue = resolveCaptionValue($next['text']['body'] ?? '');
                if ($nextValue !== '') {
                    $value = $nextValue;
                    $consumedText[$i + 1] = true;
                }
            }
        }
        $captionSources[$i] = $value;
    }

    // Pass 2: for images still without a value, borrow from the
    // immediately preceding message — but only if pass 1 didn't already
    // claim it for a different (earlier) image.
    for ($i = 0; $i < $count; $i++) {
        $message = $objects[$i];
        if (($message['type'] ?? '') !== 'image' || empty($message['image'])) {
            continue;
        }
        if (($captionSources[$i] ?? '') !== '') {
            continue; // already resolved by pass 1
        }

        $prevIdx = $i - 1;
        $prev    = $objects[$prevIdx] ?? null;
        if ($prev !== null && empty($consumedText[$prevIdx])
            && ($prev['type'] ?? '') === 'text' && empty($prev['image'])) {
            $prevValue = resolveCaptionValue($prev['text']['body'] ?? '');
            if ($prevValue !== '') {
                $captionSources[$i]    = $prevValue;
                $consumedText[$prevIdx] = true;
            }
        }
    }

    // Pass 3: whatever adjacency (passes 1-2) couldn't pair up — e.g. a
    // batch of uncaptioned images and a separate batch of number-only (or
    // series-placeholder) texts that arrived in any interleaving relative
    // to each other — is matched purely by arrival order: the 1st leftover
    // text with the 1st leftover image, the 2nd with the 2nd, and so on.
    $remainingImages = [];
    for ($i = 0; $i < $count; $i++) {
        $message = $objects[$i];
        if (($message['type'] ?? '') !== 'image' || empty($message['image'])) {
            continue;
        }
        if (($captionSources[$i] ?? '') === '') {
            $remainingImages[] = $i;
        }
    }

    $remainingTexts = [];
    for ($i = 0; $i < $count; $i++) {
        if (!empty($consumedText[$i])) {
            continue;
        }
        $message = $objects[$i];
        if (($message['type'] ?? '') !== 'text' || !empty($message['image'])) {
            continue;
        }
        if (resolveCaptionValue($message['text']['body'] ?? '') !== '') {
            $remainingTexts[] = $i;
        }
    }

    $pairCount = min(count($remainingImages), count($remainingTexts));
    for ($k = 0; $k < $pairCount; $k++) {
        $imgIdx  = $remainingImages[$k];
        $textIdx = $remainingTexts[$k];
        $captionSources[$imgIdx] = resolveCaptionValue($objects[$textIdx]['text']['body'] ?? '');
        $consumedText[$textIdx]  = true;
    }

    return $captionSources;
}

/**
 * ADVANCED mode. An image is only included if it resolves to a price/
 * placeholder — from its own caption (identical rule to classic, via
 * resolveCaptionValue()), or otherwise from a WhatsApp "reply": a text
 * message whose `context.id` (the WhatsApp Cloud API's quoted-message
 * reference) matches this image's own message `id`. No forward/backward/
 * positional borrowing — a text that is neither an image's own caption nor
 * a reply targeting one specific image is ignored entirely.
 *
 * Returns index => value ONLY for images that resolved to something; a
 * missing index means "no caption, no reply" — the caller must skip that
 * image (not download it) and count it separately.
 *
 * If more than one reply targets the same image, the first one found (in
 * arrival order) wins; later duplicate replies to that image are ignored.
 */
function resolveCaptionSourcesAdvanced(array $objects): array {
    $count = count($objects);

    // Every image message's own WhatsApp id -> its index, so a reply's
    // `context.id` can be matched back to the image it targets.
    $imageIndexById = [];
    for ($i = 0; $i < $count; $i++) {
        $message = $objects[$i];
        if (($message['type'] ?? '') !== 'image' || empty($message['image'])) {
            continue;
        }
        if (!empty($message['id'])) {
            $imageIndexById[$message['id']] = $i;
        }
    }

    $replyTextForImage = [];
    for ($i = 0; $i < $count; $i++) {
        $message = $objects[$i];
        if (($message['type'] ?? '') !== 'text') {
            continue;
        }
        $targetId = $message['context']['id'] ?? null;
        if ($targetId === null || !isset($imageIndexById[$targetId])) {
            continue; // not a reply, or replying to something other than a logged image
        }
        $imgIdx = $imageIndexById[$targetId];
        if (!isset($replyTextForImage[$imgIdx])) {
            $replyTextForImage[$imgIdx] = $message['text']['body'] ?? '';
        }
    }

    $captionSources = [];
    foreach ($imageIndexById as $imgIdx) {
        $message = $objects[$imgIdx];
        $value   = resolveCaptionValue($message['image']['caption'] ?? '');
        if ($value === '' && isset($replyTextForImage[$imgIdx])) {
            $value = resolveCaptionValue($replyTextForImage[$imgIdx]);
        }
        if ($value !== '') {
            $captionSources[$imgIdx] = $value;
        }
    }

    return $captionSources;
}

/**
 * Prints the mode-selection screen (web: a chooser page; CLI: usage text
 * to stderr) and nothing else — the caller exits right after. Processing
 * never runs until a valid mode has been explicitly chosen.
 */
function renderModeChooser(bool $isWeb): void {
    if (!$isWeb) {
        fwrite(STDERR, "Usage: php MessageFetching.php <classic|advanced>\n\n");
        fwrite(STDERR, "  classic   Every image is saved. Price comes from its own caption, or is\n");
        fwrite(STDERR, "            borrowed from a nearby text message (forward, backward, or\n");
        fwrite(STDERR, "            matched by arrival order for a batch of images/texts).\n");
        fwrite(STDERR, "  advanced  Only images with their own caption or a WhatsApp reply are\n");
        fwrite(STDERR, "            saved. Any other text is ignored entirely.\n");
        return;
    }

    echo <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>MessageFetching — Choose Mode</title>
<style>
body { font-family: Arial, sans-serif; background:#f4f4f4; padding:60px; }
.card { background:#fff; border-radius:10px; padding:36px 48px; max-width:720px; margin:0 auto; box-shadow:0 2px 8px rgba(0,0,0,.12); }
h2 { margin-top:0; }
.opt { display:block; margin:18px 0 4px; padding:20px 24px; border-radius:8px; text-decoration:none; color:#fff; font-size:18px; }
.classic { background:#2575a8; }
.classic:hover { background:#1a5c87; }
.advanced { background:#5a8f3d; }
.advanced:hover { background:#456f2f; }
p.desc { color:#666; font-size:14px; margin:0 0 6px; }
</style>
</head><body><div class="card">
<h2>MessageFetching &mdash; choose a mode</h2>
<a class="opt classic" href="?mode=classic">Classic</a>
<p class="desc">Every image is saved. Price comes from its own caption, or is borrowed from a nearby text message (forward, backward, or matched by arrival order for a batch of images/texts).</p>
<a class="opt advanced" href="?mode=advanced">Advanced</a>
<p class="desc">Only images with their own caption or a WhatsApp reply are saved &mdash; any other text is ignored entirely.</p>
<p class="desc">"סדרה"/"סידרה" (&rarr; X) is honored the same way in both modes.</p>
</div></body></html>
HTML;
}

/**
 * Resolves one piece of raw message text (an image's own caption, or a
 * text-only message's body) to the final value that belongs in the price
 * slot: the first number found, or — only when there is NO number — the
 * SERIES_PLACEHOLDER ("X") if the text contains the Hebrew word
 * "סדרה"/"סידרה" (store owner deferring the price to a manual
 * product-line comparison). Returns '' when neither applies.
 */
function resolveCaptionValue(string $text): string {
    $number = extractNumericCaption($text);
    if ($number !== '') {
        return $number;
    }
    if (isSeriesPlaceholder($text)) {
        return SERIES_PLACEHOLDER;
    }
    return '';
}

/**
 * True if $text contains the Hebrew word for "series/product line" in
 * either common spelling: "סדרה" (defective) or "סידרה" (plene). Caller is
 * responsible for only treating this as a price substitute when $text has
 * no number (see resolveCaptionValue()).
 */
function isSeriesPlaceholder(string $text): bool {
    return (bool)preg_match('/ס(י)?דרה/u', $text);
}

function buildImageFilename(array $message, int $serial, string $captionSource): string {
    // Israel local time (IST/IDT, DST-aware). The timezone is set explicitly
    // because php.ini's date.timezone here is Europe/Berlin.
    $timestamp = (int)($message['timestamp'] ?? time());
    $stamp     = (new DateTime('@' . $timestamp))
        ->setTimezone(new DateTimeZone('Asia/Jerusalem'))
        ->format('dmy-His');

    // $captionSource is already a resolved value (a number or
    // SERIES_PLACEHOLDER) from resolveCaptionValue() — just make it
    // filename-safe, don't re-extract digits from it.
    $caption = sanitizeForFilename($captionSource);

    $ext = strtolower(explode('/', $message['image']['mime_type'] ?? 'image/jpeg')[1] ?? 'jpeg');
    $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'jpeg';

    $name = trim("{$stamp} {$serial} {$caption}");
    return $name . '.' . $ext;
}

/**
 * Captions are supposed to be just the sale price, but sometimes arrive with
 * extra descriptive text mixed in (e.g. "13.9 מבצע", "מחיר 24.9 כולל מעמ").
 * Keep only the first number found (digits with an optional decimal point)
 * and discard everything else. Returns '' if no number is present.
 */
function extractNumericCaption(string $caption): string {
    if (preg_match('/\d+(?:\.\d+)?/', $caption, $m)) {
        return $m[0];
    }
    return '';
}

function sanitizeForFilename(string $text): string {
    $text = preg_replace('/[\\\\\/:*?"<>|]/', '_', $text);
    $text = trim($text);
    return mb_substr($text, 0, 120);
}

function downloadImage(array $image, string $accessToken, string $destPath): bool {
    $url = $image['url'] ?? null;
    if (!$url && !empty($image['id'])) {
        $url = fetchMediaUrl($image['id'], $accessToken);
    }
    if (!$url) {
        return false;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $data   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($data === false || $status !== 200) {
        return false;
    }

    file_put_contents($destPath, $data);
    return true;
}

function fetchMediaUrl(string $mediaId, string $accessToken): ?string {
    $ch = curl_init("https://graph.facebook.com/v21.0/{$mediaId}");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($response, true);
    return $json['url'] ?? null;
}

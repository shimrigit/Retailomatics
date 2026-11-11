<?php
/**
 * bench_common.php — what the three WhatsApp ordering methods share (spec:
 * "POAgent — אפיון בנצ'מרק הזמנת רכש בוואטסאפ (3 שיטות)", shared infrastructure).
 *
 * A phone's method is set in users.php (lib/UserAccess.php poagent_access_wa_mode):
 * '' = the login link as always (bot.php); 'a' / 'b' / 'c' = poagent_bench_handle()
 * here, which hands the message to that method's handler
 * (poagent_bench_<mode>_handle in bench_<mode>_*.php).
 *
 * In every method:
 *   "אתר"          the login link to the website, as the bot sends everyone else
 *   "תרחיש S1"     the demo scenario this phone is running (into the measurement log)
 *
 * One PO path for all methods: poagent_wa_commit_cart() — names and prices
 * always from the price list (CatalogIndex) by barcode, never from what the
 * chat / Flow / catalog sent.
 *
 * Measurement log: whatsapp/logs/bench_YYYY-MM-DD.log, one JSON line per
 * ordering attempt (tools/bench_report.php reads it). The counters live in the
 * session (data.bench) while the attempt runs.
 */

require_once __DIR__ . '/../lib/CatalogIndex.php';
require_once __DIR__ . '/../lib/POStore.php';

// A benchmark conversation idles longer than the bot's default before the
// reminder / close (session_sweeper.php → bot.php poagent_whatsapp_sweep_idle_sessions).
const POAGENT_BENCH_IDLE_REMINDER_AFTER_SECONDS = 600;   // 10 minutes
const POAGENT_BENCH_IDLE_CLOSE_AFTER_SECONDS    = 300;   // +5 minutes after the reminder → 15
const POAGENT_BENCH_SESSION_TTL                 = 3600;
const POAGENT_BENCH_IDLE_SESSION_TTL            = 43200;  // a parked conversation (between orders) keeps its scenario 12 hours

foreach (glob(__DIR__ . '/bench_[abc]_*.php') ?: [] as $methodFile) require_once $methodFile;
require_once __DIR__ . '/dn_chat.php';   // delivery notes from the chat (method A)

function poagent_bench_handle(array $event, string $mode, string $name): void
{
    $from = (string) $event['from'];
    $pnid = (string) ($event['business_phone_number_id'] ?? '');
    $text = trim((string) ($event['text'] ?? ''));

    if (in_array($text, ['אתר', 'כניסה לאתר'], true)) {
        poagent_whatsapp_send_login_link($pnid, $from, $name);
        return;
    }
    if (preg_match('/^תרחיש\s*(S\d+)$/iu', $text, $m)) {
        $scenario = strtoupper($m[1]);
        $session = WaSessionStore::get($from);
        $data = $session['data'] ?? [];
        $data['scenario'] = $scenario;
        if (isset($data['bench'])) $data['bench']['scenario'] = $scenario;
        poagent_bench_save($from, (string) ($session['state'] ?? ''), $data, $pnid, !isset($data['bench']));
        WaClient::text($pnid, $from, "תרחיש {$scenario} נרשם — יופיע בדוח המדידה עם ההזמנה הבאה.");
        return;
    }

    $fn = "poagent_bench_{$mode}_handle";
    if (!function_exists($fn)) {
        WaClient::text($pnid, $from, POAGENT_WA_MODES[$mode] . " עדיין בבנייה.\nכדי להיכנס לאתר כתבו: אתר");
        poagent_whatsapp_log(['ts' => date('c'), 'event' => 'bench_not_built', 'from' => $from, 'mode' => $mode]);
        return;
    }
    // One message of a phone at a time: several photos picked together arrive as
    // separate, near-simultaneous webhooks — each reads the session, adds its page
    // and saves; side by side, one would overwrite the other.
    @mkdir(WA_SESSION_DIR, 0775, true);
    $lock = fopen(WA_SESSION_DIR . '/' . preg_replace('/\D/', '', $from) . '.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $fn($event, $name);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Saves the conversation. Active ($idle false): the idle sweep's clock restarts
 * (bot.php). Parked ($idle true, between orders): only the scenario is kept,
 * and the sweep leaves it alone — no "are you still there" after an order.
 */
function poagent_bench_save(string $from, string $state, array $data, string $pnid, bool $idle = false): void
{
    $data['business_phone_number_id'] = $pnid;
    $data['reminder_sent_at'] = null;
    if ($idle) {
        $data = array_intersect_key($data, array_flip(['business_phone_number_id', 'scenario', 'reminder_sent_at']));
        $data['idle'] = true;
    } else {
        $data['last_inbound_at'] = date('c');
        unset($data['idle']);
    }
    WaSessionStore::set($from, POAGENT_WA_APP_ID, $state, $data, $idle ? POAGENT_BENCH_IDLE_SESSION_TTL : POAGENT_BENCH_SESSION_TTL);
}

/** "12.90 ₪" */
function poagent_bench_ils(int $agorot): string
{
    return number_format($agorot / 100, 2) . ' ₪';
}

// Conversation states in which the user types numbers (a barcode, a quantity, a search) —
// a 6-digit message there is not a desktop login code (bot.php).
// (a_confirm: a delivery date like 151126 is 6 digits too.)
const POAGENT_BENCH_DIGIT_STATES = ['a_supplier', 'a_start', 'a_build', 'a_qty', 'a_cart', 'a_line', 'a_confirm', 'd_po', 'c_supplier', 'c_browse'];

function poagent_bench_takes_digits(?array $session): bool
{
    return in_array((string) ($session['state'] ?? ''), POAGENT_BENCH_DIGIT_STATES, true);
}

// ── the PO ──────────────────────────────────────────────────────────────────

/**
 * Creates the PO from a cart {barcode: qty}. Name and price of each line from
 * the supplier's price list; a barcode no longer in it is left out and
 * returned in 'skipped'. Throws when nothing is left to order.
 * @return array{po: array, skipped: string[]}
 */
function poagent_wa_commit_cart(string $from, string $userName, string $supplierId, array $cart, ?string $deliveryDate, string $mode): array
{
    if (!CatalogIndex::exists($supplierId)) throw new RuntimeException("Unknown supplier: $supplierId");
    $items = $skipped = [];
    foreach ($cart as $barcode => $qty) {
        $qty = (int) $qty;
        if ($qty <= 0) continue;
        $it = CatalogIndex::item($supplierId, (string) $barcode);
        if (!$it) {
            $skipped[] = (string) $barcode;
            continue;
        }
        $items[] = ['barcode' => $it['barcode'], 'name' => $it['name'], 'qty' => $qty, 'unit_price_agorot' => $it['price_agorot']];
    }
    if (!$items) throw new RuntimeException('Empty cart');
    $po = POStore::createPO($from, $supplierId, $items, $userName, $deliveryDate ?: poagent_wa_next_business_day(), ['source' => "wa_{$mode}"]);
    return ['po' => $po, 'skipped' => $skipped];
}

/** Tomorrow, or Sunday when tomorrow is Saturday (Y-m-d, Israel time). */
function poagent_wa_next_business_day(?DateTimeImmutable $today = null): string
{
    $d = ($today ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Jerusalem')))->modify('+1 day');
    if ($d->format('w') === '6') $d = $d->modify('+1 day');
    return $d->format('Y-m-d');
}

// ── measurement ─────────────────────────────────────────────────────────────

/** Counters of a new ordering attempt (the session's data.bench). */
function poagent_bench_new(string $mode, string $scenario = ''): array
{
    return ['mode' => $mode, 'scenario' => $scenario, 'started_at' => time(), 'inbound_msgs' => 0, 'taps' => 0,
        'typed_msgs' => 0, 'searches' => 0, 'zero_result_searches' => 0, 'edits' => 0];
}

/** Counts one inbound message: a tap (list / button / Flow / catalog order) or a typed one. */
function poagent_bench_note(array &$bench, array $event): void
{
    $bench['inbound_msgs']++;
    $bench[in_array($event['type'] ?? '', ['interactive', 'button', 'order'], true) ? 'taps' : 'typed_msgs']++;
}

/** The attempt is over: one line in the measurement log. $outcome: created | cancelled | abandoned. */
function poagent_bench_finish(string $from, array $bench, string $outcome, ?string $poId = null, int $lines = 0): void
{
    $now = time();
    poagent_bench_log([
        'mode'        => $bench['mode'] ?? '',
        'wa_id'       => $from,
        'scenario'    => $bench['scenario'] ?? '',
        'started_at'  => date('c', (int) ($bench['started_at'] ?? $now)),
        'finished_at' => date('c', $now),
        'duration_s'  => $now - (int) ($bench['started_at'] ?? $now),
        'inbound_msgs'         => (int) ($bench['inbound_msgs'] ?? 0),
        'taps'                 => (int) ($bench['taps'] ?? 0),
        'typed_msgs'           => (int) ($bench['typed_msgs'] ?? 0),
        'searches'             => (int) ($bench['searches'] ?? 0),
        'zero_result_searches' => (int) ($bench['zero_result_searches'] ?? 0),
        'lines'       => $lines,
        'edits'       => (int) ($bench['edits'] ?? 0),
        'outcome'     => $outcome,
        'po_id'       => $poId,
    ]);
}

function poagent_bench_log(array $entry): void
{
    if (!is_dir(POAGENT_WA_LOG_DIR)) @mkdir(POAGENT_WA_LOG_DIR, 0775, true);
    @file_put_contents(POAGENT_WA_LOG_DIR . '/bench_' . date('Y-m-d') . '.log',
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

/** Every line of the measurement log, oldest first. */
function poagent_bench_read_log(): array
{
    $out = [];
    foreach (glob(POAGENT_WA_LOG_DIR . '/bench_*.log') ?: [] as $f) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (is_array($e = json_decode($line, true))) $out[] = $e;
        }
    }
    usort($out, fn($a, $b) => strcmp($a['started_at'] ?? '', $b['started_at'] ?? ''));
    return $out;
}

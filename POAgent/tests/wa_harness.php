<?php
// wa_harness.php — runs the POAgent WhatsApp bot with no WhatsApp: a test
// builds Meta webhook payloads, hands them to WaRouter::handle() exactly as
// whatsapp_app/webhook.php does, and gets back what the bot would have sent
// (WaClient::$captured — nothing goes to the Graph API).
//
// Everything the bot writes goes to a temp folder of its own, deleted at the
// end: POs and the PO counter, WhatsApp sessions, user_access.json, every log,
// and a copy of apps.json with the test senders allowed. The supplier price
// lists (SuppliersDB) are the real ones, read only.
//
// Used by wa_tests.php:  php POAgent/tests/wa_tests.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('WA_TEST_DIR', sys_get_temp_dir() . '/poagent_wa_test_' . getmypid());
const WA_TEST_FROM     = '972500000901';   // a test sender, allowed in the test apps.json
const WA_TEST_STRANGER = '972500000902';   // not allowed — the router must drop it

define('POAGENT_PODIR', WA_TEST_DIR . '/POdir');
define('POAGENT_COUNTER_DIR', WA_TEST_DIR . '/POcounter');
define('POAGENT_ACCESS_FILE', WA_TEST_DIR . '/user_access.json');
define('POAGENT_LOG_DIR', WA_TEST_DIR . '/logs');
define('POAGENT_WA_LOG_DIR', WA_TEST_DIR . '/logs');
define('WA_SESSION_DIR', WA_TEST_DIR . '/wa_sessions');
define('WA_ROUTER_LOG_DIR', WA_TEST_DIR . '/logs');
define('WA_SEND_LOG_DIR', WA_TEST_DIR . '/logs');
define('WA_REGISTRY_FILE', WA_TEST_DIR . '/apps.json');

@mkdir(WA_TEST_DIR . '/logs', 0777, true);
// apps.json as it is, plus the test sender on POAgent's allowlist.
$registry = json_decode((string) file_get_contents(__DIR__ . '/../../whatsapp_app/apps.json'), true);
foreach ($registry['apps'] as &$app) {
    if ($app['id'] === 'poagent') $app['allowed_senders'][] = ['number' => WA_TEST_FROM, 'name' => 'בודק'];
}
unset($app);
file_put_contents(WA_REGISTRY_FILE, json_encode($registry, JSON_UNESCAPED_UNICODE));
// The PO counter starts where the real one is, so test POs look real (they stay in the temp folder).
@mkdir(POAGENT_COUNTER_DIR, 0777, true);
file_put_contents(POAGENT_COUNTER_DIR . '/.po_counter', '90000');

require_once __DIR__ . '/../../whatsapp_app/lib/WaRouter.php';
WaClient::$captured = [];

$GLOBALS['wa_test'] = ['pass' => 0, 'fail' => 0, 'pnid' => (string) ($registry['apps'][0]['match']['phone_number_id'][0] ?? '')];

/**
 * One inbound message through the router. $msg: a text, or a message body
 * array (['type' => 'interactive', 'interactive' => [...]], ['type' => 'order', ...]).
 * Returns the payloads the bot sent in reply.
 */
function wa_test_send($msg, string $from = WA_TEST_FROM): array
{
    $message = is_string($msg) ? ['type' => 'text', 'text' => ['body' => $msg]] : $msg;
    $message += ['from' => $from, 'id' => 'wamid.test' . bin2hex(random_bytes(6)), 'timestamp' => (string) time()];
    $body = ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'test', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp',
        'metadata' => ['display_phone_number' => '972522649555', 'phone_number_id' => $GLOBALS['wa_test']['pnid']],
        'contacts' => [['profile' => ['name' => 'Test'], 'wa_id' => $from]],
        'messages' => [$message],
    ]]]]]];
    $before = count(WaClient::$captured);
    WaRouter::handle(json_encode($body, JSON_UNESCAPED_UNICODE));
    return array_slice(WaClient::$captured, $before);
}

/** A tap on a list row / a reply button, as Meta sends it. */
function wa_test_tap(string $id, string $title = '', string $kind = 'list_reply'): array
{
    return ['type' => 'interactive', 'interactive' => ['type' => $kind, $kind => ['id' => $id, 'title' => $title]]];
}

function wa_test_session(string $from = WA_TEST_FROM): ?array
{
    return WaSessionStore::get($from);
}

/** Text of a sent payload, whatever its type — for "does the reply say X". */
function wa_test_text(array $payload): string
{
    $i = $payload['interactive'] ?? [];
    $parts = [$payload['text']['body'] ?? '', $i['body']['text'] ?? '', $i['header']['text'] ?? '', $i['footer']['text'] ?? ''];
    foreach ($i['action']['buttons'] ?? [] as $b) $parts[] = $b['reply']['title'] ?? '';
    foreach ($i['action']['sections'] ?? [] as $s) foreach ($s['rows'] ?? [] as $r) $parts[] = ($r['title'] ?? '') . ' ' . ($r['description'] ?? '');
    $parts[] = $i['action']['parameters']['display_text'] ?? '';
    return implode("\n", array_filter($parts, fn($p) => $p !== ''));
}

/** WhatsApp's limits that a sent message breaks ([] = none). Checked on every reply by wa_check_limits(). */
function wa_test_limit_errors(array $p): array
{
    $err = [];
    $len = fn($s) => mb_strlen((string) $s);
    if (($p['type'] ?? '') === 'text' && $len($p['text']['body'] ?? '') > 4096) $err[] = 'text body > 4096';
    $i = $p['interactive'] ?? null;
    if ($i) {
        if ($len($i['body']['text'] ?? '') > 1024) $err[] = 'interactive body > 1024';
        if ($len($i['footer']['text'] ?? '') > 60) $err[] = 'footer > 60';
        $buttons = $i['action']['buttons'] ?? [];
        if (count($buttons) > 3) $err[] = 'more than 3 buttons';
        foreach ($buttons as $b) if ($len($b['reply']['title'] ?? '') > 20) $err[] = "button title > 20: {$b['reply']['title']}";
        if (isset($i['action']['button']) && $len($i['action']['button']) > 20) $err[] = 'list button > 20';
        $rows = 0;
        foreach ($i['action']['sections'] ?? [] as $s) {
            if ($len($s['title'] ?? '') > 24) $err[] = 'section title > 24';
            foreach ($s['rows'] ?? [] as $r) {
                $rows++;
                if ($len($r['title'] ?? '') > 24) $err[] = "row title > 24: {$r['title']}";
                if ($len($r['description'] ?? '') > 72) $err[] = 'row description > 72';
                if ($len($r['id'] ?? '') > 200) $err[] = 'row id > 200';
            }
        }
        if (($i['type'] ?? '') === 'list' && $rows > 10) $err[] = 'more than 10 list rows';
    }
    return $err;
}

function wa_check(bool $ok, string $what, string $detail = ''): void
{
    $GLOBALS['wa_test'][$ok ? 'pass' : 'fail']++;
    echo ($ok ? "  ✔ " : "  ✘ ") . $what . ($ok || $detail === '' ? '' : "\n      → $detail") . "\n";
}

/** Every reply within WhatsApp's limits. */
function wa_check_limits(array $sent, string $where): void
{
    $errors = [];
    foreach ($sent as $p) $errors = array_merge($errors, wa_test_limit_errors($p));
    if ($errors) wa_check(false, "$where: within WhatsApp's limits", implode('; ', $errors));
}

function wa_test_section(string $title): void
{
    echo "\n$title\n";
}

/** Summary, temp folder removed; exit code 1 if anything failed. */
function wa_test_done(): void
{
    $t = $GLOBALS['wa_test'];
    echo "\n" . ($t['fail'] ? "FAILED: {$t['fail']} of " : 'OK: all ') . ($t['pass'] + $t['fail']) . " checks"
        . ($t['fail'] ? '' : ' passed') . " · real messages sent: 0\n";
    $rm = function ($dir) use (&$rm) {
        foreach (glob("$dir/{,.}*", GLOB_BRACE) ?: [] as $f) {
            if (in_array(basename($f), ['.', '..'], true)) continue;
            is_dir($f) ? $rm($f) : @unlink($f);
        }
        @rmdir($dir);
    };
    $rm(WA_TEST_DIR);
    exit($t['fail'] ? 1 : 0);
}

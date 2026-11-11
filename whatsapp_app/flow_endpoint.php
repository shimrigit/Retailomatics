<?php
// flow_endpoint.php — the data endpoint of our WhatsApp Flows (Meta calls it
// while a Flow is open on a phone: opening it, each button that sends data,
// going back to a screen that refreshes, and its health check).
//
// Set as the Flow's endpoint in WhatsApp Manager (Flow Builder):
//   https://<ngrok host>/website/whatsapp_app/flow_endpoint.php
// The site .htaccess lets it through from outside, like webhook.php.
//
//   signed by Meta (X-Hub-Signature-256, lib/WaSignature.php)   else 432
//   decrypted (lib/FlowCrypto.php)                              else 421
//   action "ping"                       → {"data":{"status":"active"}}
//   an error notification (data.error)  → logged, {"data":{"acknowledged":true}}
//   anything else → the app's handler (today only POAgent's ordering Flow,
//   POAgent/whatsapp/bench_b_flow.php); a stale / unknown flow_token → 427
// Every answer is encrypted with the request's key. Must answer within 10 s.

require_once __DIR__ . '/lib/WaSignature.php';
require_once __DIR__ . '/lib/FlowCrypto.php';

if (!defined('WA_FLOW_LOG_DIR')) define('WA_FLOW_LOG_DIR', __DIR__ . '/logs');

function wa_flow_log(array $entry): void
{
    @mkdir(WA_FLOW_LOG_DIR, 0775, true);
    @file_put_contents(WA_FLOW_LOG_DIR . '/flow_' . date('Y-m-d') . '.log',
        json_encode(['ts' => date('c')] + $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * The decrypted request → the response to encrypt. Separate from the HTTP part
 * so tests can call it directly.
 */
function wa_flow_handle(array $req): array
{
    if (($req['action'] ?? '') === 'ping') {
        return ['data' => ['status' => 'active']];
    }
    if (isset($req['data']['error'])) {
        wa_flow_log(['event' => 'client_error', 'screen' => $req['screen'] ?? null, 'error' => $req['data']['error'],
            'message' => $req['data']['error_message'] ?? null]);
        return ['data' => ['acknowledged' => true]];
    }
    require_once __DIR__ . '/../POAgent/whatsapp/bot.php';
    return poagent_bench_b_endpoint($req);
}

/** Meta's own status codes; Apache turns a bare http_response_code(432) into 500 — with a reason phrase it passes. */
function wa_flow_status(int $code): void
{
    $reason = [421 => 'Misdirected Request', 427 => 'Flow Token Invalid', 432 => 'Signature Verification Failed'][$code] ?? 'Error';
    header("HTTP/1.1 {$code} {$reason}");
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method not allowed');
    }
    $t0 = microtime(true);
    $raw = (string) file_get_contents('php://input');
    if (WaSignature::check($raw, $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null) === false) {
        wa_flow_log(['event' => 'bad_signature', 'ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '']);
        wa_flow_status(432);
        exit;
    }
    try {
        $dec = FlowCrypto::decryptRequest(json_decode($raw, true) ?: []);
    } catch (Throwable $e) {
        wa_flow_log(['event' => 'decrypt_failed', 'error' => $e->getMessage()]);
        wa_flow_status(421);
        exit;
    }
    $req = $dec['data'];
    try {
        $resp = wa_flow_handle($req);
    } catch (WaFlowTokenError $e) {
        wa_flow_log(['event' => 'token_rejected', 'action' => $req['action'] ?? null, 'reason' => $e->getMessage()]);
        wa_flow_status(427);
        header('Content-Type: application/json');
        exit(json_encode(['error_msg' => 'הטופס הזה כבר לא פעיל. שלחו הודעה לבוט לקבלת טופס חדש.'], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        wa_flow_log(['event' => 'handler_error', 'error' => $e->getMessage(), 'where' => $e->getFile() . ':' . $e->getLine()]);
        $resp = ['screen' => (string) ($req['screen'] ?? 'SUPPLIER'), 'data' => ['error_message' => 'משהו השתבש. נסו שוב.']];
    }
    wa_flow_log(['event' => 'request', 'action' => $req['action'] ?? null, 'screen' => $req['screen'] ?? null,
        'answer' => $resp['screen'] ?? (isset($resp['data']['status']) ? 'ping' : null), 'ms' => (int) ((microtime(true) - $t0) * 1000)]);
    header('Content-Type: text/plain');
    echo FlowCrypto::encryptResponse($resp, $dec['key'], $dec['iv']);
}

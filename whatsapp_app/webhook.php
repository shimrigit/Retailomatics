<?php
// webhook.php — Meta WhatsApp webhook receiver

$VERIFY_TOKEN = "my_secret_verify_123"; // must match exactly what you enter in Meta's dashboard

$IMAGES_DIR = __DIR__ . '/whatsapp_images';

// ── GET: Meta's verification handshake ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode']         ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge']    ?? '';

    if ($mode === 'subscribe' && $token === $VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
    } else {
        http_response_code(403);
        echo "Verification failed";
    }
    exit;
}

// ── POST: incoming message events ────────────────────────────────────────────
// Two things happen, in this order:
//   1. The raw payload is appended to whatsapp_images/webhook_log.txt. This is
//      untouched legacy behavior — NP's MessageFetching.php scrapes that file
//      on demand and depends on every payload being there.
//   2. After ack'ing Meta, the body is handed to WaRouter, which matches the
//      business number the event was sent to against apps.json and calls the
//      one matching app's handler (POAgent's bot, etc.). See lib/WaRouter.php.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');

    // Only Meta's own requests (lib/WaSignature.php): a forged one could
    // otherwise pose as an allowed phone — and the ordering benchmark creates
    // POs from chat. Without an app_secret in config.json yet: let through, logged.
    require_once __DIR__ . '/lib/WaSignature.php';
    $signed = WaSignature::check($body, $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null);
    if ($signed !== true) {
        @mkdir(__DIR__ . '/logs', 0775, true);
        @file_put_contents(__DIR__ . '/logs/webhook_' . date('Y-m-d') . '.log', json_encode([
            'ts' => date('c'), 'event' => $signed === false ? 'rejected_bad_signature' : 'unverified_no_app_secret',
            'ip' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '', 'bytes' => strlen($body),
        ]) . "\n", FILE_APPEND | LOCK_EX);
        if ($signed === false) {
            http_response_code(403);
            echo 'Bad signature';
            exit;
        }
    }

    file_put_contents($IMAGES_DIR . '/webhook_log.txt', date('Y-m-d H:i:s') . " - " . $body . "\n\n", FILE_APPEND);

    // Ack Meta first, then dispatch — the webhook response must not wait on our
    // own outbound Graph API calls.
    http_response_code(200);
    echo "OK";
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    try {
        require_once __DIR__ . '/lib/WaRouter.php';
        WaRouter::handle($body);
    } catch (\Throwable $e) {
        file_put_contents(
            $IMAGES_DIR . '/webhook_log.txt',
            date('Y-m-d H:i:s') . " - [router error] " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine() . "\n\n",
            FILE_APPEND
        );
    }
    exit;
}

http_response_code(405);
echo "Method not allowed";
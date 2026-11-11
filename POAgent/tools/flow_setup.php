<?php
// flow_setup.php — WhatsApp Flows setup for method B (whatsapp/bench_b_flow.php).
//   php POAgent/tools/flow_setup.php keys       make the key pair (whatsapp_app/keys/, once) and print the public key
//   php POAgent/tools/flow_setup.php status     what Meta has registered on the POAgent number (read only)
//   php POAgent/tools/flow_setup.php register   register our public key on the POAgent number (changes Meta's setting)
//   php POAgent/tools/flow_setup.php ping       the endpoint's health check through Apache, signed + encrypted like Meta
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../../whatsapp_app/lib/FlowCrypto.php';
require_once __DIR__ . '/../../whatsapp_app/lib/WaSignature.php';

$cfg = json_decode((string) file_get_contents(__DIR__ . '/../../whatsapp_app/config.json'), true) ?: [];
$apps = json_decode((string) file_get_contents(__DIR__ . '/../../whatsapp_app/apps.json'), true) ?: [];
$pnid = '';
foreach ($apps['apps'] ?? [] as $a) if ($a['id'] === 'poagent') $pnid = (string) ($a['match']['phone_number_id'][0] ?? '');

function graph(string $method, string $path, array $form = []): array
{
    global $cfg;
    $ch = curl_init('https://graph.facebook.com/v21.0/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . ($cfg['meta_key'] ?? '')]]
        + ($method === 'POST' ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form)] : []));
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($body, true) ?? $body];
}

switch ($argv[1] ?? '') {
    case 'keys':
        [, $pub] = FlowCrypto::keys();
        echo "Key pair in whatsapp_app/keys/. Public key:\n$pub\n";
        break;
    case 'status':
        [$code, $r] = graph('GET', "$pnid/whatsapp_business_encryption");
        $mine = trim(FlowCrypto::keys()[1]);
        echo "HTTP $code\n";
        echo 'signature status: ', $r['business_public_key_signature_status'] ?? '-', "\n";
        echo 'registered key is ours: ', isset($r['business_public_key']) ? (trim($r['business_public_key']) === $mine ? 'yes' : 'NO (another key)') : 'none registered', "\n";
        if (isset($r['error'])) echo 'error: ', $r['error']['message'] ?? json_encode($r['error']), "\n";
        break;
    case 'register':
        [$code, $r] = graph('POST', "$pnid/whatsapp_business_encryption", ['business_public_key' => FlowCrypto::keys()[1]]);
        echo "HTTP $code ", json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
        break;
    case 'ping':
        $url = $argv[2] ?? 'http://localhost/website/whatsapp_app/flow_endpoint.php';
        $enc = FlowCrypto::encryptRequest(['version' => '3.0', 'action' => 'ping'], FlowCrypto::keys()[1]);
        $body = json_encode($enc['body']);
        $secret = WaSignature::secrets()[0] ?? '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=' . hash_hmac('sha256', $body, $secret)]]);
        $t = microtime(true);
        $resp = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        printf("HTTP %d in %d ms → %s\n", $code, (microtime(true) - $t) * 1000,
            json_encode(FlowCrypto::decryptResponse($resp, $enc['key'], $enc['iv'])) ?: $resp);
        break;
    default:
        echo "usage: php POAgent/tools/flow_setup.php keys|status|register|ping\n";
}

<?php
// FlowCrypto.php — the encryption of WhatsApp Flows data-endpoint requests
// (flow_endpoint.php). Meta's scheme:
//   request   encrypted_aes_key   RSA-OAEP (SHA-256, MGF1 SHA-256) with our public key → a 128-bit AES key
//             encrypted_flow_data AES-128-GCM with that key and initial_vector; the last 16 bytes are the tag
//   response  the JSON, AES-128-GCM with the same key and the IV flipped (every byte XOR 0xFF),
//             tag appended, base64 — sent as text/plain
// PHP's openssl_private_decrypt() does OAEP with SHA-1 only, so the RSA part is
// phpseclib 3 (in vendor/, through google/apiclient).
//
// The key pair lives in whatsapp_app/keys/ (git-ignored); the public key is
// registered on the business number (POAgent/tools/flow_setup.php).

require_once __DIR__ . '/../../vendor/autoload.php';

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

if (!defined('WA_FLOW_KEY_DIR')) define('WA_FLOW_KEY_DIR', __DIR__ . '/../keys');

/** An app's endpoint handler refuses the flow_token (stale / unknown): flow_endpoint.php answers 427. */
class WaFlowTokenError extends RuntimeException {}

class FlowCrypto
{
    /** [private PEM, public PEM]; a new 2048-bit pair is made the first time. */
    public static function keys(): array
    {
        $priv = WA_FLOW_KEY_DIR . '/flow_private.pem';
        $pub = WA_FLOW_KEY_DIR . '/flow_public.pem';
        if (!is_file($priv)) {
            @mkdir(WA_FLOW_KEY_DIR, 0700, true);
            $key = RSA::createKey(2048);
            file_put_contents($priv, $key->toString('PKCS8'));
            file_put_contents($pub, $key->getPublicKey()->toString('PKCS8'));
        }
        return [(string) file_get_contents($priv), (string) file_get_contents($pub)];
    }

    /**
     * Decrypts a request body ({encrypted_flow_data, encrypted_aes_key, initial_vector}).
     * @return array{data: array, key: string, iv: string}
     * @throws RuntimeException when it can't be decrypted (the endpoint answers 421)
     */
    public static function decryptRequest(array $body, ?string $privatePem = null): array
    {
        foreach (['encrypted_flow_data', 'encrypted_aes_key', 'initial_vector'] as $f) {
            if (!is_string($body[$f] ?? null)) throw new RuntimeException("missing $f");
        }
        try {
            $rsa = PublicKeyLoader::load($privatePem ?? self::keys()[0])
                ->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
            $key = $rsa->decrypt(base64_decode($body['encrypted_aes_key']));
        } catch (Throwable $e) {
            throw new RuntimeException('AES key: ' . $e->getMessage());
        }
        $flow = base64_decode($body['encrypted_flow_data']);
        $iv = base64_decode($body['initial_vector']);
        $json = openssl_decrypt(substr($flow, 0, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, substr($flow, -16));
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)) throw new RuntimeException('flow data could not be decrypted');
        return ['data' => $data, 'key' => $key, 'iv' => $iv];
    }

    /** Encrypts a response with the request's key and the flipped IV; base64, for a text/plain body. */
    public static function encryptResponse(array $response, string $key, string $iv): string
    {
        $tag = '';
        $ct = openssl_encrypt(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'aes-128-gcm', $key,
            OPENSSL_RAW_DATA, self::flip($iv), $tag, '', 16);
        return base64_encode($ct . $tag);
    }

    // ── what Meta does — for tests ──────────────────────────────────────────

    /** A request as WhatsApp would send it. @return array{body: array, key: string, iv: string} */
    public static function encryptRequest(array $data, string $publicPem): array
    {
        $key = random_bytes(16);
        $iv = random_bytes(16);
        $rsa = PublicKeyLoader::load($publicPem)->withPadding(RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256');
        $tag = '';
        $ct = openssl_encrypt(json_encode($data), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        return ['body' => [
            'encrypted_flow_data' => base64_encode($ct . $tag),
            'encrypted_aes_key'   => base64_encode($rsa->encrypt($key)),
            'initial_vector'      => base64_encode($iv),
        ], 'key' => $key, 'iv' => $iv];
    }

    /** Reads a response as WhatsApp would. */
    public static function decryptResponse(string $b64, string $key, string $iv): ?array
    {
        $raw = base64_decode($b64);
        $json = openssl_decrypt(substr($raw, 0, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, self::flip($iv), substr($raw, -16));
        return $json === false ? null : json_decode($json, true);
    }

    private static function flip(string $iv): string
    {
        return $iv ^ str_repeat("\xFF", strlen($iv));
    }
}

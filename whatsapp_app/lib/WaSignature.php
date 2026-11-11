<?php
// WaSignature.php — is a webhook POST really from Meta? Meta signs each one:
// header X-Hub-Signature-256 = "sha256=" + HMAC-SHA256 of the raw body, keyed
// with the app's App Secret (Meta app → App settings → Basic).
//
// The secret is "app_secret" in whatsapp_app/config.json (git-ignored) — one
// string, or a list when the numbers behind this webhook belong to more than
// one Meta app (any of them may match). With none configured nothing can be
// checked: webhook.php then lets the request through and logs it as unverified.

class WaSignature
{
    /** true = signed by Meta, false = missing / wrong signature, null = no app_secret configured. */
    public static function check(string $body, ?string $header, ?array $secrets = null): ?bool
    {
        $secrets ??= self::secrets();
        if (!$secrets) return null;
        if (!$header || !preg_match('/^sha256=([0-9a-f]{64})$/i', trim($header), $m)) return false;
        foreach ($secrets as $secret) {
            if (hash_equals(hash_hmac('sha256', $body, $secret), strtolower($m[1]))) return true;
        }
        return false;
    }

    /** The App Secret(s) from config.json. */
    public static function secrets(): array
    {
        $cfg = json_decode((string) @file_get_contents(__DIR__ . '/../config.json'), true);
        $s = $cfg['app_secret'] ?? [];
        return array_values(array_filter(array_map('strval', is_array($s) ? $s : [$s]), fn($v) => trim($v) !== ''));
    }
}

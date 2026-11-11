<?php
// shop_city.php — the shop's city (shopDefaultCity in configDir/shops_V2.json),
// for picking the CPD price region in stage 2 (stage_br_save.php → stage_chpf.php).

function npShopCity(string $shop): string
{
    static $shops = null;
    $shops ??= json_decode((string) @file_get_contents(__DIR__ . '/../configDir/shops_V2.json'), true) ?: [];
    foreach ($shops as $s) {
        if (($s['ShopName'] ?? '') === $shop) return (string) ($s['shopDefaultCity'] ?? '');
    }
    return '';
}

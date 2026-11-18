<?php
// shop_settings.php — NP options kept per shop (configDir/np_shop_settings.json),
// set by the checkboxes on NP's start screen (index.php → shop_settings_api.php)
// and read by the stages by shop name, so they don't have to travel through
// every form (or through WaFetch):
//   noDeptClassify  true = stage 3 doesn't classify departments; the final
//                   screen has a button to classify them by hand
//   customName      true = product names in the shop's own style (stage 2),
//                   by the shop's prompt (namePrompt)
//   namePrompt      the shop's naming rule, given to the AI as is

define('NP_SHOP_SETTINGS_PATH', __DIR__ . '/../configDir/np_shop_settings.json');

const NP_SHOP_SETTING_DEFAULTS = ['noDeptClassify' => false, 'customName' => false, 'namePrompt' => ''];

function npAllShopSettings(): array
{
    $all = json_decode((string) @file_get_contents(NP_SHOP_SETTINGS_PATH), true);
    return is_array($all) ? $all : [];
}

function npShopSettings(string $shop): array
{
    return array_merge(NP_SHOP_SETTING_DEFAULTS, npAllShopSettings()[$shop] ?? []);
}

/** Sets one option of one shop (only the two checkboxes; the prompt is edited in the file). */
function npSetShopSetting(string $shop, string $key, bool $value): void
{
    if (!in_array($key, ['noDeptClassify', 'customName'], true) || $shop === '') return;
    $fp = fopen(NP_SHOP_SETTINGS_PATH, 'c+');
    if (!$fp) throw new RuntimeException('לא ניתן לפתוח את ' . NP_SHOP_SETTINGS_PATH);
    flock($fp, LOCK_EX);
    $all = json_decode((string) stream_get_contents($fp), true);
    if (!is_array($all)) $all = [];
    $all[$shop] = array_merge(NP_SHOP_SETTING_DEFAULTS, $all[$shop] ?? []);
    $all[$shop][$key] = $value;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

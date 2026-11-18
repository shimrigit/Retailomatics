<?php
$shopsConfig = json_decode(file_get_contents(__DIR__ . '/../configDir/shops_V2.json'), true) ?? [];
$shopNames   = array_column($shopsConfig, 'ShopName');
sort($shopNames);
require_once __DIR__ . '/shop_settings.php';
$shopSettings = [];
foreach ($shopNames as $s) $shopSettings[$s] = npShopSettings($s);
$today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>NP Harmonized – התחלה</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,.15); padding: 60px 75px; width: 690px; }
        h2 { margin: 0 0 42px; color: #333; text-align: center; font-size: 36px; }
        label { display: block; margin-bottom: 9px; font-weight: bold; color: #555; font-size: 21px; }
        select, input[type="date"], input[type="text"] {
            width: 100%; padding: 15px; border: 1px solid #ccc; border-radius: 8px;
            font-size: 21px; background: #fafafa; margin-bottom: 30px; box-sizing: border-box;
        }
        input[type="text"].letter { width: 90px; text-transform: uppercase; text-align: center; font-size: 27px; font-weight: bold; }
        button { display: block; width: 100%; padding: 18px; background: #2575a8; color: #fff; border: none; border-radius: 8px; font-size: 24px; cursor: pointer; margin-top: 6px; }
        button:hover { background: #1a5c87; }
        .switch-group { display: flex; gap: 15px; margin-bottom: 30px; }
        .switch-opt { flex: 1; }
        .switch-opt input[type="radio"] { display: none; }
        .switch-opt label { display: block; margin: 0; padding: 15px; text-align: center; border: 2px solid #ccc; border-radius: 8px; background: #fafafa; font-size: 20px; font-weight: bold; color: #555; cursor: pointer; }
        .switch-opt input[type="radio"]:checked + label { border-color: #2575a8; background: #e8f1f8; color: #2575a8; }
        .shop-opts { display: none; background: #f7fafc; border: 1px solid #d6e4ef; border-radius: 8px; padding: 14px 18px; margin: -14px 0 30px; }
        .shop-opts label.chk { display: flex; align-items: center; gap: 10px; margin: 6px 0 2px; font-size: 20px; cursor: pointer; }
        .shop-opts input[type="checkbox"] { width: 22px; height: 22px; }
        .opt-note { font-size: 15px; color: #888; margin: 0 32px 8px 0; }
        .opt-saved { font-size: 15px; color: #2a7d2a; min-height: 18px; }
    </style>
</head>
<body>
<div class="card">
    <h2>NP Harmonized</h2>
    <form action="confirm.php" method="post">

        <label for="date">תאריך עיבוד</label>
        <input type="date" id="date" name="date" value="<?= $today ?>" required>

        <label for="shop">שם חנות</label>
        <select name="shop" id="shop" required>
            <option value="">-- בחר חנות --</option>
            <?php foreach ($shopNames as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
        </select>

        <div class="shop-opts" id="shopOpts">
            <label class="chk"><input type="checkbox" id="opt_noDeptClassify" data-key="noDeptClassify"> ללא סיווג למחלקות</label>
            <div class="opt-note">הסיווג אפשרי ידנית במסך הסופי</div>
            <label class="chk"><input type="checkbox" id="opt_customName" data-key="customName"> שם מוצר לפי העדפת משתמש</label>
            <div class="opt-note" id="nameNote"></div>
            <div class="opt-saved" id="optSaved"></div>
        </div>

        <label for="root_letter">אות כונן ראשי</label>
        <input type="text" class="letter" id="root_letter" name="root_letter" value="Z" maxlength="1" required>

        <label>אופן איסוף תמונות</label>
        <div class="switch-group">
            <div class="switch-opt">
                <input type="radio" id="harvest_manual" name="harvest_choice" value="manual">
                <label for="harvest_manual">ידני</label>
            </div>
            <div class="switch-opt">
                <input type="radio" id="harvest_wa_group" name="harvest_choice" value="group" checked>
                <label for="harvest_wa_group">WhatsApp — הורדה מקבוצה</label>
            </div>
            <div class="switch-opt">
                <input type="radio" id="harvest_wa_webhook" name="harvest_choice" value="webhook">
                <label for="harvest_wa_webhook">WhatsApp Business</label>
            </div>
        </div>
        <input type="hidden" name="harvest_mode" id="harvest_mode" value="whatsapp">
        <input type="hidden" name="wa_source" id="wa_source" value="group">
        <input type="hidden" name="np_flow" value="1">

        <label>מצב תהליך</label>
        <div class="switch-group">
            <div class="switch-opt">
                <input type="radio" id="mode_flow" name="mode" value="flow" checked>
                <label for="mode_flow">Flow – רציף אוטומטית</label>
            </div>
            <div class="switch-opt">
                <input type="radio" id="mode_steps" name="mode" value="steps">
                <label for="mode_steps">Steps – שלב אחר שלב</label>
            </div>
        </div>

        <button type="submit">המשך לאישור</button>
    </form>
</div>
<script>
// Per-shop options (shop_settings.php): shown once a shop is picked, with its
// last state; each change is saved at once, and the stages read it by shop.
var SHOP_SETTINGS = <?= json_encode($shopSettings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var shopSel = document.getElementById('shop');
function showShopOpts() {
    var shop = shopSel.value, s = SHOP_SETTINGS[shop] || {};
    document.getElementById('shopOpts').style.display = shop ? 'block' : 'none';
    document.getElementById('opt_noDeptClassify').checked = !!s.noDeptClassify;
    document.getElementById('opt_customName').checked = !!s.customName;
    document.getElementById('nameNote').textContent = s.namePrompt
        ? 'הכלל: ' + s.namePrompt
        : 'לחנות זו אין עדיין כלל שמות (configDir/np_shop_settings.json, namePrompt) — השם יישאר בסגנון הרגיל';
    document.getElementById('optSaved').textContent = '';
}
shopSel.addEventListener('change', showShopOpts);
document.querySelectorAll('#shopOpts input[type="checkbox"]').forEach(function (box) {
    box.addEventListener('change', function () {
        var shop = shopSel.value, key = box.dataset.key, saved = document.getElementById('optSaved');
        var body = new URLSearchParams({ shop: shop, key: key, value: box.checked ? '1' : '0' });
        fetch('shop_settings_api.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.ok) throw new Error(j.error || 'error');
                SHOP_SETTINGS[shop] = j.settings;
                saved.style.color = '#2a7d2a';
                saved.textContent = '✓ נשמר ל-' + shop;
            })
            .catch(function (e) {
                saved.style.color = '#c0392b';
                saved.textContent = '✘ השמירה נכשלה: ' + e.message;
            });
    });
});
showShopOpts();

// "WhatsApp — download from a group" goes through WaFetch first (step 1:
// download the images), which then continues to confirm.php; the other two
// go straight to confirm.php. harvest_mode stays "whatsapp" for both
// WhatsApp choices; wa_source says where the images come from.
document.querySelector('form').addEventListener('submit', function () {
    var choice = document.querySelector('input[name="harvest_choice"]:checked').value;
    document.getElementById('harvest_mode').value = choice === 'manual' ? 'manual' : 'whatsapp';
    document.getElementById('wa_source').value = choice === 'manual' ? '' : choice;
    this.action = choice === 'group' ? '../WaFetch/index.php' : 'confirm.php';
});
</script>
</body>
</html>

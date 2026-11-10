<?php
// Screen 1 — desktop login. The page shows a one-time code (and a QR code
// that opens WhatsApp with it typed in); the user sends it to the POAgent bot
// from their own phone, the bot ties the code to that number
// (whatsapp/bot.php), and this page — polling login_poll.php — logs in as
// that user. Same identity and role as their phone logins.
// The old user1/2/3 picker stays below as a fallback, for the ones not blocked
// in users.php (select_user.php).
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/UserAccess.php';
require_once __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf_barcodes_2d.php';

// "יציאה / החלף משתמש" from the menu: log out, and forget this computer.
if (isset($_GET['logout'])) {
    poagent_login_log('logout', (string) ($_SESSION['poagent_generator_id'] ?? ''), 'ok');
    $_SESSION = [];
    session_regenerate_id(true);
    poagent_access_forget();
    header('Location: index.php');
    exit;
}
// Already logged in (or remembered — lib/access_guard.php restored it): straight to the menu.
if (!empty($_SESSION['poagent_generator_id'])) {
    header('Location: main_menu.php');
    exit;
}

// The bot's number, from apps.json (poagent's display_phone_number).
$botNumber = '';
$apps = json_decode((string) @file_get_contents(__DIR__ . '/../whatsapp_app/apps.json'), true);
foreach ($apps['apps'] ?? [] as $app) {
    if (($app['id'] ?? '') === 'poagent') $botNumber = (string) ($app['match']['display_phone_number'][0] ?? '');
}
$botPhone = preg_match('/^9725(\d)(\d{7})$/', $botNumber, $m) ? "05{$m[1]}-{$m[2]}" : $botNumber;

$code = poagent_access_new_code(session_id());
$waUrl = 'https://wa.me/' . $botNumber . '?text=' . rawurlencode("כניסה {$code}");
$qr = (new TCPDF2DBarcode($waUrl, 'QRCODE,M'))->getBarcodeSVGcode(5, 5, '#000');
$qr = substr($qr, strpos($qr, '<svg'));   // drop the XML prolog — inlined in the page

$fallback = array_values(array_filter(POAGENT_USERS, fn($u) => !poagent_access_is_blocked($u)));

poagent_render_head('POAgent – כניסה');
?>
<h2>הזמנות רכש ותעודות משלוח</h2>
<?php if (isset($_GET['blocked'])): ?>
<p style="background:#f8d7da;color:#721c24;padding:12px;border-radius:8px">✖ המשתמש <?= htmlspecialchars((string) $_GET['blocked']) ?> חסום — אפשר לבטל את החסימה ב<a href="users.php">מסך המשתמשים</a></p>
<?php endif; ?>

<div id="loginBox" style="text-align:center">
    <p style="font-size:18px;margin-bottom:8px">כדי להיכנס, שלח/י את הקוד לבוט של POAgent ב-WhatsApp<br>
        <span class="muted">(<?= htmlspecialchars($botPhone) ?>) — מהטלפון שלך</span></p>
    <div style="display:flex;gap:28px;justify-content:center;align-items:center;flex-wrap:wrap;margin:16px 0">
        <div>
            <div style="display:inline-block;padding:10px;background:#fff;border:1px solid #ddd;border-radius:10px;line-height:0"><?= $qr ?></div>
            <div class="muted" style="font-size:14px">סרוק/י במצלמה של הטלפון</div>
        </div>
        <div>
            <div class="muted">או שלח/י את הקוד</div>
            <div style="font-size:44px;font-weight:bold;letter-spacing:6px;direction:ltr;margin:6px 0"><?= $code ?></div>
            <a href="<?= htmlspecialchars($waUrl) ?>" target="_blank" rel="noopener" class="muted" style="font-size:14px">פתח ב-WhatsApp במחשב</a>
        </div>
    </div>
    <p id="loginState" class="muted">ממתין לקוד… (הקוד בתוקף ל-5 דקות)</p>
</div>

<?php if ($fallback): ?>
<details style="margin-top:28px">
    <summary class="muted">כניסה בלי WhatsApp (user1/2/3)</summary>
    <form action="select_user.php" method="post">
        <div class="switch-group">
            <?php foreach ($fallback as $i => $u): ?>
            <div class="switch-opt">
                <input type="radio" id="u<?= $i ?>" name="generator_id" value="<?= htmlspecialchars($u) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                <label for="u<?= $i ?>"><?= htmlspecialchars($u) ?></label>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="submit">המשך</button>
    </form>
</details>
<?php endif; ?>

<?php // This page only opens on this computer (lib/access_guard.php), so the users screen is always one click away — even with every desktop user blocked. ?>
<p class="muted" style="margin-top:24px"><a href="users.php">👥 משתמשים והרשאות</a></p>

<script>
(function () {
    const state = document.getElementById('loginState');
    const poll = () => fetch('login_poll.php', {cache: 'no-store'}).then(r => r.json()).then(r => {
        if (r.status === 'ok') { state.textContent = '✔ מחובר — נכנס…'; location.href = 'main_menu.php'; return; }
        if (r.status === 'expired') { location.reload(); return; }          // a fresh code
        if (r.status === 'blocked') { state.textContent = '✖ המשתמש הזה חסום'; return; }
        setTimeout(poll, 2000);
    }).catch(() => setTimeout(poll, 5000));
    setTimeout(poll, 2000);
})();
</script>
<?php poagent_render_foot(); ?>

<?php
// network_mode.php — dev tool: switch the mobile link's base URL between
// "ngrok (auto)" and a direct LAN IP (see lib/NetworkMode.php,
// whatsapp/mobile_link.php), and show a history of upload/OCR timing
// measurements logged by dn_ocr_process.php — built after a real debugging
// session where these numbers were read off the phone screen and compared
// by hand turn by turn; this puts both the switch and the numbers in one
// place going forward.
//
// No session/auth — same "no hardening yet" stance as the rest of POAgent's
// dev tools (spec §8.9); this is a local-machine-only page.
require_once __DIR__ . '/../lib/ui_common.php';
require_once __DIR__ . '/../lib/NetworkMode.php';

$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'set_lan') {
        $ip = trim((string) ($_POST['ip'] ?? ''));
        if ($ip !== '' && preg_match('/^\d{1,3}(\.\d{1,3}){3}(:\d+)?$/', $ip)) {
            poagent_network_mode_set_lan('http://' . $ip);
            $notice = "מצב LAN הופעל: http://{$ip}";
        } else {
            $notice = 'כתובת IP לא תקינה.';
        }
    } elseif ($action === 'clear') {
        poagent_network_mode_clear();
        $notice = 'חזרה למצב ngrok (אוטומטי).';
    }
    header('Location: network_mode.php?notice=' . urlencode($notice));
    exit;
}
$notice = $_GET['notice'] ?? '';

$mode = poagent_network_mode_get();
$candidates = poagent_network_mode_local_ip_candidates();

// Recent timing entries, newest first — dn_ocr_process.php writes one JSON
// line per completed OCR call into POAgent/logs/dn_timing_YYYY-MM-DD.log.
$entries = [];
foreach (glob(__DIR__ . '/../logs/dn_timing_*.log') ?: [] as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $entries[] = $row;
        }
    }
}
usort($entries, fn($a, $b) => strcmp($b['ts'] ?? '', $a['ts'] ?? ''));
$entries = array_slice($entries, 0, 30);

function poagent_nm_ms(?int $ms): string
{
    return $ms === null ? '—' : number_format($ms / 1000, 1) . ' שנ׳';
}

function poagent_nm_mbps(?int $bytes, ?int $ms): string
{
    if (!$bytes || !$ms) {
        return '—';
    }
    return number_format(($bytes * 8 / 1_000_000) / ($ms / 1000), 2) . ' Mbps';
}

poagent_render_head('POAgent – מצב רשת (LAN / ngrok)', 1100);
?>
<h2>מצב רשת — LAN / ngrok</h2>

<?php if ($notice !== ''): ?>
<p class="muted"><?= htmlspecialchars($notice) ?></p>
<?php endif; ?>

<p>
    מצב נוכחי:
    <?php if ($mode['mode'] === 'lan'): ?>
        <span class="badge prcv">LAN — <?= htmlspecialchars($mode['value']) ?></span>
    <?php else: ?>
        <span class="badge open">ngrok (אוטומטי)</span>
    <?php endif; ?>
</p>
<p class="muted">
    קישורים חדשים שהבוט שולח (WhatsApp) ייבנו לפי המצב הזה. קישורים שכבר נשלחו לא משתנים —
    יש לשלוח הודעה חדשה לבוט אחרי החלפת מצב כדי לקבל קישור מעודכן.
</p>

<h3 style="margin-top:32px">מעבר ל-LAN</h3>
<?php if (empty($candidates)): ?>
    <p class="muted">לא זוהו כתובות IP מקומיות אוטומטית — ניתן להזין ידנית למטה.</p>
<?php else: ?>
    <p class="muted">כתובות שזוהו על מכונה זו:</p>
    <?php foreach ($candidates as $c): ?>
        <form method="post" style="display:inline-block;margin:0 8px 8px 0">
            <input type="hidden" name="action" value="set_lan">
            <input type="hidden" name="ip" value="<?= htmlspecialchars($c['ip']) ?>">
            <button type="submit" class="btn secondary" style="width:auto;padding:10px 18px;">
                <?= htmlspecialchars($c['ip']) ?>
                <span class="muted">(<?= htmlspecialchars($c['adapter']) ?>)</span>
            </button>
        </form>
    <?php endforeach; ?>
<?php endif; ?>

<form method="post" style="display:flex;gap:12px;align-items:flex-end;max-width:420px;margin-top:14px;">
    <input type="hidden" name="action" value="set_lan">
    <div style="flex:1">
        <label for="ip">או הזן IP ידנית</label>
        <input type="text" id="ip" name="ip" placeholder="192.168.0.90" style="margin-bottom:0">
    </div>
    <button type="submit" style="width:auto;padding:13px 24px;margin-bottom:0">הפעל LAN</button>
</form>

<form method="post" class="row-gap">
    <input type="hidden" name="action" value="clear">
    <button type="submit" class="btn secondary">חזרה ל-ngrok (אוטומטי)</button>
</form>

<h3 style="margin-top:36px">מדידות אחרונות (העלאה + OCR)</h3>
<?php if (empty($entries)): ?>
    <p class="muted">אין עדיין מדידות רשומות — יופיעו כאן אחרי ייבוא תעודת משלוח מהנייד.</p>
<?php else: ?>
<table class="responsive-table">
    <thead>
    <tr>
        <th>זמן</th><th>מצב רשת</th><th>גודל תמונה</th>
        <th>העלאה (טלפון)</th><th>העלאה (עיבוד שרת)</th><th>קצב העלאה בפועל</th>
        <th>OCR</th><th>פריטים</th>
    </tr>
    </thead>
    <?php foreach ($entries as $e): ?>
    <?php
        $ts = isset($e['ts']) ? date('d/m H:i:s', strtotime($e['ts'])) : '—';
        $bytes = $e['bytes'] ?? null;
        $kb = $bytes ? number_format($bytes / 1024, 0) . ' KB' : '—';
    ?>
    <tr>
        <td data-label="זמן"><?= htmlspecialchars($ts) ?></td>
        <td data-label="מצב רשת"><?= htmlspecialchars($e['mode'] ?? '—') ?></td>
        <td data-label="גודל תמונה"><?= htmlspecialchars($kb) ?></td>
        <td data-label="העלאה (טלפון)"><?= poagent_nm_ms($e['upload_client_ms'] ?? null) ?></td>
        <td data-label="העלאה (עיבוד שרת)"><?= poagent_nm_ms($e['upload_server_ms'] ?? null) ?></td>
        <td data-label="קצב העלאה בפועל"><?= poagent_nm_mbps($bytes, $e['upload_client_ms'] ?? null) ?></td>
        <td data-label="OCR"><?= poagent_nm_ms($e['ocr_ms'] ?? null) ?></td>
        <td data-label="פריטים"><?= htmlspecialchars((string) ($e['item_count'] ?? '—')) ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<p class="muted">
    "העלאה (טלפון)" הוא הזמן שהטלפון עצמו מדד (כולל העברת הרשת) — זה שממנו "קצב העלאה בפועל"
    מחושב. "העלאה (עיבוד שרת)" הוא רק זמן כתיבת הקובץ בשרת — אם הוא זעיר אבל "העלאה (טלפון)" גדול,
    זה חיבור הטלפון. "OCR" הוא קריאת ה-AI עצמה בשרת — לא תלוי בחיבור הטלפון בכלל.
</p>
<?php endif; ?>

<div class="row-gap"></div>
<a class="btn secondary" href="../main_menu.php">חזרה לתפריט POAgent</a>
<?php poagent_render_foot(); ?>

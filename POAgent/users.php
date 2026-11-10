<?php
// users.php — manage POAgent's mobile users: Hebrew name, phone, role (FU / BOU).
// One screen over the two files that hold them, kept in step:
//   whatsapp_app/apps.json  poagent's allowed_senders — who the WhatsApp bot answers, and the name it greets
//   POAgent/user_roles.json phone → "fu" | "bou" (see lib/UserRoles.php)
// Also the desktop users' (user1/2/3) roles. This computer only — not via the
// mobile link (a forwarded request is refused).
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    http_response_code(403);
    exit('Available on this computer only.');
}
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/UserAccess.php';
if (!empty($_SESSION['poagent_mobile'])) {
    http_response_code(403);
    exit('Available on the desktop only.');
}

define('POAGENT_APPS_FILE', __DIR__ . '/../whatsapp_app/apps.json');
define('POAGENT_ROLES_FILE', __DIR__ . '/user_roles.json');

function users_read_json(string $path): array
{
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data)) throw new RuntimeException('לא ניתן לקרוא את ' . basename($path));
    return $data;
}

/** Write via a temp file + rename, so a reader never sees half a file. $indent: apps.json uses 2 spaces. */
function users_write_json(string $path, array $data, int $indent = 4): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($indent !== 4) {
        $json = preg_replace_callback('/^( +)/m', fn($m) => str_repeat(' ', intdiv(strlen($m[1]), 4) * $indent), $json);
        // Keep the hand-edited look: short lists and the sender rows on one line
        // (["972..."], { "number": "...", "name": "..." }); other objects as they are.
        $json = preg_replace_callback('/([\[{])\n\s*([^\[\]{}\n]*(?:\n\s*[^\[\]{}\n]+)*)\n\s*([\]}])/u', function ($m) {
            if ($m[1] === '{' && !str_starts_with($m[2], '"number"')) return $m[0];
            $inner = preg_replace('/,\n\s*/', ', ', $m[2]);
            $one = $m[1] === '{' ? "{ {$inner} }" : "[{$inner}]";
            return mb_strlen($one) <= 100 ? $one : $m[0];
        }, $json);
    }
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $path)) {
        throw new RuntimeException('שמירה נכשלה: ' . basename($path));
    }
}

/** 05X-XXXXXXX (dash / spaces optional, or already 9725…) → 9725XXXXXXXX, or null. */
function users_phone_to_id(string $phone): ?string
{
    $d = preg_replace('/\D/', '', $phone);
    if (preg_match('/^05\d{8}$/', $d)) return '972' . substr($d, 1);
    if (preg_match('/^9725\d{8}$/', $d)) return $d;
    return null;
}

function users_id_to_phone(string $id): string
{
    return preg_match('/^9725(\d)(\d{7})$/', $id, $m) ? "05{$m[1]}-{$m[2]}" : $id;
}

function users_valid_name(string $name): bool
{
    return mb_strlen($name) >= 2 && mb_strlen($name) <= 30 && preg_match('/^[\p{Hebrew}\s\'"׳״.\-]+$/u', $name)
        && preg_match('/\p{Hebrew}/u', $name);
}

/** The poagent app's index in apps.json. */
function users_app_index(array $apps): int
{
    foreach ($apps['apps'] ?? [] as $i => $app) {
        if (($app['id'] ?? '') === 'poagent') return $i;
    }
    throw new RuntimeException('האפליקציה poagent לא נמצאה ב-apps.json');
}

$message = $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // One writer at a time (two tabs saving together).
    $lock = fopen(sys_get_temp_dir() . '/poagent_users.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $apps  = users_read_json(POAGENT_APPS_FILE);
        $roles = users_read_json(POAGENT_ROLES_FILE);
        $ai    = users_app_index($apps);
        $senders = $apps['apps'][$ai]['allowed_senders'] ?? [];
        $action  = $_POST['action'] ?? '';
        $role    = ($_POST['role'] ?? '') === 'bou' ? 'bou' : 'fu';
        $name    = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));

        if (in_array($action, ['disconnect', 'block', 'unblock', 'clear_fails'], true)) {
            $id = (string) ($_POST['id'] ?? '');
            if (!in_array($id, POAGENT_USERS, true) && !preg_match('/^9725\d{8}$/', $id)) throw new RuntimeException('משתמש לא מוכר');
            $who = trim((string) ($_POST['who'] ?? '')) ?: users_id_to_phone($id);
            if ($action === 'clear_fails') {
                poagent_access_clear_fails($id);
                $message = "הנעילה של {$who} הוסרה — אפשר לשלוח קוד כניסה שוב";
            } elseif ($action === 'disconnect') {
                poagent_access_disconnect($id);
                $message = "{$who} נותק — יוכל להיכנס שוב עם קישור חדש";
            } else {
                poagent_access_set_blocked($id, $action === 'block');
                $message = $action === 'block' ? "{$who} נחסם — נותק, והבוט לא ישלח לו קישור כניסה" : "החסימה של {$who} בוטלה";
            }
        } elseif ($action === 'desktop_role') {
            $id = (string) ($_POST['id'] ?? '');
            if (!in_array($id, POAGENT_USERS, true)) throw new RuntimeException('משתמש לא מוכר');
            $roles[$id] = $role;
            users_write_json(POAGENT_ROLES_FILE, $roles);
            $message = "התפקיד של {$id} עודכן ל-" . strtoupper($role);
        } else {
            $id = $action === 'add' ? users_phone_to_id((string) ($_POST['phone'] ?? '')) : (string) ($_POST['id'] ?? '');
            if (!$id || !preg_match('/^9725\d{8}$/', $id)) throw new RuntimeException('מספר טלפון לא תקין — בפורמט 05X-XXXXXXX');
            $pos = null;
            foreach ($senders as $i => $s) {
                if ((is_array($s) ? ($s['number'] ?? '') : (string) $s) === $id) $pos = $i;
            }

            if ($action === 'delete') {
                if ($pos !== null) array_splice($senders, $pos, 1);
                unset($roles[$id]);
                $message = 'המשתמש ' . users_id_to_phone($id) . ' הוסר — הבוט לא יענה לו יותר';
            } else {
                if (!users_valid_name($name)) throw new RuntimeException('שם לא תקין — שם בעברית, 2–30 תווים');
                if ($action === 'add' && $pos !== null) throw new RuntimeException('המספר ' . users_id_to_phone($id) . ' כבר קיים ברשימה');
                $entry = ['number' => $id, 'name' => $name];
                if ($pos === null) $senders[] = $entry; else $senders[$pos] = $entry;
                $roles[$id] = $role;
                $message = ($action === 'add' ? 'נוסף: ' : 'עודכן: ') . "{$name} · " . users_id_to_phone($id) . ' · ' . strtoupper($role);
            }
            $apps['apps'][$ai]['allowed_senders'] = array_values($senders);
            users_write_json(POAGENT_APPS_FILE, $apps, 2);
            users_write_json(POAGENT_ROLES_FILE, $roles);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// The list: everyone the bot answers, plus any phone that has a role but isn't answered.
$users = [];
$roles = [];
try {
    $apps  = users_read_json(POAGENT_APPS_FILE);
    $roles = users_read_json(POAGENT_ROLES_FILE);
    foreach ($apps['apps'][users_app_index($apps)]['allowed_senders'] ?? [] as $s) {
        $id = is_array($s) ? (string) ($s['number'] ?? '') : (string) $s;
        $users[$id] = ['name' => is_array($s) ? (string) ($s['name'] ?? '') : '', 'allowed' => true];
    }
    foreach ($roles as $id => $r) {
        if (preg_match('/^9725\d{8}$/', (string) $id) && !isset($users[$id])) $users[$id] = ['name' => '', 'allowed' => false];
    }
} catch (Throwable $e) {
    $error = $error ?: $e->getMessage();
}
$roleOf = fn($id) => strtolower((string) ($roles[$id] ?? '')) === 'bou' ? 'bou' : 'fu';
$roleSelect = fn($current, $form) => '<select form="' . htmlspecialchars($form) . '" name="role" style="margin:0;padding:8px;font-size:16px">'
    . '<option value="fu"' . ($current === 'fu' ? ' selected' : '') . '>FU – שטח</option>'
    . '<option value="bou"' . ($current === 'bou' ? ' selected' : '') . '>BOU – משרד</option></select>';

// Connection status (lib/UserAccess.php), in Israel time.
$access = poagent_access_read();
$ilTime = function (int $t): string {
    $d = (new DateTime('@' . $t))->setTimezone(new DateTimeZone('Asia/Jerusalem'));
    $today = (new DateTime('now', new DateTimeZone('Asia/Jerusalem')))->format('Y-m-d');
    return $d->format('Y-m-d') === $today ? 'היום ' . $d->format('H:i') : $d->format('d/m H:i');
};
$statusCell = function (string $id) use ($access, $ilTime): string {
    $s = poagent_access_status($id, $access);
    $badge = [
        'blocked'   => ['⛔ חסום', '#f8d7da', '#721c24'],
        'active'    => ['🟢 פעיל עכשיו', '#d4edda', '#155724'],
        'connected' => ['🟡 מחובר', '#fff3cd', '#856404'],
        'off'       => ['⚪ לא מחובר', '#eef1f4', '#555'],
    ][$s['state']];
    $out = "<span style=\"background:{$badge[1]};color:{$badge[2]};padding:3px 8px;border-radius:10px;white-space:nowrap\">{$badge[0]}</span>";
    $lines = [];
    if ($s['state'] === 'blocked') $lines[] = 'מ-' . $ilTime((int) $s['blockedAt']);
    if ($s['seen']) $lines[] = 'פעילות אחרונה: ' . $ilTime($s['seen']) . ($s['device'] === 'mobile' ? ' · טלפון' : ' · מחשב');
    if ($s['sessions'] > 1) $lines[] = "{$s['sessions']} חיבורים פתוחים";
    if ($s['remembered']) $lines[] = 'נשאר מחובר במחשב' . ($s['remembered'] > 1 ? " ({$s['remembered']} דפדפנים)" : '') . ' — עד יציאה או 30 יום';
    if ($s['fails'] >= POAGENT_CODE_MAX_FAILS) {
        $lines[] = "<b style=\"color:#a94400\">🔒 נעול לכניסה במחשב — {$s['fails']} קודים שגויים ב-10 דקות (נפתח לבד אחרי 10 דקות)</b>";
    } elseif ($s['fails']) {
        $lines[] = "{$s['fails']} קודים שגויים ב-10 הדקות האחרונות";
    }
    if ($s['linkValid']) $lines[] = 'קישור כניסה בתוקף (נשלח ' . $ilTime($s['link']) . ')';
    elseif ($s['link'] && !$s['seen']) $lines[] = 'קישור אחרון נשלח ' . $ilTime($s['link']);
    if ($lines) $out .= '<div class="muted" style="font-size:13px;margin-top:4px">' . implode('<br>', $lines) . '</div>';
    return $out;
};
$accessButtons = function (string $id, string $who) use ($access): string {
    $s = poagent_access_status($id, $access);
    $form = 'a_' . preg_replace('/\W/', '', $id);
    $btn = 'display:inline-block;width:auto;padding:6px 10px;font-size:14px;margin:2px 0';
    $who = htmlspecialchars($who);
    $html = "<form id=\"{$form}\" method=\"post\"><input type=\"hidden\" name=\"id\" value=\"" . htmlspecialchars($id) . "\">"
        . "<input type=\"hidden\" name=\"who\" value=\"{$who}\"></form>";
    if ($s['state'] === 'blocked') {
        return $html . "<button form=\"{$form}\" name=\"action\" value=\"unblock\" style=\"{$btn};background:#2e7d32\">בטל חסימה</button>";
    }
    if ($s['fails'] >= POAGENT_CODE_MAX_FAILS) {
        $html .= "<button form=\"{$form}\" name=\"action\" value=\"clear_fails\" style=\"{$btn};background:#d68910\">🔓 שחרר נעילה</button> ";
    }
    if ($s['sessions'] || $s['linkValid'] || $s['remembered']) {
        $html .= "<button form=\"{$form}\" name=\"action\" value=\"disconnect\" style=\"{$btn};background:#7f8c8d\""
            . " onclick=\"return confirm('לנתק את {$who}? יוכל להיכנס שוב עם קישור חדש.')\">נתק</button> ";
    }
    return $html . "<button form=\"{$form}\" name=\"action\" value=\"block\" style=\"{$btn};background:#c0392b\""
        . " onclick=\"return confirm('לחסום את {$who}? הוא ינותק מיד, והבוט לא ישלח לו קישור כניסה עד שתבטל את החסימה.')\">חסום</button>";
};

poagent_render_head('POAgent – משתמשים', 900);
?>
<h2>משתמשים</h2>
<div class="poagent-nav"><a class="btn secondary" href="main_menu.php">← תפריט ראשי</a></div>

<?php if ($message): ?><p style="background:#d4edda;color:#155724;padding:12px;border-radius:8px">✔ <?= htmlspecialchars($message) ?></p><?php endif; ?>
<?php if ($error): ?><p style="background:#f8d7da;color:#721c24;padding:12px;border-radius:8px">✖ <?= htmlspecialchars($error) ?></p><?php endif; ?>

<p class="muted">
    <b>FU</b> (שטח) — יוצר הזמנות ומצלם תעודות משלוח. <b>BOU</b> (משרד) — גם מריץ OCR, בודק וסוגר.<br>
    משתמש בטלפון נכנס דרך וואטסאפ למספר של POAgent (052-2649555) — הבוט עונה רק למספרים ברשימה, ופונה אליהם בשם שכאן.
</p>

<h3>משתמשי טלפון</h3>
<table>
    <tr><th>שם</th><th>טלפון</th><th>תפקיד</th><th></th><th>חיבור</th><th></th></tr>
    <?php foreach (array_keys($users) as $n => $id): $u = $users[$id]; $fid = "u{$n}"; ?>
        <tr>
                <td><form id="<?= $fid ?>" method="post"><input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>"></form>
                    <input form="<?= $fid ?>" type="text" name="name" value="<?= htmlspecialchars($u['name']) ?>" style="margin:0;padding:8px;font-size:16px" required>
                    <?php if (!$u['allowed']): ?><div class="muted">לא ברשימת הבוט — שמירה תוסיף אותו</div><?php endif; ?></td>
                <td style="direction:ltr;text-align:right;white-space:nowrap"><?= htmlspecialchars(users_id_to_phone($id)) ?></td>
                <td><?= $roleSelect($roleOf($id), $fid) ?></td>
                <td style="white-space:nowrap">
                    <button form="<?= $fid ?>" type="submit" name="action" value="save" style="display:inline-block;width:auto;padding:8px 14px;font-size:15px;margin:0">שמור</button>
                    <button form="<?= $fid ?>" type="submit" name="action" value="delete" formnovalidate style="display:inline-block;width:auto;padding:8px 14px;font-size:15px;margin:0;background:#c0392b"
                        onclick="return confirm('להסיר את <?= htmlspecialchars(addslashes($u['name'] ?: users_id_to_phone($id))) ?>? הבוט לא יענה לו יותר.')">הסר</button>
                </td>
                <td><?= $statusCell($id) ?></td>
                <td style="white-space:nowrap"><?= $accessButtons($id, $u['name'] ?: users_id_to_phone($id)) ?></td>
        </tr>
    <?php endforeach; ?>
    <tr style="background:#f6f9fc">
            <td><form id="uadd" method="post"></form><input form="uadd" type="text" name="name" placeholder="שם בעברית" style="margin:0;padding:8px;font-size:16px" required></td>
            <td><input form="uadd" type="text" name="phone" placeholder="05X-XXXXXXX" pattern="\s*05\d-?\s*\d{7}\s*" title="05X-XXXXXXX"
                style="margin:0;padding:8px;font-size:16px;direction:ltr;text-align:right" required></td>
            <td><?= $roleSelect('fu', 'uadd') ?></td>
            <td><button form="uadd" type="submit" name="action" value="add" style="display:inline-block;width:auto;padding:8px 14px;font-size:15px;margin:0;background:#2e7d32">➕ הוסף</button></td>
            <td></td><td></td>
    </tr>
</table>

<h3>משתמשי מחשב</h3>
<table>
    <tr><th>משתמש</th><th>תפקיד</th><th></th><th>חיבור</th><th></th></tr>
    <?php foreach (POAGENT_USERS as $id): ?>
        <tr>
                <td><form id="d_<?= htmlspecialchars($id) ?>" method="post"><input type="hidden" name="action" value="desktop_role">
                    <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>"></form><?= htmlspecialchars($id) ?></td>
                <td><?= $roleSelect($roleOf($id), "d_$id") ?></td>
                <td><button form="d_<?= htmlspecialchars($id) ?>" type="submit" style="display:inline-block;width:auto;padding:8px 14px;font-size:15px;margin:0">שמור</button></td>
                <td><?= $statusCell($id) ?></td>
                <td style="white-space:nowrap"><?= $accessButtons($id, $id) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<?php
// Recent logins — read from the login log (lib/UserAccess.php: poagent_login_log), last 7 days.
$logUser = (string) ($_GET['log_user'] ?? '');
$logUser = isset($users[$logUser]) || in_array($logUser, POAGENT_USERS, true) ? $logUser : '';
$logRows = poagent_login_log_recent(50, $logUser !== '' ? $logUser : null);
$whoName = fn($id) => ($users[$id]['name'] ?? '') !== '' ? $users[$id]['name'] : ($id === '' ? '—' : users_id_to_phone($id));
$kindHe = ['m_link' => 'קישור בטלפון', 'desktop_code' => 'קוד במחשב', 'desktop_fallback' => 'user1/2/3', 'remember' => 'מחשב זכור',
    'session' => 'חיבור', 'logout' => 'יציאה'];
$resultHe = [
    'ok' => ['✔ נכנס', '#155724'], 'code_ok' => ['✔ קוד התקבל', '#155724'], 'opened' => ['נפתח', '#555'], 'reopen' => ['✔ כבר מחובר', '#155724'],
    'preview' => ['סורק תצוגה מקדימה (נחסם)', '#888'], 'used' => ['✖ הקישור כבר שימש', '#a94400'],
    'expired_or_invalid' => ['✖ פג תוקף / לא תקין', '#a94400'], 'no_token' => ['✖ בלי קישור', '#a94400'],
    'code_invalid' => ['✖ קוד שגוי / פג תוקף', '#a94400'], 'code_limited' => ['🔒 יותר מדי קודים שגויים', '#a94400'],
    'blocked' => ['⛔ חסום', '#721c24'], 'refused' => ['⛔ נדחה', '#721c24'],
    'closed_blocked' => ['⛔ נותק — חסום', '#721c24'], 'closed_disconnected' => ['נותק (נתק)', '#a94400'],
];
$deviceOf = function (string $ua): string {
    if (preg_match('/facebookexternalhit|facebot|bot\b|crawler|spider|preview/i', $ua)) return 'סורק';
    if ($ua === '') return '—';
    $os = preg_match('/iPhone|iPad/i', $ua) ? 'iPhone' : (preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Mac OS/i', $ua) ? 'Mac' : '')));
    $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : '')));
    return trim("{$os} {$br}") ?: mb_substr($ua, 0, 30);
};
$ilLog = function (string $ts) {
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $ts, new DateTimeZone('Asia/Jerusalem'));
    if (!$d) return $ts;
    return $d->format('Y-m-d') === (new DateTime('now', new DateTimeZone('Asia/Jerusalem')))->format('Y-m-d') ? 'היום ' . $d->format('H:i:s') : $d->format('d/m H:i:s');
};
?>
<h3 id="log">כניסות אחרונות</h3>
<form method="get" action="users.php#log" style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
    <select name="log_user" onchange="this.form.submit()" style="margin:0;padding:8px;font-size:15px;width:auto">
        <option value="">כל המשתמשים</option>
        <?php foreach (array_merge(array_keys($users), POAGENT_USERS) as $id): ?>
            <option value="<?= htmlspecialchars($id) ?>"<?= $logUser === (string) $id ? ' selected' : '' ?>><?= htmlspecialchars($whoName((string) $id)) ?></option>
        <?php endforeach; ?>
    </select>
    <span class="muted" style="font-size:14px">50 האחרונות, משבוע אחרון · מהיומן POAgent/logs/login_*.log</span>
</form>
<?php if (!$logRows): ?>
    <p class="muted">אין כניסות ביומן<?= $logUser !== '' ? ' למשתמש הזה' : '' ?> בשבוע האחרון.</p>
<?php else: ?>
<table style="font-size:14px">
    <tr><th>מתי</th><th>משתמש</th><th>דרך</th><th>תוצאה</th><th>מכשיר</th><th>IP</th></tr>
    <?php foreach ($logRows as $e): $r = $resultHe[$e['result'] ?? ''] ?? [(string) ($e['result'] ?? ''), '#333']; ?>
        <tr<?= ($e['result'] ?? '') === 'preview' ? ' style="opacity:.55"' : '' ?>>
            <td style="white-space:nowrap"><?= htmlspecialchars($ilLog((string) ($e['ts'] ?? ''))) ?></td>
            <td><?= htmlspecialchars($whoName((string) ($e['user'] ?? ''))) ?></td>
            <td><?= htmlspecialchars($kindHe[$e['kind'] ?? ''] ?? (string) ($e['kind'] ?? '')) ?></td>
            <td style="color:<?= $r[1] ?>"><?= htmlspecialchars($r[0]) ?></td>
            <td title="<?= htmlspecialchars((string) ($e['ua'] ?? '')) ?>"><?= htmlspecialchars($deviceOf((string) ($e['ua'] ?? ''))) ?></td>
            <td style="direction:ltr;text-align:right;font-size:12px" class="muted"><?= htmlspecialchars(in_array($e['ip'] ?? '', ['::1', '127.0.0.1'], true) ? 'המחשב הזה' : (string) ($e['ip'] ?? '')) ?></td>
        </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<?php poagent_render_foot(); ?>

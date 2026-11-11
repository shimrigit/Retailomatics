<?php
// bench_report.php — the WhatsApp ordering benchmark's measurements
// (whatsapp/logs/bench_*.log, written by whatsapp/bench_common.php): a row per
// ordering attempt, and the averages per method × scenario for the spec's
// comparison table. This computer only, like the other tools/ pages.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    http_response_code(403);
    exit('Available on this computer only.');
}
require_once __DIR__ . '/../lib/ui_common.php';
require_once __DIR__ . '/../lib/UserAccess.php';
require_once __DIR__ . '/../whatsapp/bot.php';

$rows = poagent_bench_read_log();
$names = [];
foreach ((json_decode((string) @file_get_contents(__DIR__ . '/../../whatsapp_app/apps.json'), true)['apps'] ?? []) as $app) {
    foreach ($app['allowed_senders'] ?? [] as $s) if (is_array($s)) $names[$s['number']] = $s['name'];
}
$il = new DateTimeZone('Asia/Jerusalem');
$when = fn($iso) => (new DateTime($iso))->setTimezone($il)->format('d/m H:i');
$modeName = fn($m) => POAGENT_WA_MODES[$m] ?? $m;

// Averages per method × scenario — created orders only (an abandoned attempt has no time-to-PO).
$groups = [];
foreach ($rows as $r) {
    if (($r['outcome'] ?? '') !== 'created') continue;
    $k = ($r['mode'] ?? '') . '|' . ($r['scenario'] ?? '');
    $groups[$k][] = $r;
}
ksort($groups);
$avg = fn(array $g, string $f) => round(array_sum(array_column($g, $f)) / max(1, count($g)), 1);
$outcomeHe = ['created' => '✔ נוצרה', 'cancelled' => '✖ בוטלה', 'abandoned' => '… ננטשה'];

poagent_render_head('POAgent – מדידת בנצ\'מרק וואטסאפ', 1100);
?>
<h2>מדידת בנצ'מרק — הזמנה בוואטסאפ</h2>
<div class="poagent-nav"><a class="btn secondary" href="../users.php">← משתמשים (בחירת שיטה)</a></div>

<h3>ממוצעים לפי שיטה ותרחיש <span class="muted" style="font-size:14px">(הזמנות שנוצרו)</span></h3>
<?php if (!$groups): ?>
    <p class="muted">עוד אין הזמנות שנוצרו בבנצ'מרק.</p>
<?php else: ?>
<table>
    <tr><th>שיטה</th><th>תרחיש</th><th>ניסיונות</th><th>זמן עד PO (שנ')</th><th>פעולות</th><th>הקשות</th><th>הודעות מוקלדות</th><th>חיפושים ריקים</th><th>שורות</th></tr>
    <?php foreach ($groups as $k => $g): [$mode, $scenario] = explode('|', $k); ?>
        <tr><td><?= htmlspecialchars($modeName($mode)) ?></td><td><?= htmlspecialchars($scenario ?: '—') ?></td><td><?= count($g) ?></td>
            <td><?= $avg($g, 'duration_s') ?></td>
            <td><?= round(array_sum(array_map(fn($r) => $r['taps'] + $r['typed_msgs'], $g)) / count($g), 1) ?></td>
            <td><?= $avg($g, 'taps') ?></td><td><?= $avg($g, 'typed_msgs') ?></td><td><?= $avg($g, 'zero_result_searches') ?></td><td><?= $avg($g, 'lines') ?></td></tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<h3>כל הניסיונות</h3>
<?php if (!$rows): ?>
    <p class="muted">הלוג ריק. בחר שיטה למשתמש במסך המשתמשים, ושלח הודעה לבוט מהטלפון שלו.</p>
<?php else: ?>
<table>
    <tr><th>התחלה</th><th>משתמש</th><th>שיטה</th><th>תרחיש</th><th>תוצאה</th><th>זמן (שנ')</th><th>הקשות</th><th>מוקלדות</th><th>חיפושים</th><th>ריקים</th><th>תיקונים</th><th>שורות</th><th>PO</th></tr>
    <?php foreach (array_reverse($rows) as $r): ?>
        <tr><td><?= htmlspecialchars($when($r['started_at'])) ?></td>
            <td><?= htmlspecialchars($names[$r['wa_id']] ?? $r['wa_id']) ?></td>
            <td><?= htmlspecialchars($modeName($r['mode'])) ?></td><td><?= htmlspecialchars($r['scenario'] ?: '—') ?></td>
            <td><?= $outcomeHe[$r['outcome']] ?? htmlspecialchars($r['outcome']) ?></td>
            <td><?= (int) $r['duration_s'] ?></td><td><?= (int) $r['taps'] ?></td><td><?= (int) $r['typed_msgs'] ?></td>
            <td><?= (int) $r['searches'] ?></td><td><?= (int) $r['zero_result_searches'] ?></td><td><?= (int) $r['edits'] ?></td>
            <td><?= (int) $r['lines'] ?></td><td><?= htmlspecialchars((string) ($r['po_id'] ?? '')) ?></td></tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>
<p class="muted">"שגיאות ב-PO" וציון הנוחות (1–5) נרשמים ידנית מול הרשימה המודפסת — לא נמדדים כאן.</p>
<?php poagent_render_foot(); ?>

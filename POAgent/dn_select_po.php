<?php
// DN flow, step 1 (spec §9 step 3) — pick which PO this delivery is against.
// Only open/prcv POs are eligible (a closed/cancelled PO has nothing left
// to deliver against). Shows POs across ALL users, not just the current
// session's generator_id — for demo purposes a delivery may need to be
// logged against any user's PO, not only the one currently "logged in"
// (mirrors po_list.php's own all-users toggle).
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/POStore.php';
$generatorId = poagent_require_generator();

$allPOs = POStore::listPOs(null);
$eligible = array_values(array_filter(
    $allPOs,
    fn($po) => in_array($po['status'] ?? '', ['open', 'prcv'], true)
));

poagent_render_head('POAgent – בחירת הזמנה לתעודת משלוח', 900);
?>
<h2>בחר הזמנת רכש לתעודת המשלוח</h2>
<p class="muted">מוצגות הזמנות של כל המשתמשים.</p>

<?php if (empty($eligible)): ?>
    <p class="muted">אין הזמנות פתוחות/חלקיות במערכת כרגע.</p>
<?php else: ?>
<table>
    <tr><th>מס' הזמנה</th><th>ספק</th><th>משתמש</th><th>נוצר ב</th><th>סטטוס</th><th>פריטים</th><th></th></tr>
    <?php foreach ($eligible as $po): ?>
    <tr>
        <td><?= htmlspecialchars($po['unique_id'] ?? '') ?></td>
        <td><?= htmlspecialchars($po['supplier_id'] ?? '') ?></td>
        <td><?= htmlspecialchars(poagent_generator_display($po)) ?></td>
        <td><?= htmlspecialchars($po['date_generated'] ?? '') ?></td>
        <td><span class="badge <?= htmlspecialchars($po['status'] ?? '') ?>"><?= htmlspecialchars($po['status'] ?? '') ?></span></td>
        <td><?= count($po['items'] ?? []) ?></td>
        <td><a href="dn_browse.php?po_core_name=<?= urlencode($po['core_name'] ?? '') ?>">📷 העלה תעודה</a></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<div class="row-gap"></div>
<a class="btn secondary" href="main_menu.php">חזרה לתפריט</a>
<?php poagent_render_foot(); ?>

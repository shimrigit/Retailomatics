<?php
// Status/history view (spec §4.1) — read-only, filename-glob backed
// (spec §8.1: "the naming convention itself IS the index").
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/POStore.php';
require_once __DIR__ . '/lib/DNStore.php';
require_once __DIR__ . '/lib/VSStore.php';
require_once __DIR__ . '/lib/UserRoles.php';
$generatorId = poagent_require_generator();
$isBou = poagent_is_bou($generatorId);

// Default to showing ALL users' POs (helps with debugging during the demo
// build) — pass ?mine=1 to narrow to just the current session's user.
// TODO: revisit after the demo, when real per-user separation matters.
$showAll = !isset($_GET['mine']);
$records = POStore::listPOs($showAll ? null : $generatorId);

// Mobile always browses with ?mine=1 (see main_menu.php), so every row
// already belongs to the same user — the "משתמש" column is dead weight on a
// phone-width screen there.
$isMobile = !empty($_SESSION['poagent_mobile']);
$showUserColumn = !$isMobile;

// Priority: POs from the cutoff date on that aren't (fully) in Priority yet
// are flagged; ?pending=1 shows only those — the queue the user sends one by
// one from priority_create.php (no "send all" for now).
$pendingCount = $partialCount = $missingCount = 0;
foreach ($records as $po) {
    $state = poagent_priority_state($po);
    $pendingCount += $state === 'pending';
    $partialCount += $state === 'partial';
    $missingCount += $state === 'missing';
}
$onlyPending = !empty($_GET['pending']);
if ($onlyPending) {
    $records = array_values(array_filter($records,
        fn($po) => in_array(poagent_priority_state($po), ['pending', 'partial', 'missing'], true)));
}
$listUrl = fn(bool $pending) => 'po_list.php?' . http_build_query(array_filter(
    ['mine' => $showAll ? null : 1, 'pending' => $pending ? 1 : null]));

poagent_render_head('POAgent – היסטוריית הזמנות', 1050);
?>
<h2>הזמנות רכש<?= $showAll ? ' (כל המשתמשים)' : ' — ' . htmlspecialchars($generatorId) ?></h2>

<p>
    <?php if ($pendingCount + $partialCount + $missingCount > 0): ?>
        <strong style="color:#856404">⚠️ <?= $pendingCount ?> הזמנות ממתינות לשליחה לפריוריטי<?=
            $partialCount ? " · {$partialCount} נפתחו חלקית — לבדוק" : '' ?><?=
            $missingCount ? " · {$missingCount} נפתחו עם חוסרים — לטפל" : '' ?></strong>
    <?php else: ?>
        <span class="muted">✅ כל ההזמנות (מ-<?= date('d/m/Y', strtotime(POAGENT_PRIORITY_CUTOFF)) ?>) נמצאות בפריוריטי.</span>
    <?php endif; ?>
    &nbsp;
    <label style="cursor:pointer">
        <input type="checkbox" <?= $onlyPending ? 'checked' : '' ?>
               onchange="location.href=this.checked ? <?= htmlspecialchars(json_encode($listUrl(true))) ?> : <?= htmlspecialchars(json_encode($listUrl(false))) ?>">
        הצג רק ממתינות
    </label>
</p>

<?php if (empty($records)): ?>
    <p class="muted"><?= $onlyPending ? 'אין הזמנות ממתינות לפריוריטי.' : 'אין הזמנות עדיין.' ?></p>
<?php else: ?>
<table class="responsive-table">
    <thead>
    <tr>
        <th>מס' הזמנה</th><th>ספק</th><?php if ($showUserColumn): ?><th>משתמש</th><?php endif; ?><th>נוצר ב</th><th>סטטוס</th><th>פריוריטי</th><th>פריטים</th><th>סה"כ</th>
        <th>תעודות ודוחות</th>
    </tr>
    </thead>
    <?php foreach ($records as $po): ?>
    <?php
        $coreName = $po['core_name'] ?? '';
        $dnCount = count(DNStore::listForPo($coreName));
        $vsList = VSStore::listForPo($coreName);
        $vsCount = count($vsList);
        $vsHasVariance = array_reduce($vsList, fn($carry, $v) => $carry || ($v['status'] ?? '') === 'variance', false);
        // Same source of truth as po_view.php/dn_process_ocr.php — a pending
        // photo's existence, not the PO's status string (see those files'
        // comments for why the status alone can drift and shouldn't be
        // trusted here).
        $hasPending = DNStore::findPendingImage($coreName) !== null;
    ?>
    <tr>
        <td data-label="מס' הזמנה"><?= htmlspecialchars($po['unique_id'] ?? '') ?></td>
        <td data-label="ספק"><?= htmlspecialchars($po['supplier_id'] ?? '') ?></td>
        <?php if ($showUserColumn): ?><td data-label="משתמש"><?= htmlspecialchars(poagent_generator_display($po)) ?></td><?php endif; ?>
        <td data-label="נוצר ב"><?= htmlspecialchars($po['date_generated'] ?? '') ?></td>
        <td data-label="סטטוס"><span class="badge <?= htmlspecialchars($po['status'] ?? '') ?>"><?= htmlspecialchars($po['status'] ?? '') ?></span></td>
        <td data-label="פריוריטי">
            <?php
                $priorityState = poagent_priority_state($po);
                $priorityHref = 'priority_create.php?core_name=' . urlencode($coreName);
                $priorityBadge = [
                    'sent'    => ['closed', $po['priority']['po_number'] ?? ''],
                    'partial' => ['cancelled', 'חלקית ' . ($po['priority']['po_number'] ?? '')],
                    'missing' => ['preocr', ($po['priority']['po_number'] ?? '') . ' · חסרים '
                        . count($po['priority']['skipped_lines'] ?? [])],
                    'pending' => ['open', 'לא נשלחה'],
                ][$priorityState] ?? null;
            ?>
            <?php if ($priorityBadge === null): ?>
                <span class="muted">—</span>
            <?php elseif ($isMobile): // sending runs on the office computer ?>
                <span class="badge <?= $priorityBadge[0] ?>"><?= htmlspecialchars($priorityBadge[1]) ?></span>
            <?php else: ?>
                <a href="<?= htmlspecialchars($priorityHref) ?>" style="text-decoration:none">
                    <span class="badge <?= $priorityBadge[0] ?>"><?= htmlspecialchars($priorityBadge[1]) ?></span></a>
            <?php endif; ?>
        </td>
        <td data-label="פריטים"><?= count($po['items'] ?? []) ?></td>
        <td data-label="סה&quot;כ"><?= number_format(POStore::totalAgorot($po) / 100, 2) ?> ₪</td>
        <td data-label="תעודות ודוחות">
            <a href="po_view.php?core_name=<?= urlencode($coreName) ?>">
                🔍 צפייה
                <?php if ($dnCount > 0): ?>
                    &nbsp;|&nbsp;📷 <?= $dnCount ?>
                    &nbsp;<?= $vsHasVariance ? '⚠' : '✔' ?> <?= $vsCount ?>
                <?php endif; ?>
            </a>
            <?php if ($hasPending && $isBou): ?>
                &nbsp;|&nbsp;
                <a href="dn_process_ocr.php?po_core_name=<?= urlencode($coreName) ?>">🔄 עבד OCR</a>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<a class="btn secondary" href="po_list.php?<?= http_build_query(array_filter(['mine' => $showAll ? 1 : null, 'pending' => $onlyPending ? 1 : null])) ?>"><?= $showAll ? 'הצג רק שלי' : 'הצג את כולם' ?></a>
<div class="row-gap"></div>
<a class="btn secondary" href="main_menu.php">חזרה לתפריט</a>
<?php poagent_render_foot(); ?>

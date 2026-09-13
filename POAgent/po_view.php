<?php
// PO detail view — reached from po_list.php's single unified action column
// (explicit request: unite the old separate DN column / VS column / view
// column, and unite the three separate pages they led to). Three panels
// side by side: PO on the RIGHT (always — same shared renderer,
// poagent_render_po_detail(), as po_success.php right after creation),
// then one row per delivery, each with the DN photo+data in the MIDDLE and
// its VS (variance report) on the LEFT. PO panel is sticky since it doesn't
// change per delivery; a PO can have more than one delivery (spec §5), so
// multiple DN/VS row-pairs stack under the shared PO panel.
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/POStore.php';
require_once __DIR__ . '/lib/DNStore.php';
require_once __DIR__ . '/lib/VSStore.php';
require_once __DIR__ . '/lib/DiffEngine.php';
$generatorId = poagent_require_generator();

$coreName = $_GET['core_name'] ?? '';
// Core names are always <user>_<supplier>_<ddmmyy-His>_PO#####, built
// server-side by POStore::createPO() — reject anything that couldn't be one
// of ours before it ever reaches glob() in loadByCoreName().
$po = (is_string($coreName) && preg_match('/^[A-Za-z0-9_-]+$/', $coreName))
    ? POStore::loadByCoreName($coreName)
    : null;

if (!$po) {
    header('Location: po_list.php');
    exit;
}

$dns = DNStore::listForPo($coreName);
$vsByDnReference = [];
foreach (VSStore::listForPo($coreName) as $vs) {
    $vsByDnReference[$vs['dn_reference'] ?? ''] = $vs;
}

poagent_render_head('POAgent – הזמנה ' . ($po['unique_id'] ?? ''), 2000);
?>
<h2>הזמנת רכש — <?= htmlspecialchars($po['unique_id'] ?? '') ?></h2>

<div class="po-view-layout">

    <div class="po-view-po-panel">
        <?php poagent_render_po_detail($po); ?>
    </div>

    <div class="po-view-deliveries">
        <?php if (empty($dns)): ?>
            <p class="muted">טרם התקבלו תעודות משלוח עבור הזמנה זו.</p>
            <a class="btn" href="dn_select_po.php">📷 העלה תעודת משלוח</a>
        <?php else: ?>
            <?php $vsRecordsSoFar = []; ?>
            <?php foreach ($dns as $i => $dn): ?>
                <?php
                    $imageUrl = 'DNdir/' . rawurlencode($dn['image_filename'] ?? '');
                    $matchingVs = $vsByDnReference[$dn['dn_core_name'] ?? ''] ?? null;
                    if ($matchingVs !== null) {
                        $vsRecordsSoFar[] = $matchingVs;
                    }
                ?>
                <div>
                    <h3 style="margin-top:0;color:#555">
                        משלוח #<?= $i + 1 ?>
                        <?php if (!empty($dn['extracted_at'])): ?>
                            <span class="muted" style="font-size:16px;font-weight:normal">
                                (<?= htmlspecialchars(date('d/m/Y H:i', strtotime($dn['extracted_at']))) ?>)
                            </span>
                        <?php endif; ?>
                    </h3>
                    <div style="display:flex; flex-wrap:wrap; gap:24px; align-items:flex-start;">
                        <div style="flex:1 1 420px; min-width:0;">
                            <h4 style="margin-top:0;color:#555">📄 תעודת משלוח (DN)</h4>
                            <?php poagent_render_zoom_panel($imageUrl, 'תמונת תעודת משלוח', '65vh'); ?>
                            <div class="row-gap"></div>
                            <?php poagent_render_dn_detail($dn); ?>
                        </div>
                        <div style="flex:1 1 420px; min-width:0;">
                            <h4 style="margin-top:0;color:#555">📊 דוח שונות (VS)</h4>
                            <?php if ($matchingVs !== null): ?>
                                <?php poagent_render_vs_detail($matchingVs); ?>
                                <?php
                                    // Cumulative: reflects everything received up to and
                                    // including THIS delivery — same "remaining" philosophy
                                    // as the Diff Engine itself, not just this one VS in isolation.
                                    poagent_render_variance_summary(DiffEngine::summarizeForPo($po, $vsRecordsSoFar));
                                ?>
                            <?php else: ?>
                                <p class="muted">לא נמצא דוח שונות עבור תעודה זו.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<div class="row-gap"></div>
<a class="btn secondary" href="po_list.php">חזרה להיסטוריה</a>
<div class="row-gap"></div>
<a class="btn secondary" href="main_menu.php">חזרה לתפריט</a>
<?php poagent_render_foot(); ?>

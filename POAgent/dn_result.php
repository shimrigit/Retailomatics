<?php
// DN flow, final step — reads the one-time flash set by dn_confirm.php
// (POST/redirect/GET, same pattern as po_success.php) and shows the DN
// photo + OCR extraction + the Variance Statement + the PO status
// transition, SIDE BY SIDE on a wide enough screen: data on the left, a
// large in-page zoomable photo panel on the right (explicit request — no
// lightbox/separate screen, so the two can be compared directly). Below
// ~1320px (phones — see .poagent-sticky-layout in lib/ui_common.php) the two
// stack into one column instead, sticky turns off, and they scroll together.
// Data rendering is shared
// with po_view.php (the unified PO+DN+VS view reached from po_list.php)
// via poagent_render_dn_detail()/poagent_render_vs_detail() in
// ui_common.php; the photo panel via poagent_render_zoom_panel() there too.
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/VSStore.php';
require_once __DIR__ . '/lib/DiffEngine.php';
$generatorId = poagent_require_generator();

$flash = $_SESSION['poagent_last_dn'] ?? null;
unset($_SESSION['poagent_last_dn']);

if (!$flash) {
    header('Location: dn_select_po.php');
    exit;
}

$po = $flash['po'];
$dn = $flash['dn'];
$vs = $flash['vs'];
$oldStatus = $flash['old_status'];
$newStatus = $flash['new_status'];
$imageUrl = 'DNdir/' . rawurlencode($dn['image_filename'] ?? '');

poagent_render_head('POAgent – תוצאת עיבוד תעודת המשלוח', 1700);
?>
<h2>תוצאת עיבוד תעודת המשלוח</h2>

<div class="poagent-sticky-layout">

    <div class="poagent-sticky-panel" style="flex:1 1 480px;">
        <?php poagent_render_zoom_panel($imageUrl, 'תמונת תעודת משלוח', '85vh'); ?>
    </div>

    <div class="poagent-sticky-fill" style="flex:1 1 560px;">
        <p>
            <strong>הזמנה:</strong> <?= htmlspecialchars($po['unique_id'] ?? '') ?>
            (<?= htmlspecialchars($po['supplier_id'] ?? '') ?>)
            &nbsp;|&nbsp;
            <strong>סטטוס:</strong>
            <span class="badge <?= htmlspecialchars($oldStatus) ?>"><?= htmlspecialchars($oldStatus) ?></span>
            <?php if ($newStatus !== $oldStatus): ?>
                ← <span class="badge <?= htmlspecialchars($newStatus) ?>"><?= htmlspecialchars($newStatus) ?></span>
            <?php else: ?>
                <span class="muted">(ללא שינוי)</span>
            <?php endif; ?>
        </p>

        <h3 style="margin-top:32px;color:#555">📄 מה נקרא מהתמונה (OCR)</h3>
        <?php poagent_render_dn_detail($dn); ?>

        <h3 style="margin-top:32px;color:#555">📊 דוח שונות (VS) — משלוח #<?= (int) $vs['delivery_index'] ?></h3>
        <?php poagent_render_vs_detail($vs); ?>
        <?php
            // Cumulative across every delivery on record for this PO,
            // including the one just confirmed (dn_confirm.php already
            // wrote it before redirecting here) — same philosophy as
            // po_view.php's running per-delivery summary.
            $allVsForPo = VSStore::listForPo($po['core_name'] ?? '');
            poagent_render_variance_summary(DiffEngine::summarizeForPo($po, $allVsForPo));
        ?>

        <div class="row-gap"></div>
        <a class="btn" href="dn_select_po.php">📷 ייבוא תעודה נוספת</a>
        <div class="row-gap"></div>
        <a class="btn secondary" href="po_view.php?core_name=<?= urlencode($po['core_name'] ?? '') ?>">🔍 צפייה בהזמנה</a>
        <div class="row-gap"></div>
        <a class="btn secondary" href="main_menu.php">חזרה לתפריט</a>
    </div>

</div>
<?php poagent_render_foot(); ?>

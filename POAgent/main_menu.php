<?php
// Screen 2 — main menu (spec §4.1). Two audiences:
//   • Desktop  — reached via index.php (pick user1/2/3). Full menu incl.
//     "Upload DN" and "switch user".
//   • Mobile   — reached via m/index.php (WhatsApp login link). Identified by
//     phone number; menu is PO-create + history + logout only. DN upload and
//     "switch user" are desktop-only.
session_start();
require_once __DIR__ . '/lib/ui_common.php';
$generatorId = poagent_require_generator();

$isMobile    = !empty($_SESSION['poagent_mobile']);
$displayName = $_SESSION['poagent_display_name'] ?? $generatorId;

poagent_render_head('POAgent – תפריט ראשי');
?>
<h2>שלום, <?= htmlspecialchars($displayName) ?></h2>

<a class="btn" href="po_supplier.php">➕ צור הזמנת רכש (PO)</a>
<div class="row-gap"></div>
<?php if (!$isMobile): ?>
<a class="btn" href="dn_select_po.php">📷 העלה תעודת משלוח</a>
<div class="row-gap"></div>
<?php endif; ?>
<a class="btn secondary" href="po_list.php<?= $isMobile ? '?mine=1' : '' ?>">📋 היסטוריית הזמנות</a>
<div class="row-gap"></div>
<?php if ($isMobile): ?>
<a class="btn secondary" href="m/logout.php">🚪 יציאה</a>
<?php else: ?>
<a class="btn secondary" href="index.php">החלף משתמש</a>
<?php endif; ?>

<?php poagent_render_foot(); ?>

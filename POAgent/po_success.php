<?php
// PO flow, step 5 — confirmation screen. Reads the one-time flash set by po_create.php.
session_start();
require_once __DIR__ . '/lib/ui_common.php';
$generatorId = poagent_require_generator();

$record = $_SESSION['poagent_last_po'] ?? null;
unset($_SESSION['poagent_last_po']);

if (!$record) {
    header('Location: po_list.php');
    exit;
}

poagent_render_head('POAgent – הזמנה נוצרה');
?>
<h2>✅ הזמנת רכש נוצרה</h2>
<?php poagent_render_po_detail($record); ?>

<?php poagent_render_priority_link($record); ?>
<div class="row-gap"></div>

<a class="btn" href="po_supplier.php">➕ הזמנה נוספת</a>
<div class="row-gap"></div>
<a class="btn secondary" href="po_list.php">📋 היסטוריית הזמנות</a>
<div class="row-gap"></div>
<a class="btn secondary" href="main_menu.php">חזרה לתפריט</a>
<?php poagent_render_foot(); ?>

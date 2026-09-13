<?php
// Shown to an FU (field user, see lib/UserRoles.php) right after uploading a
// DN photo — desktop's dn_import.php and mobile's dn_upload_photo.php both
// redirect/report here instead of running OCR. There is deliberately no
// review/data to show: an FU never sees the OCR draft at all, only a plain
// confirmation that the photo is safely saved and the PO now needs a BOU
// (backoffice user) to process it — matches the request that only a BOU
// signs off on OCR accuracy.
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/POStore.php';
$generatorId = poagent_require_generator();

$poCoreName = $_GET['po_core_name'] ?? '';
$po = (is_string($poCoreName) && preg_match('/^[A-Za-z0-9_-]+$/', $poCoreName))
    ? POStore::loadByCoreName($poCoreName)
    : null;

poagent_render_head('POAgent – התמונה הועלתה');
?>
<h2>✅ התמונה הועלתה בהצלחה</h2>

<?php if ($po): ?>
<p>
    <strong>הזמנה:</strong> <?= htmlspecialchars($po['unique_id'] ?? '') ?>
    (<?= htmlspecialchars($po['supplier_id'] ?? '') ?>)
    &nbsp; <span class="badge preocr">ממתין לעיבוד</span>
</p>
<?php endif; ?>

<p class="muted">
    תעודת המשלוח נשמרה. הצוות במשרד יעבד את הנתונים (OCR ובדיקה) ויעדכן את סטטוס ההזמנה בהמשך —
    אין צורך בפעולה נוספת מכאן.
</p>

<div class="row-gap"></div>
<a class="btn" href="dn_select_po.php">📷 העלאת תעודה נוספת</a>
<div class="row-gap"></div>
<a class="btn secondary" href="po_list.php<?= !empty($_SESSION['poagent_mobile']) ? '?mine=1' : '' ?>">📋 היסטוריית הזמנות</a>
<div class="row-gap"></div>
<a class="btn secondary" href="main_menu.php">חזרה לתפריט</a>
<?php poagent_render_foot(); ?>

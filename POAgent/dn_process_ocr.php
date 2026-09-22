<?php
// BOU-only (see lib/UserRoles.php) — landing page for po_list.php's "🔄 עבד
// OCR" link. Validates role/PO state up front (same checks
// dn_process_ocr_run.php repeats server-side — this is just so a bad link
// bounces immediately instead of flashing a waiting page first), then hands
// off to that AJAX endpoint for the actual OCR call, showing progress and a
// retry-on-failure instead of a blank synchronous wait — see that file's
// header comment for why.
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/POStore.php';
require_once __DIR__ . '/lib/DNStore.php';
require_once __DIR__ . '/lib/UserRoles.php';
$generatorId = poagent_require_generator();

if (poagent_is_fu($generatorId)) {
    header('Location: po_list.php');
    exit;
}

$poCoreName = $_GET['po_core_name'] ?? '';
if (!preg_match('/^[A-Za-z0-9_-]+$/', $poCoreName)) {
    header('Location: po_list.php');
    exit;
}
$po = POStore::loadByCoreName($poCoreName);
if ($po === null) {
    header('Location: po_list.php');
    exit;
}
// A pending photo's EXISTENCE (DNStore::findPendingImage(), not the PO's
// status string) is the real source of truth here — same as po_view.php's
// own detection. The status field can drift away from 'preocr' (e.g. an
// abandoned/incomplete BOU upload that never went through the one branch
// that sets it — dn_import.php's FU path) while a genuinely unprocessed
// photo still sits in DNdir/; requiring status === 'preocr' here would
// refuse to process a photo po_view.php is showing as pending, which is
// exactly the bug this comment used to not explain.
if (DNStore::findPendingImage($poCoreName) === null) {
    header('Location: po_list.php');
    exit;
}

poagent_render_head('POAgent – מעבד OCR');
?>
<h2>עיבוד OCR — <?= htmlspecialchars($po['unique_id'] ?? '') ?> (<?= htmlspecialchars($po['supplier_id'] ?? '') ?>)</h2>

<input type="hidden" id="dn_po_core_name" value="<?= htmlspecialchars($poCoreName) ?>">
<p id="dn_status" class="muted">⏳ מריץ OCR על התמונה הממתינה, בבקשה המתן… (עד כ-15 שניות, לעיתים יותר)</p>
<div id="dn_error_actions" class="row-gap" hidden>
    <button type="button" class="btn" id="dn_retry_btn">🔁 נסה שוב</button>
</div>

<div class="row-gap"></div>
<a class="btn secondary" href="po_list.php">חזרה לרשימת ההזמנות</a>

<script>
(function () {
    var poCoreName = document.getElementById('dn_po_core_name').value;
    var status = document.getElementById('dn_status');
    var errorActions = document.getElementById('dn_error_actions');
    var retryBtn = document.getElementById('dn_retry_btn');

    function runOcr() {
        status.textContent = '⏳ מריץ OCR על התמונה הממתינה, בבקשה המתן… (עד כ-15 שניות, לעיתים יותר)';
        errorActions.hidden = true;

        var formData = new FormData();
        formData.append('po_core_name', poCoreName);

        fetch('dn_process_ocr_run.php', { method: 'POST', body: formData })
            .then(function (res) {
                return res.json().catch(function () {
                    throw new Error('תגובה לא צפויה מהשרת (סטטוס ' + res.status + ').');
                });
            })
            .then(function (data) {
                if (data.ok) {
                    status.textContent = '✅ ה-OCR הושלם, עובר לבדיקה…';
                    window.location.href = data.redirect || 'dn_review.php';
                    return;
                }
                status.textContent = '⚠️ ' + (data.error || 'שלב ה-OCR נכשל.');
                errorActions.hidden = false;
            })
            .catch(function (err) {
                status.textContent = '⚠️ ' + (err.message || 'שגיאת רשת בשלב ה-OCR.');
                errorActions.hidden = false;
            });
    }

    retryBtn.addEventListener('click', runOcr);
    runOcr();
})();
</script>

<?php poagent_render_foot(); ?>

<?php
// DN flow, step 2 — MOBILE variant (spec §9 step 3). Desktop's step 2 is a
// server-side folder browser (dn_browse.php) since the photo already lives
// on this same machine — that has no equivalent on a phone, where the photo
// lives in the camera/photo library instead. This screen offers two plain
// triggers over a single <input type=file>: "צלם" sets capture="environment"
// right before opening the picker (native camera UI), "העלה" removes it
// (native photo-library picker).
//
// The upload + OCR then run as two separate AJAX calls — dn_upload_photo.php
// (fast: just saves the file), then dn_ocr_process.php (slow: the actual
// vision-API call) — reported to the user as two distinct stages instead of
// one long silent wait behind a plain form-submit navigation, since a user
// with no feedback during the slow OCR call reads that as "stuck." Desktop's
// dn_browse.php → dn_import.php (single combined step) is untouched.
//
// Each stage also reports and displays how long it actually took (kept
// visible, not auto-advanced past) — the diagnostic this is for: a slow
// upload with a tiny server-reported processing time is the phone's
// connection; a slow OCR call is essentially always AI processing time, not
// bandwidth, since that request carries no file body. See dn_upload_photo.php
// and dn_ocr_process.php's own comments on the server_ms/ocr_ms fields.
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/POStore.php';
$generatorId = poagent_require_generator();

$poCoreName = $_GET['po_core_name'] ?? '';
if (!preg_match('/^[A-Za-z0-9_-]+$/', $poCoreName)) {
    header('Location: dn_select_po.php');
    exit;
}
$po = POStore::loadByCoreName($poCoreName);
if ($po === null) {
    header('Location: dn_select_po.php');
    exit;
}

poagent_render_head('POAgent – תמונת תעודת משלוח');
?>
<h2>תעודת משלוח עבור <?= htmlspecialchars($po['unique_id'] ?? '') ?> (<?= htmlspecialchars($po['supplier_id'] ?? '') ?>)</h2>
<p class="muted">צלמו את תעודת המשלוח, או העלו תמונה קיימת מהמכשיר.</p>

<input type="hidden" id="dn_po_core_name" value="<?= htmlspecialchars($poCoreName) ?>">
<input type="file" id="dn_photo_input" name="photo" accept="image/*" hidden>

<button type="button" class="btn" id="dn_camera_btn">📷 צלם תעודת משלוח</button>
<div class="row-gap"></div>
<button type="button" class="btn secondary" id="dn_gallery_btn">🖼️ העלה תעודת משלוח</button>

<p id="dn_status" class="muted row-gap" hidden></p>
<ul id="dn_timing_log" class="muted" style="margin:0;padding-inline-start:20px;font-size:15px;" hidden></ul>
<div id="dn_error_actions" class="row-gap" hidden>
    <button type="button" class="btn" id="dn_retry_btn"></button>
</div>
<div id="dn_continue_actions" class="row-gap" hidden>
    <button type="button" class="btn" id="dn_continue_btn">המשך לבדיקת הנתונים ←</button>
</div>

<div class="row-gap"></div>
<a class="btn secondary" href="dn_select_po.php">חזרה לבחירת הזמנה</a>

<script>
(function () {
    var input = document.getElementById('dn_photo_input');
    var poCoreName = document.getElementById('dn_po_core_name').value;
    var status = document.getElementById('dn_status');
    var timingLog = document.getElementById('dn_timing_log');
    var cameraBtn = document.getElementById('dn_camera_btn');
    var galleryBtn = document.getElementById('dn_gallery_btn');
    var errorActions = document.getElementById('dn_error_actions');
    var retryBtn = document.getElementById('dn_retry_btn');
    var continueActions = document.getElementById('dn_continue_actions');
    var continueBtn = document.getElementById('dn_continue_btn');
    var redirectTarget = 'dn_review.php';

    function seconds(ms) {
        return (ms / 1000).toFixed(1) + ' שניות';
    }

    // One line per completed stage, kept on screen (not overwritten) so both
    // are still readable once the flow finishes — the actual diagnostic this
    // is for: is a slow upload the phone's connection (clientMs far above
    // serverMs — see dn_upload_photo.php's comment) or is a slow OCR call
    // just AI processing time (clientMs ≈ serverMs there, since that request
    // carries no file — see dn_ocr_process.php's comment)?
    function logTiming(text) {
        var li = document.createElement('li');
        li.textContent = text;
        timingLog.appendChild(li);
        timingLog.hidden = false;
    }

    // Toggling "capture" right before opening the same input is what steers
    // which native picker shows up — present it for the camera, remove it
    // for the library. Two separate <input> elements sharing one name would
    // both submit (one empty), and PHP keeps only the last one it sees —
    // silently dropping whichever the user actually filled in.
    cameraBtn.addEventListener('click', function () {
        input.setAttribute('capture', 'environment');
        input.click();
    });
    galleryBtn.addEventListener('click', function () {
        input.removeAttribute('capture');
        input.click();
    });

    function setBusy(text) {
        status.hidden = false;
        status.textContent = text;
        errorActions.hidden = true;
        cameraBtn.disabled = true;
        galleryBtn.disabled = true;
    }

    function showError(message, retryLabel, retryAction) {
        status.hidden = false;
        status.textContent = '⚠️ ' + message;
        if (retryAction) {
            retryBtn.textContent = retryLabel;
            retryBtn.onclick = retryAction;
            errorActions.hidden = false;
        } else {
            errorActions.hidden = true;
        }
        cameraBtn.disabled = false;
        galleryBtn.disabled = false;
    }

    async function postJson(url, formData) {
        var res = await fetch(url, { method: 'POST', body: formData });
        var data;
        try {
            data = await res.json();
        } catch (e) {
            throw new Error('תגובה לא צפויה מהשרת (סטטוס ' + res.status + ').');
        }
        return data;
    }

    // Stage 2 — OCR. Split out so a stage-2-only failure can retry just this
    // call (the photo is already saved by stage 1, kept server-side either way).
    // uploadClientMs (stage 1's own real wall-clock time — the one number
    // only the browser can measure) rides along purely so dn_ocr_process.php
    // can log it next to what it already knows about the same delivery — see
    // POAgent/tools/network_mode.php, which reads that combined log.
    function runOcr(uploadClientMs) {
        setBusy('⏳ שולח ל-OCR, בבקשה המתן… (עד כ-15 שניות)');
        var startedAt = Date.now();
        var ocrFormData = new FormData();
        if (typeof uploadClientMs === 'number') {
            ocrFormData.append('upload_client_ms', Math.round(uploadClientMs));
        }
        postJson('dn_ocr_process.php', ocrFormData)
            .then(function (data) {
                var clientMs = Date.now() - startedAt;
                if (data.ok) {
                    redirectTarget = data.redirect || 'dn_review.php';
                    logTiming('🤖 OCR הושלם תוך ' + seconds(clientMs)
                        + (typeof data.ocr_ms === 'number' ? ' (קריאת ה-AI עצמה: ' + seconds(data.ocr_ms) + ' — זה כמעט כל הזמן כאן, לא קשור לחיבור של הטלפון)' : '') + '.');
                    status.hidden = true;
                    errorActions.hidden = true;
                    continueActions.hidden = false;
                    cameraBtn.disabled = true;
                    galleryBtn.disabled = true;
                    return;
                }
                showError(data.error || 'שלב ה-OCR נכשל.', '🔁 נסה שוב', runOcr);
            })
            .catch(function (err) {
                showError(err.message || 'שגיאת רשת בשלב ה-OCR.', '🔁 נסה שוב', runOcr);
            });
    }

    // Stage 1 — upload. Only reached with a real chosen file (camera or
    // gallery). The file stays selected in the input on failure, so "retry"
    // re-attempts the same photo directly — the camera/gallery buttons stay
    // enabled too, for picking a different one instead if that's the issue.
    function uploadPhoto() {
        if (!input.files || !input.files.length) return;
        setBusy('⏳ מעלה תמונה…');
        timingLog.innerHTML = '';
        timingLog.hidden = true;
        var startedAt = Date.now();

        var formData = new FormData();
        formData.append('po_core_name', poCoreName);
        formData.append('photo', input.files[0]);

        postJson('dn_upload_photo.php', formData)
            .then(function (data) {
                var clientMs = Date.now() - startedAt;
                if (data.ok) {
                    logTiming('📤 העלאה הושלמה תוך ' + seconds(clientMs)
                        + (typeof data.server_ms === 'number' ? ' (עיבוד בשרת: ' + seconds(data.server_ms) + ' — כל השאר הוא זמן העברה בפועל)' : '') + '.');
                    runOcr(clientMs);
                    return;
                }
                showError(data.error || 'העלאת התמונה נכשלה.', '🔁 נסה שוב', uploadPhoto);
            })
            .catch(function (err) {
                showError(err.message || 'שגיאת רשת בהעלאת התמונה.', '🔁 נסה שוב', uploadPhoto);
            });
    }

    continueBtn.addEventListener('click', function () {
        window.location.href = redirectTarget;
    });

    input.addEventListener('change', uploadPhoto);
})();
</script>

<?php poagent_render_foot(); ?>

<?php
// "קבלת סחורה מספק בפריוריטי" — enters one delivery note (after its variance
// report) in Priority as a goods receipt, through Playwright
// (lib/PriorityTasks.php::receiveGoods() → C:\RetailAgents\Priority\receive-goods.js).
// GET ?dn=<dn_core_name>: the delivery note, what must be ready first, the
// DN number/date to use (prefilled from the OCR) and the button — or the
// receipt (GR26...) if it was already made. POST: runs it (≈30-60 seconds,
// synchronously) and shows the outcome, with instructions for each error.
// The receipt is left as טיוטא — the user checks it and makes it סופית.
// Debugging period: a manual button, desktop only.
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/POStore.php';
require_once __DIR__ . '/lib/DNStore.php';
require_once __DIR__ . '/lib/PriorityTasks.php';
poagent_require_generator();

$dnCoreName = $_REQUEST['dn'] ?? '';
$dn = is_string($dnCoreName) ? DNStore::loadByCoreName($dnCoreName) : null;
$po = $dn ? POStore::loadByCoreName($dn['po_core_name'] ?? '') : null;
if (!$dn || !$po) {
    header('Location: po_list.php');
    exit;
}

$run = null;
$formError = null;
$markedManually = false;
$dnNumber = trim((string) ($_POST['dn_number'] ?? ($dn['dn_number_ocr'] ?? '')));
$dnDate = $_POST['dn_date'] ?? (poagent_parse_dn_date($dn['dn_date_ocr'] ?? '') ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_manual') {
    // "סמן כהושלמה ידנית בפריוריטי" — no Priority run, just the record.
    $markedManually = PriorityTasks::markReceiptCompletedManually(
        $dnCoreName,
        $_SESSION['poagent_display_name'] ?? $_SESSION['poagent_generator_id'],
        (string) ($_POST['manual_note'] ?? ''));
    $dn = DNStore::loadByCoreName($dnCoreName);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $validDate = poagent_valid_dn_date($dnDate);
    if ($dnNumber === '' || mb_strlen($dnNumber) > 30) {
        $formError = 'יש למלא את מספר התעודה של הספק (עד 30 תווים).';
    } elseif ($validDate === null) {
        $formError = 'יש לבחור את תאריך התעודה (בשנה האחרונה, לא תאריך עתידי — פריוריטי לא מקבל תאריך עתידי).';
    } else {
        session_write_close(); // the run takes ~30-60s — don't block the user's other POAgent tabs meanwhile
        $run = PriorityTasks::receiveGoods($dnCoreName, $dnNumber, $validDate, !empty($_POST['retry_partial']));
        $dn = DNStore::loadByCoreName($dnCoreName); // now carries the "priority" info
    }
}
$receipt = $dn['priority'] ?? null;
$state = poagent_priority_receipt_state($po, $dn);
$priorityPo = $po['priority']['po_number'] ?? '';

// What to tell the user for each outcome code (see PriorityTasks::receiveGoods()).
$errorHelp = poagent_priority_error_help() + [
    'ALREADY_RECEIVED' => ['תעודה זו כבר נקלטה בפריוריטי.', 'לא נוצרה קבלה נוספת.'],
    'PO_NOT_IN_PRIORITY' => ['ההזמנה עדיין לא נפתחה בפריוריטי במלואה.',
        'צור קודם את ההזמנה בפריוריטי (כפתור "צור הזמנת רכש בפריוריטי" בדף ההזמנה).'],
    'NO_DN_LINES' => ['בתעודה אין שורות עם כמות.', 'בדוק את התעודה ב-POAgent.'],
    'PO_NOT_SENT' => ["ההזמנה {$priorityPo} בפריוריטי עדיין בסטטוס טיוטא — פריוריטי לא מקבל סחורה מול הזמנה בטיוטא.",
        "בפריוריטי: שנה את סטטוס ההזמנה <b>{$priorityPo}</b> ל<b>נשלחה</b>. קבלת הטיוטא שנוצרה (מספר למטה) נשארה בלי שורות —"
        . ' מחק אותה בפריוריטי, ואז לחץ כאן "נסה שוב".'],
    'DN_NUMBER_USED' => ['מספר תעודת הספק כבר רשום בפריוריטי בקבלה אחרת — פריוריטי לא מקבל אותו פעמיים.',
        'אם הקבלה הקודמת היא ניסיון שנכשל — מחק אותה בפריוריטי (ואת קבלת הטיוטא שנוצרה עכשיו, מספר למטה) ולחץ "נסה שוב".'
        . ' אם זו באמת אותה תעודה — היא כבר נקלטה, אין צורך לקלוט שוב.'],
    'NOT_FOUND' => ['התעודה או ההזמנה לא נמצאו.', 'חזור לדף ההזמנה.'],
];
$actionText = [
    'approved'      => '✔ אושרה כפי שהוזמנה',
    'changed'       => '✏️ כמות שונה מההזמנה',
    'not_delivered' => '— לא סופקה (0)',
    'extra'         => '➕ נוספה (לא בהזמנה)',
];

poagent_render_head('POAgent – קבלת סחורה בפריוריטי ' . $po['unique_id'], 1000);
?>
<h2>קבלת סחורה מספק בפריוריטי — <?= htmlspecialchars($po['unique_id']) ?></h2>
<p class="muted">שלב בדיקות: הקבלה נוצרת בפריוריטי כ<b>טיוטא</b> בלבד. אחרי בדיקה — משנים בפריוריטי ל<b>סופית</b> (זה מעדכן מלאי).</p>

<?php if ($markedManually): ?>
    <div style="background:#d4edda;color:#155724;padding:14px;border-radius:8px">
        <b>✅ הקבלה סומנה כהושלמה ידנית בפריוריטי.</b>
    </div>
    <div class="row-gap"></div>
<?php endif; ?>

<?php if ($run !== null): ?>
    <?php if ($run['ok']): ?>
        <div style="background:<?= $state === 'missing' ? '#ffe0b2;color:#8a4b00' : '#d4edda;color:#155724' ?>;padding:14px;border-radius:8px">
            <b><?= $state === 'missing' ? '⚠️' : '✅' ?> הקבלה נוצרה בפריוריטי: <?= htmlspecialchars($receipt['gr_number'] ?? '') ?></b>
            (סטטוס <?= htmlspecialchars((string) ($receipt['status'] ?? '')) ?>)
            <?= $state === 'missing' ? ' — אבל ' . count($receipt['skipped_lines']) . ' פריטים לא קיימים בפריוריטי ולא נקלטו (רשימה למטה).' : '' ?>
            <br>בדוק אותה בפריוריטי ושנה את הסטטוס ל<b>סופית</b>.
        </div>
    <?php else: ?>
        <?php poagent_render_priority_error($run, $errorHelp, ['ALREADY_RECEIVED']); ?>
    <?php endif; ?>
    <div class="row-gap"></div>
<?php endif; ?>

<p>
    ספק: <b><?= htmlspecialchars($po['supplier_id']) ?></b> ·
    הזמנה בפריוריטי: <b><?= htmlspecialchars($priorityPo ?: '—') ?></b> ·
    תעודת ספק (OCR): <b><?= htmlspecialchars((string) ($dn['dn_number_ocr'] ?? '') ?: '—') ?></b>,
    <?= htmlspecialchars((string) ($dn['dn_date_ocr'] ?? '') ?: '—') ?>
</p>

<?php if ($receipt): ?>
    <h3>בפריוריטי</h3>
    <table class="responsive-table">
        <tr><th>מספר קבלה בפריוריטי</th><td><b><?= htmlspecialchars($receipt['gr_number']) ?></b></td></tr>
        <tr><th>מצב</th><td><?= [
            'sent'    => !empty($receipt['manual']) ? '<b>הושלמה ידנית בפריוריטי</b>' : 'נקלטה במלואה',
            'missing' => '<span style="color:#8a4b00"><b>נקלטה עם חוסרים</b> — פריטים שלא קיימים בפריוריטי לא נקלטו (רשימה למטה).</span>',
            'partial' => '<span style="color:#721c24"><b>נקלטה חלקית</b> — הסקריפט עצר באמצע. בדוק את הקבלה בפריוריטי.</span>',
        ][$state] ?? '' ?></td></tr>
        <?php if (!empty($receipt['manual'])): ?>
            <tr><th>הושלמה ידנית</th><td>
                ע"י <?= htmlspecialchars($receipt['manual']['by']) ?>,
                <?= htmlspecialchars(date('d/m/Y H:i', strtotime($receipt['manual']['at']))) ?>
                <?php if ($receipt['manual']['note'] !== ''): ?>
                    <br><?= nl2br(htmlspecialchars($receipt['manual']['note'])) ?>
                <?php endif; ?>
                <br><span class="muted">הקבלה בפריוריטי עשויה להיות שונה ממה שמוצג כאן.</span>
            </td></tr>
        <?php endif; ?>
        <tr><th>סטטוס בפריוריטי</th><td><?= htmlspecialchars((string) ($receipt['status'] ?? '')) ?></td></tr>
        <tr><th>מול הזמנה</th><td><?= htmlspecialchars((string) ($receipt['po_number'] ?? '')) ?></td></tr>
        <tr><th>תעודת הספק</th><td><?= htmlspecialchars((string) ($receipt['dn_number'] ?? '')) ?>, <?= htmlspecialchars((string) ($receipt['date'] ?? '')) ?></td></tr>
        <?php if (isset($receipt['total_before_vat'])): ?>
            <tr><th>סכום לפני מע"מ (פריוריטי)</th><td><?= number_format((float) $receipt['total_before_vat'], 2) ?> ₪</td></tr>
        <?php endif; ?>
        <tr><th>נוצרה</th><td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($receipt['created_at']))) ?></td></tr>
        <?php if (!empty($receipt['error'])): ?>
            <tr><th>סיבת העצירה</th><td><?= htmlspecialchars($receipt['error']) ?></td></tr>
        <?php endif; ?>
    </table>
    <?php if (!empty($receipt['lines'])): ?>
        <div class="row-gap"></div>
        <table class="responsive-table">
            <thead><tr><th>מק"ט</th><th>פריט</th><th>מה נעשה</th><th>פתוח בהזמנה</th><th>נקלט</th><th>מחיר</th></tr></thead>
            <?php foreach ($receipt['lines'] as $l): ?>
                <tr<?= ($l['action'] ?? '') === 'approved' ? '' : ' style="background:#fff8e1"' ?>>
                    <td data-label="מק&quot;ט"><?= htmlspecialchars((string) $l['sku']) ?></td>
                    <td data-label="פריט"><?= htmlspecialchars((string) ($l['description'] ?? '')) ?></td>
                    <td data-label="מה נעשה"><?= $actionText[$l['action'] ?? ''] ?? htmlspecialchars((string) ($l['action'] ?? '')) ?></td>
                    <td data-label="פתוח בהזמנה"><?= isset($l['open']) ? htmlspecialchars((string) $l['open']) : '—' ?></td>
                    <td data-label="נקלט"><?= htmlspecialchars((string) ($l['qty'] ?? '')) ?>
                        <?php if (!empty($l['overReceived'])): ?>
                            <br><span style="color:#8a4b00;font-size:13px">⚠️ יותר מהיתרה בהזמנה — פריוריטי שאל, אושר. אפשר לתקן בפריוריטי כל עוד הקבלה בטיוטא.</span>
                        <?php endif; ?></td>
                    <td data-label="מחיר"><?= number_format((float) ($l['price'] ?? 0), 2) ?> ₪
                        <?php if (!empty($l['priceChanged'])): ?>
                            <br><span style="color:#8a4b00;font-size:13px">עודכן ממחיר פריוריטי <?= number_format((float) $l['priorityPrice'], 2) ?></span>
                        <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
    <?php if (!empty($receipt['skipped_lines'])): ?>
        <div class="row-gap"></div>
        <div style="background:#f8d7da;color:#721c24;padding:14px;border-radius:8px">
            <b>❌ פריטים מהתעודה שלא נקלטו</b> — המק"ט לא קיים בפריוריטי. יש להוסיף אותם ידנית בפריוריטי (עם המק"ט הנכון).
            <table class="responsive-table">
                <thead><tr><th>מק"ט</th><th>פריט</th><th>כמות</th></tr></thead>
                <?php foreach ($receipt['skipped_lines'] as $s): ?>
                    <tr><td><?= htmlspecialchars($s['sku']) ?></td><td><?= htmlspecialchars($s['name']) ?></td>
                        <td><?= htmlspecialchars((string) ($s['qty'] ?? '')) ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
    <div class="row-gap"></div>

    <?php if (in_array($state, ['partial', 'missing'], true)): ?>
        <h3>סיימת את הקבלה ידנית בפריוריטי?</h3>
        <p class="muted">אם השלמת בפריוריטי את מה שחסר (שורות שלא הוקלדו, קבלה שנעצרה באמצע), סמן זאת כאן —
            במקום "נסה שוב" (שיוצר קבלה נוספת). כדאי לרשום מה הושלם.</p>
        <form method="post">
            <input type="hidden" name="dn" value="<?= htmlspecialchars($dnCoreName) ?>">
            <input type="hidden" name="action" value="mark_manual">
            <textarea name="manual_note" rows="3" style="width:100%;font-size:16px"
                      placeholder="מה הושלם בפריוריטי? (למשל: סומן אישור בשורת 11133)"></textarea>
            <div class="row-gap"></div>
            <button type="submit" class="btn"
                onclick="return confirm('לסמן את <?= htmlspecialchars($receipt['gr_number']) ?> כהושלמה ידנית בפריוריטי?');">
                ✔️ סמן כהושלמה ידנית בפריוריטי</button>
        </form>
        <div class="row-gap"></div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($state === 'no_po'): ?>
    <p style="color:#856404"><b>⚠️ ההזמנה עדיין לא נפתחה בפריוריטי במלואה</b> — אי אפשר לקלוט מולה סחורה.
        <a href="priority_create.php?core_name=<?= urlencode($po['core_name']) ?>">לדף ההזמנה בפריוריטי</a></p>
<?php elseif ($state === 'pending' || $state === 'partial'): ?>
    <h3>מה ייקלט</h3>
    <table class="responsive-table">
        <thead><tr><th>מק"ט</th><th>פריט</th><th>כמות בתעודה</th><th>מחיר בתעודה</th></tr></thead>
        <?php foreach ($dn['items'] ?? [] as $item): ?>
            <tr><td><?= htmlspecialchars((string) $item['barcode']) ?></td><td><?= htmlspecialchars((string) ($item['name'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) $item['qty']) ?></td><td><?= number_format((float) $item['unit_price'], 2) ?> ₪</td></tr>
        <?php endforeach; ?>
    </table>
    <p class="muted">שורה בכמות שהוזמנה — מאושרת (✔ אישור). כמות אחרת — מוקלדת, וההזמנה נשארת פתוחה ליתרה.
        מחיר שונה — מוקלד במקום מחיר פריוריטי. פריט שלא בתעודה — נשאר 0. פריט שלא בהזמנה — נוסף כשורה חדשה.</p>

    <?php poagent_render_priority_prerequisites('קבלות סחורה מספק', [
        'ההזמנה <b>' . htmlspecialchars($priorityPo) . '</b> בפריוריטי בסטטוס <b>נשלחה</b> (לא טיוטא) — פריוריטי לא מקבל סחורה מול הזמנה בטיוטא.',
    ]); ?>

    <?php if ($formError): ?>
        <p style="color:#c0392b"><b><?= htmlspecialchars($formError) ?></b></p>
    <?php endif; ?>
    <form method="post" onsubmit="var b=this.querySelector('button');b.disabled=true;b.textContent='⏳ קולט בפריוריטי… לא לגעת בכרום';">
        <input type="hidden" name="dn" value="<?= htmlspecialchars($dnCoreName) ?>">
        <p>
            <label for="dn_number"><strong>מספר תעודת הספק</strong> (נכנס ל"תעודת הספק"):</label>
            <input type="text" id="dn_number" name="dn_number" required maxlength="30" dir="ltr"
                   value="<?= htmlspecialchars($dnNumber) ?>" style="font-size:18px;padding:6px;width:220px">
        </p>
        <p>
            <label for="dn_date"><strong>תאריך התעודה</strong>:</label>
            <input type="date" id="dn_date" name="dn_date" required value="<?= htmlspecialchars((string) $dnDate) ?>"
                   min="<?= date('Y-m-d', strtotime('-1 year')) ?>" max="<?= date('Y-m-d') ?>"
                   style="font-size:18px;padding:6px">
        </p>
        <?php if ($receipt): ?>
            <input type="hidden" name="retry_partial" value="1">
            <button type="submit" class="btn"
                onclick="return confirm('תיווצר קבלה חדשה בפריוריטי. הקבלה החלקית <?= htmlspecialchars($receipt['gr_number']) ?> תישאר בפריוריטי — יש למחוק אותה שם. להמשיך?');">
                🔁 נסה שוב — קבלת סחורה חדשה בפריוריטי</button>
        <?php else: ?>
            <button type="submit" class="btn">📥 קבלת סחורה מספק בפריוריטי</button>
        <?php endif; ?>
    </form>
<?php endif; ?>

<?php poagent_render_priority_debug($run); ?>

<div class="row-gap"></div>
<a class="btn secondary" href="po_view.php?core_name=<?= urlencode($po['core_name']) ?>">הצג הזמנה</a>
<div class="row-gap"></div>
<a class="btn secondary" href="po_list.php">חזרה להיסטוריה</a>
<?php poagent_render_foot(); ?>

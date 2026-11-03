<?php
// "צור הזמנת רכש בפריוריטי" — creates a POAgent PO in Priority through
// Playwright (lib/PriorityTasks.php → C:\RetailAgents\Priority\create-po.js).
// GET: the PO, what must be ready first, and the button (or the Priority
// order number if it was already created). POST: runs it (≈30 seconds,
// synchronously) and shows the outcome, with instructions for each error.
// Debugging period: a manual button, desktop only — the script drives the
// Chrome on this computer.
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/POStore.php';
require_once __DIR__ . '/lib/PriorityTasks.php';
poagent_require_generator();

$coreName = $_REQUEST['core_name'] ?? '';
$po = (is_string($coreName) && preg_match('/^[A-Za-z0-9_-]+$/', $coreName))
    ? POStore::loadByCoreName($coreName)
    : null;
if (!$po) {
    header('Location: po_list.php');
    exit;
}

$run = null;
$markedManually = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_manual') {
    // "סמן כהושלמה ידנית בפריוריטי" — no Priority run, just the record.
    $markedManually = PriorityTasks::markCompletedManually(
        $coreName,
        $_SESSION['poagent_display_name'] ?? $_SESSION['poagent_generator_id'],
        (string) ($_POST['manual_note'] ?? ''));
    $po = POStore::loadByCoreName($coreName);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_write_close(); // the run takes ~30s — don't block the user's other POAgent tabs meanwhile
    // POs without a delivery date (WhatsApp flow, older POs) get it here first.
    if (empty($po['delivery_date']) && ($date = poagent_valid_delivery_date($_POST['delivery_date'] ?? null))) {
        POStore::setDeliveryDate($coreName, $date);
    }
    $run = PriorityTasks::createPo($coreName, !empty($_POST['retry_partial']));
    $po = POStore::loadByCoreName($coreName); // now carries the "priority" info
}
$priority = $po['priority'] ?? null;
$state = poagent_priority_state($po);
$supplierNo = SupplierStore::getPrioritySupplierNumber($po['supplier_id']);

const CHROME_CMD = '"C:\Program Files\Google\Chrome\Application\chrome.exe" --remote-debugging-port=9222 --user-data-dir="C:\RetailAgents\ChromeProfile"';

// What to tell the user for each outcome code (see PriorityTasks::createPo()).
$errorHelp = [
    'CHROME_NOT_RUNNING' => ['כרום לא פתוח במצב חיבור לפריוריטי.',
        'סגור את כל חלונות הכרום, והפעל את <b>C:\RetailAgents\Priority\start-chrome-priority.bat</b> (או את הפקודה בסעיף 1 למטה). התחבר לפריוריטי בחלון שנפתח ולחץ שוב.'],
    'PRIORITY_NOT_OPEN' => ['כרום פתוח, אבל אין בו לשונית של פריוריטי.',
        'בחלון הכרום שנפתח במצב חיבור, פתח את <b>https://p.priority-connect.online/webui/B4B4F/</b>, התחבר, ולחץ שוב.'],
    'PRIORITY_NOT_LOGGED_IN' => ['פריוריטי פתוח אבל לא מחובר.',
        'התחבר לפריוריטי עם שם המשתמש והסיסמה שלך (המערכת לעולם לא מקלידה סיסמה), ולחץ שוב.'],
    'PRIORITY_MANY_TABS' => ['פתוחה יותר מלשונית אחת של פריוריטי.',
        'סגור את הלשוניות המיותרות — השאר לשונית פריוריטי אחת בלבד — ולחץ שוב.'],
    'BUSY' => ['הזמנה אחרת נפתחת בפריוריטי ברגע זה.', 'המתן שהיא תסתיים ונסה שוב.'],
    'ALREADY_CREATED' => ['ההזמנה כבר נפתחה בפריוריטי.', 'לא נפתחה הזמנה נוספת.'],
    'NO_DELIVERY_DATE' => ['להזמנה אין תאריך אספקה, ופריוריטי דורש תאריך אספקה בכל שורה.',
        'בחר תאריך אספקה (מהיום והלאה) בשדה שמעל הכפתור ולחץ שוב.'],
    'NO_SUPPLIER_NUMBER' =>['לא נמצא מספר ספק של פריוריטי במחירון של הספק.',
        'בדוק שבקובץ המחירון ב-SuppliersDB יש עמודת "מספר ספק מועדף" עם ערך.'],
    'TIMEOUT' => ['הפעולה לא הסתיימה בזמן ונעצרה.', 'בדוק את מסך פריוריטי לפני כל פעולה נוספת.'],
    'SCRIPT_FAILED' => ['סקריפט הפריוריטי לא רץ או לא החזיר תוצאה.', 'ראה את הלוג למטה.'],
    'STOPPED' => ['הסקריפט עצר באמצע, בכוונה, כי משהו לא היה כצפוי.',
        'שום דבר נוסף לא הוקלד. בדוק את מסך פריוריטי (צילום מסך למטה).'],
    'ERROR' => ['שגיאה לא צפויה.', 'ראה את הפרטים והלוג למטה.'],
];

poagent_render_head('POAgent – פריוריטי ' . $po['unique_id'], 1000);
?>
<h2>צור הזמנת רכש בפריוריטי — <?= htmlspecialchars($po['unique_id']) ?></h2>
<p class="muted">שלב בדיקות: ההזמנה נפתחת בפריוריטי כ<b>טיוטא</b> בלבד, ורק בלחיצה על הכפתור.</p>

<?php if ($markedManually): ?>
    <div style="background:#d4edda;color:#155724;padding:14px;border-radius:8px">
        <b>✅ סומנה כהושלמה ידנית בפריוריטי.</b>
    </div>
    <div class="row-gap"></div>
<?php endif; ?>

<?php if ($run !== null): ?>
    <?php if ($run['ok'] && $state === 'missing'): ?>
        <div style="background:#ffe0b2;color:#8a4b00;padding:14px;border-radius:8px">
            <b>⚠️ ההזמנה נפתחה בפריוריטי: <?= htmlspecialchars($priority['po_number'] ?? '') ?> — אבל
            <?= count($priority['skipped_lines']) ?> פריטים לא קיימים בפריוריטי ולא נכנסו.</b>
            ראה את הרשימה למטה.
        </div>
    <?php elseif ($run['ok']): ?>
        <div style="background:#d4edda;color:#155724;padding:14px;border-radius:8px">
            <b>✅ ההזמנה נפתחה בפריוריטי: <?= htmlspecialchars($priority['po_number'] ?? '') ?></b>
            (סטטוס <?= htmlspecialchars($priority['status'] ?? '') ?>)
        </div>
    <?php else: ?>
        <?php [$what, $todo] = $errorHelp[$run['code']] ?? $errorHelp['ERROR']; ?>
        <div style="background:#f8d7da;color:#721c24;padding:14px;border-radius:8px">
            <b>❌ <?= $what ?></b><br><?= $todo ?>
            <?php if ($run['message'] !== '' && $run['code'] !== 'ALREADY_CREATED'): ?>
                <br><span style="font-size:14px">פרטים: <?= htmlspecialchars($run['message']) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <div class="row-gap"></div>
<?php endif; ?>

<?php if ($priority): ?>
    <h3>בפריוריטי</h3>
    <table class="responsive-table">
        <tr><th>מספר הזמנה בפריוריטי</th><td><b><?= htmlspecialchars($priority['po_number']) ?></b></td></tr>
        <tr><th>מצב</th><td><?= [
            'sent'    => !empty($priority['manual']) ? '<b>הושלמה ידנית בפריוריטי</b>' : 'נפתחה במלואה',
            'missing' => '<span style="color:#8a4b00"><b>נפתחה עם חוסרים</b> — פריטים שלא קיימים בפריוריטי לא נכנסו (רשימה למטה).</span>',
            'partial' => '<span style="color:#721c24"><b>נפתחה חלקית</b> — הסקריפט עצר באמצע. בדוק את ההזמנה בפריוריטי.</span>',
        ][$state] ?? '' ?></td></tr>
        <?php if (!empty($priority['manual'])): ?>
            <tr><th>הושלמה ידנית</th><td>
                ע"י <?= htmlspecialchars($priority['manual']['by']) ?>,
                <?= htmlspecialchars(date('d/m/Y H:i', strtotime($priority['manual']['at']))) ?>
                <?php if ($priority['manual']['note'] !== ''): ?>
                    <br><?= nl2br(htmlspecialchars($priority['manual']['note'])) ?>
                <?php endif; ?>
                <br><span class="muted">ההזמנה בפריוריטי עשויה להיות שונה מההזמנה ב-POAgent.</span>
            </td></tr>
        <?php endif; ?>
        <tr><th>סטטוס בפריוריטי</th><td><?= htmlspecialchars((string) ($priority['status'] ?? '')) ?></td></tr>
        <tr><th>ספק בפריוריטי</th><td><?= htmlspecialchars(($priority['supplier_number'] ?? '') . ' — ' . ($priority['supplier_name'] ?? '')) ?></td></tr>
        <?php if (isset($priority['total_before_vat'])): ?>
            <tr><th>סכום לפני מע"מ (פריוריטי)</th><td><?= number_format((float) $priority['total_before_vat'], 2) ?> ₪
                (POAgent: <?= poagent_agorot_to_ils(POStore::totalAgorot($po)) ?>)</td></tr>
        <?php endif; ?>
        <tr><th>נפתחה</th><td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($priority['created_at']))) ?></td></tr>
        <?php if (!empty($priority['error'])): ?>
            <tr><th>סיבת העצירה</th><td><?= htmlspecialchars($priority['error']) ?></td></tr>
        <?php endif; ?>
    </table>
    <?php if (!empty($priority['skipped_lines'])): ?>
        <div class="row-gap"></div>
        <div style="background:#f8d7da;color:#721c24;padding:14px;border-radius:8px">
            <b>❌ פריטים שלא נכנסו להזמנה בפריוריטי</b> — המק"ט לא קיים בפריוריטי.
            יש להוסיף אותם ידנית בפריוריטי (עם המק"ט הנכון) או לתקן את המחירון.
            <table class="responsive-table">
                <thead><tr><th>מק"ט (מהמחירון)</th><th>פריט</th><th>כמות</th></tr></thead>
                <?php
                    $qtyBySku = [];
                    foreach ($po['items'] as $item) {
                        $qtyBySku[(string) $item['barcode']] = (int) $item['qty'];
                    }
                ?>
                <?php foreach ($priority['skipped_lines'] as $s): ?>
                    <tr><td><?= htmlspecialchars($s['sku']) ?></td><td><?= htmlspecialchars($s['name']) ?></td>
                        <td><?= $qtyBySku[$s['sku']] ?? '' ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
    <?php if (!empty($priority['price_differences'])): ?>
        <div class="row-gap"></div>
        <div style="background:#fff3cd;color:#856404;padding:14px;border-radius:8px">
            <b>⚠️ הפרשי מחיר</b> — המחיר בפריוריטי שונה מהמחיר ב-POAgent. המחיר בפריוריטי <b>לא שונה</b>.
            יש להחליט אם לעדכן את המחיר בפריוריטי.
            <table class="responsive-table">
                <thead><tr><th>מק"ט</th><th>פריט</th><th>POAgent</th><th>פריוריטי</th></tr></thead>
                <?php foreach ($priority['price_differences'] as $d): ?>
                    <tr><td><?= htmlspecialchars($d['sku']) ?></td><td><?= htmlspecialchars($d['name']) ?></td>
                        <td><?= number_format((float) $d['poagent_price'], 2) ?> ₪</td>
                        <td><?= number_format((float) $d['priority_price'], 2) ?> ₪</td></tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
    <div class="row-gap"></div>

    <?php if (in_array($state, ['partial', 'missing'], true)): ?>
        <h3>סיימת את ההזמנה ידנית בפריוריטי?</h3>
        <p class="muted">אם השלמת בפריוריטי את מה שחסר (שורות שלא נכנסו, הזמנה שנעצרה באמצע), סמן זאת כאן.
            מכאן והלאה ההזמנה בפריוריטי עשויה להיות שונה מההזמנה ב-POAgent — כדאי לרשום מה שונה.</p>
        <form method="post">
            <input type="hidden" name="core_name" value="<?= htmlspecialchars($coreName) ?>">
            <input type="hidden" name="action" value="mark_manual">
            <textarea name="manual_note" rows="3" style="width:100%;font-size:16px"
                      placeholder="מה שונה בפריוריטי? (למשל: נוסף תמר מג'הול עם מק&quot;ט 14150; שורה 15 הוסרה)"></textarea>
            <div class="row-gap"></div>
            <button type="submit" class="btn"
                onclick="return confirm('לסמן את <?= htmlspecialchars($priority['po_number']) ?> כהושלמה ידנית בפריוריטי?');">
                ✔️ סמן כהושלמה ידנית בפריוריטי</button>
        </form>
        <div class="row-gap"></div>
    <?php endif; ?>
<?php endif; ?>

<?php // A run is offered for an order not yet in Priority, or to retry one that stopped half-way.
if (!$priority || $state === 'partial'): ?>
    <h3>לפני הלחיצה — לוודא:</h3>
    <ol style="line-height:1.8">
        <li>אתה עובד <b>על המחשב של המשרד</b> (המחשב שעליו רץ POAgent) — ההזמנה נפתחת בכרום של המחשב הזה.</li>
        <li><b>כרום פתוח במצב חיבור:</b> סגור את כל חלונות הכרום והפעל
            <b>C:\RetailAgents\Priority\start-chrome-priority.bat</b>, או ב-CMD:
            <pre dir="ltr" style="white-space:pre-wrap;background:#f4f4f4;padding:8px;font-size:13px"><?= htmlspecialchars(CHROME_CMD) ?></pre></li>
        <li><b>מחובר לפריוריטי</b> בחלון הזה, בלשונית <b>אחת</b> בלבד. אפשר להשאיר אותו על כל מסך — המערכת תגיע לבד להזמנות רכש.</li>
        <li>אין בפריוריטי עבודה פתוחה שלא נשמרה (המערכת סוגרת מסכים פתוחים).</li>
        <li><b>לא לגעת</b> בעכבר ובמקלדת בחלון הכרום עד לסיום (כחצי דקה).</li>
    </ol>
    <p class="muted">
        ספק: <?= htmlspecialchars($po['supplier_id']) ?> — מספר ספק בפריוריטי:
        <b><?= htmlspecialchars($supplierNo ?? 'לא נמצא') ?></b> ·
        <?= count($po['items']) ?> שורות · המחירים לא מוקלדים — פריוריטי ממלא אותם, והפרשים מדווחים כאן.
        <?php if (!empty($po['delivery_date'])): ?>
            · תאריך אספקה: <b><?= htmlspecialchars(date('d/m/Y', strtotime($po['delivery_date']))) ?></b>
        <?php endif; ?>
    </p>

    <form method="post" onsubmit="var b=this.querySelector('button');b.disabled=true;b.textContent='⏳ פותח הזמנה בפריוריטי… לא לגעת בכרום';">
        <input type="hidden" name="core_name" value="<?= htmlspecialchars($coreName) ?>">
        <?php if (empty($po['delivery_date'])): ?>
            <p>
                <label for="delivery_date"><strong>תאריך אספקה מבוקש</strong> (להזמנה זו לא נקבע תאריך — פריוריטי דורש אותו):</label>
                <input type="date" id="delivery_date" name="delivery_date" required
                       min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+1 year')) ?>"
                       style="font-size:18px;padding:6px">
            </p>
        <?php endif; ?>
        <?php if ($priority): ?>
            <input type="hidden" name="retry_partial" value="1">
            <button type="submit" class="btn"
                onclick="return confirm('תיפתח הזמנה חדשה בפריוריטי. ההזמנה החלקית <?= htmlspecialchars($priority['po_number']) ?> תישאר בפריוריטי — יש לטפל בה ידנית. להמשיך?');">
                🔁 נסה שוב — צור הזמנת רכש חדשה בפריוריטי</button>
        <?php else: ?>
            <button type="submit" class="btn">צור הזמנת רכש בפריוריטי</button>
        <?php endif; ?>
    </form>
<?php endif; ?>

<?php if ($run !== null && (!$run['ok'] || $run['log'] !== '')): ?>
    <div class="row-gap"></div>
    <details <?= $run['ok'] ? '' : 'open' ?>>
        <summary>פרטים לדיבאג</summary>
        <?php if ($run['shot']): ?>
            <p>צילום מסך של פריוריטי בסוף הריצה:</p>
            <img src="data:image/png;base64,<?= base64_encode((string) file_get_contents($run['shot'])) ?>"
                 style="max-width:100%;border:1px solid #ccc" alt="Priority screenshot">
        <?php endif; ?>
        <pre dir="ltr" style="white-space:pre-wrap;background:#f4f4f4;padding:8px;font-size:12px"><?= htmlspecialchars($run['log']) ?></pre>
    </details>
<?php endif; ?>

<div class="row-gap"></div>
<a class="btn secondary" href="po_view.php?core_name=<?= urlencode($coreName) ?>">הצג הזמנה</a>
<div class="row-gap"></div>
<a class="btn secondary" href="po_list.php">חזרה להיסטוריה</a>
<?php poagent_render_foot(); ?>

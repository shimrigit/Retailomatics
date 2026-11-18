<?php
/**
 * Retailomatics Invoice Scan — step 1: the shop, and the documents to process.
 *
 * The files waiting in preProcessDir are shown as they came (WhatsApp photos, scans): PDF,
 * JPG / JPEG, PNG — recognised by their extension. The user ticks some, or all ("בחר את
 * כולם"), and "קרא" sends them to scan.php: each file is read by the AI on its own. Which
 * photos are pages of one document is not chosen here — it is found after the reading
 * (same supplier and date → group.php asks), where the pages can be seen large.
 *
 * The old flow (PreProcess2 → harmonizedFlow, crop rectangles) is untouched and linked in
 * the header as the fallback.
 */
session_start();
require_once __DIR__ . '/lib/common.php';

$shops = scan_shops();
$lastShop = $_SESSION['invoiceScanShop'] ?? '';
$files = scan_waiting_files();
$started = scan_started();
$typeHe = ['invoice' => 'חשבונית', 'delivery_note' => 'תעודת משלוח', 'credit_or_return' => 'זיכוי / החזרה', 'other' => 'אחר'];
scan_page_start('בחירת מסמכים');
?>
<?php if ($started): ?>
    <div class="card" style="overflow-x:auto;border-color:#d9a400">
        <h2>מסמכים שהתחלתם ולא סיימתם (<?= count($started) ?>)</h2>
        <table style="width:100%">
            <tr><th>עמוד ראשון</th><th>ספק</th><th>תאריך</th><th>מספר</th><th>סוג</th><th>שורות</th><th>מצב</th><th></th></tr>
            <?php foreach ($started as $w): $d = $w['read']['data']; $ok = !empty($w['approved']);
                $m = InvoiceReader::matchSupplier($d);
                $first = $w['files'][0]; $isPdf = strtolower(pathinfo($first, PATHINFO_EXTENSION)) === 'pdf'; ?>
                <tr>
                    <td><?php if ($isPdf): ?>PDF<?php else: ?><a href="confirm.php?id=<?= scan_h($w['id']) ?>"><img src="<?= scan_source_url($w, $first) ?>" alt="" loading="lazy" style="width:90px;border:1px solid #c8cdd5;border-radius:4px"></a><?php endif; ?></td>
                    <td><b><?= scan_h($ok ? $w['approved']['supplier'] : ($m['supplier']['supplierName'] ?? ($d['supplier_name_printed'] ?: '—'))) ?></b></td>
                    <td><?= $d['doc_date'] !== '' ? scan_h(date('d/m/Y', strtotime($d['doc_date']))) : '—' ?></td>
                    <td class="num"><?= scan_h($d['doc_number'] ?: '—') ?></td>
                    <td><?= scan_h($typeHe[$d['doc_type']] ?? $d['doc_type']) ?></td>
                    <td><?= count($d['items']) ?></td>
                    <td><?= $ok ? '<span class="ok">✔ אושר</span>, ההמשך לא הושלם<br><span class="mute" dir="ltr">' . scan_h($w['approved']['pdfFile']) . '</span>' : 'נקרא, ממתין לאישור' ?></td>
                    <td><a class="btn" href="confirm.php?id=<?= scan_h($w['id']) ?>">המשך</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p class="mute">"המשך" פותח את המסמך במסך הבדיקה, עם מה שכבר נקרא ותוקן — בלי קריאה חדשה. מסמך יוצא מהרשימה כשהתהליך שלו מסתיים.</p>
    </div>
<?php endif; ?>
<form method="post" action="scan.php" id="f">
    <div class="card">
        <h2>1. חנות</h2>
        <select name="shop" id="shop" required>
            <option value="">— בחרו חנות —</option>
            <?php foreach ($shops as $s): ?>
                <option<?= $s === $lastShop ? ' selected' : '' ?>><?= scan_h($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="card">
        <h2>2. מסמכים לעיבוד <span class="mute" style="font-weight:400">(<?= count($files) ?> קבצים ב-preProcessDir)</span></h2>
        <?php if (!$files): ?>
            <p class="mute">אין קבצים. שימו קובצי PDF או תמונות (JPG / PNG) בתיקייה <code dir="ltr">preProcessDir</code> ורעננו.</p>
        <?php else: ?>
            <!-- The buttons stay at the top of the window while the list scrolls. -->
            <div style="position:sticky;top:0;z-index:3;background:#fff;padding:8px 0;border-bottom:1px solid #d9dde3;margin-bottom:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <button type="submit" id="go" disabled>קרא את המסמך</button>
                <button type="button" class="second" id="all">בחר את כולם</button>
                <button type="button" class="second" id="none">נקה בחירה</button>
                <span class="mute" id="hint"></span>
            </div>
            <p class="mute"><b>לחיצה על תמונה מציגה אותה בגדול</b> (משם אפשר גם לבחור ולעבור לבאה). הסימון עצמו — בתיבה שליד שם הקובץ.</p>
            <p class="mute">אין צורך לסדר עמודים כאן: אם יש כמה דפים מאותו ספק ותאריך, תישאלו אחרי הקריאה — עם הדפים בגדול.</p>
            <div class="files">
                <?php foreach ($files as $f): $isPdf = strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'pdf'; ?>
                    <label class="file">
                        <?php if ($isPdf): ?><div class="pdf">PDF</div>
                        <?php else: ?><img loading="lazy" src="../preProcessDir/<?= rawurlencode($f) ?>" alt=""><?php endif; ?>
                        <span><input type="checkbox" name="files[]" value="<?= scan_h($f) ?>"> <?= scan_h($f) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</form>
<!-- The large view of one file: opened by a click on its thumbnail. -->
<style>
.file img,.file .pdf{cursor:zoom-in}
#view{display:none;position:fixed;inset:0;background:rgba(20,26,38,.88);z-index:8;flex-direction:column}
#view.on{display:flex}
#view .top{background:#fff;padding:8px 14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
#view .top b{direction:ltr}
#view .body{flex:1;overflow:auto;text-align:center;padding:10px}
#view .body img{max-width:100%;cursor:zoom-in;background:#fff}
#view .body.zoom img{max-width:none;width:200%;cursor:zoom-out}
#view .body iframe{width:100%;height:100%;border:0;background:#fff}
#view label{display:flex;gap:6px;align-items:center;font-weight:600;cursor:pointer}
</style>
<div id="view">
    <div class="top">
        <button type="button" class="second" id="vClose">✕ סגור</button>
        <button type="button" class="second" id="vPrev">→ הקודם</button>
        <button type="button" class="second" id="vNext">הבא ←</button>
        <label><input type="checkbox" id="vPick" style="width:18px;height:18px"> לבחור את המסמך הזה</label>
        <b id="vName"></b><span class="mute" id="vPos"></span>
        <span class="mute">לחיצה על התמונה מגדילה · חיצים במקלדת מדפדפים · רווח בוחר · Esc סוגר</span>
    </div>
    <div class="body" id="vBody"></div>
</div>
<script>
const boxes = [...document.querySelectorAll('.file input')];
function refresh() {
    const n = boxes.filter(b => b.checked).length;
    document.getElementById('go')?.toggleAttribute('disabled', n === 0);
    if (document.getElementById('go')) document.getElementById('go').textContent = n > 1 ? 'קרא ' + n + ' קבצים' : 'קרא את המסמך';
    if (document.getElementById('hint')) document.getElementById('hint').textContent = n ? 'כ-' + Math.max(20, Math.ceil(n / 6) * 30) + ' שניות' : '';
}
boxes.forEach(b => b.addEventListener('change', refresh));
document.getElementById('all')?.addEventListener('click', () => { boxes.forEach(b => b.checked = true); refresh(); });
document.getElementById('none')?.addEventListener('click', () => { boxes.forEach(b => b.checked = false); refresh(); });
document.getElementById('f').addEventListener('submit', () => {
    const n = boxes.filter(b => b.checked).length;
    document.getElementById('busyText').textContent = n > 1 ? 'קורא ' + n + ' קבצים…' : 'קורא את המסמך…';
    document.getElementById('busy').classList.add('on');
});
refresh();

// The large view.
const view = document.getElementById('view'), vBody = document.getElementById('vBody'), vPick = document.getElementById('vPick');
const cards = [...document.querySelectorAll('.file')];
let at = -1;
function show(i) {
    at = (i + cards.length) % cards.length;
    const box = boxes[at], name = box.value, url = '../preProcessDir/' + encodeURIComponent(name);
    vBody.classList.remove('zoom');
    vBody.innerHTML = '';
    const el = document.createElement(/\.pdf$/i.test(name) ? 'iframe' : 'img');
    el.src = url;
    vBody.appendChild(el);
    vBody.scrollTop = 0;
    document.getElementById('vName').textContent = name;
    document.getElementById('vPos').textContent = (at + 1) + ' מתוך ' + cards.length;
    vPick.checked = box.checked;
    view.classList.add('on');
}
function hide() { view.classList.remove('on'); vBody.innerHTML = ''; at = -1; }
cards.forEach((card, i) => card.querySelector('img,.pdf').addEventListener('click', e => { e.preventDefault(); show(i); }));
vPick.addEventListener('change', () => { boxes[at].checked = vPick.checked; refresh(); });
document.getElementById('vClose').addEventListener('click', hide);
document.getElementById('vPrev').addEventListener('click', () => show(at - 1));
document.getElementById('vNext').addEventListener('click', () => show(at + 1));
vBody.addEventListener('click', e => { if (e.target.tagName === 'IMG') vBody.classList.toggle('zoom'); else if (e.target === vBody) hide(); });
document.addEventListener('keydown', e => {
    if (at < 0) return;
    if (e.key === 'Escape') hide();
    else if (e.key === 'ArrowLeft') show(at + 1);    // right-to-left: left = the next one
    else if (e.key === 'ArrowRight') show(at - 1);
    else if (e.key === ' ') { e.preventDefault(); vPick.checked = !vPick.checked; vPick.dispatchEvent(new Event('change')); }
});
</script>
<?php scan_page_end();

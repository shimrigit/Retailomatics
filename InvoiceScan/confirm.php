<?php
/**
 * Retailomatics Invoice Scan — step 3: what the AI identified, for the user to approve.
 *
 * The document next to: the supplier (found by ע.מ., else by name; or a new one), the
 * document's number and date, the invoice type (the OCRsanity method — detected from
 * which formula the rows satisfy), the printed columns each field was taken from, and
 * the rows. Nothing is written until "אשר" (approve.php), which continues to the existing
 * OCRsanity screen. This page can be opened again to go one step back and fix a choice.
 */
session_start();
require_once __DIR__ . '/lib/common.php';

$work = scan_load((string) ($_GET['id'] ?? ''));
$read = $work['read']['data'];
$match = InvoiceReader::matchSupplier($read);
$detected = InvoiceReader::detectMethod($read['items']);
$total = InvoiceReader::compareTotal($read);
$suppliers = InvoiceReader::suppliers();
usort($suppliers, fn($a, $b) => strcmp($a['hebrewName'] ?? $a['supplierName'], $b['hebrewName'] ?? $b['supplierName']));
$chosen = $work['approved']['supplier'] ?? ($match['supplier']['supplierName'] ?? '');
$method = $work['approved']['method'] ?? $detected['method'];
$typeHe = ['invoice' => 'חשבונית', 'delivery_note' => 'תעודת משלוח', 'credit_or_return' => 'זיכוי / החזרה', 'other' => 'אחר'];
$fieldHe = ['barcode' => 'ברקוד / קוד פריט', 'description' => 'תיאור', 'qty' => 'כמות', 'unit_price' => 'מחיר יחידה',
    'discount1_pct' => 'הנחה 1 (%)', 'discount2_pct' => 'הנחה 2 (%)', 'line_total' => 'סה"כ שורה'];
$methodHe = ['Simple' => 'Simple — בלי חישוב (אין מחירים)', 'LineTotal' => 'LineTotal — כמות × מחיר = סה"כ שורה',
    'Discount1' => 'Discount1 — עם עמודת הנחה אחת', 'Discount2' => 'Discount2 — עם שתי הנחות'];
$totalHe = ['before_discount' => 'לפני הנחה', 'before_vat' => 'לפני מע"מ', 'total' => 'סה"כ לתשלום', '' => ''];
$n = fn($v) => $v === null ? '<span class="mute">—</span>' : scan_h(rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.'));
$rowsOk = $read['rows_printed'] === null ? null : ((int) $read['rows_printed'] === count($read['items']));

// Its place among the batch's documents, and the one after it.
$batchDocs = !empty($work['batch']) ? (scan_batch_load($work['batch'])['docs'] ?? []) : [];
$place = array_search($work['id'], $batchDocs, true);
scan_page_start('בדיקה ואישור' . ($place !== false && count($batchDocs) > 1 ? ' · מסמך ' . ($place + 1) . ' מתוך ' . count($batchDocs) : ''), true);
?>
<?php if (!empty($work['approved'])): ?>
    <div class="card" style="border-color:#d9a400;background:#fff8e1">המסמך הזה כבר אושר (<?= scan_h($work['approved']['pdfFile']) ?>).
        אישור נוסף ייצור מחדש את קובץ ה-OCRsanity לפי הבחירות שכאן.</div>
<?php endif; ?>
<style>
.rows td{padding:2px 3px}
.rows input{width:100%;min-width:0;box-sizing:border-box;padding:3px 5px;font-size:13px}
.rows input[type=number]{min-width:64px;text-align:left}
.rows td:nth-child(3){min-width:170px}.rows td:nth-child(2){min-width:110px}
.rows .del{padding:2px 7px}.rows .st{white-space:nowrap;font-size:12px}
</style>
<div class="split">
    <div class="src" id="src">
        <div class="tools">המסמך המקורי · לחיצה על התמונה מגדילה / מקטינה · <a href="<?= scan_source_url($work, $work['files'][0]) ?>" target="_blank">פתח בחלון נפרד</a></div>
        <?php foreach ($work['files'] as $f): ?>
            <?php if (strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'pdf'): ?>
                <iframe src="<?= scan_source_url($work, $f) ?>"></iframe>
            <?php else: ?>
                <img src="<?= scan_source_url($work, $f) ?>" alt="">
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div class="data">
        <form method="post" action="approve.php" id="f">
            <input type="hidden" name="id" value="<?= scan_h($work['id']) ?>">
            <div class="card">
                <h2>זיהוי המסמך <span class="mute" style="font-weight:400">· חנות: <?= scan_h($work['shop']) ?> · נקרא ב-<?= scan_h($work['read']['seconds']) ?> שנ׳ (<?= scan_h($work['read']['model']) ?>)</span></h2>
                <table class="form">
                    <tr><th>ספק</th><td>
                        <select name="supplier" id="supplier" required>
                            <option value="">— בחרו ספק —</option>
                            <option value="__new__"<?= $chosen === '' ? ' selected' : '' ?>>➕ ספק חדש (לא ברשימה)</option>
                            <?php foreach ($suppliers as $s): ?>
                                <option value="<?= scan_h($s['supplierName']) ?>"<?= $s['supplierName'] === $chosen ? ' selected' : '' ?>
                                    data-method="<?= scan_h($s['OCRsanityMethod'] ?? '') ?>"><?= scan_h(($s['hebrewName'] ?? '') . ' · ' . $s['supplierName']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small>מודפס במסמך: <b><?= scan_h($read['supplier_name_printed'] ?: '—') ?></b> ·
                            <?php if ($match['by'] === 'vat'): ?><span class="ok">✔ זוהה לפי ע.מ.</span>
                            <?php elseif ($match['by'] === 'ai'): ?><span class="ok">✔ זוהה לפי השם</span> (ע.מ. עוד לא שמור לספק הזה — יישמר באישור)
                            <?php else: ?><span class="warn">לא זוהה ברשימת הספקים</span><?php endif; ?></small>
                        <div id="newSupplier" style="margin-top:8px;display:none">
                            <input type="text" name="new_key" placeholder="שם באנגלית, בלי רווחים (למשל Berman)" pattern="[A-Za-z][A-Za-z0-9]*" dir="ltr">
                            <input type="text" name="new_hebrew" placeholder="שם בעברית" value="<?= scan_h($read['supplier_name_printed']) ?>">
                            <small>השם באנגלית הוא שם הקובץ ("Berman 09-11-26 A.pdf") והמפתח ב-suppliers.json.</small>
                        </div>
                    </td></tr>
                    <tr><th>ע.מ. / ח.פ. הספק</th><td><input type="text" name="vat" value="<?= scan_h($read['supplier_vat_id']) ?>" dir="ltr" inputmode="numeric">
                        <small>נשמר לספק, ובפעם הבאה הספק יזוהה לפיו.</small></td></tr>
                    <tr><th>סוג המסמך</th><td><?= scan_h($typeHe[$read['doc_type']] ?? $read['doc_type']) ?> <span class="mute">(<?= scan_h($read['doc_type_printed']) ?>)</span></td></tr>
                    <tr><th>מספר</th><td><input type="text" name="doc_number" value="<?= scan_h($read['doc_number']) ?>" dir="ltr"></td></tr>
                    <tr><th>תאריך</th><td><input type="date" name="doc_date" value="<?= scan_h($read['doc_date']) ?>" required></td></tr>
                    <tr><th>סוג החשבונית (שיטה)</th><td>
                        <select name="method" id="method">
                            <?php foreach (InvoiceReader::METHODS as $m): ?>
                                <option value="<?= $m ?>"<?= $m === $method ? ' selected' : '' ?>><?= scan_h($methodHe[$m]) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small><?php if ($detected['checked'] === 0): ?>אין במסמך שורות עם כמות, מחיר וסה"כ — אין מה לחשב.
                            <?php else: ?>נבדק על <?= $detected['checked'] ?> שורות: LineTotal מתאים ל-<?= $detected['fit']['LineTotal'] ?>,
                                Discount1 ל-<?= $detected['fit']['Discount1'] ?>, Discount2 ל-<?= $detected['fit']['Discount2'] ?>.<?php endif; ?>
                            <span id="cfgMethod"></span></small></td></tr>
                    <tr><th>סה"כ להשוואה</th><td><input type="number" step="0.01" name="invoice_total" value="<?= scan_h($total['value'] ?? '') ?>" dir="ltr">
                        <small>סכום השורות שנקראו: <b><?= $n($total['sum']) ?></b> ·
                            <?php if ($total['key'] === ''): ?>אין סכום מודפס להשוות אליו.
                            <?php elseif ($total['equal']): ?><span class="ok">✔ שווה ל"<?= $totalHe[$total['key']] ?>" המודפס</span>
                            <?php else: ?><span class="warn">⚠ הקרוב ביותר: "<?= $totalHe[$total['key']] ?>" = <?= $n($total['value']) ?></span><?php endif; ?>
                            · מודפס: לפני הנחה <?= $n($read['totals']['before_discount']) ?>, לפני מע"מ <?= $n($read['totals']['before_vat']) ?>, מע"מ <?= $n($read['totals']['vat']) ?>, לתשלום <?= $n($read['totals']['total']) ?></small></td></tr>
                </table>
            </div>
            <div class="card">
                <h2>העמודות שמהן נלקח המידע</h2>
                <table><tr><th>שדה</th><th>כותרת העמודה המודפסת</th></tr>
                    <?php foreach ($fieldHe as $k => $label): ?>
                        <tr><td><?= $label ?></td><td><?= $read['columns'][$k] !== '' ? scan_h($read['columns'][$k]) : '<span class="mute">אין עמודה כזו</span>' ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <div class="card" style="overflow-x:auto">
                <h2>השורות: <span id="cnt"><?= count($read['items']) ?></span>
                    <span id="cntNote" style="font-weight:400" data-printed="<?= $read['rows_printed'] === null ? '' : (int) $read['rows_printed'] ?>"></span></h2>
                <div id="check" style="padding:8px 12px;border-radius:6px;margin-bottom:10px;font-weight:600"></div>
                <table style="width:100%" class="rows" id="rows"><tr><th>#</th><th>ברקוד / קוד</th><th>תיאור</th><th>כמות</th><th>מחיר</th><th>הנחה 1</th><th>הנחה 2</th><th>סה"כ שורה</th><th>בדיקה</th><th></th></tr>
                    <?php $v = fn($x) => $x === null || $x === '' ? '' : scan_h((string) $x);
                    foreach ($read['items'] as $i => $it): ?>
                        <tr><td class="num idx"><?= $i + 1 ?></td>
                            <td><input type="text" name="items[barcode][]" value="<?= $v($it['barcode']) ?>" dir="ltr"></td>
                            <td><input type="text" name="items[description][]" value="<?= $v($it['description']) ?>"></td>
                            <?php foreach (['qty', 'unit_price', 'discount1_pct', 'discount2_pct', 'line_total'] as $k): ?>
                                <td><input type="number" step="any" name="items[<?= $k ?>][]" data-f="<?= $k ?>" value="<?= $v($it[$k] ?? null) ?>" dir="ltr"></td>
                            <?php endforeach; ?>
                            <td class="st"></td><td><button type="button" class="second del" title="מחק שורה">✕</button></td></tr>
                    <?php endforeach; ?>
                </table>
                <p><button type="button" class="second" id="addRow">➕ הוסף שורה</button>
                    <button type="button" class="second" id="undo" disabled>↶ בטל שינוי</button>
                    <button type="button" class="second" id="redo" disabled>↷ בצע שוב</button>
                    <button type="button" class="second" id="flip" title="הופך את הסימן של כל ערכי ההנחה: מינוס לפלוס ופלוס למינוס">± הפוך סימן בהנחות</button>
                    <br>
                    <span class="mute">אפשר לתקן כל תא מול התמונה. הבדיקה מתעדכנת מיד, לפי השיטה שנבחרה למעלה. שורה ריקה לא נשמרת.</span></p>
                <?php if ($read['unreadable']): ?><p class="warn">לא נקרא בוודאות: <?= scan_h(implode(' · ', $read['unreadable'])) ?></p><?php endif; ?>
                <?php if ($read['notes'] !== ''): ?><p class="mute">הערות הקריאה: <?= scan_h($read['notes']) ?></p><?php endif; ?>
            </div>
            <button type="submit">✔ אשר והמשך ל-OCRsanity</button>
            <button type="submit" class="second" form="again">קרא שוב</button>
            <a class="btn second" href="<?= !empty($work['batch']) ? 'batch.php?b=' . scan_h($work['batch']) : 'index.php' ?>">חזרה לרשימת המסמכים</a>
        </form>
        <form method="post" action="scan.php" id="again"><input type="hidden" name="id" value="<?= scan_h($work['id']) ?>"></form>
    </div>
</div>
<script>
const sup = document.getElementById('supplier'), box = document.getElementById('newSupplier'), cfg = document.getElementById('cfgMethod');
function supplierChanged() {
    const isNew = sup.value === '__new__';
    box.style.display = isNew ? 'block' : 'none';
    box.querySelector('[name=new_key]').required = isNew;
    box.querySelector('[name=new_hebrew]').required = isNew;
    const m = sup.selectedOptions[0]?.dataset.method || '';
    cfg.textContent = m && m !== document.getElementById('method').value ? ' ⚠ בהגדרות הספק (suppliers.json) השיטה היא ' + m + '.' : '';
}
sup.addEventListener('change', supplierChanged);
document.getElementById('method').addEventListener('change', supplierChanged);
supplierChanged();

// ── the rows: editable, checked as they are typed (the same rules as InvoiceReader::rowFits / compareTotal) ──
const rows = document.getElementById('rows'), check = document.getElementById('check'), totalIn = document.querySelector('[name=invoice_total]');
const val = (tr, f) => { const s = tr.querySelector('[data-f=' + f + ']').value.trim(); return s === '' || isNaN(+s) ? null : +s; };
const fmt = x => (Math.round(x * 100) / 100).toLocaleString('en-US', {maximumFractionDigits: 2});
function rowCalc(tr, method) {
    const q = val(tr, 'qty'), p = val(tr, 'unit_price'), t = val(tr, 'line_total');
    if (q === null || p === null || t === null) return null;
    const d1 = val(tr, 'discount1_pct') || 0, d2 = val(tr, 'discount2_pct') || 0;
    if (method === 'Discount2' && (d1 >= 100 || d2 >= 100)) return {ok: false, calc: NaN};
    const calc = method === 'LineTotal' ? q * p : method === 'Discount1' ? q * p * (1 - d1 / 100)
        : method === 'Discount2' ? q * p / (1 - d1 / 100) / (1 - d2 / 100) : t;
    return {ok: Math.abs(Math.abs(calc) - Math.abs(t)) <= Math.max(0.1, Math.abs(t) * 0.002), calc};
}
function recalc() {
    const method = document.getElementById('method').value, trs = [...rows.querySelectorAll('tr')].slice(1);
    let sum = 0, bad = 0;
    trs.forEach((tr, i) => {
        tr.querySelector('.idx').textContent = i + 1;
        sum += val(tr, 'line_total') || 0;
        const r = rowCalc(tr, method), st = tr.querySelector('.st');
        tr.classList.toggle('bad', !!r && !r.ok);
        if (r && !r.ok) { bad++; st.innerHTML = '<span class="warn">⚠ לפי החישוב ' + (isNaN(r.calc) ? '—' : fmt(r.calc) + ' · הפרש ' + fmt(r.calc - val(tr, 'line_total'))) + '</span>'; }
        else st.innerHTML = r ? '<span class="ok">✔</span>' : '';
    });
    document.getElementById('cnt').textContent = trs.length;
    const note = document.getElementById('cntNote'), printed = note.dataset.printed;
    note.innerHTML = printed === '' ? '' : +printed === trs.length ? '<span class="ok">✔ כמו שמודפס במסמך</span>' : '<span class="warn">⚠ במסמך מודפס ' + printed + ' שורות</span>';
    const total = totalIn.value.trim() === '' ? null : +totalIn.value, diff = total === null ? null : Math.round((sum - total) * 100) / 100;
    const totalOk = diff !== null && Math.abs(Math.abs(sum) - Math.abs(total)) <= 0.06;
    const parts = [bad ? '⚠ ' + bad + ' שורות שהחשבון בהן לא מסתדר' : '✔ כל השורות מסתדרות',
        'סכום השורות ' + fmt(sum) + (total === null ? ' · אין סה"כ להשוות אליו' : totalOk ? ' = הסה"כ ללא מע"מ ✔' : ' · הסה"כ ללא מע"מ ' + fmt(total) + ' · הפרש ' + fmt(diff) + ' ⚠')];
    const good = !bad && (totalOk || total === null);
    check.textContent = parts.join('  ·  ');
    check.style.background = good ? '#e7f6ec' : '#fdecea';
    check.style.color = good ? '#15803d' : '#b42318';
}
// A barcode that is empty or not only digits is marked yellow (as the old screen did).
function markBarcodes() {
    rows.querySelectorAll('[name="items[barcode][]"]').forEach(i => i.style.background = /^\d+$/.test(i.value.trim()) ? '' : '#fff3cd');
}
// Undo / redo: the whole table is remembered before every finished change (a cell left, a row
// deleted or added, the discounts' sign flipped). Ctrl+Z inside a cell still undoes typing there.
const blank = rows.querySelector('tr:nth-child(2)')?.cloneNode(true);
blank?.querySelectorAll('input').forEach(i => i.value = '');
const snap = () => [...rows.querySelectorAll('tr')].slice(1).map(tr => [...tr.querySelectorAll('input')].map(i => i.value));
const undoBtn = document.getElementById('undo'), redoBtn = document.getElementById('redo');
let past = [], future = [], now = snap();
function restore(state) {
    [...rows.querySelectorAll('tr')].slice(1).forEach(tr => tr.remove());
    state.forEach(vals => { const tr = blank.cloneNode(true); tr.querySelectorAll('input').forEach((i, k) => i.value = vals[k]); rows.querySelector('tbody').appendChild(tr); });
    now = state;
    refreshAll();
}
function changed() {   // call after a finished change
    const s = snap();
    if (JSON.stringify(s) === JSON.stringify(now)) return;
    past.push(now); future = []; now = s;
    refreshAll();
}
function refreshAll() { undoBtn.disabled = !past.length; redoBtn.disabled = !future.length; markBarcodes(); recalc(); }
undoBtn.addEventListener('click', () => { if (past.length) { future.push(now); restore(past.pop()); } });
redoBtn.addEventListener('click', () => { if (future.length) { past.push(now); restore(future.pop()); } });

rows.addEventListener('input', () => { markBarcodes(); recalc(); });
rows.addEventListener('change', changed);
totalIn.addEventListener('input', recalc);
document.getElementById('method').addEventListener('change', recalc);
rows.addEventListener('click', e => { if (e.target.classList.contains('del')) { e.target.closest('tr').remove(); changed(); } });
document.getElementById('addRow').addEventListener('click', () => {
    if (!blank) return;
    const tr = blank.cloneNode(true);
    rows.querySelector('tbody').appendChild(tr);
    changed();
    tr.querySelector('input').focus();
});
document.getElementById('flip').addEventListener('click', () => {
    rows.querySelectorAll('[data-f=discount1_pct],[data-f=discount2_pct]').forEach(i => { const v = i.value.trim(); if (v !== '' && +v !== 0) i.value = -v; });
    changed();
});
refreshAll();
// A click on the photo: twice the size, scrolled to where the click was.
const src = document.getElementById('src');
src.querySelectorAll('img').forEach(img => img.addEventListener('click', e => {
    const r = img.getBoundingClientRect(), fx = (e.clientX - r.left) / r.width, fy = (e.clientY - r.top) / r.height;
    const zoomed = src.classList.toggle('zoom');
    const r2 = img.getBoundingClientRect();
    if (zoomed) { src.scrollLeft += r2.left + fx * r2.width - e.clientX; src.scrollTop += r2.top + fy * r2.height - e.clientY; }
}));
document.getElementById('again').addEventListener('submit', () => document.getElementById('busy').classList.add('on'));
</script>
<?php scan_page_end();

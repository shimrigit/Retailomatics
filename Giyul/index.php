<?php
// גיול חובות — the page (see lib.php). Local only.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    http_response_code(403);
    exit('זמין רק מהמחשב עצמו');
}
require_once __DIR__ . '/lib.php';
$shops = giyulShops();
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<title>גיול חובות</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 28px 40px; color: #333; }
    h1 { color: #2575a8; margin: 0 0 18px; font-size: 30px; }
    .card { background: #fff; border-radius: 10px; box-shadow: 0 1px 6px rgba(0,0,0,.1); padding: 18px 22px; margin-bottom: 18px; }
    .card h2 { margin: 0 0 12px; font-size: 20px; color: #2575a8; }
    label { margin-left: 20px; font-size: 17px; }
    select, input, textarea { font-size: 16px; padding: 5px 8px; font-family: inherit; }
    .btn { padding: 9px 20px; background: #2575a8; color: #fff; border: none; border-radius: 7px; font-size: 16px; cursor: pointer; }
    .btn.green { background: #2a7d2a; } .btn.light { background: #e8f1f8; color: #2575a8; border: 1px solid #2575a8; }
    .btn:disabled { background: #999; cursor: wait; }
    .warn { background: #fff3cd; border: 1px solid #ffd666; border-radius: 6px; padding: 8px 12px; margin-top: 10px; }
    .err { background: #fde8e6; border: 1px solid #f5a19a; border-radius: 6px; padding: 8px 12px; margin-top: 10px; }
    .ok { color: #2a7d2a; font-weight: bold; }
    .mute { color: #888; font-size: 14px; }
    .cols { display: flex; gap: 18px; align-items: flex-start; flex-wrap: wrap; }
    #shot { background: #fff; padding: 10px; display: inline-block; }
    #gt { border-collapse: collapse; font-size: 14px; }
    #gt th, #gt td { border: 1px solid #c9c9c9; padding: 3px 8px; white-space: nowrap; }
    #gt th { background: #f0f0f0; height: 25px; }
    #gt td.n { text-align: left; direction: ltr; }
    #gt td.big { background: #<?= GIYUL_YELLOW ?>; } #gt td.old { background: #<?= GIYUL_PURPLE ?>; }
    #gt tr.total td { font-weight: bold; background: #f7f7f7; }
    .msg { flex: 1; min-width: 380px; }
    .msg textarea { width: 100%; box-sizing: border-box; height: 360px; font-size: 15px; line-height: 1.45; }
    .set textarea { width: 100%; box-sizing: border-box; }
    .set td { padding: 5px 8px; vertical-align: top; }
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
</head>
<body>
<h1>גיול חובות</h1>

<div class="card">
    <label>חנות:
        <select id="shop"><?php foreach ($shops as $s): ?><option><?= $h($s) ?></option><?php endforeach; ?></select>
    </label>
    <label>יום הרצה: <input type="date" id="day"></label>
    <label>קובץ: <select id="exp" style="min-width:320px"></select></label>
    <button class="btn green" type="button" id="runBtn" onclick="run()">▶ בצע</button>
    <div class="mute">בצע = מחפש מחדש בתיקיית Collection את מה שה-Gateway הוריד (קובץ חדש נבחר לבד) ומעבד. שום דבר לא נשמר עד "שמור בתיקייה".</div>
    <div id="notes"></div>
</div>

<div class="card set">
    <h2>פנייה והגדרות <span class="mute">(לכל חנות; נשמרות עד שמשנים)</span></h2>
    <table>
        <tr><td>פנייה ל:</td><td>
            <select id="recipient"></select>
            <input id="newRecipient" placeholder="שם חדש" style="width:120px">
            <button class="btn light" type="button" onclick="addRecipient()">הוסף</button>
        </td></tr>
        <tr><td>חתימה:</td><td><input id="signature" style="width:160px"> &nbsp; סף חוב גדול: <input id="threshold" type="number" style="width:90px"> ₪</td></tr>
        <tr><td>לא לכלול בהודעות:</td><td><textarea id="exclude" rows="4" placeholder="שם לקוח בכל שורה, בדיוק כמו בדוח"></textarea></td></tr>
        <tr><td>פתיח — חובות גדולים:</td><td><textarea id="bigIntro" rows="2"></textarea></td></tr>
        <tr><td>פתיח — חובות ישנים:</td><td><textarea id="oldIntro" rows="2"></textarea>
            <div class="mute">{XXXX} = החודש שלפני יום ההרצה, {YYYY} = 4 חודשים לפניו, {XXXX_YEAR} / {YYYY_YEAR} = השנה שלהם</div></td></tr>
        <tr><td></td><td><button class="btn" type="button" onclick="saveSettings()">שמור הגדרות</button> <span id="setMsg"></span></td></tr>
    </table>
</div>

<div class="card" id="result" style="display:none">
    <div style="margin-bottom:12px">
        <button class="btn green" type="button" id="saveBtn" onclick="saveAll()">💾 שמור בתיקייה (אקסל מעובד, תמונה, הודעות)</button>
        <span id="saveMsg"></span>
    </div>
    <div class="cols">
        <div>
            <div style="margin-bottom:6px">
                <button class="btn light" type="button" onclick="copyPicture()">📋 העתק תמונה</button>
                <button class="btn light" type="button" onclick="downloadPicture()">⬇ הורד תמונה</button>
                <span id="picMsg"></span>
            </div>
            <div id="shot"><table id="gt"></table></div>
        </div>
        <div class="msg">
            <b>חובות גדולים</b> <span id="bigCount" class="mute"></span>
            <button class="btn light" type="button" onclick="copyText('big')">📋 העתק</button> <span id="bigMsg"></span>
            <textarea id="big"></textarea>
            <br><br>
            <b>חובות ישנים</b> <span id="oldCount" class="mute"></span>
            <button class="btn light" type="button" onclick="copyText('old')">📋 העתק</button> <span id="oldMsg"></span>
            <textarea id="old"></textarea>
        </div>
    </div>
</div>

<script>
const $ = id => document.getElementById(id);
const esc = t => String(t ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
const fmt = v => v === null || v === '' || v === undefined ? '' : Math.round(v).toLocaleString('en-US');
const dmy = iso => iso.split('-').reverse().map((p, i) => i === 2 ? p.slice(2) : p).join('/');
let exportsList = [], settings = null, current = null;

const store = { get: k => { try { return localStorage.getItem(k); } catch (e) { return null; } },
                set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} } };

async function api(body) {
    const r = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'שגיאה');
    return j;
}

// Reads the shop's exports again. Keeps the chosen file, unless a new one arrived: then the newest is chosen.
async function refreshList() {
    const r = await (await fetch('api.php?action=list&shop=' + encodeURIComponent($('shop').value))).json();
    if (!r.ok) throw new Error(r.error);
    if (!$('day').value) $('day').value = r.today;
    const known = new Set(exportsList.map(e => e.path + '|' + e.mtime));
    const keep = exportsList[$('exp').value]?.path;
    const arrived = exportsList.length && r.exports.some(e => !known.has(e.path + '|' + e.mtime));
    exportsList = r.exports;
    $('exp').innerHTML = exportsList.map((e, i) => `<option value="${i}">${esc(dmy(e.day))} CO — ${esc(e.file)}${e.processed.length ? ' (יש מעובד)' : ''}</option>`).join('');
    const i = arrived ? 0 : exportsList.findIndex(e => e.path === keep);
    $('exp').value = String(Math.max(i, 0));
    return r;
}

async function loadShop() {
    $('notes').innerHTML = ''; $('result').style.display = 'none';
    store.set('giyulShop', $('shop').value);
    exportsList = [];
    try {
        const r = await refreshList();
        settings = r.settings;
        showSettings();
        $('notes').innerHTML = exportsList.length ? '<div class="mute" style="margin-top:8px">לחץ ▶ בצע</div>'
            : '<div class="err">אין קובץ Export בתיקיית Collection של החנות — הורד גיול ב-Gateway ולחץ ▶ בצע</div>';
    } catch (e) { $('notes').innerHTML = `<div class="err">${esc(e.message)}</div>`; }
}

async function run() {
    $('runBtn').disabled = true;
    try {
        await refreshList();
        if (!exportsList.length) { $('notes').innerHTML = '<div class="err">אין קובץ Export בתיקיית Collection של החנות — הורד גיול ב-Gateway ולחץ ▶ בצע</div>'; $('result').style.display = 'none'; }
        else await preview();
    } catch (e) { $('notes').innerHTML = `<div class="err">${esc(e.message)}</div>`; }
    $('runBtn').disabled = false;
}

// Changing the day or the file makes the shown result stale: hide it until ▶ בצע.
function stale() {
    if ($('result').style.display === 'none') return;
    $('result').style.display = 'none';
    $('notes').innerHTML = '<div class="mute" style="margin-top:8px">השתנה יום/קובץ — לחץ ▶ בצע</div>';
}

function showSettings() {
    $('recipient').innerHTML = settings.recipients.map(r => `<option${r === settings.recipient ? ' selected' : ''}>${esc(r)}</option>`).join('');
    $('signature').value = settings.signature; $('threshold').value = settings.threshold;
    $('exclude').value = settings.exclude.join('\n');
    $('bigIntro').value = settings.bigIntro; $('oldIntro').value = settings.oldIntro;
}
function readSettings() {
    return { recipients: [...$('recipient').options].map(o => o.value), recipient: $('recipient').value,
             signature: $('signature').value, threshold: $('threshold').value,
             exclude: $('exclude').value.split('\n'), bigIntro: $('bigIntro').value, oldIntro: $('oldIntro').value };
}
async function saveSettings() {
    try {
        settings = (await api({ action: 'settings', shop: $('shop').value, settings: readSettings() })).settings;
        showSettings(); $('setMsg').innerHTML = '<span class="ok">✓ נשמר</span>';
        setTimeout(() => $('setMsg').textContent = '', 2000);
        if ($('result').style.display !== 'none') preview();   // the shown messages follow the new settings
    } catch (e) { $('setMsg').textContent = '✘ ' + e.message; }
}
function addRecipient() {
    const n = $('newRecipient').value.trim();
    if (!n) return;
    if (![...$('recipient').options].some(o => o.value === n)) $('recipient').add(new Option(n, n));
    $('recipient').value = n; $('newRecipient').value = '';
    saveSettings();
}

async function preview() {
    const e = exportsList[$('exp').value];
    if (!e || !$('day').value) return;
    $('saveMsg').textContent = '';
    const notes = [];
    const ageDays = (new Date($('day').value) - new Date(e.day)) / 86400000;
    if (ageDays > 3) notes.push(`<div class="warn">⚠ הקובץ מ-${esc(dmy(e.day))} — ${Math.round(ageDays)} ימים לפני יום ההרצה. אולי צריך להוריד גיול חדש ב-Gateway.</div>`);
    if (ageDays < 0) notes.push(`<div class="warn">⚠ הקובץ מ-${esc(dmy(e.day))} — אחרי יום ההרצה.</div>`);
    try {
        current = await api({ action: 'preview', shop: $('shop').value, path: e.path, day: $('day').value });
        if (current.existing.length) notes.push(`<div class="warn">⚠ כבר קיים בתיקייה ליום ההרצה הזה: <b>${current.existing.map(esc).join(', ')}</b>.<br>
            אפשר למחוק ידנית ב-<span dir="ltr">${esc(current.folder)}</span>, או לשמור בכל זאת — יישמר בשם עם (2).</div>`);
        else if (e.processed.length) notes.push(`<div class="mute" style="margin-top:8px">בתיקייה יש קבצים מעובדים מימים אחרים: ${e.processed.map(esc).join(', ')}</div>`);
        $('notes').innerHTML = notes.join('');
        render();
    } catch (err) { $('notes').innerHTML = notes.join('') + `<div class="err">${esc(err.message)}</div>`; $('result').style.display = 'none'; }
}

function render() {
    const c = current;
    let html = '<tr>' + c.header.map(h => `<th>${esc(h)}</th>`).join('') + '</tr>';
    c.rows.forEach((r, i) => {
        html += '<tr>' + r.map((v, j) => j < 2 ? `<td>${esc(v)}</td>` : `<td class="n${j === 2 && c.marks[i] ? ' ' + c.marks[i] : ''}">${fmt(v)}</td>`).join('') + '</tr>';
    });
    if (c.total) html += '<tr class="total">' + c.total.map((v, j) => j < 2 ? `<td>${esc(v)}</td>` : `<td class="n">${fmt(v)}</td>`).join('') + '</tr>';
    $('gt').innerHTML = html;
    $('big').value = c.messages.big; $('old').value = c.messages.old;
    $('bigCount').textContent = `(${c.messages.bigCount} לקוחות)`; $('oldCount').textContent = `(${c.messages.oldCount} לקוחות)`;
    $('result').style.display = '';
}

const picture = () => html2canvas($('shot'), { backgroundColor: '#ffffff', scale: 2 });
async function copyPicture() {
    try {
        const canvas = await picture();
        const blob = await new Promise(res => canvas.toBlob(res, 'image/png'));
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
        flash('picMsg', '✓ הועתקה — הדבק בוואטסאפ');
    } catch (e) { flash('picMsg', '✘ ' + e.message); }
}
async function downloadPicture() {
    const a = document.createElement('a');
    a.href = (await picture()).toDataURL('image/png');
    a.download = current.base + '.png';
    a.click();
}
async function copyText(k) {
    try { await navigator.clipboard.writeText($(k).value); flash(k + 'Msg', '✓ הועתק'); }
    catch (e) { $(k).select(); document.execCommand('copy'); flash(k + 'Msg', '✓ הועתק'); }
}
function flash(id, text) { $(id).textContent = text; setTimeout(() => $(id).textContent = '', 2500); }

async function saveAll() {
    const e = exportsList[$('exp').value];
    $('saveBtn').disabled = true; $('saveMsg').textContent = 'שומר…';
    try {
        const png = (await picture()).toDataURL('image/png');
        const r = await api({ action: 'save', shop: $('shop').value, path: e.path, day: $('day').value, png,
                              messages: { big: $('big').value, old: $('old').value } });
        $('saveMsg').innerHTML = `<span class="ok">✓ נשמר ב-<span dir="ltr">${esc(r.folder)}</span>:</span> ${r.saved.map(esc).join(' · ')}`;
        await refreshList();   // the "(יש מעובד)" marks
    } catch (err) { $('saveMsg').textContent = '✘ ' + err.message; }
    $('saveBtn').disabled = false;
}

$('shop').onchange = loadShop;
$('day').onchange = stale;
$('exp').onchange = stale;
$('recipient').onchange = saveSettings;
const last = store.get('giyulShop');
if (last && [...$('shop').options].some(o => o.value === last)) $('shop').value = last;
loadShop();
</script>
</body>
</html>

<?php
// WaFetch — "הורדת תמונות מ-WhatsApp": pick a saved group, a time window
// (default: from the group's last run until now) and run the fetcher; the
// page follows the run and shows what was saved. See lib.php.
// From NP harmonized (POST np_flow=1 with NP's choices): step 1 of the
// "WhatsApp — download from a group" mode. That shop's groups come first,
// and "המשך ל-NP" / "דלג" post NP's choices on to NPharmonized/confirm.php.
require_once __DIR__ . '/lib.php';
$settings = waf_settings();
$running = waf_running_job();
$np = ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['np_flow']))
    ? array_map('strval', array_intersect_key($_POST, array_flip(['date', 'shop', 'root_letter', 'mode', 'harvest_mode', 'wa_source'])))
    : null;
$inboxCount = count(waf_inbox_files());
$boot = [
    'groups'  => $settings['groups'],
    'names'   => $settings['names'],
    'running' => $running[0] ?? null,
    'imagesDir' => str_replace('/', '\\', WAF_IMAGES_DIR),
    'shop'    => $np['shop'] ?? null,
];
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WhatsApp – הורדת תמונות</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 36px 0; display: flex; justify-content: center; }
    .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,.15); padding: 40px 56px; width: 940px; max-width: 94vw; box-sizing: border-box; }
    h2 { margin: 0 0 28px; color: #333; text-align: center; font-size: 32px; }
    h3 { margin: 28px 0 12px; color: #2575a8; font-size: 22px; }
    label { display: block; margin-bottom: 6px; font-weight: bold; color: #555; font-size: 18px; }
    select, input[type="text"], input[type="datetime-local"] {
        width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 8px; font-size: 19px; background: #fafafa; box-sizing: border-box; }
    button, .btn { padding: 14px 20px; background: #2575a8; color: #fff; border: none; border-radius: 8px; font-size: 19px; cursor: pointer; }
    button:hover { background: #1a5c87; }
    button:disabled { background: #9bb8cc; cursor: default; }
    button.secondary { background: #888; } button.secondary:hover { background: #666; }
    button.small { padding: 7px 12px; font-size: 15px; }
    button.big { display: block; width: 100%; padding: 18px; font-size: 23px; margin-top: 28px; }
    .row { display: flex; gap: 12px; align-items: flex-end; }
    .row > .grow { flex: 1; }
    .chips { display: flex; flex-wrap: wrap; gap: 8px; margin: 8px 0 4px; }
    .chip { padding: 6px 12px; border: 1px solid #2575a8; color: #2575a8; background: #fff; border-radius: 16px; font-size: 15px; cursor: pointer; }
    .chip:hover { background: #e8f1f8; }
    .hint { color: #777; font-size: 15px; margin: 6px 0 0; }
    .hint.warn { color: #8a4b00; font-weight: bold; }
    .panel { border: 1px solid #ddd; border-radius: 8px; padding: 16px; margin-top: 12px; background: #fafcfe; }
    #status { margin-top: 30px; display: none; }
    .state { padding: 16px; border-radius: 8px; font-size: 19px; background: #e8f1f8; color: #1a5c87; display: flex; gap: 12px; align-items: center; }
    .state.done { background: #d4edda; color: #155724; }
    .state.error { background: #f8d7da; color: #721c24; }
    .spinner { width: 20px; height: 20px; border: 3px solid #9bc1dc; border-top-color: #2575a8; border-radius: 50%; animation: spin 1s linear infinite; flex: none; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .bar { height: 10px; background: #e3e3e3; border-radius: 5px; margin-top: 10px; overflow: hidden; }
    .bar > div { height: 100%; background: #2575a8; width: 0; transition: width .3s; }
    pre.qr { direction: ltr; text-align: center; font-family: Consolas, monospace; font-size: 9px; line-height: 9px; background: #fff; padding: 12px; display: inline-block; }
    .cards { display: flex; gap: 12px; margin-top: 16px; }
    .cards > div { flex: 1; border-radius: 8px; padding: 14px; text-align: center; font-size: 17px; }
    .cards b { display: block; font-size: 34px; }
    .c-ok { background: #d4edda; color: #155724; } .c-warn { background: #fff3cd; color: #856404; } .c-me { background: #e8f1f8; color: #1a5c87; }
    .msg { border-right: 4px solid #2575a8; background: #f6f9fc; padding: 10px 14px; margin: 8px 0; border-radius: 4px; }
    .msg .meta { color: #777; font-size: 14px; margin-bottom: 4px; }
    .msg .text { white-space: pre-wrap; font-size: 18px; }
    .msg .reply { color: #555; font-size: 14px; margin-top: 6px; border-top: 1px dashed #ccc; padding-top: 4px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; margin-top: 10px; }
    .grid figure { margin: 0; border: 1px solid #ddd; border-radius: 6px; overflow: hidden; background: #fff; }
    .grid img { width: 100%; height: 150px; object-fit: cover; display: block; cursor: zoom-in; }
    .grid figcaption { padding: 6px; font-size: 13px; direction: ltr; text-align: center; color: #444; }
    .grid figcaption b { font-size: 17px; color: #155724; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 16px; }
    th, td { border: 1px solid #ddd; padding: 7px 9px; text-align: right; } th { background: #f0f4f8; }
    .muted { color: #888; font-size: 14px; }
</style>
</head>
<body>
<div class="card">
    <h2>הורדת תמונות מ-WhatsApp</h2>
    <?php if ($np): ?>
        <form id="npForm" action="../NPharmonized/confirm.php" method="post">
            <?php foreach ($np as $k => $v): ?>
                <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
            <?php endforeach; ?>
        </form>
        <div class="panel" style="margin:-8px 0 8px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <div style="flex:1;font-size:18px">
                <b>NP Harmonized</b> · <?= htmlspecialchars($np['shop'] ?? '') ?> · <?= htmlspecialchars(date('d/m/Y', strtotime($np['date'] ?? 'today'))) ?>
                <br><span class="muted">שלב 1: הורדת התמונות מהקבוצה. בסיום — "המשך ל-NP".</span>
            </div>
            <?php if ($inboxCount): ?>
                <button type="button" class="secondary small" onclick="document.getElementById('npForm').submit()"
                        title="להמשיך עם התמונות שכבר בתיקייה">דלג — התמונות כבר הורדו (<?= $inboxCount ?>) ←</button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h3>1. קבוצה</h3>
    <div class="row">
        <div class="grow">
            <select id="group"></select>
        </div>
        <button class="secondary small" id="removeGroup" title="הסר את הקבוצה מהרשימה">הסר</button>
    </div>
    <div class="chips"><span class="chip" id="toggleAdd">+ הוסף קבוצה חדשה</span></div>
    <div class="panel" id="addPanel" style="display:none">
        <label for="newName">שם הקבוצה (או איש הקשר) כפי שהוא מופיע ב-WhatsApp</label>
        <div class="row">
            <div class="grow"><input type="text" id="newName" placeholder="למשל: זדים דוידקא"></div>
            <button id="addBtn">הוסף לרשימה</button>
        </div>
        <p class="hint">בהרצה המערכת מחפשת צ'אט בשם הזה בדיוק. אם יש כמה צ'אטים שמכילים אותו — היא תעצור ותציג אותם.</p>
    </div>

    <h3>2. טווח זמן</h3>
    <div class="row">
        <div class="grow">
            <label for="start">מ-</label>
            <input type="datetime-local" id="start">
        </div>
        <div class="grow">
            <label for="end">עד</label>
            <input type="datetime-local" id="end">
        </div>
    </div>
    <div class="row">
        <div class="grow chips" id="startChips"></div>
        <div class="grow chips"><span class="chip" data-end="now">עכשיו</span></div>
    </div>
    <p class="hint" id="windowHint"></p>

    <h3>3. הודעות אליי</h3>
    <label for="names">שמות שמסמנים הודעה אליך (מופרדים בפסיק)</label>
    <input type="text" id="names">
    <p class="hint">הודעות בטווח שמזכירות אחד השמות (גם "לשמרי", "@שמרי") יוצגו בסוף בנפרד.</p>

    <button class="big" id="runBtn">⬇ הורד תמונות</button>

    <div id="status">
        <div class="state" id="stateBox"><div class="spinner" id="spinner"></div><div id="stateText" style="white-space:pre-line"></div></div>
        <div class="bar" id="bar" style="display:none"><div></div></div>
        <div id="qr" style="text-align:center"></div>
        <div id="result"></div>
    </div>
</div>

<script>
const boot = <?= json_encode($boot, JSON_UNESCAPED_UNICODE) ?>;
let groups = boot.groups || {};
const $ = id => document.getElementById(id);
const pad = n => String(n).padStart(2, '0');
const toInput = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
const fromInput = v => v ? Math.floor(new Date(v).getTime() / 1000) : 0;
const fmt = sec => { const d = new Date(sec * 1000); return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`; };
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const daysAgo = (n, h = 0) => { const d = new Date(); d.setDate(d.getDate() - n); d.setHours(h, 0, 0, 0); return d; };

async function api(action, data = null) {
    const opts = data ? { method: 'POST', body: new URLSearchParams({ action, ...data }) } : {};
    const res = await fetch('api.php' + (data ? '' : '?action=' + action), opts);
    const json = await res.json().catch(() => ({ error: 'תשובה לא תקינה מהשרת' }));
    if (!res.ok || json.error) throw Object.assign(new Error(json.error || res.statusText), { json });
    return json;
}

// ---------------- groups ----------------
// From NP: the shop's own groups first (and the first of them selected).
const ofShop = k => boot.shop && groups[k].shop === boot.shop;
function renderGroups(selectKey) {
    const sel = $('group');
    const keys = Object.keys(groups).sort((a, b) => (ofShop(b) - ofShop(a)) || groups[a].name.localeCompare(groups[b].name, 'he'));
    sel.innerHTML = keys.length ? '' : '<option value="">— אין קבוצות שמורות: הוסף קבוצה —</option>';
    for (const k of keys) {
        const g = groups[k];
        sel.add(new Option(`${g.name}${g.shop ? '   ·   ' + g.shop : ''}${g.lastEnd ? '   ·   ריצה אחרונה עד ' + fmt(g.lastEnd) : '   ·   חדשה'}`, k));
    }
    let saved = null;
    try { saved = localStorage.getItem('wafGroup'); } catch (e) {}
    if (selectKey && groups[selectKey]) sel.value = selectKey;
    else if (boot.shop && keys.some(ofShop)) sel.value = keys.find(ofShop);
    else if (saved && groups[saved]) sel.value = saved;
    onGroupChange();
}

function onGroupChange() {
    const g = groups[$('group').value];
    try { localStorage.setItem('wafGroup', $('group').value); } catch (e) {}
    $('removeGroup').style.display = g ? '' : 'none';
    const chips = [];
    if (g && g.lastEnd) chips.push(['מהריצה האחרונה', new Date(g.lastEnd * 1000)]);
    chips.push(['היום 00:00', daysAgo(0)], ['אתמול 00:00', daysAgo(1)], ['לפני 3 ימים', daysAgo(3)], ['לפני שבוע', daysAgo(7)]);
    $('startChips').innerHTML = chips.map(([t], i) => `<span class="chip" data-i="${i}">${t}</span>`).join('');
    $('startChips').querySelectorAll('.chip').forEach(c => c.onclick = () => { $('start').value = toInput(chips[c.dataset.i][1]); updateHint(); });
    $('start').value = g && g.lastEnd ? toInput(new Date(g.lastEnd * 1000)) : '';
    $('end').value = toInput(new Date());
    updateHint();
}

function updateHint() {
    const g = groups[$('group').value], h = $('windowHint');
    h.className = 'hint';
    if (!g) { h.textContent = ''; return; }
    if (!g.lastEnd && !$('start').value) {
        h.className = 'hint warn';
        h.textContent = 'קבוצה חדשה — בחר מאיזה זמן להתחיל (למשל "היום 00:00").';
        return;
    }
    const s = fromInput($('start').value), e = fromInput($('end').value);
    h.textContent = s && e > s ? `יורדו תמונות מ-${fmt(s)} עד ${fmt(e)} (${Math.round((e - s) / 3600)} שעות).` : '';
}

$('group').onchange = onGroupChange;
$('start').oninput = updateHint;
$('end').oninput = updateHint;
document.querySelector('[data-end="now"]').onclick = () => { $('end').value = toInput(new Date()); updateHint(); };
$('toggleAdd').onclick = () => { const p = $('addPanel'); p.style.display = p.style.display === 'none' ? '' : 'none'; $('newName').focus(); };
$('addBtn').onclick = async () => {
    const name = $('newName').value.trim();
    if (!name) return $('newName').focus();
    try {
        const r = await api('add_group', { name });
        groups = r.groups;
        $('newName').value = '';
        $('addPanel').style.display = 'none';
        renderGroups(r.key);
    } catch (e) { alert(e.message); }
};
$('newName').onkeydown = e => { if (e.key === 'Enter') $('addBtn').click(); };
$('removeGroup').onclick = async () => {
    const g = groups[$('group').value];
    if (!g || !confirm(`להסיר את "${g.name}" מהרשימה?`)) return;
    groups = (await api('remove_group', { key: $('group').value })).groups;
    renderGroups();
};

// ---------------- run ----------------
$('runBtn').onclick = async () => {
    const key = $('group').value, start = fromInput($('start').value), end = fromInput($('end').value);
    if (!groups[key]) return alert('בחר קבוצה (או הוסף אחת).');
    if (!start) { updateHint(); return $('start').focus(); }
    if (end <= start) return alert('זמן הסיום חייב להיות אחרי זמן ההתחלה.');
    const g = groups[key];
    if (boot.shop && g.shop && g.shop !== boot.shop
        && !confirm(`הקבוצה "${g.name}" שייכת לחנות ${g.shop}, לא ל-${boot.shop}. להמשיך בכל זאת?`)) return;
    try {
        const data = { key, start, end, names: $('names').value, shop: boot.shop || '' };
        let r = await api('fetch', data);
        if (r.needConfirm) {
            if (!confirm(`בתיקייה יש ${r.unprocessed} תמונות ש-NP עוד לא עיבד.\nהן יועברו לארכיון (output\\archive) ולא ייכנסו ל-NP. להמשיך?`)) return;
            r = await api('fetch', { ...data, confirm_archive: 1 });
        }
        follow(r.job, 'fetch');
    } catch (e) { showError(e); }
};

function setBusy(busy) {
    $('runBtn').disabled = busy;
    $('addBtn').disabled = busy;
}

function showError(e) {
    $('status').style.display = 'block';
    $('stateBox').className = 'state error';
    $('spinner').style.display = 'none';
    $('stateText').textContent = e.message;
    if (e.json && e.json.job) follow(e.json.job, 'fetch');
}

function follow(job, mode) {
    setBusy(true);
    $('status').style.display = 'block';
    $('result').innerHTML = '';
    $('qr').innerHTML = '';
    $('status').scrollIntoView({ behavior: 'smooth' });
    const tick = async () => {
        let st;
        try { st = await (await fetch('api.php?action=status&job=' + job)).json(); } catch (e) { return setTimeout(tick, 2000); }
        const finished = st.state === 'done' || st.state === 'error';
        $('stateBox').className = 'state' + (st.state === 'done' ? ' done' : st.state === 'error' ? ' error' : '');
        $('spinner').style.display = finished ? 'none' : '';
        $('stateText').innerHTML = esc(st.message || '…') + (st.error ? `<br><span style="font-size:14px;direction:ltr;display:block;text-align:left;white-space:pre-wrap">${esc(st.error)}</span>` : '');
        $('qr').innerHTML = st.state === 'qr' && st.qr ? `<pre class="qr">${esc(st.qr)}</pre>` : '';
        const imgs = st.images;
        $('bar').style.display = st.state === 'downloading' && imgs && imgs.total ? '' : 'none';
        if (imgs && imgs.total) $('bar').firstElementChild.style.width = Math.round(100 * imgs.n / imgs.total) + '%';
        if (!finished) return setTimeout(tick, 1500);
        setBusy(false);
        if (st.state !== 'done') return;
        if (st.result) showResult(st.result);
    };
    tick();
}

function showResult(r) {
    if (groups[$('group').value] && r.end) { groups[$('group').value].lastEnd = r.end; renderGroups($('group').value); }
    const t = sec => fmt(Math.floor(new Date(sec).getTime() / 1000));
    let html = `
        <p class="hint">${esc(r.chat)} · ${fmt(r.start)} – ${fmt(r.end)} · ${r.messagesInWindow} הודעות בטווח, ${r.imagesInWindow} תמונות</p>
        <div class="cards">
            <div class="c-ok"><b>${r.matched.length}</b>תמונות עם מחיר — נשמרו</div>
            <div class="c-warn"><b>${r.unmatched.length}</b>תמונות בלי מחיר — לא נשמרו</div>
            <div class="c-me"><b>${r.actionRequests.length}</b>הודעות אליך</div>
        </div>`;
    if (r.notFromStart) html += `<p class="hint warn">לא ניתן היה לטעון הודעות לפני ${esc(r.notFromStart)} — התוצאות מתחילות משם. ודא שהטלפון מחובר ו-WhatsApp פתוח בו, ונסה שוב.</p>`;
    if (r.actionRequests.length) {
        html += '<h3>📌 הודעות אליך</h3>' + r.actionRequests.map(a => `
            <div class="msg">
                <div class="meta">${esc(a.time)} · ${esc(a.senderName || a.sender)}</div>
                <div class="text">${esc(a.text)}</div>
                ${a.inReplyTo ? `<div class="reply">בתגובה ל${a.inReplyTo.type === 'image' ? 'תמונה' : 'הודעה'}${a.inReplyTo.text ? ': ' + esc(a.inReplyTo.text) : ''}</div>` : ''}
            </div>`).join('');
    }
    html += `<h3>✅ התמונות שנשמרו</h3><p class="muted" style="direction:ltr;text-align:right">${esc(r.imagesDir)}</p>`;
    html += r.matched.length ? '<div class="grid">' + r.matched.map(m => {
        const f = m.filename || m.imagePath.split('/').pop();
        return `<figure><a href="image.php?f=${encodeURIComponent(f)}" target="_blank"><img src="image.php?f=${encodeURIComponent(f)}" loading="lazy"></a>
            <figcaption><b>${esc(m.price)} ₪</b><br>${esc(f)}</figcaption></figure>`;
    }).join('') + '</div>' : '<p class="hint">לא נשמרו תמונות בטווח הזה.</p>';
    if (r.unmatched.length) {
        html += '<h3>⚠️ תמונות שלא נשמרו</h3><table><tr><th>#</th><th>זמן</th><th>סיבה</th><th>כיתוב</th></tr>' + r.unmatched.map(u => `
            <tr><td>${esc(u.serial ?? '')}</td><td>${u.timestamp ? t(u.timestamp) : ''}</td>
            <td>${u.reason === 'image download failed' ? 'ההורדה נכשלה' : 'אין מחיר (לא בתגובה ולא בכיתוב)'}</td><td>${esc(u.caption || '')}</td></tr>`).join('') + '</table>';
    }
    if (r.archived) html += `<p class="muted">${r.archived.count} התמונות מהריצה הקודמת הועברו ל-<span style="direction:ltr;unicode-bidi:embed">${esc(r.archived.dir)}</span></p>`;
    html += '<button class="secondary small" id="openFolder" style="margin-top:14px">📂 פתח את התיקייה שוב (סמלים גדולים)</button>';
    if ($('npForm')) {
        html += r.matched.length
            ? '<button class="big" id="npContinue">המשך ל-NP ←</button>'
            : '<p class="hint warn">לא נשמרו תמונות — אין מה להעביר ל-NP. בדוק את הקבוצה ואת טווח הזמן.</p>';
    }
    $('result').innerHTML = html;
    $('openFolder').onclick = () => api('open_folder', {});
    if ($('npContinue')) $('npContinue').onclick = () => $('npForm').submit();
}

$('names').value = (boot.names || []).join(', ');
renderGroups();
if (boot.running) follow(boot.running, 'fetch');
</script>
</body>
</html>

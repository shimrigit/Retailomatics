// series_price.js — "תן מחיר לפי סדרה" for the rows whose price is X (the customer wrote
// "סדרה"): NP's final screen (stage_dcl.php) and series_fix.php (a saved file).
//
// The page has: a table whose X rows are <tr data-xrow data-row data-barcode> holding
// input.sp-price (price[row]), input.sp-noprice (hidden, noprice[row]), input.sp-pick (checkbox)
// and the name in input[name^="name["] (editable: a change searches that row again) or data-name; a #spBox with #spGo and #spAll; and
//   window.SERIES_PRICE = {shop, drive, cols}.
// For each picked row the options come from series_price_api.php: series / single items of
// the shop (Series/), best first — "עדכן מחיר לפי סדרה" puts the price in the row, the user
// may type a price instead, or "ללא מחיר" (the row leaves the file and waits for a price).
(function () {
    const cfg = window.SERIES_PRICE;
    if (!cfg) return;
    const rows = [...document.querySelectorAll('tr[data-xrow]')];
    const go = document.getElementById('spGo'), all = document.getElementById('spAll'), msg = document.getElementById('spMsg');
    if (!rows.length || !go) return;
    const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
    const money = p => Number(p).toFixed(2);
    const nameOf = tr => (tr.querySelector('input[name^="name["]') || {}).value || tr.dataset.name || '';

    const css = document.createElement('style');
    css.textContent = `
        tr.sp-panel td { background: #f7fafc; padding: 10px 18px 16px !important; }
        .sp-opt { display: flex; gap: 14px; align-items: flex-start; border: 1px solid #d6e4ef; border-radius: 8px; padding: 8px 12px; margin: 6px 0; background: #fff; font-size: 17px; }
        .sp-opt.loose { border-style: dashed; }
        .sp-opt .sp-p { font-weight: bold; color: #2575a8; min-width: 70px; font-size: 20px; }
        .sp-opt small { color: #777; display: block; }
        .sp-opt button, .sp-none { padding: 6px 14px; font-size: 16px; border-radius: 6px; border: 1px solid #2a7d2a; background: #2a7d2a; color: #fff; cursor: pointer; white-space: nowrap; }
        .sp-none { border-color: #999; background: #fff; color: #555; }
        .sp-tag { font-size: 13px; padding: 1px 8px; border-radius: 10px; background: #fff3b0; color: #7a5800; margin-inline-start: 6px; }
        .sp-tag.bad { background: #fdecea; color: #c0392b; }
        tr[data-xrow].sp-set td { background: #e8f5e9; }
        tr[data-xrow].sp-out td { background: #f1f1f1; color: #999; text-decoration: line-through; }
        input.sp-price { width: 90px; font-size: 18px; padding: 5px 8px; border: 2px solid #e6a23c; border-radius: 6px; }`;
    document.head.appendChild(css);

    const state = tr => {
        const p = tr.querySelector('.sp-price').value.trim(), out = tr.querySelector('.sp-noprice').value === '1';
        tr.classList.toggle('sp-set', !out && p !== '' && !isNaN(parseFloat(p)));
        tr.classList.toggle('sp-out', out);
    };
    // source[row]: where the price came from, for the summary to the shop owner ("כמו <product>"); empty = typed
    rows.forEach(tr => {
        const src = document.createElement('input');
        src.type = 'hidden'; src.className = 'sp-source'; src.name = 'source[' + tr.dataset.row + ']';
        tr.querySelector('.sp-noprice').after(src);
    });
    rows.forEach(tr => tr.querySelector('.sp-price').addEventListener('input', () => { tr.querySelector('.sp-noprice').value = ''; tr.querySelector('.sp-source').value = ''; state(tr); }));
    if (all) all.addEventListener('change', () => rows.forEach(tr => tr.querySelector('.sp-pick').checked = all.checked));

    function panelFor(tr) {
        let p = tr.nextElementSibling;
        if (!p || !p.classList.contains('sp-panel')) {
            p = document.createElement('tr');
            p.className = 'sp-panel';
            p.innerHTML = `<td colspan="${cfg.cols}"></td>`;
            tr.after(p);
        }
        return p.firstElementChild;
    }
    function setPrice(tr, price, source) {
        tr.querySelector('.sp-source').value = source || '';
        tr.querySelector('.sp-price').value = money(price);
        tr.querySelector('.sp-noprice').value = '';
        state(tr);
    }
    function noPrice(tr) {
        tr.querySelector('.sp-price').value = '';
        tr.querySelector('.sp-noprice').value = '1';
        state(tr);
    }

    go.addEventListener('click', () => {
        const picked = rows.filter(tr => tr.querySelector('.sp-pick').checked);
        if (!picked.length) { msg.textContent = 'סמן את המוצרים לתמחור'; return; }
        search(picked);
    });

    // A name changed (a size added, a typo fixed): that row's options are searched again.
    rows.forEach(tr => {
        const input = tr.querySelector('input[name^="name["]');
        if (input) input.addEventListener('change', () => {
            const p = tr.nextElementSibling;
            if (p && p.classList.contains('sp-panel')) search([tr]);
        });
    });

    async function search(picked) {
        go.disabled = true;
        msg.textContent = 'מחפש סדרות ל-' + picked.length + ' מוצרים…';
        try {
            const body = new URLSearchParams({ shop: cfg.shop, drive: cfg.drive, items: JSON.stringify(picked.map(tr => ({ barcode: tr.dataset.barcode, name: nameOf(tr) }))) });
            const r = await (await fetch('series_price_api.php', { method: 'POST', body })).json();
            if (!r.ok) throw new Error(r.error || 'error');
            if (!r.split) { msg.innerHTML = `ל-${esc(cfg.shop)} אין עדיין חלוקה לסדרות — <a href="../Series/" target="_blank">מסך הסדרות</a>. אפשר להקליד מחיר או לסמן "ללא מחיר".`; }
            else msg.textContent = 'לפי החלוקה לסדרות מ-' + r.split.slice(0, 16).replace(/(\d+)-(\d+)-(\d+)/, '$3/$2/$1');
            picked.forEach(tr => {
                const opts = (r.options || {})[tr.dataset.barcode] || [];
                const td = panelFor(tr);
                td.innerHTML = (opts.length ? '' : '<div style="font-size:17px;color:#c0392b;margin:4px 0">לא נמצאה סדרה מתאימה — הקלד מחיר בשורה, או "ללא מחיר".</div>')
                    + opts.map((o, i) => `<div class="sp-opt${o.loose ? ' loose' : ''}">
                        <span class="sp-p">${money(o.price)}</span>
                        <span style="flex:1">${esc(o.title)}${o.count > 1 ? ` (${o.count} פריטים)` : ''}
                            ${o.loose ? '<span class="sp-tag">התאמה חלשה — יצרן / שם שונה</span>' : ''}
                            ${o.sizeFit === null ? '<span class="sp-tag">גודל לא ידוע</span>' : ''}
                            ${o.conflict ? `<span class="sp-tag bad">מחירים שונים בסדרה: ${esc(Object.entries(o.prices).map(([p, n]) => p + ' ×' + n).join(' / '))}</span>` : ''}
                            ${o.examples.length ? '<small>' + o.examples.map(esc).join(' · ') + '</small>' : ''}</span>
                        ${o.kind === 'single' ? `<a href="../Series/rename.php?shop=${encodeURIComponent(cfg.shop)}&q=${encodeURIComponent(o.barcode)}" target="_blank" title="שם חדש בקומקס — מצרף את הפריט לסדרה שלו; אחר כך לחץ שוב 'תן מחיר לפי סדרה'" style="font-size:14px;white-space:nowrap">שנה שם / צרף לסדרה</a>` : ''}
                        <button type="button" data-i="${i}">עדכן מחיר לפי ${o.kind === 'series' ? 'סדרה' : 'פריט'}</button></div>`).join('')
                    + '<button type="button" class="sp-none">ללא מחיר — יחכה למחיר</button>';
                td.querySelectorAll('.sp-opt button').forEach(b => b.addEventListener('click', () => setPrice(tr, opts[b.dataset.i].price, opts[b.dataset.i].like)));
                td.querySelector('.sp-none').addEventListener('click', () => noPrice(tr));
            });
        } catch (e) {
            msg.textContent = '✘ ' + e.message;
        }
        go.disabled = false;
    }

    // Saving: an X row without a price and not marked "ללא מחיר" — ask, then it waits for a price.
    const form = rows[0].closest('form');
    if (form) form.addEventListener('submit', e => {
        const open = rows.filter(tr => tr.querySelector('.sp-noprice').value !== '1' && isNaN(parseFloat(tr.querySelector('.sp-price').value)));
        if (!open.length) return;
        if (!confirm(`${open.length} מוצרים עדיין בלי מחיר (X).\nהם יוצאו מהקובץ הסופי ויישמרו ברשימת "מוצרים שמחכים למחיר". להמשיך?`)) { e.preventDefault(); return; }
        open.forEach(noPrice);
    });
})();

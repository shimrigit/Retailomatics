<?php
// PO flow, step 2 — item picker (spec §4.1/§4.2).
//
// Replaces the old "one row per catalog item" table (fine for 10-20 SKUs,
// unusable at hundreds) with a search/typeahead box against the full
// catalog: type to filter (or focus with an empty box to browse/scroll the
// whole list), click a result to add it to a running "picked items" list,
// set qty there. The catalog is embedded once as JSON and filtered
// client-side in JS — no per-keystroke round trip, fine at catalog sizes up
// to low thousands.
//
// On submit this still POSTs qty[<barcode>]=<qty> for every picked item,
// same shape the old per-row table produced, so po_confirm.php (which
// re-derives prices/names server-side from SupplierStore — never trusts the
// client) needs no changes.
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/SupplierStore.php';
$generatorId = poagent_require_generator();

// Reached either via GET (fresh pick from po_supplier.php, nothing picked
// yet) or via POST (bounced back from po_confirm.php's "back" form, carrying
// the previously-entered quantities so they aren't lost).
$supplierId = $_POST['supplier_id'] ?? $_GET['supplier_id'] ?? '';
if ($supplierId === '' || !SupplierStore::exists($supplierId)) {
    header('Location: po_supplier.php');
    exit;
}

$qtyPrefill = $_POST['qty'] ?? [];
if (!is_array($qtyPrefill)) {
    $qtyPrefill = [];
}

$catalog = SupplierStore::getCatalog($supplierId);
$catalogByBarcode = [];
foreach ($catalog as $item) {
    $catalogByBarcode[$item['barcode']] = $item;
}

$initialSelected = [];
foreach ($qtyPrefill as $barcode => $qtyRaw) {
    $qty = (int) $qtyRaw;
    if ($qty > 0 && isset($catalogByBarcode[$barcode])) {
        $item = $catalogByBarcode[$barcode];
        $initialSelected[] = [
            'barcode'      => $item['barcode'],
            'name'         => $item['name'],
            'price_agorot' => $item['price_agorot'],
            'qty'          => $qty,
        ];
    }
}

poagent_render_head("POAgent – פריטים: {$supplierId}", 780);
?>
<h2>בחר פריטים וכמויות — <?= htmlspecialchars($supplierId) ?></h2>

<?php if (empty($catalog)): ?>
    <p class="muted">אין פריטים בקטלוג הספק.</p>
    <div class="row-gap"></div>
    <a class="btn secondary" href="po_supplier.php">חזרה לבחירת ספק</a>
<?php else: ?>

<div class="search-wrap">
    <label for="item_search">חיפוש פריט (שם או ברקוד)</label>
    <input type="text" id="item_search" autocomplete="off" placeholder="הקלד לחיפוש, או השאר ריק לגלילה בכל הרשימה...">
    <div id="ac_list" class="autocomplete-list" hidden></div>
</div>

<label>פריטים שנבחרו <span id="picked_count" class="item-count-badge">0</span></label>
<table id="picked_table" class="responsive-table">
    <thead>
        <tr><th>ברקוד</th><th>שם פריט</th><th>מחיר</th><th>כמות</th><th></th></tr>
    </thead>
    <tbody id="picked_tbody"></tbody>
</table>
<p id="picked_empty" class="picked-empty">עדיין לא נבחרו פריטים — חפש למעלה כדי להתחיל.</p>

<form id="items_form" action="po_confirm.php" method="post">
    <input type="hidden" name="supplier_id" value="<?= htmlspecialchars($supplierId) ?>">
    <div id="hidden_qty_inputs"></div>
    <button type="submit">המשך לאישור</button>
</form>
<?php endif; ?>

<div class="row-gap"></div>
<a class="btn secondary" href="po_supplier.php">חזרה לבחירת ספק</a>

<script>
(function () {
    const CATALOG = <?= json_encode(array_values($catalog), JSON_UNESCAPED_UNICODE) ?>;
    const INITIAL_SELECTED = <?= json_encode($initialSelected, JSON_UNESCAPED_UNICODE) ?>;
    const MAX_RESULTS = 40;

    const searchInput = document.getElementById('item_search');
    const acList = document.getElementById('ac_list');
    const pickedTbody = document.getElementById('picked_tbody');
    const pickedEmpty = document.getElementById('picked_empty');
    const pickedCount = document.getElementById('picked_count');
    const hiddenInputs = document.getElementById('hidden_qty_inputs');
    const form = document.getElementById('items_form');

    // barcode -> {barcode, name, price_agorot, qty}
    const selected = new Map();
    INITIAL_SELECTED.forEach(item => selected.set(item.barcode, Object.assign({}, item)));

    let activeIndex = -1;
    let currentResults = [];

    function formatPrice(agorot) {
        return (agorot / 100).toFixed(2) + ' ₪';
    }

    function renderPicked() {
        pickedTbody.innerHTML = '';
        const items = Array.from(selected.values());
        pickedEmpty.style.display = items.length ? 'none' : '';
        pickedCount.textContent = items.length;

        items.forEach(item => {
            const tr = document.createElement('tr');

            const tdBarcode = document.createElement('td');
            tdBarcode.dataset.label = 'ברקוד';
            tdBarcode.textContent = item.barcode;

            const tdName = document.createElement('td');
            tdName.dataset.label = 'שם פריט';
            tdName.textContent = item.name;

            const tdPrice = document.createElement('td');
            tdPrice.dataset.label = 'מחיר';
            tdPrice.textContent = formatPrice(item.price_agorot);

            const tdQty = document.createElement('td');
            tdQty.dataset.label = 'כמות';
            const qtyInput = document.createElement('input');
            qtyInput.type = 'number';
            qtyInput.className = 'qty';
            qtyInput.min = '1';
            qtyInput.step = '1';
            qtyInput.value = item.qty;
            qtyInput.addEventListener('change', () => {
                const val = parseInt(qtyInput.value, 10);
                if (!val || val <= 0) {
                    selected.delete(item.barcode);
                    renderPicked();
                    return;
                }
                item.qty = val;
            });
            tdQty.appendChild(qtyInput);

            const tdRemove = document.createElement('td');
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'remove-btn';
            removeBtn.textContent = '✕ הסר';
            removeBtn.addEventListener('click', () => {
                selected.delete(item.barcode);
                renderPicked();
            });
            tdRemove.appendChild(removeBtn);

            tr.append(tdBarcode, tdName, tdPrice, tdQty, tdRemove);
            pickedTbody.appendChild(tr);
        });
    }

    function closeAutocomplete() {
        acList.hidden = true;
        acList.innerHTML = '';
        activeIndex = -1;
        currentResults = [];
    }

    function addItem(catalogItem, focusBackOnSearch) {
        const existing = selected.get(catalogItem.barcode);
        if (existing) {
            existing.qty += 1;
        } else {
            selected.set(catalogItem.barcode, {
                barcode: catalogItem.barcode,
                name: catalogItem.name,
                price_agorot: catalogItem.price_agorot,
                qty: 1,
            });
        }
        renderPicked();
        if (focusBackOnSearch) {
            searchInput.value = '';
            closeAutocomplete();
            searchInput.focus();
        }
    }

    function renderAutocomplete(results) {
        currentResults = results;
        activeIndex = -1;
        acList.innerHTML = '';

        if (!results.length) {
            const div = document.createElement('div');
            div.className = 'autocomplete-empty';
            div.textContent = 'לא נמצאו פריטים תואמים.';
            acList.appendChild(div);
            acList.hidden = false;
            return;
        }

        results.forEach((item, idx) => {
            const row = document.createElement('div');
            row.className = 'autocomplete-item' + (selected.has(item.barcode) ? ' picked' : '');
            row.dataset.index = idx;

            const name = document.createElement('span');
            name.className = 'ac-name';
            name.textContent = item.name;

            const barcode = document.createElement('span');
            barcode.className = 'ac-barcode';
            barcode.textContent = item.barcode;

            const price = document.createElement('span');
            price.className = 'ac-price';
            price.textContent = formatPrice(item.price_agorot);

            row.append(name, barcode, price);
            row.addEventListener('mousedown', (e) => {
                // mousedown (not click) so this fires before the input's blur.
                e.preventDefault();
                addItem(item, true);
            });
            acList.appendChild(row);
        });

        if (CATALOG.length > results.length && results.length === MAX_RESULTS) {
            const hint = document.createElement('div');
            hint.className = 'autocomplete-hint';
            hint.textContent = `מוצגות ${MAX_RESULTS} תוצאות ראשונות — הקלד לצמצום החיפוש.`;
            acList.appendChild(hint);
        }

        acList.hidden = false;
        setActive(0);
    }

    function setActive(idx) {
        const rows = acList.querySelectorAll('.autocomplete-item');
        rows.forEach(r => r.classList.remove('active'));
        if (idx >= 0 && idx < rows.length) {
            rows[idx].classList.add('active');
            rows[idx].scrollIntoView({ block: 'nearest' });
            activeIndex = idx;
        } else {
            activeIndex = -1;
        }
    }

    function search(term) {
        term = term.trim().toLowerCase();
        if (term === '') {
            // Empty box: browse mode — show the catalog from the top,
            // scrollable, so the user can iterate without typing anything.
            renderAutocomplete(CATALOG.slice(0, MAX_RESULTS));
            return;
        }

        const starts = [];
        const contains = [];
        for (const item of CATALOG) {
            const name = item.name.toLowerCase();
            const barcode = item.barcode.toLowerCase();
            if (name.startsWith(term) || barcode.startsWith(term)) {
                starts.push(item);
            } else if (name.includes(term) || barcode.includes(term)) {
                contains.push(item);
            }
            if (starts.length + contains.length >= MAX_RESULTS * 3) break; // enough to fill + rank
        }
        renderAutocomplete(starts.concat(contains).slice(0, MAX_RESULTS));
    }

    searchInput.addEventListener('input', () => search(searchInput.value));
    searchInput.addEventListener('focus', () => search(searchInput.value));

    searchInput.addEventListener('keydown', (e) => {
        if (acList.hidden && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
            search(searchInput.value);
            return;
        }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive(Math.min(activeIndex + 1, currentResults.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(Math.max(activeIndex - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            // Exact barcode match (e.g. a barcode scanner feeding this box)
            // always wins, regardless of what's highlighted.
            const exact = CATALOG.find(i => i.barcode === searchInput.value.trim());
            if (exact) {
                addItem(exact, true);
                return;
            }
            if (activeIndex >= 0 && currentResults[activeIndex]) {
                addItem(currentResults[activeIndex], true);
            }
        } else if (e.key === 'Escape') {
            closeAutocomplete();
        }
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.search-wrap')) {
            closeAutocomplete();
        }
    });

    form.addEventListener('submit', (e) => {
        if (selected.size === 0) {
            e.preventDefault();
            alert('יש לבחור לפחות פריט אחד לפני המשך.');
            return;
        }
        hiddenInputs.innerHTML = '';
        selected.forEach(item => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'qty[' + item.barcode + ']';
            input.value = item.qty;
            hiddenInputs.appendChild(input);
        });
    });

    renderPicked();
})();
</script>

<?php poagent_render_foot(); ?>

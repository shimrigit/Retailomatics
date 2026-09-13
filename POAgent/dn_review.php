<?php
// DN flow, step 4 (spec §9 step 5) — human-in-the-loop Review screen. An
// OCR miss (misread barcode/qty/price) and a genuine supplier variance look
// identical to the Diff Engine unless a person catches the OCR error first
// — this screen is that catch: an editable table (Excel-sanity-style,
// invalid cells highlighted) over the OCR draft stashed by dn_import.php,
// shown SIDE BY SIDE with the actual DN photo — large, sticky on a wide
// enough screen (stays in view while the form column scrolls), and zoomable
// IN PLACE via poagent_render_zoom_panel() (explicit request: never a
// separate screen/lightbox — the photo and the data being corrected must be
// comparable at the same time). Below ~1320px (phones — see
// .poagent-sticky-layout in lib/ui_common.php) the two stack into one column
// and sticky turns off, so the photo and the form scroll together instead of
// the pinned photo covering the form scrolling underneath it.
// GET only reads from session — never re-runs OCR, so refreshing this page
// is free and safe (and re-visiting it costs nothing on the API bill).
session_start();
require_once __DIR__ . '/lib/ui_common.php';
require_once __DIR__ . '/lib/POStore.php';
$generatorId = poagent_require_generator();

$draft = $_SESSION['poagent_dn_draft'] ?? null;
if (!$draft) {
    header('Location: dn_select_po.php');
    exit;
}

$po = POStore::loadByCoreName($draft['po_core_name']);
if ($po === null) {
    unset($_SESSION['poagent_dn_draft']);
    header('Location: dn_select_po.php');
    exit;
}

$items = $draft['items'];
$blankRowCount = 3; // spare rows for an item the OCR missed entirely
$imageUrl = 'DNdir/' . rawurlencode($draft['image_filename'] ?? '');
$rowsSum = array_sum(array_map(fn($it) => (float) $it['qty'] * (float) $it['unit_price'], $items));

poagent_render_head('POAgent – בדיקת תעודת משלוח', 1700);
?>
<h2>בדיקת תעודת משלוח לפני אישור</h2>
<p class="muted">
    הזמנה: <strong><?= htmlspecialchars($po['unique_id'] ?? '') ?></strong>
    (<?= htmlspecialchars($po['supplier_id'] ?? '') ?>)
    &nbsp;|&nbsp; קובץ: <?= htmlspecialchars($draft['image_filename']) ?>
</p>
<p class="muted">
    השוו בין התמונה לנתונים שנקראו אוטומטית ותקנו לפי הצורך. שדות מסומנים באדום נכשלו בבדיקת
    התקינות (ברקוד לא מספרי/ארוך מדי, כמות או מחיר שאינם מספר) — לרוב סימן לטעות קריאה (OCR), לא
    בהכרח לפער אמיתי מול ההזמנה. ניתן גם למחוק שורה שגויה או להוסיף פריט שה-OCR פספס לגמרי.
</p>

<div class="poagent-sticky-layout">

    <div class="poagent-sticky-panel" style="flex:1 1 520px;">
        <?php poagent_render_zoom_panel($imageUrl, 'תמונת תעודת משלוח', '85vh'); ?>
    </div>

    <div class="poagent-sticky-fill" style="flex:1 1 560px;">
        <form method="post" action="dn_confirm.php">
            <input type="hidden" name="po_core_name" value="<?= htmlspecialchars($draft['po_core_name']) ?>">
            <input type="hidden" name="dn_core_name" value="<?= htmlspecialchars($draft['dn_core_name']) ?>">
            <input type="hidden" name="image_filename" value="<?= htmlspecialchars($draft['image_filename']) ?>">

            <label for="supplier_name_ocr">ספק (כפי שנקרא)</label>
            <input type="text" id="supplier_name_ocr" name="supplier_name_ocr" value="<?= htmlspecialchars($draft['supplier_name_ocr']) ?>">

            <label for="dn_number_ocr">מס' מסמך (כפי שנקרא)</label>
            <input type="text" id="dn_number_ocr" name="dn_number_ocr" value="<?= htmlspecialchars($draft['dn_number_ocr']) ?>">

            <label for="dn_date_ocr">תאריך (כפי שנקרא)</label>
            <input type="text" id="dn_date_ocr" name="dn_date_ocr" value="<?= htmlspecialchars($draft['dn_date_ocr']) ?>">

            <label for="dn_total">סה"כ בתעודה (כפי שנקרא — השאר ריק אם לא מופיע בתמונה)</label>
            <input type="number" step="0.01" id="dn_total" name="dn_total"
                   value="<?= $draft['dn_total'] !== null ? htmlspecialchars((string) $draft['dn_total']) : '' ?>">

            <div style="overflow-x:auto;">
            <table id="dnItemsTable" class="responsive-table">
                <thead><tr><th>מחק</th><th>ברקוד</th><th>שם פריט</th><th>כמות</th><th>מחיר יח'</th><th>סה"כ שורה</th></tr></thead>
                <?php foreach ($items as $i => $item): ?>
                <?php $rowTotal = (float) $item['qty'] * (float) $item['unit_price']; ?>
                <tr>
                    <td data-label="מחק" style="text-align:center"><input type="checkbox" name="items[<?= $i ?>][delete]" value="1"></td>
                    <td data-label="ברקוד">
                        <input type="text" name="items[<?= $i ?>][barcode]" value="<?= htmlspecialchars($item['barcode']) ?>"
                               style="margin-bottom:0<?= $item['barcode_invalid'] ? ';border-color:#c0392b;background:#fdecea' : '' ?>">
                    </td>
                    <td data-label="שם פריט">
                        <input type="text" name="items[<?= $i ?>][name]" value="<?= htmlspecialchars($item['name']) ?>" style="margin-bottom:0">
                    </td>
                    <td data-label="כמות">
                        <input type="number" step="1" name="items[<?= $i ?>][qty]" value="<?= htmlspecialchars((string) $item['qty']) ?>"
                               class="qty" style="margin-bottom:0<?= $item['qty_invalid'] ? ';border-color:#c0392b;background:#fdecea' : '' ?>">
                    </td>
                    <td data-label="מחיר יח'">
                        <input type="number" step="0.01" name="items[<?= $i ?>][unit_price]" value="<?= htmlspecialchars((string) $item['unit_price']) ?>"
                               style="width:100px;margin-bottom:0<?= $item['price_invalid'] ? ';border-color:#c0392b;background:#fdecea' : '' ?>">
                    </td>
                    <td data-label="סה&quot;כ שורה" class="row-total" style="font-weight:bold"><?= number_format($rowTotal, 2) ?> ₪</td>
                </tr>
                <?php endforeach; ?>
                <?php for ($i = count($items); $i < count($items) + $blankRowCount; $i++): ?>
                <tr>
                    <td data-label="מחק" style="text-align:center"></td>
                    <td data-label="ברקוד"><input type="text" name="items[<?= $i ?>][barcode]" value="" placeholder="פריט חדש…" style="margin-bottom:0"></td>
                    <td data-label="שם פריט"><input type="text" name="items[<?= $i ?>][name]" value="" style="margin-bottom:0"></td>
                    <td data-label="כמות"><input type="number" step="1" name="items[<?= $i ?>][qty]" value="" class="qty" style="margin-bottom:0"></td>
                    <td data-label="מחיר יח'"><input type="number" step="0.01" name="items[<?= $i ?>][unit_price]" value="" style="width:100px;margin-bottom:0"></td>
                    <td data-label="סה&quot;כ שורה" class="row-total" style="font-weight:bold">0.00 ₪</td>
                </tr>
                <?php endfor; ?>
                <tr>
                    <td colspan="5" style="text-align:left"><strong>סה"כ לפי שורות</strong></td>
                    <td data-label="סה&quot;כ לפי שורות" id="dnRowsSumTotal"><strong><?= number_format($rowsSum, 2) ?> ₪</strong></td>
                </tr>
            </table>
            </div>

            <button type="submit">✅ אישור והמשך להשוואה מול ההזמנה</button>
        </form>

        <script>
        (function () {
            var table = document.getElementById('dnItemsTable');
            var sumCell = document.getElementById('dnRowsSumTotal');

            function updateRow(row) {
                var qtyInput = row.querySelector('input[name$="[qty]"]');
                var priceInput = row.querySelector('input[name$="[unit_price]"]');
                var totalCell = row.querySelector('.row-total');
                if (!qtyInput || !priceInput || !totalCell) return 0;
                var qty = parseFloat(qtyInput.value) || 0;
                var price = parseFloat(priceInput.value) || 0;
                var total = qty * price;
                totalCell.textContent = total.toFixed(2) + ' ₪';
                return total;
            }

            function updateAll() {
                var sum = 0;
                table.querySelectorAll('tr').forEach(function (row) {
                    if (row.querySelector('.row-total')) {
                        sum += updateRow(row);
                    }
                });
                sumCell.innerHTML = '<strong>' + sum.toFixed(2) + ' ₪</strong>';
            }

            table.addEventListener('input', function (e) {
                if (e.target.matches('input[name$="[qty]"], input[name$="[unit_price]"]')) {
                    updateAll();
                }
            });

            updateAll(); // initial totals from the OCR draft's values
        })();
        </script>

        <div class="row-gap"></div>
        <a class="btn secondary" href="dn_browse.php?po_core_name=<?= urlencode($draft['po_core_name']) ?>">חזרה לבחירת תמונה אחרת</a>
    </div>

</div>
<?php poagent_render_foot(); ?>

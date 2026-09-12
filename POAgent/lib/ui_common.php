<?php
// ui_common.php — shared HTML shell + session helpers for POAgent screens.
// RTL Hebrew, card-style layout matching the existing convention elsewhere
// in this codebase (see NPharmonized/index.php).

// Fixed user list for Screen 1 (spec §4.1) — becomes `generator_id` for the session.
define('POAGENT_USERS', ['user1', 'user2', 'user3']);

function poagent_render_head(string $title, int $cardWidth = 690): void
{
    ?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; display: flex; justify-content: center; align-items: flex-start; min-height: 100vh; margin: 0; padding: 40px 0; }
        .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,.15); padding: 50px 65px; width: <?= (int) $cardWidth ?>px; max-width: 92vw; box-sizing: border-box; }
        h2 { margin: 0 0 32px; color: #333; text-align: center; font-size: 32px; }
        label { display: block; margin-bottom: 9px; font-weight: bold; color: #555; font-size: 19px; }
        select, input[type="text"], input[type="number"] {
            width: 100%; padding: 13px; border: 1px solid #ccc; border-radius: 8px;
            font-size: 19px; background: #fafafa; margin-bottom: 22px; box-sizing: border-box;
        }
        button, .btn {
            display: block; width: 100%; padding: 16px; background: #2575a8; color: #fff; border: none;
            border-radius: 8px; font-size: 21px; cursor: pointer; margin-top: 6px; text-align: center;
            text-decoration: none; box-sizing: border-box;
        }
        button:hover, .btn:hover { background: #1a5c87; }
        .btn.secondary { background: #888; }
        .btn.secondary:hover { background: #666; }
        .btn.disabled { background: #ddd; cursor: not-allowed; color: #888; }
        .btn.disabled:hover { background: #ddd; }
        .switch-group { display: flex; gap: 15px; margin-bottom: 26px; }
        .switch-opt { flex: 1; }
        .switch-opt input[type="radio"] { display: none; }
        .switch-opt label { display: block; margin: 0; padding: 14px; text-align: center; border: 2px solid #ccc;
            border-radius: 8px; background: #fafafa; font-size: 18px; font-weight: bold; color: #555; cursor: pointer; }
        .switch-opt input[type="radio"]:checked + label { border-color: #2575a8; background: #e8f1f8; color: #2575a8; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 17px; }
        th, td { border: 1px solid #ddd; padding: 9px 10px; text-align: right; }
        th { background: #f0f4f8; }
        input[type="number"].qty { width: 80px; margin: 0; padding: 8px; text-align: center; }
        .muted { color: #888; font-size: 15px; }
        .row-gap { margin-top: 14px; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 14px; font-weight: bold; }
        .badge.open { background: #fff3cd; color: #856404; }
        .badge.prcv { background: #d1ecf1; color: #0c5460; }
        .badge.closed { background: #d4edda; color: #155724; }
        .badge.cancelled { background: #f8d7da; color: #721c24; }

        /* Item picker (typeahead) — po_items.php */
        .search-wrap { position: relative; margin-bottom: 22px; }
        .search-wrap input[type="text"] { margin-bottom: 0; }
        .autocomplete-list {
            position: absolute; z-index: 20; top: calc(100% + 4px); right: 0; left: 0;
            background: #fff; border: 1px solid #ccc; border-radius: 8px; max-height: 340px;
            overflow-y: auto; box-shadow: 0 4px 14px rgba(0,0,0,.15);
        }
        .autocomplete-item {
            padding: 10px 14px; cursor: pointer; display: flex; align-items: baseline;
            gap: 12px; font-size: 16px; border-bottom: 1px solid #f0f0f0;
        }
        .autocomplete-item:last-child { border-bottom: none; }
        .autocomplete-item:hover, .autocomplete-item.active { background: #e8f1f8; }
        .autocomplete-item .ac-name { flex: 1; }
        .autocomplete-item .ac-barcode { color: #999; font-size: 13px; white-space: nowrap; }
        .autocomplete-item .ac-price { color: #2575a8; font-weight: bold; white-space: nowrap; }
        .autocomplete-item.picked { background: #f0f8f0; }
        .autocomplete-item.picked .ac-name::after { content: " ✓"; color: #2e8b3d; }
        .autocomplete-empty, .autocomplete-hint { padding: 14px; color: #888; text-align: center; font-size: 14px; }
        .picked-empty { color: #888; text-align: center; padding: 18px; font-size: 16px; }
        .remove-btn {
            background: #f8d7da; color: #721c24; border: none; border-radius: 6px;
            padding: 6px 10px; cursor: pointer; font-size: 14px;
        }
        .remove-btn:hover { background: #f1b0b7; }
        .item-count-badge {
            display: inline-block; background: #2575a8; color: #fff; border-radius: 12px;
            padding: 2px 10px; font-size: 14px; margin-inline-start: 8px;
        }

        /* Phone-width layout — same markup everywhere, tighter chrome so the
           real device viewport (not a shrunk-down desktop page, now that the
           viewport meta tag above is in place) doesn't feel like the laptop
           screen. Desktop styles above are untouched past this breakpoint. */
        @media (max-width: 640px) {
            body { padding: 16px 0; }
            .card { padding: 22px 16px; border-radius: 8px; }
            h2 { font-size: 22px; margin-bottom: 22px; }
            h3 { font-size: 19px; }
            h4 { font-size: 16px; }
            label { font-size: 16px; }
            select, input[type="text"], input[type="number"] { font-size: 16px; padding: 11px; margin-bottom: 16px; }
            button, .btn { font-size: 18px; padding: 15px; }
            .switch-opt label { font-size: 15px; padding: 11px; }

            /* Any table wearing this class becomes a stack of cards, one per
               row, label-above-value — a plain <table> with several columns
               is unreadable at phone width (tiny text, or sideways
               scrolling). Requires each <td> to carry data-label="...". */
            table.responsive-table thead { display: none; }
            table.responsive-table, table.responsive-table tbody,
            table.responsive-table tr, table.responsive-table td {
                display: block; width: 100%; box-sizing: border-box;
            }
            table.responsive-table { border: none; margin-bottom: 18px; }
            table.responsive-table tr {
                border: 1px solid #ddd; border-radius: 8px; margin-bottom: 12px;
                background: #fff; overflow: hidden;
            }
            table.responsive-table td {
                border: none; border-bottom: 1px solid #f2f2f2; padding: 9px 12px;
            }
            table.responsive-table td:last-child { border-bottom: none; }
            table.responsive-table td[data-label]::before {
                content: attr(data-label); display: block; font-size: 12px;
                font-weight: bold; color: #888; margin-bottom: 2px;
            }
        }
    </style>
</head>
<body>
<div class="card">
    <?php
}

function poagent_render_foot(): void
{
    ?>
</div>

<!-- Shared inline image zoom/pan panel styling+behavior. Unlike a lightbox,
     this stays ON THE PAGE — the picture is shown large, in place, next to
     the data it's being compared against (explicit request: "should not
     have the picture open in another screen but in the same screen").
     Multi-instance: every element with class="poagent-zoom-panel" (see
     poagent_render_zoom_panel() below) gets its own independent zoom/pan
     state, so a page can show several (e.g. po_view.php's multi-delivery
     list) without them interfering with each other. -->
<style>
    .poagent-zoom-panel {
        position: relative; border: 1px solid #ddd; border-radius: 8px;
        background: #fafafa; overflow: hidden; display: flex; flex-direction: column;
    }
    .poagent-zoom-panel-toolbar {
        display: flex; gap: 8px; justify-content: center; padding: 8px;
        background: #fff; border-bottom: 1px solid #eee;
    }
    .poagent-zoom-panel-toolbar button {
        background: #2575a8; color: #fff; border: none; border-radius: 6px;
        padding: 7px 18px; font-size: 16px; cursor: pointer; width: auto;
    }
    .poagent-zoom-panel-toolbar button:hover { background: #1a5c87; }
    .poagent-zoom-panel-viewport {
        flex: 1; overflow: hidden; display: flex; align-items: center; justify-content: center;
        cursor: grab; touch-action: none; min-height: 0;
    }
    .poagent-zoom-panel-viewport.dragging { cursor: grabbing; }
    .poagent-zoom-panel-viewport img {
        /* width/height:100% + object-fit:contain (not max-width/max-height)
           — these demo DN photos are small (screenshots, a few hundred px),
           and max-width/max-height only ever SHRINKS an oversized image,
           never enlarges a small one, which left the photo rendering at its
           tiny native size inside a huge panel. width/height:100% forces
           the img's own box to fill the panel; object-fit:contain then
           scales the picture itself (up or down) to fit that box. */
        width: 100%; height: 100%; object-fit: contain;
        transform-origin: center center; user-select: none; -webkit-user-drag: none;
    }
</style>
<script>
(function () {
    function initZoomPanel(panel) {
        var viewport = panel.querySelector('.poagent-zoom-panel-viewport');
        var img = viewport.querySelector('img');
        var scale = 1, panX = 0, panY = 0, dragging = false, startX = 0, startY = 0;

        function apply() {
            img.style.transform = 'translate(' + panX + 'px,' + panY + 'px) scale(' + scale + ')';
        }
        function reset() { scale = 1; panX = 0; panY = 0; apply(); }
        // Minimum 1 — "fit" (object-fit:contain) is already the natural
        // smallest useful view for an always-visible inline panel.
        function zoomBy(factor) { scale = Math.min(8, Math.max(1, scale * factor)); apply(); }

        panel.querySelectorAll('[data-action]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = btn.getAttribute('data-action');
                if (action === 'zoom-in') zoomBy(1.3);
                else if (action === 'zoom-out') zoomBy(1 / 1.3);
                else if (action === 'zoom-reset') reset();
            });
        });

        viewport.addEventListener('wheel', function (e) {
            e.preventDefault();
            zoomBy(e.deltaY < 0 ? 1.15 : 1 / 1.15);
        }, { passive: false });

        viewport.addEventListener('mousedown', function (e) {
            dragging = true;
            viewport.classList.add('dragging');
            startX = e.clientX - panX;
            startY = e.clientY - panY;
        });
        window.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            panX = e.clientX - startX;
            panY = e.clientY - startY;
            apply();
        });
        window.addEventListener('mouseup', function () {
            dragging = false;
            viewport.classList.remove('dragging');
        });
    }

    document.querySelectorAll('.poagent-zoom-panel').forEach(initZoomPanel);
})();
</script>
</body>
</html>
    <?php
}

/**
 * One inline, always-visible zoom/pan panel for a DN photo — toolbar
 * (－ / איפוס / ＋) plus scroll-wheel zoom and drag-to-pan, all scoped to
 * this one panel (see poagent_render_foot()'s shared script). $height sets
 * the panel's fixed height (CSS length, e.g. "78vh" or "520px") — width
 * comes from the caller's layout (typically a flex/grid column).
 */
function poagent_render_zoom_panel(string $imageUrl, string $alt, string $height = '78vh'): void
{
    ?>
    <div class="poagent-zoom-panel" style="height:<?= htmlspecialchars($height) ?>">
        <div class="poagent-zoom-panel-toolbar">
            <button type="button" data-action="zoom-out">－</button>
            <button type="button" data-action="zoom-reset">🔄 איפוס</button>
            <button type="button" data-action="zoom-in">＋</button>
        </div>
        <div class="poagent-zoom-panel-viewport">
            <img src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars($alt) ?>">
        </div>
    </div>
    <?php
}

/**
 * Shared PO detail rendering — id, status, supplier, generator, date, core
 * name, and the items table (qty, unit price, line total, grand total).
 * Used by both po_success.php (right after creation) and po_view.php (from
 * the history list, §po_list.php) so a PO looks identical regardless of how
 * you got to it.
 */
function poagent_render_po_detail(array $record): void
{
    $items = $record['items'] ?? [];
    $totalAgorot = 0;
    foreach ($items as $line) {
        $totalAgorot += (int) ($line['qty'] ?? 0) * (int) ($line['unit_price_agorot'] ?? 0);
    }

    $dateRaw = (string) ($record['date_generated'] ?? '');
    $dateTimestamp = $dateRaw !== '' ? strtotime($dateRaw) : false;
    $dateFormatted = $dateTimestamp !== false ? date('d/m/Y H:i', $dateTimestamp) : $dateRaw;
    $status = (string) ($record['status'] ?? '');
    ?>
    <p>
        <strong>מספר הזמנה:</strong> <?= htmlspecialchars($record['unique_id'] ?? '') ?>
        <?php if ($status !== ''): ?>
            &nbsp; <span class="badge <?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></span>
        <?php endif; ?>
    </p>
    <p>
        <strong>ספק:</strong> <?= htmlspecialchars($record['supplier_id'] ?? '') ?>
        &nbsp; <strong>נוצר ע"י:</strong> <?= htmlspecialchars($record['generator_id'] ?? '') ?>
        &nbsp; <strong>תאריך:</strong> <?= htmlspecialchars($dateFormatted) ?>
    </p>
    <p class="muted"><strong>שם קובץ (core name):</strong> <?= htmlspecialchars($record['core_name'] ?? '') ?></p>

    <table class="responsive-table">
        <tr><th>ברקוד</th><th>שם פריט</th><th>כמות</th><th>מחיר יח'</th><th>סה"כ</th></tr>
        <?php foreach ($items as $line): ?>
        <?php $lineTotal = (int) ($line['qty'] ?? 0) * (int) ($line['unit_price_agorot'] ?? 0); ?>
        <tr>
            <td data-label="ברקוד"><?= htmlspecialchars($line['barcode'] ?? '') ?></td>
            <td data-label="שם פריט"><?= htmlspecialchars($line['name'] ?? '') ?></td>
            <td data-label="כמות"><?= (int) ($line['qty'] ?? 0) ?></td>
            <td data-label="מחיר יח'"><?= number_format(((int) ($line['unit_price_agorot'] ?? 0)) / 100, 2) ?> ₪</td>
            <td data-label="סה&quot;כ"><?= number_format($lineTotal / 100, 2) ?> ₪</td>
        </tr>
        <?php endforeach; ?>
        <tr>
            <td colspan="4" style="text-align:left"><strong>סה"כ להזמנה</strong></td>
            <td data-label="סה&quot;כ להזמנה"><strong><?= number_format($totalAgorot / 100, 2) ?> ₪</strong></td>
        </tr>
    </table>
    <?php
}

function poagent_agorot_to_ils(int $agorot): string
{
    return number_format($agorot / 100, 2) . ' ₪';
}

/**
 * Shared DN detail rendering — OCR fields + item table (invalid cells
 * highlighted red per DNSanity/dn_confirm.php's flags). Does NOT render the
 * photo itself — callers show it separately via poagent_render_zoom_panel(),
 * typically in its own large column next to this (explicit request: data
 * and photo side by side, photo large and zoomable, never in a separate
 * screen). Used by dn_result.php (right after import) and po_view.php (the
 * unified PO+DN+VS view reached from po_list.php) so a DN looks identical
 * regardless of how you got to it — same convention as
 * poagent_render_po_detail() above.
 */
function poagent_render_dn_detail(array $dn): void
{
    ?>
    <p class="muted">
        ספק (כפי שנקרא): <?= htmlspecialchars($dn['supplier_name_ocr'] ?: '—') ?>
        &nbsp;|&nbsp; מס' מסמך: <?= htmlspecialchars($dn['dn_number_ocr'] ?: '—') ?>
        &nbsp;|&nbsp; תאריך: <?= htmlspecialchars($dn['dn_date_ocr'] ?: '—') ?>
        &nbsp;|&nbsp; סה"כ בתעודה: <?= ($dn['dn_total'] ?? null) !== null ? number_format((float) $dn['dn_total'], 2) . ' ₪' : '—' ?>
        &nbsp;|&nbsp; קובץ: <?= htmlspecialchars($dn['image_filename'] ?? '') ?>
        <?php if (!empty($dn['reviewed'])): ?>
            &nbsp;|&nbsp; <span style="color:#2a7d2a">✔ נבדק ואושר ידנית</span>
        <?php endif; ?>
    </p>
    <?php if (empty($dn['sanity_ok'])): ?>
        <p style="color:#c0392b">⚠️ חלק מהשדות לא עברו את בדיקת התקינות (מסומנים למטה באדום).</p>
    <?php endif; ?>

    <table class="responsive-table">
        <tr><th>ברקוד</th><th>שם פריט</th><th>כמות</th><th>מחיר יח'</th></tr>
        <?php foreach ($dn['items'] ?? [] as $item): ?>
        <tr>
            <td data-label="ברקוד" style="<?= !empty($item['barcode_invalid']) ? 'color:#c0392b;font-weight:bold' : '' ?>"><?= htmlspecialchars($item['barcode'] ?? '') ?></td>
            <td data-label="שם פריט"><?= htmlspecialchars($item['name'] ?? '') ?></td>
            <td data-label="כמות" style="<?= !empty($item['qty_invalid']) ? 'color:#c0392b;font-weight:bold' : '' ?>"><?= htmlspecialchars((string) ($item['qty'] ?? 0)) ?></td>
            <td data-label="מחיר יח'" style="<?= !empty($item['price_invalid']) ? 'color:#c0392b;font-weight:bold' : '' ?>"><?= number_format((float) ($item['unit_price'] ?? 0), 2) ?> ₪</td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php
}

/**
 * Shared VS detail rendering — status badge, line-items diff table, and any
 * unmatched-DN-items table. Used by dn_result.php and po_view.php — same
 * convention as poagent_render_po_detail()/poagent_render_dn_detail() above.
 */
function poagent_render_vs_detail(array $vs): void
{
    ?>
    <p>
        <span class="badge <?= $vs['status'] === 'matched' ? 'closed' : 'cancelled' ?>">
            <?= $vs['status'] === 'matched' ? '✔ תואם במלואו' : '⚠ נמצאה שונות' ?>
        </span>
    </p>

    <?php if (empty($vs['line_items'])): ?>
        <p class="muted">לא נמצאו פריטים תואמים להזמנה בתעודה זו.</p>
    <?php else: ?>
    <table class="responsive-table">
        <tr>
            <th>ברקוד</th><th>נותר לפני</th><th>כמות בתעודה</th><th>הפרש כמות</th>
            <th>מחיר בהזמנה</th><th>מחיר בתעודה</th><th>הפרש מחיר</th>
        </tr>
        <?php foreach ($vs['line_items'] as $line): ?>
        <tr<?= !empty($line['not_delivered']) ? ' style="background:#fdecea"' : '' ?>>
            <td data-label="ברקוד">
                <?= htmlspecialchars($line['barcode']) ?>
                <?php if (!empty($line['not_delivered'])): ?>
                    <br><span style="color:#c0392b;font-size:13px">⚠ לא נכלל בתעודה זו</span>
                <?php endif; ?>
            </td>
            <td data-label="נותר לפני"><?= (int) $line['po_qty_remaining_before'] ?></td>
            <td data-label="כמות בתעודה"><?= (int) $line['dn_qty'] ?></td>
            <td data-label="הפרש כמות" style="<?= $line['qty_flagged'] ? 'color:#c0392b;font-weight:bold' : 'color:#2a7d2a' ?>">
                <?= $line['qty_diff'] > 0 ? '+' : '' ?><?= (int) $line['qty_diff'] ?>
            </td>
            <td data-label="מחיר בהזמנה"><?= poagent_agorot_to_ils((int) $line['po_price_agorot']) ?></td>
            <td data-label="מחיר בתעודה"><?= empty($line['not_delivered']) ? poagent_agorot_to_ils((int) $line['dn_price_agorot']) : '—' ?></td>
            <td data-label="הפרש מחיר" style="<?= $line['price_flagged'] ? 'color:#c0392b;font-weight:bold' : 'color:#2a7d2a' ?>">
                <?= empty($line['not_delivered']) ? (($line['price_diff_agorot'] > 0 ? '+' : '') . poagent_agorot_to_ils((int) $line['price_diff_agorot'])) : '—' ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if (!empty($vs['unmatched_dn_items'])): ?>
        <h4 style="color:#c0392b">פריטים בתעודה שאינם בהזמנה</h4>
        <table class="responsive-table">
            <tr><th>ברקוד</th><th>כמות</th><th>הערה</th></tr>
            <?php foreach ($vs['unmatched_dn_items'] as $u): ?>
            <tr>
                <td data-label="ברקוד"><?= htmlspecialchars($u['barcode']) ?></td>
                <td data-label="כמות"><?= (int) $u['dn_qty'] ?></td>
                <td data-label="הערה"><?= htmlspecialchars($u['note']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <?php if (isset($vs['total_check'])): ?>
        <?php poagent_render_total_check($vs['total_check']); ?>
    <?php endif; ?>
    <?php
}

/**
 * Shared total-verification rendering — two checks anchored on the DN's own
 * declared (OCR'd) total: (a) does it match what this delivery was expected
 * to be worth (the PO's remaining value for the items it actually
 * contains), and (b) does it match the sum of the DN's own rows. Either
 * check is skipped (shown as "not evaluated") when no total was ever
 * visible on the document (DiffEngine::compare() leaves dn_declared_total_agorot
 * null in that case) — a missing total is not itself a mismatch. Used by
 * poagent_render_vs_detail() (which already has the matching VS in hand) and
 * consumed directly wherever a page matches a DN to its own VS by
 * dn_reference to pull just the total_check out of it.
 */
function poagent_render_total_check(array $totalCheck): void
{
    $declared = $totalCheck['dn_declared_total_agorot'] ?? null;
    ?>
    <h4 style="margin-top:24px;color:#555">🧮 בדיקת סה"כ</h4>
    <table class="responsive-table">
        <tr><th>סה"כ צפוי (לפי ההזמנה)</th><th>סה"כ מוצהר בתעודה (OCR)</th><th>סה"כ מחושב לפי שורות התעודה</th></tr>
        <tr>
            <td data-label="סה&quot;כ צפוי (לפי ההזמנה)"><?= poagent_agorot_to_ils((int) $totalCheck['po_expected_total_agorot']) ?></td>
            <td data-label="סה&quot;כ מוצהר בתעודה (OCR)" style="<?= $totalCheck['po_vs_declared_flagged'] ? 'color:#c0392b;font-weight:bold' : '' ?>">
                <?= $declared !== null ? poagent_agorot_to_ils((int) $declared) : '— לא זוהה בתמונה' ?>
            </td>
            <td data-label="סה&quot;כ מחושב לפי שורות התעודה"><?= poagent_agorot_to_ils((int) $totalCheck['dn_computed_total_agorot']) ?></td>
        </tr>
    </table>
    <?php if ($declared === null): ?>
        <p class="muted">לא זוהה סה"כ מודפס בתמונה — בדיקת הסה"כ לא בוצעה.</p>
    <?php else: ?>
        <p class="<?= $totalCheck['po_vs_declared_flagged'] ? '' : 'muted' ?>" style="<?= $totalCheck['po_vs_declared_flagged'] ? 'color:#c0392b;font-weight:bold' : '' ?>">
            <?= $totalCheck['po_vs_declared_flagged'] ? '⚠' : '✔' ?>
            סה"כ ההזמנה מול הסה"כ המוצהר בתעודה:
            הפרש <?= $totalCheck['po_vs_declared_diff_agorot'] > 0 ? '+' : '' ?><?= poagent_agorot_to_ils((int) $totalCheck['po_vs_declared_diff_agorot']) ?>
        </p>
        <p class="<?= $totalCheck['declared_vs_computed_flagged'] ? '' : 'muted' ?>" style="<?= $totalCheck['declared_vs_computed_flagged'] ? 'color:#c0392b;font-weight:bold' : '' ?>">
            <?= $totalCheck['declared_vs_computed_flagged'] ? '⚠' : '✔' ?>
            הסה"כ המוצהר בתעודה מול סכום השורות בתעודה:
            הפרש <?= $totalCheck['declared_vs_computed_diff_agorot'] > 0 ? '+' : '' ?><?= poagent_agorot_to_ils((int) $totalCheck['declared_vs_computed_diff_agorot']) ?>
        </p>
    <?php endif; ?>
    <?php
}

/**
 * Plain-language variance summary — renders DiffEngine::summarizeForPo()'s
 * output: either a single all-clear line, or one bullet per finding across
 * the 4 problem types (unordered item / missing item / qty mismatch / price
 * mismatch). Explicit request: the raw diff tables above weren't "clear
 * enough" on their own — this is the plain-Hebrew-sentence version.
 */
function poagent_render_variance_summary(array $summary): void
{
    ?>
    <h4 style="margin-top:24px;color:#555">📝 סיכום</h4>
    <?php if ($summary['fully_matched']): ?>
        <p style="color:#2a7d2a;font-weight:bold">✔ ההזמנה סופקה במלואה</p>
    <?php else: ?>
        <ul style="margin:0;padding-inline-start:22px;color:#c0392b;font-weight:bold">
            <?php foreach ($summary['findings'] as $f): ?>
                <li style="margin-bottom:6px">
                    <?php if ($f['type'] === 'unordered_item'): ?>
                        תעודת המשלוח כוללת מוצר שלא הוזמן <?= htmlspecialchars($f['barcode']) ?>
                    <?php elseif ($f['type'] === 'missing_item'): ?>
                        תעודת המשלוח חסרה את המוצר <?= htmlspecialchars($f['barcode']) ?>
                    <?php elseif ($f['type'] === 'qty_mismatch'): ?>
                        בתעודת המשלוח במוצר <?= htmlspecialchars($f['barcode']) ?> הוזמנו <?= (int) $f['po_qty'] ?> פריטים
                        אולם התקבלו <?= (int) $f['received_qty'] ?> פריטים
                    <?php elseif ($f['type'] === 'price_mismatch'): ?>
                        בתעודת המשלוח במוצר <?= htmlspecialchars($f['barcode']) ?> מחיר המוצר הוא
                        <?= poagent_agorot_to_ils((int) $f['dn_price_agorot']) ?>
                        אולם בהזמנה המחיר הוא <?= poagent_agorot_to_ils((int) $f['po_price_agorot']) ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php
}

/** Redirects to Screen 1 if no user has been selected yet; otherwise returns generator_id. */
function poagent_require_generator(): string
{
    if (empty($_SESSION['poagent_generator_id'])) {
        header('Location: index.php');
        exit;
    }
    return $_SESSION['poagent_generator_id'];
}

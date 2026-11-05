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
        /* Compact navigation row at the top of list / long pages
           (poagent_render_nav()) — so the user needn't scroll to the bottom. */
        .poagent-nav { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: -12px 0 22px; }
        .poagent-nav .btn { display: inline-block; width: auto; margin: 0; padding: 9px 18px; font-size: 16px; }
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
        .badge.preocr { background: #ffe0b2; color: #8a4b00; }
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
        /* +/- quantity stepper (po_items.php) — lets a quantity be adjusted
           without ever focusing the native number keyboard. On mobile,
           focusing that keyboard can show a "previous/next field" accessory
           bar that, if mis-tapped, sends focus back to the item-search box
           — which reopens its dropdown immediately on focus, looking like
           "the cursor jumped to the item list" (confirmed against a real
           device). The stepper buttons avoid the keyboard entirely for the
           common case; typing an exact quantity directly still works too. */
        .qty-controls { display: flex; align-items: center; gap: 6px; }
        .qty-controls input.qty { width: 56px; margin-bottom: 0; text-align: center; flex-shrink: 0; }
        .qty-btn {
            background: #2575a8; color: #fff; border: none; border-radius: 6px;
            width: 34px; height: 34px; min-width: 34px; font-size: 18px; font-weight: bold;
            line-height: 1; cursor: pointer; flex-shrink: 0; padding: 0;
        }
        .qty-btn:hover { background: #1a5c87; }
        .item-count-badge {
            display: inline-block; background: #2575a8; color: #fff; border-radius: 12px;
            padding: 2px 10px; font-size: 14px; margin-inline-start: 8px;
        }

        /* Shared "sticky panel next to scrolling content" layout — used
           wherever a photo/reference panel is meant to stay in view next to
           taller content beside it (po_view.php's PO panel next to the
           DN/VS delivery list; dn_review.php's DN photo next to the
           editable OCR form). Flex-basis is set per caller via an inline
           style (it differs: po_view.php's panels are 360/900, dn_review.php's
           are 520/560) — everything else here is shared.
           .poagent-sticky-layout   — the flex row wrapper
           .poagent-sticky-panel    — the panel that stays pinned
           .poagent-sticky-fill     — the panel that scrolls normally */
        .poagent-sticky-layout { display: flex; flex-wrap: wrap; gap: 28px; align-items: flex-start; }
        .poagent-sticky-panel { position: sticky; top: 20px; }
        .poagent-sticky-fill { min-width: 0; }

        /* The pinned panel is sticky so it stays visible while the
           (potentially much taller) content beside it scrolls past — but
           that only makes sense while the two columns are actually side by
           side. Below the width where flex-wrap would wrap them onto
           separate lines anyway, sticky must be turned off: a sticky
           element always creates its own stacking context and paints ABOVE
           normal in-flow content, so once the columns stack into one, the
           pinned panel visually overlaps whatever scrolls underneath it —
           looks like "the photo stays while the data scrolls behind it,
           disconnected", which is exactly the bug this guards against. The
           breakpoint forces the same stacking explicitly (rather than
           leaving it to flex-wrap's own content-based wrap) so there's no
           gap width where the columns have wrapped but sticky is still on.
           1320px comfortably covers every current caller's own wrap point
           (po_view.php's 360+900+28≈1290px; dn_review.php's 520+560+28≈1110px)
           with margin to spare — a bit conservative for the narrower one,
           but that only means sticky turns off slightly before it strictly
           needs to, never the reverse (which is the actual bug). */
        @media (max-width: 1320px) {
            .poagent-sticky-layout { flex-direction: column; }
            .poagent-sticky-panel { position: static; flex-basis: auto !important; }
            .poagent-sticky-fill { flex-basis: auto !important; }
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
            .poagent-nav .btn { font-size: 15px; padding: 9px 14px; }
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

/**
 * Compact navigation row under a list / long page's heading: [label => href].
 * The same buttons stay at the bottom too — a long list shouldn't need a
 * scroll down just to leave the page. $extraHtml: more controls on the row.
 */
function poagent_render_nav(array $links, string $extraHtml = ''): void
{
    ?>
    <nav class="poagent-nav">
        <?php foreach ($links as $label => $href): ?>
            <a class="btn secondary" href="<?= htmlspecialchars($href) ?>"><?= htmlspecialchars($label) ?></a>
        <?php endforeach; ?>
        <?= $extraHtml ?>
    </nav>
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
        /* touch-action: pan-y is the DEFAULT (unzoomed) state, so a touch
           here scrolls the page like anywhere else. The script below
           switches this to "none" once zoomed past 1x (there are hidden
           edges to pan to at that point, so the browser must stop treating
           a drag here as a page scroll) and back to pan-y on reset/zoom-out
           — see syncTouchAction() below. */
        flex: 1; overflow: hidden; display: flex; align-items: center; justify-content: center;
        cursor: grab; touch-action: pan-y; min-height: 0;
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
        // touch-action toggles with zoom state: at scale 1 the image exactly
        // fills the frame, so a finger there should scroll the PAGE (pan-y —
        // see the CSS comment above); past scale 1 there are hidden edges to
        // reach, so a finger there should pan the PHOTO instead, which means
        // the browser must stop treating that touch as a page scroll.
        function syncTouchAction() {
            viewport.style.touchAction = scale > 1 ? 'none' : 'pan-y';
        }
        function reset() { scale = 1; panX = 0; panY = 0; apply(); syncTouchAction(); }
        // Minimum 1 — "fit" (object-fit:contain) is already the natural
        // smallest useful view for an always-visible inline panel.
        function zoomBy(factor) { scale = Math.min(8, Math.max(1, scale * factor)); apply(); syncTouchAction(); }

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

        // Touch: one finger pans once zoomed past 1× (does nothing — and
        // lets the page scroll normally — at scale 1, since touch-action is
        // pan-y then); two fingers pinch-zoom and pan together, anchored on
        // the pinch midpoint so the point between the fingers stays put.
        var touchStart = null;   // {x, y, panX, panY} for a single-finger drag
        var pinchDist = null;    // previous two-finger distance, for a relative zoom step
        var pinchMid = null;     // previous two-finger midpoint, for a relative pan step

        function touchDistance(t1, t2) {
            return Math.hypot(t1.clientX - t2.clientX, t1.clientY - t2.clientY);
        }
        function touchMidpoint(t1, t2) {
            return { x: (t1.clientX + t2.clientX) / 2, y: (t1.clientY + t2.clientY) / 2 };
        }

        viewport.addEventListener('touchstart', function (e) {
            if (e.touches.length === 2) {
                touchStart = null;
                pinchDist = touchDistance(e.touches[0], e.touches[1]);
                pinchMid = touchMidpoint(e.touches[0], e.touches[1]);
            } else if (e.touches.length === 1 && scale > 1) {
                pinchDist = null;
                pinchMid = null;
                var t = e.touches[0];
                touchStart = { x: t.clientX, y: t.clientY, panX: panX, panY: panY };
            }
        }, { passive: true });

        viewport.addEventListener('touchmove', function (e) {
            if (e.touches.length === 2) {
                e.preventDefault(); // pinch — always ours, regardless of scale
                var dist = touchDistance(e.touches[0], e.touches[1]);
                var mid = touchMidpoint(e.touches[0], e.touches[1]);
                if (pinchDist) zoomBy(dist / pinchDist);
                if (pinchMid) {
                    panX += mid.x - pinchMid.x;
                    panY += mid.y - pinchMid.y;
                    apply();
                }
                pinchDist = dist;
                pinchMid = mid;
            } else if (e.touches.length === 1 && touchStart) {
                e.preventDefault(); // only reached at scale > 1 (see touchstart) — never eats a plain scroll
                var t2 = e.touches[0];
                panX = touchStart.panX + (t2.clientX - touchStart.x);
                panY = touchStart.panY + (t2.clientY - touchStart.y);
                apply();
            }
        }, { passive: false });

        function endTouch(e) {
            pinchDist = null;
            pinchMid = null;
            touchStart = null;
            // Lifting one finger out of a pinch, with the other still down —
            // resume that finger as a plain single-finger pan.
            if (e.touches.length === 1 && scale > 1) {
                var t = e.touches[0];
                touchStart = { x: t.clientX, y: t.clientY, panX: panX, panY: panY };
            }
        }
        viewport.addEventListener('touchend', endTouch, { passive: true });
        viewport.addEventListener('touchcancel', endTouch, { passive: true });
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
        &nbsp; <strong>נוצר ע"י:</strong> <?= htmlspecialchars(poagent_generator_display($record)) ?>
        &nbsp; <strong>תאריך:</strong> <?= htmlspecialchars($dateFormatted) ?>
        <?php if (!empty($record['delivery_date'])): ?>
            &nbsp; <strong>תאריך אספקה:</strong> <?= htmlspecialchars(date('d/m/Y', strtotime($record['delivery_date']))) ?>
        <?php endif; ?>
    </p>
    <p class="muted"><strong>שם קובץ (core name):</strong> <?= htmlspecialchars($record['core_name'] ?? '') ?></p>

    <table class="responsive-table">
        <thead><tr><th>ברקוד</th><th>שם פריט</th><th>כמות</th><th>מחיר יח'</th><th>סה"כ</th></tr></thead>
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

/**
 * Validates a posted delivery date (<input type="date">, Y-m-d): a real
 * date, today or later, within a year. Returns it normalized, or null.
 */
function poagent_valid_delivery_date($raw): ?string
{
    if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return null;
    }
    $date = DateTime::createFromFormat('!Y-m-d', $raw);
    if (!$date || $date->format('Y-m-d') !== $raw) {
        return null;
    }
    $today = new DateTime('today');
    if ($date < $today || $date > (clone $today)->modify('+1 year')) {
        return null;
    }
    return $raw;
}

// POs created from this date on are expected to be in Priority (the
// Priority connection went live then); older ones are not flagged — they get
// a cleanup when commercial operation starts.
define('POAGENT_PRIORITY_CUTOFF', '2026-11-03');

/**
 * A PO's Priority state: 'sent' (in Priority, complete — or marked
 * "completed manually"), 'missing' (in Priority, but some lines were skipped
 * because their מק"ט isn't in Priority — needs handling), 'partial' (the run
 * stopped half-way — needs checking), 'pending' (not sent yet), or null (not
 * tracked: created before POAGENT_PRIORITY_CUTOFF, or cancelled).
 */
function poagent_priority_state(array $po): ?string
{
    $priority = $po['priority'] ?? null;
    if (!empty($priority['po_number'])) {
        if (!empty($priority['manual'])) {
            return 'sent';
        }
        if (empty($priority['complete'])) {
            return 'partial';
        }
        return empty($priority['skipped_lines']) ? 'sent' : 'missing';
    }
    if (($po['status'] ?? '') === 'cancelled'
        || substr((string) ($po['date_generated'] ?? ''), 0, 10) < POAGENT_PRIORITY_CUTOFF) {
        return null;
    }
    return 'pending';
}

/**
 * The PO's Priority status under its details (po_view.php, po_success.php):
 * the Priority order number once created, otherwise the
 * "צור הזמנת רכש בפריוריטי" button (priority_create.php). Desktop only — the
 * creation drives the Chrome on the office computer.
 */
function poagent_render_priority_link(array $po): void
{
    if (!empty($_SESSION['poagent_mobile'])) {
        return;
    }
    $priority = $po['priority'] ?? null;
    $state = poagent_priority_state($po);
    $href = 'priority_create.php?core_name=' . urlencode($po['core_name'] ?? '');
    ?>
    <div class="row-gap"></div>
    <?php if ($state === 'missing'): ?>
        <p style="color:#8a4b00">⚠️ <strong>נפתחה בפריוריטי עם חוסרים:</strong> <?= htmlspecialchars($priority['po_number']) ?>
            — <?= count($priority['skipped_lines']) ?> פריטים לא קיימים בפריוריטי ולא נכנסו
            &nbsp;<a href="<?= htmlspecialchars($href) ?>">פרטים</a></p>
    <?php elseif ($priority && !empty($priority['complete'])): ?>
        <p>✅ <strong>נפתחה בפריוריטי:</strong> <?= htmlspecialchars($priority['po_number']) ?>
            <?= !empty($priority['manual']) ? '(הושלמה ידנית)' : '' ?>
            &nbsp;<a href="<?= htmlspecialchars($href) ?>">פרטים</a></p>
    <?php elseif ($priority): ?>
        <p style="color:#721c24">⚠️ <strong>נפתחה חלקית בפריוריטי:</strong> <?= htmlspecialchars($priority['po_number']) ?>
            &nbsp;<a href="<?= htmlspecialchars($href) ?>">פרטים</a></p>
    <?php else: ?>
        <a class="btn" href="<?= htmlspecialchars($href) ?>">צור הזמנת רכש בפריוריטי</a>
    <?php endif; ?>
    <?php
}

/**
 * What to tell the user for each Priority run outcome code shared by
 * priority_create.php and priority_receive.php (see PriorityTasks): [what
 * happened, what to do] — HTML. Each page adds its own codes.
 */
function poagent_priority_error_help(): array
{
    return [
        'CHROME_NOT_RUNNING' => ['כרום לא פתוח במצב חיבור לפריוריטי.',
            'סגור את כל חלונות הכרום, והפעל את <b>C:\RetailAgents\Priority\start-chrome-priority.bat</b> (או את הפקודה בסעיף 2 למטה). התחבר לפריוריטי בחלון שנפתח ולחץ שוב.'],
        'PRIORITY_NOT_OPEN' => ['כרום פתוח, אבל אין בו לשונית של פריוריטי.',
            'בחלון הכרום שנפתח במצב חיבור, פתח את <b>https://p.priority-connect.online/webui/B4B4F/</b>, התחבר, ולחץ שוב.'],
        'PRIORITY_NOT_LOGGED_IN' => ['פריוריטי פתוח אבל לא מחובר.',
            'התחבר לפריוריטי עם שם המשתמש והסיסמה שלך (המערכת לעולם לא מקלידה סיסמה), ולחץ שוב.'],
        'PRIORITY_MANY_TABS' => ['פתוחה יותר מלשונית אחת של פריוריטי.',
            'סגור את הלשוניות המיותרות — השאר לשונית פריוריטי אחת בלבד — ולחץ שוב.'],
        'BUSY' => ['פעולה אחרת רצה בפריוריטי ברגע זה.', 'המתן שהיא תסתיים ונסה שוב.'],
        'TIMEOUT' => ['הפעולה לא הסתיימה בזמן ונעצרה.', 'בדוק את מסך פריוריטי לפני כל פעולה נוספת.'],
        'SCRIPT_FAILED' => ['סקריפט הפריוריטי לא רץ או לא החזיר תוצאה.', 'ראה את הלוג למטה.'],
        'STOPPED' => ['הסקריפט עצר באמצע, בכוונה, כי משהו לא היה כצפוי.',
            'שום דבר נוסף לא הוקלד. בדוק את מסך פריוריטי (צילום מסך למטה).'],
        'ERROR' => ['שגיאה לא צפויה.', 'ראה את הפרטים והלוג למטה.'],
    ];
}

/** The outcome box of a Priority run that failed ($help: code => [what, todo]). */
function poagent_render_priority_error(array $run, array $help, array $noDetails = []): void
{
    [$what, $todo] = $help[$run['code']] ?? $help['ERROR'];
    ?>
    <div style="background:#f8d7da;color:#721c24;padding:14px;border-radius:8px">
        <b>❌ <?= $what ?></b><br><?= $todo ?>
        <?php if ($run['message'] !== '' && !in_array($run['code'], $noDetails, true)): ?>
            <br><span style="font-size:14px">פרטים: <?= htmlspecialchars($run['message']) ?></span>
        <?php endif; ?>
    </div>
    <?php
}

/** "Before clicking" — what must be ready for a Priority run. $extra: more <li> HTML items. */
function poagent_render_priority_prerequisites(string $target, array $extra = []): void
{
    $chromeCmd = '"C:\Program Files\Google\Chrome\Application\chrome.exe" --remote-debugging-port=9222 --user-data-dir="C:\RetailAgents\ChromeProfile"';
    ?>
    <h3>לפני הלחיצה — לוודא:</h3>
    <ol style="line-height:1.8">
        <li>אתה עובד <b>על המחשב של המשרד</b> (המחשב שעליו רץ POAgent) — הפעולה נעשית בכרום של המחשב הזה.</li>
        <li><b>כרום פתוח במצב חיבור:</b> סגור את כל חלונות הכרום והפעל
            <b>C:\RetailAgents\Priority\start-chrome-priority.bat</b>, או ב-CMD:
            <pre dir="ltr" style="white-space:pre-wrap;background:#f4f4f4;padding:8px;font-size:13px"><?= htmlspecialchars($chromeCmd) ?></pre></li>
        <li><b>מחובר לפריוריטי</b> בחלון הזה, בלשונית <b>אחת</b> בלבד. אפשר להשאיר אותו על כל מסך — המערכת תגיע לבד ל<?= htmlspecialchars($target) ?>.</li>
        <li>אין בפריוריטי עבודה פתוחה שלא נשמרה (המערכת סוגרת מסכים פתוחים).</li>
        <?php foreach ($extra as $item): ?>
            <li><?= $item ?></li>
        <?php endforeach; ?>
        <li><b>לא לגעת</b> בעכבר ובמקלדת בחלון הכרום עד לסיום (כחצי דקה).</li>
    </ol>
    <?php
}

/** The debug section after a Priority run: Priority's screenshot and the script's log. */
function poagent_render_priority_debug(?array $run): void
{
    if ($run === null || ($run['ok'] && $run['log'] === '')) {
        return;
    }
    ?>
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
    <?php
}

/**
 * A delivery note's goods-receipt state in Priority: 'sent' (receipt GR26...
 * complete), 'missing' (complete, but extra products whose מק"ט isn't in
 * Priority were left out), 'partial' (the run stopped half-way), 'pending'
 * (not sent yet), 'no_po' (its PO isn't fully in Priority yet — create that
 * first), or null (the PO isn't tracked for Priority, see poagent_priority_state()).
 */
function poagent_priority_receipt_state(array $po, array $dn): ?string
{
    $receipt = $dn['priority'] ?? null;
    if (!empty($receipt['gr_number'])) {
        if (!empty($receipt['manual'])) {
            return 'sent';
        }
        if (empty($receipt['complete'])) {
            return 'partial';
        }
        return empty($receipt['skipped_lines']) ? 'sent' : 'missing';
    }
    $poState = poagent_priority_state($po);
    if ($poState === null) {
        return null;
    }
    return in_array($poState, ['sent', 'missing'], true) ? 'pending' : 'no_po';
}

/**
 * Under a delivery note's variance report (po_view.php, dn_result.php): its
 * Priority receipt number once made, otherwise the "קבלת סחורה מספק
 * בפריוריטי" button (priority_receive.php). Desktop only, like the PO button.
 */
function poagent_render_priority_receive_link(array $po, array $dn): void
{
    $state = poagent_priority_receipt_state($po, $dn);
    if (!empty($_SESSION['poagent_mobile']) || $state === null) {
        return;
    }
    $receipt = $dn['priority'] ?? null;
    $href = 'priority_receive.php?dn=' . urlencode($dn['dn_core_name'] ?? '');
    ?>
    <div class="row-gap"></div>
    <?php if ($state === 'sent' || $state === 'missing'): ?>
        <p><?= $state === 'sent' ? '✅' : '⚠️' ?> <strong>נקלטה בפריוריטי:</strong> <?= htmlspecialchars($receipt['gr_number']) ?>
            (<?= htmlspecialchars((string) ($receipt['status'] ?? '')) ?>)
            <?= $state === 'missing' ? ' — ' . count($receipt['skipped_lines']) . ' פריטים לא נקלטו' : '' ?>
            &nbsp;<a href="<?= htmlspecialchars($href) ?>">פרטים</a></p>
    <?php elseif ($state === 'partial'): ?>
        <p style="color:#721c24">⚠️ <strong>נקלטה חלקית בפריוריטי:</strong> <?= htmlspecialchars($receipt['gr_number']) ?>
            &nbsp;<a href="<?= htmlspecialchars($href) ?>">פרטים</a></p>
    <?php elseif ($state === 'no_po'): ?>
        <p class="muted">קבלת סחורה בפריוריטי — אפשרית אחרי שההזמנה תיפתח בפריוריטי במלואה.</p>
    <?php else: ?>
        <a class="btn" href="<?= htmlspecialchars($href) ?>">📥 קבלת סחורה מספק בפריוריטי</a>
    <?php endif; ?>
    <?php
}

/**
 * The delivery note's date as read by the OCR ("17/09/2026", "17.09.26",
 * "2026-09-17"...) as Y-m-d, or null if it can't be read as a date.
 */
function poagent_parse_dn_date($raw): ?string
{
    $raw = trim((string) $raw);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m)) {
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    } elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})$/', $raw, $m)) {
        [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $y += $y < 100 ? 2000 : 0;
    } else {
        return null;
    }
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/**
 * Validates a posted delivery-note date (<input type="date">, Y-m-d): a real
 * date within the last year, not in the future (Priority asks about a future date). Returns it, or null.
 */
function poagent_valid_dn_date($raw): ?string
{
    $date = is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? poagent_parse_dn_date($raw) : null;
    if ($date === null) {
        return null;
    }
    return $date >= date('Y-m-d', strtotime('-1 year')) && $date <= date('Y-m-d') ? $date : null;
}

function poagent_agorot_to_ils(int $agorot): string
{
    return number_format($agorot / 100, 2) . ' ₪';
}

/**
 * The human-facing name for whoever generated a PO — 'generator_name' when
 * present (mobile POs, since POStore::createPO()), else generator_id itself
 * (desktop's "user1"/"user2"/"user3" is already display-friendly, and this
 * also covers PO records written before generator_name existed).
 */
function poagent_generator_display(array $po): string
{
    $name = trim((string) ($po['generator_name'] ?? ''));
    return $name !== '' ? $name : (string) ($po['generator_id'] ?? '');
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
        <thead><tr><th>ברקוד</th><th>שם פריט</th><th>כמות</th><th>מחיר יח'</th></tr></thead>
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
        <thead>
        <tr>
            <th>ברקוד</th><th>נותר לפני</th><th>כמות בתעודה</th><th>הפרש כמות</th>
            <th>מחיר בהזמנה</th><th>מחיר בתעודה</th><th>הפרש מחיר</th>
        </tr>
        </thead>
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
            <thead><tr><th>ברקוד</th><th>כמות</th><th>הערה</th></tr></thead>
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
        <thead><tr><th>סה"כ צפוי (לפי ההזמנה)</th><th>סה"כ מוצהר בתעודה (OCR)</th><th>סה"כ מחושב לפי שורות התעודה</th></tr></thead>
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

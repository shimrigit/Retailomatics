<?php
// Prices — "CPD – מחירי רשתות" (CPD = Chain Price Database): look barcodes up in the chains' official price
// files (C:\RetailAgents\PriceTransparency, data/cpd.sqlite, updated by
// update.js). For each barcode: the product card built from all chains
// (name, manufacturer, size), min / max, and the price in every store.
// Read-only. This computer only (like WaFetch).
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    http_response_code(403);
    exit('Available on this computer only.');
}

define('PRICES_DIR', 'C:/RetailAgents/PriceTransparency');
$chainNames = [];
foreach ((json_decode((string) @file_get_contents(PRICES_DIR . '/chains.json'), true)['chains'] ?? []) as $c) {
    $chainNames[$c['key']] = $c['name'];
}

$error = null;
$db = null;
try {
    $db = new PDO('sqlite:' . PRICES_DIR . '/data/cpd.sqlite', null, null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
} catch (Throwable $e) {
    $error = 'מאגר CPD לא נמצא — הרץ את העדכון (node update.js) ב-C:\RetailAgents\PriceTransparency';
}

$input = trim((string) ($_REQUEST['b'] ?? ''));
$barcodes = array_values(array_unique(array_filter(preg_split('/[^0-9]+/', $input), fn($b) => strlen($b) >= 4)));
$barcodes = array_slice($barcodes, 0, 200);

$results = [];
$lastRun = null;
$counts = null;
if ($db) {
    $lastRun = $db->query('SELECT * FROM runs ORDER BY finished DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
    $counts = $db->query("SELECT (SELECT COUNT(*) FROM stores) stores, (SELECT COUNT(*) FROM products) products,
        (SELECT MAX(updated) FROM stores) updated")->fetch(PDO::FETCH_ASSOC);
    $qCard = $db->prepare('SELECT * FROM products WHERE barcode = ?');
    $qPrices = $db->prepare('SELECT p.chain, p.store_id, p.price, p.date, s.name store FROM current_prices p
        LEFT JOIN stores s ON s.chain = p.chain AND s.store_id = p.store_id WHERE p.barcode = ? ORDER BY p.price, p.chain');
    $qVariants = $db->prepare('SELECT chain, name, manufacturer FROM variants WHERE barcode = ?');
    foreach ($barcodes as $b) {
        // Chains sometimes write a barcode with leading zeros dropped or added.
        $keys = array_unique([$b, ltrim($b, '0')]);
        $card = null;
        foreach ($keys as $k) {
            $qCard->execute([$k]);
            if ($card = $qCard->fetch(PDO::FETCH_ASSOC)) {
                break;
            }
        }
        $prices = $variants = [];
        if ($card) {
            $qPrices->execute([$card['barcode']]);
            $prices = $qPrices->fetchAll(PDO::FETCH_ASSOC);
            $qVariants->execute([$card['barcode']]);
            $variants = $qVariants->fetchAll(PDO::FETCH_ASSOC);
        }
        $results[] = ['barcode' => $b, 'card' => $card, 'prices' => $prices, 'variants' => $variants];
    }
}

// CSV (opens in Excel): one row per barcode.
if (($_GET['format'] ?? '') === 'csv' && $results) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chain-prices-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ברקוד', 'שם מוצר', 'יצרן', 'גודל', 'מחיר מינימלי', 'מחיר מקסימלי', 'מספר סניפים', 'רשתות']);
    foreach ($results as $r) {
        $p = array_column($r['prices'], 'price');
        fputcsv($out, [$r['barcode'], $r['card']['display_name'] ?? 'לא נמצא', $r['card']['manufacturer'] ?? '', $r['card']['size'] ?? '',
            $p ? min($p) : '', $p ? max($p) : '', count($p),
            implode(', ', array_map(fn($c) => $chainNames[$c] ?? $c, array_unique(array_column($r['prices'], 'chain'))))]);
    }
    exit;
}

$money = fn($v) => number_format((float) $v, 2);
$found = count(array_filter($results, fn($r) => $r['card']));
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CPD – מחירי רשתות</title>
<style>
    body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 32px 0; display: flex; justify-content: center; }
    .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,.15); padding: 36px 48px; width: 1100px; max-width: 95vw; box-sizing: border-box; }
    h2 { margin: 0 0 6px; color: #333; text-align: center; font-size: 30px; }
    .status { text-align: center; color: #777; font-size: 15px; margin-bottom: 22px; }
    .status.warn { color: #8a4b00; font-weight: bold; }
    textarea { width: 100%; height: 90px; font-size: 18px; padding: 10px; border: 1px solid #ccc; border-radius: 8px; box-sizing: border-box; direction: ltr; text-align: right; font-family: Consolas, monospace; }
    .row { display: flex; gap: 10px; margin-top: 10px; }
    button, .btn { padding: 12px 22px; background: #2575a8; color: #fff; border: none; border-radius: 8px; font-size: 18px; cursor: pointer; text-decoration: none; }
    .btn.secondary { background: #888; }
    .summary { margin: 22px 0 8px; font-size: 18px; }
    .item { border: 1px solid #ddd; border-radius: 8px; margin: 14px 0; overflow: hidden; }
    .item-head { display: flex; gap: 16px; align-items: center; padding: 12px 16px; background: #f6f9fc; flex-wrap: wrap; }
    .item-head .bc { font-family: Consolas, monospace; direction: ltr; color: #555; }
    .item-head .name { flex: 1; font-size: 19px; font-weight: bold; min-width: 260px; }
    .item-head .range { font-size: 19px; color: #155724; font-weight: bold; white-space: nowrap; }
    .item.missing .item-head { background: #fdf3f3; }
    .item.missing .name { color: #a33; font-weight: normal; }
    details { padding: 0 16px 12px; }
    summary { cursor: pointer; color: #2575a8; padding: 8px 0; }
    table { width: 100%; border-collapse: collapse; font-size: 15px; }
    th, td { border: 1px solid #e3e3e3; padding: 6px 8px; text-align: right; } th { background: #f0f4f8; }
    .muted { color: #888; font-size: 14px; }
</style>
</head>
<body>
<div class="card">
    <h2>CPD – מחירי רשתות</h2>
    <?php if ($error): ?>
        <div class="status warn"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($counts): ?>
        <?php $stale = $counts['updated'] < date('Y-m-d'); ?>
        <div class="status <?= $stale ? 'warn' : '' ?>">
            תל אביב · <?= (int) $counts['stores'] ?> סניפים · <?= number_format((int) $counts['products']) ?> מוצרים ·
            עודכן: <?= htmlspecialchars(date('d/m/Y', strtotime($counts['updated']))) ?>
            <?php if ($lastRun): ?>(ריצה אחרונה <?= htmlspecialchars(date('d/m H:i', strtotime($lastRun['finished']))) ?>)<?php endif; ?>
            <?= $stale ? ' — הנתונים לא מהיום' : '' ?>
        </div>
    <?php endif; ?>

    <form method="get">
        <textarea name="b" placeholder="הדבק ברקודים — אחד בשורה, או מופרדים בפסיק/רווח"><?= htmlspecialchars($input) ?></textarea>
        <div class="row">
            <button type="submit">חפש</button>
            <?php if ($results): ?>
                <a class="btn secondary" href="?<?= htmlspecialchars(http_build_query(['b' => $input, 'format' => 'csv'])) ?>">⬇ ייצא לאקסל (CSV)</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($results): ?>
        <div class="summary">נמצאו <b><?= $found ?></b> מתוך <?= count($results) ?> ברקודים.</div>
        <?php foreach ($results as $r): ?>
            <?php $p = array_column($r['prices'], 'price'); ?>
            <div class="item <?= $r['card'] ? '' : 'missing' ?>">
                <div class="item-head">
                    <span class="bc"><?= htmlspecialchars($r['barcode']) ?></span>
                    <span class="name"><?= $r['card'] ? htmlspecialchars($r['card']['display_name']) : 'לא נמצא באף רשת' ?></span>
                    <?php if ($p): ?>
                        <span class="range"><?= min($p) == max($p) ? '₪' . $money(min($p)) : '₪' . $money(min($p)) . ' – ₪' . $money(max($p)) ?></span>
                        <span class="muted"><?= count($p) ?> סניפים</span>
                    <?php endif; ?>
                </div>
                <?php if ($r['card']): ?>
                    <details>
                        <summary>פירוט לפי סניף ושמות ברשתות</summary>
                        <p class="muted">
                            יצרן: <?= htmlspecialchars($r['card']['manufacturer'] ?: '—') ?> · גודל: <?= htmlspecialchars($r['card']['size'] ?: '—') ?><br>
                            השם בכל רשת: <?= htmlspecialchars(implode(' | ', array_map(fn($v) => ($chainNames[$v['chain']] ?? $v['chain']) . ': ' . $v['name'], $r['variants']))) ?>
                        </p>
                        <table>
                            <tr><th>מחיר</th><th>רשת</th><th>סניף</th><th>עודכן</th></tr>
                            <?php foreach ($r['prices'] as $row): ?>
                                <tr><td>₪<?= $money($row['price']) ?></td><td><?= htmlspecialchars($chainNames[$row['chain']] ?? $row['chain']) ?></td>
                                    <td><?= htmlspecialchars($row['store'] ?? $row['store_id']) ?></td><td><?= htmlspecialchars(date('d/m', strtotime($row['date']))) ?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
</body>
</html>

<?php
// One shop's series: the conflicts to resolve (split into A–J / "לא מסדרה" /
// fix a price), the series already resolved, all series, and the changes
// since the previous split.
ini_set('memory_limit', '1G');
require_once __DIR__ . '/common.php';
$shop = seriesRequestShop();
$data = seriesLoad($shop);
if (!$data) { header('Location: index.php'); exit; }
$show = in_array($_GET['show'] ?? '', ['conflicts', 'resolved', 'all', 'changes'], true) ? $_GET['show'] : 'conflicts';
$dec = $data['decisions'] ?? [];
$applied = seriesApply($data);

// the parts of each series after the decisions: base id => [parts]
$parts = [];
foreach ($applied['series'] as $s) $parts[$s['baseId']][] = $s;
$bases = [];
foreach ($data['result']['series'] as $s) {
    $p = $parts[$s['id']] ?? [];
    $s['parts'] = $p;
    $s['open'] = (bool) array_filter($p, fn($x) => $x['conflict']);
    $s['touched'] = (bool) array_filter($s['items'], fn($it) => isset($dec[$it['barcode']]));
    $bases[] = $s;
}
$lists = [
    'conflicts' => array_values(array_filter($bases, fn($s) => $s['open'])),
    'resolved'  => array_values(array_filter($bases, fn($s) => !$s['open'] && ($s['conflict'] || $s['touched']))),
    'all'       => $bases,
];
$fixCount = count(array_filter($dec, fn($d) => isset($d['fixPrice'])));
$st = $data['result']['stats'];

seriesPageHead("סדרות – $shop");
?>
<style>
    .tabs { display: flex; gap: 10px; margin: 0 0 22px; flex-wrap: wrap; }
    .tabs a { padding: 10px 22px; border-radius: 7px; background: #fff; border: 1px solid #ccc; text-decoration: none; font-size: 18px; color: #555; }
    .tabs a.on { background: #2575a8; color: #fff; border-color: #2575a8; }
    .card { background: #fff; border-radius: 10px; box-shadow: 0 1px 6px rgba(0,0,0,.1); margin-bottom: 22px; overflow: hidden; }
    .card.open { border-right: 6px solid #e57373; }
    .card.done { border-right: 6px solid #81c784; }
    .card-head { padding: 14px 20px; font-size: 20px; display: flex; gap: 22px; align-items: baseline; flex-wrap: wrap; background: #f7fafc; border-bottom: 1px solid #e3e9ef; }
    .card-head .title { font-weight: bold; }
    .part { font-size: 17px; padding: 3px 10px; border-radius: 12px; background: #e8f5e9; color: #2a7d2a; }
    .part.bad { background: #fdecea; color: #c0392b; }
    .card table { box-shadow: none; border-radius: 0; }
    .card th { background: #eef3f8; color: #444; font-size: 17px; }
    .card td { font-size: 17px; padding: 7px 14px; }
    td.diff { color: #c0392b; font-weight: bold; }
    select, input.fix { font-size: 17px; padding: 5px 8px; border: 1px solid #bbb; border-radius: 6px; }
    input.fix { width: 90px; }
    tr.fixed td { background: #fff8e1; }
    tr.out td { color: #999; }
    .card-foot { padding: 10px 20px 14px; display: flex; gap: 14px; align-items: center; }
    .stats span { margin-left: 26px; }
</style>
<h2>סדרות – <?= h($shop) ?></h2>
<p class="sub">
    חולק <?= h(date('d/m/Y H:i', strtotime($data['meta']['built']))) ?> · לפי <?= h($data['meta']['source']) ?> ·
    <a href="index.php">כל הלקוחות</a> · <a href="export.php?shop=<?= urlencode($shop) ?>">ייצוא לאקסל</a>
</p>
<?php if (!empty($_GET['error'])): ?><div class="err">⚠ <?= h($_GET['error']) ?></div><?php endif; ?>
<div class="box stats">
    <span><?= number_format($st['items']) ?> פריטים</span>
    <span><?= count($applied['series']) ?> סדרות (<?= array_sum(array_map(fn($s) => count($s['items']), $applied['series'])) ?> פריטים)</span>
    <span class="<?= $lists['conflicts'] ? 'warn' : 'ok' ?>"><?= count($lists['conflicts']) ?> קונפליקטים פתוחים</span>
    <span><?= $fixCount ?> תיקוני מחיר ממתינים</span>
</div>
<div class="tabs">
    <?php foreach (['conflicts' => 'קונפליקטים (' . count($lists['conflicts']) . ')', 'resolved' => 'נפתרו / נערכו (' . count($lists['resolved']) . ')', 'all' => 'כל הסדרות (' . count($bases) . ')'] + ($data['changes'] ? ['changes' => 'שינויים מהחלוקה הקודמת'] : []) as $k => $label): ?>
    <a class="<?= $show === $k ? 'on' : '' ?>" href="?shop=<?= urlencode($shop) ?>&show=<?= $k ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($show === 'changes'):
    $c = $data['changes']; ?>
<div class="box">
    <div><strong>חדשים:</strong> <?= count($c['added']) ?> · <strong>הוסרו:</strong> <?= count($c['removed']) ?> · <strong>מחיר השתנה:</strong> <?= count($c['priceChanged']) ?></div>
    <div class="muted" style="font-size:16px;margin-top:6px">ההחלטות הקודמות (A–J, "לא מסדרה", תיקוני מחיר) נשמרו לפי ברקוד. תיקון מחיר שקומקס כבר מראה ירד מהרשימה.</div>
</div>
<?php foreach (['priceChanged' => 'מחיר השתנה', 'added' => 'פריטים חדשים', 'removed' => 'פריטים שהוסרו'] as $k => $title): if (!$c[$k]) continue; ?>
<h3><?= h($title) ?> (<?= count($c[$k]) ?>)</h3>
<table style="margin-bottom:26px">
    <tr><th>ברקוד</th><th>שם פריט</th><?= $k === 'priceChanged' ? '<th>מחיר קודם</th><th>מחיר חדש</th>' : '<th>מחיר</th>' ?></tr>
    <?php foreach (array_slice($c[$k], 0, 500) as $it): ?>
    <tr><td><?= h($it['barcode']) ?></td><td><?= h($it['name']) ?></td>
        <?= $k === 'priceChanged' ? '<td>' . money($it['old']) . '</td><td>' . money($it['new']) . '</td>' : '<td>' . money($it['price']) . '</td>' ?></tr>
    <?php endforeach; ?>
</table>
<?php endforeach; ?>

<?php else:
    $list = $lists[$show];
    if (!$list): ?>
<div class="box ok"><?= $show === 'conflicts' ? '✓ אין קונפליקטים פתוחים' : 'אין סדרות להצגה' ?></div>
<?php endif;
    foreach ($list as $s): ?>
<form class="card <?= $s['open'] ? 'open' : (($s['conflict'] || $s['touched']) ? 'done' : '') ?>" id="s<?= $s['id'] ?>" method="post" action="save.php">
    <input type="hidden" name="shop" value="<?= h($shop) ?>">
    <input type="hidden" name="base" value="<?= $s['id'] ?>">
    <input type="hidden" name="show" value="<?= h($show) ?>">
    <div class="card-head">
        <span class="title">#<?= $s['id'] ?> · <?= h($s['brand'] ?: 'ללא יצרן') ?> · <?= h($s['line']) ?> · <?= h($s['sizeText']) ?></span>
        <?php foreach ($s['parts'] as $p): ?>
        <span class="part <?= $p['conflict'] ? 'bad' : '' ?>">
            <?= $p['letter'] === '' ? 'הסדרה' : 'תת-סדרה ' . h($p['letter']) ?>:
            <?= $p['conflict'] ? h(implode(' / ', array_map(fn($pr, $n) => "$pr ×$n", array_keys($p['prices']), $p['prices']))) : money($p['price']) . ' (' . count($p['items']) . ')' ?>
        </span>
        <?php endforeach; ?>
    </div>
    <table>
        <tr><th>ברקוד</th><th>שם פריט</th><th>מחיר בקומקס</th><th>שיוך</th><th>תיקון מחיר</th></tr>
        <?php
        $partOf = [];
        foreach ($s['parts'] as $p) foreach ($p['items'] as $it) $partOf[$it['barcode']] = $p;
        foreach ($s['items'] as $it):
            $bc = $it['barcode']; $d = $dec[$bc] ?? []; $a = $d['assign'] ?? '';
            $p = $partOf[$bc] ?? null;
            $eff = seriesItemPrice($it, $dec);
            $diff = $p && abs($eff - $p['price']) > 0.001;
        ?>
        <tr class="<?= isset($d['fixPrice']) ? 'fixed' : '' ?> <?= $a === 'none' ? 'out' : '' ?>">
            <td><?= h($bc) ?></td>
            <td><?= h($it['name']) ?></td>
            <td class="<?= $diff ? 'diff' : '' ?>"><?= money($it['price']) ?></td>
            <td>
                <select name="assign[<?= h($bc) ?>]">
                    <option value="" <?= $a === '' ? 'selected' : '' ?>>הסדרה</option>
                    <?php foreach (SERIES_LETTERS as $l): ?><option value="<?= $l ?>" <?= $a === $l ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
                    <option value="none" <?= $a === 'none' ? 'selected' : '' ?>>לא מסדרה</option>
                </select>
            </td>
            <td><input class="fix" type="text" inputmode="decimal" name="fix[<?= h($bc) ?>]" value="<?= isset($d['fixPrice']) ? money($d['fixPrice']) : '' ?>" placeholder="—"></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <div class="card-foot">
        <button class="btn green">שמור</button>
        <span class="muted" style="font-size:16px">"הסדרה" = נשאר בסדרה כפי שנמצאה · A–J = תת-סדרה · תיקון מחיר = מחיר הסדרה לפריט מעכשיו, ונכנס לתיקוני המחיר לקומקס</span>
    </div>
</form>
<?php endforeach; endif; ?>
</body>
</html>

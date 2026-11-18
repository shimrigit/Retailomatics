<?php
// The shops and their series: when each was last split (or "ללא חלוקה לסדרות"),
// the newest Comax download, and the split button.
require_once __DIR__ . '/common.php';
$error = $_GET['error'] ?? '';
seriesPageHead('סדרות מוצרים');
?>
<h2>סדרות מוצרים</h2>
<p class="sub">חלוקת המחירון של כל לקוח לסדרות — לפי ההורדה האחרונה מקומקס (ה-Gateway) בתיקיית ProductsList של החנות.</p>
<?php if ($error): ?><div class="err">⚠ <?= h($error) ?></div><?php endif; ?>
<table>
    <tr><th>לקוח</th><th>חלוקה לסדרות</th><th>הורדה אחרונה מקומקס</th><th></th></tr>
    <?php foreach (seriesShops() as $shop):
        $dl = seriesLatestDownload($shop);
        $data = seriesLoad($shop);
        $applied = $data ? seriesApply($data) : null;
        $conflicts = $applied ? count(array_filter($applied['series'], fn($s) => $s['conflict'])) : 0;
        $newer = $data && $dl && $dl['name'] !== $data['meta']['source'];
    ?>
    <tr>
        <td><strong><?= h($shop) ?></strong></td>
        <td>
            <?php if ($data): ?>
                <span class="ok">חולק לסדרות <?= h(date('d/m/Y H:i', strtotime($data['meta']['built']))) ?></span>
                <div class="muted" style="font-size:16px"><?= count($applied['series']) ?> סדרות ·
                    <?= $conflicts ? '<span class="warn">' . $conflicts . ' קונפליקטים</span>' : '<span class="ok">אין קונפליקטים</span>' ?></div>
            <?php else: ?>
                <span class="muted">ללא חלוקה לסדרות</span>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($dl): ?>
                <?= h(date('d/m/Y H:i', $dl['mtime'])) ?>
                <?php if ($newer): ?><div class="warn" style="font-size:16px">הורדה חדשה שעוד לא חולקה</div><?php endif; ?>
            <?php else: ?>
                <span class="muted">אין הורדה — הורד מה-Gateway של קומקס</span>
            <?php endif; ?>
        </td>
        <td style="white-space:nowrap">
            <?php if ($dl): ?>
            <form method="post" action="split.php" style="display:inline" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='מחלק...';">
                <input type="hidden" name="shop" value="<?= h($shop) ?>">
                <button class="btn<?= $data ? ' light' : '' ?>"><?= $data ? 'עדכן חלוקה' : 'חלק לסדרות' ?></button>
            </form>
            <?php endif; ?>
            <?php if ($data): ?>
            <a class="btn" href="view.php?shop=<?= urlencode($shop) ?>">פתח</a>
            <a class="btn light" href="export.php?shop=<?= urlencode($shop) ?>">ייצוא לאקסל</a>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
</body>
</html>

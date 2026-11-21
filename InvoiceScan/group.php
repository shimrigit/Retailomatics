<?php
/**
 * Retailomatics Invoice Scan — step 2½: pages that may belong together (scan_batch_groups():
 * the same supplier and date, the same document number, or a page without a header after a page).
 *
 * Are they one document of several pages, or separate documents? The pages are shown
 * large, side by side, each with what was read from it (document number, type, rows,
 * total) and a "belongs to document N" choice. The first suggestion: pages on which the
 * same document number was read are one document; a page without a header goes with the
 * page before it; the rest are separate.
 * POST → the documents are built (pages of one document merged) → batch.php.
 */
session_start();
require_once __DIR__ . '/lib/common.php';

$batch = scan_batch_load((string) ($_REQUEST['b'] ?? ''));
$groups = scan_batch_groups($batch);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assign = [];
    foreach ($groups as $gi => $files) {
        foreach ($files as $fi => $file) {
            $n = (int) ($_POST['doc'][$gi][$fi] ?? 0);
            if ($n >= 1 && $n <= count($files)) $assign[$file] = "g{$gi}d{$n}";
        }
    }
    header('Location: ' . scan_batch_next_url(scan_batch_build_docs($batch, $assign)));
    exit;
}
if (!$groups) {
    header('Location: batch.php?b=' . $batch['id']);
    exit;
}

$typeHe = ['invoice' => 'חשבונית', 'delivery_note' => 'תעודת משלוח', 'credit_or_return' => 'זיכוי / החזרה', 'other' => 'אחר'];
$n = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.');
scan_page_start('דפים שאולי שייכים יחד');
?>
<style>
.pages{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,560px),1fr));gap:18px}
.pg{border:2px solid #d9dde3;border-radius:8px;padding:10px;background:#fafbfc}
.pg img{width:100%;border:1px solid #c8cdd5;border-radius:4px;display:block}
.pg .meta{margin:8px 0;line-height:1.7}
.pg select{min-width:0;font-weight:600}
.pg[data-doc="1"]{border-color:#1d3557}.pg[data-doc="2"]{border-color:#c2410c}.pg[data-doc="3"]{border-color:#15803d}
.pg[data-doc="4"]{border-color:#7e22ce}.pg[data-doc="5"]{border-color:#be123c}
.bar{position:sticky;top:0;background:#fff;border:1px solid #d9dde3;border-radius:8px;padding:10px 14px;margin-bottom:12px;z-index:3;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
</style>
<form method="post" action="group.php">
    <input type="hidden" name="b" value="<?= scan_h($batch['id']) ?>">
    <div class="card">
        <h2>נמצאו דפים שאולי שייכים יחד</h2>
        <p>דפים מאותו ספק ומאותו תאריך, דפים שנקרא בהם אותו מספר מסמך, ודפים בלי כותרת (המשך של טבלה) ליד הדף שלפניהם.
            האם אלה כמה דפים של <b>אותו מסמך</b>, או <b>מסמכים שונים</b>? הדפים מוצגים בגדול — לחיצה על דף פותחת אותו בחלון נפרד.
            דפים שתבחרו להם אותו מספר מסמך יאוחדו למסמך אחד, לפי הסדר שכאן.</p>
    </div>
    <?php foreach ($groups as $gi => $files):
        // the group's header: the first page on which the supplier and date were read
        $first = $batch['pages'][$files[0]]['data'];
        foreach ($files as $file) { $d = $batch['pages'][$file]['data']; if (scan_issuer($d) !== '' && $d['doc_date'] !== '') { $first = $d; break; } }
        $match = InvoiceReader::matchSupplier($first);
        $supplier = $match['supplier'] ? ($match['supplier']['hebrewName'] ?? $match['supplier']['supplierName']) : $first['supplier_name_printed'];
        // Suggestion: the same document number read on two pages = one document; a page without
        // a header (continuation) = the document of the page before it.
        $byNumber = [];
        $suggest = [];
        foreach ($files as $fi => $file) {
            $pd = $batch['pages'][$file]['data'];
            $num = $pd['doc_number'];
            if ($num !== '' && isset($byNumber[$num])) {
                $suggest[$fi] = $byNumber[$num];
            } elseif ($fi > 0 && $num === '' && scan_page_is_continuation($pd)) {
                $suggest[$fi] = $suggest[$fi - 1];
            } else {
                $suggest[$fi] = count(array_unique($suggest)) + 1;
                if ($num !== '') $byNumber[$num] = $suggest[$fi];
            }
        }
        $allSame = count(array_unique($suggest)) === 1;
    ?>
        <div class="card group" data-g="<?= $gi ?>">
            <div class="bar">
                <b><?= scan_h($supplier ?: 'ספק לא נקרא') ?> · <?= scan_h($first['doc_date'] !== '' ? date('d/m/Y', strtotime($first['doc_date'])) : 'תאריך לא נקרא') ?> · <?= count($files) ?> דפים</b>
                <button type="button" class="second one">הכול מסמך אחד</button>
                <button type="button" class="second each">כל דף מסמך נפרד</button>
                <span class="mute">ההצעה: <?= $allSame ? 'מסמך אחד — אותו מספר מסמך, או דף המשך' : count(array_unique($suggest)) . ' מסמכים — לפי מספרי המסמך שנקראו' ?></span>
            </div>
            <div class="pages">
                <?php foreach ($files as $fi => $file): $d = $batch['pages'][$file]['data']; ?>
                    <div class="pg" data-doc="<?= $suggest[$fi] ?>">
                        <div class="meta">
                            שייך ל:
                            <select name="doc[<?= $gi ?>][<?= $fi ?>]">
                                <?php for ($k = 1; $k <= count($files); $k++): ?>
                                    <option value="<?= $k ?>"<?= $k === $suggest[$fi] ? ' selected' : '' ?>>מסמך <?= $k ?></option>
                                <?php endfor; ?>
                            </select>
                            · מספר שנקרא: <b dir="ltr"><?= scan_h($d['doc_number'] ?: '—') ?></b>
                            · <?= scan_h($typeHe[$d['doc_type']] ?? $d['doc_type']) ?>
                            <?php if (!empty($d['page_number'])): ?>· עמוד <?= (int) $d['page_number'] ?><?= !empty($d['page_of']) ? ' מתוך ' . (int) $d['page_of'] : '' ?><?php endif; ?>
                            <?php if (scan_page_is_continuation($d)): ?>· <b>דף המשך (בלי כותרת)</b><?php endif; ?>
                            · <?= count($d['items']) ?> שורות
                            · סה"כ ללא מע"מ <?= scan_h($n(InvoiceReader::compareTotal($d)['value'])) ?>
                            <br><span class="mute" dir="ltr"><?= scan_h($file) ?></span>
                        </div>
                        <a href="../preProcessDir/<?= rawurlencode($file) ?>" target="_blank"><img src="../preProcessDir/<?= rawurlencode($file) ?>" alt=""></a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <button type="submit">המשך</button>
    <a class="btn second" href="index.php">חזרה לבחירת מסמכים</a>
</form>
<script>
document.querySelectorAll('.group').forEach(g => {
    const pages = [...g.querySelectorAll('.pg')];
    const paint = () => pages.forEach(p => p.dataset.doc = p.querySelector('select').value);
    pages.forEach(p => p.querySelector('select').addEventListener('change', paint));
    g.querySelector('.one').addEventListener('click', () => { pages.forEach(p => p.querySelector('select').value = 1); paint(); });
    g.querySelector('.each').addEventListener('click', () => { pages.forEach((p, i) => p.querySelector('select').value = i + 1); paint(); });
});
</script>
<?php scan_page_end();

<?php
/**
 * Retailomatics Invoice Scan — the documents of a batch, one row each, to approve one by one.
 *
 * Each row: the first page, what was identified (supplier, date, number, type, rows, total)
 * and its state — waiting for approval, or approved (already handed to OCRsanity).
 * "אישור הזיהוי" opens confirm.php for that document. Files whose reading failed are listed
 * under the table; they stay in preProcessDir and can be chosen again.
 */
session_start();
require_once __DIR__ . '/lib/common.php';

$batch = scan_batch_load((string) ($_GET['b'] ?? ''));
$typeHe = ['invoice' => 'חשבונית', 'delivery_note' => 'תעודת משלוח', 'credit_or_return' => 'זיכוי / החזרה', 'other' => 'אחר'];
$n = fn($v) => $v === null ? '<span class="mute">—</span>' : scan_h(rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.'));
$failed = array_filter($batch['pages'], fn($p) => isset($p['error']));
$_SESSION['invoiceScanBatch'] = $batch['id'];
scan_page_start('רשימת המסמכים');
?>
<div class="card" style="overflow-x:auto">
    <h2><?= count($batch['docs']) ?> מסמכים · חנות: <?= scan_h($batch['shop']) ?></h2>
    <table style="width:100%">
        <tr><th>#</th><th>עמוד ראשון</th><th>ספק</th><th>תאריך</th><th>מספר</th><th>סוג</th><th>עמודים</th><th>שורות</th><th>סה"כ ללא מע"מ</th><th>מצב</th><th></th></tr>
        <?php foreach ($batch['docs'] as $i => $docId):
            $work = scan_load($docId);
            $d = $work['read']['data'];
            $match = InvoiceReader::matchSupplier($d);
            $first = $work['files'][0];
            $isPdf = strtolower(pathinfo($first, PATHINFO_EXTENSION)) === 'pdf';
        ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?php if ($isPdf): ?><a href="confirm.php?id=<?= scan_h($docId) ?>">PDF</a>
                    <?php else: ?><a href="confirm.php?id=<?= scan_h($docId) ?>" title="לתצוגה מול הנתונים"><img src="<?= scan_source_url($work, $first) ?>" alt="" loading="lazy" style="width:110px;border:1px solid #c8cdd5;border-radius:4px"></a><?php endif; ?></td>
                <td><?php if (!empty($work['approved'])): ?><b><?= scan_h($work['approved']['supplier']) ?></b>
                    <?php elseif ($match['supplier']): ?><span class="ok">✔</span> <?= scan_h(($match['supplier']['hebrewName'] ?? '') . ' · ' . $match['supplier']['supplierName']) ?>
                    <?php else: ?><span class="warn">לא ברשימה:</span> <?= scan_h($d['supplier_name_printed'] ?: '—') ?><?php endif; ?></td>
                <td><?= $d['doc_date'] !== '' ? scan_h(date('d/m/Y', strtotime($d['doc_date']))) : '<span class="mute">—</span>' ?></td>
                <td class="num"><?= scan_h($d['doc_number'] ?: '—') ?></td>
                <td><?= scan_h($typeHe[$d['doc_type']] ?? $d['doc_type']) ?></td>
                <td><?= count($work['files']) ?></td>
                <td><?= count($d['items']) ?></td>
                <td class="num"><?= $n(InvoiceReader::compareTotal($d)['value']) ?></td>
                <td><?= !empty($work['approved']) ? '<span class="ok">✔ אושר</span><br><span class="mute" dir="ltr">' . scan_h($work['approved']['pdfFile']) . '</span>' : 'ממתין לאישור' ?></td>
                <td><a class="btn<?= !empty($work['approved']) ? ' second' : '' ?>" href="confirm.php?id=<?= scan_h($docId) ?>"><?= !empty($work['approved']) ? 'פתח שוב' : 'בדיקה ואישור' ?></a></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php if ($failed): ?>
        <p class="warn" style="margin-top:14px">קבצים שהקריאה שלהם נכשלה (נשארו ב-preProcessDir, אפשר לבחור אותם שוב):</p>
        <ul><?php foreach ($failed as $file => $p): ?><li><span dir="ltr"><?= scan_h($file) ?></span> — <?= scan_h($p['error']) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
</div>
<p class="mute">אחרי אישור מסמך ממשיכים איתו ל-OCRsanity ולשכבה המסחרית. כדי לחזור לרשימה הזו: <span dir="ltr">InvoiceScan/batch.php?b=<?= scan_h($batch['id']) ?></span> (או "רשימת המסמכים" בראש המסך).</p>
<a class="btn second" href="index.php">בחירת מסמכים נוספים</a>
<?php scan_page_end();

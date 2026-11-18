<?php
/**
 * New Products Verification Interface
 * Split-screen view: NP file data (right, Handsontable) + CHP price search (left)
 */

session_start();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/chp_headless_client.php';
require_once __DIR__ . '/cpd_client.php';   // CPD — the chains' price files; chp.co.il no longer answers the scraper
use PhpOffice\PhpSpreadsheet\IOFactory;

$npFileName = $_GET['npFile'] ?? $_SESSION['npFileName'] ?? null;
$shopName   = $_GET['shop']   ?? $_SESSION['shopName']   ?? 'CountryMZ';
if (!$npFileName) die('Error: No NP file specified');

// Shop config
$shopsConfig = json_decode(file_get_contents(__DIR__ . '/../configDir/shops_V2.json'), true);
$shopConfig  = null;
foreach ($shopsConfig as $s) { if ($s['ShopName'] === $shopName) { $shopConfig = $s; break; } }
$defaultCity = $shopConfig['shopDefaultCity'] ?? 'תל אביב';

// Departments for dropdown
$departmentsConfigPath = __DIR__ . '/../configDir/' . $shopName . '_Departments.json';
$departments   = [];
$departmentMap = []; // name → code
$departmentMargins = []; // name → "40%" (as calculate_margins.php writes it)
if (file_exists($departmentsConfigPath)) {
    foreach (json_decode(file_get_contents($departmentsConfigPath), true) as $d) {
        if (isset($d['DepartmentName'])) {
            $departments[]                       = $d['DepartmentName'];
            $departmentMap[$d['DepartmentName']] = $d['DepartmentNumber'] ?? '';
            if (isset($d['ExpectedMarginPercentage'])) $departmentMargins[$d['DepartmentName']] = round($d['ExpectedMarginPercentage'] * 100, 2) . '%';
        }
    }
}

// NP file path
$npFilePath = __DIR__ . DIRECTORY_SEPARATOR . 'commercial_invoice_files' . DIRECTORY_SEPARATOR . $npFileName;
if (!file_exists($npFilePath)) die('Error: NP file not found: ' . $npFilePath);

// ── JSON endpoints ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    if ($rawInput) {
        $jsonInput = json_decode($rawInput, true);
        if ($jsonInput && isset($jsonInput['action'])) {

        // ── getData: return fresh NP table data (called after margin calculation) ──
        if ($jsonInput['action'] === 'getData') {
            header('Content-Type: application/json');
            $sp2  = IOFactory::load($npFilePath);
            $sh2  = $sp2->getActiveSheet();
            $d2   = $sh2->toArray();
            $bIdx = array_search('Barcode', $d2[0]);
            $fr2  = []; $rn2 = [];
            for ($i = 1; $i < count($d2); $i++) {
                if (!empty($d2[$i][$bIdx] ?? '')) {
                    $rn2[] = $i + 1;
                    $fr2[] = array_values(array_slice($d2[$i], 2));
                }
            }
            echo json_encode(['success' => true, 'tableData' => array_values($fr2), 'rowNums' => $rn2]);
            exit;
        }

        // ── save: persist edits from Handsontable to Excel ──────────────────
        if ($jsonInput['action'] === 'save') {
            header('Content-Type: application/json');
            $rows = $jsonInput['rows'] ?? [];
            if (empty($rows)) { echo json_encode(['success' => true, 'savedCount' => 0]); exit; }

            $sp  = IOFactory::load($npFilePath);
            $sh  = $sp->getActiveSheet();
            $hdr = array_values($sh->rangeToArray('A1:T1')[0]);

            $cBarcode      = array_search('Barcode',        $hdr);
            $cERPName      = array_search('ItemERPName',    $hdr);
            $cDeptName     = array_search('DepartmentName', $hdr);
            $cDeptCode     = array_search('DepartmentCode', $hdr);
            if ($cDeptCode === false) $cDeptCode = array_search('DepartmentNumber', $hdr);
            if ($cDeptCode === false) $cDeptCode = 14; // column O fallback (0-based)
            $cDeptMargin   = array_search('DepartmentMargin', $hdr);
            $cSalePrice    = array_search('SalePrice',   $hdr);
            $cActualMargin = array_search('ActualMargin', $hdr);

            foreach ($rows as $row) {
                $rn = (int)$row['rowNum'];
                if ($rn <= 1) continue;
                if ($cBarcode  !== false && array_key_exists('barcode',        $row))
                    $sh->setCellValueByColumnAndRow($cBarcode  + 1, $rn, $row['barcode']);
                if ($cERPName  !== false && array_key_exists('itemERPName',    $row))
                    $sh->setCellValueByColumnAndRow($cERPName  + 1, $rn, $row['itemERPName']);
                if ($cDeptName !== false && array_key_exists('departmentName', $row)) {
                    $dn = $row['departmentName'];
                    $sh->setCellValueByColumnAndRow($cDeptName + 1, $rn, $dn);
                    $sh->setCellValueByColumnAndRow($cDeptCode + 1, $rn, $departmentMap[$dn] ?? '');
                    if ($cDeptMargin !== false && array_key_exists('departmentMargin', $row))
                        $sh->setCellValueByColumnAndRow($cDeptMargin + 1, $rn, (string) $row['departmentMargin']);
                }
                if ($cSalePrice    !== false && array_key_exists('salePrice',    $row))
                    $sh->setCellValueByColumnAndRow($cSalePrice    + 1, $rn, $row['salePrice']);
                if ($cActualMargin !== false && array_key_exists('actualMargin', $row)
                        && $row['actualMargin'] !== null && $row['actualMargin'] !== '')
                    $sh->setCellValueByColumnAndRow($cActualMargin + 1, $rn, $row['actualMargin']);
            }
            $writer = IOFactory::createWriter($sp, 'Xlsx');
            $writer->save($npFilePath);
            echo json_encode(['success' => true, 'savedCount' => count($rows)]);
            exit;
        } // end action === 'save'

        } // end isset($jsonInput['action'])
    }
}
// ──────────────────────────────────────────────────────────────────────────

// Load NP data for display
$spreadsheet = IOFactory::load($npFilePath);
$sheet       = $spreadsheet->getActiveSheet();
$npData      = $sheet->toArray();

// CHP search
$chpResults    = null;
$chpError      = null;
$searchBarcode = '';
$searchCity    = $defaultCity;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chp_search'])) {
    $searchBarcode = trim($_POST['barcode'] ?? '');
    $searchCity    = trim($_POST['city']    ?? $defaultCity);
    if (empty($searchBarcode) || empty($searchCity)) {
        $chpError = 'נא למלא ברקוד ועיר';
    } else {
        $client = new CPDClient();   // was CHPHeadlessClient (same searchPrices contract; the city is ignored — CPD is Tel Aviv)
        ob_start();
        $chpResults = $client->searchPrices($searchBarcode, $searchCity);
        ob_end_clean();
        if (!$chpResults || empty($chpResults['prices']))
            $chpError = 'לא נמצאו תוצאות עבור הברקוד והעיר שהוזנו';
    }
}

// Finalize
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize'])) {
    $_SESSION['np_complete'] = true;
    header('Location: new_products_complete.php?npFile=' . urlencode($npFileName));
    exit;
}

// Prepare Handsontable data (hide cols A & B, skip rows with no barcode)
$barcodeHdrIdx = array_search('Barcode', $npData[0]);
$rowNums       = []; // 1-based spreadsheet row numbers for each visible HOT row
$filteredRows  = [];
for ($i = 1; $i < count($npData); $i++) {
    if (!empty($npData[$i][$barcodeHdrIdx] ?? '')) {
        $rowNums[]      = $i + 1;
        $filteredRows[] = array_values(array_slice($npData[$i], 2));
    }
}
$npHeaders = array_values(array_slice($npData[0], 2));
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>אימות מוצרים חדשים - Commercial Layer</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/handsontable@12.3.1/dist/handsontable.full.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body { font-family: Arial, sans-serif; background: #f5f5f5; height: 100vh; overflow: hidden; }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; padding: 15px 30px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .header h1   { font-size: 1.8em; direction: ltr; }
        .file-info   { font-size: 0.9em; opacity: 0.9; margin-top: 5px; }

        .finalize-button {
            padding: 12px 25px; font-size: 1.1em; font-weight: bold;
            background: #28a745; color: white; border: none;
            border-radius: 8px; cursor: pointer; transition: all 0.3s;
        }
        .finalize-button:hover  { background: #218838; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(40,167,69,0.4); }
        .finalize-button:disabled { background: #6c757d; cursor: not-allowed; transform: none; box-shadow: none; }

        .calculate-button {
            padding: 12px 25px; font-size: 1.1em; font-weight: bold;
            background: #ff9800; color: white; border: none;
            border-radius: 8px; cursor: pointer; transition: all 0.3s; margin-right: 15px;
        }
        .calculate-button:hover    { background: #f57c00; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(255,152,0,0.4); }
        .calculate-button:disabled { background: #6c757d; cursor: not-allowed; transform: none; box-shadow: none; }

        .split-container { display: flex; height: calc(100vh - 80px); gap: 10px; padding: 10px; }

        .left-panel {
            width: 500px; background: white; border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            display: flex; flex-direction: column; overflow: hidden;
        }
        .right-panel {
            flex: 1; min-width: 0; background: white; border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            display: flex; flex-direction: column; overflow: hidden;
        }
        .panel-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; padding: 15px 20px; font-size: 1.3em; font-weight: bold;
        }
        .panel-content { flex: 1; overflow: auto; padding: 15px; }

        #hotContainer { overflow: hidden; position: relative; direction: ltr; }

        /* DepartmentMargin cells — system-set, read-only */
        .htCalcCell {
            background-color: #ffe0b2 !important;
            color: #000000 !important;
        }

        /* Gold highlight for bidirectional calculation output cell */
        .htGoldCell { background-color: #FFD700 !important; }

        .copied-notification {
            position: fixed; top: 80px; right: 20px;
            background: #28a745; color: white;
            padding: 15px 25px; border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 10000; opacity: 0;
        }
        .copied-notification.show { opacity: 1; animation: slideIn 0.3s, slideOut 0.3s 2.7s; }
        @keyframes slideIn { from { transform: translateX(400px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(400px); opacity: 0; } }

        /* CHP Form */
        .chp-form       { display: flex; flex-direction: column; gap: 15px; margin-bottom: 20px; }
        .form-group     { display: flex; flex-direction: column; }
        .form-group label { font-size: 1em; font-weight: bold; margin-bottom: 5px; color: #333; }
        .form-group input {
            padding: 12px; font-size: 1em; border: 2px solid #ddd;
            border-radius: 6px; transition: border-color 0.3s;
        }
        .form-group input:focus { outline: none; border-color: #667eea; }

        .search-button {
            padding: 12px 20px; font-size: 1.1em; font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; border: none; border-radius: 8px; cursor: pointer; transition: all 0.3s;
        }
        .search-button:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(102,126,234,0.4); }

        .error-message {
            background: #fee; border: 2px solid #fcc; border-radius: 6px;
            padding: 12px; color: #c33; text-align: center; margin-bottom: 15px;
        }

        /* CHP Results */
        .chp-results { margin-top: 20px; }
        .chp-stats   { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 15px; }
        .stat-box    { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 12px; border-radius: 8px; text-align: center; }
        [data-copy-price], [data-copy-name] { cursor: copy; }
        [data-copy-price]:hover, [data-copy-name]:hover { outline: 2px solid #ffb300; outline-offset: 1px; }
        [data-copy-name] { text-decoration: underline dotted; }
        .stat-box .value { font-size: 1.4em; font-weight: bold; display: block; }
        .stat-box .label { font-size: 0.85em; opacity: 0.9; }
        .chp-table   { width: 100%; border-collapse: collapse; font-size: 0.85em; }
        .chp-table thead { background: #f8f9fa; }
        .chp-table th { padding: 8px; text-align: right; font-weight: bold; border-bottom: 2px solid #667eea; }
        .chp-table tbody tr { border-bottom: 1px solid #e0e0e0; }
        .chp-table tbody tr:hover { background-color: #f5f5f5; }
        .chp-table td { padding: 8px; text-align: right; }
        .best-price-row { background-color: #d4edda !important; border-right: 4px solid #28a745; }
        .price-cell { font-weight: bold; color: #27ae60; font-size: 1.1em; }

        /* Loading overlay */
        .loading-overlay {
            display: none; position: fixed; top: 0; left: 0;
            width: 100%; height: 100%; background: rgba(0,0,0,0.7);
            z-index: 10000; justify-content: center; align-items: center;
        }
        .loading-overlay.show { display: flex; }
        .loading-content { background: white; padding: 40px; border-radius: 15px; text-align: center; box-shadow: 0 10px 40px rgba(0,0,0,0.3); }
        .loading-spinner { border: 5px solid #f3f3f3; border-top: 5px solid #667eea; border-radius: 50%; width: 60px; height: 60px; animation: spin 1s linear infinite; margin: 0 auto 20px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .loading-text { font-size: 1.2em; color: #333; font-weight: bold; }

        #saveMessage { margin: 0 10px; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <button type="button" class="calculate-button" id="calcMarginsBtn" onclick="calculateMargins()">
                🧮 Calculate Margins
            </button>
            <form id="finalizeForm" method="POST" style="display: inline;">
                <input type="hidden" name="finalize" value="1">
                <button type="button" class="finalize-button" id="finalizeBtn" onclick="saveAndFinalize()">
                    💾 Save New Products File
                </button>
            </form>
        </div>
        <div>
            <h1>🆕 Verify New Products</h1>
            <div class="file-info">File: <?= htmlspecialchars($npFileName) ?> | Shop: <?= htmlspecialchars($shopName) ?></div>
        </div>
    </div>

    <div id="saveMessage"></div>

    <div class="split-container">
        <!-- LEFT PANEL: CHP Search -->
        <div class="left-panel">
            <div class="panel-header">🔎 חיפוש מחירים בשוק</div>
            <div class="panel-content">
                <form method="POST" class="chp-form" id="chpSearchForm">
                    <div class="form-group">
                        <label for="city">עיר:</label>
                        <input type="text" id="city" name="city" value="<?= htmlspecialchars($searchCity) ?>">
                    </div>
                    <div class="form-group">
                        <label for="barcode">ברקוד מוצר:</label>
                        <input type="text" id="barcode" name="barcode"
                               placeholder="לחץ על ברקוד מהטבלה להעתקה"
                               value="<?= htmlspecialchars($searchBarcode) ?>" required>
                    </div>
                    <button type="submit" name="chp_search" class="search-button">🔎 חפש במאגר המחירים</button>
                </form>

                <div id="chpResultsContainer">
                    <?php if ($chpError): ?>
                        <div class="error-message">⚠️ <?= htmlspecialchars($chpError) ?></div>
                    <?php endif; ?>

                    <?php if ($chpResults && !empty($chpResults['prices'])): ?>
                        <div class="chp-results">
                            <?php
                            $prices   = array_filter(array_map(fn($item) => is_numeric($item['price']) ? (float)$item['price'] : null, $chpResults['prices']));
                            $minPrice = !empty($prices) ? min($prices) : 0;
                            $maxPrice = !empty($prices) ? max($prices) : 0;
                            $avgPrice = !empty($prices) ? array_sum($prices) / count($prices) : 0;
                            ?>
                            <div class="chp-stats">
                                <div class="stat-box" data-copy-price="<?= round($minPrice, 2) ?>" title="לחיצה מעתיקה למחיר המכירה בשורה שנבחרה"><span class="value"><?= number_format($minPrice, 2) ?> ₪</span><span class="label">מחיר מינימום</span></div>
                                <div class="stat-box" data-copy-price="<?= round($avgPrice, 2) ?>" title="לחיצה מעתיקה למחיר המכירה בשורה שנבחרה"><span class="value"><?= number_format($avgPrice, 2) ?> ₪</span><span class="label">מחיר ממוצע</span></div>
                                <div class="stat-box" data-copy-price="<?= round($maxPrice, 2) ?>" title="לחיצה מעתיקה למחיר המכירה בשורה שנבחרה"><span class="value"><?= number_format($maxPrice, 2) ?> ₪</span><span class="label">מחיר מקסימום</span></div>
                                <div class="stat-box"><span class="value"><?= count($chpResults['prices']) ?></span><span class="label">חנויות</span></div>
                            </div>
                            <?php if (!empty($chpResults['productName'])): ?>
                                <div style="background:#f8f9fa;padding:10px;border-radius:5px;margin-bottom:10px;">
                                    <strong>שם המוצר:</strong> <span data-copy-name="<?= htmlspecialchars($chpResults['productName']) ?>" title="לחיצה מעתיקה לשם המוצר (ItemERPName) בשורה שנבחרה"><?= htmlspecialchars($chpResults['productName']) ?></span>
                                </div>
                            <?php endif; ?>
                            <table class="chp-table">
                                <thead><tr><th>רשת</th><th>חנות</th><th>מחיר</th></tr></thead>
                                <tbody>
                                    <?php foreach ($chpResults['prices'] as $item): ?>
                                        <?php $isBest = is_numeric($item['price']) && (float)$item['price'] == $minPrice; ?>
                                        <tr class="<?= $isBest ? 'best-price-row' : '' ?>">
                                            <td><?= htmlspecialchars($item['chain'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($item['store'] ?? '') ?></td>
                                            <td class="price-cell"<?= is_numeric($item['price']) ? ' data-copy-price="' . round((float) $item['price'], 2) . '" title="לחיצה מעתיקה למחיר המכירה בשורה שנבחרה"' : '' ?>>
                                                <?= is_numeric($item['price']) ? number_format((float)$item['price'], 2) . ' ₪' : htmlspecialchars($item['price']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL: NP Handsontable -->
        <div class="right-panel">
            <div class="panel-header">📋 נתוני מוצרים חדשים</div>
            <div id="hotContainer"></div>
        </div>
    </div>

    <div id="copiedNotification" class="copied-notification">✓ ברקוד הועתק בהצלחה!</div>

    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-content">
            <div class="loading-spinner"></div>
            <div class="loading-text">מחשב שוליים...</div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/handsontable@12.3.1/dist/handsontable.full.min.js"></script>
    <script>
        // ── Data from PHP ─────────────────────────────────────────────────────
        const npHeaders   = <?php echo json_encode($npHeaders); ?>;
        let npTableData = <?php echo json_encode(array_values($filteredRows)); ?>;
        const rowNums     = <?php echo json_encode($rowNums); ?>;
        const departments = <?php echo json_encode($departments); ?>;
        const departmentMargins = <?php echo json_encode((object) $departmentMargins, JSON_UNESCAPED_UNICODE); ?>;
        // The department's margin, written as soon as the department is chosen and removed when it is cleared.
        const marginOf = name => { name = String(name ?? '').trim(); return name === '' ? '' : (departmentMargins[name] ?? 'Department not found'); };

        const barcodeColIdx         = npHeaders.indexOf('Barcode');
        const itemERPNameColIdx     = npHeaders.indexOf('ItemERPName');
        const deptNameColIdx        = npHeaders.indexOf('DepartmentName');
        const deptMarginColIdx      = npHeaders.indexOf('DepartmentMargin');
        const actualMarginColIdx    = npHeaders.indexOf('ActualMargin');
        const salePriceColIdx       = npHeaders.indexOf('SalePrice');
        const actualUnitPriceColIdx = npHeaders.indexOf('ActualUnitPrice');

        const editableCols = new Set(
            [barcodeColIdx, itemERPNameColIdx, deptNameColIdx, salePriceColIdx, actualMarginColIdx].filter(i => i >= 0)
        );

        const dirtyRows      = new Set();
        let activeRow = -1;   // the last row selected in the table — where a click in the price search copies to
        const highlightedCells = new Map(); // "row,col" → true; tracks bidirectional calc output

        // ── Barcode renderer ────────────────────────────────────────────────
        function barcodeRenderer(hotInstance, TD, row, col, prop, value, cp) {
            Handsontable.renderers.TextRenderer.apply(this, arguments);
            TD.style.color          = '#667eea';
            TD.style.cursor         = 'pointer';
            TD.style.fontWeight     = 'bold';
            TD.style.textDecoration = 'underline';
        }

        // ── Column config ───────────────────────────────────────────────────
        const columns = npHeaders.map((header, idx) => {
            const col = { readOnly: !editableCols.has(idx) };
            if (idx === barcodeColIdx) {
                col.renderer = barcodeRenderer;
            }
            if (idx === deptNameColIdx) {
                col.type       = 'autocomplete';
                col.source     = departments;
                col.strict     = false;
                col.visibleRows = 8;
            }
            if (idx === salePriceColIdx) {
                col.type = 'numeric';
                col.numericFormat = { pattern: '0.00' };
            }
            return col;
        });

        // ── The last column: a button that sets the sale price by the department's margin ──
        const priceBtnColIdx = npHeaders.length;
        function priceBtnRenderer(hotInstance, TD, row) {
            const ok = marginOf(hotInstance.getDataAtCell(row, deptNameColIdx)).endsWith('%') && parseFloat(hotInstance.getDataAtCell(row, actualUnitPriceColIdx)) > 0;
            TD.innerHTML = '<button type="button" tabindex="-1" style="font-size:11px;padding:2px 6px;border-radius:4px;border:1px solid #667eea;background:' + (ok ? '#667eea;color:#fff;cursor:pointer' : '#eee;color:#999;cursor:not-allowed') + '">מחיר לפי מרווח</button>';
            TD.style.textAlign = 'center';
            TD.title = ok ? 'מחיר המכירה = מחיר הקנייה, בתוספת מרווח המחלקה ומע"מ' : 'צריך מחלקה (עם מרווח) ומחיר קנייה';
        }
        columns.push({ readOnly: true, renderer: priceBtnRenderer, width: 105 });
        function priceByDepartment(row) {
            const m = parseFloat(marginOf(hot.getDataAtCell(row, deptNameColIdx))), cost = parseFloat(hot.getDataAtCell(row, actualUnitPriceColIdx));
            if (isNaN(m) || m >= 100 || !(cost > 0)) return;
            // the same formula as typing the margin by hand: cost / (1 - margin) + VAT
            // rounded up to a whole shekel (282.51 → 283); the small epsilon keeps an exact 283.00 from becoming 284
            hot.setDataAtCell(row, salePriceColIdx, Math.ceil((cost / (1 - m / 100)) * 1.18 - 1e-9));
        }

        // ── Height ─────────────────────────────────────────────────────────
        function hotHeight() {
            const top = document.getElementById('hotContainer').getBoundingClientRect().top;
            return Math.floor(window.innerHeight - top - 12);
        }

        // ── Handsontable init ───────────────────────────────────────────────
        const hot = new Handsontable(document.getElementById('hotContainer'), {
            data:               npTableData,
            colHeaders:         npHeaders.concat(['מחיר לפי מרווח']),
            columns:            columns,
            rowHeaders:         true,
            height:             hotHeight(),
            width:              '100%',
            licenseKey:         'non-commercial-and-evaluation',
            layoutDirection:    'ltr',
            manualColumnResize: true,
            manualRowResize:    false,
            contextMenu:        false,
            fillHandle:         false,
            wordWrap:           true,
            autoWrapRow:        true,
            afterGetColHeader(col, TH) {
                if (editableCols.has(col)) {
                    TH.style.backgroundColor = '#FFFF00';
                    TH.style.color           = '#000';
                }
            },
            cells(row, col) {
                // Gold for bidirectional calculation output cell
                if (highlightedCells.has(`${row},${col}`)) {
                    return { className: 'htGoldCell' };
                }
                // Orange for server-calculated margin columns (when they have values)
                if (col === deptMarginColIdx || col === actualMarginColIdx) {
                    const val = npTableData[row] ? npTableData[row][col] : null;
                    if (val !== null && val !== '' && val !== undefined) {
                        return { className: 'htCalcCell' };
                    }
                }
                return {};
            },
            afterChange(changes, source) {
                if (!changes || source === 'loadData' || source === 'calculated' || source === 'external') return;
                changes.forEach(([row, col, , newVal]) => {
                    if (!editableCols.has(col)) return;
                    dirtyRows.add(row);

                    if (col === deptNameColIdx && deptMarginColIdx >= 0) {
                        hot.setDataAtCell(row, deptMarginColIdx, marginOf(newVal), 'calculated');
                    } else if (col === salePriceColIdx) {
                        const actualUP  = parseFloat(hot.getDataAtCell(row, actualUnitPriceColIdx)) || 0;
                        const salePrice = parseFloat(newVal) || 0;
                        if (salePrice > 0 && actualUP > 0) {
                            const exVAT     = salePrice / 1.18;
                            const margin    = ((exVAT - actualUP) / exVAT) * 100;
                            hot.setDataAtCell(row, actualMarginColIdx, parseFloat(margin.toFixed(2)) + '%', 'calculated');   // as calculate_margins.php writes it
                            highlightedCells.set(`${row},${actualMarginColIdx}`, true);
                            highlightedCells.delete(`${row},${salePriceColIdx}`);
                        }
                        hot.render();
                    } else if (col === actualMarginColIdx) {
                        const actualUP  = parseFloat(hot.getDataAtCell(row, actualUnitPriceColIdx)) || 0;
                        const marginVal = parseFloat(newVal);
                        if (!isNaN(marginVal) && marginVal >= 0 && marginVal < 100 && actualUP > 0) {
                            const salePrice = parseFloat(((actualUP / (1 - marginVal / 100)) * 1.18).toFixed(2));
                            hot.setDataAtCell(row, salePriceColIdx, salePrice, 'calculated');
                            if (String(newVal).trim() !== marginVal + '%') hot.setDataAtCell(row, actualMarginColIdx, marginVal + '%', 'calculated');   // a typed 8 is shown as 8%
                            highlightedCells.set(`${row},${salePriceColIdx}`, true);
                            highlightedCells.delete(`${row},${actualMarginColIdx}`);
                        }
                        hot.render();
                    }
                });
            },
            afterSelectionEnd(row) { if (row >= 0) activeRow = row; },
            afterOnCellMouseDown(event, coords) {
                if (coords.col === priceBtnColIdx && coords.row >= 0) { priceByDepartment(coords.row); return; }
                if (coords.col === barcodeColIdx && coords.row >= 0) {
                    const barcode = String(hot.getDataAtCell(coords.row, barcodeColIdx) ?? '');
                    if (!barcode) return;
                    navigator.clipboard.writeText(barcode).catch(() => {}).finally(() => {
                        document.getElementById('barcode').value = barcode;
                        const n = document.getElementById('copiedNotification');
                        n.textContent = '✓ ברקוד הועתק בהצלחה!';
                        n.classList.add('show');
                        setTimeout(() => n.classList.remove('show'), 3000);
                    });
                }
            }
        });

        if (deptNameColIdx >= 0 && deptMarginColIdx >= 0) {
            const fill = [];
            for (let r = 0; r < hot.countRows(); r++) {
                const want = marginOf(hot.getDataAtCell(r, deptNameColIdx));
                if (hot.getDataAtCell(r, deptMarginColIdx) !== want) { fill.push([r, deptMarginColIdx, want]); dirtyRows.add(r); }
            }
            if (fill.length) hot.setDataAtCell(fill, 'calculated');
        }

        window.addEventListener('resize', () => hot.updateSettings({ height: hotHeight() }));

        // ── Save rows to Excel (dirty rows by default, all rows when saveAll=true) ─
        function doSave(silent = false, saveAll = false) {
            const indices = saveAll
                ? Array.from({ length: rowNums.length }, (_, i) => i)
                : Array.from(dirtyRows);

            const rows = indices
                .filter(i => i < rowNums.length)
                .map(i => ({
                    rowNum:         rowNums[i],
                    barcode:        barcodeColIdx         >= 0 ? hot.getDataAtCell(i, barcodeColIdx)         : undefined,
                    itemERPName:    itemERPNameColIdx      >= 0 ? hot.getDataAtCell(i, itemERPNameColIdx)     : undefined,
                    departmentName: deptNameColIdx         >= 0 ? hot.getDataAtCell(i, deptNameColIdx)        : undefined,
                    departmentMargin: deptMarginColIdx     >= 0 ? hot.getDataAtCell(i, deptMarginColIdx)      : undefined,
                    salePrice:      salePriceColIdx        >= 0 ? hot.getDataAtCell(i, salePriceColIdx)       : undefined,
                    actualMargin:   actualMarginColIdx     >= 0 ? hot.getDataAtCell(i, actualMarginColIdx)    : undefined,
                }));

            if (rows.length === 0) return Promise.resolve(true);

            const msgDiv = document.getElementById('saveMessage');
            if (!silent) {
                msgDiv.innerHTML = '<div style="background:#e3f2fd;border:2px solid #2196F3;padding:8px 20px;border-radius:8px;text-align:center;">🔄 Saving...</div>';
            }

            return fetch(window.location.href, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ action: 'save', rows })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    dirtyRows.clear();
                    if (!silent) {
                        msgDiv.innerHTML = `<div style="background:#d4edda;border:2px solid #28a745;padding:8px 20px;border-radius:8px;text-align:center;color:#155724;">✓ ${data.savedCount} row(s) saved</div>`;
                        setTimeout(() => msgDiv.innerHTML = '', 3000);
                    }
                    return true;
                }
                if (!silent) { alert('Save error: ' + (data.error ?? 'Unknown')); msgDiv.innerHTML = ''; }
                return false;
            })
            .catch(err => {
                if (!silent) { alert('Save error: ' + err); msgDiv.innerHTML = ''; }
                return false;
            });
        }

        // ── Calculate Margins ───────────────────────────────────────────────
        function calculateMargins() {
            const loadingOverlay = document.getElementById('loadingOverlay');
            const btn = document.getElementById('calcMarginsBtn');
            btn.disabled = true;
            loadingOverlay.classList.add('show');

            // Save ALL rows first (department selection must be persisted before margin calc)
            doSave(true, true)
                .then(ok => {
                    if (!ok) throw new Error('Save failed before margin calculation');
                    return fetch('calculate_margins.php', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify({
                            npFileName: <?php echo json_encode($npFileName); ?>,
                            shopName:   <?php echo json_encode($shopName); ?>
                        })
                    });
                })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) throw new Error(data.error || 'Margin calculation failed');
                    // Fetch updated data in-place — do NOT reload the page (would wipe CHP panel)
                    return fetch(window.location.href, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify({ action: 'getData' })
                    });
                })
                .then(r => r.json())
                .then(fresh => {
                    loadingOverlay.classList.remove('show');
                    btn.disabled = false;
                    if (fresh.success) {
                        // Update data reference (used by cells() for orange margin background)
                        npTableData = fresh.tableData;
                        highlightedCells.clear(); // server recalculated — clear manual highlights
                        hot.loadData(npTableData);
                    }
                })
                .catch(err => {
                    loadingOverlay.classList.remove('show');
                    btn.disabled = false;
                    alert('❌ Error: ' + err.message);
                });
        }

        // ── Save & Finalize ─────────────────────────────────────────────────
        function saveAndFinalize() {
            if (!confirm('Save New Products file?')) return;
            const btn = document.getElementById('finalizeBtn');
            btn.disabled    = true;
            btn.textContent = '⏳ Saving...';
            // Save ALL rows to make sure everything is persisted
            doSave(true, true).then(ok => {
                if (ok) {
                    document.getElementById('finalizeForm').submit();
                } else {
                    btn.disabled    = false;
                    btn.textContent = '💾 Save New Products File';
                    alert('Save failed — please retry.');
                }
            });
        }

        // ── A click in the price search copies into the table ────────────────
        // A price (a store's, the minimum, average or maximum) → SalePrice of the selected row;
        // the product's name → its ItemERPName. Written as if typed: the margin is recalculated and the row is saved.
        function notify(text) {
            const n = document.getElementById('copiedNotification');
            n.textContent = text;
            n.classList.add('show');
            setTimeout(() => n.classList.remove('show'), 2500);
        }
        document.getElementById('chpResultsContainer').addEventListener('click', e => {
            const el = e.target.closest('[data-copy-price],[data-copy-name]');
            if (!el) return;
            if (activeRow < 0 || activeRow >= hot.countRows()) { notify('בחרו קודם שורה בטבלה'); return; }
            const isPrice = el.hasAttribute('data-copy-price');
            const col = isPrice ? salePriceColIdx : itemERPNameColIdx;
            if (col < 0) return;
            hot.setDataAtCell(activeRow, col, isPrice ? parseFloat(el.dataset.copyPrice) : el.dataset.copyName);
            hot.selectCell(activeRow, col);
            notify('✓ הועתק לשורה ' + (activeRow + 1) + (isPrice ? ' — מחיר מכירה ' + el.dataset.copyPrice : ' — שם המוצר'));
        });

        // ── CHP Search (AJAX) ───────────────────────────────────────────────
        document.getElementById('chpSearchForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('chp_search', '1');
            const resultsContainer = document.getElementById('chpResultsContainer');
            resultsContainer.innerHTML = '<div style="text-align:center;padding:20px;">🔄 מחפש...</div>';
            fetch(window.location.href, { method: 'POST', body: formData })
                .then(r => r.text())
                .then(html => {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const newResults = doc.getElementById('chpResultsContainer');
                    resultsContainer.innerHTML = newResults
                        ? newResults.innerHTML
                        : '<div class="error-message">⚠️ שגיאה בטעינת התוצאות</div>';
                })
                .catch(err => {
                    resultsContainer.innerHTML = `<div class="error-message">⚠️ שגיאה בחיפוש: ${err.message}</div>`;
                });
        });
    </script>
</body>
</html>

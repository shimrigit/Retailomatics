<?php
/**
 * Price Change Verification Interface
 * Split-screen view: PC file data (left) + CHP price search (right)
 */

session_start();

// Load required files
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/chp_headless_client.php';
require_once __DIR__ . '/cpd_client.php';   // CPD — the chains' price files; chp.co.il no longer answers the scraper

use PhpOffice\PhpSpreadsheet\IOFactory;

// Get parameters from session or query
$pcFileName = $_GET['pcFile'] ?? $_SESSION['pcFileName'] ?? null;
$shopName = $_GET['shop'] ?? $_SESSION['shopName'] ?? 'CountryMZ';

if (!$pcFileName) {
    die('Error: No PC file specified');
}

// Load shop configuration for default city
$shopsConfig = json_decode(file_get_contents(__DIR__ . '/../configDir/shops_V2.json'), true);
$shopConfig = null;
foreach ($shopsConfig as $shop) {
    if ($shop['ShopName'] === $shopName) {
        $shopConfig = $shop;
        break;
    }
}

$defaultCity = $shopConfig['shopDefaultCity'] ?? 'תל אביב';

// Load PC file data
$pcFilePath = __DIR__ . DIRECTORY_SEPARATOR . 'commercial_invoice_files' . DIRECTORY_SEPARATOR . $pcFileName;
if (!file_exists($pcFilePath)) {
    die('Error: PC file not found: ' . $pcFilePath);
}

// ── JSON recalculate endpoint (called from Handsontable JS) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    if ($rawInput) {
        $jsonInput = json_decode($rawInput, true);
        if ($jsonInput && isset($jsonInput['action']) && $jsonInput['action'] === 'recalculate') {
            header('Content-Type: application/json');
            $changedRows = $jsonInput['changedRows'] ?? [];
            if (empty($changedRows)) {
                echo json_encode(['success' => true, 'modifiedCount' => 0, 'updatedRows' => []]);
                exit;
            }
            $sp2 = IOFactory::load($pcFilePath);
            $sh2 = $sp2->getActiveSheet();
            $hdr = $sh2->rangeToArray('A1:P1')[0];
            $colAUP  = array_search('ActualUnitPrice', $hdr);
            $colSP   = array_search('SalesPrice',      $hdr);
            $colRP   = array_search('Rec Price',        $hdr);
            $colRec  = array_search('Recommend',        $hdr);
            $colRM   = array_search('Rec Mrgn',         $hdr);
            $updatedRows = [];
            foreach ($changedRows as $change) {
                $rowNum = (int)$change['rowNum'];
                if ($rowNum <= 1) continue;
                $newRecMrgnStr = null;
                $newRecommend  = isset($change['recommend']) ? (string)$change['recommend'] : null;

                if (isset($change['recPrice']) && $change['recPrice'] !== null && $change['recPrice'] !== '') {
                    $newRecPrice = (float)$change['recPrice'];
                    $actualUP    = (float)$sh2->getCellByColumnAndRow($colAUP + 1, $rowNum)->getValue();
                    $sh2->setCellValueByColumnAndRow($colRP + 1, $rowNum, $newRecPrice);
                    $priceExVAT    = $newRecPrice / 1.18;
                    $newRecMrgnVal = $priceExVAT > 0 ? (($priceExVAT - $actualUP) / $priceExVAT) * 100 : 0;
                    $newRecMrgnStr = number_format($newRecMrgnVal, 2, '.', '') . '%';
                    $sh2->setCellValueByColumnAndRow($colRM + 1, $rowNum, $newRecMrgnStr);
                    $sh2->getCellByColumnAndRow($colRM + 1, $rowNum)->getStyle()->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFFFD700');
                }

                // Save recommend as-is from the table (user may have manually edited it)
                if ($newRecommend !== null) {
                    $sh2->setCellValueByColumnAndRow($colRec + 1, $rowNum, $newRecommend);
                }
                $updatedRows[] = ['rowNum' => $rowNum, 'recMrgn' => $newRecMrgnStr, 'recommend' => $newRecommend];
            }
            $writer = IOFactory::createWriter($sp2, 'Xlsx');
            $writer->save($pcFilePath);
            echo json_encode(['success' => true, 'modifiedCount' => count($updatedRows), 'updatedRows' => $updatedRows]);
            exit;
        }
    }
}
// ────────────────────────────────────────────────────────────────────────────

$spreadsheet = IOFactory::load($pcFilePath);
$sheet = $spreadsheet->getActiveSheet();
$pcData = $sheet->toArray();

// Track which rows have modified Rec Mrgn (light orange background)
$modifiedRows = [];
// Track which rows have barcode cells with "Not Found" (bold borders)
$notFoundBarcodeRows = [];
$headers = $pcData[0];
$colRecMrgnIdx = array_search('Rec Mrgn', $headers);
$colPriceDiffIdx = array_search('PriceDiff', $headers);

if ($colRecMrgnIdx !== false) {
    for ($i = 2; $i <= count($pcData); $i++) { // Start from row 2 (skip header)
        $cell = $sheet->getCellByColumnAndRow($colRecMrgnIdx + 1, $i);
        $fillColor = $cell->getStyle()->getFill()->getStartColor()->getARGB();

        // Check if cell has light orange background (FFFFD700)
        if ($fillColor === 'FFFFD700') {
            $modifiedRows[] = $i;
        }
    }
}

// Track barcode rows with "Not Found" in PriceDiff column
if ($colPriceDiffIdx !== false) {
    for ($i = 2; $i <= count($pcData); $i++) { // Start from row 2 (skip header)
        $priceDiffCell = $sheet->getCellByColumnAndRow($colPriceDiffIdx + 1, $i);
        $priceDiffValue = $priceDiffCell->getValue();

        // Check if PriceDiff column contains "Not Found"
        if ($priceDiffValue === 'Not Found' || strpos($priceDiffValue, 'Not Found') !== false) {
            $notFoundBarcodeRows[] = $i;
        }
    }
}

// Handle CHP search
$chpResults = null;
$chpError = null;
$searchBarcode = '';
$searchCity = $defaultCity;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chp_search'])) {
    $searchBarcode = trim($_POST['barcode'] ?? '');
    $searchCity = trim($_POST['city'] ?? $defaultCity);

    if (empty($searchBarcode) || empty($searchCity)) {
        $chpError = 'נא למלא ברקוד ועיר';
    } else {
        $client = new CPDClient();   // was CHPHeadlessClient (same searchPrices contract; the city is ignored — CPD is Tel Aviv)

        // Capture output to suppress debug messages
        ob_start();
        $chpResults = $client->searchPrices($searchBarcode, $searchCity);
        ob_end_clean(); // Discard the output

        if (!empty($chpResults['suffixMatches'])) {
            $chpError = 'הסיומת ' . $searchBarcode . ' מתאימה ל-' . count($chpResults['suffixMatches']) . ' מוצרים — בחר את המוצר:';
        } elseif (!$chpResults || empty($chpResults['prices'])) {
            $chpError = 'לא נמצאו תוצאות עבור הברקוד והעיר שהוזנו';
        }
    }
}

// Handle re-calculation of recommended margins
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recalculate'])) {
    $updatedPrices = $_POST['rec_price'] ?? [];
    $originalPrices = $_POST['original_rec_price'] ?? [];

    // Reload spreadsheet to make changes
    $spreadsheet = IOFactory::load($pcFilePath);
    $sheet = $spreadsheet->getActiveSheet();

    // Find column indices
    $headers = $sheet->rangeToArray('A1:P1')[0];
    $colActualUnitPrice = array_search('ActualUnitPrice', $headers); // Column E (index 4)
    $colSalesPrice = array_search('SalesPrice', $headers); // Column I (index 8)
    $colRecPrice = array_search('Rec Price', $headers); // Column N (index 13)
    $colRecommend = array_search('Recommend', $headers); // Column O (index 14)
    $colRecMrgn = array_search('Rec Mrgn', $headers); // Column P (index 15)

    $modifiedCount = 0;

    // Only process rows where the value actually changed
    foreach ($updatedPrices as $rowIndex => $newRecPrice) {
        $rowNum = (int)$rowIndex; // This is the spreadsheet row number (1-based)

        if ($rowNum <= 1) continue; // Skip header row

        $originalPrice = isset($originalPrices[$rowIndex]) ? (float)$originalPrices[$rowIndex] : null;
        $newRecPrice = !empty($newRecPrice) ? (float)$newRecPrice : null;

        // Skip if value didn't change or is empty
        if ($newRecPrice === null || $originalPrice === null || $newRecPrice == $originalPrice) {
            continue;
        }

        // This row was modified - recalculate
        $modifiedCount++;

        // Get ActualUnitPrice (column E) and SalesPrice (column I)
        $actualUnitPrice = (float)$sheet->getCellByColumnAndRow($colActualUnitPrice + 1, $rowNum)->getValue();
        $salesPrice = (float)$sheet->getCellByColumnAndRow($colSalesPrice + 1, $rowNum)->getValue();

        // Update Rec Price (column N)
        $sheet->setCellValueByColumnAndRow($colRecPrice + 1, $rowNum, $newRecPrice);

        // Calculate new Rec Mrgn (column P): ((Y/1.18)-X)/(Y/1.18)
        // where Y = Rec Price, X = ActualUnitPrice
        $priceBeforeVAT = $newRecPrice / 1.18;
        $newRecMrgn = (($priceBeforeVAT - $actualUnitPrice) / $priceBeforeVAT) * 100;
        $sheet->setCellValueByColumnAndRow($colRecMrgn + 1, $rowNum, number_format($newRecMrgn, 2, '.', '') . '%');

        // Set background color to light orange for modified Rec Mrgn cells
        $recMrgnCell = $sheet->getCellByColumnAndRow($colRecMrgn + 1, $rowNum);
        $recMrgnCell->getStyle()->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFFFD700'); // Light orange

        // Update Recommend (column O) based on comparison with SalesPrice
        $recommend = '';
        if ($salesPrice == $newRecPrice) {
            $recommend = 'NO';
        } elseif ($salesPrice > $newRecPrice) {
            $recommend = 'YES. decrease';
        } else { // $salesPrice < $newRecPrice
            $recommend = 'YES. increase';
        }
        $sheet->setCellValueByColumnAndRow($colRecommend + 1, $rowNum, $recommend);
    }

    // Save the updated spreadsheet only if changes were made
    if ($modifiedCount > 0) {
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($pcFilePath);

        // Reload the data for display
        $pcData = $sheet->toArray();

        // Reload modified rows tracking
        $modifiedRows = [];
        if ($colRecMrgnIdx !== false) {
            for ($i = 2; $i <= count($pcData); $i++) {
                $cell = $sheet->getCellByColumnAndRow($colRecMrgnIdx + 1, $i);
                $fillColor = $cell->getStyle()->getFill()->getStartColor()->getARGB();
                if ($fillColor === 'FFFFD700') {
                    $modifiedRows[] = $i;
                }
            }
        }

        // Set success message
        $recalcSuccess = $modifiedCount;
    } else {
        $recalcSuccess = 0; // No changes made
    }
}

// Handle finalization (continue to New Product Process)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize'])) {
    // Extract CL filename from session
    $clFileName = $_SESSION['clFileName'] ?? null;

    if (!$clFileName) {
        die('Error: CL file name not found in session');
    }

    // Redirect with flag to trigger NP generation via JavaScript
    $_SESSION['continue_to_np'] = true;
    header('Location: verify_price_changes.php?pcFile=' . urlencode($pcFileName) . '&shop=' . urlencode($shopName) . '&continueToNP=1');
    exit;
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>אימות שינויי מחירים - Commercial Layer</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/handsontable@12.3.1/dist/handsontable.full.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            height: 100vh;
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
            direction: ltr; /* Force LTR for header */
        }

        .header h1 {
            font-size: 1.8em;
            margin: 0;
            direction: ltr;
            text-align: left;
        }

        .header .file-info {
            font-size: 0.9em;
            opacity: 0.9;
            direction: ltr;
            text-align: left;
        }

        .finalize-button {
            padding: 10px 25px;
            font-size: 1.1em;
            font-weight: bold;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .finalize-button:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
        }

        .split-container {
            display: flex;
            height: calc(100vh - 70px);
            gap: 10px;
            padding: 10px;
        }

        .left-panel {
            width: 500px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .right-panel {
            flex: 1;
            min-width: 0;
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        #hotContainer {
            overflow: hidden;
            position: relative;
            direction: ltr;
        }

        .panel-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            font-size: 1.3em;
            font-weight: bold;
        }

        .panel-content {
            flex: 1;
            overflow: auto;
            padding: 15px;
        }

        /* Bold border for barcodes with "Not Found" in PriceDiff */
        .not-found-barcode {
            border: 3px solid #000 !important;
        }

        .copied-notification {
            position: fixed;
            top: 80px;
            right: 20px;
            background: #28a745;
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            z-index: 10000;
            animation: slideIn 0.3s, slideOut 0.3s 2.7s;
            opacity: 0;
        }

        @keyframes slideIn {
            from { transform: translateX(400px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(400px); opacity: 0; }
        }

        .copied-notification.show {
            opacity: 1;
        }

        /* CHP Search Form Styles */
        .chp-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 1em;
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
        }

        .form-group input {
            padding: 12px;
            font-size: 1em;
            border: 2px solid #ddd;
            border-radius: 6px;
            transition: border-color 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-group input:read-only {
            background: #f8f9fa;
        }

        .search-button {
            padding: 12px 20px;
            font-size: 1.1em;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .search-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .search-button:active {
            transform: translateY(0);
        }

        .error-message {
            background: #fee;
            border: 2px solid #fcc;
            border-radius: 6px;
            padding: 12px;
            color: #c33;
            text-align: center;
            margin-bottom: 15px;
        }

        /* CHP Results Styles */
        .chp-results {
            margin-top: 20px;
        }

        .chp-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }

        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
        }

        [data-copy-price] { cursor: copy; }
        [data-copy-price]:hover { outline: 2px solid #ffb300; outline-offset: 1px; }
        .stat-box .value {
            font-size: 1.4em;
            font-weight: bold;
            display: block;
        }

        .stat-box .label {
            font-size: 0.85em;
            opacity: 0.9;
        }

        .chp-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85em;
        }

        .chp-table thead {
            background: #f8f9fa;
        }

        .chp-table th {
            padding: 8px;
            text-align: right;
            font-weight: bold;
            border-bottom: 2px solid #667eea;
        }

        .chp-table tbody tr {
            border-bottom: 1px solid #e0e0e0;
        }

        .chp-table tbody tr:hover {
            background-color: #f5f5f5;
        }

        .chp-table td {
            padding: 8px;
            text-align: right;
        }

        .best-price-row {
            background-color: #d4edda !important;
            border-right: 4px solid #28a745;
        }

        .price-cell {
            font-weight: bold;
            color: #27ae60;
            font-size: 1.1em;
        }

        .recommendation-yes {
            color: #28a745;
            font-weight: bold;
        }

        .recommendation-no {
            color: #dc3545;
        }

        /* Recalculate button */
        .recalculate-button {
            padding: 10px 25px;
            font-size: 1.1em;
            font-weight: bold;
            background: #ff9800;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            margin-right: 15px;
        }

        .recalculate-button:hover {
            background: #f57c00;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(255, 152, 0, 0.4);
        }

        /* Success message */
        .success-message {
            background: #d4edda;
            border: 2px solid #c3e6cb;
            border-radius: 8px;
            padding: 12px 20px;
            color: #155724;
            text-align: center;
            margin: 10px 0;
            font-weight: bold;
        }

        /* Gold highlight for calculated output cells */
        .htGoldCell {
            background-color: #FFD700 !important;
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>🔍 Verify Price Changes</h1>
            <div class="file-info">File: <?= htmlspecialchars($pcFileName) ?> | Shop: <?= htmlspecialchars($shopName) ?></div>
            <?php if (!empty($_SESSION['harmonizedFlow']['priceListLabel'])): ?><div class="file-info"><?= htmlspecialchars($_SESSION['harmonizedFlow']['priceListLabel']) ?></div><?php endif; ?>
        </div>
        <div>
            <button type="button" class="recalculate-button" id="recalculateBtn">
                🔄 Re-calculate Recommended Margin
            </button>
            <form id="finalizeForm" method="POST" style="display: inline;">
                <input type="hidden" name="finalize" value="1">
                <button type="button" class="finalize-button" onclick="continueToNP()">
                    ➡️ Continue to New Product Process
                </button>
            </form>
        </div>
    </div>

    <div id="recalcMessage"></div>

    <div class="split-container">
        <!-- LEFT PANEL: CHP Search -->
        <div class="left-panel">
            <div class="panel-header">
                🔎 חיפוש מחירים בשוק
            </div>
            <div class="panel-content">
                <form method="POST" class="chp-form" id="chpSearchForm">
                    <div class="form-group">
                        <label for="city">עיר:</label>
                        <input
                            type="text"
                            id="city"
                            name="city"
                            value="<?= htmlspecialchars($searchCity) ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="barcode">ברקוד מוצר:</label>
                        <input
                            type="text"
                            id="barcode"
                            name="barcode"
                            placeholder="לחץ על ברקוד מהטבלה להעתקה"
                            value="<?= htmlspecialchars($searchBarcode) ?>"
                            required
                        >
                    </div>

                    <button type="submit" name="chp_search" class="search-button">
                        🔎 חפש במאגר המחירים
                    </button>
                </form>

                <div id="chpResultsContainer">

                <?php if ($chpError): ?>
                    <div class="error-message">
                        ⚠️ <?= htmlspecialchars($chpError) ?>
                    </div>
                    <?php if (!empty($chpResults['suffixMatches'])): ?>
                        <div class="suffix-list" style="margin:8px 0;font-size:14px">
                            <?php foreach ($chpResults['suffixMatches'] as $sm): ?>
                                <div><a href="#" class="suffix-pick" data-full-barcode="<?= htmlspecialchars($sm['barcode']) ?>" style="direction:ltr;display:inline-block"><?= htmlspecialchars($sm['barcode']) ?></a> — <?= htmlspecialchars($sm['name']) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($chpResults && !empty($chpResults['prices'])): ?>
                    <?php if (!empty($chpResults['suffixOf'])): ?>
                        <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:6px;padding:6px 10px;margin-bottom:8px;font-size:14px">נמצא לפי סיומת: <b dir="ltr"><?= htmlspecialchars($chpResults['suffixOf']) ?></b> ← ברקוד מלא <b dir="ltr"><?= htmlspecialchars($chpResults['barcode']) ?></b></div>
                    <?php endif; ?>
                    <div class="chp-results">
                        <?php
                        // Calculate statistics
                        $prices = array_filter(
                            array_map(function($item) {
                                return is_numeric($item['price']) ? (float)$item['price'] : null;
                            }, $chpResults['prices'])
                        );

                        $minPrice = !empty($prices) ? min($prices) : 0;
                        $maxPrice = !empty($prices) ? max($prices) : 0;
                        $avgPrice = !empty($prices) ? array_sum($prices) / count($prices) : 0;
                        ?>

                        <div class="chp-stats">
                            <div class="stat-box" data-copy-price="<?= round($minPrice, 2) ?>" title="לחיצה מעתיקה ל-Rec Price בשורה שנבחרה">
                                <span class="value"><?= number_format($minPrice, 2) ?> ₪</span>
                                <span class="label">מחיר מינימום</span>
                            </div>
                            <div class="stat-box" data-copy-price="<?= round($avgPrice, 2) ?>" title="לחיצה מעתיקה ל-Rec Price בשורה שנבחרה">
                                <span class="value"><?= number_format($avgPrice, 2) ?> ₪</span>
                                <span class="label">מחיר ממוצע</span>
                            </div>
                            <div class="stat-box" data-copy-price="<?= round($maxPrice, 2) ?>" title="לחיצה מעתיקה ל-Rec Price בשורה שנבחרה">
                                <span class="value"><?= number_format($maxPrice, 2) ?> ₪</span>
                                <span class="label">מחיר מקסימום</span>
                            </div>
                            <div class="stat-box">
                                <span class="value"><?= count($chpResults['prices']) ?></span>
                                <span class="label">חנויות</span>
                            </div>
                        </div>

                        <?php if (!empty($chpResults['productName'])): ?>
                            <div style="background: #f8f9fa; padding: 10px; border-radius: 5px; margin-bottom: 10px;">
                                <strong>שם המוצר:</strong> <?= htmlspecialchars($chpResults['productName']) ?>
                            </div>
                        <?php endif; ?>

                        <table class="chp-table">
                            <thead>
                                <tr>
                                    <th>רשת</th>
                                    <th>חנות</th>
                                    <th>מחיר</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($chpResults['prices'] as $item): ?>
                                    <?php
                                    $isBestPrice = is_numeric($item['price']) && (float)$item['price'] == $minPrice;
                                    ?>
                                    <tr class="<?= $isBestPrice ? 'best-price-row' : '' ?>">
                                        <td><?= htmlspecialchars($item['chain'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($item['store'] ?? '') ?></td>
                                        <td class="price-cell"<?= is_numeric($item['price']) ? ' data-copy-price="' . round((float) $item['price'], 2) . '" title="לחיצה מעתיקה ל-Rec Price בשורה שנבחרה"' : '' ?>>
                                            <?php if (is_numeric($item['price'])): ?>
                                                <?= number_format((float)$item['price'], 2) ?> ₪
                                            <?php else: ?>
                                                <?= htmlspecialchars($item['price']) ?>
                                            <?php endif; ?>
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

        <!-- RIGHT PANEL: PC File Data -->
        <div class="right-panel">
            <div class="panel-header">
                📊 נתוני שינוי מחירים
            </div>

            <div id="hotContainer"></div>
        </div>
    </div>

    <!-- Copied Notification -->
    <div id="copiedNotification" class="copied-notification">
        ✓ ברקוד הועתק בהצלחה!
    </div>

    <script src="https://cdn.jsdelivr.net/npm/handsontable@12.3.1/dist/handsontable.full.min.js"></script>
    <script>
        // ── Data from PHP ───────────────────────────────────────────────────
        const pcHeaders    = <?php echo json_encode(array_slice($pcData[0], 2)); ?>;
        const pcTableData  = <?php echo json_encode(array_values(array_map(function($r){ return array_slice($r, 2); }, array_slice($pcData, 1)))); ?>;
        // Convert 1-based spreadsheet row numbers to 0-based data indices
        const modifiedRowSet   = new Set(<?php echo json_encode(array_values(array_map(function($r){ return $r - 2; }, $modifiedRows))); ?>);
        const notFoundRowSet   = new Set(<?php echo json_encode(array_values(array_map(function($r){ return $r - 2; }, $notFoundBarcodeRows))); ?>);

        const barcodeColIdx    = pcHeaders.indexOf('Barcode');
        const recPriceColIdx   = pcHeaders.indexOf('Rec Price');
        const recMrgnColIdx    = pcHeaders.indexOf('Rec Mrgn');
        const recommendColIdx  = pcHeaders.indexOf('Recommend');
        const actualUPColIdx   = pcHeaders.indexOf('ActualUnitPrice');
        const salesPriceColIdx = pcHeaders.indexOf('SalesPrice');

        // Gold-highlight tracking: "row,col" → true (marks the OUTPUT cell of a calculation)
        const highlightedCells = new Map();
        // Pre-populate from rows that were already saved as modified in the Excel file
        modifiedRowSet.forEach(row => {
            if (recMrgnColIdx >= 0) highlightedCells.set(`${row},${recMrgnColIdx}`, true);
        });

        // Rows the user has edited during this session (either field)
        const dirtyRows = new Set();
        let activeRow = -1;   // the last row selected in the table — where a click in the price search copies to

        // ── Custom renderers ────────────────────────────────────────────────
        function barcodeRenderer(hot, TD, row, col, prop, value, cp) {
            Handsontable.renderers.TextRenderer.apply(this, arguments);
            TD.style.color          = '#667eea';
            TD.style.cursor         = 'pointer';
            TD.style.fontWeight     = 'bold';
            TD.style.textDecoration = 'underline';
            if (notFoundRowSet.has(row)) {
                TD.style.border = '3px solid #000';
            }
        }

        function recMrgnRenderer(hot, TD, row, col, prop, value, cp) {
            Handsontable.renderers.TextRenderer.apply(this, arguments);
            // Gold background handled by cells() callback via highlightedCells
        }

        function recommendRenderer(hot, TD, row, col, prop, value, cp) {
            Handsontable.renderers.TextRenderer.apply(this, arguments);
            if (typeof value === 'string' && value.startsWith('YES')) {
                TD.style.color      = '#28a745';
                TD.style.fontWeight = 'bold';
            } else if (value === 'NO') {
                TD.style.color = '#dc3545';
            }
        }

        // ── Column config ───────────────────────────────────────────────────
        const columns = pcHeaders.map((header, idx) => {
            const col = { readOnly: idx !== recPriceColIdx && idx !== recMrgnColIdx && idx !== recommendColIdx };
            if (idx === recPriceColIdx) {
                col.type = 'numeric';
                col.numericFormat = { pattern: '0.00' };
                col.className = 'htLeft';
            }
            if (idx === barcodeColIdx)   col.renderer = barcodeRenderer;
            if (idx === recMrgnColIdx)   col.renderer = recMrgnRenderer;
            if (idx === recommendColIdx) col.renderer = recommendRenderer;
            return col;
        });

        // ── Handsontable init ───────────────────────────────────────────────
        function hotHeight() {
            const top = document.getElementById('hotContainer').getBoundingClientRect().top;
            return Math.floor(window.innerHeight - top - 12); // 12px margin keeps scrollbar clear of viewport edge
        }

        function updateRecommend(row, recPrice) {
            if (recommendColIdx < 0 || salesPriceColIdx < 0) return;
            const salesPrice = parseFloat(hot.getDataAtCell(row, salesPriceColIdx)) || 0;
            let recommend;
            if (Math.abs(salesPrice - recPrice) < 0.001) recommend = 'NO';
            else if (salesPrice > recPrice)              recommend = 'YES. decrease';
            else                                         recommend = 'YES. increase';
            hot.setDataAtCell(row, recommendColIdx, recommend, 'calculated');
        }

        const hot = new Handsontable(document.getElementById('hotContainer'), {
            data:               pcTableData,
            colHeaders:         pcHeaders,
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
                if (col === recPriceColIdx || col === recMrgnColIdx || col === recommendColIdx) {
                    TH.style.backgroundColor = '#FFFF00';
                    TH.style.color = '#000';
                }
            },
            cells(row, col) {
                if (highlightedCells.has(`${row},${col}`)) {
                    return { className: 'htGoldCell' };
                }
                return {};
            },
            afterChange(changes, source) {
                // 'calculated' = our own bidirectional update; 'external' = server response applied to table
                if (!changes || source === 'calculated' || source === 'external') return;
                changes.forEach(([row, col, oldVal, newVal]) => {
                    if (col === recPriceColIdx) {
                        const actualUP = parseFloat(hot.getDataAtCell(row, actualUPColIdx)) || 0;
                        const recPrice = parseFloat(newVal) || 0;
                        if (recPrice > 0 && actualUP > 0) {
                            const priceExVAT = recPrice / 1.18;
                            const recMrgn = ((priceExVAT - actualUP) / priceExVAT) * 100;
                            hot.setDataAtCell(row, recMrgnColIdx, recMrgn.toFixed(2) + '%', 'calculated');
                            highlightedCells.set(`${row},${recMrgnColIdx}`, true);
                            highlightedCells.delete(`${row},${recPriceColIdx}`);
                            updateRecommend(row, recPrice);
                        }
                        dirtyRows.add(row);
                    } else if (col === recMrgnColIdx) {
                        const actualUP = parseFloat(hot.getDataAtCell(row, actualUPColIdx)) || 0;
                        const rawVal   = String(newVal || '').replace('%', '').trim();
                        const recMrgnVal = parseFloat(rawVal);
                        if (!isNaN(recMrgnVal) && recMrgnVal >= 0 && recMrgnVal < 100 && actualUP > 0) {
                            const recPrice = parseFloat(((actualUP / (1 - recMrgnVal / 100)) * 1.18).toFixed(2));
                            hot.setDataAtCell(row, recPriceColIdx, recPrice, 'calculated');
                            highlightedCells.set(`${row},${recPriceColIdx}`, true);
                            highlightedCells.delete(`${row},${recMrgnColIdx}`);
                            updateRecommend(row, recPrice);
                        }
                        dirtyRows.add(row);
                    } else if (col === recommendColIdx) {
                        dirtyRows.add(row);
                    }
                });
            },
            afterSelectionEnd(row) { if (row >= 0) activeRow = row; },
            afterOnCellMouseDown(event, coords) {
                if (coords.col === barcodeColIdx && coords.row >= 0) {
                    const barcode = String(hot.getDataAtCell(coords.row, barcodeColIdx) ?? '');
                    if (!barcode) return;
                    navigator.clipboard.writeText(barcode)
                        .catch(() => {})
                        .finally(() => {
                            document.getElementById('barcode').value = barcode;
                            const n = document.getElementById('copiedNotification');
                            n.textContent = '✓ ברקוד הועתק בהצלחה!';
                            n.classList.add('show');
                            setTimeout(() => n.classList.remove('show'), 3000);
                        });
                }
            }
        });

        window.addEventListener('resize', () => hot.updateSettings({ height: hotHeight() }));

        // ── A click on a price in the search (a store's, the minimum, average or maximum) copies it
        // into Rec Price of the selected row — as if typed: Rec Mrgn and Recommend follow, the row is saved.
        function notify(text) {
            const n = document.getElementById('copiedNotification');
            n.textContent = text;
            n.classList.add('show');
            setTimeout(() => n.classList.remove('show'), 2500);
        }
        document.getElementById('chpResultsContainer').addEventListener('click', e => {
            const el = e.target.closest('[data-copy-price]');
            if (!el || recPriceColIdx < 0) return;
            if (activeRow < 0 || activeRow >= hot.countRows()) { notify('בחרו קודם שורה בטבלה'); return; }
            hot.setDataAtCell(activeRow, recPriceColIdx, parseFloat(el.dataset.copyPrice));
            hot.selectCell(activeRow, recPriceColIdx);
            notify('✓ הועתק לשורה ' + (activeRow + 1) + ' — Rec Price ' + el.dataset.copyPrice);
        });

        // ── CHP search (AJAX, unchanged logic) ─────────────────────────────
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

        // ── Save dirty rows to Excel (shared by button and auto-save) ──────────
        function doSave(silent = false) {
            const changedRows = [];
            dirtyRows.forEach(idx => {
                const recPrice  = recPriceColIdx  >= 0 ? hot.getDataAtCell(idx, recPriceColIdx)  : null;
                const recommend = recommendColIdx >= 0 ? hot.getDataAtCell(idx, recommendColIdx) : null;
                changedRows.push({
                    rowNum:    idx + 2,
                    recPrice:  (recPrice !== null && recPrice !== '') ? parseFloat(recPrice) : null,
                    recommend: recommend
                });
            });

            if (changedRows.length === 0) return Promise.resolve(true);

            const msgDiv = document.getElementById('recalcMessage');
            if (!silent) {
                msgDiv.innerHTML = '<div style="background:#e3f2fd;border:2px solid #2196F3;padding:10px 20px;margin:5px 15px;border-radius:8px;text-align:center;">🔄 Saving...</div>';
            }

            return fetch(window.location.href, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ action: 'recalculate', changedRows })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    data.updatedRows.forEach(upd => {
                        const rowIdx = upd.rowNum - 2;
                        if (upd.recMrgn   && recMrgnColIdx  >= 0) hot.setDataAtCell(rowIdx, recMrgnColIdx,   upd.recMrgn,   'external');
                        if (upd.recommend && recommendColIdx >= 0) hot.setDataAtCell(rowIdx, recommendColIdx, upd.recommend,  'external');
                        modifiedRowSet.add(rowIdx);
                    });
                    dirtyRows.clear();
                    hot.render();
                    if (!silent) {
                        msgDiv.innerHTML = `<div style="background:#d4edda;border:2px solid #28a745;padding:10px 20px;margin:5px 15px;border-radius:8px;text-align:center;color:#155724;">✓ ${data.modifiedCount} row(s) saved successfully</div>`;
                        setTimeout(() => msgDiv.innerHTML = '', 3000);
                    }
                    return true;
                } else {
                    if (!silent) { alert('Error: ' + data.error); msgDiv.innerHTML = ''; }
                    return false;
                }
            })
            .catch(err => {
                if (!silent) { alert('Error: ' + err); msgDiv.innerHTML = ''; }
                return false;
            });
        }

        // ── Recalculate button ──────────────────────────────────────────────
        document.getElementById('recalculateBtn').addEventListener('click', function() {
            if (dirtyRows.size === 0) { alert('No unsaved changes detected'); return; }
            const btn = this;
            btn.disabled    = true;
            btn.textContent = '⏳ Saving...';
            doSave().finally(() => {
                btn.disabled    = false;
                btn.textContent = '🔄 Re-calculate Recommended Margin';
            });
        });

        // ── Continue to NP — auto-save dirty rows first ─────────────────────
        function continueToNP() {
            if (!confirm('Continue to New Product process?')) return;
            const btn = document.querySelector('#finalizeForm button');
            if (dirtyRows.size > 0) {
                btn.disabled    = true;
                btn.textContent = '⏳ Saving...';
                doSave(true).then(ok => {
                    if (ok) {
                        document.getElementById('finalizeForm').submit();
                    } else {
                        btn.disabled    = false;
                        btn.textContent = '➡️ Continue to New Product Process';
                        alert('Save failed — please retry before continuing.');
                    }
                });
            } else {
                document.getElementById('finalizeForm').submit();
            }
        }

        // ── Continue to NP process ──────────────────────────────────────────
        <?php if (isset($_GET['continueToNP']) && $_GET['continueToNP'] == '1'): ?>
        window.addEventListener('DOMContentLoaded', function() {
            const clFileName = '<?= $_SESSION['clFileName'] ?? '' ?>';
            const shopName   = '<?= $shopName ?>';
            if (!clFileName) { alert('Error: CL file name not found'); return; }
            fetch('generate_np_file.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ clFileName, shopName })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (data.hasNewProducts) {
                        alert('✅ New Products file created!\n\nProducts found: ' + data.newProductCount + '\nSupplier: ' + data.supplierHebrewName + '\n\nRedirecting to New Product verification...');
                        window.location.href = data.redirectUrl;
                    } else {
                        alert('ℹ️ ' + data.message);
                        window.location.href = 'price_change_complete.php?pcFile=' + encodeURIComponent('<?= $pcFileName ?>') + '&shop=' + encodeURIComponent('<?= $shopName ?>') + '&cl=' + encodeURIComponent(clFileName) + '&noNP=true';
                    }
                } else {
                    alert('❌ Error: ' + data.error);
                }
            })
            .catch(err => alert('❌ Error generating NP file: ' + err));
        });
        <?php endif; ?>

        // Several barcodes end with the digits typed: a click searches the chosen full barcode.
        document.getElementById('chpResultsContainer').addEventListener('click', e => {
            const a = e.target.closest('.suffix-pick');
            if (!a) return;
            e.preventDefault();
            document.getElementById('barcode').value = a.dataset.fullBarcode;
            document.getElementById('chpSearchForm').requestSubmit();
        });
    </script>
</body>
</html>

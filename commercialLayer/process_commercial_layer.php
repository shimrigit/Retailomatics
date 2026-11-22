<?php
// Commercial Layer Processing
// Purpose: Add commercial parameters from shop price list and recognize price changes/new products

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get shop name
    $shopName = $_POST['shopName'] ?? '';
    $fileOption = $_POST['fileOption'] ?? 'manual';

    if (empty($shopName)) {
        die("Error: Please select a shop name");
    }

    // Load shops configuration
    $shopsFile = __DIR__ . '/../configDir/shops_V2.json';
    if (!file_exists($shopsFile)) {
        die("Error: shops_V2.json configuration file not found");
    }

    $shops = json_decode(file_get_contents($shopsFile), true);
    $shopConfig = null;

    foreach ($shops as $shop) {
        if ($shop['ShopName'] === $shopName) {
            $shopConfig = $shop;
            break;
        }
    }

    if ($shopConfig === null) {
        die("Error: Shop '{$shopName}' not found in configuration");
    }

    // Get file paths based on selected option
    $ocrSanityPath = '';
    $priceListPath = '';
    $invoicePdfPath = '';

    if ($fileOption === 'manual') {
        // Option 1: Manual file upload
        if (!isset($_FILES['ocrSanity']) || !isset($_FILES['priceList']) || !isset($_FILES['invoice'])) {
            die("Error: Please upload all three required files");
        }

        $ocrSanityPath = $_FILES['ocrSanity']['tmp_name'];
        $priceListPath = $_FILES['priceList']['tmp_name'];
        $invoicePdfPath = $_FILES['invoice']['tmp_name'];

        $ocrSanityOriginalName = $_FILES['ocrSanity']['name'];
        $invoicePdfOriginalName = $_FILES['invoice']['name'];
    } else {
        // Option 2: Test configuration file
        $testConfigFile = __DIR__ . '/../configDir/test_config.json';
        if (!file_exists($testConfigFile)) {
            die("Error: test_config.json configuration file not found");
        }

        $testConfig = json_decode(file_get_contents($testConfigFile), true);

        // Find CommercialLayer test config
        $clTestConfig = null;
        foreach ($testConfig as $test) {
            if ($test['testName'] === 'CommercialLayer') {
                $clTestConfig = $test;
                break;
            }
        }

        if ($clTestConfig === null) {
            die("Error: CommercialLayer test configuration not found in test_config.json");
        }

        $ocrSanityPath = $clTestConfig['OCRsanity'];
        $priceListPath = $clTestConfig['PriceList'];
        $invoicePdfPath = $clTestConfig['Invoice'];

        if (!file_exists($ocrSanityPath) || !file_exists($priceListPath) || !file_exists($invoicePdfPath)) {
            die("Error: One or more test files not found at configured paths");
        }

        $ocrSanityOriginalName = basename($ocrSanityPath);
        $invoicePdfOriginalName = basename($invoicePdfPath);
    }

    // Load OCR Sanity Excel file
    try {
        $spreadsheet = IOFactory::load($ocrSanityPath);
        $sheet = $spreadsheet->getActiveSheet();
    } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
        die("❌ <strong>Error: Unable to read OCR Sanity file</strong><br><br>" .
            "📄 <strong>File:</strong> " . htmlspecialchars(basename($ocrSanityPath)) . "<br><br>" .
            "⚠️ <strong>Possible causes:</strong><br>" .
            "• The file is currently open in Excel or another program<br>" .
            "• The file is corrupted or not a valid Excel file<br>" .
            "• The file is locked by another process<br><br>" .
            "💡 <strong>Solution:</strong> Please close the file in Excel and try again.<br><br>" .
            "🔧 <strong>Technical details:</strong> " . htmlspecialchars($e->getMessage()));
    }

    // Generate CL filename with suffix: _CL_ddmmyyyy_hhmmss
    $timestamp = date('dmY_His'); // ddmmyyyy_hhmmss
    $ocrSanityBaseName = pathinfo($ocrSanityOriginalName, PATHINFO_FILENAME);
    $clFileName = "{$ocrSanityBaseName}_CL_{$timestamp}.xlsx";

    // Add new column headers (row 1)
    $sheet->setCellValue('L1', 'OriginalUnitPrice');
    $sheet->setCellValue('M1', 'ItemERPName');
    $sheet->setCellValue('N1', 'Department');
    $sheet->setCellValue('O1', 'SalesPrice');
    $sheet->setCellValue('P1', 'PriceDiff');

    // Make headers bold
    $sheet->getStyle('L1:P1')->getFont()->setBold(true);

    // Load Price List Excel file
    try {
        $priceListSpreadsheet = IOFactory::load($priceListPath);
        $priceListSheet = $priceListSpreadsheet->getActiveSheet();
    } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
        die("❌ <strong>Error: Unable to read Price List file</strong><br><br>" .
            "📄 <strong>File:</strong> " . htmlspecialchars(basename($priceListPath)) . "<br><br>" .
            "⚠️ <strong>Possible causes:</strong><br>" .
            "• The file is currently open in Excel or another program<br>" .
            "• The file is corrupted or not a valid Excel file<br>" .
            "• The file is locked by another process<br><br>" .
            "💡 <strong>Solution:</strong> Please close the file in Excel and try again.<br><br>" .
            "🔧 <strong>Technical details:</strong> " . htmlspecialchars($e->getMessage()));
    }

    // The columns: by the header row (the Gateway's export and the old files differ), else the
    // shop's PriceListColumns — commercialLayer/price_list_source.php
    require_once __DIR__ . '/price_list_source.php';
    try {
        $priceListColumns = clPriceListColumns($priceListSheet, $shopConfig['PriceListColumns']);
    } catch (RuntimeException $e) {
        die('❌ <strong>' . htmlspecialchars($e->getMessage()) . '</strong><br>📄 ' . htmlspecialchars(basename($priceListPath)));
    }
    $plItemERPNameCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($priceListColumns['ItemERPName']);
    $plBarcodeCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($priceListColumns['Barcode']);
    $plDepartmentCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($priceListColumns['Department']);
    $plCostPriceCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($priceListColumns['CostPrice']);
    $plSalesPriceCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($priceListColumns['SalesPrice']);

    // Get highest row in both sheets
    $clHighestRow = $sheet->getHighestRow();
    $plHighestRow = $priceListSheet->getHighestRow();

    // Get ReplaceBarcodeToERP setting from shop config (default to NO)
    $replaceBarcodeToERP = isset($shopConfig['ReplaceBarcodeToERP']) && strtoupper($shopConfig['ReplaceBarcodeToERP']) === 'YES';

    // Iterate through CL file rows (starting from row 2)
    for ($n = 2; $n <= $clHighestRow; $n++) {
        // Get invoice barcode (IB) from column D
        $ibOriginal = $sheet->getCell("D{$n}")->getValue();

        // Skip empty rows
        if ($ibOriginal === null || $ibOriginal === '') {
            continue;
        }

        // Convert to string and remove any non-numeric characters for matching
        $ib = (string)$ibOriginal;
        $ib = preg_replace('/[^0-9]/', '', $ib);

        $matchFound = false;
        $matchRow = null;
        $matchType = null; // 'A' or 'B'
        $matchedERPBarcode = null; // Store the matched ERP barcode

        // Search for matching barcode in Price List
        for ($m = 2; $m <= $plHighestRow; $m++) {
            $plbOriginal = $priceListSheet->getCell("{$plBarcodeCol}{$m}")->getValue();

            // Skip empty barcodes
            if ($plbOriginal === null || $plbOriginal === '') {
                continue;
            }

            // Convert to string and remove any non-numeric characters for matching
            $plb = (string)$plbOriginal;
            $plb = preg_replace('/[^0-9]/', '', $plb);

            // Matching logic
            $ibLen = strlen($ib);
            $plbLen = strlen($plb);

            // Case 1: Same length - exact match
            if ($ibLen === $plbLen) {
                if ($ib === $plb) {
                    $matchFound = true;
                    $matchRow = $m;
                    $matchedERPBarcode = $plb; // Store the normalized ERP barcode
                    break;
                }
            }
            // Case 2: Different length, both longer than 6 - suffix match
            elseif ($ibLen > 6 && $plbLen > 6) {
                if (strlen($ib) < strlen($plb)) {
                    // IB is shorter - check if IB is suffix of PLB
                    if (substr($plb, -$ibLen) === $ib) {
                        $matchFound = true;
                        $matchRow = $m;
                        $matchedERPBarcode = $plb; // Store the normalized ERP barcode
                        break;
                    }
                } else {
                    // PLB is shorter - check if PLB is suffix of IB
                    if (substr($ib, -$plbLen) === $plb) {
                        $matchFound = true;
                        $matchRow = $m;
                        $matchedERPBarcode = $plb; // Store the normalized ERP barcode
                        break;
                    }
                }
            }
            // Case 3: IB shorter than 6 - pad with zeros and check suffix
            elseif ($ibLen < 6) {
                $ibPadded = str_pad($ib, 6, '0', STR_PAD_LEFT);
                if (strlen($ibPadded) <= strlen($plb)) {
                    if (substr($plb, -6) === $ibPadded) {
                        $matchFound = true;
                        $matchRow = $m;
                        $matchedERPBarcode = $plb; // Store the normalized ERP barcode
                        break;
                    }
                }
            }
        }

        if ($matchFound) {
            // Match found - copy data from Price List row $matchRow to CL row $n

            // Check if we should replace the invoice barcode with ERP barcode
            if ($replaceBarcodeToERP && $ib !== $matchedERPBarcode) {
                // Barcodes are different - replace with ERP barcode and highlight
                $sheet->setCellValueExplicit("D{$n}", $matchedERPBarcode, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                // Apply light brown background color (tan/wheat color)
                $sheet->getStyle("D{$n}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFD2B48C'); // Light brown/tan color
            }

            // Copy ItemERPName (PL column B -> CL column M)
            $itemERPName = $priceListSheet->getCell("{$plItemERPNameCol}{$matchRow}")->getValue();
            $sheet->setCellValue("M{$n}", $itemERPName);

            // Copy Department (PL column E -> CL column N)
            $department = $priceListSheet->getCell("{$plDepartmentCol}{$matchRow}")->getValue();
            $sheet->setCellValue("N{$n}", $department);

            // Copy CostPrice (PL column I -> CL column L - OriginalUnitPrice)
            $costPrice = $priceListSheet->getCell("{$plCostPriceCol}{$matchRow}")->getValue();
            $sheet->setCellValue("L{$n}", $costPrice);
            $sheet->getStyle("L{$n}")->getNumberFormat()->setFormatCode('#,##0.00');

            // Copy SalesPrice (PL column G -> CL column O)
            $salesPrice = $priceListSheet->getCell("{$plSalesPriceCol}{$matchRow}")->getValue();
            $sheet->setCellValue("O{$n}", $salesPrice);
            $sheet->getStyle("O{$n}")->getNumberFormat()->setFormatCode('#,##0.00');

            // Calculate price difference percentage: (K / L) - 1
            $actualUnitPrice = $sheet->getCell("K{$n}")->getValue();
            $originalUnitPrice = $costPrice;

            // Check if cost price is missing or empty
            if (empty($originalUnitPrice) || !is_numeric($originalUnitPrice) || $originalUnitPrice == 0) {
                // Barcode found but no cost in price list
                $sheet->setCellValue("P{$n}", "No Cost in PL");
                $sheet->getStyle("P{$n}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFFA500'); // Orange background
            } elseif (is_numeric($actualUnitPrice) && is_numeric($originalUnitPrice)) {
                $priceDiff = (((float)$actualUnitPrice / (float)$originalUnitPrice) - 1) * 100;
                $priceDiff = round($priceDiff, 2);

                $sheet->setCellValue("P{$n}", $priceDiff);
                $sheet->getStyle("P{$n}")->getNumberFormat()->setFormatCode('0.00"%"');

                // Apply conditional formatting
                if ($priceDiff > 0) {
                    // Light red background
                    $sheet->getStyle("P{$n}")->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFFFCCCC'); // Light red
                } elseif ($priceDiff < 0) {
                    // Light green background
                    $sheet->getStyle("P{$n}")->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFCCFFCC'); // Light green
                }
            }
        } else {
            // No match found - write "Not Found" in black text (default)
            $sheet->setCellValue("P{$n}", "Not Found");

            // Add bold borders to the barcode cell (column D) to highlight for manual correction
            $sheet->getCell("D{$n}")->getStyle()->getBorders()->getTop()->setBorderStyle(Border::BORDER_THICK);
            $sheet->getCell("D{$n}")->getStyle()->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THICK);
            $sheet->getCell("D{$n}")->getStyle()->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THICK);
            $sheet->getCell("D{$n}")->getStyle()->getBorders()->getRight()->setBorderStyle(Border::BORDER_THICK);
        }
    }

    // Save CL file
    $saveDirectory = __DIR__ . '/commercial_invoice_files/';
    if (!is_dir($saveDirectory)) {
        mkdir($saveDirectory, 0777, true);
    }

    $clFilePath = $saveDirectory . $clFileName;
    $writer = new Xlsx($spreadsheet);
    $writer->save($clFilePath);

    // Store PDF temporarily for verification (use original uploaded file or from test config)
    $tempPdfName = 'temp_' . $clFileName . '.pdf';
    $tempPdfPath = $saveDirectory . $tempPdfName;

    if ($fileOption === 'manual') {
        // In harmonized mode, file might already be in place, so check before moving
        if (is_uploaded_file($invoicePdfPath)) {
            move_uploaded_file($invoicePdfPath, $tempPdfPath);
        } else {
            copy($invoicePdfPath, $tempPdfPath);
        }
    } else {
        copy($invoicePdfPath, $tempPdfPath);
    }

    // Check if harmonized mode
    $harmonizedMode = isset($_POST['harmonized_mode']) && $_POST['harmonized_mode'] === 'true';

    // Build redirect URL
    $redirectUrl = "/website/commercialLayer/verify_commercial_layer.php?cl=" . urlencode($clFileName) .
                   "&pdf=" . urlencode($tempPdfName) .
                   "&shop=" . urlencode($shopName);

    if ($harmonizedMode) {
        $redirectUrl .= "&harmonized=true";
    }

    // Redirect to verification page
    header("Location: " . $redirectUrl);
    exit;
}

// Display upload form
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commercial Layer Processing</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        select, input[type="file"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .radio-group {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        .radio-option {
            margin-bottom: 10px;
        }
        .radio-option input[type="radio"] {
            margin-right: 10px;
        }
        .file-inputs {
            margin-top: 15px;
            padding-left: 30px;
        }
        .test-mode-info {
            margin-top: 15px;
            padding: 10px;
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            font-size: 14px;
        }
        button {
            background: #007bff;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 20px;
        }
        button:hover {
            background: #0056b3;
        }
        .description {
            background: #e7f3ff;
            padding: 15px;
            border-left: 4px solid #007bff;
            margin-bottom: 20px;
            font-size: 14px;
        }
    </style>
    <script>
        function toggleFileInputs() {
            const fileOption = document.querySelector('input[name="fileOption"]:checked').value;
            const manualInputs = document.getElementById('manualInputs');
            const testModeInfo = document.getElementById('testModeInfo');

            if (fileOption === 'manual') {
                manualInputs.style.display = 'block';
                testModeInfo.style.display = 'none';
            } else {
                manualInputs.style.display = 'none';
                testModeInfo.style.display = 'block';
            }
        }

        window.onload = function() {
            toggleFileInputs();
        };
    </script>
</head>
<body>
    <div class="container">
        <h1>🏪 Commercial Layer Processing</h1>

        <div class="description">
            <strong>Purpose:</strong> Add commercial parameters from shop price list and recognize price changes and new products.
        </div>

        <form method="POST" enctype="multipart/form-data">
            <!-- Shop Selection -->
            <div class="form-group">
                <label for="shopName">Select Shop:</label>
                <select name="shopName" id="shopName" required>
                    <option value="">-- Select a Shop --</option>
                    <?php
                    $shopsFile = __DIR__ . '/../configDir/shops_V2.json';
                    if (file_exists($shopsFile)) {
                        $shops = json_decode(file_get_contents($shopsFile), true);
                        foreach ($shops as $shop) {
                            echo "<option value='{$shop['ShopName']}'>{$shop['ShopName']}</option>";
                        }
                    }
                    ?>
                </select>
            </div>

            <!-- File Input Options -->
            <div class="radio-group">
                <label>File Input Method:</label>

                <div class="radio-option">
                    <input type="radio" id="optionManual" name="fileOption" value="manual" checked onchange="toggleFileInputs()">
                    <label for="optionManual" style="display: inline; font-weight: normal;">
                        Option 1: Upload Files Manually
                    </label>
                </div>

                <div class="radio-option">
                    <input type="radio" id="optionTest" name="fileOption" value="test" onchange="toggleFileInputs()">
                    <label for="optionTest" style="display: inline; font-weight: normal;">
                        Option 2: Use Test Configuration (test_config.json)
                    </label>
                </div>

                <!-- Manual Upload Inputs -->
                <div id="manualInputs" class="file-inputs">
                    <div class="form-group">
                        <label for="ocrSanity">OCR Sanity File (*.xlsx):</label>
                        <input type="file" name="ocrSanity" id="ocrSanity" accept=".xlsx">
                    </div>

                    <div class="form-group">
                        <label for="priceList">Price List File (*.xlsx):</label>
                        <input type="file" name="priceList" id="priceList" accept=".xlsx">
                    </div>

                    <div class="form-group">
                        <label for="invoice">Invoice PDF File (*.pdf):</label>
                        <input type="file" name="invoice" id="invoice" accept=".pdf">
                    </div>
                </div>

                <!-- Test Mode Info -->
                <div id="testModeInfo" class="test-mode-info" style="display: none;">
                    ℹ️ Test mode will use pre-configured file paths from <code>test_config.json</code>
                </div>
            </div>

            <button type="submit">🚀 Process Commercial Layer</button>
        </form>
    </div>
</body>
</html>

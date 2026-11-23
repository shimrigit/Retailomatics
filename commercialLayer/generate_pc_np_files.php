<?php
/**
 * Generate Price Change (PC) and New Products (NP) Files
 * This endpoint creates two new files based on the Commercial Layer file:
 * 1. Price Change file - contains items with price changes above threshold
 * 2. New Products file - contains new products not in the price list
 */

// Start output buffering to prevent any accidental output before JSON
ob_start();

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['filename']) || !isset($input['shop']) || !isset($input['data'])) {
    ob_clean(); // Clear any output before sending JSON
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

$clFileName = $input['filename'];
$shopName = $input['shop'];
$clData = $input['data'];

try {
    // 1. Save the current CL file first (same as "Save CL File" functionality)
    $clFilePath = __DIR__ . '/commercial_invoice_files/' . $clFileName;

    if (!file_exists($clFilePath)) {
        throw new Exception('CL file not found: ' . $clFileName);
    }

    // Load the CL spreadsheet to update it
    try {
        $clSpreadsheet = IOFactory::load($clFilePath);
        $clSheet = $clSpreadsheet->getActiveSheet();
    } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'error' => "Unable to read CL file. The file may be open in Excel or another program.\n\nFile: " . basename($clFilePath) . "\n\nPlease close the file and try again.\n\nTechnical details: " . $e->getMessage()
        ]);
        exit;
    }

    // Update the CL file with current data
    $rowIndex = 1;
    foreach ($clData as $rowData) {
        $colIndex = 1;
        foreach ($rowData as $cellValue) {
            $clSheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $cellValue);
            $colIndex++;
        }
        $rowIndex++;
    }

    // Save the updated CL file
    $writer = new Xlsx($clSpreadsheet);
    $writer->save($clFilePath);

    // Delete temp PDF file (CL file is now concluded)
    $tempPdfName = 'temp_' . $clFileName . '.pdf';
    $tempPdfPath = __DIR__ . '/commercial_invoice_files/' . $tempPdfName;
    if (file_exists($tempPdfPath)) {
        unlink($tempPdfPath);
    }

    // 2. Load shops_V2.json to get shop configuration
    $shopsConfigPath = __DIR__ . '/../configDir/shops_V2.json';
    if (!file_exists($shopsConfigPath)) {
        throw new Exception('shops_V2.json not found');
    }

    $shopsConfig = json_decode(file_get_contents($shopsConfigPath), true);
    $shopConfig = null;

    foreach ($shopsConfig as $shop) {
        if ($shop['ShopName'] === $shopName) {
            $shopConfig = $shop;
            break;
        }
    }

    if ($shopConfig === null) {
        throw new Exception("Shop '{$shopName}' not found in configuration");
    }

    // 3. Get PriceChangePercentageThreshold (PCPT) and MarginTolerancePercentage
    $pcpt = $shopConfig['PriceChangePercentageThreshold'] ?? 3; // Default to 3 if not set
    $marginTolerance = $shopConfig['MarginTolerancePercentage'] ?? 15; // Default to 15 if not set

    // 3a. PRE-CHECK: Check if any rows exceed PCPT threshold before creating PC file
    $headers = $clData[0];
    $colMapping = [];
    foreach ($headers as $index => $header) {
        $colMapping[$header] = $index;
    }

    $priceDiffColIdx = $colMapping['PriceDiff'] ?? null;
    if ($priceDiffColIdx === null) {
        throw new Exception('PriceDiff column not found in CL file');
    }

    $hasRowsExceedingThreshold = false;
    for ($i = 1; $i < count($clData); $i++) { // Start from 1 to skip header
        $row = $clData[$i];
        $priceDiffValue = $row[$priceDiffColIdx] ?? '';

        // Check if PriceDiff is "No Cost in PL" (should be included in PC file)
        if (trim($priceDiffValue) === 'No Cost in PL') {
            $hasRowsExceedingThreshold = true;
            break;
        }

        // Parse percentage value (remove % sign and convert to number)
        $priceDiffValue = str_replace('%', '', $priceDiffValue);
        $priceDiffValue = str_replace(',', '.', $priceDiffValue);
        $priceDiffNumeric = floatval($priceDiffValue);

        // Check if absolute value exceeds PCPT
        if (abs($priceDiffNumeric) > $pcpt) {
            $hasRowsExceedingThreshold = true;
            break;
        }
    }

    // If no rows exceed threshold, skip PC file generation but check for New Products
    if (!$hasRowsExceedingThreshold) {
        // Check if there are new products (rows with "Not Found" in PriceDiff column)
        $hasNewProducts = false;

        // Find PriceDiff column index (already defined above, but let's be explicit)
        $priceDiffColIdx = array_search('PriceDiff', $headers);

        if ($priceDiffColIdx !== false) {
            foreach ($clData as $rowIndex => $row) {
                if ($rowIndex == 0) continue; // Skip header
                $priceDiff = $row[$priceDiffColIdx] ?? '';
                if (stripos($priceDiff, 'Not Found') !== false) {
                    $hasNewProducts = true;
                    break;
                }
            }
        }

        if ($hasNewProducts) {
            // There are new products - need to generate NP file
            // Store CL filename in session for NP generation
            $_SESSION['clFileName'] = $clFileName;
            $_SESSION['shopName'] = $shopName;

            // Return flag to trigger NP generation on frontend
            ob_clean(); // Clear any output before sending JSON
            echo json_encode([
                'success' => true,
                'skipPriceChange' => true,
                'hasNewProducts' => true,
                'generateNP' => true, // Flag to tell frontend to call generate_np_file.php
                'message' => "There are no price changes ({$pcpt}% or below) to justify Price Change file. Proceeding to New Products file...",
                'threshold' => $pcpt,
                'clFileName' => $clFileName,
                'shopName' => $shopName
            ]);
        } else {
            // No price changes and no new products - go directly to completion
            ob_clean(); // Clear any output before sending JSON
            echo json_encode([
                'success' => true,
                'skipPriceChange' => true,
                'hasNewProducts' => false,
                'message' => "There are no price changes ({$pcpt}% or below) and no new products. Process complete.",
                'threshold' => $pcpt,
                'redirectUrl' => 'new_products_complete.php?skip=true&cl=' . urlencode($clFileName) . '&shop=' . urlencode($shopName)
            ]);
        }
        exit;
    }

    // 4. Load Shop Department JSON file
    $departmentFilePath = __DIR__ . '/../configDir/' . $shopName . '_Departments.json';
    $departmentData = null;

    if (file_exists($departmentFilePath)) {
        $departmentData = json_decode(file_get_contents($departmentFilePath), true);
    }

    // 5. Load Supervised_products.json
    $supervisedProductsPath = __DIR__ . '/../configDir/Supervised_products.json';
    $supervisedProducts = null;

    if (file_exists($supervisedProductsPath)) {
        $supervisedProducts = json_decode(file_get_contents($supervisedProductsPath), true);
    }

    // 6. Generate PC file name with timestamp
    // Remove .xlsx extension and _CL suffix to get base name
    $baseName = str_replace('.xlsx', '', $clFileName);
    $timestamp = date('dmy_His'); // ddmmyy_hhmmss format
    $pcFileName = $baseName . '_PRICE-CHANGE_' . $timestamp . '.xlsx';
    $pcFilePath = __DIR__ . '/commercial_invoice_files/' . $pcFileName;

    // 7. Create new PC spreadsheet
    $pcSpreadsheet = new Spreadsheet();
    $pcSheet = $pcSpreadsheet->getActiveSheet();

    // 8. Get column mapping from CL file header (row 1)
    $headers = $clData[0];
    $colMapping = [];
    foreach ($headers as $index => $header) {
        $colMapping[$header] = $index;
    }

    // Define which columns to copy to PC file
    // PC columns: A, B (as-is), then Index->C, Barcode->D, ActualUnitPrice->E, OriginalUnitPrice->F,
    // ItemERPName->G, Department->H, SalesPrice->I, PriceDiff->J
    $pcColumnMap = [
        'A' => 0,  // Column A from CL
        'B' => 1,  // Column B from CL
        'C' => $colMapping['Index'] ?? null,
        'D' => $colMapping['Barcode'] ?? null,
        'E' => $colMapping['ActualUnitPrice'] ?? null,
        'F' => $colMapping['OriginalUnitPrice'] ?? null,
        'G' => $colMapping['ItemERPName'] ?? null,
        'H' => $colMapping['Department'] ?? null,
        'I' => $colMapping['SalesPrice'] ?? null,
        'J' => $colMapping['PriceDiff'] ?? null
    ];

    // 9. Write PC file headers
    $pcSheet->setCellValue('A1', $clData[0][0] ?? '');
    $pcSheet->setCellValue('B1', $clData[0][1] ?? '');
    $pcSheet->setCellValue('C1', 'Original Index');
    $pcSheet->setCellValue('D1', 'Barcode');
    $pcSheet->setCellValue('E1', 'ActualUnitPrice');
    $pcSheet->setCellValue('F1', 'OriginalUnitPrice');
    $pcSheet->setCellValue('G1', 'ItemERPName');
    $pcSheet->setCellValue('H1', 'Department');
    $pcSheet->setCellValue('I1', 'SalesPrice');
    $pcSheet->setCellValue('J1', 'PriceDiff');
    $pcSheet->setCellValue('K1', 'Old Mrgn');
    $pcSheet->setCellValue('L1', 'New Mrgn');
    $pcSheet->setCellValue('M1', 'Dprtmnt Mrgn');
    $pcSheet->setCellValue('N1', 'Rec Price');
    $pcSheet->setCellValue('O1', 'Recommend');
    $pcSheet->setCellValue('P1', 'Rec Mrgn');
    $pcSheet->setCellValue('Q1', 'InvoiceIdentifier');
    $pcSheet->setCellValue('R1', 'Change reason');

    // Extract InvoiceIdentifier from CL filename
    // Format: "OCRsanity_XXXX_dd-mm-yyyy_Z_ddmmyyyy_hhmmss_CL_..."
    $invoiceIdentifier = 'UNKNOWN';
    if (preg_match('/^OCRsanity_(.+?)_CL_/', $clFileName, $matches)) {
        $fullMatch = $matches[1]; // e.g., "Gad_10-11-2025_A_10112025_110745"
        // Remove timestamp (ddmmyyyy_hhmmss) from end
        $invoiceIdentifier = preg_replace('/_\d{8}_\d{6}$/', '', $fullMatch);
        // Result: "Gad_10-11-2025_A" or "Gad_10-11-2025"
    }

    // 10. Filter and copy rows based on PCPT threshold
    $pcRowIndex = 2; // Start from row 2 (after header row)
    $copiedRowCount = 0;

    for ($clRowIdx = 1; $clRowIdx < count($clData); $clRowIdx++) { // Start from 1 to skip header
        $row = $clData[$clRowIdx];

        // Get PriceDiff value (column P in percentage)
        $priceDiffColIdx = $colMapping['PriceDiff'] ?? null;
        if ($priceDiffColIdx === null) {
            continue;
        }

        $priceDiffValue = $row[$priceDiffColIdx] ?? '';

        // Check if PriceDiff is "No Cost in PL" (special case)
        $isNoCostInPL = (trim($priceDiffValue) === 'No Cost in PL');

        // Parse percentage value (remove % sign and convert to number)
        $priceDiffValue = str_replace('%', '', $priceDiffValue);
        $priceDiffValue = str_replace(',', '.', $priceDiffValue);
        $priceDiffNumeric = floatval($priceDiffValue);

        // Include row if: (1) exceeds PCPT threshold OR (2) has "No Cost in PL"
        if ($isNoCostInPL || abs($priceDiffNumeric) > $pcpt) {
            // Copy this row to PC file
            $pcSheet->setCellValue('A' . $pcRowIndex, $row[0] ?? '');
            $pcSheet->setCellValue('B' . $pcRowIndex, $row[1] ?? '');
            $pcSheet->setCellValue('C' . $pcRowIndex, $row[$pcColumnMap['C']] ?? ''); // Original Index
            $pcSheet->setCellValue('D' . $pcRowIndex, $row[$pcColumnMap['D']] ?? ''); // Barcode
            $pcSheet->setCellValue('E' . $pcRowIndex, $row[$pcColumnMap['E']] ?? ''); // ActualUnitPrice
            $pcSheet->setCellValue('F' . $pcRowIndex, $row[$pcColumnMap['F']] ?? ''); // OriginalUnitPrice
            $pcSheet->setCellValue('G' . $pcRowIndex, $row[$pcColumnMap['G']] ?? ''); // ItemERPName
            $pcSheet->setCellValue('H' . $pcRowIndex, $row[$pcColumnMap['H']] ?? ''); // Department
            $pcSheet->setCellValue('I' . $pcRowIndex, $row[$pcColumnMap['I']] ?? ''); // SalesPrice

            // Set PriceDiff value - keep "No Cost in PL" as-is, add % to numeric values
            if ($isNoCostInPL) {
                $pcSheet->setCellValue('J' . $pcRowIndex, 'No Cost in PL'); // Keep text as-is
            } else {
                $pcSheet->setCellValue('J' . $pcRowIndex, $priceDiffValue . '%'); // PriceDiff with % sign
            }

            $pcSheet->setCellValue('Q' . $pcRowIndex, $invoiceIdentifier); // InvoiceIdentifier

            $pcRowIndex++;
            $copiedRowCount++;
        }
    }

    // 11. CREATE SUPERVISED PRODUCTS LIST from Supervised_products.json
    $supervisedBarcodes = [];
    if ($supervisedProducts !== null && is_array($supervisedProducts)) {
        foreach ($supervisedProducts as $product) {
            if (isset($product['Barcode'])) {
                $supervisedBarcodes[] = $product['Barcode'];
            }
        }
    }

    // 12. PROCESS EACH ROW IN PC FILE FOR CALCULATIONS
    $yesRecommendationCount = 0;

    for ($row = 2; $row < $pcRowIndex; $row++) {
        // Get values from current row
        $barcode = $pcSheet->getCell('D' . $row)->getValue();
        $actualUnitPrice = floatval($pcSheet->getCell('E' . $row)->getValue());
        $originalUnitPrice = floatval($pcSheet->getCell('F' . $row)->getValue());
        $department = $pcSheet->getCell('H' . $row)->getValue();
        $salesPrice = floatval($pcSheet->getCell('I' . $row)->getValue());
        $priceDiffStr = $pcSheet->getCell('J' . $row)->getValue();

        // STEP 1: SET RECOMMENDED MARGIN FROM DEPARTMENT DATA (do this first for ALL rows)
        $departmentMargin = null;
        if ($departmentData !== null && is_array($departmentData)) {
            foreach ($departmentData as $dept) {
                $matchByName   = isset($dept['DepartmentName'])   && $dept['DepartmentName']   == $department;
                $matchByNumber = isset($dept['DepartmentNumber']) && (string)$dept['DepartmentNumber'] === (string)$department;
                if ($matchByName || $matchByNumber) {
                    if (isset($dept['ExpectedMarginPercentage'])) {
                        $departmentMargin = floatval($dept['ExpectedMarginPercentage']);
                        $pcSheet->setCellValue('M' . $row, ($departmentMargin * 100) . '%');
                    }
                    break;
                }
            }
        }

        // Check if this is a "No Cost in PL" row
        if (trim($priceDiffStr) === 'No Cost in PL') {
            // Old Mrgn = NA (no historical cost available)
            $pcSheet->setCellValue('K' . $row, 'NA');

            // New Mrgn = ((SalesPrice/1.18) - ActualUnitPrice) / (SalesPrice/1.18)
            if ($salesPrice > 0 && $actualUnitPrice > 0) {
                $salePriceNoVAT = $salesPrice / 1.18;
                $newMargin = ($salePriceNoVAT - $actualUnitPrice) / $salePriceNoVAT;
                $pcSheet->setCellValue('L' . $row, number_format($newMargin * 100, 2) . '%');
            }

            // Check supervised
            $isSupervised = false;
            foreach ($supervisedBarcodes as $supervisedBarcode) {
                if ($barcode == $supervisedBarcode) { $isSupervised = true; break; }
                $barcodeLen = strlen($barcode);
                $supervisedLen = strlen($supervisedBarcode);
                if ($barcodeLen < $supervisedLen && $barcode == substr($supervisedBarcode, -$barcodeLen)) {
                    $isSupervised = true; break;
                }
            }
            if ($isSupervised) {
                $pcSheet->setCellValue('O' . $row, 'NO. supervised');
                continue;
            }

            // Skip recommendation if department not found
            if ($departmentMargin === null) {
                $pcSheet->setCellValue('O' . $row, 'NO - Missing Cost');
                $pcSheet->setCellValue('R' . $row, 'Cost price missing in price list');
                continue;
            }

            // No cost in the price list AND no price on the invoice (a delivery note without prices):
            // nothing to recommend from (was: a division by zero).
            if ($actualUnitPrice <= 0) {
                $pcSheet->setCellValue('O' . $row, 'NO - Missing invoice price');
                $pcSheet->setCellValue('R' . $row, 'No cost in price list and no price on the invoice');
                continue;
            }

            // Use current SalesPrice as preliminary price (no PriceDiff available)
            $lowMarginBorder  = ($actualUnitPrice / (1 - $departmentMargin)) * 1.18;
            $highMarginBorder = ($actualUnitPrice / (1 - ($departmentMargin + $marginTolerance / 100))) * 1.18;

            $finalPrice   = $salesPrice;
            $changeReason = 'No historical cost. New cost applied.';

            if ($salesPrice < $lowMarginBorder) {
                $finalPrice   = $lowMarginBorder;
                $changeReason = 'No historical cost. Price increase to meet LMB';
            } elseif ($salesPrice > $highMarginBorder) {
                $finalPrice   = $highMarginBorder;
                $changeReason = 'No historical cost. Price decrease to meet HMB';
            }

            // Rec Mrgn
            $fpNoVAT   = $finalPrice / 1.18;
            $recMargin = ($fpNoVAT - $actualUnitPrice) / $fpNoVAT;
            $pcSheet->setCellValue('P' . $row, number_format($recMargin * 100, 2) . '%');

            // Recommendation
            $priceDifference        = abs($salesPrice - $finalPrice);
            $priceDifferencePercent = ($salesPrice > 0) ? ($priceDifference / $salesPrice) * 100 : 0;

            if ($priceDifferencePercent < $pcpt) {
                $pcSheet->setCellValue('O' . $row, 'NO. slim difference');
                $pcSheet->setCellValue('N' . $row, $salesPrice);
            } elseif ($salesPrice > $finalPrice) {
                $pcSheet->setCellValue('O' . $row, 'YES. decrease');
                $pcSheet->setCellValue('N' . $row, number_format($finalPrice, 2));
                $yesRecommendationCount++;
            } elseif ($salesPrice < $finalPrice) {
                $pcSheet->setCellValue('O' . $row, 'YES. increase');
                $pcSheet->setCellValue('N' . $row, number_format($finalPrice, 2));
                $yesRecommendationCount++;
            } else {
                $pcSheet->setCellValue('O' . $row, 'NO. within margin');
                $pcSheet->setCellValue('N' . $row, $salesPrice);
            }

            $pcSheet->setCellValue('R' . $row, $changeReason);
            continue;
        }

        // Parse PriceDiff percentage
        $priceDiff = floatval(str_replace(['%', ','], ['', '.'], $priceDiffStr)) / 100;

        // STEP 2: CHECK IF PRODUCT IS SUPERVISED
        $isSupervised = false;
        foreach ($supervisedBarcodes as $supervisedBarcode) {
            // Check if barcode matches (exact match or suffix match)
            if ($barcode == $supervisedBarcode) {
                $isSupervised = true;
                break;
            }
            // Check if barcode is a suffix of supervised barcode
            $barcodeLen = strlen($barcode);
            $supervisedLen = strlen($supervisedBarcode);
            if ($barcodeLen < $supervisedLen) {
                $suffix = substr($supervisedBarcode, -$barcodeLen);
                if ($barcode == $suffix) {
                    $isSupervised = true;
                    break;
                }
            }
        }

        if ($isSupervised) {
            $pcSheet->setCellValue('O' . $row, 'NO. supervised');
            continue; // Skip all calculations for supervised products
        }

        if ($departmentMargin === null) {
            $pcSheet->setCellValue('M' . $row, 'Department not found');
            continue; // Skip calculations if department not found
        }

        // STEP 3: CALCULATE OLD AND NEW MARGINS
        // Old Margin = ((SalesPrice/1.18) - OriginalUnitPrice) / (SalesPrice/1.18)
        if ($salesPrice > 0) {
            $salePriceNoVAT = $salesPrice / 1.18;
            $oldMargin = ($salePriceNoVAT - $originalUnitPrice) / $salePriceNoVAT;
            $pcSheet->setCellValue('K' . $row, number_format($oldMargin * 100, 2) . '%');
        }

        // New Margin = ((SalesPrice/1.18) - ActualUnitPrice) / (SalesPrice/1.18)
        if ($salesPrice > 0) {
            $salePriceNoVAT = $salesPrice / 1.18;
            $newMargin = ($salePriceNoVAT - $actualUnitPrice) / $salePriceNoVAT;
            $pcSheet->setCellValue('L' . $row, number_format($newMargin * 100, 2) . '%');
        }

        // STEP 4: CALCULATE PRELIMINARY PRICE (PP) AND BORDERS
        // PP = SalesPrice * (1 + PriceDiff)
        $preliminaryPrice = $salesPrice * (1 + $priceDiff);

        // LMB (Low Margin Border) = (ActualUnitPrice / (1 - Dprtmnt Mrgn)) * 1.18
        $lowMarginBorder = ($actualUnitPrice / (1 - $departmentMargin)) * 1.18;

        // HMB (High Margin Border) = (ActualUnitPrice / (1 - (Dprtmnt Mrgn + MarginTolerance/100))) * 1.18
        $highMarginBorder = ($actualUnitPrice / (1 - ($departmentMargin + $marginTolerance / 100))) * 1.18;

        // STEP 5: APPLY MARGIN VALIDATION AND SET FINAL PRICE (FP)
        $finalPrice = $preliminaryPrice;
        $changeReason = '';

        if ($preliminaryPrice > $highMarginBorder) {
            $finalPrice = $highMarginBorder;
            $changeReason = 'Price decrease. To meet HMB';
        } elseif ($preliminaryPrice < $lowMarginBorder) {
            $finalPrice = $lowMarginBorder;
            $changeReason = 'Price increase. To meet LMB';
        } else {
            // PP is within acceptable range
            $changeReason = 'Cost change. Within margin threshold';
        }

        // STEP 6: CHECK PRICE CHANGE THRESHOLDS AND SET RECOMMENDATIONS
        $priceDifference = abs($salesPrice - $finalPrice);
        $priceDifferencePercent = ($salesPrice > 0) ? ($priceDifference / $salesPrice) * 100 : 0;

        // Calculate Rec Mrgn in all cases (byproduct of FP and ActualUnitPrice)
        $fpNoVAT = $finalPrice / 1.18;
        $recMargin = ($fpNoVAT - $actualUnitPrice) / $fpNoVAT;
        $pcSheet->setCellValue('P' . $row, number_format($recMargin * 100, 2) . '%');

        if ($priceDifferencePercent < $pcpt) {
            // Price change is too slim - determine if close to LMB or HMB
            if ($preliminaryPrice < $lowMarginBorder) {
                // PP was below LMB, capped at LMB, but difference too small
                $changeReason = 'No price change. Too close to LMB';
            } elseif ($preliminaryPrice > $highMarginBorder) {
                // PP was above HMB, capped at HMB, but difference too small
                $changeReason = 'No price change. Too close to HMB';
            }
            // else: PP was within range, change too small, keep original changeReason

            $pcSheet->setCellValue('O' . $row, 'NO. slim difference');
            $pcSheet->setCellValue('N' . $row, $salesPrice);
        } elseif ($salesPrice > $finalPrice) {
            // Price decrease
            $pcSheet->setCellValue('O' . $row, 'YES. decrease');
            $pcSheet->setCellValue('N' . $row, number_format($finalPrice, 2));
            $yesRecommendationCount++;
        } elseif ($salesPrice < $finalPrice) {
            // Price increase
            $pcSheet->setCellValue('O' . $row, 'YES. increase');
            $pcSheet->setCellValue('N' . $row, number_format($finalPrice, 2));
            $yesRecommendationCount++;
        }

        // Set Change Reason in column R
        $pcSheet->setCellValue('R' . $row, $changeReason);
    }

    // 13. Save PC file
    $pcWriter = new Xlsx($pcSpreadsheet);
    $pcWriter->save($pcFilePath);

    // Store PC file info and CL filename in session for verification page
    $_SESSION['pcFileName'] = $pcFileName;
    $_SESSION['clFileName'] = $clFileName;
    $_SESSION['shopName'] = $shopName;

    ob_clean(); // Clear any output before sending JSON
    echo json_encode([
        'success' => true,
        'message' => "Price change file was created with price recommendation for {$yesRecommendationCount} items",
        'pcFileName' => $pcFileName,
        'pcRowCount' => $copiedRowCount,
        'recommendationCount' => $yesRecommendationCount,
        'threshold' => $pcpt,
        'redirectUrl' => 'verify_price_changes.php?pcFile=' . urlencode($pcFileName) . '&shop=' . urlencode($shopName)
    ]);

} catch (Exception $e) {
    ob_clean(); // Clear any output before sending JSON
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

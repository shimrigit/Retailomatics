<?php
// Retrieve Commercial Layer data - re-match barcodes with price list

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

header('Content-Type: application/json');

// Get POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['filename']) || !isset($data['shop']) || !isset($data['data'])) {
    echo json_encode(['success' => false, 'error' => 'Missing filename, shop, or data']);
    exit;
}

$filename = basename($data['filename']);
$shopName = $data['shop'];
$updatedData = $data['data'];
$isDemoMode = !empty($data['demoMode']) && $data['demoMode'] === true;

$clFilePath = __DIR__ . '/commercial_invoice_files/' . $filename;

if (!file_exists($clFilePath)) {
    echo json_encode(['success' => false, 'error' => 'CL file not found']);
    exit;
}

try {
    // Load shops_V2.json to get price list columns configuration
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

    if (!$shopConfig) {
        throw new Exception("Shop configuration not found for: $shopName");
    }

    $priceListColumns = $shopConfig['PriceListColumns'];

    // Get ReplaceBarcodeToERP setting from shop config (default to NO)
    $replaceBarcodeToERP = isset($shopConfig['ReplaceBarcodeToERP']) && strtoupper($shopConfig['ReplaceBarcodeToERP']) === 'YES';

    // Find the price list file for this shop
    if ($isDemoMode) {
        // Demo mode: use price list from preProcessDir/Demo, file must start with shop name
        $demoDir = __DIR__ . '/../preProcessDir/Demo/';
        $allFiles = glob($demoDir . '*.xlsx');
        $priceListFiles = array_filter((array)$allFiles, function($f) use ($shopName) {
            return stripos(basename($f), $shopName) === 0 && stripos(basename($f), 'OCRsanity') !== 0;
        });
    } else {
        // the shop's newest Gateway download, else price_list_files (price_list_source.php)
        require_once __DIR__ . '/price_list_source.php';
        $pl = clFindPriceList($shopName);
        $priceListFiles = $pl ? [$pl['path']] : [];
    }

    if (empty($priceListFiles)) {
        throw new Exception("No price list file found for shop: $shopName");
    }

    // Use the most recent price list file
    usort($priceListFiles, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    $priceListPath = array_values($priceListFiles)[0];

    // Load CL Excel file
    $clSpreadsheet = IOFactory::load($clFilePath);
    $clSheet = $clSpreadsheet->getActiveSheet();

    // Load Price List Excel file
    $plSpreadsheet = IOFactory::load($priceListPath);
    $plSheet = $plSpreadsheet->getActiveSheet();
    require_once __DIR__ . '/price_list_source.php';
    $priceListColumns = clPriceListColumns($plSheet, $priceListColumns);   // by the header row
    $plHighestRow = $plSheet->getHighestRow();

    // Get column letters from PriceListColumns configuration
    $plItemERPNameCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($priceListColumns['ItemERPName']);
    $plBarcodeCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($priceListColumns['Barcode']);
    $plDepartmentCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($priceListColumns['Department']);
    $plCostPriceCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($priceListColumns['CostPrice']);
    $plSalesPriceCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($priceListColumns['SalesPrice']);

    // First, update the CL file with the user's edits from Handsontable
    foreach ($updatedData as $rowIndex => $rowData) {
        foreach ($rowData as $colIndex => $value) {
            $excelRow = $rowIndex + 1;
            $excelCol = $colIndex + 1;

            if ($value === null || $value === '') {
                continue;
            }

            // Column D (4) is Barcode - save as text
            if ($excelCol === 4) {
                $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($excelCol);
                $clSheet->setCellValueExplicit("{$columnLetter}{$excelRow}", $value,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            // Numeric columns: B, F, G, H, I, J, K, L, O (cost/price columns)
            else if (in_array($excelCol, [2, 6, 7, 8, 9, 10, 11, 12, 15])) {
                $numericValue = str_replace(',', '', $value);
                $numericValue = str_replace('%', '', $numericValue);
                if (is_numeric($numericValue)) {
                    $clSheet->setCellValueByColumnAndRow($excelCol, $excelRow, (float)$numericValue);
                } else {
                    $clSheet->setCellValueByColumnAndRow($excelCol, $excelRow, $value);
                }
            }
            // Column P (16) - PriceDiff
            else if ($excelCol === 16) {
                $cleanValue = str_replace('%', '', $value);
                if (is_numeric($cleanValue)) {
                    $clSheet->setCellValueByColumnAndRow($excelCol, $excelRow, (float)$cleanValue);
                } else {
                    $clSheet->setCellValueByColumnAndRow($excelCol, $excelRow, $value);
                }
            }
            else {
                $clSheet->setCellValueByColumnAndRow($excelCol, $excelRow, $value);
            }
        }
    }

    // Now re-match barcodes and retrieve commercial data
    $highestRow = $clSheet->getHighestRow();
    $updatedCells = [];

    // Step 1: Clear all borders from column D (barcode column) before re-matching
    for ($n = 2; $n <= $highestRow; $n++) {
        $clSheet->getCell("D{$n}")->getStyle()->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_NONE);
    }

    // The shop's conversion table (code_map.php). D as it is now (the user may have typed over it):
    // not a barcode (M3100) → the table, else a new number; "חדש" / "+" → a new number (also a row
    // without a code); a code that is the table's for this row's printed code (Q) → matched exactly.
    require_once __DIR__ . '/code_map.php';
    $cmSupplier = trim((string) $clSheet->getCell('B2')->getValue());
    $cmHasQ = trim((string) $clSheet->getCell('Q1')->getValue()) === 'InvoiceCode';
    $cmPlCodes = cmPriceListCodes($plSheet, $plBarcodeCol, $plHighestRow);
    $cmRows = cmUpdate($shopName, function (array &$cm) use ($clSheet, $highestRow, $cmSupplier, $cmHasQ, $cmPlCodes) {
        cmConfirmArrived($cm, $cmPlCodes);
        // First what the user typed over a printed code (a Comax code, the same in the price list):
        // learned before new numbers are given, so a number it frees is given again at once.
        for ($n = 2; $cmHasQ && $n <= $highestRow; $n++) {
            $d = preg_replace('/[^0-9]/', '', trim((string) $clSheet->getCell("D{$n}")->getValue()));
            $printed = trim((string) $clSheet->getCell("Q{$n}")->getValue());
            $name = trim((string) $clSheet->getCell("E{$n}")->getValue());
            if ($d === '' || !isset($cmPlCodes[$d]) || cmIsInternal(trim((string) $clSheet->getCell("D{$n}")->getValue()))) continue;
            $pd = preg_replace('/[^0-9]/', '', $printed);
            $same = $pd !== '' && (str_ends_with($d, $pd) || str_ends_with($pd, $d) || str_ends_with($d, str_pad($pd, 6, '0', STR_PAD_LEFT)));
            if (cmIsInternal($printed) || !$same) cmLearn($cm, $cmSupplier, cmKey($printed, $name), $d, $name);
        }
        $out = [];
        for ($n = 2; $n <= $highestRow; $n++) {
            $d = trim((string) $clSheet->getCell("D{$n}")->getValue());
            if ($d === '') continue;
            $printed = $cmHasQ ? trim((string) $clSheet->getCell("Q{$n}")->getValue()) : '';
            $name = trim((string) $clSheet->getCell("E{$n}")->getValue());
            if ($d === 'חדש' || $d === '+') {
                $out[$n] = cmResolve($cm, $cmSupplier, $printed, $name, $cmPlCodes, true);
            } elseif (cmIsInternal($d)) {
                $out[$n] = cmResolve($cm, $cmSupplier, $d, $name, $cmPlCodes);
            } else {
                $hit = $cmHasQ ? cmLookup($cm, $cmSupplier, cmKey($printed, $name)) : null;
                $out[$n] = [$d, $hit && (string) $hit['code'] === $d ? 'map' : ''];
            }
        }
        return $out;
    }, $isDemoMode);
    $cmLearn = [];   // [row, code] — the user typed a Comax code over a printed code that is not it

    // Step 2: Start from row 2 (skip header) and re-match barcodes
    for ($n = 2; $n <= $highestRow; $n++) {
        if (!isset($cmRows[$n])) continue;
        [$cmCode, $cmHow] = $cmRows[$n];
        if (isset(CM_FILL[$cmHow])) {
            $clSheet->setCellValueExplicit("D{$n}", $cmCode, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $clSheet->getStyle("D{$n}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(CM_FILL[$cmHow]);
            $updatedCells[] = ['row' => $n - 1, 'col' => 3, 'value' => $cmCode, 'background' => '#' . substr(CM_FILL[$cmHow], 2)];
        }
        $ibOriginal = $cmCode;

        // Convert to string and remove any non-numeric characters for matching
        $ib = (string)$ibOriginal;
        $ib = preg_replace('/[^0-9]/', '', $ib);

        // Search for matching barcode in Price List
        $matchFound = false;
        $matchRow = null;
        $matchedERPBarcode = null; // Store the matched ERP barcode

        // The same code first; a code from the table / a new number: only the same code; not a barcode: none.
        $cmExact = $cmHow === 'map' || $cmHow === 'new';
        if ($cmHow !== 'internal' && $ib !== '' && isset($cmPlCodes[$ib])) {
            for ($m = 2; $m <= $plHighestRow; $m++) {
                if (preg_replace('/[^0-9]/', '', (string) $plSheet->getCell("{$plBarcodeCol}{$m}")->getValue()) === $ib) {
                    $matchFound = true; $matchRow = $m; $matchedERPBarcode = $ib;
                    break;
                }
            }
        }

        for ($m = 2; !$matchFound && !$cmExact && $cmHow !== 'internal' && $ib !== '' && $m <= $plHighestRow; $m++) {
            $plbOriginal = $plSheet->getCell("{$plBarcodeCol}{$m}")->getValue();

            // Convert to string and remove any non-numeric characters for matching
            $plb = (string)$plbOriginal;
            $plb = preg_replace('/[^0-9]/', '', $plb);

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
                    if (substr($plb, -$ibLen) === $ib) {
                        $matchFound = true;
                        $matchRow = $m;
                        $matchedERPBarcode = $plb; // Store the normalized ERP barcode
                        break;
                    }
                } else {
                    if (substr($ib, -$plbLen) === $plb) {
                        $matchFound = true;
                        $matchRow = $m;
                        $matchedERPBarcode = $plb; // Store the normalized ERP barcode
                        break;
                    }
                }
            }
            // Case 3: IB of 6 digits or fewer - pad to 6 with zeros and check suffix (6 itself was matched by no case: Berman 497112 → 7290000497112)
            elseif ($ibLen <= 6) {
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
            // Borders already cleared at the beginning - no need to clear here

            // The user typed a Comax code over the printed one (M3100, none, or the supplier's own
            // number — not just a shorter form of the same barcode): the table learns it.
            if ($cmHow === '' && $cmHasQ) {
                $p = trim((string) $clSheet->getCell("Q{$n}")->getValue());
                $pd = preg_replace('/[^0-9]/', '', $p);
                $same = $pd !== '' && (str_ends_with($matchedERPBarcode, $pd) || str_ends_with($pd, $matchedERPBarcode)
                    || str_ends_with($matchedERPBarcode, str_pad($pd, 6, '0', STR_PAD_LEFT)));
                if (cmIsInternal($p) || !$same) $cmLearn[] = [$n, $p, $matchedERPBarcode];
            }

            // Check if we should replace the invoice barcode with ERP barcode
            if ($replaceBarcodeToERP && $ib !== $matchedERPBarcode) {
                // Barcodes are different - replace with ERP barcode and highlight
                $clSheet->setCellValueExplicit("D{$n}", $matchedERPBarcode, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                // Apply light brown background color (tan/wheat color)
                $clSheet->getStyle("D{$n}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFD2B48C'); // Light brown/tan color

                // Track the barcode update for the response
                $updatedCells[] = [
                    'row' => $n - 1, // 0-indexed for Handsontable
                    'col' => 3, // Column D (barcode)
                    'value' => $matchedERPBarcode,
                    'background' => '#D2B48C'
                ];
            }

            // Copy ItemERPName (PL -> CL column M)
            $itemERPName = $plSheet->getCell("{$plItemERPNameCol}{$matchRow}")->getValue();
            $clSheet->setCellValue("M{$n}", $itemERPName);

            // Copy Department (PL -> CL column N)
            $department = $plSheet->getCell("{$plDepartmentCol}{$matchRow}")->getValue();
            $clSheet->setCellValue("N{$n}", $department);

            // Copy CostPrice (PL -> CL column L - OriginalUnitPrice)
            $costPrice = $plSheet->getCell("{$plCostPriceCol}{$matchRow}")->getValue();
            $clSheet->setCellValue("L{$n}", $costPrice);
            $clSheet->getStyle("L{$n}")->getNumberFormat()->setFormatCode('#,##0.00');

            // Copy SalesPrice (PL -> CL column O)
            $salesPrice = $plSheet->getCell("{$plSalesPriceCol}{$matchRow}")->getValue();
            $clSheet->setCellValue("O{$n}", $salesPrice);
            $clSheet->getStyle("O{$n}")->getNumberFormat()->setFormatCode('#,##0.00');

            // Calculate price difference percentage: (K / L - 1) * 100
            $actualUnitPrice = $clSheet->getCell("K{$n}")->getValue();
            $originalUnitPrice = $costPrice;

            $priceDiffValue = null;
            $priceDiffBg = null;

            // Check if cost price is missing or empty
            if (empty($originalUnitPrice) || !is_numeric($originalUnitPrice) || $originalUnitPrice == 0) {
                // Barcode found but no cost in price list
                $clSheet->setCellValue("P{$n}", "No Cost in PL");
                $clSheet->getStyle("P{$n}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFFFA500'); // Orange background
                $priceDiffValue = "No Cost in PL";
                $priceDiffBg = '#FFA500';
            } elseif (is_numeric($actualUnitPrice) && is_numeric($originalUnitPrice)) {
                $priceDiff = (((float)$actualUnitPrice / (float)$originalUnitPrice) - 1) * 100;
                $priceDiff = round($priceDiff, 2);

                $clSheet->setCellValue("P{$n}", $priceDiff);
                $clSheet->getStyle("P{$n}")->getNumberFormat()->setFormatCode('0.00"%"');

                // Apply conditional formatting
                if ($priceDiff > 0) {
                    // Light red background
                    $clSheet->getStyle("P{$n}")->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFFFCCCC');
                    $priceDiffBg = '#FFCCCC';
                } elseif ($priceDiff < 0) {
                    // Light green background
                    $clSheet->getStyle("P{$n}")->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FFCCFFCC');
                    $priceDiffBg = '#CCFFCC';
                }

                $priceDiffValue = $priceDiff;
            }

            // Track updated cells for response
            $updatedCells[] = [
                'row' => $n - 1, // 0-indexed for Handsontable
                'col' => 12, // Column M (ItemERPName)
                'value' => $itemERPName
            ];
            $updatedCells[] = [
                'row' => $n - 1,
                'col' => 13, // Column N (Department)
                'value' => $department
            ];
            $updatedCells[] = [
                'row' => $n - 1,
                'col' => 11, // Column L (OriginalUnitPrice)
                'value' => number_format($costPrice, 2)
            ];
            $updatedCells[] = [
                'row' => $n - 1,
                'col' => 14, // Column O (SalesPrice)
                'value' => number_format($salesPrice, 2)
            ];
            $updatedCells[] = [
                'row' => $n - 1,
                'col' => 15, // Column P (PriceDiff)
                'value' => $priceDiffValue,
                'background' => $priceDiffBg
            ];
        } else {
            // No match found
            $clSheet->setCellValue("P{$n}", "Not Found");
            $clSheet->getStyle("P{$n}")->getFill()->setFillType(Fill::FILL_NONE);

            // Add bold borders to the barcode cell (column D) to highlight for manual correction
            $clSheet->getCell("D{$n}")->getStyle()->getBorders()->getTop()->setBorderStyle(Border::BORDER_THICK);
            $clSheet->getCell("D{$n}")->getStyle()->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THICK);
            $clSheet->getCell("D{$n}")->getStyle()->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THICK);
            $clSheet->getCell("D{$n}")->getStyle()->getBorders()->getRight()->setBorderStyle(Border::BORDER_THICK);

            // Clear other columns
            $clSheet->setCellValue("L{$n}", "");
            $clSheet->setCellValue("M{$n}", "");
            $clSheet->setCellValue("N{$n}", "");
            $clSheet->setCellValue("O{$n}", "");

            $updatedCells[] = [
                'row' => $n - 1,
                'col' => 11,
                'value' => ''
            ];
            $updatedCells[] = [
                'row' => $n - 1,
                'col' => 12,
                'value' => ''
            ];
            $updatedCells[] = [
                'row' => $n - 1,
                'col' => 13,
                'value' => ''
            ];
            $updatedCells[] = [
                'row' => $n - 1,
                'col' => 14,
                'value' => ''
            ];
            $updatedCells[] = [
                'row' => $n - 1,
                'col' => 15,
                'value' => 'Not Found',
                'background' => null
            ];
        }
    }

    if ($cmLearn) {
        cmUpdate($shopName, function (array &$cm) use ($cmLearn, $clSheet, $cmSupplier) {
            foreach ($cmLearn as [$n, $printed, $code]) {
                $name = trim((string) $clSheet->getCell("E{$n}")->getValue());
                cmLearn($cm, $cmSupplier, cmKey($printed, $name), $code, $name);
            }
        }, $isDemoMode);
    }

    // Save the file
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($clSpreadsheet);
    $writer->save($clFilePath);

    echo json_encode([
        'success' => true,
        'message' => 'Commercial data retrieved successfully',
        'updatedCells' => $updatedCells
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

<?php
/**
 * BOpack Retailomatics - Main Processing File
 *
 * Purpose: Process CL, PC, NP files and generate BOpack output files
 * - Invoice_list.xlsx
 * - Invoice_to_upload_*.xlsx (one per invoice)
 * - Price_change_list.xlsx (future)
 * - New_products_list.xlsx (future)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die('Error: Invalid request method. Please submit the form.');
}

// Get form data
$shopName = isset($_POST['shopName']) ? trim($_POST['shopName']) : '';
$processDate = isset($_POST['processDate']) ? trim($_POST['processDate']) : '';

if (empty($shopName) || empty($processDate)) {
    die('Error: Shop name and process date are required.');
}

// Convert process date from Y-m-d to dd-mm-yy format for file matching
$processDateObj = DateTime::createFromFormat('Y-m-d', $processDate);
if (!$processDateObj) {
    die('Error: Invalid process date format.');
}
$processDateShort = $processDateObj->format('d-m-y'); // dd-mm-yy for output files
$processDateFull = $processDateObj->format('d-m-Y'); // dd-mm-yyyy for input file matching

// Load shop configuration
$shopsConfigPath = __DIR__ . '/../configDir/shops_V2.json';
if (!file_exists($shopsConfigPath)) {
    die('Error: shops_V2.json configuration file not found.');
}

$shopsConfig = json_decode(file_get_contents($shopsConfigPath), true);
if (!$shopsConfig) {
    die('Error: Failed to parse shops_V2.json.');
}

// Find shop configuration
$shopConfig = null;
foreach ($shopsConfig as $shop) {
    if ($shop['ShopName'] === $shopName) {
        $shopConfig = $shop;
        break;
    }
}

if (!$shopConfig) {
    die("Error: Shop '{$shopName}' not found in configuration.");
}

// Get BackOfficeType (Comax or All4shops)
$backOfficeType = isset($shopConfig['BackOfficeType']) ? $shopConfig['BackOfficeType'] : 'Comax';

// Load supplier codes for this shop
$supplierCodesPath = __DIR__ . "/../configDir/{$shopName}_suppliers.json";
if (!file_exists($supplierCodesPath)) {
    die("Error: Supplier codes file not found: {$shopName}_suppliers.json");
}

$supplierCodes = json_decode(file_get_contents($supplierCodesPath), true);
if (!$supplierCodes) {
    die("Error: Failed to parse {$shopName}_suppliers.json");
}

// Create associative array for quick lookup
$supplierCodeMap = [];
foreach ($supplierCodes as $supplier) {
    $supplierCodeMap[$supplier['SupplierName']] = $supplier['SupplierCode'];
}

// Define directories
$sourceDir = __DIR__ . '/../commercialLayer/commercial_invoice_files';
$outputDir = __DIR__ . '/../toBackOffice';

// Ensure output directory exists
if (!file_exists($outputDir)) {
    mkdir($outputDir, 0777, true);
}

// Scan for CL, PC, NP files
$allFiles = scandir($sourceDir);
$clFiles = [];
$pcFiles = [];
$npFiles = [];

// DEBUG: Show what we're looking for
echo "<h3>Debug Information:</h3>";
echo "<p><strong>Looking for date:</strong> {$processDateFull}</p>";
echo "<p><strong>Source directory:</strong> {$sourceDir}</p>";
echo "<p><strong>Total files in directory:</strong> " . count($allFiles) . "</p>";
echo "<hr>";

$matchedDateFiles = [];

foreach ($allFiles as $file) {
    if ($file === '.' || $file === '..') {
        continue;
    }

    $filePath = $sourceDir . '/' . $file;

    // Check if file matches the shop and date pattern
    // Format: OCRsanity_{Supplier}_{dd-mm-yyyy}...
    // Note: Supplier name can contain letters and numbers (e.g., TnuvaMeat2)
    if (preg_match('/^OCRsanity_([A-Za-z0-9]+)_(\d{2}-\d{2}-\d{4})/', $file, $matches)) {
        $fileSupplier = $matches[1];
        $fileDate = $matches[2];

        // DEBUG: Track files with matching dates
        if ($fileDate === $processDateFull) {
            $matchedDateFiles[] = $file;
        }

        // Check if date matches
        if ($fileDate !== $processDateFull) {
            continue;
        }

        // Categorize file type
        // IMPORTANT: Check PC and NP first since they contain _CL_ in their names
        // Note: Timestamp format is ddmmyyyy_hhmmss (8 digits + 6 digits)
        if (preg_match('/_PRICE-CHANGE_\d{6}_\d{6}\.xlsx$/', $file)) {
            $pcFiles[] = [
                'path' => $filePath,
                'name' => $file,
                'supplier' => $fileSupplier,
                'date' => $fileDate,
                'mtime' => filemtime($filePath)
            ];
        } elseif (preg_match('/_NEW-PRODUCTS_\d{6}_\d{6}\.xlsx$/', $file)) {
            $npFiles[] = [
                'path' => $filePath,
                'name' => $file,
                'supplier' => $fileSupplier,
                'date' => $fileDate,
                'mtime' => filemtime($filePath)
            ];
        } elseif (preg_match('/_CL_\d{8}_\d{6}\.xlsx$/', $file)) {
            // Pure CL files (no PRICE-CHANGE or NEW-PRODUCTS suffix)
            // Timestamp format: _CL_ddmmyyyy_hhmmss.xlsx
            $clFiles[] = [
                'path' => $filePath,
                'name' => $file,
                'supplier' => $fileSupplier,
                'date' => $fileDate,
                'mtime' => filemtime($filePath)
            ];
        }
    }
}

// DEBUG: Show matched files
echo "<h3>Files with matching date ({$processDateFull}):</h3>";
if (empty($matchedDateFiles)) {
    echo "<p style='color: red;'>❌ No files found with date {$processDateFull}</p>";
} else {
    echo "<ul>";
    foreach ($matchedDateFiles as $mf) {
        echo "<li>{$mf}</li>";
    }
    echo "</ul>";
}
echo "<hr>";

// Sort CL files by modification time (older first)
usort($clFiles, function($a, $b) {
    return $a['mtime'] - $b['mtime'];
});

// DEBUG: Show categorized files
echo "<h3>Categorized Files:</h3>";
echo "<p><strong>CL Files:</strong> " . count($clFiles) . "</p>";
if (!empty($clFiles)) {
    echo "<ul>";
    foreach ($clFiles as $cf) {
        echo "<li>{$cf['name']}</li>";
    }
    echo "</ul>";
}
echo "<p><strong>PC Files:</strong> " . count($pcFiles) . "</p>";
echo "<p><strong>NP Files:</strong> " . count($npFiles) . "</p>";
echo "<hr>";

// Check if we have CL files
if (empty($clFiles)) {
    die("Error: No CL files found for shop '{$shopName}' and date '{$processDateFull}'");
}

echo "<h2>Files Found:</h2>";
echo "<p><strong>CL Files:</strong> " . count($clFiles) . "</p>";
echo "<p><strong>PC Files:</strong> " . count($pcFiles) . "</p>";
echo "<p><strong>NP Files:</strong> " . count($npFiles) . "</p>";
echo "<hr>";

// ============================================
// STEP 1: Generate Invoice_list file
// ============================================

$invoiceListFile = $outputDir . "/invoice_list_{$processDateShort}.xlsx";
$invoiceList = new Spreadsheet();
$invoiceSheet = $invoiceList->getActiveSheet();

// Set headers
$invoiceSheet->setCellValue('A1', 'supplier');
$invoiceSheet->setCellValue('B1', 'date');
$invoiceSheet->setCellValue('C1', 'supplierCode');
$invoiceSheet->setCellValue('D1', 'invoiceNum');
$invoiceSheet->setCellValue('E1', 'invoiceSum');
$invoiceSheet->setCellValue('F1', 'remark');
$invoiceSheet->setCellValue('G1', 'uploadFile'); // which invoice_to_upload file this row is (the Comax Gateway loads by it)

$rowNum = 2;

foreach ($clFiles as $clFile) {
    $supplierName = $clFile['supplier'];

    // Get supplier code
    $supplierCode = isset($supplierCodeMap[$supplierName]) ? $supplierCodeMap[$supplierName] : 'UNKNOWN';

    // Load CL file
    try {
        $clSpreadsheet = IOFactory::load($clFile['path']);
        $clSheet = $clSpreadsheet->getActiveSheet();
    } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
        die("❌ <strong>Error: Unable to read CL file for Invoice List</strong><br><br>" .
            "📄 <strong>File:</strong> " . htmlspecialchars(basename($clFile['path'])) . "<br><br>" .
            "⚠️ <strong>Possible causes:</strong><br>" .
            "• The file is currently open in Excel or another program<br>" .
            "• The file is corrupted or not a valid Excel file<br>" .
            "• The file is locked by another process<br><br>" .
            "💡 <strong>Solution:</strong> Please close the file in Excel and try again.<br><br>" .
            "🔧 <strong>Technical details:</strong> " . htmlspecialchars($e->getMessage()));
    }

    // Extract values from CL file
    $cellB1 = $clSheet->getCell('B1')->getValue(); // Invoice Number
    $cellB2 = $clSheet->getCell('B2')->getValue(); // Supplier Name
    $cellB3 = $clSheet->getCell('B3')->getValue(); // Invoice Date
    $cellB4 = $clSheet->getCell('B4')->getValue(); // Invoice Total
    $cellB5 = $clSheet->getCell('B5')->getValue(); // Remark

    // Convert date to dd.mm.yyyy format (standard format)
    $invoiceDateFormatted = $cellB3;

    // Handle different date formats and convert to dd.mm.yyyy
    if (preg_match('/^(\d{2})[-.](\d{2})[-.](\d{4})$/', $cellB3, $dateMatches)) {
        // Format: dd-mm-yyyy or dd.mm.yyyy (4-digit year)
        $invoiceDateFormatted = $dateMatches[1] . '.' . $dateMatches[2] . '.' . $dateMatches[3];
    } elseif (preg_match('/^(\d{2})[-.](\d{2})[-.](\d{2})$/', $cellB3, $dateMatches)) {
        // Format: dd-mm-yy or dd.mm.yy (2-digit year)
        // Convert 2-digit year to 4-digit year
        $year = intval($dateMatches[3]);
        $fullYear = ($year >= 0 && $year <= 50) ? (2000 + $year) : (1900 + $year);
        $invoiceDateFormatted = $dateMatches[1] . '.' . $dateMatches[2] . '.' . $fullYear;
    }

    // Write to invoice list
    $invoiceSheet->setCellValue("A{$rowNum}", $cellB2); // Supplier
    $invoiceSheet->setCellValue("B{$rowNum}", $invoiceDateFormatted); // Date in dd.mm.yyyy format
    $invoiceSheet->setCellValue("C{$rowNum}", $supplierCode); // Supplier Code
    $invoiceSheet->setCellValueExplicit("D{$rowNum}", $cellB1, DataType::TYPE_STRING); // Invoice Number
    $invoiceSheet->setCellValue("E{$rowNum}", $cellB4); // Invoice Sum
    $invoiceSheet->setCellValue("F{$rowNum}", $cellB5); // Remark
    // The invoice_to_upload file of this row: same signature and extension as STEP 2 writes.
    if (preg_match('/OCRsanity_([A-Za-z0-9]+_\d{2}-\d{2}-\d{4}_[A-Z])/', $clFile['name'], $sigMatch)) {
        $invoiceSheet->setCellValue("G{$rowNum}", "invoice_to_upload_{$sigMatch[1]}." . ($backOfficeType === 'All4shops' ? 'csv' : 'xlsx'));
    }

    $rowNum++;
}

// Auto-size columns
foreach (range('A', 'G') as $col) {
    $invoiceSheet->getColumnDimension($col)->setAutoSize(true);
}

// Save invoice list
try {
    $writer = new Xlsx($invoiceList);
    $writer->save($invoiceListFile);
} catch (\PhpOffice\PhpSpreadsheet\Writer\Exception $e) {
    die("❌ <strong>Error: Unable to save Invoice List file</strong><br><br>" .
        "📄 <strong>File:</strong> " . htmlspecialchars(basename($invoiceListFile)) . "<br><br>" .
        "⚠️ <strong>Possible causes:</strong><br>" .
        "• The file is currently open in Excel or another program<br>" .
        "• The file is locked by another process<br>" .
        "• Insufficient disk space or permissions<br><br>" .
        "💡 <strong>Solution:</strong> Please close the file in Excel and try again.<br><br>" .
        "🔧 <strong>Technical details:</strong> " . htmlspecialchars($e->getMessage()));
}

echo "<p>✅ Invoice list created: <strong>{$invoiceListFile}</strong></p>";
echo "<p>📊 Total invoices processed: <strong>" . count($clFiles) . "</strong></p>";
echo "<hr>";

// ============================================
// STEP 2: Generate Invoice_to_upload files
// ============================================

$invoicesToUploadCount = 0;

foreach ($clFiles as $clFile) {
    // Extract invoice signature from filename
    // Format: OCRsanity_{Supplier}_{dd-mm-yyyy}_{Letter}_ddmmyy_hhmmss_CL_ddmmyy_hhmmss.xlsx
    // Note: Supplier name can contain letters and numbers (e.g., TnuvaMeat2)
    if (preg_match('/OCRsanity_([A-Za-z0-9]+_\d{2}-\d{2}-\d{4}_[A-Z])/', $clFile['name'], $matches)) {
        $invoiceSignature = $matches[1];
    } else {
        echo "<p>⚠️ Warning: Could not extract invoice signature from {$clFile['name']}</p>";
        continue;
    }

    // Load CL file
    try {
        $clSpreadsheet = IOFactory::load($clFile['path']);
        $clSheet = $clSpreadsheet->getActiveSheet();
    } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
        die("❌ <strong>Error: Unable to read CL file for Invoice to Upload</strong><br><br>" .
            "📄 <strong>File:</strong> " . htmlspecialchars(basename($clFile['path'])) . "<br><br>" .
            "⚠️ <strong>Possible causes:</strong><br>" .
            "• The file is currently open in Excel or another program<br>" .
            "• The file is corrupted or not a valid Excel file<br>" .
            "• The file is locked by another process<br><br>" .
            "💡 <strong>Solution:</strong> Please close the file in Excel and try again.<br><br>" .
            "🔧 <strong>Technical details:</strong> " . htmlspecialchars($e->getMessage()));
    }

    // Get highest row in CL file
    $highestRow = $clSheet->getHighestRow();

    // Create new Invoice_to_upload file
    $invoiceToUpload = new Spreadsheet();
    $uploadSheet = $invoiceToUpload->getActiveSheet();

    // Get mapping configuration based on BackOfficeType
    if ($backOfficeType === 'All4shops') {
        $mapping = $shopConfig['Invoice_to_upload_HeadersMapping_All4shops'];
    } else {
        $mapping = $shopConfig['Invoice_to_upload_HeadersMapping_Comax'];
    }

    // Set headers and process data
    $destRow = 1;

    // Write headers (row 1)
    foreach ($mapping as $destCol => $config) {
        $header = $config[0];
        $uploadSheet->setCellValue("{$destCol}1", $header);
    }

    // Process data rows (starting from row 2 in CL file)
    $rowIndex = 1; // Row index counter starting at 1
    for ($sourceRow = 2; $sourceRow <= $highestRow; $sourceRow++) {
        $destRow = $sourceRow; // Keep same row number in destination

        foreach ($mapping as $destCol => $config) {
            $header = $config[0];
            $originCol = $config[1];
            $dataType = $config[2];

            // Handle origin column = 0 (special cases)
            if ($originCol === 0) {
                // Column A: Auto-generate row number starting from 1
                if ($destCol === 'A') {
                    $uploadSheet->setCellValue("{$destCol}{$destRow}", $rowIndex);
                }
                // Other columns with origin = 0: leave empty
                continue;
            }

            // Get value from CL file
            $cellAddress = "{$originCol}{$sourceRow}";
            $value = $clSheet->getCell($cellAddress)->getValue();

            // Apply data type formatting
            if ($dataType === 'T') {
                // Text - use explicit string type
                $uploadSheet->setCellValueExplicit("{$destCol}{$destRow}", $value, DataType::TYPE_STRING);
            } elseif ($dataType === 'D') {
                // Double - numeric with decimals
                $uploadSheet->setCellValue("{$destCol}{$destRow}", (float)$value);
                $uploadSheet->getStyle("{$destCol}{$destRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
            } elseif ($dataType === 'I') {
                // Integer - whole number
                $uploadSheet->setCellValue("{$destCol}{$destRow}", (int)$value);
                $uploadSheet->getStyle("{$destCol}{$destRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
            } else {
                // No specific formatting
                $uploadSheet->setCellValue("{$destCol}{$destRow}", $value);
            }
        }

        $rowIndex++; // Increment row index for next row
    }

    // Auto-size all columns
    foreach (array_keys($mapping) as $col) {
        $uploadSheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Determine file extension and writer based on BackOfficeType
    if ($backOfficeType === 'All4shops') {
        $invoiceToUploadFile = $outputDir . "/invoice_to_upload_{$invoiceSignature}.csv";
    } else {
        $invoiceToUploadFile = $outputDir . "/invoice_to_upload_{$invoiceSignature}.xlsx";
    }

    // Delete existing file if it exists
    if (file_exists($invoiceToUploadFile)) {
        if (!@unlink($invoiceToUploadFile)) {
            die("❌ <strong>Error: Unable to delete existing Invoice to Upload file</strong><br><br>" .
                "📄 <strong>File:</strong> " . htmlspecialchars(basename($invoiceToUploadFile)) . "<br><br>" .
                "⚠️ <strong>Possible cause:</strong><br>" .
                "• The file is currently open in Excel or another program<br><br>" .
                "💡 <strong>Solution:</strong> Please close the file in Excel and try again.");
        }
    }

    try {
        if ($backOfficeType === 'All4shops') {
            $writer = new Csv($invoiceToUpload);
            $writer->setDelimiter(',');
            $writer->setEnclosure('"');
            $writer->setLineEnding("\r\n");
            $writer->setSheetIndex(0);
            $writer->save($invoiceToUploadFile);
        } else {
            $writer = new Xlsx($invoiceToUpload);
            $writer->save($invoiceToUploadFile);
        }
    } catch (\PhpOffice\PhpSpreadsheet\Writer\Exception $e) {
        die("❌ <strong>Error: Unable to save Invoice to Upload file</strong><br><br>" .
            "📄 <strong>File:</strong> " . htmlspecialchars(basename($invoiceToUploadFile)) . "<br><br>" .
            "⚠️ <strong>Possible causes:</strong><br>" .
            "• The file is currently open in Excel or another program<br>" .
            "• The file is locked by another process<br>" .
            "• Insufficient disk space or permissions<br><br>" .
            "💡 <strong>Solution:</strong> Please close the file in Excel and try again.<br><br>" .
            "🔧 <strong>Technical details:</strong> " . htmlspecialchars($e->getMessage()));
    }

    $fileExt = ($backOfficeType === 'All4shops') ? 'csv' : 'xlsx';
    echo "<p>✅ Invoice to upload created: <strong>invoice_to_upload_{$invoiceSignature}.{$fileExt}</strong></p>";
    $invoicesToUploadCount++;
}

echo "<hr>";

// ============================================
// STEP 3: Generate Price_change_list file(s)
// ============================================

$pcTotalRows = 0;

if ($backOfficeType === 'All4shops') {
    // All4shops: Generate TWO files - data (xlsx) and upload (csv)
    $priceChangeDataFile = $outputDir . "/sale_price_change_data_{$processDateShort}.xlsx";
    $priceChangeUploadFile = $outputDir . "/sale_price_change_upload_{$processDateShort}.csv";

    // Check if mappings exist
    if (!isset($shopConfig['Price_change_list_HeadersMapping_All4shops_Data']) ||
        !isset($shopConfig['Price_change_list_HeadersMapping_All4shops_Upload'])) {
        echo "<p>⚠️ Warning: Price_change_list_HeadersMapping_All4shops_Data or _Upload not found in shop config. Skipping price change list generation.</p>";
    } else {
        $pcDataMapping = $shopConfig['Price_change_list_HeadersMapping_All4shops_Data'];
        $pcUploadMapping = $shopConfig['Price_change_list_HeadersMapping_All4shops_Upload'];

        // Create Data spreadsheet (xlsx)
        $priceChangeDataList = new Spreadsheet();
        $priceChangeDataSheet = $priceChangeDataList->getActiveSheet();

        // Create Upload spreadsheet (csv)
        $priceChangeUploadList = new Spreadsheet();
        $priceChangeUploadSheet = $priceChangeUploadList->getActiveSheet();

        // Set headers for Data file
        foreach ($pcDataMapping as $destCol => $config) {
            $header = $config[0];
            $priceChangeDataSheet->setCellValue("{$destCol}1", $header);
        }

        // Set headers for Upload file
        foreach ($pcUploadMapping as $destCol => $config) {
            $header = $config[0];
            $priceChangeUploadSheet->setCellValue("{$destCol}1", $header);
        }

        // Delete existing files if they exist
        foreach ([$priceChangeDataFile, $priceChangeUploadFile] as $fileToDelete) {
            if (file_exists($fileToDelete)) {
                if (!@unlink($fileToDelete)) {
                    die("❌ <strong>Error: Unable to delete existing Price Change file</strong><br><br>" .
                        "📄 <strong>File:</strong> " . htmlspecialchars(basename($fileToDelete)) . "<br><br>" .
                        "⚠️ <strong>Possible cause:</strong><br>" .
                        "• The file is currently open in Excel or another program<br><br>" .
                        "💡 <strong>Solution:</strong> Please close the file in Excel and try again.");
                }
            }
        }

        $pcRowIndex = 1; // Row index counter starting at 1
        $pcDestRow = 2; // Start from row 2 in destination

        // Process each PC file
        foreach ($pcFiles as $pcFile) {
            // Load PC file
            try {
                $pcSpreadsheet = IOFactory::load($pcFile['path']);
                $pcSheet = $pcSpreadsheet->getActiveSheet();
            } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
                die("❌ <strong>Error: Unable to read PC file</strong><br><br>" .
                    "📄 <strong>File:</strong> " . htmlspecialchars(basename($pcFile['path'])) . "<br><br>" .
                    "⚠️ <strong>Possible causes:</strong><br>" .
                    "• The file is currently open in Excel or another program<br>" .
                    "• The file is corrupted or not a valid Excel file<br>" .
                    "• The file is locked by another process<br><br>" .
                    "💡 <strong>Solution:</strong> Please close the file in Excel and try again.<br><br>" .
                    "🔧 <strong>Technical details:</strong> " . htmlspecialchars($e->getMessage()));
            }
            $highestRow = $pcSheet->getHighestRow();

            // Process each row in PC file (starting from row 2)
            for ($sourceRow = 2; $sourceRow <= $highestRow; $sourceRow++) {
                // Check if row is empty (check first few columns)
                $isEmpty = true;
                foreach (['A', 'B', 'C', 'D'] as $col) {
                    if (!empty($pcSheet->getCell($col . $sourceRow)->getValue())) {
                        $isEmpty = false;
                        break;
                    }
                }

                if ($isEmpty) {
                    continue; // Skip empty rows
                }

                // Process Data file mapping
                foreach ($pcDataMapping as $destCol => $config) {
                    $header = $config[0];
                    $originCol = $config[1];
                    $dataType = $config[2];

                    if ($originCol === 0) {
                        if ($destCol === 'A') {
                            $priceChangeDataSheet->setCellValue("{$destCol}{$pcDestRow}", $pcRowIndex);
                        }
                        continue;
                    }

                    $value = $pcSheet->getCell("{$originCol}{$sourceRow}")->getValue();

                    if ($dataType === 'T') {
                        $priceChangeDataSheet->setCellValueExplicit("{$destCol}{$pcDestRow}", $value, DataType::TYPE_STRING);
                    } elseif ($dataType === 'D') {
                        $priceChangeDataSheet->setCellValue("{$destCol}{$pcDestRow}", (float)$value);
                        $priceChangeDataSheet->getStyle("{$destCol}{$pcDestRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                    } elseif ($dataType === 'I') {
                        $priceChangeDataSheet->setCellValue("{$destCol}{$pcDestRow}", (int)$value);
                        $priceChangeDataSheet->getStyle("{$destCol}{$pcDestRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
                    } elseif ($dataType === 'P') {
                        $percentValue = str_replace('%', '', $value);
                        $percentValue = str_replace(',', '.', $percentValue);
                        $percentDecimal = floatval($percentValue) / 100;
                        $priceChangeDataSheet->setCellValue("{$destCol}{$pcDestRow}", $percentDecimal);
                        $priceChangeDataSheet->getStyle("{$destCol}{$pcDestRow}")->getNumberFormat()->setFormatCode('0.00%');
                    } else {
                        $priceChangeDataSheet->setCellValue("{$destCol}{$pcDestRow}", $value);
                    }
                }

                // Process Upload file mapping
                foreach ($pcUploadMapping as $destCol => $config) {
                    $header = $config[0];
                    $originCol = $config[1];
                    $dataType = $config[2];

                    if ($originCol === 0) {
                        if ($destCol === 'A') {
                            $priceChangeUploadSheet->setCellValue("{$destCol}{$pcDestRow}", $pcRowIndex);
                        }
                        // Column C (Qty) is 0 in mapping - leave empty or set to 0
                        continue;
                    }

                    $value = $pcSheet->getCell("{$originCol}{$sourceRow}")->getValue();

                    if ($dataType === 'T') {
                        $priceChangeUploadSheet->setCellValueExplicit("{$destCol}{$pcDestRow}", $value, DataType::TYPE_STRING);
                    } elseif ($dataType === 'D') {
                        $priceChangeUploadSheet->setCellValue("{$destCol}{$pcDestRow}", (float)$value);
                        $priceChangeUploadSheet->getStyle("{$destCol}{$pcDestRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                    } elseif ($dataType === 'I') {
                        $priceChangeUploadSheet->setCellValue("{$destCol}{$pcDestRow}", (int)$value);
                        $priceChangeUploadSheet->getStyle("{$destCol}{$pcDestRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
                    } else {
                        $priceChangeUploadSheet->setCellValue("{$destCol}{$pcDestRow}", $value);
                    }
                }

                $pcRowIndex++;
                $pcDestRow++;
                $pcTotalRows++;
            }
        }

        // Auto-size all columns in Data file
        foreach (array_keys($pcDataMapping) as $col) {
            $priceChangeDataSheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Auto-size all columns in Upload file
        foreach (array_keys($pcUploadMapping) as $col) {
            $priceChangeUploadSheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Save Data file (xlsx)
        try {
            $writer = new Xlsx($priceChangeDataList);
            $writer->save($priceChangeDataFile);
        } catch (\PhpOffice\PhpSpreadsheet\Writer\Exception $e) {
            die("❌ <strong>Error: Unable to save Price Change Data file</strong><br><br>" .
                "📄 <strong>File:</strong> " . htmlspecialchars(basename($priceChangeDataFile)) . "<br><br>" .
                "⚠️ <strong>Possible causes:</strong><br>" .
                "• The file is currently open in Excel or another program<br>" .
                "• The file is locked by another process<br>" .
                "• Insufficient disk space or permissions<br><br>" .
                "💡 <strong>Solution:</strong> Please close the file in Excel and try again.<br><br>" .
                "🔧 <strong>Technical details:</strong> " . htmlspecialchars($e->getMessage()));
        }

        // Save Upload file (csv)
        try {
            $writer = new Csv($priceChangeUploadList);
            $writer->setDelimiter(',');
            $writer->setEnclosure('"');
            $writer->setLineEnding("\r\n");
            $writer->setSheetIndex(0);
            $writer->save($priceChangeUploadFile);
        } catch (\PhpOffice\PhpSpreadsheet\Writer\Exception $e) {
            die("❌ <strong>Error: Unable to save Price Change Upload file</strong><br><br>" .
                "📄 <strong>File:</strong> " . htmlspecialchars(basename($priceChangeUploadFile)) . "<br><br>" .
                "⚠️ <strong>Possible causes:</strong><br>" .
                "• The file is currently open in Excel or another program<br>" .
                "• The file is locked by another process<br>" .
                "• Insufficient disk space or permissions<br><br>" .
                "💡 <strong>Solution:</strong> Please close the file in Excel and try again.<br><br>" .
                "🔧 <strong>Technical details:</strong> " . htmlspecialchars($e->getMessage()));
        }

        echo "<p>✅ Price change data file created: <strong>sale_price_change_data_{$processDateShort}.xlsx</strong></p>";
        echo "<p>✅ Price change upload file created: <strong>sale_price_change_upload_{$processDateShort}.csv</strong></p>";
        echo "<p>📊 Total price changes: <strong>{$pcTotalRows}</strong></p>";
    }
} else {
    // Comax: Generate single xlsx file
    $priceChangeListFile = $outputDir . "/price_change_list_{$processDateShort}.xlsx";
    $priceChangeList = new Spreadsheet();
    $priceChangeSheet = $priceChangeList->getActiveSheet();

    // Get Price_change_list mapping
    if (!isset($shopConfig['Price_change_list_HeadersMapping_Comax'])) {
        echo "<p>⚠️ Warning: Price_change_list_HeadersMapping_Comax not found in shop config. Skipping price change list generation.</p>";
    } else {
        $pcMapping = $shopConfig['Price_change_list_HeadersMapping_Comax'];

        // Set headers
        foreach ($pcMapping as $destCol => $config) {
            $header = $config[0];
            $priceChangeSheet->setCellValue("{$destCol}1", $header);
        }

        // Delete existing file if it exists
        if (file_exists($priceChangeListFile)) {
            if (!@unlink($priceChangeListFile)) {
                die("❌ <strong>Error: Unable to delete existing Price Change List file</strong><br><br>" .
                    "📄 <strong>File:</strong> " . htmlspecialchars(basename($priceChangeListFile)) . "<br><br>" .
                    "⚠️ <strong>Possible cause:</strong><br>" .
                    "• The file is currently open in Excel or another program<br><br>" .
                    "💡 <strong>Solution:</strong> Please close the file in Excel and try again.");
            }
        }

        $pcRowIndex = 1; // Row index counter starting at 1
        $pcDestRow = 2; // Start from row 2 in destination

        // Process each PC file
        foreach ($pcFiles as $pcFile) {
            // Load PC file
            try {
                $pcSpreadsheet = IOFactory::load($pcFile['path']);
                $pcSheet = $pcSpreadsheet->getActiveSheet();
            } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
                die("❌ <strong>Error: Unable to read PC file</strong><br><br>" .
                    "📄 <strong>File:</strong> " . htmlspecialchars(basename($pcFile['path'])) . "<br><br>" .
                    "⚠️ <strong>Possible causes:</strong><br>" .
                    "• The file is currently open in Excel or another program<br>" .
                    "• The file is corrupted or not a valid Excel file<br>" .
                    "• The file is locked by another process<br><br>" .
                    "💡 <strong>Solution:</strong> Please close the file in Excel and try again.<br><br>" .
                    "🔧 <strong>Technical details:</strong> " . htmlspecialchars($e->getMessage()));
            }
            $highestRow = $pcSheet->getHighestRow();

            // Process each row in PC file (starting from row 2)
            for ($sourceRow = 2; $sourceRow <= $highestRow; $sourceRow++) {
                // Check if row is empty (check first few columns)
                $isEmpty = true;
                foreach (['A', 'B', 'C', 'D'] as $col) {
                    if (!empty($pcSheet->getCell($col . $sourceRow)->getValue())) {
                        $isEmpty = false;
                        break;
                    }
                }

                if ($isEmpty) {
                    continue; // Skip empty rows
                }

                foreach ($pcMapping as $destCol => $config) {
                    $header = $config[0];
                    $originCol = $config[1];
                    $dataType = $config[2];

                    // Handle origin column = 0 (special cases)
                    if ($originCol === 0) {
                        // Column A: Auto-generate row number
                        if ($destCol === 'A') {
                            $priceChangeSheet->setCellValue("{$destCol}{$pcDestRow}", $pcRowIndex);
                        }
                        continue;
                    }

                    // Get value from PC file
                    $cellAddress = "{$originCol}{$sourceRow}";
                    $value = $pcSheet->getCell($cellAddress)->getValue();

                    // Apply data type formatting
                    if ($dataType === 'T') {
                        // Text
                        $priceChangeSheet->setCellValueExplicit("{$destCol}{$pcDestRow}", $value, DataType::TYPE_STRING);
                    } elseif ($dataType === 'D') {
                        // Double
                        $priceChangeSheet->setCellValue("{$destCol}{$pcDestRow}", (float)$value);
                        $priceChangeSheet->getStyle("{$destCol}{$pcDestRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                    } elseif ($dataType === 'I') {
                        // Integer
                        $priceChangeSheet->setCellValue("{$destCol}{$pcDestRow}", (int)$value);
                        $priceChangeSheet->getStyle("{$destCol}{$pcDestRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
                    } elseif ($dataType === 'P') {
                        // Percentage
                        // Remove % sign if present and convert to decimal
                        $percentValue = str_replace('%', '', $value);
                        $percentValue = str_replace(',', '.', $percentValue);
                        $percentDecimal = floatval($percentValue) / 100;
                        $priceChangeSheet->setCellValue("{$destCol}{$pcDestRow}", $percentDecimal);
                        $priceChangeSheet->getStyle("{$destCol}{$pcDestRow}")->getNumberFormat()->setFormatCode('0.00%');
                    } else {
                        // No specific formatting
                        $priceChangeSheet->setCellValue("{$destCol}{$pcDestRow}", $value);
                    }
                }

                $pcRowIndex++;
                $pcDestRow++;
                $pcTotalRows++;
            }
        }

        // Auto-size all columns
        foreach (array_keys($pcMapping) as $col) {
            $priceChangeSheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Save Price_change_list file
        try {
            $writer = new Xlsx($priceChangeList);
            $writer->save($priceChangeListFile);
        } catch (\PhpOffice\PhpSpreadsheet\Writer\Exception $e) {
            die("❌ <strong>Error: Unable to save Price Change List file</strong><br><br>" .
                "📄 <strong>File:</strong> " . htmlspecialchars(basename($priceChangeListFile)) . "<br><br>" .
                "⚠️ <strong>Possible causes:</strong><br>" .
                "• The file is currently open in Excel or another program<br>" .
                "• The file is locked by another process<br>" .
                "• Insufficient disk space or permissions<br><br>" .
                "💡 <strong>Solution:</strong> Please close the file in Excel and try again.<br><br>" .
                "🔧 <strong>Technical details:</strong> " . htmlspecialchars($e->getMessage()));
        }

        echo "<p>✅ Price change list created: <strong>price_change_list_{$processDateShort}.xlsx</strong></p>";
        echo "<p>📊 Total price changes: <strong>{$pcTotalRows}</strong></p>";
    }
}

echo "<hr>";

// ============================================
// STEP 4: Generate New_products_list file
// ============================================

$newProductsListFile = $outputDir . "/new_products_list_{$processDateShort}.xlsx";
$newProductsList = new Spreadsheet();
$newProductsSheet = $newProductsList->getActiveSheet();

// Get New_products_list mapping based on BackOfficeType
$npMappingKey = ($backOfficeType === 'All4shops') ? 'New_products_list_HeadersMapping_All4shops' : 'New_products_list_HeadersMapping_Comax';

if (!isset($shopConfig[$npMappingKey])) {
    echo "<p>⚠️ Warning: {$npMappingKey} not found in shop config. Skipping new products list generation.</p>";
} else {
    $npMapping = $shopConfig[$npMappingKey];

    // Set headers
    foreach ($npMapping as $destCol => $config) {
        $header = $config[0];
        $newProductsSheet->setCellValue("{$destCol}1", $header);
    }

    // Delete existing file if it exists
    if (file_exists($newProductsListFile)) {
        if (!@unlink($newProductsListFile)) {
            die("❌ <strong>Error: Unable to delete existing New Products List file</strong><br><br>" .
                "📄 <strong>File:</strong> " . htmlspecialchars(basename($newProductsListFile)) . "<br><br>" .
                "⚠️ <strong>Possible cause:</strong><br>" .
                "• The file is currently open in Excel or another program<br><br>" .
                "💡 <strong>Solution:</strong> Please close the file in Excel and try again.");
        }
    }

    $npDestRow = 2; // Start from row 2 in destination
    $npTotalRows = 0;

    // Process each NP file
    foreach ($npFiles as $npFile) {
        // Load NP file
        try {
            $npSpreadsheet = IOFactory::load($npFile['path']);
            $npSheet = $npSpreadsheet->getActiveSheet();
        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            die("❌ <strong>Error: Unable to read NP file</strong><br><br>" .
                "📄 <strong>File:</strong> " . htmlspecialchars(basename($npFile['path'])) . "<br><br>" .
                "⚠️ <strong>Possible causes:</strong><br>" .
                "• The file is currently open in Excel or another program<br>" .
                "• The file is corrupted or not a valid Excel file<br>" .
                "• The file is locked by another process<br><br>" .
                "💡 <strong>Solution:</strong> Please close the file in Excel and try again.<br><br>" .
                "🔧 <strong>Technical details:</strong> " . htmlspecialchars($e->getMessage()));
        }
        $highestRow = $npSheet->getHighestRow();

        // Process each row in NP file (starting from row 2)
        for ($sourceRow = 2; $sourceRow <= $highestRow; $sourceRow++) {
            // Check if row is empty by checking the key source columns used in the mapping
            // Column D (Item Number/Barcode), F (Item Name), G (Purchase Price), K (Sale Price)
            $isEmpty = true;
            foreach (['D', 'F', 'G', 'K'] as $col) {
                $value = $npSheet->getCell($col . $sourceRow)->getValue();
                if (!empty($value) && $value !== 0 && $value !== '0') {
                    $isEmpty = false;
                    break;
                }
            }

            if ($isEmpty) {
                continue; // Skip empty rows
            }

            foreach ($npMapping as $destCol => $config) {
                $header = $config[0];
                $originCol = $config[1];
                $dataType = $config[2];

                // Handle origin column = 0 (special cases for NP)
                if ($originCol === 0) {
                    // Column E: SupplierCode from NP column N
                    if ($destCol === 'E') {
                        $supplierCodeValue = $npSheet->getCell('N' . $sourceRow)->getValue();
                        $newProductsSheet->setCellValue("{$destCol}{$npDestRow}", (int)$supplierCodeValue);
                        $newProductsSheet->getStyle("{$destCol}{$npDestRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
                    }
                    // Column G: DepartmentCode from NP column O
                    elseif ($destCol === 'G') {
                        $deptCodeValue = $npSheet->getCell('O' . $sourceRow)->getValue();
                        $newProductsSheet->setCellValue("{$destCol}{$npDestRow}", (int)$deptCodeValue);
                        $newProductsSheet->getStyle("{$destCol}{$npDestRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
                    }
                    continue;
                }

                // Get value from NP file
                $cellAddress = "{$originCol}{$sourceRow}";
                $value = $npSheet->getCell($cellAddress)->getValue();

                // Apply data type formatting
                if ($dataType === 'T') {
                    // Text
                    $newProductsSheet->setCellValueExplicit("{$destCol}{$npDestRow}", $value, DataType::TYPE_STRING);
                } elseif ($dataType === 'D') {
                    // Double
                    $newProductsSheet->setCellValue("{$destCol}{$npDestRow}", (float)$value);
                    $newProductsSheet->getStyle("{$destCol}{$npDestRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
                } elseif ($dataType === 'I') {
                    // Integer
                    $newProductsSheet->setCellValue("{$destCol}{$npDestRow}", (int)$value);
                    $newProductsSheet->getStyle("{$destCol}{$npDestRow}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
                } elseif ($dataType === 'P') {
                    // Percentage
                    $percentValue = str_replace('%', '', $value);
                    $percentValue = str_replace(',', '.', $percentValue);
                    $percentDecimal = floatval($percentValue) / 100;
                    $newProductsSheet->setCellValue("{$destCol}{$npDestRow}", $percentDecimal);
                    $newProductsSheet->getStyle("{$destCol}{$npDestRow}")->getNumberFormat()->setFormatCode('0.00%');
                } else {
                    // No specific formatting
                    $newProductsSheet->setCellValue("{$destCol}{$npDestRow}", $value);
                }
            }

            $npDestRow++;
            $npTotalRows++;
        }
    }

    // Auto-size all columns
    foreach (array_keys($npMapping) as $col) {
        $newProductsSheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Save New_products_list file
    try {
        $writer = new Xlsx($newProductsList);
        $writer->save($newProductsListFile);
    } catch (\PhpOffice\PhpSpreadsheet\Writer\Exception $e) {
        die("❌ <strong>Error: Unable to save New Products List file</strong><br><br>" .
            "📄 <strong>File:</strong> " . htmlspecialchars(basename($newProductsListFile)) . "<br><br>" .
            "⚠️ <strong>Possible causes:</strong><br>" .
            "• The file is currently open in Excel or another program<br>" .
            "• The file is locked by another process<br>" .
            "• Insufficient disk space or permissions<br><br>" .
            "💡 <strong>Solution:</strong> Please close the file in Excel and try again.<br><br>" .
            "🔧 <strong>Technical details:</strong> " . htmlspecialchars($e->getMessage()));
    }

    echo "<p>✅ New products list created: <strong>{$newProductsListFile}</strong></p>";
    echo "<p>📊 Total new products: <strong>{$npTotalRows}</strong></p>";
}

echo "<hr>";
echo "<h2>✨ BOpack Process Complete!</h2>";
echo "<p>📋 Invoice list: <strong>1 file</strong></p>";
echo "<p>📦 Invoices to upload: <strong>{$invoicesToUploadCount} files</strong></p>";
echo "<p>💰 Price changes: <strong>{$pcTotalRows} rows</strong></p>";
echo "<p>🆕 New products: <strong>{$npTotalRows} rows</strong></p>";
echo "<p>📁 Output directory: <strong>{$outputDir}</strong></p>";
echo "<hr>";
echo '<p><a href="bopack_start.php">← חזור למסך הפתיחה</a></p>';
?>

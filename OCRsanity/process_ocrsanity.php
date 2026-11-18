<?php
// Check if PhpSpreadsheet is available
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;

/**
 * Retailomatics Invoice Scan (InvoiceScan/approve.php) reads the whole page with the AI and
 * lets the user approve the invoice type and the columns; it leaves them in the session as
 * harmonizedFlow.scanOverride = ['method' => ..., 'mapping' => [field => property number]].
 * When that is there, it replaces the supplier's own OCRsanityMethod / jsonToOcrSanity for
 * this run (and no auto-detection). Without it — the old flow — nothing changes.
 */
function applyInvoiceScanOverride($supplierConfig) {
    $override = $_SESSION['harmonizedFlow']['scanOverride'] ?? null;
    if ($supplierConfig === null || !is_array($override) || empty($override['method']) || empty($override['mapping'])) {
        return $supplierConfig;
    }
    $supplierConfig['OCRsanityMethod'] = $override['method'];
    $supplierConfig['jsonToOcrSanity'] = $override['mapping'];
    $supplierConfig['autoDetectMapping'] = false;
    return $supplierConfig;
}

/**
 * Apply sanity verification to the OCRsanity spreadsheet
 *
 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet The worksheet to verify
 * @param string $method The sanity method to use (Simple, LineTotal, NoLineTotal, Discount1, Discount2)
 */
function applySanityVerification($sheet, $method) {
    // Get the highest row with data
    $highestRow = $sheet->getHighestRow();

    $totalDifference = 0;

    // Apply verification based on method
    switch ($method) {
        case 'Simple':
            applyDataTypeVerification($sheet, $highestRow);
            applyBarcodeSanityVerification($sheet, $highestRow);
            break;

        case 'LineTotal':
            applyDataTypeVerification($sheet, $highestRow);
            applyBarcodeSanityVerification($sheet, $highestRow);
            applyLineSumVerification($sheet, $highestRow);
            $totalDifference = applyTotalSumVerification($sheet, $highestRow);
            break;

        case 'Discount1':
            applyDataTypeVerification($sheet, $highestRow);
            applyBarcodeSanityVerification($sheet, $highestRow);
            applyDiscount1CalcVerification($sheet, $highestRow);
            $totalDifference = applyTotalSumVerification($sheet, $highestRow);
            break;

        case 'Discount2':
            applyDataTypeVerification($sheet, $highestRow);
            applyBarcodeSanityVerification($sheet, $highestRow);
            applyDiscount2CalcVerification($sheet, $highestRow);
            $totalDifference = applyTotalSumVerification($sheet, $highestRow);
            break;

        // Future methods will be added here
        case 'NoLineTotal':
            // To be implemented
            break;
    }

    return $totalDifference;
}

/**
 * DataType Verification: Check columns F-J for numeric values
 * Non-numeric values get light purple background
 */
function applyDataTypeVerification($sheet, $highestRow) {
    $columnsToCheck = ['F', 'G', 'H', 'I', 'J'];

    for ($row = 2; $row <= $highestRow; $row++) {
        foreach ($columnsToCheck as $column) {
            $cell = $sheet->getCell("{$column}{$row}");
            $value = $cell->getValue();

            // Skip empty cells
            if ($value === null || $value === '') {
                continue;
            }

            // Check if value is numeric
            if (!is_numeric($value)) {
                // Paint cell background light purple
                $cell->getStyle()->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFE6CCE6'); // Light purple
            }
        }
    }
}

/**
 * BarcodeSanity Verification: Check column D for valid barcodes
 * - Must be string with only digits
 * - Must not exceed 13 digits
 * Invalid barcodes get light yellow background
 */
function applyBarcodeSanityVerification($sheet, $highestRow) {
    for ($row = 2; $row <= $highestRow; $row++) {
        $cell = $sheet->getCell("D{$row}");
        $value = $cell->getValue();

        // Check if cell is empty
        if ($value === null || $value === '') {
            // Paint empty barcode cells light yellow
            $cell->getStyle()->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFFFFFCC'); // Light yellow
            continue;
        }

        // Convert to string for validation
        $valueStr = (string)$value;

        // Check if contains only digits
        if (!ctype_digit($valueStr)) {
            // Paint cell background light yellow (non-digit characters)
            $cell->getStyle()->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFFFFFCC'); // Light yellow
            continue;
        }

        // Check if length exceeds 13 digits
        if (strlen($valueStr) > 13) {
            // Paint cell background light yellow (too many digits)
            $cell->getStyle()->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFFFFFCC'); // Light yellow
        }
    }
}

/**
 * LineSum Verification: Check if Qty * UnitPrice = LineTotal
 * For each row: F * G should equal H
 * Invalid rows get colored border on cell H:
 * - Orange border: diff <= 0.1 ILS
 * - Red border: diff > 0.1 ILS
 */
function applyLineSumVerification($sheet, $highestRow) {
    for ($row = 2; $row <= $highestRow; $row++) {
        $qtyCell = $sheet->getCell("F{$row}");
        $unitPriceCell = $sheet->getCell("G{$row}");
        $lineTotalCell = $sheet->getCell("H{$row}");

        $qty = $qtyCell->getValue();
        $unitPrice = $unitPriceCell->getValue();
        $lineTotal = $lineTotalCell->getValue();

        // Skip empty rows
        if (($qty === null || $qty === '') && ($unitPrice === null || $unitPrice === '') && ($lineTotal === null || $lineTotal === '')) {
            continue;
        }

        // Check if F and G are numeric
        if (!is_numeric($qty) || !is_numeric($unitPrice)) {
            // Set red border on H cell (invalid data)
            $lineTotalCell->getStyle()->getBorders()->getOutline()
                ->setBorderStyle(Border::BORDER_THICK)
                ->setColor(new Color('FFFF0000')); // Red
            continue;
        }

        // Calculate expected line total
        $expectedTotal = round((float)$qty * (float)$unitPrice, 2);
        $actualTotal = round((float)$lineTotal, 2);
        $diff = abs($expectedTotal - $actualTotal);

        // Compare with tolerance for floating point
        if ($diff > 0.01) {
            // Determine border color based on diff amount
            if ($diff <= 0.1) {
                // Orange border for small differences (≤ 0.1 ILS)
                $lineTotalCell->getStyle()->getBorders()->getOutline()
                    ->setBorderStyle(Border::BORDER_THICK)
                    ->setColor(new Color('FFFFA500')); // Orange
            } else {
                // Red border for larger differences (> 0.1 ILS)
                $lineTotalCell->getStyle()->getBorders()->getOutline()
                    ->setBorderStyle(Border::BORDER_THICK)
                    ->setColor(new Color('FFFF0000')); // Red
            }
        }
    }
}

/**
 * Discount1Calc Verification: Check if Qty × UnitPrice × (1 - Discount1/100) = LineTotal
 * Formula: F × G × (1 - I/100) = H
 * Invalid rows get colored border on cell H:
 * - Orange border: diff <= 0.1 ILS
 * - Red border: diff > 0.1 ILS
 */
function applyDiscount1CalcVerification($sheet, $highestRow) {
    for ($row = 2; $row <= $highestRow; $row++) {
        $qtyCell = $sheet->getCell("F{$row}");
        $unitPriceCell = $sheet->getCell("G{$row}");
        $lineTotalCell = $sheet->getCell("H{$row}");
        $discount1Cell = $sheet->getCell("I{$row}");

        $qty = $qtyCell->getValue();
        $unitPrice = $unitPriceCell->getValue();
        $lineTotal = $lineTotalCell->getValue();
        $discount1 = $discount1Cell->getValue();

        // Skip empty rows
        if (($qty === null || $qty === '') && ($unitPrice === null || $unitPrice === '') && ($lineTotal === null || $lineTotal === '')) {
            continue;
        }

        // Check if F, G, and I are numeric
        if (!is_numeric($qty) || !is_numeric($unitPrice) || !is_numeric($discount1)) {
            // Set red border on H cell explicitly (invalid data)
            $sheet->getCell("H{$row}")->getStyle()->getBorders()->getTop()
                ->setBorderStyle(Border::BORDER_THICK)->setColor(new Color('FFFF0000'));
            $sheet->getCell("H{$row}")->getStyle()->getBorders()->getBottom()
                ->setBorderStyle(Border::BORDER_THICK)->setColor(new Color('FFFF0000'));
            $sheet->getCell("H{$row}")->getStyle()->getBorders()->getLeft()
                ->setBorderStyle(Border::BORDER_THICK)->setColor(new Color('FFFF0000'));
            $sheet->getCell("H{$row}")->getStyle()->getBorders()->getRight()
                ->setBorderStyle(Border::BORDER_THICK)->setColor(new Color('FFFF0000'));
            continue;
        }

        // Calculate expected line total with discount: Qty × UnitPrice × (1 - Discount1/100)
        $expectedTotal = round((float)$qty * (float)$unitPrice * (1 - (float)$discount1 / 100), 2);
        $actualTotal = round((float)$lineTotal, 2);
        $diff = abs($expectedTotal - $actualTotal);

        // Compare with tolerance for floating point
        if ($diff > 0.01) {
            // Determine border color based on diff amount
            $borderColor = ($diff <= 0.1) ? 'FFFFA500' : 'FFFF0000'; // Orange or Red

            // Set colored border on H cell explicitly
            $sheet->getCell("H{$row}")->getStyle()->getBorders()->getTop()
                ->setBorderStyle(Border::BORDER_THICK)->setColor(new Color($borderColor));
            $sheet->getCell("H{$row}")->getStyle()->getBorders()->getBottom()
                ->setBorderStyle(Border::BORDER_THICK)->setColor(new Color($borderColor));
            $sheet->getCell("H{$row}")->getStyle()->getBorders()->getLeft()
                ->setBorderStyle(Border::BORDER_THICK)->setColor(new Color($borderColor));
            $sheet->getCell("H{$row}")->getStyle()->getBorders()->getRight()
                ->setBorderStyle(Border::BORDER_THICK)->setColor(new Color($borderColor));
        }
    }
}

/**
 * Discount2Calc Verification: Check if Qty × UnitPrice / (1 - Discount1/100) / (1 - Discount2/100) = LineTotal
 * Formula: F × G / (1 - I/100) / (1 - J/100) = H
 * Invalid rows get colored border on cell H:
 * - Orange border: diff <= 0.1 ILS
 * - Red border: diff > 0.1 ILS
 */
function applyDiscount2CalcVerification($sheet, $highestRow) {
    for ($row = 2; $row <= $highestRow; $row++) {
        $qtyCell = $sheet->getCell("F{$row}");
        $unitPriceCell = $sheet->getCell("G{$row}");
        $lineTotalCell = $sheet->getCell("H{$row}");
        $discount1Cell = $sheet->getCell("I{$row}");
        $discount2Cell = $sheet->getCell("J{$row}");

        $qty = $qtyCell->getValue();
        $unitPrice = $unitPriceCell->getValue();
        $lineTotal = $lineTotalCell->getValue();
        $discount1 = $discount1Cell->getValue();
        $discount2 = $discount2Cell->getValue();

        // Skip empty rows
        if (($qty === null || $qty === '') && ($unitPrice === null || $unitPrice === '') && ($lineTotal === null || $lineTotal === '')) {
            continue;
        }

        // Check if F, G, I, and J are numeric
        if (!is_numeric($qty) || !is_numeric($unitPrice) || !is_numeric($discount1) || !is_numeric($discount2)) {
            // Set red border on H cell explicitly (invalid data)
            $sheet->getCell("H{$row}")->getStyle()->getBorders()->getTop()
                ->setBorderStyle(Border::BORDER_THICK)->setColor(new Color('FFFF0000'));
            $sheet->getCell("H{$row}")->getStyle()->getBorders()->getBottom()
                ->setBorderStyle(Border::BORDER_THICK)->setColor(new Color('FFFF0000'));
            $sheet->getCell("H{$row}")->getStyle()->getBorders()->getLeft()
                ->setBorderStyle(Border::BORDER_THICK)->setColor(new Color('FFFF0000'));
            $sheet->getCell("H{$row}")->getStyle()->getBorders()->getRight()
                ->setBorderStyle(Border::BORDER_THICK)->setColor(new Color('FFFF0000'));
            continue;
        }

        // Calculate expected line total with both discounts: Qty × UnitPrice / (1 - Discount1/100) / (1 - Discount2/100)
        $expectedTotal = round((float)$qty * (float)$unitPrice / (1 - (float)$discount1 / 100) / (1 - (float)$discount2 / 100), 2);
        $actualTotal = round((float)$lineTotal, 2);
        $diff = abs($expectedTotal - $actualTotal);

        // Compare with tolerance for floating point
        if ($diff > 0.01) {
            // Determine border color based on diff amount
            $borderColor = ($diff <= 0.1) ? 'FFFFA500' : 'FFFF0000'; // Orange or Red

            // Set colored border on H cell explicitly
            $sheet->getCell("H{$row}")->getStyle()->getBorders()->getTop()
                ->setBorderStyle(Border::BORDER_THICK)->setColor(new Color($borderColor));
            $sheet->getCell("H{$row}")->getStyle()->getBorders()->getBottom()
                ->setBorderStyle(Border::BORDER_THICK)->setColor(new Color($borderColor));
            $sheet->getCell("H{$row}")->getStyle()->getBorders()->getLeft()
                ->setBorderStyle(Border::BORDER_THICK)->setColor(new Color($borderColor));
            $sheet->getCell("H{$row}")->getStyle()->getBorders()->getRight()
                ->setBorderStyle(Border::BORDER_THICK)->setColor(new Color($borderColor));
        }
    }
}

/**
 * TotalSum Verification: Check if sum of column H equals B4
 * Returns the difference for display indicator
 */
function applyTotalSumVerification($sheet, $highestRow) {
    $columnHSum = 0;

    // Sum all numeric values in column H from row 2 to end
    for ($row = 2; $row <= $highestRow; $row++) {
        $cell = $sheet->getCell("H{$row}");
        $value = $cell->getValue();

        if ($value !== null && $value !== '' && is_numeric($value)) {
            $columnHSum += (float)$value;
        }
    }

    // Round to 2 decimal places
    $columnHSum = round($columnHSum, 2);

    // Get invoice total from B4
    $invoiceTotal = $sheet->getCell('B4')->getValue();
    $invoiceTotal = round((float)$invoiceTotal, 2);

    // Calculate signed difference: B4 - sum of column H
    $difference = $invoiceTotal - $columnHSum;
    $difference = round($difference, 2);

    return $difference;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['ocr_json']) && isset($_FILES['invoice_pdf'])) {

    // Get uploaded files
    $jsonFile = $_FILES['ocr_json'];
    $pdfFile = $_FILES['invoice_pdf'];

    // Validate JSON file upload
    if ($jsonFile['error'] !== UPLOAD_ERR_OK) {
        die('Error uploading JSON file');
    }

    // Validate JSON file type
    $jsonMimeType = mime_content_type($jsonFile['tmp_name']);
    $jsonExtension = strtolower(pathinfo($jsonFile['name'], PATHINFO_EXTENSION));

    if ($jsonExtension !== 'json' && !in_array($jsonMimeType, ['application/json', 'text/plain'])) {
        die('Error: The uploaded file is not a valid JSON file. Please upload a .json file.');
    }

    // Validate PDF file upload
    if ($pdfFile['error'] !== UPLOAD_ERR_OK) {
        die('Error uploading PDF file');
    }

    // Validate PDF file type
    $pdfMimeType = mime_content_type($pdfFile['tmp_name']);
    $pdfExtension = strtolower(pathinfo($pdfFile['name'], PATHINFO_EXTENSION));

    if ($pdfExtension !== 'pdf' || $pdfMimeType !== 'application/pdf') {
        die('Error: The uploaded file is not a valid PDF file. Please upload a .pdf file.');
    }

    // Read JSON file
    $jsonContent = file_get_contents($jsonFile['tmp_name']);
    $invoiceData = json_decode($jsonContent, true);

    if ($invoiceData === null) {
        die('Error: Invalid JSON file content. The file does not contain valid JSON data.');
    }

    // Extract SupplierName and ProcessDate from PDF filename
    $pdfFileName = $pdfFile['name'];

    // Remove .pdf extension
    $nameWithoutExt = pathinfo($pdfFileName, PATHINFO_FILENAME);

    // Find first space
    $firstSpace = strpos($nameWithoutExt, ' ');
    if ($firstSpace === false) {
        die('Error: PDF filename format is invalid. Expected format: "SupplierName dd-mm-yy ..." (must have at least one space)');
    }

    // Extract SupplierName (from start to first space)
    $supplierName = substr($nameWithoutExt, 0, $firstSpace);

    // Extract date part (after first space, take next 8 characters for dd-mm-yy)
    $afterFirstSpace = substr($nameWithoutExt, $firstSpace + 1);

    // Get first 8 characters as date (dd-mm-yy format)
    $datePart = substr($afterFirstSpace, 0, 8); // dd-mm-yy format

    // Validate that we have at least 8 characters for the date
    if (strlen($datePart) < 8) {
        die('Error: PDF filename format is invalid. Expected format: "SupplierName dd-mm-yy ..." (date part too short)');
    }

    // Convert dd-mm-yy to dd-mm-yyyy
    $dateParts = explode('-', $datePart);
    if (count($dateParts) !== 3) {
        die('Error: Date format in PDF filename is invalid. Expected: dd-mm-yy');
    }

    $day = $dateParts[0];
    $month = $dateParts[1];
    $year = '20' . $dateParts[2]; // Convert yy to yyyy

    $processDate = DateTime::createFromFormat('d-m-Y', "{$day}-{$month}-{$year}");
    if (!$processDate) {
        die('Error: Invalid date in PDF filename');
    }

    $processDateFormatted = $processDate->format('d-m-Y'); // dd-mm-yyyy

    // ===== EARLY VALIDATION: Check suppliers.json configuration BEFORE creating Excel file =====
    $suppliersFile = __DIR__ . '/../suppliers.json';
    $supplierConfig = null;
    $validationError = '';

    // Check if suppliers.json exists
    if (!file_exists($suppliersFile)) {
        $validationError = "Error: suppliers.json file not found";
    } else {
        $suppliers = json_decode(file_get_contents($suppliersFile), true);

        // Find supplier by name
        foreach ($suppliers as $supplier) {
            if ($supplier['supplierName'] === $supplierName) {
                $supplierConfig = $supplier;
                break;
            }
        }
        $supplierConfig = applyInvoiceScanOverride($supplierConfig);

        // Validation 1: Check if supplier exists
        if ($supplierConfig === null) {
            $validationError = "Error: Supplier '{$supplierName}' does not exist in suppliers.json config file";
        }
        // Validation 2: Check if supplier has jsonToOcrSanity (skipped when autoDetectMapping is enabled)
        elseif (!isset($supplierConfig['jsonToOcrSanity']) && !($supplierConfig['autoDetectMapping'] ?? true)) {
            $validationError = "Error: Supplier '{$supplierName}' does not have jsonToOcrSanity array";
        }
        // Validation 3: Check if sanity method is valid/implemented
        else {
            $sanityMethod = $supplierConfig['OCRsanityMethod'] ?? 'Simple';
            $validMethods = ['Simple', 'LineTotal', 'Discount1', 'Discount2'];

            if (!in_array($sanityMethod, $validMethods)) {
                $validationError = "Error: OCRsanityMethod '{$sanityMethod}' for supplier {$supplierName} is not implemented. Valid methods: " . implode(', ', $validMethods);
            }
            // Validation 4: Check required fields in jsonToOcrSanity — skipped when autoDetectMapping is enabled
            elseif (!($supplierConfig['autoDetectMapping'] ?? true)) {
                $jsonToOcrSanity = $supplierConfig['jsonToOcrSanity'];
                $requiredFields = [];

                switch ($sanityMethod) {
                    case 'Simple':
                        $requiredFields = ['Barcode', 'ItemName'];
                        break;
                    case 'LineTotal':
                        $requiredFields = ['Barcode', 'ItemName', 'Qty', 'UnitPrice', 'LineTotal'];
                        break;
                    case 'Discount1':
                        $requiredFields = ['Barcode', 'ItemName', 'Qty', 'UnitPrice', 'LineTotal'];
                        // Note: Discount1 is optional - will be auto-filled with 0 if missing
                        break;
                    case 'Discount2':
                        $requiredFields = ['Barcode', 'ItemName', 'Qty', 'UnitPrice', 'LineTotal'];
                        // Note: Discount1 and Discount2 are optional - will be auto-filled with 0 if missing
                        break;
                }

                $missingFields = [];
                foreach ($requiredFields as $field) {
                    if (!isset($jsonToOcrSanity[$field])) {
                        $missingFields[] = $field;
                    }
                }

                if (!empty($missingFields)) {
                    $validationError = "Error: Required field(s) missing in jsonToOcrSanity for supplier {$supplierName} using '{$sanityMethod}' method: " . implode(', ', $missingFields);
                }
            }
        }
    }

    // If validation failed, show error page and stop execution
    if (!empty($validationError)) {
        echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>OCR Sanity Process - Configuration Error</title>
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
        h2 {
            color: #333;
            border-bottom: 3px solid #dc3545;
            padding-bottom: 10px;
        }
        .error-box {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        .back-link:hover {
            background: #0056b3;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h2>❌ OCR Sanity Process - Configuration Error</h2>
        <div class='error-box'>
            <strong>⚠️ Configuration Error:</strong><br>
            " . htmlspecialchars($validationError) . "
        </div>
        <p><strong>Supplier Name extracted from PDF:</strong> {$supplierName}</p>
        <p><strong>What to do:</strong></p>
        <ul>
            <li>Check that the supplier '{$supplierName}' exists in suppliers.json</li>
            <li>Verify the supplier has a valid 'jsonToOcrSanity' array</li>
            <li>Verify the supplier has a valid 'OCRsanityMethod' (Simple, LineTotal, or Discount1)</li>
            <li>Ensure all required fields are present in jsonToOcrSanity for the selected method</li>
        </ul>";

        // Check if we're in harmonized mode to provide correct back link
        if (isset($_POST['harmonized_mode']) && $_POST['harmonized_mode'] === 'true') {
            echo "<a href='../harmonizedFlow/step1_upload.php' class='back-link'>⬅️ Back to Upload</a>";
        } else {
            echo "<a href='" . htmlspecialchars($_SERVER['PHP_SELF']) . "' class='back-link'>⬅️ Back to Upload</a>";
        }

        echo "
    </div>
</body>
</html>";
        exit;
    }

    // Validation passed - proceed with Excel file creation
    // Create Excel file
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Stage 1: Add mandatory metadata with bold font

    // Column A - Labels (bold)
    $sheet->setCellValue('A1', 'InvoiceNo');
    $sheet->setCellValue('A2', 'supplierName');
    $sheet->setCellValue('A3', 'InvoiceDate');
    $sheet->setCellValue('A4', 'InvoiceTotal');
    $sheet->setCellValue('A5', 'Remark');
    $sheet->setCellValue('A6', 'Sanity Method');

    // Column C to K - Headers (bold)
    $sheet->setCellValue('C1', 'Index');
    $sheet->setCellValue('D1', 'Barcode');
    $sheet->setCellValue('E1', 'ItemName');
    $sheet->setCellValue('F1', 'Qty');
    $sheet->setCellValue('G1', 'UnitPrice');
    $sheet->setCellValue('H1', 'LineTotal');
    $sheet->setCellValue('I1', 'Discount1');
    $sheet->setCellValue('J1', 'Discount2');
    $sheet->setCellValue('K1', 'ActualUnitPrice');

    // Make all headers bold
    $sheet->getStyle('A1:A6')->getFont()->setBold(true);
    $sheet->getStyle('C1:K1')->getFont()->setBold(true);

    // Stage 2: Map JSON data to Excel

    // B1 - Invoice Number (as text)
    $invoiceNumber = $invoiceData['invoice_number'] ?? '';
    $sheet->setCellValueExplicit('B1', $invoiceNumber, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

    // B2 - Supplier Name (from PDF filename)
    $sheet->setCellValue('B2', $supplierName);

    // B3 - Invoice Date (as date)
    $invoiceDate = $invoiceData['invoice_date'] ?? '';
    if (!empty($invoiceDate)) {
        // Normalize 2-digit year to 4-digit (always 20YY — e.g. 26→2026, 99→2099)
        $normalizedDate = preg_replace('/^(\d{2})\/(\d{2})\/(\d{2})$/', '$1/$2/20$3', $invoiceDate);
        // Try to parse the date
        $dateObj = DateTime::createFromFormat('d/m/Y', $normalizedDate);
        if (!$dateObj) {
            $dateObj = DateTime::createFromFormat('Y-m-d', $invoiceDate);
        }

        if ($dateObj) {
            // Store as Excel date
            $sheet->setCellValue('B3', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($dateObj));
            $sheet->getStyle('B3')->getNumberFormat()->setFormatCode('DD-MM-YYYY');
        } else {
            $sheet->setCellValue('B3', $invoiceDate);
        }
    }

    // B4 - Invoice Total (as number)
    $invoiceTotal = $invoiceData['invoice_total'] ?? 0;
    $sheet->setCellValue('B4', floatval($invoiceTotal));
    $sheet->getStyle('B4')->getNumberFormat()->setFormatCode('#,##0.00');

    // B5 - Remark (leave empty)
    $sheet->setCellValue('B5', '');

    // B6 - Sanity Method (will be set after determining supplier config)
    // Placeholder - will be filled later after loading supplier config

    // Load supplier configuration for table mapping
    $suppliersFile = __DIR__ . '/../suppliers.json';
    $supplierConfig = null;
    $statusMessage = '';
    $rowsProcessed = 0;

    if (!file_exists($suppliersFile)) {
        $statusMessage = "Error: suppliers.json file not found";
    } else {
        $suppliers = json_decode(file_get_contents($suppliersFile), true);

        // Find supplier by name
        foreach ($suppliers as $supplier) {
            if ($supplier['supplierName'] === $supplierName) {
                $supplierConfig = $supplier;
                break;
            }
        }
        $supplierConfig = applyInvoiceScanOverride($supplierConfig);

        if ($supplierConfig === null) {
            $statusMessage = "Supplier {$supplierName} does not exist in suppliers.json config file";
        } else {
            $autoDetect    = $supplierConfig['autoDetectMapping'] ?? true;
            $sanityMethod  = $supplierConfig['OCRsanityMethod'] ?? 'Simple';
            $jsonToOcrSanity = null;

            $autoDetectNotice = ''; // will be shown as popup in verify_ocrsanity.php

            if ($autoDetect) {
                require_once __DIR__ . '/detect_json_mapping.php';
                $detectedNotices = [];
                $detectedMapping = detectJsonMapping(
                    $invoiceData['table'] ?? [],
                    $sanityMethod,
                    $detectedNotices,
                    $supplierConfig['jsonToOcrSanity'] ?? null
                );

                // Check that all required fields ended up in the mapping
                $requiredFields = ['ItemName', 'Barcode', 'Qty'];
                if (in_array($sanityMethod, ['LineTotal', 'Discount1', 'Discount2'], true)) {
                    $requiredFields = array_merge($requiredFields, ['UnitPrice', 'LineTotal']);
                }
                $missingFields = [];
                foreach ($requiredFields as $f) {
                    if (!isset($detectedMapping[$f])) $missingFields[] = $f;
                }

                if (empty($missingFields)) {
                    $jsonToOcrSanity = $detectedMapping;
                } else {
                    $statusMessage = "Cannot process {$supplierName}: required column(s) could not be mapped: " . implode(', ', $missingFields);
                }

                // Build popup: one line per field, plus summary
                $autoDetectNotice  = "Column mapping for {$supplierName} ({$sanityMethod}):\n";
                $autoDetectNotice .= implode("\n", $detectedNotices);
                if (!empty($missingFields)) {
                    $autoDetectNotice .= "\n\n❌ Cannot process — missing required field(s): " . implode(', ', $missingFields);
                }
            } elseif (isset($supplierConfig['jsonToOcrSanity'])) {
                // Validate column names in jsonToOcrSanity
                $validColumnNames = ['Barcode', 'ItemName', 'Qty', 'UnitPrice', 'LineTotal', 'Discount1', 'Discount2'];
                $jsonToOcrSanity  = $supplierConfig['jsonToOcrSanity'];
                foreach ($jsonToOcrSanity as $columnName => $propertyNumber) {
                    if (!in_array($columnName, $validColumnNames)) {
                        $statusMessage = "Column name {$columnName} in jsonToOcrSanity array of supplier {$supplierName} is not valid";
                        break;
                    }
                }
            } else {
                $statusMessage = "Supplier {$supplierName} does not have jsonToOcrSanity array";
            }

            // Process table data if mapping is available and no hard error
            if ($jsonToOcrSanity !== null && (empty($statusMessage) || str_starts_with($statusMessage, 'Warning'))) {
                $tableData = $invoiceData['table'] ?? [];

                foreach ($tableData as $rowObject) {
                    $index = $rowObject['index'] ?? null;

                    if ($index === null) {
                        continue; // Skip rows without index
                    }

                    $excelRow = $index + 1; // Row in Excel (index n -> row n+1)

                    // Write index to column C
                    $sheet->setCellValue("C{$excelRow}", $index);

                    // Map columns according to jsonToOcrSanity
                    foreach ($jsonToOcrSanity as $columnName => $propertyNumber) {
                        $propertyKey = (string)$propertyNumber;

                        if (!isset($rowObject[$propertyKey])) {
                            $statusMessage .= "Warning: Property '{$propertyNumber}' (for {$columnName}) does not exist in row {$index} of {$supplierName} invoice\n";
                            continue;
                        }

                        $value = $rowObject[$propertyKey];

                        // Special handling for ItemName - truncate to 60 characters
                        if ($columnName === 'ItemName') {
                            $value = mb_substr($value, 0, 60);
                        }

                        // Determine Excel column based on column name
                        $excelColumn = '';
                        switch ($columnName) {
                            case 'Barcode':
                                $excelColumn = 'D';
                                // Save as text to preserve long numbers (13 digits) without scientific notation
                                $sheet->setCellValueExplicit("{$excelColumn}{$excelRow}", $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                                continue 2; // Skip the setCellValue below
                                break;
                            case 'ItemName':
                                $excelColumn = 'E';
                                break;
                            case 'Qty':
                                $excelColumn = 'F';
                                // Convert to number
                                $value = is_numeric($value) ? floatval($value) : $value;
                                break;
                            case 'UnitPrice':
                                $excelColumn = 'G';
                                // Convert to number with formatting
                                if (is_numeric($value)) {
                                    $sheet->setCellValue("{$excelColumn}{$excelRow}", floatval($value));
                                    $sheet->getStyle("{$excelColumn}{$excelRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                                    continue 2; // Skip the setCellValue below
                                }
                                break;
                            case 'LineTotal':
                                $excelColumn = 'H';
                                // Convert to number with formatting
                                if (is_numeric($value)) {
                                    $sheet->setCellValue("{$excelColumn}{$excelRow}", floatval($value));
                                    $sheet->getStyle("{$excelColumn}{$excelRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                                    continue 2; // Skip the setCellValue below
                                }
                                break;
                            case 'Discount1':
                                $excelColumn = 'I';
                                // Convert to number with formatting
                                if (is_numeric($value)) {
                                    $sheet->setCellValue("{$excelColumn}{$excelRow}", floatval($value));
                                    $sheet->getStyle("{$excelColumn}{$excelRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                                    continue 2; // Skip the setCellValue below
                                }
                                break;
                            case 'Discount2':
                                $excelColumn = 'J';
                                // Convert to number with formatting
                                if (is_numeric($value)) {
                                    $sheet->setCellValue("{$excelColumn}{$excelRow}", floatval($value));
                                    $sheet->getStyle("{$excelColumn}{$excelRow}")->getNumberFormat()->setFormatCode('#,##0.00');
                                    continue 2; // Skip the setCellValue below
                                }
                                break;
                        }

                        // Write value to Excel
                        if (!empty($excelColumn)) {
                            $sheet->setCellValue("{$excelColumn}{$excelRow}", $value);
                        }
                    }

                    $rowsProcessed++;
                }

                // Set completion message
                if (empty($statusMessage)) {
                    $statusMessage = "Completed OCRsanity for {$supplierName} with {$rowsProcessed} rows";
                }
            }
        }
    }

    // Auto-fit all columns
    foreach (range('A', 'J') as $columnID) {
        $sheet->getColumnDimension($columnID)->setAutoSize(true);
    }

    // Get sanity method from supplier config (already validated earlier)
    // $jsonToOcrSanity is already set from the mapping section (auto-detected or configured)
    $sanityMethod = $supplierConfig['OCRsanityMethod'] ?? 'Simple';
    $totalDifference = 0;

    // If using Discount1 method but mapping doesn't include Discount1, fill column I with zeros
    if ($sanityMethod === 'Discount1' && isset($jsonToOcrSanity)) {

        // Check if Discount1 is NOT in the mapping
        if (!isset($jsonToOcrSanity['Discount1'])) {
            // Fill column I (Discount1) with 0 for all data rows
            $highestRow = $sheet->getHighestRow();
            for ($row = 2; $row <= $highestRow; $row++) {
                // Only fill if the row has data (check if column C has index)
                $indexValue = $sheet->getCell("C{$row}")->getValue();
                if ($indexValue !== null && $indexValue !== '') {
                    $sheet->setCellValue("I{$row}", 0);
                }
            }
        }
    }

    // Set B6 with the sanity method name
    $sheet->setCellValue('B6', $sanityMethod);

    // Apply sanity verification based on supplier's method
    $totalDifference = applySanityVerification($sheet, $sanityMethod);

    // Calculate ActualUnitPrice for column K based on sanity method
    $highestRow = $sheet->getHighestRow();
    for ($row = 2; $row <= $highestRow; $row++) {
        // Check if row has data (check if column C has index)
        $indexValue = $sheet->getCell("C{$row}")->getValue();
        if ($indexValue === null || $indexValue === '') {
            continue; // Skip empty rows
        }

        $unitPrice = $sheet->getCell("G{$row}")->getValue();
        $discount1 = $sheet->getCell("I{$row}")->getValue();

        // Calculate ActualUnitPrice based on sanity method
        $actualUnitPrice = 0;

        switch ($sanityMethod) {
            case 'Simple':
            case 'LineTotal':
                // ActualUnitPrice = UnitPrice (copy column G to column K)
                $actualUnitPrice = $unitPrice;
                break;

            case 'Discount1':
                // ActualUnitPrice = UnitPrice * (1 - Discount1/100)
                if (is_numeric($unitPrice) && is_numeric($discount1)) {
                    $actualUnitPrice = (float)$unitPrice * (1 - (float)$discount1 / 100);
                } else {
                    $actualUnitPrice = $unitPrice; // Fallback if not numeric
                }
                break;

            default:
                // For any other method, default to UnitPrice
                $actualUnitPrice = $unitPrice;
                break;
        }

        // Set ActualUnitPrice in column K
        if (is_numeric($actualUnitPrice)) {
            $sheet->setCellValue("K{$row}", (float)$actualUnitPrice);
            $sheet->getStyle("K{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        } else {
            $sheet->setCellValue("K{$row}", $actualUnitPrice);
        }
    }

    // Store total difference and sanity method in a hidden metadata sheet
    // This will be used by verify_ocrsanity.php to display the indicator
    $metaSheet = $spreadsheet->createSheet();
    $metaSheet->setTitle('_Metadata');
    $metaSheet->getCell('A1')->setValue('TotalDifference');
    $metaSheet->getCell('B1')->setValue($totalDifference);
    $metaSheet->getCell('A2')->setValue('SanityMethod');
    $metaSheet->getCell('B2')->setValue($sanityMethod);
    $metaSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

    // Set active sheet back to first sheet
    $spreadsheet->setActiveSheetIndex(0);

    // Generate filename for Excel file
    $timestamp = date('dmY_His'); // ddmmyyyy_hhmmss
    $processDateFile = $processDate->format('d-m-Y'); // dd-mm-yyyy for filename

    // Check if harmonized mode with sequence identifier
    $sequenceIdentifier = '';
    if (isset($_POST['harmonized_mode']) && $_POST['harmonized_mode'] === 'true') {
        $sequenceIdentifier = $_POST['harmonized_sequence'] ?? '';
    }

    // Include sequence identifier in filename if present
    if (!empty($sequenceIdentifier)) {
        $excelFileName = "OCRsanity_{$supplierName}_{$processDateFile}_{$sequenceIdentifier}_{$timestamp}.xlsx";
    } else {
        $excelFileName = "OCRsanity_{$supplierName}_{$processDateFile}_{$timestamp}.xlsx";
    }

    // Save directory
    $saveDirectory = __DIR__ . '/sanity_files/';
    $fullPath = $saveDirectory . $excelFileName;

    // Save Excel file
    $writer = new Xlsx($spreadsheet);
    $writer->save($fullPath);

    // Store PDF temporarily for verification (use original uploaded file)
    $tempPdfName = 'temp_' . $excelFileName . '.pdf';
    $tempPdfPath = $saveDirectory . $tempPdfName;

    // Handle PDF file upload/copy
    if (is_uploaded_file($pdfFile['tmp_name'])) {
        move_uploaded_file($pdfFile['tmp_name'], $tempPdfPath);
    } else {
        // In harmonized mode, file might already be in place, so copy it
        copy($pdfFile['tmp_name'], $tempPdfPath);
    }

    // Store OCRjson filename for later use (for "Check OCRjson file" feature)
    session_start();
    $_SESSION['ocrJsonFileName'] = $jsonFile['name'];

    // Persist auto-detect notice so verify page can show it as a popup
    $_SESSION['autoDetectNotice'] = $autoDetectNotice ?? '';

    // Check if harmonized mode for redirect
    if (isset($_POST['harmonized_mode']) && $_POST['harmonized_mode'] === 'true') {
        // Store OCRsanity file info in session
        $_SESSION['harmonizedFlow']['ocrSanityFile'] = $excelFileName;
        $_SESSION['harmonizedFlow']['ocrSanityPath'] = $fullPath;
        $_SESSION['harmonizedFlow']['step'] = 4;

        // Redirect to verify_ocrsanity.php with harmonized flag (using absolute path)
        header("Location: /website/OCRsanity/verify_ocrsanity.php?excel=" . urlencode($excelFileName) . "&pdf=" . urlencode($tempPdfName) . "&harmonized=true");
        exit;
    } else {
        // Standard mode redirect
        header("Location: verify_ocrsanity.php?excel=" . urlencode($excelFileName) . "&pdf=" . urlencode($tempPdfName));
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OCR Sanity Process</title>
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
        h2 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
        }
        .form-group {
            margin: 20px 0;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 4px;
            border-left: 4px solid #007bff;
        }
        label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
            color: #555;
        }
        input[type="file"] {
            display: block;
            width: 100%;
            padding: 10px;
            border: 2px dashed #ccc;
            border-radius: 4px;
            cursor: pointer;
            transition: border-color 0.3s;
        }
        input[type="file"]:hover {
            border-color: #007bff;
        }
        button {
            background: #28a745;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            width: 100%;
            margin-top: 20px;
            transition: background 0.3s;
        }
        button:hover {
            background: #218838;
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .info-box p {
            margin: 5px 0;
            font-size: 14px;
            color: #555;
        }
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .warning-box p {
            margin: 5px 0;
            font-size: 14px;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>📊 OCR Sanity Process</h2>

        <div class="info-box">
            <p><strong>ℹ️ Instructions:</strong></p>
            <p>This process converts OCR JSON data into a validated Excel sanity file.</p>
        </div>

        <div class="warning-box">
            <p><strong>⚠️ PDF Filename Format:</strong></p>
            <p>The invoice PDF filename must follow this format: <strong>SupplierName dd-mm-yy X.pdf</strong></p>
            <p>Examples:</p>
            <ul>
                <li>"FarmaDeal 20-10-25 A.pdf" → Supplier: FarmaDeal, Date: 20-10-2025</li>
                <li>"Globrands 07-09-25 A.pdf" → Supplier: Globrands, Date: 07-09-2025</li>
            </ul>
        </div>

        <form method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label for="ocr_json">📄 Select OCR JSON File (.json)</label>
                <input type="file" id="ocr_json" name="ocr_json" accept=".json,application/json" required>
                <small style="display:block; margin-top:10px; color:#666;">Choose the OCRjson_*.json file</small>
            </div>

            <div class="form-group">
                <label for="invoice_pdf">📑 Select Invoice PDF File (.pdf)</label>
                <input type="file" id="invoice_pdf" name="invoice_pdf" accept=".pdf,application/pdf" required>
                <small style="display:block; margin-top:10px; color:#666;">Choose the invoice PDF (filename format: SupplierName dd-mm-yy X.pdf)</small>
            </div>

            <button type="submit">🚀 Generate OCR Sanity File</button>
        </form>
    </div>
</body>
</html>

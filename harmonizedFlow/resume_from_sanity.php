<?php
/**
 * Resume from Existing OCR Sanity File
 * Loads an existing sanity file and launches the verification screen
 */

session_start();

// Get parameters
$sanityFile = $_POST['sanityFile'] ?? null;
$shopName = $_POST['shopName'] ?? null;

if (!$sanityFile || !$shopName) {
    die('Error: Missing required parameters (sanityFile or shopName)');
}

// Validate sanity file exists
$sanityFilePath = __DIR__ . '/../OCRsanity/sanity_files/' . $sanityFile;
if (!file_exists($sanityFilePath)) {
    die('Error: Sanity file not found: ' . htmlspecialchars($sanityFile));
}

// Parse sanity filename to extract invoice details
// Format: OCRsanity_XXXX_dd-mm-yyyy_Z_ddmmyyyy_hhmmss.xlsx
if (!preg_match('/^OCRsanity_(.+?)_(\d{2}-\d{2}-\d{4})_([A-Z])_(\d{8})_(\d{6})\.xlsx$/i', $sanityFile, $matches)) {
    die('Error: Invalid sanity file format: ' . htmlspecialchars($sanityFile));
}

$supplierName = $matches[1];
$processDate = $matches[2]; // dd-mm-yyyy
$invoiceId = $matches[3];
$dateStamp = $matches[4]; // ddmmyyyy
$timeStamp = $matches[5]; // hhmmss

// Store session data matching the normal flow
$_SESSION['harmonizedFlow'] = [
    'shopName' => $shopName,
    'supplierName' => $supplierName,
    'processDate' => $processDate,
    'invoiceId' => $invoiceId,
    'sanityFileName' => $sanityFile,
    'sanityFilePath' => $sanityFilePath,
    'currentStep' => 'sanity_verify',
    'resumedFromFile' => true, // Flag to indicate this is a resumed session
    // The processing day is the date in the name ("dd-mm-yyyy" = the day it was processed): the archive goes
    // back to that day's folder (cleanup_process.php), not today's.
    'workDay' => DateTime::createFromFormat('!d-m-Y', $processDate) ? DateTime::createFromFormat('!d-m-Y', $processDate)->format('Y-m-d') : '',
    'timestamp' => time()
];

// Redirect to step4_verify_ocr.php
// This screen will load the existing sanity file instead of creating a new one
header('Location: step4_verify_ocr.php');
exit;

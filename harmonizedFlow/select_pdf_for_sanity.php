<?php
/**
 * Select PDF for Existing Sanity File
 * Allows user to browse and select a PDF file to accompany the sanity file
 */

session_start();

// Check if harmonized flow session exists
if (!isset($_SESSION['harmonizedFlow'])) {
    die('Error: Harmonized flow session not found. Please start from Step 1.');
}

$flowData = $_SESSION['harmonizedFlow'];
$sanityFile = $flowData['sanityFileName'] ?? 'Unknown';
$shopName = $flowData['shopName'] ?? 'Unknown';

// Function to get all PDF files from a directory recursively
function getPdfFiles($directory, $maxDepth = 2, $currentDepth = 0) {
    $pdfFiles = [];

    if ($currentDepth >= $maxDepth || !is_dir($directory)) {
        return $pdfFiles;
    }

    $items = @scandir($directory);
    if ($items === false) {
        return $pdfFiles;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $fullPath = $directory . DIRECTORY_SEPARATOR . $item;

        if (is_file($fullPath) && strtolower(pathinfo($fullPath, PATHINFO_EXTENSION)) === 'pdf') {
            $pdfFiles[] = [
                'path' => $fullPath,
                'name' => $item,
                'dir' => basename($directory),
                'size' => filesize($fullPath),
                'modified' => filemtime($fullPath)
            ];
        } elseif (is_dir($fullPath)) {
            $subFiles = getPdfFiles($fullPath, $maxDepth, $currentDepth + 1);
            $pdfFiles = array_merge($pdfFiles, $subFiles);
        }
    }

    return $pdfFiles;
}

// Where to look: the website, or the archive on Z (finished documents are archived there —
// invoice_archive.php). It opens in the shop's Invoices folder on Z, else in the website.
require_once __DIR__ . '/invoice_archive.php';
$websiteRoot = realpath(__DIR__ . '/..');
$archiveRoot = realpath(INV_ARCHIVE_ROOT) ?: null;
$shopInvoices = realpath(INV_ARCHIVE_ROOT . '/' . basename($shopName) . '/Invoices') ?: null;
$inRoots = function ($p) use ($websiteRoot, $archiveRoot) {
    return $p && (strpos($p, $websiteRoot) === 0 || ($archiveRoot && strpos($p, $archiveRoot) === 0));
};
$currentDir = isset($_GET['dir']) ? realpath($_GET['dir']) : ($shopInvoices ?: realpath(INV_PENDING_DIR) ?: $websiteRoot);
if (!$inRoots($currentDir)) {
    $currentDir = $shopInvoices ?: $websiteRoot;
}

// Get all PDF files from current directory only (no subdirectories)
$pdfFiles = getPdfFiles($currentDir, 1);

// Sort by modification time (newest first)
usort($pdfFiles, function($a, $b) {
    return $b['modified'] - $a['modified'];
});

// Get parent directories for navigation
$parentDir = dirname($currentDir);
$canGoUp = $parentDir !== $currentDir && $inRoots($parentDir);

// Get subdirectories
$subdirectories = [];
$items = @scandir($currentDir);
if ($items !== false) {
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $fullPath = $currentDir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($fullPath)) {
            $subdirectories[] = [
                'name' => $item,
                'path' => $fullPath
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select PDF for Sanity File</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 30px auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        h1 {
            color: #333;
            border-bottom: 4px solid #667eea;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .navigation {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
        }
        .navigation strong {
            color: #667eea;
        }
        .directory-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 15px 0;
        }
        .dir-button {
            padding: 8px 15px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background 0.2s;
        }
        .dir-button:hover {
            background: #5568d3;
        }
        .dir-button.parent {
            background: #6c757d;
        }
        .dir-button.parent:hover {
            background: #5a6268;
        }
        .pdf-list {
            max-height: 500px;
            overflow-y: auto;
            border: 2px solid #ddd;
            border-radius: 6px;
            padding: 10px;
            background: #fafafa;
        }
        .pdf-item {
            padding: 15px;
            margin-bottom: 10px;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .pdf-item:hover {
            background: #f0f0f0;
            border-color: #667eea;
            transform: translateX(5px);
        }
        .pdf-item.selected {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .pdf-item input[type="radio"] {
            margin-right: 10px;
        }
        .pdf-meta {
            font-size: 12px;
            color: #666;
            margin-left: 25px;
            margin-top: 5px;
        }
        .pdf-item.selected .pdf-meta {
            color: #fff;
        }
        button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 40px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            margin-top: 20px;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        button:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        .button-group {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        .skip-button {
            background: #6c757d;
        }
        .skip-button:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📄 Select PDF for Sanity File</h1>

        <div class="info-box">
            <p><strong>ℹ️ Selected Sanity File:</strong> <?php echo htmlspecialchars($sanityFile); ?></p>
            <p><strong>Shop:</strong> <?php echo htmlspecialchars($shopName); ?></p>
            <p>Please select the corresponding PDF invoice file, or skip if PDF is not needed.</p>
        </div>

        <div class="navigation">
            <p><strong>📁 Current Directory:</strong> <?php echo htmlspecialchars(str_replace($websiteRoot, 'website', $currentDir)); ?></p>

            <?php if ($canGoUp): ?>
                <a href="?dir=<?php echo urlencode($parentDir); ?>" class="dir-button parent">⬆️ Go Up</a>
            <?php endif; ?>
            <?php if ($shopInvoices): ?>
                <a href="?dir=<?php echo urlencode($shopInvoices); ?>" class="dir-button">🗄 ארכיון החשבוניות של <?php echo htmlspecialchars($shopName); ?> (Z)</a>
            <?php endif; ?>
            <a href="?dir=<?php echo urlencode($websiteRoot); ?>" class="dir-button">🌐 website</a>

            <?php if (!empty($subdirectories)): ?>
                <div class="directory-list">
                    <strong>Subdirectories:</strong>
                    <?php foreach ($subdirectories as $dir): ?>
                        <a href="?dir=<?php echo urlencode($dir['path']); ?>" class="dir-button">
                            📁 <?php echo htmlspecialchars($dir['name']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <form id="pdfForm" method="POST" action="process_pdf_selection.php">
            <input type="hidden" name="action" value="select_pdf">

            <h3>📄 PDF Files (<?php echo count($pdfFiles); ?> found):</h3>

            <?php if (empty($pdfFiles)): ?>
                <div class="warning-box">
                    <p>⚠️ No PDF files found in this directory. Try navigating to a different directory or skip PDF selection.</p>
                </div>
            <?php else: ?>
                <div class="pdf-list">
                    <?php foreach ($pdfFiles as $pdf): ?>
                        <div class="pdf-item" onclick="selectPdf(this)">
                            <input type="radio" name="pdfFile" value="<?php echo htmlspecialchars($pdf['path']); ?>"
                                   id="pdf_<?php echo md5($pdf['path']); ?>">
                            <label for="pdf_<?php echo md5($pdf['path']); ?>"
                                   style="display: inline; font-weight: normal; cursor: pointer;">
                                <?php echo htmlspecialchars($pdf['name']); ?>
                            </label>
                            <div class="pdf-meta">
                                📂 <?php echo htmlspecialchars($pdf['dir']); ?> |
                                📏 <?php echo number_format($pdf['size'] / 1024, 1); ?> KB |
                                🕒 <?php echo date('Y-m-d H:i:s', $pdf['modified']); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="button-group">
                <button type="submit" name="submitAction" value="continue" id="continueBtn">
                    ✅ Continue with Selected PDF
                </button>
                <button type="submit" name="submitAction" value="skip" class="skip-button">
                    ⏭️ Skip PDF - Continue Without PDF
                </button>
            </div>
        </form>
    </div>

    <script>
        function selectPdf(element) {
            // Remove selected class from all items
            document.querySelectorAll('.pdf-item').forEach(item => {
                item.classList.remove('selected');
            });

            // Add selected class to clicked item
            element.classList.add('selected');

            // Check the radio button
            const radio = element.querySelector('input[type="radio"]');
            radio.checked = true;
        }

        // Form validation
        document.getElementById('pdfForm').addEventListener('submit', function(e) {
            const submitAction = document.activeElement.value;

            if (submitAction === 'continue') {
                const selectedPdf = document.querySelector('input[name="pdfFile"]:checked');
                if (!selectedPdf) {
                    alert('⚠️ Please select a PDF file or click "Skip PDF"');
                    e.preventDefault();
                    return false;
                }
            }

            return true;
        });
    </script>
</body>
</html>

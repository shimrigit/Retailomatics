<?php
// Verify OCR Sanity - Edit and Compare with PDF

session_start();
$autoDetectNotice = $_SESSION['autoDetectNotice'] ?? '';
unset($_SESSION['autoDetectNotice']); // show once

if (!isset($_GET['excel']) || !isset($_GET['pdf'])) {
    die('Error: Missing excel or pdf parameter');
}

$excelFile = basename($_GET['excel']);
$pdfFile = basename($_GET['pdf']);

// Check if we're in harmonized mode
$harmonizedMode = isset($_GET['harmonized']) && $_GET['harmonized'] === 'true';

$excelPath = __DIR__ . '/sanity_files/' . $excelFile;
$pdfPath = __DIR__ . '/sanity_files/' . $pdfFile;

// Verify files exist
if (!file_exists($excelPath)) {
    die('Error: Excel file not found');
}

// Allow PDF to be missing when resuming from existing sanity file
$pdfAvailable = file_exists($pdfPath);
if (!$pdfAvailable && $pdfFile !== 'NOT_FOUND.pdf') {
    die('Error: PDF file not found: ' . htmlspecialchars($pdfFile));
}

// Load Excel data using PhpSpreadsheet
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$spreadsheet = IOFactory::load($excelPath);
$sheet = $spreadsheet->getActiveSheet();

// Read metadata for total difference and sanity method
$totalDifference = 0;
$sanityMethod = 'Simple';
$metaSheet = $spreadsheet->getSheetByName('_Metadata');
if ($metaSheet !== null) {
    $totalDifference = (float)$metaSheet->getCell('B1')->getValue();
    $sanityMethod = $metaSheet->getCell('B2')->getValue();
    if (empty($sanityMethod)) {
        $sanityMethod = 'Simple';
    }
}

// Convert Excel data to array for JavaScript
$highestRow = $sheet->getHighestRow();
$highestColumn = $sheet->getHighestColumn();
$highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

$data = [];
$cellStyles = []; // Store cell background colors and borders
for ($row = 1; $row <= $highestRow; $row++) {
    $rowData = [];
    for ($col = 1; $col <= $highestColumnIndex; $col++) {
        $cell = $sheet->getCellByColumnAndRow($col, $row);
        $rowData[] = $cell->getFormattedValue();

        $styleData = [
            'row' => $row - 1, // 0-indexed for Handsontable
            'col' => $col - 1
        ];
        $hasStyle = false;

        // Get cell background color
        $fill = $cell->getStyle()->getFill();
        $fillType = $fill->getFillType();
        if ($fillType !== \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE) {
            $bgColor = $fill->getStartColor()->getARGB();
            // Remove FF prefix if present and convert to hex
            if (strlen($bgColor) === 8 && substr($bgColor, 0, 2) === 'FF') {
                $bgColor = '#' . substr($bgColor, 2);
            } else {
                $bgColor = '#' . $bgColor;
            }
            $styleData['backgroundColor'] = $bgColor;
            $hasStyle = true;
        }

        // Get cell border style (check if any border is thick/bold and extract color)
        $borders = $cell->getStyle()->getBorders();
        $hasBoldBorder = false;

        if ($borders->getTop()->getBorderStyle() === \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK ||
            $borders->getBottom()->getBorderStyle() === \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK ||
            $borders->getLeft()->getBorderStyle() === \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK ||
            $borders->getRight()->getBorderStyle() === \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THICK) {
            $styleData['boldBorder'] = true;

            // Extract border color from top border (all borders should have same color)
            $borderColor = $borders->getTop()->getColor()->getARGB();
            if (strlen($borderColor) === 8 && substr($borderColor, 0, 2) === 'FF') {
                $borderColor = '#' . substr($borderColor, 2);
            } else {
                $borderColor = '#' . $borderColor;
            }
            $styleData['borderColor'] = $borderColor;

            $hasStyle = true;
        }

        if ($hasStyle) {
            $cellStyles[] = $styleData;
        }
    }
    $data[] = $rowData;
}

$jsonData = json_encode($data);
$cellStylesData = json_encode($cellStyles);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OCR Sanity</title>

    <!-- Handsontable CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/handsontable@14.0.0/dist/handsontable.full.min.css">

    <!-- PDF.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            overflow: hidden;
        }

        .header {
            background: #007bff;
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 20px;
        }

        .header-buttons {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .total-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            padding: 8px 15px;
            border-radius: 4px;
            color: #333;
        }

        .indicator-label {
            font-size: 14px;
            font-weight: bold;
        }

        .indicator-value {
            display: inline-block;
            min-width: 50px;
            padding: 6px 12px;
            border-radius: 4px;
            text-align: center;
            font-weight: bold;
            font-size: 16px;
            border: 2px solid;
        }

        .indicator-green {
            background-color: #28a745;
            border-color: #28a745;
            color: white;
        }

        .indicator-orange {
            background-color: #ff9800;
            border-color: #ff9800;
            color: white;
        }

        .indicator-red {
            background-color: #dc3545;
            border-color: #dc3545;
            color: white;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }

        .btn-save {
            background: #28a745;
            color: white;
        }

        .btn-save:hover {
            background: #218838;
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
        }

        .btn-cancel:hover {
            background: #5a6268;
        }

        .btn-check-linetotal {
            background: #6f42c1;
            color: white;
        }

        .btn-check-linetotal:hover {
            background: #5a32a3;
        }

        .btn-check-json {
            background: #17a2b8;
            color: white;
        }

        .btn-check-json:hover {
            background: #138496;
        }

        /* Delete dialog styles */
        .delete-dialog-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }

        .delete-dialog-overlay.active {
            display: flex;
        }

        .delete-dialog {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3);
            min-width: 300px;
        }

        .delete-dialog h3 {
            margin: 0 0 15px 0;
            font-size: 16px;
        }

        .delete-dialog-options {
            margin: 15px 0;
        }

        .delete-dialog-options label {
            display: block;
            margin: 8px 0;
            cursor: pointer;
        }

        .delete-dialog-options input[type="radio"] {
            margin-right: 8px;
        }

        .delete-dialog-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 15px;
        }

        .delete-dialog-buttons button {
            padding: 6px 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .delete-dialog-buttons .btn-ok {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }

        .delete-dialog-buttons .btn-cancel {
            background: #f8f9fa;
            color: #333;
        }

        .container {
            display: flex;
            height: calc(100vh - 140px);
            margin-bottom: 20px;
        }

        .left-panel {
            width: 50%;
            border-right: 2px solid #ccc;
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            background: #f8f9fa;
            padding: 10px;
            border-bottom: 1px solid #dee2e6;
            font-weight: bold;
        }

        .excel-container {
            flex: 1;
            padding: 10px;
            position: relative;
            min-height: 0;
            display: flex;
            flex-direction: column;
        }

        .right-panel {
            width: 50%;
            display: flex;
            flex-direction: column;
        }

        .pdf-controls {
            background: #f8f9fa;
            padding: 10px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .pdf-controls button {
            padding: 5px 10px;
            border: 1px solid #ccc;
            background: white;
            border-radius: 4px;
            cursor: pointer;
        }

        .pdf-controls button:hover {
            background: #e9ecef;
        }

        .pdf-container {
            flex: 1;
            overflow: auto;
            overflow-y: scroll;
            overflow-x: auto;
            background: #525659;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 20px;
        }

        .pdf-wrapper {
            display: inline-block;
            position: relative;
        }

        #pdf-canvas {
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            background: white;
            cursor: text;
        }

        .instructions {
            background: #fff3cd;
            color: #856404;
            padding: 10px;
            text-align: center;
            font-size: 14px;
        }

        #hot-container {
            flex: 1;
            width: 100%;
            overflow: hidden;
        }

        #text-layer {
            position: absolute;
            top: 0;
            left: 0;
            pointer-events: auto;
        }

        .word-span {
            position: absolute;
            cursor: pointer;
            padding: 0 2px;
            user-select: none;
        }

        .word-span:hover {
            opacity: 0.8;
        }
    </style>
</head>
<body>
<?php if ($autoDetectNotice !== ''): ?>
<script>
window.addEventListener('DOMContentLoaded', function() {
    alert(<?php echo json_encode($autoDetectNotice); ?>);
});
</script>
<?php endif; ?>
    <div class="header">
        <h1>📊 OCR Sanity Verification - <?php echo htmlspecialchars($excelFile); ?></h1>
        <div class="header-buttons">
            <button class="btn btn-undo" id="undoBtn" title="Undo (Ctrl+Z)">↶ Undo</button>
            <button class="btn btn-redo" id="redoBtn" title="Redo (Ctrl+Y)">↷ Redo</button>
            <button class="btn btn-check-linetotal" id="checkLineTotalBtn">🔍 Check LineTotal diff</button>
            <button class="btn btn-clean-numeric" id="cleanNumericBtn">🧹 Clean non-numeric</button>
            <button class="btn btn-positive-discounts" id="positiveDiscountsBtn">➕ Positive discount values</button>
            <button class="btn btn-check-json" id="checkOcrJsonBtn">📋 Check OCRjson file</button>
            <?php if ($sanityMethod === 'LineTotal' || $sanityMethod === 'Discount1' || $sanityMethod === 'Discount2'): ?>
            <div id="totalIndicator" class="total-indicator">
                <span class="indicator-label">Total Equality Indicator:</span>
                <span class="indicator-value" id="indicatorValue">0</span>
            </div>
            <?php endif; ?>
            <button class="btn btn-save" id="saveBtn">💾 Save OCR Sanity</button>
            <button class="btn btn-cancel" id="reverifyBtn">🔄 Re-verify</button>
        </div>
    </div>

    <div class="instructions">
        💡 <strong>Instructions:</strong> Edit cells in the spreadsheet on the left. Scroll through the PDF on the right to view all pages. Click on highlighted text in the PDF (numbers shown in green) to copy it to the selected cell. Use arrow keys to navigate between cells. Toggle overlay to show/hide clickable text. Press Ctrl+(-) to open delete & shift cells menu.
    </div>

    <!-- Delete Dialog -->
    <div class="delete-dialog-overlay" id="deleteDialogOverlay">
        <div class="delete-dialog">
            <h3>Delete</h3>
            <div class="delete-dialog-options">
                <label>
                    <input type="radio" name="deleteOption" value="shiftLeft" checked>
                    Shift cells left
                </label>
                <label>
                    <input type="radio" name="deleteOption" value="shiftUp">
                    Shift cells up
                </label>
                <label>
                    <input type="radio" name="deleteOption" value="entireRow">
                    Entire row
                </label>
                <label>
                    <input type="radio" name="deleteOption" value="entireColumn">
                    Entire column
                </label>
            </div>
            <div class="delete-dialog-buttons">
                <button class="btn-ok" id="deleteDialogOk">OK</button>
                <button class="btn-cancel" id="deleteDialogCancel">Cancel</button>
            </div>
        </div>
    </div>

    <div class="container">
        <!-- Left Panel - Excel Editor -->
        <div class="left-panel">
            <div class="panel-header">📝 OCR Sanity Spreadsheet (Editable)</div>
            <div class="excel-container">
                <div id="hot-container"></div>
            </div>
        </div>

        <!-- Right Panel - PDF Viewer -->
        <div class="right-panel">
            <div class="panel-header">📄 Invoice PDF</div>
            <div class="pdf-controls">
                <button id="zoom-out">🔍 -</button>
                <button id="zoom-in">🔍 +</button>
                <button id="zoom-reset">↻ Reset</button>
                <button id="toggle-overlay">👁 Toggle Overlay</button>
                <span id="zoom-level">Zoom: 100%</span>
                <span id="page-info" style="margin-left: auto;">Total Pages: 0</span>
            </div>
            <div class="pdf-container" id="pdf-scroll-container">
                <div class="pdf-wrapper">
                    <canvas id="pdf-canvas"></canvas>
                    <div id="text-layer"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Handsontable JS -->
    <script src="https://cdn.jsdelivr.net/npm/handsontable@14.0.0/dist/handsontable.full.min.js"></script>

    <script>
        // Excel data from PHP
        const excelData = <?php echo $jsonData; ?>;
        const excelFileName = <?php echo json_encode($excelFile); ?>;
        const cellStyles = <?php echo $cellStylesData; ?>;
        let totalDifference = <?php echo $totalDifference; ?>;
        const sanityMethod = <?php echo json_encode($sanityMethod); ?>;

        // Create a map for quick lookup of cell styles
        const styleMap = new Map();
        cellStyles.forEach(style => {
            const key = `${style.row}-${style.col}`;
            styleMap.set(key, style);
        });

        // Function to update total indicator
        function updateTotalIndicator(difference) {
            const indicatorValue = document.getElementById('indicatorValue');
            if (!indicatorValue) return; // Only for LineTotal method

            totalDifference = difference;
            indicatorValue.textContent = difference.toFixed(2);

            // Remove existing color classes
            indicatorValue.classList.remove('indicator-green', 'indicator-orange', 'indicator-red');

            // Apply color based on difference
            if (difference === 0) {
                indicatorValue.classList.add('indicator-green');
            } else if (Math.abs(difference) <= 3) {
                indicatorValue.classList.add('indicator-orange');
            } else {
                indicatorValue.classList.add('indicator-red');
            }
        }

        // Initialize indicator on page load (if LineTotal, Discount1, or Discount2 method)
        if (sanityMethod === 'LineTotal' || sanityMethod === 'Discount1' || sanityMethod === 'Discount2') {
            updateTotalIndicator(totalDifference);
        }

        // Initialize Handsontable with proper scrolling
        const container = document.getElementById('hot-container');

        const hot = new Handsontable(container, {
            data: excelData,
            colHeaders: true,
            rowHeaders: false,
            width: '100%',
            height: 'calc(100vh - 200px)',
            licenseKey: 'non-commercial-and-evaluation',
            contextMenu: [
                'row_above',
                'row_below',
                'remove_row',
                '---------',
                'col_left',
                'col_right',
                'remove_col',
                '---------',
                'undo',
                'redo',
                '---------',
                'cut',
                'copy'
            ],
            manualColumnResize: true,
            manualRowResize: true,
            stretchH: 'none',
            autoWrapRow: false,
            autoWrapCol: false,
            readOnly: false,
            undo: true,
            cells: function(row, col) {
                const cellProperties = {};
                const key = `${row}-${col}`;
                if (styleMap.has(key)) {
                    cellProperties.renderer = function(instance, td, row, col, prop, value, cellProperties) {
                        Handsontable.renderers.TextRenderer.apply(this, arguments);
                        const styleData = styleMap.get(`${row}-${col}`);

                        // Safety check - make sure styleData exists
                        if (styleData) {
                            // Apply background color if present
                            if (styleData.backgroundColor) {
                                td.style.backgroundColor = styleData.backgroundColor;
                            }

                            // Apply bold border if present (with color if specified)
                            if (styleData.boldBorder) {
                                const borderColor = styleData.borderColor || '#000';
                                td.style.border = `3px solid ${borderColor}`;
                            }
                        }
                    };
                }
                return cellProperties;
            }
        });

        // Track selected cell
        let selectedCell = { row: 0, col: 0 };

        hot.addHook('afterSelection', function(row, col) {
            selectedCell = { row, col };
        });

        // PDF.js setup
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        let pdfDoc = null;
        let scale = 1.5;
        let overlayVisible = true;
        const canvas = document.getElementById('pdf-canvas');
        const ctx = canvas.getContext('2d');

        // Load PDF (if available)
        <?php if ($pdfAvailable): ?>
        const pdfPath = 'sanity_files/<?php echo htmlspecialchars($pdfFile); ?>';

        pdfjsLib.getDocument(pdfPath).promise.then(function(pdfDoc_) {
            pdfDoc = pdfDoc_;
            document.getElementById('page-info').textContent = `Total Pages: ${pdfDoc.numPages}`;
            renderAllPages();
        }).catch(function(error) {
            console.error('Error loading PDF:', error);
            document.getElementById('page-info').textContent = 'PDF not available';
        });
        <?php else: ?>
        document.getElementById('page-info').textContent = 'PDF not available - Sanity data only';
        <?php endif; ?>

        async function renderAllPages() {
            if (!pdfDoc) return;

            // Get all pages and their viewports
            const numPages = pdfDoc.numPages;
            const pages = [];
            const viewports = [];

            for (let i = 1; i <= numPages; i++) {
                const page = await pdfDoc.getPage(i);
                const viewport = page.getViewport({ scale: scale });
                pages.push(page);
                viewports.push(viewport);
            }

            // Calculate total canvas dimensions
            const maxWidth = Math.max(...viewports.map(v => v.width));
            const totalHeight = viewports.reduce((sum, v) => sum + v.height, 0);

            // Set canvas size
            canvas.width = maxWidth;
            canvas.height = totalHeight;

            // Clear and setup text layer
            const textLayerDiv = document.getElementById('text-layer');
            textLayerDiv.innerHTML = '';
            textLayerDiv.style.width = `${maxWidth}px`;
            textLayerDiv.style.height = `${totalHeight}px`;

            // Render all pages vertically stacked
            let currentY = 0;
            for (let i = 0; i < pages.length; i++) {
                const page = pages[i];
                const viewport = viewports[i];

                // Save context and translate for this page
                ctx.save();
                ctx.translate(0, currentY);

                // Render page
                const renderContext = {
                    canvasContext: ctx,
                    viewport: viewport
                };
                await page.render(renderContext).promise;

                // Extract and render text content
                const textContent = await page.getTextContent();
                textContent.items.forEach(item => {
                    const span = document.createElement('span');
                    const word = item.str.trim();

                    if (!word) return; // Skip empty strings

                    span.textContent = word;
                    span.classList.add('word-span');

                    // Calculate position using PDF.js transform
                    const transform = pdfjsLib.Util.transform(viewport.transform, item.transform);
                    const x = transform[4];
                    const y = transform[5] + currentY; // Offset by current Y position
                    const fontSize = Math.sqrt(transform[0] ** 2 + transform[1] ** 2);

                    span.style.left = `${x}px`;
                    span.style.top = `${y - fontSize}px`;
                    span.style.fontSize = `${fontSize}px`;

                    // Highlight numbers with light green background
                    const hasDigits = /[\d]/.test(word);
                    if (hasDigits) {
                        span.style.backgroundColor = 'rgba(144, 238, 144, 0.3)';
                    }

                    // Click handler - copy to selected Excel cell
                    span.onclick = function() {
                        span.style.backgroundColor = 'orange';
                        if (selectedCell) {
                            const { row, col } = selectedCell;
                            hot.setDataAtCell(row, col, word);
                            hot.selectCell(row, col);
                            console.log(`✔ Inserted "${word}" into cell at row ${row}, column ${col}`);
                        } else {
                            console.warn('⚠ No cell selected!');
                        }
                    };

                    // Only add span if overlay is visible
                    if (overlayVisible) {
                        textLayerDiv.appendChild(span);
                    }
                });

                // Restore context
                ctx.restore();

                // Move Y position for next page
                currentY += viewport.height;
            }
        }

        // Zoom controls
        document.getElementById('zoom-in').addEventListener('click', function() {
            scale += 0.2;
            document.getElementById('zoom-level').textContent = `Zoom: ${Math.round(scale * 100)}%`;
            renderAllPages();
        });

        document.getElementById('zoom-out').addEventListener('click', function() {
            if (scale <= 0.5) return;
            scale -= 0.2;
            document.getElementById('zoom-level').textContent = `Zoom: ${Math.round(scale * 100)}%`;
            renderAllPages();
        });

        document.getElementById('zoom-reset').addEventListener('click', function() {
            scale = 1.5;
            document.getElementById('zoom-level').textContent = `Zoom: 100%`;
            renderAllPages();
        });

        // Toggle overlay
        document.getElementById('toggle-overlay').addEventListener('click', function() {
            overlayVisible = !overlayVisible;
            renderAllPages();
        });

        // Undo/Redo functionality
        document.getElementById('undoBtn').addEventListener('click', function() {
            hot.undo();
        });

        document.getElementById('redoBtn').addEventListener('click', function() {
            hot.redo();
        });

        // Delete dialog functionality
        let pendingDeleteSelection = null;

        function showDeleteDialog() {
            document.getElementById('deleteDialogOverlay').classList.add('active');
        }

        function hideDeleteDialog() {
            document.getElementById('deleteDialogOverlay').classList.remove('active');
            pendingDeleteSelection = null;
        }

        document.getElementById('deleteDialogCancel').addEventListener('click', hideDeleteDialog);

        document.getElementById('deleteDialogOk').addEventListener('click', function() {
            const selectedOption = document.querySelector('input[name="deleteOption"]:checked').value;
            const selection = pendingDeleteSelection;

            if (!selection) {
                hideDeleteDialog();
                return;
            }

            const [startRow, startCol, endRow, endCol] = selection;

            if (selectedOption === 'shiftLeft') {
                // Shift cells left
                for (let row = startRow; row <= endRow; row++) {
                    for (let col = startCol; col <= endCol; col++) {
                        // Get all cells to the right in this row
                        const rowData = hot.getDataAtRow(row);
                        // Remove the selected cells and shift left
                        rowData.splice(col, endCol - startCol + 1);
                        // Fill with empty values at the end
                        for (let i = 0; i < (endCol - startCol + 1); i++) {
                            rowData.push('');
                        }
                        // Update the row
                        for (let c = 0; c < rowData.length; c++) {
                            hot.setDataAtCell(row, c, rowData[c]);
                        }
                    }
                }
            } else if (selectedOption === 'shiftUp') {
                // Shift cells up
                for (let col = startCol; col <= endCol; col++) {
                    const columnData = hot.getDataAtCol(col);
                    // Remove selected cells
                    columnData.splice(startRow, endRow - startRow + 1);
                    // Fill with empty values at the end
                    for (let i = 0; i < (endRow - startRow + 1); i++) {
                        columnData.push('');
                    }
                    // Update the column
                    for (let r = 0; r < columnData.length; r++) {
                        hot.setDataAtCell(r, col, columnData[r]);
                    }
                }
            } else if (selectedOption === 'entireRow') {
                // Delete entire rows
                hot.alter('remove_row', startRow, endRow - startRow + 1);
            } else if (selectedOption === 'entireColumn') {
                // Delete entire columns
                hot.alter('remove_col', startCol, endCol - startCol + 1);
            }

            hideDeleteDialog();
        });

        // Keyboard shortcuts for undo/redo and delete
        document.addEventListener('keydown', function(e) {
            // Ctrl+Z or Cmd+Z for undo
            if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
                e.preventDefault();
                hot.undo();
            }
            // Ctrl+Y or Cmd+Shift+Z for redo
            if (((e.ctrlKey || e.metaKey) && e.key === 'y') ||
                ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'z')) {
                e.preventDefault();
                hot.redo();
            }
            // Ctrl+Minus to show delete dialog
            if ((e.ctrlKey || e.metaKey) && e.key === '-') {
                e.preventDefault();
                const selected = hot.getSelected();
                if (selected && selected.length > 0) {
                    pendingDeleteSelection = selected[0];
                    showDeleteDialog();
                }
            }
            // Delete key to clear cell contents (not shift)
            if (e.key === 'Delete') {
                const selected = hot.getSelected();
                if (selected) {
                    // Clear all selected cells
                    selected.forEach(([startRow, startCol, endRow, endCol]) => {
                        for (let row = startRow; row <= endRow; row++) {
                            for (let col = startCol; col <= endCol; col++) {
                                hot.setDataAtCell(row, col, '');
                            }
                        }
                    });
                }
            }
        });

        // Save functionality
        document.getElementById('saveBtn').addEventListener('click', function() {
            if (!confirm('Save OCR Sanity file? This will close the verification window.')) {
                return;
            }

            const updatedData = hot.getData();

            // Disable save button
            document.getElementById('saveBtn').disabled = true;
            document.getElementById('saveBtn').textContent = '⏳ Saving...';

            // Send data to server to update Excel file
            fetch('save_ocrsanity.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    filename: excelFileName,
                    data: updatedData
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Check if we're in harmonized mode
                    const urlParams = new URLSearchParams(window.location.search);
                    const harmonizedMode = urlParams.get('harmonized') === 'true';

                    // Show success page
                    document.body.innerHTML = `
                        <div style="display: flex; justify-content: center; align-items: center; height: 100vh; background: #f5f5f5;">
                            <div style="background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); text-align: center; max-width: 600px;">
                                <div style="font-size: 64px; color: #28a745; margin-bottom: 20px;">✅</div>
                                <h2 style="color: #333; margin-bottom: 20px;">OCR Sanity File Saved Successfully!</h2>
                                <p style="color: #666; font-size: 16px; margin-bottom: 10px;">
                                    <strong>File:</strong> ${excelFileName}
                                </p>
                                <p style="color: #666; font-size: 16px;">
                                    <strong>Location:</strong> C:\\xampp\\htdocs\\website\\OCRsanity\\sanity_files
                                </p>
                                ${harmonizedMode ? '<p style="color: #28a745; font-size: 14px; margin-top: 20px;">⏳ Redirecting to Commercial Layer...</p>' : ''}
                            </div>
                        </div>
                    `;

                    // If harmonized mode, redirect to step 5 immediately
                    if (harmonizedMode) {
                        window.location.href = '/website/harmonizedFlow/step5_commercial_layer.php';
                    }
                } else {
                    alert('❌ Error saving file: ' + data.error);
                    document.getElementById('saveBtn').disabled = false;
                    document.getElementById('saveBtn').textContent = '💾 Save OCR Sanity';
                }
            })
            .catch(error => {
                alert('❌ Error: ' + error);
                document.getElementById('saveBtn').disabled = false;
                document.getElementById('saveBtn').textContent = '💾 Save OCR Sanity';
            });
        });

        // Check LineTotal diff functionality
        document.getElementById('checkLineTotalBtn').addEventListener('click', function() {
            // Get current data from Handsontable
            const currentData = hot.getData();

            // Build LDC table data
            const ldcData = [];

            // Add header row - New order: Index, Qty, UnitPrice, Discount1, Discount2, Formula, LineTotal, Diff
            // The formula of the invoice's method (B6), as the server check (process_ocrsanity.php): Discount2 divides
            // (the printed price is after the discounts); the others multiply (the price is before the discount).
            const ldcMultiply = sanityMethod !== 'Discount2';
            ldcData.push(['Index', 'Qty', 'UnitPrice', 'Discount1', 'Discount2', ldcMultiply ? 'Qty×Price×(1-D1%)×(1-D2%)' : 'Qty×Price/(1-D1%/100)/(1-D2%/100)', 'LineTotal', 'Diff']);

            // Process each data row (skip header row 0)
            for (let i = 1; i < currentData.length; i++) {
                const row = currentData[i];

                // Column C (index 2) - Index
                const index = row[2] || '';

                // Column F (index 5) - Qty
                const qtyStr = String(row[5] || '0').replace(/,/g, '');
                const qty = parseFloat(qtyStr) || 0;

                // Column G (index 6) - UnitPrice (Cost)
                const unitPriceStr = String(row[6] || '0').replace(/,/g, '');
                const unitPrice = parseFloat(unitPriceStr) || 0;

                // Column H (index 7) - LineTotal
                // Remove commas before parsing
                const lineTotalStr = String(row[7] || '0').replace(/,/g, '');
                const lineTotal = parseFloat(lineTotalStr) || 0;

                // Column I (index 8) - Discount1
                const discount1Str = String(row[8] || '0').replace(/,/g, '');
                const discount1 = parseFloat(discount1Str) || 0;

                // Column J (index 9) - Discount2
                const discount2Str = String(row[9] || '0').replace(/,/g, '');
                const discount2 = parseFloat(discount2Str) || 0;

                const formulaResult = ldcMultiply ? qty * unitPrice * (1 - discount1 / 100) * (1 - discount2 / 100)
                                                  : qty * unitPrice / (1 - discount1 / 100) / (1 - discount2 / 100);

                // Calculate Diff (Formula - LineTotal)
                const diff = formulaResult - lineTotal;

                ldcData.push([
                    index,
                    qty.toFixed(2),
                    unitPrice.toFixed(2),
                    discount1.toFixed(2),
                    discount2.toFixed(2),
                    formulaResult.toFixed(2),
                    lineTotal.toFixed(2),
                    diff.toFixed(2)
                ]);
            }

            // Open popup window (75% of screen size)
            const screenWidth = window.screen.width;
            const screenHeight = window.screen.height;
            const popupWidth = Math.floor(screenWidth * 0.75);
            const popupHeight = Math.floor(screenHeight * 0.75);
            const left = Math.floor((screenWidth - popupWidth) / 2);
            const top = Math.floor((screenHeight - popupHeight) / 2);

            const popup = window.open('', 'LineTotalDiffChecker',
                `width=${popupWidth},height=${popupHeight},left=${left},top=${top},scrollbars=yes,resizable=yes`);

            // Write popup content
            popup.document.write(`
                <!DOCTYPE html>
                <html lang="en">
                <head>
                    <meta charset="UTF-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                    <title>LineTotal Diff Check</title>
                    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/handsontable@14.0.0/dist/handsontable.full.min.css">
                    <style>
                        * {
                            margin: 0;
                            padding: 0;
                            box-sizing: border-box;
                        }
                        body {
                            font-family: Arial, sans-serif;
                            padding: 20px;
                            background: #f5f5f5;
                        }
                        .header {
                            background: #6f42c1;
                            color: white;
                            padding: 15px 20px;
                            border-radius: 8px;
                            margin-bottom: 20px;
                        }
                        .header h1 {
                            font-size: 20px;
                            margin-bottom: 10px;
                        }
                        .stats {
                            display: flex;
                            gap: 20px;
                            font-size: 14px;
                        }
                        .stats span {
                            background: rgba(255,255,255,0.2);
                            padding: 5px 10px;
                            border-radius: 4px;
                        }
                        .container {
                            background: white;
                            border-radius: 8px;
                            padding: 20px;
                            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                        }
                        #hot-container {
                            width: 100%;
                            height: calc(100vh - 200px);
                            overflow: hidden;
                        }
                        .info {
                            background: #e7e3fc;
                            color: #5a32a3;
                            padding: 10px;
                            border-radius: 4px;
                            margin-bottom: 15px;
                            font-size: 14px;
                        }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>🔍 LineTotal Diff Check (LDC)</h1>
                        <div class="stats">
                            <span>📊 Total Rows: ${ldcData.length - 1}</span>
                            <button id="recalcBtn" style="background: #28a745; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 14px; margin-left: 20px;">
                                🔄 Re-calculate
                            </button>
                        </div>
                    </div>
                    <div class="container">
                        <div class="info">
                            💡 This table shows the difference between LineTotal and the formula of the invoice's method (${sanityMethod}) for each row. You can edit Qty, UnitPrice, Discount1, Discount2, and LineTotal values. Changed cells will be highlighted in light orange. Click "Re-calculate" to update the Formula and Diff based on your edits.
                        </div>
                        <div id="hot-container"></div>
                    </div>
                    <script src="https://cdn.jsdelivr.net/npm/handsontable@14.0.0/dist/handsontable.full.min.js"><\/script>
                    <script>
                        const container = document.getElementById('hot-container');
                        const ldcData = ${JSON.stringify(ldcData)};
                        const ldcMultiply = ${ldcMultiply};

                        // Track which cells have been manually changed
                        const changedCells = new Set();

                        const hot = new Handsontable(container, {
                            data: ldcData,
                            colHeaders: false,
                            rowHeaders: false,
                            width: '100%',
                            height: 'calc(100vh - 220px)',
                            licenseKey: 'non-commercial-and-evaluation',
                            contextMenu: true,
                            manualColumnResize: true,
                            manualRowResize: true,
                            stretchH: 'all',
                            autoWrapRow: true,
                            autoWrapCol: true,
                            cells: function(row, col) {
                                const cellProperties = {};

                                // Header row is read-only
                                if (row === 0) {
                                    cellProperties.readOnly = true;
                                    cellProperties.renderer = function(instance, td, row, col, prop, value, cellProperties) {
                                        Handsontable.renderers.TextRenderer.apply(this, arguments);
                                        td.style.backgroundColor = '#6f42c1';
                                        td.style.color = 'white';
                                        td.style.fontWeight = 'bold';
                                        td.style.textAlign = 'center';
                                    };
                                } else {
                                    // Make Index, Formula, and Diff read-only (columns 0, 5, 7)
                                    if (col === 0 || col === 5 || col === 7) {
                                        cellProperties.readOnly = true;
                                    }

                                    // Column 7 - Diff column - apply color coding based on value
                                    if (col === 7) {
                                        cellProperties.renderer = function(instance, td, row, col, prop, value, cellProperties) {
                                            Handsontable.renderers.TextRenderer.apply(this, arguments);
                                            const diffValue = parseFloat(value);

                                            if (Math.abs(diffValue) < 0.01) {
                                                // Near zero - green background
                                                td.style.backgroundColor = '#d4edda';
                                                td.style.color = '#155724';
                                            } else if (Math.abs(diffValue) < 0.10) {
                                                // Small difference - yellow background
                                                td.style.backgroundColor = '#fff3cd';
                                                td.style.color = '#856404';
                                            } else {
                                                // Significant difference - red background
                                                td.style.backgroundColor = '#f8d7da';
                                                td.style.color = '#721c24';
                                            }

                                            td.style.fontWeight = 'bold';
                                            td.style.textAlign = 'right';
                                        };
                                    }
                                    // Editable columns (Qty, UnitPrice, LineTotal) and calculated columns
                                    else if (col >= 1 && col <= 4) {
                                        cellProperties.renderer = function(instance, td, row, col, prop, value, cellProperties) {
                                            Handsontable.renderers.TextRenderer.apply(this, arguments);
                                            td.style.textAlign = 'right';

                                            // Check if this cell was manually changed (light orange)
                                            const cellKey = row + '-' + col;
                                            if (changedCells.has(cellKey)) {
                                                td.style.backgroundColor = '#FFE4CC';
                                            }
                                        };
                                    }
                                }

                                return cellProperties;
                            },
                            afterChange: function(changes, source) {
                                // Track manual changes (ignore initial load and recalculation)
                                if (source === 'edit') {
                                    changes.forEach(([row, col, oldValue, newValue]) => {
                                        if (oldValue !== newValue && row > 0) {
                                            const cellKey = row + '-' + col;
                                            changedCells.add(cellKey);
                                        }
                                    });
                                    hot.render();
                                }
                            }
                        });

                        // Re-calculate button functionality
                        document.getElementById('recalcBtn').addEventListener('click', function() {
                            const data = hot.getData();

                            // Process each row (skip header row 0)
                            for (let i = 1; i < data.length; i++) {
                                const qty = parseFloat(data[i][1]) || 0;
                                const unitPrice = parseFloat(data[i][2]) || 0;
                                const discount1 = parseFloat(data[i][3]) || 0;
                                const discount2 = parseFloat(data[i][4]) || 0;
                                const lineTotal = parseFloat(data[i][6]) || 0;

                                const formulaResult = ldcMultiply ? qty * unitPrice * (1 - discount1 / 100) * (1 - discount2 / 100)
                                                                  : qty * unitPrice / (1 - discount1 / 100) / (1 - discount2 / 100);

                                // Calculate Diff (Formula - LineTotal)
                                const diff = formulaResult - lineTotal;

                                // Update calculated columns (don't add to changedCells)
                                hot.setDataAtCell(i, 5, formulaResult.toFixed(2), 'recalc');
                                hot.setDataAtCell(i, 7, diff.toFixed(2), 'recalc');
                            }

                            hot.render();
                        });
                    <\/script>
                </body>
                </html>
            `);
            popup.document.close();
        });

        // Check OCRjson file functionality
        document.getElementById('checkOcrJsonBtn').addEventListener('click', function() {
            // Disable button while loading
            document.getElementById('checkOcrJsonBtn').disabled = true;
            document.getElementById('checkOcrJsonBtn').textContent = '⏳ Loading...';

            // Fetch OCRjson data
            fetch('get_ocrjson_data.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Open popup window (75% of screen size)
                    const screenWidth = window.screen.width;
                    const screenHeight = window.screen.height;
                    const popupWidth = Math.floor(screenWidth * 0.75);
                    const popupHeight = Math.floor(screenHeight * 0.75);
                    const left = Math.floor((screenWidth - popupWidth) / 2);
                    const top = Math.floor((screenHeight - popupHeight) / 2);

                    const popup = window.open('', 'OCRjsonViewer',
                        `width=${popupWidth},height=${popupHeight},left=${left},top=${top},scrollbars=yes,resizable=yes`);

                    // Write popup content
                    popup.document.write(`
                        <!DOCTYPE html>
                        <html lang="en">
                        <head>
                            <meta charset="UTF-8">
                            <meta name="viewport" content="width=device-width, initial-scale=1.0">
                            <title>OCRjson Data - ${data.fileName}</title>
                            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/handsontable@14.0.0/dist/handsontable.full.min.css">
                            <style>
                                * {
                                    margin: 0;
                                    padding: 0;
                                    box-sizing: border-box;
                                }
                                body {
                                    font-family: Arial, sans-serif;
                                    padding: 20px;
                                    background: #f5f5f5;
                                }
                                .header {
                                    background: #17a2b8;
                                    color: white;
                                    padding: 15px 20px;
                                    border-radius: 8px;
                                    margin-bottom: 20px;
                                }
                                .header h1 {
                                    font-size: 20px;
                                    margin-bottom: 10px;
                                }
                                .stats {
                                    display: flex;
                                    gap: 20px;
                                    font-size: 14px;
                                }
                                .stats span {
                                    background: rgba(255,255,255,0.2);
                                    padding: 5px 10px;
                                    border-radius: 4px;
                                }
                                .container {
                                    background: white;
                                    border-radius: 8px;
                                    padding: 20px;
                                    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                                }
                                #hot-container {
                                    width: 100%;
                                    height: calc(100vh - 200px);
                                    overflow: hidden;
                                }
                                .info {
                                    background: #fff3cd;
                                    color: #856404;
                                    padding: 10px;
                                    border-radius: 4px;
                                    margin-bottom: 15px;
                                    font-size: 14px;
                                }
                            </style>
                        </head>
                        <body>
                            <div class="header">
                                <h1>📋 OCRjson Data Viewer</h1>
                                <div class="stats">
                                    <span>📄 File: ${data.fileName}</span>
                                    <span>📊 Fields: ${data.fieldCount}</span>
                                    <span>📈 Rows: ${data.rowCount}</span>
                                </div>
                            </div>
                            <div class="container">
                                <div class="info">
                                    💡 This is the raw OCR data extracted by OpenAI. You can copy values from this table to the OCR Sanity sheet if the mapping doesn't match.
                                </div>
                                <div id="hot-container"></div>
                            </div>
                            <script src="https://cdn.jsdelivr.net/npm/handsontable@14.0.0/dist/handsontable.full.min.js"><\/script>
                            <script>
                                const container = document.getElementById('hot-container');
                                const ocrData = ${JSON.stringify(data.data)};

                                const hot = new Handsontable(container, {
                                    data: ocrData,
                                    colHeaders: ${JSON.stringify(data.colHeaders)},
                                    rowHeaders: true,
                                    width: '100%',
                                    height: 'calc(100vh - 220px)',
                                    licenseKey: 'non-commercial-and-evaluation',
                                    contextMenu: true,
                                    manualColumnResize: true,
                                    manualRowResize: true,
                                    stretchH: 'all',
                                    autoWrapRow: true,
                                    autoWrapCol: true,
                                    readOnly: false,
                                    cells: function(row, col) {
                                        const cellProperties = {};
                                        // Highlight header row
                                        if (row === 0) {
                                            cellProperties.renderer = function(instance, td, row, col, prop, value, cellProperties) {
                                                Handsontable.renderers.TextRenderer.apply(this, arguments);
                                                td.style.backgroundColor = '#17a2b8';
                                                td.style.color = 'white';
                                                td.style.fontWeight = 'bold';
                                            };
                                        }
                                        // Highlight invoice metadata rows (1-3)
                                        else if (row >= 1 && row <= 3) {
                                            cellProperties.renderer = function(instance, td, row, col, prop, value, cellProperties) {
                                                Handsontable.renderers.TextRenderer.apply(this, arguments);
                                                if (col === 0) {
                                                    td.style.backgroundColor = '#e7f3ff';
                                                    td.style.fontWeight = 'bold';
                                                }
                                            };
                                        }
                                        return cellProperties;
                                    }
                                });
                            <\/script>
                        </body>
                        </html>
                    `);
                    popup.document.close();
                } else {
                    alert('❌ Error loading OCRjson data: ' + data.error);
                }

                // Re-enable button
                document.getElementById('checkOcrJsonBtn').disabled = false;
                document.getElementById('checkOcrJsonBtn').textContent = '📋 Check OCRjson file';
            })
            .catch(error => {
                alert('❌ Error: ' + error);
                document.getElementById('checkOcrJsonBtn').disabled = false;
                document.getElementById('checkOcrJsonBtn').textContent = '📋 Check OCRjson file';
            });
        });

        // Re-verify functionality
        document.getElementById('reverifyBtn').addEventListener('click', function() {
            const updatedData = hot.getData();

            // Disable re-verify button
            document.getElementById('reverifyBtn').disabled = true;
            document.getElementById('reverifyBtn').textContent = '⏳ Re-verifying...';

            // Send data to server to re-verify
            fetch('reverify_ocrsanity.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    filename: excelFileName,
                    data: updatedData
                })
            })
            .then(response => response.json())
            .then(data => {
                console.log('Re-verify response:', data); // Debug log
                if (data.success) {
                    // Update styleMap with new cell styles
                    styleMap.clear();
                    data.cellStyles.forEach(style => {
                        const key = `${style.row}-${style.col}`;
                        styleMap.set(key, style);
                    });

                    // Update total indicator if LineTotal, Discount1, or Discount2 method
                    if ((sanityMethod === 'LineTotal' || sanityMethod === 'Discount1' || sanityMethod === 'Discount2') && data.totalDifference !== undefined) {
                        console.log('Updating indicator with:', data.totalDifference); // Debug log
                        updateTotalIndicator(data.totalDifference);
                    }

                    // Reload cell data from server (including recalculated ActualUnitPrice in column K)
                    if (data.cellData) {
                        hot.loadData(data.cellData);
                    }

                    // Force Handsontable to re-render all cells with new styles
                    hot.render();

                    // Re-enable button
                    document.getElementById('reverifyBtn').disabled = false;
                    document.getElementById('reverifyBtn').textContent = '🔄 Re-verify';
                } else {
                    // Check if it's an invalid method error
                    if (data.error === 'invalid_method') {
                        // Show detailed popup with valid methods
                        const validMethodsList = data.validMethods.join(', ');
                        alert(`❌ Invalid Sanity Method in cell B6!\n\nCurrent value: "${data.currentMethod}"\n\nPlease change cell B6 to one of these valid methods:\n${validMethodsList}\n\nThen click Re-verify again.`);
                    } else {
                        // Generic error
                        alert('❌ Error re-verifying file: ' + (data.message || data.error));
                    }
                    document.getElementById('reverifyBtn').disabled = false;
                    document.getElementById('reverifyBtn').textContent = '🔄 Re-verify';
                }
            })
            .catch(error => {
                alert('❌ Error: ' + error);
                document.getElementById('reverifyBtn').disabled = false;
                document.getElementById('reverifyBtn').textContent = '🔄 Re-verify';
            });
        });

        // Clean non-numeric functionality
        document.getElementById('cleanNumericBtn').addEventListener('click', function() {
            if (!confirm('האם לנקות ערכים לא-נומריים מעמודות D, F, G, H, I, J?\nניקוי ישמור רק ספרות, נקודה (.) ומינוס (-).')) {
                return;
            }

            // Disable button while processing
            const cleanBtn = this;
            cleanBtn.disabled = true;
            cleanBtn.textContent = '🔄 שומר...';

            // Step 1: Save current Handsontable data first (without deleting PDF)
            const updatedData = hot.getData();

            fetch('save_ocrsanity.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    filename: excelFileName,
                    data: updatedData,
                    skipPdfDelete: true  // Don't delete PDF during cleaning
                })
            })
            .then(response => response.json())
            .then(saveResult => {
                if (!saveResult.success) {
                    throw new Error('Failed to save: ' + saveResult.message);
                }

                // Step 2: Now clean the numeric fields
                cleanBtn.textContent = '🔄 מנקה...';

                return fetch('clean_numeric_fields.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'file=' + encodeURIComponent(excelFileName)
                });
            })
            .then(response => response.json())
            .then(cleanResult => {
                if (cleanResult.success) {
                    alert(cleanResult.message);
                    // Reload page to show cleaned data (force reload from server)
                    location.reload(true);
                } else {
                    alert('שגיאה: ' + cleanResult.message);
                    cleanBtn.disabled = false;
                    cleanBtn.textContent = '🧹 Clean non-numeric';
                }
            })
            .catch(error => {
                alert('שגיאה: ' + error.message);
                cleanBtn.disabled = false;
                cleanBtn.textContent = '🧹 Clean non-numeric';
            });
        });

        // Positive discount values functionality
        document.getElementById('positiveDiscountsBtn').addEventListener('click', function() {
            if (!confirm('האם להמיר ערכי הנחה שליליים לחיוביים בעמודות Discount1 ו-Discount2?\nסימן המינוס (-) יוסר מכל ערכי ההנחה.')) {
                return;
            }

            // Disable button while processing
            const posBtn = this;
            posBtn.disabled = true;
            posBtn.textContent = '🔄 שומר...';

            // Step 1: Save current Handsontable data first (without deleting PDF)
            const updatedData = hot.getData();

            fetch('save_ocrsanity.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    filename: excelFileName,
                    data: updatedData,
                    skipPdfDelete: true  // Don't delete PDF during conversion
                })
            })
            .then(response => response.json())
            .then(saveResult => {
                if (!saveResult.success) {
                    throw new Error('Failed to save: ' + saveResult.message);
                }

                // Step 2: Now convert negative discounts to positive
                posBtn.textContent = '🔄 ממיר...';

                return fetch('positive_discount_values.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'file=' + encodeURIComponent(excelFileName)
                });
            })
            .then(response => response.json())
            .then(convertResult => {
                if (convertResult.success) {
                    alert(convertResult.message);
                    // Reload page to show converted data (force reload from server)
                    location.reload(true);
                } else {
                    alert('שגיאה: ' + convertResult.message);
                    posBtn.disabled = false;
                    posBtn.textContent = '➕ Positive discount values';
                }
            })
            .catch(error => {
                alert('שגיאה: ' + error.message);
                posBtn.disabled = false;
                posBtn.textContent = '➕ Positive discount values';
            });
        });
    </script>
</body>
</html>

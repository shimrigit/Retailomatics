<?php
// A document that came from Retailomatics Invoice Scan returns there (to its next document);
// otherwise, as before, to the start of this flow.
session_start();
$nextUrl = !empty($_SESSION['invoiceScanReturn']) ? '/website/InvoiceScan/next.php' : '/website/harmonizedFlow/start_harmonized_flow.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cleanup Complete</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .cleanup-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 800px;
            width: 100%;
        }

        .success-icon {
            font-size: 4em;
            color: #28a745;
            text-align: center;
            margin-bottom: 20px;
            animation: scaleIn 0.5s ease-out;
        }

        @keyframes scaleIn {
            from { transform: scale(0); }
            to { transform: scale(1); }
        }

        h1 {
            color: #333;
            font-size: 2em;
            margin-bottom: 10px;
            text-align: center;
        }

        .subtitle {
            color: #666;
            text-align: center;
            margin-bottom: 30px;
            font-size: 14px;
        }

        .files-section {
            margin-bottom: 25px;
        }

        .section-header {
            background: #667eea;
            color: white;
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: bold;
            margin-bottom: 15px;
            font-size: 16px;
        }

        .file-item {
            background: #f8f9fa;
            border-left: 4px solid #28a745;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 6px;
        }

        .file-item.moved {
            border-left-color: #17a2b8;
        }

        .file-item.staying {
            border-left-color: #6c757d;
        }

        .file-name {
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
            font-size: 14px;
        }

        .file-location {
            color: #666;
            font-size: 13px;
            margin-left: 10px;
        }

        .arrow {
            color: #17a2b8;
            font-weight: bold;
            margin: 0 5px;
        }

        .redirect-message {
            background: #fff3cd;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
        }

        .loader {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-right: 10px;
            vertical-align: middle;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="cleanup-card">
        <div class="success-icon">🧹</div>
        <h1>Cleanup Complete!</h1>
        <p class="subtitle">Files have been organized and moved to their final locations</p>

        <div class="files-section">
            <div class="section-header">📦 Moved Files (to uploads/)</div>
            <div id="movedFilesContainer"></div>
        </div>

        <div class="files-section">
            <div class="section-header">📁 Files Remaining in Commercial Layer</div>
            <div id="stayingFilesContainer"></div>
        </div>

        <div class="redirect-message">
            <span class="loader"></span>
            Redirecting to next invoice in <span id="countdown">2</span> seconds...
        </div>
    </div>

    <script>
        // Get cleanup results from URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        const resultsJson = urlParams.get('results');

        if (resultsJson) {
            try {
                const results = JSON.parse(decodeURIComponent(resultsJson));
                displayResults(results);
            } catch (e) {
                console.error('Error parsing results:', e);
            }
        }

        function displayResults(results) {
            const movedContainer = document.getElementById('movedFilesContainer');
            const stayingContainer = document.getElementById('stayingFilesContainer');

            // Display moved files
            if (results.movedFiles) {
                if (results.movedFiles.pdf) {
                    const file = results.movedFiles.pdf;
                    movedContainer.innerHTML += `
                        <div class="file-item moved">
                            <div class="file-name">📄 PDF Invoice: ${file.fileName}</div>
                            <div class="file-location">${file.from} <span class="arrow">→</span> ${file.to}</div>
                        </div>
                    `;
                }

                if (results.movedFiles.ocrJson) {
                    const file = results.movedFiles.ocrJson;
                    movedContainer.innerHTML += `
                        <div class="file-item moved">
                            <div class="file-name">📋 OCRjson: ${file.fileName}</div>
                            <div class="file-location">${file.from} <span class="arrow">→</span> ${file.to}</div>
                        </div>
                    `;
                }
            }

            // Display staying files
            if (results.stayingFiles) {
                if (results.stayingFiles.cl) {
                    const file = results.stayingFiles.cl;
                    stayingContainer.innerHTML += `
                        <div class="file-item staying">
                            <div class="file-name">🏪 Commercial Layer: ${file.fileName}</div>
                            <div class="file-location">${file.location}</div>
                        </div>
                    `;
                }

                if (results.stayingFiles.pc) {
                    const file = results.stayingFiles.pc;
                    stayingContainer.innerHTML += `
                        <div class="file-item staying">
                            <div class="file-name">💰 Price Change: ${file.fileName}</div>
                            <div class="file-location">${file.location}</div>
                        </div>
                    `;
                }

                if (results.stayingFiles.np) {
                    const file = results.stayingFiles.np;
                    stayingContainer.innerHTML += `
                        <div class="file-item staying">
                            <div class="file-name">✨ New Products: ${file.fileName}</div>
                            <div class="file-location">${file.location}</div>
                        </div>
                    `;
                }
            }

            // Show message if no staying files
            if (!results.stayingFiles || Object.keys(results.stayingFiles).length === 0) {
                stayingContainer.innerHTML = '<div class="file-item staying"><div class="file-name">No CL/PC/NP files generated</div></div>';
            }
        }

        // Countdown and redirect
        let countdown = 2;
        const countdownElement = document.getElementById('countdown');

        const interval = setInterval(() => {
            countdown--;
            countdownElement.textContent = countdown;

            if (countdown <= 0) {
                clearInterval(interval);
                window.location.href = <?= json_encode($nextUrl) ?>;
            }
        }, 1000);
    </script>
</body>
</html>

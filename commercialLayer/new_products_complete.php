<?php
/**
 * New Products Process Completion Page
 */

session_start();

// Check if we're skipping (no PC and no NP)
$skip = isset($_GET['skip']) && $_GET['skip'] === 'true';
$clFileName = $_GET['cl'] ?? null;
$shopName = $_GET['shop'] ?? null;

if ($skip) {
    // Skipping mode - no PC and no NP files generated
    $npFileName = null;
    $npComplete = true;
} else {
    // Normal mode - NP file was generated
    $npFileName = $_GET['npFile'] ?? null;
    $npComplete = $_SESSION['np_complete'] ?? false;

    if (!$npFileName || !$npComplete) {
        header('Location: index.php');
        exit;
    }

    // Clear session flag
    unset($_SESSION['np_complete']);
}
?>
<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>תהליך מוצרים חדשים הושלם</title>
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

        .completion-card {
            background: white;
            border-radius: 20px;
            padding: 50px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
            max-width: 600px;
        }

        .success-icon {
            font-size: 5em;
            color: #28a745;
            margin-bottom: 20px;
            animation: scaleIn 0.5s ease-out;
        }

        @keyframes scaleIn {
            from { transform: scale(0); }
            to { transform: scale(1); }
        }

        h1 {
            color: #333;
            font-size: 2.5em;
            margin-bottom: 20px;
        }

        .message {
            font-size: 1.3em;
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .file-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            border-right: 5px solid #28a745;
        }

        .file-info p {
            font-size: 1.1em;
            margin: 10px 0;
            color: #333;
        }

        .file-info strong {
            color: #667eea;
        }

        .buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .button {
            padding: 15px 30px;
            font-size: 1.1em;
            font-weight: bold;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }

        .button-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .button-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .button-secondary {
            background: #6c757d;
            color: white;
        }

        .button-secondary:hover {
            background: #5a6268;
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
        }

        .completion-message {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-top: 30px;
            font-size: 1.2em;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="completion-card">
        <div class="success-icon">✓</div>

        <?php if ($skip): ?>
            <h1>תהליך ה-Commercial Layer הושלם!</h1>

            <?php
            // What of this document is kept: a PRICE-CHANGE file of its CL (not skipped), and the NP skipped or not found.
            $clBase = $clFileName ? pathinfo(basename($clFileName), PATHINFO_FILENAME) : '';
            $pcKept = $clBase !== '' && glob(__DIR__ . '/commercial_invoice_files/' . preg_replace('/([*?\[])/', '[$1]', $clBase) . '_PRICE-CHANGE_*.xlsx');
            ?>
            <div class="message">
                <?php if (!empty($_GET['npSkipped'])): ?>
                    דילגת על המוצרים החדשים — קובץ המוצרים החדשים לא נשמר<br>
                    <?= $pcKept ? 'קובץ שינויי המחיר נשמר' : 'אין קובץ שינויי מחיר' ?>
                <?php else: ?>
                    לא נשמרו שינויי מחיר ולא מוצרים חדשים<br>
                    התהליך הושלם ללא יצירת קבצים נוספים
                <?php endif; ?>
            </div>

            <div class="file-info">
                <p><strong>קובץ CL:</strong> <?= htmlspecialchars($clFileName ?? 'N/A') ?></p>
                <p><strong>חנות:</strong> <?= htmlspecialchars($shopName ?? 'N/A') ?></p>
                <p><strong>תאריך:</strong> <?= date('Y-m-d H:i:s') ?></p>
                <p><strong>מיקום:</strong> commercialLayer/commercial_invoice_files/</p>
            </div>

            <div class="buttons">
                <button id="cleanupBtn" class="button button-primary">Cleanup and continue to next invoice</button>
            </div>

            <div class="completion-message">
                ℹ️ לא נדרשו עדכוני מחירים או מוצרים חדשים
            </div>

        <?php else: ?>
            <h1>תהליך המוצרים החדשים הושלם!</h1>

            <div class="message">
                קובץ המוצרים החדשים נשמר בהצלחה ומוכן ליישום במערכת
            </div>

            <div class="file-info">
                <p><strong>שם הקובץ:</strong> <?= htmlspecialchars($npFileName) ?></p>
                <p><strong>תאריך:</strong> <?= date('Y-m-d H:i:s') ?></p>
                <p><strong>מיקום:</strong> commercialLayer/commercial_invoice_files/</p>
            </div>

            <div class="buttons">
                <button id="cleanupBtn" class="button button-primary">Cleanup and continue to next invoice</button>
            </div>

            <div class="completion-message">
                🎉 שלב ה-Commercial Layer הושלם בהצלחה!
            </div>
        <?php endif; ?>
        </div>
    </div>

    <script>
        document.getElementById('cleanupBtn').addEventListener('click', function() {
            // Disable button
            this.disabled = true;
            this.textContent = '⏳ Cleaning up...';

            // Get file information from URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            const npFile = urlParams.get('npFile');
            const clFile = urlParams.get('cl');
            const shopName = urlParams.get('shop');
            const skip = urlParams.get('skip');

            // Build cleanup URL with file parameters
            let cleanupUrl = '/website/harmonizedFlow/cleanup_process.php?';
            if (npFile) cleanupUrl += 'npFile=' + encodeURIComponent(npFile) + '&';
            if (clFile) cleanupUrl += 'clFile=' + encodeURIComponent(clFile);

            // Call cleanup process
            fetch(cleanupUrl)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Redirect to cleanup results screen
                        const resultsJson = encodeURIComponent(JSON.stringify(data));
                        window.location.href = '/website/harmonizedFlow/cleanup_results.php?results=' + resultsJson;
                    } else {
                        alert('❌ Cleanup error: ' + data.error);
                        this.disabled = false;
                        this.textContent = 'Cleanup and continue to next invoice';
                    }
                })
                .catch(error => {
                    alert('❌ Error: ' + error);
                    this.disabled = false;
                    this.textContent = 'Cleanup and continue to next invoice';
                });
        });
    </script>
</body>
</html>

<?php
/**
 * Retailomatics Invoice Scan — after a document went through the whole flow
 * (harmonizedFlow/cleanup_results.php sends here): back to the first page, where the
 * documents started and not finished, and the ones still waiting, are listed.
 */
session_start();
unset($_SESSION['invoiceScanReturn']);
header('Location: index.php');

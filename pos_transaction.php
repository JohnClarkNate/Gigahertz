<?php
// Fallback entry point for legacy/typo URL: pos_transaction.php (singular).
// This file exists so old links still work without showing a 404 page.

// Build the canonical destination for POS transactions.
$target = 'pos/sales_history.php';

// Send an HTTP redirect to the correct transactions page.
header('Location: ' . $target);

// Stop script execution immediately after sending redirect headers.
exit();

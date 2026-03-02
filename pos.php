<?php

$posRoute = $_GET['route'] ?? 'system';

$posRouteMap = [
    'system' => __DIR__ . '/pos/pos_system.php',
    'checkout' => __DIR__ . '/pos/pos_checkout.php',
    'transactions' => __DIR__ . '/pos/sales_history.php',
];

$targetFile = $posRouteMap[$posRoute] ?? null;

if (!$targetFile || !is_file($targetFile)) {
    http_response_code(404);
    exit('POS route not found.');
}

require $targetFile;

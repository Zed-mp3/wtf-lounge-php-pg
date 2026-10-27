<?php
/**
 * AJAX Cart Update Endpoint
 * Handles cart operations without page reload
 */

require_once __DIR__ . '/includes/auth.php';
configureSession();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// Only logged-in users can access
if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

// CSRF check
if (!isset($_POST['csrf_token']) || !csrfVerifyAjax($_POST['csrf_token'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed']);
    exit;
}

$action = $_POST['action'] ?? '';
$productId = (int) ($_POST['product_id'] ?? 0);
$lineKey = (string) ($_POST['line_key'] ?? '');
$response = ['success' => false];

if ($lineKey !== '' && isset(comboData()[$lineKey]) && in_array($action, ['increment', 'decrement', 'remove'], true)) {
    // Customised budget meal line
    $combo = comboData()[$lineKey];
    $mealProduct = getProduct($pdo, (int) $combo['product_id']);
    $maxQty = $mealProduct ? max(1, (int) $mealProduct['stock']) : 1;
    if ($action === 'increment') {
        comboSetQty($lineKey, min((int) $combo['qty'] + 1, $maxQty));
    } elseif ($action === 'decrement') {
        comboSetQty($lineKey, (int) $combo['qty'] - 1);
    } else {
        comboRemove($lineKey);
    }

    $qty = 0;
    $lineTotal = 0;
    $lines = cartLines($pdo);
    foreach ($lines as $line) {
        if (($line['line_key'] ?? '') === $lineKey) {
            $qty = (int) $line['qty'];
            $lineTotal = $line['line_total'];
            break;
        }
    }
    $response = [
        'success' => true,
        'qty' => $qty,
        'line_total' => $lineTotal,
        'subtotal' => cartSubtotal($pdo),
        'cart_count' => array_sum(array_column($lines, 'qty'))
    ];

} elseif ($action === 'increment' && $productId > 0) {
    $cart = cartData();
    $newQty = ($cart[$productId] ?? 0) + 1;
    $product = getProduct($pdo, $productId);
    if ($product) {
        $newQty = min($newQty, (int) $product['stock']);
    }
    cartSetQty($productId, $newQty);
    
    $lines = cartLines($pdo);
    $lineTotal = 0;
    foreach ($lines as $line) {
        if ($line['product_id'] == $productId) {
            $lineTotal = $line['line_total'];
            break;
        }
    }
    
    $response = [
        'success' => true,
        'qty' => $newQty,
        'line_total' => $lineTotal,
        'subtotal' => cartSubtotal($pdo),
        'cart_count' => array_sum(array_column(cartLines($pdo), 'qty'))
    ];
    
} elseif ($action === 'decrement' && $productId > 0) {
    $cart = cartData();
    $currentQty = $cart[$productId] ?? 0;
    $newQty = max(0, $currentQty - 1);
    
    if ($newQty > 0) {
        cartSetQty($productId, $newQty);
    } else {
        cartRemove($productId);
    }
    
    $lines = cartLines($pdo);
    $lineTotal = 0;
    foreach ($lines as $line) {
        if ($line['product_id'] == $productId) {
            $lineTotal = $line['line_total'];
            break;
        }
    }
    
    $response = [
        'success' => true,
        'qty' => $newQty,
        'line_total' => $lineTotal,
        'subtotal' => cartSubtotal($pdo),
        'cart_count' => array_sum(array_column(cartLines($pdo), 'qty'))
    ];
    
} elseif ($action === 'remove' && $productId > 0) {
    cartRemove($productId);
    
    $response = [
        'success' => true,
        'subtotal' => cartSubtotal($pdo),
        'cart_count' => array_sum(array_column(cartLines($pdo), 'qty'))
    ];
}

header('Content-Type: application/json');
echo json_encode($response);
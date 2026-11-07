<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
session_start();

// Redirect admins to AdminDashboard - they should not access member pages
if (isset($_SESSION['user']) && isset($_SESSION['user']->role) && $_SESSION['user']->role === 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Admins cannot access member payment pages']);
    exit;
}

header('Content-Type: application/json');

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/../../config/stripe_config.php';
require __DIR__ . '/../../database/connection.php';
require __DIR__ . '/../../service/InventoryService.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

error_log("=== PAYMENT PROCESSING START ===");

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $paymentIntentId = $input['paymentIntentId'] ?? null;
    $paymentMethod = $input['paymentMethod'] ?? null;
    $orderId = $input['orderId'] ?? ($_SESSION['pending_order_id'] ?? null);
    $userId = $_SESSION['user_id'] ?? null;

    error_log("Payment Input: paymentIntentId={$paymentIntentId}, paymentMethod={$paymentMethod}, orderId={$orderId}, userId={$userId}");

    // Start database transaction
    $db = new Database();
    $conn = $db->getConnection();
    $conn->beginTransaction();

    // Verify order exists and is pending
    $verifyStmt = $conn->prepare("
        SELECT order_id, total_amount FROM orders 
        WHERE order_id = :order_id AND user_id = :user_id AND order_status = 'pending'
    ");
    $verifyStmt->execute([':order_id' => $orderId, ':user_id' => $userId]);
    $order = $verifyStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found or already processed');
    }

    error_log("Order verified: {$orderId}, total: {$order['total_amount']}");

    if ($paymentIntentId) {
        // Stripe payment flow
        // The intent must be the one we created for this session...
        if (empty($_SESSION['payment_intent_id']) || !hash_equals($_SESSION['payment_intent_id'], (string)$paymentIntentId)) {
            throw new Exception('Payment session mismatch. Please try again.');
        }
        $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
        if ($paymentIntent->status !== 'succeeded') {
            throw new Exception('Payment not completed');
        }
        // ...and it must have been charged for exactly this order's total
        $expectedCents = (int)round(((float)$order['total_amount']) * 100);
        if ((int)$paymentIntent->amount !== $expectedCents
            || strtolower((string)$paymentIntent->currency) !== strtolower(STRIPE_CURRENCY)
            || (string)($paymentIntent->metadata['order_id'] ?? '') !== (string)$orderId) {
            error_log("Payment mismatch for order {$orderId}: intent amount={$paymentIntent->amount}, expected={$expectedCents}");
            throw new Exception('Payment amount does not match the order total.');
        }
        // Update order status to paid
        $orderStmt = $conn->prepare("
            UPDATE orders SET order_status = 'paid'
            WHERE order_id = :order_id
        ");
        $orderStmt->execute([':order_id' => $orderId]);
        
        // Insert payment record with 'paid' status directly (for stock deduction check)
        $paymentStmt = $conn->prepare("
            INSERT INTO payment (order_id, payment_method, payment_status, transaction_id, paid_amount, payment_date)
            VALUES (:order_id, 'credit_card', 'paid', :transaction_id, :paid_amount, NOW())
        ");
        $paymentStmt->execute([
            ':order_id' => $orderId,
            ':transaction_id' => $paymentIntentId,
            ':paid_amount' => $paymentIntent->amount / 100 // Convert from cents
        ]);
        
        error_log("Stripe payment recorded for order {$orderId} with status 'paid'");
    } elseif (in_array($paymentMethod, ['online-banking', 'e-wallet'], true) && !ALLOW_SIMULATED_PAYMENTS) {
        // No real gateway is integrated for these methods, so they must stay off outside demos
        throw new Exception('This payment method is currently unavailable.');
    } elseif ($paymentMethod === 'online-banking') {
        // Simulated payment flow for online banking (FPX)
        $orderStmt = $conn->prepare("
            UPDATE orders SET order_status = 'paid'
            WHERE order_id = :order_id
        ");
        $orderStmt->execute([':order_id' => $orderId]);
        $paymentStmt = $conn->prepare("
            INSERT INTO payment (order_id, payment_method, payment_status, transaction_id, paid_amount, payment_date)
            VALUES (:order_id, 'online-banking', 'paid', NULL, :paid_amount, NOW())
        ");
        $paymentStmt->execute([
            ':order_id' => $orderId,
            ':paid_amount' => $order['total_amount']
        ]);
    } elseif ($paymentMethod === 'e-wallet') {
        // Simulated payment flow for e-wallet
        $orderStmt = $conn->prepare("
            UPDATE orders SET order_status = 'paid'
            WHERE order_id = :order_id
        ");
        $orderStmt->execute([':order_id' => $orderId]);
        $paymentStmt = $conn->prepare("
            INSERT INTO payment (order_id, payment_method, payment_status, transaction_id, paid_amount, payment_date)
            VALUES (:order_id, 'e-wallet', 'paid', NULL, :paid_amount, NOW())
        ");
        $paymentStmt->execute([
            ':order_id' => $orderId,
            ':paid_amount' => $order['total_amount']
        ]);
    } else {
        throw new Exception('Invalid payment method or missing payment intent');
    }

    // Deduct stock after successful payment (CRITICAL: must happen before commit)
    error_log("=== Starting stock deduction for order {$orderId} ===");
    $inventoryService = new InventoryService($conn);
    $deductionResult = $inventoryService->deductStockForPaidOrder($orderId);
    error_log("Stock deduction result for order {$orderId}: " . json_encode($deductionResult));

    // Record voucher usage for this user/order if a voucher was used
    $voucherIdStmt = $conn->prepare("SELECT voucher_id FROM orders WHERE order_id = :order_id AND voucher_id IS NOT NULL");
    $voucherIdStmt->execute([':order_id' => $orderId]);
    $voucherRow = $voucherIdStmt->fetch(PDO::FETCH_ASSOC);
    if ($voucherRow && !empty($voucherRow['voucher_id'])) {
        $insertUsageStmt = $conn->prepare("INSERT INTO voucher_usage (user_id, voucher_id, used_at) VALUES (:user_id, :voucher_id, NOW()) ON DUPLICATE KEY UPDATE used_at = NOW()");
        $insertUsageStmt->execute([
            ':user_id' => $userId,
            ':voucher_id' => $voucherRow['voucher_id']
        ]);
        error_log("Voucher usage recorded for user {$userId}, voucher {$voucherRow['voucher_id']}");
    }

    // Get cart items from order_items to delete from cart
    $itemsStmt = $conn->prepare("
        SELECT oi.product_id, oi.variant_id, oi.size
        FROM order_item oi
        WHERE oi.order_id = :order_id
    ");
    $itemsStmt->execute([':order_id' => $orderId]);
    $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get the user's cart_id
    $cartIdStmt = $conn->prepare("SELECT cart_id FROM shopping_cart WHERE user_id = :user_id LIMIT 1");
    $cartIdStmt->execute([':user_id' => $userId]);
    $cartRow = $cartIdStmt->fetch(PDO::FETCH_ASSOC);
    $cartId = $cartRow ? $cartRow['cart_id'] : null;

    // Remove exactly the purchased cart lines (same product + variant + size).
    // "<=>" is NULL-safe equality, so items without a variant or size still match.
    if ($cartId) {
        $deleteStmt = $conn->prepare("
            DELETE FROM cart_item
            WHERE cart_id = :cart_id
            AND product_id = :product_id
            AND variant_id <=> :variant_id
            AND size <=> :size
        ");
        foreach ($orderItems as $item) {
            $deleteStmt->execute([
                ':cart_id' => $cartId,
                ':product_id' => $item['product_id'],
                ':variant_id' => $item['variant_id'],
                ':size' => $item['size']
            ]);
        }
    }

    $conn->commit();

    // Clear session data
    unset($_SESSION['payment_intent_id']);
    unset($_SESSION['pending_order_data']);
    unset($_SESSION['pending_order_id']);
    unset($_SESSION['checkout_items']);

    error_log("=== PAYMENT SUCCESSFUL ===");
    error_log("Order {$orderId} payment processed successfully");

    echo json_encode([
        'success' => true,
        'orderId' => $orderId,
        'message' => 'Payment successful!'
    ]);
    
} catch (Exception $e) {
    error_log("=== PAYMENT FAILED ===");
    error_log("Error: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    
    if (isset($conn)) {
        try {
            $conn->rollBack();
            error_log("Transaction rolled back");
        } catch (Exception $rollbackError) {
            error_log("Rollback error: " . $rollbackError->getMessage());
        }
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
<?php
error_reporting(0);
ini_set('display_errors', 0);
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

// Set Stripe API key
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        throw new Exception('User not logged in');
    }
    
    // The amount is NEVER taken from the browser: it comes from the pending order
    // in the database, which belongs to this user.
    $orderId = (int)($input['orderId'] ?? ($_SESSION['pending_order_id'] ?? 0));
    if ($orderId <= 0) {
        throw new Exception('Missing order');
    }

    $db = new Database();
    $conn = $db->getConnection();
    $orderStmt = $conn->prepare("
        SELECT order_id, total_amount FROM orders
        WHERE order_id = :order_id AND user_id = :user_id AND order_status = 'pending'
    ");
    $orderStmt->execute([':order_id' => $orderId, ':user_id' => $userId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new Exception('Order not found or already processed');
    }

    $amountInCents = (int)round(((float)$order['total_amount']) * 100); // Stripe uses the smallest unit
    if ($amountInCents <= 0) {
        throw new Exception('Invalid amount');
    }

    // Create Stripe Payment Intent
    $paymentIntent = \Stripe\PaymentIntent::create([
        'amount' => $amountInCents,
        'currency' => STRIPE_CURRENCY,
        'payment_method_types' => ['card'],
        'metadata' => [
            'user_id' => $userId,
            'order_id' => $orderId,
            'order_type' => 'ecommerce'
        ]
    ]);

    // Remember which intent belongs to which order so process_payment can verify it
    $_SESSION['payment_intent_id'] = $paymentIntent->id;
    $_SESSION['pending_order_id'] = $orderId;
    
    echo json_encode([
        'success' => true,
        'clientSecret' => $paymentIntent->client_secret,
        'paymentIntentId' => $paymentIntent->id
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

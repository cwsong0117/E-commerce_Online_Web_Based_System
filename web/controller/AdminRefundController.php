<?php
session_start();

// Check if user is admin
if (!isset($_SESSION['user']) || $_SESSION['user']->role !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

require_once __DIR__ . '/../database/connection.php';
require_once __DIR__ . '/../helpers/ActivityLogger.php';
require_once __DIR__ . '/../lib/PHPMailer.php';
require_once __DIR__ . '/../lib/SMTP.php';

header('Content-Type: application/json');

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $action = $_POST['action'] ?? null;
    $orderId = $_POST['order_id'] ?? null;
    $adminNote = $_POST['admin_note'] ?? '';
    $adminId = $_SESSION['user']->user_id;
    
    // Validate input
    if (!$orderId || !$action) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    
    if (!in_array($action, ['approve', 'reject'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }
    
    // Verify the order exists and has refund_requested status
    $checkQuery = "SELECT o.order_id, o.order_status, o.total_amount, o.user_id, u.email, u.username 
                   FROM orders o 
                   JOIN users u ON o.user_id = u.user_id
                   WHERE o.order_id = :order_id AND o.order_status = 'refund_requested'";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->execute([':order_id' => $orderId]);
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Order not found or not eligible for refund approval']);
        exit;
    }
    
    $conn->beginTransaction();
    
    if ($action === 'approve') {
        // Approve refund
        $newStatus = 'refunded';
        $paymentStatus = 'refunded';
        $noteText = "Refund approved by admin.";
        $successMessage = 'Refund approved successfully';
        
        // Update order status
        $updateQuery = "UPDATE orders SET order_status = :status, updated_at = CURRENT_TIMESTAMP WHERE order_id = :order_id";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->execute([':status' => $newStatus, ':order_id' => $orderId]);
        
        // Update payment status
        $paymentQuery = "UPDATE payment SET payment_status = :payment_status WHERE order_id = :order_id";
        $paymentStmt = $conn->prepare($paymentQuery);
        $paymentStmt->execute([':payment_status' => $paymentStatus, ':order_id' => $orderId]);

        // Give the voucher back to the customer
        require_once __DIR__ . '/../helpers/VoucherHelper.php';
        releaseVoucherForOrder($conn, (int)$orderId);

        // Log refund approval
        ActivityLogger::logOrderRefundApprove($orderId, $adminNote);
        
    } else {
        // Reject refund - return to paid status
        $newStatus = 'paid';
        $noteText = "Refund rejected by admin.";
        $successMessage = 'Refund rejected';
        
        // Update order status back to paid
        $updateQuery = "UPDATE orders SET order_status = :status, updated_at = CURRENT_TIMESTAMP WHERE order_id = :order_id";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->execute([':status' => $newStatus, ':order_id' => $orderId]);
        
        // Log refund rejection
        ActivityLogger::logOrderRefundReject($orderId, $adminNote);
    }
    
    // Add admin note if provided
    if (!empty($adminNote)) {
        $noteText .= " Note: {$adminNote}";
    }
    
    // Insert order note
    $noteQuery = "INSERT INTO order_notes (order_id, admin_id, note_text, created_at) 
                  VALUES (:order_id, :admin_id, :note_text, CURRENT_TIMESTAMP)";
    $noteStmt = $conn->prepare($noteQuery);
    $noteStmt->execute([
        ':order_id' => $orderId,
        ':admin_id' => $adminId,
        ':note_text' => $noteText
    ]);
    
    $conn->commit();
    
    // Send email notification to customer
    // Print recipient email for debugging
    error_log('Refund notification recipient: ' . $order['email']);
    $mail = new PHPMailer();
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = '6403360weihao@gmail.com';
    $mail->Password = 'kkch bjlp clpk kfyw';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom('6403360weihao@gmail.com', 'NGear Admin');
    $mail->addAddress($order['email']);
    $mail->isHTML(true);
    if ($action === 'approve') {
        $mail->Subject = 'Your refund request has been approved';
        $mail->Body = '<p>Dear ' . htmlspecialchars($order['username']) . ',</p>'
            . '<p>Your refund request for order #' . $orderId . ' has been <b>approved</b>. The refund will be processed to your payment method soon.</p>'
            . '<p>If you have any questions, please contact support.</p>';
    } else if ($action === 'reject') {
        $mail->Subject = 'Your refund request has been rejected';
        $mail->Body = '<p>Dear ' . htmlspecialchars($order['username']) . ',</p>'
            . '<p>Your refund request for order #' . $orderId . ' has been <b>rejected</b>. If you have questions, please contact support.</p>';
    }
    $mail->AltBody = strip_tags($mail->Body);
    $mail->send();

    echo json_encode([
        'success' => true,
        'message' => $successMessage,
        'order_id' => $orderId,
        'new_status' => $newStatus
    ]);
    
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    
    error_log("Admin refund action error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while processing the refund action'
    ]);
}

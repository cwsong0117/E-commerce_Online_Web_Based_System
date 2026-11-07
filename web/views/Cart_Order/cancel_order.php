<?php
// Cancel order endpoint for AJAX
header('Content-Type: application/json');
require_once '../../database/connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'])) {
    $orderId = intval($_POST['order_id']);
    session_start();
    $userId = $_SESSION['user_id'] ?? 0;
    $db = new Database();
    $conn = $db->getConnection();

    // Look up the order's current status first: stock is only deducted once an order is
    // paid, so only a previously-paid ('processing') order may have its stock put back.
    $statusStmt = $conn->prepare("SELECT order_status FROM orders WHERE order_id = :order_id AND user_id = :user_id");
    $statusStmt->execute([':order_id' => $orderId, ':user_id' => $userId]);
    $previousStatus = $statusStmt->fetchColumn();

    $stmt = $conn->prepare("UPDATE orders SET order_status = 'canceled' WHERE order_id = :order_id AND user_id = :user_id AND order_status = :previous_status AND order_status IN ('pending', 'processing')");
    $stmt->execute([':order_id' => $orderId, ':user_id' => $userId, ':previous_status' => $previousStatus]);
    if ($stmt->rowCount() > 0 && $previousStatus !== 'processing') {
        // Pending (unpaid) order: nothing was deducted, so there is nothing to restock
        echo json_encode(['success' => true]);
    } elseif ($stmt->rowCount() > 0) {
        // Restock inventory for each item in the order
        require_once '../../service/InventoryService.php';
        $inventoryService = new InventoryService($conn);
        $itemStmt = $conn->prepare("SELECT product_id, variant_id, size, quantity FROM order_item WHERE order_id = :order_id");
        $itemStmt->execute([':order_id' => $orderId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as $item) {
            $inventoryService->restock(
                $item['product_id'],
                $item['variant_id'] !== null ? $item['variant_id'] : null,
                $item['size'] ?? 'default',
                $item['quantity'],
                true
            );
        }
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Unable to cancel order.']);
    }
    exit;
}
echo json_encode(['success' => false, 'error' => 'Invalid request.']);

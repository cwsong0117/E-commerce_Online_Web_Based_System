<?php
session_start();
header('Content-Type: application/json');

require __DIR__ . '/../../database/connection.php';
require_once __DIR__ . '/../../service/InventoryService.php';

try {
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not logged in');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $selectedItemIds = $input['selectedItems'] ?? [];
    $voucher = $input['voucher'] ?? null;
    if (empty($selectedItemIds)) {
        throw new Exception('No items selected');
    }
    $userId = $_SESSION['user_id'];
    
    // Get database connection
    $db = new Database();
    $conn = $db->getConnection();
    $conn->beginTransaction();
    
    // Fetch selected cart items with product details
    $placeholders = implode(',', array_fill(0, count($selectedItemIds), '?'));
    $query = "
        SELECT 
            ci.cart_item_id as id,
            ci.product_id,
            ci.quantity,
            p.product_name as name,
            ci.variant_id,
            ci.size,
            COALESCE(pp.original_price, 0) as price,
            COALESCE(pi.image_path, '../../images/no-image.png') as image
        FROM cart_item ci
        JOIN shopping_cart sc ON ci.cart_id = sc.cart_id
        JOIN product p ON ci.product_id = p.product_id
        LEFT JOIN product_price pp ON p.product_id = pp.product_id
        LEFT JOIN product_image pi ON p.product_id = pi.product_id
        WHERE ci.cart_item_id IN ($placeholders) 
        AND sc.user_id = ?
        GROUP BY ci.cart_item_id
    ";
    
    $params = array_merge($selectedItemIds, [$userId]);
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $cartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($cartItems)) {
        throw new Exception('No valid items found. Selected IDs: ' . implode(',', $selectedItemIds));
    }
    
    // Calculate totals
    $subtotal = 0;
    foreach ($cartItems as $item) {
        $subtotal += $item['price'] * $item['quantity'];
    }
    
    // Make sure every selected item is still in stock BEFORE any payment is taken
    $inventoryService = new InventoryService($conn);
    $inventoryService->assertStockAvailable($cartItems);

    $shippingFee = 10.00;
    $taxRate = 0.06;
    $tax = $subtotal * $taxRate;
    $voucherDiscount = 0;

    // Never trust voucher details sent by the browser: only the voucher id is used,
    // and everything else is loaded from the database and re-validated here.
    $voucherId = null;
    $clientVoucherId = is_array($voucher) ? (int)($voucher['id'] ?? 0) : 0;
    if ($clientVoucherId > 0) {
        $vStmt = $conn->prepare("
            SELECT v.* FROM voucher v
            WHERE v.voucher_id = :voucher_id
              AND v.status = 'active'
              AND v.start_date <= CURDATE()
              AND v.end_date >= CURDATE()
              AND NOT EXISTS (
                  SELECT 1 FROM voucher_usage vu
                  WHERE vu.voucher_id = v.voucher_id AND vu.user_id = :user_id
              )
        ");
        $vStmt->execute([':voucher_id' => $clientVoucherId, ':user_id' => $userId]);
        $voucherRow = $vStmt->fetch(PDO::FETCH_ASSOC);

        if (!$voucherRow) {
            throw new Exception('This voucher is invalid, expired or already used. Please remove it and try again.');
        }
        if ($subtotal < (float)$voucherRow['min_spend']) {
            throw new Exception('Your order does not meet the minimum spend for this voucher.');
        }

        if ($voucherRow['type'] === 'percent') {
            $voucherDiscount = $subtotal * ((float)$voucherRow['discount_value'] / 100);
            if (!empty($voucherRow['max_discount'])) {
                $voucherDiscount = min($voucherDiscount, (float)$voucherRow['max_discount']);
            }
        } elseif ($voucherRow['type'] === 'fixed') {
            $voucherDiscount = (float)$voucherRow['discount_value'];
        } elseif ($voucherRow['type'] === 'freeshipping') {
            $voucherDiscount = $shippingFee;
            $shippingFee = 0;
        }
        // A voucher can never make the order cheaper than free goods
        $voucherDiscount = min($voucherDiscount, $subtotal + $shippingFee + $tax);

        $voucherId = (int)$voucherRow['voucher_id'];
        // Canonical (server-side) voucher data, same shape the confirmation page expects
        $voucher = [
            'id' => $voucherId,
            'code' => $voucherRow['code'],
            'type' => $voucherRow['type'],
            'value' => (float)$voucherRow['discount_value'],
            'minSpend' => (float)$voucherRow['min_spend'],
            'maxDiscount' => $voucherRow['max_discount'] !== null ? (float)$voucherRow['max_discount'] : null,
        ];
    } else {
        $voucher = null;
    }
    $totalAmount = round($subtotal + $shippingFee + $tax - $voucherDiscount, 2);

    // Create pending order
    if ($voucherId) {
        $orderStmt = $conn->prepare("
            INSERT INTO orders (user_id, total_amount, order_status, create_at, voucher_id)
            VALUES (:user_id, :total_amount, 'pending', NOW(), :voucher_id)
        ");
        $orderStmt->execute([
            ':user_id' => $userId,
            ':total_amount' => $totalAmount,
            ':voucher_id' => $voucherId
        ]);
    } else {
        $orderStmt = $conn->prepare("
            INSERT INTO orders (user_id, total_amount, order_status, create_at)
            VALUES (:user_id, :total_amount, 'pending', NOW())
        ");
        $orderStmt->execute([
            ':user_id' => $userId,
            ':total_amount' => $totalAmount
        ]);
    }
    $orderId = $conn->lastInsertId();
    // Debug logging for voucher id
    if ($voucher && array_key_exists('id', $voucher)) {
        error_log('DEBUG: voucher id received: ' . var_export($voucher['id'], true));
    } else {
        error_log('DEBUG: no voucher id received');
    }
    if ($voucher && !empty($voucher['id'])) {
        $voucherId = $voucher['id'];
        error_log('DEBUG: inserting order with voucher_id = ' . $voucherId);
    } else {
        error_log('DEBUG: inserting order without voucher_id');
    }
    
    // Insert order items
    $itemStmt = $conn->prepare("
        INSERT INTO order_item (order_id, product_id, product_name_snapshot, product_price_snapshot, quantity, subtotal, variant_id, size)
        VALUES (:order_id, :product_id, :product_name, :product_price, :quantity, :subtotal, :variant_id, :size)
    ");
    
    foreach ($cartItems as $item) {
        $lineSubtotal = $item['price'] * $item['quantity'];
        $itemStmt->execute([
            ':order_id' => $orderId,
            ':product_id' => $item['product_id'],
            ':product_name' => $item['name'],
            ':product_price' => $item['price'],
            ':quantity' => $item['quantity'],
            ':subtotal' => $lineSubtotal,
            ':variant_id' => $item['variant_id'],
            ':size' => $item['size']
        ]);
    }
    
    $conn->commit();
    
    // Store order data in session for checkout
    $_SESSION['pending_order_id'] = $orderId;
    $_SESSION['pending_order_data'] = [
        'orderId' => $orderId,
        'items' => $cartItems,
        'total_amount' => $totalAmount,
        'subtotal' => $subtotal,
        'shipping' => $shippingFee,
        'tax' => $tax,
        'voucher' => $voucher,
        'voucher_discount' => $voucherDiscount
    ];
    
    echo json_encode([
        'success' => true,
        'orderId' => $orderId,
        'message' => 'Order created successfully'
    ]);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
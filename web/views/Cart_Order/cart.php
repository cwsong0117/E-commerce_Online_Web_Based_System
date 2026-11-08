<?php 
session_start();
require __DIR__ . '/../../database/connection.php';
$db = new Database();
$conn = $db->getConnection();

// Redirect admins to AdminDashboard - they should not access member pages
if (isset($_SESSION['user']) && isset($_SESSION['user']->role) && $_SESSION['user']->role === 'admin') {
    header('Location: ../../views/admin/AdminDashboard.php');
    exit;
}

// Get user_id from session first
if (isset($_SESSION['user']) && isset($_SESSION['user']->user_id)) {
    $_SESSION['user_id'] = $_SESSION['user']->user_id;
} elseif (!isset($_SESSION['user_id'])) {
    header('Location: ../security/login.php');
    exit;
}

// Get user_id from session
$userId = $_SESSION['user_id'];

// ----------------- Accept incoming item from ProductDetails (BEFORE ANY OUTPUT) -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['product_id'])) {
    $pid = (int) ($_POST['product_id'] ?? 0);
    $vid = isset($_POST['variant_id']) && $_POST['variant_id'] !== '' ? (int) $_POST['variant_id'] : null;
    $size = trim($_POST['size'] ?? '');
    $qty = max(1, (int) ($_POST['quantity'] ?? 1));
    $isAjax = (!empty($_POST['ajax']) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'));

    // fetch product name & price
    $pStmt = $conn->prepare("SELECT p.product_id, p.product_name, pr.original_price 
    FROM product p 
    LEFT JOIN product_price pr 
    ON p.product_id = pr.product_id 
    WHERE p.product_id = :pid 
    LIMIT 1");
    $pStmt->execute([':pid' => $pid]);
    $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);

    if ($pRow) {
        // First, ensure user has a shopping cart
        $cartCheckStmt = $conn->prepare("SELECT cart_id FROM shopping_cart WHERE user_id = :user_id");
        $cartCheckStmt->execute([':user_id' => $userId]);
        $cartRow = $cartCheckStmt->fetch(PDO::FETCH_ASSOC);

        if (!$cartRow) {
            // Create shopping cart for user
            $createCartStmt = $conn->prepare("INSERT INTO shopping_cart (user_id) VALUES (:user_id)");
            $createCartStmt->execute([':user_id' => $userId]);
            $cartId = $conn->lastInsertId();
        } else {
            $cartId = $cartRow['cart_id'];
        }

        // Check if the item (product_id, variant_id, size) already exists in the cart
        $checkItemStmt = $conn->prepare("SELECT cart_item_id, quantity FROM cart_item WHERE cart_id = :cart_id AND product_id = :product_id AND ((variant_id IS NULL AND :variant_id IS NULL) OR (variant_id = :variant_id)) AND size = :size");
        $checkItemStmt->execute([
            ':cart_id' => $cartId,
            ':product_id' => $pid,
            ':variant_id' => $vid,
            ':size' => $size
        ]);
        $existingItem = $checkItemStmt->fetch(PDO::FETCH_ASSOC);

        // Don't let the cart hold more than the stock left
        require_once __DIR__ . '/../../service/InventoryService.php';
        $inventory = new InventoryService($conn);
        $stock = $inventory->getAvailableStock($pid, $vid, $size);
        $inCart = $existingItem ? (int)$existingItem['quantity'] : 0;
        if ($inCart + $qty > $stock) {
            $qty = $stock - $inCart;
        }
        if ($qty <= 0) {
            $message = 'You already have all the available stock of this item in your cart.';
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $message]);
                exit;
            }
            $_SESSION['error_message'] = $message;
            header('Location: cart.php');
            exit;
        }

        if ($existingItem) {
            // If exists, just update the quantity
            $newQty = $existingItem['quantity'] + $qty;
            $updateStmt = $conn->prepare("UPDATE cart_item SET quantity = :quantity WHERE cart_item_id = :cart_item_id");
            $updateStmt->execute([
                ':quantity' => $newQty,
                ':cart_item_id' => $existingItem['cart_item_id']
            ]);
        } else {
            // Insert new item
            $insertStmt = $conn->prepare("INSERT INTO cart_item (cart_id, product_id, variant_id, size, quantity) VALUES (:cart_id, :product_id, :variant_id, :size, :quantity)");
            $insertStmt->execute([
                ':cart_id' => $cartId,
                ':product_id' => $pid,
                ':variant_id' => $vid,
                ':size' => $size,
                ':quantity' => $qty
            ]);
        }

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Item added to cart']);
            exit;
        }

        // Redirect to cart page to avoid form resubmission and show updated cart
        header('Location: cart.php');
        exit;
    }
}

// Now include header and navbar AFTER redirect logic
$pageTitle = "Shopping Cart";
include '../../general/_header.php'; 
include '../../general/_navbar.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
<?php 


// query of fetching vouchers from db, excluding those already used by this user
$voucherQuery = "SELECT v.* FROM voucher v 
WHERE v.status = 'active'
    AND v.start_date <= CURDATE()
    AND v.end_date >= CURDATE()
    AND NOT EXISTS (
        SELECT 1 FROM voucher_usage vu
        WHERE vu.voucher_id = v.voucher_id
            AND vu.user_id = :user_id
    )
ORDER BY v.type, v.min_spend";
$voucherStmt = $conn->prepare($voucherQuery);
$voucherStmt->execute([':user_id' => $userId]);
// fetch all vouchers as an array
$vouchers = $voucherStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch cart items from database
$cartItems = [];
    if (isset($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
        
        // Query to fetch cart items with variant details and correct image
        $cartQuery = "
        SELECT 
            ci.cart_item_id,
            ci.product_id,
            ci.variant_id,
            ci.size,
            ci.quantity,
            p.product_name,
            pv.color,
            pp.original_price,
            COALESCE(

                (SELECT pi.image_path FROM product_image pi 
                WHERE pi.variant_id = ci.variant_id 
                LIMIT 1),

                (SELECT pi.image_path FROM product_image pi 
                WHERE pi.product_id = ci.product_id AND pi.type = 'main' 
                LIMIT 1)
                
            ) AS image_path
        FROM cart_item ci
        JOIN shopping_cart sc ON ci.cart_id = sc.cart_id
        JOIN product p ON ci.product_id = p.product_id
        LEFT JOIN product_variant pv ON ci.variant_id = pv.variant_id
        LEFT JOIN product_price pp ON p.product_id = pp.product_id
        WHERE sc.user_id = :user_id
        GROUP BY ci.cart_item_id
        ORDER BY ci.cart_item_id DESC
        ";
        
    $cartStmt = $conn->prepare($cartQuery);
    $cartStmt->execute([':user_id' => $userId]);
    $dbCartItems = $cartStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format cart items for display
    // Format cart items for display
    foreach ($dbCartItems as $item) {
        $rawPath = $item['image_path']; // The path exactly as it comes from SQL
        
        // 1. Set default if empty
        $imgPath = !empty($rawPath) ? $rawPath : 'products/default.jpg';

        // 2. Process the path if it's not the default
        if ($imgPath !== 'products/default.jpg') {
            // stipe the "web/" prefix if it exists in DB path
            if (strpos($imgPath, 'web/') === 0) {
            $imgPath = substr($imgPath, 4); // Removes "web/"
            }

            // handle the absolute path cleaning
            if(preg_match('#[a-zA-Z]:\\\\|/#', $imgPath)) {
                $imgPath = preg_replace('#.*images[\\\\/]#', 'images/', $imgPath);
            }

            // fix slashes for web
            $imgPath = str_replace('\\', '/', $imgPath);

            // add redirect prefix
            $imgPath = '../../' . $imgPath;
        }

        // --- DEBUGGING BLOCK ---
        // This prints to your Browser Console (F12 > Console)
        echo "<script>
            console.group('Cart Item Debug: " . addslashes($item['product_name']) . "');
            console.log('Product ID:', " . $item['product_id'] . ");
            console.log('Variant ID:', '" . ($item['variant_id'] ?? 'NULL') . "');
            console.log('Raw Path from DB:', '" . addslashes($rawPath) . "');
            console.log('Processed Path for HTML:', '" . addslashes($imgPath) . "');
            console.groupEnd();
        </script>";
        // --- END DEBUGGING ---

        $variantLabel = !empty($item['color']) ? $item['color'] : 'Null';
        $cartItems[] = [
            'id' => $item['cart_item_id'],
            'image' => $imgPath,
            'name' => $item['product_name'],
            'variant' => $variantLabel,
            'size' => $item['size'] ?? '',
            'price' => (float) ($item['original_price'] ?? 0),
            'quantity' => (int) $item['quantity']
        ];
    }
}

// calculate initial values from the cart items
// array_column() gets the 'quantity' values from each item
// array_sum() adds them all together
$cartItemCount = array_sum(array_column($cartItems, 'quantity'));

// calculate subtotal by looping through each item
$subtotal = 0;
foreach ($cartItems as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}

// set fixed values for shipping and tax
$shippingFee = 15.00;
$tax = $subtotal * 0.06; // 6% tax
$grandTotal = $subtotal + $shippingFee + $tax;

?>

<link rel="stylesheet" href="../../css/cart.css">

<div class="container">
    <h1>Your Shopping Cart</h1>
        
    <!-- create dynamic cart message with IDs for JavaScript control -->
    <!-- The span elements allow JavaScript to update specific parts -->
    <p class="cart-count-message" id="cart-message">
        You have <strong><span id="cart-item-count"><?= $cartItemCount ?></span></strong> item<span id="item-plural"><?= $cartItemCount !== 1 ? 's' : '' ?></span> in your shopping cart.
    </p>

    <div class="cart-layout">
        <!-- Cart Items Section -->
        <div class="cart-items-section">
            <div style="margin-bottom:1rem; display:flex; align-items:center; gap:1rem;">
                <button id="delete-selected-btn" class="btn btn-danger" style="display:flex; align-items:center; gap:0.5rem;">
                    <span class="material-symbols-outlined">delete</span> Delete Selected
                </button>
            </div>
            <table class="cart-table">
                <thead>
                    <tr>
                        <th>
                            <input type="checkbox" id="select-all" class="select-checkbox" title="Select All">
                        </th>
                        <th>Item</th>
                        <th>Price</th>
                        <th>Quantity</th>
                        <th>Total</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cartItems as $item): ?>
                    <tr data-item-id="<?= $item['id'] ?>">
                        <td class="item-select">
                            <input type="checkbox" class="item-checkbox" data-item-id="<?= $item['id'] ?>">
                        </td>
                        <td class="item-details">
                            <div class="item-info">
                                <img src="<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="item-image">
                                <div class="item-text">
                                    <h4><?= htmlspecialchars($item['name']) ?></h4>
                                        <p class="item-variant">Variant: <?= htmlspecialchars($item['variant']) ?></p>
                                        <p class="item-size">Size: <?= htmlspecialchars($item['size']) ?></p>
                                </div>
                            </div>
                        </td>
                        
                        <td class="item-price">RM <?= number_format($item['price'], 2) ?></td>
                        <td class="item-quantity">
                            <div class="quantity-controls">
                                <button class="qty-btn minus-btn" data-item-id="<?= $item['id'] ?>">-</button>
                                <span class="qty-display"><?= $item['quantity'] ?></span>
                                <button class="qty-btn plus-btn" data-item-id="<?= $item['id'] ?>">+</button>
                            </div>
                        </td>
                        <td class="item-total">RM <?= number_format($item['price'] * $item['quantity'], 2) ?></td>
                        <td class="item-action">
                            <button class="remove-btn" data-item-id="<?= $item['id'] ?>" title="Remove item">
                                <span class="material-symbols-outlined">delete</span>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Continue Shopping Section -->
            <div class="continue-shopping">
                <a href="/index.php" class="continue-shopping-link">
                    <span class="arrow-left">←</span>
                    <span>Continue Shopping</span>
                </a>
            </div>
        </div>

        <!-- Order Summary Section -->
        <div class="order-summary-section">
            <div class="order-summary">
                <h3>Order Summary</h3>
                
                <div class="summary-line">
                    <span>Subtotal:</span>
                    <span>RM <?= number_format($subtotal, 2) ?></span>
                </div>
                
                <div class="summary-line">
                    <span>Shipping Fee:</span>
                    <span>RM <?= number_format($shippingFee, 2) ?></span>
                </div>
                
                <div class="summary-line">
                    <span>Tax (6%):</span>
                    <span>RM <?= number_format($tax, 2) ?></span>
                </div>

                <!-- Voucher discount (hidden by default, shown by JavaScript when applied) -->
                <div class="summary-line voucher-discount-applied">
                    <span>Voucher Discount:</span>
                    <span id="voucher-discount-amount">- RM 0.00</span>
                </div>
                
                <hr>
                
                <div class="summary-line total">
                    <span><strong>Grand Total:</strong></span>
                    <span><strong>RM <?= number_format($grandTotal, 2) ?></strong></span>
                </div>
                
                <div class="promo-section">  


                
                    <h4>Apply Voucher</h4>
                    <!--Select the voucher-->
                    <button type="button" class="select-voucher-btn" id="selectVoucherBtn">
                        <i class="fas fa-ticket-alt"></i> Select Available Voucher
                    </button>
                    
                    <!-- Applied Voucher Display -->
                    <div id="appliedVoucher" class="applied-voucher" style="display: none;">
                        <div class="voucher-info">
                            <span class="voucher-label"></span>
                            <button class="remove-voucher-btn" id="removeVoucherBtn">&times;</button>
                        </div>
                    </div>
                </div>
                
                <button type="button" class="checkout-btn" id="checkout-btn">
                    Proceed to Checkout
                </button>
            </div>
        </div>
    </div>
</div>

<!-- This section shows what is inside the modal after users click the select voucher button -->
<!-- Voucher Selection Modal -->
<div id="voucherModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Select Voucher</h3>
            <span class="close">&times;</span>
        </div>
        <div class="modal-body">
            <?php if (empty($vouchers)): ?>
                <p class="no-vouchers">No vouchers available at the moment.</p>
            <?php else: ?>
                <div class="voucher-list">
                    <?php foreach ($vouchers as $voucher): ?>
                            <div class="voucher-card" 
                                data-id="<?= htmlspecialchars($voucher['voucher_id']) ?>"
                                data-code="<?= htmlspecialchars($voucher['code']) ?>"
                                data-type="<?= htmlspecialchars($voucher['type']) ?>"
                                data-value="<?= htmlspecialchars($voucher['discount_value']) ?>"
                                data-min="<?= htmlspecialchars($voucher['min_spend']) ?? '' ?>"
                                data-max="<?= htmlspecialchars($voucher['max_discount'] ?? '') ?>">
                        <div class="voucher-icon">
                                <i class="fas fa-ticket-alt"></i>
                            </div>
                            <div class="voucher-details">
                                <h4><?= htmlspecialchars($voucher['code']) ?></h4>
                                <p class="voucher-desc"><?= htmlspecialchars($voucher['description']) ?></p>
                                <div class="voucher-info-row">
                                    <span class="voucher-discount">
                                        <?php 
                                        if ($voucher['type'] == 'percent') {
                                            echo number_format($voucher['discount_value'], 0) . '% OFF';
                                        } elseif ($voucher['type'] == 'fixed') {
                                            echo 'RM ' . number_format($voucher['discount_value'], 2) . ' OFF';
                                        } else {
                                            echo 'FREE SHIPPING';
                                        }
                                        ?>
                                    </span>
                                    <?php if ($voucher['min_spend'] > 0): ?>
                                        <span class="voucher-min">Min: RM <?= number_format($voucher['min_spend'], 2) ?></span>
                                    <?php endif; ?>
                                </div>
                                <p class="voucher-validity">Valid until: <?= date('d M Y', strtotime($voucher['end_date'])) ?></p>
                            </div>
                            <button class="use-voucher-btn">Use</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
    <?php if(isset($_SESSION['user_id'])): ?>
        console.log('User is logged in with user_id: <?= $_SESSION['user_id'] ?>');
    <?php else: ?>
        console.log('User is not logged in.');
    <?php endif; ?>
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="../../js/cart.js"></script>

<?php include '../../general/_footer.php'; ?>
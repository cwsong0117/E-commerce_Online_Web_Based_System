<?php
session_start();
require __DIR__ . '/../database/connection.php';
require_once __DIR__ . '/../service/InventoryService.php';

header('Content-Type: application/json');

class CartController {
        public function batchDeleteCartItems() {
            $userId = $this->getUserId();
            if (!$userId) {
                echo json_encode(['success' => false, 'message' => 'User not logged in']);
                return;
            }
            $ids = $_POST['cart_item_ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                echo json_encode(['success' => false, 'message' => 'No items selected']);
                return;
            }
            // Sanitize IDs
            $ids = array_map('intval', $ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $deleteQuery = "DELETE ci FROM cart_item ci JOIN shopping_cart sc ON ci.cart_id = sc.cart_id WHERE ci.cart_item_id IN ($placeholders) AND sc.user_id = ?";
            $stmt = $this->conn->prepare($deleteQuery);
            $params = array_merge($ids, [$userId]);
            $stmt->execute($params);
            echo json_encode(['success' => $stmt->rowCount() > 0]);
        }
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    private function getUserId() {
        if (isset($_SESSION['user']) && isset($_SESSION['user']->user_id)) {
            return $_SESSION['user']->user_id;
        } elseif (isset($_SESSION['user_id'])) {
            return $_SESSION['user_id'];
        }
        return null;
    }
    
    public function deleteCartItem() {
        $userId = $this->getUserId();
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'User not logged in']);
            return;
        }
        
        $cartItemId = (int) ($_POST['cart_item_id'] ?? 0);
        if ($cartItemId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid cart item ID']);
            return;
        }
        
        $deleteQuery = "DELETE ci FROM cart_item ci 
                        JOIN shopping_cart sc ON ci.cart_id = sc.cart_id 
                        WHERE ci.cart_item_id = :cart_item_id 
                        AND sc.user_id = :user_id";
        
        $stmt = $this->conn->prepare($deleteQuery);
        $stmt->execute([
            ':cart_item_id' => $cartItemId,
            ':user_id' => $userId
        ]);
        
        echo json_encode(['success' => $stmt->rowCount() > 0]);
    }
    
    public function updateQuantity() {
        $userId = $this->getUserId();
        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'User not logged in']);
            return;
        }
        
        $cartItemId = (int) ($_POST['cart_item_id'] ?? 0);
        $quantity = max(1, min(99, (int) ($_POST['quantity'] ?? 1)));
        
        if ($cartItemId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid cart item ID']);
            return;
        }
        
        // Don't allow more than the stock left
        $itemStmt = $this->conn->prepare("SELECT ci.product_id, ci.variant_id, ci.size
                        FROM cart_item ci
                        JOIN shopping_cart sc ON ci.cart_id = sc.cart_id
                        WHERE ci.cart_item_id = :cart_item_id AND sc.user_id = :user_id");
        $itemStmt->execute([':cart_item_id' => $cartItemId, ':user_id' => $userId]);
        $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            echo json_encode(['success' => false, 'message' => 'Cart item not found']);
            return;
        }
        $inventory = new InventoryService($this->conn);
        $stock = $inventory->getAvailableStock($item['product_id'], $item['variant_id'], $item['size']);
        if ($quantity > $stock) {
            $quantity = max(1, $stock);
        }

        $updateQuery = "UPDATE cart_item ci
                        JOIN shopping_cart sc ON ci.cart_id = sc.cart_id
                        SET ci.quantity = :quantity
                        WHERE ci.cart_item_id = :cart_item_id
                        AND sc.user_id = :user_id";
        
        $stmt = $this->conn->prepare($updateQuery);
        $stmt->execute([
            ':quantity' => $quantity,
            ':cart_item_id' => $cartItemId,
            ':user_id' => $userId
        ]);
        
        // rowCount is 0 when the quantity did not change, so always report success here
        echo json_encode(['success' => true, 'quantity' => $quantity, 'stock' => $stock]);
    }
}

// Initialize controller
$db = new Database();
$conn = $db->getConnection();
$controller = new CartController($conn);

// Route based on action
$action = $_POST['action'] ?? '';

switch ($action) {
        case 'batch_delete':
            $controller->batchDeleteCartItems();
            break;
    case 'delete':
        $controller->deleteCartItem();
        break;
    case 'updateQuantity':
        $controller->updateQuantity();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

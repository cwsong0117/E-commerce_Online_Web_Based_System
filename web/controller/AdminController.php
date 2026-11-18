<?php
session_start();
require_once __DIR__ . '/../database/connection.php';
require_once __DIR__ . '/../repository/AdminRepository.php';
require_once __DIR__ . '/../service/AdminService.php';
require_once __DIR__ . '/../DTO/AdminDTO.php';
require_once __DIR__ . '/../helpers/ActivityLogger.php';

class AdminController
{
    private $adminService;

    public function __construct()
    {
        $database = new Database();
        $repository = new AdminRepository($database);
        $this->adminService = new AdminService($repository);
    }

    private function requireAdmin(): void
    {
        if (!isset($_SESSION['user']) || $_SESSION['user']->role !== 'admin') {
            $_SESSION['error_message'] = 'You must be logged in as admin to access this page.';
            header('Location: ../views/security/login.php');
            exit;
        }
    }

    // Displays all admins with pagination, search, and filtering options
    public function showAllAdmins(): void
    {
        $this->requireAdmin();

        if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
            $this->getAdminsAjax();
            return;
        }

        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
        $sortBy = isset($_GET['sortBy']) ? $_GET['sortBy'] : 'created_at';
        $sortOrder = isset($_GET['sortOrder']) ? strtoupper($_GET['sortOrder']) : 'DESC';
        $status = isset($_GET['status']) ? trim($_GET['status']) : '';

        if ($page < 1) { $page = 1; }
        if ($limit < 1) { $limit = 10; }
        if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') { $sortOrder = 'DESC'; }

        try {
            $data = $this->adminService->getAllAdmins($page, $limit, $searchTerm, $sortBy, $sortOrder, $status);
            $admins = $data['admins'];
            $pagination = $data['pagination'];
            $currentSort = ['sortBy' => $sortBy, 'sortOrder' => $sortOrder];
        } catch (Exception $e) {
            $admins = [];
            $pagination = [];
            $currentSort = ['sortBy' => 'created_at', 'sortOrder' => 'DESC'];
            $_SESSION['error_message'] = $e->getMessage();
        }

        require_once __DIR__ . '/../views/admin/AllAdmins.php';
    }

    // Returns admin data as JSON for AJAX requests
    private function getAdminsAjax(): void
    {
        $this->requireAdmin();

        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
        $sortBy = isset($_GET['sortBy']) ? $_GET['sortBy'] : 'created_at';
        $sortOrder = isset($_GET['sortOrder']) ? strtoupper($_GET['sortOrder']) : 'DESC';
        $status = isset($_GET['status']) ? trim($_GET['status']) : '';

        if ($page < 1) { $page = 1; }
        if ($limit < 1) { $limit = 10; }
        if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') { $sortOrder = 'DESC'; }

        try {
            $data = $this->adminService->getAllAdmins($page, $limit, $searchTerm, $sortBy, $sortOrder, $status);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'admins' => $data['admins'],
                'pagination' => $data['pagination'],
                'sortBy' => $sortBy,
                'sortOrder' => $sortOrder,
                'status' => $status
            ]);
            exit;
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }

    // Creates a new admin account
    public function createAdmin(): void
    {
        $this->requireAdmin();

        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $username = isset($_POST['username']) ? trim($_POST['username']) : '';
                $password = isset($_POST['password']) ? $_POST['password'] : '';
                $repeatPassword = isset($_POST['repeat_password']) ? $_POST['repeat_password'] : '';
                $fullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';
                $contactNo = isset($_POST['contact_no']) ? trim($_POST['contact_no']) : '';
                $gender = isset($_POST['gender']) ? trim($_POST['gender']) : '';

                if ($username === '' || $password === '' || $repeatPassword === '' || $fullName === '' || $email === '') {
                    throw new Exception('Username, password, full name, and email are required.');
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email format.');
                }

                // Validate Malaysian phone number (10-11 digits)
                $contactNo = $contactNo === '' ? '' : trim($contactNo);
                if ($contactNo !== '') {
                    $contact_digits = preg_replace('/\D+/', '', $contactNo);
                    $len = strlen($contact_digits);
                    if ($len < 10 || $len > 11) {
                        throw new Exception("Invalid phone number format. Please enter a valid Malaysian phone number (10-11 digits).");
                    }
                    $contactNo = $contact_digits; // Store digits only
                }

                $adminDTO = new AdminRegistrationDTO(
                    $username,
                    $password,
                    $repeatPassword,
                    $fullName,
                    $gender,
                    $contactNo,
                    $email
                );

                $newAdminId = $this->adminService->registerAdmin($adminDTO);

                if ($newAdminId) {
                    // Log the admin creation using the returned admin ID
                    ActivityLogger::logAdminCreate($newAdminId, $username);
                    
                    $_SESSION['success_message'] = 'Admin created successfully.';
                } else {
                    throw new Exception('Failed to create admin. Please try again.');
                }

                header('Location: ../controller/AdminController.php?action=showAll');
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../controller/AdminController.php?action=showAll');
            exit;
        }
    }

    // Updates admin profile information
    public function updateAdmin(): void
    {
        $this->requireAdmin();

        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (!isset($_POST['user_id']) || empty($_POST['user_id'])) {
                    throw new Exception('User ID is required');
                }

                $username = isset($_POST['username']) ? trim($_POST['username']) : '';
                $fullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';
                $contactNo = isset($_POST['contact_no']) ? trim($_POST['contact_no']) : '';
                $gender = isset($_POST['gender']) ? trim($_POST['gender']) : '';

                if ($fullName === '' || $email === '') {
                    throw new Exception('Full name and email are required.');
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email format.');
                }

                // Validate Malaysian phone number (10-11 digits)
                $contactNo = $contactNo === '' ? '' : trim($contactNo);
                if ($contactNo !== '') {
                    $contact_digits = preg_replace('/\D+/', '', $contactNo);
                    $len = strlen($contact_digits);
                    if ($len < 10 || $len > 11) {
                        throw new Exception("Invalid phone number format. Please enter a valid Malaysian phone number (10-11 digits).");
                    }
                    $contactNo = $contact_digits; // Store digits only
                }

                // Get old values before update for logging
                $database = new Database();
                $repository = new AdminRepository($database);
                $oldAdmin = $repository->getAdminById((int)$_POST['user_id']);
                $oldValues = $oldAdmin ? [
                    'full_name' => $oldAdmin['full_name'],
                    'email' => $oldAdmin['email'],
                    'gender' => $oldAdmin['gender'],
                    'contact_no' => $oldAdmin['contact_no']
                ] : null;

                $adminDTO = new AdminUpdateDTO(
                    (int)$_POST['user_id'],
                    $username,
                    $fullName,
                    $email,
                    $gender,
                    $contactNo
                );

                $result = $this->adminService->updateAdmin($adminDTO);

                if ($result) {
                    // Log the update
                    $newValues = [
                        'full_name' => $fullName,
                        'email' => $email,
                        'gender' => $gender,
                        'contact_no' => $contactNo
                    ];
                    ActivityLogger::logAdminUpdate((int)$_POST['user_id'], $username, $oldValues, $newValues);
                    
                    $_SESSION['success_message'] = 'Admin updated successfully.';
                }

                header('Location: ../controller/AdminController.php?action=showAll');
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../controller/AdminController.php?action=showAll');
            exit;
        }
    }

    // Updates admin account status
    public function updateAdminStatus(): void
    {
        $this->requireAdmin();

        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
                $status = isset($_POST['status']) ? trim($_POST['status']) : '';

                if ($userId <= 0 || $status === '') {
                    throw new Exception('User ID and status are required');
                }

                // Get old status before update
                $database = new Database();
                $repository = new AdminRepository($database);
                $oldAdmin = $repository->getAdminById($userId);
                $oldStatus = $oldAdmin ? $oldAdmin['status'] : null;
                $username = $oldAdmin ? $oldAdmin['username'] : 'Unknown';

                $result = $this->adminService->updateAdminStatus($userId, $status);

                if ($result) {
                    // Log status change
                    if ($oldStatus && $oldStatus !== $status) {
                        ActivityLogger::logAdminStatusChange($userId, $username, $oldStatus, $status);
                    }
                    
                    $_SESSION['success_message'] = 'Status updated successfully.';
                } else {
                    $_SESSION['error_message'] = 'No changes were made.';
                }

                header('Location: ../controller/AdminController.php?action=showAll');
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../controller/AdminController.php?action=showAll');
            exit;
        }
    }

    // Deletes an admin account
    public function deleteAdmin(): void
    {
        $this->requireAdmin();

        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

                if ($userId <= 0) {
                    throw new Exception('Invalid user ID');
                }

                // Get admin info before delete for logging
                $database = new Database();
                $repository = new AdminRepository($database);
                $adminToDelete = $repository->getAdminById($userId);
                $username = $adminToDelete ? $adminToDelete['username'] : 'Unknown';

                $result = $this->adminService->deleteAdmin($userId);

                if ($result) {
                    // Log deletion
                    ActivityLogger::logAdminDelete($userId, $username);
                    
                    $_SESSION['success_message'] = 'Admin deleted successfully.';
                } else {
                    $_SESSION['error_message'] = 'Unable to delete admin.';
                }

                header('Location: ../controller/AdminController.php?action=showAll');
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../controller/AdminController.php?action=showAll');
            exit;
        }
    }


    // Retrieves refund reason for an order
    public function getRefundReason(): void
    {
        $this->requireAdmin();

        header('Content-Type: application/json');

        try {
            $orderId = $_GET['order_id'] ?? null;

            if (!$orderId) {
                throw new Exception('Order ID is required');
            }

            $database = new Database();
            $conn = $database->getConnection();

            // Get the refund request note from order_notes
            $stmt = $conn->prepare("
                SELECT note_text, created_at 
                FROM order_notes 
                WHERE order_id = :order_id 
                AND note_text LIKE 'Refund requested by customer%'
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([':order_id' => $orderId]);
            $note = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$note) {
                throw new Exception('No refund request found for this order');
            }

            // Parse the note to extract reason and details
            $noteText = $note['note_text'];
            $reason = 'Not specified';
            $details = '';

            // Extract reason (format: "Refund requested by customer. Reason: XXX")
            if (preg_match('/Reason: ([^.]+)/', $noteText, $matches)) {
                $reason = trim($matches[1]);
            }

            // Extract details (format: ". Details: XXX")
            if (preg_match('/\. Details: (.+)$/s', $noteText, $matches)) {
                $details = trim($matches[1]);
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'reason' => $reason,
                    'details' => $details,
                    'created_at' => date('M d, Y H:i', strtotime($note['created_at']))
                ]
            ]);
            exit;

        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
            exit;
        }
    }

    // Updates order status and payment status
    public function updateOrderStatus(): void
    {
        $this->requireAdmin();

        header('Content-Type: application/json');

        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method');
            }

            $orderId = $_POST['order_id'] ?? null;
            $orderStatus = $_POST['order_status'] ?? null;
            $paymentStatus = $_POST['payment_status'] ?? null;
            $trackingNumber = $_POST['tracking_number'] ?? null;
            $adminNotes = $_POST['admin_notes'] ?? null;
            $sendEmail = isset($_POST['send_email']);

            if (!$orderId) {
                throw new Exception('Order ID is required');
            }

            if (!$orderStatus) {
                throw new Exception('Order status is required');
            }

            $database = new Database();
            $conn = $database->getConnection();

            // Get order details before update
            $stmt = $conn->prepare("SELECT o.*, u.email, u.username FROM orders o LEFT JOIN users u ON o.user_id = u.user_id WHERE o.order_id = :order_id");
            $stmt->execute([':order_id' => $orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new Exception('Order not found');
            }

            // Store old status for logging
            $oldOrderStatus = $order['order_status'];


            // Build update query for orders table
            $updateFields = ['order_status = :order_status'];
            $params = [
                ':order_id' => $orderId,
                ':order_status' => $orderStatus
            ];

            if ($trackingNumber) {
                $updateFields[] = 'tracking_number = :tracking_number';
                $params[':tracking_number'] = $trackingNumber;
            }

            // Update orders table
            $updateQuery = "UPDATE orders SET " . implode(', ', $updateFields) . " WHERE order_id = :order_id";
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->execute($params);

            // A paid order that is cancelled or refunded by an admin gives the voucher back
            if (in_array($orderStatus, ['canceled', 'refunded'], true)
                && !in_array($oldOrderStatus, ['pending', 'canceled', 'refunded'], true)) {
                require_once __DIR__ . '/../helpers/VoucherHelper.php';
                releaseVoucherForOrder($conn, (int)$orderId);
            }

            // Update payment_status in payment table if provided
            if ($paymentStatus) {
                $paymentUpdateStmt = $conn->prepare("UPDATE payment SET payment_status = :payment_status WHERE order_id = :order_id");
                $paymentUpdateStmt->execute([
                    ':payment_status' => $paymentStatus,
                    ':order_id' => $orderId
                ]);
            }
            // Log order status change
            if ($oldOrderStatus !== $orderStatus) {
                ActivityLogger::logOrderStatusChange($orderId, $oldOrderStatus, $orderStatus);
            }

            // Insert admin notes if provided
            if ($adminNotes) {
                $notesStmt = $conn->prepare("
                    INSERT INTO order_notes (order_id, admin_id, note_text, created_at) 
                    VALUES (:order_id, :admin_id, :note_text, NOW())
                ");
                $notesStmt->execute([
                    ':order_id' => $orderId,
                    ':admin_id' => $_SESSION['user']->user_id,
                    ':note_text' => $adminNotes
                ]);
            }

            // Send email notification if requested
            if ($sendEmail && $order['email']) {
                // TODO: Implement email notification
                // This would use PHPMailer to send status update email
                // For now, we'll just log it
                error_log("Email notification would be sent to: " . $order['email']);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Order status updated successfully'
            ]);
            exit;

        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
            exit;
        }
    }
}

$controller = new AdminController();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? $_GET['action'] ?? 'create';

    if ($action === 'create') {
        $controller->createAdmin();
    } elseif ($action === 'update') {
        $controller->updateAdmin();
    } elseif ($action === 'updateStatus') {
        $controller->updateAdminStatus();
    } elseif ($action === 'delete') {
        $controller->deleteAdmin();
    } elseif ($action === 'updateOrderStatus') {
        $controller->updateOrderStatus();
    }
} else {
    $action = $_GET['action'] ?? 'showAll';

    if ($action === 'showAll') {
        $controller->showAllAdmins();
    } elseif ($action === 'updateOrderStatus') {
        $controller->updateOrderStatus();
    } elseif ($action === 'getRefundReason') {
        $controller->getRefundReason();
    }
}

<?php
session_start();
require_once __DIR__ . '/../database/connection.php';
require_once __DIR__ . '/../repository/VoucherRepository.php';
require_once __DIR__ . '/../service/VoucherService.php';
require_once __DIR__ . '/../DTO/VoucherDTO.php';
require_once __DIR__ . '/../helpers/ActivityLogger.php';

// Composer autoload for external libraries (e.g., endroid/qr-code)
require_once __DIR__ . '/../../vendor/autoload.php';

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\RoundBlockSizeMode;

class VoucherController
{
    private $voucherService;

    public function __construct()
    {
        $database = new Database();
        $voucherRepository = new VoucherRepository($database);
        $this->voucherService = new VoucherService($voucherRepository);
    }

    // Displays all vouchers with pagination, search, and sorting options
    public function showAllVouchers()
    {
        try {
            // Check if this is an AJAX request
            if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
                $this->getVouchersAjax();
                return;
            }

            // Get pagination, search, and sort parameters
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $searchTerm = isset($_GET['search']) ? $_GET['search'] : '';
            $sortBy = isset($_GET['sortBy']) ? $_GET['sortBy'] : 'voucher_id';
            $sortOrder = isset($_GET['sortOrder']) ? strtoupper($_GET['sortOrder']) : 'DESC';

            // Validate page number
            if ($page < 1) $page = 1;
            if ($limit < 1) $limit = 10;

            // Validate sort order
            if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
                $sortOrder = 'DESC';
            }

            // Get vouchers data from service
            $data = $this->voucherService->getAllVouchers($page, $limit, $searchTerm, $sortBy, $sortOrder);

            // Store data in variable to be used in view
            $vouchers = $data['vouchers'];
            $pagination = $data['pagination'];
            $currentSort = ['sortBy' => $sortBy, 'sortOrder' => $sortOrder];

            // Include the view
            require_once __DIR__ . '/../views/voucher_management/AllVoucher.php';
        } catch (Exception $e) {
            // Initialize empty variables for the view
            $vouchers = [];
            $pagination = [];
            $currentSort = ['sortBy' => 'voucher_id', 'sortOrder' => 'DESC'];
            // Show empty state instead of error message
            require_once __DIR__ . '/../views/voucher_management/AllVoucher.php';
        }
    }

    // Returns voucher data as JSON for AJAX requests
    private function getVouchersAjax()
    {
        try {
            // Check if this is a request for all IDs
            if (isset($_GET['getAllIds']) && $_GET['getAllIds'] == '1') {
                $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
                $status = isset($_GET['status']) ? trim($_GET['status']) : '';
                $type = isset($_GET['type']) ? trim($_GET['type']) : '';
                
                $voucherIds = $this->voucherService->getAllVoucherIds($searchTerm, $status, $type);
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'ids' => $voucherIds
                ]);
                exit;
            }

            // Get pagination, search, and sort parameters
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $searchTerm = isset($_GET['search']) ? $_GET['search'] : '';
            $sortBy = isset($_GET['sortBy']) ? $_GET['sortBy'] : 'voucher_id';
            $sortOrder = isset($_GET['sortOrder']) ? strtoupper($_GET['sortOrder']) : 'DESC';

            // Validate page number
            if ($page < 1) $page = 1;
            if ($limit < 1) $limit = 10;

            // Validate sort order
            if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
                $sortOrder = 'DESC';
            }

            // Get vouchers data from service
            $data = $this->voucherService->getAllVouchers($page, $limit, $searchTerm, $sortBy, $sortOrder);

            // Return JSON response
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'vouchers' => $data['vouchers'],
                'pagination' => $data['pagination'],
                'sortBy' => $sortBy,
                'sortOrder' => $sortOrder
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

    // Creates a new voucher
    public function registerVoucher()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $isRedeemable = isset($_POST['is_redeemable']) && ($_POST['is_redeemable'] === '1' || $_POST['is_redeemable'] === 'on' || $_POST['is_redeemable'] === true);
                $voucherDTO = new VoucherRegistrationDTO(
                    $_POST['code'],
                    $_POST['description'] ?? '',
                    $_POST['type'],
                    $_POST['discount_value'],
                    $_POST['min_spend'] ?? 0,
                    $_POST['max_discount'] ?? null,
                    $_POST['start_date'],
                    $_POST['end_date'],
                    false,
                    $isRedeemable
                );

                $result = $this->voucherService->registerVoucher($voucherDTO);

                if ($result) {
                    // Get voucher ID for logging (query by code to get the ID)
                    $database = new Database();
                    $conn = $database->getConnection();
                    $stmt = $conn->prepare('SELECT voucher_id FROM voucher WHERE code = ? ORDER BY voucher_id DESC LIMIT 1');
                    $stmt->execute([$_POST['code']]);
                    $voucherData = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Log voucher creation only if current user is admin
                    if (isset($_SESSION['user']) && $_SESSION['user']->role === 'admin' && $voucherData) {
                        ActivityLogger::logVoucherCreate((int)$voucherData['voucher_id'], $_POST['code']);
                    }
                    
                    $_SESSION['success_message'] = "Voucher registered successfully!";
                    
                    // Check if registration is from admin panel
                    $returnTo = isset($_POST['return_to']) ? $_POST['return_to'] : (isset($_GET['return_to']) ? $_GET['return_to'] : '');
                    
                    if ($returnTo === 'admin') {
                        header('Location: ../controller/VoucherController.php?action=showAll');
                    } else {
                        header('Location: ../views/voucher_management/VoucherRegisterForm.php');
                    }
                    exit;
                }
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            
            // Preserve POST data for form repopulation
            $_SESSION['form_data'] = $_POST;
            
            // Check if registration is from admin panel
            $returnTo = isset($_POST['return_to']) ? $_POST['return_to'] : (isset($_GET['return_to']) ? $_GET['return_to'] : '');
            
            if ($returnTo === 'admin') {
                header('Location: ../views/voucher_management/VoucherRegisterForm.php?return_to=admin');
            } else {
                header('Location: ../views/voucher_management/VoucherRegisterForm.php');
            }
            exit;
        }
    }

    // Updates voucher information
    public function updateVoucher()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Validate required fields
                if (!isset($_POST['voucher_id']) || empty($_POST['voucher_id'])) {
                    throw new Exception("Voucher ID is required");
                }

                $isRedeemable = isset($_POST['is_redeemable']) && ($_POST['is_redeemable'] === '1' || $_POST['is_redeemable'] === 'on' || $_POST['is_redeemable'] === true);
                $voucherDTO = new VoucherUpdateDTO(
                    (int)$_POST['voucher_id'],
                    $_POST['code'],
                    $_POST['description'] ?? '',
                    $_POST['type'],
                    $_POST['discount_value'],
                    $_POST['min_spend'] ?? 0,
                    $_POST['max_discount'] ?? null,
                    $_POST['start_date'],
                    $_POST['end_date'],
                    false,
                    $isRedeemable
                );

                // Get old values before update for logging
                $database = new Database();
                $voucherRepository = new VoucherRepository($database);
                $oldVoucher = $voucherRepository->getVoucherById((int)$_POST['voucher_id']);
                $oldValues = $oldVoucher ? [
                    'code' => $oldVoucher['code'] ?? '',
                    'description' => $oldVoucher['description'] ?? '',
                    'type' => $oldVoucher['type'] ?? '',
                    'discount_value' => $oldVoucher['discount_value'] ?? '',
                    'min_spend' => $oldVoucher['min_spend'] ?? 0,
                    'max_discount' => $oldVoucher['max_discount'] ?? null,
                    'start_date' => $oldVoucher['start_date'] ?? '',
                    'end_date' => $oldVoucher['end_date'] ?? '',
                    'is_redeemable' => $oldVoucher['is_redeemable'] ?? false
                ] : null;

                $result = $this->voucherService->updateVoucher($voucherDTO);

                if ($result) {
                    // Log voucher update only if current user is admin
                    if (isset($_SESSION['user']) && $_SESSION['user']->role === 'admin' && $oldValues) {
                        $newValues = [
                            'code' => $_POST['code'],
                            'description' => $_POST['description'] ?? '',
                            'type' => $_POST['type'],
                            'discount_value' => $_POST['discount_value'],
                            'min_spend' => $_POST['min_spend'] ?? 0,
                            'max_discount' => $_POST['max_discount'] ?? null,
                            'start_date' => $_POST['start_date'],
                            'end_date' => $_POST['end_date'],
                            'is_redeemable' => $isRedeemable
                        ];
                        ActivityLogger::logVoucherUpdate((int)$_POST['voucher_id'], $_POST['code'], $oldValues, $newValues);
                    }
                    
                    $_SESSION['success_message'] = "Voucher updated successfully!";
                    header('Location: ../controller/VoucherController.php?action=showAll');
                    exit;
                } else {
                    throw new Exception("Failed to update voucher");
                }
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../controller/VoucherController.php?action=showAll');
            exit;
        }
    }

    // Updates voucher status (active, inactive, expired)
    public function updateVoucherStatus()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Validate required fields
                if (!isset($_POST['voucher_id']) || empty($_POST['voucher_id'])) {
                    throw new Exception("Voucher ID is required");
                }

                if (!isset($_POST['status']) || empty($_POST['status'])) {
                    throw new Exception("Status is required");
                }

                $voucherId = (int)$_POST['voucher_id'];
                $status = $_POST['status'];

                // Validate status
                $allowedStatuses = ['active', 'inactive', 'expired'];
                if (!in_array($status, $allowedStatuses)) {
                    throw new Exception("Invalid status value");
                }

                // Get old status and voucher info before update for logging
                $database = new Database();
                $voucherRepository = new VoucherRepository($database);
                $voucher = $voucherRepository->getVoucherById($voucherId);
                $oldStatus = $voucher ? ($voucher['status'] ?? 'active') : 'active';
                $code = $voucher ? ($voucher['code'] ?? 'Unknown') : 'Unknown';

                $result = $this->voucherService->updateVoucherStatus($voucherId, $status);

                if ($result) {
                    // Log status change only if current user is admin and status actually changed
                    if (isset($_SESSION['user']) && $_SESSION['user']->role === 'admin' && $oldStatus !== $status) {
                        ActivityLogger::logVoucherStatusChange($voucherId, $code, $oldStatus, $status);
                    }
                    
                    $statusLabels = [
                        'active' => 'activated',
                        'inactive' => 'set to inactive',
                        'expired' => 'expired'
                    ];
                    $_SESSION['success_message'] = "Voucher " . $statusLabels[$status] . " successfully!";
                } else {
                    throw new Exception("Failed to update voucher status. Voucher may not exist.");
                }

                header('Location: ../controller/VoucherController.php?action=showAll');
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../controller/VoucherController.php?action=showAll');
            exit;
        }
    }

    // Deletes a voucher
    public function deleteVoucher()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Validate required fields
                if (!isset($_POST['voucher_id']) || empty($_POST['voucher_id'])) {
                    throw new Exception("Voucher ID is required");
                }

                $voucherId = (int)$_POST['voucher_id'];

                // Get voucher info before delete for logging
                $database = new Database();
                $voucherRepository = new VoucherRepository($database);
                $voucher = $voucherRepository->getVoucherById($voucherId);
                $code = $voucher ? ($voucher['code'] ?? 'Unknown') : 'Unknown';

                $result = $this->voucherService->deleteVoucher($voucherId);

                if ($result) {
                    // Log voucher deletion only if current user is admin
                    if (isset($_SESSION['user']) && $_SESSION['user']->role === 'admin') {
                        ActivityLogger::logVoucherDelete($voucherId, $code);
                    }
                    
                    $_SESSION['success_message'] = "Voucher deleted successfully!";
                } else {
                    throw new Exception("Failed to delete voucher. Voucher may not exist.");
                }

                header('Location: ../controller/VoucherController.php?action=showAll');
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../controller/VoucherController.php?action=showAll');
            exit;
        }
    }

    // Deletes multiple vouchers in bulk
    public function bulkDeleteVouchers()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Validate required fields
                if (!isset($_POST['voucher_ids']) || empty($_POST['voucher_ids'])) {
                    throw new Exception("Please select at least one voucher to delete");
                }

                $voucherIds = $_POST['voucher_ids'];
                if (!is_array($voucherIds)) {
                    $voucherIds = [$voucherIds];
                }

                // Get voucher info before delete for logging
                $database = new Database();
                $voucherRepository = new VoucherRepository($database);
                $vouchersToDelete = [];
                foreach ($voucherIds as $vid) {
                    $voucher = $voucherRepository->getVoucherById((int)$vid);
                    if ($voucher) {
                        $vouchersToDelete[] = [
                            'voucher_id' => (int)$vid,
                            'code' => $voucher['code'] ?? 'Unknown'
                        ];
                    }
                }

                $result = $this->voucherService->bulkDeleteVouchers($voucherIds);

                if ($result['success']) {
                    // Log each voucher deletion only if current user is admin
                    if (isset($_SESSION['user']) && $_SESSION['user']->role === 'admin') {
                        foreach ($vouchersToDelete as $voucher) {
                            ActivityLogger::logVoucherDelete($voucher['voucher_id'], $voucher['code']);
                        }
                    }
                    
                    $_SESSION['success_message'] = $result['message'];
                } else {
                    throw new Exception($result['message']);
                }

                header('Location: ../controller/VoucherController.php?action=showAll');
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../controller/VoucherController.php?action=showAll');
            exit;
        }
    }

    // Assigns voucher to members (all or specific)
    public function assignVoucher()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Validate required fields
                if (!isset($_POST['voucher_id']) || empty($_POST['voucher_id'])) {
                    throw new Exception("Voucher ID is required");
                }

                if (!isset($_POST['assignment_type']) || empty($_POST['assignment_type'])) {
                    throw new Exception("Assignment type is required");
                }

                $voucherId = (int)$_POST['voucher_id'];
                $assignmentType = $_POST['assignment_type']; // 'all' or 'specific'
                $assignedBy = isset($_SESSION['user']->user_id) ? (int)$_SESSION['user']->user_id : null;

                // Get voucher code for logging
                $database = new Database();
                $voucherRepository = new VoucherRepository($database);
                $voucher = $voucherRepository->getVoucherById($voucherId);
                $code = $voucher ? ($voucher['code'] ?? 'Unknown') : 'Unknown';

                if ($assignmentType === 'all') {
                    // Assign to all active members
                    $result = $this->voucherService->assignVoucherToAllMembers($voucherId, $assignedBy);
                    
                    if ($result['success']) {
                        // Log voucher assignment only if current user is admin
                        if (isset($_SESSION['user']) && $_SESSION['user']->role === 'admin') {
                            ActivityLogger::logVoucherAssign($voucherId, $code, 'all');
                        }
                        
                        $_SESSION['success_message'] = $result['message'];
                    } else {
                        throw new Exception($result['message']);
                    }
                } elseif ($assignmentType === 'specific') {
                    // Assign to specific members
                    if (!isset($_POST['member_ids']) || empty($_POST['member_ids'])) {
                        throw new Exception("Please select at least one member");
                    }

                    $memberIds = $_POST['member_ids'];
                    if (!is_array($memberIds)) {
                        $memberIds = [$memberIds];
                    }

                    $result = $this->voucherService->assignVoucherToSpecificMembers($voucherId, $memberIds, $assignedBy);
                    
                    if ($result['success']) {
                        // Log voucher assignment only if current user is admin
                        if (isset($_SESSION['user']) && $_SESSION['user']->role === 'admin') {
                            ActivityLogger::logVoucherAssign($voucherId, $code, 'specific', count($memberIds));
                        }
                        
                        $_SESSION['success_message'] = $result['message'];
                    } else {
                        throw new Exception($result['message']);
                    }
                } else {
                    throw new Exception("Invalid assignment type");
                }

                header('Location: ../controller/VoucherController.php?action=showAll');
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../controller/VoucherController.php?action=showAll');
            exit;
        }
    }

    // Returns list of members available for voucher assignment
    public function getMembersForAssignment()
    {
        try {
            // Get voucher_id from request to exclude already assigned members
            $voucherId = isset($_GET['voucher_id']) ? (int)$_GET['voucher_id'] : null;
            
            // Return JSON list of members for assignment dropdown
            // Excludes members who already have this voucher assigned
            $members = $this->voucherService->getAllActiveMembers($voucherId);
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'members' => $members
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

    // Downloads CSV template for bulk voucher import
    public function downloadTemplate()
    {
        try {
            // Set headers for CSV download
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="voucher_import_template.csv"');
            
            // Create output stream
            $output = fopen('php://output', 'w');
            
            // Write CSV headers
            fputcsv($output, [
                'code',
                'description',
                'type',
                'discount_value',
                'min_spend',
                'max_discount',
                'start_date',
                'end_date',
                'is_redeemable'
            ]);
            
            // Write example row
            fputcsv($output, [
                'SUMMER25',
                'Summer sale voucher',
                'percent',
                '25',
                '100',
                '50',
                '01-06-2024',
                '31-08-2024',
                '1'
            ]);
            
            fclose($output);
            exit;
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../views/voucher_management/VoucherRegisterForm.php');
            exit;
        }
    }

    // Previews vouchers from CSV file before bulk import
    public function previewBulkImport()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
                $file = $_FILES['csv_file'];
                
                // Validate file
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("File upload error: " . $file['error']);
                }
                
                if ($file['type'] !== 'text/csv' && pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
                    throw new Exception("Please upload a valid CSV file");
                }
                
                // Parse CSV
                $result = $this->voucherService->parseCSVFile($file['tmp_name']);
                
                if (!$result['success']) {
                    throw new Exception($result['error']);
                }
                
                // Store parsed vouchers in session for preview
                $_SESSION['bulk_import_vouchers'] = $result['vouchers'];
                $_SESSION['bulk_import_errors'] = $result['errors'];
                
                // Redirect to preview page
                $returnTo = isset($_POST['return_to']) ? $_POST['return_to'] : '';
                $redirectUrl = '../views/voucher_management/VoucherBulkImportPreview.php';
                if ($returnTo) {
                    $redirectUrl .= '?return_to=' . urlencode($returnTo);
                }
                header('Location: ' . $redirectUrl);
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            $returnTo = isset($_POST['return_to']) ? $_POST['return_to'] : '';
            $redirectUrl = '../views/voucher_management/VoucherRegisterForm.php';
            if ($returnTo) {
                $redirectUrl .= '?return_to=' . urlencode($returnTo);
            }
            header('Location: ' . $redirectUrl);
            exit;
        }
    }

    // Executes bulk import of vouchers from CSV
    public function executeBulkImport()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (!isset($_SESSION['bulk_import_vouchers'])) {
                    throw new Exception("No vouchers to import. Please upload a CSV file first.");
                }
                
                $vouchers = $_SESSION['bulk_import_vouchers'];
                $result = $this->voucherService->bulkImportVouchers($vouchers);
                
                // Log bulk import only if current user is admin
                if (isset($_SESSION['user']) && $_SESSION['user']->role === 'admin' && $result['success']) {
                    $importedCount = $result['imported_count'] ?? 0;
                    $totalCount = count($vouchers);
                    ActivityLogger::logVoucherBulkImport($importedCount, $totalCount);
                }
                
                // Clear session data
                unset($_SESSION['bulk_import_vouchers']);
                unset($_SESSION['bulk_import_errors']);
                
                if ($result['success']) {
                    $_SESSION['success_message'] = $result['message'];
                } else {
                    $_SESSION['error_message'] = $result['message'];
                }
                
                // Redirect based on return_to
                $returnTo = isset($_POST['return_to']) ? $_POST['return_to'] : '';
                if ($returnTo === 'admin') {
                    header('Location: ../controller/VoucherController.php?action=showAll');
                } else {
                    header('Location: ../views/voucher_management/VoucherRegisterForm.php');
                }
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            $returnTo = isset($_POST['return_to']) ? $_POST['return_to'] : '';
            if ($returnTo === 'admin') {
                header('Location: ../controller/VoucherController.php?action=showAll');
            } else {
                header('Location: ../views/voucher_management/VoucherRegisterForm.php');
            }
            exit;
        }
    }

    // Displays vouchers assigned to logged-in member
    public function showMemberVouchers()
    {
        try {
            // Redirect admins to AdminDashboard - they should not access member pages
            if (isset($_SESSION['user']) && isset($_SESSION['user']->role) && $_SESSION['user']->role === 'admin') {
                header('Location: ../views/admin/AdminDashboard.php');
                exit;
            }
            
            $vouchers = [];
            $isLoggedIn = isset($_SESSION['user']) && !empty($_SESSION['user']);
            $isMember = $isLoggedIn && isset($_SESSION['user']->role) && $_SESSION['user']->role === 'member';
            $userId = null;
            $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
            
            // Validate filter
            $allowedFilters = ['all', 'active', 'used', 'expired'];
            if (!in_array($filter, $allowedFilters)) {
                $filter = 'all';
            }

            // Get sort parameters
            $sortBy = isset($_GET['sortBy']) ? $_GET['sortBy'] : 'end_date';
            $sortOrder = isset($_GET['sortOrder']) ? strtoupper($_GET['sortOrder']) : 'ASC';
            
            // Validate sort column
            $allowedSortColumns = ['end_date', 'start_date', 'assigned_at', 'discount_value', 'code'];
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'end_date';
            }
            
            // Validate sort order
            if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
                $sortOrder = 'ASC';
            }

            // Only fetch vouchers if user is logged in and is a member
            if ($isMember) {
                $userId = $_SESSION['user']->user_id;
                $vouchers = $this->voucherService->getMemberVouchers($userId, $filter, $sortBy, $sortOrder);
            }

            // Include the view (will show empty state if not logged in)
            require_once __DIR__ . '/../views/member/MemberVoucherList.php';
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../../index.php');
            exit;
        }
    }

    // Redeems voucher code by member
    public function redeemVoucher()
    {
        try {
            // Check if user is logged in and is a member
            if (!isset($_SESSION['user']) || empty($_SESSION['user']) || !isset($_SESSION['user']->role) || $_SESSION['user']->role !== 'member') {
                $_SESSION['error_message'] = 'Please login as a member to redeem vouchers.';
                header('Location: ../controller/VoucherController.php?action=showMemberVouchers');
                exit;
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Validate voucher code
                if (!isset($_POST['voucher_code']) || empty(trim($_POST['voucher_code']))) {
                    $_SESSION['error_message'] = 'Please enter a voucher code.';
                    header('Location: ../controller/VoucherController.php?action=showMemberVouchers');
                    exit;
                }

                $userId = $_SESSION['user']->user_id;
                $voucherCode = trim($_POST['voucher_code']);

                // Redeem voucher
                $result = $this->voucherService->redeemVoucherByCode($voucherCode, $userId);

                if ($result['success']) {
                    $_SESSION['success_message'] = $result['message'];
                } else {
                    $_SESSION['error_message'] = $result['message'];
                }

                header('Location: ../controller/VoucherController.php?action=showMemberVouchers');
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../controller/VoucherController.php?action=showMemberVouchers');
            exit;
        }
    }

    /**
     * Generate and download a QR code image for a voucher.
     *
     * The QR content is simply the voucher code. This can later be changed
     * to a full URL if you add a scan/redeem endpoint.
     */
    // Downloads QR code image for voucher
    public function downloadVoucherQr()
    {
        try {
            if (!isset($_GET['voucher_id']) || empty($_GET['voucher_id'])) {
                throw new Exception("Voucher ID is required");
            }

            if (!isset($_GET['code']) || trim($_GET['code']) === '') {
                throw new Exception("Voucher code is required");
            }

            $voucherId = (int)$_GET['voucher_id'];
            if ($voucherId <= 0) {
                throw new Exception("Invalid voucher ID");
            }

            $voucherCode = trim($_GET['code']);

            // Build a safe filename for download
            $safeCode = preg_replace('/[^A-Za-z0-9_\-]/', '_', $voucherCode);
            $fileName = 'voucher_' . $safeCode . '.png';

            // Generate QR code with brand colors (#FF523B)
            $qrCode = new QrCode(
                $voucherCode,
                new \Endroid\QrCode\Encoding\Encoding('UTF-8'),
                ErrorCorrectionLevel::High,
                300,
                10,  // margin
                RoundBlockSizeMode::Margin,
                new Color(255, 82, 59),  // Foreground: #FF523B (brand red)
                new Color(255, 255, 255)  // Background: White
            );

            $writer = new PngWriter();
            $result = $writer->write($qrCode);

            // Send headers for file download
            header('Content-Type: ' . $result->getMimeType());
            header('Content-Disposition: attachment; filename="' . $fileName . '"');

            echo $result->getString();
            exit;
        } catch (Exception $e) {
            $_SESSION['error_message'] = 'Failed to generate voucher QR code: ' . $e->getMessage();
            header('Location: ../controller/VoucherController.php?action=showAll');
            exit;
        }
    }

    /**
     * Show a preview page with the voucher QR code so the admin can
     * view it first and then decide to download.
     */
    // Displays QR code preview for voucher
    public function showVoucherQr()
    {
        try {
            if (!isset($_GET['voucher_id']) || empty($_GET['voucher_id'])) {
                throw new Exception("Voucher ID is required");
            }

            if (!isset($_GET['code']) || trim($_GET['code']) === '') {
                throw new Exception("Voucher code is required");
            }

            $voucherId = (int)$_GET['voucher_id'];
            if ($voucherId <= 0) {
                throw new Exception("Invalid voucher ID");
            }

            $voucherCode = trim($_GET['code']);

            // Generate QR code with brand colors (#FF523B)
            $qrCode = new QrCode(
                $voucherCode,
                new \Endroid\QrCode\Encoding\Encoding('UTF-8'),
                ErrorCorrectionLevel::High,
                300,
                10,  // margin
                RoundBlockSizeMode::Margin,
                new Color(255, 82, 59),  // Foreground: #FF523B (brand red)
                new Color(255, 255, 255)  // Background: White
            );

            $writer = new PngWriter();
            $result = $writer->write($qrCode);
            $svgContent = $result->getString();

            // Build a safe filename hint for the view (used in title / labels)
            $safeCode = preg_replace('/[^A-Za-z0-9_\-]/', '_', $voucherCode);
            $fileName = 'voucher_' . $safeCode . '.png';

            // Include a simple preview view
            require __DIR__ . '/../views/voucher_management/VoucherQrPreview.php';
        } catch (Exception $e) {
            $_SESSION['error_message'] = 'Failed to generate voucher QR code: ' . $e->getMessage();
            header('Location: ../controller/VoucherController.php?action=showAll');
            exit;
        }
    }
}

// Handle the request
$controller = new VoucherController();

/**
 * Voucher management is admin-only. Reject the request with HTTP 403 JSON
 * unless the current session belongs to an admin.
 */
function voucherControllerRequireAdmin(): void
{
    if (($_SESSION['user']->role ?? null) !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Access denied. Admin privileges required.']);
        exit;
    }
}

$voucherAdminPostActions = ['register', 'update', 'updateStatus', 'delete', 'bulkDelete', 'assign', 'previewBulkImport', 'executeBulkImport'];
$voucherAdminGetActions = ['showAll', 'getMembers', 'downloadTemplate', 'showVoucherQr', 'downloadVoucherQr'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? $_GET['action'] ?? 'register';
    if (in_array($action, $voucherAdminPostActions, true)) {
        voucherControllerRequireAdmin();
    }

    if ($action === 'register') {
        $controller->registerVoucher();
    } elseif ($action === 'update') {
        $controller->updateVoucher();
    } elseif ($action === 'updateStatus') {
        $controller->updateVoucherStatus();
    } elseif ($action === 'delete') {
        $controller->deleteVoucher();
    } elseif ($action === 'bulkDelete') {
        $controller->bulkDeleteVouchers();
    } elseif ($action === 'assign') {
        $controller->assignVoucher();
    } elseif ($action === 'previewBulkImport') {
        $controller->previewBulkImport();
    } elseif ($action === 'executeBulkImport') {
        $controller->executeBulkImport();
    } elseif ($action === 'redeemVoucher') {
        $controller->redeemVoucher();
    }
} else {
    // Handle GET requests
    $action = $_GET['action'] ?? '';
    if (in_array($action, $voucherAdminGetActions, true)) {
        voucherControllerRequireAdmin();
    }

    if ($action === 'showAll') {
        $controller->showAllVouchers();
    } elseif ($action === 'getMembers') {
        $controller->getMembersForAssignment();
    } elseif ($action === 'downloadTemplate') {
        $controller->downloadTemplate();
    } elseif ($action === 'showVoucherQr') {
        $controller->showVoucherQr();
    } elseif ($action === 'downloadVoucherQr') {
        $controller->downloadVoucherQr();
    } elseif ($action === 'showMemberVouchers') {
        $controller->showMemberVouchers();
    }
}


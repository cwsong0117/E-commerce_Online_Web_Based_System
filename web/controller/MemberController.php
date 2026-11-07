<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../database/connection.php';
require_once __DIR__ . '/../repository/MemberRepository.php';
require_once __DIR__ . '/../service/MemberService.php';
require_once __DIR__ . '/../DTO/MemberDTO.php';
require_once __DIR__ . '/../helpers/ActivityLogger.php';

class MemberController
{
    private $membershipServices;

    public function __construct()
    {
        $database = new Database();
        $membershipRepository = new MembershipRepository($database);
        $this->membershipServices = new MembershipServices($membershipRepository);
    }

    // Redirects to account login page
    public function showLogin()
    {
        try {
            header('Location: ../account.php');
            exit;
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../../index.php');
            exit;
        }
    }

    // Authenticates user credentials and logs in the user
    public function login()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $username = isset($_POST['username']) ? trim($_POST['username']) : '';
                $password = isset($_POST['password']) ? $_POST['password'] : '';
                $rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] == '1';

                $userDTO = $this->membershipServices->authenticate($username, $password);

                // Check if email is verified
                $user = $this->membershipServices->getMemberByUsername($username);
                if ($user && !$user['email_verified']) {
                    $_SESSION['error_message'] = 'Please verify your email before logging in.';
                    $_SESSION['unverified_email'] = $user['email'];
                    header('Location: ../views/security/email_verification.php');
                    exit;
                }

                // Check if admin requires 2FA
                if ($userDTO->getRole() === 'admin') {
                    $userId = $userDTO->getUserId();
                    
                    // Check if 2FA secret exists and is enabled
                    $twoFactorSecret = $this->membershipServices->getTwoFactorSecret($userId);
                    $isTwoFactorEnabled = $twoFactorSecret && isset($twoFactorSecret['two_factor_enabled']) && $twoFactorSecret['two_factor_enabled'] == 1;
                    
                    if (!$twoFactorSecret || !$twoFactorSecret['two_factor_secret'] || !$isTwoFactorEnabled) {
                        // No 2FA secret OR 2FA not enabled (setup incomplete) - show setup page
                        // If secret exists but not enabled, reuse it; otherwise generate new
                        if ($twoFactorSecret && $twoFactorSecret['two_factor_secret']) {
                            // Secret exists but setup incomplete - reuse existing secret
                            $setupData = $this->membershipServices->getSetupDataForExistingSecret($userId);
                        } else {
                            // No secret - generate new one
                            $setupData = $this->membershipServices->setupTwoFactor($userId);
                        }
                        
                        // Store temporary session data for 2FA setup
                        $_SESSION['2fa_setup'] = [
                            'user_id' => $userId,
                            'username' => $userDTO->getUsername(),
                            'full_name' => $userDTO->getFullName(),
                            'email' => $userDTO->getEmail(),
                            'role' => $userDTO->getRole(),
                            'remember_me' => $rememberMe,
                            'qr_code' => $setupData['qr_code'],
                            'manual_entry' => $setupData['manual_entry'],
                            'secret_key' => $setupData['secret_key'] // Secret key for manual entry
                        ];
                        
                        header('Location: ../views/security/two_factor_setup.php');
                        exit;
                    } else {
                        // 2FA secret exists AND is enabled - require verification
                        // Store temporary session data for 2FA verification
                        $_SESSION['2fa_verify'] = [
                            'user_id' => $userId,
                            'username' => $userDTO->getUsername(),
                            'full_name' => $userDTO->getFullName(),
                            'email' => $userDTO->getEmail(),
                            'role' => $userDTO->getRole(),
                            'remember_me' => $rememberMe
                        ];
                        
                        header('Location: ../views/security/two_factor_verify.php');
                        exit;
                    }
                }

                // Non-admin users or 2FA not required - proceed with normal login
                // Save minimal user info in session as stdClass object
                $_SESSION['user'] = new stdClass();
                $_SESSION['user']->user_id = $userDTO->getUserId();
                $_SESSION['user']->username = $userDTO->getUsername();
                $_SESSION['user']->full_name = $userDTO->getFullName();
                $_SESSION['user']->email = $userDTO->getEmail();
                $_SESSION['user']->role = $userDTO->getRole();
                $_SESSION['user']->profile_photo = null;

                // Handle Remember Me functionality
                if ($rememberMe) {
                    $this->setRememberMeToken($userDTO->getUserId());
                } else {
                    // Clear any existing remember token if user didn't check "remember me"
                    $this->membershipServices->clearRememberToken($userDTO->getUserId());
                    $this->clearRememberMeCookie();
                }

                // Redirect based on user role
                if ($userDTO->getRole() === 'admin') {
                    header('Location: ../views/admin/AdminDashboard.php');
                } else {
                    header('Location: ../../index.php');
                }
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../views/security/login.php');
            exit;
        }
    }

    /**
     * Set remember me token for persistent login
     */
    private function setRememberMeToken($userId)
    {
        $token = bin2hex(random_bytes(32));
        $hashedToken = password_hash($token, PASSWORD_DEFAULT);
        $expiry = date('Y-m-d H:i:s', strtotime('+30 days'));
        
        // Store hashed token in database
        $this->membershipServices->saveRememberToken($userId, $hashedToken, $expiry);
        
        // Set cookie with plain token (30 days)
        $cookieExpiry = time() + (30 * 24 * 60 * 60);
        setcookie('remember_token', $userId . ':' . $token, $cookieExpiry, '/', '', false, true);
    }

    /**
     * Check and auto-login from remember me cookie
     */
    public function checkRememberMe()
    {
        if (isset($_SESSION['user'])) {
            return true; // Already logged in
        }

        if (!isset($_COOKIE['remember_token'])) {
            return false;
        }

        $parts = explode(':', $_COOKIE['remember_token'], 2);
        if (count($parts) !== 2) {
            $this->clearRememberMeCookie();
            return false;
        }

        $userId = (int)$parts[0];
        $token = $parts[1];

        $tokenData = $this->membershipServices->getRememberToken($userId);
        
        if (!$tokenData || strtotime($tokenData['token_expires_at']) < time()) {
            $this->clearRememberMeCookie();
            return false;
        }

        if (!password_verify($token, $tokenData['remember_token'])) {
            $this->clearRememberMeCookie();
            return false;
        }

        // Token is valid - auto login
        $user = $this->membershipServices->getMemberById($userId);
        if (!$user || $user['status'] !== 'active') {
            $this->clearRememberMeCookie();
            return false;
        }

        // Set session
        $_SESSION['user'] = new stdClass();
        $_SESSION['user']->user_id = $user['user_id'];
        $_SESSION['user']->username = $user['username'];
        $_SESSION['user']->full_name = $user['full_name'];
        $_SESSION['user']->email = $user['email'];
        $_SESSION['user']->role = $user['role'];
        $_SESSION['user']->profile_photo = null;

        // Refresh the token for security
        $this->setRememberMeToken($userId);

        return true;
    }

    /**
     * Clear remember me cookie
     */
    private function clearRememberMeCookie()
    {
        setcookie('remember_token', '', time() - 3600, '/', '', false, true);
    }

    // Logs out the current user and clears session
    public function logout()
    {
        // Clear remember me token from database and cookie
        if (isset($_SESSION['user']->user_id)) {
            $this->membershipServices->clearRememberToken($_SESSION['user']->user_id);
        }
        $this->clearRememberMeCookie();
        
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['success_message'] = 'You have been logged out';
        header('Location: ../../index.php');
        exit;
    }

    // Registers a new member account
    public function registerMember()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Get DateOfBirth - prioritize the hidden date input (YYYY-MM-DD format)
                // If not available, check the text input and convert from DD/MM/YYYY
                $dateOfBirth = null;
                if (isset($_POST['DateOfBirth']) && !empty($_POST['DateOfBirth'])) {
                    $dateOfBirth = trim($_POST['DateOfBirth']);
                } elseif (isset($_POST['DateOfBirthText']) && !empty($_POST['DateOfBirthText'])) {
                    // Convert from DD/MM/YYYY to YYYY-MM-DD
                    $dateText = trim($_POST['DateOfBirthText']);
                    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dateText, $matches)) {
                        $dateOfBirth = $matches[3] . '-' . $matches[2] . '-' . $matches[1];
                    }
                }
                
                $memberDTO = new MemberRegistrationDTO(
                    $_POST['username'],
                    $_POST['password'],
                    $_POST['repeat_password'],
                    $_POST['full_name'],
                    $_POST['gender'],
                    $_POST['contact_no'],
                    $_POST['email'],
                    null, // profile_photo will be set later
                    $dateOfBirth
                );

                // Get profile photo data
                $profilePhotoFile = $_FILES['profile_photo'] ?? null;
                $croppedPhotoData = isset($_POST['profile_photo_cropped']) ? trim($_POST['profile_photo_cropped']) : null;

                $result = $this->membershipServices->registerMember($memberDTO, $profilePhotoFile, $croppedPhotoData);

                if ($result) {
                    $_SESSION['success_message'] = "Registration successful! Please check your email to verify your account before logging in.";
                    $_SESSION['registered_email'] = $memberDTO->getEmail();
                    header('Location: ../views/security/email_verification.php');
                    exit;
                }
            }
        } catch (PDOException $e) {
            // Handle database integrity constraint violations
            $errorCode = $e->getCode();
            $errorMessage = $e->getMessage();
            $errorField = null;
            $userFriendlyMessage = '';

            // Check for duplicate entry error (SQLSTATE[23000] or MySQL error code 1062)
            if ($errorCode == 23000 || strpos($errorMessage, '23000') !== false || strpos($errorMessage, '1062') !== false) {
                // Extract which field is duplicated from the error message
                // Error format: "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'test1' for key 'username'"
                if (stripos($errorMessage, "key 'username'") !== false || (stripos($errorMessage, "Duplicate entry") !== false && stripos($errorMessage, 'username') !== false)) {
                    $errorField = 'username';
                    $userFriendlyMessage = 'This username is already taken. Please choose a different username.';
                } elseif (stripos($errorMessage, "key 'email'") !== false || (stripos($errorMessage, "Duplicate entry") !== false && stripos($errorMessage, 'email') !== false)) {
                    $errorField = 'email';
                    $userFriendlyMessage = 'This email address is already registered. Please use a different email or try logging in.';
                } elseif (stripos($errorMessage, "key 'contact_no'") !== false || (stripos($errorMessage, "Duplicate entry") !== false && stripos($errorMessage, 'contact') !== false)) {
                    $errorField = 'contact_no';
                    $userFriendlyMessage = 'This contact number is already registered. Please use a different contact number.';
                } else {
                    // Generic duplicate entry message
                    $userFriendlyMessage = 'This information is already registered. Please check your details and try again.';
                }
            } else {
                // Other database errors
                $userFriendlyMessage = 'An error occurred during registration. Please try again later.';
            }

            $_SESSION['error_message'] = $userFriendlyMessage;
            $_SESSION['form_data'] = $_POST;

            if ($errorField) {
                $_SESSION['error_field'] = $errorField;
            }

            header('Location: ../views/member_management/MemberRegisterForm.php');
            exit;
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();

            // Preserve POST data for form repopulation
            $_SESSION['form_data'] = $_POST;

            // Detect which field caused the error from error message
            $errorField = null;
            $errorMessage = strtolower($e->getMessage());

            // Check error message for field indicators
            if (stripos($errorMessage, 'username') !== false && stripos($errorMessage, 'already exists') !== false) {
                $errorField = 'username';
            } elseif (stripos($errorMessage, 'email') !== false && stripos($errorMessage, 'already exists') !== false) {
                $errorField = 'email';
            } elseif ((stripos($errorMessage, 'contact') !== false || stripos($errorMessage, 'phone') !== false) && stripos($errorMessage, 'already exists') !== false) {
                $errorField = 'contact_no';
            }

            if ($errorField) {
                $_SESSION['error_field'] = $errorField;
            }

            header('Location: ../views/member_management/MemberRegisterForm.php');
            exit;
        }
    }

    // Verifies member email using verification token
    public function verifyEmail()
    {
        try {
            $token = isset($_GET['token']) ? trim($_GET['token']) : '';

            if (empty($token)) {
                $_SESSION['error_message'] = 'Invalid verification link';
                header('Location: ../views/security/email_verification.php');
                exit;
            }

            $result = $this->membershipServices->verifyEmail($token);

            if ($result['success']) {
                $_SESSION['success_message'] = 'Email verified successfully! You can now log in.';
                header('Location: ../views/security/login.php');
            } else {
                $_SESSION['error_message'] = $result['message'];
                header('Location: ../views/security/email_verification.php');
            }
            exit;
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../views/security/email_verification.php');
            exit;
        }
    }

    // Resends email verification link to member
    public function resendVerificationEmail()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';

                if (empty($email)) {
                    $_SESSION['error_message'] = 'Email is required';
                    header('Location: ../views/security/email_verification.php');
                    exit;
                }

                $result = $this->membershipServices->resendVerificationEmail($email);

                if ($result['success']) {
                    $_SESSION['success_message'] = 'Verification email sent! Please check your inbox.';
                } else {
                    $_SESSION['error_message'] = $result['message'];
                }

                header('Location: ../views/security/email_verification.php');
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../views/security/email_verification.php');
            exit;
        }
    }

    // Displays all members with pagination, search, and filtering options
    public function showAllMembers()
    {
        try {
            // Check if this is an AJAX request
            if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
                $this->getMembersAjax();
                return;
            }

            // Get pagination, search, sort and status parameters
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
            $sortBy = isset($_GET['sortBy']) ? $_GET['sortBy'] : 'created_at';
            $sortOrder = isset($_GET['sortOrder']) ? strtoupper($_GET['sortOrder']) : 'DESC';
            $status = isset($_GET['status']) ? trim($_GET['status']) : '';

            // Validate page number
            if ($page < 1) $page = 1;
            if ($limit < 1) $limit = 10;

            // Validate sort order
            if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
                $sortOrder = 'DESC';
            }

            // Get members data from service
            $data = $this->membershipServices->getAllMembers($page, $limit, $searchTerm, $sortBy, $sortOrder, $status);

            // Store data in variable to be used in view
            $members = $data['members'];
            $pagination = $data['pagination'];
            $currentSort = ['sortBy' => $sortBy, 'sortOrder' => $sortOrder];

            // Include the view
            require_once __DIR__ . '/../views/member_management/AllMembers.php';
        } catch (Exception $e) {
            // Initialize empty variables for the view
            $members = [];
            $pagination = [];
            $currentSort = ['sortBy' => 'created_at', 'sortOrder' => 'DESC'];
            // Show empty state instead of error message
            require_once __DIR__ . '/../views/member_management/AllMembers.php';
        }
    }

    // Returns member data as JSON for AJAX requests
    private function getMembersAjax()
    {
        try {
            // Check if this is a request for all IDs
            if (isset($_GET['getAllIds']) && $_GET['getAllIds'] == '1') {
                $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
                $status = isset($_GET['status']) ? trim($_GET['status']) : '';
                
                $memberIds = $this->membershipServices->getAllMemberIds($searchTerm, $status);
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'ids' => $memberIds
                ]);
                exit;
            }

            // Get pagination, search, sort and status parameters
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
            $sortBy = isset($_GET['sortBy']) ? $_GET['sortBy'] : 'created_at';
            $sortOrder = isset($_GET['sortOrder']) ? strtoupper($_GET['sortOrder']) : 'DESC';
            $status = isset($_GET['status']) ? trim($_GET['status']) : '';

            // Validate page number
            if ($page < 1) $page = 1;
            if ($limit < 1) $limit = 10;

            // Validate sort order
            if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
                $sortOrder = 'DESC';
            }

            // Get members data from service
            $data = $this->membershipServices->getAllMembers($page, $limit, $searchTerm, $sortBy, $sortOrder, $status);

            // Return JSON response
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'members' => $data['members'],
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

    // Updates member profile information
    public function updateMember()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Validate required fields
                if (!isset($_POST['user_id']) || empty($_POST['user_id'])) {
                    throw new Exception("User ID is required");
                }

                // Only admins may edit other users; everyone else may edit only themselves
                $sessionUser = $_SESSION['user'] ?? null;
                if (!$sessionUser
                    || ($sessionUser->role !== 'admin' && (int)$_POST['user_id'] !== (int)$sessionUser->user_id)) {
                    throw new Exception("Unauthorized access");
                }

                // Basic input sanitization
                $username = isset($_POST['username']) ? trim($_POST['username']) : '';
                $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
                $email = isset($_POST['email']) ? trim($_POST['email']) : '';
                $contact_no = isset($_POST['contact_no']) ? trim($_POST['contact_no']) : '';
                $selected_address_id = isset($_POST['selected_address_id']) ? (int)$_POST['selected_address_id'] : 0;

                // Server-side constraints: no empty fields
                if ($full_name === '' || $email === '' || $contact_no === '') {
                    throw new Exception("All fields are required.");
                }

                // Email format
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("Invalid email format.");
                }

                // Normalize phone: keep only digits, Malaysian format (10-11 digits)
                $contact_digits = preg_replace('/\D+/', '', $contact_no);
                $len = strlen($contact_digits);
                if ($len < 10 || $len > 11) {
                    throw new Exception("Invalid phone number format. Please enter a valid Malaysian phone number (10-11 digits).");
                }

                // Store digits-only
                $contact_no = $contact_digits;

                // Get current user data to preserve gender and capture old values for logging
                $currentUser = $this->membershipServices->getMemberById((int)$_POST['user_id']);
                if (!$currentUser) {
                    throw new Exception("User not found.");
                }
                $gender = $currentUser['gender'];
                
                // Capture old values for activity logging
                $oldValues = [
                    'full_name' => $currentUser['full_name'] ?? '',
                    'email' => $currentUser['email'] ?? '',
                    'contact_no' => $currentUser['contact_no'] ?? '',
                    'gender' => $currentUser['gender'] ?? ''
                ];

                $memberDTO = new MemberUpdateDTO(
                    (int)$_POST['user_id'],
                    $username,
                    $full_name,
                    $email,
                    $gender,
                    $contact_no
                );

                // Collect password change request fields (optional)
                $currentPassword = isset($_POST['current_password']) ? trim($_POST['current_password']) : '';
                $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
                $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

                $result = $this->membershipServices->updateMember($memberDTO);

                // Update default address if selected
                if ($result && $selected_address_id > 0) {
                    $db = new Database();
                    $conn = $db->getConnection();

                    // Remove default from all user addresses
                    $stmtReset = $conn->prepare("UPDATE address SET is_default = 0 WHERE user_id = ?");
                    $stmtReset->execute([(int)$_POST['user_id']]);

                    // Set new default
                    $stmtSet = $conn->prepare("UPDATE address SET is_default = 1 WHERE id = ? AND user_id = ?");
                    $stmtSet->execute([$selected_address_id, (int)$_POST['user_id']]);
                }

                if ($result) {
                    // Log the member update only if current user is an admin editing another user
                    if (isset($_SESSION['user']) && $_SESSION['user']->role === 'admin' && (int)$_POST['user_id'] !== (int)$_SESSION['user']->user_id) {
                        $newValues = [
                            'full_name' => $full_name,
                            'email' => $email,
                            'contact_no' => $contact_no,
                            'gender' => $gender
                        ];
                        ActivityLogger::logMemberUpdate((int)$_POST['user_id'], $username, $oldValues, $newValues);
                        }
                    // If a password change was requested, process it now
                    if (!empty($newPassword)) {
                        $targetUserId = (int)$_POST['user_id'];
                        $isAdminEditingOther = isset($_SESSION['user']) && $_SESSION['user']->role === 'admin' && (int)$_SESSION['user']->user_id !== $targetUserId;

                        // If not an admin editing another user, require current password verification
                        if (!$isAdminEditingOther) {
                            if ($currentPassword === '') {
                                throw new Exception('Current password is required to change password.');
                            }
                            $targetUser = $this->membershipServices->getMemberById($targetUserId);
                            if (!$targetUser || !password_verify($currentPassword, $targetUser['password'])) {
                                throw new Exception('Current password is incorrect.');
                            }
                        }

                        // Enforce same password constraints as registration
                        $reqs = [];
                        $reqs[] = (strlen($newPassword) >= 8);
                        $reqs[] = (bool)preg_match('/[A-Z]/', $newPassword);
                        $reqs[] = (bool)preg_match('/[a-z]/', $newPassword);
                        $reqs[] = (bool)preg_match('/[0-9]/', $newPassword);
                        $reqs[] = (bool)preg_match('/[!@#$%^&*(),.?":{}|<>]/', $newPassword);
                        if (in_array(false, $reqs, true)) {
                            throw new Exception('New password does not meet security requirements: at least 8 characters, include uppercase, lowercase, number and special character.');
                        }

                        // Ensure confirmation matches
                        if ($newPassword !== $confirmPassword) {
                            throw new Exception('Passwords do not match.');
                        }

                        // Perform the password update
                        $updatedPwd = $this->membershipServices->resetPassword($targetUserId, $newPassword);
                        if (!$updatedPwd) {
                            throw new Exception('Failed to update password.');
                        }
                        // Append message
                        $_SESSION['success_message'] = "Member updated successfully! Password changed.";
                    }
                    // Ensure a single success message is set after all processing (including optional password change)
                    if (empty($_SESSION['success_message'])) {
                        $_SESSION['success_message'] = "Member updated successfully!";
                    }

                    // Allow redirection back to profile when updating from user profile page
                    $returnTo = isset($_POST['return_to']) ? $_POST['return_to'] : (isset($_GET['return_to']) ? $_GET['return_to'] : '');
                    if ($returnTo === 'profile') {
                        header('Location: ../views/member/profile.php');
                    } elseif (isset($_SESSION['user']) && $_SESSION['user']->role === 'admin' && isset($_POST['user_id']) && (int)$_POST['user_id'] === (int)$_SESSION['user']->user_id) {
                        header('Location: ../views/admin/AdminProfile.php');
                    } else {
                        header('Location: ../controller/MemberController.php?action=showAll');
                    }
                    exit;
                } else {
                    throw new Exception("Failed to update member");
                }
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            $returnTo = isset($_POST['return_to']) ? $_POST['return_to'] : (isset($_GET['return_to']) ? $_GET['return_to'] : '');
            if ($returnTo === 'profile') {
                header('Location: ../views/member/profile.php');
            } elseif (isset($_SESSION['user']) && $_SESSION['user']->role === 'admin' && isset($_POST['user_id']) && (int)$_POST['user_id'] === (int)$_SESSION['user']->user_id) {
                header('Location: ../views/admin/AdminProfile.php');
            } else {
                header('Location: ../controller/MemberController.php?action=showAll');
            }
            exit;
        }
    }

    /**
     * Handle password reset via security question flow.
     * POST with 'email' -> show question
     * POST with 'security_answer' and 'new_password' -> verify and update
     */
    // Initiates password reset by showing security question
    public function sendReset()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: ../views/security/forgot_password.php');
                exit;
            }

            $username = isset($_POST['username']) ? trim($_POST['username']) : '';
            if ($username) {
                $user = $this->membershipServices->getMemberByUsername($username);
                if (!$user) {
                    $_SESSION['fp_message'] = 'Username not found';
                    header('Location: ../views/security/forgot_password.php');
                    exit;
                }

                // Generate and send OTP
                $this->membershipServices->sendResetOtp($user);

                // Store user in session for next step
                $_SESSION['reset_user'] = [
                    'user_id' => $user['user_id'],
                    'username' => $user['username'],
                    'created_at' => time()
                ];
                unset($_SESSION['reset_verified']);
                $_SESSION['fp_message'] = 'An OTP has been sent to your registered email address.';
                header('Location: ../views/security/forgot_password.php');
                exit;
            }

            header('Location: ../views/security/forgot_password.php');
            exit;
        } catch (Exception $e) {
            $_SESSION['fp_message'] = $e->getMessage();
            header('Location: ../views/security/forgot_password.php');
            exit;
        }
    }

    /**
     * Verify the security answer. If correct, mark verified and redirect to new-password page.
     */
    // Verifies security answer for password reset
    public function verifyReset()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: ../views/security/forgot_password.php');
                exit;
            }

            $otp = isset($_POST['otp_code']) ? trim($_POST['otp_code']) : '';

            if (empty($_SESSION['reset_user'])) {
                $_SESSION['fp_message'] = 'Session expired. Please start again.';
                header('Location: ../views/security/forgot_password.php');
                exit;
            }

            $userId = (int)$_SESSION['reset_user']['user_id'];
            if ($this->membershipServices->verifyResetOtp($userId, $otp)) {
                $_SESSION['reset_verified'] = true;
                $_SESSION['fp_message'] = 'OTP verified. You may now set a new password.';
                header('Location: ../views/security/forgot_password.php');
                exit;
            } else {
                $_SESSION['fp_message'] = 'Invalid or expired OTP.';
                header('Location: ../views/security/forgot_password.php');
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['fp_message'] = $e->getMessage();
            header('Location: ../views/security/forgot_password.php');
            exit;
        }
    }

    /**
     * Complete the reset by setting the new password. Requires prior verification.
     */
    // Completes password reset with new password
    public function completeReset()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: ../views/security/forgot_password.php');
                exit;
            }

            if (empty($_SESSION['reset_user']) || empty($_SESSION['reset_verified'])) {
                $_SESSION['fp_message'] = 'Unauthorized action. Please verify your security answer first.';
                header('Location: ../views/security/forgot_password.php');
                exit;
            }

            $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : null;
            $newPasswordConfirm = isset($_POST['new_password_confirm']) ? $_POST['new_password_confirm'] : null;

            if ($newPassword === null || $newPasswordConfirm === null) {
                $_SESSION['fp_message'] = 'Please provide the new password and confirmation.';
                header('Location: ../views/security/forgot_password.php');
                exit;
            }

            if ($newPassword !== $newPasswordConfirm) {
                $_SESSION['fp_message'] = 'Passwords do not match';
                header('Location: ../views/security/forgot_password.php');
                exit;
            }

            // Enforce same password constraints as registration
            $requirements = [];
            $requirements[] = (strlen($newPassword) >= 8);
            $requirements[] = (bool)preg_match('/[A-Z]/', $newPassword);
            $requirements[] = (bool)preg_match('/[a-z]/', $newPassword);
            $requirements[] = (bool)preg_match('/[0-9]/', $newPassword);
            $requirements[] = (bool)preg_match('/[!@#$%^&*(),.?":{}|<>]/', $newPassword);

            if (in_array(false, $requirements, true)) {
                $_SESSION['fp_message'] = 'Password does not meet security requirements: at least 8 characters, include uppercase, lowercase, number and special character.';
                header('Location: ../views/security/forgot_password.php');
                exit;
            }

            $userId = (int)$_SESSION['reset_user']['user_id'];
            $updated = $this->membershipServices->resetPassword($userId, $newPassword);
            if ($updated) {
                unset($_SESSION['reset_user']);
                unset($_SESSION['reset_verified']);
                // Use the general success message so it shows on the login page,
                // and does not persist as a forgot-password specific message.
                $_SESSION['success_message'] = 'Password updated successfully. You may now log in.';
                header('Location: ../views/security/login.php');
                exit;
            } else {
                $_SESSION['fp_message'] = 'Failed to update password. Please try again.';
                header('Location: ../views/security/forgot_password.php');
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['fp_message'] = $e->getMessage();
            header('Location: ../views/security/forgot_password.php');
            exit;
        }
    }

    // Updates member profile photo
    public function updateProfilePhoto()
    {
        header('Content-Type: application/json');

        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception("Invalid request method");
            }

            if (empty($_SESSION['user'])) {
                throw new Exception("User not authenticated");
            }

            $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

            if ($userId !== (int)$_SESSION['user']->user_id) {
                throw new Exception("Unauthorized access");
            }

            if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("No file uploaded or upload error");
            }

            $file = $_FILES['photo'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
            $maxSize = 5 * 1024 * 1024; // 5MB

            // Validate file type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowedTypes)) {
                throw new Exception("Invalid file type. Only JPG, PNG, and GIF are allowed.");
            }

            // Validate file size
            if ($file['size'] > $maxSize) {
                throw new Exception("File size exceeds 5MB limit.");
            }

            // Get username for filename
            $username = $_SESSION['user']->username;
            $safeUsername = preg_replace('/[^a-zA-Z0-9_-]/', '', $username);

            // Create upload directory if it doesn't exist
            $uploadDir = __DIR__ . '/../images/profiles/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Remove old profile photos
            $oldFiles = glob($uploadDir . $safeUsername . '.*');
            foreach ($oldFiles as $oldFile) {
                if (is_file($oldFile)) {
                    unlink($oldFile);
                }
            }

            // Generate new filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            if (empty($extension)) {
                $extension = 'jpg';
            }
            $newFilename = $safeUsername . '.' . $extension;
            $targetPath = $uploadDir . $newFilename;

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                throw new Exception("Failed to save uploaded file.");
            }

            // Update database with new photo path
            $photoPath = 'web/images/profiles/' . $newFilename;
            $db = new Database();
            $conn = $db->getConnection();

            $stmt = $conn->prepare("UPDATE users SET profile_photo = ? WHERE user_id = ?");
            $stmt->execute([$photoPath, $userId]);

            // Compute the web-accessible URL
            $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
            $webRootDir = dirname(__DIR__); // /web directory
            $relativePath = str_replace($docRoot, '', str_replace('\\', '/', $webRootDir));
            $webBasePath = str_replace('\\', '/', $relativePath) . '/';
            
            echo json_encode([
                'success' => true,
                'message' => 'Profile photo updated successfully',
                'photoUrl' => $webBasePath . 'images/profiles/' . $newFilename
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

    // Adds or updates member shipping address
    public function addAddress()
    {
        header('Content-Type: application/json');

        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception("Invalid request method");
            }

            if (empty($_SESSION['user'])) {
                throw new Exception("User not authenticated");
            }

            $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            $addressId = isset($_POST['address_id']) ? (int)$_POST['address_id'] : 0;

            if ($userId !== (int)$_SESSION['user']->user_id) {
                throw new Exception("Unauthorized access");
            }

            // Validate required fields
            $address1 = isset($_POST['address1']) ? trim($_POST['address1']) : '';
            $address2 = isset($_POST['address2']) ? trim($_POST['address2']) : '';
            $city = isset($_POST['city']) ? trim($_POST['city']) : '';
            $state = isset($_POST['state']) ? trim($_POST['state']) : '';
            $postcode = isset($_POST['postcode']) ? trim($_POST['postcode']) : '';
            $label = isset($_POST['label']) ? trim($_POST['label']) : 'home';

            if (empty($address1) || empty($city) || empty($state) || empty($postcode)) {
                throw new Exception("All required fields must be filled");
            }

            // Validate postcode (5 digits)
            if (!preg_match('/^\d{5}$/', $postcode)) {
                throw new Exception("Invalid postcode format");
            }

            // Validate label
            if (!in_array($label, ['home', 'work', 'other'])) {
                $label = 'home';
            }

            $db = new Database();
            $conn = $db->getConnection();

            // Check if this is an update or insert
            if ($addressId > 0) {
                // Update existing address
                $stmtCheck = $conn->prepare("SELECT id FROM address WHERE id = ? AND user_id = ?");
                $stmtCheck->execute([$addressId, $userId]);
                if (!$stmtCheck->fetch()) {
                    throw new Exception("Address not found or unauthorized");
                }

                $stmt = $conn->prepare("
                    UPDATE address 
                    SET address1 = ?, address2 = ?, city = ?, postcode = ?, state = ?, label = ?
                    WHERE id = ? AND user_id = ?
                ");

                $stmt->execute([
                    $address1,
                    $address2,
                    $city,
                    $postcode,
                    $state,
                    $label,
                    $addressId,
                    $userId
                ]);

                $message = 'Address updated successfully';
            } else {
                // Insert new address
                $stmtCount = $conn->prepare("SELECT COUNT(*) FROM address WHERE user_id = ?");
                $stmtCount->execute([$userId]);
                $addressCount = $stmtCount->fetchColumn();

                // If first address, set as default
                $isDefault = ($addressCount == 0) ? 1 : 0;

                $stmt = $conn->prepare("
                    INSERT INTO address (user_id, address1, address2, city, postcode, state, label, is_default) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $userId,
                    $address1,
                    $address2,
                    $city,
                    $postcode,
                    $state,
                    $label,
                    $isDefault
                ]);

                $message = 'Address added successfully';
            }

            echo json_encode([
                'success' => true,
                'message' => $message
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

    // Deletes member shipping address
    public function deleteAddress()
    {
        header('Content-Type: application/json');

        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception("Invalid request method");
            }

            if (empty($_SESSION['user'])) {
                throw new Exception("User not authenticated");
            }

            $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            $addressId = isset($_POST['address_id']) ? (int)$_POST['address_id'] : 0;

            if ($userId !== (int)$_SESSION['user']->user_id) {
                throw new Exception("Unauthorized access");
            }

            if ($addressId <= 0) {
                throw new Exception("Invalid address ID");
            }

            $db = new Database();
            $conn = $db->getConnection();

            // Verify the address belongs to the user
            $stmtCheck = $conn->prepare("SELECT is_default FROM address WHERE id = ? AND user_id = ?");
            $stmtCheck->execute([$addressId, $userId]);
            $address = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            
            if (!$address) {
                throw new Exception("Address not found or unauthorized");
            }

            $wasDefault = $address['is_default'];

            // Delete the address
            $stmt = $conn->prepare("DELETE FROM address WHERE id = ? AND user_id = ?");
            $stmt->execute([$addressId, $userId]);

            // If deleted address was default, set the first remaining address as default
            if ($wasDefault) {
                $stmtFirst = $conn->prepare("SELECT id FROM address WHERE user_id = ? LIMIT 1");
                $stmtFirst->execute([$userId]);
                $firstAddress = $stmtFirst->fetch(PDO::FETCH_ASSOC);
                
                if ($firstAddress) {
                    $stmtUpdate = $conn->prepare("UPDATE address SET is_default = 1 WHERE id = ?");
                    $stmtUpdate->execute([$firstAddress['id']]);
                }
            }

            echo json_encode([
                'success' => true,
                'message' => 'Address deleted successfully'
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

    // Updates member account status (active, inactive, banned)
    public function updateMemberStatus()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Validate required fields
                if (!isset($_POST['user_id']) || empty($_POST['user_id'])) {
                    throw new Exception("User ID is required");
                }

                if (!isset($_POST['status']) || empty($_POST['status'])) {
                    throw new Exception("Status is required");
                }

                $userId = (int)$_POST['user_id'];
                $status = $_POST['status'];

                // Validate status
                $allowedStatuses = ['active', 'inactive', 'banned'];
                if (!in_array($status, $allowedStatuses)) {
                    throw new Exception("Invalid status value");
                }

                // Get old status and member info before update for logging
                $member = $this->membershipServices->getMemberById($userId);
                $oldStatus = $member ? ($member['status'] ?? 'active') : 'active';
                $username = $member ? ($member['username'] ?? 'Unknown') : 'Unknown';

                $result = $this->membershipServices->updateMemberStatus($userId, $status);

                if ($result) {
                    // Log status change if status actually changed and current user is an admin
                    if ($oldStatus !== $status && isset($_SESSION['user']) && $_SESSION['user']->role === 'admin') {
                        ActivityLogger::logMemberStatusChange($userId, $username, $oldStatus, $status);
                    }
                    
                    $statusLabels = [
                        'active' => 'activated',
                        'inactive' => 'set to inactive',
                        'banned' => 'banned'
                    ];
                    $_SESSION['success_message'] = "Member " . $statusLabels[$status] . " successfully!";
                } else {
                    throw new Exception("Failed to update member status. Member may not exist or may not be a regular member.");
                }

                header('Location: ../controller/MemberController.php?action=showAll');
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../controller/MemberController.php?action=showAll');
            exit;
        }
    }

    // Deletes a member account
    public function deleteMember()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Validate required fields
                if (!isset($_POST['user_id']) || empty($_POST['user_id'])) {
                    throw new Exception("User ID is required");
                }

                $userId = (int)$_POST['user_id'];

                // Get member info before delete for logging
                $member = $this->membershipServices->getMemberById($userId);
                $username = $member ? ($member['username'] ?? 'Unknown') : 'Unknown';

                $result = $this->membershipServices->deleteMember($userId);

                if ($result) {
                    // Log member deletion only if current user is an admin
                    if (isset($_SESSION['user']) && $_SESSION['user']->role === 'admin') {
                        ActivityLogger::logMemberDelete($userId, $username);
                    }
                    
                    $_SESSION['success_message'] = "Member deleted successfully!";
                } else {
                    throw new Exception("Failed to delete member. Member may not exist or may not be a regular member.");
                }

                header('Location: ../controller/MemberController.php?action=showAll');
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../controller/MemberController.php?action=showAll');
            exit;
        }
    }

    // Deletes multiple members in bulk
    public function bulkDeleteMembers()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Validate required fields
                if (!isset($_POST['user_ids']) || empty($_POST['user_ids'])) {
                    throw new Exception("Please select at least one member to delete");
                }

                $userIds = $_POST['user_ids'];
                if (!is_array($userIds)) {
                    $userIds = [$userIds];
                }

                // Get member info before delete for logging
                $membersToDelete = [];
                foreach ($userIds as $userId) {
                    $member = $this->membershipServices->getMemberById((int)$userId);
                    if ($member) {
                        $membersToDelete[] = [
                            'user_id' => (int)$userId,
                            'username' => $member['username'] ?? 'Unknown'
                        ];
                    }
                }

                $result = $this->membershipServices->bulkDeleteMembers($userIds);

                if ($result['success']) {
                    // Log each member deletion only if current user is an admin
                    if (isset($_SESSION['user']) && $_SESSION['user']->role === 'admin') {
                        foreach ($membersToDelete as $member) {
                            ActivityLogger::logMemberDelete($member['user_id'], $member['username']);
                        }
                    }
                    
                    $_SESSION['success_message'] = $result['message'];
                } else {
                    throw new Exception($result['message']);
                }

                header('Location: ../controller/MemberController.php?action=showAll');
                exit;
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../controller/MemberController.php?action=showAll');
            exit;
        }
    }

    /**
     * Verify 2FA code and complete login
     */
    public function verifyTwoFactor()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: ../views/security/login.php');
                exit;
            }

            if (empty($_SESSION['2fa_verify'])) {
                $_SESSION['error_message'] = 'Session expired. Please log in again.';
                header('Location: ../views/security/login.php');
                exit;
            }

            $code = isset($_POST['code']) ? trim($_POST['code']) : '';
            if (empty($code) || strlen($code) !== 6 || !ctype_digit($code)) {
                $_SESSION['error_message'] = 'Please enter a valid 6-digit code.';
                header('Location: ../views/security/two_factor_verify.php');
                exit;
            }

            $userData = $_SESSION['2fa_verify'];
            $userId = $userData['user_id'];

            if (!$this->membershipServices->verifyTwoFactorCode($userId, $code)) {
                $_SESSION['error_message'] = 'Invalid verification code. Please try again.';
                header('Location: ../views/security/two_factor_verify.php');
                exit;
            }

            // 2FA verified - complete login
            $_SESSION['user'] = new stdClass();
            $_SESSION['user']->user_id = $userData['user_id'];
            $_SESSION['user']->username = $userData['username'];
            $_SESSION['user']->full_name = $userData['full_name'];
            $_SESSION['user']->email = $userData['email'];
            $_SESSION['user']->role = $userData['role'];
            $_SESSION['user']->profile_photo = null;

            // Handle Remember Me functionality
            if (!empty($userData['remember_me'])) {
                $this->setRememberMeToken($userId);
            } else {
                $this->membershipServices->clearRememberToken($userId);
                $this->clearRememberMeCookie();
            }

            // Clear temporary 2FA session
            unset($_SESSION['2fa_verify']);

            // Redirect to admin dashboard
            header('Location: ../views/admin/AdminDashboard.php');
            exit;
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../views/security/two_factor_verify.php');
            exit;
        }
    }

    /**
     * Complete 2FA setup by verifying test code
     */
    public function setupTwoFactor()
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                header('Location: ../views/security/login.php');
                exit;
            }

            if (empty($_SESSION['2fa_setup'])) {
                $_SESSION['error_message'] = 'Session expired. Please log in again.';
                header('Location: ../views/security/login.php');
                exit;
            }

            $code = isset($_POST['code']) ? trim($_POST['code']) : '';
            if (empty($code) || strlen($code) !== 6 || !ctype_digit($code)) {
                $_SESSION['error_message'] = 'Please enter a valid 6-digit code.';
                header('Location: ../views/security/two_factor_setup.php');
                exit;
            }

            $userData = $_SESSION['2fa_setup'];
            $userId = $userData['user_id'];

            if (!$this->membershipServices->completeTwoFactorSetup($userId, $code)) {
                $_SESSION['error_message'] = 'Invalid verification code. Please try again.';
                header('Location: ../views/security/two_factor_setup.php');
                exit;
            }

            // 2FA setup complete - complete login
            $_SESSION['user'] = new stdClass();
            $_SESSION['user']->user_id = $userData['user_id'];
            $_SESSION['user']->username = $userData['username'];
            $_SESSION['user']->full_name = $userData['full_name'];
            $_SESSION['user']->email = $userData['email'];
            $_SESSION['user']->role = $userData['role'];
            $_SESSION['user']->profile_photo = null;

            // Handle Remember Me functionality
            if (!empty($userData['remember_me'])) {
                $this->setRememberMeToken($userId);
            } else {
                $this->membershipServices->clearRememberToken($userId);
                $this->clearRememberMeCookie();
            }

            // Clear temporary 2FA setup session
            unset($_SESSION['2fa_setup']);

            $_SESSION['success_message'] = 'Two-factor authentication has been enabled successfully!';
            
            // Redirect to admin dashboard
            header('Location: ../views/admin/AdminDashboard.php');
            exit;
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: ../views/security/two_factor_setup.php');
            exit;
        }
    }

    /**
     * Cancel 2FA setup - clear setup session and allow user to try again later
     */
    public function cancelTwoFactorSetup()
    {
        // Clear the 2FA setup session
        unset($_SESSION['2fa_setup']);
        
        // Redirect to login
        $_SESSION['info_message'] = '2FA setup cancelled. You can set it up again on your next login.';
        header('Location: ../views/security/login.php');
        exit;
    }
}

// Handle the request
$controller = new MemberController();

/**
 * Reject the request unless the current session satisfies $requirement.
 * $requirement: 'admin' (admin session) or 'user' (any logged-in user).
 * Responds with HTTP 403 JSON, since these endpoints are called via AJAX/forms.
 */
function memberControllerRequire(string $requirement): void
{
    $role = $_SESSION['user']->role ?? null;
    $allowed = $requirement === 'admin'
        ? ($role === 'admin')
        : !empty($_SESSION['user']);

    if (!$allowed) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $requirement === 'admin'
                ? 'Access denied. Admin privileges required.'
                : 'Please log in first.'
        ]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? $_GET['action'] ?? 'register';

    // Admin-only member management actions
    if (in_array($action, ['updateStatus', 'delete', 'bulkDelete'], true)) {
        memberControllerRequire('admin');
    }
    // Profile update: must be logged in (self-vs-admin is enforced in updateMember)
    if ($action === 'update') {
        memberControllerRequire('user');
    }

    if ($action === 'register') {
        $controller->registerMember();
    } elseif ($action === 'update') {
        $controller->updateMember();
    } elseif ($action === 'update_photo') {
        $controller->updateProfilePhoto();
    } elseif ($action === 'add_address') {
        $controller->addAddress();
    } elseif ($action === 'edit_address') {
        $controller->addAddress();
    } elseif ($action === 'delete_address') {
        $controller->deleteAddress();
    } elseif ($action === 'updateStatus') {
        $controller->updateMemberStatus();
    } elseif ($action === 'delete') {
        $controller->deleteMember();
    } elseif ($action === 'bulkDelete') {
        $controller->bulkDeleteMembers();
    } elseif ($action === 'login') {
        $controller->login();
        $action = $_POST['action'] ?? $_GET['action'] ?? 'register';
    } elseif ($action === 'send_reset') {
        $controller->sendReset();
    } elseif ($action === 'verify_reset') {
        $controller->verifyReset();
    } elseif ($action === 'complete_reset') {
        $controller->completeReset();
    } elseif ($action === 'resend_verification') {
        $controller->resendVerificationEmail();
    } elseif ($action === 'verify_2fa') {
        $controller->verifyTwoFactor();
    } elseif ($action === 'setup_2fa') {
        $controller->setupTwoFactor();
    } elseif ($action === 'cancel_2fa_setup') {
        $controller->cancelTwoFactorSetup();
    }
} else {
    // Handle GET requests
    $action = $_GET['action'] ?? '';

    if ($action === 'showAll') {
        memberControllerRequire('admin');
        $controller->showAllMembers();
    } elseif ($action === 'login') {
        $controller->showLogin();
    } elseif ($action === 'logout') {
        $controller->logout();
    } elseif ($action === 'verifyEmail') {
        $controller->verifyEmail();
    } elseif ($action === 'cancel_2fa_setup') {
        $controller->cancelTwoFactorSetup();
    }
}

<?php

require_once __DIR__ . '/../DTO/MemberDTO.php';

class MembershipRepository
{

    private $db;

    public function __construct(Database $databaseConnection)
    {
        $this->db = $databaseConnection->getConnection();
    }

    // Replace the createMember method (lines 15-35) with:

    public function createMember(MemberRegistrationDTO $memberDTO)
    {
        $sql = "INSERT INTO users (username, password, full_name, gender, contact_no, email, profile_photo, DateOfBirth, email_verified, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, FALSE, 'inactive')";

        $stmt = $this->db->prepare($sql);

        $result = $stmt->execute([
            $memberDTO->getUsername(),
            $memberDTO->getPassword(),
            $memberDTO->getFullName(),
            $memberDTO->getGender(),
            $memberDTO->getContactNo(),
            $memberDTO->getEmail(),
            $memberDTO->getProfilePhoto(),
            $memberDTO->getDateOfBirth()
        ]);

        return $result;
    }

    public function setVerificationToken($userId, $token, $expiredAt)
    {
        $sql = "UPDATE users SET verification_token = ?, token_expires_at = ? WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$token, $expiredAt, $userId]);
    }

    public function verifyEmail($token)
    {
        try {
            $sql = "SELECT user_id, email, token_expires_at, email_verified 
                    FROM users 
                    WHERE verification_token = ? 
                    LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$token]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return ['success' => false, 'message' => 'Invalid verification token'];
            }

            // Check if already verified
            if ($user['email_verified']) {
                return ['success' => false, 'message' => 'Email already verified'];
            }

            // Check if token expired
            if ($user['token_expires_at'] && strtotime($user['token_expires_at']) < time()) {
                return ['success' => false, 'message' => 'Verification token has expired. Please request a new one.'];
            }

            // Verify the email
            $sql = "UPDATE users 
                    SET email_verified = TRUE, 
                        verification_token = NULL, 
                        token_expires_at = NULL,
                        status = 'active'
                    WHERE user_id = ?";
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([$user['user_id']]);

            if ($result) {
                return ['success' => true, 'message' => 'Email verified successfully', 'user_id' => $user['user_id']];
            }

            return ['success' => false, 'message' => 'Failed to verify email'];
        } catch (PDOException $e) {
            error_log("Database error in verifyEmail: " . $e->getMessage());
            throw new Exception("Error verifying email");
        }
    }

    public function getMemberByVerificationToken($token)
    {
        try {
            $sql = "SELECT * FROM users WHERE verification_token = ? LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$token]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result : null;
        } catch (PDOException $e) {
            error_log("Database error in getMemberByVerificationToken: " . $e->getMessage());
            return null;
        }
    }

    public function updateVerificationToken($email, $token, $expiresAt)
    {
        try {
            $sql = "UPDATE users 
                    SET verification_token = ?, 
                        token_expires_at = ? 
                    WHERE email = ? AND email_verified = FALSE";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([$token, $expiresAt, $email]);
        } catch (PDOException $e) {
            error_log("Database error in updateVerificationToken: " . $e->getMessage());
            return false;
        }
    }

    public function checkExistingMember($username, $email, $contactNo): array
    {
        // Check username - only verified usernames block registration
        $sql = "SELECT COUNT(*) as count FROM users WHERE username = ? AND email_verified = TRUE";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$username]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['count'] > 0) {
            return ['exists' => true, 'field' => 'username', 'message' => 'Username already exists, Try others'];
        }

        // Check email - only verified emails block registration
        $sql = "SELECT COUNT(*) as count FROM users WHERE email = ? AND email_verified = TRUE";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['count'] > 0) {
            return ['exists' => true, 'field' => 'email', 'message' => 'Email already exists'];
        }
        
        // If email exists but is unverified, delete the old unverified record
        // This allows users to re-register if they didn't verify their email
        $sql = "SELECT user_id FROM users WHERE email = ? AND email_verified = FALSE";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$email]);
        $unverifiedUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($unverifiedUser) {
            // Delete the old unverified account to allow fresh registration
            try {
                $this->deleteMember($unverifiedUser['user_id']);
                error_log("Deleted unverified account for email: {$email} (user_id: {$unverifiedUser['user_id']})");
            } catch (Exception $e) {
                error_log("Failed to delete unverified account: " . $e->getMessage());
            }
        }

        // Check contact number - keep as is (contact numbers are unique regardless of verification status)
        $sql = "SELECT COUNT(*) as count FROM users WHERE contact_no = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$contactNo]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['count'] > 0) {
            return ['exists' => true, 'field' => 'contact_no', 'message' => 'Contact number already exists'];
        }

        return ['exists' => false];
    }

    public function getAllMembers($limit = 10, $offset = 0, $searchTerm = '', $sortBy = 'created_at', $sortOrder = 'DESC', $statusFilter = ''): array
    {
        try {
            // Ensure limit and offset are integers
            $limit = (int)$limit;
            $offset = (int)$offset;

            // Trim and normalize search term and status
            $searchTerm = trim($searchTerm);
            $statusFilter = trim($statusFilter);

            // Validate sort column to prevent SQL injection
            $allowedSortColumns = ['username', 'full_name', 'email', 'contact_no', 'gender', 'created_at'];
            if (!in_array($sortBy, $allowedSortColumns)) {
                $sortBy = 'created_at';
            }

            // Validate sort order
            $sortOrder = strtoupper($sortOrder);
            if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
                $sortOrder = 'DESC';
            }

            // Base query - exclude deleted members by default
            $sql = "SELECT 
                    user_id,
                    username,
                    full_name,
                    email,
                    contact_no,
                    gender,
                    profile_photo,
                    status,
                    DateOfBirth,
                    created_at
                FROM users
                WHERE role = 'member' AND status != 'deleted'";

            $params = [];

            // Add search filter if provided (after trimming)
            if (!empty($searchTerm)) {
                $sql .= " AND (
                        username LIKE :search OR
                        full_name LIKE :search OR
                        email LIKE :search OR
                        contact_no LIKE :search
                    )";
                $params[':search'] = "%{$searchTerm}%";
            }

            // Add status filter if provided and valid
            if (!empty($statusFilter)) {
                // Only allow known statuses to avoid SQL injection / invalid values
                $allowedStatuses = ['active', 'inactive', 'banned', 'blocked', 'deleted'];
                if (in_array($statusFilter, $allowedStatuses, true)) {
                    // If filtering for deleted, remove the default exclusion
                    if ($statusFilter === 'deleted') {
                        $sql = str_replace(" AND status != 'deleted'", "", $sql);
                    }
                    $sql .= " AND status = :status";
                    $params[':status'] = $statusFilter;
                }
            }

            // Add ordering and pagination (safe integers and validated columns, not bound as parameters)
            $sql .= " ORDER BY $sortBy $sortOrder LIMIT $limit OFFSET $offset";

            // Debug logging
            error_log("SQL Query: " . $sql);
            error_log("Params: " . print_r($params, true));

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Log results
            error_log("Query Results: " . print_r($results, true));

            return $results;
        } catch (PDOException $e) {
            error_log("Database error in getAllMembers: " . $e->getMessage());
            throw new Exception("Error retrieving members");
        }
    }


    /**
     * Get all member IDs matching the filters (no pagination)
     */
    public function getAllMemberIds($searchTerm = '', $statusFilter = ''): array
    {
        try {
            // Trim and normalize search term and status
            $searchTerm = trim($searchTerm);
            $statusFilter = trim($statusFilter);

            // Exclude deleted members by default
            $sql = "SELECT user_id FROM users WHERE role = 'member' AND status != 'deleted'";
            $params = [];

            if (!empty($searchTerm)) {
                $sql .= " AND (
                    username LIKE :search OR
                    full_name LIKE :search OR
                    email LIKE :search OR
                    contact_no LIKE :search
                )";
                $params[':search'] = "%{$searchTerm}%";
            }

            // Add status filter if provided and valid
            if (!empty($statusFilter)) {
                $allowedStatuses = ['active', 'inactive', 'banned', 'blocked', 'deleted'];
                if (in_array($statusFilter, $allowedStatuses, true)) {
                    // If filtering for deleted, remove the default exclusion
                    if ($statusFilter === 'deleted') {
                        $sql = str_replace(" AND status != 'deleted'", "", $sql);
                    }
                    $sql .= " AND status = :status";
                    $params[':status'] = $statusFilter;
                }
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return array_map('intval', $results);
        } catch (PDOException $e) {
            error_log("Database error in getAllMemberIds: " . $e->getMessage());
            throw new Exception("Error retrieving member IDs");
        }
    }

    public function getTotalMembersCount($searchTerm = '', $statusFilter = '')
    {
        try {
            // Trim and normalize search term and status
            $searchTerm = trim($searchTerm);
            $statusFilter = trim($statusFilter);

            // Exclude deleted members by default
            $sql = "SELECT COUNT(*) as total FROM users WHERE role = 'member' AND status != 'deleted'";
            $params = [];

            if (!empty($searchTerm)) {
                $sql .= " AND (
                    username LIKE ? OR 
                    full_name LIKE ? OR 
                    email LIKE ? OR 
                    contact_no LIKE ?
                )";
                $searchParam = "%{$searchTerm}%";
                $params = array_fill(0, 4, $searchParam);
            }

            // Add status filter if provided and valid
            if (!empty($statusFilter)) {
                $allowedStatuses = ['active', 'inactive', 'banned', 'blocked', 'deleted'];
                if (in_array($statusFilter, $allowedStatuses, true)) {
                    // If filtering for deleted, remove the default exclusion
                    if ($statusFilter === 'deleted') {
                        $sql = str_replace(" AND status != 'deleted'", "", $sql);
                    }
                    $sql .= " AND status = ?";
                    $params[] = $statusFilter;
                }
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return (int) $result['total'];
        } catch (PDOException $e) {
            error_log("Database error in getTotalMembersCount: " . $e->getMessage());
            throw new Exception("Error counting members");
        }
    }

    /**
     * Get count of all members
     * Counts all users with role = 'member' regardless of status
     */
    public function getActiveMembersCount(): int
    {
        try {
            $sql = "SELECT COUNT(*) as total 
                    FROM users 
                    WHERE role = 'member'";

            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return (int) $result['total'];
        } catch (PDOException $e) {
            error_log("Database error in getActiveMembersCount: " . $e->getMessage());
            throw new Exception("Error counting members");
        }
    }

    /**
     * Get count of all new members that were created recently (in the last 7 days)
     * This represents new members registered recently, regardless of status
     */
    public function getRecentActiveMembersCount($days = 7): int
    {
        try {
            $pastDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));

            $sql = "SELECT COUNT(*) as total 
                    FROM users 
                    WHERE role = 'member' 
                    AND created_at >= ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$pastDate]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return (int) $result['total'];
        } catch (PDOException $e) {
            error_log("Database error in getRecentActiveMembersCount: " . $e->getMessage());
            throw new Exception("Error counting recent members");
        }
    }

    /**
     * Fetch a single user record by username
     * Returns associative array or null when not found
     */
    public function getMemberByUsername($username)
    {
        try {
            // Exclude deleted members from authentication
            $sql = "SELECT * FROM users WHERE username = ? AND status != 'deleted' LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$username]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? $result : null;
        } catch (PDOException $e) {
            error_log("Database error in getMemberByUsername: " . $e->getMessage());
            throw new Exception("Error fetching member");
        }
    }

    /**
     * Fetch a single user record by email
     */
    public function getMemberByEmail($email)
    {
        try {
            // Exclude deleted members
            $sql = "SELECT * FROM users WHERE email = ? AND status != 'deleted' LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$email]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? $result : null;
        } catch (PDOException $e) {
            error_log("Database error in getMemberByEmail: " . $e->getMessage());
            throw new Exception("Error fetching member by email");
        }
    }

    /**
     * Fetch a single user record by id
     */
    public function getMemberById($userId)
    {
        try {
            $sql = "SELECT * FROM users WHERE user_id = ? LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ? $result : null;
        } catch (PDOException $e) {
            error_log("Database error in getMemberById: " . $e->getMessage());
            throw new Exception("Error fetching member by id");
        }
    }

    /**
     * Update the stored password hash for a user by id
     */
    public function updatePasswordHash($userId, $newHashedPassword)
    {
        try {
            $sql = "UPDATE users SET password = ? WHERE user_id = ?";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([$newHashedPassword, $userId]);
        } catch (PDOException $e) {
            error_log("Database error in updatePasswordHash: " . $e->getMessage());
            return false;
        }
    }

    public function updateMember(MemberUpdateDTO $memberDTO): bool
    {
        try {
            $sql = "UPDATE users 
                    SET full_name = ?, email = ?, gender = ?, contact_no = ? 
                    WHERE user_id = ?";

            $stmt = $this->db->prepare($sql);

            $result = $stmt->execute([
                $memberDTO->getFullName(),
                $memberDTO->getEmail(),
                $memberDTO->getGender(),
                $memberDTO->getContactNo(),
                $memberDTO->getUserId()
            ]);

            return $result;
        } catch (PDOException $e) {
            error_log("Database error in updateMember: " . $e->getMessage());
            throw new Exception("Error updating member");
        }
    }

    public function checkExistingMemberForUpdate($userId, $username, $email, $contactNo): array
    {
        // Check username (excluding current user)
        $sql = "SELECT COUNT(*) as count FROM users WHERE username = ? AND user_id != ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$username, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['count'] > 0) {
            return ['exists' => true, 'field' => 'username', 'message' => 'Username already exists, Try others'];
        }

        // Check email (excluding current user)
        $sql = "SELECT COUNT(*) as count FROM users WHERE email = ? AND user_id != ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$email, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['count'] > 0) {
            return ['exists' => true, 'field' => 'email', 'message' => 'Email already exists'];
        }

        // Check contact number (excluding current user)
        $sql = "SELECT COUNT(*) as count FROM users WHERE contact_no = ? AND user_id != ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$contactNo, $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result['count'] > 0) {
            return ['exists' => true, 'field' => 'contact_no', 'message' => 'Contact number already exists'];
        }

        return ['exists' => false];
    }

    public function updateMemberStatus($userId, $status): bool
    {
        try {
            // Validate status
            $allowedStatuses = ['active', 'inactive', 'banned', 'blocked', 'deleted'];
            if (!in_array($status, $allowedStatuses)) {
                throw new Exception("Invalid status value");
            }

            $sql = "UPDATE users SET status = ? WHERE user_id = ? AND role = 'member'";
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([$status, $userId]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Database error in updateMemberStatus: " . $e->getMessage());
            throw new Exception("Error updating member status");
        }
    }

    public function deleteMember($userId): bool
    {
        try {
            // Soft delete: set status to 'deleted' instead of actually deleting
            $sql = "UPDATE users SET status = 'deleted' WHERE user_id = ? AND role = 'member'";

            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([$userId]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Database error in deleteMember: " . $e->getMessage());
            throw new Exception("Error deleting member");
        }
    }

    /**
     * Bulk delete members by array of user IDs
     */
    public function bulkDeleteMembers(array $userIds): array
    {
        try {
            if (empty($userIds)) {
                return ['success' => false, 'message' => 'No members selected for deletion'];
            }

            // Validate and sanitize user IDs
            $validUserIds = [];
            foreach ($userIds as $userId) {
                $userId = (int)$userId;
                if ($userId > 0) {
                    $validUserIds[] = $userId;
                }
            }

            if (empty($validUserIds)) {
                return ['success' => false, 'message' => 'No valid member IDs provided'];
            }

            // Create placeholders for IN clause
            $placeholders = str_repeat('?,', count($validUserIds) - 1) . '?';

            // Soft delete: set status to 'deleted' instead of actually deleting
            $sql = "UPDATE users SET status = 'deleted' WHERE user_id IN ($placeholders) AND role = 'member'";
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute($validUserIds);

            $deletedCount = $stmt->rowCount();

            return [
                'success' => true,
                'deleted_count' => $deletedCount,
                'message' => "Successfully deleted $deletedCount member(s)."
            ];
        } catch (PDOException $e) {
            error_log("Database error in bulkDeleteMembers: " . $e->getMessage());
            throw new Exception("Error bulk deleting members: " . $e->getMessage());
        }
    }

    // Failed login tracking methods
    public function incrementFailedLoginAttempts($username)
    {
        $sql = "UPDATE users SET failed_login_attempts = failed_login_attempts + 1, last_failed_login = NOW() WHERE username = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$username]);
    }

    public function resetFailedLoginAttempts($username)
    {
        $sql = "UPDATE users SET failed_login_attempts = 0, last_failed_login = NULL WHERE username = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$username]);
    }

    public function blockUser($username)
    {
        $sql = "UPDATE users SET status = 'blocked' WHERE username = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$username]);
    }

    /**
     * Save remember me token for persistent login
     */
    public function saveRememberToken(int $userId, string $hashedToken, string $expiry): bool
    {
        $sql = "UPDATE users SET remember_token = ?, remember_token_expires_at = ? WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$hashedToken, $expiry, $userId]);
    }

    /**
     * Get remember me token data for a user
     */
    public function getRememberToken(int $userId): ?array
    {
        $sql = "SELECT remember_token, remember_token_expires_at AS token_expires_at FROM users WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Clear remember me token for a user
     */
    public function clearRememberToken(int $userId): bool
    {
        $sql = "UPDATE users SET remember_token = NULL WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$userId]);
    }

    /**
     * Set OTP and expiry for password reset
     */
    public function setResetOtp($userId, $otp, $expiresAt)
    {
        $sql = "UPDATE users SET reset_otp = ?, reset_otp_expires_at = ? WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$otp, $expiresAt, $userId]);
    }

    /**
     * Get OTP and expiry for a user
     */
    public function getResetOtp($userId)
    {
        $sql = "SELECT reset_otp, reset_otp_expires_at FROM users WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Clear OTP after use
     */
    public function clearResetOtp($userId)
    {
        $sql = "UPDATE users SET reset_otp = NULL, reset_otp_expires_at = NULL WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$userId]);
    }

    /**
     * Get 2FA secret for a user
     */
    public function getTwoFactorSecret($userId)
    {
        $sql = "SELECT two_factor_secret, two_factor_enabled FROM users WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Set 2FA secret for a user
     */
    public function setTwoFactorSecret($userId, $secret, $enabled = false)
    {
        $sql = "UPDATE users SET two_factor_secret = ?, two_factor_enabled = ? WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$secret, $enabled ? 1 : 0, $userId]);
    }

    /**
     * Check if 2FA is enabled for a user
     */
    public function isTwoFactorEnabled($userId)
    {
        $sql = "SELECT two_factor_enabled FROM users WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result['two_factor_enabled'] == 1;
    }

    /**
     * Enable 2FA for a user (after successful setup verification)
     */
    public function enableTwoFactor($userId)
    {
        $sql = "UPDATE users SET two_factor_enabled = 1 WHERE user_id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$userId]);
    }
}

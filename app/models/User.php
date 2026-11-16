<?php
/**
 * Module: User Management & Authentication
 * Purpose: Data layer for user CRUD operations and authentication
 * Reference: Task 2b System Design Section 4.1 - User Entity
 * Author: WIL Student
 * Entity: Users (MySQL table)
 */

class User
{
    private $conn;
    private $table = "Users";

    public function __construct($db)
    {
        $this->conn = $db;
    }

    public function supportsEmailVerificationFields()
    {
        try {
            $stmt = $this->conn->query(
                'SELECT EmailVerifiedAt, PendingEmail, EmailGraceUntil,
                        RegistrationVerificationRequired, SmsVerifiedAt
                 FROM Users LIMIT 1'
            );
            return $stmt !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function isEmailVerificationGraceSatisfied(array $user)
    {
        if (!$this->supportsEmailVerificationFields()) {
            return true;
        }

        $emailVerifiedAt = $user['EmailVerifiedAt'] ?? null;
        if (!empty($emailVerifiedAt)) {
            return true;
        }

        $graceUntil = $user['EmailGraceUntil'] ?? null;
        try {
            $timestamp = is_string($graceUntil)
                ? (new DateTimeImmutable($graceUntil, new DateTimeZone('UTC')))->getTimestamp()
                : false;
        } catch (Exception $e) {
            $timestamp = false;
        }
        return $timestamp !== false && $timestamp > time();
    }

    public function getLoginVerificationMessage(array $user)
    {
        if (!$this->supportsEmailVerificationFields()) {
            return null;
        }

        $registrationVerificationRequired = !empty($user['RegistrationVerificationRequired']);
        if ($registrationVerificationRequired &&
            (empty($user['EmailVerifiedAt']) || empty($user['SmsVerifiedAt']))) {
            return 'Verify your email address and phone number before signing in.';
        }

        if (empty($user['EmailVerifiedAt']) &&
            !empty($user['EmailGraceUntil']) &&
            !$this->isEmailVerificationGraceSatisfied($user)) {
            return 'Verify your email address before signing in.';
        }

        return null;
    }

    public function requestEmailVerification($userId, $email, $purpose = 'register')
    {
        if (!$this->supportsEmailVerificationFields()) {
            return false;
        }

        require_once __DIR__ . '/../helpers/EmailVerificationService.php';
        $service = new EmailVerificationService($this->conn);
        return $service->createTokenForUser((int)$userId, $email, $purpose);
    }

    public function completeEmailVerification($userId, $email, $token, $purpose = 'register')
    {
        if (!$this->supportsEmailVerificationFields()) {
            return false;
        }

        require_once __DIR__ . '/../helpers/EmailVerificationService.php';
        $service = new EmailVerificationService($this->conn);
        return $service->verifyToken((int)$userId, $email, $token, $purpose);
    }

    public function requestSmsVerification($userId, $phone)
    {
        require_once __DIR__ . '/../helpers/SmsVerificationService.php';
        $service = new SmsVerificationService($this->conn);
        return $service->createOtp((int)$userId, $phone);
    }

    public function verifySmsOtp($userId, $phone, $code)
    {
        require_once __DIR__ . '/../helpers/SmsVerificationService.php';
        $service = new SmsVerificationService($this->conn);
        return $service->verifyOtp((int)$userId, $phone, $code);
    }

    /**
     * HZ-USER-001
     * Purpose: Authenticate user by verifying username and password
     * Table: Users
     * Returns: User array on success, false on failure
     * Security: Uses password_verify for secure password comparison
     */
    public function authenticate($username, $password)
    {
        $user = $this->findByUsername($username);

        if (!$user) {
            return false;
        }

        if (!password_verify($password, $user["PasswordHash"])) {
            return false;
        }

        return $user;
    }

    /**
     * HZ-USER-002
     * Purpose: Find user by username from Users table
     * Table: Users
     * Returns: User record (array) or false if not found
     * Security: Uses parameterized query to prevent SQL injection
     */
    public function findByUsername($username)
    {
        $verificationFields = $this->supportsEmailVerificationFields()
            ? ', EmailVerifiedAt, SmsVerifiedAt, EmailGraceUntil, RegistrationVerificationRequired'
            : '';
        $query = "SELECT UserID, Username, Email, PasswordHash, Role, CreatedAt, Status{$verificationFields}
                  FROM " . $this->table . " 
                  WHERE Username = :username 
                  AND Status IN ('active', 'pending') 
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":username", $username);

        if ($stmt->execute()) {
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return false;
    }

    /**
     * HZ-USER-003
     * Purpose: Find user by email address
     * Table: Users
     * Returns: User record (array) or false if not found
     * Security: Uses parameterized query to prevent SQL injection
     */
    public function findByEmail($email)
    {
        $query = "SELECT UserID, Username, Email, Role, CreatedAt, Status 
                  FROM " . $this->table . " 
                  WHERE Email = :email 
                  AND Status IN ('active', 'pending')
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":email", $email);

        if ($stmt->execute()) {
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return false;
    }

    /**
     * HZ-USER-004
     * Purpose: Register new user account
     * Table: Users
     * Returns: UserID on success, false on failure
     * Security: Hashes password using PHP password_hash, validates input
     * Validation: Checks for duplicate username/email, validates email format
     */
    public function register($username, $email, $password, $role = 'volunteer', $fullName = null, $phone = null, $status = 'pending', $requiresVerification = false)
    {
        // Validation: Check if username already exists
        if ($this->findByUsername($username)) {
            throw new Exception("Username already exists");
        }

        // Validation: Check if email already exists
        if ($this->findByEmail($email)) {
            throw new Exception("Email already exists");
        }

        // Validation: Email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format");
        }
        if ($requiresVerification) {
            if (!$this->supportsEmailVerificationFields()) {
                throw new RuntimeException('Apply the email and SMS verification migration before accepting new registrations.');
            }
            require_once __DIR__ . '/../helpers/SmsVerificationService.php';
            $phone = SmsVerificationService::normalizePhoneNumber($phone);
            if (!preg_match('/^0[6-8]\d{8}$/', $phone)) {
                throw new InvalidArgumentException('A valid South African mobile number is required.');
            }
        }

        $this->validatePasswordStrength($password);

        $this->validatePasswordStrength($password);

        // Hash password using PHP password_hash (bcrypt)
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        // Prepare and execute INSERT query
        $columns = 'Username, Email, PasswordHash, FullName, Phone, Role, Status';
        $values = ':username, :email, :password_hash, :full_name, :phone, :role, :status';
        if ($requiresVerification) {
            $columns .= ', RegistrationVerificationRequired';
            $values .= ', 1';
        }
        $query = "INSERT INTO " . $this->table . " ({$columns}) VALUES ({$values})";

        $stmt = $this->conn->prepare($query);

        // Bind parameters
        $stmt->bindParam(":username", $username);
        $stmt->bindParam(":email", $email);
        $stmt->bindParam(":password_hash", $hashedPassword);
        $stmt->bindParam(":full_name", $fullName);
        $stmt->bindParam(":phone", $phone);
        $stmt->bindParam(":role", $role);
        $stmt->bindParam(":status", $status);

        if ($stmt->execute()) {
            return (int)$this->conn->lastInsertId();
        }

        return false;
    }

    /**
     * HZ-USER-005
     * Purpose: Get user by UserID
     * Table: Users
     * Returns: User record (array) or false if not found
     * Security: Uses parameterized query
     */
    public function getUserById($userId)
    {
        $verificationFields = $this->supportsEmailVerificationFields()
            ? 'EmailVerifiedAt, PendingEmail, EmailGraceUntil, RegistrationVerificationRequired, SmsVerifiedAt, '
            : '';
        $query = "SELECT UserID, Username, Email, {$verificationFields}Phone, FullName, Role, CreatedAt, Status, UpdatedAt
                  FROM " . $this->table . "
                  WHERE UserID = :user_id
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $userId);

        if ($stmt->execute()) {
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return false;
    }

    public function getUserAuthById($userId)
    {
        $query = "SELECT UserID, Username, Email, PasswordHash, FullName, Phone, Role, CreatedAt, Status, UpdatedAt
                  FROM " . $this->table . " WHERE UserID = :user_id LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $userId);
        return $stmt->execute() ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    }

    public function updateDetails($userId, $email, $fullName, $phone, $role = null, $status = null)
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format");
        }

        $existing = $this->getUserById((int)$userId);
        if (!$existing) {
            return false;
        }
        require_once __DIR__ . '/../helpers/SmsVerificationService.php';
        $emailChanged = strcasecmp(trim((string)$existing['Email']), trim((string)$email)) !== 0;
        if ($emailChanged && !$this->supportsEmailVerificationFields()) {
            throw new RuntimeException('Email changes require the verification migration.');
        }

        $normalizedPhone = SmsVerificationService::normalizePhoneNumber($phone);
        $registrationVerificationRequired = !empty($existing['RegistrationVerificationRequired']);
        if ($registrationVerificationRequired && !preg_match('/^0[6-8]\d{8}$/', $normalizedPhone)) {
            throw new InvalidArgumentException('A valid South African mobile number is required.');
        }
        if (preg_match('/^0[6-8]\d{8}$/', $normalizedPhone)) {
            $phone = $normalizedPhone;
        }
        $fields = ["FullName = :full_name", "Phone = :phone"];
        $params = [':full_name' => $fullName, ':phone' => $phone, ':user_id' => $userId];
        if ($emailChanged) {
            require_once __DIR__ . '/../helpers/EmailVerificationService.php';
            $verification = new EmailVerificationService($this->conn);
            $verification->assertEmailNotTakenByAnotherAccount((int)$userId, $email);
            $fields[] = 'PendingEmail = :pending_email';
            $params[':pending_email'] = EmailVerificationService::normalizeEmail($email);
        }
        $phoneChanged = SmsVerificationService::normalizePhoneNumber($existing['Phone'] ?? '') !== $normalizedPhone;
        if ($phoneChanged && $this->supportsEmailVerificationFields()) {
            $fields[] = 'SmsVerifiedAt = NULL';
        }
        if ($role !== null) {
            $fields[] = "Role = :role";
            $params[':role'] = $role;
        }
        if ($status !== null) {
            $fields[] = "Status = :status";
            $params[':status'] = $status;
        }

        $fields[] = 'UpdatedAt = CURRENT_TIMESTAMP';
        $stmt = $this->conn->prepare("UPDATE " . $this->table . " SET " . implode(', ', $fields) . " WHERE UserID = :user_id");
        return $stmt->execute($params);
    }

    /**
     * HZ-USER-006
     * Purpose: Deactivate user account (soft delete)
     * Table: Users
     * Returns: true on success, false on failure
     * Security: Uses parameterized query, does not hard-delete data
     */
    public function deactivateUser($userId)
    {
        $query = "UPDATE " . $this->table . " 
                  SET Status = 'inactive' 
                  WHERE UserID = :user_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":user_id", $userId);

        return $stmt->execute();
    }

    /**
     * HZ-USER-007
     * Purpose: Update user password
     * Table: Users
     * Returns: true on success, false on failure
     * Security: Hashes new password with bcrypt
     */
    public function changePassword($userId, $newPassword)
    {
        $this->validatePasswordStrength($newPassword);

        // Hash new password
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

        $ownsTransaction = !$this->conn->inTransaction();
        if ($ownsTransaction) {
            $this->conn->beginTransaction();
        }

        try {
            $query = "UPDATE " . $this->table . "
                      SET PasswordHash = :password_hash
                      WHERE UserID = :user_id";
            $stmt = $this->conn->prepare($query);
            $updated = $stmt->execute([
                ':password_hash' => $hashedPassword,
                ':user_id' => $userId,
            ]);

            if (!$updated) {
                if ($ownsTransaction) $this->conn->rollBack();
                return false;
            }

            // Password changes invalidate every previously issued API bearer token.
            $revoke = $this->conn->prepare(
                'UPDATE ApiTokens SET RevokedAt = UTC_TIMESTAMP() WHERE UserID = :user_id AND RevokedAt IS NULL'
            );
            $revoke->execute([':user_id' => $userId]);

            if ($ownsTransaction) {
                $this->conn->commit();
            }
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }

    private function validatePasswordStrength($password)
    {
        if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            throw new Exception('Password must be at least 8 characters and include an uppercase letter and a number');
        }
    }
}

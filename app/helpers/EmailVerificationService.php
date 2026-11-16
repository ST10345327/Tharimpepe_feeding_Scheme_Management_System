<?php
require_once __DIR__ . '/Exceptions.php';

interface EmailProviderAdapter
{
    public function send($emailAddress, $token, $purpose = 'register');
}

class MockEmailProvider implements EmailProviderAdapter
{
    private static function isProductionEnvironment()
    {
        $environment = getenv('APP_ENV');
        return is_string($environment) && strtolower(trim($environment)) === 'production';
    }

    public function send($emailAddress, $token, $purpose = 'register')
    {
        if (self::isProductionEnvironment()) {
            throw new RuntimeException('Mock email provider is disabled in production. Configure a real SMTP provider.');
        }

        $msg = "\n------------------------------------------------------------\n" .
               "[MOCK EMAIL SENT]\n" .
               "To: $emailAddress\n" .
               "Code: $token\n" .
               "Purpose: $purpose\n" .
               "------------------------------------------------------------\n";
        $logMsg = "\n------------------------------------------------------------\n" .
                  "[MOCK EMAIL SENT]\n" .
                  "To: $emailAddress\n" .
                  "Code: simulated\n" .
                  "Purpose: $purpose\n" .
                  "------------------------------------------------------------\n";
        error_log($logMsg);
        echo $msg;

        return [
            'provider' => 'mock',
            'status' => 'queued',
            'message' => 'Development mock email provider is active; see server terminal for code.',
        ];
    }
}

class SendGridEmailProvider implements EmailProviderAdapter
{
    private $apiKey;
    private $fromEmail;
    private $fromName;

    public function __construct($apiKey = null, $fromEmail = null, $fromName = null)
    {
        $this->apiKey = $apiKey === null ? (getenv('SENDGRID_API_KEY') ?: '') : $apiKey;
        $this->fromEmail = $fromEmail === null ? (getenv('EMAIL_FROM_ADDRESS') ?: '') : $fromEmail;
        $this->fromName = $fromName === null ? (getenv('EMAIL_FROM_NAME') ?: 'Tharimpepe Feeding Scheme') : $fromName;
    }

    public function send($emailAddress, $token, $purpose = 'register')
    {
        if ($this->apiKey === '' || !filter_var($this->fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new ExternalServiceException('SendGrid', 'Email verification delivery is not configured.');
        }
        if (!function_exists('curl_init')) {
            throw new ExternalServiceException('SendGrid', 'The PHP cURL extension is required for email delivery.');
        }

        $subject = $purpose === 'change-email'
            ? 'Confirm your new FSMS email address'
            : 'Verify your FSMS email address';
        $payload = json_encode([
            'personalizations' => [[
                'to' => [['email' => $emailAddress]],
            ]],
            'from' => [
                'email' => $this->fromEmail,
                'name' => $this->fromName,
            ],
            'subject' => $subject,
            'content' => [[
                'type' => 'text/plain',
                'value' => "Your FSMS verification code is: {$token}\n\nThis code expires in 30 minutes. If you did not request it, you can ignore this email.",
            ]],
        ]);
        if (!is_string($payload)) {
            throw new ExternalServiceException('SendGrid', 'Email verification delivery is temporarily unavailable.');
        }

        $handle = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $failed = $response === false;
        curl_close($handle);

        if ($failed || $status < 200 || $status >= 300) {
            throw new ExternalServiceException('SendGrid', 'Email verification delivery failed. Check the SendGrid sender and trial configuration.');
        }

        return ['provider' => 'sendgrid', 'status' => 'queued'];
    }
}

class EmailVerificationService
{
    private $db;
    private $provider;

    public function __construct(PDO $db, EmailProviderAdapter $provider = null)
    {
        $this->db = $db;
        $this->provider = $provider ?: self::createProviderFromEnvironment();
    }

    public function isMockProvider()
    {
        return $this->provider instanceof MockEmailProvider;
    }

    private static function createProviderFromEnvironment()
    {
        $provider = strtolower(trim((string)(getenv('EMAIL_PROVIDER') ?: 'mock')));
        if ($provider === 'mock') {
            return new MockEmailProvider();
        }
        if ($provider === 'sendgrid') {
            return new SendGridEmailProvider();
        }
        throw new InvalidArgumentException('Unsupported email provider configuration.');
    }

    public static function normalizeEmail($email)
    {
        return strtolower(trim((string)$email));
    }

    private static function utcTimestamp($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Exception $e) {
            return false;
        }
    }

    public function createTokenForUser($userId, $email, $purpose = 'register', $ttlMinutes = 30)
    {
        $normalizedEmail = self::normalizeEmail($email);
        if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email address is invalid.');
        }

        $this->assertEmailNotTakenByAnotherAccount((int)$userId, $normalizedEmail);
        $this->enforceResendLimit((int)$userId, $normalizedEmail, $purpose);

        $userStmt = $this->db->prepare('SELECT Email, PendingEmail FROM Users WHERE UserID = :user_id LIMIT 1');
        $userStmt->execute([':user_id' => (int)$userId]);
        $currentUser = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $currentEmail = isset($currentUser['Email']) ? self::normalizeEmail($currentUser['Email']) : '';
        $pendingEmail = isset($currentUser['PendingEmail']) ? self::normalizeEmail($currentUser['PendingEmail']) : '';

        if ($currentEmail !== '' && strtolower($currentEmail) !== strtolower($normalizedEmail) && $pendingEmail !== $normalizedEmail) {
            $this->assertEmailNotTakenByAnotherAccount((int)$userId, $normalizedEmail);
            $this->db->prepare('UPDATE Users SET PendingEmail = :pending_email WHERE UserID = :user_id')
                ->execute([
                    ':pending_email' => $normalizedEmail,
                    ':user_id' => (int)$userId,
                ]);
        } elseif ($purpose === 'register') {
            $this->db->prepare(
                'UPDATE Users SET RegistrationVerificationRequired = 1, EmailGraceUntil = NULL
                 WHERE UserID = :user_id'
            )->execute([':user_id' => (int)$userId]);
        }

        $token = bin2hex(random_bytes(16));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + max(1, (int)$ttlMinutes) * 60);

        $stmt = $this->db->prepare(
            'INSERT INTO EmailVerificationTokens (UserID, EmailAddress, Purpose, TokenHash, ExpiresAt, CreatedAt)
             VALUES (:user_id, :email_address, :purpose, :token_hash, :expires_at, :created_at)'
        );
        $stmt->execute([
            ':user_id' => (int)$userId,
            ':email_address' => $normalizedEmail,
            ':purpose' => $purpose,
            ':token_hash' => hash('sha256', $token),
            ':expires_at' => $expiresAt,
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        try {
            $this->provider->send($normalizedEmail, $token, $purpose);
        } catch (Throwable $e) {
            $this->db->prepare('DELETE FROM EmailVerificationTokens WHERE TokenHash = :token_hash AND ConsumedAt IS NULL')
                ->execute([':token_hash' => hash('sha256', $token)]);
            throw $e;
        }

        return $token;
    }

    public function verifyToken($userId, $email, $token, $purpose = 'register')
    {
        $normalizedEmail = self::normalizeEmail($email);
        if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        if (!is_string($token) || trim($token) === '') {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT TokenID, TokenHash, EmailAddress, Purpose, ExpiresAt, ConsumedAt, CreatedAt
             FROM EmailVerificationTokens
             WHERE UserID = :user_id AND LOWER(TRIM(EmailAddress)) = :email_address AND Purpose = :purpose
             ORDER BY CreatedAt DESC, TokenID DESC LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => (int)$userId,
            ':email_address' => $normalizedEmail,
            ':purpose' => $purpose,
        ]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return false;
        }
        if (!empty($record['ConsumedAt'])) {
            return false;
        }
        $expiresAt = self::utcTimestamp((string)$record['ExpiresAt']);
        if ($expiresAt === false || $expiresAt <= time()) {
            return false;
        }
        if (!hash_equals((string)$record['TokenHash'], hash('sha256', $token))) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare(
                'UPDATE EmailVerificationTokens
                 SET ConsumedAt = :consumed_at
                 WHERE TokenID = :token_id AND ConsumedAt IS NULL'
            );
            $update->execute([
                ':consumed_at' => gmdate('Y-m-d H:i:s'),
                ':token_id' => (int)$record['TokenID'],
            ]);
            if ($update->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $userUpdate = $this->db->prepare(
                'UPDATE Users
                 SET EmailVerifiedAt = :verified_at,
                     PendingEmail = NULL,
                     Email = :email
                 WHERE UserID = :user_id'
            );
            $userUpdate->execute([
                ':verified_at' => gmdate('Y-m-d H:i:s'),
                ':email' => $normalizedEmail,
                ':user_id' => (int)$userId,
            ]);

            $this->db->commit();
            return $userUpdate->rowCount() === 1;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function assertEmailNotTakenByAnotherAccount($userId, $email)
    {
        $stmt = $this->db->prepare(
            'SELECT UserID FROM Users
             WHERE (LOWER(TRIM(Email)) = :email OR LOWER(TRIM(PendingEmail)) = :pending_email)
               AND UserID != :user_id
             LIMIT 1'
        );
        $stmt->execute([
            ':email' => self::normalizeEmail($email),
            ':pending_email' => self::normalizeEmail($email),
            ':user_id' => (int)$userId,
        ]);
        if ($stmt->fetchColumn()) {
            throw new DomainException('A different account already uses this email address.');
        }
    }

    public function enforceResendLimit($userId, $email, $purpose)
    {
        $stmt = $this->db->prepare(
            'SELECT CreatedAt FROM EmailVerificationTokens
             WHERE UserID = :user_id AND LOWER(TRIM(EmailAddress)) = :email_address AND Purpose = :purpose AND ExpiresAt > :now
             ORDER BY CreatedAt DESC, TokenID DESC LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => (int)$userId,
            ':email_address' => self::normalizeEmail($email),
            ':purpose' => $purpose,
            ':now' => gmdate('Y-m-d H:i:s', time()),
        ]);
        $lastSent = $stmt->fetchColumn();

        $lastSentTimestamp = self::utcTimestamp((string)$lastSent);
        if ($lastSentTimestamp !== false && (time() - $lastSentTimestamp) < 60) {
            throw new InvalidOperationException(
                'Email verification was requested too recently.',
                'A verification email was sent recently. Please wait before requesting another one.'
            );
        }
    }
}

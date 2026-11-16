<?php
require_once __DIR__ . '/Exceptions.php';

interface SmsProviderAdapter
{
    public function send($phoneNumber, $code);
}

class MockSmsProvider implements SmsProviderAdapter
{
    private static function isProductionEnvironment()
    {
        $environment = getenv('APP_ENV');
        return is_string($environment) && strtolower(trim($environment)) === 'production';
    }

    public function send($phoneNumber, $code)
    {
        if (self::isProductionEnvironment()) {
            throw new RuntimeException('Mock SMS provider is disabled in production. Configure a real SMS provider.');
        }

        $msg = "\n------------------------------------------------------------\n" .
               "[MOCK SMS SENT]\n" .
               "To: $phoneNumber\n" .
               "OTP Code: $code\n" .
               "------------------------------------------------------------\n";
        error_log($msg);
        echo $msg;

        return [
            'provider' => 'mock',
            'status' => 'queued',
            'message' => 'Development mock SMS provider is active; see server terminal for code.',
        ];
    }
}

class TwilioSmsProvider implements SmsProviderAdapter
{
    private $accountSid;
    private $authToken;
    private $from;

    public function __construct($accountSid = null, $authToken = null, $from = null)
    {
        $this->accountSid = $accountSid === null ? (getenv('SMS_ACCOUNT_SID') ?: '') : $accountSid;
        $this->authToken = $authToken === null ? (getenv('SMS_AUTH_TOKEN') ?: '') : $authToken;
        $this->from = $from === null ? (getenv('SMS_FROM') ?: '') : $from;
    }

    public function send($phoneNumber, $code)
    {
        if ($this->accountSid === '' || $this->authToken === '' || $this->from === '') {
            throw new ExternalServiceException('Twilio', 'SMS verification delivery is not configured.');
        }
        if (!function_exists('curl_init')) {
            throw new ExternalServiceException('Twilio', 'The PHP cURL extension is required for SMS delivery.');
        }

        $normalizedPhone = SmsVerificationService::toE164($phoneNumber);
        $handle = curl_init(
            'https://api.twilio.com/2010-04-01/Accounts/' .
            rawurlencode($this->accountSid) . '/Messages.json'
        );
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'To' => $normalizedPhone,
                'From' => $this->from,
                'Body' => 'Your Tharimpepe FSMS verification code is ' . $code . '. It expires in 5 minutes.',
            ]),
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->accountSid . ':' . $this->authToken,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $failed = $response === false;
        curl_close($handle);

        if ($failed || $status < 200 || $status >= 300) {
            throw new ExternalServiceException('Twilio', 'SMS delivery failed. Check the Twilio sender, trial balance, and verified recipient.');
        }

        return ['provider' => 'twilio', 'status' => 'queued'];
    }
}

class SmsVerificationService
{
    const DEFAULT_TTL_MINUTES = 5;
    const DEFAULT_MAX_ATTEMPTS = 5;
    const DEFAULT_RESEND_SECONDS = 60;

    private $db;
    private $provider;

    public function __construct(PDO $db, SmsProviderAdapter $provider = null)
    {
        $this->db = $db;
        $this->provider = $provider ?: self::createProviderFromEnvironment();
    }

    public function isMockProvider()
    {
        return $this->provider instanceof MockSmsProvider;
    }

    private static function createProviderFromEnvironment()
    {
        $provider = strtolower(trim((string)(getenv('SMS_PROVIDER') ?: 'mock')));
        if ($provider === 'mock') {
            return new MockSmsProvider();
        }
        if ($provider === 'twilio') {
            return new TwilioSmsProvider();
        }
        throw new InvalidArgumentException('Unsupported SMS provider configuration.');
    }

    public static function normalizePhoneNumber($phone)
    {
        $digits = preg_replace('/\D+/', '', (string)$phone);
        if ($digits === '') {
            return '';
        }

        if (strpos($digits, '27') === 0 && strlen($digits) === 11) {
            return '0' . substr($digits, 2);
        }
        if (strpos($digits, '0') === 0 && strlen($digits) === 10) {
            return $digits;
        }
        if (strlen($digits) === 9) {
            return '0' . $digits;
        }
        if (strlen($digits) === 12 && strpos($digits, '27') === 0) {
            return '0' . substr($digits, 2);
        }
        return $digits;
    }

    public static function toE164($phone)
    {
        $normalized = self::normalizePhoneNumber($phone);
        if (!preg_match('/^0[6-8]\d{8}$/', $normalized)) {
            throw new InvalidArgumentException('Invalid South African mobile number format.');
        }
        return '+27' . substr($normalized, 1);
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

    public function createOtp($userId, $phone, $ttlMinutes = self::DEFAULT_TTL_MINUTES)
    {
        $normalizedPhone = self::normalizePhoneNumber($phone);
        if ($normalizedPhone === '') {
            throw new InvalidArgumentException('A valid South African phone number is required.');
        }
        if (!preg_match('/^0[6-8]\d{8}$/', $normalizedPhone)) {
            throw new InvalidArgumentException('Invalid South African mobile number format.');
        }

        $userStmt = $this->db->prepare('SELECT Phone FROM Users WHERE UserID = :user_id LIMIT 1');
        $userStmt->execute([':user_id' => (int)$userId]);
        $storedPhone = $userStmt->fetchColumn();
        if ($storedPhone === false || self::normalizePhoneNumber($storedPhone) !== $normalizedPhone) {
            throw new InvalidArgumentException('The phone number must match the number on the account.');
        }

        $lastSent = $this->getMostRecentCreatedAt((int)$userId, $normalizedPhone);
        $lastSentTimestamp = $lastSent ? self::utcTimestamp((string)$lastSent) : false;
        if ($lastSentTimestamp !== false && (time() - $lastSentTimestamp) < self::DEFAULT_RESEND_SECONDS) {
            throw new RuntimeException('A one-time password was already sent recently. Please wait before requesting another one.');
        }

        $code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + max(1, (int)$ttlMinutes) * 60);
        $insert = $this->db->prepare(
            'INSERT INTO SmsVerificationTokens (UserID, PhoneNumber, OtpHash, ExpiresAt, CreatedAt, Attempts)
             VALUES (:user_id, :phone_number, :otp_hash, :expires_at, :created_at, 0)'
        );
        $insert->execute([
            ':user_id' => (int)$userId,
            ':phone_number' => $normalizedPhone,
            ':otp_hash' => hash('sha256', $code),
            ':expires_at' => $expiresAt,
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $tokenId = (int)$this->db->lastInsertId();
        try {
            $this->provider->send($normalizedPhone, $code);
        } catch (Throwable $e) {
            $this->db->prepare('DELETE FROM SmsVerificationTokens WHERE TokenID = :token_id AND ConsumedAt IS NULL')
                ->execute([':token_id' => $tokenId]);
            throw $e;
        }
        return $code;
    }

    public function resendRegistrationOtp($userId, $phone)
    {
        return $this->createOtp($userId, $phone);
    }

    public function verifyOtp($userId, $phone, $code)
    {
        $normalizedPhone = self::normalizePhoneNumber($phone);
        $stmt = $this->db->prepare(
            'SELECT TokenID, UserID, PhoneNumber, OtpHash, ExpiresAt, Attempts, ConsumedAt, CreatedAt
             FROM SmsVerificationTokens
             WHERE UserID = :user_id AND PhoneNumber = :phone_number AND ConsumedAt IS NULL
             ORDER BY CreatedAt DESC, TokenID DESC LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => (int)$userId,
            ':phone_number' => $normalizedPhone,
        ]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return false;
        }
        $expiresAtTimestamp = self::utcTimestamp((string)$record['ExpiresAt']);
        if ($expiresAtTimestamp === false || $expiresAtTimestamp <= time()) {
            return false;
        }
        if ((int)$record['Attempts'] >= self::DEFAULT_MAX_ATTEMPTS) {
            return false;
        }
        if (!hash_equals((string)$record['OtpHash'], hash('sha256', (string)$code))) {
            $this->db->prepare(
                'UPDATE SmsVerificationTokens SET Attempts = Attempts + 1 WHERE TokenID = :token_id'
            )->execute([':token_id' => (int)$record['TokenID']]);
            return false;
        }

        $this->db->beginTransaction();
        try {
            $currentPhone = $this->db->prepare('SELECT Phone FROM Users WHERE UserID = :user_id LIMIT 1');
            $currentPhone->execute([':user_id' => (int)$userId]);
            if (self::normalizePhoneNumber($currentPhone->fetchColumn()) !== $normalizedPhone) {
                $this->db->rollBack();
                return false;
            }

            $consume = $this->db->prepare(
                'UPDATE SmsVerificationTokens SET ConsumedAt = :consumed_at
                 WHERE TokenID = :token_id AND ConsumedAt IS NULL'
            );
            $consume->execute([
                ':consumed_at' => gmdate('Y-m-d H:i:s'),
                ':token_id' => (int)$record['TokenID'],
            ]);
            if ($consume->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $updateUser = $this->db->prepare(
                'UPDATE Users SET SmsVerifiedAt = :verified_at WHERE UserID = :user_id'
            );
            $updateUser->execute([
                ':verified_at' => gmdate('Y-m-d H:i:s'),
                ':user_id' => (int)$userId,
            ]);
            if ($updateUser->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function getMostRecentCreatedAt($userId, $phone)
    {
        $stmt = $this->db->prepare(
            'SELECT CreatedAt FROM SmsVerificationTokens
             WHERE UserID = :user_id AND PhoneNumber = :phone_number
             ORDER BY CreatedAt DESC, TokenID DESC LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => (int)$userId,
            ':phone_number' => $phone,
        ]);
        return $stmt->fetchColumn() ?: null;
    }
}

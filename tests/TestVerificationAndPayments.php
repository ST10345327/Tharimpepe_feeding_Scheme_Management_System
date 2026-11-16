<?php
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../app/helpers/EmailVerificationService.php';
require_once __DIR__ . '/../app/helpers/SmsVerificationService.php';
require_once __DIR__ . '/../app/helpers/PayFastService.php';
require_once __DIR__ . '/../app/models/User.php';
require_once __DIR__ . '/../app/models/Donation.php';

class VerificationAndPaymentsTest extends TestCase
{
    private $db;

    public function setUp()
    {
        putenv('PAYFAST_MODE=sandbox');
        putenv('PAYFAST_MERCHANT_ID=123456');
        putenv('PAYFAST_MERCHANT_KEY=abcd1234');
        putenv('PAYFAST_PASSPHRASE=secret');

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE Users (
                UserID INTEGER PRIMARY KEY AUTOINCREMENT,
                Username TEXT NOT NULL,
                Email TEXT NOT NULL,
                EmailVerifiedAt TEXT NULL,
                PendingEmail TEXT NULL,
                EmailGraceUntil TEXT NULL,
                RegistrationVerificationRequired INTEGER NOT NULL DEFAULT 0,
                LastEmailVerificationSentAt TEXT NULL,
                PasswordHash TEXT NOT NULL,
                FullName TEXT,
                Phone TEXT,
                SmsVerifiedAt TEXT NULL,
                Role TEXT NOT NULL,
                Status TEXT NOT NULL,
                CreatedAt TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt TEXT NULL
            )'
        );
        $this->db->exec(
            'CREATE TABLE EmailVerificationTokens (
                TokenID INTEGER PRIMARY KEY AUTOINCREMENT,
                UserID INTEGER NOT NULL,
                EmailAddress TEXT NOT NULL,
                Purpose TEXT NOT NULL,
                TokenHash TEXT NOT NULL,
                ExpiresAt TEXT NOT NULL,
                ConsumedAt TEXT NULL,
                CreatedAt TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->db->exec(
            'CREATE TABLE SmsVerificationTokens (
                TokenID INTEGER PRIMARY KEY AUTOINCREMENT,
                UserID INTEGER NOT NULL,
                PhoneNumber TEXT NOT NULL,
                OtpHash TEXT NOT NULL,
                ExpiresAt TEXT NOT NULL,
                Attempts INTEGER NOT NULL DEFAULT 0,
                ConsumedAt TEXT NULL,
                CreatedAt TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->db->exec(
            'CREATE TABLE Donations (
                DonationID INTEGER PRIMARY KEY AUTOINCREMENT,
                DonorName TEXT NOT NULL,
                DonorEmail TEXT,
                DonationType TEXT DEFAULT "cash",
                Amount NUMERIC(10,2),
                Description TEXT,
                DonationDate TEXT NOT NULL,
                CreatedAt TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->db->exec(
            'CREATE TABLE DonationPayments (
                PaymentID INTEGER PRIMARY KEY AUTOINCREMENT,
                DonationID INTEGER NOT NULL,
                Provider TEXT NOT NULL DEFAULT "payfast",
                PaymentReference TEXT NOT NULL,
                MerchantReference TEXT NOT NULL,
                Amount NUMERIC(10,2) NOT NULL,
                Currency TEXT NOT NULL DEFAULT "ZAR",
                Status TEXT NOT NULL DEFAULT "pending",
                RequestData TEXT,
                ResponseData TEXT,
                VerifiedAt TEXT NULL,
                CreatedAt TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UpdatedAt TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );

        $hash = password_hash('Password99', PASSWORD_BCRYPT);
        $this->db->prepare(
            'INSERT INTO Users (UserID, Username, Email, EmailVerifiedAt, PasswordHash, FullName, Phone, Role, Status, CreatedAt)
             VALUES (1, "demo-user", "demo@example.com", NULL, :password, "Demo User", "0831234567", "donor", "active", :created_at)'
        )->execute([
            ':password' => $hash,
            ':created_at' => gmdate('Y-m-d H:i:s', time() - 60 * 60 * 24 * 45),
        ]);
    }

    public function testEmailVerificationRejectsExpiredAndReusedTokens()
    {
        $service = new EmailVerificationService($this->db);
        $token = $service->createTokenForUser(1, 'demo@example.com', 'register');

        $this->db->prepare(
            'UPDATE EmailVerificationTokens SET ExpiresAt = :expires_at WHERE UserID = 1 AND Purpose = "register"'
        )->execute([':expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]);

        $this->assertFalse($service->verifyToken(1, 'demo@example.com', $token, 'register'));

        $freshToken = $service->createTokenForUser(1, 'demo@example.com', 'register');
        $this->assertNotNull($freshToken);
        $this->assertFalse($service->verifyToken(1, 'demo@example.com', $token, 'register'));
        $this->assertTrue($service->verifyToken(1, 'demo@example.com', $freshToken, 'register'));
    }

    public function testDuplicateEmailIsRejectedAndGracePeriodIsHonored()
    {
        $user = new User($this->db);
        $this->assertTrue($user->isEmailVerificationGraceSatisfied([
            'EmailVerifiedAt' => null,
            'EmailGraceUntil' => gmdate('Y-m-d H:i:s', time() + 60 * 60 * 24 * 5),
        ]));

        $this->assertFalse($user->isEmailVerificationGraceSatisfied([
            'EmailVerifiedAt' => null,
            'EmailGraceUntil' => gmdate('Y-m-d H:i:s', time() - 60),
        ]));

        $this->db->prepare(
            'INSERT INTO Users (Username, Email, EmailVerifiedAt, PasswordHash, FullName, Phone, Role, Status, CreatedAt)
             VALUES ("other-user", "other@example.com", NULL, :password, "Other User", "0821112222", "staff", "active", :created_at)'
        )->execute([
            ':password' => password_hash('Password99', PASSWORD_BCRYPT),
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $service = new EmailVerificationService($this->db);
        $rejected = false;
        try {
            $service->createTokenForUser(1, 'other@example.com', 'register');
        } catch (DomainException $e) {
            $rejected = true;
        }
        $this->assertTrue($rejected);
    }

    public function testEmailChangeTracksPendingAddressUntilVerification()
    {
        $service = new EmailVerificationService($this->db);
        $token = $service->createTokenForUser(1, 'new-demo@example.com', 'change-email');

        $pendingStmt = $this->db->prepare('SELECT PendingEmail FROM Users WHERE UserID = 1');
        $pendingStmt->execute();
        $pending = $pendingStmt->fetchColumn();
        $this->assertSame('new-demo@example.com', $pending);

        $this->assertTrue($service->verifyToken(1, 'new-demo@example.com', $token, 'change-email'));

        $userStmt = $this->db->prepare('SELECT Email, PendingEmail FROM Users WHERE UserID = 1');
        $userStmt->execute();
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('new-demo@example.com', $user['Email']);
        $this->assertNull($user['PendingEmail']);
    }

    public function testNewRegistrationRequiresEmailAndPhoneVerification()
    {
        $user = new User($this->db);
        $userId = $user->register(
            'new-registration',
            'new-registration@example.com',
            'Password99',
            'donor',
            'New Registration',
            '+27831234567',
            'pending',
            true
        );
        $account = $user->getUserById($userId);
        $this->assertSame('0831234567', $account['Phone']);
        $this->assertSame(1, (int)$account['RegistrationVerificationRequired']);
        $this->assertSame(
            'Verify your email address and phone number before signing in.',
            $user->getLoginVerificationMessage($account)
        );

        $emailService = new EmailVerificationService($this->db);
        $emailToken = $emailService->createTokenForUser($userId, $account['Email'], 'register');
        $smsService = new SmsVerificationService($this->db, new MockSmsProvider());
        $otp = $smsService->createOtp($userId, $account['Phone']);

        $this->assertTrue($user->completeEmailVerification($userId, $account['Email'], $emailToken, 'register'));
        $this->assertTrue($user->verifySmsOtp($userId, $account['Phone'], $otp));
        $this->assertNull($user->getLoginVerificationMessage($user->getUserById($userId)));
    }

    public function testEmailChangeRemainsPendingUntilVerified()
    {
        $user = new User($this->db);
        $this->assertTrue($user->updateDetails(1, 'new-demo@example.com', 'Demo User', '0831234567'));

        $beforeVerification = $user->getUserById(1);
        $this->assertSame('demo@example.com', $beforeVerification['Email']);
        $this->assertSame('new-demo@example.com', $beforeVerification['PendingEmail']);

        $service = new EmailVerificationService($this->db);
        $token = $service->createTokenForUser(1, 'new-demo@example.com', 'change-email');
        $this->assertTrue($user->completeEmailVerification(1, 'new-demo@example.com', $token, 'change-email'));
        $this->assertSame('new-demo@example.com', $user->getUserById(1)['Email']);
    }

    public function testSouthAfricanPhoneNumbersAreNormalizedAndOtpAttemptsLimit()
    {
        $this->assertSame('0831234567', SmsVerificationService::normalizePhoneNumber('+27831234567'));
        $this->assertSame('0831234567', SmsVerificationService::normalizePhoneNumber('0831234567'));
        $this->assertSame('+27831234567', SmsVerificationService::toE164('0831234567'));
        $invalidMobileRejected = false;
        try {
            SmsVerificationService::toE164('0123456789');
        } catch (InvalidArgumentException $e) {
            $invalidMobileRejected = true;
        }
        $this->assertTrue($invalidMobileRejected);

        $service = new SmsVerificationService($this->db, new MockSmsProvider());
        $otp = $service->createOtp(1, '+27831234567');
        $this->assertSame(6, strlen($otp));

        $this->assertFalse($service->verifyOtp(1, '0831234567', '000000'));
        $this->assertFalse($service->verifyOtp(1, '0831234567', '111111'));
        $this->assertFalse($service->verifyOtp(1, '0831234567', '222222'));
        $this->assertFalse($service->verifyOtp(1, '0831234567', '333333'));
        $this->assertFalse($service->verifyOtp(1, '0831234567', '444444'));
        $this->assertFalse($service->verifyOtp(1, '0831234567', $otp));
    }

    public function testMockProvidersDoNotLogSecrets()
    {
        $logPath = tempnam(sys_get_temp_dir(), 'fsms-log');
        $originalLog = ini_get('error_log');
        ini_set('error_log', $logPath);

        try {
            $emailService = new EmailVerificationService($this->db, new MockEmailProvider());
            $emailToken = $emailService->createTokenForUser(1, 'demo@example.com', 'register');

            $smsService = new SmsVerificationService($this->db, new MockSmsProvider());
            $otp = $smsService->createOtp(1, '+27831234567');

            $logOutput = file_get_contents($logPath);
            $this->assertStringContains('simulated', strtolower((string)$logOutput));
            $this->assertFalse(strpos((string)$logOutput, $emailToken) !== false);
            $this->assertFalse(strpos((string)$logOutput, $otp) !== false);
        } finally {
            if ($originalLog !== false) {
                ini_set('error_log', $originalLog);
            }
            if (is_file($logPath)) {
                unlink($logPath);
            }
        }
    }

    public function testPayFastITNValidationRejectsTamperingAndDuplicates()
    {
        $service = new PayFastService($this->db);
        $reference = 'fsms-ref-2024';
        $service->createPendingDonation([
            'donation_id' => 0,
            'merchant_reference' => $reference,
            'amount' => 100.00,
            'currency' => 'ZAR',
            'reference' => $reference,
        ]);

        $validData = [
            'merchant_id' => '123456',
            'merchant_key' => 'abcd1234',
            'payment_status' => 'COMPLETE',
            'amount_gross' => '100.00',
            'currency' => 'ZAR',
            'm_payment_id' => $reference,
            'signature' => '',
        ];
        ksort($validData);
        $payload = '';
        foreach ($validData as $key => $value) {
            if ($key === 'signature') {
                continue;
            }
            $payload .= $key . '=' . $value . '&';
        }
        $payload = rtrim($payload, '&') . '&passphrase=secret';
        $validData['signature'] = md5($payload);

        $this->assertTrue($service->verifySignature($validData));

        $result = $service->processItn($validData);
        $this->assertSame('successful', $result['status']);
        $duplicate = $service->processItn($validData);
        $this->assertSame('duplicate', $duplicate['status']);

        $tampered = $validData;
        $tampered['amount_gross'] = '101.00';
        $this->assertSame('invalid', $service->processItn($tampered)['status']);

        $failedReference = 'fsms-ref-2024-failed';
        $service->createPendingDonation([
            'donation_id' => 0,
            'merchant_reference' => $failedReference,
            'amount' => 75.50,
            'currency' => 'ZAR',
            'reference' => $failedReference,
        ]);

        $failed = $validData;
        $failed['m_payment_id'] = $failedReference;
        $failed['merchant_reference'] = $failedReference;
        $failed['payment_status'] = 'FAILED';
        $failed['amount_gross'] = '75.50';
        ksort($failed);
        $failedPayload = '';
        foreach ($failed as $key => $value) {
            if ($key === 'signature') {
                continue;
            }
            $failedPayload .= $key . '=' . $value . '&';
        }
        $failedPayload = rtrim($failedPayload, '&') . '&passphrase=secret';
        $failed['signature'] = md5($failedPayload);
        $this->assertSame('failed', $service->processItn($failed)['status']);
    }

    public function testUnverifiedDonationDoesNotAffectSummary()
    {
        $donation = new Donation($this->db);
        $this->db->prepare(
            'INSERT INTO Donations (DonorName, DonorEmail, DonationType, Amount, Description, DonationDate)
             VALUES ("Pending Donor", "pending@example.com", "cash", 90.00, "Unverified", "2025-01-01")'
        )->execute();
        $this->db->prepare(
            'INSERT INTO DonationPayments (DonationID, PaymentReference, MerchantReference, Amount, Currency, Status)
             VALUES (1, "payfast-missing", "payfast-missing", 90.00, "ZAR", "pending")'
        )->execute();

        $summary = $donation->getDonationSummary();
        $this->assertSame('0', (string)($summary['total_donations'] ?? 0));
    }
}

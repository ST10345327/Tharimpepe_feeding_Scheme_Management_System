-- Add verification and payment tracking for email, SMS and PayFast workflows.
ALTER TABLE Users
    ADD COLUMN IF NOT EXISTS EmailVerifiedAt DATETIME NULL AFTER Email,
    ADD COLUMN IF NOT EXISTS PendingEmail VARCHAR(255) NULL AFTER EmailVerifiedAt,
    ADD COLUMN IF NOT EXISTS EmailGraceUntil DATETIME NULL AFTER PendingEmail,
    ADD COLUMN IF NOT EXISTS RegistrationVerificationRequired TINYINT(1) NOT NULL DEFAULT 0 AFTER EmailGraceUntil,
    ADD COLUMN IF NOT EXISTS RegistrationVerificationKeyHash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER RegistrationVerificationRequired,
    ADD COLUMN IF NOT EXISTS SmsVerifiedAt DATETIME NULL AFTER Phone,
    ADD COLUMN IF NOT EXISTS LastSmsOtpSentAt DATETIME NULL AFTER SmsVerifiedAt,
    ADD COLUMN IF NOT EXISTS LastEmailVerificationSentAt DATETIME NULL AFTER RegistrationVerificationKeyHash;

CREATE TABLE IF NOT EXISTS EmailVerificationTokens (
    TokenID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    UserID INT NOT NULL,
    EmailAddress VARCHAR(255) NOT NULL,
    Purpose VARCHAR(32) NOT NULL DEFAULT 'register',
    TokenHash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ExpiresAt DATETIME NOT NULL,
    ConsumedAt DATETIME NULL,
    CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_email_verification_hash (TokenHash),
    KEY idx_email_verification_user_email (UserID, EmailAddress, Purpose),
    CONSTRAINT fk_email_verification_tokens_user
        FOREIGN KEY (UserID) REFERENCES Users(UserID) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS SmsVerificationTokens (
    TokenID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    UserID INT NOT NULL,
    PhoneNumber VARCHAR(20) NOT NULL,
    OtpHash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ExpiresAt DATETIME NOT NULL,
    Attempts INT NOT NULL DEFAULT 0,
    ConsumedAt DATETIME NULL,
    CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sms_verification_user_phone (UserID, PhoneNumber, CreatedAt),
    CONSTRAINT fk_sms_verification_tokens_user
        FOREIGN KEY (UserID) REFERENCES Users(UserID) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS DonationPayments (
    PaymentID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    DonationID INT NOT NULL DEFAULT 0,
    Provider VARCHAR(32) NOT NULL DEFAULT 'payfast',
    PaymentReference VARCHAR(128) NOT NULL,
    MerchantReference VARCHAR(128) NOT NULL,
    Amount DECIMAL(10,2) NOT NULL,
    Currency CHAR(3) NOT NULL DEFAULT 'ZAR',
    Status ENUM('pending','successful','failed','cancelled','invalid') NOT NULL DEFAULT 'pending',
    RequestData JSON NULL,
    ResponseData JSON NULL,
    VerifiedAt DATETIME NULL,
    CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_donation_payment_reference (PaymentReference),
    KEY idx_donation_payment_status (Status),
    KEY idx_donation_payment_donation (DonationID)
);

-- Existing accounts get a one-time 60-day email verification grace period.
UPDATE Users
SET EmailGraceUntil = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 DAY)
WHERE EmailGraceUntil IS NULL AND RegistrationVerificationRequired = 0;

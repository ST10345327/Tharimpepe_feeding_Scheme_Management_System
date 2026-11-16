-- Additive Google account linking and single-use OIDC nonce storage.
-- Existing Users, password hashes, roles, and API tokens are not changed.
CREATE TABLE IF NOT EXISTS GoogleIdentityLinks (
    IdentityID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    UserID INT NOT NULL,
    Provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    Subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_google_identity_provider_subject (Provider, Subject),
    UNIQUE KEY uq_google_identity_provider_user (Provider, UserID),
    KEY idx_google_identity_user (UserID),
    CONSTRAINT fk_google_identity_user
        FOREIGN KEY (UserID) REFERENCES Users(UserID) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS GoogleAuthChallenges (
    NonceHash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    ExpiresAt DATETIME NOT NULL,
    ConsumedAt DATETIME NULL,
    CreatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_google_auth_challenge_expiry (ExpiresAt, ConsumedAt)
);

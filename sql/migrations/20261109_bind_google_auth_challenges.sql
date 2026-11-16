-- Bind Google link challenges to the FSMS account and, for website linking,
-- the authenticated PHP session. Existing challenges remain login challenges.
ALTER TABLE GoogleAuthChallenges
    ADD COLUMN Purpose VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'login',
    ADD COLUMN UserID INT NULL,
    ADD COLUMN SessionHash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD KEY idx_google_auth_challenge_user (UserID),
    ADD CONSTRAINT fk_google_auth_challenge_user
        FOREIGN KEY (UserID) REFERENCES Users(UserID) ON DELETE CASCADE;

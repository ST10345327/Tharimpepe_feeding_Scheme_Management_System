<?php

class GoogleIdentityService
{
    const PROVIDER = 'google';
    const CHALLENGE_TTL_SECONDS = 300;

    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function getWebClientId()
    {
        $clientId = getenv('GOOGLE_WEB_CLIENT_ID');
        return is_string($clientId) ? trim($clientId) : '';
    }

    public function createNonce($purpose = 'login', $userId = null, $sessionHash = null)
    {
        if (self::getWebClientId() === '') {
            throw new RuntimeException('Google Sign-In is not configured.');
        }
        if (!in_array($purpose, ['login', 'link'], true) ||
            ($purpose === 'login' && ($userId !== null || $sessionHash !== null)) ||
            ($purpose === 'link' && (!is_numeric($userId) || (int)$userId < 1)) ||
            ($sessionHash !== null && (!is_string($sessionHash) || !preg_match('/^[a-f0-9]{64}$/', $sessionHash)))) {
            throw new InvalidArgumentException('Invalid Google authentication challenge context.');
        }

        $now = gmdate('Y-m-d H:i:s');
        $cleanup = $this->db->prepare(
            'DELETE FROM GoogleAuthChallenges
             WHERE ExpiresAt <= :now OR ConsumedAt IS NOT NULL'
        );
        $cleanup->execute([':now' => $now]);

        $nonce = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::CHALLENGE_TTL_SECONDS);
        $stmt = $this->db->prepare(
            'INSERT INTO GoogleAuthChallenges (NonceHash, Purpose, UserID, SessionHash, ExpiresAt)
             VALUES (:nonce_hash, :purpose, :user_id, :session_hash, :expires_at)'
        );
        $stmt->execute([
            ':nonce_hash' => hash('sha256', $nonce),
            ':purpose' => $purpose,
            ':user_id' => $userId === null ? null : (int)$userId,
            ':session_hash' => $sessionHash,
            ':expires_at' => $expiresAt,
        ]);

        return $nonce;
    }

    private static function hasVerifiedEmailClaim($value)
    {
        return in_array($value, [true, 1, 'true', '1'], true);
    }

    public function verifyIdToken($idToken, $expectedNonce)
    {
        $clientId = self::getWebClientId();
        if ($clientId === '') {
            throw new RuntimeException('Google Sign-In is not configured.');
        }
        if (!is_string($idToken) || $idToken === '' || strlen($idToken) > 16384 ||
            !is_string($expectedNonce) || !preg_match('/^[a-f0-9]{64}$/', $expectedNonce)) {
            throw new InvalidArgumentException('Invalid Google credential.');
        }

        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('Google authentication dependency is not installed.');
        }
        require_once $autoload;

        if (!class_exists('Google\\Client')) {
            throw new RuntimeException('Google authentication dependency is unavailable.');
        }

        $client = new Google\Client(['client_id' => $clientId]);
        try {
            $claims = $client->verifyIdToken($idToken);
        } catch (UnexpectedValueException $e) {
            throw new InvalidArgumentException('Invalid Google credential.');
        }
        if (!is_array($claims)) {
            throw new InvalidArgumentException('Invalid Google credential.');
        }

        $issuer = $claims['iss'] ?? '';
        $audience = $claims['aud'] ?? '';
        $audiences = is_array($audience) ? $audience : [$audience];
        $authorizedParty = $claims['azp'] ?? null;
        $subject = $claims['sub'] ?? '';
        $expiresAt = $claims['exp'] ?? 0;
        $nonce = $claims['nonce'] ?? '';
        $emailVerified = $claims['email_verified'] ?? null;

        if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true) ||
            !in_array($clientId, $audiences, true) ||
            (count($audiences) > 1 && $authorizedParty !== $clientId) ||
            !is_string($subject) || $subject === '' || strlen($subject) > 255 ||
            !is_numeric($expiresAt) || (int)$expiresAt <= time() ||
            !is_string($nonce) || !hash_equals($expectedNonce, $nonce) ||
            !self::hasVerifiedEmailClaim($emailVerified)) {
            throw new InvalidArgumentException('Google email is not verified.');
        }

        return $claims;
    }

    public function consumeNonce($nonce, $purpose = 'login', $userId = null, $sessionHash = null)
    {
        if (!is_string($nonce) || !preg_match('/^[a-f0-9]{64}$/', $nonce) ||
            !in_array($purpose, ['login', 'link'], true) ||
            ($purpose === 'login' && ($userId !== null || $sessionHash !== null)) ||
            ($purpose === 'link' && (!is_numeric($userId) || (int)$userId < 1)) ||
            ($sessionHash !== null && (!is_string($sessionHash) || !preg_match('/^[a-f0-9]{64}$/', $sessionHash)))) {
            return false;
        }

        $where = 'NonceHash = :nonce_hash AND Purpose = :purpose
                  AND ConsumedAt IS NULL AND ExpiresAt > :expires_at';
        $params = [
            ':nonce_hash' => hash('sha256', $nonce),
            ':purpose' => $purpose,
            ':expires_at' => gmdate('Y-m-d H:i:s'),
            ':consumed_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($userId === null) {
            $where .= ' AND UserID IS NULL';
        } else {
            $where .= ' AND UserID = :user_id';
            $params[':user_id'] = (int)$userId;
        }
        if ($sessionHash === null) {
            $where .= ' AND SessionHash IS NULL';
        } else {
            $where .= ' AND SessionHash = :session_hash';
            $params[':session_hash'] = $sessionHash;
        }

        $stmt = $this->db->prepare(
            'UPDATE GoogleAuthChallenges
             SET ConsumedAt = :consumed_at
             WHERE ' . $where
        );
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    }

    public function findLinkedUser($subject)
    {
        $stmt = $this->db->prepare(
            'SELECT u.UserID, u.Username, u.Email, u.FullName, u.Role, u.Status
             FROM GoogleIdentityLinks l
             INNER JOIN Users u ON u.UserID = l.UserID
             WHERE l.Provider = :provider AND l.Subject = :subject
             LIMIT 1'
        );
        $stmt->execute([
            ':provider' => self::PROVIDER,
            ':subject' => $subject,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: false;
    }

    public function findOrLinkUserForSignIn(array $claims)
    {
        $subject = $claims['sub'] ?? '';
        if (!is_string($subject) || $subject === '') {
            return false;
        }

        $linkedUser = $this->findLinkedUser($subject);
        if ($linkedUser) {
            if (!self::hasVerifiedEmailClaim($claims['email_verified'] ?? null)) {
                return false;
            }
            return $linkedUser;
        }

        return false;
    }

    public function linkIdentity($userId, $subject)
    {
        if (!is_string($subject) || $subject === '' || strlen($subject) > 255) {
            throw new InvalidArgumentException('Invalid Google identity.');
        }

        $existingSubject = $this->db->prepare(
            'SELECT UserID FROM GoogleIdentityLinks
             WHERE Provider = :provider AND Subject = :subject LIMIT 1'
        );
        $existingSubject->execute([
            ':provider' => self::PROVIDER,
            ':subject' => $subject,
        ]);
        $linkedUserId = $existingSubject->fetchColumn();
        if ($linkedUserId !== false) {
            if ((int)$linkedUserId === (int)$userId) {
                return;
            }
            throw new DomainException('This Google account is already linked to another FSMS account.');
        }

        $existingUser = $this->db->prepare(
            'SELECT 1 FROM GoogleIdentityLinks
             WHERE Provider = :provider AND UserID = :user_id LIMIT 1'
        );
        $existingUser->execute([
            ':provider' => self::PROVIDER,
            ':user_id' => (int)$userId,
        ]);
        if ($existingUser->fetchColumn()) {
            throw new DomainException('This FSMS account already has a linked Google account.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO GoogleIdentityLinks (UserID, Provider, Subject)
             VALUES (:user_id, :provider, :subject)'
        );
        try {
            $stmt->execute([
                ':user_id' => (int)$userId,
                ':provider' => self::PROVIDER,
                ':subject' => $subject,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new DomainException('The Google identity or FSMS account is already linked.');
            }
            throw $e;
        }
    }
}

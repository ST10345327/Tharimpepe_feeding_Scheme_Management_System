<?php
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../app/helpers/GoogleIdentityService.php';

class GoogleIdentityServiceTest extends TestCase
{
    private $db;
    private $identityService;
    private $previousClientId;

    public function setUp()
    {
        $this->previousClientId = getenv('GOOGLE_WEB_CLIENT_ID');
        putenv('GOOGLE_WEB_CLIENT_ID=unit-test-client-id');
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE Users (
                UserID INTEGER PRIMARY KEY,
                Username TEXT NOT NULL,
                Email TEXT NOT NULL,
                FullName TEXT,
                Role TEXT NOT NULL,
                Status TEXT NOT NULL
            )'
        );
        $this->db->exec(
            'CREATE TABLE GoogleIdentityLinks (
                IdentityID INTEGER PRIMARY KEY AUTOINCREMENT,
                UserID INTEGER NOT NULL,
                Provider TEXT NOT NULL,
                Subject TEXT NOT NULL,
                UNIQUE (Provider, Subject),
                UNIQUE (Provider, UserID)
            )'
        );
        $this->db->exec(
            'CREATE TABLE GoogleAuthChallenges (
                NonceHash TEXT PRIMARY KEY,
                Purpose TEXT NOT NULL DEFAULT "login",
                UserID INTEGER,
                SessionHash TEXT,
                ExpiresAt TEXT NOT NULL,
                ConsumedAt TEXT,
                CreatedAt TEXT DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->db->exec(
            "INSERT INTO Users (UserID, Username, Email, FullName, Role, Status) VALUES
                (1, 'user-one', 'user-one@gmail.com', 'User One', 'volunteer', 'active'),
                (2, 'user-two', 'user-two@gmail.com', 'User Two', 'admin', 'active')"
        );
        $this->identityService = new GoogleIdentityService($this->db);
    }

    public function tearDown()
    {
        if ($this->previousClientId === false) {
            putenv('GOOGLE_WEB_CLIENT_ID');
        } else {
            putenv('GOOGLE_WEB_CLIENT_ID=' . $this->previousClientId);
        }
    }

    public function testFindsLinkedAccountUsingProviderSubject()
    {
        $this->identityService->linkIdentity(1, 'google-subject-1');

        $user = $this->identityService->findLinkedUser('google-subject-1');

        $this->assertSame(1, (int)$user['UserID']);
        $this->assertSame('volunteer', $user['Role']);
        $this->assertSame('active', $user['Status']);
    }

    public function testRejectsGoogleIdentityAlreadyLinkedToAnotherUser()
    {
        $this->identityService->linkIdentity(1, 'google-subject-1');
        $rejected = false;

        try {
            $this->identityService->linkIdentity(2, 'google-subject-1');
        } catch (DomainException $e) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
        $this->assertSame(1, (int)$this->identityService->findLinkedUser('google-subject-1')['UserID']);
    }

    public function testRejectsSecondGoogleIdentityForSameFsmsUser()
    {
        $this->identityService->linkIdentity(1, 'google-subject-1');
        $rejected = false;

        try {
            $this->identityService->linkIdentity(1, 'google-subject-2');
        } catch (DomainException $e) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
        $this->assertFalse($this->identityService->findLinkedUser('google-subject-2'));
    }

    public function testRelinkingSameGoogleIdentityToSameUserIsIdempotent()
    {
        $this->identityService->linkIdentity(1, 'google-subject-1');
        $this->identityService->linkIdentity(1, 'google-subject-1');

        $this->assertSame(1, (int)$this->identityService->findLinkedUser('google-subject-1')['UserID']);
    }

    public function testLinkedGoogleIdentityWithVerifiedEmailCanAuthenticate()
    {
        $this->identityService->linkIdentity(1, 'google-subject-1');

        $user = $this->identityService->findOrLinkUserForSignIn([
            'sub' => 'google-subject-1',
            'email' => 'USER-ONE@gmail.com',
            'email_verified' => true,
        ]);

        $this->assertSame(1, (int)$user['UserID']);
        $this->assertSame('volunteer', $user['Role']);
    }

    public function testGoogleSignInRequiresVerifiedEmailClaim()
    {
        $this->identityService->linkIdentity(1, 'google-subject-true');
        $this->assertSame(1, (int)$this->identityService->findOrLinkUserForSignIn([
            'sub' => 'google-subject-true',
            'email' => 'user-one@gmail.com',
            'email_verified' => true,
        ])['UserID']);

        $this->assertFalse($this->identityService->findOrLinkUserForSignIn([
            'sub' => 'google-subject-true',
            'email' => 'user-one@gmail.com',
            'email_verified' => false,
        ]));

        $this->assertFalse($this->identityService->findOrLinkUserForSignIn([
            'sub' => 'google-subject-true',
            'email' => 'user-one@gmail.com',
        ]));
    }

    public function testUnlinkedGoogleIdentityIsNeverAutoLinkedToExistingAccount()
    {
        $user = $this->identityService->findOrLinkUserForSignIn([
            'sub' => 'google-subject-1',
            'email' => 'user-one@gmail.com',
            'email_verified' => true,
        ]);

        $this->assertFalse($user);
        $this->assertFalse($this->identityService->findLinkedUser('google-subject-1'));
    }

    public function testDoesNotAutoLinkWhenVerifiedEmailDoesNotMatchAnFsmsAccount()
    {
        $user = $this->identityService->findOrLinkUserForSignIn([
            'sub' => 'google-subject-1',
            'email' => 'another-user@gmail.com',
            'email_verified' => true,
        ]);

        $this->assertFalse($user);
        $this->assertFalse($this->identityService->findLinkedUser('google-subject-1'));
    }

    public function testDoesNotReplaceExistingGoogleLinkDuringEmailAutoLink()
    {
        $this->identityService->linkIdentity(1, 'existing-google-subject');

        $user = $this->identityService->findOrLinkUserForSignIn([
            'sub' => 'another-google-subject',
            'email' => 'user-one@gmail.com',
            'email_verified' => true,
        ]);

        $this->assertFalse($user);
        $this->assertFalse($this->identityService->findLinkedUser('another-google-subject'));
    }

    public function testRejectsMalformedGoogleIdToken()
    {
        $rejected = false;

        try {
            $this->identityService->verifyIdToken('not-a-jwt', str_repeat('a', 64));
        } catch (InvalidArgumentException $e) {
            $rejected = true;
        }

        $this->assertTrue($rejected);
    }

    public function testWebClientIdIsTrimmedConsistently()
    {
        putenv('GOOGLE_WEB_CLIENT_ID=  unit-test-client-id  ');

        $this->assertSame('unit-test-client-id', GoogleIdentityService::getWebClientId());
    }

    public function testWebClientIdIsEmptyWhenMissingOrWhitespaceOnly()
    {
        putenv('GOOGLE_WEB_CLIENT_ID');
        $this->assertSame('', GoogleIdentityService::getWebClientId());

        putenv('GOOGLE_WEB_CLIENT_ID=');
        $this->assertSame('', GoogleIdentityService::getWebClientId());

        putenv('GOOGLE_WEB_CLIENT_ID=   ');
        $this->assertSame('', GoogleIdentityService::getWebClientId());
    }

    public function testDatabaseFailureIsNotMissingClientConfiguration()
    {
        $brokenDatabase = new PDO('sqlite::memory:');
        $identityService = new GoogleIdentityService($brokenDatabase);

        try {
            $identityService->createNonce();
            $this->assertTrue(false, 'Expected the missing challenge table to fail.');
        } catch (PDOException $e) {
            $this->assertSame('unit-test-client-id', GoogleIdentityService::getWebClientId());
        }
    }

    public function testLoginChallengeCannotBeConsumedForLinking()
    {
        $nonce = $this->identityService->createNonce();

        $this->assertFalse($this->identityService->consumeNonce($nonce, 'link', 1));
        $this->assertTrue($this->identityService->consumeNonce($nonce));
    }

    public function testLinkChallengeIsBoundToUserAndWebsiteSession()
    {
        $sessionHash = hash('sha256', 'session-one');
        $nonce = $this->identityService->createNonce('link', 1, $sessionHash);

        $this->assertFalse($this->identityService->consumeNonce($nonce, 'login'));
        $this->assertFalse($this->identityService->consumeNonce('link-nonce', 'link', 1, $sessionHash));
        $this->assertFalse($this->identityService->consumeNonce($nonce, 'link', 2, $sessionHash));
        $this->assertFalse($this->identityService->consumeNonce($nonce, 'link', 1, hash('sha256', 'session-two')));
        $this->assertTrue($this->identityService->consumeNonce($nonce, 'link', 1, $sessionHash));
        $this->assertFalse($this->identityService->consumeNonce($nonce, 'link', 1, $sessionHash));
    }

    public function testApiLinkChallengeIsBoundToAuthenticatedUser()
    {
        $nonce = $this->identityService->createNonce('link', 1);

        $this->assertFalse($this->identityService->consumeNonce($nonce, 'link', 2));
        $this->assertTrue($this->identityService->consumeNonce($nonce, 'link', 1));
    }

    public function testExpiredChallengeCannotBeConsumed()
    {
        $nonce = $this->identityService->createNonce();
        $stmt = $this->db->prepare(
            "UPDATE GoogleAuthChallenges SET ExpiresAt = '2000-01-01 00:00:00' WHERE NonceHash = :nonce_hash"
        );
        $stmt->execute([':nonce_hash' => hash('sha256', $nonce)]);

        $this->assertFalse($this->identityService->consumeNonce($nonce));
    }
}

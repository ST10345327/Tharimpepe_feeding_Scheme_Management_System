<?php
function handleLogin() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResponse(false, 'Method not allowed', null, 405);
    }

    $input = getJsonInput();
    validateRequired($input, ['username', 'password']);

    $db = getDBConnection();
    $userModel = new User($db);
    $user = $userModel->authenticate($input['username'], $input['password']);

    if (!$user) {
        apiJsonResponse(false, 'Invalid username or password', null, 401);
    }

    if ($user['Status'] !== 'active') {
        apiJsonResponse(false, 'Account is not active. Please contact an administrator.', null, 403);
    }

    $verificationMessage = $userModel->getLoginVerificationMessage($user);
    if ($verificationMessage !== null) {
        apiJsonResponse(false, $verificationMessage, null, 403);
    }

    $token = createApiToken($db, $user['UserID']);
    $account = $userModel->getUserById((int)$user['UserID']);

    apiJsonResponse(true, 'Login successful', [
        'user' => [
            'id' => (int)$user['UserID'],
            'username' => $user['Username'],
            'email' => $user['Email'],
            'role' => $user['Role'],
        ],
        'token' => $token['token'],
        'token_expires_at' => $token['expires_at']
    ]);
}

function handleRegister() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResponse(false, 'Method not allowed', null, 405);
    }

    $input = getJsonInput();
    validateRequired($input, ['username', 'email', 'password', 'phone']);

    $db = getDBConnection();
    $userModel = new User($db);

    try {
        $role = $input['role'] ?? 'volunteer';
        if (!in_array($role, ['donor', 'volunteer'], true)) {
            $role = 'donor';
        }
        $uid = $userModel->register(
            $input['username'],
            $input['email'],
            $input['password'],
            $role,
            $input['full_name'] ?? null,
            $input['phone'] ?? null,
            'pending',
            true
        );
        if ($uid) {
            $verificationKey = bin2hex(random_bytes(32));
            $db->prepare(
                'UPDATE Users SET RegistrationVerificationKeyHash = :key_hash
                 WHERE UserID = :user_id AND RegistrationVerificationRequired = 1'
            )->execute([
                ':key_hash' => hash('sha256', $verificationKey),
                ':user_id' => (int)$uid,
            ]);
            $volunteerModel = new Volunteer(getDBConnection());
            if ($role === 'volunteer') {
                try {
                    $parts = explode(' ', trim((string)($input['full_name'] ?? '')), 2);
                    $firstName = $parts[0] ?: $input['username'];
                    $lastName = $parts[1] ?? '';
                    $volunteerModel->createVolunteer((int)$uid, $firstName, $lastName, $input['phone'] ?? null, null, 'pending', 'unavailable');
                } catch (Exception $e) {
                    logMessage("Failed to create volunteer profile during API registration: " . $e->getMessage(), 'ERROR');
                }
            }
            $data = $userModel->getUserById((int)$uid);
            unset($data['PasswordHash']);
            $data['verification'] = sendRegistrationVerification($db, $uid, $data);
            $data['verification_key'] = $verificationKey;
            apiJsonResponse(true, 'Account created. Verify your email and phone; administrator approval is a separate step.', $data, 201);
        }
        apiJsonResponse(false, 'Failed to create account', null, 500);
    } catch (Exception $e) {
        apiJsonResponse(false, $e->getMessage(), null, 400);
    }
}

function findRegistrationVerificationUser(PDO $db, $userId, $verificationKey)
{
    if (!is_string($verificationKey) || !preg_match('/^[a-f0-9]{64}$/i', $verificationKey)) {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT UserID, Email, Phone, EmailVerifiedAt, SmsVerifiedAt
         FROM Users
         WHERE UserID = :user_id AND RegistrationVerificationRequired = 1
           AND RegistrationVerificationKeyHash = :key_hash
           AND Status IN ('pending', 'active')
         LIMIT 1"
    );
    $stmt->execute([
        ':user_id' => (int)$userId,
        ':key_hash' => hash('sha256', strtolower($verificationKey)),
    ]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function sendRegistrationVerification(PDO $db, $userId, array $user)
{
    $delivery = [];
    try {
        $service = new EmailVerificationService($db);
        $service->createTokenForUser((int)$userId, $user['Email'], 'register');
        $delivery['email'] = $service->isMockProvider() ? 'simulated' : 'queued';
    } catch (Throwable $e) {
        error_log('[FSMS Verification] Registration email delivery failed (' . get_class($e) . ')');
        $delivery['email'] = 'failed';
    }

    try {
        $service = new SmsVerificationService($db);
        $service->createOtp((int)$userId, $user['Phone']);
        $delivery['sms'] = $service->isMockProvider() ? 'simulated' : 'queued';
    } catch (Throwable $e) {
        error_log('[FSMS Verification] Registration SMS delivery failed (' . get_class($e) . ')');
        $delivery['sms'] = 'failed';
    }
    return $delivery;
}

function handleRegistrationEmailVerificationRequest()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResponse(false, 'Method not allowed', null, 405);
    }
    $input = getJsonInput();
    validateRequired($input, ['user_id', 'verification_key']);
    $db = getDBConnection();
    $user = findRegistrationVerificationUser($db, $input['user_id'], $input['verification_key']);
    if (!$user || !empty($user['EmailVerifiedAt'])) {
        apiJsonResponse(false, 'Registration verification request could not be completed.', null, 400);
    }

    try {
        $service = new EmailVerificationService($db);
        $service->createTokenForUser((int)$user['UserID'], $user['Email'], 'register');
        apiJsonResponse(true, $service->isMockProvider()
            ? 'Development mode: no real email was sent.'
            : 'Verification email queued.');
    } catch (InvalidOperationException $e) {
        apiJsonResponse(false, $e->getUserMessage(), null, 429);
    } catch (ExternalServiceException $e) {
        apiJsonResponse(false, $e->getUserMessage(), null, 503);
    } catch (Throwable $e) {
        error_log('[FSMS Verification] Registration email request failed (' . get_class($e) . ')');
        apiJsonResponse(false, 'Verification email could not be sent.', null, 503);
    }
}

function handleRegistrationEmailVerificationConfirm()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResponse(false, 'Method not allowed', null, 405);
    }
    $input = getJsonInput();
    validateRequired($input, ['user_id', 'verification_key', 'token']);
    $db = getDBConnection();
    $user = findRegistrationVerificationUser($db, $input['user_id'], $input['verification_key']);
    if (!$user || !empty($user['EmailVerifiedAt'])) {
        apiJsonResponse(false, 'Invalid or expired email verification code.', null, 400);
    }
    $model = new User($db);
    if (!$model->completeEmailVerification((int)$user['UserID'], $user['Email'], $input['token'], 'register')) {
        apiJsonResponse(false, 'Invalid or expired email verification code.', null, 400);
    }
    apiJsonResponse(true, 'Email verified successfully.');
}

function handleRegistrationSmsVerificationRequest()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResponse(false, 'Method not allowed', null, 405);
    }
    $input = getJsonInput();
    validateRequired($input, ['user_id', 'verification_key']);
    $db = getDBConnection();
    $user = findRegistrationVerificationUser($db, $input['user_id'], $input['verification_key']);
    if (!$user || !empty($user['SmsVerifiedAt']) || empty($user['Phone'])) {
        apiJsonResponse(false, 'Registration verification request could not be completed.', null, 400);
    }
    try {
        $service = new SmsVerificationService($db);
        $service->createOtp((int)$user['UserID'], $user['Phone']);
        apiJsonResponse(true, $service->isMockProvider()
            ? 'Development mode: no real SMS was sent.'
            : 'SMS verification code queued.');
    } catch (RuntimeException $e) {
        $statusCode = $e instanceof FSMSException ? $e->getStatusCode() : 429;
        $message = $e instanceof FSMSException ? $e->getUserMessage() : $e->getMessage();
        apiJsonResponse(false, $message, null, $statusCode);
    } catch (InvalidArgumentException $e) {
        apiJsonResponse(false, $e->getMessage(), null, 400);
    } catch (ExternalServiceException $e) {
        apiJsonResponse(false, $e->getUserMessage(), null, 503);
    } catch (Throwable $e) {
        error_log('[FSMS Verification] Registration SMS request failed (' . get_class($e) . ')');
        apiJsonResponse(false, 'SMS verification code could not be sent.', null, 503);
    }
}

function handleRegistrationSmsVerificationConfirm()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResponse(false, 'Method not allowed', null, 405);
    }
    $input = getJsonInput();
    validateRequired($input, ['user_id', 'verification_key', 'otp']);
    $db = getDBConnection();
    $user = findRegistrationVerificationUser($db, $input['user_id'], $input['verification_key']);
    if (!$user || !empty($user['SmsVerifiedAt']) || empty($user['Phone'])) {
        apiJsonResponse(false, 'Invalid or expired SMS verification code.', null, 400);
    }
    $model = new User($db);
    if (!$model->verifySmsOtp((int)$user['UserID'], $user['Phone'], $input['otp'])) {
        apiJsonResponse(false, 'Invalid or expired SMS verification code.', null, 400);
    }
    apiJsonResponse(true, 'Phone number verified successfully.');
}

function handleEmailVerificationRequest() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResponse(false, 'Method not allowed', null, 405);
    }

    $user = requireAuth();
    $input = getJsonInput();
    $email = trim((string)($input['email'] ?? $user['Email'] ?? ''));
    validateRequired(['email' => $email], ['email']);

    $db = getDBConnection();
    $purpose = strcasecmp(trim((string)$email), trim((string)$user['Email'])) === 0 ? 'register' : 'change-email';
    try {
        $service = new EmailVerificationService($db);
        $service->createTokenForUser((int)$user['UserID'], $email, $purpose);
        apiJsonResponse(true, $service->isMockProvider()
            ? 'Development mode: no real email was sent.'
            : 'Verification email queued.');
    } catch (DomainException $e) {
        apiJsonResponse(false, $e->getMessage(), null, 409);
    } catch (InvalidOperationException $e) {
        apiJsonResponse(false, $e->getUserMessage(), null, 429);
    } catch (ExternalServiceException $e) {
        apiJsonResponse(false, $e->getUserMessage(), null, 503);
    }
}

function handleEmailVerificationConfirm() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResponse(false, 'Method not allowed', null, 405);
    }

    $user = requireAuth();
    $input = getJsonInput();
    validateRequired($input, ['email', 'token']);

    $db = getDBConnection();
    $userModel = new User($db);
    $purpose = strcasecmp(trim((string)$input['email']), trim((string)$user['Email'])) === 0 ? 'register' : 'change-email';
    $verified = $userModel->completeEmailVerification((int)$user['UserID'], $input['email'], $input['token'], $purpose);
    if ($verified) {
        apiJsonResponse(true, 'Email verified successfully.');
    }
    apiJsonResponse(false, 'Invalid or expired email verification token.', null, 400);
}

function handleSmsVerificationRequest() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResponse(false, 'Method not allowed', null, 405);
    }

    $user = requireAuth();
    $input = getJsonInput();
    $phone = trim((string)($input['phone'] ?? $user['Phone'] ?? ''));
    validateRequired(['phone' => $phone], ['phone']);

    $db = getDBConnection();
    $service = new SmsVerificationService($db);
    try {
        $service->createOtp((int)$user['UserID'], $phone);
        apiJsonResponse(true, 'One-time password sent.');
    } catch (RuntimeException $e) {
        apiJsonResponse(false, $e->getMessage(), null, 429);
    } catch (InvalidArgumentException $e) {
        apiJsonResponse(false, $e->getMessage(), null, 400);
    } catch (ExternalServiceException $e) {
        apiJsonResponse(false, $e->getUserMessage(), null, 503);
    }
}

function handleSmsVerificationConfirm() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResponse(false, 'Method not allowed', null, 405);
    }

    $user = requireAuth();
    $input = getJsonInput();
    validateRequired($input, ['phone', 'otp']);

    $db = getDBConnection();
    $userModel = new User($db);
    $verified = $userModel->verifySmsOtp((int)$user['UserID'], $input['phone'], $input['otp']);
    if ($verified) {
        apiJsonResponse(true, 'Phone number verified.');
    }
    apiJsonResponse(false, 'Invalid or expired OTP.', null, 400);
}

function handleLogout() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiJsonResponse(false, 'Method not allowed', null, 405);
    }

    $user = requireAuth();
    $db = getDBConnection();
    revokeApiToken($db, $user['ApiTokenID']);
    apiJsonResponse(true, 'Logout successful');
}

function googleAuthPayload($user, $token = null) {
    $payload = [
        'user' => [
            'id' => (int)$user['UserID'],
            'username' => $user['Username'],
            'email' => $user['Email'],
            'role' => $user['Role'],
            'FullName' => $user['FullName'] ?? null,
            'Phone' => $user['Phone'] ?? null,
            'Status' => $user['Status'] ?? null,
            'EmailVerifiedAt' => $user['EmailVerifiedAt'] ?? null,
            'SmsVerifiedAt' => $user['SmsVerifiedAt'] ?? null,
            'PendingEmail' => $user['PendingEmail'] ?? null,
            'FullName' => $account['FullName'] ?? null,
            'Phone' => $account['Phone'] ?? null,
            'Status' => $account['Status'] ?? $user['Status'],
            'EmailVerifiedAt' => $account['EmailVerifiedAt'] ?? null,
            'SmsVerifiedAt' => $account['SmsVerifiedAt'] ?? null,
            'PendingEmail' => $account['PendingEmail'] ?? null,
            'email_verified_at' => $account['EmailVerifiedAt'] ?? null,
            'phone_verified_at' => $account['SmsVerifiedAt'] ?? null,
        ],
    ];
    if ($token !== null) {
        $payload['token'] = $token['token'];
        $payload['token_expires_at'] = $token['expires_at'];
    }
    return $payload;
}

function logGoogleAuthFailure($context, Throwable $exception) {
    error_log('[FSMS Google Auth] ' . $context . ' (' . get_class($exception) . ')');
}

function handleGoogleAuth($method, $id, $subresource = null) {
    if ($id !== 'google') {
        apiJsonResponse(false, 'Authentication endpoint not found', null, 404);
    }
    if ($method !== 'POST') {
        apiJsonResponse(false, 'Method not allowed', null, 405);
    }

    try {
        $db = getDBConnection();
    } catch (Throwable $e) {
        logGoogleAuthFailure('Database unavailable', $e);
        apiJsonResponse(false, 'Google Sign-In is temporarily unavailable.', null, 503);
    }
    $googleIdentity = new GoogleIdentityService($db);

    if ($subresource === 'challenge') {
        if (GoogleIdentityService::getWebClientId() === '') {
            apiJsonResponse(false, 'Google Sign-In is not configured.', null, 503);
        }
        try {
            $nonce = $googleIdentity->createNonce();
            apiJsonResponse(true, 'Google authentication challenge created', [
                'client_id' => GoogleIdentityService::getWebClientId(),
                'nonce' => $nonce,
            ]);
        } catch (Throwable $e) {
            logGoogleAuthFailure('Challenge creation failed', $e);
            apiJsonResponse(false, 'Google Sign-In is temporarily unavailable.', null, 503);
        }
    }

    if ($subresource === 'link-challenge') {
        $user = requireAuth();
        if (GoogleIdentityService::getWebClientId() === '') {
            apiJsonResponse(false, 'Google Sign-In is not configured.', null, 503);
        }
        try {
            $nonce = $googleIdentity->createNonce('link', (int)$user['UserID']);
            apiJsonResponse(true, 'Google account-link challenge created', [
                'client_id' => GoogleIdentityService::getWebClientId(),
                'nonce' => $nonce,
            ]);
        } catch (Throwable $e) {
            logGoogleAuthFailure('Link challenge creation failed', $e);
            apiJsonResponse(false, 'Google account linking is temporarily unavailable.', null, 503);
        }
    }

    $input = getJsonInput();
    if ($subresource === 'link') {
        $user = requireAuth();
    } elseif ($subresource === null) {
        $user = null;
    } else {
        apiJsonResponse(false, 'Authentication endpoint not found', null, 404);
    }
    validateRequired($input, ['id_token', 'nonce']);

    if (GoogleIdentityService::getWebClientId() === '') {
        apiJsonResponse(false, 'Google Sign-In is not configured.', null, 503);
    }
    try {
        $claims = $googleIdentity->verifyIdToken($input['id_token'], $input['nonce']);
    } catch (InvalidArgumentException $e) {
        apiJsonResponse(false, 'Google authentication was rejected.', null, 401);
    } catch (Throwable $e) {
        logGoogleAuthFailure('ID-token verification failed', $e);
        apiJsonResponse(false, 'Google Sign-In is temporarily unavailable.', null, 503);
    }

    try {
        $nonceConsumed = $googleIdentity->consumeNonce(
            $input['nonce'],
            $subresource === 'link' ? 'link' : 'login',
            $subresource === 'link' ? (int)$user['UserID'] : null
        );
    } catch (Throwable $e) {
        logGoogleAuthFailure('Nonce validation failed', $e);
        apiJsonResponse(false, 'Google Sign-In is temporarily unavailable.', null, 503);
    }
    if (!$nonceConsumed) {
        apiJsonResponse(false, 'Google authentication challenge expired or was already used.', null, 401);
    }

    if ($subresource === 'link') {
        try {
            $googleIdentity->linkIdentity((int)$user['UserID'], $claims['sub']);
        } catch (DomainException $e) {
            apiJsonResponse(false, $e->getMessage(), null, 409);
        } catch (Throwable $e) {
            logGoogleAuthFailure('Identity link failed', $e);
            apiJsonResponse(false, 'Unable to link this Google account.', null, 500);
        }
        apiJsonResponse(true, 'Google account linked successfully.');
    }

    try {
        $linkedUser = $googleIdentity->findOrLinkUserForSignIn($claims);
    } catch (Throwable $e) {
        logGoogleAuthFailure('Linked account lookup failed', $e);
        apiJsonResponse(false, 'Google Sign-In is temporarily unavailable.', null, 503);
    }
    if (!$linkedUser) {
        apiJsonResponse(false, 'This Google account is not linked to an FSMS account. Sign in with your existing FSMS account and link Google first.', null, 403);
    }
    if ($linkedUser['Status'] !== 'active') {
        apiJsonResponse(false, 'This FSMS account is not active. Please contact an administrator.', null, 403);
    }
    $account = (new User($db))->getUserById((int)$linkedUser['UserID']);
    $verificationMessage = $account ? (new User($db))->getLoginVerificationMessage($account) : null;
    if ($verificationMessage !== null) {
        apiJsonResponse(false, $verificationMessage, null, 403);
    }
    if ($account) {
        $linkedUser = array_merge($linkedUser, $account);
    }

    try {
        $token = createApiToken($db, $linkedUser['UserID']);
    } catch (Throwable $e) {
        logGoogleAuthFailure('FSMS token creation failed', $e);
        apiJsonResponse(false, 'Google Sign-In is temporarily unavailable.', null, 503);
    }
    apiJsonResponse(true, 'Google login successful', googleAuthPayload($linkedUser, $token));
}

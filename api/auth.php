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

    $token = createApiToken($db, $user['UserID']);

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
    validateRequired($input, ['username', 'email', 'password']);

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
            'pending'
        );
        if ($uid) {
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
            apiJsonResponse(true, 'Account created successfully. Your account is pending admin approval. You will be able to log in once an administrator approves your registration.', $data, 201);
        }
        apiJsonResponse(false, 'Failed to create account', null, 500);
    } catch (Exception $e) {
        apiJsonResponse(false, $e->getMessage(), null, 400);
    }
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
        $linkedUser = $googleIdentity->findLinkedUser($claims['sub']);
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

    try {
        $token = createApiToken($db, $linkedUser['UserID']);
    } catch (Throwable $e) {
        logGoogleAuthFailure('FSMS token creation failed', $e);
        apiJsonResponse(false, 'Google Sign-In is temporarily unavailable.', null, 503);
    }
    apiJsonResponse(true, 'Google login successful', googleAuthPayload($linkedUser, $token));
}

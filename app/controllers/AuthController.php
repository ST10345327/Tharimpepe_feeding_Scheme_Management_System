<?php
/**
 * Module: User Authentication & Session Management
 * Purpose: Handles user login, registration, and logout workflows
 * Reference: Task 2b System Design Section 4.1 - Authentication Flow
 * Author: WIL Student
 */

// Initialize application with error handling and validation
require_once __DIR__ . "/../helpers/bootstrap.php";
require_once __DIR__ . "/../models/User.php";
require_once __DIR__ . "/../models/Volunteer.php";
require_once __DIR__ . "/../models/ActivityLog.php";
require_once __DIR__ . "/../helpers/GoogleIdentityService.php";

$action = isset($_GET['action']) ? $_GET['action'] : 'login';
$error = "";
$success = "";

function establishAuthenticatedSession(array $user)
{
    session_regenerate_id(true);
    $_SESSION["user_id"] = $user["UserID"];
    $_SESSION["username"] = $user["Username"];
    $_SESSION["email"] = $user["Email"];
    $_SESSION["role"] = $user["Role"];
    $_SESSION["login_time"] = time();
    ActivityLog::log($user['UserID'], 'login', 'Users', $user['UserID'], 'User logged in');
}

// Treat a repeated login POST from an already authenticated browser as a no-op.
if ($action === 'login' && $_SERVER["REQUEST_METHOD"] === "POST" && isUserLoggedIn()) {
    header("Location: /");
    exit();
}

try {
    if ($action === 'google_link_challenge') {
        header('Content-Type: application/json');
        $respond = function ($success, $message, $data = null, $statusCode = 200) {
            http_response_code($statusCode);
            $response = ['success' => $success, 'message' => $message];
            if ($data !== null) {
                $response['data'] = $data;
            }
            echo json_encode($response);
            exit();
        };

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $respond(false, 'Method not allowed', null, 405);
        }
        if (!isUserLoggedIn()) {
            $respond(false, 'Authentication required', null, 401);
        }
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $respond(false, 'Security validation failed. Please reload the page and try again.', null, 403);
        }

        try {
            $challengeDb = getDBConnection();
            $statusStmt = $challengeDb->prepare(
                "SELECT Status FROM Users WHERE UserID = :user_id AND Status = 'active' LIMIT 1"
            );
            $statusStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
            if (!$statusStmt->fetchColumn()) {
                $respond(false, 'Only active FSMS accounts can link Google Sign-In.', null, 403);
            }

            $challengeService = new GoogleIdentityService($challengeDb);
            $nonce = $challengeService->createNonce(
                'link',
                (int)$_SESSION['user_id'],
                hash('sha256', session_id())
            );
            $respond(true, 'Google account-link challenge created', [
                'client_id' => GoogleIdentityService::getWebClientId(),
                'nonce' => $nonce,
            ]);
        } catch (Throwable $e) {
            error_log('[FSMS Google Auth] Website link challenge failed (' . get_class($e) . ')');
            $respond(false, 'Google account linking is temporarily unavailable.', null, 503);
        }
    }

    // Get database connection
    $db = getDBConnection();
    
    // Create user model
    $userModel = new User($db);
    $googleIdentity = new GoogleIdentityService($db);

    if ($action === 'google_login' && $_SERVER["REQUEST_METHOD"] !== "POST") {
        header('Location: /');
        exit();
    }

    if ($action === 'google_link' && $_SERVER["REQUEST_METHOD"] !== "POST") {
        header('Location: ' . (isUserLoggedIn() ? '/controllers/ProfileController.php?action=profile' : '/'));
        exit();
    }

    if ($action === 'google_login' && $_SERVER["REQUEST_METHOD"] === "POST") {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $error = "Security validation failed. Please try again.";
        } else {
            try {
                $nonce = $_POST['google_nonce'] ?? '';
                $claims = $googleIdentity->verifyIdToken($_POST['google_credential'] ?? '', $nonce);
                if (!$googleIdentity->consumeNonce($nonce, 'login')) {
                    throw new InvalidArgumentException('Google authentication challenge expired or was already used.');
                }

                $user = $googleIdentity->findLinkedUser($claims['sub']);
                if (!$user) {
                    $error = "This Google account is not linked to an FSMS account. Sign in with your existing FSMS account and link Google first.";
                } elseif ($user['Status'] !== 'active') {
                    $error = "This FSMS account is not active. Please contact an administrator.";
                } else {
                    establishAuthenticatedSession($user);
                    header('Location: ' . rbacDefaultDashboardPath($user['Role']));
                    exit();
                }
            } catch (InvalidArgumentException $e) {
                $error = "Google sign-in failed. Please try again.";
            } catch (Throwable $e) {
                error_log('[FSMS Google Auth] Website sign-in failed (' . get_class($e) . ')');
                $error = "Google sign-in is temporarily unavailable.";
            }
        }
        $action = 'google_login_error';
    }

    if ($action === 'google_link' && $_SERVER["REQUEST_METHOD"] === "POST") {
        if (!isUserLoggedIn()) {
            header('Location: /');
            exit();
        }
        $statusStmt = $db->prepare('SELECT Status FROM Users WHERE UserID = :user_id LIMIT 1');
        $statusStmt->execute([':user_id' => (int)$_SESSION['user_id']]);
        if ($statusStmt->fetchColumn() !== 'active') {
            $_SESSION['error'] = 'Only active FSMS accounts can link Google Sign-In.';
            header('Location: /controllers/ProfileController.php?action=profile');
            exit();
        }
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = "Security validation failed. Please try again.";
            header('Location: /controllers/ProfileController.php?action=profile');
            exit();
        }

        try {
            $nonce = $_POST['google_nonce'] ?? '';
            $claims = $googleIdentity->verifyIdToken($_POST['google_credential'] ?? '', $nonce);
            $sessionHash = hash('sha256', session_id());
            if (!$googleIdentity->consumeNonce(
                $nonce,
                'link',
                (int)$_SESSION['user_id'],
                $sessionHash
            )) {
                throw new InvalidArgumentException('Google authentication challenge expired or was already used.');
            }
            $googleIdentity->linkIdentity((int)$_SESSION['user_id'], $claims['sub']);
            $_SESSION['message'] = 'Google account linked successfully.';
        } catch (InvalidArgumentException $e) {
            $_SESSION['error'] = 'Google account linking failed. Please try again.';
        } catch (DomainException $e) {
            $_SESSION['error'] = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[FSMS Google Auth] Website account linking failed (' . get_class($e) . ')');
            $_SESSION['error'] = 'Google account linking is temporarily unavailable.';
        }
        header('Location: /controllers/ProfileController.php?action=profile');
        exit();
    }

    /**
     * HZ-AUTH-001
     * Purpose: Handle user login form submission
     * Flow: Validate credentials -> Authenticate -> Create session -> Redirect
     */
    if ($action === 'login' && $_SERVER["REQUEST_METHOD"] === "POST") {
        FormValidator::reset();
        
        // HZ-AUTH-001B: Validate CSRF token to prevent login-CSRF attacks
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!verifyCSRFToken($csrfToken)) {
            $error = "Security validation failed. Please try again.";
            $action = 'login';
        } else {
            // Get and validate input
            $username = FormValidator::getRequired('username', 'Username');
            $password = FormValidator::getOptional('password', '');
            
            // Check required fields
            if (empty($username) || empty($password)) {
                $error = "Username and password are required";
            } else {
                try {
                    // Attempt authentication
                    $user = $userModel->authenticate($username, $password);

                    if ($user) {
                        // Check if user is pending approval
                        if ($user['Status'] !== 'active') {
                            $error = $user['Status'] === 'pending'
                                ? "Your account is pending approval. Please wait for an administrator to approve your registration."
                                : "Your account is not active. Please contact an administrator.";
                        } else {
                            // HZ-AUTH-002: Create secure session with session regeneration
                            establishAuthenticatedSession($user);

                            // Redirect to index which serves dashboard for authenticated users
                            header("Location: index.php");
                            exit();
                        }
                    } else {
                        $error = "Invalid username or password";
                    }
                } catch (AuthenticationException $e) {
                    $error = $e->getUserMessage();
                } catch (\Throwable $e) {
                    $error = "Login error: " . $e->getMessage();
                    logMessage("Login error: " . $e->getMessage(), 'ERROR');
                }
            }

            // Return to login form with error message
            $action = 'login';
        }
    }

    /**
     * HZ-AUTH-003
     * Purpose: Handle user registration form submission
     * Flow: Validate input -> Create user account -> Redirect to login
     */
    if ($action === 'register' && $_SERVER["REQUEST_METHOD"] === "POST") {
        FormValidator::reset();
        
        // HZ-AUTH-003B: Validate CSRF token to prevent registration-CSRF attacks
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!verifyCSRFToken($csrfToken)) {
            $error = "Security validation failed. Please try again.";
            $action = 'register';
        } else {
            try {
                // Get and validate input
                $fullName = FormValidator::getRequired('full_name', 'Full Name');
                $username = FormValidator::getRequired('username', 'Username');
                $email = FormValidator::getRequired('email', 'Email');
                $phone = FormValidator::getOptional('phone', '');
                $role = FormValidator::getOptional('role', 'volunteer');
                $password = FormValidator::getRequired('password', 'Password');
                $password_confirm = FormValidator::getOptional('password_confirm', '');

                // Validate allowed roles
                if (!in_array($role, ['volunteer', 'donor', 'staff'])) {
                    $role = 'volunteer';
                }

                // Validate formats
                FormValidator::validateUsername($username);
                FormValidator::validateEmail($email);
                FormValidator::validatePassword($password, 'Password');
                
                // Check password match
                if ($password !== $password_confirm) {
                    $error = "Passwords do not match";
                }

                if (FormValidator::hasErrors()) {
                    throw new ValidationException("Validation failed", FormValidator::getErrors());
                }

                // Attempt registration in User model
                $userId = $userModel->register($username, $email, $password, $role, $fullName, $phone);

                if ($userId) {
                    // Create Volunteers record if role is volunteer
                    if (strtolower((string)$role) === 'volunteer') {
                        try {
                            $db = getDBConnection();
                            $volunteerModel = new Volunteer($db);
                            $parts = explode(' ', (string)$fullName, 2);
                            $firstName = $parts[0];
                            $lastName = $parts[1] ?? '';
                            $volunteerModel->createVolunteer($userId, $firstName, $lastName, $phone, null, 'pending', 'unavailable');
                        } catch (Exception $e) {
                            logMessage("Failed to create volunteer profile during registration: " . $e->getMessage(), 'ERROR');
                        }
                    }

                    // Log registration
                    ActivityLog::log($userId, 'register', 'Users', $userId, 'New user registered as ' . $role);
                    
                    $success = "Account created successfully! Your account is pending admin approval. Redirecting to login...";
                    // Redirect after 3 seconds
                    header("Refresh: 3; URL=index.php?registered=success");
                } else {
                    throw new Exception("Registration failed. Please try again.");
                }

            } catch (ValidationException $e) {
                $error = implode(", ", $e->getErrors());
                $action = 'register';
            } catch (DuplicateException $e) {
                $error = $e->getUserMessage();
                $action = 'register';
            } catch (Exception $e) {
                $error = $e->getMessage();
                logMessage("Registration error: " . $e->getMessage(), 'ERROR');
                $action = 'register';
            }
        }
    }

    /**
     * HZ-AUTH-004
     * Purpose: Handle user logout and session termination
     * Flow: Destroy session -> Redirect to login
     */
    if ($action === 'logout') {
        // Log logout activity before destroying session
        if (isset($_SESSION['user_id'])) {
            ActivityLog::log($_SESSION['user_id'], 'logout', 'Users', $_SESSION['user_id'], 'User logged out');
        }
        
        // Destroy all session data
        $_SESSION = array();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }
        session_destroy();

        // Redirect to index
        header("Location: index.php?logout=success");
        exit();
    }

} catch (DatabaseException $e) {
    $error = "Database error: " . $e->getUserMessage();
    logMessage($e->getMessage(), 'ERROR');
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
    logMessage("Auth controller error: " . $e->getMessage(), 'ERROR');
}

// Render appropriate view based on action
if ($action === 'login' || $action === 'google_login_error') {
    include __DIR__ . "/../views/login.php";
} elseif ($action === 'register') {
    include __DIR__ . "/../views/register.php";
}

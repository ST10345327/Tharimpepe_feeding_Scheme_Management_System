<?php
require_once __DIR__ . '/config.php';

$endpoint = isset($_GET['endpoint']) ? trim($_GET['endpoint'], '/') : '';

if (empty($endpoint)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        apiJsonResponse(false, 'Method not allowed', null, 405);
    }
    $catalogUser = requireAuth();
    requireApiRole($catalogUser, ['admin', 'staff', 'volunteer', 'donor']);
    apiJsonResponse(true, 'Tharimpepe FSMS API v1.0', [
        'endpoints' => [
            'POST /api/login',
            'POST /api/register',
            'POST /api/logout',
            'POST /api/auth/google/challenge',
            'POST /api/auth/google/link-challenge',
            'POST /api/auth/google',
            'POST /api/auth/google/link',
            'POST /api/auth/registration-email-request',
            'POST /api/auth/registration-email-verify',
            'POST /api/auth/registration-sms-request',
            'POST /api/auth/registration-sms-verify',
            'GET /api/beneficiaries',
            'GET /api/beneficiaries/{id}',
            'POST /api/beneficiaries',
            'PUT /api/beneficiaries/{id}',
            'DELETE /api/beneficiaries/{id}',
            'GET /api/attendance',
            'POST /api/attendance',
            'GET /api/stock',
            'POST /api/stock',
            'PUT /api/stock/{id}',
            'DELETE /api/stock/{id}',
            'GET /api/donations',
            'POST /api/donations',
            'POST /api/donations/checkout',
            'POST /api/donations/itn',
            'GET /api/volunteers',
            'POST /api/volunteers',
            'GET /api/dashboard',
            'GET /api/reports',
            'GET /api/users',
            'POST /api/users/{id}/approve',
            'POST /api/users/{id}/reject',
            'GET /api/mealsessions',
        ]
    ]);
}

$parts = explode('/', $endpoint);
$resource = $parts[0] ?? '';
$id = $parts[1] ?? null;
$subresource = $parts[2] ?? null;

$method = $_SERVER['REQUEST_METHOD'];

// Public authentication routes are the only operations that do not require an established token.
if (!in_array($resource, ['login', 'register'], true)) {
    authorizeApiOperation($resource, $method, $id, $subresource);
}

try {
    switch ($resource) {
        case 'auth':
            require __DIR__ . '/auth.php';
            if ($id === 'registration-email-request') {
                handleRegistrationEmailVerificationRequest();
            } elseif ($id === 'registration-email-verify') {
                handleRegistrationEmailVerificationConfirm();
            } elseif ($id === 'registration-sms-request') {
                handleRegistrationSmsVerificationRequest();
            } elseif ($id === 'registration-sms-verify') {
                handleRegistrationSmsVerificationConfirm();
            } elseif (in_array($subresource, ['email-request'], true)) {
                handleEmailVerificationRequest();
            } elseif (in_array($subresource, ['email-verify'], true)) {
                handleEmailVerificationConfirm();
            } elseif (in_array($subresource, ['sms-request'], true)) {
                handleSmsVerificationRequest();
            } elseif (in_array($subresource, ['sms-verify'], true)) {
                handleSmsVerificationConfirm();
            } else {
                handleGoogleAuth($method, $id, $subresource);
            }
            break;
        case 'login':
            require __DIR__ . '/auth.php';
            handleLogin();
            break;
        case 'register':
            require __DIR__ . '/auth.php';
            handleRegister();
            break;
        case 'logout':
            require __DIR__ . '/auth.php';
            handleLogout();
            break;
        case 'beneficiaries':
            require __DIR__ . '/beneficiaries.php';
            handleBeneficiaries($method, $id);
            break;
        case 'attendance':
            require __DIR__ . '/attendance.php';
            handleAttendance($method, $id, $subresource);
            break;
        case 'stock':
            require __DIR__ . '/stock.php';
            handleStock($method, $id);
            break;
        case 'donations':
            require __DIR__ . '/donations.php';
            if ($id === 'checkout') {
                handleDonationCheckout();
            } elseif ($id === 'itn') {
                handleDonationPayfastItn();
            } else {
                handleDonations($method, $id);
            }
            break;
        case 'volunteers':
            require __DIR__ . '/volunteers.php';
            handleVolunteers($method, $id);
            break;
        case 'dashboard':
            require __DIR__ . '/dashboard.php';
            handleDashboard();
            break;
        case 'reports':
            require __DIR__ . '/reports.php';
            handleReports();
            break;
        case 'users':
            require __DIR__ . '/users.php';
            handleUsers($method, $id, $subresource);
            break;
        case 'mealsessions':
            require __DIR__ . '/mealsessions.php';
            handleMealSessions($method, $id);
            break;
        default:
            apiJsonResponse(false, 'Endpoint not found: /api/' . $resource, null, 404);
    }
} catch (Exception $e) {
    apiJsonResponse(false, 'Server error: ' . $e->getMessage(), null, 500);
}

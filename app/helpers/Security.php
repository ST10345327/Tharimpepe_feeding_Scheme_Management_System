<?php
function isHttpsTransport($server = null, $trustedProxies = null)
{
    $server = is_array($server) ? $server : $_SERVER;
    $https = strtolower(trim((string)($server['HTTPS'] ?? '')));
    if (!in_array($https, ['', 'off', '0', 'false'], true)) {
        return true;
    }

    if ($trustedProxies === null) {
        $trustedProxies = getenv('FSMS_TRUSTED_PROXIES') ?: '';
    }
    if (!is_array($trustedProxies)) {
        $trustedProxies = explode(',', (string)$trustedProxies);
    }
    $trustedProxies = array_filter(array_map('trim', $trustedProxies), 'strlen');

    $remoteAddress = trim((string)($server['REMOTE_ADDR'] ?? ''));
    $forwardedProto = strtolower(trim((string)($server['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if (isLoopbackProxyAddress($remoteAddress)
        && in_array($remoteAddress, $trustedProxies, true)
        && $forwardedProto === 'https') {
        return true;
    }

    return false;
}

function isLoopbackProxyAddress($address)
{
    return $address === '127.0.0.1' || $address === '::1';
}

/** Enforce HTTPS before application pages can process credentials or session data. */
function requireHttpsTransport($jsonResponse = false)
{
    // CLI tools and unit tests do not carry network traffic.
    if (PHP_SAPI === 'cli' && empty($_SERVER['HTTP_HOST'])) {
        return;
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $isLoopback = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host) === 1;
    $remoteAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $allowHttpLocal = getenv('FSMS_ALLOW_HTTP_LOCAL') === '1';
    if (isHttpsTransport() ||
        ($allowHttpLocal && $isLoopback && isLoopbackProxyAddress($remoteAddress)) ||
        ($allowHttpLocal && preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01]))\./', $remoteAddress) === 1)) {
        return;
    }

    http_response_code(426);
    if ($jsonResponse) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'HTTPS is required for API traffic']);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'HTTPS is required to access this application.';
    }
    exit();
}
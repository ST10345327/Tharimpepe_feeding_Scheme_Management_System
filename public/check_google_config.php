<?php

header('Content-Type: text/plain');

$clientId = getenv('GOOGLE_WEB_CLIENT_ID');

if ($clientId === false || $clientId === '') {
    http_response_code(500);
    echo "GOOGLE_WEB_CLIENT_ID is NOT configured.";
    exit;
}

echo "GOOGLE_WEB_CLIENT_ID is configured.";
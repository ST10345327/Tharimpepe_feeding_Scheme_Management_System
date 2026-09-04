<?php
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$publicRoot = __DIR__ . '/public';

if (preg_match('#^/api(?:/.*)?$#', $path, $m)) {
    $_GET['endpoint'] = ltrim(substr($path, 4), '/');
    require __DIR__ . '/api/index.php';
    return true;
}

$publicFile = $publicRoot . str_replace('/', DIRECTORY_SEPARATOR, $path);
$extension = strtolower(pathinfo($publicFile, PATHINFO_EXTENSION));
$mimeTypes = [
    'css' => 'text/css; charset=UTF-8',
    'js' => 'application/javascript; charset=UTF-8',
    'json' => 'application/json; charset=UTF-8',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
    'woff' => 'font/woff',
    'woff2' => 'font/woff2',
];
if (
    $path !== '/' &&
    $extension !== 'php' &&
    file_exists($publicFile) && !is_dir($publicFile) &&
    isset($mimeTypes[$extension])
) {
    header('Content-Type: ' . $mimeTypes[$extension]);
    readfile($publicFile);
    return true;
}

require $publicRoot . '/index.php';
return true;

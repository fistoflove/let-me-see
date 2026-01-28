<?php
/**
 * Router for PHP built-in server
 * Usage: php -S localhost:8080 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove leading slash
$uri = ltrim($uri, '/');

// Route requests
if ($uri === 'status' || $uri === 'status.php') {
    require __DIR__ . '/status.php';
    return true;
}

if (preg_match('#^storage/(.+)$#', $uri)) {
    require __DIR__ . '/files.php';
    return true;
}

if ($uri === 'render' || $uri === '' || $uri === 'render.php') {
    require __DIR__ . '/index.php';
    return true;
}

// Serve static files
if ($uri && file_exists(__DIR__ . '/' . $uri)) {
    return false; // Let PHP serve it
}

// Default to index
require __DIR__ . '/index.php';
return true;

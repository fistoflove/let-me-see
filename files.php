<?php
/**
 * Let Me See - Screenshot Rendering Service
 * Static file server: /files/*
 */

require_once __DIR__ . '/vendor/autoload.php';

use LetMeSee\StorageManager;
use LetMeSee\ResponseBuilder;
use Dotenv\Dotenv;

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Configuration
$storagePath = $_ENV['STORAGE_PATH'] ?? __DIR__ . '/storage';
$filesUrlPrefix = $_ENV['FILES_URL_PREFIX'] ?? '/files';
$filesBaseUrl = $_ENV['FILES_BASE_URL'] ?? null;

// Initialize components
$storageManager = new StorageManager($storagePath, $filesUrlPrefix, $filesBaseUrl);
$responseBuilder = new ResponseBuilder();

// Get the requested file path from the URL
$requestUri = $_SERVER['REQUEST_URI'];
$requestPath = parse_url($requestUri, PHP_URL_PATH);

// Remove the /files prefix
$filesPrefix = rtrim($filesUrlPrefix, '/');
if (strpos($requestPath, $filesPrefix) === 0) {
    $requestPath = substr($requestPath, strlen($filesPrefix));
}

// Resolve the secure file path
$filePath = $storageManager->resolveSecureFilePath($requestPath);

if ($filePath === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => [
            'message' => 'File not found',
            'code' => 404
        ]
    ]);
    exit;
}

// Determine content type
$mimeType = mime_content_type($filePath);
if ($mimeType === false) {
    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
    $mimeType = match($extension) {
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'html' => 'text/html',
        default => 'application/octet-stream'
    };
}

// Set headers and serve file
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: public, max-age=86400'); // Cache for 24 hours

// Send CORS headers if needed
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
}

readfile($filePath);
exit;

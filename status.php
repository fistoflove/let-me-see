<?php
/**
 * Let Me See - Screenshot Rendering Service
 * Status endpoint: /status
 */

require_once __DIR__ . '/vendor/autoload.php';

use LetMeSee\ChromeConnection;
use LetMeSee\ResponseBuilder;

$responseBuilder = new ResponseBuilder();

// Send CORS headers
$responseBuilder->sendCorsHeaders();

// Get Chrome status
$chromeStatus = ChromeConnection::getStatus();

// Build status response
$status = [
    'success' => true,
    'service' => 'Let Me See Screenshot Service',
    'version' => '1.0.0',
    'timestamp' => date('c'),
    'chrome' => $chromeStatus
];

$responseBuilder->send($status, 200);

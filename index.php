<?php
/**
 * Let Me See - Screenshot Rendering Service
 * Main endpoint: /render
 */

require_once __DIR__ . '/vendor/autoload.php';

use LetMeSee\RequestHandler;
use LetMeSee\HtmlComposer;
use LetMeSee\Renderer;
use LetMeSee\StorageManager;
use LetMeSee\ResponseBuilder;
use Dotenv\Dotenv;

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Configuration
$config = [
    'bearer_token' => $_ENV['BEARER_TOKEN'] ?? null,
    'chrome_path' => $_ENV['CHROME_PATH'] ?? '/usr/bin/google-chrome',
    'storage_path' => $_ENV['STORAGE_PATH'] ?? __DIR__ . '/storage',
    'files_url_prefix' => $_ENV['FILES_URL_PREFIX'] ?? '/files',
    'files_base_url' => $_ENV['FILES_BASE_URL'] ?? null,
    'max_html_size' => (int)($_ENV['MAX_HTML_SIZE'] ?? 1048576),
    'max_resolutions' => (int)($_ENV['MAX_RESOLUTIONS'] ?? 10),
    'render_timeout' => (int)($_ENV['RENDER_TIMEOUT'] ?? 5000),
    'chrome_max_idle_time' => (int)($_ENV['CHROME_MAX_IDLE_TIME'] ?? 300),
];

// Initialize components
$responseBuilder = new ResponseBuilder();
$requestHandler = new RequestHandler($config['max_html_size'], $config['max_resolutions']);
$storageManager = new StorageManager($config['storage_path'], $config['files_url_prefix'], $config['files_base_url']);
$htmlComposer = new HtmlComposer();
$renderer = new Renderer($config['chrome_path'], $config['render_timeout'], $config['chrome_max_idle_time']);

// Handle CORS
$responseBuilder->sendCorsHeaders();

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $responseBuilder->send(
        $responseBuilder->buildError('Method not allowed. Use POST.', 405),
        405
    );
}

try {
    // Authenticate
    if (!$requestHandler->authenticate($config['bearer_token'])) {
        $responseBuilder->send(
            $responseBuilder->buildError('Unauthorized', 401),
            401
        );
    }

    // Parse and validate request
    $data = $requestHandler->parseAndValidate();

    // Generate job ID
    $jobId = $storageManager->generateJobId();
    $jobDir = $storageManager->createJobDirectory($jobId);

    // Compose HTML
    $fullHtml = $htmlComposer->compose(
        $data['html'],
        $data['css'] ?? null,
        true // Strip scripts for security
    );

    // Save HTML to file
    $htmlPath = $htmlComposer->saveToFile($fullHtml, $jobId, $config['storage_path']);

    // Render screenshots
    $screenshots = $renderer->renderScreenshots($htmlPath, $data['resolutions'], $jobDir);

    // Build response with URLs
    $screenshotsWithUrls = [];
    $includeBase64 = isset($data['returnBase64']) && $data['returnBase64'] === true;

    foreach ($screenshots as $screenshot) {
        $item = [
            'label' => $screenshot['label'],
            'width' => $screenshot['width'],
            'height' => $screenshot['height'],
            'url' => $storageManager->getPublicUrl($screenshot['file'], true) // Include full URL with host
        ];

        if ($includeBase64) {
            $item['base64'] = $renderer->toBase64($screenshot['file']);
        }

        $screenshotsWithUrls[] = $item;
    }

    // Send success response
    $responseBuilder->send(
        $responseBuilder->buildSuccess($jobId, $screenshotsWithUrls, $includeBase64),
        200
    );

} catch (\Exception $e) {
    $code = $e->getCode() ?: 500;
    $httpCode = in_array($code, [400, 401, 403, 404, 413, 500]) ? $code : 500;
    
    $responseBuilder->send(
        $responseBuilder->buildError($e->getMessage(), $code),
        $httpCode
    );
}

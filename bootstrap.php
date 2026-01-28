<?php
/**
 * Let Me See - Slim Application bootstrap
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use LetMeSee\RequestHandler;
use LetMeSee\HtmlComposer;
use LetMeSee\FastRenderer;
use LetMeSee\StorageManager;
use LetMeSee\ResponseBuilder;
use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

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
    'files_url_prefix' => $_ENV['FILES_URL_PREFIX'] ?? '/storage',
    'files_base_url' => $_ENV['FILES_BASE_URL'] ?? null,
    'max_html_size' => (int)($_ENV['MAX_HTML_SIZE'] ?? 1048576),
    'max_resolutions' => (int)($_ENV['MAX_RESOLUTIONS'] ?? 10),
    'render_timeout' => (int)($_ENV['RENDER_TIMEOUT'] ?? 5000),
    'chrome_max_idle_time' => (int)($_ENV['CHROME_MAX_IDLE_TIME'] ?? 300),
];

$buildScreenshotPayload = static function (array $screenshots, StorageManager $storageManager, FastRenderer $renderer, bool $includeBase64): array {
    $formatted = [];

    foreach ($screenshots as $screenshot) {
        // Construct the viewport object
        $viewport = [
            'id' => $screenshot['label'],
            'width' => (int)$screenshot['width'],
            'height' => (int)$screenshot['height']
        ];

        // Base item structure
        $item = [
            'viewport' => $viewport,
            'url' => $storageManager->getPublicUrl($screenshot['file'], true), // Accessible URL
            'html' => $screenshot['html'] ?? '',
        ];

        // Add layout metrics if available
        if (!empty($screenshot['layout_metrics'])) {
            $item['layout_metrics'] = $screenshot['layout_metrics'];
        }

        // Add Base64 if requested
        if ($includeBase64) {
            $item['screenshot_base64'] = $renderer->toBase64($screenshot['file']);
        }

        $formatted[] = $item;
    }

    return $formatted;
};

$ensureAuthenticated = static function (Request $request, Response $response, ResponseBuilder $responseBuilder, ?string $expectedToken): ?Response {
    if ($expectedToken === null || $expectedToken === '') {
        return null;
    }

    $authHeader = $request->getHeaderLine('Authorization');
    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        $data = $responseBuilder->buildError('Unauthorized', 401);
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json');
    }

    if (!hash_equals($expectedToken, $matches[1])) {
        $data = $responseBuilder->buildError('Unauthorized', 401);
        $response->getBody()->write(json_encode($data));
        return $response
            ->withStatus(401)
            ->withHeader('Content-Type', 'application/json');
    }

    return null;
};

// Create Slim app
$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

// Add CORS middleware
$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});

// OPTIONS preflight
$app->options('/{routes:.+}', function (Request $request, Response $response) {
    return $response;
});

// Status endpoint
$app->get('/status', function (Request $request, Response $response) {
    $chromeStatus = FastRenderer::getStatus();

    $data = [
        'success' => true,
        'service' => 'Let Me See Screenshot Service',
        'version' => '2.0.0',
        'timestamp' => date('c'),
        'chrome' => $chromeStatus
    ];

    $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// Render endpoint
$app->post('/render', function (Request $request, Response $response) use ($config, $buildScreenshotPayload, $ensureAuthenticated) {
    $responseBuilder = new ResponseBuilder();

    try {
        // Get request body
        $body = (string)$request->getBody();

        // Initialize components
        $requestHandler = new RequestHandler($config['max_html_size'], $config['max_resolutions']);
        $storageManager = new StorageManager($config['storage_path'], $config['files_url_prefix'], $config['files_base_url']);
        $renderer = new FastRenderer($config['chrome_path'], $config['render_timeout'], $config['chrome_max_idle_time']);
        $htmlComposer = new HtmlComposer();

        // Authenticate (if required)
        if (($authFailure = $ensureAuthenticated($request, $response, $responseBuilder, $config['bearer_token'])) !== null) {
            return $authFailure;
        }

        // Parse and validate payload
        $payload = $requestHandler->parseAndValidateHtml($body);

        // Prepare storage
        $jobId = $storageManager->generateJobId();
        $jobDir = $storageManager->createJobDirectory($jobId);

        // Save HTML to file
        $fullHtml = $htmlComposer->compose(
            $payload['html'],
            $payload['css'],
            true
        );
        $htmlPath = $htmlComposer->saveToFile($fullHtml, $jobId, $config['storage_path']);

        // Render screenshots
        $screenshots = $renderer->renderScreenshots(
            $htmlPath,
            $payload['resolutions'],
            $jobDir,
            $payload['timeoutMs'],
            $payload['delayAfterLoadMs'],
            $payload['scrollSteps'],
            $payload['scrollIntervalMs'],
            $payload['optimizeForLlm']
        );

        // Build response with URLs
        $screenshotsWithUrls = $buildScreenshotPayload($screenshots, $storageManager, $renderer, $payload['returnBase64']);

        // Send success response
        $result = $responseBuilder->buildSuccess($jobId, $screenshotsWithUrls, $payload['returnBase64']);
        $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Exception $e) {
        $code = $e->getCode() ?: 500;
        $httpCode = in_array($code, [400, 401, 403, 404, 413, 500]) ? $code : 500;

        $data = $responseBuilder->buildError($e->getMessage(), $code);
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($httpCode)->withHeader('Content-Type', 'application/json');
    }
});

// Render by URL endpoint
$app->post('/render-url', function (Request $request, Response $response) use ($config, $buildScreenshotPayload, $ensureAuthenticated) {
    $responseBuilder = new ResponseBuilder();

    try {
        $body = (string)$request->getBody();

        $requestHandler = new RequestHandler($config['max_html_size'], $config['max_resolutions']);
        $storageManager = new StorageManager($config['storage_path'], $config['files_url_prefix'], $config['files_base_url']);
        $renderer = new FastRenderer($config['chrome_path'], $config['render_timeout'], $config['chrome_max_idle_time']);

        if (($authFailure = $ensureAuthenticated($request, $response, $responseBuilder, $config['bearer_token'])) !== null) {
            return $authFailure;
        }

        $payload = $requestHandler->parseAndValidateUrl($body);

        $jobId = $storageManager->generateJobId();
        $jobDir = $storageManager->createJobDirectory($jobId);

        $screenshots = $renderer->renderScreenshotsFromUrl(
            $payload['url'],
            $payload['resolutions'],
            $jobDir,
            $payload['timeoutMs'],
            $payload['delayAfterLoadMs'],
            $payload['scrollSteps'],
            $payload['scrollIntervalMs'],
            $payload['optimizeForLlm']
        );

        $screenshotsWithUrls = $buildScreenshotPayload($screenshots, $storageManager, $renderer, $payload['returnBase64']);

        $result = $responseBuilder->buildSuccess($jobId, $screenshotsWithUrls, $payload['returnBase64']);
        $response->getBody()->write(json_encode($result, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');

    } catch (\Exception $e) {
        $code = $e->getCode() ?: 500;
        $httpCode = in_array($code, [400, 401, 403, 404, 413, 500]) ? $code : 500;

        $data = $responseBuilder->buildError($e->getMessage(), $code);
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($httpCode)->withHeader('Content-Type', 'application/json');
    }
});

// File serving endpoint
$app->get('/storage/{path:.+}', function (Request $request, Response $response, array $args) use ($config) {
    $storageManager = new StorageManager($config['storage_path'], $config['files_url_prefix'], $config['files_base_url']);
    $filePath = $storageManager->resolveSecureFilePath($args['path']);

    if ($filePath === null) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'error' => ['message' => 'File not found', 'code' => 404]
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    // Determine content type
    $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

    $response->getBody()->write(file_get_contents($filePath));
    return $response
        ->withHeader('Content-Type', $mimeType)
        ->withHeader('Content-Length', (string)filesize($filePath))
        ->withHeader('Cache-Control', 'public, max-age=86400');
});

// Default route
$app->get('/', function (Request $request, Response $response) {
    $html = file_get_contents(__DIR__ . '/test.html');
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
});

return $app;

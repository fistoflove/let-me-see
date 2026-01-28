<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use OpenSwoole\Http\Request as SwooleRequest;
use OpenSwoole\Http\Response as SwooleResponse;
use OpenSwoole\Http\Server;
use Psr\Http\Message\ResponseInterface;

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap.php';

$psr17Factory = new Psr17Factory();
$serverRequestCreator = new ServerRequestCreator(
    $psr17Factory,
    $psr17Factory,
    $psr17Factory,
    $psr17Factory
);

$listenPort = (int)($_ENV['SWOOLE_LISTEN_PORT'] ?? getenv('SWOOLE_LISTEN_PORT') ?: 9501);
$documentRoot = $_ENV['SWOOLE_DOCUMENT_ROOT'] ?? realpath(__DIR__);
$staticLocations = ['/storage'];

$server = new Server('0.0.0.0', $listenPort);
$server->set([
    'worker_num' => (int)($_ENV['SWOOLE_WORKER_NUM'] ?? 1),
    'max_request' => 0,
    'http_compression' => false,
    'enable_static_handler' => true,
    'document_root' => $documentRoot,
    'static_handler_locations' => $staticLocations,
]);

$server->on('start', static function (Server $server): void {
    echo sprintf(
        "[OpenSwoole] HTTP server listening on http://127.0.0.1:%d%s",
    $server->port,
        PHP_EOL
    );
});

$server->on('WorkerStart', static function (Server $server, int $workerId): void {
    $total = $server->setting['worker_num'] ?? 0;
    echo sprintf('[OpenSwoole] Worker %d started (total=%d)%s', $workerId, $total, PHP_EOL);
});

$server->on('request', static function (SwooleRequest $request, SwooleResponse $response) use ($app, $serverRequestCreator, $psr17Factory, $server): void {
    if (isset($server->worker_id)) {
        echo sprintf('[OpenSwoole] Worker %d handling %s%s', $server->worker_id, $request->server['request_uri'] ?? '/', PHP_EOL);
    }

    try {
        $headers = normaliseHeaderKeys($request->header ?? []);
        $serverParams = normaliseServerParams($request->server ?? [], $headers);
        $parsedBody = decodeRequestBody($request);
        $uploadedFiles = normaliseUploadedFiles($psr17Factory, $request->files ?? []);

        populateSuperGlobals($serverParams, $headers, $request, $parsedBody);

        $psrRequest = $serverRequestCreator->fromArrays(
            $serverParams,
            $headers,
            $request->cookie ?? [],
            $request->get ?? [],
            $parsedBody,
            $uploadedFiles,
            $request->rawContent() ?: null
        );

        $psrResponse = $app->handle($psrRequest);
        sendPsrResponse($psrResponse, $response);
    } catch (\Throwable $throwable) {
        $response->status(500);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'success' => false,
            'error' => [
                'message' => $throwable->getMessage(),
            ],
        ], JSON_PRETTY_PRINT));
    }
});

$server->start();

/**
 * Convert PSR response back to Swoole response.
 */
function sendPsrResponse(ResponseInterface $psrResponse, SwooleResponse $swooleResponse): void
{
    $swooleResponse->status($psrResponse->getStatusCode());

    foreach ($psrResponse->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            $swooleResponse->header($name, $value, false);
        }
    }

    $body = $psrResponse->getBody();
    if ($body->isSeekable()) {
        $body->rewind();
    }

    $swooleResponse->end($body->getContents());
}

/**
 * Ensure server params look like the traditional $_SERVER superglobal.
 */
function normaliseServerParams(array $server, array $headers): array
{
    $normalised = [];

    foreach ($server as $key => $value) {
        $normalised[strtoupper($key)] = $value;
    }

    if (isset($headers['host'])) {
        $normalised['HTTP_HOST'] = $headers['host'];

        if (!isset($normalised['SERVER_NAME'])) {
            $normalised['SERVER_NAME'] = strtok($headers['host'], ':');
        }

        if (!isset($normalised['SERVER_PORT'])) {
            $parsedPort = parse_url('http://' . $headers['host'], PHP_URL_PORT);
            if ($parsedPort !== null) {
                $normalised['SERVER_PORT'] = $parsedPort;
            }
        }
    }

    if (isset($headers['x-forwarded-proto'])) {
        $normalised['REQUEST_SCHEME'] = $headers['x-forwarded-proto'];
        $normalised['HTTPS'] = $headers['x-forwarded-proto'] === 'https' ? 'on' : 'off';
    }

    if (!isset($normalised['REQUEST_SCHEME'])) {
        $normalised['REQUEST_SCHEME'] = (!empty($normalised['HTTPS']) && strtolower((string)$normalised['HTTPS']) !== 'off')
            ? 'https'
            : 'http';
    }

    if (!isset($normalised['HTTPS'])) {
        $normalised['HTTPS'] = $normalised['REQUEST_SCHEME'] === 'https' ? 'on' : 'off';
    }

    if (!isset($normalised['SERVER_PROTOCOL'])) {
        $normalised['SERVER_PROTOCOL'] = 'HTTP/1.1';
    }

    if (!isset($normalised['SERVER_PORT'])) {
        $normalised['SERVER_PORT'] = $normalised['REQUEST_SCHEME'] === 'https' ? 443 : 80;
    }

    if (!isset($normalised['REQUEST_TIME'])) {
        $normalised['REQUEST_TIME'] = time();
    }

    if (!isset($normalised['REQUEST_TIME_FLOAT'])) {
        $normalised['REQUEST_TIME_FLOAT'] = microtime(true);
    }

    return $normalised;
}

/**
 * Populate the traditional PHP superglobals for compatibility layers.
 */
function populateSuperGlobals(array $server, array $headers, SwooleRequest $request, array $parsedBody): void
{
    static $initialServerSnapshot = null;

    if ($initialServerSnapshot === null) {
        $initialServerSnapshot = $_SERVER;
    }

    $_SERVER = array_merge($initialServerSnapshot, $server);

    foreach ($headers as $name => $value) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $_SERVER[$key] = $value;
    }

    $_GET = $request->get ?? [];
    $_POST = str_contains($headers['content-type'] ?? '', 'application/json') ? [] : ($request->post ?? []);
    $_COOKIE = $request->cookie ?? [];
    $_FILES = $request->files ?? [];
}

/**
 * Normalise header array keys to match PSR expectations.
 */
function normaliseHeaderKeys(array $headers): array
{
    $normalised = [];

    foreach ($headers as $key => $value) {
        $normalised[strtolower($key)] = $value;
    }

    return $normalised;
}

/**
 * Convert uploaded file array into PSR compatible structure.
 */
function normaliseUploadedFiles(Psr17Factory $factory, array $files): array
{
    $normalised = [];

    foreach ($files as $key => $file) {
        if (is_array($file) && isset($file['tmp_name'])) {
            $normalised[$key] = $factory->createUploadedFile(
                $factory->createStreamFromFile($file['tmp_name']),
                (int)($file['size'] ?? 0),
                (int)($file['error'] ?? UPLOAD_ERR_OK),
                $file['name'] ?? null,
                $file['type'] ?? null
            );
        } elseif (is_array($file)) {
            $normalised[$key] = normaliseUploadedFiles($factory, $file);
        }
    }

    return $normalised;
}

/**
 * Decode JSON payloads for POST requests while leaving raw body accessible.
 */
function decodeRequestBody(SwooleRequest $request): array
{
    $contentType = $request->header['content-type'] ?? '';

    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode($request->rawContent() ?: '', true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? $decoded
            : [];
    }

    return $request->post ?? [];
}

<?php

use LetMeSee\Phapi\Services\Config;
use LetMeSee\Phapi\Services\ResponseFactory;
use PHAPI\PHAPI;

require __DIR__ . '/../vendor/autoload.php';

$rootAutoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($rootAutoload)) {
    require $rootAutoload;
}

spl_autoload_register(function (string $class): void {
    $prefix = 'LetMeSee\\Phapi\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

spl_autoload_register(function (string $class): void {
    $prefix = 'LetMeSee\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../../src/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

$baseDir = dirname(__DIR__);
$rootDir = dirname($baseDir);

$configService = new Config($rootDir);
$appConfig = require $baseDir . '/config/app.php';
$appConfig['max_body_bytes'] = $configService->maxBodyBytes();

$api = new PHAPI($appConfig);
$api->container()->singleton(Config::class, $configService);
$api->container()->singleton(ResponseFactory::class, fn () => new ResponseFactory());

$api->loadApp($baseDir);
$api->run();

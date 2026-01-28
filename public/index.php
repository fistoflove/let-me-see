<?php

require __DIR__ . '/../vendor/autoload.php';

use LetMeSee\Phapi\Services\Config;
use LetMeSee\Phapi\Services\ResponseFactory;
use PHAPI\PHAPI;

$baseDir = dirname(__DIR__);
$rootDir = $baseDir;

$configService = new Config($rootDir);
$appConfig = require $baseDir . '/config/app.php';
$appConfig['max_body_bytes'] = $configService->maxBodyBytes();

$api = new PHAPI($appConfig);
$api->container()->singleton(Config::class, $configService);
$api->container()->singleton(ResponseFactory::class, fn () => new ResponseFactory());

$api->loadApp($baseDir);
$api->run();

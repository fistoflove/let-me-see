<?php

use LetMeSee\Phapi\Controllers\HomeController;
use LetMeSee\Phapi\Controllers\RenderController;
use LetMeSee\Phapi\Controllers\StatusController;
use LetMeSee\Phapi\Controllers\StorageController;
use PHAPI\HTTP\Response;

$api->options('/{path?}', function (): Response {
    return Response::empty(204);
});

$api->options('/{path}/{child}/{grandchild?}', function (): Response {
    return Response::empty(204);
});

$api->get('/status', [StatusController::class, '__invoke']);
$api->post('/render', [RenderController::class, 'render'])->middleware('auth');
$api->post('/render-url', [RenderController::class, 'renderUrl'])->middleware('auth');
$api->get('/storage/{jobId}/{file}', [StorageController::class, 'show']);
$api->get('/', [HomeController::class, '__invoke']);

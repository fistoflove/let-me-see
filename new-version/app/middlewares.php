<?php

use LetMeSee\Phapi\Middleware\AuthMiddleware;
use LetMeSee\Phapi\Middleware\CorsMiddleware;

$api->middleware(CorsMiddleware::class);
$api->addMiddleware('auth', $api->classMiddleware(AuthMiddleware::class));

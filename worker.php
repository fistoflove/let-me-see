<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use Spiral\RoadRunner\WorkerFactory;
use Spiral\RoadRunner\Http\PSR7Worker;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

require __DIR__ . '/vendor/autoload.php';

$psr17Factory = new Psr17Factory();
$app = require __DIR__ . '/bootstrap.php';

$worker = WorkerFactory::create()->createWorker();
$psrWorker = new PSR7Worker($worker, $psr17Factory, $psr17Factory, $psr17Factory);

while ($request = $psrWorker->waitRequest()) {
    try {
        $response = $app->handle($request);
        $psrWorker->respond($response);
    } catch (\Throwable $e) {
        $psrWorker->respond(new SymfonyResponse(
            json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]),
            500,
            ['Content-Type' => 'application/json']
        ));
    }
}

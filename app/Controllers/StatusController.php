<?php

namespace LetMeSee\Phapi\Controllers;

use LetMeSee\FastRenderer;
use LetMeSee\Phapi\Services\ResponseFactory;
use PHAPI\HTTP\Response;

final class StatusController
{
    private ResponseFactory $responseFactory;

    public function __construct(ResponseFactory $responseFactory)
    {
        $this->responseFactory = $responseFactory;
    }

    public function __invoke(): Response
    {
        $chromeStatus = FastRenderer::getStatus();

        $data = [
            'success' => true,
            'service' => 'Let Me See Screenshot Service',
            'version' => '2.0.0',
            'timestamp' => date('c'),
            'chrome' => $chromeStatus,
        ];

        return $this->responseFactory->json($data, 200, true);
    }
}

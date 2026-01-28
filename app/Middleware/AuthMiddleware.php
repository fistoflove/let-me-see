<?php

namespace LetMeSee\Phapi\Middleware;

use LetMeSee\ResponseBuilder;
use LetMeSee\Phapi\Services\Config;
use LetMeSee\Phapi\Services\ResponseFactory;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;

final class AuthMiddleware
{
    private Config $config;
    private ResponseFactory $responseFactory;

    public function __construct(Config $config, ResponseFactory $responseFactory)
    {
        $this->config = $config;
        $this->responseFactory = $responseFactory;
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $expectedToken = $this->config->serviceConfig()['bearer_token'] ?? null;
        if ($expectedToken === null || $expectedToken === '') {
            return $next($request);
        }

        $authHeader = (string)$request->header('authorization', '');
        if (!preg_match('/^Bearer\\s+(.+)$/i', $authHeader, $matches)) {
            return $this->unauthorized();
        }

        if (!hash_equals($expectedToken, $matches[1])) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    private function unauthorized(): Response
    {
        $builder = new ResponseBuilder();
        $data = $builder->buildError('Unauthorized', 401);
        return $this->responseFactory->json($data, 401);
    }
}

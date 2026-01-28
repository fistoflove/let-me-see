<?php

namespace LetMeSee\Phapi\Middleware;

use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;

final class CorsMiddleware
{
    public function __invoke(Request $request, callable $next): Response
    {
        if ($request->method() === 'OPTIONS') {
            $response = Response::empty(204);
        } else {
            $response = $next($request);
        }

        return $this->withCorsHeaders($response);
    }

    private function withCorsHeaders(Response $response): Response
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
    }
}

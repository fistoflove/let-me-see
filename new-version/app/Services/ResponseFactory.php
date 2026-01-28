<?php

namespace LetMeSee\Phapi\Services;

use PHAPI\HTTP\Response;

final class ResponseFactory
{
    /**
     * @param mixed $data
     */
    public function json($data, int $status = 200, bool $pretty = false): Response
    {
        $options = $pretty ? JSON_PRETTY_PRINT : 0;
        $encoded = json_encode($data, $options);
        if ($encoded === false) {
            $encoded = '';
        }

        return Response::text($encoded, $status)
            ->withHeader('Content-Type', 'application/json');
    }
}

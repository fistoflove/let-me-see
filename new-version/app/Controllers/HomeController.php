<?php

namespace LetMeSee\Phapi\Controllers;

use LetMeSee\Phapi\Services\Config;
use PHAPI\HTTP\Response;

final class HomeController
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function __invoke(): Response
    {
        $html = file_get_contents($this->config->rootPath() . '/test.html');
        if ($html === false) {
            $html = '';
        }

        return Response::html($html);
    }
}

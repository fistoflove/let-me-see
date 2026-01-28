<?php

return [
    'runtime' => getenv('APP_RUNTIME') ?: 'swoole',
    'host' => getenv('APP_HOST') ?: '0.0.0.0',
    'port' => (int)(getenv('APP_PORT') ?: 9501),
    'debug' => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'max_body_bytes' => (int)(getenv('MAX_BODY_BYTES') ?: 1048576),
];

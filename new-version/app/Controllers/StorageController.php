<?php

namespace LetMeSee\Phapi\Controllers;

use LetMeSee\StorageManager;
use LetMeSee\Phapi\Services\Config;
use LetMeSee\Phapi\Services\ResponseFactory;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;

final class StorageController
{
    private Config $config;
    private ResponseFactory $responseFactory;

    public function __construct(Config $config, ResponseFactory $responseFactory)
    {
        $this->config = $config;
        $this->responseFactory = $responseFactory;
    }

    public function show(Request $request): Response
    {
        $config = $this->config->serviceConfig();
        $storageManager = new StorageManager($config['storage_path'], $config['files_url_prefix'], $config['files_base_url']);

        $jobId = (string)$request->param('jobId', '');
        $file = (string)$request->param('file', '');
        $requestedPath = ltrim($jobId . '/' . $file, '/');

        $filePath = $storageManager->resolveSecureFilePath($requestedPath);

        if ($filePath === null) {
            $data = [
                'success' => false,
                'error' => ['message' => 'File not found', 'code' => 404],
            ];
            return $this->responseFactory->json($data, 404);
        }

        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            $data = [
                'success' => false,
                'error' => ['message' => 'File not found', 'code' => 404],
            ];
            return $this->responseFactory->json($data, 404);
        }

        return Response::text($contents, 200)
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Length', (string)filesize($filePath))
            ->withHeader('Cache-Control', 'public, max-age=86400');
    }
}

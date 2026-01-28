<?php

namespace LetMeSee\Phapi\Controllers;

use LetMeSee\FastRenderer;
use LetMeSee\HtmlComposer;
use LetMeSee\RequestHandler;
use LetMeSee\ResponseBuilder;
use LetMeSee\StorageManager;
use LetMeSee\Phapi\Services\Config;
use LetMeSee\Phapi\Services\ResponseFactory;
use PHAPI\HTTP\Request;
use PHAPI\HTTP\Response;

final class RenderController
{
    private Config $config;
    private ResponseFactory $responseFactory;

    public function __construct(Config $config, ResponseFactory $responseFactory)
    {
        $this->config = $config;
        $this->responseFactory = $responseFactory;
    }

    public function render(Request $request): Response
    {
        $responseBuilder = new ResponseBuilder();
        $config = $this->config->serviceConfig();

        try {
            $body = $this->extractRawBody($request);

            $requestHandler = new RequestHandler($config['max_html_size'], $config['max_resolutions']);
            $storageManager = new StorageManager($config['storage_path'], $config['files_url_prefix'], $config['files_base_url']);
            $renderer = new FastRenderer($config['chrome_path'], $config['render_timeout'], $config['chrome_max_idle_time']);
            $htmlComposer = new HtmlComposer();

            $payload = $requestHandler->parseAndValidateHtml($body);

            $jobId = $storageManager->generateJobId();
            $jobDir = $storageManager->createJobDirectory($jobId);

            $fullHtml = $htmlComposer->compose(
                $payload['html'],
                $payload['css'],
                true
            );
            $htmlPath = $htmlComposer->saveToFile($fullHtml, $jobId, $config['storage_path']);

            $screenshots = $renderer->renderScreenshots(
                $htmlPath,
                $payload['resolutions'],
                $jobDir,
                $payload['timeoutMs'],
                $payload['delayAfterLoadMs'],
                $payload['scrollSteps'],
                $payload['scrollIntervalMs'],
                $payload['optimizeForLlm']
            );

            $screenshotsWithUrls = $this->buildScreenshotPayload(
                $screenshots,
                $storageManager,
                $renderer,
                $payload['returnBase64']
            );

            $result = $responseBuilder->buildSuccess($jobId, $screenshotsWithUrls, $payload['returnBase64']);
            return $this->responseFactory->json($result, 200, true);
        } catch (\Exception $e) {
            $code = $e->getCode() ?: 500;
            $httpCode = in_array($code, [400, 401, 403, 404, 413, 500], true) ? $code : 500;

            $data = $responseBuilder->buildError($e->getMessage(), $code);
            return $this->responseFactory->json($data, $httpCode);
        }
    }

    public function renderUrl(Request $request): Response
    {
        $responseBuilder = new ResponseBuilder();
        $config = $this->config->serviceConfig();

        try {
            $body = $this->extractRawBody($request);

            $requestHandler = new RequestHandler($config['max_html_size'], $config['max_resolutions']);
            $storageManager = new StorageManager($config['storage_path'], $config['files_url_prefix'], $config['files_base_url']);
            $renderer = new FastRenderer($config['chrome_path'], $config['render_timeout'], $config['chrome_max_idle_time']);

            $payload = $requestHandler->parseAndValidateUrl($body);

            $jobId = $storageManager->generateJobId();
            $jobDir = $storageManager->createJobDirectory($jobId);

            $screenshots = $renderer->renderScreenshotsFromUrl(
                $payload['url'],
                $payload['resolutions'],
                $jobDir,
                $payload['timeoutMs'],
                $payload['delayAfterLoadMs'],
                $payload['scrollSteps'],
                $payload['scrollIntervalMs'],
                $payload['optimizeForLlm']
            );

            $screenshotsWithUrls = $this->buildScreenshotPayload(
                $screenshots,
                $storageManager,
                $renderer,
                $payload['returnBase64']
            );

            $result = $responseBuilder->buildSuccess($jobId, $screenshotsWithUrls, $payload['returnBase64']);
            return $this->responseFactory->json($result, 200, true);
        } catch (\Exception $e) {
            $code = $e->getCode() ?: 500;
            $httpCode = in_array($code, [400, 401, 403, 404, 413, 500], true) ? $code : 500;

            $data = $responseBuilder->buildError($e->getMessage(), $code);
            return $this->responseFactory->json($data, $httpCode);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $screenshots
     * @return array<int, array<string, mixed>>
     */
    private function buildScreenshotPayload(
        array $screenshots,
        StorageManager $storageManager,
        FastRenderer $renderer,
        bool $includeBase64
    ): array {
        $formatted = [];

        foreach ($screenshots as $screenshot) {
            $viewport = [
                'id' => $screenshot['label'],
                'width' => (int)$screenshot['width'],
                'height' => (int)$screenshot['height'],
            ];

            $item = [
                'viewport' => $viewport,
                'url' => $storageManager->getPublicUrl($screenshot['file'], true),
                'html' => $screenshot['html'] ?? '',
            ];

            if (!empty($screenshot['layout_metrics'])) {
                $item['layout_metrics'] = $screenshot['layout_metrics'];
            }

            if ($includeBase64) {
                $item['screenshot_base64'] = $renderer->toBase64($screenshot['file']);
            }

            $formatted[] = $item;
        }

        return $formatted;
    }

    private function extractRawBody(Request $request): string
    {
        $body = $request->body();

        if (is_string($body)) {
            return $body;
        }

        if (is_array($body)) {
            $encoded = json_encode($body);
            return $encoded === false ? '' : $encoded;
        }

        return '';
    }
}

<?php

namespace LetMeSee;

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Browser;
use HeadlessChromium\Page;
use HeadlessChromium\PageUtils\ResponseWaiter;

/**
 * Fast Renderer - Uses direct Chrome process without remote debugging
 */
class FastRenderer
{
    private static ?Browser $browser = null;
    private static ?int $lastUsed = null;
    private static int $maxIdleTime = 300;
    private const LLM_MAX_WIDTH = 1280;
    private const LLM_MAX_HEIGHT = 720;
    private string $chromePath;
    private int $timeout;
    private bool $debug;
    private string $debugLogPath;
    private bool $captureBeyondViewport;
    private string $navigationEvent;

    public function __construct(?string $chromePath = null, int $timeout = 5000, int $maxIdleTime = 300)
    {
        $this->chromePath = $this->resolveChromePath($chromePath);
        $this->timeout = $timeout;
        self::$maxIdleTime = $maxIdleTime;

        $this->debug = filter_var(
            $_ENV['FAST_RENDERER_DEBUG'] ?? getenv('FAST_RENDERER_DEBUG') ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $storageRoot = rtrim(
            $_ENV['STORAGE_PATH']
                ?? getenv('STORAGE_PATH')
                ?? __DIR__ . '/../storage',
            '/'
        );

        $defaultLog = $storageRoot . '/logs/fast_renderer.log';
        $this->debugLogPath = $_ENV['FAST_RENDERER_LOG']
            ?? getenv('FAST_RENDERER_LOG')
            ?? $defaultLog;

        $this->captureBeyondViewport = $this->resolveCaptureSetting();

        $event = $_ENV['URL_NAVIGATION_EVENT'] ?? getenv('URL_NAVIGATION_EVENT') ?? Page::NETWORK_IDLE;
        $allowedEvents = [
            Page::DOM_CONTENT_LOADED,
            Page::FIRST_CONTENTFUL_PAINT,
            Page::FIRST_IMAGE_PAINT,
            Page::FIRST_MEANINGFUL_PAINT,
            Page::FIRST_PAINT,
            Page::INTERACTIVE_TIME,
            Page::LOAD,
            Page::NETWORK_IDLE,
        ];

        $this->navigationEvent = in_array($event, $allowedEvents, true) ? $event : Page::NETWORK_IDLE;
    }

    /**
     * Get or create browser instance
     */
    private function getBrowser(): Browser
    {
        // Check if we need to recreate due to idle timeout
        if (self::$browser !== null && self::$lastUsed !== null) {
            $idleTime = time() - self::$lastUsed;
            if ($idleTime > self::$maxIdleTime) {
                $this->closeBrowser();
            }
        }

        // Create if doesn't exist
        if (self::$browser === null) {
            $browserFactory = new BrowserFactory($this->chromePath);
            
            self::$browser = $browserFactory->createBrowser([
                'headless' => true,
                'windowSize' => [1920, 1080],
                'customFlags' => [
                    '--disable-web-security',
                    '--hide-scrollbars',
                    '--disable-dev-shm-usage',
                    '--disable-gpu',
                    '--no-sandbox',
                    '--disable-setuid-sandbox',
                ]
            ]);
        }

        self::$lastUsed = time();
        return self::$browser;
    }

    /**
     * Close browser
     */
    private function closeBrowser(): void
    {
        if (self::$browser !== null) {
            try {
                self::$browser->close();
            } catch (\Exception $e) {
                // Ignore
            }
            self::$browser = null;
            self::$lastUsed = null;
        }
    }

    /**
     * Render screenshots
     */
    public function renderScreenshots(
        string $htmlFilePath,
        array $resolutions,
        string $outputDir,
        ?int $timeoutOverride = null,
        ?int $delayAfterLoadMs = null,
        ?int $scrollSteps = null,
        ?int $scrollIntervalMs = null,
        bool $optimizeForLlm = false
    ): array
    {
        $page = null;
        $screenshots = [];
        $timeout = $timeoutOverride !== null ? max(1000, $timeoutOverride) : $this->timeout;
        $scrollSteps = $this->resolveScrollSteps($scrollSteps);
        $scrollIntervalMs = $this->resolveScrollInterval($scrollIntervalMs);

        try {
            $renderStart = microtime(true);
            $this->logDebug('render:start', [
                'html' => $htmlFilePath,
                'resolutions' => count($resolutions)
            ]);

            $browserStart = microtime(true);
            $browser = $this->getBrowser();
            $this->logDebug('render:browser_ready', [
                'elapsed_ms' => $this->elapsedMs($browserStart)
            ]);

            $pageCreateStart = microtime(true);
            $page = $browser->createPage();
            $this->logDebug('render:page_created', [
                'label' => 'initial',
                'elapsed_ms' => $this->elapsedMs($pageCreateStart)
            ]);

            $navigateStart = microtime(true);
            $initialHtml = file_get_contents($htmlFilePath);
            $page->setHtml($initialHtml, $timeout, Page::DOM_CONTENT_LOADED);
            $this->logDebug('render:navigation_complete', [
                'label' => 'initial',
                'elapsed_ms' => $this->elapsedMs($navigateStart)
            ]);

            if ($delayAfterLoadMs !== null && $delayAfterLoadMs > 0) {
                usleep($delayAfterLoadMs * 1000);
                $this->logDebug('render:post_load_delay', [
                    'delay_ms' => $delayAfterLoadMs
                ]);
            }

            $this->performScroll($page, $scrollSteps, $scrollIntervalMs);

            $screenshots = $this->captureScreenshots($page, $resolutions, $outputDir, $optimizeForLlm);

            $this->logDebug('render:complete', [
                'total_ms' => $this->elapsedMs($renderStart)
            ]);

        } catch (\Exception $e) {
            throw $this->wrapRenderException($e, $timeout);
        } finally {
            if ($page !== null) {
                try {
                    $page->close();
                } catch (\Throwable) {
                    // ignore close failures
                }
            }
        }

        return $screenshots;
    }

    public function renderScreenshotsFromUrl(
        string $url,
        array $resolutions,
        string $outputDir,
        ?int $timeoutOverride = null,
        ?int $delayAfterLoadMs = null,
        ?int $scrollSteps = null,
        ?int $scrollIntervalMs = null,
        bool $optimizeForLlm = false
    ): array
    {
        $page = null;
        $screenshots = [];
        $timeout = $timeoutOverride !== null ? max(1000, $timeoutOverride) : $this->timeout;
        $scrollSteps = $this->resolveScrollSteps($scrollSteps);
        $scrollIntervalMs = $this->resolveScrollInterval($scrollIntervalMs);

        try {
            $renderStart = microtime(true);
            $this->logDebug('render-url:start', [
                'url' => $url,
                'resolutions' => count($resolutions)
            ]);

            $browserStart = microtime(true);
            $browser = $this->getBrowser();
            $this->logDebug('render-url:browser_ready', [
                'elapsed_ms' => $this->elapsedMs($browserStart)
            ]);

            $pageCreateStart = microtime(true);
            $page = $browser->createPage();
            $this->logDebug('render-url:page_created', [
                'elapsed_ms' => $this->elapsedMs($pageCreateStart)
            ]);

            $navigateStart = microtime(true);
            $navigation = $page->navigate($url);
            $navigation->waitForNavigation($this->navigationEvent, $timeout);
            $this->logDebug('render-url:navigation_complete', [
                'elapsed_ms' => $this->elapsedMs($navigateStart),
                'event' => $this->navigationEvent
            ]);

            if ($delayAfterLoadMs !== null && $delayAfterLoadMs > 0) {
                usleep($delayAfterLoadMs * 1000);
                $this->logDebug('render-url:post_load_delay', [
                    'delay_ms' => $delayAfterLoadMs
                ]);
            }

            $this->performScroll($page, $scrollSteps, $scrollIntervalMs);

            $screenshots = $this->captureScreenshots($page, $resolutions, $outputDir, $optimizeForLlm);

            $this->logDebug('render-url:complete', [
                'total_ms' => $this->elapsedMs($renderStart)
            ]);

        } catch (\Exception $e) {
            throw $this->wrapRenderException($e, $timeout);
        } finally {
            if ($page !== null) {
                try {
                    $page->close();
                } catch (\Throwable) {
                    // ignore close failures
                }
            }
        }

        return $screenshots;
    }

    private function captureScreenshots(Page $page, array $resolutions, string $outputDir, bool $optimizeForLlm): array
    {
        $screenshots = [];
        $format = strtolower($_ENV['SCREENSHOT_FORMAT'] ?? getenv('SCREENSHOT_FORMAT') ?? 'png');
        $quality = isset($_ENV['SCREENSHOT_QUALITY'])
            ? (int)$_ENV['SCREENSHOT_QUALITY']
            : (int)(getenv('SCREENSHOT_QUALITY') ?: 80);

        if ($optimizeForLlm) {
            $originalFormat = $format;
            $format = 'jpeg';
            $quality = max(10, min(60, $quality));

            $this->logDebug('render:llm_optimization', [
                'format' => $format,
                'quality' => $quality,
                'original_format' => $originalFormat
            ]);
        }

        $fileExtension = $format === 'jpeg' ? 'jpg' : $format;

        foreach ($resolutions as $index => $resolution) {
            $resolutionStart = microtime(true);

            $width = (int)$resolution['width'];
            $height = (int)$resolution['height'];
            $label = preg_replace('/[^a-zA-Z0-9_-]/', '_', $resolution['label']);
            $scale = $optimizeForLlm ? $this->calculateLlmScale($width, $height) : 1.0;

            $viewportStart = microtime(true);
            $this->applyViewport($page, $width, $height, $scale)->await();
            $this->logDebug('render:viewport_set', [
                'label' => $label,
                'elapsed_ms' => $this->elapsedMs($viewportStart),
                'scale' => $scale
            ]);

            if ($index > 0) {
                $resizeWaitStart = microtime(true);
                usleep(20000);
                $this->logDebug('render:resize_wait_complete', [
                    'label' => $label,
                    'elapsed_ms' => $this->elapsedMs($resizeWaitStart)
                ]);
            } else {
                usleep(30000);
            }

            // Capture HTML and Layout Metrics
            $dataExtractionStart = microtime(true);
            $pageData = $page->evaluate('(() => {
                return {
                    html: document.documentElement.outerHTML,
                    metrics: {
                        scrollWidth: document.documentElement.scrollWidth,
                        scrollHeight: document.documentElement.scrollHeight,
                        clientWidth: document.documentElement.clientWidth,
                        clientHeight: document.documentElement.clientHeight,
                        bodyScrollWidth: document.body.scrollWidth,
                        bodyScrollHeight: document.body.scrollHeight
                    }
                };
            })()')->getReturnValue();
            
            $this->logDebug('render:data_extracted', [
                'label' => $label,
                'elapsed_ms' => $this->elapsedMs($dataExtractionStart)
            ]);

            $screenshotStart = microtime(true);
            $screenshotPath = $outputDir . '/' . $label . '.' . $fileExtension;

            $options = ['format' => $format];
            if ($format === 'jpeg') {
                $options['quality'] = max(10, min(100, $quality));
            }

            if ($this->captureBeyondViewport) {
                $options['captureBeyondViewport'] = true;
            }

            $page->screenshot($options)->saveToFile($screenshotPath);

            $actualWidth = (int)round($width * $scale);
            $actualHeight = (int)round($height * $scale);

            $imageInfo = @getimagesize($screenshotPath);
            if ($imageInfo !== false) {
                $actualWidth = (int)$imageInfo[0];
                $actualHeight = (int)$imageInfo[1];
            }

            $reportedWidth = $optimizeForLlm ? $width : $actualWidth;
            $reportedHeight = $actualHeight;

            $this->logDebug('render:screenshot_saved', [
                'label' => $label,
                'elapsed_ms' => $this->elapsedMs($screenshotStart)
            ]);

            $screenshots[] = [
                'label' => $label,
                'width' => $reportedWidth,
                'height' => $reportedHeight,
                'file' => $screenshotPath,
                'filename' => $label . '.' . $fileExtension,
                'html' => $pageData['html'] ?? '',
                'layout_metrics' => $pageData['metrics'] ?? []
            ];

            $this->logDebug('render:resolution_complete', [
                'label' => $label,
                'width' => $reportedWidth,
                'height' => $reportedHeight,
                'actual_width' => $actualWidth,
                'actual_height' => $actualHeight,
                'elapsed_ms' => $this->elapsedMs($resolutionStart)
            ]);
        }

        return $screenshots;
    }

    private function resolveScrollSteps(?int $scrollSteps): int
    {
        if ($scrollSteps === null) {
            return 0;
        }

        return max(0, min($scrollSteps, 20));
    }

    private function resolveScrollInterval(?int $scrollIntervalMs): int
    {
        if ($scrollIntervalMs === null) {
            return 0;
        }

        return max(0, min($scrollIntervalMs, 5000));
    }

    private function performScroll(Page $page, int $scrollSteps, int $scrollIntervalMs): void
    {
        if ($scrollSteps <= 0) {
            return;
        }

        $this->logDebug('render:scroll:start', [
            'steps' => $scrollSteps,
            'interval_ms' => $scrollIntervalMs
        ]);

        for ($step = 0; $step < $scrollSteps; $step++) {
            $page->evaluate('window.scrollBy(0, window.innerHeight);')->waitForResponse();

            if ($scrollIntervalMs > 0) {
                usleep($scrollIntervalMs * 1000);
            }
        }

        $page->evaluate('window.scrollTo(0, 0);')->waitForResponse();

        $this->logDebug('render:scroll:complete', [
            'steps' => $scrollSteps
        ]);
    }

    private function applyViewport(Page $page, int $width, int $height, float $scale): ResponseWaiter
    {
        $scale = max(0.1, $scale);

        if ($scale < 1.0) {
            return $page->setDeviceMetricsOverride([
                'width' => $width,
                'height' => $height,
                'deviceScaleFactor' => $scale,
                'mobile' => false,
            ]);
        }

        return $page->setViewport($width, $height);
    }

    private function calculateLlmScale(int $width, int $height): float
    {
        if ($width <= 0 || $height <= 0) {
            return 1.0;
        }

        if ($width <= self::LLM_MAX_WIDTH && $height <= self::LLM_MAX_HEIGHT) {
            return 1.0;
        }

        $scaleByWidth = self::LLM_MAX_WIDTH / $width;
        $scaleByHeight = self::LLM_MAX_HEIGHT / $height;

        return max(0.1, min(1.0, min($scaleByWidth, $scaleByHeight)));
    }


    /**
     * Convert to base64
     */
    public function toBase64(string $filePath): string
    {
        $imageData = file_get_contents($filePath);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($imageData);
    }

    /**
     * Get status
     */
    public static function getStatus(): array
    {
        return [
            'alive' => self::$browser !== null,
            'lastUsed' => self::$lastUsed,
            'uptime' => self::$lastUsed ? time() - self::$lastUsed : null
        ];
    }

    private function resolveCaptureSetting(): bool
    {
        $value = $_ENV['SCREENSHOT_FULL_PAGE'] ?? getenv('SCREENSHOT_FULL_PAGE');

        if ($value === null || $value === '') {
            return true;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? true;
    }

    /**
     * Resolve the Chrome binary path with sensible fallbacks.
     */
    private function resolveChromePath(?string $providedPath): string
    {
        if ($providedPath && is_executable($providedPath)) {
            return $providedPath;
        }

        $candidates = array_filter([
            $providedPath,
            $_ENV['CHROME_PATH'] ?? getenv('CHROME_PATH'),
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/lib/chromium/chrome',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
        ], static fn($path) => is_string($path) && $path !== '');

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Chrome binary not found. Set CHROME_PATH to a valid executable.');
    }

    private function logDebug(string $message, array $context = []): void
    {
        if (!$this->debug) {
            return;
        }

        $dir = dirname($this->debugLogPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $payload = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        $line = sprintf('[%s] %s%s%s', date('c'), $message, $payload, PHP_EOL);
        file_put_contents($this->debugLogPath, $line, FILE_APPEND);
    }

    private function elapsedMs(float $start): float
    {
        return round((microtime(true) - $start) * 1000, 2);
    }

    private function wrapRenderException(\Exception $exception, int $timeoutMs): \Exception
    {
        $message = $exception->getMessage();

        if (stripos($message, 'timed out') !== false || stripos($message, 'timeout') !== false) {
            $this->logDebug('render:timeout_triggered', [
                'message' => $message,
                'timeout_ms' => $timeoutMs
            ]);

            $this->closeBrowser();

            $seconds = round($timeoutMs / 1000, 2);

            return new \Exception(
                sprintf('Rendering error: Operation timed out after %ss.', $seconds),
                504
            );
        }

        $this->logDebug('render:error', [
            'message' => $message
        ]);

        return new \Exception('Rendering error: ' . $message, $exception->getCode() ?: 500);
    }
}

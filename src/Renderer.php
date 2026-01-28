<?php

namespace LetMeSee;

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Exception\CommunicationException;
use HeadlessChromium\Exception\NoResponseAvailable;
use LetMeSee\ChromeConnection;

class Renderer
{
    private string $chromePath;
    private int $timeout;
    private int $maxIdleTime;

    public function __construct(?string $chromePath = null, int $timeout = 5000, int $maxIdleTime = 300)
    {
        $this->chromePath = $chromePath ?? '/usr/bin/google-chrome';
        $this->timeout = $timeout;
        $this->maxIdleTime = $maxIdleTime;
    }

    /**
     * Render HTML at specified resolutions and capture screenshots
     * 
     * @param string $htmlFilePath Path to the HTML file to render
     * @param array $resolutions Array of resolution specifications
     * @param string $outputDir Directory to save screenshots
     * @return array Array of screenshot information
     * @throws \Exception on rendering failure
     */
    public function renderScreenshots(string $htmlFilePath, array $resolutions, string $outputDir): array
    {
        $screenshots = [];

        try {
            // Get or start persistent Chrome connection
            $chromeConnection = new ChromeConnection($this->chromePath, $this->maxIdleTime);
            $connection = $chromeConnection->getConnection();

            // Connect to existing Chrome instance
            $browserFactory = new BrowserFactory();
            $browser = $browserFactory->createBrowser([
                'debuggerUrl' => "http://{$connection['host']}:{$connection['port']}"
            ]);

            foreach ($resolutions as $resolution) {
                $width = (int)$resolution['width'];
                $height = (int)$resolution['height'];
                $label = preg_replace('/[^a-zA-Z0-9_-]/', '_', $resolution['label']);

                // Create a new page/tab (fast operation)
                $page = $browser->createPage();

                // Set viewport
                $page->setViewport($width, $height)
                    ->await();

                // Navigate to the HTML file
                $page->navigate('file://' . realpath($htmlFilePath))
                    ->waitForNavigation();

                // Minimal wait for rendering (CSS is usually instant for static content)
                usleep(100000); // 100ms instead of 500ms

                // Take screenshot
                $screenshotPath = $outputDir . '/' . $label . '.png';
                $page->screenshot([
                    'format' => 'png',
                ])->saveToFile($screenshotPath);

                $screenshots[] = [
                    'label' => $label,
                    'width' => $width,
                    'height' => $height,
                    'file' => $screenshotPath,
                    'filename' => $label . '.png'
                ];

                // Close the page/tab to free memory
                $page->close();
            }

            // DON'T close browser - keep it alive for next request!

        } catch (CommunicationException | NoResponseAvailable $e) {
            throw new \Exception('Chrome communication error: ' . $e->getMessage(), 500);
        } catch (\Exception $e) {
            throw new \Exception('Rendering error: ' . $e->getMessage(), 500);
        }

        return $screenshots;
    }

    /**
     * Convert screenshot to base64 encoded string
     * 
     * @param string $filePath Path to the screenshot file
     * @return string Base64 encoded image
     */
    public function toBase64(string $filePath): string
    {
        $imageData = file_get_contents($filePath);
        return 'data:image/png;base64,' . base64_encode($imageData);
    }
}

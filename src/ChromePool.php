<?php

namespace LetMeSee;

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Browser;
use HeadlessChromium\Exception\CommunicationException;

class ChromePool
{
    private static ?Browser $browser = null;
    private static ?int $lastUsed = null;
    private static int $maxIdleTime = 300; // 5 minutes
    private static ?string $chromePath = null;
    private static int $timeout = 5000;

    public function __construct(?string $chromePath = null, int $timeout = 5000, int $maxIdleTime = 300)
    {
        // Set static configuration on first initialization
        if (self::$chromePath === null) {
            self::$chromePath = $chromePath ?? '/usr/bin/google-chrome';
            self::$timeout = $timeout;
            self::$maxIdleTime = $maxIdleTime;
        }
    }

    /**
     * Get or create a persistent Chrome browser instance
     * 
     * @return Browser
     * @throws \Exception
     */
    public function getBrowser(): Browser
    {
        // Check if browser needs to be restarted due to inactivity
        if (self::$browser !== null && self::$lastUsed !== null) {
            $idleTime = time() - self::$lastUsed;
            if ($idleTime > self::$maxIdleTime) {
                $this->closeBrowser();
            }
        }

        // Create browser if it doesn't exist or was closed
        if (self::$browser === null) {
            self::$browser = $this->createBrowser();
            self::$lastUsed = time();
        }

        // Skip health check - just return the browser
        // The renderer will handle any connection errors naturally
        
        self::$lastUsed = time();
        return self::$browser;
    }

    /**
     * Create a new Chrome browser instance
     * 
     * @return Browser
     */
    private function createBrowser(): Browser
    {
        $browserFactory = new BrowserFactory(self::$chromePath);

        $browser = $browserFactory->createBrowser([
            'headless' => true,
            'noSandbox' => false,
            'windowSize' => [1920, 1080],
            'keepAlive' => true, // Keep the connection alive
            'customFlags' => [
                '--disable-javascript',
                '--disable-web-security',
                '--hide-scrollbars',
                '--disable-dev-shm-usage',
                '--disable-gpu',
                '--no-first-run',
                '--no-default-browser-check',
                '--disable-background-networking',
                '--disable-default-apps',
                '--disable-extensions',
                '--disable-sync',
                '--metrics-recording-only',
                '--mute-audio',
                '--no-sandbox', // Required in some environments
            ]
        ]);

        return $browser;
    }

    /**
     * Close the browser instance
     */
    public function closeBrowser(): void
    {
        if (self::$browser !== null) {
            try {
                self::$browser->close();
            } catch (\Exception $e) {
                // Ignore errors when closing
            }
            self::$browser = null;
            self::$lastUsed = null;
        }
    }

    /**
     * Get the last time the browser was used
     * 
     * @return int|null Unix timestamp or null if never used
     */
    public static function getLastUsed(): ?int
    {
        return self::$lastUsed;
    }

    /**
     * Check if browser is currently alive
     * 
     * @return bool
     */
    public static function isAlive(): bool
    {
        return self::$browser !== null;
    }

    /**
     * Get browser uptime in seconds
     * 
     * @return int|null Uptime in seconds or null if not running
     */
    public static function getUptime(): ?int
    {
        if (self::$lastUsed === null) {
            return null;
        }
        return time() - self::$lastUsed;
    }
}

<?php

namespace LetMeSee;

/**
 * Chrome Connection Manager
 * Uses a connection file to maintain Chrome process across PHP requests
 */
class ChromeConnection
{
    private static string $connectionFile = '/tmp/letmesee_chrome.json';
    private static int $maxIdleTime = 300;
    private string $chromePath;

    public function __construct(?string $chromePath = null, int $maxIdleTime = 300)
    {
        $this->chromePath = $chromePath ?? '/usr/bin/google-chrome';
        self::$maxIdleTime = $maxIdleTime;
    }

    /**
     * Get or start a Chrome instance on a specific debugging port
     * 
     * @return array ['host' => string, 'port' => int]
     */
    public function getConnection(): array
    {
        $connection = $this->readConnection();

        // Check if existing connection is still valid
        if ($connection && $this->isChromRunning($connection['pid'])) {
            // Check if idle for too long
            if (time() - $connection['lastUsed'] < self::$maxIdleTime) {
                // Update last used time
                $this->updateConnection($connection['pid'], $connection['port']);
                return [
                    'host' => '127.0.0.1',
                    'port' => $connection['port']
                ];
            } else {
                // Kill idle Chrome
                $this->killChrome($connection['pid']);
            }
        }

        // Start new Chrome instance
        return $this->startChrome();
    }

    /**
     * Start a new Chrome instance
     * 
     * @return array
     */
    private function startChrome(): array
    {
        $port = 9222; // Default debugging port
        
        // Kill any existing Chrome on this port
        exec("lsof -ti:$port | xargs kill -9 2>/dev/null");
        
        $command = sprintf(
            '%s --headless --disable-gpu --remote-debugging-port=%d --no-sandbox --disable-javascript --disable-web-security --hide-scrollbars --disable-dev-shm-usage --no-first-run --disable-background-networking --disable-default-apps --disable-extensions --disable-sync --metrics-recording-only --mute-audio > /dev/null 2>&1 & echo $!',
            escapeshellarg($this->chromePath),
            $port
        );

        $pid = (int)trim(shell_exec($command));
        
        // Wait for Chrome to start - check if port is open (faster than sleep)
        $maxWait = 30; // 3 seconds max
        $connected = false;
        for ($i = 0; $i < $maxWait; $i++) {
            usleep(100000); // 100ms
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($connection) {
                fclose($connection);
                $connected = true;
                break;
            }
        }
        
        if (!$connected) {
            throw new \Exception("Chrome failed to start on port $port");
        }
        
        // Save connection info
        $this->saveConnection($pid, $port);

        return [
            'host' => '127.0.0.1',
            'port' => $port
        ];
    }

    /**
     * Check if Chrome process is running
     * 
     * @param int $pid
     * @return bool
     */
    private function isChromRunning(int $pid): bool
    {
        $result = shell_exec("ps -p $pid -o comm= 2>/dev/null");
        return !empty($result) && (strpos($result, 'chrome') !== false || strpos($result, 'chromium') !== false);
    }

    /**
     * Kill Chrome process
     * 
     * @param int $pid
     */
    private function killChrome(int $pid): void
    {
        exec("kill -9 $pid 2>/dev/null");
        @unlink(self::$connectionFile);
    }

    /**
     * Read connection file
     * 
     * @return array|null
     */
    private function readConnection(): ?array
    {
        if (!file_exists(self::$connectionFile)) {
            return null;
        }

        $data = json_decode(file_get_contents(self::$connectionFile), true);
        return $data ?: null;
    }

    /**
     * Save connection info
     * 
     * @param int $pid
     * @param int $port
     */
    private function saveConnection(int $pid, int $port): void
    {
        file_put_contents(self::$connectionFile, json_encode([
            'pid' => $pid,
            'port' => $port,
            'lastUsed' => time(),
            'started' => time()
        ]));
    }

    /**
     * Update last used time
     * 
     * @param int $pid
     * @param int $port
     */
    private function updateConnection(int $pid, int $port): void
    {
        $connection = $this->readConnection();
        if ($connection) {
            $connection['lastUsed'] = time();
            file_put_contents(self::$connectionFile, json_encode($connection));
        }
    }

    /**
     * Get Chrome status
     * 
     * @return array
     */
    public static function getStatus(): array
    {
        $connection = null;
        if (file_exists(self::$connectionFile)) {
            $connection = json_decode(file_get_contents(self::$connectionFile), true);
        }

        if (!$connection) {
            return [
                'alive' => false,
                'pid' => null,
                'port' => null,
                'lastUsed' => null,
                'uptime' => null
            ];
        }

        $isRunning = false;
        if ($connection['pid']) {
            $result = shell_exec("ps -p {$connection['pid']} -o comm= 2>/dev/null");
            $isRunning = !empty($result) && (strpos($result, 'chrome') !== false || strpos($result, 'chromium') !== false);
        }

        return [
            'alive' => $isRunning,
            'pid' => $connection['pid'],
            'port' => $connection['port'],
            'lastUsed' => $connection['lastUsed'] ?? null,
            'uptime' => isset($connection['started']) ? time() - $connection['started'] : null
        ];
    }

    /**
     * Close Chrome manually
     */
    public function close(): void
    {
        $connection = $this->readConnection();
        if ($connection && isset($connection['pid'])) {
            $this->killChrome($connection['pid']);
        }
    }
}

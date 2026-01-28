<?php

namespace LetMeSee;

class StorageManager
{
    private string $storagePath;
    private string $filesUrlPrefix;
    private ?string $baseUrl;

    public function __construct(string $storagePath = './storage', string $filesUrlPrefix = '/files', ?string $baseUrl = null)
    {
        $this->storagePath = rtrim($storagePath, '/');
        $this->filesUrlPrefix = rtrim($filesUrlPrefix, '/');
        $this->baseUrl = $baseUrl ? rtrim($baseUrl, '/') : null;

        // Ensure storage directory exists
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }

    /**
     * Generate a unique job ID
     * 
     * @return string Job ID (UUID-like)
     */
    public function generateJobId(): string
    {
        return date('Y-m-d_His') . '_' . bin2hex(random_bytes(8));
    }

    /**
     * Create a directory for a specific job
     * 
     * @param string $jobId Job identifier
     * @return string Full path to the job directory
     */
    public function createJobDirectory(string $jobId): string
    {
        $jobDir = $this->storagePath . '/' . $jobId;
        
        if (!is_dir($jobDir)) {
            mkdir($jobDir, 0755, true);
        }

        return $jobDir;
    }

    /**
     * Convert a file path to a public URL
     * 
     * @param string $filePath Full file path
     * @param bool $includeHost Whether to include the full host URL
     * @return string Public URL
     */
    public function getPublicUrl(string $filePath, bool $includeHost = false): string
    {
        $relativePath = str_replace($this->storagePath . '/', '', $filePath);
        $url = $this->filesUrlPrefix . '/' . $relativePath;
        
        if ($includeHost) {
            $baseUrl = $this->baseUrl ?? $this->detectBaseUrl();
            if ($baseUrl !== null) {
                $url = rtrim($baseUrl, '/') . $url;
            }
        }
        
        return $url;
    }

    private function detectBaseUrl(): ?string
    {
        $scheme = 'http';

        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
            $scheme = 'https';
        } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
            $scheme = $_SERVER['REQUEST_SCHEME'];
        }

        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? null;

        if ($host === null) {
            return null;
        }

        if (!str_contains($host, ':')) {
            $port = $_SERVER['SERVER_PORT'] ?? null;
            if ($port !== null) {
                $port = (int)$port;
                if ($port !== 80 && $port !== 443) {
                    $host .= ':' . $port;
                }
            }
        }

        return $scheme . '://' . $host;
    }

    /**
     * Get the storage path
     * 
     * @return string
     */
    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    /**
     * Validate and resolve a file path (protect against directory traversal)
     * 
     * @param string $requestedPath Requested file path
     * @return string|null Resolved absolute path or null if invalid
     */
    public function resolveSecureFilePath(string $requestedPath): ?string
    {
        // Remove any query strings or fragments
        $requestedPath = strtok($requestedPath, '?');
        
        // Remove leading slash if present
        $requestedPath = ltrim($requestedPath, '/');

        // Build the full path
        $fullPath = $this->storagePath . '/' . $requestedPath;
        
        // Resolve real path (follows symlinks and resolves .. and .)
        $realPath = realpath($fullPath);

        // Check if file exists and is within storage directory
        if ($realPath === false || !file_exists($realPath)) {
            return null;
        }

        // Ensure the resolved path is within the storage directory
        $realStoragePath = realpath($this->storagePath);
        if (strpos($realPath, $realStoragePath) !== 0) {
            return null; // Path traversal attempt
        }

        return $realPath;
    }

    /**
     * Clean up old job directories (optional maintenance)
     * 
     * @param int $olderThanSeconds Delete jobs older than this many seconds
     * @return int Number of directories deleted
     */
    public function cleanup(int $olderThanSeconds = 86400): int
    {
        $deleted = 0;
        $cutoffTime = time() - $olderThanSeconds;

        $dirs = glob($this->storagePath . '/*', GLOB_ONLYDIR);
        
        foreach ($dirs as $dir) {
            if (filemtime($dir) < $cutoffTime) {
                $this->deleteDirectory($dir);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Recursively delete a directory
     * 
     * @param string $dir Directory path
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}

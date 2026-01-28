<?php

namespace LetMeSee\Phapi\Services;

final class Config
{
    private string $rootPath;
    /**
     * @var array<string, string>
     */
    private array $env = [];
    /**
     * @var array<string, mixed>|null
     */
    private ?array $serviceConfig = null;

    public function __construct(string $rootPath)
    {
        $this->rootPath = rtrim($rootPath, '/');
        $this->loadEnv();
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    /**
     * @return array<string, mixed>
     */
    public function serviceConfig(): array
    {
        if ($this->serviceConfig !== null) {
            return $this->serviceConfig;
        }

        $storagePath = $this->resolvePath(
            $this->env('STORAGE_PATH', $this->rootPath . '/storage')
        );

        $this->serviceConfig = [
            'bearer_token' => $this->env('BEARER_TOKEN'),
            'chrome_path' => $this->env('CHROME_PATH', '/usr/bin/google-chrome'),
            'storage_path' => $storagePath,
            'files_url_prefix' => $this->env('FILES_URL_PREFIX', '/storage'),
            'files_base_url' => $this->env('FILES_BASE_URL'),
            'max_html_size' => $this->envInt('MAX_HTML_SIZE', 1048576),
            'max_resolutions' => $this->envInt('MAX_RESOLUTIONS', 10),
            'render_timeout' => $this->envInt('RENDER_TIMEOUT', 5000),
            'chrome_max_idle_time' => $this->envInt('CHROME_MAX_IDLE_TIME', 300),
        ];

        return $this->serviceConfig;
    }

    public function maxBodyBytes(): int
    {
        $configured = $this->envInt('MAX_BODY_BYTES', 0);
        if ($configured > 0) {
            return $configured;
        }

        $service = $this->serviceConfig();
        return (int)($service['max_html_size'] ?? 1048576);
    }

    private function env(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $this->env[$key] ?? $default;
        }
        return $value;
    }

    private function envInt(string $key, int $default): int
    {
        $value = $this->env($key);
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            return $default;
        }
        return (int)$value;
    }

    private function resolvePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        $trimmed = ltrim($path, './');
        return rtrim($this->rootPath . '/' . $trimmed, '/');
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return (bool)preg_match('/^[A-Za-z]:\\\\/', $path);
    }

    private function loadEnv(): void
    {
        $envPath = $this->rootPath . '/.env';
        if (!file_exists($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (!str_contains($trimmed, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $key = trim($key);
            $value = trim($value);
            $value = $this->stripQuotes($value);

            if ($key === '') {
                continue;
            }

            if (getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }

            $this->env[$key] = $value;
        }
    }

    private function stripQuotes(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}

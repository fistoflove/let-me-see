<?php

namespace LetMeSee;

class RequestHandler
{
    private int $maxPayloadSize;
    private int $maxResolutions;

    public function __construct(int $maxPayloadSize = 1048576, int $maxResolutions = 10)
    {
        $this->maxPayloadSize = $maxPayloadSize;
        $this->maxResolutions = $maxResolutions;
    }

    /**
     * Backwards compatible HTML parser that reads php://input when payload is omitted.
     */
    public function parseAndValidate(): array
    {
        return $this->parseAndValidateHtml();
    }

    public function parseAndValidateHtml(?string $rawInput = null): array
    {
        $payload = $this->decodePayload($rawInput);

        if (!isset($payload['html']) || !is_string($payload['html']) || trim($payload['html']) === '') {
            throw new \Exception('Missing required field: html', 400);
        }

        if (isset($payload['css']) && !is_string($payload['css'])) {
            throw new \Exception('Field css must be a string when provided', 400);
        }

        $resolutions = $this->validateResolutions($payload['resolutions'] ?? null);
        $includeBase64 = $this->resolveBoolean($payload['returnBase64'] ?? false);
        $timeoutMs = $this->resolveOptionalInt($payload['timeoutMs'] ?? null, 1000, 120000, 'timeoutMs');
        $delayAfterLoadMs = $this->resolveOptionalInt($payload['delayAfterLoadMs'] ?? null, 0, 60000, 'delayAfterLoadMs');
        $scrollSteps = $this->resolveOptionalInt($payload['scrollSteps'] ?? null, 0, 20, 'scrollSteps');
        $scrollIntervalMs = $this->resolveOptionalInt($payload['scrollIntervalMs'] ?? null, 0, 5000, 'scrollIntervalMs');
        $optimizeForLlm = $this->resolveBoolean($payload['optimizeForLlm'] ?? false);

        return [
            'html' => $payload['html'],
            'css' => $payload['css'] ?? null,
            'resolutions' => $resolutions,
            'returnBase64' => $includeBase64,
            'timeoutMs' => $timeoutMs,
            'delayAfterLoadMs' => $delayAfterLoadMs,
            'scrollSteps' => $scrollSteps,
            'scrollIntervalMs' => $scrollIntervalMs,
            'optimizeForLlm' => $optimizeForLlm,
        ];
    }

    public function parseAndValidateUrl(?string $rawInput = null): array
    {
        $payload = $this->decodePayload($rawInput);

        if (!isset($payload['url']) || !is_string($payload['url']) || trim($payload['url']) === '') {
            throw new \Exception('Missing required field: url', 400);
        }

        $url = $this->validatePublicUrl(trim($payload['url']));
        $resolutions = $this->validateResolutions($payload['resolutions'] ?? null);
        $includeBase64 = $this->resolveBoolean($payload['returnBase64'] ?? false);
        $timeoutMs = $this->resolveOptionalInt($payload['timeoutMs'] ?? null, 1000, 120000, 'timeoutMs');
        $delayAfterLoadMs = $this->resolveOptionalInt($payload['delayAfterLoadMs'] ?? null, 0, 60000, 'delayAfterLoadMs');
        $scrollSteps = $this->resolveOptionalInt($payload['scrollSteps'] ?? null, 0, 20, 'scrollSteps');
        $scrollIntervalMs = $this->resolveOptionalInt($payload['scrollIntervalMs'] ?? null, 0, 5000, 'scrollIntervalMs');
        $optimizeForLlm = $this->resolveBoolean($payload['optimizeForLlm'] ?? false);

        return [
            'url' => $url,
            'resolutions' => $resolutions,
            'returnBase64' => $includeBase64,
            'timeoutMs' => $timeoutMs,
            'delayAfterLoadMs' => $delayAfterLoadMs,
            'scrollSteps' => $scrollSteps,
            'scrollIntervalMs' => $scrollIntervalMs,
            'optimizeForLlm' => $optimizeForLlm,
        ];
    }

    public function authenticate(?string $expectedToken): bool
    {
        if ($expectedToken === null || $expectedToken === '') {
            return true;
        }

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return false;
        }

        return hash_equals($expectedToken, $matches[1]);
    }

    private function decodePayload(?string $rawInput = null): array
    {
        $rawInput ??= file_get_contents('php://input');

        if ($rawInput === false) {
            throw new \Exception('Unable to read request body', 400);
        }

        if (strlen($rawInput) > $this->maxPayloadSize) {
            throw new \Exception('Request body too large', 413);
        }

        $data = json_decode($rawInput, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON: ' . json_last_error_msg(), 400);
        }

        if (!is_array($data)) {
            throw new \Exception('Invalid JSON payload', 400);
        }

        return $data;
    }

    private function validateResolutions(mixed $resolutions): array
    {
        if (!is_array($resolutions)) {
            throw new \Exception('Missing or invalid field: resolutions', 400);
        }

        if (count($resolutions) === 0) {
            throw new \Exception('At least one resolution is required', 400);
        }

        if (count($resolutions) > $this->maxResolutions) {
            throw new \Exception("Too many resolutions. Maximum: {$this->maxResolutions}", 400);
        }

        $validated = [];

        foreach ($resolutions as $index => $resolution) {
            if (!is_array($resolution)) {
                throw new \Exception("Resolution at index {$index} must be an object", 400);
            }

            if (!isset($resolution['width'], $resolution['height'])) {
                throw new \Exception("Resolution at index {$index} missing width or height", 400);
            }

            if (!is_numeric($resolution['width']) || !is_numeric($resolution['height'])) {
                throw new \Exception("Resolution at index {$index} has invalid width or height", 400);
            }

            $width = (int)$resolution['width'];
            $height = (int)$resolution['height'];

            if ($width < 1 || $width > 7680) {
                throw new \Exception("Resolution at index {$index} width out of range (1-7680)", 400);
            }

            if ($height < 1 || $height > 4320) {
                throw new \Exception("Resolution at index {$index} height out of range (1-4320)", 400);
            }

            if (!isset($resolution['label']) || !is_string($resolution['label']) || trim($resolution['label']) === '') {
                throw new \Exception("Resolution at index {$index} missing label", 400);
            }

            $validated[] = [
                'width' => $width,
                'height' => $height,
                'label' => $resolution['label'],
            ];
        }

        return $validated;
    }

    private function resolveBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return false;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? false;
    }

    private function resolveOptionalInt(mixed $value, int $min, int $max, string $field): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value === '') {
            throw new \Exception("Field {$field} cannot be empty", 400);
        }

        if (!is_numeric($value)) {
            throw new \Exception("Field {$field} must be numeric", 400);
        }

        $intValue = (int)$value;

        if ($intValue < $min || $intValue > $max) {
            throw new \Exception("Field {$field} must be between {$min} and {$max}", 400);
        }

        return $intValue;
    }

    private function validatePublicUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \Exception('Invalid URL format', 400);
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \Exception('URL must use http or https scheme', 400);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \Exception('User info in URL is not allowed', 400);
        }

        $host = strtolower($parts['host']);
        if ($host === 'localhost' || $host === '127.0.0.1') {
            throw new \Exception('URL must not target localhost', 400);
        }

        $ips = $this->resolveHostIps($host);

        if (empty($ips)) {
            throw new \Exception('Unable to resolve host', 400);
        }

        foreach ($ips as $ip) {
            if ($this->isPrivateOrReservedIp($ip)) {
                throw new \Exception('URL resolves to a private or reserved address', 400);
            }
        }

        return $url;
    }

    private function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
            return [$host];
        }

        $ips = [];

        if (function_exists('dns_get_record')) {
            $types = DNS_A;
            if (defined('DNS_AAAA')) {
                $types |= DNS_AAAA;
            }

            $records = @dns_get_record($host, $types);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip'])) {
                        $ips[] = $record['ip'];
                    }
                    if (isset($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        if (empty($ips)) {
            $ipv4 = @gethostbynamel($host);
            if (is_array($ipv4)) {
                $ips = array_merge($ips, $ipv4);
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return true;
    }
}

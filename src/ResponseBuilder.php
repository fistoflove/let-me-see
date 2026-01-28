<?php

namespace LetMeSee;

class ResponseBuilder
{
    /**
     * Build a success response
     * 
     * @param string $jobId Job identifier
     * @param array $screenshots Array of screenshot data
     * @param bool $includeBase64 Whether to include base64 encoded images
     * @return array Response data
     */
    public function buildSuccess(string $jobId, array $screenshots, bool $includeBase64 = false): array
    {
        $response = [
            'success' => true,
            'jobId' => $jobId,
            'screenshots' => []
        ];

        $response['screenshots'] = $screenshots;

        return $response;
    }

    /**
     * Build an error response
     * 
     * @param string $message Error message
     * @param int $code Error code
     * @return array Response data
     */
    public function buildError(string $message, int $code = 500): array
    {
        return [
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => $code
            ]
        ];
    }

    /**
     * Send JSON response and exit
     * 
     * @param array $data Response data
     * @param int $httpCode HTTP status code
     */
    public function send(array $data, int $httpCode = 200): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Send CORS headers
     */
    public function sendCorsHeaders(): void
    {
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 86400');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
            }

            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
                header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
            }

            exit(0);
        }
    }
}

#!/usr/bin/env php
<?php
/**
 * Performance benchmark for Let Me See service
 * Tests the difference between cold start and warm requests
 */

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

if (file_exists(__DIR__ . '/.env')) {
    Dotenv::createImmutable(__DIR__)->safeLoad();
}

$baseUrl = rtrim(
    getenv('BENCHMARK_URL')
        ?: ($_ENV['BENCHMARK_URL'] ?? $_ENV['SERVICE_URL'] ?? $_ENV['APP_URL'] ?? 'http://127.0.0.1:8080'),
    '/'
);

if (str_ends_with($baseUrl, '/render')) {
    $renderUrl = $baseUrl;
    $statusUrl = preg_replace('/\/render$/', '/status', $renderUrl);
} else {
    $renderUrl = $baseUrl . '/render';
    $statusUrl = $baseUrl . '/status';
}

$token = getenv('BEARER_TOKEN') ?: ($_ENV['BEARER_TOKEN'] ?? null);

$payload = [
    'html' => '<div style="padding: 40px; text-align: center;"><h1>Performance Test</h1><p>Testing render speed</p></div>',
    'css' => 'body { background: linear-gradient(135deg, #667eea, #764ba2); color: white; font-family: Arial; }',
    'resolutions' => [
        ['width' => 375, 'height' => 667, 'label' => 'mobile'],
        ['width' => 1920, 'height' => 1080, 'label' => 'desktop']
    ]
];

function makeRequest(string $url, ?string $token, array $payload): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_values(array_filter([
        'Content-Type: application/json',
        $token ? 'Authorization: Bearer ' . $token : null,
    ])));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $start = microtime(true);
    $response = curl_exec($ch);
    $time = microtime(true) - $start;
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'time' => $time,
        'httpCode' => $httpCode,
        'success' => $httpCode === 200,
        'body' => $response,
    ];
}

echo "🚀 Let Me See - Performance Benchmark\n";
echo str_repeat("=", 50) . "\n\n";

echo "Testing endpoint: $renderUrl\n";
echo "Status endpoint:  $statusUrl\n\n";

// First request (cold start)
echo "📊 Request #1 (Cold Start)...\n";
$result1 = makeRequest($renderUrl, $token, $payload);
if ($result1['success']) {
    echo "✓ Success! Time: " . number_format($result1['time'], 3) . "s\n\n";
} else {
    echo "✗ Failed! HTTP " . $result1['httpCode'] . "\n\n";
    exit(1);
}

// Wait a moment
sleep(1);

// Check status after first request
sleep(1);
echo "🔍 Checking Chrome status...\n";
$statusCh = curl_init($statusUrl);
curl_setopt($statusCh, CURLOPT_RETURNTRANSFER, true);
$statusResponse = curl_exec($statusCh);
$statusData = json_decode($statusResponse, true);
if ($statusData && isset($statusData['chrome'])) {
    echo "Chrome alive: " . ($statusData['chrome']['alive'] ? 'YES ✓' : 'NO ✗') . "\n\n";
} else {
    echo "Could not check status\n\n";
}

// Subsequent requests (warm)
$times = [];
for ($i = 2; $i <= 5; $i++) {
    echo "📊 Request #$i (Warm)...\n";
    $result = makeRequest($renderUrl, $token, $payload);
    if ($result['success']) {
        echo "✓ Success! Time: " . number_format($result['time'], 3) . "s\n";
        $times[] = $result['time'];
    } else {
        echo "✗ Failed! HTTP " . $result['httpCode'] . "\n";
    }
    if ($i < 5) sleep(1);
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "📈 Results:\n\n";

$avgWarm = array_sum($times) / count($times);
$improvement = (($result1['time'] - $avgWarm) / $result1['time']) * 100;

echo "Cold Start (1st request):     " . number_format($result1['time'], 3) . "s\n";
echo "Warm Average (2-5 requests):  " . number_format($avgWarm, 3) . "s\n";
echo "Best Warm Request:            " . number_format(min($times), 3) . "s\n";
echo "Speed Improvement:            " . number_format($improvement, 1) . "%\n\n";

if ($improvement > 50) {
    echo "🎉 Chrome Pool is working great!\n";
} elseif ($improvement > 20) {
    echo "✓ Chrome Pool is providing good performance.\n";
} else {
    echo "⚠️  Chrome Pool may not be working optimally.\n";
    echo "   Check if Chrome is staying alive between requests.\n";
}

echo "\n💡 Tip: Run 'curl $statusUrl' to check Chrome status\n";

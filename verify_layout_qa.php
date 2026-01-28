<?php

function test_server() {
    $port = 8082;
    $cmd = "STORAGE_PATH=/tmp/lms_storage php -d variables_order=EGPCS -S 127.0.0.1:$port app.php > /dev/null 2>&1 & echo $!";
    $pid = shell_exec($cmd);
    sleep(2); // Wait for server to start

    $payload = json_encode([
        'html' => '<div style="width: 100%; height: 200px; background: red;"><h1>Layout QA Test</h1></div>',
        'resolutions' => [
            ['width' => 375, 'height' => 812, 'label' => 'mobile-test'],
            ['width' => 1024, 'height' => 768, 'label' => 'tablet-test']
        ],
        'returnBase64' => true,
        'optimizeForLlm' => true
    ]);

    $ch = curl_init("http://127.0.0.1:$port/render");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer your-secret-token-here'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Kill server
    shell_exec("kill $pid");

    if ($httpCode !== 200) {
        echo "Example request failed with code $httpCode\n";
        echo "Response: $response\n";
        exit(1);
    }

    $json = json_decode($response, true);
    
    // Verify Schema
    $first = $json['screenshots'][0];
    
    $checks = [
        'Has viewport object' => isset($first['viewport']) && is_array($first['viewport']),
        'Viewport has ID' => isset($first['viewport']['id']) && $first['viewport']['id'] === 'mobile-test',
        'Viewport has dimensions' => isset($first['viewport']['width']) && isset($first['viewport']['height']),
        'Has HTML' => isset($first['html']) && strpos($first['html'], 'Layout QA Test') !== false,
        'Has Layout Metrics' => isset($first['layout_metrics']) && is_array($first['layout_metrics']),
        'Has Base64 or URL' => isset($first['screenshot_base64']) || isset($first['url']),
    ];

    $failed = false;
    foreach ($checks as $name => $passed) {
        if ($passed) {
            echo "[PASS] $name\n";
        } else {
            echo "[FAIL] $name\n";
            $failed = true;
        }
    }

    if ($failed) {
        echo "\nFull response dump:\n";
        print_r($first);
        exit(1);
    } else {
        echo "\nConfiguration verified successfully!\n";
    }
}

test_server();

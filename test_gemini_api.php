<?php
/**
 * Gemini AI API Test
 */

require_once __DIR__ . '/Connection/ai_config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Gemini API Test</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #1a1a2e; color: #eee; }
        .box { background: #16213e; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
        .success { border-left: 4px solid #00ff88; }
        .error { border-left: 4px solid #ff4444; }
        .info { border-left: 4px solid #4ecdc4; }
        pre { background: #0d0d1a; padding: 15px; border-radius: 5px; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word; }
        h1 { color: #4ecdc4; }
        h2 { color: #a8dadc; }
    </style>
</head>
<body>
    <h1>🤖 Gemini API Test</h1>

    <div class="box info">
        <h2>Configuration</h2>
        <p><strong>Provider:</strong> <?= AI_PROVIDER ?></p>
        <p><strong>API Key:</strong> <?= substr(GEMINI_API_KEY, 0, 15) ?>...<?= substr(GEMINI_API_KEY, -5) ?></p>
        <p><strong>Model:</strong> <?= GEMINI_MODEL ?></p>
        <p><strong>API URL:</strong> <?= GEMINI_API_URL ?></p>
    </div>

    <div class="box">
        <h2>Direct API Test</h2>
        <?php
        $url = GEMINI_API_URL . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY;
        echo "<p><strong>Full URL:</strong> " . htmlspecialchars(substr($url, 0, 100)) . "...</p>";
        
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => 'Say hello in one short sentence.']
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 100
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        echo "<p><strong>HTTP Code:</strong> $httpCode</p>";
        if ($curlError) {
            echo "<p><strong>cURL Error:</strong> $curlError</p>";
        }
        
        echo "<h3>Raw Response:</h3>";
        echo "<pre>" . htmlspecialchars($response) . "</pre>";
        
        $responseData = json_decode($response, true);
        
        if ($httpCode === 200 && isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
            echo '<div class="box success">';
            echo '<h3>✅ SUCCESS! Gemini API is working!</h3>';
            echo '<p><strong>Response:</strong> ' . htmlspecialchars($responseData['candidates'][0]['content']['parts'][0]['text']) . '</p>';
            echo '</div>';
        } else {
            echo '<div class="box error">';
            echo '<h3>❌ API Error</h3>';
            if (isset($responseData['error'])) {
                echo '<p><strong>Error:</strong> ' . htmlspecialchars($responseData['error']['message'] ?? json_encode($responseData['error'])) . '</p>';
            }
            echo '</div>';
        }
        ?>
    </div>

    <div class="box">
        <h2>Test callAI() Function</h2>
        <?php
        $result = callAI('Say hello in one sentence.', 'You are a helpful assistant.', 0.7);
        echo "<h3>Result:</h3>";
        echo "<pre>" . htmlspecialchars(print_r($result, true)) . "</pre>";
        
        if ($result['success']) {
            echo '<div class="box success">';
            echo '<h3>✅ callAI() Working!</h3>';
            echo '<p><strong>Provider:</strong> ' . ($result['provider'] ?? 'unknown') . '</p>';
            echo '<p><strong>Response:</strong> ' . htmlspecialchars($result['data']) . '</p>';
            echo '</div>';
        } else {
            echo '<div class="box error">';
            echo '<h3>❌ callAI() Failed</h3>';
            echo '<p>' . htmlspecialchars($result['error'] ?? 'Unknown error') . '</p>';
            echo '</div>';
        }
        ?>
    </div>
</body>
</html>

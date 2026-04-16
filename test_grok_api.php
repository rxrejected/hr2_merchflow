<?php
/**
 * Direct Grok API Test
 */

require_once __DIR__ . '/Connection/ai_config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Grok API Test</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #1a1a2e; color: #eee; }
        .box { background: #16213e; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
        .success { border-left: 4px solid #00ff88; }
        .error { border-left: 4px solid #ff4444; }
        .info { border-left: 4px solid #4ecdc4; }
        pre { background: #0d0d1a; padding: 15px; border-radius: 5px; overflow-x: auto; }
        h1 { color: #4ecdc4; }
        h2 { color: #a8dadc; }
        .btn { background: #4ecdc4; color: #000; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 5px; }
        .btn:hover { background: #45b7aa; }
    </style>
</head>
<body>
    <h1>🤖 Grok API Test</h1>

    <div class="box info">
        <h2>Configuration</h2>
        <p><strong>API URL:</strong> <?= GROK_API_URL ?></p>
        <p><strong>Model:</strong> <?= GROK_MODEL ?></p>
        <p><strong>API Key:</strong> <?= substr(GROK_API_KEY, 0, 10) ?>...<?= substr(GROK_API_KEY, -10) ?></p>
    </div>

    <?php
    // Test API call
    echo '<div class="box">';
    echo '<h2>Testing API Connection...</h2>';
    
    $ch = curl_init(GROK_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROK_API_KEY
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => GROK_MODEL,
            'messages' => [
                ['role' => 'user', 'content' => 'Say hello in one short sentence.']
            ],
            'max_tokens' => 50,
            'temperature' => 0.7
        ]),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    echo "<p><strong>HTTP Status:</strong> $httpCode</p>";
    
    if ($error) {
        echo '<div class="box error">';
        echo "<p><strong>cURL Error:</strong> $error</p>";
        echo '</div>';
    }
    
    echo '<h3>Raw Response:</h3>';
    echo '<pre>' . htmlspecialchars($response) . '</pre>';
    
    $data = json_decode($response, true);
    
    if ($httpCode === 200 && isset($data['choices'][0]['message']['content'])) {
        echo '<div class="box success">';
        echo '<h3>✅ SUCCESS! API is working!</h3>';
        echo '<p><strong>Response:</strong> ' . htmlspecialchars($data['choices'][0]['message']['content']) . '</p>';
        echo '</div>';
    } else {
        echo '<div class="box error">';
        echo '<h3>❌ API Error</h3>';
        if (isset($data['error'])) {
            echo '<p><strong>Error Type:</strong> ' . ($data['error']['type'] ?? 'Unknown') . '</p>';
            echo '<p><strong>Error Message:</strong> ' . ($data['error']['message'] ?? json_encode($data['error'])) . '</p>';
        }
        echo '</div>';
        
        // Suggestions
        echo '<div class="box info">';
        echo '<h3>💡 Possible Solutions:</h3>';
        echo '<ul>';
        echo '<li>Check if API key is valid at <a href="https://console.x.ai/" target="_blank">https://console.x.ai/</a></li>';
        echo '<li>Verify the model name is correct (try: grok-beta, grok-2, grok-2-mini)</li>';
        echo '<li>Check if your account has API credits</li>';
        echo '</ul>';
        echo '</div>';
    }
    echo '</div>';
    ?>

    <div class="box">
        <h2>Try Different Models</h2>
        <form method="get">
            <button type="submit" name="test_model" value="grok-beta" class="btn">Test grok-beta</button>
            <button type="submit" name="test_model" value="grok-2" class="btn">Test grok-2</button>
            <button type="submit" name="test_model" value="grok-2-mini" class="btn">Test grok-2-mini</button>
            <button type="submit" name="test_model" value="grok-2-latest" class="btn">Test grok-2-latest</button>
        </form>
    </div>

    <?php
    if (isset($_GET['test_model'])) {
        $testModel = $_GET['test_model'];
        echo '<div class="box">';
        echo "<h2>Testing Model: $testModel</h2>";
        
        $ch = curl_init(GROK_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . GROK_API_KEY
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $testModel,
                'messages' => [['role' => 'user', 'content' => 'Say hello']],
                'max_tokens' => 30
            ]),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        echo "<p>HTTP Code: $httpCode</p>";
        echo '<pre>' . htmlspecialchars($response) . '</pre>';
        
        if ($httpCode === 200) {
            echo '<p class="success">✅ Model works!</p>';
        } else {
            echo '<p class="error">❌ Model failed</p>';
        }
        echo '</div>';
    }
    ?>
</body>
</html>

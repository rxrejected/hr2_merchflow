<?php
/**
 * AI Test Script
 * Run this to check if the Gemini API is working
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🧪 AI Connection Test</h2>";

// Check cURL
echo "<p><strong>1. cURL Check:</strong> ";
if (function_exists('curl_init')) {
    echo "✅ cURL is enabled</p>";
} else {
    echo "❌ cURL is NOT enabled. Enable it in php.ini</p>";
    exit;
}

// Check if we can reach Google
echo "<p><strong>2. Internet Check:</strong> ";
$ch = curl_init('https://www.google.com');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$result = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($result) {
    echo "✅ Can reach internet</p>";
} else {
    echo "❌ Cannot reach internet. Error: " . $error . "</p>";
}

// List available models first
echo "<p><strong>3. Available Models:</strong></p>";

$apiKey = 'AIzaSyB3e_upEucti-AqNpw1gEnsAhkg8svW5xI';
$listUrl = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $apiKey;

$ch = curl_init($listUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
$response = curl_exec($ch);
curl_close($ch);

$models = json_decode($response, true);

if (isset($models['models'])) {
    echo "<div style='background: #e7f3ff; padding: 10px; border-radius: 8px; max-height: 200px; overflow-y: auto;'>";
    echo "<ul>";
    foreach ($models['models'] as $model) {
        if (strpos($model['name'], 'gemini') !== false) {
            $methods = implode(', ', $model['supportedGenerationMethods'] ?? []);
            echo "<li><strong>" . $model['name'] . "</strong> - Methods: " . $methods . "</li>";
        }
    }
    echo "</ul></div>";
} else {
    echo "<div style='background: #f8d7da; padding: 10px; border-radius: 8px;'>";
    echo "Error listing models: " . htmlspecialchars(substr($response, 0, 500));
    echo "</div>";
}

// Now test with a working model
echo "<p><strong>4. Gemini API Test:</strong></p>";

// Try different models
$modelsToTry = [
    'gemini-2.0-flash-lite',
    'gemini-2.5-flash-lite', 
    'gemini-flash-lite-latest',
    'gemini-flash-latest',
    'gemini-pro-latest',
];

foreach ($modelsToTry as $modelName) {
    echo "<p>Testing <strong>$modelName</strong>... ";
    
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelName}:generateContent?key=" . $apiKey;
    
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => 'Say "Hello, AI is working!" in one sentence.']
                ]
            ]
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($httpCode === 200 && isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        echo "✅ <strong>WORKING!</strong></p>";
        echo "<div style='background: #d4edda; padding: 15px; border-radius: 8px; margin: 10px 0;'>";
        echo "<strong>Use this model:</strong> <code>$modelName</code><br><br>";
        echo "<strong>AI Response:</strong> " . htmlspecialchars($result['candidates'][0]['content']['parts'][0]['text']);
        echo "</div>";
        
        echo "<h3>✅ Copy this to your ai_config.php line 15:</h3>";
        echo "<pre style='background: #333; color: #0f0; padding: 10px; border-radius: 5px;'>define('GEMINI_MODEL', '$modelName');</pre>";
        break;
    } else {
        $errorMsg = $result['error']['message'] ?? 'Unknown error';
        echo "❌ Failed - " . htmlspecialchars(substr($errorMsg, 0, 100)) . "</p>";
    }
}
?>

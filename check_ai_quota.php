<?php
/**
 * Check Gemini AI API Quota and Status
 * Test if API key is working and check quota limits
 */
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'Super Admin', 'developer'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

require_once 'Connection/ai_config.php';

header('Content-Type: application/json');

// Test prompt
$testPrompt = "Hello, respond with 'OK' if you're working.";

// Call Gemini API
$result = callGeminiAI($testPrompt, "You are a helpful assistant.", 0.5);

// Get rate limit status
$limitStatus = ai_rate_limit_status(ai_get_client_ip());

// Format response
$response = [
    'timestamp' => date('Y-m-d H:i:s'),
    'api_key_configured' => GEMINI_API_KEY !== 'your-gemini-api-key-here',
    'api_key_preview' => substr(GEMINI_API_KEY, 0, 20) . '...',
    'model' => GEMINI_MODEL,
    'test_result' => [
        'success' => $result['success'],
        'cached' => $result['cached'] ?? false,
        'fallback' => $result['fallback'] ?? false,
        'response_preview' => isset($result['data']) ? substr($result['data'], 0, 100) : null,
        'error' => $result['error'] ?? null
    ],
    'rate_limits' => [
        'window' => AI_RATE_LIMIT_WINDOW . ' seconds (' . round(AI_RATE_LIMIT_WINDOW/60) . ' minutes)',
        'max_requests' => AI_RATE_LIMIT_MAX,
        'remaining' => $limitStatus['remaining'],
        'reset_in' => $limitStatus['resetIn'] . ' seconds (' . round($limitStatus['resetIn']/60, 1) . ' minutes)',
        'allowed' => $limitStatus['allowed']
    ],
    'cache' => [
        'ttl' => AI_CACHE_TTL . ' seconds (' . round(AI_CACHE_TTL/60) . ' minutes)',
        'max_tokens' => AI_MAX_OUTPUT_TOKENS
    ],
    'your_ip' => ai_get_client_ip(),
    'temp_dir' => sys_get_temp_dir(),
    'interpretation' => []
];

// Add interpretation
if (!$result['success']) {
    if (isset($result['error'])) {
        $error = $result['error'];
        
        if (stripos($error, 'quota') !== false) {
            $response['interpretation'][] = '❌ GEMINI API QUOTA EXCEEDED - You\'ve reached Google\'s daily/monthly quota limit';
            $response['interpretation'][] = 'Solution: Wait 24 hours or upgrade your Gemini API plan at https://aistudio.google.com/';
        } elseif (stripos($error, 'authorization') !== false || stripos($error, '401') !== false || stripos($error, '403') !== false) {
            $response['interpretation'][] = '❌ API KEY INVALID or BILLING NOT ENABLED';
            $response['interpretation'][] = 'Solution: Check your API key at https://aistudio.google.com/app/apikey';
        } elseif (stripos($error, 'rate') !== false || stripos($error, '429') !== false) {
            $response['interpretation'][] = '⚠️ RATE LIMIT - Too many requests too quickly';
            $response['interpretation'][] = 'Solution: Wait ' . round($limitStatus['resetIn']/60, 1) . ' minutes';
        } else {
            $response['interpretation'][] = '⚠️ API ERROR: ' . $error;
        }
    }
} elseif ($result['fallback'] ?? false) {
    $response['interpretation'][] = '⚠️ LOCAL RATE LIMIT - Using fallback response';
    $response['interpretation'][] = 'Wait ' . round($limitStatus['resetIn']/60, 1) . ' minutes for reset';
} elseif ($result['cached'] ?? false) {
    $response['interpretation'][] = '✓ CACHED RESPONSE - Not consuming quota';
} else {
    $response['interpretation'][] = '✓ API WORKING - Quota OK';
    $response['interpretation'][] = 'Remaining requests: ' . $limitStatus['remaining'] . ' / ' . AI_RATE_LIMIT_MAX;
}

// Check for rate limit files
$tempDir = sys_get_temp_dir();
$rateLimitFiles = glob($tempDir . DIRECTORY_SEPARATOR . 'hr2_ai_rate_*.json');
$cacheFiles = glob($tempDir . DIRECTORY_SEPARATOR . 'hr2_ai_cache_*.json');

$response['files'] = [
    'rate_limit_files' => count($rateLimitFiles),
    'cache_files' => count($cacheFiles)
];

echo json_encode($response, JSON_PRETTY_PRINT);
?>

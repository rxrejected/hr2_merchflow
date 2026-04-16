<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'Super Admin', 'developer'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit();
}

require_once 'Connection/ai_config.php';

header('Content-Type: application/json');

echo json_encode([
    'AI_RATE_LIMIT_WINDOW' => AI_RATE_LIMIT_WINDOW,
    'AI_RATE_LIMIT_MAX' => AI_RATE_LIMIT_MAX,
    'AI_CACHE_TTL' => AI_CACHE_TTL,
    'AI_MAX_OUTPUT_TOKENS' => AI_MAX_OUTPUT_TOKENS,
    'file_path' => __FILE__,
    'real_path' => realpath(__FILE__),
    'ai_config_path' => realpath('Connection/ai_config.php'),
    'file_mtime' => date('Y-m-d H:i:s', filemtime('Connection/ai_config.php')),
], JSON_PRETTY_PRINT);
?>

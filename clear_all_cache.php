<?php
/**
 * Clear PHP OPcache and Rate Limit Files
 */
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'Super Admin', 'developer'])) {
    http_response_code(403);
    die('Forbidden: Admin access required.');
}

// Clear OPcache if available
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✓ OPcache cleared\n";
} else {
    echo "⚠ OPcache not available\n";
}

// Clear all AI rate limit files
$temp = sys_get_temp_dir();
$files = glob($temp . DIRECTORY_SEPARATOR . 'hr2_ai_*');

echo "\nTemp directory: $temp\n";
echo "Files found: " . count($files) . "\n\n";

if ($files) {
    foreach ($files as $file) {
        if (unlink($file)) {
            echo "✓ Deleted: " . basename($file) . "\n";
        } else {
            echo "✗ Failed to delete: " . basename($file) . "\n";
        }
    }
} else {
    echo "No HR2 AI files to delete\n";
}

echo "\n✓ All caches cleared!\n";
echo "\nNow reload check_ai_quota.php to test again.\n";
?>

<?php
/**
 * Database Migration: Add device_token and device_fingerprint columns
 * Run this once to update the user_devices table
 */
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'Super Admin', 'developer'])) {
    http_response_code(403);
    die('Forbidden: Admin access required.');
}

require_once 'Connection/Config.php';

echo "<h2>HR2 Device Token Migration</h2>";

// Check if columns already exist
$result = $conn->query("SHOW COLUMNS FROM user_devices LIKE 'device_token'");
$tokenExists = $result && $result->num_rows > 0;

$result = $conn->query("SHOW COLUMNS FROM user_devices LIKE 'device_fingerprint'");
$fingerprintExists = $result && $result->num_rows > 0;

$success = true;
$messages = [];

// Add device_token column if not exists
if (!$tokenExists) {
    $sql = "ALTER TABLE user_devices ADD COLUMN device_token VARCHAR(255) NULL AFTER device_hash";
    if ($conn->query($sql)) {
        $messages[] = "✅ Added device_token column";
    } else {
        $messages[] = "❌ Failed to add device_token: " . $conn->error;
        $success = false;
    }
} else {
    $messages[] = "ℹ️ device_token column already exists";
}

// Add device_fingerprint column if not exists
if (!$fingerprintExists) {
    $sql = "ALTER TABLE user_devices ADD COLUMN device_fingerprint VARCHAR(255) NULL AFTER device_token";
    if ($conn->query($sql)) {
        $messages[] = "✅ Added device_fingerprint column";
    } else {
        $messages[] = "❌ Failed to add device_fingerprint: " . $conn->error;
        $success = false;
    }
} else {
    $messages[] = "ℹ️ device_fingerprint column already exists";
}

// Add indexes (ignore errors if they already exist)
$conn->query("ALTER TABLE user_devices ADD INDEX idx_device_token (device_token)");
$conn->query("ALTER TABLE user_devices ADD INDEX idx_device_fingerprint (device_fingerprint)");
$messages[] = "ℹ️ Indexes created or already exist";

// Display results
echo "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 20px auto; padding: 20px; background: #f8f9fa; border-radius: 8px;'>";
echo "<h3>" . ($success ? "✅ Migration Successful" : "⚠️ Migration had some issues") . "</h3>";
echo "<ul style='list-style: none; padding: 0;'>";
foreach ($messages as $msg) {
    echo "<li style='padding: 8px 0; border-bottom: 1px solid #ddd;'>$msg</li>";
}
echo "</ul>";

// Show current table structure
echo "<h4>Current user_devices table structure:</h4>";
echo "<pre style='background: #1e293b; color: #e2e8f0; padding: 15px; border-radius: 8px; overflow-x: auto;'>";
$result = $conn->query("DESCRIBE user_devices");
if ($result) {
    printf("%-25s %-25s %-10s %-10s\n", "Field", "Type", "Null", "Key");
    echo str_repeat("-", 70) . "\n";
    while ($row = $result->fetch_assoc()) {
        printf("%-25s %-25s %-10s %-10s\n", 
            $row['Field'], 
            $row['Type'], 
            $row['Null'], 
            $row['Key']
        );
    }
}
echo "</pre>";

echo "<p style='margin-top: 20px;'><a href='settings.php' style='color: #dc3545;'>← Back to Settings</a></p>";
echo "</div>";
?>

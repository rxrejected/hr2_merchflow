<?php
/**
 * Update AI Configuration - Web-Based Editor
 * Password: admin123
 */

$password = 'admin123';
$submitted = $_SERVER['REQUEST_METHOD'] === 'POST';
$authenticated = $submitted && isset($_POST['password']) && $_POST['password'] === $password;

if ($submitted && !$authenticated) {
    die('❌ Wrong password');
}

$configFile = __DIR__ . '/Connection/ai_config.php';

if ($authenticated && isset($_POST['action']) && $_POST['action'] === 'update') {
    $newWindow = (int)$_POST['window'];
    $newMax = (int)$_POST['max'];
    $newCacheTTL = (int)$_POST['cache_ttl'];
    $newMaxTokens = (int)$_POST['max_tokens'];
    
    // Read the current file
    $content = file_get_contents($configFile);
    
    // Replace the values using regex
    $content = preg_replace(
        "/define\('AI_RATE_LIMIT_WINDOW',\s*\d+\);/",
        "define('AI_RATE_LIMIT_WINDOW', $newWindow);",
        $content
    );
    $content = preg_replace(
        "/define\('AI_RATE_LIMIT_MAX',\s*\d+\);/",
        "define('AI_RATE_LIMIT_MAX', $newMax);",
        $content
    );
    $content = preg_replace(
        "/define\('AI_CACHE_TTL',\s*\d+\);/",
        "define('AI_CACHE_TTL', $newCacheTTL);",
        $content
    );
    $content = preg_replace(
        "/define\('AI_MAX_OUTPUT_TOKENS',\s*\d+\);/",
        "define('AI_MAX_OUTPUT_TOKENS', $newMaxTokens);",
        $content
    );
    
    // Write the updated content
    if (file_put_contents($configFile, $content)) {
        // Clear OPcache
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        
        // Clear rate limit files
        $temp = sys_get_temp_dir();
        $files = glob($temp . DIRECTORY_SEPARATOR . 'hr2_ai_*');
        foreach ($files as $file) {
            @unlink($file);
        }
        
        echo "✅ Configuration updated successfully!<br>";
        echo "✅ OPcache cleared<br>";
        echo "✅ Rate limit files cleared<br><br>";
        echo "<a href='check_ai_quota.php'>Test AI Quota Now</a><br>";
        echo "<a href='{$_SERVER['PHP_SELF']}'>Update Again</a>";
        exit;
    } else {
        die('❌ Failed to write to config file. Check permissions.');
    }
}

// Load current values
require_once 'Connection/ai_config.php';

?>
<!DOCTYPE html>
<html>
<head>
    <title>AI Config Updater</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; }
        input[type="number"], input[type="password"] { 
            width: 100%; padding: 8px; box-sizing: border-box; 
        }
        button { 
            background: #4CAF50; color: white; padding: 10px 20px; 
            border: none; cursor: pointer; font-size: 16px; 
        }
        button:hover { background: #45a049; }
        .info { background: #e7f3fe; padding: 10px; margin-bottom: 20px; border-left: 4px solid #2196F3; }
        .current { color: #666; font-size: 14px; }
    </style>
</head>
<body>
    <h1>🔧 AI Configuration Updater</h1>
    
    <div class="info">
        <strong>Server File:</strong> <?php echo $configFile; ?><br>
        <strong>Last Modified:</strong> <?php echo date('Y-m-d H:i:s', filemtime($configFile)); ?>
    </div>

    <?php if (!$authenticated): ?>
        <form method="POST">
            <div class="form-group">
                <label>Password:</label>
                <input type="password" name="password" required autofocus>
            </div>
            <button type="submit">Login</button>
        </form>
    <?php else: ?>
        <form method="POST">
            <input type="hidden" name="password" value="<?php echo htmlspecialchars($password); ?>">
            <input type="hidden" name="action" value="update">
            
            <div class="form-group">
                <label>Rate Limit Window (seconds):</label>
                <span class="current">Current: <?php echo AI_RATE_LIMIT_WINDOW; ?> seconds</span>
                <input type="number" name="window" value="3600" required>
                <small>3600 = 1 hour, 7200 = 2 hours</small>
            </div>
            
            <div class="form-group">
                <label>Rate Limit Max Requests:</label>
                <span class="current">Current: <?php echo AI_RATE_LIMIT_MAX; ?> requests</span>
                <input type="number" name="max" value="500" required>
                <small>Maximum requests allowed per window</small>
            </div>
            
            <div class="form-group">
                <label>Cache TTL (seconds):</label>
                <span class="current">Current: <?php echo AI_CACHE_TTL; ?> seconds</span>
                <input type="number" name="cache_ttl" value="1800" required>
                <small>1800 = 30 minutes</small>
            </div>
            
            <div class="form-group">
                <label>Max Output Tokens:</label>
                <span class="current">Current: <?php echo AI_MAX_OUTPUT_TOKENS; ?> tokens</span>
                <input type="number" name="max_tokens" value="2000" required>
                <small>Higher = longer responses</small>
            </div>
            
            <button type="submit">💾 Update Configuration</button>
        </form>
    <?php endif; ?>
</body>
</html>

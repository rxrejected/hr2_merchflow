<?php
echo "PHP Temp Dir: " . sys_get_temp_dir() . "\n";
echo "Directory Separator: " . DIRECTORY_SEPARATOR . "\n";

// Check if the directory exists and is writable
$temp = sys_get_temp_dir();
echo "Exists: " . (is_dir($temp) ? 'Yes' : 'No') . "\n";
echo "Writable: " . (is_writable($temp) ? 'Yes' : 'No') . "\n";

// List HR2 AI files
$files = glob($temp . DIRECTORY_SEPARATOR . 'hr2_ai_*');
echo "\nHR2 AI Files found: " . count($files) . "\n";
if ($files) {
    foreach ($files as $file) {
        echo "  - " . basename($file) . "\n";
        $content = @file_get_contents($file);
        echo "    Content: " . $content . "\n";
    }
}
?>

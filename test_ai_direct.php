<?php
/**
 * Direct AI Test - No login required
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🔍 Direct AI Insights Test</h2>";

require_once 'Connection/Config.php';
require_once 'Connection/ai_config.php';

// Test 1: Check if AI config is loaded
echo "<p><strong>1. AI Config:</strong> ";
echo "Model = " . GEMINI_MODEL . " ✅</p>";

// Test 2: Simple AI call
echo "<p><strong>2. Direct AI Call:</strong> ";
$testResult = callGeminiAI("Say 'AI is working perfectly!' in one sentence.", AI_SYSTEM_PROMPT_CAREER, 0.5);

if ($testResult['success']) {
    echo "✅ Working!</p>";
    echo "<div style='background: #d4edda; padding: 10px; border-radius: 8px;'>";
    echo htmlspecialchars($testResult['data']);
    echo "</div>";
} else {
    echo "❌ Failed</p>";
    echo "<div style='background: #f8d7da; padding: 10px; border-radius: 8px;'>";
    echo "Error: " . htmlspecialchars($testResult['error']);
    echo "</div>";
}

// Test 3: Check employees in database
echo "<h3>3. Employees in Database:</h3>";
$empQuery = $conn->query("SELECT id, full_name, email, role FROM users WHERE role = 'employee' LIMIT 5");

if ($empQuery && $empQuery->num_rows > 0) {
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Full Name</th><th>Email</th><th>Role</th></tr>";
    while ($row = $empQuery->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . htmlspecialchars($row['full_name']) . "</td>";
        echo "<td>" . htmlspecialchars($row['email']) . "</td>";
        echo "<td>" . htmlspecialchars($row['role']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: orange;'>⚠️ No employees found with role='employee'</p>";
    
    // Show all users instead
    echo "<p>All users in database:</p>";
    $allUsers = $conn->query("SELECT id, full_name, role FROM users LIMIT 10");
    if ($allUsers && $allUsers->num_rows > 0) {
        echo "<ul>";
        while ($row = $allUsers->fetch_assoc()) {
            echo "<li>ID: {$row['id']}, Name: {$row['full_name']}, Role: {$row['role']}</li>";
        }
        echo "</ul>";
    }
}

// Test 4: Test the actual bulk analysis simulation
echo "<h3>4. Simulated Employee Analysis:</h3>";

$testEmployee = [
    'name' => 'Test Employee',
    'position' => 'Merchandiser',
    'department' => 'Merchandising',
    'evaluation_score' => 85,
    'assessment_score' => 78,
    'courses_completed' => 3,
    'training_attended' => 2
];

$analysisResult = analyzeEmployeeSkills($testEmployee);

if ($analysisResult['success']) {
    echo "<div style='background: #d4edda; padding: 15px; border-radius: 8px;'>";
    echo "<strong>✅ AI Analysis Working!</strong><br><br>";
    echo nl2br(htmlspecialchars($analysisResult['data']));
    echo "</div>";
} else {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 8px;'>";
    echo "<strong>❌ Error:</strong> " . htmlspecialchars($analysisResult['error']);
    echo "</div>";
}
?>

<?php
/**
 * HR2 - Employee List API
 */
session_start();
header("Content-Type: application/json");
header('Access-Control-Allow-Methods: GET');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once 'Connection/Config.php';

try {
    // Simple fetch - all employees
    $sql = "SELECT id, full_name, email, job_position, avatar, phone 
            FROM users 
            WHERE role = 'employee' 
            ORDER BY full_name ASC 
            LIMIT 200";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    $employees = [];
    
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $employees,
        'count' => count($employees),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

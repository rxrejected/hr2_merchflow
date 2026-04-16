<?php
/**
 * Evaluations API for HR2
 * Returns evaluation data in JSON format
 */

require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            // Get all evaluations with employee info
            $sql = "
                SELECT 
                    e.id,
                    e.employee_id,
                    e.period,
                    e.due_date,
                    e.status,
                    e.notes,
                    e.overall_score,
                    e.narrative,
                    e.created_at,
                    e.updated_at,
                    u.full_name,
                    u.job_position,
                    u.email,
                    u.avatar
                FROM evaluations e
                JOIN users u ON u.id = e.employee_id
                WHERE u.role = 'employee'
                ORDER BY e.due_date DESC, e.id DESC
            ";
            
            $result = $conn->query($sql);
            $evaluations = [];
            
            while ($row = $result->fetch_assoc()) {
                $evaluations[] = [
                    'id' => $row['id'],
                    'employee_id' => $row['employee_id'],
                    'employee_name' => $row['full_name'],
                    'position' => $row['job_position'],
                    'email' => $row['email'],
                    'avatar' => $row['avatar'],
                    'period' => $row['period'],
                    'due_date' => $row['due_date'],
                    'status' => $row['status'],
                    'overall_score' => $row['overall_score'],
                    'notes' => $row['notes'],
                    'narrative' => $row['narrative'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at']
                ];
            }
            
            echo json_encode([
                'success' => true,
                'data' => $evaluations,
                'count' => count($evaluations)
            ]);
            break;
            
        case 'get':
            // Get single evaluation by ID
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'Invalid ID']);
                exit;
            }
            
            $sql = "
                SELECT 
                    e.*,
                    u.full_name,
                    u.job_position,
                    u.email,
                    u.avatar
                FROM evaluations e
                JOIN users u ON u.id = e.employee_id
                WHERE e.id = ?
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            if (!$row) {
                echo json_encode(['success' => false, 'error' => 'Not found']);
                exit;
            }
            
            echo json_encode([
                'success' => true,
                'data' => $row
            ]);
            break;
            
        case 'stats':
            // Get evaluation statistics
            $sql = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue,
                    AVG(CASE WHEN overall_score IS NOT NULL THEN overall_score END) as avg_score
                FROM evaluations
                WHERE due_date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            ";
            
            $result = $conn->query($sql);
            $stats = $result->fetch_assoc();
            
            echo json_encode([
                'success' => true,
                'data' => $stats
            ]);
            break;
            
        case 'employee':
            // Get evaluations for specific employee
            $emp_id = (int)($_GET['employee_id'] ?? $_SESSION['user_id']);
            
            $sql = "
                SELECT 
                    e.*,
                    u.full_name,
                    u.job_position
                FROM evaluations e
                JOIN users u ON u.id = e.employee_id
                WHERE e.employee_id = ?
                ORDER BY e.due_date DESC
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $emp_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $evaluations = [];
            while ($row = $result->fetch_assoc()) {
                $evaluations[] = $row;
            }
            
            echo json_encode([
                'success' => true,
                'data' => $evaluations,
                'count' => count($evaluations)
            ]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

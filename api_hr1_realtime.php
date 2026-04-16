<?php
/**
 * API: Fetch HR1 Data in Real-Time
 * Endpoint for fetching employees and evaluations from HR1 database
 * For HR2 MerchFlow Integration
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Update session activity
$_SESSION['LAST_ACTIVITY'] = time();

// Include HR1 Database class
require_once __DIR__ . '/Connection/hr1_db.php';

$hr1db = new HR1Database();

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? 'employees';

try {
    switch ($action) {
        case 'employees':
            // Fetch all HR1 employees in real-time
            $search = $_GET['search'] ?? '';
            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 500) : 100;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            
            $result = $hr1db->getEmployees($search, $limit, $offset);
            echo json_encode($result);
            break;
            
        case 'evaluations':
            // Fetch all HR1 evaluations in real-time
            $status = $_GET['status'] ?? 'completed';
            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 200) : 100;
            
            $result = $hr1db->getEvaluations($status, $limit);
            echo json_encode($result);
            break;
            
        case 'stats':
            // Fetch HR1 evaluation statistics
            $result = $hr1db->getEvaluationStats();
            echo json_encode($result);
            break;
            
        case 'employee':
            // Fetch single employee with evaluations
            $employeeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            
            if ($employeeId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid employee ID']);
                break;
            }
            
            $result = $hr1db->getEmployeeWithEvaluations($employeeId);
            echo json_encode($result);
            break;
            
        case 'employee_detail':
            // Fetch full employee personal details (all fields)
            $employeeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            
            if ($employeeId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid employee ID']);
                break;
            }
            
            $result = $hr1db->getEmployeeFullDetails($employeeId);
            echo json_encode($result);
            break;
            
        case 'employee_status_counts':
            // Fetch employee counts grouped by status
            $result = $hr1db->getEmployeeStatusCounts();
            echo json_encode($result);
            break;
            
        case 'combined':
            // Fetch employees with their latest evaluations (for module1_sub1)
            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 200) : 100;
            
            // Get evaluations
            $evalResult = $hr1db->getEvaluations('completed', $limit);
            
            if (!$evalResult['success']) {
                echo json_encode($evalResult);
                break;
            }
            
            // Group evaluations by employee
            $employeeEvaluations = [];
            foreach ($evalResult['data'] as $eval) {
                $empId = $eval['employee_id'];
                if (!isset($employeeEvaluations[$empId])) {
                    $employeeEvaluations[$empId] = [
                        'employee_id' => $empId,
                        'employee_name' => $eval['employee_name'],
                        'employee_email' => $eval['employee_email'],
                        'employee_code' => $eval['employee_code'],
                        'role' => $eval['role'],
                        'department' => $eval['department'],
                        'evaluations' => []
                    ];
                }
                $employeeEvaluations[$empId]['evaluations'][] = $eval;
            }
            
            // Calculate training needs
            $processedData = [];
            $stats = [
                'total_evaluated' => 0,
                'need_soft_skills' => 0,
                'need_hard_skills' => 0,
                'excellent_performers' => 0
            ];
            
            foreach ($employeeEvaluations as $empData) {
                $latestEval = !empty($empData['evaluations']) ? $empData['evaluations'][0] : null;
                $trainingNeeds = ['soft_skills' => [], 'hard_skills' => []];
                
                if ($latestEval) {
                    $score = (float)$latestEval['overall_score'];
                    
                    // Determine training needs based on score
                    if ($score < 70) {
                        $trainingNeeds['soft_skills'][] = 'Customer Service Excellence';
                        $trainingNeeds['soft_skills'][] = 'Communication & Interpersonal Skills';
                        $trainingNeeds['hard_skills'][] = 'Job-Specific Technical Skills';
                    } elseif ($score < 80) {
                        $trainingNeeds['soft_skills'][] = 'Leadership & Teamwork';
                        $trainingNeeds['hard_skills'][] = 'Process Improvement';
                    }
                    
                    // Update stats
                    $stats['total_evaluated']++;
                    if (count($trainingNeeds['soft_skills']) > 0) $stats['need_soft_skills']++;
                    if (count($trainingNeeds['hard_skills']) > 0) $stats['need_hard_skills']++;
                    if ($score >= 90) $stats['excellent_performers']++;
                }
                
                $processedData[] = array_merge($empData, [
                    'latest_evaluation' => $latestEval,
                    'training_needs' => $trainingNeeds
                ]);
            }
            
            echo json_encode([
                'success' => true,
                'data' => $processedData,
                'stats' => $stats,
                'count' => count($processedData),
                'timestamp' => date('Y-m-d H:i:s'),
                'source' => 'HR1_DIRECT_DB'
            ]);
            break;
        
        // ==================================================
        // ONBOARDING ACTIONS - Real-time from HR1 Database
        // ==================================================
        
        case 'onboarding_plans':
            // Fetch all onboarding plans
            $status = $_GET['status'] ?? 'all';
            $archived = isset($_GET['archived']) ? (bool)$_GET['archived'] : false;
            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 500) : 200;
            
            $result = $hr1db->getOnboardingPlans($status, $archived, $limit);
            echo json_encode($result);
            break;
            
        case 'onboarding_stats':
            // Fetch onboarding statistics
            $result = $hr1db->getOnboardingStats();
            echo json_encode($result);
            break;
            
        case 'onboarding_plan':
            // Fetch single plan with tasks
            $planId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
            
            if ($planId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid plan ID']);
                break;
            }
            
            $result = $hr1db->getOnboardingPlan($planId);
            echo json_encode($result);
            break;
            
        case 'onboarding_upcoming':
            // Fetch upcoming start dates
            $days = isset($_GET['days']) ? (int)$_GET['days'] : 14;
            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 50) : 10;
            
            $result = $hr1db->getUpcomingOnboarding($days, $limit);
            echo json_encode($result);
            break;
            
        case 'onboarding_overdue':
            // Fetch overdue tasks
            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 50;
            
            $result = $hr1db->getOverdueTasks($limit);
            echo json_encode($result);
            break;
            
        case 'onboarding_combined':
            // Fetch all onboarding data at once (for dashboard refresh)
            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 500) : 200;
            
            $plans = $hr1db->getOnboardingPlans('all', false, $limit);
            $stats = $hr1db->getOnboardingStats();
            $upcoming = $hr1db->getUpcomingOnboarding(14, 10);
            $overdue = $hr1db->getOverdueTasks(50);
            
            echo json_encode([
                'success' => true,
                'plans' => $plans['success'] ? $plans['data'] : [],
                'stats' => $stats['success'] ? $stats['data'] : [],
                'upcoming' => $upcoming['success'] ? $upcoming['data'] : [],
                'overdue' => $overdue['success'] ? $overdue['data'] : [],
                'timestamp' => date('Y-m-d H:i:s'),
                'source' => 'HR1_DIRECT_DB'
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action: ' . $action]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
} finally {
    $hr1db->close();
}
?>

<?php
/**
 * AI Analysis API Endpoint
 * Handles all AI-related requests for skill analysis and career coaching
 */

// Clean any stray output that might break JSON
ob_start();

// Set error handling to prevent HTML errors from breaking JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Custom error handler to return JSON errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'error' => "Error: $errstr",
        'debug' => "File: $errfile, Line: $errline"
    ]);
    exit;
});

// Catch fatal errors too
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Fatal error: ' . $error['message']
        ]);
    }
});

session_start();
require_once 'Connection/Config.php';
require_once 'Connection/ai_config.php';

// Clean output buffer before sending JSON
ob_end_clean();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$type = $_POST['type'] ?? '';
$userId = $_SESSION['user_id'];

// Handle type-based requests (for succession planning modules)
if ($type === 'succession') {
    handleSuccessionAnalysis();
    exit;
}

switch ($action) {
    case 'analyze_skills':
        handleSkillAnalysis($conn, $userId);
        break;
        
    case 'career_path':
        handleCareerPath($conn, $userId);
        break;
        
    case 'training_recommendations':
        handleTrainingRecommendations($conn, $userId);
        break;
        
    case 'chat':
        handleChat($conn, $userId);
        break;
        
    case 'analyze_employee':
        handleEmployeeAnalysis($conn);
        break;
        
    case 'bulk_analysis':
        handleBulkAnalysis($conn);
        break;

    case 'analyze_hr1_employee':
        handleHR1EmployeeAnalysis();
        break;
        
    case 'bulk_hr1_analysis':
        handleBulkHR1Analysis();
        break;

    case 'clear_ai_cache':
        handleClearAICache();
        break;

    case 'rate_limit_status':
        handleRateLimitStatus();
        break;

    case 'course_analytics':
        handleCourseAnalytics();
        break;

    case 'learning_analytics':
        handleLearningAnalytics();
        break;

    case 'dashboard_analytics':
        handleDashboardAnalytics();
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

/**
 * Return real-time AI rate limit status (no increment)
 */
function handleRateLimitStatus() {
    $status = ai_rate_limit_status(ai_get_client_ip());
    echo json_encode([
        'success' => true,
        'status' => $status,
        'window' => AI_RATE_LIMIT_WINDOW,
        'max' => AI_RATE_LIMIT_MAX
    ]);
}

/**
 * Handle Succession Planning AI Analysis
 * Used by module4_sub1.php and module4_sub2.php
 */
function handleSuccessionAnalysis() {
    $message = $_POST['message'] ?? '';
    
    if (empty($message)) {
        echo json_encode(['success' => false, 'error' => 'Message is required']);
        return;
    }
    
    // System prompt for succession planning
    $systemPrompt = "You are an expert HR Director and Succession Planning Analyst for a merchandising/retail company. 
Your role is to:
1. Analyze employee readiness and make promotion/separation recommendations
2. Identify who should be promoted, who needs development, and who may need to be separated
3. Provide actionable, specific recommendations with employee names when available
4. Assess performance gaps and create practical improvement plans
5. Follow fair labor practices — termination is always a last resort after due process (PIP, counseling, documentation)

Scoring System:
- Scores are BLENDED: 30% HR1 (360° Evaluation) + 70% HR2 (Quiz + Training + Courses + Assessments)
- ≥85% = Promote Ready | 70-84% = Retain & Develop | 50-69% = Development Plan | 35-49% = Performance Watch | <35% = Under Review

Guidelines:
- Be concise and use bullet points
- Use employee names when provided
- Be encouraging but realistic and direct
- For separation recommendations, always include due process steps
- Keep responses focused and actionable (3-5 points per section)
- Use markdown formatting with **bold** for emphasis";

    try {
        // Call AI (uses Groq by default, falls back to Gemini)
        $result = callAI($message, $systemPrompt, 0.7);
        
        if ($result['success']) {
            echo json_encode([
                'success' => true,
                'response' => $result['data'],
                'provider' => $result['provider'] ?? 'unknown',
                'cached' => $result['cached'] ?? false
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => $result['error'] ?? 'AI analysis failed'
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Exception: ' . $e->getMessage()
        ]);
    }
}

/**
 * Handle AI insights for course analytics
 */
function handleCourseAnalytics() {
    $total = intval($_POST['total'] ?? 0);
    $soft = intval($_POST['soft'] ?? 0);
    $hard = intval($_POST['hard'] ?? 0);
    $theoretical = intval($_POST['theoretical'] ?? 0);
    $actual = intval($_POST['actual'] ?? 0);

    $prompt = "Analyze the following course analytics and provide insights and recommendations:\n";
    $prompt .= "Total Courses: {$total}\n";
    $prompt .= "Soft Skills: {$soft}\n";
    $prompt .= "Hard Skills: {$hard}\n";
    $prompt .= "Theoretical: {$theoretical}\n";
    $prompt .= "Actual: {$actual}\n\n";
    $prompt .= "Provide:\n";
    $prompt .= "1. Key observations\n";
    $prompt .= "2. Balance assessment (soft vs hard, theoretical vs actual)\n";
    $prompt .= "3. Suggested next courses to add\n";
    $prompt .= "4. Short action plan to improve course coverage";

    $result = callAI($prompt, AI_SYSTEM_PROMPT_TRAINING, 0.5);
    echo json_encode($result);
}

/**
 * Handle AI insights for learning analytics dashboard (Module 2 Sub 2)
 */
function handleLearningAnalytics() {
    $totalCourses = intval($_POST['total_courses'] ?? 0);
    $totalEnrollments = intval($_POST['total_enrollments'] ?? 0);
    $completed = intval($_POST['completed'] ?? 0);
    $inProgress = intval($_POST['in_progress'] ?? 0);
    $avgCompletion = intval($_POST['avg_completion'] ?? 0);
    $softSkills = intval($_POST['soft_skills'] ?? 0);
    $hardSkills = intval($_POST['hard_skills'] ?? 0);
    $theoretical = intval($_POST['theoretical'] ?? 0);
    $actual = intval($_POST['actual'] ?? 0);
    $totalEmployees = intval($_POST['total_employees'] ?? 0);

    // Calculate completion rate
    $completionRate = $totalEnrollments > 0 ? round(($completed / $totalEnrollments) * 100) : 0;
    $engagementRate = $totalEmployees > 0 ? round(($totalEnrollments / $totalEmployees) * 100) : 0;

    $prompt = "You are an HR Learning & Development expert. Analyze this learning data and provide actionable insights:\n\n";
    $prompt .= "**LEARNING METRICS:**\n";
    $prompt .= "- Total Courses Available: {$totalCourses}\n";
    $prompt .= "- Total Enrollments: {$totalEnrollments}\n";
    $prompt .= "- Completed: {$completed} ({$completionRate}% completion rate)\n";
    $prompt .= "- In Progress: {$inProgress}\n";
    $prompt .= "- Average Completion: {$avgCompletion}%\n";
    $prompt .= "- Total Employees: {$totalEmployees}\n";
    $prompt .= "- Engagement Rate: {$engagementRate}%\n\n";
    
    $prompt .= "**COURSE DISTRIBUTION:**\n";
    $prompt .= "- Soft Skills: {$softSkills} courses\n";
    $prompt .= "- Hard Skills: {$hardSkills} courses\n";
    $prompt .= "- Theoretical: {$theoretical} courses\n";
    $prompt .= "- Practical/Actual: {$actual} courses\n\n";
    
    $prompt .= "Provide a comprehensive analysis with:\n";
    $prompt .= "1. **Performance Summary** - Overall learning health assessment\n";
    $prompt .= "2. **Key Insights** - What the data tells us (completion trends, engagement patterns)\n";
    $prompt .= "3. **Areas of Concern** - Identify potential issues (low completion, skill gaps)\n";
    $prompt .= "4. **Recommendations** - Specific actions to improve learning outcomes\n";
    $prompt .= "5. **Quick Wins** - Immediate steps that can boost engagement\n\n";
    $prompt .= "Keep the response concise but insightful. Use bullet points where appropriate.";

    $result = callAI($prompt, AI_SYSTEM_PROMPT_TRAINING, 0.6);
    echo json_encode($result);
}

/**
 * Handle skill analysis for the logged-in user
 */
function handleSkillAnalysis($conn, $userId) {
    // Get employee data
    $employeeData = getEmployeeData($conn, $userId);
    
    if (!$employeeData) {
        echo json_encode(['success' => false, 'error' => 'Employee data not found']);
        return;
    }
    
    $result = analyzeEmployeeSkills($employeeData);
    echo json_encode($result);
}

/**
 * Handle career path recommendations
 */
function handleCareerPath($conn, $userId) {
    $careerGoal = $_POST['career_goal'] ?? '';
    $employeeData = getEmployeeData($conn, $userId);
    
    if (!$employeeData) {
        echo json_encode(['success' => false, 'error' => 'Employee data not found']);
        return;
    }
    
    $result = getCareerPathRecommendations($employeeData, $careerGoal);
    echo json_encode($result);
}

/**
 * Handle training recommendations
 */
function handleTrainingRecommendations($conn, $userId) {
    $skillGaps = $_POST['skill_gaps'] ?? '';
    $currentRole = $_POST['current_role'] ?? 'Merchandiser';
    
    // If no skill gaps provided, generate from employee data
    if (empty($skillGaps)) {
        $employeeData = getEmployeeData($conn, $userId);
        $skillGaps = [];
        
        if ($employeeData['evaluation_score'] < 70) {
            $skillGaps[] = "Performance improvement needed";
        }
        if ($employeeData['assessment_score'] < 70) {
            $skillGaps[] = "Technical knowledge gaps";
        }
        if ($employeeData['courses_completed'] < 3) {
            $skillGaps[] = "Limited course completion";
        }
        if ($employeeData['training_attended'] < 2) {
            $skillGaps[] = "Insufficient training participation";
        }
        
        if (empty($skillGaps)) {
            $skillGaps[] = "General skill enhancement for career growth";
        }
    }
    
    $result = getTrainingRecommendations($skillGaps, $currentRole);
    echo json_encode($result);
}

/**
 * Handle chat with AI Career Coach (supports real-time conversation)
 */
function handleChat($conn, $userId) {
    $message = $_POST['message'] ?? '';
    
    if (empty($message)) {
        echo json_encode(['success' => false, 'error' => 'Message is required']);
        return;
    }
    
    // Get conversation history from request
    $conversationHistory = [];
    if (!empty($_POST['history'])) {
        $historyData = json_decode($_POST['history'], true);
        if (is_array($historyData)) {
            $conversationHistory = $historyData;
        }
    }
    
    $employeeData = getEmployeeData($conn, $userId);
    $result = chatWithCareerCoach($message, $employeeData, $conversationHistory);
    echo json_encode($result);
}

/**
 * Handle analysis for a specific employee (admin only)
 */
function handleEmployeeAnalysis($conn) {
    if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'manager' && $_SESSION['role'] !== 'Super Admin') {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        return;
    }
    
    $employeeId = $_POST['employee_id'] ?? '';
    
    if (empty($employeeId)) {
        echo json_encode(['success' => false, 'error' => 'Employee ID is required']);
        return;
    }
    
    $employeeData = getEmployeeData($conn, $employeeId);
    
    if (!$employeeData) {
        echo json_encode(['success' => false, 'error' => 'Employee not found']);
        return;
    }
    
    $result = analyzeEmployeeSkills($employeeData);
    echo json_encode($result);
}

/**
 * Handle bulk analysis for multiple employees (admin only)
 */
function handleBulkAnalysis($conn) {
    if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'manager' && $_SESSION['role'] !== 'Super Admin') {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        return;
    }
    
    $limit = intval($_POST['limit'] ?? 10);
    
    // Get employees with their data
    $query = "SELECT u.id, u.full_name, u.email, u.job_position 
              FROM users u 
              WHERE u.role = 'employee' 
              LIMIT ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $analyses = [];
    
    while ($row = $result->fetch_assoc()) {
        $employeeData = getEmployeeData($conn, $row['id']);
        
        // Create a summary prompt for efficiency
        $prompt = "Quickly assess this employee (one paragraph):\n";
        $prompt .= "Name: " . $employeeData['name'] . "\n";
        $prompt .= "Evaluation: " . ($employeeData['evaluation_score'] ?? 'N/A') . "%\n";
        $prompt .= "Assessment: " . ($employeeData['assessment_score'] ?? 'N/A') . "%\n";
        $prompt .= "Courses: " . ($employeeData['courses_completed'] ?? 0) . "\n";
        $prompt .= "Provide: Promotion readiness (%), top strength, main area to improve.";
        
        $aiResult = callOpenAI($prompt, AI_SYSTEM_PROMPT_SKILLS, 0.5);
        
        $analyses[] = [
            'employee' => $employeeData,
            'analysis' => $aiResult['success'] ? $aiResult['data'] : $aiResult['error']
        ];
    }
    
    echo json_encode(['success' => true, 'analyses' => $analyses]);
}

/**
 * Get comprehensive employee data for AI analysis
 */
function getEmployeeData($conn, $userId) {
    // Get user info
    $userQuery = "SELECT id, full_name, email, role, job_position, avatar FROM users WHERE id = ?";
    $stmt = $conn->prepare($userQuery);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    
    if (!$user) {
        return null;
    }
    
    // Initialize default values
    $evalScore = 0;
    $assessScore = 0;
    $coursesCompleted = 0;
    $trainingAttended = 0;
    $promoStatus = 'none';
    
    // Get latest evaluation score from evaluations table (not assessment - removed)
    try {
        $evalQuery = "SELECT customer_service, cash_handling, inventory, teamwork, attendance 
                      FROM evaluations WHERE employee_id = ? ORDER BY created_at DESC LIMIT 1";
        $stmt = @$conn->prepare($evalQuery);
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $eval = $stmt->get_result()->fetch_assoc();
            if ($eval) {
                // Convert enum values to scores
                $ratingMap = ['Excellent' => 100, 'Good' => 80, 'Average' => 60, 'Poor' => 40];
                $scores = [
                    $ratingMap[$eval['customer_service']] ?? 0,
                    $ratingMap[$eval['cash_handling']] ?? 0,
                    $ratingMap[$eval['inventory']] ?? 0,
                    $ratingMap[$eval['teamwork']] ?? 0,
                    $ratingMap[$eval['attendance']] ?? 0
                ];
                $evalScore = round(array_sum($scores) / 5, 2);
            }
        }
    } catch (Exception $e) {
        $evalScore = 0;
    }
    
    // No assessment_answers table - use evalScore as fallback
    $assessScore = $evalScore;
    
    // Try to get courses completed if table exists
    try {
        $courseQuery = "SELECT COUNT(*) as completed FROM course_progress WHERE employee_id = ? AND watched_percent = 100";
        $stmt = @$conn->prepare($courseQuery);
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $courses = $stmt->get_result()->fetch_assoc();
            $coursesCompleted = $courses['completed'] ?? 0;
        }
    } catch (Exception $e) {
        $coursesCompleted = 0;
    }
    
    // Try to get training attended if table exists
    try {
        $trainingQuery = "SELECT COUNT(*) as attended FROM training_attendance WHERE user_id = ? AND attended = 'Yes'";
        $stmt = @$conn->prepare($trainingQuery);
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $training = $stmt->get_result()->fetch_assoc();
            $trainingAttended = $training['attended'] ?? 0;
        }
    } catch (Exception $e) {
        $trainingAttended = 0;
    }
    
    // Try to get promotion status if table exists
    try {
        $promoQuery = "SELECT status FROM promotions WHERE employee_id = ? ORDER BY created_at DESC LIMIT 1";
        $stmt = @$conn->prepare($promoQuery);
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $promo = $stmt->get_result()->fetch_assoc();
            $promoStatus = $promo['status'] ?? 'none';
        }
    } catch (Exception $e) {
        $promoStatus = 'none';
    }
    
    return [
        'id' => $user['id'],
        'name' => $user['full_name'],
        'email' => $user['email'],
        'position' => $user['job_position'] ?? 'Merchandiser',
        'department' => 'Merchandising',
        'evaluation_score' => $evalScore,
        'assessment_score' => $assessScore ?: $evalScore,
        'courses_completed' => $coursesCompleted,
        'training_attended' => $trainingAttended,
        'promotion_status' => $promoStatus
    ];
}

/**
 * Handle analysis for HR1 employee data
 */
function handleHR1EmployeeAnalysis() {
    if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'manager' && $_SESSION['role'] !== 'Super Admin') {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        return;
    }
    
    $employeeId = $_POST['employee_id'] ?? '';
    $employeeName = $_POST['employee_name'] ?? 'Unknown';
    $score = floatval($_POST['score'] ?? 0);
    $role = $_POST['role'] ?? 'Employee';
    
    if (empty($employeeId)) {
        echo json_encode(['success' => false, 'error' => 'Employee ID is required']);
        return;
    }
    
    // Get more data from HR1 if available
    require_once 'Connection/hr1_integration.php';
    $hr1Service = new HR1IntegrationService();
    $hr1Response = $hr1Service->getEvaluations(true);
    
    $employeeEvals = [];
    $latestCriteria = [];
    $evalHistory = '';
    $criteriaTable = '';
    
    if ($hr1Response['success']) {
        foreach ($hr1Response['data'] as $eval) {
            if ($eval['employee_id'] == $employeeId) {
                $employeeEvals[] = $eval;
                // Get latest criteria scores
                if (!empty($eval['criteria_scores']) && empty($latestCriteria)) {
                    $latestCriteria = $eval['criteria_scores'];
                }
            }
        }
        
        // Build evaluation history table
        if (!empty($employeeEvals)) {
            $evalHistory = "\n\n**EVALUATION HISTORY:**\n";
            $evalHistory .= "| Period | Score | Status |\n";
            $evalHistory .= "|--------|-------|--------|\n";
            foreach ($employeeEvals as $ev) {
                $evalScore = $ev['overall_score'] ?? 'N/A';
                $evalPeriod = $ev['period'] ?? 'N/A';
                $evalStatus = $ev['status'] ?? 'N/A';
                $evalHistory .= "| {$evalPeriod} | {$evalScore}/5 | {$evalStatus} |\n";
            }
        }
        
        // Build criteria breakdown table if available
        if (!empty($latestCriteria)) {
            $criteriaTable = "\n\n**DETAILED CRITERIA BREAKDOWN (Latest Evaluation):**\n";
            $criteriaTable .= "| Criteria | Score | Weight | Performance |\n";
            $criteriaTable .= "|----------|-------|--------|-------------|\n";
            foreach ($latestCriteria as $key => $data) {
                $critScore = $data['score'] ?? 0;
                $critLabel = $data['label'] ?? ucfirst($key);
                $critWeight = $data['weight'] ?? 0;
                $perfLevel = $critScore >= 4 ? 'Excellent' : ($critScore >= 3 ? 'Good' : 'Needs Improvement');
                $criteriaTable .= "| {$critLabel} | {$critScore}/5 | {$critWeight}% | {$perfLevel} |\n";
            }
        }
    }
    
    // Determine performance level
    $performanceLevel = 'Needs Improvement';
    if ($score >= 4.5) { $performanceLevel = 'Outstanding'; }
    elseif ($score >= 4) { $performanceLevel = 'Excellent'; }
    elseif ($score >= 3.5) { $performanceLevel = 'Very Good'; }
    elseif ($score >= 3) { $performanceLevel = 'Good'; }
    elseif ($score >= 2.5) { $performanceLevel = 'Fair'; }
    
    // Build professional prompt
    $prompt = "You are a professional HR analyst. Analyze this employee's evaluation data and provide a comprehensive, well-structured assessment.\n\n";
    
    $prompt .= "**EMPLOYEE PROFILE:**\n";
    $prompt .= "| Field | Value |\n";
    $prompt .= "|-------|-------|\n";
    $prompt .= "| Name | {$employeeName} |\n";
    $prompt .= "| Position | {$role} |\n";
    $prompt .= "| Overall Score | {$score}/5 |\n";
    $prompt .= "| Performance Level | {$performanceLevel} |\n";
    $prompt .= "| Total Evaluations | " . count($employeeEvals) . " |\n";
    $prompt .= $evalHistory;
    $prompt .= $criteriaTable;
    
    $prompt .= "\n\n**PROVIDE YOUR ANALYSIS IN THE FOLLOWING FORMAT:**\n\n";
    
    $prompt .= "## 1. EXECUTIVE SUMMARY\n";
    $prompt .= "Provide a 2-3 sentence overview of the employee's overall performance and potential.\n\n";
    
    $prompt .= "## 2. PERFORMANCE ANALYSIS\n";
    $prompt .= "Create a table showing:\n";
    $prompt .= "| Aspect | Rating | Analysis |\n";
    $prompt .= "Include: Overall Performance, Consistency, Growth Trajectory\n\n";
    
    $prompt .= "## 3. KEY STRENGTHS\n";
    $prompt .= "List 3 specific strengths based on the criteria scores. Use bullet points.\n\n";
    
    $prompt .= "## 4. AREAS FOR DEVELOPMENT\n";
    $prompt .= "Identify 2-3 areas that need improvement. Be constructive and specific.\n\n";
    
    $prompt .= "## 5. TRAINING RECOMMENDATIONS\n";
    $prompt .= "Create a table:\n";
    $prompt .= "| Training Program | Purpose | Priority |\n";
    $prompt .= "Suggest 2-3 relevant training programs.\n\n";
    
    $prompt .= "## 6. CAREER DEVELOPMENT\n";
    $prompt .= "| Metric | Assessment |\n";
    $prompt .= "|--------|------------|\n";
    $prompt .= "| Promotion Readiness | __% |\n";
    $prompt .= "| Estimated Timeline | __ months |\n";
    $prompt .= "| Recommended Next Role | _______ |\n\n";
    
    $prompt .= "## 7. ACTION PLAN\n";
    $prompt .= "| Timeframe | Goal | Action Items |\n";
    $prompt .= "|-----------|------|-------------|\n";
    $prompt .= "| 30 Days | ... | ... |\n";
    $prompt .= "| 60 Days | ... | ... |\n";
    $prompt .= "| 90 Days | ... | ... |\n\n";
    
    $prompt .= "**IMPORTANT GUIDELINES:**\n";
    $prompt .= "- Use professional English throughout\n";
    $prompt .= "- Use markdown tables where specified\n";
    $prompt .= "- Be specific and data-driven in your analysis\n";
    $prompt .= "- Keep tone professional but supportive\n";
    $prompt .= "- Base recommendations on the actual scores provided\n";
    
    $result = callAI($prompt, AI_SYSTEM_PROMPT_SKILLS, 0.7);
    
    // Add metadata to response
    if ($result['success']) {
        $result['employee'] = [
            'id' => $employeeId,
            'name' => $employeeName,
            'role' => $role,
            'score' => $score,
            'performance_level' => $performanceLevel,
            'eval_count' => count($employeeEvals),
            'criteria' => $latestCriteria
        ];
    }
    
    echo json_encode($result);
}

/**
 * Handle bulk analysis for all HR1 employees
 */
function handleBulkHR1Analysis() {
    if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'manager' && $_SESSION['role'] !== 'Super Admin') {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        return;
    }
    
    require_once 'Connection/hr1_integration.php';
    $hr1Service = new HR1IntegrationService();
    $hr1Response = $hr1Service->getEvaluations(true);
    
    if (!$hr1Response['success']) {
        echo json_encode(['success' => false, 'error' => 'Failed to fetch HR1 data: ' . ($hr1Response['error'] ?? 'Unknown error')]);
        return;
    }
    
    // Group by employee with criteria
    $employees = [];
    foreach ($hr1Response['data'] as $eval) {
        $empId = $eval['employee_id'];
        if (!isset($employees[$empId])) {
            $employees[$empId] = [
                'id' => $empId,
                'name' => $eval['employee_name'],
                'email' => $eval['employee_email'],
                'role' => $eval['role'] ?? 'Employee',
                'score' => $eval['overall_score'] ?? 0,
                'criteria' => $eval['criteria_scores'] ?? [],
                'eval_count' => 0
            ];
        }
        $employees[$empId]['eval_count']++;
        // Keep latest score and criteria
        if (!empty($eval['overall_score'])) {
            $employees[$empId]['score'] = $eval['overall_score'];
        }
        if (!empty($eval['criteria_scores'])) {
            $employees[$empId]['criteria'] = $eval['criteria_scores'];
        }
    }
    
    $limit = min(intval($_POST['limit'] ?? 10), 20);
    $employees = array_slice(array_values($employees), 0, $limit);
    
    $analyses = [];
    
    foreach ($employees as $emp) {
        // Build criteria summary table
        $criteriaInfo = '';
        if (!empty($emp['criteria'])) {
            $criteriaInfo = "\n| Criteria | Score |\n|----------|-------|\n";
            foreach ($emp['criteria'] as $key => $data) {
                $criteriaInfo .= "| {$data['label']} | {$data['score']}/5 |\n";
            }
        }
        
        $score = floatval($emp['score']);
        $perfLevel = $score >= 4 ? 'Excellent' : ($score >= 3 ? 'Good' : 'Needs Improvement');
        
        $prompt = "Provide a detailed professional assessment for this employee:\n\n";
        $prompt .= "| Field | Value |\n";
        $prompt .= "|-------|-------|\n";
        $prompt .= "| Name | {$emp['name']} |\n";
        $prompt .= "| Role | {$emp['role']} |\n";
        $prompt .= "| Score | {$emp['score']}/5 ({$perfLevel}) |\n";
        $prompt .= "| Evaluations | {$emp['eval_count']} |\n";
        $prompt .= $criteriaInfo;
        $prompt .= "\nProvide a comprehensive analysis covering:\n\n";
        $prompt .= "**Promotion Readiness:** X% - explain why based on scores\n";
        $prompt .= "**Key Strengths:** List 2-3 specific strengths with evidence from scores\n";
        $prompt .= "**Development Areas:** 2 specific areas to improve with actionable steps\n";
        $prompt .= "**Training Recommendations:** Suggest 2 relevant training programs\n";
        $prompt .= "**Career Path:** Recommended next role and estimated timeline\n";
        $prompt .= "**Action Items:** 3 prioritized next steps\n\n";
        $prompt .= "Use professional English. Be specific and reference the actual scores provided. Use bullet points and clear formatting.";
        
        $aiResult = callAI($prompt, AI_SYSTEM_PROMPT_SKILLS, 0.5);
        
        $analyses[] = [
            'employee' => [
                'id' => $emp['id'],
                'name' => $emp['name'],
                'role' => $emp['role'],
                'score' => $emp['score'],
                'eval_count' => $emp['eval_count']
            ],
            'analysis' => $aiResult['success'] ? $aiResult['data'] : ($aiResult['error'] ?? 'Analysis unavailable')
        ];
    }
    
    echo json_encode(['success' => true, 'analyses' => $analyses, 'total' => count($employees)]);
}

/**
 * Clear AI cache and rate limits
 */
function handleClearAICache() {
    if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'Super Admin') {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        return;
    }
    
    $cleared = ai_clear_rate_limits();
    
    // Also clear cache files
    $tempDir = sys_get_temp_dir();
    $cachePattern = $tempDir . DIRECTORY_SEPARATOR . 'hr2_ai_cache_*.json';
    $cacheCleared = 0;
    foreach (glob($cachePattern) as $file) {
        if (@unlink($file)) $cacheCleared++;
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Cleared $cleared rate limit files and $cacheCleared cache files",
        'rate_limits_cleared' => $cleared,
        'cache_cleared' => $cacheCleared
    ]);
}

/**
 * Dashboard AI Analytics - comprehensive workforce analysis
 */
function handleDashboardAnalytics() {
    try {
        $inputData = json_decode($_POST['data'] ?? '{}', true);
        
        if (empty($inputData)) {
            echo json_encode(['success' => false, 'error' => 'No data provided']);
            return;
        }
        
        $total = (int)($inputData['totalEmployees'] ?? 0);
        $active = (int)($inputData['activeCount'] ?? 0);
        $probation = (int)($inputData['probationCount'] ?? 0);
        $onboarding = (int)($inputData['onboardingCount'] ?? 0);
        $onLeave = (int)($inputData['onLeaveCount'] ?? 0);
        $inactive = (int)($inputData['inactiveCount'] ?? 0);
        $trainings = (int)($inputData['totalTrainings'] ?? 0);
        $evaluations = (int)($inputData['totalEvaluations'] ?? 0);
        $courses = (int)($inputData['totalCourses'] ?? 0);
        $upcoming = (int)($inputData['upcomingTrainings'] ?? 0);
        
        $prompt = "You are an expert HR analytics AI for OSAVE HR2 MerchFlow system. Analyze this workforce data comprehensively and return a JSON response with NO markdown formatting, NO code blocks, ONLY raw JSON.

Workforce Data:
- Total Employees: $total
- Active Employees: $active (" . ($total > 0 ? round($active/$total*100) : 0) . "%)
- Probation: $probation (" . ($total > 0 ? round($probation/$total*100) : 0) . "%)
- Onboarding: $onboarding
- On Leave: $onLeave
- Inactive: $inactive
- Total Trainings: $trainings, Upcoming: $upcoming
- Total Evaluations: $evaluations
- Total Courses: $courses
- Evaluation-to-Employee Ratio: " . ($total > 0 ? round($evaluations/$total, 1) : 0) . "
- Training-to-Employee Ratio: " . ($total > 0 ? round($trainings/$total, 1) : 0) . "

Analyze the data thoroughly. Consider:
- High inactive/on-leave ratio indicates retention risk
- Low evaluation count vs employees indicates assessment gaps
- Low training count indicates development investment needed
- Probation ratio affects workforce stability

Return this exact JSON structure with intelligent, data-backed values:
{
  \"healthScore\": <number 0-100 based on workforce metrics>,
  \"healthInsight\": \"<detailed 2-sentence summary explaining the health score>\",
  \"riskPercent\": <number 0-100 based on risk factors>,
  \"riskLabel\": \"Low|Medium|High\",
  \"riskInsight\": \"<detailed 2-sentence risk analysis citing specific numbers>\",
  \"skills\": {\"leadership\": <0-100>, \"technical\": <0-100>, \"communication\": <0-100>, \"training\": <0-100>, \"engagement\": <0-100>},
  \"skillsInsight\": \"<detailed 2-sentence skills gap summary>\",
  \"recommendations\": [{\"type\": \"urgent|improve|growth|info\", \"text\": \"<specific, actionable recommendation with metrics>\"}],
  \"recsInsight\": \"<2-sentence recommendation summary>\",
  \"departments\": [{\"name\": \"<dept>\", \"count\": <num>, \"score\": <0-100>, \"color\": \"<hex>\"}],
  \"trends\": {\"labels\": [\"Sep\",\"Oct\",\"Nov\",\"Dec\",\"Jan\",\"Feb\"], \"headcount\": [<nums>], \"predicted\": [<nums>]}
}

Provide at least 4-6 detailed recommendations. Make health and risk scores realistic based on the actual data.";


        // Try AI call using the shared callAI wrapper (from ai_config.php)
        $rateCheck = ai_rate_limit_check('dashboard_analytics_' . date('Y-m-d'));
        
        if ($rateCheck['allowed']) {
            $systemPrompt = 'You are an HR data analytics AI. Always respond with valid JSON only, no markdown or code blocks.';
            $aiResult = callAI($prompt, $systemPrompt, 0.3);
            
            if ($aiResult['success'] && !empty($aiResult['data'])) {
                // Try to parse JSON from response
                $cleanResponse = trim($aiResult['data']);
                // Remove markdown code blocks if present
                $cleanResponse = preg_replace('/^```(?:json)?\s*/m', '', $cleanResponse);
                $cleanResponse = preg_replace('/\s*```$/m', '', $cleanResponse);
                $cleanResponse = trim($cleanResponse);
                
                $parsed = json_decode($cleanResponse, true);
                
                if ($parsed && isset($parsed['healthScore'])) {
                    echo json_encode(['success' => true, 'analytics' => $parsed]);
                    return;
                }
            }
        }
        
        // Fallback: return null to let frontend compute locally
        echo json_encode(['success' => false, 'error' => 'AI unavailable, using local computation']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    }
}

// Note: callGroqAI() and callAI() are provided by Connection/ai_config.php
// No need for a local duplicate here
?>

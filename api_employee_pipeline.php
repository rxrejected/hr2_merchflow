<?php
/**
 * API: Employee Evaluation Pipeline
 * Manages the complete flow: HR1 Eval → Course Assignment → Course Completion → Assessment → HR2 Evaluation
 * 
 * Actions:
 *   GET:  list, stats, details, courses, quiz_results
 *   POST: import_employee, assign_courses, update_stage, save_quiz, evaluate, remove
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

header('Content-Type: application/json');

// Auth check — admin only
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$userRole = strtolower(str_replace(' ', '', $_SESSION['role'] ?? ''));
if (!in_array($userRole, ['admin', 'manager', 'superadmin'])) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

$userId = (int)$_SESSION['user_id'];

// ============================
// GET — Fetch Pipeline Data
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'list';

    // List all pipeline entries (with optional filters)
    if ($action === 'list') {
        $stage = trim($_GET['stage'] ?? '');
        $type = trim($_GET['type'] ?? '');
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? 'active');

        $sql = "SELECT * FROM employee_pipeline WHERE 1=1";
        $params = [];
        $types = '';

        if (!empty($status)) {
            $sql .= " AND status = ?";
            $params[] = $status;
            $types .= 's';
        }
        if (!empty($stage)) {
            $sql .= " AND current_stage = ?";
            $params[] = $stage;
            $types .= 's';
        }
        if (!empty($type)) {
            $sql .= " AND employee_type = ?";
            $params[] = $type;
            $types .= 's';
        }
        if (!empty($search)) {
            $sql .= " AND (hr1_employee_name LIKE ? OR hr1_employee_email LIKE ? OR department LIKE ?)";
            $searchLike = "%{$search}%";
            $params[] = $searchLike;
            $params[] = $searchLike;
            $params[] = $searchLike;
            $types .= 'sss';
        }

        $sql .= " ORDER BY updated_at DESC";

        if (!empty($params)) {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $conn->query($sql);
        }

        $data = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
        }

        echo json_encode(['success' => true, 'data' => $data, 'count' => count($data)]);
        exit();
    }

    // Pipeline statistics
    if ($action === 'stats') {
        $stats = [];

        // Total employees in pipeline
        $r = $conn->query("SELECT COUNT(*) as total FROM employee_pipeline WHERE status = 'active'");
        $stats['total_active'] = $r ? (int)$r->fetch_assoc()['total'] : 0;

        // By stage
        $r = $conn->query("SELECT current_stage, COUNT(*) as cnt FROM employee_pipeline WHERE status = 'active' GROUP BY current_stage");
        $stats['by_stage'] = [];
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $stats['by_stage'][$row['current_stage']] = (int)$row['cnt'];
            }
        }

        // By type
        $r = $conn->query("SELECT employee_type, COUNT(*) as cnt FROM employee_pipeline WHERE status = 'active' GROUP BY employee_type");
        $stats['by_type'] = [];
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $stats['by_type'][$row['employee_type']] = (int)$row['cnt'];
            }
        }

        // Average scores
        $r = $conn->query("SELECT 
            AVG(hr1_eval_score) as avg_hr1,
            AVG(hr2_eval_score) as avg_hr2,
            AVG(combined_score) as avg_combined,
            AVG(course_completion_pct) as avg_course_completion,
            AVG(avg_assessment_score) as avg_quiz_score
            FROM employee_pipeline WHERE status = 'active'");
        if ($r) {
            $stats['averages'] = $r->fetch_assoc();
        }

        // Completion rate
        $r = $conn->query("SELECT COUNT(*) as cnt FROM employee_pipeline WHERE current_stage = 'completed' AND status = 'active'");
        $stats['completed'] = $r ? (int)$r->fetch_assoc()['cnt'] : 0;
        $stats['completion_rate'] = $stats['total_active'] > 0 ? round(($stats['completed'] / $stats['total_active']) * 100, 1) : 0;

        echo json_encode(['success' => true, 'stats' => $stats]);
        exit();
    }

    // Get details for specific employee
    if ($action === 'details') {
        $pipelineId = (int)($_GET['id'] ?? 0);
        if ($pipelineId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid pipeline ID']);
            exit();
        }

        $stmt = $conn->prepare("SELECT * FROM employee_pipeline WHERE id = ?");
        $stmt->bind_param('i', $pipelineId);
        $stmt->execute();
        $pipeline = $stmt->get_result()->fetch_assoc();

        if (!$pipeline) {
            echo json_encode(['success' => false, 'error' => 'Employee not found']);
            exit();
        }

        // Get assigned courses
        $stmt2 = $conn->prepare("
            SELECT ca.*, c.title as course_title, c.description as course_description, 
                   c.video_path, c.skill_type, c.training_type
            FROM course_assignments ca
            LEFT JOIN courses c ON ca.course_id = c.course_id
            WHERE ca.pipeline_id = ?
            ORDER BY ca.created_at ASC
        ");
        $stmt2->bind_param('i', $pipelineId);
        $stmt2->execute();
        $coursesResult = $stmt2->get_result();
        $courses = [];
        while ($row = $coursesResult->fetch_assoc()) {
            $courses[] = $row;
        }

        // Get quiz results
        $stmt3 = $conn->prepare("
            SELECT qr.*, c.title as course_title
            FROM course_quiz_results qr
            LEFT JOIN courses c ON qr.course_id = c.course_id
            WHERE qr.pipeline_id = ?
            ORDER BY qr.created_at DESC
        ");
        $stmt3->bind_param('i', $pipelineId);
        $stmt3->execute();
        $quizResult = $stmt3->get_result();
        $quizzes = [];
        while ($row = $quizResult->fetch_assoc()) {
            $quizzes[] = $row;
        }

        // Get HR2 assessment (if exists)
        $hr2Assessment = null;
        $stmt4 = $conn->prepare("SELECT * FROM hr2_assessments WHERE hr1_employee_id = ? ORDER BY id DESC LIMIT 1");
        $stmt4->bind_param('i', $pipeline['hr1_employee_id']);
        $stmt4->execute();
        $hr2Res = $stmt4->get_result();
        if ($hr2Res->num_rows > 0) {
            $hr2Assessment = $hr2Res->fetch_assoc();
        }

        echo json_encode([
            'success' => true,
            'pipeline' => $pipeline,
            'courses' => $courses,
            'quiz_results' => $quizzes,
            'hr2_assessment' => $hr2Assessment
        ]);
        exit();
    }

    // Get available courses for assignment
    if ($action === 'courses') {
        $result = $conn->query("SELECT course_id, title, description, skill_type, training_type FROM courses ORDER BY title ASC");
        $courses = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $courses[] = $row;
            }
        }
        echo json_encode(['success' => true, 'courses' => $courses]);
        exit();
    }

    // Sync all pipeline entries with actual course_progress data
    if ($action === 'sync_progress') {
        $pipelines = $conn->query("SELECT id FROM employee_pipeline WHERE status = 'active'");
        $synced = 0;
        if ($pipelines) {
            while ($p = $pipelines->fetch_assoc()) {
                recalculatePipelineStats($conn, (int)$p['id']);
                $synced++;
            }
        }
        echo json_encode(['success' => true, 'message' => "Synced {$synced} pipeline entries with course progress", 'synced' => $synced]);
        exit();
    }
}

// ============================
// POST — Pipeline Actions
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Import HR1 employee into pipeline
    if ($action === 'import_employee') {
        $hr1Id = (int)($_POST['hr1_employee_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $dept = trim($_POST['department'] ?? 'Operations');
        $position = trim($_POST['position'] ?? 'Employee');
        $empType = trim($_POST['employee_type'] ?? 'new');
        $dateHired = trim($_POST['date_hired'] ?? '');
        $months = (int)($_POST['months_tenure'] ?? 0);
        $hr1Score = (float)($_POST['hr1_eval_score'] ?? 0);
        $hr1Rating = trim($_POST['hr1_eval_rating'] ?? '');
        $hr1Date = trim($_POST['hr1_eval_date'] ?? date('Y-m-d'));

        if ($hr1Id <= 0 || empty($name)) {
            echo json_encode(['success' => false, 'error' => 'Employee ID and name are required']);
            exit();
        }

        // Check if already exists
        $check = $conn->prepare("SELECT id FROM employee_pipeline WHERE hr1_employee_id = ?");
        $check->bind_param('i', $hr1Id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'error' => 'Employee already in pipeline']);
            exit();
        }

        $stmt = $conn->prepare("
            INSERT INTO employee_pipeline 
            (hr1_employee_id, hr1_employee_name, hr1_employee_email, department, position,
             employee_type, date_hired, months_tenure, hr1_eval_score, hr1_eval_rating, hr1_eval_date,
             current_stage, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'hr1_evaluated', 'active', ?)
        ");
        $stmt->bind_param('issssssidssi',
            $hr1Id, $name, $email, $dept, $position, $empType,
            $dateHired, $months, $hr1Score, $hr1Rating, $hr1Date, $userId
        );

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Employee imported to pipeline', 'id' => $conn->insert_id]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to import: ' . $stmt->error]);
        }
        exit();
    }

    // Assign courses to employee
    if ($action === 'assign_courses') {
        $pipelineId = (int)($_POST['pipeline_id'] ?? 0);
        $courseIds = $_POST['course_ids'] ?? [];
        $dueDate = trim($_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days')));

        if ($pipelineId <= 0 || empty($courseIds)) {
            echo json_encode(['success' => false, 'error' => 'Pipeline ID and course IDs are required']);
            exit();
        }

        // Get employee details
        $pStmt = $conn->prepare("SELECT hr1_employee_id FROM employee_pipeline WHERE id = ?");
        $pStmt->bind_param('i', $pipelineId);
        $pStmt->execute();
        $pResult = $pStmt->get_result()->fetch_assoc();
        if (!$pResult) {
            echo json_encode(['success' => false, 'error' => 'Pipeline record not found']);
            exit();
        }
        $hr1EmpId = $pResult['hr1_employee_id'];

        $assigned = 0;
        $errors = [];
        foreach ($courseIds as $courseId) {
            $stmt = $conn->prepare("
                INSERT IGNORE INTO course_assignments (pipeline_id, hr1_employee_id, course_id, assigned_by, due_date, status)
                VALUES (?, ?, ?, ?, ?, 'assigned')
            ");
            $cId = (int)$courseId;
            $stmt->bind_param('iiiis', $pipelineId, $hr1EmpId, $cId, $userId, $dueDate);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $assigned++;
            }
        }

        // Update pipeline stage
        $conn->query("UPDATE employee_pipeline SET current_stage = 'courses_assigned', 
                       courses_assigned = (SELECT COUNT(*) FROM course_assignments WHERE pipeline_id = {$pipelineId})
                       WHERE id = {$pipelineId} AND current_stage = 'hr1_evaluated'");

        echo json_encode(['success' => true, 'message' => "{$assigned} course(s) assigned", 'assigned' => $assigned]);
        exit();
    }

    // Update pipeline stage manually  
    if ($action === 'update_stage') {
        $pipelineId = (int)($_POST['pipeline_id'] ?? 0);
        $newStage = trim($_POST['stage'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $validStages = ['hr1_evaluated', 'courses_assigned', 'learning', 'assessment', 'hr2_evaluated', 'completed'];
        if ($pipelineId <= 0 || !in_array($newStage, $validStages)) {
            echo json_encode(['success' => false, 'error' => 'Invalid pipeline ID or stage']);
            exit();
        }

        $sql = "UPDATE employee_pipeline SET current_stage = ?";
        $params = [$newStage];
        $types = 's';

        if (!empty($notes)) {
            $sql .= ", notes = ?";
            $params[] = $notes;
            $types .= 's';
        }

        $sql .= " WHERE id = ?";
        $params[] = $pipelineId;
        $types .= 'i';

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            // Auto-recalculate stats
            recalculatePipelineStats($conn, $pipelineId);
            echo json_encode(['success' => true, 'message' => "Stage updated to: {$newStage}"]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Update failed: ' . $stmt->error]);
        }
        exit();
    }

    // Save course quiz result
    if ($action === 'save_quiz') {
        $pipelineId = (int)($_POST['pipeline_id'] ?? 0);
        $hr1EmpId = (int)($_POST['hr1_employee_id'] ?? 0);
        $courseId = (int)($_POST['course_id'] ?? 0);
        $totalQ = (int)($_POST['total_questions'] ?? 0);
        $correctA = (int)($_POST['correct_answers'] ?? 0);
        $scorePct = (float)($_POST['score_percentage'] ?? 0);
        $passed = $scorePct >= 70.0 ? 1 : 0;
        $timeTaken = (int)($_POST['time_taken'] ?? 0);

        if ($pipelineId <= 0 || $courseId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Pipeline ID and Course ID required']);
            exit();
        }

        $stmt = $conn->prepare("
            INSERT INTO course_quiz_results 
            (pipeline_id, hr1_employee_id, course_id, total_questions, correct_answers, 
             score_percentage, passed, time_taken_seconds, evaluated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('iiiiidiii',
            $pipelineId, $hr1EmpId, $courseId, $totalQ, $correctA, $scorePct, $passed, $timeTaken, $userId
        );

        if ($stmt->execute()) {
            recalculatePipelineStats($conn, $pipelineId);
            echo json_encode(['success' => true, 'message' => 'Quiz result saved', 'passed' => $passed]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Save failed: ' . $stmt->error]);
        }
        exit();
    }

    // Final HR2 evaluation (calculate combined score)
    if ($action === 'evaluate') {
        $pipelineId = (int)($_POST['pipeline_id'] ?? 0);
        $recommendation = trim($_POST['recommendation'] ?? '');

        if ($pipelineId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid pipeline ID']);
            exit();
        }

        // Get current pipeline record
        $stmt = $conn->prepare("SELECT * FROM employee_pipeline WHERE id = ?");
        $stmt->bind_param('i', $pipelineId);
        $stmt->execute();
        $pipeline = $stmt->get_result()->fetch_assoc();

        if (!$pipeline) {
            echo json_encode(['success' => false, 'error' => 'Pipeline record not found']);
            exit();
        }

        // Use avg_assessment_score as HR2 eval score
        $hr2Score = (float)$pipeline['avg_assessment_score'];
        $hr1Score = (float)$pipeline['hr1_eval_score'];

        // Calculate combined score: 30% HR1 + 70% HR2
        $combinedScore = ($hr1Score * 0.30) + ($hr2Score * 0.70);

        // Determine ratings
        $hr2Rating = getRatingLabel($hr2Score);
        $combinedRating = getRatingLabel($combinedScore);

        $updateStmt = $conn->prepare("
            UPDATE employee_pipeline SET
                hr2_eval_score = ?,
                hr2_eval_rating = ?,
                hr2_eval_date = CURDATE(),
                combined_score = ?,
                combined_rating = ?,
                final_recommendation = ?,
                current_stage = 'completed',
                status = 'completed'
            WHERE id = ?
        ");
        $updateStmt->bind_param('dsdssi', $hr2Score, $hr2Rating, $combinedScore, $combinedRating, $recommendation, $pipelineId);

        if ($updateStmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Evaluation completed',
                'hr1_score' => round($hr1Score, 2),
                'hr2_score' => round($hr2Score, 2),
                'combined_score' => round($combinedScore, 2),
                'combined_rating' => $combinedRating
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Evaluation failed: ' . $updateStmt->error]);
        }
        exit();
    }

    // Remove from pipeline
    if ($action === 'remove') {
        $pipelineId = (int)($_POST['pipeline_id'] ?? 0);
        if ($pipelineId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid pipeline ID']);
            exit();
        }

        $conn->query("DELETE FROM course_quiz_results WHERE pipeline_id = {$pipelineId}");
        $conn->query("DELETE FROM course_assignments WHERE pipeline_id = {$pipelineId}");
        $conn->query("DELETE FROM employee_pipeline WHERE id = {$pipelineId}");

        echo json_encode(['success' => true, 'message' => 'Employee removed from pipeline']);
        exit();
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);

// ============================
// Helper Functions
// ============================
function getRatingLabel($score) {
    if ($score >= 90) return 'Outstanding';
    if ($score >= 80) return 'Excellent';
    if ($score >= 70) return 'Very Good';
    if ($score >= 60) return 'Good';
    if ($score >= 50) return 'Fair';
    return 'Needs Improvement';
}

function recalculatePipelineStats($conn, $pipelineId) {
    // First, sync course_assignments with actual course_progress data
    // Find the HR2 user_id for this pipeline employee via email lookup
    $pipelineStmt = $conn->prepare("SELECT hr1_employee_id, hr1_employee_email FROM employee_pipeline WHERE id = ?");
    $pipelineStmt->bind_param('i', $pipelineId);
    $pipelineStmt->execute();
    $pipelineRow = $pipelineStmt->get_result()->fetch_assoc();
    $pipelineStmt->close();

    if ($pipelineRow && $pipelineRow['hr1_employee_email']) {
        // Find the HR2 user_id via email
        $userStmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $userStmt->bind_param('s', $pipelineRow['hr1_employee_email']);
        $userStmt->execute();
        $userRow = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();

        if ($userRow) {
            $hr2UserId = (int)$userRow['id'];
            
            // Check if course_progress table exists
            $cpExists = $conn->query("SHOW TABLES LIKE 'course_progress'")->num_rows > 0;
            
            if ($cpExists) {
                // Sync each course_assignment's progress from course_progress
                $assignments = $conn->query("SELECT id, course_id FROM course_assignments WHERE pipeline_id = {$pipelineId}");
                if ($assignments) {
                    while ($ca = $assignments->fetch_assoc()) {
                        $cpStmt = $conn->prepare("SELECT watched_percent FROM course_progress WHERE employee_id = ? AND course_id = ? LIMIT 1");
                        $cpStmt->bind_param('ii', $hr2UserId, $ca['course_id']);
                        $cpStmt->execute();
                        $cpRow = $cpStmt->get_result()->fetch_assoc();
                        $cpStmt->close();

                        if ($cpRow) {
                            $watchProgress = (float)$cpRow['watched_percent'];
                            $isCompleted = $watchProgress >= 100 ? 1 : 0;
                            $completedAt = $isCompleted ? date('Y-m-d H:i:s') : null;
                            $status = $isCompleted ? 'completed' : ($watchProgress > 0 ? 'in_progress' : 'assigned');

                            $updateCA = $conn->prepare("UPDATE course_assignments SET watch_progress = ?, is_completed = ?, status = ?" . ($completedAt ? ", completed_at = ?" : "") . " WHERE id = ?");
                            if ($completedAt) {
                                $updateCA->bind_param('dissi', $watchProgress, $isCompleted, $status, $completedAt, $ca['id']);
                            } else {
                                $updateCA->bind_param('disi', $watchProgress, $isCompleted, $status, $ca['id']);
                            }
                            $updateCA->execute();
                            $updateCA->close();
                        }
                    }
                }
            }
        }
    }

    // Now recalculate stats from the synced data
    $conn->query("
        UPDATE employee_pipeline p SET
            courses_assigned = (SELECT COUNT(*) FROM course_assignments WHERE pipeline_id = p.id),
            courses_completed = (SELECT COUNT(*) FROM course_assignments WHERE pipeline_id = p.id AND is_completed = 1),
            course_completion_pct = IFNULL(
                (SELECT COUNT(*) FROM course_assignments WHERE pipeline_id = p.id AND is_completed = 1) /
                NULLIF((SELECT COUNT(*) FROM course_assignments WHERE pipeline_id = p.id), 0) * 100, 0
            ),
            assessments_taken = (SELECT COUNT(*) FROM course_quiz_results WHERE pipeline_id = p.id),
            assessments_passed = (SELECT COUNT(*) FROM course_quiz_results WHERE pipeline_id = p.id AND passed = 1),
            avg_assessment_score = IFNULL(
                (SELECT AVG(score_percentage) FROM course_quiz_results WHERE pipeline_id = p.id), 0
            )
        WHERE p.id = {$pipelineId}
    ");
}
?>

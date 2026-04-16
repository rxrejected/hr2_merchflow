<?php
/**
 * API: HR2 Assessment Quiz - Save/Fetch
 * Handles quiz-based assessment CRUD (10 MCQ questions, 4 choices each)
 * ADMIN ONLY
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
    echo json_encode(['success' => false, 'error' => 'Access denied — Admin only']);
    exit();
}

$evaluatorId = (int)$_SESSION['user_id'];

// ============================
// POST — Save Quiz Results
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hr1EmpId = (int)($_POST['hr1_employee_id'] ?? 0);
    $hr1EmpName = trim($_POST['hr1_employee_name'] ?? '');
    $hr1EmpEmail = trim($_POST['hr1_employee_email'] ?? '');
    $period = trim($_POST['period'] ?? '');

    if ($hr1EmpId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid employee ID']);
        exit();
    }
    if (empty($period)) {
        echo json_encode(['success' => false, 'error' => 'Please select an assessment period']);
        exit();
    }

    // Quiz answer keys (correspond to DB columns)
    $questionKeys = [
        'job_knowledge', 'work_quality', 'productivity', 'reliability', 'initiative',
        'communication', 'teamwork', 'problem_solving', 'adaptability', 'leadership'
    ];

    $answers = [];
    $unanswered = [];
    foreach ($questionKeys as $key) {
        $val = (int)($_POST[$key] ?? 0);
        if ($val < 1 || $val > 4) {
            $unanswered[] = $key;
            $val = 1; // Default to lowest if somehow invalid
        }
        $answers[$key] = $val;
    }

    if (count($unanswered) > 0) {
        echo json_encode(['success' => false, 'error' => 'Please answer all 10 questions. Missing: ' . implode(', ', $unanswered)]);
        exit();
    }

    // Calculate overall score (10 questions × max 4 points = 40 max)
    $totalPoints = array_sum($answers);
    $maxPoints = count($questionKeys) * 4;
    $overallScore = ($totalPoints / $maxPoints) * 100;

    // Determine rating label
    if ($overallScore >= 90) $ratingLabel = 'Outstanding';
    elseif ($overallScore >= 80) $ratingLabel = 'Excellent';
    elseif ($overallScore >= 70) $ratingLabel = 'Very Good';
    elseif ($overallScore >= 60) $ratingLabel = 'Good';
    elseif ($overallScore >= 50) $ratingLabel = 'Fair';
    else $ratingLabel = 'Needs Improvement';

    $comments = trim($_POST['comments'] ?? '');
    $strengths = trim($_POST['strengths'] ?? '');
    $areasForImprovement = trim($_POST['areas_for_improvement'] ?? '');

    // Insert (always new record for assessment history)
    $stmt = $conn->prepare("
        INSERT INTO hr2_assessments
        (hr1_employee_id, hr1_employee_name, hr1_employee_email, evaluator_id, period,
         job_knowledge, work_quality, productivity, reliability, initiative,
         communication, teamwork, problem_solving, adaptability, leadership,
         overall_score, rating_label, comments, strengths, areas_for_improvement, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')
    ");

    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
        exit();
    }

    $stmt->bind_param(
        'issisiiiiiiiiiidsss' . 's',
        $hr1EmpId,
        $hr1EmpName,
        $hr1EmpEmail,
        $evaluatorId,
        $period,
        $answers['job_knowledge'],
        $answers['work_quality'],
        $answers['productivity'],
        $answers['reliability'],
        $answers['initiative'],
        $answers['communication'],
        $answers['teamwork'],
        $answers['problem_solving'],
        $answers['adaptability'],
        $answers['leadership'],
        $overallScore,
        $ratingLabel,
        $comments,
        $strengths,
        $areasForImprovement
    );

    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Quiz assessment saved successfully',
            'id' => $conn->insert_id,
            'overall_score' => round($overallScore, 2),
            'rating_label' => $ratingLabel,
            'total_points' => $totalPoints,
            'max_points' => $maxPoints
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to save: ' . $stmt->error]);
    }

    $stmt->close();
    exit();
}

// ============================
// GET — Fetch Assessments
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        $empId = (int)($_GET['employee_id'] ?? 0);

        $sql = "SELECT * FROM hr2_assessments";
        $params = [];
        $types = '';

        if ($empId > 0) {
            $sql .= " WHERE hr1_employee_id = ?";
            $params[] = $empId;
            $types .= 'i';
        }

        $sql .= " ORDER BY created_at DESC";

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

    if ($action === 'latest') {
        $result = $conn->query("
            SELECT a.* FROM hr2_assessments a
            INNER JOIN (
                SELECT hr1_employee_id, MAX(id) as max_id
                FROM hr2_assessments
                WHERE status = 'completed'
                GROUP BY hr1_employee_id
            ) b ON a.id = b.max_id
            ORDER BY a.hr1_employee_id
        ");

        $data = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $data[$row['hr1_employee_id']] = $row;
            }
        }

        echo json_encode(['success' => true, 'data' => $data]);
        exit();
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);

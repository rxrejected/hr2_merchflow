<?php
/**
 * OSAVE CONVENIENCE STORE - HR2 MERCHFLOW
 * Training Evaluation & Results Module
 * Premium Glassmorphism UI - Poppins Font
 * Employee data linked to users_employee table
 */

require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

$role = $_SESSION['role'] ?? 'employee';
$user_id = (int)$_SESSION['user_id'];

$userRole = strtolower(str_replace(' ', '', $role));
if (!in_array($userRole, ['admin', 'manager', 'superadmin'])) {
    header('Location: employee.php');
    exit();
}

$success = "";
$error = "";

// Ensure employee_activity_log table exists
$conn->query("CREATE TABLE IF NOT EXISTS employee_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL COMMENT 'hr1_employee_id from users_employee',
    activity_type VARCHAR(50) NOT NULL,
    reference_id INT DEFAULT NULL,
    reference_title VARCHAR(255) DEFAULT NULL,
    details TEXT DEFAULT NULL,
    performed_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_employee (employee_id),
    INDEX idx_type (activity_type),
    INDEX idx_created (created_at)
)");

// Fetch employees from users_employee table (synced from HR1)
$empQuery = $conn->query("
    SELECT id, hr1_employee_id, employee_code, full_name, email, phone,
           job_position, department, site, avatar, employment_status,
           date_hired, last_login, login_count, is_active
    FROM users_employee 
    WHERE is_active = 1 
    ORDER BY full_name ASC
");
$allEmployees = [];
$employeesByHr1Id = [];
if ($empQuery) {
    while ($emp = $empQuery->fetch_assoc()) {
        $allEmployees[] = $emp;
        $employeesByHr1Id[(int)$emp['hr1_employee_id']] = $emp;
    }
}

function getAvatarUrl($avatar) {
    if (empty($avatar) || $avatar === 'default.png') return 'uploads/avatars/default.png';
    if (filter_var($avatar, FILTER_VALIDATE_URL)) return htmlspecialchars($avatar);
    if (strpos($avatar, 'http') === 0) return htmlspecialchars($avatar);
    if (strpos($avatar, 'uploads/') === 0) return htmlspecialchars($avatar);
    return 'uploads/avatars/' . htmlspecialchars($avatar);
}

// Helper: Log employee activity
function logActivity($conn, $employee_id, $type, $ref_id, $ref_title, $details, $performed_by) {
    $stmt = $conn->prepare("INSERT INTO employee_activity_log (employee_id, activity_type, reference_id, reference_title, details, performed_by) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isissi", $employee_id, $type, $ref_id, $ref_title, $details, $performed_by);
    $stmt->execute();
    $stmt->close();
}

// ==========================================
// EVALUATE INDIVIDUAL EMPLOYEE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['evaluate_employee'])) {
    try {
        $training_id = (int)($_POST['training_id'] ?? 0);
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $result = $_POST['eval_result'] ?? '';

        if ($training_id <= 0 || $employee_id <= 0) {
            throw new Exception("Invalid training or employee.");
        }
        if (!in_array($result, ['Passed', 'Failed'])) {
            throw new Exception("Please select Passed or Failed.");
        }

        $stmt = $conn->prepare("
            UPDATE training_attendance 
            SET training_result = ?, date_evaluated = NOW()
            WHERE user_id = ? AND schedule_id = ? AND attended = 'Yes'
        ");
        $stmt->bind_param("sii", $result, $employee_id, $training_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            $success = "Evaluation saved successfully!";
            // Log activity
            $tQ = $conn->prepare("SELECT title FROM training_schedules WHERE id = ?");
            $tQ->bind_param("i", $training_id);
            $tQ->execute();
            $tTitle = ($tQ->get_result()->fetch_assoc())['title'] ?? 'Training #'.$training_id;
            $tQ->close();
            $actType = ($result === 'Passed') ? 'training_passed' : 'training_failed';
            logActivity($conn, $employee_id, $actType, $training_id, $tTitle, "Employee evaluated as $result", $user_id);
        } else {
            $error = "No changes made. Employee may not have attended this training.";
        }
        $stmt->close();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ==========================================
// SAVE BULK TRAINING RESULTS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_result'])) {
    try {
        $training_id = (int)($_POST['training_id'] ?? 0);
        if ($training_id <= 0) throw new Exception("Invalid training selected.");

        $saved_count = 0;
        if (isset($_POST['result']) && is_array($_POST['result'])) {
            foreach ($_POST['result'] as $user_id_key => $result) {
                if ($result === '' || !in_array($result, ['Passed', 'Failed'])) continue;

                $stmt = $conn->prepare("
                    UPDATE training_attendance 
                    SET training_result = ?, date_evaluated = NOW()
                    WHERE user_id = ? AND schedule_id = ? AND attended = 'Yes'
                ");
                if ($stmt) {
                    $stmt->bind_param("sii", $result, $user_id_key, $training_id);
                    if ($stmt->execute() && $stmt->affected_rows > 0) $saved_count++;
                    $stmt->close();
                }
            }
        }
        $success = "Training results saved! ($saved_count record(s) updated)";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ==========================================
// BULK ENROLL ALL EMPLOYEES (FROM HR1)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_enroll'])) {
    try {
        $training_id = (int)($_POST['training_id'] ?? 0);
        if ($training_id <= 0) throw new Exception("Invalid training selected.");

        $enrolled_count = 0;
        foreach ($allEmployees as $emp) {
            $empHr1Id = (int)$emp['hr1_employee_id'];
            $check = $conn->prepare("SELECT id FROM training_attendance WHERE user_id = ? AND schedule_id = ?");
            $check->bind_param("ii", $empHr1Id, $training_id);
            $check->execute();
            if ($check->get_result()->num_rows === 0) {
                $insert = $conn->prepare("INSERT INTO training_attendance (user_id, schedule_id, attended, date_submitted) VALUES (?, ?, 'No', NOW())");
                $insert->bind_param("ii", $empHr1Id, $training_id);
                if ($insert->execute()) $enrolled_count++;
                $insert->close();
            }
            $check->close();
        }
        $success = "Bulk enrollment completed! ($enrolled_count new employee(s) enrolled)";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ==========================================
// MARK ATTENDANCE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    try {
        $training_id = (int)($_POST['training_id'] ?? 0);
        if ($training_id <= 0) throw new Exception("Invalid training selected.");

        $marked_count = 0;
        if (isset($_POST['attendance']) && is_array($_POST['attendance'])) {
            foreach ($_POST['attendance'] as $user_id_key => $attended) {
                $attended_value = ($attended === 'Yes') ? 'Yes' : 'No';
                $check = $conn->prepare("SELECT id FROM training_attendance WHERE user_id = ? AND schedule_id = ?");
                $check->bind_param("ii", $user_id_key, $training_id);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;
                $check->close();

                if ($exists) {
                    $stmt = $conn->prepare("UPDATE training_attendance SET attended = ?, date_submitted = NOW() WHERE user_id = ? AND schedule_id = ?");
                    $stmt->bind_param("sii", $attended_value, $user_id_key, $training_id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO training_attendance (user_id, schedule_id, attended, date_submitted) VALUES (?, ?, ?, NOW())");
                    $stmt->bind_param("iis", $user_id_key, $training_id, $attended_value);
                }
                if ($stmt->execute()) $marked_count++;
                $stmt->close();
            }
        }
        $success = "Attendance marked successfully! ($marked_count record(s) updated)";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ==========================================
// FETCH TRAININGS
// ==========================================
$trainings = $conn->query("
    SELECT id, title, date, trainer, training_type, training_source, venue
    FROM training_schedules ORDER BY date DESC
");

$selected_id = $_GET['training_id'] ?? $_POST['training_id'] ?? null;
$attendance = [];
$training_info = null;

if ($selected_id) {
    $info = $conn->prepare("
        SELECT id, title, date, time, end_time, venue, trainer, training_type, training_source, description
        FROM training_schedules WHERE id = ?
    ");
    $info->bind_param("i", $selected_id);
    $info->execute();
    $training_info = $info->get_result()->fetch_assoc();
    $info->close();

    $attendanceData = [];
    $attStmt = $conn->prepare("
        SELECT user_id, attended, date_submitted, training_result, date_evaluated
        FROM training_attendance WHERE schedule_id = ?
    ");
    $attStmt->bind_param("i", $selected_id);
    $attStmt->execute();
    $attRes = $attStmt->get_result();
    while ($attRow = $attRes->fetch_assoc()) {
        $attendanceData[$attRow['user_id']] = $attRow;
    }
    $attStmt->close();

    foreach ($allEmployees as $emp) {
        $empHr1Id = (int)$emp['hr1_employee_id'];
        $attRecord = $attendanceData[$empHr1Id] ?? null;
        $attendance[] = [
            'user_id' => $empHr1Id,
            'employee_table_id' => $emp['id'],
            'full_name' => $emp['full_name'] ?? 'Unknown',
            'avatar' => $emp['avatar'] ?? null,
            'job_position' => $emp['job_position'] ?? 'N/A',
            'department' => $emp['department'] ?? 'N/A',
            'email' => $emp['email'] ?? '',
            'employment_status' => $emp['employment_status'] ?? 'active',
            'attended' => $attRecord['attended'] ?? null,
            'date_submitted' => $attRecord['date_submitted'] ?? null,
            'training_result' => $attRecord['training_result'] ?? null,
            'date_evaluated' => $attRecord['date_evaluated'] ?? null
        ];
    }
    usort($attendance, function($a, $b) {
        return strcasecmp($a['full_name'], $b['full_name']);
    });
}

// ==========================================
// FETCH STATISTICS
// ==========================================
$stats = ['total_trainings' => 0, 'total_attendees' => 0, 'passed' => 0, 'failed' => 0, 'pending' => 0];
$stats_query = $conn->query("
    SELECT 
        (SELECT COUNT(*) FROM training_schedules) as total_trainings,
        COUNT(DISTINCT ta.user_id) as total_attendees,
        SUM(CASE WHEN ta.training_result = 'Passed' THEN 1 ELSE 0 END) as passed,
        SUM(CASE WHEN ta.training_result = 'Failed' THEN 1 ELSE 0 END) as failed,
        SUM(CASE WHEN ta.attended = 'Yes' AND (ta.training_result IS NULL OR ta.training_result = '') THEN 1 ELSE 0 END) as pending
    FROM training_attendance ta
");
if ($stats_query) $stats = $stats_query->fetch_assoc();

$training_stats = null;
if ($selected_id) {
    $ts_query = $conn->prepare("
        SELECT 
            COUNT(DISTINCT ta.user_id) as total_participants,
            SUM(CASE WHEN ta.attended = 'Yes' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN ta.attended = 'No' THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN ta.training_result = 'Passed' THEN 1 ELSE 0 END) as passed,
            SUM(CASE WHEN ta.training_result = 'Failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN ta.attended = 'Yes' AND (ta.training_result IS NULL OR ta.training_result = '') THEN 1 ELSE 0 END) as pending
        FROM training_attendance ta WHERE ta.schedule_id = ?
    ");
    $ts_query->bind_param("i", $selected_id);
    $ts_query->execute();
    $training_stats = $ts_query->get_result()->fetch_assoc();
    $ts_query->close();
}

// ==========================================
// FETCH ALL EMPLOYEE TRAINING HISTORIES
// ==========================================
$all_training_history = [];
$history_query = $conn->query("
    SELECT ta.user_id, ta.schedule_id, ta.attended, ta.training_result, ta.date_submitted, ta.date_evaluated,
           ts.title as training_title, ts.date as training_date, ts.trainer, ts.training_type, ts.training_source, ts.venue
    FROM training_attendance ta
    JOIN training_schedules ts ON ta.schedule_id = ts.id
    WHERE ta.attended = 'Yes'
    ORDER BY ts.date DESC
");
if ($history_query) {
    while ($h = $history_query->fetch_assoc()) {
        $uid = (int)$h['user_id'];
        if (!isset($all_training_history[$uid])) $all_training_history[$uid] = [];
        $all_training_history[$uid][] = $h;
    }
}

// ==========================================
// EMPLOYEE PROGRESS ACROSS SYSTEM
// ==========================================
$employeeProgress = [];
$progressQuery = $conn->query("
    SELECT 
        ta.user_id as hr1_employee_id,
        COUNT(DISTINCT ta.schedule_id) as total_trainings,
        SUM(CASE WHEN ta.attended = 'Yes' THEN 1 ELSE 0 END) as attended_count,
        SUM(CASE WHEN ta.training_result = 'Passed' THEN 1 ELSE 0 END) as passed_count,
        SUM(CASE WHEN ta.training_result = 'Failed' THEN 1 ELSE 0 END) as failed_count,
        SUM(CASE WHEN ta.attended = 'Yes' AND (ta.training_result IS NULL OR ta.training_result = '') THEN 1 ELSE 0 END) as pending_count,
        MAX(ta.date_submitted) as last_training_date
    FROM training_attendance ta
    GROUP BY ta.user_id
");
if ($progressQuery) {
    while ($p = $progressQuery->fetch_assoc()) {
        $employeeProgress[(int)$p['hr1_employee_id']] = $p;
    }
}

// Course progress data
$courseProgress = [];
$tableCheck = $conn->query("SHOW TABLES LIKE 'course_progress'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $cpQuery = $conn->query("
        SELECT cp.employee_id, 
               COUNT(DISTINCT cp.course_id) as courses_enrolled,
               SUM(CASE WHEN cp.watched_percent >= 100 THEN 1 ELSE 0 END) as courses_completed,
               ROUND(AVG(cp.watched_percent), 0) as avg_progress,
               MAX(cp.last_watched_at) as last_course_date
        FROM course_progress cp
        GROUP BY cp.employee_id
    ");
    if ($cpQuery) {
        while ($c = $cpQuery->fetch_assoc()) {
            $courseProgress[(int)$c['employee_id']] = $c;
        }
    }
}

// Assessment data
$assessmentData = [];
$tableCheck = $conn->query("SHOW TABLES LIKE 'assessment'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $asQuery = $conn->query("
        SELECT a.employee_id,
               COUNT(*) as total_assessments,
               MAX(a.date_created) as last_assessment_date
        FROM assessment a
        GROUP BY a.employee_id
    ");
    if ($asQuery) {
        while ($a = $asQuery->fetch_assoc()) {
            $assessmentData[(int)$a['employee_id']] = $a;
        }
    }
}

// Recent activity log
$recentActivities = [];
$tableCheck = $conn->query("SHOW TABLES LIKE 'employee_activity_log'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $actQuery = $conn->query("
        SELECT eal.*, ue.full_name, ue.avatar, ue.department
        FROM employee_activity_log eal
        LEFT JOIN users_employee ue ON eal.employee_id = ue.hr1_employee_id
        ORDER BY eal.created_at DESC
        LIMIT 30
    ");
    if ($actQuery) {
        while ($a = $actQuery->fetch_assoc()) {
            $recentActivities[] = $a;
        }
    }
}

// Build combined progress for each employee
$combinedProgress = [];
foreach ($allEmployees as $emp) {
    $hr1Id = (int)$emp['hr1_employee_id'];
    $tp = $employeeProgress[$hr1Id] ?? null;
    $cp = $courseProgress[$hr1Id] ?? null;
    $ap = $assessmentData[$hr1Id] ?? null;
    
    $trainingScore = 0;
    if ($tp) {
        $totalTr = (int)($tp['attended_count'] ?? 0);
        $passedTr = (int)($tp['passed_count'] ?? 0);
        $trainingScore = $totalTr > 0 ? round(($passedTr / $totalTr) * 100) : 0;
    }
    $courseScore = (int)($cp['avg_progress'] ?? 0);
    $assessmentScore = ($ap && (int)$ap['total_assessments'] > 0) ? 100 : 0;
    
    $overallScore = 0;
    $factors = 0;
    if ($tp) { $overallScore += $trainingScore; $factors++; }
    if ($cp) { $overallScore += $courseScore; $factors++; }
    if ($ap) { $overallScore += $assessmentScore; $factors++; }
    $overallScore = $factors > 0 ? round($overallScore / $factors) : 0;
    
    $combinedProgress[] = [
        'employee' => $emp,
        'training' => $tp,
        'courses' => $cp,
        'assessments' => $ap,
        'training_score' => $trainingScore,
        'course_score' => $courseScore,
        'overall_score' => $overallScore,
        'history' => $all_training_history[$hr1Id] ?? []
    ];
}

// Sort by overall score descending
usort($combinedProgress, function($a, $b) {
    return $b['overall_score'] - $a['overall_score'];
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Training Results & Evaluation | Osave HR2</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="Css/nbar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="Css/sbar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="Css/theme.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="Css/module3_sub2.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<?php include 'partials/sidebar.php'; ?>

<div class="main-content">
    <?php include 'partials/nav.php'; ?>

    <div class="container">

        <!-- Page Header -->
        <div class="page-header">
            <div class="header-content">
                <div class="header-icon">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <div class="header-text">
                    <h2>Training Results & Evaluation</h2>
                    <p>View employee training results and evaluate performance</p>
                </div>
            </div>
            <div class="header-actions">
                <?php if ($selected_id): ?>
                <button type="button" class="header-btn" id="exportBtn">
                    <i class="fas fa-file-export"></i>
                    <span>Export CSV</span>
                </button>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="training_id" value="<?= $selected_id ?>">
                    <button type="submit" name="bulk_enroll" class="header-btn primary">
                        <i class="fas fa-user-plus"></i>
                        <span>Enroll All</span>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                <div class="stat-info">
                    <h3><?= number_format($stats['total_trainings'] ?? 0) ?></h3>
                    <p>Total Trainings</p>
                </div>
            </div>
            <div class="stat-card attendees">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <h3><?= number_format($stats['total_attendees'] ?? 0) ?></h3>
                    <p>Total Attendees</p>
                </div>
            </div>
            <div class="stat-card passed">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <h3><?= number_format($stats['passed'] ?? 0) ?></h3>
                    <p>Passed</p>
                </div>
            </div>
            <div class="stat-card failed">
                <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                <div class="stat-info">
                    <h3><?= number_format($stats['failed'] ?? 0) ?></h3>
                    <p>Failed</p>
                </div>
            </div>
            <div class="stat-card pending">
                <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
                <div class="stat-info">
                    <h3><?= number_format($stats['pending'] ?? 0) ?></h3>
                    <p>Pending Evaluation</p>
                </div>
            </div>
        </div>

        <!-- Toast Notifications -->
        <?php if (!empty($success)): ?>
        <div class="toast success" id="successToast">
            <i class="fas fa-check-circle"></i>
            <span><?= htmlspecialchars($success) ?></span>
            <button type="button" class="toast-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
        </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
        <div class="toast error" id="errorToast">
            <i class="fas fa-exclamation-circle"></i>
            <span><?= htmlspecialchars($error) ?></span>
            <button type="button" class="toast-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
        </div>
        <?php endif; ?>

        <!-- Training Selector -->
        <div class="training-selector">
            <div class="selector-header">
                <i class="fas fa-filter"></i>
                <h3>Select Training</h3>
            </div>
            <form method="GET" class="selector-form">
                <div class="select-wrapper">
                    <i class="fas fa-chalkboard"></i>
                    <select name="training_id" id="trainingSelect" required>
                        <option value="">-- Select a Training --</option>
                        <?php 
                        if ($trainings) {
                            $trainings->data_seek(0);
                            while ($t = $trainings->fetch_assoc()): 
                        ?>
                            <option value="<?= $t['id'] ?>" <?= ($t['id'] == $selected_id) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['title']) ?> 
                                (<?= date("M d, Y", strtotime($t['date'])) ?>)
                                - <?= htmlspecialchars($t['trainer']) ?>
                            </option>
                        <?php endwhile; } ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-eye"></i>
                    <span>View Results</span>
                </button>
            </form>
        </div>

        <?php if ($training_info): ?>
        <!-- Training Detail Card -->
        <div class="training-detail">
            <div class="detail-header">
                <div class="detail-title">
                    <h2><?= htmlspecialchars($training_info['title']) ?></h2>
                    <div class="detail-badges">
                        <span class="badge badge-<?= strtolower($training_info['training_type'] ?? 'theoretical') === 'theoretical' ? 'theoretical' : 'practical' ?>">
                            <i class="fas fa-<?= strtolower($training_info['training_type'] ?? '') === 'theoretical' ? 'book' : 'hands' ?>"></i>
                            <?= htmlspecialchars($training_info['training_type'] ?? 'N/A') ?>
                        </span>
                        <span class="badge badge-<?= strtolower($training_info['training_source'] ?? 'internal') ?>">
                            <i class="fas fa-<?= strtolower($training_info['training_source'] ?? '') === 'internal' ? 'building' : 'globe' ?>"></i>
                            <?= htmlspecialchars($training_info['training_source'] ?? 'N/A') ?>
                        </span>
                    </div>
                </div>
                <div class="detail-meta">
                    <div class="meta-item">
                        <i class="fas fa-user-tie"></i>
                        <span><?= htmlspecialchars($training_info['trainer'] ?? 'N/A') ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-calendar"></i>
                        <span><?= $training_info['date'] ? date("F d, Y", strtotime($training_info['date'])) : 'N/A' ?></span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-clock"></i>
                        <span>
                            <?= $training_info['time'] ? date("h:i A", strtotime($training_info['time'])) : '' ?>
                            - <?= $training_info['end_time'] ? date("h:i A", strtotime($training_info['end_time'])) : '' ?>
                        </span>
                    </div>
                    <div class="meta-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?= htmlspecialchars($training_info['venue'] ?? 'N/A') ?></span>
                    </div>
                </div>
            </div>

            <?php if ($training_stats): 
                $total_p = (int)($training_stats['total_participants'] ?? 0);
                $present = (int)($training_stats['present'] ?? 0);
                $absent = (int)($training_stats['absent'] ?? 0);
                $passed = (int)($training_stats['passed'] ?? 0);
                $failed = (int)($training_stats['failed'] ?? 0);
                $pending_eval = (int)($training_stats['pending'] ?? 0);
                $attendance_pct = $total_p > 0 ? round(($present / $total_p) * 100) : 0;
                $pass_pct = $present > 0 ? round(($passed / $present) * 100) : 0;
            ?>
            <div class="mini-stats-grid">
                <div class="mini-stat">
                    <h3><?= $total_p ?></h3>
                    <p>Total Enrolled</p>
                </div>
                <div class="mini-stat present">
                    <h3><?= $present ?></h3>
                    <p>Present</p>
                </div>
                <div class="mini-stat absent">
                    <h3><?= $absent ?></h3>
                    <p>Absent</p>
                </div>
                <div class="mini-stat passed">
                    <h3><?= $passed ?></h3>
                    <p>Passed</p>
                </div>
                <div class="mini-stat failed">
                    <h3><?= $failed ?></h3>
                    <p>Failed</p>
                </div>
                <div class="mini-stat pending-eval">
                    <h3><?= $pending_eval ?></h3>
                    <p>Pending</p>
                </div>
            </div>

            <div class="progress-section">
                <div class="progress-row">
                    <div class="progress-label">
                        <span><i class="fas fa-user-check"></i> Attendance Rate</span>
                        <strong><?= $attendance_pct ?>%</strong>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill attendance" style="width: <?= $attendance_pct ?>%"></div>
                    </div>
                </div>
                <div class="progress-row">
                    <div class="progress-label">
                        <span><i class="fas fa-trophy"></i> Pass Rate</span>
                        <strong><?= $pass_pct ?>%</strong>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill pass-rate" style="width: <?= $pass_pct ?>%"></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tabs Container -->
        <div class="tabs-container">
            <div class="tabs-nav">
                <button type="button" class="tab-btn active" data-tab="results">
                    <i class="fas fa-award"></i>
                    <span>Results (<?= $training_stats['present'] ?? 0 ?>)</span>
                </button>
                <button type="button" class="tab-btn" data-tab="attendance">
                    <i class="fas fa-user-check"></i>
                    <span>Mark Attendance</span>
                </button>
                <button type="button" class="tab-btn" data-tab="bulk-eval">
                    <i class="fas fa-tasks"></i>
                    <span>Bulk Evaluate</span>
                </button>
                <button type="button" class="tab-btn" data-tab="progress">
                    <i class="fas fa-chart-line"></i>
                    <span>Employee Progress (<?= count($allEmployees) ?>)</span>
                </button>
            </div>

            <!-- ===================== -->
            <!-- TAB 1: RESULTS -->
            <!-- ===================== -->
            <div class="tab-panel active" id="results-tab">
                <div class="panel-toolbar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="resultsSearch" placeholder="Search employees...">
                    </div>
                    <div class="toolbar-right">
                        <select id="resultStatusFilter" class="filter-select">
                            <option value="">All Status</option>
                            <option value="Passed">Passed</option>
                            <option value="Failed">Failed</option>
                            <option value="Pending">Pending</option>
                        </select>
                        <div class="summary-chips">
                            <span class="chip success"><i class="fas fa-check-circle"></i> <?= $training_stats['passed'] ?? 0 ?> Passed</span>
                            <span class="chip danger"><i class="fas fa-times-circle"></i> <?= $training_stats['failed'] ?? 0 ?> Failed</span>
                            <span class="chip warning"><i class="fas fa-clock"></i> <?= $training_stats['pending'] ?? 0 ?> Pending</span>
                        </div>
                    </div>
                </div>

                <?php 
                $attendedEmployees = array_filter($attendance, function($emp) {
                    return $emp['attended'] === 'Yes';
                });
                ?>

                <?php if (count($attendedEmployees) > 0): ?>
                <div class="results-grid" id="resultsGrid">
                    <?php foreach ($attendedEmployees as $emp): 
                        $empHistory = $all_training_history[$emp['user_id']] ?? [];
                        $historyJson = htmlspecialchars(json_encode($empHistory), ENT_QUOTES, 'UTF-8');
                    ?>
                    <div class="result-card" 
                         data-name="<?= htmlspecialchars(strtolower($emp['full_name'])) ?>" 
                         data-position="<?= htmlspecialchars(strtolower($emp['job_position'] ?? '')) ?>"
                         data-result="<?= htmlspecialchars($emp['training_result'] ?? 'Pending') ?>">
                        <div class="card-top">
                            <div class="emp-avatar">
                                <img src="<?= getAvatarUrl($emp['avatar']) ?>" 
                                     alt="<?= htmlspecialchars($emp['full_name']) ?>"
                                     onerror="this.src='uploads/avatars/default.png'">
                                <?php if ($emp['training_result'] === 'Passed'): ?>
                                <span class="avatar-badge passed"><i class="fas fa-check"></i></span>
                                <?php elseif ($emp['training_result'] === 'Failed'): ?>
                                <span class="avatar-badge failed"><i class="fas fa-times"></i></span>
                                <?php else: ?>
                                <span class="avatar-badge pending"><i class="fas fa-clock"></i></span>
                                <?php endif; ?>
                            </div>
                            <div class="emp-info">
                                <h4><?= htmlspecialchars($emp['full_name']) ?></h4>
                                <span class="emp-position"><?= htmlspecialchars($emp['job_position'] ?? 'Employee') ?></span>
                                <?php if (!empty($emp['email'])): ?>
                                <span class="emp-email"><i class="fas fa-envelope"></i> <?= htmlspecialchars($emp['email']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-middle">
                            <?php if ($emp['training_result'] === 'Passed'): ?>
                            <span class="result-tag passed"><i class="fas fa-trophy"></i> Passed</span>
                            <?php elseif ($emp['training_result'] === 'Failed'): ?>
                            <span class="result-tag failed"><i class="fas fa-times-circle"></i> Failed</span>
                            <?php else: ?>
                            <span class="result-tag pending"><i class="fas fa-clock"></i> Pending Evaluation</span>
                            <?php endif; ?>
                            <?php if (!empty($emp['date_submitted'])): ?>
                            <span class="attended-date"><i class="fas fa-calendar-check"></i> <?= date("M d, Y", strtotime($emp['date_submitted'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="card-bottom">
                            <button type="button" class="action-btn evaluate" 
                                    onclick="openEvaluateModal(<?= $emp['user_id'] ?>, '<?= htmlspecialchars(addslashes($emp['full_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($emp['job_position'] ?? 'Employee'), ENT_QUOTES) ?>', '<?= getAvatarUrl($emp['avatar']) ?>', '<?= $emp['training_result'] ?? '' ?>')">
                                <i class="fas fa-star"></i> Evaluate
                            </button>
                            <button type="button" class="action-btn view-history" 
                                    onclick='openHistoryModal(<?= $emp["user_id"] ?>, "<?= htmlspecialchars(addslashes($emp["full_name"]), ENT_QUOTES) ?>", <?= $historyJson ?>)'>
                                <i class="fas fa-history"></i> History
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-user-slash"></i></div>
                    <h3>No Attendees Yet</h3>
                    <p>No employees have been marked as present for this training. Go to "Mark Attendance" tab first.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- ===================== -->
            <!-- TAB 2: MARK ATTENDANCE -->
            <!-- ===================== -->
            <div class="tab-panel" id="attendance-tab">
                <?php if (in_array($role, ['admin', 'manager', 'Super Admin'])): ?>
                <form method="POST" id="attendanceForm">
                    <input type="hidden" name="training_id" value="<?= $selected_id ?>">
                    
                    <div class="panel-toolbar">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="attendanceSearch" placeholder="Search employees...">
                        </div>
                        <div class="toolbar-right">
                            <button type="button" class="btn btn-sm btn-success" onclick="markAll('Yes')">
                                <i class="fas fa-check-double"></i> All Present
                            </button>
                            <button type="button" class="btn btn-sm btn-danger" onclick="markAll('No')">
                                <i class="fas fa-times"></i> All Absent
                            </button>
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table" id="attendanceTable">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Position</th>
                                    <th>Attendance</th>
                                    <th>Date Marked</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendance as $row): ?>
                                <tr>
                                    <td>
                                        <div class="emp-cell">
                                            <img src="<?= getAvatarUrl($row['avatar']) ?>" 
                                                 class="cell-avatar" 
                                                 alt="<?= htmlspecialchars($row['full_name']) ?>"
                                                 onerror="this.src='uploads/avatars/default.png'">
                                            <div class="cell-details">
                                                <span class="cell-name"><?= htmlspecialchars($row['full_name']) ?></span>
                                                <span class="cell-email"><?= htmlspecialchars($row['email'] ?? '') ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="position-badge"><?= htmlspecialchars($row['job_position'] ?? 'N/A') ?></span></td>
                                    <td>
                                        <div class="attendance-toggle">
                                            <label class="toggle-option">
                                                <input type="radio" name="attendance[<?= $row['user_id'] ?>]" value="Yes" 
                                                       <?= ($row['attended'] === 'Yes') ? 'checked' : '' ?>>
                                                <span class="toggle-label present">
                                                    <i class="fas fa-check"></i> Present
                                                </span>
                                            </label>
                                            <label class="toggle-option">
                                                <input type="radio" name="attendance[<?= $row['user_id'] ?>]" value="No"
                                                       <?= ($row['attended'] === 'No') ? 'checked' : '' ?>>
                                                <span class="toggle-label absent">
                                                    <i class="fas fa-times"></i> Absent
                                                </span>
                                            </label>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['date_submitted'])): ?>
                                        <span class="date-text"><i class="fas fa-calendar-check"></i> <?= date("M d, Y h:i A", strtotime($row['date_submitted'])) ?></span>
                                        <?php else: ?>
                                        <span class="muted-text">Not marked yet</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="mark_attendance" class="btn btn-primary btn-lg">
                            <i class="fas fa-save"></i> Save Attendance
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>

            <!-- ===================== -->
            <!-- TAB 3: BULK EVALUATE -->
            <!-- ===================== -->
            <div class="tab-panel" id="bulk-eval-tab">
                <?php if (in_array($role, ['admin', 'manager', 'Super Admin'])): ?>
                <form method="POST" id="bulkEvalForm">
                    <input type="hidden" name="training_id" value="<?= $selected_id ?>">

                    <div class="panel-toolbar">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="bulkEvalSearch" placeholder="Search employees...">
                        </div>
                        <div class="toolbar-right">
                            <select id="bulkResultFilter" class="filter-select">
                                <option value="">All Results</option>
                                <option value="Passed">Passed</option>
                                <option value="Failed">Failed</option>
                                <option value="Pending">Pending</option>
                            </select>
                        </div>
                    </div>

                    <div class="table-wrapper">
                        <table class="data-table" id="bulkEvalTable">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Position</th>
                                    <th>Attendance</th>
                                    <th>Result</th>
                                    <th>Date Evaluated</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendance as $row): ?>
                                <tr class="eval-row" data-attended="<?= $row['attended'] ?? '' ?>" data-result="<?= $row['training_result'] ?? 'Pending' ?>">
                                    <td>
                                        <div class="emp-cell">
                                            <img src="<?= getAvatarUrl($row['avatar']) ?>" 
                                                 class="cell-avatar" 
                                                 alt="<?= htmlspecialchars($row['full_name']) ?>"
                                                 onerror="this.src='uploads/avatars/default.png'">
                                            <div class="cell-details">
                                                <span class="cell-name"><?= htmlspecialchars($row['full_name']) ?></span>
                                                <span class="cell-email"><?= htmlspecialchars($row['email'] ?? '') ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="position-badge"><?= htmlspecialchars($row['job_position'] ?? 'N/A') ?></span></td>
                                    <td>
                                        <?php if ($row['attended'] === 'Yes'): ?>
                                        <span class="status-tag present"><i class="fas fa-check"></i> Present</span>
                                        <?php elseif ($row['attended'] === 'No'): ?>
                                        <span class="status-tag absent"><i class="fas fa-times"></i> Absent</span>
                                        <?php else: ?>
                                        <span class="status-tag none"><i class="fas fa-minus"></i> Not Marked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['attended'] === 'Yes'): ?>
                                        <select name="result[<?= $row['user_id'] ?>]" class="result-select">
                                            <option value="">-- Select --</option>
                                            <option value="Passed" <?= ($row['training_result'] === 'Passed') ? 'selected' : '' ?>>✓ Passed</option>
                                            <option value="Failed" <?= ($row['training_result'] === 'Failed') ? 'selected' : '' ?>>✗ Failed</option>
                                        </select>
                                        <?php else: ?>
                                        <span class="muted-text">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['date_evaluated'])): ?>
                                        <span class="date-text"><i class="fas fa-calendar-check"></i> <?= date("M d, Y", strtotime($row['date_evaluated'])) ?></span>
                                        <?php elseif (!empty($row['training_result'])): ?>
                                        <span class="date-text"><i class="fas fa-calendar-check"></i> <?= date("M d, Y", strtotime($row['date_submitted'] ?? 'now')) ?></span>
                                        <?php else: ?>
                                        <span class="muted-text">Not evaluated</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="save_result" class="btn btn-primary btn-lg">
                            <i class="fas fa-save"></i> Save All Results
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>

            <!-- ===================== -->
            <!-- TAB 4: EMPLOYEE PROGRESS -->
            <!-- ===================== -->
            <div class="tab-panel" id="progress-tab">
                <div class="panel-toolbar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="progressSearch" placeholder="Search employees...">
                    </div>
                    <div class="toolbar-right">
                        <select id="progressSort" class="filter-select">
                            <option value="score-desc">Highest Score</option>
                            <option value="score-asc">Lowest Score</option>
                            <option value="name-asc">Name A-Z</option>
                            <option value="training-desc">Most Trainings</option>
                        </select>
                        <select id="progressDeptFilter" class="filter-select">
                            <option value="">All Departments</option>
                            <?php
                            $depts = array_unique(array_filter(array_column($allEmployees, 'department')));
                            sort($depts);
                            foreach ($depts as $dept): ?>
                            <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php if (count($combinedProgress) > 0): ?>
                <div class="progress-overview-stats">
                    <?php
                    $totalEmps = count($combinedProgress);
                    $avgScore = $totalEmps > 0 ? round(array_sum(array_column($combinedProgress, 'overall_score')) / $totalEmps) : 0;
                    $highPerformers = count(array_filter($combinedProgress, fn($p) => $p['overall_score'] >= 80));
                    $needsAttention = count(array_filter($combinedProgress, fn($p) => $p['overall_score'] < 50 && $p['overall_score'] > 0));
                    $noActivity = count(array_filter($combinedProgress, fn($p) => $p['overall_score'] === 0));
                    ?>
                    <div class="overview-stat">
                        <div class="overview-icon"><i class="fas fa-users"></i></div>
                        <h3><?= $totalEmps ?></h3>
                        <p>Total Employees</p>
                    </div>
                    <div class="overview-stat success">
                        <div class="overview-icon"><i class="fas fa-chart-line"></i></div>
                        <h3><?= $avgScore ?>%</h3>
                        <p>Avg. Progress</p>
                    </div>
                    <div class="overview-stat primary">
                        <div class="overview-icon"><i class="fas fa-star"></i></div>
                        <h3><?= $highPerformers ?></h3>
                        <p>High Performers</p>
                    </div>
                    <div class="overview-stat warning">
                        <div class="overview-icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <h3><?= $needsAttention ?></h3>
                        <p>Needs Attention</p>
                    </div>
                    <div class="overview-stat muted">
                        <div class="overview-icon"><i class="fas fa-user-clock"></i></div>
                        <h3><?= $noActivity ?></h3>
                        <p>No Activity</p>
                    </div>
                </div>

                <div class="progress-grid" id="progressGrid">
                    <?php foreach ($combinedProgress as $prog):
                        $emp = $prog['employee'];
                        $tp = $prog['training'];
                        $cp = $prog['courses'];
                        $ap = $prog['assessments'];
                        $score = $prog['overall_score'];
                        $scoreClass = $score >= 80 ? 'excellent' : ($score >= 60 ? 'good' : ($score >= 40 ? 'fair' : ($score > 0 ? 'poor' : 'none')));
                        $historyJson = htmlspecialchars(json_encode($prog['history']), ENT_QUOTES, 'UTF-8');
                    ?>
                    <div class="progress-card <?= $scoreClass ?>"
                         data-name="<?= htmlspecialchars(strtolower($emp['full_name'])) ?>"
                         data-dept="<?= htmlspecialchars($emp['department'] ?? '') ?>"
                         data-score="<?= $score ?>"
                         data-trainings="<?= (int)($tp['attended_count'] ?? 0) ?>">
                        <div class="progress-card-header">
                            <div class="progress-emp">
                                <img src="<?= getAvatarUrl($emp['avatar']) ?>" class="progress-avatar" alt="" onerror="this.src='uploads/avatars/default.png'">
                                <div class="progress-emp-info">
                                    <h4><?= htmlspecialchars($emp['full_name']) ?></h4>
                                    <span class="progress-position"><?= htmlspecialchars($emp['job_position'] ?? 'Employee') ?></span>
                                    <span class="progress-dept"><i class="fas fa-building"></i> <?= htmlspecialchars($emp['department'] ?? 'N/A') ?></span>
                                </div>
                            </div>
                            <div class="score-circle <?= $scoreClass ?>">
                                <svg viewBox="0 0 36 36">
                                    <path class="score-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                    <path class="score-fill" stroke-dasharray="<?= $score ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                                </svg>
                                <span class="score-text"><?= $score ?>%</span>
                            </div>
                        </div>

                        <div class="progress-metrics">
                            <div class="metric">
                                <div class="metric-header">
                                    <i class="fas fa-dumbbell"></i>
                                    <span>Training</span>
                                </div>
                                <div class="metric-bar">
                                    <div class="metric-fill training" style="width: <?= $prog['training_score'] ?>%"></div>
                                </div>
                                <div class="metric-detail">
                                    <span><?= (int)($tp['attended_count'] ?? 0) ?> attended</span>
                                    <span class="passed"><?= (int)($tp['passed_count'] ?? 0) ?> passed</span>
                                    <span class="failed"><?= (int)($tp['failed_count'] ?? 0) ?> failed</span>
                                </div>
                            </div>

                            <div class="metric">
                                <div class="metric-header">
                                    <i class="fas fa-book-open"></i>
                                    <span>Courses</span>
                                </div>
                                <div class="metric-bar">
                                    <div class="metric-fill courses" style="width: <?= $prog['course_score'] ?>%"></div>
                                </div>
                                <div class="metric-detail">
                                    <span><?= (int)($cp['courses_enrolled'] ?? 0) ?> enrolled</span>
                                    <span class="passed"><?= (int)($cp['courses_completed'] ?? 0) ?> completed</span>
                                    <span><?= (int)($cp['avg_progress'] ?? 0) ?>% avg</span>
                                </div>
                            </div>

                            <div class="metric">
                                <div class="metric-header">
                                    <i class="fas fa-clipboard-check"></i>
                                    <span>Assessments</span>
                                </div>
                                <div class="metric-bar">
                                    <div class="metric-fill assessments" style="width: <?= ($ap && (int)$ap['total_assessments'] > 0) ? 100 : 0 ?>%"></div>
                                </div>
                                <div class="metric-detail">
                                    <span><?= (int)($ap['total_assessments'] ?? 0) ?> completed</span>
                                </div>
                            </div>
                        </div>

                        <div class="progress-card-footer">
                            <div class="last-activity">
                                <?php
                                $lastDate = null;
                                if (!empty($tp['last_training_date'])) $lastDate = $tp['last_training_date'];
                                if (!empty($cp['last_course_date']) && (!$lastDate || $cp['last_course_date'] > $lastDate)) $lastDate = $cp['last_course_date'];
                                if (!empty($emp['last_login']) && (!$lastDate || $emp['last_login'] > $lastDate)) $lastDate = $emp['last_login'];
                                ?>
                                <?php if ($lastDate): ?>
                                <span class="last-date"><i class="fas fa-clock"></i> Last active: <?= date('M d, Y', strtotime($lastDate)) ?></span>
                                <?php else: ?>
                                <span class="last-date muted"><i class="fas fa-clock"></i> No activity yet</span>
                                <?php endif; ?>
                            </div>
                            <div class="progress-actions">
                                <button type="button" class="action-btn view-history" onclick='openHistoryModal(<?= $emp["hr1_employee_id"] ?>, "<?= htmlspecialchars(addslashes($emp["full_name"]), ENT_QUOTES) ?>", <?= $historyJson ?>)'>
                                    <i class="fas fa-history"></i> History
                                </button>
                                <button type="button" class="action-btn view-detail" onclick="openProgressDetail(<?= htmlspecialchars(json_encode($prog), ENT_QUOTES) ?>)">
                                    <i class="fas fa-expand"></i> Detail
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon"><i class="fas fa-users-slash"></i></div>
                    <h3>No Employee Data</h3>
                    <p>No employees have been synced yet. Sync employees from HR1 in Employee Accounts first.</p>
                </div>
                <?php endif; ?>

                <!-- Recent Activity Log -->
                <?php if (!empty($recentActivities)): ?>
                <div class="activity-log-section">
                    <h3 class="section-title"><i class="fas fa-stream"></i> Recent Activity Log</h3>
                    <div class="activity-timeline">
                        <?php foreach ($recentActivities as $act):
                            $actIcon = match($act['activity_type']) {
                                'training_passed' => 'trophy',
                                'training_failed' => 'times-circle',
                                'training_attended' => 'user-check',
                                'course_enrolled' => 'book-open',
                                'course_completed' => 'graduation-cap',
                                'assessment_taken' => 'clipboard-check',
                                'login' => 'sign-in-alt',
                                default => 'circle'
                            };
                            $actColor = match($act['activity_type']) {
                                'training_passed', 'course_completed' => 'success',
                                'training_failed' => 'danger',
                                'training_attended', 'course_enrolled' => 'primary',
                                'assessment_taken' => 'info',
                                default => 'muted'
                            };
                        ?>
                        <div class="activity-item">
                            <div class="activity-dot <?= $actColor ?>"><i class="fas fa-<?= $actIcon ?>"></i></div>
                            <div class="activity-content">
                                <div class="activity-text">
                                    <strong><?= htmlspecialchars($act['full_name'] ?? 'Unknown') ?></strong>
                                    <?= htmlspecialchars($act['details'] ?? $act['activity_type']) ?>
                                </div>
                                <span class="activity-time"><i class="fas fa-clock"></i> <?= date('M d, Y h:i A', strtotime($act['created_at'])) ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php else: ?>
        <!-- Empty State -->
        <div class="empty-state">
            <div class="empty-icon"><i class="fas fa-clipboard-list"></i></div>
            <h3>No Training Selected</h3>
            <p>Please select a training from the dropdown above to view results and evaluate employees.</p>
        </div>
        <?php endif; ?>

    </div><!-- end .container -->
</div><!-- end .main-content -->

<!-- ============================================ -->
<!-- MODALS (rendered outside .main-content) -->
<!-- ============================================ -->

<!-- Evaluate Employee Modal -->
<div class="modal" id="evaluateModal">
    <div class="modal-content animate-in">
        <div class="modal-header">
            <h3><i class="fas fa-star"></i> Evaluate Employee</h3>
            <button type="button" class="modal-close" onclick="closeModal('evaluateModal')">&times;</button>
        </div>
        <form method="POST" id="evaluateForm">
            <input type="hidden" name="training_id" value="<?= $selected_id ?>">
            <input type="hidden" name="employee_id" id="evalEmployeeId">
            <div class="modal-body">
                <!-- Employee Info -->
                <div class="eval-employee">
                    <img src="uploads/avatars/default.png" id="evalAvatar" class="eval-avatar" alt="">
                    <div class="eval-info">
                        <h4 id="evalName">Employee Name</h4>
                        <span id="evalPosition">Position</span>
                    </div>
                </div>

                <div class="eval-training-context">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <span>Training: <strong><?= htmlspecialchars($training_info['title'] ?? 'N/A') ?></strong></span>
                </div>

                <!-- Result Selection -->
                <div class="eval-result-section">
                    <label class="eval-label">Select Evaluation Result</label>
                    <div class="eval-options">
                        <label class="eval-option passed-option">
                            <input type="radio" name="eval_result" value="Passed" id="evalPassed">
                            <div class="option-card">
                                <i class="fas fa-trophy"></i>
                                <span>Passed</span>
                                <small>Employee successfully completed the training</small>
                            </div>
                        </label>
                        <label class="eval-option failed-option">
                            <input type="radio" name="eval_result" value="Failed" id="evalFailed">
                            <div class="option-card">
                                <i class="fas fa-times-circle"></i>
                                <span>Failed</span>
                                <small>Employee did not meet the training requirements</small>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('evaluateModal')">Cancel</button>
                <button type="submit" name="evaluate_employee" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Evaluation
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Employee Training History Modal -->
<div class="modal" id="historyModal">
    <div class="modal-content modal-lg animate-in">
        <div class="modal-header">
            <h3><i class="fas fa-history"></i> Training History</h3>
            <button type="button" class="modal-close" onclick="closeModal('historyModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="history-employee">
                <h4 id="historyName">Employee Name</h4>
            </div>
            <div class="history-summary" id="historySummary"></div>
            <div class="history-timeline" id="historyTimeline"></div>
            <div class="history-empty" id="historyEmpty" style="display:none;">
                <i class="fas fa-folder-open"></i>
                <p>No training history found for this employee.</p>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('historyModal')">Close</button>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay" style="display:none;">
    <div class="loader">
        <div class="spinner"></div>
        <p>Loading...</p>
    </div>
</div>

<!-- Employee Progress Detail Modal -->
<div class="modal" id="progressModal">
    <div class="modal-content modal-lg animate-in">
        <div class="modal-header progress-modal-header">
            <h3><i class="fas fa-chart-line"></i> Employee Progress Detail</h3>
            <button type="button" class="modal-close" onclick="closeModal('progressModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="progress-detail-header" id="progressDetailHeader"></div>
            <div class="progress-detail-metrics" id="progressDetailMetrics"></div>
            <div class="progress-detail-history" id="progressDetailHistory"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('progressModal')">Close</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // ==========================================
    // TABS
    // ==========================================
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabPanels = document.querySelectorAll('.tab-panel');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const tabId = this.dataset.tab;
            tabBtns.forEach(b => b.classList.remove('active'));
            tabPanels.forEach(p => p.classList.remove('active'));
            this.classList.add('active');
            document.getElementById(`${tabId}-tab`)?.classList.add('active');
            localStorage.setItem('m3s2_activeTab', tabId);
        });
    });

    const savedTab = localStorage.getItem('m3s2_activeTab');
    if (savedTab) {
        const btn = document.querySelector(`.tab-btn[data-tab="${savedTab}"]`);
        if (btn) btn.click();
    }

    // ==========================================
    // SEARCH - Results Grid
    // ==========================================
    const resultsSearch = document.getElementById('resultsSearch');
    if (resultsSearch) {
        resultsSearch.addEventListener('input', function() {
            filterResultsGrid();
        });
    }

    const resultStatusFilter = document.getElementById('resultStatusFilter');
    if (resultStatusFilter) {
        resultStatusFilter.addEventListener('change', function() {
            filterResultsGrid();
        });
    }

    function filterResultsGrid() {
        const searchVal = (document.getElementById('resultsSearch')?.value || '').toLowerCase();
        const statusVal = document.getElementById('resultStatusFilter')?.value || '';
        const cards = document.querySelectorAll('.result-card');
        let visible = 0;

        cards.forEach(card => {
            const name = card.dataset.name || '';
            const position = card.dataset.position || '';
            const result = card.dataset.result || 'Pending';
            
            const matchesSearch = name.includes(searchVal) || position.includes(searchVal);
            const matchesStatus = !statusVal || result === statusVal;

            if (matchesSearch && matchesStatus) {
                card.style.display = '';
                visible++;
            } else {
                card.style.display = 'none';
            }
        });
    }

    // ==========================================
    // SEARCH - Tables
    // ==========================================
    function setupTableSearch(inputId, tableId) {
        const input = document.getElementById(inputId);
        const table = document.getElementById(tableId);
        if (input && table) {
            input.addEventListener('input', function() {
                const filter = this.value.toLowerCase();
                table.querySelectorAll('tbody tr').forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
                });
            });
        }
    }

    setupTableSearch('attendanceSearch', 'attendanceTable');
    setupTableSearch('bulkEvalSearch', 'bulkEvalTable');

    // ==========================================
    // BULK RESULT FILTER
    // ==========================================
    const bulkResultFilter = document.getElementById('bulkResultFilter');
    if (bulkResultFilter) {
        bulkResultFilter.addEventListener('change', function() {
            const filter = this.value;
            document.querySelectorAll('.eval-row').forEach(row => {
                const result = row.dataset.result;
                const attended = row.dataset.attended;
                if (!filter) {
                    row.style.display = '';
                } else if (filter === 'Pending') {
                    row.style.display = (attended === 'Yes' && (!result || result === '' || result === 'Pending')) ? '' : 'none';
                } else {
                    row.style.display = result === filter ? '' : 'none';
                }
            });
        });
    }

    // ==========================================
    // EXPORT
    // ==========================================
    document.getElementById('exportBtn')?.addEventListener('click', function() {
        const activePanel = document.querySelector('.tab-panel.active');
        const table = activePanel?.querySelector('.data-table');
        if (!table) {
            // Export from results grid
            const cards = document.querySelectorAll('.result-card');
            if (cards.length === 0) { alert('No data to export'); return; }
            
            let csv = ['"Name","Position","Result","Date"'];
            cards.forEach(card => {
                if (card.style.display === 'none') return;
                const name = card.querySelector('h4')?.textContent?.trim() || '';
                const pos = card.querySelector('.emp-position')?.textContent?.trim() || '';
                const result = card.querySelector('.result-tag')?.textContent?.trim() || '';
                const date = card.querySelector('.attended-date')?.textContent?.trim() || '';
                csv.push(`"${name}","${pos}","${result}","${date}"`);
            });
            downloadCSV(csv.join('\n'));
            return;
        }

        let csv = [];
        table.querySelectorAll('tr').forEach(row => {
            const cols = row.querySelectorAll('th, td');
            const rowData = [];
            cols.forEach(col => {
                let text = col.innerText.replace(/"/g, '""').replace(/\n/g, ' ').trim();
                rowData.push(`"${text}"`);
            });
            csv.push(rowData.join(','));
        });
        downloadCSV(csv.join('\n'));
    });

    function downloadCSV(content) {
        const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `training_results_${new Date().toISOString().split('T')[0]}.csv`;
        link.click();
    }

    // ==========================================
    // AUTO-HIDE TOASTS
    // ==========================================
    document.querySelectorAll('.toast').forEach(toast => {
        toast.style.display = 'flex';
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            setTimeout(() => toast.remove(), 400);
        }, 4000);
    });

    // ==========================================
    // FORM SUBMIT LOADING
    // ==========================================
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function() {
            const btn = this.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            }
        });
    });
});

// ==========================================
// MODAL FUNCTIONS
// ==========================================
function openEvaluateModal(empId, empName, empPosition, empAvatar, currentResult) {
    document.getElementById('evalEmployeeId').value = empId;
    document.getElementById('evalName').textContent = empName;
    document.getElementById('evalPosition').textContent = empPosition;
    document.getElementById('evalAvatar').src = empAvatar;
    document.getElementById('evalAvatar').onerror = function() { this.src = 'uploads/avatars/default.png'; };

    // Pre-select current result
    document.getElementById('evalPassed').checked = (currentResult === 'Passed');
    document.getElementById('evalFailed').checked = (currentResult === 'Failed');

    document.getElementById('evaluateModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function openHistoryModal(empId, empName, history) {
    document.getElementById('historyName').textContent = empName + ' — Training History';
    
    const timeline = document.getElementById('historyTimeline');
    const summary = document.getElementById('historySummary');
    const empty = document.getElementById('historyEmpty');

    if (!history || history.length === 0) {
        timeline.innerHTML = '';
        summary.innerHTML = '';
        empty.style.display = 'block';
        document.getElementById('historyModal').classList.add('active');
        document.body.style.overflow = 'hidden';
        return;
    }

    empty.style.display = 'none';

    // Summary stats
    const totalTrainings = history.length;
    const passedCount = history.filter(h => h.training_result === 'Passed').length;
    const failedCount = history.filter(h => h.training_result === 'Failed').length;
    const pendingCount = history.filter(h => !h.training_result).length;
    const passRate = totalTrainings > 0 ? Math.round((passedCount / totalTrainings) * 100) : 0;

    summary.innerHTML = `
        <div class="history-stat"><h3>${totalTrainings}</h3><p>Total Trainings</p></div>
        <div class="history-stat passed"><h3>${passedCount}</h3><p>Passed</p></div>
        <div class="history-stat failed"><h3>${failedCount}</h3><p>Failed</p></div>
        <div class="history-stat pending"><h3>${pendingCount}</h3><p>Pending</p></div>
        <div class="history-stat rate"><h3>${passRate}%</h3><p>Pass Rate</p></div>
    `;

    // Timeline
    let timelineHTML = '';
    history.forEach(h => {
        const resultClass = h.training_result === 'Passed' ? 'passed' : h.training_result === 'Failed' ? 'failed' : 'pending';
        const resultText = h.training_result || 'Pending';
        const resultIcon = h.training_result === 'Passed' ? 'trophy' : h.training_result === 'Failed' ? 'times-circle' : 'clock';
        const date = h.training_date ? new Date(h.training_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : 'N/A';

        timelineHTML += `
            <div class="timeline-item ${resultClass}">
                <div class="timeline-dot"><i class="fas fa-${resultIcon}"></i></div>
                <div class="timeline-content">
                    <h5>${escapeHtml(h.training_title)}</h5>
                    <div class="timeline-meta">
                        <span><i class="fas fa-calendar"></i> ${date}</span>
                        <span><i class="fas fa-user-tie"></i> ${escapeHtml(h.trainer)}</span>
                        <span><i class="fas fa-tag"></i> ${escapeHtml(h.training_type || 'N/A')}</span>
                    </div>
                    <span class="timeline-result ${resultClass}">
                        <i class="fas fa-${resultIcon}"></i> ${resultText}
                    </span>
                </div>
            </div>
        `;
    });

    timeline.innerHTML = timelineHTML;
    document.getElementById('historyModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}

// Close modal on backdrop click
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});

// Close modal on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(m => closeModal(m.id));
    }
});

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Mark all attendance
function markAll(value) {
    document.querySelectorAll(`#attendanceTable input[type="radio"][value="${value}"]`).forEach(r => {
        r.checked = true;
    });
}

function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.style.display = 'flex';
    toast.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
        <span>${message}</span>
        <button type="button" class="toast-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
    `;
    document.querySelector('.container')?.prepend(toast);
    setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3000);
}

// ==========================================
// EMPLOYEE PROGRESS TAB
// ==========================================

// Progress Search
const progressSearch = document.getElementById('progressSearch');
if (progressSearch) {
    progressSearch.addEventListener('input', filterProgressGrid);
}
const progressDeptFilter = document.getElementById('progressDeptFilter');
if (progressDeptFilter) {
    progressDeptFilter.addEventListener('change', filterProgressGrid);
}
const progressSort = document.getElementById('progressSort');
if (progressSort) {
    progressSort.addEventListener('change', sortProgressGrid);
}

function filterProgressGrid() {
    const search = (document.getElementById('progressSearch')?.value || '').toLowerCase();
    const dept = document.getElementById('progressDeptFilter')?.value || '';
    const cards = document.querySelectorAll('.progress-card');
    cards.forEach(card => {
        const name = card.dataset.name || '';
        const cardDept = card.dataset.dept || '';
        const matchSearch = name.includes(search);
        const matchDept = !dept || cardDept === dept;
        card.style.display = (matchSearch && matchDept) ? '' : 'none';
    });
}

function sortProgressGrid() {
    const sortVal = document.getElementById('progressSort')?.value || 'score-desc';
    const grid = document.getElementById('progressGrid');
    if (!grid) return;
    const cards = Array.from(grid.querySelectorAll('.progress-card'));
    cards.sort((a, b) => {
        switch (sortVal) {
            case 'score-asc': return parseInt(a.dataset.score) - parseInt(b.dataset.score);
            case 'score-desc': return parseInt(b.dataset.score) - parseInt(a.dataset.score);
            case 'name-asc': return a.dataset.name.localeCompare(b.dataset.name);
            case 'training-desc': return parseInt(b.dataset.trainings) - parseInt(a.dataset.trainings);
            default: return 0;
        }
    });
    cards.forEach(card => grid.appendChild(card));
}

function openProgressDetail(prog) {
    const emp = prog.employee;
    const tp = prog.training || {};
    const cp = prog.courses || {};
    const ap = prog.assessments || {};
    const score = prog.overall_score || 0;
    const scoreClass = score >= 80 ? 'excellent' : (score >= 60 ? 'good' : (score >= 40 ? 'fair' : (score > 0 ? 'poor' : 'none')));

    const avatarUrl = emp.avatar && emp.avatar !== 'default.png' 
        ? (emp.avatar.startsWith('uploads/') || emp.avatar.startsWith('http') ? emp.avatar : 'uploads/avatars/' + emp.avatar)
        : 'uploads/avatars/default.png';

    document.getElementById('progressDetailHeader').innerHTML = `
        <div class="detail-emp-card">
            <img src="${avatarUrl}" class="detail-avatar" alt="" onerror="this.src='uploads/avatars/default.png'">
            <div class="detail-emp-info">
                <h3>${escapeHtml(emp.full_name)}</h3>
                <span>${escapeHtml(emp.job_position || 'Employee')}</span>
                <span><i class="fas fa-building"></i> ${escapeHtml(emp.department || 'N/A')}</span>
                <span><i class="fas fa-envelope"></i> ${escapeHtml(emp.email || '')}</span>
                <span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(emp.site || 'N/A')}</span>
            </div>
            <div class="detail-score-circle ${scoreClass}">
                <svg viewBox="0 0 36 36">
                    <path class="score-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                    <path class="score-fill" stroke-dasharray="${score}, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                </svg>
                <span class="score-text">${score}%</span>
                <span class="score-label">Overall</span>
            </div>
        </div>
    `;

    document.getElementById('progressDetailMetrics').innerHTML = `
        <div class="detail-metrics-grid">
            <div class="detail-metric-card training">
                <h4><i class="fas fa-dumbbell"></i> Training</h4>
                <div class="detail-metric-stats">
                    <div class="dm-stat"><span class="dm-val">${parseInt(tp.total_trainings || 0)}</span><span class="dm-label">Enrolled</span></div>
                    <div class="dm-stat"><span class="dm-val">${parseInt(tp.attended_count || 0)}</span><span class="dm-label">Attended</span></div>
                    <div class="dm-stat success"><span class="dm-val">${parseInt(tp.passed_count || 0)}</span><span class="dm-label">Passed</span></div>
                    <div class="dm-stat danger"><span class="dm-val">${parseInt(tp.failed_count || 0)}</span><span class="dm-label">Failed</span></div>
                    <div class="dm-stat warning"><span class="dm-val">${parseInt(tp.pending_count || 0)}</span><span class="dm-label">Pending</span></div>
                </div>
                <div class="detail-progress-bar"><div class="detail-fill training" style="width:${prog.training_score || 0}%"></div></div>
                <span class="detail-pct">${prog.training_score || 0}% Pass Rate</span>
            </div>
            <div class="detail-metric-card courses">
                <h4><i class="fas fa-book-open"></i> Courses</h4>
                <div class="detail-metric-stats">
                    <div class="dm-stat"><span class="dm-val">${parseInt(cp.courses_enrolled || 0)}</span><span class="dm-label">Enrolled</span></div>
                    <div class="dm-stat success"><span class="dm-val">${parseInt(cp.courses_completed || 0)}</span><span class="dm-label">Completed</span></div>
                    <div class="dm-stat"><span class="dm-val">${parseInt(cp.avg_progress || 0)}%</span><span class="dm-label">Avg Progress</span></div>
                </div>
                <div class="detail-progress-bar"><div class="detail-fill courses" style="width:${parseInt(cp.avg_progress || 0)}%"></div></div>
                <span class="detail-pct">${parseInt(cp.avg_progress || 0)}% Course Progress</span>
            </div>
            <div class="detail-metric-card assessments">
                <h4><i class="fas fa-clipboard-check"></i> Assessments</h4>
                <div class="detail-metric-stats">
                    <div class="dm-stat"><span class="dm-val">${parseInt(ap.total_assessments || 0)}</span><span class="dm-label">Completed</span></div>
                </div>
                <div class="detail-progress-bar"><div class="detail-fill assessments" style="width:${parseInt(ap.total_assessments || 0) > 0 ? 100 : 0}%"></div></div>
                <span class="detail-pct">${parseInt(ap.total_assessments || 0) > 0 ? 'Completed' : 'Not Started'}</span>
            </div>
            <div class="detail-metric-card activity">
                <h4><i class="fas fa-sign-in-alt"></i> System Activity</h4>
                <div class="detail-metric-stats">
                    <div class="dm-stat"><span class="dm-val">${parseInt(emp.login_count || 0)}</span><span class="dm-label">Total Logins</span></div>
                    <div class="dm-stat"><span class="dm-val">${emp.last_login ? new Date(emp.last_login).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'}) : 'Never'}</span><span class="dm-label">Last Login</span></div>
                    <div class="dm-stat"><span class="dm-val">${emp.employment_status || 'active'}</span><span class="dm-label">Status</span></div>
                </div>
            </div>
        </div>
    `;

    // Training history
    const history = prog.history || [];
    if (history.length > 0) {
        let histHTML = '<h4 class="detail-section-title"><i class="fas fa-history"></i> Training History</h4><div class="detail-timeline">';
        history.forEach(h => {
            const resultClass = h.training_result === 'Passed' ? 'passed' : h.training_result === 'Failed' ? 'failed' : 'pending';
            const resultIcon = h.training_result === 'Passed' ? 'trophy' : h.training_result === 'Failed' ? 'times-circle' : 'clock';
            const date = h.training_date ? new Date(h.training_date).toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric' }) : 'N/A';
            histHTML += `
                <div class="timeline-item ${resultClass}">
                    <div class="timeline-dot"><i class="fas fa-${resultIcon}"></i></div>
                    <div class="timeline-content">
                        <h5>${escapeHtml(h.training_title)}</h5>
                        <div class="timeline-meta">
                            <span><i class="fas fa-calendar"></i> ${date}</span>
                            <span><i class="fas fa-user-tie"></i> ${escapeHtml(h.trainer)}</span>
                        </div>
                        <span class="timeline-result ${resultClass}"><i class="fas fa-${resultIcon}"></i> ${h.training_result || 'Pending'}</span>
                    </div>
                </div>`;
        });
        histHTML += '</div>';
        document.getElementById('progressDetailHistory').innerHTML = histHTML;
    } else {
        document.getElementById('progressDetailHistory').innerHTML = '<div class="empty-mini"><i class="fas fa-folder-open"></i><p>No training history</p></div>';
    }

    document.getElementById('progressModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
</script>

</body>
</html>

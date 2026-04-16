<?php
/**
 * OSAVE CONVENIENCE STORE - HR2 MERCHFLOW
 * Training Management - Schedules Module
 * Enhanced Version with Full Functionality and Responsive Design
 * With Light/Dark Mode Support
 */

// Include centralized session handler (handles session start, timeout, and activity tracking)
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';
require_once 'Connection/hr1_db.php';

// Initialize HR1 Database connection
$hr1db = new HR1Database();
$hr1Employees = [];
$hr1EmployeesResult = $hr1db->getEmployees('', 500, 0);
if ($hr1EmployeesResult['success']) {
    $hr1Employees = $hr1EmployeesResult['data'];
}

// Create lookup array for HR1 employees by ID
$hr1EmployeesById = [];
foreach ($hr1Employees as $emp) {
    $hr1EmployeesById[$emp['id']] = $emp;
}

$role = $_SESSION['role'] ?? 'employee';
$user_id = (int)$_SESSION['user_id'];

// Admin role guard
$userRole = strtolower(str_replace(' ', '', $role));
if (!in_array($userRole, ['admin', 'manager', 'superadmin'])) {
    header('Location: employee.php');
    exit();
}

$success = "";
$error = "";

// ==========================================
// AJAX HANDLERS
// ==========================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'get_schedule':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $conn->prepare("SELECT * FROM training_schedules WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            echo json_encode(['success' => true, 'data' => $result]);
            exit;
            
        case 'get_attendees':
            $schedule_id = (int)($_GET['schedule_id'] ?? 0);
            
            // Fetch attendance records only (user_id references HR1 employee ID)
            $stmt = $conn->prepare("
                SELECT ta.* 
                FROM training_attendance ta 
                WHERE ta.schedule_id = ?
                ORDER BY ta.id ASC
            ");
            $stmt->bind_param("i", $schedule_id);
            $stmt->execute();
            $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // Enrich with HR1 employee data
            $attendees = [];
            foreach ($records as $record) {
                $empId = (int)$record['user_id'];
                $empData = isset($hr1EmployeesById[$empId]) ? $hr1EmployeesById[$empId] : null;
                
                $attendees[] = [
                    'id' => $record['id'],
                    'schedule_id' => $record['schedule_id'],
                    'user_id' => $record['user_id'],
                    'attended' => $record['attended'],
                    'training_result' => $record['training_result'] ?? null,
                    'date_evaluated' => $record['date_evaluated'] ?? null,
                    'full_name' => $empData ? $empData['name'] : 'Unknown Employee #' . $empId,
                    'email' => $empData ? $empData['email'] : '',
                    'role' => $empData ? $empData['role'] : 'Employee',
                    'department' => $empData ? $empData['department'] : ''
                ];
            }
            echo json_encode(['success' => true, 'data' => $attendees]);
            exit;
            
        case 'toggle_attendance':
            if (!in_array($role, ['admin', 'manager', 'Super Admin'])) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $attendance_id = (int)($data['attendance_id'] ?? 0);
            $attended = $data['attended'] === 'Yes' ? 'No' : 'Yes';
            
            $stmt = $conn->prepare("UPDATE training_attendance SET attended = ? WHERE id = ?");
            $stmt->bind_param("si", $attended, $attendance_id);
            $result = $stmt->execute();
            echo json_encode(['success' => $result, 'new_status' => $attended]);
            exit;
            
        case 'update_result':
            if (!in_array($role, ['admin', 'manager', 'Super Admin'])) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $attendance_id = (int)($data['attendance_id'] ?? 0);
            $result_status = $data['result'] ?? null;
            
            $stmt = $conn->prepare("UPDATE training_attendance SET training_result = ?, date_evaluated = NOW() WHERE id = ?");
            $stmt->bind_param("si", $result_status, $attendance_id);
            $result = $stmt->execute();
            echo json_encode(['success' => $result]);
            exit;
            
        case 'get_calendar_events':
            $month = $_GET['month'] ?? date('Y-m');
            $stmt = $conn->prepare("
                SELECT id, title, date, time, end_time, venue, training_type, training_source,
                       (SELECT COUNT(*) FROM training_attendance WHERE schedule_id = training_schedules.id) as enrolled_count
                FROM training_schedules 
                WHERE DATE_FORMAT(date, '%Y-%m') = ?
                ORDER BY date, time
            ");
            $stmt->bind_param("s", $month);
            $stmt->execute();
            $events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'data' => $events]);
            exit;
            
        case 'enroll_employee':
            try {
                if (!in_array($role, ['admin', 'manager', 'Super Admin'])) {
                    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                    exit;
                }
                
                $data = json_decode(file_get_contents('php://input'), true);
                if (!$data) {
                    echo json_encode(['success' => false, 'message' => 'Invalid request data']);
                    exit;
                }
                
                $schedule_id = (int)($data['schedule_id'] ?? 0);
                $employee_id = (int)($data['employee_id'] ?? 0);
                
                if ($schedule_id <= 0 || $employee_id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid schedule or employee ID']);
                    exit;
                }
                
                // Check if schedule exists
                $schedCheck = $conn->prepare("SELECT id FROM training_schedules WHERE id = ?");
                $schedCheck->bind_param("i", $schedule_id);
                $schedCheck->execute();
                if ($schedCheck->get_result()->num_rows === 0) {
                    echo json_encode(['success' => false, 'message' => 'Training schedule not found']);
                    exit;
                }
                
                // Check if already enrolled
                $check = $conn->prepare("SELECT id FROM training_attendance WHERE schedule_id = ? AND user_id = ?");
                $check->bind_param("ii", $schedule_id, $employee_id);
                $check->execute();
                if ($check->get_result()->num_rows > 0) {
                    echo json_encode(['success' => false, 'message' => 'Employee already enrolled in this training']);
                    exit;
                }
                
                // Temporarily disable foreign key checks for HR1 employee IDs
                $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                
                // Insert enrollment (user_id now stores HR1 employee ID)
                $stmt = $conn->prepare("INSERT INTO training_attendance (schedule_id, user_id, attended, created_at) VALUES (?, ?, 'No', NOW())");
                if (!$stmt) {
                    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
                    exit;
                }
                $stmt->bind_param("ii", $schedule_id, $employee_id);
                $result = $stmt->execute();
                
                // Re-enable foreign key checks
                $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                
                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Employee enrolled successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to enroll: ' . $stmt->error]);
                }
            } catch (Exception $e) {
                $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            exit;
            
        case 'remove_enrollment':
            if (!in_array($role, ['admin', 'manager', 'Super Admin'])) {
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
                exit;
            }
            $data = json_decode(file_get_contents('php://input'), true);
            $attendance_id = (int)($data['attendance_id'] ?? 0);
            
            $stmt = $conn->prepare("DELETE FROM training_attendance WHERE id = ?");
            $stmt->bind_param("i", $attendance_id);
            $result = $stmt->execute();
            echo json_encode(['success' => $result]);
            exit;
    }
}

// ==========================================
// ADD NEW TRAINING SCHEDULE
// ==========================================
if (isset($_POST['add_schedule'])) {
    try {
        $title = trim($_POST['title'] ?? '');
        $trainer = trim($_POST['trainer'] ?? '');
        $date = $_POST['date'] ?? '';
        $time = $_POST['time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $venue = trim($_POST['venue'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $training_type = $_POST['training_type'] ?? 'Theoretical';
        $training_source = $_POST['training_source'] ?? 'Internal';
        $status = $_POST['status'] ?? 'Scheduled';

        // Validation
        if (empty($title) || empty($trainer) || empty($date) || empty($time) || empty($end_time) || empty($venue)) {
            throw new Exception("All required fields must be filled.");
        }

        $start = strtotime($date . ' ' . $time);
        $end = strtotime($date . ' ' . $end_time);
        if ($end <= $start) {
            throw new Exception("End time must be after start time.");
        }

        $stmt = $conn->prepare("
            INSERT INTO training_schedules 
            (title, trainer, date, time, end_time, venue, description, training_type, training_source, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            throw new Exception("Database prepare error: " . $conn->error);
        }

        $stmt->bind_param("sssssssssi", $title, $trainer, $date, $time, $end_time, $venue, $description, $training_type, $training_source, $user_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to add schedule: " . $stmt->error);
        }

        $success = "Training schedule added successfully!";
        $stmt->close();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ==========================================
// EDIT TRAINING SCHEDULE
// ==========================================
if (isset($_POST['edit_schedule'])) {
    try {
        $id = (int)($_POST['schedule_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $trainer = trim($_POST['trainer'] ?? '');
        $date = $_POST['date'] ?? '';
        $time = $_POST['time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $venue = trim($_POST['venue'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $training_type = $_POST['training_type'] ?? 'Theoretical';
        $training_source = $_POST['training_source'] ?? 'Internal';

        if ($id <= 0 || empty($title) || empty($trainer) || empty($date) || empty($time) || empty($end_time) || empty($venue)) {
            throw new Exception("All required fields must be filled with a valid ID.");
        }

        $start = strtotime($date . ' ' . $time);
        $end = strtotime($date . ' ' . $end_time);
        if ($end <= $start) {
            throw new Exception("End time must be after start time.");
        }

        $stmt = $conn->prepare("
            UPDATE training_schedules 
            SET title=?, trainer=?, date=?, time=?, end_time=?, venue=?, description=?, 
                training_type=?, training_source=?
            WHERE id=?
        ");

        if (!$stmt) {
            throw new Exception("Database prepare error: " . $conn->error);
        }

        $stmt->bind_param("sssssssssi", $title, $trainer, $date, $time, $end_time, $venue, $description, $training_type, $training_source, $id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update schedule: " . $stmt->error);
        }

        $success = "Training schedule updated successfully!";
        $stmt->close();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ==========================================
// DELETE TRAINING SCHEDULE
// ==========================================
if (isset($_GET['delete'])) {
    try {
        $id = (int)$_GET['delete'];
        
        if ($id <= 0) {
            throw new Exception("Invalid schedule ID.");
        }

        // Check if there are attendance records
        $check = $conn->prepare("SELECT COUNT(*) as count FROM training_attendance WHERE schedule_id = ?");
        if ($check) {
            $check->bind_param("i", $id);
            $check->execute();
            $result = $check->get_result()->fetch_assoc();
            
            if ($result['count'] > 0) {
                // Delete attendance records first
                $del_attendance = $conn->prepare("DELETE FROM training_attendance WHERE schedule_id = ?");
                $del_attendance->bind_param("i", $id);
                $del_attendance->execute();
            }
        }

        $stmt = $conn->prepare("DELETE FROM training_schedules WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Database prepare error: " . $conn->error);
        }

        $stmt->bind_param("i", $id);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to delete schedule: " . $stmt->error);
        }

        $success = "Training schedule deleted successfully!";
        $stmt->close();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ==========================================
// FETCH STATISTICS
// ==========================================
$stats = [
    'total' => 0,
    'upcoming' => 0,
    'completed' => 0,
    'this_month' => 0,
    'theoretical' => 0,
    'practical' => 0,
    'internal' => 0,
    'external' => 0,
    'total_attendees' => 0,
    'attendance_rate' => 0
];

$stats_query = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN date >= CURDATE() THEN 1 ELSE 0 END) as upcoming,
        SUM(CASE WHEN date < CURDATE() THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE()) THEN 1 ELSE 0 END) as this_month,
        SUM(CASE WHEN training_type = 'Theoretical' THEN 1 ELSE 0 END) as theoretical,
        SUM(CASE WHEN training_type = 'Actual Practices' THEN 1 ELSE 0 END) as practical,
        SUM(CASE WHEN training_source = 'Internal' THEN 1 ELSE 0 END) as internal_training,
        SUM(CASE WHEN training_source = 'External' THEN 1 ELSE 0 END) as external_training
    FROM training_schedules
");

if ($stats_query) {
    $stats = array_merge($stats, $stats_query->fetch_assoc());
}

// Get attendance statistics
$attendance_stats = $conn->query("
    SELECT 
        COUNT(*) as total_enrolled,
        SUM(CASE WHEN attended = 'Yes' THEN 1 ELSE 0 END) as total_attended
    FROM training_attendance
");
if ($attendance_stats) {
    $att = $attendance_stats->fetch_assoc();
    $stats['total_attendees'] = $att['total_enrolled'] ?? 0;
    $stats['attendance_rate'] = $att['total_enrolled'] > 0 
        ? round(($att['total_attended'] / $att['total_enrolled']) * 100, 1) 
        : 0;
}

// Get all employees for enrollment modal - Using HR1 employees (already fetched at top)
// $hr1Employees is already available from the top of the file

// ==========================================
// FETCH SCHEDULES WITH FILTERS
// ==========================================
$search = $_GET['search'] ?? '';
$filter_type = $_GET['filter_type'] ?? '';
$filter_source = $_GET['filter_source'] ?? '';
$filter_month = $_GET['filter_month'] ?? '';
$sort_by = $_GET['sort'] ?? 'date_asc';

$where_clauses = [];
$params = [];
$types = "";

if (!empty($search)) {
    $where_clauses[] = "(ts.title LIKE ? OR ts.trainer LIKE ? OR ts.venue LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($filter_type)) {
    $where_clauses[] = "ts.training_type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

if (!empty($filter_source)) {
    $where_clauses[] = "ts.training_source = ?";
    $params[] = $filter_source;
    $types .= "s";
}

if (!empty($filter_month)) {
    $where_clauses[] = "DATE_FORMAT(ts.date, '%Y-%m') = ?";
    $params[] = $filter_month;
    $types .= "s";
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Sorting
$order_sql = match($sort_by) {
    'date_desc' => "ORDER BY ts.date DESC, ts.time DESC",
    'title_asc' => "ORDER BY ts.title ASC",
    'title_desc' => "ORDER BY ts.title DESC",
    'trainer_asc' => "ORDER BY ts.trainer ASC",
    default => "ORDER BY ts.date ASC, ts.time ASC"
};

$sql = "
    SELECT ts.*, u.full_name,
           (SELECT COUNT(*) FROM training_attendance WHERE schedule_id = ts.id) as enrolled_count
    FROM training_schedules ts 
    LEFT JOIN users u ON ts.created_by = u.id 
    $where_sql 
    $order_sql
";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $schedules = $stmt->get_result();
} else {
    $schedules = $conn->query($sql);
}

// Fetch trainers for dropdown
$trainers = $conn->query("SELECT DISTINCT trainer FROM training_schedules ORDER BY trainer ASC");

// Fetch venues for dropdown
$venues = $conn->query("SELECT DISTINCT venue FROM training_schedules ORDER BY venue ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Training Schedules | Osave HR2</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="Css/module3_sub1.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
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
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="header-text">
                    <h1>Training Schedules</h1>
                    <p>Manage and organize training sessions for employees</p>
                </div>
            </div>
            <?php if (in_array($role, ['admin', 'manager', 'Super Admin'])): ?>
            <div class="header-actions">
                <button type="button" id="openAddModal" class="btn btn-primary">
                    <i class="fas fa-plus"></i>
                    <span>Add Training</span>
                </button>
                <button type="button" id="exportBtn" class="btn btn-outline">
                    <i class="fas fa-download"></i>
                    <span>Export</span>
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card stat-total">
                <div class="stat-icon">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?= number_format($stats['total'] ?? 0) ?></span>
                    <span class="stat-label">Total Trainings</span>
                </div>
            </div>
            <div class="stat-card stat-upcoming">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?= number_format($stats['upcoming'] ?? 0) ?></span>
                    <span class="stat-label">Upcoming</span>
                </div>
            </div>
            <div class="stat-card stat-completed">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?= number_format($stats['completed'] ?? 0) ?></span>
                    <span class="stat-label">Completed</span>
                </div>
            </div>
            <div class="stat-card stat-month">
                <div class="stat-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?= number_format($stats['total_attendees'] ?? 0) ?></span>
                    <span class="stat-label">Total Enrolled</span>
                </div>
            </div>
            <div class="stat-card stat-attendance">
                <div class="stat-icon">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="stat-info">
                    <span class="stat-value"><?= $stats['attendance_rate'] ?? 0 ?>%</span>
                    <span class="stat-label">Attendance Rate</span>
                </div>
            </div>
        </div>

        <!-- Toast Notifications -->
        <?php if (!empty($success)): ?>
        <div class="toast toast-success" id="successToast">
            <i class="fas fa-check-circle"></i>
            <span><?= htmlspecialchars($success) ?></span>
            <button type="button" class="toast-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
        <div class="toast toast-error" id="errorToast">
            <i class="fas fa-exclamation-circle"></i>
            <span><?= htmlspecialchars($error) ?></span>
            <button type="button" class="toast-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>

        <!-- Filters & Search Toolbar -->
        <div class="toolbar">
            <form method="GET" class="toolbar-form" id="filterForm">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Search trainings..." 
                           value="<?= htmlspecialchars($search) ?>">
                </div>

                <div class="filter-group">
                    <select name="filter_type" class="filter-select">
                        <option value="">All Types</option>
                        <option value="Theoretical" <?= $filter_type === 'Theoretical' ? 'selected' : '' ?>>Theoretical</option>
                        <option value="Actual Practices" <?= $filter_type === 'Actual Practices' ? 'selected' : '' ?>>Practical</option>
                    </select>

                    <select name="filter_source" class="filter-select">
                        <option value="">All Sources</option>
                        <option value="Internal" <?= $filter_source === 'Internal' ? 'selected' : '' ?>>Internal</option>
                        <option value="External" <?= $filter_source === 'External' ? 'selected' : '' ?>>External</option>
                    </select>

                    <input type="month" name="filter_month" class="filter-select" 
                           value="<?= htmlspecialchars($filter_month) ?>" placeholder="Month">

                    <select name="sort" class="filter-select">
                        <option value="date_asc" <?= $sort_by === 'date_asc' ? 'selected' : '' ?>>Date (Oldest)</option>
                        <option value="date_desc" <?= $sort_by === 'date_desc' ? 'selected' : '' ?>>Date (Newest)</option>
                        <option value="title_asc" <?= $sort_by === 'title_asc' ? 'selected' : '' ?>>Title (A-Z)</option>
                        <option value="title_desc" <?= $sort_by === 'title_desc' ? 'selected' : '' ?>>Title (Z-A)</option>
                    </select>
                </div>

                <div class="toolbar-actions">
                    <button type="submit" class="btn btn-secondary">
                        <i class="fas fa-filter"></i>
                        <span>Apply</span>
                    </button>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-ghost">
                        <i class="fas fa-redo"></i>
                        <span>Reset</span>
                    </a>
                </div>

                <!-- View Toggle -->
                <div class="view-toggle">
                    <button type="button" class="view-btn active" data-view="table" title="Table View">
                        <i class="fas fa-list"></i>
                    </button>
                    <button type="button" class="view-btn" data-view="grid" title="Card View">
                        <i class="fas fa-th-large"></i>
                    </button>
                    <button type="button" class="view-btn" data-view="calendar" title="Calendar View">
                        <i class="fas fa-calendar-alt"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- Results Count -->
        <div class="results-info">
            <span class="results-count">
                <i class="fas fa-list-ul"></i>
                Showing <strong><?= $schedules ? $schedules->num_rows : 0 ?></strong> training(s)
            </span>
            <?php if (!empty($search) || !empty($filter_type) || !empty($filter_source) || !empty($filter_month)): ?>
            <span class="active-filters">
                Active filters:
                <?php if (!empty($search)): ?>
                    <span class="filter-tag">Search: "<?= htmlspecialchars($search) ?>"</span>
                <?php endif; ?>
                <?php if (!empty($filter_type)): ?>
                    <span class="filter-tag">Type: <?= htmlspecialchars($filter_type) ?></span>
                <?php endif; ?>
                <?php if (!empty($filter_source)): ?>
                    <span class="filter-tag">Source: <?= htmlspecialchars($filter_source) ?></span>
                <?php endif; ?>
            </span>
            <?php endif; ?>
        </div>

        <!-- Table View -->
        <div class="table-container" id="tableView">
            <?php if (!$schedules || $schedules->num_rows === 0): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fas fa-calendar-xmark"></i>
                </div>
                <h3>No Training Schedules Found</h3>
                <p>There are no training schedules matching your criteria.</p>
                <?php if (in_array($role, ['admin', 'manager', 'Super Admin'])): ?>
                <button type="button" id="openAddModalEmpty" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create First Training
                </button>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="col-title">Training</th>
                            <th class="col-trainer">Trainer</th>
                            <th class="col-type">Type</th>
                            <th class="col-source">Source</th>
                            <th class="col-datetime">Date & Time</th>
                            <th class="col-venue">Venue</th>
                            <th class="col-enrolled">Enrolled</th>
                            <?php if (in_array($role, ['admin', 'manager', 'Super Admin'])): ?>
                            <th class="col-actions">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $schedules->data_seek(0);
                        while ($row = $schedules->fetch_assoc()): 
                            $title = $row['title'] ?? 'N/A';
                            $trainer = $row['trainer'] ?? 'N/A';
                            $training_type = $row['training_type'] ?? 'Theoretical';
                            $training_source = $row['training_source'] ?? 'Internal';
                            $date_formatted = $row['date'] ? date("M d, Y", strtotime($row['date'])) : 'N/A';
                            $time_formatted = $row['time'] ? date("h:i A", strtotime($row['time'])) : '';
                            $end_time_formatted = $row['end_time'] ? date("h:i A", strtotime($row['end_time'])) : '';
                            $venue = $row['venue'] ?? 'N/A';
                            $description = $row['description'] ?? '';
                            $enrolled = $row['enrolled_count'] ?? 0;
                            $created_by = $row['full_name'] ?? 'Unknown';

                            // Type class
                            $type_class = strtolower($training_type) === 'theoretical' ? 'badge-theoretical' : 'badge-practical';
                            
                            // Source class
                            $source_class = strtolower($training_source) === 'internal' ? 'badge-internal' : 'badge-external';

                            // Check if training date is past
                            $is_past = strtotime($row['date']) < strtotime(date('Y-m-d'));
                        ?>
                        <tr class="<?= $is_past ? 'row-past' : '' ?>" data-id="<?= $row['id'] ?>">
                            <td data-label="Training">
                                <div class="training-info">
                                    <span class="training-title"><?= htmlspecialchars($title) ?></span>
                                    <?php if (!empty($description)): ?>
                                    <span class="training-desc"><?= htmlspecialchars(substr($description, 0, 60)) ?>...</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td data-label="Trainer">
                                <div class="trainer-info">
                                    <i class="fas fa-user-tie"></i>
                                    <span><?= htmlspecialchars($trainer) ?></span>
                                </div>
                            </td>
                            <td data-label="Type">
                                <span class="badge <?= $type_class ?>">
                                    <i class="fas <?= $training_type === 'Theoretical' ? 'fa-book' : 'fa-hands' ?>"></i>
                                    <?= htmlspecialchars($training_type) ?>
                                </span>
                            </td>
                            <td data-label="Source">
                                <span class="badge <?= $source_class ?>">
                                    <i class="fas <?= $training_source === 'Internal' ? 'fa-building' : 'fa-globe' ?>"></i>
                                    <?= htmlspecialchars($training_source) ?>
                                </span>
                            </td>
                            <td data-label="Date & Time">
                                <div class="datetime-info">
                                    <span class="date">
                                        <i class="fas fa-calendar"></i>
                                        <?= htmlspecialchars($date_formatted) ?>
                                    </span>
                                    <span class="time">
                                        <i class="fas fa-clock"></i>
                                        <?= htmlspecialchars($time_formatted) ?> - <?= htmlspecialchars($end_time_formatted) ?>
                                    </span>
                                </div>
                            </td>
                            <td data-label="Venue">
                                <div class="venue-info">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?= htmlspecialchars($venue) ?></span>
                                </div>
                            </td>
                            <td data-label="Enrolled">
                                <div class="enrolled-info">
                                    <i class="fas fa-users"></i>
                                    <span><?= $enrolled ?></span>
                                </div>
                            </td>
                            <?php if (in_array($role, ['admin', 'manager', 'Super Admin'])): ?>
                            <td data-label="Actions">
                                <div class="action-buttons">
                                    <button type="button" class="btn-icon btn-view openAttendeesModal" title="View Attendees"
                                        data-id="<?= (int)$row['id'] ?>"
                                        data-title="<?= htmlspecialchars($title) ?>">
                                        <i class="fas fa-users"></i>
                                    </button>
                                    <button type="button" class="btn-icon btn-edit openEditModal" title="Edit"
                                        data-id="<?= (int)$row['id'] ?>"
                                        data-title="<?= htmlspecialchars($title) ?>"
                                        data-trainer="<?= htmlspecialchars($trainer) ?>"
                                        data-training_type="<?= htmlspecialchars($training_type) ?>"
                                        data-training_source="<?= htmlspecialchars($training_source) ?>"
                                        data-date="<?= htmlspecialchars($row['date'] ?? '') ?>"
                                        data-time="<?= htmlspecialchars($row['time'] ?? '') ?>"
                                        data-end_time="<?= htmlspecialchars($row['end_time'] ?? '') ?>"
                                        data-venue="<?= htmlspecialchars($venue) ?>"
                                        data-description="<?= htmlspecialchars($description) ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn-icon btn-delete delete-schedule-btn" 
                                        data-id="<?= (int)$row['id'] ?>" 
                                        data-title="<?= htmlspecialchars($row['title'] ?? 'Training') ?>" 
                                        title="Delete">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Grid View (Cards) -->
        <div class="grid-container" id="gridView" style="display: none;">
            <?php if ($schedules && $schedules->num_rows > 0): ?>
            <div class="training-grid">
                <?php 
                $schedules->data_seek(0);
                while ($row = $schedules->fetch_assoc()): 
                    $type_class = strtolower($row['training_type'] ?? '') === 'theoretical' ? 'badge-theoretical' : 'badge-practical';
                    $is_past = strtotime($row['date']) < strtotime(date('Y-m-d'));
                ?>
                <div class="training-card <?= $is_past ? 'card-past' : '' ?>">
                    <div class="card-header">
                        <span class="badge <?= $type_class ?>"><?= htmlspecialchars($row['training_type'] ?? 'N/A') ?></span>
                        <?php if ($is_past): ?>
                        <span class="badge badge-completed">Completed</span>
                        <?php else: ?>
                        <span class="badge badge-upcoming">Upcoming</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <h3 class="card-title"><?= htmlspecialchars($row['title'] ?? 'N/A') ?></h3>
                        <div class="card-meta">
                            <div class="meta-item">
                                <i class="fas fa-user-tie"></i>
                                <span><?= htmlspecialchars($row['trainer'] ?? 'N/A') ?></span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-calendar"></i>
                                <span><?= $row['date'] ? date("M d, Y", strtotime($row['date'])) : 'N/A' ?></span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-clock"></i>
                                <span>
                                    <?= $row['time'] ? date("h:i A", strtotime($row['time'])) : '' ?>
                                    - <?= $row['end_time'] ? date("h:i A", strtotime($row['end_time'])) : '' ?>
                                </span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?= htmlspecialchars($row['venue'] ?? 'N/A') ?></span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-users"></i>
                                <span><?= $row['enrolled_count'] ?? 0 ?> Enrolled</span>
                            </div>
                        </div>
                        <?php if (!empty($row['description'])): ?>
                        <p class="card-description"><?= htmlspecialchars(substr($row['description'], 0, 100)) ?>...</p>
                        <?php endif; ?>
                    </div>
                    <?php if (in_array($role, ['admin', 'manager', 'Super Admin'])): ?>
                    <div class="card-footer">
                        <button type="button" class="btn btn-sm btn-secondary openAttendeesModal"
                            data-id="<?= (int)$row['id'] ?>"
                            data-title="<?= htmlspecialchars($row['title'] ?? '') ?>">
                            <i class="fas fa-users"></i> Attendees
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary openEditModal"
                            data-id="<?= (int)$row['id'] ?>"
                            data-title="<?= htmlspecialchars($row['title'] ?? '') ?>"
                            data-trainer="<?= htmlspecialchars($row['trainer'] ?? '') ?>"
                            data-training_type="<?= htmlspecialchars($row['training_type'] ?? '') ?>"
                            data-training_source="<?= htmlspecialchars($row['training_source'] ?? '') ?>"
                            data-date="<?= htmlspecialchars($row['date'] ?? '') ?>"
                            data-time="<?= htmlspecialchars($row['time'] ?? '') ?>"
                            data-end_time="<?= htmlspecialchars($row['end_time'] ?? '') ?>"
                            data-venue="<?= htmlspecialchars($row['venue'] ?? '') ?>"
                            data-description="<?= htmlspecialchars($row['description'] ?? '') ?>">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button type="button" class="btn btn-sm btn-danger delete-schedule-btn"
                            data-id="<?= (int)$row['id'] ?>"
                            data-title="<?= htmlspecialchars($row['title'] ?? 'Training') ?>">
                            <i class="fas fa-trash-alt"></i> Delete
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endwhile; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Calendar View -->
        <div class="calendar-container" id="calendarView" style="display: none;">
            <div class="calendar-header">
                <button type="button" class="btn btn-ghost calendar-nav" id="prevMonth">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <h3 id="calendarTitle"><?= date('F Y') ?></h3>
                <button type="button" class="btn btn-ghost calendar-nav" id="nextMonth">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
            <div class="calendar-grid">
                <div class="calendar-weekdays">
                    <div class="weekday">Sun</div>
                    <div class="weekday">Mon</div>
                    <div class="weekday">Tue</div>
                    <div class="weekday">Wed</div>
                    <div class="weekday">Thu</div>
                    <div class="weekday">Fri</div>
                    <div class="weekday">Sat</div>
                </div>
                <div class="calendar-days" id="calendarDays">
                    <!-- Days will be populated by JavaScript -->
                </div>
            </div>
            <div class="calendar-legend">
                <div class="legend-item">
                    <span class="legend-color legend-theoretical"></span>
                    <span>Theoretical</span>
                </div>
                <div class="legend-item">
                    <span class="legend-color legend-practical"></span>
                    <span>Practical</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Attendees Modal -->
<div id="attendeesModal" class="modal">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-users"></i>
                <h3>Training Attendees</h3>
            </div>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="attendees-header">
                <h4 id="attendeesTrainingTitle">Training Title</h4>
                <button type="button" class="btn btn-primary btn-sm" id="openEnrollBtn">
                    <i class="fas fa-user-plus"></i> Enroll Employee
                </button>
            </div>
            <div class="attendees-stats">
                <div class="mini-stat">
                    <span class="mini-stat-value" id="totalEnrolled">0</span>
                    <span class="mini-stat-label">Enrolled</span>
                </div>
                <div class="mini-stat">
                    <span class="mini-stat-value" id="totalAttended">0</span>
                    <span class="mini-stat-label">Attended</span>
                </div>
                <div class="mini-stat">
                    <span class="mini-stat-value" id="totalPassed">0</span>
                    <span class="mini-stat-label">Passed</span>
                </div>
            </div>
            <div class="attendees-table-wrapper">
                <table class="attendees-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Role</th>
                            <th>Attended</th>
                            <th>Result</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="attendeesTableBody">
                        <!-- Populated by JavaScript -->
                    </tbody>
                </table>
                <div class="attendees-empty" id="attendeesEmpty" style="display: none;">
                    <i class="fas fa-user-slash"></i>
                    <p>No employees enrolled yet</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Enroll Employee Modal -->
<div id="enrollModal" class="modal">
    <div class="modal-content" style="max-width: 700px;">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-users"></i>
                <h3>Bulk Enroll Employees</h3>
            </div>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="employeeSearchInput">
                    <i class="fas fa-search"></i> Search HR1 Employees
                </label>
                <input type="text" id="employeeSearchInput" class="filter-select" style="width: 100%; margin-bottom: 10px;" 
                       placeholder="Type to search by name, role or department...">
            </div>
            <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                <button type="button" class="btn btn-sm btn-secondary" id="selectAllEmployees">
                    <i class="fas fa-check-double"></i> Select All Visible
                </button>
                <button type="button" class="btn btn-sm btn-ghost" id="clearAllEmployees">
                    <i class="fas fa-times"></i> Clear Selection
                </button>
                <span style="margin-left: auto; color: var(--text-secondary); font-size: 0.85rem; display: flex; align-items: center;">
                    <i class="fas fa-info-circle" style="margin-right: 5px;"></i>
                    Hold Ctrl/Cmd to select multiple
                </span>
            </div>
            <div class="form-group">
                <label for="enrollEmployeeSelect">
                    <i class="fas fa-user"></i> Select Employees 
                    <span style="color: var(--primary); font-weight: 700;" id="selectedCount">(0 selected)</span>
                    <span style="color: var(--text-tertiary);">- <?= count($hr1Employees) ?> available</span>
                </label>
                <select id="enrollEmployeeSelect" class="filter-select" style="width: 100%;" size="10" multiple>
                    <?php foreach ($hr1Employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" 
                            data-name="<?= htmlspecialchars(strtolower($emp['name'])) ?>"
                            data-role="<?= htmlspecialchars(strtolower($emp['role'] ?? '')) ?>"
                            data-dept="<?= htmlspecialchars(strtolower($emp['department'] ?? '')) ?>">
                        <?= htmlspecialchars($emp['name']) ?> - <?= htmlspecialchars($emp['role'] ?? 'Employee') ?> (<?= htmlspecialchars($emp['department'] ?? 'N/A') ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-ghost modal-cancel">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmEnrollBtn">
                    <i class="fas fa-user-plus"></i> Enroll Selected
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Training Modal -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-plus-circle"></i>
                <h3>Add New Training Schedule</h3>
            </div>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <form method="POST" class="training-form">
            <div class="form-grid">
                <div class="form-group full-width">
                    <label for="add_title">
                        <i class="fas fa-heading"></i> Training Title <span class="required">*</span>
                    </label>
                    <input type="text" id="add_title" name="title" placeholder="Enter training title" required>
                </div>

                <div class="form-group">
                    <label for="add_trainer">
                        <i class="fas fa-user-tie"></i> Trainer <span class="required">*</span>
                    </label>
                    <input type="text" id="add_trainer" name="trainer" placeholder="Trainer name" required list="trainer_list">
                    <datalist id="trainer_list">
                        <?php if ($trainers): while ($t = $trainers->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($t['trainer']) ?>">
                        <?php endwhile; endif; ?>
                    </datalist>
                </div>

                <div class="form-group">
                    <label for="add_venue">
                        <i class="fas fa-map-marker-alt"></i> Venue <span class="required">*</span>
                    </label>
                    <input type="text" id="add_venue" name="venue" placeholder="Training location" required list="venue_list">
                    <datalist id="venue_list">
                        <?php 
                        if ($venues) {
                            $venues->data_seek(0);
                            while ($v = $venues->fetch_assoc()): 
                        ?>
                            <option value="<?= htmlspecialchars($v['venue']) ?>">
                        <?php endwhile; } ?>
                    </datalist>
                </div>

                <div class="form-group">
                    <label for="add_training_type">
                        <i class="fas fa-book"></i> Training Type <span class="required">*</span>
                    </label>
                    <select id="add_training_type" name="training_type" required>
                        <option value="Theoretical">Theoretical</option>
                        <option value="Actual Practices">Actual Practices</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="add_training_source">
                        <i class="fas fa-building"></i> Training Source <span class="required">*</span>
                    </label>
                    <select id="add_training_source" name="training_source" required>
                        <option value="Internal">Internal</option>
                        <option value="External">External</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="add_date">
                        <i class="fas fa-calendar"></i> Date <span class="required">*</span>
                    </label>
                    <input type="date" id="add_date" name="date" required>
                </div>

                <div class="form-group">
                    <label for="add_time">
                        <i class="fas fa-clock"></i> Start Time <span class="required">*</span>
                    </label>
                    <input type="time" id="add_time" name="time" required>
                </div>

                <div class="form-group">
                    <label for="add_end_time">
                        <i class="fas fa-clock"></i> End Time <span class="required">*</span>
                    </label>
                    <input type="time" id="add_end_time" name="end_time" required>
                </div>

                <div class="form-group full-width">
                    <label for="add_description">
                        <i class="fas fa-align-left"></i> Description
                    </label>
                    <textarea id="add_description" name="description" rows="4" placeholder="Training description and objectives..."></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-ghost modal-cancel">Cancel</button>
                <button type="submit" name="add_schedule" class="btn btn-primary">
                    <i class="fas fa-save"></i> Create Training
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Training Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-edit"></i>
                <h3>Edit Training Schedule</h3>
            </div>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <form method="POST" class="training-form">
            <input type="hidden" name="schedule_id" id="edit_id">
            
            <div class="form-grid">
                <div class="form-group full-width">
                    <label for="edit_title">
                        <i class="fas fa-heading"></i> Training Title <span class="required">*</span>
                    </label>
                    <input type="text" id="edit_title" name="title" placeholder="Enter training title" required>
                </div>

                <div class="form-group">
                    <label for="edit_trainer">
                        <i class="fas fa-user-tie"></i> Trainer <span class="required">*</span>
                    </label>
                    <input type="text" id="edit_trainer" name="trainer" placeholder="Trainer name" required>
                </div>

                <div class="form-group">
                    <label for="edit_venue">
                        <i class="fas fa-map-marker-alt"></i> Venue <span class="required">*</span>
                    </label>
                    <input type="text" id="edit_venue" name="venue" placeholder="Training location" required>
                </div>

                <div class="form-group">
                    <label for="edit_training_type">
                        <i class="fas fa-book"></i> Training Type <span class="required">*</span>
                    </label>
                    <select id="edit_training_type" name="training_type" required>
                        <option value="Theoretical">Theoretical</option>
                        <option value="Actual Practices">Actual Practices</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit_training_source">
                        <i class="fas fa-building"></i> Training Source <span class="required">*</span>
                    </label>
                    <select id="edit_training_source" name="training_source" required>
                        <option value="Internal">Internal</option>
                        <option value="External">External</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit_date">
                        <i class="fas fa-calendar"></i> Date <span class="required">*</span>
                    </label>
                    <input type="date" id="edit_date" name="date" required>
                </div>

                <div class="form-group">
                    <label for="edit_time">
                        <i class="fas fa-clock"></i> Start Time <span class="required">*</span>
                    </label>
                    <input type="time" id="edit_time" name="time" required>
                </div>

                <div class="form-group">
                    <label for="edit_end_time">
                        <i class="fas fa-clock"></i> End Time <span class="required">*</span>
                    </label>
                    <input type="time" id="edit_end_time" name="end_time" required>
                </div>

                <div class="form-group full-width">
                    <label for="edit_description">
                        <i class="fas fa-align-left"></i> Description
                    </label>
                    <textarea id="edit_description" name="description" rows="4" placeholder="Training description and objectives..."></textarea>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-ghost modal-cancel">Cancel</button>
                <button type="submit" name="edit_schedule" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header" style="background: var(--danger-light); border-color: var(--danger);">
            <div class="modal-title">
                <i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i>
                <h3 style="color: var(--danger);">Delete Training Schedule</h3>
            </div>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body" style="text-align: center; padding: 30px;">
            <div style="margin-bottom: 20px;">
                <i class="fas fa-trash-alt" style="font-size: 48px; color: var(--danger); opacity: 0.8;"></i>
            </div>
            <p style="font-size: 1rem; color: var(--text-primary); margin-bottom: 10px;">
                Are you sure you want to delete:
            </p>
            <p style="font-size: 1.1rem; font-weight: 700; color: var(--danger); margin-bottom: 20px;" id="deleteScheduleTitle">
                Training Title
            </p>
            <p style="font-size: 0.85rem; color: var(--text-tertiary);">
                This action cannot be undone. All attendance records for this training will also be deleted.
            </p>
            <input type="hidden" id="deleteScheduleId" value="">
            <div class="form-actions" style="margin-top: 24px;">
                <button type="button" class="btn btn-secondary modal-cancel">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="fas fa-trash-alt"></i> Delete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Remove Enrollment Modal -->
<div id="removeEnrollmentModal" class="modal">
    <div class="modal-content" style="max-width: 420px;">
        <div class="modal-header" style="background: var(--warning-light); border-color: var(--warning);">
            <div class="modal-title">
                <i class="fas fa-user-minus" style="color: var(--warning-dark);"></i>
                <h3 style="color: var(--warning-dark);">Remove Employee</h3>
            </div>
            <button type="button" class="modal-close">&times;</button>
        </div>
        <div class="modal-body" style="text-align: center; padding: 30px;">
            <div style="margin-bottom: 20px;">
                <i class="fas fa-user-times" style="font-size: 48px; color: var(--warning); opacity: 0.8;"></i>
            </div>
            <p style="font-size: 1rem; color: var(--text-primary); margin-bottom: 10px;">
                Remove this employee from training?
            </p>
            <p style="font-size: 1.1rem; font-weight: 700; color: var(--warning-dark); margin-bottom: 20px;" id="removeEmployeeName">
                Employee Name
            </p>
            <p style="font-size: 0.85rem; color: var(--text-tertiary);">
                Their attendance and results will be deleted.
            </p>
            <input type="hidden" id="removeEnrollmentId" value="">
            <div class="form-actions" style="margin-top: 24px;">
                <button type="button" class="btn btn-secondary modal-cancel">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-danger" id="confirmRemoveBtn">
                    <i class="fas fa-user-minus"></i> Remove
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner">
        <div class="spinner"></div>
        <p>Loading...</p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ==========================================
    // MODAL FUNCTIONALITY
    // ==========================================
    const addModal = document.getElementById('addModal');
    const editModal = document.getElementById('editModal');
    const attendeesModal = document.getElementById('attendeesModal');
    const enrollModal = document.getElementById('enrollModal');
    const deleteModal = document.getElementById('deleteModal');
    const removeEnrollmentModal = document.getElementById('removeEnrollmentModal');
    
    let currentScheduleId = null;
    let currentCalendarDate = new Date();
    
    // Open Add Modal
    const openAddBtns = document.querySelectorAll('#openAddModal, #openAddModalEmpty');
    openAddBtns.forEach(btn => {
        if (btn) {
            btn.addEventListener('click', () => {
                addModal.classList.add('active');
                document.body.style.overflow = 'hidden';
            });
        }
    });

    // Handle Delete Schedule - Open Modal
    document.addEventListener('click', function(e) {
        const deleteBtn = e.target.closest('.delete-schedule-btn');
        if (deleteBtn) {
            e.preventDefault();
            e.stopPropagation();
            
            const scheduleId = deleteBtn.dataset.id;
            const scheduleTitle = deleteBtn.dataset.title || 'Training Schedule';
            
            document.getElementById('deleteScheduleId').value = scheduleId;
            document.getElementById('deleteScheduleTitle').textContent = scheduleTitle;
            deleteModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    });
    
    // Confirm Delete
    document.getElementById('confirmDeleteBtn')?.addEventListener('click', function() {
        const scheduleId = document.getElementById('deleteScheduleId').value;
        if (scheduleId) {
            window.location.href = '?delete=' + scheduleId;
        }
    });

    // Open Edit Modal
    document.addEventListener('click', function(e) {
        const editBtn = e.target.closest('.openEditModal');
        if (editBtn) {
            editModal.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Populate form fields
            document.getElementById('edit_id').value = editBtn.dataset.id || '';
            document.getElementById('edit_title').value = editBtn.dataset.title || '';
            document.getElementById('edit_trainer').value = editBtn.dataset.trainer || '';
            document.getElementById('edit_training_type').value = editBtn.dataset.training_type || 'Theoretical';
            document.getElementById('edit_training_source').value = editBtn.dataset.training_source || 'Internal';
            document.getElementById('edit_date').value = editBtn.dataset.date || '';
            document.getElementById('edit_time').value = editBtn.dataset.time || '';
            document.getElementById('edit_end_time').value = editBtn.dataset.end_time || '';
            document.getElementById('edit_venue').value = editBtn.dataset.venue || '';
            document.getElementById('edit_description').value = editBtn.dataset.description || '';
        }
    });
    
    // Open Attendees Modal
    document.addEventListener('click', function(e) {
        const attendeesBtn = e.target.closest('.openAttendeesModal');
        if (attendeesBtn) {
            currentScheduleId = attendeesBtn.dataset.id;
            document.getElementById('attendeesTrainingTitle').textContent = attendeesBtn.dataset.title || 'Training';
            attendeesModal.classList.add('active');
            document.body.style.overflow = 'hidden';
            loadAttendees(currentScheduleId);
        }
    });
    
    // Open Enroll Modal
    document.getElementById('openEnrollBtn')?.addEventListener('click', function() {
        enrollModal.classList.add('active');
        document.getElementById('employeeSearchInput').value = '';
        filterEmployeeOptions('');
    });
    
    // Employee Search Filter
    document.getElementById('employeeSearchInput')?.addEventListener('input', function() {
        filterEmployeeOptions(this.value.toLowerCase());
    });
    
    function filterEmployeeOptions(searchTerm) {
        const select = document.getElementById('enrollEmployeeSelect');
        const options = select.querySelectorAll('option');
        
        options.forEach(option => {
            const name = option.dataset.name || '';
            const role = option.dataset.role || '';
            const dept = option.dataset.dept || '';
            
            const matches = name.includes(searchTerm) || 
                           role.includes(searchTerm) || 
                           dept.includes(searchTerm) ||
                           searchTerm === '';
            
            option.style.display = matches ? '' : 'none';
        });
    }
    
    // Update selected count
    function updateSelectedCount() {
        const select = document.getElementById('enrollEmployeeSelect');
        const selectedCount = select.selectedOptions.length;
        document.getElementById('selectedCount').textContent = `(${selectedCount} selected)`;
    }
    
    document.getElementById('enrollEmployeeSelect')?.addEventListener('change', updateSelectedCount);
    
    // Select All Visible
    document.getElementById('selectAllEmployees')?.addEventListener('click', function() {
        const select = document.getElementById('enrollEmployeeSelect');
        const options = select.querySelectorAll('option');
        options.forEach(option => {
            if (option.style.display !== 'none') {
                option.selected = true;
            }
        });
        updateSelectedCount();
    });
    
    // Clear All Selection
    document.getElementById('clearAllEmployees')?.addEventListener('click', function() {
        const select = document.getElementById('enrollEmployeeSelect');
        select.selectedIndex = -1;
        updateSelectedCount();
    });
    
    // Confirm Bulk Enroll
    document.getElementById('confirmEnrollBtn')?.addEventListener('click', async function() {
        const select = document.getElementById('enrollEmployeeSelect');
        const selectedOptions = Array.from(select.selectedOptions);
        const employeeIds = selectedOptions.map(opt => parseInt(opt.value));
        
        if (!currentScheduleId) {
            showToast('No training schedule selected', 'error');
            return;
        }
        
        if (employeeIds.length === 0) {
            showToast('Please select at least one employee', 'error');
            return;
        }
        
        // Disable button during request
        this.disabled = true;
        this.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Enrolling ${employeeIds.length} employee(s)...`;
        
        let successCount = 0;
        let failCount = 0;
        
        try {
            // Enroll each employee
            for (const employeeId of employeeIds) {
                try {
                    const response = await fetch('?action=enroll_employee', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            schedule_id: parseInt(currentScheduleId), 
                            employee_id: employeeId 
                        })
                    });
                    
                    const result = await response.json();
                    if (result.success) {
                        successCount++;
                    } else {
                        failCount++;
                    }
                } catch (err) {
                    failCount++;
                }
            }
            
            if (successCount > 0) {
                showToast(`Successfully enrolled ${successCount} employee(s)` + (failCount > 0 ? `, ${failCount} failed/already enrolled` : ''), 'success');
                enrollModal.classList.remove('active');
                document.body.style.overflow = '';
                document.getElementById('enrollEmployeeSelect').selectedIndex = -1;
                document.getElementById('employeeSearchInput').value = '';
                filterEmployeeOptions('');
                updateSelectedCount();
                loadAttendees(currentScheduleId);
            } else {
                showToast(`Failed to enroll employees. They may already be enrolled.`, 'error');
            }
        } catch (err) {
            console.error('Enrollment error:', err);
            showToast('Error enrolling employees: ' + err.message, 'error');
        } finally {
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-user-plus"></i> Enroll Selected';
        }
    });

    // Close modals
    const closeButtons = document.querySelectorAll('.modal-close, .modal-cancel');
    closeButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = this.closest('.modal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
                // Reset employee search on close
                if (modal.id === 'enrollModal') {
                    document.getElementById('employeeSearchInput').value = '';
                    document.getElementById('enrollEmployeeSelect').selectedIndex = -1;
                    filterEmployeeOptions('');
                    updateSelectedCount();
                }
            }
        });
    });

    // Close on outside click
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    });
    
    // ==========================================
    // LOAD ATTENDEES
    // ==========================================
    async function loadAttendees(scheduleId) {
        const tbody = document.getElementById('attendeesTableBody');
        const emptyState = document.getElementById('attendeesEmpty');
        
        tbody.innerHTML = '<tr><td colspan="5" class="loading-cell"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
        
        try {
            const response = await fetch(`?action=get_attendees&schedule_id=${scheduleId}`);
            const result = await response.json();
            
            if (result.success && result.data.length > 0) {
                emptyState.style.display = 'none';
                tbody.innerHTML = '';
                
                let totalAttended = 0;
                let totalPassed = 0;
                
                result.data.forEach(attendee => {
                    if (attendee.attended === 'Yes') totalAttended++;
                    if (attendee.training_result === 'Passed') totalPassed++;
                    
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>
                            <div class="attendee-info">
                                <span class="attendee-name">${escapeHtml(attendee.full_name)}</span>
                                <span class="attendee-email">${escapeHtml(attendee.email || '')}</span>
                            </div>
                        </td>
                        <td>${escapeHtml(attendee.role || 'Employee')}</td>
                        <td>
                            <button type="button" class="badge ${attendee.attended === 'Yes' ? 'badge-success' : 'badge-warning'} toggle-attendance"
                                data-id="${attendee.id}" data-attended="${attendee.attended}">
                                ${attendee.attended === 'Yes' ? '<i class="fas fa-check"></i> Yes' : '<i class="fas fa-times"></i> No'}
                            </button>
                        </td>
                        <td>
                            <select class="result-select" data-id="${attendee.id}">
                                <option value="">-- Select --</option>
                                <option value="Passed" ${attendee.training_result === 'Passed' ? 'selected' : ''}>Passed</option>
                                <option value="Failed" ${attendee.training_result === 'Failed' ? 'selected' : ''}>Failed</option>
                            </select>
                        </td>
                        <td>
                            <button type="button" class="btn-icon btn-delete remove-enrollment" 
                                data-id="${attendee.id}" 
                                data-name="${escapeHtml(attendee.full_name)}" 
                                title="Remove">
                                <i class="fas fa-user-minus"></i>
                            </button>
                        </td>
                    `;
                    tbody.appendChild(row);
                });
                
                document.getElementById('totalEnrolled').textContent = result.data.length;
                document.getElementById('totalAttended').textContent = totalAttended;
                document.getElementById('totalPassed').textContent = totalPassed;
                
            } else {
                tbody.innerHTML = '';
                emptyState.style.display = 'flex';
                document.getElementById('totalEnrolled').textContent = '0';
                document.getElementById('totalAttended').textContent = '0';
                document.getElementById('totalPassed').textContent = '0';
            }
        } catch (err) {
            tbody.innerHTML = '<tr><td colspan="5" class="error-cell">Error loading attendees</td></tr>';
        }
    }
    
    // Toggle Attendance
    document.getElementById('attendeesTableBody')?.addEventListener('click', async function(e) {
        const toggleBtn = e.target.closest('.toggle-attendance');
        if (toggleBtn) {
            const id = toggleBtn.dataset.id;
            const currentStatus = toggleBtn.dataset.attended;
            
            try {
                const response = await fetch('?action=toggle_attendance', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ attendance_id: id, attended: currentStatus })
                });
                const result = await response.json();
                
                if (result.success) {
                    toggleBtn.dataset.attended = result.new_status;
                    toggleBtn.className = `badge ${result.new_status === 'Yes' ? 'badge-success' : 'badge-warning'} toggle-attendance`;
                    toggleBtn.innerHTML = result.new_status === 'Yes' 
                        ? '<i class="fas fa-check"></i> Yes' 
                        : '<i class="fas fa-times"></i> No';
                    showToast('Attendance updated', 'success');
                }
            } catch (err) {
                showToast('Error updating attendance', 'error');
            }
        }
        
        // Remove enrollment - Show Modal
        const removeBtn = e.target.closest('.remove-enrollment');
        if (removeBtn) {
            const enrollmentId = removeBtn.dataset.id;
            const employeeName = removeBtn.dataset.name || 'This Employee';
            
            document.getElementById('removeEnrollmentId').value = enrollmentId;
            document.getElementById('removeEmployeeName').textContent = employeeName;
            removeEnrollmentModal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    });
    
    // Confirm Remove Enrollment
    document.getElementById('confirmRemoveBtn')?.addEventListener('click', async function() {
        const enrollmentId = document.getElementById('removeEnrollmentId').value;
        if (!enrollmentId) return;
        
        this.disabled = true;
        this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Removing...';
        
        try {
            const response = await fetch('?action=remove_enrollment', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ attendance_id: enrollmentId })
            });
            const result = await response.json();
            
            if (result.success) {
                showToast('Employee removed from training', 'success');
                removeEnrollmentModal.classList.remove('active');
                document.body.style.overflow = '';
                loadAttendees(currentScheduleId);
            } else {
                showToast(result.error || 'Error removing employee', 'error');
            }
        } catch (err) {
            showToast('Error removing employee', 'error');
        }
        
        this.disabled = false;
        this.innerHTML = '<i class="fas fa-user-minus"></i> Remove';
    });
    
    // Update Result
    document.getElementById('attendeesTableBody')?.addEventListener('change', async function(e) {
        if (e.target.classList.contains('result-select')) {
            const id = e.target.dataset.id;
            const resultValue = e.target.value;
            
            try {
                const response = await fetch('?action=update_result', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ attendance_id: id, result: resultValue })
                });
                const result = await response.json();
                
                if (result.success) {
                    showToast('Result updated', 'success');
                }
            } catch (err) {
                showToast('Error updating result', 'error');
            }
        }
    });

    // ==========================================
    // VIEW TOGGLE
    // ==========================================
    const viewBtns = document.querySelectorAll('.view-btn');
    const tableView = document.getElementById('tableView');
    const gridView = document.getElementById('gridView');
    const calendarView = document.getElementById('calendarView');

    viewBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            viewBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            const view = this.dataset.view;
            tableView.style.display = view === 'table' ? 'block' : 'none';
            gridView.style.display = view === 'grid' ? 'block' : 'none';
            calendarView.style.display = view === 'calendar' ? 'block' : 'none';
            
            if (view === 'calendar') {
                renderCalendar();
            }
            
            localStorage.setItem('trainingView', view);
        });
    });

    // Restore saved view preference
    const savedView = localStorage.getItem('trainingView') || 'table';
    document.querySelector(`.view-btn[data-view="${savedView}"]`)?.click();
    
    // ==========================================
    // CALENDAR FUNCTIONALITY
    // ==========================================
    async function renderCalendar() {
        const year = currentCalendarDate.getFullYear();
        const month = currentCalendarDate.getMonth();
        
        document.getElementById('calendarTitle').textContent = 
            currentCalendarDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const startingDay = firstDay.getDay();
        const totalDays = lastDay.getDate();
        
        // Fetch events for this month
        const monthStr = `${year}-${String(month + 1).padStart(2, '0')}`;
        let events = [];
        try {
            const response = await fetch(`?action=get_calendar_events&month=${monthStr}`);
            const result = await response.json();
            if (result.success) events = result.data;
        } catch (err) {
            console.error('Error fetching calendar events');
        }
        
        const calendarDays = document.getElementById('calendarDays');
        calendarDays.innerHTML = '';
        
        // Empty cells before first day
        for (let i = 0; i < startingDay; i++) {
            const emptyCell = document.createElement('div');
            emptyCell.className = 'calendar-day empty';
            calendarDays.appendChild(emptyCell);
        }
        
        // Days of month
        const today = new Date();
        for (let day = 1; day <= totalDays; day++) {
            const dayCell = document.createElement('div');
            dayCell.className = 'calendar-day';
            
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const dayEvents = events.filter(e => e.date === dateStr);
            
            // Check if today
            if (year === today.getFullYear() && month === today.getMonth() && day === today.getDate()) {
                dayCell.classList.add('today');
            }
            
            // Check if past
            if (new Date(dateStr) < new Date(today.toDateString())) {
                dayCell.classList.add('past');
            }
            
            let eventsHtml = '';
            dayEvents.forEach(event => {
                const typeClass = event.training_type === 'Theoretical' ? 'event-theoretical' : 'event-practical';
                eventsHtml += `
                    <div class="calendar-event ${typeClass}" title="${escapeHtml(event.title)}">
                        <span class="event-time">${formatTime(event.time)}</span>
                        <span class="event-title">${escapeHtml(event.title.substring(0, 15))}${event.title.length > 15 ? '...' : ''}</span>
                    </div>
                `;
            });
            
            dayCell.innerHTML = `
                <span class="day-number">${day}</span>
                <div class="day-events">${eventsHtml}</div>
            `;
            
            calendarDays.appendChild(dayCell);
        }
    }
    
    document.getElementById('prevMonth')?.addEventListener('click', function() {
        currentCalendarDate.setMonth(currentCalendarDate.getMonth() - 1);
        renderCalendar();
    });
    
    document.getElementById('nextMonth')?.addEventListener('click', function() {
        currentCalendarDate.setMonth(currentCalendarDate.getMonth() + 1);
        renderCalendar();
    });

    // ==========================================
    // EXPORT FUNCTIONALITY
    // ==========================================
    document.getElementById('exportBtn')?.addEventListener('click', function() {
        const table = document.querySelector('.data-table');
        if (!table) {
            showToast('No data to export', 'error');
            return;
        }

        let csv = [];
        const rows = table.querySelectorAll('tr');
        
        rows.forEach(row => {
            const cols = row.querySelectorAll('th, td');
            const rowData = [];
            cols.forEach((col, index) => {
                // Skip actions column
                if (index < cols.length - 1 || row.closest('thead')) {
                    let text = col.innerText.replace(/"/g, '""').trim();
                    rowData.push(`"${text}"`);
                }
            });
            if (rowData.length > 0) {
                csv.push(rowData.join(','));
            }
        });

        const csvContent = csv.join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `training_schedules_${new Date().toISOString().split('T')[0]}.csv`;
        link.click();
        
        showToast('Training schedules exported successfully!', 'success');
    });

    // ==========================================
    // UTILITY FUNCTIONS
    // ==========================================
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
            <span>${message}</span>
            <button type="button" class="toast-close"><i class="fas fa-times"></i></button>
        `;
        document.body.appendChild(toast);

        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 4000);

        toast.querySelector('.toast-close').addEventListener('click', () => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        });
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }
    
    function formatTime(timeStr) {
        if (!timeStr) return '';
        const [hours, minutes] = timeStr.split(':');
        const h = parseInt(hours);
        const ampm = h >= 12 ? 'PM' : 'AM';
        const h12 = h % 12 || 12;
        return `${h12}:${minutes} ${ampm}`;
    }

    // Auto-hide existing toasts
    document.querySelectorAll('.toast').forEach(toast => {
        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    });

    // ==========================================
    // TIME VALIDATION
    // ==========================================
    function validateTimes(startId, endId) {
        const startTime = document.getElementById(startId);
        const endTime = document.getElementById(endId);
        
        if (startTime && endTime) {
            endTime.addEventListener('change', function() {
                if (startTime.value && this.value <= startTime.value) {
                    showToast('End time must be after start time', 'error');
                    this.value = '';
                }
            });
        }
    }

    validateTimes('add_time', 'add_end_time');
    validateTimes('edit_time', 'edit_end_time');

    // ==========================================
    // KEYBOARD SHORTCUTS
    // ==========================================
    document.addEventListener('keydown', function(e) {
        // ESC to close modals
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.active').forEach(modal => {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            });
        }
        
        // Ctrl+N to add new training
        if (e.ctrlKey && e.key === 'n') {
            e.preventDefault();
            document.getElementById('openAddModal')?.click();
        }
    });

    // ==========================================
    // SEARCH ON ENTER
    // ==========================================
    const searchInput = document.querySelector('.search-box input');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('filterForm').submit();
            }
        });
    }
});
</script>

</body>
</html>
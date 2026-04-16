<?php
/**
 * EMPLOYEE DASHBOARD - HR2 MerchFlow
 * Rich dashboard with module shortcuts, analytics, announcements & activity
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

// Get user info
$employee_id = (int)$_SESSION['user_id'];
$from_hr1 = isset($_SESSION['from_hr1']) && $_SESSION['from_hr1'] === true;
$from_employee_table = isset($_SESSION['from_employee_table']) && $_SESSION['from_employee_table'] === true;
$hr1_employee_id = $_SESSION['hr1_employee_id'] ?? null;
$lookup_id = $hr1_employee_id ?? $employee_id;

// Fetch employee details
$employee = null;
if ($from_employee_table) {
    $ue_stmt = $conn->prepare("SELECT full_name, job_position, department, site, avatar FROM users_employee WHERE id = ?");
    $ue_stmt->bind_param("i", $employee_id);
    $ue_stmt->execute();
    $employee = $ue_stmt->get_result()->fetch_assoc();
    $ue_stmt->close();
}
if (!$employee) {
    $stmt = $conn->prepare("SELECT full_name, avatar FROM users WHERE id = ?");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $employee = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
if (!$employee) {
    $employee = ['full_name' => $_SESSION['full_name'] ?? 'Employee'];
}

$firstName = explode(' ', trim($employee['full_name'] ?? 'Employee'))[0];
$jobPosition = $employee['job_position'] ?? 'Employee';
$department = $employee['department'] ?? '';
$site = $employee['site'] ?? '';
$avatar = $employee['avatar'] ?? null;

// ===== TABLE CHECKS =====
$trainingTableExists = $conn->query("SHOW TABLES LIKE 'training_attendance'")->num_rows > 0;
$schedulesTableExists = $conn->query("SHOW TABLES LIKE 'training_schedules'")->num_rows > 0;
$courseProgressTableExists = $conn->query("SHOW TABLES LIKE 'course_progress'")->num_rows > 0;
$evaluationsTableExists = $conn->query("SHOW TABLES LIKE 'evaluations'")->num_rows > 0;
$announcementsTableExists = $conn->query("SHOW TABLES LIKE 'announcements'")->num_rows > 0;
$requestsTableExists = $conn->query("SHOW TABLES LIKE 'employee_requests'")->num_rows > 0;
$coursesTableExists = $conn->query("SHOW TABLES LIKE 'courses'")->num_rows > 0;

// ===== STAT METRICS =====
$totalTrainings = 0;
$totalAttendance = 0;
$totalAssessments = 0;
$totalCoursesCompleted = 0;
$totalCourses = 0;
$coursesInProgress = 0;
$upcomingTrainings = 0;
$unreadAnnouncements = 0;
$pendingRequests = 0;
$attendanceRate = 0;

// Training stats
if ($trainingTableExists) {
    $r = $conn->prepare("SELECT COUNT(*) as c FROM training_attendance WHERE user_id = ?");
    $r->bind_param("i", $lookup_id); $r->execute();
    $totalTrainings = $r->get_result()->fetch_assoc()['c'] ?? 0; $r->close();

    $r = $conn->prepare("SELECT COUNT(*) as c FROM training_attendance WHERE user_id = ? AND attended = 'Yes'");
    $r->bind_param("i", $lookup_id); $r->execute();
    $totalAttendance = $r->get_result()->fetch_assoc()['c'] ?? 0; $r->close();

    $attendanceRate = $totalTrainings > 0 ? round(($totalAttendance / $totalTrainings) * 100) : 0;
}

// Evaluations
if ($evaluationsTableExists) {
    $r = $conn->prepare("SELECT COUNT(*) as c FROM evaluations WHERE employee_id = ?");
    $r->bind_param("i", $lookup_id); $r->execute();
    $totalAssessments = $r->get_result()->fetch_assoc()['c'] ?? 0; $r->close();
}

// Course stats
if ($courseProgressTableExists) {
    $r = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN watched_percent = 100 THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN watched_percent > 0 AND watched_percent < 100 THEN 1 ELSE 0 END) as in_progress FROM course_progress WHERE employee_id = ?");
    $r->bind_param("i", $employee_id); $r->execute();
    $courseData = $r->get_result()->fetch_assoc(); $r->close();
    $totalCourses = (int)($courseData['total'] ?? 0);
    $totalCoursesCompleted = (int)($courseData['completed'] ?? 0);
    $coursesInProgress = (int)($courseData['in_progress'] ?? 0);
}
$coursesNotStarted = $totalCourses > 0 ? max(0, $totalCourses - $totalCoursesCompleted - $coursesInProgress) : 0;
$courseCompletionRate = $totalCourses > 0 ? round(($totalCoursesCompleted / $totalCourses) * 100) : 0;

// Upcoming trainings count
if ($schedulesTableExists) {
    $r = $conn->prepare("SELECT COUNT(DISTINCT ts.id) as c FROM training_schedules ts LEFT JOIN training_attendance ta ON ts.id = ta.schedule_id AND ta.user_id = ? WHERE ts.date >= CURDATE() AND (ta.id IS NULL OR ta.attended = 'No')");
    $r->bind_param("i", $lookup_id); $r->execute();
    $upcomingTrainings = $r->get_result()->fetch_assoc()['c'] ?? 0; $r->close();
}

// Unread announcements
if ($announcementsTableExists) {
    $now = date('Y-m-d H:i:s');
    $readTableExists = $conn->query("SHOW TABLES LIKE 'announcement_reads'")->num_rows > 0;
    if ($readTableExists) {
        $r = $conn->query("SELECT COUNT(*) as c FROM announcements a LEFT JOIN announcement_reads ar ON a.id = ar.announcement_id AND ar.user_id = {$employee_id} WHERE ar.id IS NULL AND (a.target_audience IN ('all','employees')) AND (a.published_at IS NULL OR a.published_at <= '{$now}') AND (a.expires_at IS NULL OR a.expires_at >= '{$now}')");
        $unreadAnnouncements = $r->fetch_assoc()['c'] ?? 0;
    }
}

// Pending requests
if ($requestsTableExists) {
    $r = $conn->prepare("SELECT COUNT(*) as c FROM employee_requests WHERE employee_id = ? AND status = 'pending'");
    $r->bind_param("i", $employee_id); $r->execute();
    $pendingRequests = $r->get_result()->fetch_assoc()['c'] ?? 0; $r->close();
}

// ===== RECENT ANNOUNCEMENTS (Latest 5) =====
$recentAnnouncements = [];
if ($announcementsTableExists) {
    $now = date('Y-m-d H:i:s');
    $annResult = $conn->query("
        SELECT a.id, a.title, a.content, a.category, a.priority, a.is_pinned, a.created_at,
               u.full_name as author_name
        FROM announcements a
        LEFT JOIN users u ON a.created_by = u.id
        WHERE (a.target_audience IN ('all','employees'))
        AND (a.published_at IS NULL OR a.published_at <= '{$now}')
        AND (a.expires_at IS NULL OR a.expires_at >= '{$now}')
        ORDER BY a.is_pinned DESC, a.created_at DESC
        LIMIT 5
    ");
    if ($annResult) $recentAnnouncements = $annResult->fetch_all(MYSQLI_ASSOC);
}

// ===== UPCOMING TRAINING LIST (Next 5) =====
$upcomingList = [];
if ($schedulesTableExists) {
    // Check which columns exist
    $schCols = [];
    $colResult = $conn->query("SHOW COLUMNS FROM training_schedules");
    while ($c = $colResult->fetch_assoc()) $schCols[] = $c['Field'];

    $titleCol = in_array('title', $schCols) ? 'ts.title' : (in_array('training_title', $schCols) ? 'ts.training_title' : "'Training'");
    $dateCol = in_array('date', $schCols) ? 'ts.date' : (in_array('training_date', $schCols) ? 'ts.training_date' : 'ts.created_at');
    $timeCol = in_array('time', $schCols) ? ', ts.time' : (in_array('start_time', $schCols) ? ', ts.start_time as time' : '');
    $venueCol = in_array('venue', $schCols) ? ', ts.venue' : (in_array('location', $schCols) ? ', ts.location as venue' : '');

    $upResult = $conn->query("
        SELECT ts.id, {$titleCol} as title, {$dateCol} as training_date {$timeCol} {$venueCol}
        FROM training_schedules ts
        WHERE {$dateCol} >= CURDATE()
        ORDER BY {$dateCol} ASC
        LIMIT 5
    ");
    if ($upResult) $upcomingList = $upResult->fetch_all(MYSQLI_ASSOC);
}

// ===== RECENT REQUESTS (Latest 5) =====
$recentRequests = [];
if ($requestsTableExists) {
    $r = $conn->prepare("SELECT id, request_type, title, status, priority, created_at FROM employee_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT 5");
    $r->bind_param("i", $employee_id); $r->execute();
    $recentRequests = $r->get_result()->fetch_all(MYSQLI_ASSOC); $r->close();
}

// Greeting based on time
$hour = (int)date('H');
if ($hour < 12) $greeting = 'Good Morning';
elseif ($hour < 17) $greeting = 'Good Afternoon';
else $greeting = 'Good Evening';

// Category helpers
function getDashCategoryInfo($cat) {
    $map = [
        'general' => ['icon' => 'fa-info-circle', 'color' => '#3b82f6', 'label' => 'General'],
        'urgent' => ['icon' => 'fa-exclamation-triangle', 'color' => '#ef4444', 'label' => 'Urgent'],
        'event' => ['icon' => 'fa-calendar', 'color' => '#8b5cf6', 'label' => 'Event'],
        'policy' => ['icon' => 'fa-gavel', 'color' => '#f59e0b', 'label' => 'Policy'],
        'holiday' => ['icon' => 'fa-umbrella-beach', 'color' => '#10b981', 'label' => 'Holiday'],
    ];
    return $map[$cat] ?? $map['general'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Employee Dashboard - HR2 MerchFlow</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="Css/emp.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="Css/ai_chat_bubble.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<?php include 'partials/sidebar.php'; ?>

<div class="main-content">
    <?php include 'partials/nav.php'; ?>

    <div class="container">

        <!-- ===== WELCOME BANNER ===== -->
        <div class="welcome-banner">
            <div class="welcome-left">
                <div class="welcome-avatar">
                    <?php if ($avatar): ?>
                        <img src="<?php echo htmlspecialchars($avatar); ?>" alt="Avatar">
                    <?php else: ?>
                        <div class="avatar-initials"><?php echo strtoupper(substr($firstName, 0, 1)); ?></div>
                    <?php endif; ?>
                    <span class="online-dot"></span>
                </div>
                <div class="welcome-text">
                    <h1><?php echo $greeting; ?>, <?php echo htmlspecialchars($firstName); ?>! 👋</h1>
                    <p class="welcome-subtitle">
                        <?php echo htmlspecialchars($jobPosition); ?>
                        <?php if ($department): ?> &bull; <?php echo htmlspecialchars($department); ?><?php endif; ?>
                        <?php if ($site): ?> &bull; <?php echo htmlspecialchars($site); ?><?php endif; ?>
                    </p>
                    <p class="welcome-date"><i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?></p>
                </div>
            </div>
            <div class="welcome-right">
                <?php if ($unreadAnnouncements > 0): ?>
                    <a href="module5_sub8.php" class="welcome-badge badge-alert">
                        <i class="fas fa-bell"></i>
                        <span><?php echo $unreadAnnouncements; ?> unread announcement<?php echo $unreadAnnouncements > 1 ? 's' : ''; ?></span>
                    </a>
                <?php endif; ?>
                <?php if ($pendingRequests > 0): ?>
                    <a href="module5_sub7.php" class="welcome-badge badge-pending">
                        <i class="fas fa-clock"></i>
                        <span><?php echo $pendingRequests; ?> pending request<?php echo $pendingRequests > 1 ? 's' : ''; ?></span>
                    </a>
                <?php endif; ?>
                <?php if ($upcomingTrainings > 0): ?>
                    <a href="module5_sub4.php" class="welcome-badge badge-info">
                        <i class="fas fa-dumbbell"></i>
                        <span><?php echo $upcomingTrainings; ?> upcoming training<?php echo $upcomingTrainings > 1 ? 's' : ''; ?></span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- ===== STATS OVERVIEW ===== -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon icon-primary"><i class="fas fa-graduation-cap"></i></div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo $totalTrainings; ?></span>
                    <span class="stat-label">Total Trainings</span>
                </div>
                <div class="stat-trend <?php echo $attendanceRate >= 75 ? 'trend-up' : ($attendanceRate >= 50 ? 'trend-neutral' : 'trend-down'); ?>">
                    <i class="fas fa-<?php echo $attendanceRate >= 75 ? 'arrow-up' : ($attendanceRate >= 50 ? 'minus' : 'arrow-down'); ?>"></i>
                    <?php echo $attendanceRate; ?>% rate
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-success"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo $totalAttendance; ?></span>
                    <span class="stat-label">Sessions Attended</span>
                </div>
                <div class="stat-trend trend-up"><i class="fas fa-calendar-check"></i> completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-info"><i class="fas fa-clipboard-check"></i></div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo $totalAssessments; ?></span>
                    <span class="stat-label">Evaluations</span>
                </div>
                <div class="stat-trend trend-neutral"><i class="fas fa-chart-line"></i> reviews</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon icon-accent"><i class="fas fa-play-circle"></i></div>
                <div class="stat-info">
                    <span class="stat-value"><?php echo $totalCoursesCompleted; ?><span class="stat-total">/<?php echo $totalCourses; ?></span></span>
                    <span class="stat-label">Courses Completed</span>
                </div>
                <div class="stat-trend <?php echo $courseCompletionRate >= 75 ? 'trend-up' : 'trend-neutral'; ?>">
                    <i class="fas fa-percentage"></i> <?php echo $courseCompletionRate; ?>%
                </div>
            </div>
        </div>

        <!-- ===== QUICK ACCESS MODULES ===== -->
        <div class="section-header">
            <h2><i class="fas fa-th-large"></i> Quick Access</h2>
            <p class="section-subtitle">Navigate to your modules</p>
        </div>
        <div class="quick-access-grid">
            <a href="module5_sub8.php" class="quick-card qc-announcements">
                <div class="quick-icon"><i class="fas fa-bullhorn"></i></div>
                <div class="quick-info">
                    <h3>Announcements</h3>
                    <p>Company news & updates</p>
                </div>
                <?php if ($unreadAnnouncements > 0): ?>
                    <span class="quick-badge"><?php echo $unreadAnnouncements; ?></span>
                <?php endif; ?>
                <i class="fas fa-chevron-right quick-arrow"></i>
            </a>
            <a href="module5_sub1.php" class="quick-card qc-profile">
                <div class="quick-icon"><i class="fas fa-user-circle"></i></div>
                <div class="quick-info">
                    <h3>My Profile</h3>
                    <p>View & edit your info</p>
                </div>
                <i class="fas fa-chevron-right quick-arrow"></i>
            </a>
            <a href="module5_sub7.php" class="quick-card qc-requests">
                <div class="quick-icon"><i class="fas fa-paper-plane"></i></div>
                <div class="quick-info">
                    <h3>My Requests</h3>
                    <p>Leave, documents & more</p>
                </div>
                <?php if ($pendingRequests > 0): ?>
                    <span class="quick-badge badge-yellow"><?php echo $pendingRequests; ?></span>
                <?php endif; ?>
                <i class="fas fa-chevron-right quick-arrow"></i>
            </a>
            <a href="module5_sub3.php" class="quick-card qc-courses">
                <div class="quick-icon"><i class="fas fa-book-open"></i></div>
                <div class="quick-info">
                    <h3>My Courses</h3>
                    <p>Online learning modules</p>
                </div>
                <?php if ($coursesInProgress > 0): ?>
                    <span class="quick-badge badge-blue"><?php echo $coursesInProgress; ?> in progress</span>
                <?php endif; ?>
                <i class="fas fa-chevron-right quick-arrow"></i>
            </a>
            <a href="module5_sub4.php" class="quick-card qc-training">
                <div class="quick-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                <div class="quick-info">
                    <h3>My Training</h3>
                    <p>Schedules & attendance</p>
                </div>
                <?php if ($upcomingTrainings > 0): ?>
                    <span class="quick-badge badge-green"><?php echo $upcomingTrainings; ?> upcoming</span>
                <?php endif; ?>
                <i class="fas fa-chevron-right quick-arrow"></i>
            </a>
            <a href="module5_sub2.php" class="quick-card qc-evaluations">
                <div class="quick-icon"><i class="fas fa-chart-line"></i></div>
                <div class="quick-info">
                    <h3>My Evaluations</h3>
                    <p>Performance reviews</p>
                </div>
                <i class="fas fa-chevron-right quick-arrow"></i>
            </a>
            <a href="module5_sub5.php" class="quick-card qc-assessments">
                <div class="quick-icon"><i class="fas fa-clipboard-check"></i></div>
                <div class="quick-info">
                    <h3>My Assessments</h3>
                    <p>Quizzes & skill tests</p>
                </div>
                <i class="fas fa-chevron-right quick-arrow"></i>
            </a>
            <a href="module5_sub6.php" class="quick-card qc-documents">
                <div class="quick-icon"><i class="fas fa-folder-open"></i></div>
                <div class="quick-info">
                    <h3>My Documents</h3>
                    <p>Files & certificates</p>
                </div>
                <i class="fas fa-chevron-right quick-arrow"></i>
            </a>
        </div>

        <!-- ===== ANALYTICS + SIDEBAR SECTIONS ===== -->
        <div class="dashboard-grid">

            <!-- LEFT COLUMN: Analytics -->
            <div class="dashboard-left">

                <!-- Course Progress Chart -->
                <div class="section-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Course Progress</h3>
                        <span class="header-stat"><?php echo $courseCompletionRate; ?>% Complete</span>
                    </div>
                    <div class="card-body">
                        <?php if ($totalCourses > 0): ?>
                        <div class="chart-row">
                            <div class="chart-wrapper">
                                <canvas id="courseChart" width="200" height="200"></canvas>
                            </div>
                            <div class="chart-legend">
                                <div class="legend-item">
                                    <span class="legend-dot dot-completed"></span>
                                    <span class="legend-label">Completed</span>
                                    <span class="legend-value"><?php echo $totalCoursesCompleted; ?></span>
                                </div>
                                <div class="legend-item">
                                    <span class="legend-dot dot-progress"></span>
                                    <span class="legend-label">In Progress</span>
                                    <span class="legend-value"><?php echo $coursesInProgress; ?></span>
                                </div>
                                <div class="legend-item">
                                    <span class="legend-dot dot-notstarted"></span>
                                    <span class="legend-label">Not Started</span>
                                    <span class="legend-value"><?php echo $coursesNotStarted; ?></span>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-book-open"></i>
                            <p>No courses assigned yet</p>
                            <a href="module5_sub3.php" class="btn-link">Browse Courses <i class="fas fa-arrow-right"></i></a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Training Attendance Chart -->
                <div class="section-card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-bar"></i> Training Overview</h3>
                        <span class="header-stat"><?php echo $attendanceRate; ?>% Attendance</span>
                    </div>
                    <div class="card-body">
                        <?php if ($totalTrainings > 0): ?>
                        <div class="chart-row">
                            <div class="chart-wrapper">
                                <canvas id="trainingChart" width="280" height="180"></canvas>
                            </div>
                            <div class="chart-legend">
                                <div class="legend-item">
                                    <span class="legend-dot dot-attended"></span>
                                    <span class="legend-label">Attended</span>
                                    <span class="legend-value"><?php echo $totalAttendance; ?></span>
                                </div>
                                <div class="legend-item">
                                    <span class="legend-dot dot-missed"></span>
                                    <span class="legend-label">Missed</span>
                                    <span class="legend-value"><?php echo $totalTrainings - $totalAttendance; ?></span>
                                </div>
                                <div class="legend-item">
                                    <span class="legend-dot dot-upcoming"></span>
                                    <span class="legend-label">Upcoming</span>
                                    <span class="legend-value"><?php echo $upcomingTrainings; ?></span>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-dumbbell"></i>
                            <p>No training records yet</p>
                            <a href="module5_sub4.php" class="btn-link">View Training <i class="fas fa-arrow-right"></i></a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN: Feeds -->
            <div class="dashboard-right">

                <!-- Recent Announcements -->
                <div class="section-card">
                    <div class="card-header">
                        <h3><i class="fas fa-bullhorn"></i> Latest Announcements</h3>
                        <a href="module5_sub8.php" class="header-link">View All <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="card-body feed-body">
                        <?php if (!empty($recentAnnouncements)): ?>
                            <?php foreach ($recentAnnouncements as $ann):
                                $catInfo = getDashCategoryInfo($ann['category'] ?? 'general');
                            ?>
                            <a href="module5_sub8.php?mark_read=<?php echo $ann['id']; ?>" class="feed-item">
                                <div class="feed-icon" style="color: <?php echo $catInfo['color']; ?>; background: <?php echo $catInfo['color']; ?>15;">
                                    <i class="fas <?php echo $catInfo['icon']; ?>"></i>
                                </div>
                                <div class="feed-content">
                                    <h4><?php echo htmlspecialchars($ann['title']); ?></h4>
                                    <p class="feed-meta">
                                        <span class="feed-category" style="color: <?php echo $catInfo['color']; ?>;"><?php echo $catInfo['label']; ?></span>
                                        &bull; <?php echo date('M j', strtotime($ann['created_at'])); ?>
                                        <?php if ($ann['author_name']): ?> &bull; <?php echo htmlspecialchars($ann['author_name']); ?><?php endif; ?>
                                    </p>
                                </div>
                                <?php if ($ann['is_pinned']): ?>
                                    <span class="feed-pin"><i class="fas fa-thumbtack"></i></span>
                                <?php endif; ?>
                            </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state small">
                                <i class="fas fa-bullhorn"></i>
                                <p>No announcements yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upcoming Trainings -->
                <div class="section-card">
                    <div class="card-header">
                        <h3><i class="fas fa-calendar-alt"></i> Upcoming Trainings</h3>
                        <a href="module5_sub4.php" class="header-link">View All <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="card-body feed-body">
                        <?php if (!empty($upcomingList)): ?>
                            <?php foreach ($upcomingList as $tr): ?>
                            <div class="feed-item training-item">
                                <div class="training-date-mini">
                                    <span class="td-month"><?php echo date('M', strtotime($tr['training_date'])); ?></span>
                                    <span class="td-day"><?php echo date('d', strtotime($tr['training_date'])); ?></span>
                                </div>
                                <div class="feed-content">
                                    <h4><?php echo htmlspecialchars($tr['title'] ?? 'Training Session'); ?></h4>
                                    <p class="feed-meta">
                                        <i class="far fa-clock"></i> <?php echo isset($tr['time']) ? date('h:i A', strtotime($tr['time'])) : 'TBA'; ?>
                                        <?php if (isset($tr['venue']) && $tr['venue']): ?>
                                            &bull; <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($tr['venue']); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state small">
                                <i class="fas fa-calendar-check"></i>
                                <p>No upcoming trainings</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Requests -->
                <div class="section-card">
                    <div class="card-header">
                        <h3><i class="fas fa-paper-plane"></i> My Recent Requests</h3>
                        <a href="module5_sub7.php" class="header-link">View All <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="card-body feed-body">
                        <?php if (!empty($recentRequests)): ?>
                            <?php foreach ($recentRequests as $req):
                                $statusClass = $req['status'] === 'approved' ? 'st-approved' : ($req['status'] === 'rejected' ? 'st-rejected' : 'st-pending');
                                $typeIcons = ['leave' => 'fa-calendar-minus', 'document' => 'fa-file-alt', 'schedule_change' => 'fa-clock', 'other' => 'fa-question-circle'];
                                $reqIcon = $typeIcons[$req['request_type']] ?? 'fa-question-circle';
                            ?>
                            <div class="feed-item">
                                <div class="feed-icon feed-icon-request">
                                    <i class="fas <?php echo $reqIcon; ?>"></i>
                                </div>
                                <div class="feed-content">
                                    <h4><?php echo htmlspecialchars($req['title']); ?></h4>
                                    <p class="feed-meta">
                                        <?php echo ucfirst(str_replace('_', ' ', $req['request_type'])); ?>
                                        &bull; <?php echo date('M j', strtotime($req['created_at'])); ?>
                                    </p>
                                </div>
                                <span class="feed-status <?php echo $statusClass; ?>"><?php echo ucfirst($req['status']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state small">
                                <i class="fas fa-paper-plane"></i>
                                <p>No requests submitted yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

    </div> <!-- End container -->
</div> <!-- End main-content -->

<!-- ===== AI CHAT BUBBLE (Outside main-content) ===== -->
<div class="ai-chat-container" id="aiChatContainer">
    <button class="ai-bubble-btn" id="aiBubbleBtn" onclick="toggleAiChat()">
        <i class="fas fa-robot"></i>
        <span class="ai-pulse"></span>
    </button>
    <div class="ai-chat-window" id="aiChatWindow">
        <div class="ai-chat-header">
            <div class="ai-chat-avatar"><i class="fas fa-robot"></i></div>
            <div class="ai-chat-title">
                <h4>AI Assistant</h4>
                <span class="ai-status"><i class="fas fa-circle"></i> Online</span>
            </div>
            <div class="ai-chat-header-actions">
                <button class="ai-header-btn" onclick="clearChatHistory()" title="Clear Chat"><i class="fas fa-trash-alt"></i></button>
                <button class="ai-chat-close" onclick="toggleAiChat()"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="ai-chat-body" id="aiChatBody">
            <div class="ai-message ai-bot">
                <div class="ai-message-avatar"><i class="fas fa-robot"></i></div>
                <div class="ai-message-bubble">
                    <p>Hi <?php echo htmlspecialchars($firstName); ?>! I'm your AI Assistant. Ask me about:</p>
                    <ul style="margin: 8px 0; padding-left: 18px; font-size: 0.85rem;">
                        <li>Your training & courses</li>
                        <li>Career development tips</li>
                        <li>Company policies & FAQs</li>
                        <li>Skills & learning paths</li>
                    </ul>
                    <span class="ai-message-time">Just now</span>
                </div>
            </div>
            <div class="ai-quick-actions">
                <button class="ai-quick-btn" onclick="sendQuickAction('What courses should I take to improve my skills?')">
                    <i class="fas fa-graduation-cap"></i> Learning Tips
                </button>
                <button class="ai-quick-btn" onclick="sendQuickAction('What are the company policies I should know about?')">
                    <i class="fas fa-gavel"></i> Policies
                </button>
                <button class="ai-quick-btn" onclick="sendQuickAction('Give me career growth advice based on my role')">
                    <i class="fas fa-chart-line"></i> Career Growth
                </button>
            </div>
        </div>
        <div class="ai-chat-footer">
            <div class="ai-chat-input-wrapper">
                <textarea class="ai-chat-input" id="aiChatInput" placeholder="Ask me anything..." rows="1"
                    onkeydown="handleChatKeydown(event)" oninput="autoResizeInput(this)"></textarea>
                <button class="ai-send-btn" id="aiSendBtn" onclick="sendChatMessage()">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Charts JavaScript ===== -->
<script>
    // Course Progress Doughnut Chart
    <?php if ($totalCourses > 0): ?>
    const courseCtx = document.getElementById('courseChart').getContext('2d');
    new Chart(courseCtx, {
        type: 'doughnut',
        data: {
            labels: ['Completed', 'In Progress', 'Not Started'],
            datasets: [{
                data: [<?php echo $totalCoursesCompleted; ?>, <?php echo $coursesInProgress; ?>, <?php echo $coursesNotStarted; ?>],
                backgroundColor: ['#10b981', '#6366f1', '#e2e8f0'],
                borderColor: ['#059669', '#4f46e5', '#cbd5e1'],
                borderWidth: 2,
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '68%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.9)',
                    padding: 12,
                    titleFont: { family: 'Poppins', size: 13 },
                    bodyFont: { family: 'Poppins', size: 12 },
                    cornerRadius: 8
                }
            }
        }
    });
    <?php endif; ?>

    // Training Overview Bar Chart
    <?php if ($totalTrainings > 0): ?>
    const trainingCtx = document.getElementById('trainingChart').getContext('2d');
    new Chart(trainingCtx, {
        type: 'bar',
        data: {
            labels: ['Attended', 'Missed', 'Upcoming'],
            datasets: [{
                data: [<?php echo $totalAttendance; ?>, <?php echo $totalTrainings - $totalAttendance; ?>, <?php echo $upcomingTrainings; ?>],
                backgroundColor: ['rgba(16, 185, 129, 0.85)', 'rgba(239, 68, 68, 0.85)', 'rgba(99, 102, 241, 0.85)'],
                borderColor: ['#059669', '#dc2626', '#4f46e5'],
                borderWidth: 2,
                borderRadius: 8,
                borderSkipped: false,
                barThickness: 40
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, font: { family: 'Poppins', size: 11 }, color: '#94a3b8' },
                    grid: { color: 'rgba(148, 163, 184, 0.1)' },
                    border: { display: false }
                },
                x: {
                    ticks: { font: { family: 'Poppins', size: 11, weight: 500 }, color: '#64748b' },
                    grid: { display: false },
                    border: { display: false }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(15, 23, 42, 0.9)',
                    padding: 12,
                    titleFont: { family: 'Poppins', size: 13 },
                    bodyFont: { family: 'Poppins', size: 12 },
                    cornerRadius: 8
                }
            }
        }
    });
    <?php endif; ?>

    /* ===== AI Chat Functions ===== */
    let chatHistory = [];
    let isAiResponding = false;

    function toggleAiChat() {
        const chatWindow = document.getElementById('aiChatWindow');
        const bubbleBtn = document.getElementById('aiBubbleBtn');
        chatWindow.classList.toggle('active');
        if (chatWindow.classList.contains('active')) {
            bubbleBtn.innerHTML = '<i class="fas fa-times"></i>';
            setTimeout(() => document.getElementById('aiChatInput').focus(), 300);
        } else {
            bubbleBtn.innerHTML = '<i class="fas fa-robot"></i><span class="ai-pulse"></span>';
        }
    }

    function addMessage(content, isBot = true, isRaw = false) {
        const body = document.getElementById('aiChatBody');
        const now = new Date();
        const timeStr = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        const quickActions = body.querySelector('.ai-quick-actions');
        if (quickActions) quickActions.style.display = 'none';
        const messageDiv = document.createElement('div');
        messageDiv.className = `ai-message ${isBot ? 'ai-bot' : 'ai-user'} animate-fadeInUp`;
        if (isRaw) {
            messageDiv.innerHTML = content;
        } else {
            let formattedContent = isBot ? formatAiResponse(content) : content;
            messageDiv.innerHTML = `
                <div class="ai-message-avatar"><i class="fas fa-${isBot ? 'robot' : 'user'}"></i></div>
                <div class="ai-message-bubble">${formattedContent}<span class="ai-message-time">${timeStr}</span></div>
            `;
        }
        body.appendChild(messageDiv);
        body.scrollTop = body.scrollHeight;
        return messageDiv;
    }

    function formatAiResponse(text) {
        let html = text;
        html = html.replace(/^### (.+)$/gm, '<h4 style="margin:10px 0 6px;font-size:0.9rem;">$1</h4>');
        html = html.replace(/^## (.+)$/gm, '<h3 style="margin:12px 0 6px;font-size:0.95rem;">$1</h3>');
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
        html = html.replace(/^- (.+)$/gm, '<li style="margin-left:16px;font-size:0.85rem;">$1</li>');
        html = html.replace(/^• (.+)$/gm, '<li style="margin-left:16px;font-size:0.85rem;">$1</li>');
        html = html.replace(/^\d+\. (.+)$/gm, '<li style="margin-left:16px;font-size:0.85rem;">$1</li>');
        html = html.replace(/\n\n/g, '<br><br>');
        html = html.replace(/\n/g, '<br>');
        if (!html.startsWith('<')) html = `<p>${html}</p>`;
        return html;
    }

    function showTyping() {
        const body = document.getElementById('aiChatBody');
        const typingDiv = document.createElement('div');
        typingDiv.className = 'ai-message ai-bot animate-fadeIn';
        typingDiv.id = 'aiTyping';
        typingDiv.innerHTML = `
            <div class="ai-message-avatar"><i class="fas fa-robot"></i></div>
            <div class="ai-message-bubble"><div class="ai-typing"><span></span><span></span><span></span></div></div>
        `;
        body.appendChild(typingDiv);
        body.scrollTop = body.scrollHeight;
    }

    function hideTyping() {
        const typing = document.getElementById('aiTyping');
        if (typing) typing.remove();
    }

    function handleChatKeydown(e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChatMessage(); }
    }

    function autoResizeInput(textarea) {
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, 100) + 'px';
    }

    function sendQuickAction(message) {
        document.getElementById('aiChatInput').value = message;
        sendChatMessage();
    }

    function sendChatMessage() {
        const input = document.getElementById('aiChatInput');
        const sendBtn = document.getElementById('aiSendBtn');
        const message = input.value.trim();
        if (!message || isAiResponding) return;

        input.value = '';
        input.style.height = 'auto';
        addMessage(`<p>${escapeHtml(message)}</p>`, false);
        chatHistory.push({ role: 'user', content: message });

        isAiResponding = true;
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        input.disabled = true;
        setTimeout(() => showTyping(), 300);

        const formData = new FormData();
        formData.append('action', 'chat');
        formData.append('message', message);
        formData.append('history', JSON.stringify(chatHistory.slice(-10)));

        fetch('ai_analyze.php', { method: 'POST', body: formData })
        .then(r => r.text())
        .then(text => { try { return JSON.parse(text); } catch(e) { throw new Error('Invalid response'); } })
        .then(data => {
            hideTyping();
            if (data.success && data.data) {
                addMessage(data.data, true);
                chatHistory.push({ role: 'assistant', content: data.data });
            } else {
                addMessage(`<p>Sorry, I encountered an issue. ${data.error || 'Please try again.'}</p>`, true);
            }
        })
        .catch(error => {
            hideTyping();
            addMessage(`<p>Connection error. Please check your network and try again.</p>`, true);
        })
        .finally(() => {
            isAiResponding = false;
            sendBtn.disabled = false;
            sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
            input.disabled = false;
            input.focus();
        });
    }

    function clearChatHistory() {
        chatHistory = [];
        document.getElementById('aiChatBody').innerHTML = `
            <div class="ai-message ai-bot animate-fadeIn">
                <div class="ai-message-avatar"><i class="fas fa-robot"></i></div>
                <div class="ai-message-bubble">
                    <p>Chat cleared! How can I help you?</p>
                    <span class="ai-message-time">Just now</span>
                </div>
            </div>
            <div class="ai-quick-actions">
                <button class="ai-quick-btn" onclick="sendQuickAction('What courses should I take to improve my skills?')">
                    <i class="fas fa-graduation-cap"></i> Learning Tips
                </button>
                <button class="ai-quick-btn" onclick="sendQuickAction('What are the company policies I should know about?')">
                    <i class="fas fa-gavel"></i> Policies
                </button>
                <button class="ai-quick-btn" onclick="sendQuickAction('Give me career growth advice based on my role')">
                    <i class="fas fa-chart-line"></i> Career Growth
                </button>
            </div>
        `;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
</script>

</body>
</html>

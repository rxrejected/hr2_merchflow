<?php
/**
 * MODULE 5 SUB 5 ADMIN - EMPLOYEE ANALYTICS
 * HR2 MerchFlow - Employee Portal Management
 * Dashboard showing employee activity analytics
 * Data Source: HR1 Real-Time + HR2 Local
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';
require_once 'Connection/hr1_db.php';

// Admin role check
if (!in_array($_SESSION['role'] ?? '', ['admin', 'Super Admin', 'developer'])) {
    header('Location: employee.php');
    exit();
}

$admin_id = $_SESSION['user_id'];

// ===== HR1 REAL-TIME DATA FETCH =====
$hr1db = new HR1Database();
$hr1Response = $hr1db->getEmployees('', 1000, 0);
$hr1_employees = $hr1Response['success'] ? $hr1Response['data'] : [];
$statusCounts = $hr1db->getEmployeeStatusCounts();
$statusData = $statusCounts['success'] ? $statusCounts['data'] : [];

// HR1 Employee Stats
$total_employees = count($hr1_employees);
$active_employees = $statusData['active'] ?? 0;
$probation_employees = $statusData['probation'] ?? 0;
$onboarding_employees = $statusData['onboarding'] ?? 0;
$on_leave_employees = $statusData['on_leave'] ?? 0;

// New hires this month from HR1
$new_this_month = 0;
$current_month = date('Y-m');
foreach ($hr1_employees as $emp) {
    $emp_created = $emp['created_at'] ?? '';
    if (substr($emp_created, 0, 7) === $current_month) $new_this_month++;
}

// Department breakdown from HR1 employees
$dept_map = [];
foreach ($hr1_employees as $emp) {
    $dept = $emp['department'] ?? '';
    if (!empty($dept)) {
        $dept_map[$dept] = ($dept_map[$dept] ?? 0) + 1;
    }
}
arsort($dept_map);
$dept_breakdown = [];
$i = 0;
foreach ($dept_map as $deptName => $cnt) {
    if ($i >= 8) break;
    $dept_breakdown[] = ['department' => $deptName, 'cnt' => $cnt];
    $i++;
}

// Employment type breakdown from HR1
$type_map = [];
foreach ($hr1_employees as $emp) {
    $type = ucfirst(str_replace('_', ' ', $emp['employment_type'] ?? 'full_time'));
    $type_map[$type] = ($type_map[$type] ?? 0) + 1;
}

// ===== HR1 Evaluation data — SAME SOURCE AS module1_sub1.php =====
$evalResult = $hr1db->getEvaluations('completed', 500);

// Group by employee (identical logic to module1_sub1)
$employeeEvaluations = [];
if ($evalResult['success'] && !empty($evalResult['data'])) {
    foreach ($evalResult['data'] as $eval) {
        $empId = $eval['employee_id'];
        if (!isset($employeeEvaluations[$empId])) {
            $employeeEvaluations[$empId] = [
                'employee_id' => $empId,
                'employee_name' => $eval['employee_name'],
                'department' => $eval['department'] ?? '',
                'evaluations' => []
            ];
        }
        $employeeEvaluations[$empId]['evaluations'][] = $eval;
    }
}
;
// Compute stats matching module1_sub1
$total_evaluated = count($employeeEvaluations);
$needSoftSkills = 0;
$needHardSkills = 0;
$excellentPerformers = 0;
$total_score_sum = 0;
$total_score_count = 0;

foreach ($employeeEvaluations as $empData) {
    $latestEval = $empData['evaluations'][0] ?? null;
    if ($latestEval) {
        $score = (float)($latestEval['overall_score'] ?? 0);
        $total_score_sum += $score;
        $total_score_count++;
        if ($score >= 90) $excellentPerformers++;
        if ($score < 70) { $needSoftSkills++; $needHardSkills++; }
        elseif ($score < 80) { $needSoftSkills++; $needHardSkills++; }
    }
}
$avg_eval_score = $total_score_count > 0 ? round($total_score_sum / $total_score_count, 1) : 0;

$evaluation_stats = ['total' => $total_evaluated, 'avg_score' => $avg_eval_score];
$assessment_stats = $evaluation_stats;

// Top performers — BLENDED SCORE: 30% HR1 Evaluation + 70% HR2 Activity
// Step 1: HR1 evaluation averages per employee (30% weight)
$hr1_scores = [];
if ($evalResult['success'] && !empty($evalResult['data'])) {
    $eval_map = [];
    foreach ($evalResult['data'] as $ev) {
        $empId = $ev['employee_id'];
        if (!isset($eval_map[$empId])) {
            $eval_map[$empId] = ['total' => 0, 'count' => 0];
        }
        $eval_map[$empId]['total'] += (float)($ev['overall_score'] ?? 0);
        $eval_map[$empId]['count']++;
    }
    foreach ($eval_map as $empId => $d) {
        $hr1_scores[$empId] = $d['count'] > 0 ? round($d['total'] / $d['count'], 1) : 0;
    }
}

// Step 2: HR2 scores per employee (70% weight)
// Components: Training pass rate, Course avg progress, Assessment completion
$hr2_training = [];
$trQuery = @$conn->query("
    SELECT ta.user_id,
           COUNT(*) as total,
           SUM(CASE WHEN ta.attended = 'Yes' THEN 1 ELSE 0 END) as attended,
           SUM(CASE WHEN ta.training_result = 'Passed' THEN 1 ELSE 0 END) as passed
    FROM training_attendance ta
    GROUP BY ta.user_id
");
if ($trQuery) {
    while ($r = $trQuery->fetch_assoc()) {
        $attended = (int)$r['attended'];
        $passed = (int)$r['passed'];
        $hr2_training[(int)$r['user_id']] = $attended > 0 ? round(($passed / $attended) * 100, 1) : 0;
    }
}

$hr2_courses = [];
$cpCheck = @$conn->query("SHOW TABLES LIKE 'course_progress'");
if ($cpCheck && $cpCheck->num_rows > 0) {
    $cpQuery = @$conn->query("
        SELECT cp.employee_id,
               ROUND(AVG(cp.watched_percent), 1) as avg_progress
        FROM course_progress cp
        GROUP BY cp.employee_id
    ");
    if ($cpQuery) {
        while ($r = $cpQuery->fetch_assoc()) {
            $hr2_courses[(int)$r['employee_id']] = min(100, (float)$r['avg_progress']);
        }
    }
}

$hr2_assessments = [];
$asCheck = @$conn->query("SHOW TABLES LIKE 'assessment'");
if ($asCheck && $asCheck->num_rows > 0) {
    $asQuery = @$conn->query("
        SELECT a.employee_id, COUNT(*) as cnt
        FROM assessment a
        GROUP BY a.employee_id
    ");
    if ($asQuery) {
        while ($r = $asQuery->fetch_assoc()) {
            $hr2_assessments[(int)$r['employee_id']] = (int)$r['cnt'] > 0 ? 100 : 0;
        }
    }
}

// HR2 Assessment Quiz scores (hr2_assessments table — same source as module1_sub1)
$hr2_quiz = [];
$quizCheck = @$conn->query("SHOW TABLES LIKE 'hr2_assessments'");
if ($quizCheck && $quizCheck->num_rows > 0) {
    $quizQuery = @$conn->query("
        SELECT a.hr1_employee_id, a.overall_score
        FROM hr2_assessments a
        INNER JOIN (SELECT hr1_employee_id, MAX(id) as max_id FROM hr2_assessments WHERE status='completed' GROUP BY hr1_employee_id) b
        ON a.id = b.max_id
    ");
    if ($quizQuery) {
        while ($r = $quizQuery->fetch_assoc()) {
            $hr2_quiz[(int)$r['hr1_employee_id']] = min(100, (float)$r['overall_score']);
        }
    }
}

// Step 3: Collect all employee IDs that have any score data
$all_scored_ids = array_unique(array_merge(
    array_keys($hr1_scores),
    array_keys($hr2_training),
    array_keys($hr2_courses),
    array_keys($hr2_assessments),
    array_keys($hr2_quiz)
));

// Build name/avatar lookup from HR1 employees
$hr1_info = [];
foreach ($hr1_employees as $emp) {
    $hr1_info[$emp['id']] = [
        'full_name' => $emp['name'] ?? 'Unknown',
        'department' => $emp['department'] ?? 'N/A',
        'avatar' => $emp['photo'] ?? 'uploads/avatars/default.png',
        'position' => $emp['role'] ?? '',
    ];
}

// Step 4: Calculate blended scores
$top_performers = [];
foreach ($all_scored_ids as $empId) {
    $empId = (int)$empId;
    if (!isset($hr1_info[$empId])) continue; // skip if not in HR1 directory

    // HR1 component (30%)
    $hr1_score = $hr1_scores[$empId] ?? 0;
    $has_hr1 = isset($hr1_scores[$empId]);

    // HR2 component (70%) — average of ALL available sub-components
    $hr2_components = [];
    if (isset($hr2_quiz[$empId])) $hr2_components[] = $hr2_quiz[$empId];
    if (isset($hr2_training[$empId])) $hr2_components[] = $hr2_training[$empId];
    if (isset($hr2_courses[$empId])) $hr2_components[] = $hr2_courses[$empId];
    if (isset($hr2_assessments[$empId])) $hr2_components[] = $hr2_assessments[$empId];

    $hr2_score = count($hr2_components) > 0 ? array_sum($hr2_components) / count($hr2_components) : 0;
    $has_hr2 = count($hr2_components) > 0;

    // Blended score
    if ($has_hr1 && $has_hr2) {
        $blended = ($hr1_score * 0.30) + ($hr2_score * 0.70);
    } elseif ($has_hr1) {
        $blended = $hr1_score; // only HR1 available
    } elseif ($has_hr2) {
        $blended = $hr2_score; // only HR2 available
    } else {
        continue;
    }

    $info = $hr1_info[$empId];
    $top_performers[] = [
        'id' => $empId,
        'full_name' => $info['full_name'],
        'department' => $info['department'],
        'avatar' => $info['avatar'],
        'avg_score' => round($blended, 1),
        'hr1_score' => round($hr1_score, 1),
        'hr2_score' => round($hr2_score, 1),
        'breakdown' => [
            'quiz' => $hr2_quiz[$empId] ?? null,
            'training' => $hr2_training[$empId] ?? null,
            'courses' => $hr2_courses[$empId] ?? null,
            'assessments' => $hr2_assessments[$empId] ?? null,
        ]
    ];
}

// Sort by blended score descending
usort($top_performers, function($a, $b) { return $b['avg_score'] <=> $a['avg_score']; });
$all_performers = $top_performers; // Full list for scoring table
$top_performers = array_slice($top_performers, 0, 5); // Top 5 for leaderboard

// Compute overall blended average
$blended_avg = count($all_performers) > 0 
    ? round(array_sum(array_column($all_performers, 'avg_score')) / count($all_performers), 1) 
    : 0;
$outstanding_count = count(array_filter($all_performers, fn($p) => $p['avg_score'] >= 90));
$excellent_count = count(array_filter($all_performers, fn($p) => $p['avg_score'] >= 80 && $p['avg_score'] < 90));
$good_count = count(array_filter($all_performers, fn($p) => $p['avg_score'] >= 60 && $p['avg_score'] < 80));
$fair_count = count(array_filter($all_performers, fn($p) => $p['avg_score'] >= 50 && $p['avg_score'] < 60));
$poor_count = count(array_filter($all_performers, fn($p) => $p['avg_score'] < 50 && $p['avg_score'] > 0));
$no_data_count = count(array_filter($all_performers, fn($p) => $p['avg_score'] == 0));

// Get unique departments for filter
$score_departments = array_unique(array_column($all_performers, 'department'));
sort($score_departments);

$hr1db->close();

// ===== HR2 LOCAL DATA (training, courses, requests, docs) =====

// Course completion stats
$course_stats_query = @$conn->query("
    SELECT 
        COUNT(*) as total_enrollments,
        SUM(CASE WHEN watched_percent >= 100 THEN 1 ELSE 0 END) as completed,
        AVG(watched_percent) as avg_progress
    FROM course_progress
");
$course_stats = $course_stats_query ? $course_stats_query->fetch_assoc() : [];
$course_stats['total_enrollments'] = $course_stats['total_enrollments'] ?? 0;
$course_stats['completed'] = $course_stats['completed'] ?? 0;
$course_stats['avg_progress'] = round($course_stats['avg_progress'] ?? 0);

// Training attendance
$training_stats_query = @$conn->query("
    SELECT 
        COUNT(*) as total_registrations,
        SUM(CASE WHEN attended = 'Yes' THEN 1 ELSE 0 END) as attended
    FROM training_attendance
");
$training_stats = $training_stats_query ? $training_stats_query->fetch_assoc() : [];
$training_stats['total_registrations'] = $training_stats['total_registrations'] ?? 0;
$training_stats['attended'] = $training_stats['attended'] ?? 0;
$attendance_rate = $training_stats['total_registrations'] > 0 
    ? round(($training_stats['attended'] / $training_stats['total_registrations']) * 100) 
    : 0;

// Request statistics
$request_counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$request_stats_query = @$conn->query("SELECT status, COUNT(*) as cnt FROM employee_requests GROUP BY status");
if ($request_stats_query) {
    while ($r = $request_stats_query->fetch_assoc()) {
        $request_counts[$r['status']] = (int)$r['cnt'];
    }
}

// Documents uploaded this month
$docs_query = @$conn->query("SELECT COUNT(*) as cnt FROM employee_documents WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
$docs_this_month = $docs_query ? ($docs_query->fetch_assoc()['cnt'] ?? 0) : 0;

// Monthly activity trend (placeholder)
$monthly_data = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Employee Analytics | Admin Portal</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/module5_sub5_admin.css?v=<?= time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<?php include 'partials/sidebar.php'; ?>
<div class="main-content">
    <?php include 'partials/nav.php'; ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-content">
            <div class="header-icon"><i class="fas fa-chart-line"></i></div>
            <div class="header-text">
                <h2>Employee Analytics</h2>
                <p>Comprehensive employee activity and performance insights</p>
            </div>
        </div>
        <div class="header-actions">
            <button class="btn btn-secondary" onclick="window.print()">
                <i class="fas fa-print"></i> Export Report
            </button>
        </div>
    </div>
    
    <!-- Key Metrics — Clickable Shortcuts -->
    <div class="analytics-grid">
        <a href="module5_sub1_admin.php" class="metric-card blue fade-in clickable-card">
            <div class="metric-icon blue"><i class="fas fa-users"></i></div>
            <div class="metric-value"><?= $total_employees ?></div>
            <div class="metric-label">Total Employees (HR1)</div>
            <div class="metric-trend up"><i class="fas fa-database"></i> Real-time</div>
            <span class="card-shortcut-hint"><i class="fas fa-external-link-alt"></i> Employee Directory</span>
        </a>
        <a href="module5_sub1_admin.php" class="metric-card green fade-in clickable-card">
            <div class="metric-icon green"><i class="fas fa-user-check"></i></div>
            <div class="metric-value"><?= $active_employees ?></div>
            <div class="metric-label">Active Employees</div>
            <div class="metric-trend up"><i class="fas fa-check"></i> <?= $probation_employees ?> Probation</div>
            <span class="card-shortcut-hint"><i class="fas fa-external-link-alt"></i> View All</span>
        </a>
        <a href="module5_sub1_admin.php" class="metric-card yellow fade-in clickable-card">
            <div class="metric-icon yellow"><i class="fas fa-user-plus"></i></div>
            <div class="metric-value"><?= $new_this_month ?></div>
            <div class="metric-label">New Hires (This Month)</div>
            <div class="metric-trend"><i class="fas fa-user-clock"></i> <?= $onboarding_employees ?> Onboarding</div>
            <span class="card-shortcut-hint"><i class="fas fa-external-link-alt"></i> Employee Directory</span>
        </a>
        <a href="module1_sub1.php" class="metric-card purple fade-in clickable-card">
            <div class="metric-icon purple"><i class="fas fa-clipboard-check"></i></div>
            <div class="metric-value"><?= $total_evaluated ?></div>
            <div class="metric-label">Total Evaluated (HR1)</div>
            <div class="metric-trend"><i class="fas fa-star"></i> <?= $excellentPerformers ?> Excellent · Avg: <?= $avg_eval_score ?>%</div>
            <span class="card-shortcut-hint"><i class="fas fa-external-link-alt"></i> Competency Mgmt</span>
        </a>
    </div>
    
    <!-- Secondary Metrics — Clickable Shortcuts -->
    <div class="analytics-grid">
        <a href="module1_sub1.php" class="metric-card fade-in clickable-card" style="border-left: 4px solid #10b981;">
            <div class="metric-icon green"><i class="fas fa-graduation-cap"></i></div>
            <div class="metric-value"><?= $course_stats['completed'] ?></div>
            <div class="metric-label">Courses Completed</div>
            <span class="card-shortcut-hint"><i class="fas fa-external-link-alt"></i> Evaluations</span>
        </a>
        <a href="module1_sub2.php" class="metric-card fade-in clickable-card" style="border-left: 4px solid #f59e0b;">
            <div class="metric-icon yellow"><i class="fas fa-clipboard-list"></i></div>
            <div class="metric-value"><?= $attendance_rate ?>%</div>
            <div class="metric-label">Training Attendance</div>
            <span class="card-shortcut-hint"><i class="fas fa-external-link-alt"></i> Training</span>
        </a>
        <a href="module5_sub1_admin.php" class="metric-card fade-in clickable-card" style="border-left: 4px solid #ef4444;">
            <div class="metric-icon" style="background:rgba(239,68,68,0.1);color:#ef4444;"><i class="fas fa-bed"></i></div>
            <div class="metric-value"><?= $on_leave_employees ?></div>
            <div class="metric-label">On Leave</div>
            <span class="card-shortcut-hint"><i class="fas fa-external-link-alt"></i> Employee Directory</span>
        </a>
        <a href="module5_sub3_admin.php" class="metric-card fade-in clickable-card" style="border-left: 4px solid #6366f1;">
            <div class="metric-icon purple"><i class="fas fa-bullhorn"></i></div>
            <div class="metric-value"><?= $docs_this_month ?></div>
            <div class="metric-label">Announcements</div>
            <span class="card-shortcut-hint"><i class="fas fa-external-link-alt"></i> Announcements</span>
        </a>
    </div>
    
    <!-- Charts Row -->
    <div class="chart-row">
        <div class="chart-card fade-in">
            <h4><i class="fas fa-chart-pie"></i> Employee Status (HR1)</h4>
            <div class="chart-canvas">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
        <div class="chart-card fade-in">
            <h4><i class="fas fa-chart-pie"></i> Request Status</h4>
            <div class="chart-canvas">
                <canvas id="requestChart"></canvas>
            </div>
        </div>
    </div>
    
    <div class="chart-row">
        <!-- Top Performers (30% HR1 + 70% HR2) -->
        <div class="chart-card fade-in">
            <h4><i class="fas fa-trophy"></i> Top Performers <span style="font-size:0.65rem;font-weight:500;color:var(--text-muted);margin-left:6px;">30% HR1 · 70% HR2</span></h4>
            <?php foreach ($top_performers as $i => $performer): 
                $rank_class = $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : 'default'));
            ?>
            <div class="leaderboard-item">
                <div class="leaderboard-rank <?= $rank_class ?>"><?= $i + 1 ?></div>
                <img src="<?= htmlspecialchars($performer['avatar'] ?: 'uploads/avatars/default.png') ?>" class="leaderboard-avatar" onerror="this.src='uploads/avatars/default.png'">
                <div class="leaderboard-info">
                    <div class="leaderboard-name"><?= htmlspecialchars($performer['full_name']) ?></div>
                    <div class="leaderboard-dept"><?= htmlspecialchars($performer['department'] ?: 'N/A') ?></div>
                    <div class="leaderboard-breakdown">
                        <span class="lb-chip hr1" title="HR1 Evaluation (30%)"><i class="fas fa-clipboard-check"></i> <?= $performer['hr1_score'] ?>%</span>
                        <span class="lb-chip hr2" title="HR2 Activity (70%)"><i class="fas fa-chart-bar"></i> <?= $performer['hr2_score'] ?>%</span>
                    </div>
                </div>
                <div class="leaderboard-score">
                    <?= round($performer['avg_score'], 1) ?>%
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($top_performers)): ?>
            <div class="empty-state">
                <i class="fas fa-trophy"></i>
                <p>No performance data yet</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Department Breakdown -->
        <div class="chart-card fade-in">
            <h4><i class="fas fa-building"></i> Employees by Department</h4>
            <?php 
            $max_dept = !empty($dept_breakdown) ? max(array_column($dept_breakdown, 'cnt')) : 1;
            $colors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444', '#2563eb'];
            foreach ($dept_breakdown as $i => $dept): 
                $pct = ($dept['cnt'] / $max_dept) * 100;
            ?>
            <div class="dept-bar">
                <div class="dept-name"><?= htmlspecialchars($dept['department']) ?></div>
                <div class="dept-progress">
                    <div class="dept-progress-bar" style="width: <?= $pct ?>%; background: <?= $colors[$i % 6] ?>;"></div>
                </div>
                <div class="dept-count"><?= $dept['cnt'] ?></div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($dept_breakdown)): ?>
            <div class="empty-state">
                <i class="fas fa-building"></i>
                <p>No department data available</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Additional Stats -->
    <div class="stats-grid">
        <a href="module1_sub1.php" class="stat-card clickable-card">
            <div class="stat-icon"><i class="fas fa-star"></i></div>
            <div class="stat-info">
                <h3><?= $avg_eval_score ?>%</h3>
                <p>Avg. Evaluation Score (HR1)</p>
            </div>
        </a>
        <a href="module5_sub2_admin.php" class="stat-card clickable-card">
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <h3><?= $request_counts['approved'] ?></h3>
                <p>Approved Requests</p>
            </div>
        </a>
        <a href="module5_sub2_admin.php" class="stat-card clickable-card">
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-info">
                <h3><?= $request_counts['pending'] ?></h3>
                <p>Pending Requests</p>
            </div>
        </a>
        <a href="module1_sub1.php" class="stat-card clickable-card">
            <div class="stat-icon"><i class="fas fa-percentage"></i></div>
            <div class="stat-info">
                <h3><?= $course_stats['avg_progress'] ?>%</h3>
                <p>Avg. Learning Progress</p>
            </div>
        </a>
    </div>
    
    <!-- ============================================================
         FULL EMPLOYEE SCORING TABLE (30% HR1 + 70% HR2)
         ============================================================ -->
    <div class="scoring-section fade-in">
        <div class="scoring-header">
            <div class="scoring-title">
                <h3><i class="fas fa-poll"></i> Employee Total Scores</h3>
                <p>Blended scoring: <strong>30% HR1 Evaluation</strong> + <strong>70% HR2 Activity</strong> (Quiz · Training · Courses · Assessments)</p>
            </div>
            <div class="scoring-actions">
                <button class="btn btn-sm btn-secondary" onclick="exportScores()">
                    <i class="fas fa-download"></i> Export CSV
                </button>
            </div>
        </div>

        <!-- Score Summary Cards -->
        <div class="score-summary-grid">
            <div class="score-summary-card total">
                <div class="ssc-icon"><i class="fas fa-users"></i></div>
                <div class="ssc-value"><?= count($all_performers) ?></div>
                <div class="ssc-label">Scored Employees</div>
            </div>
            <div class="score-summary-card avg">
                <div class="ssc-icon"><i class="fas fa-chart-line"></i></div>
                <div class="ssc-value"><?= $blended_avg ?>%</div>
                <div class="ssc-label">Blended Average</div>
            </div>
            <div class="score-summary-card outstanding">
                <div class="ssc-icon"><i class="fas fa-crown"></i></div>
                <div class="ssc-value"><?= $outstanding_count ?></div>
                <div class="ssc-label">Outstanding (≥90%)</div>
            </div>
            <div class="score-summary-card excellent">
                <div class="ssc-icon"><i class="fas fa-trophy"></i></div>
                <div class="ssc-value"><?= $excellent_count ?></div>
                <div class="ssc-label">Excellent (80-89%)</div>
            </div>
            <div class="score-summary-card good">
                <div class="ssc-icon"><i class="fas fa-thumbs-up"></i></div>
                <div class="ssc-value"><?= $good_count ?></div>
                <div class="ssc-label">Good (60-79%)</div>
            </div>
            <div class="score-summary-card poor">
                <div class="ssc-icon"><i class="fas fa-arrow-down"></i></div>
                <div class="ssc-value"><?= $fair_count + $poor_count ?></div>
                <div class="ssc-label">Needs Work (&lt;60%)</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="scoring-filters">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="scoreSearch" placeholder="Search by name or department...">
            </div>
            <select id="scoreDeptFilter" class="filter-select">
                <option value="">All Departments</option>
                <?php foreach ($score_departments as $dept): ?>
                <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="scoreRatingFilter" class="filter-select">
                <option value="">All Ratings</option>
                <option value="outstanding">Outstanding (≥90%)</option>
                <option value="excellent">Excellent (80-89%)</option>
                <option value="verygood">Very Good (70-79%)</option>
                <option value="good">Good (60-69%)</option>
                <option value="fair">Fair (50-59%)</option>
                <option value="poor">Needs Improvement (<50%)</option>
            </select>
        </div>

        <!-- Scoring Table -->
        <div class="scoring-table-wrap">
            <table class="scoring-table" id="scoringTable">
                <thead>
                    <tr>
                        <th class="sortable" data-sort="rank">#</th>
                        <th class="sortable" data-sort="name">Employee</th>
                        <th>Department</th>
                        <th class="sortable" data-sort="hr1">HR1 Score <span class="weight-badge">30%</span></th>
                        <th class="sortable" data-sort="hr2">HR2 Score <span class="weight-badge">70%</span></th>
                        <th>HR2 Breakdown</th>
                        <th class="sortable" data-sort="total">Total Score</th>
                        <th>Rating</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_performers as $rank => $p): 
                        $rating = '';
                        $ratingClass = '';
                        if ($p['avg_score'] >= 90) { $rating = 'Outstanding'; $ratingClass = 'outstanding'; }
                        elseif ($p['avg_score'] >= 80) { $rating = 'Excellent'; $ratingClass = 'excellent'; }
                        elseif ($p['avg_score'] >= 70) { $rating = 'Very Good'; $ratingClass = 'verygood'; }
                        elseif ($p['avg_score'] >= 60) { $rating = 'Good'; $ratingClass = 'good'; }
                        elseif ($p['avg_score'] >= 50) { $rating = 'Fair'; $ratingClass = 'fair'; }
                        elseif ($p['avg_score'] > 0) { $rating = 'Needs Improvement'; $ratingClass = 'poor'; }
                        else { $rating = 'No Data'; $ratingClass = 'none'; }
                        
                        $bd = $p['breakdown'];
                    ?>
                    <tr data-name="<?= strtolower(htmlspecialchars($p['full_name'])) ?>"
                        data-dept="<?= htmlspecialchars($p['department']) ?>"
                        data-rating="<?= $ratingClass ?>"
                        data-hr1="<?= $p['hr1_score'] ?>"
                        data-hr2="<?= $p['hr2_score'] ?>"
                        data-total="<?= $p['avg_score'] ?>">
                        <td class="rank-cell"><?= $rank + 1 ?></td>
                        <td>
                            <div class="score-emp">
                                <img src="<?= htmlspecialchars($p['avatar'] ?: 'uploads/avatars/default.png') ?>" 
                                     class="score-avatar" onerror="this.src='uploads/avatars/default.png'">
                                <span class="score-name"><?= htmlspecialchars($p['full_name']) ?></span>
                            </div>
                        </td>
                        <td><span class="score-dept-tag"><?= htmlspecialchars($p['department']) ?></span></td>
                        <td>
                            <div class="score-bar-cell">
                                <div class="mini-bar"><div class="mini-fill hr1" style="width:<?= $p['hr1_score'] ?>%"></div></div>
                                <span class="score-val"><?= $p['hr1_score'] ?>%</span>
                            </div>
                        </td>
                        <td>
                            <div class="score-bar-cell">
                                <div class="mini-bar"><div class="mini-fill hr2" style="width:<?= $p['hr2_score'] ?>%"></div></div>
                                <span class="score-val"><?= $p['hr2_score'] ?>%</span>
                            </div>
                        </td>
                        <td>
                            <div class="hr2-breakdown">
                                <?php if ($bd['quiz'] !== null): ?>
                                <span class="bd-chip quiz" title="HR2 Quiz Score"><i class="fas fa-question-circle"></i> <?= round($bd['quiz']) ?>%</span>
                                <?php endif; ?>
                                <?php if ($bd['training'] !== null): ?>
                                <span class="bd-chip training" title="Training Pass Rate"><i class="fas fa-dumbbell"></i> <?= round($bd['training']) ?>%</span>
                                <?php endif; ?>
                                <?php if ($bd['courses'] !== null): ?>
                                <span class="bd-chip courses" title="Course Progress"><i class="fas fa-book-open"></i> <?= round($bd['courses']) ?>%</span>
                                <?php endif; ?>
                                <?php if ($bd['assessments'] !== null): ?>
                                <span class="bd-chip assessments" title="Assessment"><i class="fas fa-clipboard-check"></i> <?= round($bd['assessments']) ?>%</span>
                                <?php endif; ?>
                                <?php if ($bd['quiz'] === null && $bd['training'] === null && $bd['courses'] === null && $bd['assessments'] === null): ?>
                                <span class="bd-chip none">No HR2 data</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="total-score-cell <?= $ratingClass ?>">
                                <strong><?= $p['avg_score'] ?>%</strong>
                            </div>
                        </td>
                        <td>
                            <span class="rating-badge <?= $ratingClass ?>"><?= $rating ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($all_performers)): ?>
                    <tr>
                        <td colspan="8" class="empty-table">
                            <i class="fas fa-chart-bar"></i>
                            <p>No scored employees yet. Scores are calculated from HR1 evaluations and HR2 training, courses, and assessments.</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Score Formula Explanation -->
        <div class="score-formula">
            <h5><i class="fas fa-info-circle"></i> How Total Score is Calculated</h5>
            <div class="formula-grid">
                <div class="formula-item hr1">
                    <div class="formula-weight">30%</div>
                    <div class="formula-detail">
                        <strong>HR1 Evaluation</strong>
                        <span>Average of completed 360° evaluation scores from HR1 system</span>
                    </div>
                </div>
                <div class="formula-plus">+</div>
                <div class="formula-item hr2">
                    <div class="formula-weight">70%</div>
                    <div class="formula-detail">
                        <strong>HR2 Activity</strong>
                        <span>Average of: HR2 Quiz score, Training pass rate, Course progress, Assessment completion</span>
                    </div>
                </div>
                <div class="formula-equals">=</div>
                <div class="formula-item total">
                    <div class="formula-weight">100%</div>
                    <div class="formula-detail">
                        <strong>Total Score</strong>
                        <span>Final blended performance rating</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const isDark = false;
const gridColor = 'rgba(0,0,0,0.1)';
const textColor = '#64748b';

// Employee Status Chart (HR1 data)
const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: ['Active', 'Probation', 'Onboarding', 'On Leave'],
        datasets: [{
            data: [<?= $active_employees ?>, <?= $probation_employees ?>, <?= $onboarding_employees ?>, <?= $on_leave_employees ?>],
            backgroundColor: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { color: textColor, padding: 15 }
            }
        }
    }
});

// Request Chart
const requestCtx = document.getElementById('requestChart').getContext('2d');
new Chart(requestCtx, {
    type: 'doughnut',
    data: {
        labels: ['Approved', 'Pending', 'Rejected'],
        datasets: [{
            data: [<?= $request_counts['approved'] ?>, <?= $request_counts['pending'] ?>, <?= $request_counts['rejected'] ?>],
            backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { color: textColor, padding: 15 }
            }
        }
    }
});

// ========== SCORING TABLE: Search, Filter, Sort, Export ==========
(function() {
    const searchInput = document.getElementById('scoreSearch');
    const deptFilter = document.getElementById('scoreDeptFilter');
    const ratingFilter = document.getElementById('scoreRatingFilter');
    const table = document.getElementById('scoringTable');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    const headers = table.querySelectorAll('thead th.sortable');
    let sortCol = 'rank';
    let sortDir = 'asc';

    // Filter rows
    function filterRows() {
        const search = (searchInput?.value || '').toLowerCase().trim();
        const dept = deptFilter?.value || '';
        const rating = ratingFilter?.value || '';
        const rows = tbody.querySelectorAll('tr[data-name]');
        let visibleRank = 0;

        rows.forEach(row => {
            const name = (row.dataset.name || '').toLowerCase();
            const rowDept = row.dataset.dept || '';
            const rowRating = row.dataset.rating || '';

            const matchSearch = !search || name.includes(search);
            const matchDept = !dept || rowDept === dept;
            const matchRating = !rating || rowRating === rating;

            if (matchSearch && matchDept && matchRating) {
                row.style.display = '';
                visibleRank++;
                row.querySelector('.rank-cell').textContent = visibleRank;
            } else {
                row.style.display = 'none';
            }
        });
    }

    if (searchInput) searchInput.addEventListener('input', filterRows);
    if (deptFilter) deptFilter.addEventListener('change', filterRows);
    if (ratingFilter) ratingFilter.addEventListener('change', filterRows);

    // Sort table
    headers.forEach(th => {
        th.addEventListener('click', function() {
            const col = this.dataset.sort;
            if (sortCol === col) {
                sortDir = sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                sortCol = col;
                sortDir = 'asc';
            }

            // Update header indicators
            headers.forEach(h => {
                h.classList.remove('sort-asc', 'sort-desc');
            });
            this.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');

            // Get rows as array
            const rows = Array.from(tbody.querySelectorAll('tr[data-name]'));
            rows.sort((a, b) => {
                let valA, valB;
                if (col === 'name') {
                    valA = (a.dataset.name || '').toLowerCase();
                    valB = (b.dataset.name || '').toLowerCase();
                    return sortDir === 'asc' ? valA.localeCompare(valB) : valB.localeCompare(valA);
                } else {
                    valA = parseFloat(a.dataset[col]) || 0;
                    valB = parseFloat(b.dataset[col]) || 0;
                    return sortDir === 'asc' ? valA - valB : valB - valA;
                }
            });

            // Re-append sorted rows
            rows.forEach((row, i) => {
                tbody.appendChild(row);
                if (row.style.display !== 'none') {
                    // rank re-numbering happens in filterRows
                }
            });

            filterRows();
        });
    });

    // Export CSV
    window.exportScores = function() {
        const rows = tbody.querySelectorAll('tr[data-name]');
        let csv = 'Rank,Employee,Department,HR1 Score,HR2 Score,Training,Courses,Assessments,Total Score,Rating\n';
        let rank = 0;

        rows.forEach(row => {
            if (row.style.display === 'none') return;
            rank++;
            const name = row.dataset.name || '';
            const dept = row.dataset.dept || '';
            const hr1 = row.dataset.hr1 || '0';
            const hr2 = row.dataset.hr2 || '0';
            const total = row.dataset.total || '0';
            const rating = row.dataset.rating || 'none';

            // Get breakdown values from chips
            const chips = row.querySelectorAll('.bd-chip');
            let training = '-', courses = '-', assessments = '-';
            chips.forEach(chip => {
                const text = chip.textContent.trim();
                if (chip.classList.contains('training')) training = text;
                else if (chip.classList.contains('courses')) courses = text;
                else if (chip.classList.contains('assessments')) assessments = text;
            });

            csv += `${rank},"${name}","${dept}",${hr1}%,${hr2}%,${training},${courses},${assessments},${total}%,${rating}\n`;
        });

        const blob = new Blob([csv], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'employee_scores_' + new Date().toISOString().slice(0,10) + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    };
})();
</script>
</body>
</html>

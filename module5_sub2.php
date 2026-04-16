<?php
/**
 * MODULE 5 SUB 2 - MY EVALUATIONS
 * HR2 MerchFlow - Employee Self-Service Portal
 * View personal evaluation scores and assessments
 * Shows COMBINED SCORE: 30% HR1 + 70% HR2 (same as admin view)
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';
require_once 'Connection/hr1_db.php';

$employee_id = (int)$_SESSION['user_id'];
$from_hr1 = isset($_SESSION['from_hr1']) && $_SESSION['from_hr1'] === true;
$hr1_employee_id = $_SESSION['hr1_employee_id'] ?? null;
$lookup_id = $hr1_employee_id ?? $employee_id;

// Helper function to convert rating to score
function ratingToScore($rating) {
    $map = ['Excellent' => 100, 'Good' => 80, 'Average' => 60, 'Poor' => 40];
    return $map[$rating] ?? 0;
}

// Fetch employee details
if ($from_hr1) {
    $employee = [
        'full_name' => $_SESSION['full_name'] ?? 'Employee',
        'job_position' => $_SESSION['hr1_department'] ?? 'Employee',
        'avatar' => null
    ];
} else {
    $emp_query = "SELECT full_name, job_position, avatar FROM users WHERE id = ?";
    $stmt = $conn->prepare($emp_query);
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $employee = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Check if evaluations table exists
$evaluationsTableExists = $conn->query("SHOW TABLES LIKE 'evaluations'")->num_rows > 0;

$latest_assessment = null;
$assessment_history = [];

if ($evaluationsTableExists) {
    // Fetch latest evaluation
    $evaluation_query = "SELECT * FROM evaluations WHERE employee_id = ? ORDER BY created_at DESC LIMIT 1";
    $stmt = $conn->prepare($evaluation_query);
    $stmt->bind_param("i", $lookup_id);
    $stmt->execute();
    $latest_assessment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Fetch all evaluations for history
    $history_query = "SELECT * FROM evaluations WHERE employee_id = ? ORDER BY created_at DESC LIMIT 10";
    $stmt = $conn->prepare($history_query);
    $stmt->bind_param("i", $lookup_id);
    $stmt->execute();
    $assessment_history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Calculate overall score
$overall_score = 0;
$competencies = [];
if ($latest_assessment) {
    $competencies = [
        'customer_service' => ['label' => 'Customer Service', 'score' => ratingToScore($latest_assessment['customer_service']), 'icon' => 'fa-headset', 'color' => 'blue'],
        'cash_handling' => ['label' => 'Cash Handling', 'score' => ratingToScore($latest_assessment['cash_handling']), 'icon' => 'fa-cash-register', 'color' => 'green'],
        'inventory' => ['label' => 'Inventory Management', 'score' => ratingToScore($latest_assessment['inventory']), 'icon' => 'fa-boxes', 'color' => 'yellow'],
        'teamwork' => ['label' => 'Teamwork', 'score' => ratingToScore($latest_assessment['teamwork']), 'icon' => 'fa-users', 'color' => 'purple'],
        'attendance' => ['label' => 'Attendance', 'score' => ratingToScore($latest_assessment['attendance']), 'icon' => 'fa-calendar-check', 'color' => 'teal']
    ];
    $overall_score = round(array_sum(array_column($competencies, 'score')) / 5, 1);
}

// Determine performance level
$performance_level = 'Needs Improvement';
$performance_class = 'danger';
if ($overall_score >= 90) {
    $performance_level = 'Exceptional';
    $performance_class = 'success';
} elseif ($overall_score >= 80) {
    $performance_level = 'Exceeds Expectations';
    $performance_class = 'success';
} elseif ($overall_score >= 70) {
    $performance_level = 'Meets Expectations';
    $performance_class = 'info';
} elseif ($overall_score >= 60) {
    $performance_level = 'Needs Development';
    $performance_class = 'warning';
}

// ===== COMBINED SCORE (Same formula as Admin view: 30% HR1 + 70% HR2) =====
$hr1_score = 0;
$hr2_full_score = 0;
$combined_score = 0;
$combined_rating = 'N/A';
$has_combined = false;
$hr1_eval_data = null;
$hr2_assessment_data = null;

// Fetch HR1 evaluation score from HR1 database
if ($hr1_employee_id) {
    try {
        $hr1db = new HR1Database();
        $hr1Response = $hr1db->getEvaluations('completed', 500);
        if ($hr1Response['success']) {
            foreach ($hr1Response['data'] as $eval) {
                if ((int)$eval['employee_id'] === (int)$hr1_employee_id) {
                    $hr1_eval_data = $eval;
                    $hr1_score = (float)($eval['overall_score'] ?? 0);
                    break;
                }
            }
        }
        $hr1db->close();
    } catch (Exception $e) {
        // HR1 unavailable, skip
    }
}

// Fetch ALL HR2 components for this employee (same as admin module5_sub5_admin)
$hr2_components = [];

// 1. HR2 Assessment Quiz score
$hr2AssessTableExists = $conn->query("SHOW TABLES LIKE 'hr2_assessments'")->num_rows > 0;
if ($hr2AssessTableExists && $hr1_employee_id) {
    $stmt = $conn->prepare("SELECT * FROM hr2_assessments WHERE hr1_employee_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $hr1_employee_id);
    $stmt->execute();
    $hr2_assessment_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($hr2_assessment_data) {
        $hr2_components[] = (float)$hr2_assessment_data['overall_score'];
    }
}

// 2. Training pass rate
$trStmt = @$conn->prepare("SELECT SUM(CASE WHEN attended='Yes' THEN 1 ELSE 0 END) as att, SUM(CASE WHEN training_result='Passed' THEN 1 ELSE 0 END) as passed FROM training_attendance WHERE user_id = ?");
if ($trStmt && $lookup_id) {
    $trStmt->bind_param("i", $lookup_id);
    $trStmt->execute();
    $trRow = $trStmt->get_result()->fetch_assoc();
    $trStmt->close();
    if ($trRow && (int)$trRow['att'] > 0) {
        $hr2_components[] = round(((int)$trRow['passed'] / (int)$trRow['att']) * 100, 1);
    }
}

// 3. Course progress
$cpCheck = @$conn->query("SHOW TABLES LIKE 'course_progress'");
if ($cpCheck && $cpCheck->num_rows > 0 && $lookup_id) {
    $cpStmt = $conn->prepare("SELECT ROUND(AVG(watched_percent),1) as avg_progress FROM course_progress WHERE employee_id = ?");
    if ($cpStmt) {
        $cpStmt->bind_param("i", $lookup_id);
        $cpStmt->execute();
        $cpRow = $cpStmt->get_result()->fetch_assoc();
        $cpStmt->close();
        if ($cpRow && $cpRow['avg_progress'] !== null) {
            $hr2_components[] = min(100, (float)$cpRow['avg_progress']);
        }
    }
}

// 4. Assessment completion
$asCheck = @$conn->query("SHOW TABLES LIKE 'assessment'");
if ($asCheck && $asCheck->num_rows > 0 && $lookup_id) {
    $asStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM assessment WHERE employee_id = ?");
    if ($asStmt) {
        $asStmt->bind_param("i", $lookup_id);
        $asStmt->execute();
        $asRow = $asStmt->get_result()->fetch_assoc();
        $asStmt->close();
        if ($asRow && (int)$asRow['cnt'] > 0) {
            $hr2_components[] = 100;
        }
    }
}

$hr2_full_score = count($hr2_components) > 0 ? round(array_sum($hr2_components) / count($hr2_components), 1) : 0;

// Calculate combined score
if ($hr1_score > 0 || $hr2_full_score > 0) {
    $combined_score = ($hr1_score * 0.30) + ($hr2_full_score * 0.70);
    $has_combined = true;

    if ($combined_score >= 90) $combined_rating = 'Outstanding';
    elseif ($combined_score >= 80) $combined_rating = 'Excellent';
    elseif ($combined_score >= 70) $combined_rating = 'Very Good';
    elseif ($combined_score >= 60) $combined_rating = 'Good';
    elseif ($combined_score >= 50) $combined_rating = 'Fair';
    else $combined_rating = 'Needs Improvement';
}

// Check pipeline status
$pipeline_data = null;
$pipelineTableExists = $conn->query("SHOW TABLES LIKE 'employee_pipeline'")->num_rows > 0;
if ($pipelineTableExists && $hr1_employee_id) {
    $stmt = $conn->prepare("SELECT * FROM employee_pipeline WHERE hr1_employee_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $hr1_employee_id);
    $stmt->execute();
    $pipeline_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>My Evaluations | Employee Portal</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/module5_sub2.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="Css/ai_chat_bubble.css?v=<?= time(); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<?php include 'partials/sidebar.php'; ?>
<div class="main-content">
    <?php include 'partials/nav.php'; ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h2><i class="fas fa-chart-bar"></i> My Evaluations</h2>
            <div class="subtitle">Track your performance evaluations and scores</div>
        </div>
    </div>
    
    <?php if ($latest_assessment): ?>
    <!-- Stats Row -->
    <div class="stats-row">
        <div class="stat-card fade-in">
            <div class="icon green"><i class="fas fa-trophy"></i></div>
            <div>
                <div class="value"><?= $has_combined ? round($combined_score, 1) : $overall_score ?>%</div>
                <div class="label"><?= $has_combined ? 'Combined Score' : 'Overall Score' ?></div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon blue"><i class="fas fa-medal"></i></div>
            <div>
                <div class="value"><?= $has_combined ? $combined_rating : $performance_level ?></div>
                <div class="label">Performance Level</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon purple"><i class="fas fa-clipboard-check"></i></div>
            <div>
                <div class="value"><?= count($assessment_history) ?></div>
                <div class="label">Assessments</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon yellow"><i class="fas fa-calendar"></i></div>
            <div>
                <div class="value"><?= isset($latest_assessment['created_at']) ? date('M d', strtotime($latest_assessment['created_at'])) : 'N/A' ?></div>
                <div class="label">Last Assessment</div>
            </div>
        </div>
    </div>

    <?php if ($has_combined): ?>
    <!-- Combined Score Breakdown (30% HR1 + 70% HR2) -->
    <div class="combined-score-section fade-in" style="background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%); border-radius: 16px; padding: 28px; margin-bottom: 24px; color: #fff; box-shadow: 0 8px 32px rgba(99,102,241,0.3);">
        <h3 style="margin: 0 0 18px; font-size: 18px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-calculator"></i> Combined Performance Score
            <span style="font-size: 12px; background: rgba(255,255,255,0.2); padding: 3px 12px; border-radius: 20px; font-weight: 400;">30% HR1 + 70% HR2</span>
        </h3>
        <div style="display: grid; grid-template-columns: 1fr auto 1fr auto 1fr; align-items: center; gap: 16px; text-align: center;">
            <!-- HR1 Score -->
            <div style="background: rgba(255,255,255,0.15); border-radius: 14px; padding: 20px 16px; backdrop-filter: blur(10px);">
                <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.85; margin-bottom: 6px;">HR1 Evaluation</div>
                <div style="font-size: 32px; font-weight: 700;"><?= round($hr1_score, 1) ?>%</div>
                <div style="font-size: 12px; opacity: 0.75; margin-top: 4px;">Weight: 30%</div>
                <div style="font-size: 13px; color: rgba(255,255,255,0.7); margin-top: 2px;">= <?= round($hr1_score * 0.30, 1) ?> pts</div>
            </div>
            <div style="font-size: 24px; opacity: 0.5;"><i class="fas fa-plus"></i></div>
            <!-- HR2 Score -->
            <div style="background: rgba(255,255,255,0.15); border-radius: 14px; padding: 20px 16px; backdrop-filter: blur(10px);">
                <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.85; margin-bottom: 6px;">HR2 Activity</div>
                <div style="font-size: 32px; font-weight: 700;"><?= round($hr2_full_score, 1) ?>%</div>
                <div style="font-size: 12px; opacity: 0.75; margin-top: 4px;">Weight: 70%</div>
                <div style="font-size: 13px; color: rgba(255,255,255,0.7); margin-top: 2px;">= <?= round($hr2_full_score * 0.70, 1) ?> pts</div>
            </div>
            <div style="font-size: 24px; opacity: 0.5;"><i class="fas fa-equals"></i></div>
            <!-- Combined Score -->
            <div style="background: rgba(255,255,255,0.25); border-radius: 14px; padding: 20px 16px; backdrop-filter: blur(10px); border: 2px solid rgba(255,255,255,0.3);">
                <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.85; margin-bottom: 6px;">Combined Score</div>
                <div style="font-size: 36px; font-weight: 800;"><?= round($combined_score, 1) ?>%</div>
                <div style="font-size: 14px; font-weight: 600; margin-top: 4px; background: rgba(255,255,255,0.2); display: inline-block; padding: 2px 12px; border-radius: 12px;"><?= $combined_rating ?></div>
            </div>
        </div>

        <?php if ($pipeline_data): ?>
        <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.2); display: flex; align-items: center; gap: 12px; font-size: 13px;">
            <i class="fas fa-stream" style="opacity: 0.7;"></i>
            <span>Pipeline Stage: <strong><?= ucwords(str_replace('_', ' ', $pipeline_data['current_stage'])) ?></strong></span>
            <span style="opacity: 0.5;">|</span>
            <span>Type: <strong><?= ucfirst($pipeline_data['employee_type'] ?? 'N/A') ?></strong></span>
            <?php if ($pipeline_data['course_completion_pct'] > 0): ?>
            <span style="opacity: 0.5;">|</span>
            <span>Course Completion: <strong><?= round($pipeline_data['course_completion_pct'], 1) ?>%</strong></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <div class="content-container">
        <div class="grid-2" style="gap: 2rem;">
            <!-- Overall Score Card -->
            <div class="section-card fade-in">
                <div class="section-header">
                    <h3><i class="fas fa-star"></i> Overall Performance</h3>
                </div>
                <div class="section-body" style="text-align: center;">
                    <div class="overall-score-circle" style="--score: <?= $overall_score ?>">
                        <div class="overall-score-inner">
                            <div class="overall-score-value"><?= $overall_score ?>%</div>
                            <div class="overall-score-label"><?= $performance_level ?></div>
                        </div>
                    </div>
                    <div class="status-badge <?= $performance_class ?>" style="font-size: 1rem; padding: 0.5rem 1.5rem;">
                        <i class="fas fa-award"></i> <?= $performance_level ?>
                    </div>
                </div>
            </div>
            
            <!-- Radar Chart -->
            <div class="section-card fade-in">
                <div class="section-header">
                    <h3><i class="fas fa-chart-pie"></i> Competency Radar</h3>
                </div>
                <div class="section-body">
                    <div class="chart-container">
                        <canvas id="radarChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Competency Breakdown -->
        <div class="section-card fade-in" style="margin-top: 2rem;">
            <div class="section-header">
                <h3><i class="fas fa-th-large"></i> Competency Breakdown</h3>
            </div>
            <div class="section-body">
                <div class="grid-3" style="gap: 1.5rem;">
                    <?php foreach ($competencies as $key => $comp): ?>
                    <div class="competency-card">
                        <div class="competency-header">
                            <div class="competency-icon <?= $comp['color'] ?>">
                                <i class="fas <?= $comp['icon'] ?>"></i>
                            </div>
                            <div>
                                <div class="competency-label"><?= $comp['label'] ?></div>
                                <div class="competency-score"><?= $comp['score'] ?>%</div>
                            </div>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill <?= $comp['score'] < 60 ? 'danger' : ($comp['score'] < 75 ? 'warning' : '') ?>" style="width: <?= $comp['score'] ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Assessment History -->
        <div class="section-card fade-in" style="margin-top: 2rem;">
            <div class="section-header">
                <h3><i class="fas fa-history"></i> Assessment History</h3>
            </div>
            <div class="section-body" style="padding: 0;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Customer Service</th>
                            <th>Cash Handling</th>
                            <th>Inventory</th>
                            <th>Teamwork</th>
                            <th>Attendance</th>
                            <th>Average</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assessment_history as $assessment): 
                            $cs = ratingToScore($assessment['customer_service'] ?? '');
                            $ch = ratingToScore($assessment['cash_handling'] ?? '');
                            $inv = ratingToScore($assessment['inventory'] ?? '');
                            $tw = ratingToScore($assessment['teamwork'] ?? '');
                            $att = ratingToScore($assessment['attendance'] ?? '');
                            $avg = round(($cs + $ch + $inv + $tw + $att) / 5, 1);
                        ?>
                        <tr>
                            <td><?= isset($assessment['created_at']) ? date('M d, Y', strtotime($assessment['created_at'])) : 'N/A' ?></td>
                            <td><?= $cs ?>%</td>
                            <td><?= $ch ?>%</td>
                            <td><?= $inv ?>%</td>
                            <td><?= $tw ?>%</td>
                            <td><?= $att ?>%</td>
                            <td><strong><?= $avg ?>%</strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
    // Radar Chart
    const ctx = document.getElementById('radarChart').getContext('2d');
    const isDarkMode = false;
    
    new Chart(ctx, {
        type: 'radar',
        data: {
            labels: ['Customer Service', 'Cash Handling', 'Inventory', 'Teamwork', 'Attendance'],
            datasets: [{
                label: 'My Scores',
                data: [<?= implode(',', array_column($competencies, 'score')) ?>],
                backgroundColor: 'rgba(220, 53, 69, 0.2)',
                borderColor: '#dc3545',
                borderWidth: 2,
                pointBackgroundColor: '#dc3545',
                pointBorderColor: '#fff',
                pointHoverBackgroundColor: '#fff',
                pointHoverBorderColor: '#dc3545'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                r: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        stepSize: 20,
                        color: isDarkMode ? '#a8b0bd' : '#64748b'
                    },
                    grid: {
                        color: isDarkMode ? '#3d4556' : '#e2e8f0'
                    },
                    pointLabels: {
                        color: isDarkMode ? '#e8eaed' : '#1e293b',
                        font: { size: 12, weight: '500' }
                    }
                }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });
    </script>
    
    <?php else: ?>
    <!-- No Assessment Data -->
    <div class="content-container">
        <div class="section-card fade-in">
            <div class="section-body">
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h4>No Assessments Yet</h4>
                    <p>You don't have any competency assessments recorded yet. Your manager will conduct your assessment soon.</p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php include 'partials/ai_chat.php'; ?>
</body>
</html>

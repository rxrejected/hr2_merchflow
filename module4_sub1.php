<?php
/**
 * MODULE 4 - SUB 1: TALENT POOL & READINESS
 * HR2 MerchFlow - Succession Planning
 * Fetches employees from HR1 database with evaluation-based readiness
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';
require_once 'Connection/ai_config.php';
require_once 'Connection/hr1_db.php';

$role = $_SESSION['role'] ?? 'employee';
$user_id = (int)$_SESSION['user_id'];

// Admin role guard
$userRole = strtolower(str_replace(' ', '', $role));
if (!in_array($userRole, ['admin', 'manager', 'superadmin'])) {
    header('Location: employee.php');
    exit();
}

// Initialize HR1 Database
$hr1db = new HR1Database();

// Fetch employees from HR1 with BLENDED evaluation scores (30% HR1 + 70% HR2)
$employees = [];
$stats = ['ready' => 0, 'potential' => 0, 'developing' => 0, 'needs_work' => 0, 'promote' => 0, 'review' => 0, 'watch' => 0];
$totalScore = 0;
$apiError = null;

try {
    // Get employees from HR1
    $empResult = $hr1db->getEmployees('', 500, 0);
    
    if ($empResult['success'] && !empty($empResult['data'])) {
        // Get HR1 evaluations (30% weight)
        $evalResult = $hr1db->getEvaluations('completed', 500);
        $evalScores = [];
        
        if ($evalResult['success'] && !empty($evalResult['data'])) {
            foreach ($evalResult['data'] as $eval) {
                $empId = $eval['employee_id'];
                if (!isset($evalScores[$empId])) {
                    $evalScores[$empId] = ['scores' => [], 'count' => 0];
                }
                $evalScores[$empId]['scores'][] = (float)$eval['overall_score'];
                $evalScores[$empId]['count']++;
            }
        }
        
        $hr1db->close();
        
        // Fetch HR2 data sources (70% weight) — same as module5_sub5_admin
        $hr2Quiz = [];
        $quizCheck = @$conn->query("SHOW TABLES LIKE 'hr2_assessments'");
        if ($quizCheck && $quizCheck->num_rows > 0) {
            $qr = @$conn->query("SELECT a.hr1_employee_id, a.overall_score FROM hr2_assessments a INNER JOIN (SELECT hr1_employee_id, MAX(id) as max_id FROM hr2_assessments WHERE status='completed' GROUP BY hr1_employee_id) b ON a.id = b.max_id");
            if ($qr) { while ($r = $qr->fetch_assoc()) $hr2Quiz[(int)$r['hr1_employee_id']] = min(100,(float)$r['overall_score']); }
        }
        $hr2Training = [];
        $trRes = @$conn->query("SELECT user_id, SUM(CASE WHEN attended='Yes' THEN 1 ELSE 0 END) as att, SUM(CASE WHEN training_result='Passed' THEN 1 ELSE 0 END) as passed FROM training_attendance GROUP BY user_id");
        if ($trRes) { while ($r = $trRes->fetch_assoc()) { $a=(int)$r['att']; $hr2Training[(int)$r['user_id']] = $a>0 ? round(((int)$r['passed']/$a)*100,1) : 0; } }
        
        $hr2Courses = [];
        $cpCheck = @$conn->query("SHOW TABLES LIKE 'course_progress'");
        if ($cpCheck && $cpCheck->num_rows > 0) {
            $cpRes = @$conn->query("SELECT employee_id, ROUND(AVG(watched_percent),1) as avg_progress FROM course_progress GROUP BY employee_id");
            if ($cpRes) { while ($r = $cpRes->fetch_assoc()) $hr2Courses[(int)$r['employee_id']] = min(100,(float)$r['avg_progress']); }
        }
        $hr2Assess = [];
        $asCheck = @$conn->query("SHOW TABLES LIKE 'assessment'");
        if ($asCheck && $asCheck->num_rows > 0) {
            $asRes = @$conn->query("SELECT employee_id, COUNT(*) as cnt FROM assessment GROUP BY employee_id");
            if ($asRes) { while ($r = $asRes->fetch_assoc()) $hr2Assess[(int)$r['employee_id']] = (int)$r['cnt']>0?100:0; }
        }
        
        // Process each employee with BLENDED score
        foreach ($empResult['data'] as $emp) {
            $empId = $emp['id'];
            
            // HR1 component (30%)
            $hr1Score = 0;
            $evalCount = 0;
            if (isset($evalScores[$empId])) {
                $hr1Score = round(array_sum($evalScores[$empId]['scores']) / count($evalScores[$empId]['scores']), 1);
                $evalCount = $evalScores[$empId]['count'];
            }
            $hasHr1 = isset($evalScores[$empId]);
            
            // HR2 component (70%) — average of all available
            $hr2Components = [];
            if (isset($hr2Quiz[$empId])) $hr2Components[] = $hr2Quiz[$empId];
            if (isset($hr2Training[$empId])) $hr2Components[] = $hr2Training[$empId];
            if (isset($hr2Courses[$empId])) $hr2Components[] = $hr2Courses[$empId];
            if (isset($hr2Assess[$empId])) $hr2Components[] = $hr2Assess[$empId];
            $hr2Score = count($hr2Components) > 0 ? array_sum($hr2Components) / count($hr2Components) : 0;
            $hasHr2 = count($hr2Components) > 0;
            
            // Blended score
            if ($hasHr1 && $hasHr2) {
                $score = round(($hr1Score * 0.30) + ($hr2Score * 0.70), 1);
            } elseif ($hasHr1) {
                $score = $hr1Score;
            } elseif ($hasHr2) {
                $score = round($hr2Score, 1);
            } else {
                $score = 0;
            }
            
            // Determine status based on blended score (same tiers as all modules)
            if ($score >= 90) {
                $status = 'Ready Now';
                $statusClass = 'status-ready';
                $stats['ready']++;
            } elseif ($score >= 70) {
                $status = 'High Potential';
                $statusClass = 'status-potential';
                $stats['potential']++;
            } elseif ($score >= 50) {
                $status = 'Developing';
                $statusClass = 'status-developing';
                $stats['developing']++;
            } else {
                $status = 'Needs Improvement';
                $statusClass = 'status-needs';
                $stats['needs_work']++;
            }
            
            // HR Action Recommendation
            if ($score >= 85) {
                $action = 'Promote';
                $actionClass = 'action-promote';
                $stats['promote']++;
            } elseif ($score >= 70) {
                $action = 'Retain';
                $actionClass = 'action-retain';
            } elseif ($score >= 50) {
                $action = 'Develop';
                $actionClass = 'action-develop';
            } elseif ($score >= 35) {
                $action = 'Watch';
                $actionClass = 'action-watch';
                $stats['watch']++;
            } elseif ($score > 0) {
                $action = 'Under Review';
                $actionClass = 'action-review';
                $stats['review']++;
            } else {
                $action = 'No Data';
                $actionClass = 'action-nodata';
            }
            
            $totalScore += $score;
            
            $employees[] = [
                'id' => $empId,
                'full_name' => $emp['name'],
                'job_position' => $emp['role'],
                'department' => $emp['department'],
                'email' => $emp['email'],
                'avatar' => $emp['photo'] ?? 'uploads/avatars/default.png',
                'score' => $score,
                'hr1_score' => $hr1Score,
                'hr2_score' => round($hr2Score, 1),
                'status' => $status,
                'status_class' => $statusClass,
                'action' => $action,
                'action_class' => $actionClass,
                'eval_count' => $evalCount,
                'hire_date' => $emp['date_hired'] ?? $emp['start_date'] ?? ''
            ];
        }
        
        // Sort by score descending
        usort($employees, fn($a, $b) => $b['score'] <=> $a['score']);
    } else {
        $apiError = $empResult['error'] ?? 'Failed to fetch employees from HR1';
    }
} catch (Exception $e) {
    $apiError = 'Error: ' . $e->getMessage();
}

$totalEmployees = count($employees);
$avgScore = $totalEmployees > 0 ? round($totalScore / $totalEmployees, 1) : 0;
$benchStrength = $totalEmployees > 0 ? round((($stats['ready'] + $stats['potential']) / $totalEmployees) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Talent Pool | HR2 MerchFlow</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/module4_sub1.css?v=<?= time(); ?>">
</head>
<body>
<?php include 'partials/sidebar.php'; ?>

<div class="main-content">
    <?php include 'partials/nav.php'; ?>
    
    <div class="container m4s1-wrap">
        <!-- Page Header -->
        <div class="m4s1-header">
            <div class="m4s1-header-content">
                <div class="m4s1-header-icon">
                    <i class="fas fa-users-cog"></i>
                </div>
                <div class="m4s1-header-text">
                    <h1>Talent Pool</h1>
                    <p>Employee readiness for succession planning</p>
                </div>
            </div>
            <div class="m4s1-header-actions">
                <a href="module4_sub2.php" class="m4s1-btn primary">
                    <i class="fas fa-brain"></i> AI Analysis
                </a>
                <button class="m4s1-btn outline" onclick="exportData()">
                    <i class="fas fa-download"></i> Export
                </button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="m4s1-stats">
            <div class="m4s1-stat">
                <div class="m4s1-stat-icon icon-total">
                    <i class="fas fa-users"></i>
                </div>
                <div class="m4s1-stat-info">
                    <span class="m4s1-stat-val"><?= $totalEmployees ?></span>
                    <span class="m4s1-stat-lbl">Total Employees</span>
                </div>
            </div>
            <div class="m4s1-stat highlight-promote">
                <div class="m4s1-stat-icon icon-promote">
                    <i class="fas fa-arrow-circle-up"></i>
                </div>
                <div class="m4s1-stat-info">
                    <span class="m4s1-stat-val"><?= $stats['promote'] ?></span>
                    <span class="m4s1-stat-lbl">Promote Ready</span>
                </div>
            </div>
            <div class="m4s1-stat highlight-review">
                <div class="m4s1-stat-icon icon-review">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="m4s1-stat-info">
                    <span class="m4s1-stat-val"><?= $stats['review'] + $stats['watch'] ?></span>
                    <span class="m4s1-stat-lbl">Needs Attention</span>
                </div>
            </div>
            <div class="m4s1-stat">
                <div class="m4s1-stat-icon icon-bench">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="m4s1-stat-info">
                    <span class="m4s1-stat-val"><?= $benchStrength ?>%</span>
                    <span class="m4s1-stat-lbl">Bench Strength</span>
                </div>
            </div>
            <div class="m4s1-stat">
                <div class="m4s1-stat-icon icon-avg">
                    <i class="fas fa-percentage"></i>
                </div>
                <div class="m4s1-stat-info">
                    <span class="m4s1-stat-val"><?= $avgScore ?>%</span>
                    <span class="m4s1-stat-lbl">Avg Readiness</span>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="m4s1-filters">
            <div class="m4s1-filter-group">
                <button class="m4s1-fbtn active" data-filter="all">All <span class="count">(<?= $totalEmployees ?>)</span></button>
                <button class="m4s1-fbtn promote" data-filter="Promote"><i class="fas fa-arrow-up"></i> Promote <span class="count">(<?= $stats['promote'] ?>)</span></button>
                <button class="m4s1-fbtn" data-filter="Ready Now">Ready Now</button>
                <button class="m4s1-fbtn" data-filter="High Potential">High Potential</button>
                <button class="m4s1-fbtn" data-filter="Developing">Developing</button>
                <button class="m4s1-fbtn review" data-filter="Under Review"><i class="fas fa-flag"></i> Under Review <span class="count">(<?= $stats['review'] ?>)</span></button>
            </div>
            <div class="m4s1-search">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search employee...">
            </div>
        </div>

        <!-- Talent Table -->
        <div class="m4s1-card">
            <div class="m4s1-card-hd">
                <h3><i class="fas fa-list"></i> Talent Pool <span class="m4s1-formula">30% HR1 + 70% HR2</span></h3>
                <span class="m4s1-count"><?= $totalEmployees ?> employees</span>
            </div>
            <?php if ($apiError): ?>
            <div class="m4s1-card-bd">
                <div class="m4s1-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <p><?= htmlspecialchars($apiError) ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="m4s1-table-wrap">
                <table class="m4s1-table" id="talentTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>HR1 <span class="m4s1-weight">30%</span></th>
                            <th>HR2 <span class="m4s1-weight">70%</span></th>
                            <th>Total Score</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $rank => $emp): 
                            $scoreClass = $emp['score'] >= 85 ? 'high' : ($emp['score'] >= 70 ? 'medium' : ($emp['score'] >= 50 ? 'low' : 'critical'));
                        ?>
                        <tr data-status="<?= $emp['status'] ?>" data-action="<?= $emp['action'] ?>">
                            <td class="m4s1-rank"><?= $rank + 1 ?></td>
                            <td>
                                <div class="m4s1-emp">
                                    <img src="<?= htmlspecialchars($emp['avatar'] ?: 'uploads/avatars/default.png') ?>" 
                                         alt="Avatar" class="m4s1-avatar"
                                         onerror="this.onerror=null; this.src='uploads/avatars/default.png';">
                                    <div>
                                        <span class="m4s1-name"><?= htmlspecialchars($emp['full_name']) ?></span>
                                        <span class="m4s1-role"><?= htmlspecialchars($emp['job_position'] ?: 'Employee') ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><span class="m4s1-dept"><?= htmlspecialchars($emp['department'] ?: 'Operations') ?></span></td>
                            <td>
                                <div class="m4s1-score-bar">
                                    <div class="m4s1-mini-bar"><div class="m4s1-mini-fill hr1" style="width:<?= min($emp['hr1_score'], 100) ?>%"></div></div>
                                    <span class="m4s1-score-val"><?= $emp['hr1_score'] ?>%</span>
                                </div>
                            </td>
                            <td>
                                <div class="m4s1-score-bar">
                                    <div class="m4s1-mini-bar"><div class="m4s1-mini-fill hr2" style="width:<?= min($emp['hr2_score'], 100) ?>%"></div></div>
                                    <span class="m4s1-score-val"><?= $emp['hr2_score'] ?>%</span>
                                </div>
                            </td>
                            <td>
                                <strong class="m4s1-total <?= $scoreClass ?>"><?= $emp['score'] ?>%</strong>
                            </td>
                            <td><span class="m4s1-badge <?= $emp['status_class'] ?>"><?= $emp['status'] ?></span></td>
                            <td><span class="m4s1-action <?= $emp['action_class'] ?>"><i class="fas <?= $emp['action'] === 'Promote' ? 'fa-arrow-up' : ($emp['action'] === 'Under Review' ? 'fa-flag' : ($emp['action'] === 'Watch' ? 'fa-eye' : ($emp['action'] === 'Retain' ? 'fa-shield-alt' : ($emp['action'] === 'Develop' ? 'fa-book' : 'fa-minus')))) ?>"></i> <?= $emp['action'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Action Legend -->
        <div class="m4s1-legend">
            <h4><i class="fas fa-info-circle"></i> Action Guide</h4>
            <div class="m4s1-legend-items">
                <div class="m4s1-legend-item"><span class="m4s1-action action-promote"><i class="fas fa-arrow-up"></i> Promote</span> Score ≥85% — Ready for promotion or higher responsibility</div>
                <div class="m4s1-legend-item"><span class="m4s1-action action-retain"><i class="fas fa-shield-alt"></i> Retain</span> Score 70-84% — High value employee, invest in retention</div>
                <div class="m4s1-legend-item"><span class="m4s1-action action-develop"><i class="fas fa-book"></i> Develop</span> Score 50-69% — Needs targeted development plan</div>
                <div class="m4s1-legend-item"><span class="m4s1-action action-watch"><i class="fas fa-eye"></i> Watch</span> Score 35-49% — Performance warning, close monitoring</div>
                <div class="m4s1-legend-item"><span class="m4s1-action action-review"><i class="fas fa-flag"></i> Under Review</span> Score &lt;35% — Serious concern, review for possible separation</div>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="m4s1-toast" id="toast">
    <i class="fas fa-check-circle"></i>
    <span id="toastMsg">Success!</span>
</div>

<script>
const employees = <?= json_encode(array_map(function($e) {
    return [
        'id' => $e['id'],
        'name' => $e['full_name'],
        'position' => $e['job_position'],
        'department' => $e['department'],
        'score' => $e['score'],
        'hr1_score' => $e['hr1_score'],
        'hr2_score' => $e['hr2_score'],
        'status' => $e['status'],
        'action' => $e['action'],
        'eval_count' => $e['eval_count']
    ];
}, $employees)) ?>;

// Filter buttons (status + action)
document.querySelectorAll('.m4s1-fbtn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.m4s1-fbtn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        applyFilters();
    });
});

// Search
document.getElementById('searchInput').addEventListener('input', applyFilters);

function applyFilters() {
    const activeBtn = document.querySelector('.m4s1-fbtn.active');
    const filter = activeBtn ? activeBtn.dataset.filter : 'all';
    const search = (document.getElementById('searchInput').value || '').toLowerCase();
    let rank = 0;
    
    document.querySelectorAll('#talentTable tbody tr').forEach(row => {
        const text = row.textContent.toLowerCase();
        const status = row.dataset.status || '';
        const action = row.dataset.action || '';
        
        const matchFilter = filter === 'all' || status === filter || action === filter;
        const matchSearch = !search || text.includes(search);
        
        if (matchFilter && matchSearch) {
            row.style.display = '';
            rank++;
            const rankCell = row.querySelector('.m4s1-rank');
            if (rankCell) rankCell.textContent = rank;
        } else {
            row.style.display = 'none';
        }
    });
}

function exportData() {
    let csv = 'Rank,Name,Position,Department,HR1 Score,HR2 Score,Total Score,Status,Action\n';
    let rank = 0;
    employees.forEach(emp => {
        rank++;
        csv += `${rank},"${emp.name}","${emp.position || 'Employee'}","${emp.department || 'Operations'}",${emp.hr1_score}%,${emp.hr2_score}%,${emp.score}%,"${emp.status}","${emp.action}"\n`;
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `talent_pool_${new Date().toISOString().split('T')[0]}.csv`;
    link.click();
    
    showToast('Exported successfully!');
}

function showToast(msg) {
    const toast = document.getElementById('toast');
    document.getElementById('toastMsg').textContent = msg;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3000);
}
</script>
</body>
</html>

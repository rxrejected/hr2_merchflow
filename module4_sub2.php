<?php
/**
 * MODULE 4 - SUB 2: AI SUCCESSION ANALYSIS
 * HR2 MerchFlow - AI-Powered Succession Planning
 * Fetches employees from HR1 database
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';
require_once 'Connection/ai_config.php';
require_once 'Connection/hr1_db.php';

$role = $_SESSION['role'] ?? 'employee';

// Admin role guard
$userRole = strtolower(str_replace(' ', '', $role));
if (!in_array($userRole, ['admin', 'manager', 'superadmin'])) {
    header('Location: employee.php');
    exit();
}

// Initialize HR1 Database
$hr1db = new HR1Database();

// Fetch employees from HR1 with BLENDED scores (30% HR1 + 70% HR2)
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
        
        // Fetch HR2 data sources (70% weight) — same as all other modules
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
            
            // HR2 component (70%)
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
            
            // Determine status based on blended score (same tiers as module4_sub1)
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
            
            // HR Action Recommendation (same as module4_sub1)
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
                'avatar' => $emp['photo'] ?? 'uploads/avatars/default.png',
                'score' => $score,
                'hr1_score' => $hr1Score,
                'hr2_score' => round($hr2Score, 1),
                'status' => $status,
                'status_class' => $statusClass,
                'action' => $action,
                'action_class' => $actionClass,
                'eval_count' => $evalCount
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

// Top candidates for succession
$topCandidates = array_filter($employees, fn($e) => $e['score'] >= 70);
$needsDevelopment = array_filter($employees, fn($e) => $e['score'] < 55);

// Promotion & Review lists
$promotionCandidates = array_filter($employees, fn($e) => $e['score'] >= 85);
$underReview = array_filter($employees, fn($e) => $e['score'] > 0 && $e['score'] < 35);
$watchList = array_filter($employees, fn($e) => $e['score'] >= 35 && $e['score'] < 50);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Succession Analysis | HR2 MerchFlow</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/module4_sub2.css?v=<?= time(); ?>">
</head>
<body>
<?php include 'partials/sidebar.php'; ?>

<div class="main-content">
    <?php include 'partials/nav.php'; ?>
    
    <div class="container m4s2-wrap">
        <!-- Page Header -->
        <div class="m4s2-header">
            <div class="m4s2-header-content">
                <div class="m4s2-header-icon">
                    <i class="fas fa-brain"></i>
                </div>
                <div class="m4s2-header-text">
                    <h1>AI Succession Analysis</h1>
                    <p>AI-powered insights for succession planning</p>
                </div>
            </div>
            <div class="m4s2-header-actions">
                <a href="module4_sub1.php" class="m4s2-btn outline">
                    <i class="fas fa-users"></i> Talent Pool
                </a>
                <button class="m4s2-btn primary" onclick="runFullAnalysis()">
                    <i class="fas fa-magic"></i> Run Analysis
                </button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="m4s2-stats">
            <div class="m4s2-stat">
                <div class="m4s2-stat-icon icon-total">
                    <i class="fas fa-users"></i>
                </div>
                <div class="m4s2-stat-info">
                    <span class="m4s2-stat-val"><?= $totalEmployees ?></span>
                    <span class="m4s2-stat-lbl">Total Pool</span>
                </div>
            </div>
            <div class="m4s2-stat highlight-promote">
                <div class="m4s2-stat-icon icon-promote">
                    <i class="fas fa-arrow-circle-up"></i>
                </div>
                <div class="m4s2-stat-info">
                    <span class="m4s2-stat-val"><?= $stats['promote'] ?></span>
                    <span class="m4s2-stat-lbl">Promote Ready</span>
                </div>
            </div>
            <div class="m4s2-stat highlight-review">
                <div class="m4s2-stat-icon icon-review">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="m4s2-stat-info">
                    <span class="m4s2-stat-val"><?= $stats['review'] + $stats['watch'] ?></span>
                    <span class="m4s2-stat-lbl">Needs Attention</span>
                </div>
            </div>
            <div class="m4s2-stat">
                <div class="m4s2-stat-icon icon-bench">
                    <i class="fas fa-chart-pie"></i>
                </div>
                <div class="m4s2-stat-info">
                    <span class="m4s2-stat-val"><?= $benchStrength ?>%</span>
                    <span class="m4s2-stat-lbl">Bench Strength</span>
                </div>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="m4s2-grid">
            <!-- AI Analysis Panel -->
            <div class="m4s2-card m4s2-ai-panel">
                <div class="m4s2-card-hd">
                    <h3><i class="fas fa-robot"></i> AI Insights</h3>
                    <span class="m4s2-ai-status" id="aiStatus">
                        <i class="fas fa-circle"></i> Ready
                    </span>
                </div>
                <div class="m4s2-card-bd" id="aiInsights">
                    <div class="m4s2-placeholder">
                        <i class="fas fa-lightbulb"></i>
                        <h4>Run AI Analysis</h4>
                        <p>Click "Run Analysis" to get AI-powered promotion and review insights</p>
                    </div>
                </div>
            </div>

            <!-- Quick Actions Panel -->
            <div class="m4s2-card m4s2-actions-panel">
                <div class="m4s2-card-hd">
                    <h3><i class="fas fa-bolt"></i> Quick Analysis</h3>
                </div>
                <div class="m4s2-card-bd">
                    <div class="m4s2-action-grid">
                        <button class="m4s2-action-btn" onclick="analyzePromotions()">
                            <i class="fas fa-arrow-circle-up" style="color:#059669;"></i>
                            <span>Promotion Analysis</span>
                        </button>
                        <button class="m4s2-action-btn" onclick="analyzePerformance()">
                            <i class="fas fa-exclamation-triangle" style="color:#dc2626;"></i>
                            <span>Performance Review</span>
                        </button>
                        <button class="m4s2-action-btn" onclick="analyzeGaps()">
                            <i class="fas fa-search-minus" style="color:#6366f1;"></i>
                            <span>Gap Analysis</span>
                        </button>
                        <button class="m4s2-action-btn" onclick="createRoadmap()">
                            <i class="fas fa-road" style="color:#f59e0b;"></i>
                            <span>Dev Roadmap</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Promotion Candidates Table -->
        <div class="m4s2-card m4s2-sec-promote">
            <div class="m4s2-card-hd">
                <h3><i class="fas fa-arrow-circle-up"></i> Promotion Candidates <span class="m4s2-badge-promote"><?= count($promotionCandidates) ?></span></h3>
                <span class="m4s2-sec-desc">Employees scoring ≥85% — recommended for promotion or higher responsibility</span>
            </div>
            <?php if (empty($promotionCandidates)): ?>
            <div class="m4s2-card-bd"><div class="m4s2-empty"><i class="fas fa-trophy"></i><p>No employees currently meet the promotion threshold (≥85%)</p></div></div>
            <?php else: ?>
            <div class="m4s2-table-wrap">
                <table class="m4s2-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Employee</th>
                            <th>Position</th>
                            <th>Department</th>
                            <th>HR1 <span class="m4s2-weight">30%</span></th>
                            <th>HR2 <span class="m4s2-weight">70%</span></th>
                            <th>Total</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 0; foreach ($promotionCandidates as $emp): $rank++; ?>
                        <tr>
                            <td class="m4s2-rank"><?= $rank ?></td>
                            <td>
                                <div class="m4s2-emp">
                                    <img src="<?= htmlspecialchars($emp['avatar'] ?: 'uploads/avatars/default.png') ?>" alt="" class="m4s2-avatar" onerror="this.onerror=null;this.src='uploads/avatars/default.png';">
                                    <span class="m4s2-emp-name"><?= htmlspecialchars($emp['full_name']) ?></span>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($emp['job_position'] ?: 'Employee') ?></td>
                            <td><?= htmlspecialchars($emp['department'] ?: 'Operations') ?></td>
                            <td><span class="m4s2-score-val"><?= $emp['hr1_score'] ?>%</span></td>
                            <td><span class="m4s2-score-val"><?= $emp['hr2_score'] ?>%</span></td>
                            <td><strong class="m4s2-total high"><?= $emp['score'] ?>%</strong></td>
                            <td>
                                <button class="m4s2-btn sm promote-btn" onclick="analyzeEmployee(<?= $emp['id'] ?>, '<?= htmlspecialchars(addslashes($emp['full_name'])) ?>', <?= $emp['score'] ?>, '<?= $emp['action'] ?>')">
                                    <i class="fas fa-brain"></i> AI Review
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Under Review / Watch List Table -->
        <div class="m4s2-card m4s2-sec-review">
            <div class="m4s2-card-hd">
                <h3><i class="fas fa-exclamation-triangle"></i> Under Review / Performance Watch <span class="m4s2-badge-review"><?= count($underReview) + count($watchList) ?></span></h3>
                <span class="m4s2-sec-desc">Employees scoring &lt;50% — may need intervention, PIP, or separation review</span>
            </div>
            <?php 
            $atRisk = array_merge(array_values($underReview), array_values($watchList));
            usort($atRisk, fn($a, $b) => $a['score'] <=> $b['score']);
            ?>
            <?php if (empty($atRisk)): ?>
            <div class="m4s2-card-bd"><div class="m4s2-empty"><i class="fas fa-check-circle" style="color:#10b981;"></i><p>No employees currently under review — all performing above threshold</p></div></div>
            <?php else: ?>
            <div class="m4s2-table-wrap">
                <table class="m4s2-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Employee</th>
                            <th>Position</th>
                            <th>Department</th>
                            <th>HR1 <span class="m4s2-weight">30%</span></th>
                            <th>HR2 <span class="m4s2-weight">70%</span></th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $rank = 0; foreach ($atRisk as $emp): $rank++; 
                            $isReview = $emp['score'] < 35;
                        ?>
                        <tr class="<?= $isReview ? 'm4s2-row-danger' : 'm4s2-row-warning' ?>">
                            <td class="m4s2-rank"><?= $rank ?></td>
                            <td>
                                <div class="m4s2-emp">
                                    <img src="<?= htmlspecialchars($emp['avatar'] ?: 'uploads/avatars/default.png') ?>" alt="" class="m4s2-avatar" onerror="this.onerror=null;this.src='uploads/avatars/default.png';">
                                    <span class="m4s2-emp-name"><?= htmlspecialchars($emp['full_name']) ?></span>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($emp['job_position'] ?: 'Employee') ?></td>
                            <td><?= htmlspecialchars($emp['department'] ?: 'Operations') ?></td>
                            <td><span class="m4s2-score-val"><?= $emp['hr1_score'] ?>%</span></td>
                            <td><span class="m4s2-score-val"><?= $emp['hr2_score'] ?>%</span></td>
                            <td><strong class="m4s2-total critical"><?= $emp['score'] ?>%</strong></td>
                            <td><span class="m4s2-action <?= $emp['action_class'] ?>"><i class="fas <?= $isReview ? 'fa-flag' : 'fa-eye' ?>"></i> <?= $emp['action'] ?></span></td>
                            <td>
                                <button class="m4s2-btn sm review-btn" onclick="analyzeEmployee(<?= $emp['id'] ?>, '<?= htmlspecialchars(addslashes($emp['full_name'])) ?>', <?= $emp['score'] ?>, '<?= $emp['action'] ?>')">
                                    <i class="fas fa-brain"></i> AI Review
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- AI Modal -->
<div class="m4s2-modal-overlay" id="aiModal">
    <div class="m4s2-modal">
        <div class="m4s2-modal-hd">
            <h3><i class="fas fa-brain"></i> <span id="modalTitle">AI Analysis</span></h3>
            <button class="m4s2-modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="m4s2-modal-bd" id="modalContent">
            <div class="m4s2-loading">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Analyzing...</p>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="m4s2-toast" id="toast">
    <i class="fas fa-check-circle"></i>
    <span id="toastMsg">Success!</span>
</div>

<script>
const stats = {
    total: <?= $totalEmployees ?>,
    ready: <?= $stats['ready'] ?>,
    potential: <?= $stats['potential'] ?>,
    developing: <?= $stats['developing'] ?>,
    needsWork: <?= $stats['needs_work'] ?>,
    promote: <?= $stats['promote'] ?>,
    review: <?= $stats['review'] ?>,
    watch: <?= $stats['watch'] ?>,
    avgScore: <?= $avgScore ?>,
    benchStrength: <?= $benchStrength ?>
};

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

// Run full AI analysis (with promotion & review focus)
async function runFullAnalysis() {
    const panel = document.getElementById('aiInsights');
    const status = document.getElementById('aiStatus');
    
    status.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing...';
    status.classList.add('processing');
    
    panel.innerHTML = '<div class="m4s2-loading"><i class="fas fa-spinner fa-spin"></i><p>Analyzing promotion and review data...</p></div>';
    
    const promoteNames = employees.filter(e => e.score >= 85).slice(0, 5).map(e => `${e.name} (${e.score}%, ${e.position})`).join(', ');
    const reviewNames = employees.filter(e => e.score > 0 && e.score < 35).slice(0, 5).map(e => `${e.name} (${e.score}%, ${e.position})`).join(', ');
    const watchNames = employees.filter(e => e.score >= 35 && e.score < 50).slice(0, 5).map(e => `${e.name} (${e.score}%, ${e.position})`).join(', ');
    
    const prompt = `You are a senior HR Director conducting a succession planning review. Analyze this workforce data and provide actionable decisions.

SCORING: Blended score = 30% HR1 Evaluation + 70% HR2 Activity (Quiz + Training + Courses + Assessments)

WORKFORCE SUMMARY:
- Total employees: ${stats.total}
- Ready for promotion (score ≥85%): ${stats.promote}
- High potential (70-84%): ${stats.potential}
- Developing (50-69%): ${stats.developing}
- Performance watch (35-49%): ${stats.watch}
- Under review / possible separation (<35%): ${stats.review}
- Average score: ${stats.avgScore}%
- Bench strength: ${stats.benchStrength}%

PROMOTION CANDIDATES: ${promoteNames || 'None currently qualify'}
UNDER REVIEW (may need separation): ${reviewNames || 'None below threshold'}
PERFORMANCE WATCH: ${watchNames || 'None on watch'}

Provide a complete analysis with these sections (use bullet points, be specific):

1. **PROMOTION DECISIONS** - Who should be promoted and why. What positions/roles to promote them to.
2. **SEPARATION REVIEW** - Who may need to be terminated and why. What due process steps to follow first (PIP, counseling, etc).
3. **PERFORMANCE WATCH** - Who needs immediate intervention. What specific plan for each.
4. **RISK ASSESSMENT** - Organizational risks from current talent distribution.
5. **TOP 3 PRIORITY ACTIONS** - Most urgent actions HR should take this month.

Be direct and specific. Use real employee names when available. Include practical steps.`;

    try {
        const response = await fetch('ai_analyze.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ type: 'succession', message: prompt })
        });
        
        if (!response.ok) throw new Error(`HTTP error: ${response.status}`);
        const text = await response.text();
        if (!text || text.trim() === '') throw new Error('Empty response from server');
        
        let data;
        try { data = JSON.parse(text); } catch (e) { throw new Error('Invalid response from server'); }
        
        if (data.success) {
            panel.innerHTML = `<div class="m4s2-ai-result">${formatResponse(data.response)}</div>`;
            status.innerHTML = '<i class="fas fa-check-circle"></i> Complete';
            status.classList.remove('processing');
            status.classList.add('complete');
        } else {
            throw new Error(data.error || 'Analysis failed');
        }
    } catch (error) {
        panel.innerHTML = `<div class="m4s2-error"><i class="fas fa-exclamation-circle"></i><p>${error.message}</p></div>`;
        status.innerHTML = '<i class="fas fa-times-circle"></i> Error';
        status.classList.remove('processing');
    }
}

// Promotion Analysis
async function analyzePromotions() {
    showModal('Promotion Analysis');
    
    const candidates = employees.filter(e => e.score >= 85);
    const list = candidates.map(e => `- ${e.name}: ${e.score}% (${e.position}, ${e.department}) | HR1: ${e.hr1_score}% | HR2: ${e.hr2_score}%`).join('\n');
    
    const prompt = `You are an HR Director reviewing promotion candidates.

PROMOTION CANDIDATES (Score ≥85%, Blended: 30% HR1 Eval + 70% HR2 Activity):
${list || 'No employees currently meet the promotion threshold (≥85%)'}

Total workforce: ${stats.total}
Average score: ${stats.avgScore}%

For each candidate, provide:
1. **Promotion recommendation** - Yes/No/Maybe with reason
2. **Suggested next role** - What position they could be promoted to
3. **Strengths** - Why they deserve promotion (based on their HR1 and HR2 scores)
4. **Development areas** - What they still need to work on before/after promotion
5. **Priority** - High/Medium/Low

Also provide:
- **General promotion policy recommendations** - How many can realistically be promoted this cycle
- **Budget/timeline considerations**

Be specific and practical.`;

    await runAIQuery(prompt);
}

// Performance Review (Termination Analysis)
async function analyzePerformance() {
    showModal('Performance Review — Separation Analysis');
    
    const atRisk = employees.filter(e => e.score > 0 && e.score < 50).sort((a,b) => a.score - b.score);
    const list = atRisk.map(e => `- ${e.name}: ${e.score}% (${e.position}, ${e.department}) | HR1: ${e.hr1_score}% | HR2: ${e.hr2_score}% | Action: ${e.action}`).join('\n');
    
    const prompt = `You are an HR Director conducting a performance review for underperforming employees. Some may need to be terminated (separated from the company).

EMPLOYEES UNDER REVIEW (Score <50%, Blended: 30% HR1 Eval + 70% HR2 Activity):
${list || 'No employees currently below threshold'}

For each employee, provide:
1. **Assessment** - Is this performance recoverable or terminal?
2. **Recommended action**: 
   - PIP (Performance Improvement Plan) - give another chance with specific targets
   - Final Warning - last chance before separation
   - Separation/Termination - recommend ending employment
   - Reassignment - move to a different role that fits better
3. **Reason** - Why this action (cite their specific scores)
4. **Due Process Steps** - What steps must be taken before termination (counseling, PIP period, documentation)
5. **Timeline** - When to make the final decision

IMPORTANT GUIDELINES:
- Be fair and follow Philippine labor law best practices where applicable
- Termination should be a last resort after due process
- Always consider if the employee can be salvaged through intervention
- Document everything

Provide a clear summary at the end: How many to PIP, how many to terminate, how many to reassign.`;

    await runAIQuery(prompt);
}

// Gap Analysis
async function analyzeGaps() {
    showModal('Talent Gap Analysis');
    
    const deptMap = {};
    employees.forEach(e => {
        if (!deptMap[e.department]) deptMap[e.department] = { total: 0, scoreSum: 0, promote: 0, review: 0 };
        deptMap[e.department].total++;
        deptMap[e.department].scoreSum += e.score;
        if (e.score >= 85) deptMap[e.department].promote++;
        if (e.score < 50) deptMap[e.department].review++;
    });
    
    let deptSummary = Object.entries(deptMap).map(([dept, d]) => 
        `- ${dept}: ${d.total} employees, avg ${Math.round(d.scoreSum/d.total)}%, ${d.promote} promotable, ${d.review} at risk`
    ).join('\n');
    
    const prompt = `You are an HR analyst. Identify talent gaps by department.

DEPARTMENT BREAKDOWN:
${deptSummary}

OVERALL:
- Bench strength: ${stats.benchStrength}%
- Promote ready: ${stats.promote}
- Under review: ${stats.review + stats.watch}

Analyze:
1. **Critical gaps** - Which departments have the weakest talent pipeline?
2. **Overstaffed vs understaffed** - Where is talent concentrated?
3. **Succession risk** - Which departments would struggle if key people left?
4. **Hiring recommendations** - Where should recruitment focus?
5. **Cross-training opportunities** - How to spread talent across departments?

Be concise with bullet points.`;

    await runAIQuery(prompt);
}

// Development Roadmap
async function createRoadmap() {
    showModal('12-Month Development Roadmap');
    
    const prompt = `You are an HR strategist. Create a 12-month talent development roadmap.

CURRENT STATE:
- ${stats.promote} employees ready for promotion (score ≥85%)
- ${stats.potential + stats.ready} high performers (70%+)
- ${stats.developing} developing (50-69%)
- ${stats.watch} on performance watch (35-49%)
- ${stats.review} under review / possible separation (<35%)
- Bench strength: ${stats.benchStrength}%

Create a practical roadmap:

**MONTHS 1-3: Immediate Actions**
- Process promotions for top performers
- Issue PIPs for under-review employees
- Start mentorship program

**MONTHS 4-6: Development Phase**
- Training programs for developing employees
- Mid-PIP reviews
- Identify next batch of promotion candidates

**MONTHS 7-9: Evaluation**
- PIP final decisions (retain or separate)
- Promotion readiness assessments
- Succession plan updates

**MONTHS 10-12: Decisions**
- Annual promotion cycle
- Final separation decisions
- Next year planning

Provide 3-4 specific action items per phase. Be practical.`;

    await runAIQuery(prompt);
}

// Individual Employee AI Analysis
async function analyzeEmployee(empId, empName, empScore, empAction) {
    showModal(`AI Review: ${empName}`);
    
    const emp = employees.find(e => e.id === empId);
    if (!emp) {
        document.getElementById('modalContent').innerHTML = '<div class="m4s2-error"><p>Employee data not found</p></div>';
        return;
    }
    
    let analysisType = '';
    if (empAction === 'Promote') {
        analysisType = `This employee is being considered for PROMOTION (score: ${empScore}%).`;
    } else if (empAction === 'Under Review') {
        analysisType = `This employee is UNDER REVIEW for possible separation (score: ${empScore}%).`;
    } else if (empAction === 'Watch') {
        analysisType = `This employee is on PERFORMANCE WATCH (score: ${empScore}%).`;
    }
    
    const prompt = `You are an HR Director providing an individual employee assessment.

EMPLOYEE: ${emp.name}
Position: ${emp.position || 'Employee'}
Department: ${emp.department || 'Operations'}
HR1 Evaluation Score (30% weight): ${emp.hr1_score}%
HR2 Activity Score (70% weight): ${emp.hr2_score}%
Total Blended Score: ${emp.score}%
Current Action: ${emp.action}
Evaluations Completed: ${emp.eval_count}

${analysisType}

Provide a detailed individual assessment:

1. **Overall Assessment** - Summary of this employee's performance
2. **Strengths** - What they're doing well (based on scores)
3. **Weaknesses** - Where they need improvement
4. **HR1 vs HR2 Analysis** - Is there a significant gap between evaluation (HR1) and activity (HR2)? What does that tell us?
5. **Recommended Action** - Specific recommendation (promote/retain/develop/PIP/separate)
6. **Next Steps** - 3-4 concrete actions to take for this employee
7. **Timeline** - When to review again

Be specific and practical. If recommending separation, include due process steps.`;

    await runAIQuery(prompt);
}

// Helper: Show modal with loading
function showModal(title) {
    document.getElementById('modalTitle').textContent = title;
    document.getElementById('modalContent').innerHTML = `
        <div class="m4s2-loading">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Generating analysis...</p>
        </div>
    `;
    document.getElementById('aiModal').classList.add('show');
}

// Helper: Run AI query
async function runAIQuery(prompt) {
    try {
        const response = await fetch('ai_analyze.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ type: 'succession', message: prompt })
        });
        
        if (!response.ok) throw new Error(`HTTP error: ${response.status}`);
        const text = await response.text();
        if (!text || text.trim() === '') throw new Error('Empty response from server');
        
        let data;
        try { data = JSON.parse(text); } catch (e) { throw new Error('Invalid response from server'); }
        
        if (data.success) {
            document.getElementById('modalContent').innerHTML = `<div class="m4s2-ai-result">${formatResponse(data.response)}</div>`;
        } else {
            throw new Error(data.error || 'Failed');
        }
    } catch (error) {
        document.getElementById('modalContent').innerHTML = `<div class="m4s2-error"><i class="fas fa-exclamation-circle"></i><p>${error.message}</p></div>`;
    }
}

// Format AI response
function formatResponse(text) {
    if (!text) return '<p>No response</p>';
    return text
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/^### (.+)$/gm, '<h4>$1</h4>')
        .replace(/^## (.+)$/gm, '<h3>$1</h3>')
        .replace(/^# (.+)$/gm, '<h2>$1</h2>')
        .replace(/^- (.+)$/gm, '<li>$1</li>')
        .replace(/^\d+\. (.+)$/gm, '<li>$1</li>')
        .replace(/\n\n/g, '</p><p>')
        .replace(/\n/g, '<br>');
}

function closeModal() {
    document.getElementById('aiModal').classList.remove('show');
}

function showToast(msg) {
    const toast = document.getElementById('toast');
    document.getElementById('toastMsg').textContent = msg;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3000);
}

document.getElementById('aiModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
</body>
</html>

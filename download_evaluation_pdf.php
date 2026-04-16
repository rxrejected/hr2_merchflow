<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Unauthorized: Please log in.');
}

require_once 'Connection/Config.php';
require_once 'Connection/hr1_integration.php';

if (!isset($_GET['id'])) {
    die('Invalid request - No employee ID provided');
}

$employeeId = intval($_GET['id']);

// Fetch HR1 evaluations
$hr1Service = new HR1IntegrationService();
$hr1Response = $hr1Service->getEvaluations(false);

if (!$hr1Response['success']) {
    die('Unable to fetch data from HR1 system: ' . ($hr1Response['error'] ?? 'Unknown error'));
}

// Find the employee's evaluations
$employeeData = null;
$evaluations = [];

foreach ($hr1Response['data'] as $eval) {
    if ($eval['employee_id'] == $employeeId) {
        if (!$employeeData) {
            $employeeData = [
                'id' => $eval['employee_id'],
                'full_name' => $eval['employee_name'],
                'email' => $eval['employee_email'],
                'job_position' => $eval['role'] ?? 'Employee'
            ];
        }
        $evaluations[] = $hr1Service->mapToHR2Format($eval);
    }
}

if (!$employeeData || count($evaluations) === 0) {
    die('No evaluation data found for this employee');
}

// Get the latest evaluation
$latestEval = $evaluations[0];
$score = $latestEval['overall_score'];
$ratingLabel = $latestEval['rating_label'];
$rawScore = $latestEval['raw_score'] ?? ($score / 20); // Convert back to 1-5 scale

// Determine rating class
$ratingClass = 'average';
if ($score >= 90) $ratingClass = 'outstanding';
elseif ($score >= 80) $ratingClass = 'excellent';
elseif ($score >= 70) $ratingClass = 'good';
elseif ($score >= 60) $ratingClass = 'fair';
elseif ($score < 60 && $score > 0) $ratingClass = 'poor';

// Training needs based on score
$trainingNeeds = [];
if ($score < 70) {
    $trainingNeeds[] = 'Customer Service Excellence';
    $trainingNeeds[] = 'Communication & Interpersonal Skills';
    $trainingNeeds[] = 'Job-Specific Technical Skills';
} elseif ($score < 80) {
    $trainingNeeds[] = 'Leadership & Teamwork';
    $trainingNeeds[] = 'Process Improvement';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Evaluation Report - <?= htmlspecialchars($employeeData['full_name']) ?></title>

<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f8fafc;
    padding: 30px;
    color: #1e293b;
    line-height: 1.6;
}

/* ===== HEADER ===== */
.report-header {
    background: linear-gradient(135deg, #dc3545 0%, #be123c 50%, #881337 100%);
    color: white;
    padding: 30px;
    border-radius: 16px;
    margin-bottom: 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 10px 40px rgba(220, 53, 69, 0.3);
}

.report-header .logo-section h1 {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 5px;
    letter-spacing: 0.5px;
}

.report-header .logo-section p {
    font-size: 14px;
    opacity: 0.9;
}

.report-header .date-section {
    text-align: right;
    font-size: 13px;
}

.report-header .date-section .report-date {
    font-size: 16px;
    font-weight: 600;
}

/* ===== EMPLOYEE INFO CARD ===== */
.employee-card {
    background: white;
    border-radius: 16px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
}

.employee-card .card-title {
    font-size: 14px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e2e8f0;
}

.employee-info-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

.info-item {
    padding: 15px;
    background: #f8fafc;
    border-radius: 10px;
    border-left: 4px solid #dc3545;
}

.info-item label {
    display: block;
    font-size: 11px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 5px;
}

.info-item span {
    font-size: 16px;
    font-weight: 600;
    color: #1e293b;
}

/* ===== SCORE SECTION ===== */
.score-section {
    background: linear-gradient(135deg, #dc3545 0%, #2563eb 100%);
    color: white;
    border-radius: 16px;
    padding: 40px;
    margin-bottom: 25px;
    text-align: center;
    box-shadow: 0 10px 40px rgba(220, 53, 69, 0.3);
}

.score-section h3 {
    font-size: 16px;
    opacity: 0.9;
    margin-bottom: 15px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.score-display {
    font-size: 72px;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 10px;
    text-shadow: 0 4px 15px rgba(0,0,0,0.2);
}

.rating-badge {
    display: inline-block;
    padding: 8px 24px;
    background: rgba(255,255,255,0.2);
    border-radius: 30px;
    font-size: 18px;
    font-weight: 600;
    backdrop-filter: blur(10px);
}

.score-scale {
    margin-top: 20px;
    font-size: 13px;
    opacity: 0.8;
}

/* ===== CONTENT SECTIONS ===== */
.content-section {
    background: white;
    border-radius: 16px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    border: 1px solid #e2e8f0;
}

.section-title {
    font-size: 16px;
    font-weight: 700;
    color: #dc3545;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f1f5f9;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-title::before {
    content: '';
    width: 4px;
    height: 24px;
    background: linear-gradient(180deg, #dc3545, #f59e0b);
    border-radius: 2px;
}

/* ===== EVALUATION DETAILS TABLE ===== */
.details-table {
    width: 100%;
    border-collapse: collapse;
}

.details-table th {
    background: #f8fafc;
    color: #475569;
    padding: 12px 15px;
    text-align: left;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e2e8f0;
}

.details-table td {
    padding: 15px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 14px;
    color: #334155;
}

.details-table tr:last-child td {
    border-bottom: none;
}

.details-table tr:hover {
    background: #f8fafc;
}

/* ===== NARRATIVE BOX ===== */
.narrative-box {
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    padding: 20px;
    border-radius: 12px;
    border-left: 4px solid #3b82f6;
    font-size: 14px;
    line-height: 1.8;
    color: #475569;
}

.narrative-box .quote-icon {
    color: #3b82f6;
    font-size: 24px;
    margin-bottom: 10px;
}

/* ===== TRAINING NEEDS ===== */
.training-list {
    list-style: none;
}

.training-list li {
    padding: 12px 15px;
    background: #fef3c7;
    border-radius: 8px;
    margin-bottom: 10px;
    border-left: 4px solid #f59e0b;
    font-size: 14px;
    color: #92400e;
}

.training-list li:last-child {
    margin-bottom: 0;
}

.no-training {
    padding: 20px;
    background: #d1fae5;
    border-radius: 12px;
    text-align: center;
    color: #065f46;
}

.no-training .icon {
    font-size: 32px;
    margin-bottom: 10px;
}

/* ===== EVALUATION HISTORY ===== */
.history-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: #f8fafc;
    border-radius: 10px;
    margin-bottom: 10px;
}

.history-item:last-child {
    margin-bottom: 0;
}

.history-item .period {
    font-weight: 600;
    color: #1e293b;
}

.history-item .score {
    padding: 5px 15px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 13px;
}

.history-item .score.outstanding { background: #d1fae5; color: #065f46; }
.history-item .score.excellent { background: #dbeafe; color: #1e40af; }
.history-item .score.good { background: #e0e7ff; color: #3730a3; }
.history-item .score.fair { background: #fef3c7; color: #92400e; }
.history-item .score.poor { background: #fee2e2; color: #991b1b; }

/* ===== FOOTER ===== */
.report-footer {
    margin-top: 40px;
    padding-top: 20px;
    border-top: 2px dashed #e2e8f0;
    text-align: center;
    font-size: 12px;
    color: #64748b;
}

.report-footer .confidential {
    color: #dc3545;
    font-weight: 600;
    margin-bottom: 5px;
}

/* ===== PRINT STYLES ===== */
@media print {
    body {
        background: white;
        padding: 20px;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .report-header,
    .score-section,
    .rating-badge,
    .info-item,
    .training-list li,
    .history-item .score,
    .no-training {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
    
    .employee-card,
    .content-section {
        box-shadow: none;
        border: 1px solid #e2e8f0;
        page-break-inside: avoid;
    }
    
    .score-section {
        box-shadow: none;
    }
    
    @page {
        margin: 15mm;
        size: A4;
    }
}
</style>
</head>

<body onload="window.print()">

<!-- HEADER -->
<div class="report-header">
    <div class="logo-section">
        <h1>📊 EMPLOYEE EVALUATION REPORT</h1>
        <p>HR2 Competency Management System • HR1 Integration</p>
    </div>
    <div class="date-section">
        <div>Report Generated</div>
        <div class="report-date"><?= date('F d, Y') ?></div>
        <div><?= date('h:i A') ?></div>
    </div>
</div>

<!-- EMPLOYEE INFO -->
<div class="employee-card">
    <div class="card-title">👤 Employee Information</div>
    <div class="employee-info-grid">
        <div class="info-item">
            <label>Full Name</label>
            <span><?= htmlspecialchars($employeeData['full_name']) ?></span>
        </div>
        <div class="info-item">
            <label>Position</label>
            <span><?= htmlspecialchars($employeeData['job_position']) ?></span>
        </div>
        <div class="info-item">
            <label>Email</label>
            <span><?= htmlspecialchars($employeeData['email']) ?></span>
        </div>
        <div class="info-item">
            <label>Employee ID (HR1)</label>
            <span>#<?= $employeeData['id'] ?></span>
        </div>
        <div class="info-item">
            <label>Evaluation Period</label>
            <span><?= htmlspecialchars($latestEval['period'] ?: 'N/A') ?></span>
        </div>
        <div class="info-item">
            <label>Due Date</label>
            <span><?= $latestEval['due_date'] ? date('F d, Y', strtotime($latestEval['due_date'])) : 'N/A' ?></span>
        </div>
    </div>
</div>

<!-- SCORE SECTION -->
<div class="score-section">
    <h3>📈 Overall Performance Score</h3>
    <div class="score-display"><?= number_format($score, 1) ?>%</div>
    <div class="rating-badge">⭐ <?= htmlspecialchars($ratingLabel) ?></div>
    <div class="score-scale">Based on 1-5 scale evaluation (Raw Score: <?= number_format($rawScore, 2) ?>/5.00)</div>
</div>

<!-- EVALUATION DETAILS -->
<div class="content-section">
    <div class="section-title">📋 Evaluation Details</div>
    <table class="details-table">
        <thead>
            <tr>
                <th>Field</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Status</strong></td>
                <td><?= ucfirst(htmlspecialchars($latestEval['status'] ?: 'N/A')) ?></td>
            </tr>
            <tr>
                <td><strong>Created Date</strong></td>
                <td><?= $latestEval['created_at'] ? date('F d, Y', strtotime($latestEval['created_at'])) : 'N/A' ?></td>
            </tr>
            <tr>
                <td><strong>Data Source</strong></td>
                <td>HR1 Performance Evaluation System</td>
            </tr>
        </tbody>
    </table>
</div>

<?php if (!empty($latestEval['narrative'])): ?>
<!-- PERFORMANCE NARRATIVE -->
<div class="content-section">
    <div class="section-title">💬 Performance Narrative</div>
    <div class="narrative-box">
        <div class="quote-icon">❝</div>
        <?= nl2br(htmlspecialchars($latestEval['narrative'])) ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($latestEval['notes'])): ?>
<!-- ADDITIONAL NOTES -->
<div class="content-section">
    <div class="section-title">📝 Additional Notes</div>
    <div class="narrative-box" style="border-left-color: #f59e0b;">
        <?= nl2br(htmlspecialchars($latestEval['notes'])) ?>
    </div>
</div>
<?php endif; ?>

<!-- TRAINING RECOMMENDATIONS -->
<div class="content-section">
    <div class="section-title">🎓 Training Recommendations</div>
    <?php if (count($trainingNeeds) > 0): ?>
        <ul class="training-list">
            <?php foreach ($trainingNeeds as $need): ?>
                <li>⚠️ <?= htmlspecialchars($need) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <div class="no-training">
            <div class="icon">🏆</div>
            <strong>Excellent Performance!</strong>
            <p>No additional training required at this time.</p>
        </div>
    <?php endif; ?>
</div>

<?php if (count($evaluations) > 1): ?>
<!-- EVALUATION HISTORY -->
<div class="content-section">
    <div class="section-title">📅 Evaluation History</div>
    <?php foreach ($evaluations as $index => $eval): 
        $histScore = $eval['overall_score'];
        $histClass = 'fair';
        if ($histScore >= 90) $histClass = 'outstanding';
        elseif ($histScore >= 80) $histClass = 'excellent';
        elseif ($histScore >= 70) $histClass = 'good';
        elseif ($histScore < 60 && $histScore > 0) $histClass = 'poor';
    ?>
        <div class="history-item">
            <div class="period">
                <?= htmlspecialchars($eval['period'] ?: 'Period ' . ($index + 1)) ?>
                <?php if ($index === 0): ?><small>(Latest)</small><?php endif; ?>
            </div>
            <div class="score <?= $histClass ?>">
                <?= number_format($histScore, 1) ?>% - <?= htmlspecialchars($eval['rating_label']) ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- FOOTER -->
<div class="report-footer">
    <div class="confidential">⚠️ CONFIDENTIAL DOCUMENT</div>
    <div>© <?= date('Y') ?> O!SAVE Convenience Store • HR2 Competency Management System</div>
    <div>Data sourced from HR1 Performance Evaluation System</div>
</div>

</body>
</html>

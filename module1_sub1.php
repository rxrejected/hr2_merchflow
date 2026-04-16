<?php
// Include centralized session handler (handles session start, timeout, and activity tracking)
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';
require_once 'Connection/hr1_db.php';

// Admin role guard
$userRole = strtolower(str_replace(' ', '', $_SESSION['role'] ?? ''));
if (!in_array($userRole, ['admin', 'manager', 'superadmin'])) {
    header('Location: employee.php');
    exit();
}

// Use DIRECT database connection for REAL-TIME data
$hr1db = new HR1Database();
$hr1Response = $hr1db->getEvaluations('completed', 500);
$hr1Evaluations = $hr1Response['success'] ? $hr1Response['data'] : [];

// Also fetch HR1 employees for date_hired (old/new indicator)
$hr1EmpResponse = $hr1db->getEmployees('', 500, 0);
$hr1EmployeesList = $hr1EmpResponse['success'] ? $hr1EmpResponse['data'] : [];
$hr1db->close();

// Map HR1 employees by ID for quick lookup
$hr1EmpMap = [];
foreach ($hr1EmployeesList as $e) {
    $hr1EmpMap[$e['id']] = $e;
}

// Fetch HR2 assessments (latest per employee) — provides part of 70% HR2 score
$hr2Assessments = [];
$hr2Result = $conn->query("SELECT a.* FROM hr2_assessments a INNER JOIN (SELECT hr1_employee_id, MAX(id) as max_id FROM hr2_assessments WHERE status='completed' GROUP BY hr1_employee_id) b ON a.id = b.max_id");
if ($hr2Result) {
    while ($row = $hr2Result->fetch_assoc()) {
        $hr2Assessments[$row['hr1_employee_id']] = $row;
    }
}

// Fetch additional HR2 data sources for FULL blended scoring (same as module5_sub5_admin)
$hr2TrainingScores = [];
$trResult = @$conn->query("SELECT user_id, SUM(CASE WHEN attended='Yes' THEN 1 ELSE 0 END) as attended, SUM(CASE WHEN training_result='Passed' THEN 1 ELSE 0 END) as passed FROM training_attendance GROUP BY user_id");
if ($trResult) {
    while ($r = $trResult->fetch_assoc()) {
        $att = (int)$r['attended'];
        $hr2TrainingScores[(int)$r['user_id']] = $att > 0 ? round(((int)$r['passed'] / $att) * 100, 1) : 0;
    }
}

$hr2CourseScores = [];
$cpCheck = @$conn->query("SHOW TABLES LIKE 'course_progress'");
if ($cpCheck && $cpCheck->num_rows > 0) {
    $cpResult = @$conn->query("SELECT employee_id, ROUND(AVG(watched_percent),1) as avg_progress FROM course_progress GROUP BY employee_id");
    if ($cpResult) {
        while ($r = $cpResult->fetch_assoc()) {
            $hr2CourseScores[(int)$r['employee_id']] = min(100, (float)$r['avg_progress']);
        }
    }
}

$hr2AssessmentCompletion = [];
$asCheck = @$conn->query("SHOW TABLES LIKE 'assessment'");
if ($asCheck && $asCheck->num_rows > 0) {
    $asResult = @$conn->query("SELECT employee_id, COUNT(*) as cnt FROM assessment GROUP BY employee_id");
    if ($asResult) {
        while ($r = $asResult->fetch_assoc()) {
            $hr2AssessmentCompletion[(int)$r['employee_id']] = (int)$r['cnt'] > 0 ? 100 : 0;
        }
    }
}

// Helper: Calculate full HR2 score for an employee (avg of all available HR2 sources)
function getFullHR2Score($empId, $hr2Assessments, $hr2TrainingScores, $hr2CourseScores, $hr2AssessmentCompletion) {
    $components = [];
    if (isset($hr2Assessments[$empId])) $components[] = (float)$hr2Assessments[$empId]['overall_score'];
    if (isset($hr2TrainingScores[$empId])) $components[] = $hr2TrainingScores[$empId];
    if (isset($hr2CourseScores[$empId])) $components[] = $hr2CourseScores[$empId];
    if (isset($hr2AssessmentCompletion[$empId])) $components[] = $hr2AssessmentCompletion[$empId];
    return count($components) > 0 ? round(array_sum($components) / count($components), 1) : 0;
}

// Fetch certificate counts per employee
$certCounts = [];
$certResult = $conn->query("SELECT hr1_employee_id, COUNT(*) as cert_count FROM employee_certificates GROUP BY hr1_employee_id");
if ($certResult) {
    while ($row = $certResult->fetch_assoc()) {
        $certCounts[$row['hr1_employee_id']] = (int)$row['cert_count'];
    }
}

// Fetch certificates list per employee  
$certsByEmployee = [];
$certListResult = $conn->query("SELECT * FROM employee_certificates ORDER BY date_issued DESC");
if ($certListResult) {
    while ($row = $certListResult->fetch_assoc()) {
        $certsByEmployee[$row['hr1_employee_id']][] = $row;
    }
}

// Group evaluations by employee (using employee_id from HR1)
$employeeEvaluations = [];
foreach ($hr1Evaluations as $eval) {
    $empId = $eval['employee_id'];
    if (!isset($employeeEvaluations[$empId])) {
        $employeeEvaluations[$empId] = [
            'employee_id' => $empId,
            'employee_name' => $eval['employee_name'],
            'employee_email' => $eval['employee_email'],
            'employee_code' => $eval['employee_code'] ?? '',
            'role' => $eval['role'] ?? '',
            'department' => $eval['department'] ?? '',
            'evaluations' => []
        ];
    }
    $employeeEvaluations[$empId]['evaluations'][] = $eval;
}

// Function to get latest evaluation for an HR1 employee
function getLatestEvaluation($employeeData) {
    if (isset($employeeData['evaluations']) && count($employeeData['evaluations']) > 0) {
        return $employeeData['evaluations'][0]; // Most recent
    }
    return null;
}

// Function to determine training needs based on COMBINED score
function getTrainingNeeds($evaluation, $hr2Score = 0) {
    $needs = ['soft_skills' => [], 'hard_skills' => []];
    
    // Calculate combined score
    $hr1Score = ($evaluation && isset($evaluation['overall_score'])) ? (float)$evaluation['overall_score'] : 0;
    $combinedScore = ($hr1Score * 0.30) + ($hr2Score * 0.70);
    
    if ($combinedScore <= 0 && $hr1Score <= 0) return $needs;
    
    // Use combined score for training recommendations
    $score = $combinedScore > 0 ? $combinedScore : $hr1Score;
    
    if ($score < 70) {
        $needs['soft_skills'][] = 'Customer Service Excellence';
        $needs['soft_skills'][] = 'Communication & Interpersonal Skills';
        $needs['hard_skills'][] = 'Job-Specific Technical Skills';
    } elseif ($score < 80) {
        $needs['soft_skills'][] = 'Leadership & Teamwork';
        $needs['hard_skills'][] = 'Process Improvement';
    }
    
    return $needs;
}

// Function to check if employee is New or Old/Regular
function getEmployeeTenure($hr1Employee) {
    $dateHired = $hr1Employee['date_hired'] ?? $hr1Employee['start_date'] ?? $hr1Employee['created_at'] ?? null;
    if (!$dateHired) return ['status' => 'unknown', 'label' => 'Unknown', 'months' => 0];
    
    $hiredDate = new DateTime($dateHired);
    $now = new DateTime();
    $diff = $now->diff($hiredDate);
    $totalMonths = ($diff->y * 12) + $diff->m;
    
    // New = less than 6 months or probationary
    $probEnd = $hr1Employee['probation_end'] ?? null;
    $isOnProbation = $probEnd && strtotime($probEnd) > time();
    
    if ($totalMonths < 6 || $isOnProbation) {
        return ['status' => 'new', 'label' => 'New Employee', 'months' => $totalMonths, 'date_hired' => $dateHired];
    }
    return ['status' => 'regular', 'label' => 'Regular Employee', 'months' => $totalMonths, 'date_hired' => $dateHired];
}

// Function to calculate combined score (30% HR1 + 70% HR2)
function getCombinedScore($hr1Score, $hr2Score) {
    $hr1Component = $hr1Score * 0.30;
    $hr2Component = $hr2Score * 0.70;
    return [
        'hr1_score' => $hr1Score,
        'hr2_score' => $hr2Score,
        'hr1_weighted' => round($hr1Component, 2),
        'hr2_weighted' => round($hr2Component, 2),
        'combined' => round($hr1Component + $hr2Component, 2)
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0" />
  <meta name="description" content="Employee Evaluation Reports - Competency Management System" />
  <meta name="theme-color" content="#e11d48" />
  <title>Competency Management | Evaluation Reports</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <link rel="icon" type="image/png" href="osicon.png" />
  <link rel="stylesheet" href="Css/module1_sub1.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'partials/sidebar.php'; ?>

  <div class="main-content">
    <?php include 'partials/nav.php'; ?>

    <div class="container">
      
      <div class="page-header">
        <div class="header-content">
          <h2><i class="fas fa-chart-line"></i> Competency Management</h2>
          <p class="page-subtitle info-notice">
            <i class="fas fa-database"></i> Combined Score: <strong>30% HR1</strong> + <strong>70% HR2</strong>
            <span style="margin-left: 10px; font-size: 0.75rem; opacity: 0.8;">
              <i class="fas fa-clock"></i> Last updated: <?php echo date('M d, Y h:i A'); ?>
            </span>
          </p>
        </div>
        <div class="header-actions">
          <button class="action-btn" id="refreshBtn" title="Refresh Real-time Data">
            <i class="fas fa-sync-alt"></i>
            <span>Refresh</span>
          </button>
        </div>
      </div>

      <?php
      // Calculate statistics using COMBINED scores (30% HR1 + 70% HR2)
      $totalEmployees = count($employeeEvaluations);
      $needSoftSkills = 0;
      $needHardSkills = 0;
      $excellentPerformers = 0;
      $assessedByHR2 = 0;
      
      foreach($employeeEvaluations as $empData) {
          $eval = getLatestEvaluation($empData);
          $empId = $empData['employee_id'];
          $hr2FullScore = getFullHR2Score($empId, $hr2Assessments, $hr2TrainingScores, $hr2CourseScores, $hr2AssessmentCompletion);
          
          if($eval || $hr2FullScore > 0) {
              $needs = getTrainingNeeds($eval, $hr2FullScore);
              if(count($needs['soft_skills']) > 0) $needSoftSkills++;
              if(count($needs['hard_skills']) > 0) $needHardSkills++;
              
              $hr1Score = $eval ? (float)$eval['overall_score'] : 0;
              $combined = ($hr1Score * 0.30) + ($hr2FullScore * 0.70);
              if($combined >= 90) $excellentPerformers++;
              if($hr2FullScore > 0) $assessedByHR2++;
          }
      }
      ?>

      <!-- Statistics Banner -->
      <div class="stats-banner">
        <div class="stat-card" data-aos="fade-up" data-aos-delay="100">
          <div class="stat-icon primary">
            <i class="fas fa-users"></i>
          </div>
          <div class="stat-content">
            <h3 class="counter" data-target="<?php echo $totalEmployees; ?>"><?php echo $totalEmployees; ?></h3>
            <p>Total Evaluated</p>
          </div>
          <div class="stat-trend up">
            <i class="fas fa-arrow-up"></i>
          </div>
        </div>
        <div class="stat-card" data-aos="fade-up" data-aos-delay="200">
          <div class="stat-icon info">
            <i class="fas fa-user-friends"></i>
          </div>
          <div class="stat-content">
            <h3 class="counter" data-target="<?php echo $needSoftSkills; ?>"><?php echo $needSoftSkills; ?></h3>
            <p>Need Soft Skills</p>
          </div>
          <?php if($needSoftSkills > 0): ?>
          <div class="stat-trend warning">
            <i class="fas fa-exclamation"></i>
          </div>
          <?php endif; ?>
        </div>
        <div class="stat-card" data-aos="fade-up" data-aos-delay="300">
          <div class="stat-icon warning">
            <i class="fas fa-tools"></i>
          </div>
          <div class="stat-content">
            <h3 class="counter" data-target="<?php echo $needHardSkills; ?>"><?php echo $needHardSkills; ?></h3>
            <p>Need Hard Skills</p>
          </div>
          <?php if($needHardSkills > 0): ?>
          <div class="stat-trend warning">
            <i class="fas fa-exclamation"></i>
          </div>
          <?php endif; ?>
        </div>
        <div class="stat-card" data-aos="fade-up" data-aos-delay="400">
          <div class="stat-icon success">
            <i class="fas fa-star"></i>
          </div>
          <div class="stat-content">
            <h3 class="counter" data-target="<?php echo $excellentPerformers; ?>"><?php echo $excellentPerformers; ?></h3>
            <p>Excellent Performers</p>
          </div>
          <div class="stat-trend up">
            <i class="fas fa-trophy"></i>
          </div>
        </div>
      </div>

      <!-- Search & Sort -->
      <div class="search-sort">
        <div class="search-wrapper">
          <i class="fas fa-search search-icon"></i>
          <input type="text" id="searchInput" placeholder="Search employee name..." autocomplete="off" />
          <button class="clear-search" id="clearSearch" title="Clear search">
            <i class="fas fa-times"></i>
          </button>
        </div>
        <div class="filter-wrapper">
          <select id="filterSelect" title="Filter by training needs">
            <option value="all">All Employees</option>
            <option value="soft">Needs Soft Skills</option>
            <option value="hard">Needs Hard Skills</option>
            <option value="none">No Training Needed</option>
            <option value="not-evaluated">Not Evaluated</option>
          </select>
          <select id="sortSelect" title="Sort employees">
            <option value="asc">Name (A-Z)</option>
            <option value="desc">Name (Z-A)</option>
            <option value="rating-high">Rating (High to Low)</option>
            <option value="rating-low">Rating (Low to High)</option>
            <option value="training-needs">Most Training Needs</option>
            <option value="recent">Recently Evaluated</option>
          </select>
        </div>
      </div>

      <!-- Results Count -->
      <div class="results-info">
        <span id="resultsCount">
          <i class="fas fa-database" style="color: var(--primary-red); margin-right: 5px;"></i>
          Showing <?php echo count($employeeEvaluations); ?> employees (HR1 Database)
        </span>
      </div>

      <!-- Employee Grid - COMBINED SCORING (30% HR1 + 70% HR2) -->
      <div class="grid" id="employeeGrid">
        <?php if(count($employeeEvaluations) > 0): ?>
          <?php foreach($employeeEvaluations as $empData): 
            $eval = getLatestEvaluation($empData);
            $empId = $empData['employee_id'];
            $hr1Score = $eval ? (float)$eval['overall_score'] : 0;
            $hr2Score = getFullHR2Score($empId, $hr2Assessments, $hr2TrainingScores, $hr2CourseScores, $hr2AssessmentCompletion);
            $combinedData = getCombinedScore($hr1Score, $hr2Score);
            $combinedScore = $combinedData['combined'];
            
            $trainingNeeds = getTrainingNeeds($eval, $hr2Score);
            $totalNeeds = count($trainingNeeds['soft_skills']) + count($trainingNeeds['hard_skills']);
            $hasSoftSkills = count($trainingNeeds['soft_skills']) > 0;
            $hasHardSkills = count($trainingNeeds['hard_skills']) > 0;
            
            // Combined rating label
            if ($combinedScore >= 90) $combinedRating = 'Outstanding';
            elseif ($combinedScore >= 80) $combinedRating = 'Excellent';
            elseif ($combinedScore >= 70) $combinedRating = 'Very Good';
            elseif ($combinedScore >= 60) $combinedRating = 'Good';
            elseif ($combinedScore >= 50) $combinedRating = 'Fair';
            elseif ($combinedScore > 0) $combinedRating = 'Needs Improvement';
            else $combinedRating = 'Not Evaluated';
            
            $ratingLabel = $combinedScore > 0 ? $combinedRating : ($eval ? $eval['rating_label'] : 'Not Evaluated');
            $displayScore = $combinedScore > 0 ? $combinedScore : $hr1Score;
            $evalDate = $eval ? $eval['due_date'] : '';
            
            // Old/New employee
            $hr1Emp = $hr1EmpMap[$empId] ?? null;
            $tenure = $hr1Emp ? getEmployeeTenure($hr1Emp) : ['status' => 'unknown', 'label' => 'Unknown', 'months' => 0];
            
            // Certificates count
            $certCount = $certCounts[$empId] ?? 0;
          ?>
            <div class="employee-card" 
                 data-id="<?= $empData['employee_id'] ?>" 
                 data-name="<?= strtolower($empData['employee_name']) ?>"
                 data-rating="<?= $ratingLabel ?>"
                 data-training-needs="<?= $totalNeeds ?>"
                 data-has-soft="<?= $hasSoftSkills ? '1' : '0' ?>"
                 data-has-hard="<?= $hasHardSkills ? '1' : '0' ?>"
                 data-evaluated="<?= $eval ? '1' : '0' ?>"
                 data-eval-date="<?= $evalDate ?>"
                 data-score="<?= $displayScore ?>">
              
              <div class="card-avatar">
                <img src="uploads/avatars/default.png" 
                     alt="<?= htmlspecialchars($empData['employee_name']) ?>" 
                     loading="lazy"
                     onerror="this.src='uploads/avatars/default.png'" />
                <?php if($combinedScore >= 90): ?>
                  <span class="avatar-badge"><i class="fas fa-star"></i></span>
                <?php endif; ?>
              </div>
              
              <h3><?= htmlspecialchars($empData['employee_name']) ?></h3>
              <p class="job-title"><i class="fas fa-briefcase"></i> <?= htmlspecialchars($empData['role'] ?: 'Employee') ?></p>
              
              <!-- Old/New Employee Badge -->
              <div class="tenure-badge tenure-<?= $tenure['status'] ?>">
                <?php if ($tenure['status'] === 'new'): ?>
                  <i class="fas fa-sparkles"></i> New Employee
                  <?php if ($tenure['months'] > 0): ?>
                    <small>(<?= $tenure['months'] ?> mo)</small>
                  <?php endif; ?>
                <?php elseif ($tenure['status'] === 'regular'): ?>
                  <i class="fas fa-user-check"></i> Regular Employee
                  <?php if ($tenure['months'] >= 12): ?>
                    <small>(<?= floor($tenure['months']/12) ?>y <?= $tenure['months']%12 ?>m)</small>
                  <?php else: ?>
                    <small>(<?= $tenure['months'] ?> mo)</small>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
              
              <?php if($eval || $hr2): ?>
                <!-- Combined Score Display -->
                <div class="combined-score-section">
                  <div class="combined-score-main">
                    <span class="combined-score-value"><?= number_format($displayScore, 1) ?>%</span>
                    <span class="rating-badge rating-<?= strtolower(str_replace(' ', '-', $ratingLabel)) ?>">
                      <i class="fas fa-award"></i> <?= htmlspecialchars($ratingLabel) ?>
                    </span>
                  </div>
                  
                  <!-- Score Breakdown -->
                  <div class="score-breakdown">
                    <div class="score-component hr1">
                      <small>HR1 (30%)</small>
                      <div class="mini-bar"><div class="mini-fill" style="width: <?= $hr1Score ?>%"></div></div>
                      <span><?= number_format($hr1Score, 1) ?>%</span>
                      <span class="weighted-val">→ <?= number_format($combinedData['hr1_weighted'], 1) ?>%</span>
                    </div>
                    <div class="score-component hr2">
                      <small>HR2 (70%)</small>
                      <div class="mini-bar"><div class="mini-fill" style="width: <?= $hr2Score ?>%"></div></div>
                      <span><?= number_format($hr2Score, 1) ?>%</span>
                      <span class="weighted-val">→ <?= number_format($combinedData['hr2_weighted'], 1) ?>%</span>
                    </div>
                  </div>
                  
                  <?php if ($hr2Score == 0): ?>
                  <div class="no-hr2-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <small>HR2 assessment pending — showing HR1 score only (30% weight)</small>
                  </div>
                  <?php endif; ?>
                </div>
                
                <!-- Certificates Badge -->
                <?php if ($certCount > 0): ?>
                <div class="cert-badge-row">
                  <span class="cert-indicator" title="<?= $certCount ?> certificate(s) — more certificates = more knowledgeable">
                    <i class="fas fa-certificate"></i> <?= $certCount ?> Certificate<?= $certCount > 1 ? 's' : '' ?>
                  </span>
                </div>
                <?php endif; ?>

                <!-- Training Needs Indicators -->
                <div class="training-needs-section">
                  <h4><i class="fas fa-graduation-cap"></i> Training Needs</h4>
                  <div class="training-badges">
                    <?php if($totalNeeds > 0): ?>
                      <?php if($hasSoftSkills): ?>
                        <span class="training-needs-badge soft-skill-badge" title="<?= implode(', ', $trainingNeeds['soft_skills']) ?>">
                          <i class="fas fa-user-friends"></i> Soft Skills (<?= count($trainingNeeds['soft_skills']) ?>)
                        </span>
                      <?php endif; ?>
                      <?php if($hasHardSkills): ?>
                        <span class="training-needs-badge hard-skill-badge" title="<?= implode(', ', $trainingNeeds['hard_skills']) ?>">
                          <i class="fas fa-tools"></i> Hard Skills (<?= count($trainingNeeds['hard_skills']) ?>)
                        </span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="training-needs-badge no-training-badge">
                        <i class="fas fa-check-circle"></i> No Training Needed
                      </span>
                    <?php endif; ?>
                  </div>
                </div>

                <p class="evaluation-date">
                  <i class="far fa-calendar-alt"></i> Due Date: 
                  <?= date('M d, Y', strtotime($evalDate)) ?>
                </p>
              <?php else: ?>
                <div class="no-evaluation">
                  <i class="fas fa-clipboard-list"></i>
                  <p>No evaluation data</p>
                </div>
              <?php endif; ?>
              
              <button class="view-btn" aria-label="View full report for <?= htmlspecialchars($empData['employee_name']) ?>">
                <i class="fas fa-eye"></i> View Full Report
              </button>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="empty-state">
            <i class="fas fa-database"></i>
            <h3>No HR1 Data Found</h3>
            <p>Unable to fetch employee evaluations from HR1 database.</p>
            <?php if(!$hr1Response['success']): ?>
              <p style="color: #ef4444; font-size: 0.9rem;">
                <i class="fas fa-exclamation-triangle"></i> 
                Error: <?= htmlspecialchars($hr1Response['error'] ?? 'Database connection failed') ?>
              </p>
              <p style="font-size: 0.8rem; color: #666;">
                Please check if the HR1 database is accessible and credentials are correct.
              </p>
            <?php endif; ?>
            <button class="action-btn" onclick="location.reload()" style="margin-top: 1rem;">
              <i class="fas fa-sync-alt"></i> Retry Connection
            </button>
          </div>
        <?php endif; ?>
      </div>

      <!-- No Results Message -->
      <div class="no-results" id="noResults" style="display: none;">
        <i class="fas fa-search"></i>
        <h3>No Results Found</h3>
        <p>Try adjusting your search or filter criteria</p>
        <button class="reset-btn" id="resetFilters">Reset Filters</button>
      </div>
    </div>
  </div>

<!-- Modal for Full Report -->
<div id="evaluationModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="employeeName">
  <div class="modal-content">
    <div class="modal-header">
      <h3 id="employeeName"><i class="fas fa-file-alt"></i> Evaluation Report</h3>
      <div class="modal-header-actions">
        <button id="downloadPdfBtn" class="modal-btn" title="Download as PDF">
          <i class="fas fa-file-pdf"></i> <span>Download PDF</span>
        </button>
        <button class="close" aria-label="Close modal">&times;</button>
      </div>
    </div>
    <div id="reportContent">
      <div class="loading-state">
        <i class="fas fa-spinner fa-spin"></i>
        <p>Loading report...</p>
      </div>
    </div>
  </div>
</div>

<!-- Toast Notification -->
<div id="toast" class="toast">
  <i class="fas fa-check-circle"></i>
  <span id="toastMessage">Action completed</span>
</div>



  <script>
// ===== GLOBAL STATE =====
let selectedEmployeeId = null;
const modal = document.getElementById('evaluationModal');
const closeBtn = modal.querySelector('.close');
const reportContent = document.getElementById('reportContent');
const searchInput = document.getElementById('searchInput');
const sortSelect = document.getElementById('sortSelect');
const filterSelect = document.getElementById('filterSelect');
const noResults = document.getElementById('noResults');
const resultsCount = document.getElementById('resultsCount');
const toast = document.getElementById('toast');

// ===== EMPLOYEE DATA FROM HR1 + HR2 (COMBINED SCORING) =====
const employees = <?php 
  $emp_data = [];
  foreach($employeeEvaluations as $empData) {
    $eval = getLatestEvaluation($empData);
    $empId = $empData['employee_id'];
    $hr1Score = $eval ? (float)$eval['overall_score'] : 0;
    $hr2Score = getFullHR2Score($empId, $hr2Assessments, $hr2TrainingScores, $hr2CourseScores, $hr2AssessmentCompletion);
    $combined = getCombinedScore($hr1Score, $hr2Score);
    $hr1Emp = $hr1EmpMap[$empId] ?? null;
    $tenure = $hr1Emp ? getEmployeeTenure($hr1Emp) : ['status' => 'unknown', 'label' => 'Unknown', 'months' => 0, 'date_hired' => null];
    $empCerts = $certsByEmployee[$empId] ?? [];
    
    $emp_data[] = [
        'id' => $empData['employee_id'],
        'employee_code' => $empData['employee_code'] ?? '',
        'full_name' => $empData['employee_name'],
        'email' => $empData['employee_email'],
        'job_position' => $empData['role'] ?: 'Employee',
        'department' => $empData['department'] ?? 'Operations',
        'hr1_evaluation' => $eval,
        'hr2_assessment' => $hr2Assessments[$empId] ?? null,
        'combined_score' => $combined,
        'training_needs' => getTrainingNeeds($eval, $hr2Score),
        'tenure' => $tenure,
        'certificates' => $empCerts,
        'cert_count' => count($empCerts)
    ];
  }
  echo json_encode($emp_data);
?>;

const employeeEvaluations = <?php echo json_encode($employeeEvaluations); ?>;

const skillCategories = {
  soft: {
    'customer_service': 'Customer Service Excellence',
    'teamwork': 'Teamwork & Collaboration',
    'attendance': 'Time Management & Punctuality'
  },
  hard: {
    'cash_handling': 'Cash Handling & Financial Accuracy',
    'inventory': 'Inventory Management Systems'
  }
};

// ===== UTILITY FUNCTIONS =====
function getPerformanceClass(rating) {
  return ['Average', 'Poor'].includes(rating) ? 'needs-improvement' : '';
}

function getRatingIcon(rating) {
  const icons = {
    'Excellent': 'fas fa-star',
    'Good': 'fas fa-thumbs-up',
    'Average': 'fas fa-minus-circle',
    'Poor': 'fas fa-exclamation-circle'
  };
  return icons[rating] || 'fas fa-question-circle';
}

function showToast(message, type = 'success') {
  const toastEl = document.getElementById('toast');
  const toastMessage = document.getElementById('toastMessage');
  toastMessage.textContent = message;
  toastEl.className = `toast show ${type}`;
  setTimeout(() => toastEl.classList.remove('show'), 3000);
}

function updateResultsCount() {
  const visible = document.querySelectorAll('.employee-card:not([style*="display: none"])').length;
  const total = document.querySelectorAll('.employee-card').length;
  resultsCount.textContent = `Showing ${visible} of ${total} employees`;
  noResults.style.display = visible === 0 ? 'flex' : 'none';
  document.getElementById('employeeGrid').style.display = visible === 0 ? 'none' : 'grid';
}

// ===== SEARCH FUNCTIONALITY =====
searchInput.addEventListener('input', debounce(function() {
  applyFilters();
}, 300));

// Clear search button
document.getElementById('clearSearch').addEventListener('click', function() {
  searchInput.value = '';
  applyFilters();
});

// ===== FILTER FUNCTIONALITY =====
filterSelect.addEventListener('change', applyFilters);

function applyFilters() {
  const searchTerm = searchInput.value.toLowerCase().trim();
  const filterValue = filterSelect.value;
  
  document.querySelectorAll('.employee-card').forEach(card => {
    const name = card.getAttribute('data-name');
    const hasSoft = card.getAttribute('data-has-soft') === '1';
    const hasHard = card.getAttribute('data-has-hard') === '1';
    const isEvaluated = card.getAttribute('data-evaluated') === '1';
    
    let matchesSearch = name.includes(searchTerm);
    let matchesFilter = true;
    
    switch(filterValue) {
      case 'soft':
        matchesFilter = hasSoft;
        break;
      case 'hard':
        matchesFilter = hasHard;
        break;
      case 'none':
        matchesFilter = isEvaluated && !hasSoft && !hasHard;
        break;
      case 'not-evaluated':
        matchesFilter = !isEvaluated;
        break;
    }
    
    card.style.display = (matchesSearch && matchesFilter) ? '' : 'none';
  });
  
  updateResultsCount();
}

// ===== SORT FUNCTIONALITY =====
sortSelect.addEventListener('change', function() {
  const cards = Array.from(document.querySelectorAll('.employee-card'));
  const grid = document.getElementById('employeeGrid');
  const sortOrder = this.value;

  cards.sort((a, b) => {
    if(sortOrder === 'asc' || sortOrder === 'desc') {
      const nameA = a.getAttribute('data-name');
      const nameB = b.getAttribute('data-name');
      return sortOrder === 'asc' ? nameA.localeCompare(nameB) : nameB.localeCompare(nameA);
    } else if(sortOrder === 'training-needs') {
      const needsA = parseInt(a.getAttribute('data-training-needs')) || 0;
      const needsB = parseInt(b.getAttribute('data-training-needs')) || 0;
      return needsB - needsA;
    } else if(sortOrder === 'recent') {
      const dateA = a.getAttribute('data-eval-date') || '';
      const dateB = b.getAttribute('data-eval-date') || '';
      return dateB.localeCompare(dateA);
    } else {
      const ratingOrder = {excellent: 4, good: 3, average: 2, poor: 1, '': 0};
      const ratingA = ratingOrder[a.getAttribute('data-rating')?.toLowerCase()] || 0;
      const ratingB = ratingOrder[b.getAttribute('data-rating')?.toLowerCase()] || 0;
      return sortOrder === 'rating-high' ? ratingB - ratingA : ratingA - ratingB;
    }
  });

  cards.forEach(card => grid.appendChild(card));
});

// ===== RESET FILTERS =====
document.getElementById('resetFilters').addEventListener('click', function() {
  searchInput.value = '';
  filterSelect.value = 'all';
  sortSelect.value = 'asc';
  applyFilters();
});

// ===== REFRESH BUTTON - REAL-TIME FROM HR1 =====
document.getElementById('refreshBtn').addEventListener('click', async function() {
  const btn = this;
  const icon = btn.querySelector('i');
  icon.classList.add('fa-spin');
  btn.disabled = true;
  
  try {
    showToast('Fetching real-time data from HR1...', 'info');
    
    const response = await fetch('api_hr1_realtime.php?action=combined&limit=200&_=' + Date.now());
    const data = await response.json();
    
    if (data.success) {
      // Update stats
      if (data.stats) {
        updateStatCard(0, data.stats.total_evaluated);
        updateStatCard(1, data.stats.need_soft_skills);
        updateStatCard(2, data.stats.need_hard_skills);
        updateStatCard(3, data.stats.excellent_performers);
      }
      
      showToast(`Data refreshed! ${data.count} employees loaded from HR1`, 'success');
      
      // Optionally reload for full update
      setTimeout(() => location.reload(), 1000);
    } else {
      showToast('Error: ' + (data.error || 'Failed to fetch data'), 'error');
    }
  } catch (error) {
    console.error('Refresh error:', error);
    showToast('Connection error. Please try again.', 'error');
  } finally {
    icon.classList.remove('fa-spin');
    btn.disabled = false;
  }
});

function updateStatCard(index, value) {
  const statCards = document.querySelectorAll('.stat-card .counter');
  if (statCards[index]) {
    statCards[index].textContent = value;
    statCards[index].setAttribute('data-target', value);
  }
}

// ===== DEBOUNCE HELPER =====
function debounce(func, wait) {
  let timeout;
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout);
      func(...args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
}

// ===== MODAL HANDLERS =====
document.getElementById('employeeGrid').addEventListener('click', function(e) {
  const btn = e.target.closest('.view-btn');
  if(!btn) return;
  
  const card = btn.closest('.employee-card');
  selectedEmployeeId = card.getAttribute('data-id');
  const emp = employees.find(e => e.id == selectedEmployeeId);
  
  document.getElementById('employeeName').innerHTML = 
    '<i class="fas fa-user-circle"></i> ' + emp.full_name;
  
  // Build combined report (30% HR1 + 70% HR2)
  const hr1Eval = emp.hr1_evaluation;
  const hr2Assess = emp.hr2_assessment;
  const combined = emp.combined_score;
  const tenure = emp.tenure;
  const certs = emp.certificates || [];
  
  // Tenure badge HTML
  const tenureBadgeHTML = tenure.status === 'new' 
    ? `<span class="tenure-badge-modal new"><i class="fas fa-sparkles"></i> New Employee ${tenure.months > 0 ? '(' + tenure.months + ' months)' : ''}</span>`
    : tenure.status === 'regular'
    ? `<span class="tenure-badge-modal regular"><i class="fas fa-user-check"></i> Regular Employee ${tenure.months >= 12 ? '(' + Math.floor(tenure.months/12) + 'y ' + (tenure.months%12) + 'm)' : '(' + tenure.months + ' months)'}</span>`
    : '';
  
  // Certificates HTML
  let certsHTML = '';
  if (certs.length > 0) {
    certsHTML = `
      <div class="report-section certificates-section">
        <h4><i class="fas fa-certificate"></i> Employee Certificates (${certs.length})</h4>
        <p class="cert-note"><i class="fas fa-info-circle"></i> More certificates = more knowledgeable employee</p>
        <div class="cert-list-report">
          ${certs.map(c => `
            <div class="cert-report-item">
              <i class="fas fa-award" style="color: #e9b949;"></i>
              <div>
                <strong>${c.certificate_name}</strong>
                <small>${c.issuing_organization || ''} ${c.date_issued ? '• Issued: ' + new Date(c.date_issued).toLocaleDateString('en-US', {year:'numeric', month:'short'}) : ''}</small>
              </div>
            </div>
          `).join('')}
        </div>
      </div>
    `;
  }
  
  if(hr1Eval || hr2Assess) {
    const hr1Score = hr1Eval ? parseFloat(hr1Eval.overall_score) : 0;
    const hr2Score = hr2Assess ? parseFloat(hr2Assess.overall_score) : 0;
    const combinedScore = combined.combined;
    const displayScore = combinedScore > 0 ? combinedScore : hr1Score;
    
    // Combined rating
    let combinedRating = 'Not Rated';
    if (displayScore >= 90) combinedRating = 'Outstanding';
    else if (displayScore >= 80) combinedRating = 'Excellent';
    else if (displayScore >= 70) combinedRating = 'Very Good';
    else if (displayScore >= 60) combinedRating = 'Good';
    else if (displayScore >= 50) combinedRating = 'Fair';
    else if (displayScore > 0) combinedRating = 'Needs Improvement';
    
    let softSkillsHTML = '';
    let hardSkillsHTML = '';

    if(emp.training_needs.soft_skills.length > 0) {
      softSkillsHTML = `
        <div class="training-recommendation soft">
          <h4><i class="fas fa-user-friends"></i> Soft Skills Training Required</h4>
          <ul class="training-list soft-skills">
            ${emp.training_needs.soft_skills.map(skill => `
              <li><i class="fas fa-arrow-right"></i> ${skill}</li>
            `).join('')}
          </ul>
        </div>
      `;
    }

    if(emp.training_needs.hard_skills.length > 0) {
      hardSkillsHTML = `
        <div class="training-recommendation hard">
          <h4><i class="fas fa-tools"></i> Hard Skills Training Required</h4>
          <ul class="training-list hard-skills">
            ${emp.training_needs.hard_skills.map(skill => `
              <li><i class="fas fa-arrow-right"></i> ${skill}</li>
            `).join('')}
          </ul>
        </div>
      `;
    }

    let noTrainingHTML = '';
    if(emp.training_needs.soft_skills.length === 0 && emp.training_needs.hard_skills.length === 0) {
      noTrainingHTML = `
        <div class="success-state">
          <i class="fas fa-trophy"></i>
          <h4>Excellent Performance!</h4>
          <p>No additional training required at this time.</p>
        </div>
      `;
    }

    // Combined Evaluation Report
    reportContent.innerHTML = `
      <div class="employee-summary">
        <div class="summary-item">
          <label><i class="fas fa-id-badge"></i> HR1 Employee ID</label>
          <span>#${emp.id}</span>
        </div>
        <div class="summary-item">
          <label><i class="fas fa-barcode"></i> Employee Code</label>
          <span>${emp.employee_code || 'N/A'}</span>
        </div>
        <div class="summary-item">
          <label><i class="fas fa-briefcase"></i> Position</label>
          <span>${emp.job_position || 'Not Assigned'}</span>
        </div>
        <div class="summary-item">
          <label><i class="fas fa-building"></i> Department</label>
          <span>${emp.department || 'Operations'}</span>
        </div>
        <div class="summary-item">
          <label><i class="fas fa-user-tag"></i> Employee Status</label>
          ${tenureBadgeHTML}
        </div>
        ${tenure.date_hired ? `
        <div class="summary-item">
          <label><i class="fas fa-calendar-plus"></i> Date Hired</label>
          <span>${new Date(tenure.date_hired).toLocaleDateString('en-US', {year:'numeric', month:'long', day:'numeric'})}</span>
        </div>` : ''}
        <div class="summary-item">
          <label><i class="fas fa-certificate"></i> Certificates</label>
          <span style="color: ${certs.length > 0 ? '#10b981' : '#94a3b8'}; font-weight: 600;">
            ${certs.length} certificate${certs.length !== 1 ? 's' : ''}
            ${certs.length >= 3 ? ' <i class="fas fa-star" style="color:#e9b949"></i> Highly Skilled' : ''}
          </span>
        </div>
      </div>
      
      <!-- COMBINED SCORE DISPLAY -->
      <div class="combined-score-report">
        <h4 style="text-align:center; margin-bottom: 15px; color: #1e293b;">
          <i class="fas fa-chart-line"></i> Combined Performance Score
        </h4>
        
        <div class="score-main-display" style="text-align: center; padding: 1.5rem; background: linear-gradient(135deg, #dc3545 0%, #2563eb 100%); color: white; border-radius: 12px; margin-bottom: 20px;">
          <div style="font-size: 3rem; font-weight: bold; margin: 5px 0;">
            ${displayScore.toFixed(1)}%
          </div>
          <div style="font-size: 1.1rem; opacity: 0.9;">
            <i class="fas fa-award"></i> ${combinedRating}
          </div>
        </div>
        
        <!-- Score Breakdown Table -->
        <div class="score-breakdown-table">
          <div class="breakdown-row hr1-row">
            <div class="breakdown-label">
              <i class="fas fa-building" style="color:#8b5cf6"></i>
              <span>HR1 Evaluation Score</span>
              <span class="weight-label">(30% Weight)</span>
            </div>
            <div class="breakdown-bar">
              <div class="breakdown-fill" style="width:${hr1Score}%; background: linear-gradient(90deg, #8b5cf6, #a78bfa)"></div>
            </div>
            <div class="breakdown-values">
              <span class="raw-score">${hr1Score.toFixed(1)}%</span>
              <span class="weighted-score">→ ${combined.hr1_weighted.toFixed(1)}%</span>
            </div>
          </div>
          <div class="breakdown-row hr2-row">
            <div class="breakdown-label">
              <i class="fas fa-clipboard-check" style="color:#3b82f6"></i>
              <span>HR2 Assessment Score</span>
              <span class="weight-label">(70% Weight)</span>
            </div>
            <div class="breakdown-bar">
              <div class="breakdown-fill" style="width:${hr2Score}%; background: linear-gradient(90deg, #3b82f6, #60a5fa)"></div>
            </div>
            <div class="breakdown-values">
              <span class="raw-score">${hr2Score > 0 ? hr2Score.toFixed(1) + '%' : 'Pending'}</span>
              <span class="weighted-score">→ ${combined.hr2_weighted.toFixed(1)}%</span>
            </div>
          </div>
          <div class="breakdown-row total-row">
            <div class="breakdown-label">
              <i class="fas fa-equals" style="color:#10b981"></i>
              <strong>Combined Total</strong>
            </div>
            <div></div>
            <div class="breakdown-values">
              <strong class="total-score">${displayScore.toFixed(1)}%</strong>
            </div>
          </div>
        </div>
        
        ${!hr2Assess ? `
          <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 8px; margin-top: 15px;">
            <strong><i class="fas fa-exclamation-triangle" style="color:#f59e0b"></i> HR2 Assessment Pending</strong><br>
            <small>This employee has not yet been assessed in HR2. The score shown is only from HR1 (30% weight). 
            Complete the HR2 assessment to get the full combined score.</small>
          </div>
        ` : ''}
      </div>

      ${certsHTML}

      ${hr1Eval && hr1Eval.narrative ? `
        <div class="comments-section">
          <h4><i class="fas fa-file-alt"></i> HR1 Performance Narrative</h4>
          <div class="comments-box" style="background: #f8f9fa; padding: 15px; border-radius: 8px; line-height: 1.6;">
            <i class="fas fa-quote-left" style="color: #3498db;"></i>
            <p style="margin: 10px 0; white-space: pre-wrap;">${hr1Eval.narrative}</p>
            <i class="fas fa-quote-right" style="color: #3498db; float: right;"></i>
          </div>
        </div>
      ` : ''}

      ${hr2Assess && hr2Assess.strengths ? `
        <div class="comments-section">
          <h4><i class="fas fa-star"></i> HR2 Key Strengths</h4>
          <div class="comments-box" style="background: #d1fae5; padding: 15px; border-radius: 8px; line-height: 1.6;">
            <p style="margin: 0; white-space: pre-wrap;">${hr2Assess.strengths}</p>
          </div>
        </div>
      ` : ''}

      ${hr2Assess && hr2Assess.areas_for_improvement ? `
        <div class="comments-section">
          <h4><i class="fas fa-arrow-up"></i> HR2 Areas for Improvement</h4>
          <div class="comments-box" style="background: #fef3c7; padding: 15px; border-radius: 8px; line-height: 1.6; border-left: 4px solid #f59e0b;">
            <p style="margin: 0; white-space: pre-wrap;">${hr2Assess.areas_for_improvement}</p>
          </div>
        </div>
      ` : ''}

      ${hr1Eval && hr1Eval.notes ? `
        <div class="comments-section">
          <h4><i class="fas fa-sticky-note"></i> Additional Notes</h4>
          <div class="comments-box" style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107;">
            <span style="white-space: pre-wrap;">${hr1Eval.notes}</span>
          </div>
        </div>
      ` : ''}

      ${noTrainingHTML}
      ${softSkillsHTML}
      ${hardSkillsHTML}
      
      <div style="background: linear-gradient(135deg, #e8f4fd, #dbeafe); padding: 15px; border-radius: 8px; margin-top: 20px; border-left: 4px solid #10b981;">
        <strong><i class="fas fa-bolt" style="color: #10b981;"></i> Data Sources:</strong><br>
        <small style="color: #555;">
          HR1 Employee ID: #${hr1Eval ? hr1Eval.employee_id : emp.id} | 
          Employee Code: ${emp.employee_code || 'N/A'} |
          HR1 Score: ${hr1Score.toFixed(1)}% (30%) |
          HR2 Score: ${hr2Score > 0 ? hr2Score.toFixed(1) + '%' : 'Pending'} (70%) |
          Combined: ${displayScore.toFixed(1)}%
        </small>
      </div>
    `;
  } else {
    reportContent.innerHTML = `
      <div class="no-evaluation" style="padding: 3rem; text-align: center;">
        <i class="fas fa-clipboard-list" style="font-size: 4rem; margin-bottom: 1rem; opacity: 0.4;"></i>
        <h4 style="margin: 0 0 0.5rem 0; color: #64748b;">No Evaluation Data Available</h4>
        <p style="margin: 0 0 1rem 0; color: #94a3b8;">This employee has not been evaluated in HR1 or assessed in HR2 yet.</p>
        <p style="margin: 0; font-size: 0.85rem; color: #64748b;">
          <i class="fas fa-info-circle"></i> HR1 evaluation provides 30% of score • HR2 assessment provides 70%
        </p>
        ${certsHTML}
      </div>
    `;
  }
  
  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
});

// Close modal handlers
closeBtn.onclick = closeModal;
window.onclick = (event) => {
  if (event.target == modal) closeModal();
};

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && modal.style.display === 'flex') {
    closeModal();
  }
});

function closeModal() {
  modal.style.display = 'none';
  document.body.style.overflow = '';
}

// ===== PDF DOWNLOAD =====
document.getElementById('downloadPdfBtn').addEventListener('click', function() {
  if(!selectedEmployeeId) {
    showToast('No employee selected!', 'error');
    return;
  }
  showToast('Preparing PDF download...', 'info');
  window.open('download_evaluation_pdf.php?id=' + selectedEmployeeId, '_blank');
});

// ===== ANIMATION ON LOAD =====
document.addEventListener('DOMContentLoaded', function() {
  // Animate stat cards
  document.querySelectorAll('.stat-card').forEach((card, i) => {
    card.style.opacity = '0';
    card.style.transform = 'translateY(20px)';
    setTimeout(() => {
      card.style.transition = 'all 0.5s cubic-bezier(0.4, 0, 0.2, 1)';
      card.style.opacity = '1';
      card.style.transform = 'translateY(0)';
    }, 100 + (i * 100));
  });
  
  // Animate employee cards with IntersectionObserver
  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry, index) => {
      if(entry.isIntersecting) {
        setTimeout(() => {
          entry.target.classList.add('animate-in');
        }, index * 50);
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.1 });
  
  document.querySelectorAll('.employee-card').forEach(card => {
    observer.observe(card);
  });
  
  // Counter animation
  document.querySelectorAll('.counter').forEach(counter => {
    const target = parseInt(counter.getAttribute('data-target'));
    const duration = 1000;
    const start = 0;
    const startTime = performance.now();
    
    function updateCounter(currentTime) {
      const elapsed = currentTime - startTime;
      const progress = Math.min(elapsed / duration, 1);
      const easeOut = 1 - Math.pow(1 - progress, 3);
      counter.textContent = Math.round(start + (target - start) * easeOut);
      
      if(progress < 1) {
        requestAnimationFrame(updateCounter);
      }
    }
    
    requestAnimationFrame(updateCounter);
  });
});
</script>
</body>
</html>
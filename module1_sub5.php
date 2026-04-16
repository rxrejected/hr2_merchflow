<?php
/**
 * Module 1 Sub 5: Employee Evaluation Pipeline
 * 
 * FLOW: HR1 Evaluation → Course Assignment → Course Learning → Course Assessment → HR2 Final Evaluation
 * Shows complete journey of each employee through the HR2 system
 * Indicates New vs Regular (Old) employees
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';
require_once 'Connection/hr1_db.php';

// Admin role guard
$userRole = strtolower(str_replace(' ', '', $_SESSION['role'] ?? ''));
if (!in_array($userRole, ['admin', 'manager', 'superadmin'])) {
    header('Location: employee.php');
    exit();
}

// ============================
// Fetch Pipeline Data
// ============================
$pipelineData = [];
$pResult = $conn->query("SELECT * FROM employee_pipeline WHERE status IN ('active','completed') ORDER BY 
    CASE current_stage 
        WHEN 'hr1_evaluated' THEN 1 
        WHEN 'courses_assigned' THEN 2 
        WHEN 'learning' THEN 3 
        WHEN 'assessment' THEN 4 
        WHEN 'hr2_evaluated' THEN 5 
        WHEN 'completed' THEN 6 
    END ASC, updated_at DESC");
if ($pResult) {
    while ($row = $pResult->fetch_assoc()) {
        $pipelineData[] = $row;
    }
}

// Fetch course assignments per pipeline
$courseAssignments = [];
$caResult = $conn->query("
    SELECT ca.*, c.title as course_title, c.skill_type, c.training_type
    FROM course_assignments ca 
    LEFT JOIN courses c ON ca.course_id = c.course_id
    ORDER BY ca.pipeline_id, ca.created_at ASC
");
if ($caResult) {
    while ($row = $caResult->fetch_assoc()) {
        $courseAssignments[$row['pipeline_id']][] = $row;
    }
}

// Fetch quiz results per pipeline
$quizResults = [];
$qrResult = $conn->query("
    SELECT qr.*, c.title as course_title
    FROM course_quiz_results qr
    LEFT JOIN courses c ON qr.course_id = c.course_id
    ORDER BY qr.pipeline_id, qr.created_at DESC
");
if ($qrResult) {
    while ($row = $qrResult->fetch_assoc()) {
        $quizResults[$row['pipeline_id']][] = $row;
    }
}

// Get available courses for assignment modal
$availableCourses = [];
$cResult = $conn->query("SELECT course_id, title, description, skill_type, training_type FROM courses ORDER BY title ASC");
if ($cResult) {
    while ($row = $cResult->fetch_assoc()) {
        $availableCourses[] = $row;
    }
}

// Fetch HR1 evaluated employees (for import)
$hr1db = new HR1Database();
$hr1Response = $hr1db->getEvaluations('completed', 200);
$hr1Evaluations = $hr1Response['success'] ? $hr1Response['data'] : [];
$hr1EmpResponse = $hr1db->getEmployees('', 500, 0);
$hr1EmployeesList = $hr1EmpResponse['success'] ? $hr1EmpResponse['data'] : [];
$hr1db->close();

// Map HR1 employees for lookup
$hr1EmpMap = [];
foreach ($hr1EmployeesList as $e) {
    $hr1EmpMap[$e['id']] = $e;
}

// Get unique evaluated employee IDs
$evaluatedEmployees = [];
foreach ($hr1Evaluations as $eval) {
    $empId = $eval['employee_id'];
    if (!isset($evaluatedEmployees[$empId])) {
        $evaluatedEmployees[$empId] = [
            'id' => $empId,
            'name' => $eval['employee_name'],
            'email' => $eval['employee_email'],
            'department' => $eval['department'] ?? '',
            'role' => $eval['role'] ?? '',
            'overall_score' => $eval['overall_score'],
            'rating_label' => $eval['rating_label'] ?? '',
            'due_date' => $eval['due_date'] ?? ''
        ];
    }
}

// Get already imported employee IDs
$importedIds = [];
foreach ($pipelineData as $p) {
    $importedIds[$p['hr1_employee_id']] = true;
}

// Stats
$stats = [
    'total' => count($pipelineData),
    'new_employees' => 0,
    'regular_employees' => 0,
    'hr1_evaluated' => 0,
    'courses_assigned' => 0,
    'learning' => 0,
    'assessment' => 0,
    'hr2_evaluated' => 0,
    'completed' => 0,
    'avg_combined' => 0,
    'avg_completion' => 0,
];

$totalCombined = 0;
$countCombined = 0;
$totalCompletion = 0;

foreach ($pipelineData as $p) {
    $stats[$p['current_stage']]++;
    if ($p['employee_type'] === 'new') $stats['new_employees']++;
    else $stats['regular_employees']++;
    
    if ($p['combined_score'] > 0) {
        $totalCombined += (float)$p['combined_score'];
        $countCombined++;
    }
    $totalCompletion += (float)$p['course_completion_pct'];
}

$stats['avg_combined'] = $countCombined > 0 ? round($totalCombined / $countCombined, 1) : 0;
$stats['avg_completion'] = $stats['total'] > 0 ? round($totalCompletion / $stats['total'], 1) : 0;

// Helper functions
function getStageInfo($stage) {
    $stages = [
        'hr1_evaluated'    => ['label' => 'HR1 Evaluated',     'icon' => 'fa-clipboard-check', 'color' => '#6366f1', 'step' => 1],
        'courses_assigned' => ['label' => 'Courses Assigned',  'icon' => 'fa-book-open',       'color' => '#8b5cf6', 'step' => 2],
        'learning'         => ['label' => 'Learning',          'icon' => 'fa-graduation-cap',  'color' => '#a855f7', 'step' => 3],
        'assessment'       => ['label' => 'Assessment',        'icon' => 'fa-tasks',           'color' => '#ec4899', 'step' => 4],
        'hr2_evaluated'    => ['label' => 'HR2 Evaluated',     'icon' => 'fa-chart-line',      'color' => '#f59e0b', 'step' => 5],
        'completed'        => ['label' => 'Completed',         'icon' => 'fa-check-circle',    'color' => '#10b981', 'step' => 6],
    ];
    return $stages[$stage] ?? $stages['hr1_evaluated'];
}

function getScoreColor($score) {
    if ($score >= 90) return '#10b981';
    if ($score >= 80) return '#22d3ee';
    if ($score >= 70) return '#6366f1';
    if ($score >= 60) return '#f59e0b';
    if ($score >= 50) return '#f97316';
    return '#ef4444';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0" />
  <meta name="description" content="Employee Evaluation Pipeline - HR2 MerchFlow" />
  <meta name="theme-color" content="#6366f1" />
  <title>Employee Evaluation Pipeline | HR2 MerchFlow</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <link rel="icon" type="image/png" href="osicon.png" />
  <link rel="stylesheet" href="Css/module1_sub5.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'partials/sidebar.php'; ?>

  <div class="main-content">
    <?php include 'partials/nav.php'; ?>

    <div class="container">
      
      <!-- Page Header -->
      <div class="page-header">
        <div class="header-content">
          <h2><i class="fas fa-project-diagram"></i> Employee Evaluation Pipeline</h2>
          <p class="page-subtitle">
            <i class="fas fa-stream"></i> 
            HR1 Evaluation → Courses → Assessment → HR2 Evaluation
            <span class="last-update">
              <i class="fas fa-clock"></i> <?php echo date('M d, Y h:i A'); ?>
            </span>
          </p>
        </div>
        <div class="header-actions">
          <button class="action-btn btn-import" id="importBtn" title="Import HR1 Evaluated Employees">
            <i class="fas fa-file-import"></i>
            <span>Import from HR1</span>
          </button>
          <button class="action-btn btn-refresh" id="refreshBtn" title="Refresh Pipeline Data">
            <i class="fas fa-sync-alt"></i>
            <span>Refresh</span>
          </button>
        </div>
      </div>

      <!-- Pipeline Flow Visual -->
      <div class="pipeline-flow-container">
        <div class="pipeline-flow">
          <?php 
          $flowStages = [
              ['key' => 'hr1_evaluated', 'label' => 'HR1 Evaluated', 'icon' => 'fa-clipboard-check', 'desc' => 'Evaluated in HR1'],
              ['key' => 'courses_assigned', 'label' => 'Courses Assigned', 'icon' => 'fa-book-open', 'desc' => 'Courses assigned'],
              ['key' => 'learning', 'label' => 'Learning', 'icon' => 'fa-graduation-cap', 'desc' => 'Taking courses'],
              ['key' => 'assessment', 'label' => 'Assessment', 'icon' => 'fa-tasks', 'desc' => 'Quiz & MCQ'],
              ['key' => 'hr2_evaluated', 'label' => 'HR2 Evaluated', 'icon' => 'fa-chart-line', 'desc' => 'Score calculated'],
              ['key' => 'completed', 'label' => 'Completed', 'icon' => 'fa-check-circle', 'desc' => 'Pipeline done'],
          ];
          foreach ($flowStages as $idx => $fs): 
              $count = $stats[$fs['key']] ?? 0;
          ?>
          <div class="flow-stage <?php echo $count > 0 ? 'has-employees' : ''; ?>" data-stage="<?php echo $fs['key']; ?>">
            <div class="flow-step">
              <div class="flow-icon">
                <i class="fas <?php echo $fs['icon']; ?>"></i>
              </div>
              <div class="flow-count"><?php echo $count; ?></div>
            </div>
            <div class="flow-label"><?php echo $fs['label']; ?></div>
            <div class="flow-desc"><?php echo $fs['desc']; ?></div>
            <?php if ($idx < count($flowStages) - 1): ?>
            <div class="flow-arrow">
              <i class="fas fa-chevron-right"></i>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Stats Cards -->
      <div class="stats-banner">
        <div class="stat-card stat-total">
          <div class="stat-icon"><i class="fas fa-users"></i></div>
          <div class="stat-content">
            <h3><?php echo $stats['total']; ?></h3>
            <p>Total in Pipeline</p>
          </div>
        </div>
        <div class="stat-card stat-new">
          <div class="stat-icon"><i class="fas fa-user-plus"></i></div>
          <div class="stat-content">
            <h3><?php echo $stats['new_employees']; ?></h3>
            <p>New Employees</p>
          </div>
          <span class="stat-badge badge-new">< 6 months</span>
        </div>
        <div class="stat-card stat-regular">
          <div class="stat-icon"><i class="fas fa-user-check"></i></div>
          <div class="stat-content">
            <h3><?php echo $stats['regular_employees']; ?></h3>
            <p>Regular Employees</p>
          </div>
          <span class="stat-badge badge-regular">≥ 6 months</span>
        </div>
        <div class="stat-card stat-score">
          <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
          <div class="stat-content">
            <h3><?php echo $stats['avg_combined']; ?>%</h3>
            <p>Avg Combined Score</p>
          </div>
          <span class="stat-badge badge-formula">30% HR1 + 70% HR2</span>
        </div>
        <div class="stat-card stat-completion">
          <div class="stat-icon"><i class="fas fa-tasks"></i></div>
          <div class="stat-content">
            <h3><?php echo $stats['avg_completion']; ?>%</h3>
            <p>Avg Course Completion</p>
          </div>
        </div>
        <div class="stat-card stat-done">
          <div class="stat-icon"><i class="fas fa-flag-checkered"></i></div>
          <div class="stat-content">
            <h3><?php echo $stats['completed']; ?></h3>
            <p>Pipeline Completed</p>
          </div>
        </div>
      </div>

      <!-- Filters & Search -->
      <div class="search-sort">
        <div class="search-wrapper">
          <i class="fas fa-search search-icon"></i>
          <input type="text" id="searchInput" placeholder="Search employee name, department..." autocomplete="off" />
          <button class="clear-search" id="clearSearch" title="Clear search">
            <i class="fas fa-times"></i>
          </button>
        </div>
        <div class="filter-wrapper">
          <select id="stageFilter" title="Filter by stage">
            <option value="all">All Stages</option>
            <option value="hr1_evaluated">HR1 Evaluated</option>
            <option value="courses_assigned">Courses Assigned</option>
            <option value="learning">Learning</option>
            <option value="assessment">Assessment</option>
            <option value="hr2_evaluated">HR2 Evaluated</option>
            <option value="completed">Completed</option>
          </select>
          <select id="typeFilter" title="Filter by type">
            <option value="all">All Types</option>
            <option value="new">🆕 New Employees</option>
            <option value="regular">👤 Regular Employees</option>
          </select>
          <select id="sortSelect" title="Sort">
            <option value="stage">Sort by Stage</option>
            <option value="name-asc">Name (A-Z)</option>
            <option value="name-desc">Name (Z-A)</option>
            <option value="score-high">Score (High-Low)</option>
            <option value="score-low">Score (Low-High)</option>
            <option value="recent">Recently Updated</option>
          </select>
        </div>
      </div>

      <!-- Results Info -->
      <div class="results-info">
        <span id="resultsCount">
          <i class="fas fa-stream" style="color: #6366f1; margin-right: 5px;"></i>
          Showing <?php echo count($pipelineData); ?> employees in pipeline
        </span>
      </div>

      <!-- Employee Pipeline Table -->
      <div class="pipeline-table-container">
        <table class="pipeline-table" id="pipelineTable">
          <thead>
            <tr>
              <th class="th-employee">Employee</th>
              <th class="th-type">Type</th>
              <th class="th-stage">Pipeline Stage</th>
              <th class="th-hr1">HR1 Score</th>
              <th class="th-courses">Courses</th>
              <th class="th-quiz">Assessment</th>
              <th class="th-hr2">HR2 Score</th>
              <th class="th-combined">Combined</th>
              <th class="th-actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($pipelineData) > 0): ?>
              <?php foreach ($pipelineData as $emp): 
                $stageInfo = getStageInfo($emp['current_stage']);
                $courses = $courseAssignments[$emp['id']] ?? [];
                $quizzes = $quizResults[$emp['id']] ?? [];
                $hr1ScoreColor = getScoreColor((float)$emp['hr1_eval_score']);
                $hr2ScoreColor = getScoreColor((float)$emp['hr2_eval_score']);
                $combinedColor = getScoreColor((float)$emp['combined_score']);
              ?>
              <tr class="pipeline-row" 
                  data-id="<?php echo $emp['id']; ?>" 
                  data-name="<?php echo strtolower($emp['hr1_employee_name']); ?>"
                  data-dept="<?php echo strtolower($emp['department']); ?>"
                  data-stage="<?php echo $emp['current_stage']; ?>"
                  data-type="<?php echo $emp['employee_type']; ?>"
                  data-score="<?php echo $emp['combined_score'] ?? $emp['hr1_eval_score']; ?>">
                
                <!-- Employee Info -->
                <td class="td-employee">
                  <div class="emp-info">
                    <div class="emp-avatar">
                      <span class="avatar-initials"><?php echo strtoupper(substr($emp['hr1_employee_name'], 0, 1) . substr(strrchr($emp['hr1_employee_name'], ' ') ?: $emp['hr1_employee_name'], 1, 1)); ?></span>
                    </div>
                    <div class="emp-details">
                      <strong class="emp-name"><?php echo htmlspecialchars($emp['hr1_employee_name']); ?></strong>
                      <small class="emp-dept">
                        <i class="fas fa-building"></i> <?php echo htmlspecialchars($emp['department']); ?>
                      </small>
                      <small class="emp-position"><?php echo htmlspecialchars($emp['position']); ?></small>
                    </div>
                  </div>
                </td>
                
                <!-- Employee Type (New/Regular) -->
                <td class="td-type">
                  <?php if ($emp['employee_type'] === 'new'): ?>
                    <span class="type-badge type-new">
                      <i class="fas fa-sparkles"></i> New
                    </span>
                    <small class="tenure-info"><?php echo $emp['months_tenure']; ?> mo</small>
                  <?php else: ?>
                    <span class="type-badge type-regular">
                      <i class="fas fa-user-check"></i> Regular
                    </span>
                    <small class="tenure-info">
                      <?php 
                      $m = (int)$emp['months_tenure'];
                      echo $m >= 12 ? floor($m/12) . 'y ' . ($m%12) . 'm' : $m . ' mo'; 
                      ?>
                    </small>
                  <?php endif; ?>
                </td>
                
                <!-- Pipeline Stage -->
                <td class="td-stage">
                  <div class="stage-indicator">
                    <div class="stage-progress">
                      <?php for ($s = 1; $s <= 6; $s++): ?>
                        <div class="stage-dot <?php echo $s <= $stageInfo['step'] ? 'active' : ''; ?> <?php echo $s === $stageInfo['step'] ? 'current' : ''; ?>"></div>
                        <?php if ($s < 6): ?>
                          <div class="stage-line <?php echo $s < $stageInfo['step'] ? 'active' : ''; ?>"></div>
                        <?php endif; ?>
                      <?php endfor; ?>
                    </div>
                    <span class="stage-label" style="color: <?php echo $stageInfo['color']; ?>">
                      <i class="fas <?php echo $stageInfo['icon']; ?>"></i> <?php echo $stageInfo['label']; ?>
                    </span>
                  </div>
                </td>
                
                <!-- HR1 Score -->
                <td class="td-score">
                  <?php if ($emp['hr1_eval_score'] > 0): ?>
                    <div class="score-cell">
                      <div class="score-ring" style="--score-color: <?php echo $hr1ScoreColor; ?>; --score-pct: <?php echo $emp['hr1_eval_score']; ?>%;">
                        <span><?php echo number_format($emp['hr1_eval_score'], 1); ?></span>
                      </div>
                      <small class="score-weight">30% weight</small>
                    </div>
                  <?php else: ?>
                    <span class="no-data">—</span>
                  <?php endif; ?>
                </td>
                
                <!-- Course Progress -->
                <td class="td-courses">
                  <?php if (count($courses) > 0): ?>
                    <div class="course-progress-cell">
                      <div class="progress-bar-mini">
                        <div class="progress-fill" style="width: <?php echo $emp['course_completion_pct']; ?>%;"></div>
                      </div>
                      <small><?php echo $emp['courses_completed']; ?>/<?php echo $emp['courses_assigned']; ?> courses</small>
                      <small class="pct-label"><?php echo number_format($emp['course_completion_pct'], 0); ?>%</small>
                    </div>
                  <?php else: ?>
                    <span class="no-data">Not assigned</span>
                  <?php endif; ?>
                </td>
                
                <!-- Assessment/Quiz -->
                <td class="td-quiz">
                  <?php if ($emp['assessments_taken'] > 0): ?>
                    <div class="quiz-cell">
                      <div class="quiz-score" style="color: <?php echo getScoreColor((float)$emp['avg_assessment_score']); ?>;">
                        <?php echo number_format($emp['avg_assessment_score'], 1); ?>%
                      </div>
                      <small><?php echo $emp['assessments_passed']; ?>/<?php echo $emp['assessments_taken']; ?> passed</small>
                    </div>
                  <?php else: ?>
                    <span class="no-data">Not taken</span>
                  <?php endif; ?>
                </td>
                
                <!-- HR2 Score -->
                <td class="td-score">
                  <?php if ($emp['hr2_eval_score'] > 0): ?>
                    <div class="score-cell">
                      <div class="score-ring" style="--score-color: <?php echo $hr2ScoreColor; ?>; --score-pct: <?php echo $emp['hr2_eval_score']; ?>%;">
                        <span><?php echo number_format($emp['hr2_eval_score'], 1); ?></span>
                      </div>
                      <small class="score-weight">70% weight</small>
                    </div>
                  <?php else: ?>
                    <span class="no-data">Pending</span>
                  <?php endif; ?>
                </td>
                
                <!-- Combined Score -->
                <td class="td-combined">
                  <?php if ($emp['combined_score'] > 0): ?>
                    <div class="combined-cell">
                      <div class="combined-score-value" style="color: <?php echo $combinedColor; ?>;">
                        <?php echo number_format($emp['combined_score'], 1); ?>%
                      </div>
                      <span class="combined-rating rating-<?php echo strtolower(str_replace(' ', '-', $emp['combined_rating'])); ?>">
                        <?php echo $emp['combined_rating']; ?>
                      </span>
                    </div>
                  <?php else: ?>
                    <span class="no-data">—</span>
                  <?php endif; ?>
                </td>
                
                <!-- Actions -->
                <td class="td-actions">
                  <div class="action-buttons">
                    <button class="btn-action btn-view" onclick="viewDetails(<?php echo $emp['id']; ?>)" title="View Details">
                      <i class="fas fa-eye"></i>
                    </button>
                    <?php if (in_array($emp['current_stage'], ['hr1_evaluated', 'courses_assigned'])): ?>
                    <button class="btn-action btn-assign" onclick="openAssignCourses(<?php echo $emp['id']; ?>, '<?php echo addslashes($emp['hr1_employee_name']); ?>')" title="Assign Courses">
                      <i class="fas fa-book-open"></i>
                    </button>
                    <?php endif; ?>
                    <?php if (in_array($emp['current_stage'], ['assessment', 'hr2_evaluated', 'learning']) && $emp['avg_assessment_score'] > 0): ?>
                    <button class="btn-action btn-evaluate" onclick="finalEvaluate(<?php echo $emp['id']; ?>, '<?php echo addslashes($emp['hr1_employee_name']); ?>')" title="Complete Evaluation">
                      <i class="fas fa-check-double"></i>
                    </button>
                    <?php endif; ?>
                    <button class="btn-action btn-stage" onclick="changeStage(<?php echo $emp['id']; ?>, '<?php echo $emp['current_stage']; ?>', '<?php echo addslashes($emp['hr1_employee_name']); ?>')" title="Change Stage">
                      <i class="fas fa-exchange-alt"></i>
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="9" class="empty-state-td">
                  <div class="empty-state">
                    <i class="fas fa-stream"></i>
                    <h3>No Employees in Pipeline</h3>
                    <p>Import evaluated employees from HR1 to start the evaluation pipeline.</p>
                    <button class="action-btn btn-import" onclick="document.getElementById('importBtn').click()">
                      <i class="fas fa-file-import"></i> Import from HR1
                    </button>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- No Results -->
      <div class="no-results" id="noResults" style="display: none;">
        <i class="fas fa-search"></i>
        <h3>No Results Found</h3>
        <p>Try adjusting your search or filter criteria</p>
        <button class="reset-btn" id="resetFilters">Reset Filters</button>
      </div>

    </div>
  </div>

  <!-- ==================== MODALS ==================== -->

  <!-- Import from HR1 Modal -->
  <div id="importModal" class="modal" role="dialog" aria-modal="true">
    <div class="modal-content modal-lg">
      <div class="modal-header">
        <h3><i class="fas fa-file-import"></i> Import HR1 Evaluated Employees</h3>
        <button class="modal-close" onclick="closeModal('importModal')">&times;</button>
      </div>
      <div class="modal-body">
        <p class="modal-info">
          <i class="fas fa-info-circle"></i> 
          Select employees who have been evaluated in HR1 to import into the HR2 evaluation pipeline.
        </p>
        <div class="import-search">
          <input type="text" id="importSearch" placeholder="Search employees..." />
        </div>
        <div class="import-list" id="importList">
          <?php if (count($evaluatedEmployees) > 0): ?>
            <?php foreach ($evaluatedEmployees as $empId => $emp): 
              $alreadyImported = isset($importedIds[$empId]);
              $hr1Emp = $hr1EmpMap[$empId] ?? null;
              $dateHired = $hr1Emp['date_hired'] ?? $hr1Emp['start_date'] ?? '';
              $monthsTenure = 0;
              $empType = 'new';
              if ($dateHired) {
                  $hired = new DateTime($dateHired);
                  $now = new DateTime();
                  $monthsTenure = ($now->diff($hired)->y * 12) + $now->diff($hired)->m;
                  $empType = $monthsTenure >= 6 ? 'regular' : 'new';
              }
            ?>
            <div class="import-item <?php echo $alreadyImported ? 'already-imported' : ''; ?>" 
                 data-name="<?php echo strtolower($emp['name']); ?>"
                 data-id="<?php echo $empId; ?>">
              <div class="import-check">
                <?php if (!$alreadyImported): ?>
                  <input type="checkbox" class="import-cb" 
                         data-id="<?php echo $empId; ?>"
                         data-name="<?php echo htmlspecialchars($emp['name']); ?>"
                         data-email="<?php echo htmlspecialchars($emp['email']); ?>"
                         data-dept="<?php echo htmlspecialchars($emp['department']); ?>"
                         data-position="<?php echo htmlspecialchars($emp['role']); ?>"
                         data-type="<?php echo $empType; ?>"
                         data-hired="<?php echo $dateHired; ?>"
                         data-months="<?php echo $monthsTenure; ?>"
                         data-score="<?php echo $emp['overall_score']; ?>"
                         data-rating="<?php echo htmlspecialchars($emp['rating_label']); ?>"
                         data-date="<?php echo $emp['due_date']; ?>" />
                <?php else: ?>
                  <i class="fas fa-check-circle" style="color: #10b981;"></i>
                <?php endif; ?>
              </div>
              <div class="import-info">
                <strong><?php echo htmlspecialchars($emp['name']); ?></strong>
                <small><?php echo htmlspecialchars($emp['department']); ?> · <?php echo htmlspecialchars($emp['role']); ?></small>
              </div>
              <div class="import-type">
                <span class="type-badge type-<?php echo $empType; ?> type-sm">
                  <?php echo $empType === 'new' ? '🆕 New' : '👤 Regular'; ?>
                </span>
              </div>
              <div class="import-score">
                <span style="color: <?php echo getScoreColor((float)$emp['overall_score']); ?>;">
                  <?php echo number_format((float)$emp['overall_score'], 1); ?>%
                </span>
                <small><?php echo $emp['rating_label']; ?></small>
              </div>
              <div class="import-status">
                <?php if ($alreadyImported): ?>
                  <span class="badge-imported">Already in Pipeline</span>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="empty-import">
              <i class="fas fa-database"></i>
              <p>No evaluated employees found in HR1.</p>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="modal-footer">
        <span id="importCount">0 selected</span>
        <button class="btn-cancel" onclick="closeModal('importModal')">Cancel</button>
        <button class="btn-primary" id="importSelectedBtn" onclick="importSelected()">
          <i class="fas fa-file-import"></i> Import Selected
        </button>
      </div>
    </div>
  </div>

  <!-- Assign Courses Modal -->
  <div id="assignModal" class="modal" role="dialog" aria-modal="true">
    <div class="modal-content">
      <div class="modal-header">
        <h3><i class="fas fa-book-open"></i> Assign Courses</h3>
        <button class="modal-close" onclick="closeModal('assignModal')">&times;</button>
      </div>
      <div class="modal-body">
        <p class="modal-subtitle" id="assignEmployee">Assigning courses to: <strong></strong></p>
        <input type="hidden" id="assignPipelineId" />
        
        <div class="course-checklist" id="courseChecklist">
          <?php foreach ($availableCourses as $course): ?>
          <label class="course-item">
            <input type="checkbox" name="course_ids[]" value="<?php echo $course['course_id']; ?>" class="course-cb" />
            <div class="course-info">
              <strong><?php echo htmlspecialchars($course['title']); ?></strong>
              <small>
                <span class="skill-badge"><?php echo $course['skill_type']; ?></span>
                <span class="training-badge"><?php echo $course['training_type']; ?></span>
              </small>
              <p><?php echo htmlspecialchars(substr($course['description'], 0, 100)); ?>...</p>
            </div>
          </label>
          <?php endforeach; ?>
          <?php if (empty($availableCourses)): ?>
          <div class="empty-import">
            <i class="fas fa-book"></i>
            <p>No courses available. <a href="module2_sub1.php">Create courses first</a>.</p>
          </div>
          <?php endif; ?>
        </div>
        
        <div class="form-group">
          <label for="dueDate"><i class="fas fa-calendar"></i> Due Date</label>
          <input type="date" id="dueDate" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" />
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn-cancel" onclick="closeModal('assignModal')">Cancel</button>
        <button class="btn-primary" onclick="submitAssignment()">
          <i class="fas fa-check"></i> Assign Courses
        </button>
      </div>
    </div>
  </div>

  <!-- Details Modal -->
  <div id="detailsModal" class="modal" role="dialog" aria-modal="true">
    <div class="modal-content modal-xl">
      <div class="modal-header">
        <h3><i class="fas fa-user-circle"></i> <span id="detailsEmpName">Employee Details</span></h3>
        <button class="modal-close" onclick="closeModal('detailsModal')">&times;</button>
      </div>
      <div class="modal-body" id="detailsBody">
        <div class="loading-spinner">
          <i class="fas fa-spinner fa-spin"></i> Loading details...
        </div>
      </div>
    </div>
  </div>

  <!-- Change Stage Modal -->
  <div id="stageModal" class="modal" role="dialog" aria-modal="true">
    <div class="modal-content modal-sm">
      <div class="modal-header">
        <h3><i class="fas fa-exchange-alt"></i> Change Pipeline Stage</h3>
        <button class="modal-close" onclick="closeModal('stageModal')">&times;</button>
      </div>
      <div class="modal-body">
        <p class="modal-subtitle" id="stageEmployee">Employee: <strong></strong></p>
        <input type="hidden" id="stagePipelineId" />
        
        <div class="stage-options" id="stageOptions">
          <label class="stage-option"><input type="radio" name="new_stage" value="hr1_evaluated" /> <i class="fas fa-clipboard-check"></i> HR1 Evaluated</label>
          <label class="stage-option"><input type="radio" name="new_stage" value="courses_assigned" /> <i class="fas fa-book-open"></i> Courses Assigned</label>
          <label class="stage-option"><input type="radio" name="new_stage" value="learning" /> <i class="fas fa-graduation-cap"></i> Learning</label>
          <label class="stage-option"><input type="radio" name="new_stage" value="assessment" /> <i class="fas fa-tasks"></i> Assessment</label>
          <label class="stage-option"><input type="radio" name="new_stage" value="hr2_evaluated" /> <i class="fas fa-chart-line"></i> HR2 Evaluated</label>
          <label class="stage-option"><input type="radio" name="new_stage" value="completed" /> <i class="fas fa-check-circle"></i> Completed</label>
        </div>
        
        <div class="form-group">
          <label for="stageNotes"><i class="fas fa-sticky-note"></i> Notes (optional)</label>
          <textarea id="stageNotes" rows="3" placeholder="Add notes about this stage change..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn-cancel" onclick="closeModal('stageModal')">Cancel</button>
        <button class="btn-primary" onclick="submitStageChange()">
          <i class="fas fa-save"></i> Update Stage
        </button>
      </div>
    </div>
  </div>

  <!-- Evaluate Modal -->
  <div id="evaluateModal" class="modal" role="dialog" aria-modal="true">
    <div class="modal-content">
      <div class="modal-header">
        <h3><i class="fas fa-check-double"></i> Complete Final Evaluation</h3>
        <button class="modal-close" onclick="closeModal('evaluateModal')">&times;</button>
      </div>
      <div class="modal-body">
        <p class="modal-subtitle" id="evalEmployee">Evaluating: <strong></strong></p>
        <input type="hidden" id="evalPipelineId" />
        
        <div class="eval-preview" id="evalPreview">
          <!-- Filled by JS -->
        </div>
        
        <div class="form-group">
          <label for="evalRecommendation"><i class="fas fa-comment-alt"></i> Final Recommendation</label>
          <textarea id="evalRecommendation" rows="4" placeholder="Enter final recommendation for this employee..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn-cancel" onclick="closeModal('evaluateModal')">Cancel</button>
        <button class="btn-primary btn-success" onclick="submitEvaluation()">
          <i class="fas fa-check-double"></i> Complete Evaluation
        </button>
      </div>
    </div>
  </div>

<script>
// ============================
// Search & Filter
// ============================
const searchInput = document.getElementById('searchInput');
const stageFilter = document.getElementById('stageFilter');
const typeFilter = document.getElementById('typeFilter');
const sortSelect = document.getElementById('sortSelect');
const clearSearch = document.getElementById('clearSearch');
const noResults = document.getElementById('noResults');
const resultsCount = document.getElementById('resultsCount');

function filterTable() {
    const search = searchInput.value.toLowerCase().trim();
    const stage = stageFilter.value;
    const type = typeFilter.value;
    const rows = document.querySelectorAll('.pipeline-row');
    let visibleCount = 0;

    rows.forEach(row => {
        const name = row.dataset.name || '';
        const dept = row.dataset.dept || '';
        const rowStage = row.dataset.stage || '';
        const rowType = row.dataset.type || '';

        const matchSearch = !search || name.includes(search) || dept.includes(search);
        const matchStage = stage === 'all' || rowStage === stage;
        const matchType = type === 'all' || rowType === type;

        if (matchSearch && matchStage && matchType) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    resultsCount.innerHTML = `<i class="fas fa-stream" style="color: #6366f1; margin-right: 5px;"></i> Showing ${visibleCount} of ${rows.length} employees`;
    noResults.style.display = visibleCount === 0 ? 'flex' : 'none';
    document.querySelector('.pipeline-table-container').style.display = visibleCount === 0 ? 'none' : '';
}

function sortTable() {
    const sort = sortSelect.value;
    const tbody = document.querySelector('#pipelineTable tbody');
    const rows = Array.from(tbody.querySelectorAll('.pipeline-row'));

    rows.sort((a, b) => {
        switch(sort) {
            case 'name-asc':
                return (a.dataset.name || '').localeCompare(b.dataset.name || '');
            case 'name-desc':
                return (b.dataset.name || '').localeCompare(a.dataset.name || '');
            case 'score-high':
                return parseFloat(b.dataset.score || 0) - parseFloat(a.dataset.score || 0);
            case 'score-low':
                return parseFloat(a.dataset.score || 0) - parseFloat(b.dataset.score || 0);
            case 'recent':
                return 0; // Keep server order (already ordered by updated_at)
            default: // stage
                return 0;
        }
    });

    rows.forEach(row => tbody.appendChild(row));
}

searchInput.addEventListener('input', filterTable);
stageFilter.addEventListener('change', filterTable);
typeFilter.addEventListener('change', filterTable);
sortSelect.addEventListener('change', () => { sortTable(); filterTable(); });
clearSearch.addEventListener('click', () => { searchInput.value = ''; filterTable(); });
document.getElementById('resetFilters').addEventListener('click', () => {
    searchInput.value = '';
    stageFilter.value = 'all';
    typeFilter.value = 'all';
    sortSelect.value = 'stage';
    filterTable();
});

// Flow stage click to filter
document.querySelectorAll('.flow-stage').forEach(el => {
    el.addEventListener('click', () => {
        stageFilter.value = el.dataset.stage;
        filterTable();
    });
});

// ============================
// Modal Management
// ============================
function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}

// Close modals on escape or background click
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(m => m.classList.remove('active'));
        document.body.style.overflow = '';
    }
});
document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => {
        if (e.target === m) closeModal(m.id);
    });
});

// ============================
// Import from HR1
// ============================
document.getElementById('importBtn').addEventListener('click', () => openModal('importModal'));

// Import search
document.getElementById('importSearch').addEventListener('input', function() {
    const search = this.value.toLowerCase();
    document.querySelectorAll('.import-item').forEach(item => {
        const name = item.dataset.name || '';
        item.style.display = !search || name.includes(search) ? '' : 'none';
    });
});

// Update selected count
document.querySelectorAll('.import-cb').forEach(cb => {
    cb.addEventListener('change', updateImportCount);
});

function updateImportCount() {
    const count = document.querySelectorAll('.import-cb:checked').length;
    document.getElementById('importCount').textContent = count + ' selected';
}

async function importSelected() {
    const checkboxes = document.querySelectorAll('.import-cb:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one employee to import.');
        return;
    }

    const btn = document.getElementById('importSelectedBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';

    let imported = 0;
    let errors = [];

    for (const cb of checkboxes) {
        const formData = new FormData();
        formData.append('action', 'import_employee');
        formData.append('hr1_employee_id', cb.dataset.id);
        formData.append('name', cb.dataset.name);
        formData.append('email', cb.dataset.email);
        formData.append('department', cb.dataset.dept);
        formData.append('position', cb.dataset.position);
        formData.append('employee_type', cb.dataset.type);
        formData.append('date_hired', cb.dataset.hired);
        formData.append('months_tenure', cb.dataset.months);
        formData.append('hr1_eval_score', cb.dataset.score);
        formData.append('hr1_eval_rating', cb.dataset.rating);
        formData.append('hr1_eval_date', cb.dataset.date);

        try {
            const res = await fetch('api_employee_pipeline.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                imported++;
            } else {
                errors.push(cb.dataset.name + ': ' + data.error);
            }
        } catch (e) {
            errors.push(cb.dataset.name + ': Network error');
        }
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-file-import"></i> Import Selected';

    let msg = `Successfully imported ${imported} employee(s).`;
    if (errors.length > 0) {
        msg += `\n\nErrors:\n${errors.join('\n')}`;
    }
    alert(msg);
    if (imported > 0) location.reload();
}

// ============================
// Assign Courses
// ============================
function openAssignCourses(pipelineId, empName) {
    document.getElementById('assignPipelineId').value = pipelineId;
    document.querySelector('#assignEmployee strong').textContent = empName;
    document.querySelectorAll('.course-cb').forEach(cb => cb.checked = false);
    openModal('assignModal');
}

async function submitAssignment() {
    const pipelineId = document.getElementById('assignPipelineId').value;
    const courseIds = Array.from(document.querySelectorAll('.course-cb:checked')).map(cb => cb.value);
    const dueDate = document.getElementById('dueDate').value;

    if (courseIds.length === 0) {
        alert('Please select at least one course.');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'assign_courses');
    formData.append('pipeline_id', pipelineId);
    formData.append('due_date', dueDate);
    courseIds.forEach(id => formData.append('course_ids[]', id));

    try {
        const res = await fetch('api_employee_pipeline.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            alert(data.message);
            closeModal('assignModal');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (e) {
        alert('Network error. Please try again.');
    }
}

// ============================
// Change Stage
// ============================
function changeStage(pipelineId, currentStage, empName) {
    document.getElementById('stagePipelineId').value = pipelineId;
    document.querySelector('#stageEmployee strong').textContent = empName;
    document.getElementById('stageNotes').value = '';
    
    // Pre-select current stage
    document.querySelectorAll('input[name="new_stage"]').forEach(r => {
        r.checked = r.value === currentStage;
    });
    
    openModal('stageModal');
}

async function submitStageChange() {
    const pipelineId = document.getElementById('stagePipelineId').value;
    const stage = document.querySelector('input[name="new_stage"]:checked')?.value;
    const notes = document.getElementById('stageNotes').value;

    if (!stage) {
        alert('Please select a stage.');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'update_stage');
    formData.append('pipeline_id', pipelineId);
    formData.append('stage', stage);
    formData.append('notes', notes);

    try {
        const res = await fetch('api_employee_pipeline.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            alert(data.message);
            closeModal('stageModal');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (e) {
        alert('Network error. Please try again.');
    }
}

// ============================
// View Details
// ============================
async function viewDetails(pipelineId) {
    const detailsBody = document.getElementById('detailsBody');
    detailsBody.innerHTML = '<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading details...</div>';
    openModal('detailsModal');

    try {
        const res = await fetch(`api_employee_pipeline.php?action=details&id=${pipelineId}`);
        const data = await res.json();

        if (!data.success) {
            detailsBody.innerHTML = `<div class="error-msg"><i class="fas fa-exclamation-circle"></i> ${data.error}</div>`;
            return;
        }

        const p = data.pipeline;
        const courses = data.courses || [];
        const quizzes = data.quiz_results || [];
        const hr2 = data.hr2_assessment;

        document.getElementById('detailsEmpName').textContent = p.hr1_employee_name;

        const stages = getStageSteps(p.current_stage);
        const typeClass = p.employee_type === 'new' ? 'type-new' : 'type-regular';
        const typeLabel = p.employee_type === 'new' ? '🆕 New Employee' : '👤 Regular Employee';
        const tenureStr = p.months_tenure >= 12 
            ? Math.floor(p.months_tenure/12) + 'y ' + (p.months_tenure%12) + 'm' 
            : p.months_tenure + ' months';

        let html = `
        <div class="detail-grid">
            <!-- Employee Overview -->
            <div class="detail-card detail-overview">
                <h4><i class="fas fa-user"></i> Employee Information</h4>
                <div class="detail-row">
                    <span>Name</span><strong>${p.hr1_employee_name}</strong>
                </div>
                <div class="detail-row">
                    <span>Email</span><strong>${p.hr1_employee_email || '—'}</strong>
                </div>
                <div class="detail-row">
                    <span>Department</span><strong>${p.department}</strong>
                </div>
                <div class="detail-row">
                    <span>Position</span><strong>${p.position}</strong>
                </div>
                <div class="detail-row">
                    <span>Employee Type</span>
                    <span class="type-badge ${typeClass}">${typeLabel}</span>
                </div>
                <div class="detail-row">
                    <span>Date Hired</span><strong>${p.date_hired || '—'}</strong>
                </div>
                <div class="detail-row">
                    <span>Tenure</span><strong>${tenureStr}</strong>
                </div>
            </div>

            <!-- Score Summary -->
            <div class="detail-card detail-scores">
                <h4><i class="fas fa-chart-bar"></i> Score Summary</h4>
                <div class="score-summary-grid">
                    <div class="score-box hr1-box">
                        <small>HR1 Score (30%)</small>
                        <span class="big-score">${p.hr1_eval_score ? parseFloat(p.hr1_eval_score).toFixed(1) + '%' : '—'}</span>
                        <small class="score-label">${p.hr1_eval_rating || ''}</small>
                    </div>
                    <div class="score-box plus-sign">+</div>
                    <div class="score-box hr2-box">
                        <small>HR2 Score (70%)</small>
                        <span class="big-score">${p.hr2_eval_score ? parseFloat(p.hr2_eval_score).toFixed(1) + '%' : 'Pending'}</span>
                        <small class="score-label">${p.hr2_eval_rating || ''}</small>
                    </div>
                    <div class="score-box equals-sign">=</div>
                    <div class="score-box combined-box">
                        <small>Combined</small>
                        <span class="big-score combined">${p.combined_score ? parseFloat(p.combined_score).toFixed(1) + '%' : '—'}</span>
                        <small class="score-label">${p.combined_rating || ''}</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pipeline Progress -->
        <div class="detail-card detail-pipeline-progress">
            <h4><i class="fas fa-stream"></i> Pipeline Progress</h4>
            <div class="detail-pipeline-flow">
                ${stages}
            </div>
        </div>

        <!-- Assigned Courses -->
        <div class="detail-card detail-courses">
            <h4><i class="fas fa-book-open"></i> Assigned Courses (${courses.length})</h4>
            ${courses.length > 0 ? `
                <div class="detail-course-list">
                    ${courses.map(c => `
                        <div class="detail-course-item ${c.is_completed == 1 ? 'completed' : ''}">
                            <div class="course-icon">${c.is_completed == 1 ? '<i class="fas fa-check-circle" style="color:#10b981;"></i>' : '<i class="fas fa-play-circle" style="color:#6366f1;"></i>'}</div>
                            <div class="course-details">
                                <strong>${c.course_title || 'Course #' + c.course_id}</strong>
                                <small>${c.skill_type || ''} · ${c.training_type || ''}</small>
                            </div>
                            <div class="course-progress-mini">
                                <div class="mini-progress-bar">
                                    <div class="mini-progress-fill" style="width: ${c.watch_progress}%"></div>
                                </div>
                                <small>${c.watch_progress}% watched</small>
                            </div>
                            <div class="course-status-badge status-${c.status}">
                                ${c.status}
                            </div>
                        </div>
                    `).join('')}
                </div>
            ` : '<p class="no-data-text">No courses assigned yet.</p>'}
        </div>

        <!-- Quiz Results -->
        <div class="detail-card detail-quizzes">
            <h4><i class="fas fa-tasks"></i> Quiz / Assessment Results (${quizzes.length})</h4>
            ${quizzes.length > 0 ? `
                <div class="detail-quiz-list">
                    ${quizzes.map(q => `
                        <div class="detail-quiz-item ${q.passed == 1 ? 'passed' : 'failed'}">
                            <div class="quiz-icon">${q.passed == 1 ? '<i class="fas fa-check" style="color:#10b981;"></i>' : '<i class="fas fa-times" style="color:#ef4444;"></i>'}</div>
                            <div class="quiz-details">
                                <strong>${q.course_title || 'Course Quiz'}</strong>
                                <small>${q.correct_answers}/${q.total_questions} correct</small>
                            </div>
                            <div class="quiz-score" style="color: ${getScoreColorJS(parseFloat(q.score_percentage))}">
                                ${parseFloat(q.score_percentage).toFixed(1)}%
                            </div>
                            <div class="quiz-badge ${q.passed == 1 ? 'badge-passed' : 'badge-failed'}">
                                ${q.passed == 1 ? 'PASSED' : 'FAILED'}
                            </div>
                        </div>
                    `).join('')}
                </div>
            ` : '<p class="no-data-text">No assessments taken yet.</p>'}
        </div>

        ${p.final_recommendation ? `
        <div class="detail-card detail-recommendation">
            <h4><i class="fas fa-comment-alt"></i> Final Recommendation</h4>
            <p>${p.final_recommendation}</p>
        </div>
        ` : ''}
        `;

        detailsBody.innerHTML = html;

    } catch (e) {
        detailsBody.innerHTML = '<div class="error-msg"><i class="fas fa-exclamation-circle"></i> Failed to load details.</div>';
    }
}

function getStageSteps(currentStage) {
    const stageOrder = ['hr1_evaluated', 'courses_assigned', 'learning', 'assessment', 'hr2_evaluated', 'completed'];
    const stageLabels = {
        'hr1_evaluated': { label: 'HR1 Evaluated', icon: 'fa-clipboard-check' },
        'courses_assigned': { label: 'Courses Assigned', icon: 'fa-book-open' },
        'learning': { label: 'Learning', icon: 'fa-graduation-cap' },
        'assessment': { label: 'Assessment', icon: 'fa-tasks' },
        'hr2_evaluated': { label: 'HR2 Evaluated', icon: 'fa-chart-line' },
        'completed': { label: 'Completed', icon: 'fa-check-circle' },
    };
    
    const currentIdx = stageOrder.indexOf(currentStage);

    return stageOrder.map((s, i) => {
        let cls = i < currentIdx ? 'stage-done' : (i === currentIdx ? 'stage-current' : 'stage-pending');
        let info = stageLabels[s];
        let connector = i < stageOrder.length - 1 ? '<div class="flow-connector ' + (i < currentIdx ? 'done' : '') + '"></div>' : '';
        return `
            <div class="flow-step-detail ${cls}">
                <div class="flow-dot"><i class="fas ${info.icon}"></i></div>
                <span>${info.label}</span>
            </div>
            ${connector}
        `;
    }).join('');
}

function getScoreColorJS(score) {
    if (score >= 90) return '#10b981';
    if (score >= 80) return '#22d3ee';
    if (score >= 70) return '#6366f1';
    if (score >= 60) return '#f59e0b';
    if (score >= 50) return '#f97316';
    return '#ef4444';
}

// ============================
// Final Evaluation
// ============================
async function finalEvaluate(pipelineId, empName) {
    document.getElementById('evalPipelineId').value = pipelineId;
    document.querySelector('#evalEmployee strong').textContent = empName;
    document.getElementById('evalRecommendation').value = '';

    // Fetch details for preview
    try {
        const res = await fetch(`api_employee_pipeline.php?action=details&id=${pipelineId}`);
        const data = await res.json();
        if (data.success) {
            const p = data.pipeline;
            const hr1 = parseFloat(p.hr1_eval_score || 0);
            const hr2 = parseFloat(p.avg_assessment_score || 0);
            const combined = (hr1 * 0.30) + (hr2 * 0.70);
            const rating = getRatingLabel(combined);
            
            document.getElementById('evalPreview').innerHTML = `
                <div class="eval-preview-grid">
                    <div class="eval-box">
                        <label>HR1 Score</label>
                        <span class="eval-value">${hr1.toFixed(1)}%</span>
                        <small>× 30% = ${(hr1 * 0.30).toFixed(1)}%</small>
                    </div>
                    <div class="eval-plus">+</div>
                    <div class="eval-box">
                        <label>HR2 Score (Avg Quiz)</label>
                        <span class="eval-value">${hr2.toFixed(1)}%</span>
                        <small>× 70% = ${(hr2 * 0.70).toFixed(1)}%</small>
                    </div>
                    <div class="eval-equals">=</div>
                    <div class="eval-box eval-combined">
                        <label>Combined Score</label>
                        <span class="eval-value eval-final" style="color: ${getScoreColorJS(combined)}">${combined.toFixed(1)}%</span>
                        <small class="eval-rating">${rating}</small>
                    </div>
                </div>
                <div class="eval-breakdown-note">
                    <i class="fas fa-info-circle"></i> 
                    Course completion: ${parseFloat(p.course_completion_pct || 0).toFixed(0)}% · 
                    Quizzes: ${p.assessments_passed}/${p.assessments_taken} passed · 
                    Avg Quiz Score: ${hr2.toFixed(1)}%
                </div>
            `;
        }
    } catch (e) {
        document.getElementById('evalPreview').innerHTML = '<p class="error-msg">Could not load preview.</p>';
    }

    openModal('evaluateModal');
}

function getRatingLabel(score) {
    if (score >= 90) return 'Outstanding';
    if (score >= 80) return 'Excellent';
    if (score >= 70) return 'Very Good';
    if (score >= 60) return 'Good';
    if (score >= 50) return 'Fair';
    return 'Needs Improvement';
}

async function submitEvaluation() {
    const pipelineId = document.getElementById('evalPipelineId').value;
    const recommendation = document.getElementById('evalRecommendation').value;

    const formData = new FormData();
    formData.append('action', 'evaluate');
    formData.append('pipeline_id', pipelineId);
    formData.append('recommendation', recommendation);

    try {
        const res = await fetch('api_employee_pipeline.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            alert(`Evaluation Complete!\n\nHR1 Score: ${data.hr1_score}%\nHR2 Score: ${data.hr2_score}%\nCombined: ${data.combined_score}% (${data.combined_rating})`);
            closeModal('evaluateModal');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (e) {
        alert('Network error. Please try again.');
    }
}

// ============================
// Refresh — sync course_progress data first, then reload
// ============================
document.getElementById('refreshBtn').addEventListener('click', () => {
    const btn = document.getElementById('refreshBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i><span>Syncing...</span>';
    
    fetch('api_employee_pipeline.php?action=sync_progress')
        .then(r => r.json())
        .then(data => {
            location.reload();
        })
        .catch(() => location.reload());
});

// ============================
// Auto-sync course progress on page load
// ============================
document.addEventListener('DOMContentLoaded', () => {
    // Animate rows
    document.querySelectorAll('.pipeline-row').forEach((row, i) => {
        row.style.animationDelay = `${i * 0.05}s`;
    });
    
    // Auto-sync course_progress in background
    fetch('api_employee_pipeline.php?action=sync_progress')
        .then(r => r.json())
        .catch(() => {});
});
</script>
</body>
</html>

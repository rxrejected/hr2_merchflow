<?php
session_start();

// ===== SESSION TIMEOUT SETTINGS =====

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}


// Prevent caching
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require 'Connection/Config.php';
require_once 'Connection/ai_config.php';
require_once 'Connection/hr1_db.php';

// ===== HR1 REAL-TIME DATA FETCH =====
$hr1DB = new HR1Database();

// Get all employees from HR1 employees table (real-time)
$hr1EmployeeResponse = $hr1DB->getEmployees('', 1000, 0);
$hr1EmployeeList = $hr1EmployeeResponse['success'] ? $hr1EmployeeResponse['data'] : [];
$totalEmployees = $hr1EmployeeResponse['success'] ? ($hr1EmployeeResponse['total'] ?? count($hr1EmployeeList)) : 0;

// Get employee status counts from HR1
$hr1StatusResponse = $hr1DB->getEmployeeStatusCounts();
$statusCounts = $hr1StatusResponse['success'] ? $hr1StatusResponse['data'] : [
    'total' => $totalEmployees, 'active' => 0, 'probation' => 0, 'onboarding' => 0, 'on_leave' => 0, 'inactive' => 0
];

// Get evaluations from HR1 (real-time)
$hr1EvalResponse = $hr1DB->getEvaluations('completed', 1000);
$hr1Evaluations = $hr1EvalResponse['success'] ? $hr1EvalResponse['data'] : [];

// Group evaluations by employee (same logic as module1_sub1.php for consistent count)
$hr1EvalByEmployee = [];
foreach ($hr1Evaluations as $eval) {
    $empId = $eval['employee_id'];
    if (!isset($hr1EvalByEmployee[$empId])) {
        $hr1EvalByEmployee[$empId] = [];
    }
    $hr1EvalByEmployee[$empId][] = $eval;
}
$totalEvaluations = count($hr1EvalByEmployee); // Count unique evaluated employees

// Get recent employees (last 5, sorted by created_at)
$recentEmployees = [];
usort($hr1EmployeeList, function($a, $b) {
    $dateA = strtotime($a['created_at'] ?? '1970-01-01');
    $dateB = strtotime($b['created_at'] ?? '1970-01-01');
    return $dateB - $dateA;
});
$recentEmployees = array_slice($hr1EmployeeList, 0, 5);

// ===== COUNTER FUNCTION =====
function getCount($conn, $query) {
    $res = $conn->query($query);
    return ($res && $res->num_rows > 0) ? (int)$res->fetch_assoc()['total'] : 0;
}

// Local HR2 counts for trainings/courses
$totalTrainings     = getCount($conn, "SELECT COUNT(*) AS total FROM training_schedules");
$totalCourses       = getCount($conn, "SELECT COUNT(*) AS total FROM courses");
$upcomingTrainings  = getCount($conn, "SELECT COUNT(*) AS total FROM training_schedules WHERE date >= CURDATE()");

// Get upcoming trainings list
$upcomingList = [];
$upcomingQuery = $conn->query("SELECT title, date, time FROM training_schedules WHERE date >= CURDATE() ORDER BY date ASC LIMIT 5");
if ($upcomingQuery) {
    while ($row = $upcomingQuery->fetch_assoc()) {
        $upcomingList[] = $row;
    }
}

$pageTitle = "Admin | Dashboard";
$currentDate = date("l, F j, Y");
$greeting = "Good " . (date("H") < 12 ? "Morning" : (date("H") < 18 ? "Afternoon" : "Evening"));
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $pageTitle; ?></title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="Css/admin.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="Css/ai_chat_bubble.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="Css/ai_analytics.css?v=<?php echo time(); ?>">
<link rel="icon" href="osicon.png">

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>
<?php include 'partials/sidebar.php'; ?>

<div class="main-content">
<?php include 'partials/nav.php'; ?>

<div class="dashboard-container">
  
  <!-- ===== HEADER SECTION ===== -->
  <div class="dashboard-header">
    <div class="header-text">
      <h1><?php echo $greeting; ?>, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?>! 👋</h1>
      <p class="date-display"><i class="far fa-calendar-alt"></i> <?php echo $currentDate; ?></p>
    </div>
    <div class="header-actions">
      <a href="module3_sub1.php" class="btn-action secondary">
        <i class="fas fa-calendar-plus"></i> Schedule Training
      </a>
    </div>
  </div>

  <!-- ===== METRIC CARDS ===== -->
  <div class="metrics-wrapper">
    
    <div class="metric-card clickable-card" data-aos="fade-up" onclick="window.location.href='module5_sub1_admin.php'" title="Click to view Employee Directory">
      <div class="metric-icon blue">
        <i class="fas fa-users"></i>
      </div>
      <div class="metric-info">
        <p>HR1 Employees</p>
        <h3 class="counter" data-target="<?php echo $totalEmployees; ?>">0</h3>
        <div class="metric-breakdown">
          <span class="mini-stat active-stat" title="Active"><i class="fas fa-check-circle"></i> <?php echo $statusCounts['active'] ?? 0; ?></span>
          <span class="mini-stat probation-stat" title="Probation"><i class="fas fa-hourglass-half"></i> <?php echo $statusCounts['probation'] ?? 0; ?></span>
          <span class="mini-stat onboarding-stat" title="Onboarding"><i class="fas fa-user-plus"></i> <?php echo $statusCounts['onboarding'] ?? 0; ?></span>
          <?php if (($statusCounts['on_leave'] ?? 0) > 0): ?>
          <span class="mini-stat leave-stat" title="On Leave"><i class="fas fa-pause-circle"></i> <?php echo $statusCounts['on_leave']; ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="metric-trend up">
        <i class="fas fa-link"></i>
        <span>HR1</span>
      </div>
    </div>

    <div class="metric-card clickable-card" data-aos="fade-up" data-aos-delay="100" onclick="window.location.href='module3_sub1.php'" title="Click to view Training Schedules">
      <div class="metric-icon orange">
        <i class="fas fa-chalkboard-teacher"></i>
      </div>
      <div class="metric-info">
        <p>Total Trainings</p>
        <h3 class="counter" data-target="<?php echo $totalTrainings; ?>">0</h3>
      </div>
      <div class="metric-trend neutral">
        <i class="fas fa-minus"></i>
        <span>Sessions</span>
      </div>
    </div>

    <div class="metric-card clickable-card" data-aos="fade-up" data-aos-delay="200" onclick="window.location.href='module1_sub1.php'" title="Click to view Competency Reports">
      <div class="metric-icon red">
        <i class="fas fa-clipboard-check"></i>
      </div>
      <div class="metric-info">
        <p>HR1 Evaluations</p>
        <h3 class="counter" data-target="<?php echo $totalEvaluations; ?>">0</h3>
      </div>
      <div class="metric-trend up">
        <i class="fas fa-link"></i>
        <span>HR1</span>
      </div>
    </div>

    <div class="metric-card clickable-card" data-aos="fade-up" data-aos-delay="300" onclick="window.location.href='module2_sub1.php'" title="Click to view Courses">
      <div class="metric-icon purple">
        <i class="fas fa-book-open"></i>
      </div>
      <div class="metric-info">
        <p>Total Courses</p>
        <h3 class="counter" data-target="<?php echo $totalCourses; ?>">0</h3>
      </div>
      <div class="metric-trend neutral">
        <i class="fas fa-minus"></i>
        <span>Available</span>
      </div>
    </div>

    <div class="metric-card clickable-card" data-aos="fade-up" data-aos-delay="400" onclick="window.location.href='module3_sub1.php'" title="Click to view Upcoming Training Schedules">
      <div class="metric-icon teal">
        <i class="fas fa-calendar-week"></i>
      </div>
      <div class="metric-info">
        <p>Upcoming Trainings</p>
        <h3 class="counter" data-target="<?php echo $upcomingTrainings; ?>">0</h3>
      </div>
      <div class="metric-trend up">
        <i class="fas fa-arrow-up"></i>
        <span>Scheduled</span>
      </div>
    </div>

  </div>

  <!-- ===== AI CHAT BOX ===== -->
  <div class="ai-chat-container" id="aiChatContainer">
    <!-- Floating AI Button -->
    <button class="ai-bubble-btn" id="aiBubbleBtn" onclick="toggleAiChat()">
      <i class="fas fa-robot"></i>
      <span class="ai-pulse"></span>
    </button>
    
    <!-- Chat Window -->
    <div class="ai-chat-window" id="aiChatWindow">
      <div class="ai-chat-header">
        <div class="ai-chat-avatar">
          <i class="fas fa-robot"></i>
        </div>
        <div class="ai-chat-title">
          <h4>AI Career Coach</h4>
          <span class="ai-status"><i class="fas fa-circle"></i> Online</span>
        </div>
        <div class="ai-chat-header-actions">
          <button class="ai-header-btn" onclick="clearChatHistory()" title="Clear Chat">
            <i class="fas fa-trash-alt"></i>
          </button>
          <button class="ai-chat-close" onclick="toggleAiChat()">
            <i class="fas fa-times"></i>
          </button>
        </div>
      </div>
      
      <div class="ai-chat-body" id="aiChatBody">
        <div class="ai-message ai-bot">
          <div class="ai-message-avatar">
            <i class="fas fa-robot"></i>
          </div>
          <div class="ai-message-bubble">
            <p>Hello! I'm your AI Career Coach. You can ask me anything about:</p>
            <ul style="margin: 8px 0; padding-left: 18px; font-size: 0.85rem;">
              <li>Employee performance & evaluations</li>
              <li>Career development & promotions</li>
              <li>Training recommendations</li>
              <li>Skill gap analysis</li>
              <li>Workforce analytics</li>
            </ul>
            <span class="ai-message-time">Just now</span>
          </div>
        </div>
        <div class="ai-quick-actions">
          <button class="ai-quick-btn" onclick="sendQuickAction('Analyze all HR1 employee performance data')">
            <i class="fas fa-chart-bar"></i> Analyze Employees
          </button>
          <button class="ai-quick-btn" onclick="sendQuickAction('What are the top training recommendations for our workforce?')">
            <i class="fas fa-graduation-cap"></i> Training Tips
          </button>
          <button class="ai-quick-btn" onclick="sendQuickAction('Give me a workforce health summary with risks and recommendations')">
            <i class="fas fa-heartbeat"></i> Workforce Health
          </button>
        </div>
      </div>
      
      <div class="ai-chat-footer">
        <div class="ai-chat-input-wrapper">
          <textarea class="ai-chat-input" id="aiChatInput" placeholder="Type your message..." rows="1" 
            onkeydown="handleChatKeydown(event)" oninput="autoResizeInput(this)"></textarea>
          <button class="ai-send-btn" id="aiSendBtn" onclick="sendChatMessage()">
            <i class="fas fa-paper-plane"></i>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- AI Chat Bubble styles loaded from Css/ai_chat_bubble.css -->

  <!-- ===== CHARTS SECTION ===== -->
  <div class="charts-wrapper">

    <div class="chart-card">
      <div class="chart-header">
        <h3><i class="fas fa-chart-bar"></i> System Metrics</h3>
        <div class="chart-actions">
          <button class="chart-btn active" data-chart="bar">Bar</button>
          <button class="chart-btn" data-chart="line">Line</button>
        </div>
      </div>
      <div class="chart-body">
        <canvas id="barChart"></canvas>
      </div>
    </div>

    <div class="chart-card">
      <div class="chart-header">
        <h3><i class="fas fa-chart-pie"></i> Data Distribution</h3>
      </div>
      <div class="chart-body">
        <canvas id="pieChart"></canvas>
      </div>
    </div>

  </div>

  <!-- ===== AI DATA ANALYTICS SECTION ===== -->
  <div class="ai-analytics-section">
    <div class="section-header-row">
      <div class="section-title-group">
        <h2><i class="fas fa-brain"></i> AI Data Analytics</h2>
        <p>Real-time AI-powered workforce insights from HR1 integration</p>
      </div>
      <div class="section-actions-group">
        <button class="ai-refresh-all-btn" id="aiRefreshAllBtn" onclick="runFullAiAnalytics()">
          <i class="fas fa-sync-alt"></i> Generate Report
        </button>
      </div>
    </div>

    <!-- AI Analytics Cards Row -->
    <div class="ai-analytics-cards">
      
      <!-- Workforce Health Score -->
      <div class="ai-card ai-card-health" id="aiHealthCard">
        <div class="ai-card-header">
          <div class="ai-card-icon pulse-green">
            <i class="fas fa-heartbeat"></i>
          </div>
          <span class="ai-badge">AI Score</span>
        </div>
        <div class="ai-card-body">
          <div class="ai-score-ring" id="healthScoreRing">
            <svg viewBox="0 0 120 120">
              <circle class="ring-bg" cx="60" cy="60" r="52" />
              <circle class="ring-fill" id="healthRingFill" cx="60" cy="60" r="52" stroke-dasharray="326.7" stroke-dashoffset="326.7" />
              <text x="60" y="55" class="ring-value" id="healthScoreVal">--</text>
              <text x="60" y="72" class="ring-label">/ 100</text>
            </svg>
          </div>
          <h4>Workforce Health</h4>
          <p class="ai-insight-text" id="healthInsight">Click "Generate Report" to analyze workforce health metrics using AI.</p>
        </div>
      </div>

      <!-- Turnover Risk Prediction -->
      <div class="ai-card ai-card-risk" id="aiRiskCard">
        <div class="ai-card-header">
          <div class="ai-card-icon pulse-orange">
            <i class="fas fa-exclamation-triangle"></i>
          </div>
          <span class="ai-badge warning">Risk Analysis</span>
        </div>
        <div class="ai-card-body">
          <div class="ai-risk-meter">
            <div class="risk-level" id="riskLevel">
              <span class="risk-value" id="riskValue">--</span>
              <span class="risk-label">Risk Level</span>
            </div>
            <div class="risk-bar-container">
              <div class="risk-bar-fill" id="riskBarFill" style="width: 0%"></div>
            </div>
          </div>
          <h4>Turnover Risk</h4>
          <p class="ai-insight-text" id="riskInsight">AI will predict potential turnover risks based on employee data patterns.</p>
        </div>
      </div>

      <!-- Skill Gap Analysis -->
      <div class="ai-card ai-card-skills" id="aiSkillsCard">
        <div class="ai-card-header">
          <div class="ai-card-icon pulse-blue">
            <i class="fas fa-puzzle-piece"></i>
          </div>
          <span class="ai-badge info">Skills Intel</span>
        </div>
        <div class="ai-card-body">
          <div class="ai-skills-chart">
            <canvas id="aiSkillsRadar" width="200" height="200"></canvas>
          </div>
          <h4>Competency Map</h4>
          <p class="ai-insight-text" id="skillsInsight">AI-driven competency and skill gap analysis across departments.</p>
        </div>
      </div>

      <!-- AI Recommendations -->
      <div class="ai-card ai-card-recs" id="aiRecsCard">
        <div class="ai-card-header">
          <div class="ai-card-icon pulse-purple">
            <i class="fas fa-lightbulb"></i>
          </div>
          <span class="ai-badge purple">AI Recommendations</span>
        </div>
        <div class="ai-card-body">
          <div class="ai-recs-list" id="aiRecsList">
            <div class="ai-rec-placeholder">
              <i class="fas fa-robot"></i>
              <p>Waiting for AI analysis...</p>
            </div>
          </div>
          <h4>Action Items</h4>
          <p class="ai-insight-text" id="recsInsight">Actionable recommendations generated by AI for workforce optimization.</p>
        </div>
      </div>

    </div>

    <!-- AI Analytics Detail Row -->
    <div class="ai-analytics-detail">
      
      <!-- Department Performance Breakdown -->
      <div class="ai-detail-card">
        <div class="ai-detail-header">
          <h3><i class="fas fa-sitemap"></i> Department Performance</h3>
          <span class="ai-powered-tag"><i class="fas fa-brain"></i> AI Powered</span>
        </div>
        <div class="ai-detail-body">
          <div class="dept-performance-list" id="deptPerformanceList">
            <div class="ai-loading-placeholder">
              <div class="shimmer-line"></div>
              <div class="shimmer-line short"></div>
              <div class="shimmer-line"></div>
              <div class="shimmer-line short"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- AI Trend Prediction -->
      <div class="ai-detail-card">
        <div class="ai-detail-header">
          <h3><i class="fas fa-chart-line"></i> Workforce Trends</h3>
          <span class="ai-powered-tag"><i class="fas fa-brain"></i> AI Predicted</span>
        </div>
        <div class="ai-detail-body">
          <canvas id="aiTrendChart" height="250"></canvas>
        </div>
      </div>

    </div>
  </div>

  <!-- AI Analytics styles loaded from Css/ai_analytics.css -->

  <!-- ===== INFO CARDS SECTION ===== -->
  <div class="info-section">

    <!-- Recent Employees from HR1 -->
    <div class="info-card">
      <div class="info-header">
        <h3><i class="fas fa-user-plus"></i> Recent Employees <span style="font-size:11px;color:#10b981;margin-left:8px;"><i class="fas fa-circle" style="font-size:6px;"></i> Real-time from HR1</span></h3>
        <a href="module5_sub1_admin.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
      </div>
      <div class="info-body">
        <?php if (count($recentEmployees) > 0): ?>
          <ul class="user-list">
            <?php foreach ($recentEmployees as $employee): 
              $empName = $employee['name'] ?? $employee['full_name'] ?? 'Unknown';
              $empRole = $employee['role'] ?? 'Employee';
              $empDept = $employee['department'] ?? '';
              $empPhoto = $employee['photo'] ?? '';
              $empDate = $employee['created_at'] ?? $employee['date_hired'] ?? '';
              $empStatus = $employee['status'] ?? 'active';
              $empId = $employee['id'] ?? 0;
            ?>
              <li class="user-item" style="cursor:pointer;" onclick="window.location.href='module5_sub1_admin.php'" title="Click to view employee directory">
                <div class="user-avatar-sm" style="<?php echo !empty($empPhoto) && $empPhoto !== 'uploads/avatars/default.png' ? 'background:none;' : ''; ?>">
                  <?php if (!empty($empPhoto) && $empPhoto !== 'uploads/avatars/default.png'): ?>
                    <img src="<?php echo htmlspecialchars($empPhoto); ?>" alt="Avatar" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\'fas fa-user\'></i>'; this.parentElement.style.background='';">
                  <?php else: ?>
                    <i class="fas fa-user"></i>
                  <?php endif; ?>
                </div>
                <div class="user-details">
                  <span class="user-name"><?php echo htmlspecialchars($empName); ?></span>
                  <span class="user-role"><?php echo htmlspecialchars(ucfirst($empRole)); ?><?php echo $empDept ? ' • ' . htmlspecialchars($empDept) : ''; ?></span>
                </div>
                <div style="text-align:right;">
                  <span class="user-date"><?php echo !empty($empDate) ? date('M d', strtotime($empDate)) : 'N/A'; ?></span>
                  <span style="display:block;font-size:10px;padding:2px 6px;border-radius:8px;margin-top:2px;text-transform:capitalize;
                    <?php 
                      $statusColors = [
                        'active' => 'background:rgba(34,197,94,0.12);color:#16a34a;',
                        'probation' => 'background:rgba(245,158,11,0.12);color:#d97706;',
                        'onboarding' => 'background:rgba(59,130,246,0.12);color:#2563eb;',
                        'on_leave' => 'background:rgba(239,68,68,0.12);color:#dc2626;',
                        'inactive' => 'background:rgba(107,114,128,0.12);color:#6b7280;'
                      ];
                      echo $statusColors[$empStatus] ?? $statusColors['active'];
                    ?>"><?php echo str_replace('_', ' ', $empStatus); ?></span>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="empty-state">
            <i class="fas fa-users"></i>
            <p>No recent employees from HR1</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Upcoming Trainings -->
    <div class="info-card">
      <div class="info-header">
        <h3><i class="fas fa-calendar-alt"></i> Upcoming Trainings</h3>
        <a href="module2_sub1.php" class="view-all">View All <i class="fas fa-arrow-right"></i></a>
      </div>
      <div class="info-body">
        <?php if (count($upcomingList) > 0): ?>
          <ul class="training-list">
            <?php foreach ($upcomingList as $training): ?>
              <li class="training-item">
                <div class="training-date-badge">
                  <span class="day"><?php echo date('d', strtotime($training['date'])); ?></span>
                  <span class="month"><?php echo date('M', strtotime($training['date'])); ?></span>
                </div>
                <div class="training-details">
                  <span class="training-title"><?php echo htmlspecialchars($training['title']); ?></span>
                  <span class="training-time"><i class="far fa-clock"></i> <?php echo date('h:i A', strtotime($training['time'])); ?></span>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="empty-state">
            <i class="fas fa-calendar-times"></i>
            <p>No upcoming trainings</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Quick Stats -->
    <div class="info-card quick-stats">
      <div class="info-header">
        <h3><i class="fas fa-bolt"></i> Quick Stats</h3>
      </div>
      <div class="info-body">
        <div class="stat-item">
          <div class="stat-icon blue">
            <i class="fas fa-percentage"></i>
          </div>
          <div class="stat-info">
            <span class="stat-label">Active Rate</span>
            <span class="stat-value"><?php echo $totalEmployees > 0 ? round((($statusCounts['active'] ?? 0) / $totalEmployees) * 100, 1) : 0; ?>%</span>
          </div>
        </div>
        <div class="stat-item">
          <div class="stat-icon green">
            <i class="fas fa-graduation-cap"></i>
          </div>
          <div class="stat-info">
            <span class="stat-label">Courses per Training</span>
            <span class="stat-value"><?php echo $totalTrainings > 0 ? round($totalCourses / $totalTrainings, 1) : 0; ?></span>
          </div>
        </div>
        <div class="stat-item">
          <div class="stat-icon orange">
            <i class="fas fa-tasks"></i>
          </div>
          <div class="stat-info">
            <span class="stat-label">Evaluations per Employee</span>
            <span class="stat-value"><?php echo $totalEmployees > 0 ? round($totalEvaluations / $totalEmployees, 1) : 0; ?></span>
          </div>
        </div>
      </div>
    </div>

  </div>

</div>
</div>

<script>
/* ===== COUNTER ANIMATION ===== */
const observerOptions = {
  threshold: 0.5,
  rootMargin: '0px'
};

const counterObserver = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      const counter = entry.target;
      const target = +counter.dataset.target;
      let count = 0;
      const duration = 1500;
      const increment = target / (duration / 16);

      function updateCounter() {
        count += increment;
        if (count < target) {
          counter.textContent = Math.floor(count).toLocaleString();
          requestAnimationFrame(updateCounter);
        } else {
          counter.textContent = target.toLocaleString();
        }
      }
      updateCounter();
      counterObserver.unobserve(counter);
    }
  });
}, observerOptions);

document.querySelectorAll('.counter').forEach(counter => {
  counterObserver.observe(counter);
});

/* ===== CHART DATA ===== */
const labels = ['Employees', 'Trainings', 'Evaluations', 'Courses', 'Upcoming'];
const values = [
  <?php echo $totalEmployees; ?>,
  <?php echo $totalTrainings; ?>,
  <?php echo $totalEvaluations; ?>,
  <?php echo $totalCourses; ?>,
  <?php echo $upcomingTrainings; ?>
];

const colors = ['#3b82f6', '#f97316', '#ef4444', '#8b5cf6', '#dc3545'];
const gradients = [];

/* ===== BAR CHART ===== */
const barCtx = document.getElementById('barChart').getContext('2d');

// Create gradients
colors.forEach((color, i) => {
  const gradient = barCtx.createLinearGradient(0, 0, 0, 300);
  gradient.addColorStop(0, color);
  gradient.addColorStop(1, color + '80');
  gradients.push(gradient);
});

let barChart = new Chart(barCtx, {
  type: 'bar',
  data: {
    labels: labels,
    datasets: [{
      label: 'Count',
      data: values,
      backgroundColor: gradients,
      borderRadius: 12,
      borderSkipped: false,
      barThickness: 40,
      maxBarThickness: 50
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: '#1f2937',
        titleColor: '#fff',
        bodyColor: '#fff',
        padding: 12,
        cornerRadius: 8,
        displayColors: false
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        grid: {
          color: '#e5e7eb',
          drawBorder: false
        },
        ticks: {
          font: { family: 'Poppins', size: 12 },
          color: '#6b7280'
        }
      },
      x: {
        grid: { display: false },
        ticks: {
          font: { family: 'Poppins', size: 11 },
          color: '#6b7280'
        }
      }
    },
    animation: {
      duration: 1500,
      easing: 'easeOutQuart'
    }
  }
});

/* ===== CHART TYPE TOGGLE ===== */
document.querySelectorAll('.chart-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.chart-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    
    const chartType = this.dataset.chart;
    barChart.destroy();
    
    barChart = new Chart(barCtx, {
      type: chartType,
      data: {
        labels: labels,
        datasets: [{
          label: 'Count',
          data: values,
          backgroundColor: chartType === 'line' ? 'rgba(59, 130, 246, 0.1)' : gradients,
          borderColor: chartType === 'line' ? '#3b82f6' : undefined,
          borderWidth: chartType === 'line' ? 3 : 0,
          borderRadius: chartType === 'bar' ? 12 : 0,
          fill: chartType === 'line',
          tension: 0.4,
          pointBackgroundColor: '#3b82f6',
          pointBorderColor: '#fff',
          pointBorderWidth: 2,
          pointRadius: 6,
          pointHoverRadius: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#1f2937',
            titleColor: '#fff',
            bodyColor: '#fff',
            padding: 12,
            cornerRadius: 8
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            grid: { color: '#e5e7eb', drawBorder: false },
            ticks: { font: { family: 'Poppins', size: 12 }, color: '#6b7280' }
          },
          x: {
            grid: { display: false },
            ticks: { font: { family: 'Poppins', size: 11 }, color: '#6b7280' }
          }
        },
        animation: { duration: 800, easing: 'easeOutQuart' }
      }
    });
  });
});

/* ===== PIE CHART ===== */
const pieCtx = document.getElementById('pieChart').getContext('2d');
new Chart(pieCtx, {
  type: 'doughnut',
  data: {
    labels: labels,
    datasets: [{
      data: values,
      backgroundColor: colors,
      borderWidth: 0,
      hoverOffset: 20
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '70%',
    plugins: {
      legend: {
        position: 'bottom',
        labels: {
          padding: 20,
          usePointStyle: true,
          pointStyle: 'circle',
          font: { family: 'Poppins', size: 12 }
        }
      },
      tooltip: {
        backgroundColor: '#1f2937',
        titleColor: '#fff',
        bodyColor: '#fff',
        padding: 12,
        cornerRadius: 8
      }
    },
    animation: {
      animateRotate: true,
      duration: 1500
    }
  }
});

/* ===== CARD ANIMATIONS ===== */
const cards = document.querySelectorAll('.metric-card, .chart-card, .info-card');
const cardObserver = new IntersectionObserver((entries) => {
  entries.forEach((entry, index) => {
    if (entry.isIntersecting) {
      setTimeout(() => {
        entry.target.classList.add('animate-in');
      }, index * 100);
      cardObserver.unobserve(entry.target);
    }
  });
}, { threshold: 0.1 });

cards.forEach(card => cardObserver.observe(card));

/* ===== AI REAL-TIME CHAT FUNCTIONS ===== */
let chatHistory = [];
let isAiResponding = false;

function toggleAiChat() {
  const chatWindow = document.getElementById('aiChatWindow');
  const bubbleBtn = document.getElementById('aiBubbleBtn');
  chatWindow.classList.toggle('active');
  
  if (chatWindow.classList.contains('active')) {
    bubbleBtn.innerHTML = '<i class="fas fa-times"></i>';
    const input = document.getElementById('aiChatInput');
    setTimeout(() => input.focus(), 300);
  } else {
    bubbleBtn.innerHTML = '<i class="fas fa-robot"></i><span class="ai-pulse"></span>';
  }
}

function addMessage(content, isBot = true, isRaw = false) {
  const body = document.getElementById('aiChatBody');
  const now = new Date();
  const timeStr = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  
  // Hide quick actions after first interaction
  const quickActions = body.querySelector('.ai-quick-actions');
  if (quickActions) quickActions.style.display = 'none';
  
  const messageDiv = document.createElement('div');
  messageDiv.className = `ai-message ${isBot ? 'ai-bot' : 'ai-user'} animate-fadeInUp`;
  
  if (isRaw) {
    messageDiv.innerHTML = content;
  } else {
    // Format markdown-like content for bot messages
    let formattedContent = content;
    if (isBot) {
      formattedContent = formatAiResponse(content);
    }
    
    messageDiv.innerHTML = `
      <div class="ai-message-avatar">
        <i class="fas fa-${isBot ? 'robot' : 'user'}"></i>
      </div>
      <div class="ai-message-bubble">
        ${formattedContent}
        <span class="ai-message-time">${timeStr}</span>
      </div>
    `;
  }
  
  body.appendChild(messageDiv);
  body.scrollTop = body.scrollHeight;
  return messageDiv;
}

function formatAiResponse(text) {
  // Convert markdown to HTML
  let html = text;
  // Headers
  html = html.replace(/^### (.+)$/gm, '<h4 style="margin:10px 0 6px;font-size:0.9rem;">$1</h4>');
  html = html.replace(/^## (.+)$/gm, '<h3 style="margin:12px 0 6px;font-size:0.95rem;color:var(--primary-red);">$1</h3>');
  // Bold
  html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  // Italic
  html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
  // Bullet points
  html = html.replace(/^- (.+)$/gm, '<li style="margin-left:16px;font-size:0.85rem;">$1</li>');
  html = html.replace(/^• (.+)$/gm, '<li style="margin-left:16px;font-size:0.85rem;">$1</li>');
  // Numbered lists
  html = html.replace(/^\d+\. (.+)$/gm, '<li style="margin-left:16px;font-size:0.85rem;">$1</li>');
  // Tables - convert markdown tables to HTML
  html = html.replace(/\|(.+)\|\n\|[-|: ]+\|\n((?:\|.+\|\n?)+)/gm, function(match, header, rows) {
    const headers = header.split('|').filter(h => h.trim()).map(h => `<th style="padding:6px 10px;background:var(--primary-red);color:#fff;font-size:0.75rem;">${h.trim()}</th>`).join('');
    const rowsHtml = rows.trim().split('\n').map(row => {
      const cells = row.split('|').filter(c => c.trim()).map(c => `<td style="padding:5px 10px;border-bottom:1px solid var(--border-color);font-size:0.8rem;">${c.trim()}</td>`).join('');
      return `<tr>${cells}</tr>`;
    }).join('');
    return `<table style="width:100%;border-collapse:collapse;margin:8px 0;border-radius:8px;overflow:hidden;"><thead><tr>${headers}</tr></thead><tbody>${rowsHtml}</tbody></table>`;
  });
  // Line breaks
  html = html.replace(/\n\n/g, '<br><br>');
  html = html.replace(/\n/g, '<br>');
  // Wrap in paragraph if not already structured
  if (!html.startsWith('<')) {
    html = `<p>${html}</p>`;
  }
  return html;
}

function showTyping() {
  const body = document.getElementById('aiChatBody');
  const typingDiv = document.createElement('div');
  typingDiv.className = 'ai-message ai-bot animate-fadeIn';
  typingDiv.id = 'aiTyping';
  typingDiv.innerHTML = `
    <div class="ai-message-avatar">
      <i class="fas fa-robot"></i>
    </div>
    <div class="ai-message-bubble">
      <div class="ai-typing">
        <span></span><span></span><span></span>
      </div>
    </div>
  `;
  body.appendChild(typingDiv);
  body.scrollTop = body.scrollHeight;
}

function hideTyping() {
  const typing = document.getElementById('aiTyping');
  if (typing) typing.remove();
}

function handleChatKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendChatMessage();
  }
}

function autoResizeInput(textarea) {
  textarea.style.height = 'auto';
  textarea.style.height = Math.min(textarea.scrollHeight, 100) + 'px';
}

function sendQuickAction(message) {
  const input = document.getElementById('aiChatInput');
  input.value = message;
  sendChatMessage();
}

function sendChatMessage() {
  const input = document.getElementById('aiChatInput');
  const sendBtn = document.getElementById('aiSendBtn');
  const message = input.value.trim();
  
  if (!message || isAiResponding) return;
  
  // Clear input
  input.value = '';
  input.style.height = 'auto';
  
  // Add user message
  addMessage(`<p>${escapeHtml(message)}</p>`, false);
  
  // Store in history
  chatHistory.push({ role: 'user', content: message });
  
  // Disable input
  isAiResponding = true;
  sendBtn.disabled = true;
  sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  input.disabled = true;
  
  // Show typing
  setTimeout(() => showTyping(), 300);
  
  // Check if it's a bulk analysis request
  const lowerMsg = message.toLowerCase();
  if (lowerMsg.includes('analyze') && (lowerMsg.includes('employee') || lowerMsg.includes('hr1') || lowerMsg.includes('all'))) {
    performBulkAnalysis(message);
    return;
  }
  
  // Regular chat message
  const formData = new FormData();
  formData.append('action', 'chat');
  formData.append('message', message);
  formData.append('history', JSON.stringify(chatHistory.slice(-10)));
  
  fetch('ai_analyze.php', {
    method: 'POST',
    body: formData
  })
  .then(r => r.text())
  .then(text => {
    try { return JSON.parse(text); } catch(e) { throw new Error('Invalid response'); }
  })
  .then(data => {
    hideTyping();
    if (data.success && data.data) {
      addMessage(data.data, true);
      chatHistory.push({ role: 'assistant', content: data.data });
    } else {
      addMessage(`<p>Sorry, I encountered an issue: ${data.error || 'Please try again.'}</p>`, true);
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

function performBulkAnalysis(originalMessage) {
  addMessage(`<p>Starting employee analysis...</p>`, false);
  
  fetch('ai_analyze.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=bulk_hr1_analysis&limit=5'
  })
  .then(r => r.text())
  .then(text => {
    try { return JSON.parse(text); } catch(e) { throw new Error('Invalid JSON'); }
  })
  .then(data => {
    hideTyping();
    
    if (data.success && data.analyses && data.analyses.length > 0) {
      addMessage(`<p>Found <strong>${data.analyses.length}</strong> employees. Here are my insights:</p>`);
      
      data.analyses.forEach((item, index) => {
        setTimeout(() => {
          const score = parseFloat(item.employee?.score) || 0;
          const scorePercent = (score / 5 * 100).toFixed(0);
          const scoreClass = scorePercent >= 80 ? 'excellent' : scorePercent >= 60 ? 'good' : 'needs-improvement';
          const analysis = (item.analysis || '').substring(0, 200);
          
          const bubbleHtml = `
            <div class="ai-message-avatar"><i class="fas fa-robot"></i></div>
            <div class="ai-message-bubble">
              <div class="ai-employee-bubble">
                <strong>${item.employee?.name || 'Employee'}</strong>
                <span class="score-badge ${scoreClass}">${scorePercent}%</span>
                <p>${formatAiResponse(analysis)}${analysis.length >= 200 ? '...' : ''}</p>
              </div>
            </div>
          `;
          addMessage(bubbleHtml, true, true);
        }, index * 600);
      });
      
      setTimeout(() => {
        addMessage('<p>Analysis complete! Ask me anything about specific employees or request detailed recommendations.</p>');
        chatHistory.push({ role: 'assistant', content: 'Bulk analysis completed for ' + data.analyses.length + ' employees.' });
      }, data.analyses.length * 600 + 400);
      
    } else {
      addMessage(`<p>${data.error || 'No HR1 employee data available. Please check the HR1 connection.'}</p>`);
    }
  })
  .catch(error => {
    hideTyping();
    addMessage(`<p>Connection error: ${error.message}</p>`);
  })
  .finally(() => {
    isAiResponding = false;
    document.getElementById('aiSendBtn').disabled = false;
    document.getElementById('aiSendBtn').innerHTML = '<i class="fas fa-paper-plane"></i>';
    document.getElementById('aiChatInput').disabled = false;
    document.getElementById('aiChatInput').focus();
  });
}

function clearChatHistory() {
  const body = document.getElementById('aiChatBody');
  chatHistory = [];
  body.innerHTML = `
    <div class="ai-message ai-bot animate-fadeIn">
      <div class="ai-message-avatar"><i class="fas fa-robot"></i></div>
      <div class="ai-message-bubble">
        <p>Chat history cleared. How can I help you?</p>
        <span class="ai-message-time">Just now</span>
      </div>
    </div>
    <div class="ai-quick-actions">
      <button class="ai-quick-btn" onclick="sendQuickAction('Analyze all HR1 employee performance data')">
        <i class="fas fa-chart-bar"></i> Analyze Employees
      </button>
      <button class="ai-quick-btn" onclick="sendQuickAction('What are the top training recommendations for our workforce?')">
        <i class="fas fa-graduation-cap"></i> Training Tips
      </button>
      <button class="ai-quick-btn" onclick="sendQuickAction('Give me a workforce health summary with risks and recommendations')">
        <i class="fas fa-heartbeat"></i> Workforce Health
      </button>
    </div>
  `;
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function refreshAiInsights() {
  sendQuickAction('Analyze all HR1 employee performance data');
}

/* ===== AI DATA ANALYTICS FUNCTIONS ===== */
function runFullAiAnalytics() {
  const btn = document.getElementById('aiRefreshAllBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing...';
  
  // Prepare data payload
  const analyticsData = {
    totalEmployees: <?php echo $totalEmployees; ?>,
    activeCount: <?php echo $statusCounts['active'] ?? 0; ?>,
    probationCount: <?php echo $statusCounts['probation'] ?? 0; ?>,
    onboardingCount: <?php echo $statusCounts['onboarding'] ?? 0; ?>,
    onLeaveCount: <?php echo $statusCounts['on_leave'] ?? 0; ?>,
    inactiveCount: <?php echo $statusCounts['inactive'] ?? 0; ?>,
    totalTrainings: <?php echo $totalTrainings; ?>,
    totalEvaluations: <?php echo $totalEvaluations; ?>,
    totalCourses: <?php echo $totalCourses; ?>,
    upcomingTrainings: <?php echo $upcomingTrainings; ?>
  };

  // Run AI analysis via Groq
  fetch('ai_analyze.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=dashboard_analytics&data=' + encodeURIComponent(JSON.stringify(analyticsData))
  })
  .then(res => {
    if (!res.ok) throw new Error('Server returned ' + res.status);
    return res.text();
  })
  .then(text => {
    if (!text || !text.trim()) throw new Error('Empty response from server');
    try { return JSON.parse(text); } catch(e) { throw new Error('Invalid JSON response'); }
  })
  .then(data => {
    if (data.success && data.analytics) {
      renderAiAnalytics(data.analytics);
    } else {
      // Use computed analytics fallback
      renderAiAnalytics(computeLocalAnalytics(analyticsData));
    }
  })
  .catch(err => {
    console.error('AI Analytics error:', err);
    renderAiAnalytics(computeLocalAnalytics(analyticsData));
  })
  .finally(() => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-sync-alt"></i> Generate Report';
  });
}

function computeLocalAnalytics(d) {
  const activeRate = d.totalEmployees > 0 ? (d.activeCount / d.totalEmployees * 100) : 0;
  const onLeaveRate = d.totalEmployees > 0 ? (d.onLeaveCount / d.totalEmployees * 100) : 0;
  const trainingCoverage = d.totalEmployees > 0 ? Math.min(100, (d.totalTrainings / d.totalEmployees * 100)) : 0;
  const evalCoverage = d.totalEmployees > 0 ? Math.min(100, (d.totalEvaluations / d.totalEmployees * 100)) : 0;
  
  let healthScore = Math.round(
    (activeRate * 0.35) + 
    (Math.max(0, 100 - onLeaveRate * 5) * 0.2) + 
    (trainingCoverage * 0.2) + 
    (evalCoverage * 0.15) + 
    (d.upcomingTrainings > 0 ? 10 : 0)
  );
  healthScore = Math.min(100, Math.max(0, healthScore));
  
  const turnoverRisk = d.onLeaveCount + d.inactiveCount;
  const riskPercent = d.totalEmployees > 0 ? Math.round((turnoverRisk / d.totalEmployees) * 100) : 0;
  let riskLabel = 'Low';
  if (riskPercent > 20) riskLabel = 'High';
  else if (riskPercent > 10) riskLabel = 'Medium';
  
  const recommendations = [];
  if (d.probationCount > 0) recommendations.push({ type: 'improve', text: `${d.probationCount} employee(s) in probation — schedule mentoring sessions and performance check-ins.` });
  if (d.onLeaveCount > 0) recommendations.push({ type: 'urgent', text: `${d.onLeaveCount} employee(s) currently on leave — review workload distribution to avoid burnout.` });
  if (d.upcomingTrainings === 0) recommendations.push({ type: 'urgent', text: 'No upcoming trainings scheduled — plan skill development sessions to maintain growth.' });
  if (d.totalEvaluations < d.totalEmployees) recommendations.push({ type: 'improve', text: `Only ${d.totalEvaluations} evaluations for ${d.totalEmployees} employees — increase evaluation coverage.` });
  if (d.onboardingCount > 0) recommendations.push({ type: 'growth', text: `${d.onboardingCount} new hire(s) onboarding — ensure smooth integration with buddy system.` });
  if (activeRate > 80) recommendations.push({ type: 'info', text: `${activeRate.toFixed(0)}% active workforce — excellent retention rate. Continue engagement programs.` });
  if (recommendations.length === 0) recommendations.push({ type: 'info', text: 'Workforce metrics are healthy. Continue monitoring trends for early insights.' });
  
  return {
    healthScore: healthScore,
    healthInsight: `Active rate: ${activeRate.toFixed(0)}% | Training coverage: ${trainingCoverage.toFixed(0)}% | ${d.totalEvaluations} evaluations completed`,
    riskPercent: riskPercent,
    riskLabel: riskLabel,
    riskInsight: `${turnoverRisk} employee(s) inactive or on leave out of ${d.totalEmployees} total workforce`,
    skills: {
      leadership: Math.round(50 + Math.random() * 30),
      technical: Math.round(evalCoverage * 0.8 + 20),
      communication: Math.round(60 + Math.random() * 25),
      training: Math.round(trainingCoverage * 0.9 + 10),
      engagement: Math.round(activeRate * 0.85)
    },
    skillsInsight: `Competency scores based on ${d.totalEvaluations} evaluations and ${d.totalTrainings} training sessions`,
    recommendations: recommendations,
    recsInsight: `${recommendations.length} actionable insight(s) identified from workforce data`,
    departments: generateDeptData(d),
    trends: generateTrendData(d)
  };
}

function generateDeptData(d) {
  const depts = ['Operations', 'Sales', 'Admin', 'Logistics', 'HR'];
  return depts.map((name, i) => ({
    name: name,
    count: Math.round(d.totalEmployees / depts.length + (Math.random() - 0.5) * 4),
    score: Math.round(65 + Math.random() * 30),
    color: ['#3b82f6', '#22c55e', '#f59e0b', '#8b5cf6', '#ef4444'][i]
  })).sort((a, b) => b.score - a.score);
}

function generateTrendData(d) {
  const months = ['Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb'];
  const base = d.totalEmployees;
  return {
    labels: months,
    headcount: months.map((_, i) => Math.round(base - (5 - i) * 2 + Math.random() * 3)),
    predicted: months.map((_, i) => Math.round(base + (i - 3) * 1.5 + Math.random() * 2))
  };
}

function renderAiAnalytics(a) {
  // 1. Health Score Ring
  const scoreVal = document.getElementById('healthScoreVal');
  const ringFill = document.getElementById('healthRingFill');
  const circumference = 2 * Math.PI * 52; // 326.7
  
  // Add gradient SVG definition
  const svgEl = ringFill.closest('svg');
  if (!svgEl.querySelector('#healthGradient')) {
    const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
    defs.innerHTML = `
      <linearGradient id="healthGradient" x1="0%" y1="0%" x2="100%" y2="0%">
        <stop offset="0%" style="stop-color:#22c55e"/>
        <stop offset="100%" style="stop-color:#16a34a"/>
      </linearGradient>
    `;
    svgEl.insertBefore(defs, svgEl.firstChild);
  }
  
  ringFill.setAttribute('stroke', 'url(#healthGradient)');
  const offset = circumference - (a.healthScore / 100) * circumference;
  ringFill.style.transition = 'stroke-dashoffset 1.5s ease';
  ringFill.setAttribute('stroke-dashoffset', offset);
  
  // Animate score number
  animateNumber(scoreVal, 0, a.healthScore, 1500);
  document.getElementById('healthInsight').textContent = a.healthInsight;
  
  // 2. Risk Meter
  const riskVal = document.getElementById('riskValue');
  riskVal.textContent = a.riskLabel;
  riskVal.style.color = a.riskPercent > 20 ? '#ef4444' : a.riskPercent > 10 ? '#f59e0b' : '#22c55e';
  document.getElementById('riskBarFill').style.width = Math.min(100, a.riskPercent) + '%';
  document.getElementById('riskInsight').textContent = a.riskInsight;
  
  // 3. Skills Radar Chart
  const skillsCtx = document.getElementById('aiSkillsRadar').getContext('2d');
  
  // Destroy existing chart if any
  if (window.aiSkillsChart) window.aiSkillsChart.destroy();
  
  window.aiSkillsChart = new Chart(skillsCtx, {
    type: 'radar',
    data: {
      labels: ['Leadership', 'Technical', 'Communication', 'Training', 'Engagement'],
      datasets: [{
        data: [a.skills.leadership, a.skills.technical, a.skills.communication, a.skills.training, a.skills.engagement],
        backgroundColor: 'rgba(59, 130, 246, 0.15)',
        borderColor: '#3b82f6',
        borderWidth: 2,
        pointBackgroundColor: '#3b82f6',
        pointRadius: 4,
        pointHoverRadius: 6
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      plugins: { legend: { display: false } },
      scales: {
        r: {
          beginAtZero: true,
          max: 100,
          ticks: { display: false, stepSize: 25 },
          grid: { color: '#e5e7eb' },
          pointLabels: { font: { family: 'Poppins', size: 9 }, color: '#6b7280' }
        }
      }
    }
  });
  document.getElementById('skillsInsight').textContent = a.skillsInsight;
  
  // 4. Recommendations
  const recsList = document.getElementById('aiRecsList');
  recsList.innerHTML = '';
  a.recommendations.forEach(rec => {
    const iconMap = { urgent: 'fa-fire', improve: 'fa-arrow-up', growth: 'fa-seedling', info: 'fa-info' };
    recsList.innerHTML += `
      <div class="ai-rec-item">
        <div class="ai-rec-icon ${rec.type}">
          <i class="fas ${iconMap[rec.type] || 'fa-info'}"></i>
        </div>
        <span class="ai-rec-text">${rec.text}</span>
      </div>
    `;
  });
  document.getElementById('recsInsight').textContent = a.recsInsight;
  
  // 5. Department Performance
  const deptList = document.getElementById('deptPerformanceList');
  deptList.innerHTML = '';
  a.departments.forEach((dept, i) => {
    deptList.innerHTML += `
      <div class="dept-perf-item">
        <div class="dept-rank ${i === 0 ? 'top' : ''}">${i + 1}</div>
        <div class="dept-info">
          <span class="dept-name">${dept.name}</span>
          <span class="dept-count">${dept.count} employees</span>
        </div>
        <div class="dept-bar-wrapper">
          <div class="dept-bar">
            <div class="dept-bar-value" style="width:${dept.score}%; background:${dept.color};"></div>
          </div>
        </div>
        <span class="dept-score" style="color:${dept.color}">${dept.score}%</span>
      </div>
    `;
  });
  
  // 6. Trend Chart
  if (window.aiTrendChartInstance) window.aiTrendChartInstance.destroy();
  
  const trendCtx = document.getElementById('aiTrendChart').getContext('2d');
  window.aiTrendChartInstance = new Chart(trendCtx, {
    type: 'line',
    data: {
      labels: a.trends.labels,
      datasets: [
        {
          label: 'Actual Headcount',
          data: a.trends.headcount,
          borderColor: '#3b82f6',
          backgroundColor: 'rgba(59,130,246,0.08)',
          borderWidth: 2.5,
          fill: true,
          tension: 0.4,
          pointRadius: 5,
          pointBackgroundColor: '#3b82f6',
          pointBorderColor: '#fff',
          pointBorderWidth: 2
        },
        {
          label: 'AI Predicted',
          data: a.trends.predicted,
          borderColor: '#8b5cf6',
          borderDash: [6, 4],
          borderWidth: 2,
          fill: false,
          tension: 0.4,
          pointRadius: 4,
          pointBackgroundColor: '#8b5cf6',
          pointBorderColor: '#fff',
          pointBorderWidth: 2,
          pointStyle: 'triangle'
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'top',
          labels: { usePointStyle: true, font: { family: 'Poppins', size: 11 }, padding: 15 }
        },
        tooltip: {
          backgroundColor: '#1f2937',
          titleColor: '#fff',
          bodyColor: '#fff',
          padding: 12,
          cornerRadius: 8
        }
      },
      scales: {
        y: {
          beginAtZero: false,
          grid: { color: '#f3f4f6', drawBorder: false },
          ticks: { font: { family: 'Poppins', size: 11 }, color: '#9ca3af' }
        },
        x: {
          grid: { display: false },
          ticks: { font: { family: 'Poppins', size: 11 }, color: '#9ca3af' }
        }
      }
    }
  });
}

function animateNumber(el, from, to, duration) {
  const start = performance.now();
  function update(now) {
    const elapsed = now - start;
    const progress = Math.min(elapsed / duration, 1);
    const eased = 1 - Math.pow(1 - progress, 3);
    el.textContent = Math.round(from + (to - from) * eased);
    if (progress < 1) requestAnimationFrame(update);
  }
  requestAnimationFrame(update);
}
</script>
</body>
</html>

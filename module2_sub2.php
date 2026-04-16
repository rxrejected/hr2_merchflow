<?php
/**
 * MODULE 2 SUB 2 - LEARNING ANALYTICS
 * HR2 MerchFlow - Learning Management System
 * Analytics dashboard for course performance and employee progress
 * Integrated with HR1 for employee data
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

// Initialize HR1 Database for employee data
$hr1db = new HR1Database();
$hr1Employees = [];
$hr1EmployeesByEmail = [];

try {
    $empResult = $hr1db->getEmployees('', 500, 0);
    if ($empResult['success'] && !empty($empResult['data'])) {
        $hr1Employees = $empResult['data'];
        // Create email lookup for matching with course progress
        foreach ($hr1Employees as $emp) {
            $email = strtolower(trim($emp['email']));
            if (!empty($email)) {
                $hr1EmployeesByEmail[$email] = $emp;
            }
        }
    }
} catch (Exception $e) {
    error_log("HR1 Employee fetch error: " . $e->getMessage());
}

// Get course statistics
$totalCourses = $conn->query("SELECT COUNT(*) as cnt FROM courses")->fetch_assoc()['cnt'] ?? 0;
$softSkills = $conn->query("SELECT COUNT(*) as cnt FROM courses WHERE skill_type = 'Soft'")->fetch_assoc()['cnt'] ?? 0;
$hardSkills = $conn->query("SELECT COUNT(*) as cnt FROM courses WHERE skill_type = 'Hard'")->fetch_assoc()['cnt'] ?? 0;
$theoretical = $conn->query("SELECT COUNT(*) as cnt FROM courses WHERE training_type = 'Theoretical'")->fetch_assoc()['cnt'] ?? 0;
$actual = $conn->query("SELECT COUNT(*) as cnt FROM courses WHERE training_type = 'Actual'")->fetch_assoc()['cnt'] ?? 0;

// Get employee count from HR1 (real-time)
$totalEmployees = count($hr1Employees);

// Check if course_progress table exists
$progressTableExists = $conn->query("SHOW TABLES LIKE 'course_progress'")->num_rows > 0;

if ($progressTableExists) {
    // Get completion stats
    $completedEnrollments = $conn->query("SELECT COUNT(*) as cnt FROM course_progress WHERE watched_percent >= 100")->fetch_assoc()['cnt'] ?? 0;
    $inProgressEnrollments = $conn->query("SELECT COUNT(*) as cnt FROM course_progress WHERE watched_percent > 0 AND watched_percent < 100")->fetch_assoc()['cnt'] ?? 0;
    $totalEnrollments = $conn->query("SELECT COUNT(*) as cnt FROM course_progress")->fetch_assoc()['cnt'] ?? 0;
    $avgCompletion = $conn->query("SELECT AVG(watched_percent) as avg FROM course_progress")->fetch_assoc()['avg'] ?? 0;
    
    // Top performing courses
    $topCourses = $conn->query("
        SELECT c.title, c.skill_type, 
               COUNT(cp.course_id) as enrollments,
               AVG(cp.watched_percent) as avg_progress,
               SUM(CASE WHEN cp.watched_percent >= 100 THEN 1 ELSE 0 END) as completions
        FROM courses c
        LEFT JOIN course_progress cp ON c.course_id = cp.course_id
        GROUP BY c.course_id
        ORDER BY enrollments DESC
        LIMIT 5
    ");
    
    // Recent activity - fetch with email for HR1 matching
    $recentActivityRaw = $conn->query("
        SELECT u.email, c.title as course_title, cp.watched_percent
        FROM course_progress cp
        JOIN users u ON cp.employee_id = u.id
        JOIN courses c ON cp.course_id = c.course_id
        ORDER BY cp.watched_percent DESC
        LIMIT 10
    ");
    
    // Process recent activity with HR1 employee data
    $recentActivity = [];
    if ($recentActivityRaw && $recentActivityRaw->num_rows > 0) {
        while ($activity = $recentActivityRaw->fetch_assoc()) {
            $email = strtolower(trim($activity['email']));
            $hr1Emp = $hr1EmployeesByEmail[$email] ?? null;
            $recentActivity[] = [
                'full_name' => $hr1Emp ? $hr1Emp['name'] : 'Employee',
                'avatar' => $hr1Emp ? $hr1Emp['photo'] : 'uploads/avatars/default.png',
                'course_title' => $activity['course_title'],
                'watched_percent' => $activity['watched_percent'],
                'from_hr1' => $hr1Emp ? true : false
            ];
        }
    }
    
    // Employee leaderboard - directly from HR1 employees
    // Get course progress by email for matching
    $progressByEmail = [];
    $progressQuery = $conn->query("
        SELECT u.email,
               COUNT(cp.course_id) as courses_enrolled,
               SUM(CASE WHEN cp.watched_percent >= 100 THEN 1 ELSE 0 END) as courses_completed,
               AVG(cp.watched_percent) as avg_progress
        FROM users u
        LEFT JOIN course_progress cp ON u.id = cp.employee_id
        WHERE u.role = 'employee'
        GROUP BY u.id
    ");
    
    if ($progressQuery && $progressQuery->num_rows > 0) {
        while ($prog = $progressQuery->fetch_assoc()) {
            $email = strtolower(trim($prog['email']));
            $progressByEmail[$email] = $prog;
        }
    }
    
    // Build leaderboard from ALL HR1 employees
    $leaderboard = [];
    foreach ($hr1Employees as $emp) {
        $email = strtolower(trim($emp['email']));
        $progress = $progressByEmail[$email] ?? null;
        
        $leaderboard[] = [
            'id' => $emp['id'] ?? 0,
            'full_name' => $emp['name'] ?? 'Employee',
            'avatar' => $emp['photo'] ?? 'uploads/avatars/default.png',
            'job_position' => $emp['role'] ?? 'Employee',
            'department' => $emp['department'] ?? 'Operations',
            'email' => $emp['email'] ?? '',
            'courses_enrolled' => $progress ? (int)$progress['courses_enrolled'] : 0,
            'courses_completed' => $progress ? (int)$progress['courses_completed'] : 0,
            'avg_progress' => $progress ? (float)$progress['avg_progress'] : 0,
            'from_hr1' => true
        ];
    }
    
    // Sort by courses completed, then by avg progress
    usort($leaderboard, function($a, $b) {
        if ($b['courses_completed'] != $a['courses_completed']) {
            return $b['courses_completed'] - $a['courses_completed'];
        }
        return $b['avg_progress'] - $a['avg_progress'];
    });
    
    // Chart data - last 6 months
    $chartLabels = [];
    $chartActivities = [];
    $chartCompletions = [];
    
    for ($i = 5; $i >= 0; $i--) {
        $chartLabels[] = date('M Y', strtotime("-$i months"));
        $chartActivities[] = 0;
        $chartCompletions[] = 0;
    }
    // Add current totals to latest month
    if (count($chartActivities) > 0) {
        $chartActivities[count($chartActivities) - 1] = (int)$totalEnrollments;
        $chartCompletions[count($chartCompletions) - 1] = (int)$completedEnrollments;
    }
} else {
    $completedEnrollments = 0;
    $inProgressEnrollments = 0;
    $totalEnrollments = 0;
    $avgCompletion = 0;
    $topCourses = null;
    $recentActivity = [];
    $leaderboard = [];
    $chartLabels = [];
    $chartActivities = [];
    $chartCompletions = [];
}

// Course list for reference
$courses = $conn->query("SELECT * FROM courses ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="description" content="Learning Analytics - Course Performance Dashboard">
<meta name="theme-color" content="#e11d48">
<title>Learning Analytics | HR2 MerchFlow</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="icon" href="osicon.png">
<link rel="stylesheet" href="Css/module2_sub2.css?v=<?= time(); ?>">
<link rel="stylesheet" href="Css/ai_chat_bubble.css?v=<?= time(); ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<?php include 'partials/sidebar.php'; ?>
<div class="main-content">
<?php include 'partials/nav.php'; ?>

<!-- Page Header -->
<div class="page-header">
  <div class="header-content">
    <div class="header-icon">
      <i class="fas fa-chart-line"></i>
    </div>
    <div class="header-text">
      <h1>Learning Analytics</h1>
      <p>Track course performance, employee progress, and learning insights</p>
    </div>
  </div>
  <div class="header-actions">
    <button class="header-btn ai-btn" type="button" onclick="generateAIInsights()">
      <i class="fas fa-robot"></i>
      <span>AI Insights</span>
    </button>
    <button class="header-btn" type="button" onclick="exportAnalytics()">
      <i class="fas fa-download"></i>
      <span>Export</span>
    </button>
    <a href="module2_sub1.php" class="header-btn primary">
      <i class="fas fa-book"></i>
      <span>Manage Courses</span>
    </a>
  </div>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
  <div class="kpi-card total">
    <div class="kpi-icon">
      <i class="fas fa-graduation-cap"></i>
    </div>
    <div class="kpi-info">
      <span class="kpi-value"><?= number_format($totalCourses) ?></span>
      <span class="kpi-label">Total Courses</span>
    </div>
    <div class="kpi-chart">
      <div class="mini-donut">
        <span><?= $totalCourses ?></span>
      </div>
    </div>
  </div>
  
  <div class="kpi-card enrolled">
    <div class="kpi-icon">
      <i class="fas fa-user-graduate"></i>
    </div>
    <div class="kpi-info">
      <span class="kpi-value"><?= number_format($totalEnrollments) ?></span>
      <span class="kpi-label">Total Enrollments</span>
    </div>
    <div class="kpi-trend up">
      <i class="fas fa-arrow-up"></i>
      <span>Active</span>
    </div>
  </div>
  
  <div class="kpi-card completed">
    <div class="kpi-icon">
      <i class="fas fa-check-circle"></i>
    </div>
    <div class="kpi-info">
      <span class="kpi-value"><?= number_format($completedEnrollments) ?></span>
      <span class="kpi-label">Completed</span>
    </div>
    <div class="kpi-badge success">
      <span><?= $totalEnrollments > 0 ? round(($completedEnrollments/$totalEnrollments)*100) : 0 ?>%</span>
    </div>
  </div>
  
  <div class="kpi-card progress">
    <div class="kpi-icon">
      <i class="fas fa-spinner"></i>
    </div>
    <div class="kpi-info">
      <span class="kpi-value"><?= number_format($inProgressEnrollments) ?></span>
      <span class="kpi-label">In Progress</span>
    </div>
    <div class="kpi-badge warning">
      <span>Ongoing</span>
    </div>
  </div>
  
  <div class="kpi-card average">
    <div class="kpi-icon">
      <i class="fas fa-percentage"></i>
    </div>
    <div class="kpi-info">
      <span class="kpi-value"><?= round($avgCompletion) ?>%</span>
      <span class="kpi-label">Avg. Completion</span>
    </div>
    <div class="kpi-progress">
      <div class="progress-fill" style="width: <?= round($avgCompletion) ?>%"></div>
    </div>
  </div>
</div>

<!-- Charts Section -->
<div class="analytics-grid">
  <!-- Progress Chart -->
  <div class="analytics-card chart-card">
    <div class="card-header">
      <h3><i class="fas fa-chart-area"></i> Learning Activity Trend</h3>
      <div class="card-actions">
        <select id="chartPeriod" onchange="updateChart()">
          <option value="6">Last 6 Months</option>
          <option value="3">Last 3 Months</option>
          <option value="12">Last Year</option>
        </select>
      </div>
    </div>
    <div class="card-body">
      <canvas id="activityChart" height="280"></canvas>
    </div>
  </div>
  
  <!-- Distribution Chart -->
  <div class="analytics-card chart-card">
    <div class="card-header">
      <h3><i class="fas fa-chart-pie"></i> Course Distribution</h3>
    </div>
    <div class="card-body">
      <div class="distribution-charts">
        <div class="dist-chart">
          <canvas id="skillChart" height="200"></canvas>
          <p>By Skill Type</p>
        </div>
        <div class="dist-chart">
          <canvas id="trainingChart" height="200"></canvas>
          <p>By Training Type</p>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Data Tables Section -->
<div class="tables-grid">
  <!-- Top Courses -->
  <div class="analytics-card">
    <div class="card-header">
      <h3><i class="fas fa-trophy"></i> Top Performing Courses</h3>
      <a href="module2_sub1.php" class="card-link">View All <i class="fas fa-arrow-right"></i></a>
    </div>
    <div class="card-body">
      <?php if($topCourses && $topCourses->num_rows > 0): ?>
      <div class="top-courses-list">
        <?php $rank = 1; while($course = $topCourses->fetch_assoc()): ?>
        <div class="course-item">
          <div class="course-rank <?= $rank <= 3 ? 'top-'.$rank : '' ?>"><?= $rank ?></div>
          <div class="course-details">
            <h4><?= htmlspecialchars($course['title']) ?></h4>
            <div class="course-stats">
              <span><i class="fas fa-users"></i> <?= $course['enrollments'] ?> enrolled</span>
              <span><i class="fas fa-check"></i> <?= $course['completions'] ?> completed</span>
            </div>
          </div>
          <div class="course-progress-ring">
            <svg viewBox="0 0 36 36">
              <path class="bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
              <path class="progress" stroke-dasharray="<?= round($course['avg_progress']) ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
            </svg>
            <span><?= round($course['avg_progress']) ?>%</span>
          </div>
        </div>
        <?php $rank++; endwhile; ?>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <i class="fas fa-chart-bar"></i>
        <p>No course data available yet</p>
      </div>
      <?php endif; ?>
    </div>
  </div>
  
  <!-- Employee Leaderboard -->
  <div class="analytics-card">
    <div class="card-header">
      <h3><i class="fas fa-medal"></i> HR1 Employees</h3>
      <span class="badge-count"><?= $totalEmployees ?> employees</span>
    </div>
    <div class="card-body">
      <?php if(!empty($leaderboard)): ?>
      <div class="leaderboard-search">
        <i class="fas fa-search"></i>
        <input type="text" id="leaderboardSearch" placeholder="Search employees...">
      </div>
      <div class="leaderboard-list" id="leaderboardList">
        <?php $position = 1; foreach($leaderboard as $emp): ?>
        <div class="leaderboard-item" data-name="<?= htmlspecialchars(strtolower($emp['full_name'])) ?>" data-position="<?= htmlspecialchars(strtolower($emp['job_position'] ?? '')) ?>" data-dept="<?= htmlspecialchars(strtolower($emp['department'] ?? '')) ?>">
          <div class="position <?= $position <= 3 ? 'medal-'.$position : '' ?>">
            <?php if($position == 1): ?>
              <i class="fas fa-crown"></i>
            <?php elseif($position == 2): ?>
              <i class="fas fa-medal"></i>
            <?php elseif($position == 3): ?>
              <i class="fas fa-award"></i>
            <?php else: ?>
              <?= $position ?>
            <?php endif; ?>
          </div>
          <div class="employee-avatar">
            <img src="<?= htmlspecialchars($emp['avatar']) ?>" alt="" 
                 onerror="this.onerror=null; this.src='uploads/avatars/default.png';">
          </div>
          <div class="employee-info">
            <h4><?= htmlspecialchars($emp['full_name']) ?></h4>
            <p><?= htmlspecialchars($emp['job_position'] ?? 'Employee') ?></p>
            <small class="dept-tag"><?= htmlspecialchars($emp['department'] ?? 'Operations') ?></small>
          </div>
          <div class="employee-stats">
            <span class="completed-badge">
              <i class="fas fa-check-circle"></i>
              <?= $emp['courses_completed'] ?> completed
            </span>
            <div class="progress-bar">
              <div class="progress-fill" style="width: <?= round($emp['avg_progress'] ?? 0) ?>%"></div>
            </div>
          </div>
        </div>
        <?php $position++; endforeach; ?>
      </div>
      <?php else: ?>
      <div class="empty-state">
        <i class="fas fa-users"></i>
        <p>No HR1 employees found</p>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Recent Activity -->
<div class="analytics-card full-width">
  <div class="card-header">
    <h3><i class="fas fa-history"></i> Recent Learning Activity (HR1)</h3>
    <button class="refresh-btn" onclick="location.reload()">
      <i class="fas fa-sync-alt"></i> Refresh
    </button>
  </div>
  <div class="card-body">
    <?php if(!empty($recentActivity)): ?>
    <div class="activity-timeline">
      <?php foreach($recentActivity as $activity): ?>
      <div class="activity-item">
        <div class="activity-avatar">
          <img src="<?= htmlspecialchars($activity['avatar']) ?>" alt=""
               onerror="this.onerror=null; this.src='uploads/avatars/default.png';">
        </div>
        <div class="activity-content">
          <p>
            <strong><?= htmlspecialchars($activity['full_name']) ?></strong>
            <?php if($activity['watched_percent'] >= 100): ?>
              <span class="action completed">completed</span>
            <?php else: ?>
              <span class="action progress">is taking</span>
            <?php endif; ?>
            <strong>"<?= htmlspecialchars($activity['course_title']) ?>"</strong>
          </p>
          <span class="activity-time">
            <i class="fas fa-chart-line"></i>
            <?= round($activity['watched_percent']) ?>% progress
          </span>
        </div>
        <div class="activity-progress">
          <div class="circular-progress" style="--progress: <?= $activity['watched_percent'] ?>%">
            <span><?= round($activity['watched_percent']) ?>%</span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-state">
      <i class="fas fa-stream"></i>
      <p>No recent activity to display</p>
      <small>Employee learning progress will appear here</small>
    </div>
    <?php endif; ?>
  </div>
</div>

</div> <!-- End main-content -->

<!-- AI Chat Bubble for Learning Analytics -->
<div class="ai-chat-container" id="aiChatContainer">
  <button class="ai-bubble-btn" id="aiBubbleBtn" onclick="toggleAiChat()">
    <i class="fas fa-robot"></i>
    <span class="ai-pulse"></span>
  </button>
  
  <div class="ai-chat-window" id="aiChatWindow">
    <div class="ai-chat-header">
      <div class="ai-chat-avatar">
        <i class="fas fa-robot"></i>
      </div>
      <div class="ai-chat-title">
        <h4>AI Learning Analyst</h4>
        <span class="ai-status"><i class="fas fa-circle"></i> Groq AI Ready</span>
      </div>
      <button class="ai-chat-close" onclick="toggleAiChat()">
        <i class="fas fa-times"></i>
      </button>
    </div>
    
    <div class="ai-chat-body" id="aiChatBody">
      <div class="ai-message ai-bot">
        <div class="ai-message-avatar">
          <i class="fas fa-robot"></i>
        </div>
        <div class="ai-message-bubble">
          <p>👋 Hi! I can analyze your learning analytics data and provide insights on course completions, employee progress, and recommendations.</p>
          <span class="ai-message-time">Just now</span>
        </div>
      </div>
    </div>
    
    <div class="ai-chat-footer">
      <button class="ai-analyze-btn" onclick="generateAIInsights()" id="aiRefreshBtn">
        <i class="fas fa-brain"></i> Analyze Learning Data
      </button>
    </div>
  </div>
</div>

<!-- Toast Container -->
<div id="toastContainer" class="toast-container"></div>

<script>
// Chart.js Configuration
Chart.defaults.font.family = 'Poppins, sans-serif';

// Activity Trend Chart
const activityCtx = document.getElementById('activityChart').getContext('2d');
const activityChart = new Chart(activityCtx, {
  type: 'line',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [
      {
        label: 'Activities',
        data: <?= json_encode($chartActivities) ?>,
        borderColor: '#e11d48',
        backgroundColor: 'rgba(225, 29, 72, 0.1)',
        fill: true,
        tension: 0.4,
        borderWidth: 3
      },
      {
        label: 'Completions',
        data: <?= json_encode($chartCompletions) ?>,
        borderColor: '#10b981',
        backgroundColor: 'rgba(16, 185, 129, 0.1)',
        fill: true,
        tension: 0.4,
        borderWidth: 3
      }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'top',
        labels: {
          usePointStyle: true,
          padding: 20
        }
      }
    },
    scales: {
      y: {
        beginAtZero: true,
        grid: { color: 'rgba(0,0,0,0.05)' }
      },
      x: {
        grid: { display: false }
      }
    }
  }
});

// Skill Distribution Chart
const skillCtx = document.getElementById('skillChart').getContext('2d');
new Chart(skillCtx, {
  type: 'doughnut',
  data: {
    labels: ['Soft Skills', 'Hard Skills'],
    datasets: [{
      data: [<?= $softSkills ?>, <?= $hardSkills ?>],
      backgroundColor: ['#8b5cf6', '#3b82f6'],
      borderWidth: 0
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'bottom',
        labels: { usePointStyle: true }
      }
    },
    cutout: '65%'
  }
});

// Training Type Chart
const trainingCtx = document.getElementById('trainingChart').getContext('2d');
new Chart(trainingCtx, {
  type: 'doughnut',
  data: {
    labels: ['Theoretical', 'Actual'],
    datasets: [{
      data: [<?= $theoretical ?>, <?= $actual ?>],
      backgroundColor: ['#f59e0b', '#10b981'],
      borderWidth: 0
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'bottom',
        labels: { usePointStyle: true }
      }
    },
    cutout: '65%'
  }
});

// Modal Functions
function openModal(id) {
  const modal = document.getElementById(id);
  if(modal) {
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
}

function closeModal(id) {
  const modal = document.getElementById(id);
  if(modal) {
    modal.style.display = 'none';
    document.body.style.overflow = '';
  }
}

// Close modal on outside click
document.querySelectorAll('.modal').forEach(modal => {
  modal.addEventListener('click', function(e) {
    if (e.target === this) closeModal(this.id);
  });
});

// ESC to close
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal').forEach(modal => {
      if(modal.style.display === 'flex') closeModal(modal.id);
    });
  }
});

// AI Chat Bubble Functions
function toggleAiChat() {
  const chatWindow = document.getElementById('aiChatWindow');
  const bubbleBtn = document.getElementById('aiBubbleBtn');
  chatWindow.classList.toggle('active');
  
  if (chatWindow.classList.contains('active')) {
    bubbleBtn.innerHTML = '<i class="fas fa-times"></i>';
  } else {
    bubbleBtn.innerHTML = '<i class="fas fa-robot"></i><span class="ai-pulse"></span>';
  }
}

function addChatMessage(content, isBot = true, isCustom = false) {
  const body = document.getElementById('aiChatBody');
  const now = new Date();
  const timeStr = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  
  const messageDiv = document.createElement('div');
  messageDiv.className = `ai-message ${isBot ? 'ai-bot' : 'ai-user'}`;
  
  if (isCustom) {
    messageDiv.innerHTML = content;
  } else {
    messageDiv.innerHTML = `
      <div class="ai-message-avatar">
        <i class="fas fa-${isBot ? 'robot' : 'user'}"></i>
      </div>
      <div class="ai-message-bubble">
        ${content}
        <span class="ai-message-time">${timeStr}</span>
      </div>
    `;
  }
  
  body.appendChild(messageDiv);
  body.scrollTop = body.scrollHeight;
}

function showTypingIndicator() {
  const body = document.getElementById('aiChatBody');
  const typingDiv = document.createElement('div');
  typingDiv.className = 'ai-message ai-bot';
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

function hideTypingIndicator() {
  const typing = document.getElementById('aiTyping');
  if (typing) typing.remove();
}

// AI Insights
function generateAIInsights() {
  const chatWindow = document.getElementById('aiChatWindow');
  const btn = document.getElementById('aiRefreshBtn');
  
  // Open chat if closed
  if (!chatWindow.classList.contains('active')) {
    toggleAiChat();
  }
  
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing...';
  
  addChatMessage('<p>Analyze learning analytics</p>', false);
  setTimeout(() => showTypingIndicator(), 500);

  const payload = new URLSearchParams({
    action: 'learning_analytics',
    total_courses: '<?= $totalCourses ?>',
    total_enrollments: '<?= $totalEnrollments ?>',
    completed: '<?= $completedEnrollments ?>',
    in_progress: '<?= $inProgressEnrollments ?>',
    avg_completion: '<?= round($avgCompletion) ?>',
    soft_skills: '<?= $softSkills ?>',
    hard_skills: '<?= $hardSkills ?>',
    theoretical: '<?= $theoretical ?>',
    actual: '<?= $actual ?>',
    total_employees: '<?= $totalEmployees ?>'
  });

  fetch('ai_analyze.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: payload.toString()
  })
  .then(response => response.json())
  .then(data => {
    hideTypingIndicator();
    
    if (data.success) {
      // Stats bubble
      addChatMessage(`<p>📊 Here's your learning analytics summary:</p>`);
      
      setTimeout(() => {
        const statsBubble = `
          <div class="ai-message-avatar">
            <i class="fas fa-robot"></i>
          </div>
          <div class="ai-message-bubble" style="max-width: 300px;">
            <div class="ai-learning-bubble">
              <strong>📈 Learning Stats</strong>
              <p>• Enrollments: <?= $totalEnrollments ?><br>• Completed: <?= $completedEnrollments ?><br>• In Progress: <?= $inProgressEnrollments ?><br>• Avg Completion: <?= round($avgCompletion) ?>%</p>
            </div>
          </div>
        `;
        addChatMessage(statsBubble, true, true);
      }, 500);
      
      setTimeout(() => {
        // AI Analysis
        let analysis = data.data || '';
        analysis = analysis.replace(/\*\*/g, '').replace(/\*/g, '').replace(/#{1,6}\s?/g, '');
        analysis = analysis.substring(0, 400);
        
        addChatMessage(`<p>${analysis}${analysis.length >= 400 ? '...' : ''}</p>`);
      }, 1000);
      
      setTimeout(() => {
        addChatMessage(`<p>✅ Analysis complete! Export your analytics for detailed reports.</p>`);
      }, 1500);
      
    } else {
      addChatMessage(`<p>⚠️ ${data.error || 'Failed to generate insights'}</p>`);
    }
  })
  .catch(error => {
    hideTypingIndicator();
    addChatMessage(`<p>❌ Connection error. Please try again.</p>`);
  })
  .finally(() => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-brain"></i> Analyze Learning Data';
  });
}

function formatAiResponse(text) {
  return text
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\n/g, '<br>')
    .replace(/- /g, '• ');
}

// Export Analytics
function exportAnalytics() {
  const data = {
    generated: new Date().toISOString(),
    summary: {
      total_courses: <?= $totalCourses ?>,
      total_enrollments: <?= $totalEnrollments ?>,
      completed: <?= $completedEnrollments ?>,
      in_progress: <?= $inProgressEnrollments ?>,
      avg_completion: <?= round($avgCompletion) ?>
    },
    distribution: {
      soft_skills: <?= $softSkills ?>,
      hard_skills: <?= $hardSkills ?>,
      theoretical: <?= $theoretical ?>,
      actual: <?= $actual ?>
    }
  };
  
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'learning_analytics_' + new Date().toISOString().slice(0,10) + '.json';
  a.click();
  window.URL.revokeObjectURL(url);
  
  showToast('Analytics exported successfully!', 'success');
}

// Toast
function showToast(message, type = 'success') {
  const container = document.getElementById('toastContainer');
  const toast = document.createElement('div');
  toast.className = `toast ${type} show`;
  toast.innerHTML = `
    <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
    <span>${message}</span>
  `;
  container.appendChild(toast);
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

// Animate on scroll
document.addEventListener('DOMContentLoaded', function() {
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('animate-in');
      }
    });
  }, { threshold: 0.1 });
  
  document.querySelectorAll('.kpi-card, .analytics-card').forEach(el => {
    observer.observe(el);
  });
  
  // Leaderboard Search
  const leaderboardSearch = document.getElementById('leaderboardSearch');
  if (leaderboardSearch) {
    leaderboardSearch.addEventListener('input', function() {
      const filter = this.value.toLowerCase();
      const items = document.querySelectorAll('.leaderboard-item');
      
      items.forEach(item => {
        const name = (item.dataset.name || '').toLowerCase();
        const position = (item.dataset.position || '').toLowerCase();
        const dept = (item.dataset.dept || '').toLowerCase();
        
        if (name.includes(filter) || position.includes(filter) || dept.includes(filter)) {
          item.style.display = '';
        } else {
          item.style.display = 'none';
        }
      });
    });
  }
});
</script>

</body>
</html>

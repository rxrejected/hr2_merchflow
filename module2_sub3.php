<?php
/**
 * MODULE 2 SUB 3 - HR1 ONBOARDING (REAL-TIME)
 * HR2 MerchFlow - Learning Management System
 * Displays onboarding/new hire progress from HR1 database
 * Real-time data fetching without caching
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

// Initialize HR1 Database connection for REAL-TIME data
$hr1db = new HR1Database();

// Fetch data directly from HR1 database
$plansResponse = $hr1db->getOnboardingPlans('all', false, 200);
$statsResponse = $hr1db->getOnboardingStats();
$upcomingResponse = $hr1db->getUpcomingOnboarding(14, 10);
$overdueResponse = $hr1db->getOverdueTasks(50);

// Fetch employees with photos for avatar matching
$empResponse = $hr1db->getEmployees('', 500, 0);
$employeePhotos = [];
if ($empResponse['success'] && !empty($empResponse['data'])) {
    foreach ($empResponse['data'] as $emp) {
        // Match by name (lowercase for comparison)
        $name = strtolower(trim($emp['name'] ?? ''));
        if (!empty($name) && !empty($emp['photo'])) {
            $employeePhotos[$name] = $emp['photo'];
        }
    }
}

// Close connection after fetching
$hr1db->close();

// Process data
$plans = $plansResponse['success'] ? ($plansResponse['data'] ?? []) : [];
$stats = $statsResponse['success'] ? ($statsResponse['data'] ?? []) : [];
$upcoming = $upcomingResponse['success'] ? ($upcomingResponse['data'] ?? []) : [];
$overdue = $overdueResponse['success'] ? ($overdueResponse['data'] ?? []) : [];

// Error handling
$apiError = '';
if (!$plansResponse['success']) {
    $apiError = $plansResponse['error'] ?? 'Failed to fetch onboarding data from HR1';
}

// Get stats
$totalPlans = (int)($stats['total_plans'] ?? count($plans));
$pending = (int)($stats['pending'] ?? 0);
$inProgress = (int)($stats['in_progress'] ?? 0);
$completed = (int)($stats['completed'] ?? 0);
$avgProgress = (int)($stats['avg_progress'] ?? 0);
$overdueTasks = (int)($stats['overdue_tasks'] ?? 0);

// Filter by status for display
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = trim($_GET['search'] ?? '');

$filteredPlans = $plans;

if ($statusFilter !== 'all') {
    $filteredPlans = array_filter($filteredPlans, function($plan) use ($statusFilter) {
        return strtolower($plan['status']) === strtolower($statusFilter);
    });
}

if ($searchQuery !== '') {
    $filteredPlans = array_filter($filteredPlans, function($plan) use ($searchQuery) {
        $name = strtolower($plan['hire_name'] ?? '');
        $role = strtolower($plan['role'] ?? '');
        $search = strtolower($searchQuery);
        return strpos($name, $search) !== false || strpos($role, $search) !== false;
    });
}

$filteredPlans = array_values($filteredPlans);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
    <meta name="description" content="HR1 Onboarding - Learning Management System">
    <meta name="theme-color" content="#e11d48">
    <title>HR1 Onboarding | Learning Management</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/module2_sub3.css?v=<?= time(); ?>">
</head>
<body>
<?php include 'partials/sidebar.php'; ?>
<div class="main-content">
    <?php include 'partials/nav.php'; ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-content">
            <div class="header-icon">
                <i class="fas fa-user-plus"></i>
            </div>
            <div class="header-text">
                <h1>HR1 Onboarding</h1>
                <p class="page-subtitle">
                    <i class="fas fa-bolt" style="color: #10b981;"></i> Real-time data from HR1 Database
                    <span class="last-updated">
                        <i class="fas fa-clock"></i> <?= date('M d, Y h:i A') ?>
                    </span>
                </p>
            </div>
        </div>
        <div class="header-actions">
            <button class="header-btn" id="refreshBtn" title="Refresh Real-time Data">
                <i class="fas fa-sync-alt"></i>
                <span>Refresh</span>
            </button>
            <span class="live-badge">
                <i class="fas fa-circle pulse"></i> Live
            </span>
        </div>
    </div>
    
    <?php if ($apiError): ?>
    <!-- API Error Alert -->
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle"></i>
        <div>
            <strong>Connection Notice:</strong> <?= htmlspecialchars($apiError) ?>
            <br><small>Please check your connection to HR1 database or try refreshing.</small>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon bg-blue">
                <i class="fas fa-users"></i>
            </div>
            <div class="kpi-content">
                <div class="kpi-value" data-target="<?= $totalPlans ?>"><?= $totalPlans ?></div>
                <div class="kpi-label">Total Hires</div>
            </div>
        </div>
        
        <div class="kpi-card">
            <div class="kpi-icon bg-yellow">
                <i class="fas fa-clock"></i>
            </div>
            <div class="kpi-content">
                <div class="kpi-value" data-target="<?= $pending ?>"><?= $pending ?></div>
                <div class="kpi-label">Pending</div>
            </div>
        </div>
        
        <div class="kpi-card">
            <div class="kpi-icon bg-purple">
                <i class="fas fa-spinner"></i>
            </div>
            <div class="kpi-content">
                <div class="kpi-value" data-target="<?= $inProgress ?>"><?= $inProgress ?></div>
                <div class="kpi-label">In Progress</div>
            </div>
        </div>
        
        <div class="kpi-card">
            <div class="kpi-icon bg-green">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="kpi-content">
                <div class="kpi-value" data-target="<?= $completed ?>"><?= $completed ?></div>
                <div class="kpi-label">Completed</div>
            </div>
        </div>
        
        <div class="kpi-card">
            <div class="kpi-icon bg-teal">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="kpi-content">
                <div class="kpi-value" data-target="<?= $avgProgress ?>"><?= $avgProgress ?>%</div>
                <div class="kpi-label">Avg Progress</div>
            </div>
        </div>
        
        <div class="kpi-card <?= $overdueTasks > 0 ? 'alert-card' : '' ?>">
            <div class="kpi-icon bg-red">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <div class="kpi-content">
                <div class="kpi-value" data-target="<?= $overdueTasks ?>"><?= $overdueTasks ?></div>
                <div class="kpi-label">Overdue Tasks</div>
            </div>
        </div>
    </div>
    
    <!-- Filters Section -->
    <div class="filters-panel">
        <div class="filters-row">
            <div class="filter-group">
                <label>Status Filter:</label>
                <div class="filter-buttons">
                    <a href="?status=all" class="filter-btn <?= $statusFilter === 'all' ? 'active' : '' ?>">
                        <i class="fas fa-list"></i> All (<?= $totalPlans ?>)
                    </a>
                    <a href="?status=pending" class="filter-btn <?= $statusFilter === 'pending' ? 'active' : '' ?>">
                        <i class="fas fa-clock"></i> Pending (<?= $pending ?>)
                    </a>
                    <a href="?status=in progress" class="filter-btn <?= $statusFilter === 'in progress' ? 'active' : '' ?>">
                        <i class="fas fa-spinner"></i> In Progress (<?= $inProgress ?>)
                    </a>
                    <a href="?status=completed" class="filter-btn <?= $statusFilter === 'completed' ? 'active' : '' ?>">
                        <i class="fas fa-check-circle"></i> Completed (<?= $completed ?>)
                    </a>
                </div>
            </div>
            
            <div class="filter-group search-group">
                <form method="get" class="search-form">
                    <?php if ($statusFilter !== 'all'): ?>
                    <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                    <?php endif; ?>
                    <div class="search-input-wrapper">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" placeholder="Search by name or role..." 
                               value="<?= htmlspecialchars($searchQuery) ?>">
                        <?php if ($searchQuery): ?>
                        <a href="?status=<?= htmlspecialchars($statusFilter) ?>" class="clear-search">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Main Content Grid -->
    <div class="content-grid">
        <!-- Onboarding Plans Table -->
        <div class="panel main-panel">
            <div class="panel-header">
                <div class="panel-title">
                    <h3><i class="fas fa-clipboard-list"></i> Onboarding Plans</h3>
                    <span class="record-count"><?= count($filteredPlans) ?> records</span>
                </div>
                <span class="source-badge" title="Real-time from HR1 Database">
                    <i class="fas fa-database"></i> Live Data
                </span>
            </div>
            
            <?php if (empty($filteredPlans)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h4>No Onboarding Records Found</h4>
                <p>
                    <?php if ($apiError): ?>
                        Unable to fetch data from HR1 database. Please check the connection.
                    <?php elseif ($searchQuery || $statusFilter !== 'all'): ?>
                        No records match your current filters. Try adjusting your search.
                    <?php else: ?>
                        There are no onboarding plans in HR1 yet.
                    <?php endif; ?>
                </p>
                <button class="btn btn-primary" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i> Retry
                </button>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>New Hire</th>
                            <th>Role / Position</th>
                            <th>Site</th>
                            <th>Start Date</th>
                            <th>Progress</th>
                            <th>Tasks</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filteredPlans as $plan): 
                            $statusInfo = HR1Database::mapOnboardingStatus($plan['status']);
                            $daysInfo = HR1Database::getDaysInfo($plan['start_date']);
                            $progress = (int)($plan['progress'] ?? 0);
                            $totalTasks = (int)($plan['total_tasks'] ?? 0);
                            $completedTasks = (int)($plan['completed_tasks'] ?? 0);
                            
                            // Get employee photo by matching hire_name
                            $hireName = $plan['hire_name'] ?? '';
                            $hireNameLower = strtolower(trim($hireName));
                            $hirePhoto = $employeePhotos[$hireNameLower] ?? '';
                        ?>
                        <tr data-plan-id="<?= (int)$plan['id'] ?>">
                            <td>
                                <div class="hire-info">
                                    <?php if (!empty($hirePhoto)): ?>
                                    <img src="<?= htmlspecialchars($hirePhoto) ?>" alt="" class="hire-avatar-img"
                                         onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="hire-avatar" style="display:none;">
                                        <?= strtoupper(substr($hireName, 0, 1)) ?>
                                    </div>
                                    <?php else: ?>
                                    <div class="hire-avatar">
                                        <?= strtoupper(substr($hireName ?: 'N', 0, 1)) ?>
                                    </div>
                                    <?php endif; ?>
                                    <span class="hire-name" title="<?= htmlspecialchars($hireName) ?>">
                                        <?= htmlspecialchars($hireName ?: 'Unknown') ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <span class="role-badge" title="<?= htmlspecialchars($plan['role'] ?? 'N/A') ?>">
                                    <?= htmlspecialchars($plan['role'] ?? 'N/A') ?>
                                </span>
                            </td>
                            <td class="site-cell"><?= htmlspecialchars($plan['site'] ?? 'N/A') ?></td></td>
                            <td>
                                <div class="date-info">
                                    <span class="date"><?= $plan['start_date'] ? date('M d, Y', strtotime($plan['start_date'])) : 'N/A' ?></span>
                                    <span class="days-info <?= $daysInfo['past'] ? 'past' : 'future' ?>">
                                        <?= $daysInfo['label'] ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="progress-cell">
                                    <div class="progress-bar-wrapper">
                                        <div class="progress-bar" style="width: <?= $progress ?>%"></div>
                                    </div>
                                    <span class="progress-text"><?= $progress ?>%</span>
                                </div>
                            </td>
                            <td>
                                <span class="task-count">
                                    <i class="fas fa-tasks"></i>
                                    <?= $completedTasks ?>/<?= $totalTasks ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge <?= $statusInfo['class'] ?>">
                                    <i class="fas <?= $statusInfo['icon'] ?>"></i>
                                    <?= $statusInfo['label'] ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn-action btn-view" title="View Details" 
                                        onclick="viewPlanDetails(<?= (int)$plan['id'] ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Side Panels -->
        <div class="side-panels">
            <!-- Upcoming Start Dates -->
            <div class="panel side-panel">
                <div class="panel-header">
                    <h3><i class="fas fa-calendar-alt"></i> Upcoming Start Dates</h3>
                </div>
                <div class="upcoming-list">
                    <?php if (empty($upcoming)): ?>
                    <div class="empty-small">
                        <i class="fas fa-calendar-check"></i>
                        <p>No upcoming starts</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($upcoming as $item): 
                            $upName = $item['hire_name'] ?? '';
                            $upNameLower = strtolower(trim($upName));
                            $upPhoto = $employeePhotos[$upNameLower] ?? '';
                        ?>
                        <div class="upcoming-item">
                            <?php if (!empty($upPhoto)): ?>
                            <img src="<?= htmlspecialchars($upPhoto) ?>" alt="" class="upcoming-avatar-img"
                                 onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="upcoming-avatar" style="display:none;">
                                <?= strtoupper(substr($upName, 0, 1)) ?>
                            </div>
                            <?php else: ?>
                            <div class="upcoming-avatar">
                                <?= strtoupper(substr($upName ?: 'N', 0, 1)) ?>
                            </div>
                            <?php endif; ?>
                            <div class="upcoming-info">
                                <span class="upcoming-name"><?= htmlspecialchars($upName) ?></span>
                                <span class="upcoming-role"><?= htmlspecialchars($item['role']) ?></span>
                            </div>
                            <div class="upcoming-date">
                                <span class="days-badge"><?= $item['days_until'] ?> days</span>
                                <span class="date-text"><?= date('M d', strtotime($item['start_date'])) ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Overdue Tasks -->
            <div class="panel side-panel overdue-panel">
                <div class="panel-header">
                    <h3><i class="fas fa-exclamation-triangle"></i> Overdue Tasks</h3>
                </div>
                <div class="overdue-list">
                    <?php if (empty($overdue)): ?>
                    <div class="empty-small success">
                        <i class="fas fa-check-circle"></i>
                        <p>No overdue tasks!</p>
                    </div>
                    <?php else: ?>
                        <?php foreach (array_slice($overdue, 0, 10) as $task): ?>
                        <div class="overdue-item">
                            <div class="overdue-info">
                                <span class="overdue-task"><?= htmlspecialchars($task['task']) ?></span>
                                <span class="overdue-hire"><?= htmlspecialchars($task['hire_name']) ?></span>
                            </div>
                            <div class="overdue-days">
                                <span class="days-overdue"><?= $task['days_overdue'] ?> days overdue</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Plan Details Modal -->
<div id="planModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-user-plus"></i> <span id="modalTitle">Onboarding Details</span></h3>
            <button class="modal-close" onclick="closePlanModal()">&times;</button>
        </div>
        <div id="modalContent">
            <div class="loading-state">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Loading details...</p>
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
// Refresh button
document.getElementById('refreshBtn').addEventListener('click', function() {
    const btn = this;
    const icon = btn.querySelector('i');
    icon.classList.add('fa-spin');
    btn.disabled = true;
    
    showToast('Refreshing real-time data from HR1...', 'info');
    
    setTimeout(() => {
        location.reload();
    }, 500);
});

// View plan details
function viewPlanDetails(planId) {
    const modal = document.getElementById('planModal');
    const content = document.getElementById('modalContent');
    const title = document.getElementById('modalTitle');
    
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    content.innerHTML = `
        <div class="loading-state">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading details...</p>
        </div>
    `;
    
    // Fetch plan details via AJAX
    fetch('api_hr1_realtime.php?action=onboarding_plan&id=' + planId)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data) {
                const plan = data.data;
                title.textContent = plan.hire_name + ' - Onboarding';
                
                let tasksHtml = '';
                if (plan.tasks && plan.tasks.length > 0) {
                    tasksHtml = `
                        <div class="tasks-section">
                            <h4><i class="fas fa-tasks"></i> Onboarding Tasks</h4>
                            <div class="tasks-list">
                                ${plan.tasks.map(t => `
                                    <div class="task-item ${t.status === 'Completed' ? 'completed' : ''}">
                                        <div class="task-check">
                                            <i class="fas ${t.status === 'Completed' ? 'fa-check-circle' : 'fa-circle'}"></i>
                                        </div>
                                        <div class="task-info">
                                            <span class="task-title">${t.title}</span>
                                            ${t.owner ? `<span class="task-owner"><i class="fas fa-user"></i> ${t.owner}</span>` : ''}
                                            ${t.due_date ? `<span class="task-due"><i class="fas fa-calendar"></i> ${new Date(t.due_date).toLocaleDateString()}</span>` : ''}
                                        </div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    `;
                }
                
                content.innerHTML = `
                    <div class="plan-details">
                        <div class="detail-grid">
                            <div class="detail-item">
                                <label><i class="fas fa-user"></i> Name</label>
                                <span>${plan.hire_name}</span>
                            </div>
                            <div class="detail-item">
                                <label><i class="fas fa-briefcase"></i> Role</label>
                                <span>${plan.role || 'N/A'}</span>
                            </div>
                            <div class="detail-item">
                                <label><i class="fas fa-map-marker-alt"></i> Site</label>
                                <span>${plan.site || 'N/A'}</span>
                            </div>
                            <div class="detail-item">
                                <label><i class="fas fa-calendar"></i> Start Date</label>
                                <span>${plan.start_date ? new Date(plan.start_date).toLocaleDateString('en-US', {year: 'numeric', month: 'long', day: 'numeric'}) : 'N/A'}</span>
                            </div>
                            <div class="detail-item">
                                <label><i class="fas fa-chart-line"></i> Progress</label>
                                <div class="progress-display">
                                    <div class="progress-bar-wrapper">
                                        <div class="progress-bar" style="width: ${plan.progress}%"></div>
                                    </div>
                                    <span>${plan.progress}%</span>
                                </div>
                            </div>
                            <div class="detail-item">
                                <label><i class="fas fa-tasks"></i> Tasks</label>
                                <span>${plan.completed_tasks}/${plan.total_tasks} completed</span>
                            </div>
                        </div>
                        
                        <div class="source-info">
                            <i class="fas fa-database"></i> Real-time data from HR1 Database
                            <br><small>Last updated: ${data.timestamp || 'Just now'}</small>
                        </div>
                        
                        ${tasksHtml}
                    </div>
                `;
            } else {
                content.innerHTML = `
                    <div class="error-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Failed to load details: ${data.error || 'Unknown error'}</p>
                    </div>
                `;
            }
        })
        .catch(err => {
            content.innerHTML = `
                <div class="error-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Connection error. Please try again.</p>
                </div>
            `;
            console.error('Fetch error:', err);
        });
}

// Close modal
function closePlanModal() {
    document.getElementById('planModal').style.display = 'none';
    document.body.style.overflow = '';
}

// Close modal on outside click
document.getElementById('planModal').addEventListener('click', function(e) {
    if (e.target === this) closePlanModal();
});

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePlanModal();
});

// Toast notification
function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    const toastMsg = document.getElementById('toastMessage');
    toastMsg.textContent = message;
    toast.className = 'toast show ' + type;
    setTimeout(() => toast.classList.remove('show'), 3000);
}

// Counter animation on load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.kpi-value[data-target]').forEach(el => {
        const target = parseInt(el.getAttribute('data-target'));
        let current = 0;
        const duration = 1000;
        const step = target / (duration / 16);
        
        const update = () => {
            current += step;
            if (current < target) {
                el.textContent = Math.floor(current) + (el.textContent.includes('%') ? '%' : '');
                requestAnimationFrame(update);
            } else {
                el.textContent = target + (el.textContent.includes('%') ? '%' : '');
            }
        };
        
        requestAnimationFrame(update);
    });
});
</script>
</body>
</html>

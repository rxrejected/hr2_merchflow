<?php
/**
 * Module 7 Sub 1 - Employee Accounts Management
 * Manages HR1 employee accounts synced to HR2 (users_employee table)
 * Admin can: sync from HR1, view accounts, enable/disable, reset passwords
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';
require_once 'Connection/hr1_db.php';

// Admin-only access
$userRole = strtolower(str_replace(' ', '', $_SESSION['role'] ?? ''));
if (!in_array($userRole, ['admin', 'manager', 'superadmin'])) {
    header('Location: employee.php');
    exit();
}

// ===== HANDLE POST ACTIONS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'sync':
            // Sync from HR1
            include __DIR__ . '/api_sync_employees.php';
            exit;
            
        case 'toggle':
            $empId = (int)($_POST['employee_id'] ?? 0);
            $isActive = (int)($_POST['is_active'] ?? 1);
            if ($empId > 0) {
                $stmt = $conn->prepare("UPDATE users_employee SET is_active = ? WHERE id = ?");
                $stmt->bind_param("ii", $isActive, $empId);
                if ($stmt->execute()) {
                    $statusText = $isActive ? 'activated' : 'disabled';
                    echo json_encode(['status' => 'success', 'message' => "Account {$statusText}"]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Update failed']);
                }
                $stmt->close();
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
            }
            exit;
            
        case 'reset_password':
            $empId = (int)($_POST['employee_id'] ?? 0);
            if ($empId > 0) {
                $stmt = $conn->prepare("SELECT email, full_name FROM users_employee WHERE id = ?");
                $stmt->bind_param("i", $empId);
                $stmt->execute();
                $emp = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                if ($emp) {
                    $emailPrefix = explode('@', $emp['email'])[0];
                    $defaultPass = $emailPrefix . '2026';
                    $hashed = password_hash($defaultPass, PASSWORD_BCRYPT);
                    $upd = $conn->prepare("UPDATE users_employee SET password = ? WHERE id = ?");
                    $upd->bind_param("si", $hashed, $empId);
                    $upd->execute();
                    $upd->close();
                    echo json_encode(['status' => 'success', 'message' => "Password reset for {$emp['full_name']}", 'default_password' => $defaultPass]);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Employee not found']);
                }
            }
            exit;
            
        case 'delete':
            $empId = (int)($_POST['employee_id'] ?? 0);
            if ($empId > 0) {
                $stmt = $conn->prepare("DELETE FROM users_employee WHERE id = ?");
                $stmt->bind_param("i", $empId);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    echo json_encode(['status' => 'success', 'message' => 'Account deleted']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Delete failed']);
                }
                $stmt->close();
            }
            exit;
    }
}

// ===== FETCH DATA =====
$tableExists = $conn->query("SHOW TABLES LIKE 'users_employee'")->num_rows > 0;

$employees = [];
$stats = ['total' => 0, 'active' => 0, 'disabled' => 0, 'logged_in' => 0, 'never_logged' => 0];
$departments = [];

if ($tableExists) {
    // Stats
    $statsResult = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as disabled,
            SUM(CASE WHEN last_login IS NOT NULL THEN 1 ELSE 0 END) as logged_in,
            SUM(CASE WHEN last_login IS NULL THEN 1 ELSE 0 END) as never_logged,
            MAX(synced_at) as last_sync
        FROM users_employee
    ");
    if ($statsResult) {
        $stats = $statsResult->fetch_assoc();
    }
    
    // Departments
    $deptResult = $conn->query("SELECT DISTINCT department FROM users_employee WHERE department IS NOT NULL ORDER BY department");
    while ($deptResult && $row = $deptResult->fetch_assoc()) {
        $departments[] = $row['department'];
    }
    
    // All employees
    $empResult = $conn->query("
        SELECT id, hr1_employee_id, employee_code, full_name, email, phone, 
               job_position, department, site, employment_status, employment_type,
               date_hired, is_active, last_login, login_count, synced_at, created_at
        FROM users_employee 
        ORDER BY full_name ASC
    ");
    while ($empResult && $row = $empResult->fetch_assoc()) {
        $employees[] = $row;
    }
}

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Accounts - HR2 MerchFlow</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="Css/nbar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="Css/sbar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="Css/theme.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="Css/module7_sub1.css?v=<?php echo time(); ?>">
</head>
<body>
    <?php include 'partials/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'partials/nav.php'; ?>

        <div class="container">
            <!-- Page Header -->
            <div class="page-header">
                <div class="header-content">
                    <div class="header-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="header-text">
                        <h2>Employee Accounts</h2>
                        <p>Manage HR1 employee login accounts for HR2 system</p>
                    </div>
                </div>
                <div class="header-actions">
                    <?php if ($stats['total'] > 0 && $stats['last_sync']): ?>
                        <span class="sync-badge">
                            <i class="fas fa-clock"></i>
                            Last sync: <?php echo date('M d, g:i A', strtotime($stats['last_sync'])); ?>
                        </span>
                    <?php endif; ?>
                    <button class="header-btn" onclick="exportCSV()">
                        <i class="fas fa-download"></i><span>Export</span>
                    </button>
                    <button class="header-btn primary" id="syncBtn" onclick="syncFromHR1()">
                        <i class="fas fa-sync-alt"></i><span>Sync from HR1</span>
                    </button>
                </div>
            </div>

            <!-- Toast -->
            <div id="toast" class="toast">
                <i class="fas fa-check-circle"></i>
                <span id="toastMsg"></span>
                <button class="toast-close" onclick="this.parentElement.style.display='none'">&times;</button>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['total'] ?? 0); ?></h3>
                        <p>Total Accounts</p>
                    </div>
                </div>
                <div class="stat-card active">
                    <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['active'] ?? 0); ?></h3>
                        <p>Active</p>
                    </div>
                </div>
                <div class="stat-card disabled">
                    <div class="stat-icon"><i class="fas fa-user-slash"></i></div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['disabled'] ?? 0); ?></h3>
                        <p>Disabled</p>
                    </div>
                </div>
                <div class="stat-card logged">
                    <div class="stat-icon"><i class="fas fa-sign-in-alt"></i></div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['logged_in'] ?? 0); ?></h3>
                        <p>Has Logged In</p>
                    </div>
                </div>
                <div class="stat-card never">
                    <div class="stat-icon"><i class="fas fa-user-clock"></i></div>
                    <div class="stat-info">
                        <h3><?php echo number_format($stats['never_logged'] ?? 0); ?></h3>
                        <p>Never Logged In</p>
                    </div>
                </div>
            </div>

            <?php if (!$tableExists || $stats['total'] == 0): ?>
                <!-- Empty State -->
                <div class="empty-state-card">
                    <div class="empty-icon"><i class="fas fa-user-plus"></i></div>
                    <h3>No Employee Accounts Yet</h3>
                    <p>Click "Sync from HR1" to automatically create login accounts for all HR1 employees.</p>
                    <button class="btn btn-primary btn-lg" onclick="syncFromHR1()">
                        <i class="fas fa-sync-alt"></i> Sync Now
                    </button>
                </div>
            <?php else: ?>
                <!-- Toolbar -->
                <div class="panel-toolbar">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="searchInput" placeholder="Search employees..." oninput="filterTable()">
                    </div>
                    <div class="toolbar-right">
                        <select class="filter-select" id="deptFilter" onchange="filterTable()">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo htmlspecialchars($dept); ?>"><?php echo htmlspecialchars($dept); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="statusFilter" onchange="filterTable()">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="disabled">Disabled</option>
                            <option value="logged">Has Logged In</option>
                            <option value="never">Never Logged In</option>
                        </select>
                        <div class="summary-chips">
                            <span class="chip success"><i class="fas fa-circle"></i> <?php echo $stats['active'] ?? 0; ?> Active</span>
                            <span class="chip danger"><i class="fas fa-circle"></i> <?php echo $stats['disabled'] ?? 0; ?> Disabled</span>
                        </div>
                    </div>
                </div>

                <!-- Employee Accounts Table -->
                <div class="table-card">
                    <div class="table-wrapper">
                        <table class="data-table" id="employeesTable">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Code</th>
                                    <th>Department</th>
                                    <th>Position</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th>Logins</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employees as $emp): ?>
                                    <tr data-id="<?php echo $emp['id']; ?>" 
                                        data-name="<?php echo htmlspecialchars(strtolower($emp['full_name'])); ?>"
                                        data-email="<?php echo htmlspecialchars(strtolower($emp['email'])); ?>"
                                        data-dept="<?php echo htmlspecialchars($emp['department']); ?>"
                                        data-active="<?php echo $emp['is_active']; ?>"
                                        data-logged="<?php echo $emp['last_login'] ? '1' : '0'; ?>">
                                        <td>
                                            <div class="emp-cell">
                                                <div class="cell-avatar-wrapper">
                                                    <div class="cell-avatar-initials" style="background: <?php echo generateColor($emp['full_name']); ?>">
                                                        <?php echo getInitials($emp['full_name']); ?>
                                                    </div>
                                                    <span class="online-dot <?php echo $emp['is_active'] ? 'active' : 'inactive'; ?>"></span>
                                                </div>
                                                <div class="cell-details">
                                                    <span class="cell-name"><?php echo htmlspecialchars($emp['full_name']); ?></span>
                                                    <span class="cell-email"><?php echo htmlspecialchars($emp['email']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="code-badge"><?php echo htmlspecialchars($emp['employee_code'] ?? '—'); ?></span>
                                        </td>
                                        <td>
                                            <span class="dept-badge"><?php echo htmlspecialchars($emp['department'] ?? '—'); ?></span>
                                        </td>
                                        <td>
                                            <span class="position-text"><?php echo htmlspecialchars($emp['job_position'] ?? '—'); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($emp['is_active']): ?>
                                                <span class="status-tag active"><i class="fas fa-check-circle"></i> Active</span>
                                            <?php else: ?>
                                                <span class="status-tag disabled"><i class="fas fa-ban"></i> Disabled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($emp['last_login']): ?>
                                                <span class="date-text"><i class="fas fa-clock"></i> <?php echo date('M d, g:i A', strtotime($emp['last_login'])); ?></span>
                                            <?php else: ?>
                                                <span class="muted-text">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="login-count"><?php echo $emp['login_count']; ?></span>
                                        </td>
                                        <td>
                                            <div class="action-btns">
                                                <?php if ($emp['is_active']): ?>
                                                    <button class="action-btn disable" title="Disable Account" onclick="toggleAccount(<?php echo $emp['id']; ?>, 0, '<?php echo htmlspecialchars(addslashes($emp['full_name'])); ?>')">
                                                        <i class="fas fa-user-slash"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button class="action-btn enable" title="Enable Account" onclick="toggleAccount(<?php echo $emp['id']; ?>, 1, '<?php echo htmlspecialchars(addslashes($emp['full_name'])); ?>')">
                                                        <i class="fas fa-user-check"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button class="action-btn reset" title="Reset Password" onclick="resetPassword(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars(addslashes($emp['full_name'])); ?>')">
                                                    <i class="fas fa-key"></i>
                                                </button>
                                                <button class="action-btn info" title="View Details" onclick="viewDetails(<?php echo $emp['id']; ?>)">
                                                    <i class="fas fa-info-circle"></i>
                                                </button>
                                                <button class="action-btn delete" title="Delete Account" onclick="deleteAccount(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars(addslashes($emp['full_name'])); ?>')">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <span class="row-count">Showing <strong id="visibleCount"><?php echo count($employees); ?></strong> of <?php echo count($employees); ?> accounts</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php
$mainContent = ob_get_clean();
echo $mainContent;
?>

<!-- Details Modal -->
<div class="modal" id="detailsModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-user-circle"></i> Account Details</h3>
            <button class="modal-close" onclick="closeModal('detailsModal')">&times;</button>
        </div>
        <div class="modal-body" id="detailsBody">
            <!-- Filled by JS -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('detailsModal')">Close</button>
        </div>
    </div>
</div>

<!-- Sync Progress Modal -->
<div class="modal" id="syncModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-sync-alt"></i> Syncing from HR1</h3>
            <button class="modal-close" onclick="closeModal('syncModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="sync-progress" id="syncProgress">
                <div class="sync-spinner">
                    <div class="spinner"></div>
                    <p>Connecting to HR1 database and syncing employees...</p>
                </div>
            </div>
            <div class="sync-result" id="syncResult" style="display:none;">
                <!-- Filled by JS -->
            </div>
        </div>
        <div class="modal-footer" id="syncFooter" style="display:none;">
            <button class="btn btn-primary" onclick="location.reload()"><i class="fas fa-refresh"></i> Reload Page</button>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal" id="resetModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-key"></i> Password Reset</h3>
            <button class="modal-close" onclick="closeModal('resetModal')">&times;</button>
        </div>
        <div class="modal-body" id="resetBody">
            <!-- Filled by JS -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('resetModal')">Close</button>
        </div>
    </div>
</div>

<script>
// ===== Employee Data (for details modal) =====
const employeeData = <?php echo json_encode($employees); ?>;

// ===== SYNC FROM HR1 =====
function syncFromHR1() {
    document.getElementById('syncModal').classList.add('active');
    document.getElementById('syncProgress').style.display = 'flex';
    document.getElementById('syncResult').style.display = 'none';
    document.getElementById('syncFooter').style.display = 'none';
    
    const formData = new FormData();
    formData.append('action', 'sync');
    
    fetch('api_sync_employees.php', {
        method: 'POST',
        body: formData
    })
    .then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status + ': ' + r.statusText);
        return r.text();
    })
    .then(text => {
        try {
            return JSON.parse(text);
        } catch(e) {
            console.error('Raw response:', text);
            throw new Error('Invalid response from server. Check console for details.');
        }
    })
    .then(data => {
        document.getElementById('syncProgress').style.display = 'none';
        document.getElementById('syncResult').style.display = 'block';
        document.getElementById('syncFooter').style.display = 'flex';
        
        if (data.status === 'success') {
            const d = data.data;
            document.getElementById('syncResult').innerHTML = `
                <div class="sync-success">
                    <div class="sync-icon success"><i class="fas fa-check-circle"></i></div>
                    <h4>Sync Complete!</h4>
                    <div class="sync-stats">
                        <div class="sync-stat">
                            <span class="sync-num">${d.hr1_total}</span>
                            <span class="sync-label">HR1 Employees</span>
                        </div>
                        <div class="sync-stat new">
                            <span class="sync-num">${d.inserted}</span>
                            <span class="sync-label">New Accounts</span>
                        </div>
                        <div class="sync-stat">
                            <span class="sync-num">${d.updated}</span>
                            <span class="sync-label">Updated</span>
                        </div>
                        <div class="sync-stat">
                            <span class="sync-num">${d.skipped}</span>
                            <span class="sync-label">Skipped</span>
                        </div>
                    </div>
                    <p class="sync-total">Total accounts in system: <strong>${d.total_accounts}</strong></p>
                    ${d.new_accounts.length > 0 ? `
                        <div class="new-accounts-list">
                            <h5><i class="fas fa-user-plus"></i> New Accounts Created:</h5>
                            <ul>
                                ${d.new_accounts.slice(0, 10).map(a => `<li><strong>${escapeHtml(a.name)}</strong> — ${escapeHtml(a.email)}</li>`).join('')}
                                ${d.new_accounts.length > 10 ? `<li class="more">...and ${d.new_accounts.length - 10} more</li>` : ''}
                            </ul>
                        </div>
                    ` : ''}
                </div>
            `;
        } else {
            document.getElementById('syncResult').innerHTML = `
                <div class="sync-error">
                    <div class="sync-icon error"><i class="fas fa-exclamation-triangle"></i></div>
                    <h4>Sync Failed</h4>
                    <p>${escapeHtml(data.message)}</p>
                </div>
            `;
        }
    })
    .catch(err => {
        document.getElementById('syncProgress').style.display = 'none';
        document.getElementById('syncResult').style.display = 'block';
        document.getElementById('syncFooter').style.display = 'flex';
        document.getElementById('syncResult').innerHTML = `
            <div class="sync-error">
                <div class="sync-icon error"><i class="fas fa-exclamation-triangle"></i></div>
                <h4>Connection Error</h4>
                <p>${escapeHtml(err.message)}</p>
            </div>
        `;
    });
}

// ===== TOGGLE ACCOUNT =====
function toggleAccount(id, isActive, name) {
    const action = isActive ? 'enable' : 'disable';
    if (!confirm(`Are you sure you want to ${action} the account for "${name}"?`)) return;
    
    const formData = new FormData();
    formData.append('action', 'toggle');
    formData.append('employee_id', id);
    formData.append('is_active', isActive);
    
    fetch(location.href, { method: 'POST', body: formData })
    .then(r => r.text())
    .then(text => { const data = safeJSON(text); showToast(data.message, data.status === 'success' ? 'success' : 'error'); if (data.status === 'success') setTimeout(() => location.reload(), 800); })
    .catch(err => showToast(err.message, 'error'));
}

// ===== RESET PASSWORD =====
function resetPassword(id, name) {
    if (!confirm(`Reset password for "${name}" to default?`)) return;
    
    const formData = new FormData();
    formData.append('action', 'reset_password');
    formData.append('employee_id', id);
    
    fetch(location.href, { method: 'POST', body: formData })
    .then(r => r.text())
    .then(text => {
        const data = safeJSON(text);
        if (data.status === 'success') {
            document.getElementById('resetBody').innerHTML = `
                <div class="reset-result">
                    <div class="sync-icon success"><i class="fas fa-check-circle"></i></div>
                    <h4>Password Reset Successful</h4>
                    <p>Password for <strong>${escapeHtml(name)}</strong> has been reset.</p>
                    <div class="password-display">
                        <label>New Default Password:</label>
                        <div class="password-value">
                            <code id="newPassword">${escapeHtml(data.default_password)}</code>
                            <button class="copy-btn" onclick="copyPassword()"><i class="fas fa-copy"></i></button>
                        </div>
                    </div>
                    <p class="password-note"><i class="fas fa-info-circle"></i> Please share this password securely with the employee.</p>
                </div>
            `;
            document.getElementById('resetModal').classList.add('active');
        } else {
            showToast(data.message, 'error');
        }
    })
    .catch(err => showToast(err.message, 'error'));
}

// ===== DELETE ACCOUNT =====
function deleteAccount(id, name) {
    if (!confirm(`⚠️ DELETE account for "${name}"? This cannot be undone!`)) return;
    if (!confirm(`Are you REALLY sure? This will permanently remove this employee account.`)) return;
    
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('employee_id', id);
    
    fetch(location.href, { method: 'POST', body: formData })
    .then(r => r.text())
    .then(text => { const data = safeJSON(text); showToast(data.message, data.status === 'success' ? 'success' : 'error'); if (data.status === 'success') setTimeout(() => location.reload(), 800); })
    .catch(err => showToast(err.message, 'error'));
}

// ===== VIEW DETAILS =====
function viewDetails(id) {
    const emp = employeeData.find(e => e.id == id);
    if (!emp) return;
    
    document.getElementById('detailsBody').innerHTML = `
        <div class="detail-card">
            <div class="detail-avatar" style="background: ${generateColor(emp.full_name)}">
                ${getInitials(emp.full_name)}
            </div>
            <h4>${escapeHtml(emp.full_name)}</h4>
            <span class="detail-role">${escapeHtml(emp.job_position || 'No Position')}</span>
        </div>
        <div class="detail-grid">
            <div class="detail-item">
                <label><i class="fas fa-envelope"></i> Email</label>
                <span>${escapeHtml(emp.email)}</span>
            </div>
            <div class="detail-item">
                <label><i class="fas fa-id-badge"></i> Employee Code</label>
                <span>${escapeHtml(emp.employee_code || '—')}</span>
            </div>
            <div class="detail-item">
                <label><i class="fas fa-phone"></i> Phone</label>
                <span>${escapeHtml(emp.phone || '—')}</span>
            </div>
            <div class="detail-item">
                <label><i class="fas fa-building"></i> Department</label>
                <span>${escapeHtml(emp.department || '—')}</span>
            </div>
            <div class="detail-item">
                <label><i class="fas fa-map-marker-alt"></i> Site</label>
                <span>${escapeHtml(emp.site || '—')}</span>
            </div>
            <div class="detail-item">
                <label><i class="fas fa-briefcase"></i> Employment Type</label>
                <span>${escapeHtml(emp.employment_type || '—')}</span>
            </div>
            <div class="detail-item">
                <label><i class="fas fa-calendar"></i> Date Hired</label>
                <span>${emp.date_hired ? new Date(emp.date_hired).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'}) : '—'}</span>
            </div>
            <div class="detail-item">
                <label><i class="fas fa-toggle-on"></i> Account Status</label>
                <span class="${emp.is_active == 1 ? 'text-success' : 'text-danger'}">${emp.is_active == 1 ? 'Active' : 'Disabled'}</span>
            </div>
            <div class="detail-item">
                <label><i class="fas fa-sign-in-alt"></i> Last Login</label>
                <span>${emp.last_login ? new Date(emp.last_login).toLocaleString() : 'Never'}</span>
            </div>
            <div class="detail-item">
                <label><i class="fas fa-chart-bar"></i> Total Logins</label>
                <span>${emp.login_count}</span>
            </div>
            <div class="detail-item">
                <label><i class="fas fa-sync"></i> Last Synced</label>
                <span>${emp.synced_at ? new Date(emp.synced_at).toLocaleString() : '—'}</span>
            </div>
            <div class="detail-item">
                <label><i class="fas fa-database"></i> HR1 Employee ID</label>
                <span>${emp.hr1_employee_id}</span>
            </div>
        </div>
    `;
    document.getElementById('detailsModal').classList.add('active');
}

// ===== FILTER TABLE =====
function filterTable() {
    const search = document.getElementById('searchInput').value.toLowerCase();
    const dept = document.getElementById('deptFilter').value;
    const status = document.getElementById('statusFilter').value;
    const rows = document.querySelectorAll('#employeesTable tbody tr');
    let visible = 0;
    
    rows.forEach(row => {
        const name = row.dataset.name || '';
        const email = row.dataset.email || '';
        const rowDept = row.dataset.dept || '';
        const isActive = row.dataset.active;
        const hasLogged = row.dataset.logged;
        
        let show = true;
        
        if (search && !name.includes(search) && !email.includes(search)) show = false;
        if (dept && rowDept !== dept) show = false;
        if (status === 'active' && isActive !== '1') show = false;
        if (status === 'disabled' && isActive !== '0') show = false;
        if (status === 'logged' && hasLogged !== '1') show = false;
        if (status === 'never' && hasLogged !== '0') show = false;
        
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    
    document.getElementById('visibleCount').textContent = visible;
}

// ===== EXPORT CSV =====
function exportCSV() {
    const rows = document.querySelectorAll('#employeesTable tbody tr');
    let csv = 'Name,Email,Employee Code,Department,Position,Status,Last Login,Login Count\n';
    
    rows.forEach(row => {
        if (row.style.display === 'none') return;
        const cells = row.querySelectorAll('td');
        const name = row.dataset.name;
        const email = row.dataset.email;
        const code = cells[1]?.textContent?.trim() || '';
        const dept = row.dataset.dept;
        const pos = cells[3]?.textContent?.trim() || '';
        const status = row.dataset.active === '1' ? 'Active' : 'Disabled';
        const login = cells[5]?.textContent?.trim() || '';
        const count = cells[6]?.textContent?.trim() || '0';
        csv += `"${name}","${email}","${code}","${dept}","${pos}","${status}","${login}","${count}"\n`;
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `employee_accounts_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

// ===== HELPERS =====
function showToast(msg, type) {
    const toast = document.getElementById('toast');
    const icon = toast.querySelector('i');
    document.getElementById('toastMsg').textContent = msg;
    toast.className = 'toast ' + type;
    icon.className = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-circle';
    toast.style.display = 'flex';
    setTimeout(() => { toast.style.display = 'none'; }, 4000);
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function copyPassword() {
    const pw = document.getElementById('newPassword').textContent;
    navigator.clipboard.writeText(pw).then(() => {
        showToast('Password copied to clipboard', 'success');
    });
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function safeJSON(text) {
    try {
        // Strip any PHP warnings/notices before JSON
        const jsonStart = text.indexOf('{');
        if (jsonStart > 0) {
            console.warn('Stray output before JSON:', text.substring(0, jsonStart));
            text = text.substring(jsonStart);
        }
        return JSON.parse(text);
    } catch(e) {
        console.error('Failed to parse JSON. Raw response:', text);
        throw new Error(text || 'Empty response from server');
    }
}

function generateColor(name) {
    const colors = ['#6366f1','#8b5cf6','#ec4899','#f59e0b','#10b981','#3b82f6','#ef4444','#14b8a6','#f97316','#06b6d4'];
    let hash = 0;
    for (let i = 0; i < (name||'').length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return colors[Math.abs(hash) % colors.length];
}

function getInitials(name) {
    if (!name) return '?';
    return name.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
}

// Close modals on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(m => m.classList.remove('active'));
    }
});

// Close modals on backdrop click
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', e => {
        if (e.target === modal) modal.classList.remove('active');
    });
});
</script>
</body>
</html>
<?php

// Helper functions
function getInitials($name) {
    $parts = explode(' ', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }
    return $initials ?: '?';
}

function generateColor($name) {
    $colors = ['#6366f1','#8b5cf6','#ec4899','#f59e0b','#10b981','#3b82f6','#ef4444','#14b8a6','#f97316','#06b6d4'];
    $hash = crc32($name ?? '');
    return $colors[abs($hash) % count($colors)];
}
?>

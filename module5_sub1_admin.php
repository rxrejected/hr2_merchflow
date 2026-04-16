<?php
/**
 * MODULE 5 SUB 1 ADMIN - EMPLOYEE DIRECTORY
 * HR2 MerchFlow - Employee Portal Management
 * View and manage all employees (Data from HR1 Integration)
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';
require_once 'Connection/hr1_db.php';

// ===== HR1 REAL-TIME DATA FETCH =====
$hr1db = new HR1Database();
$hr1Response = $hr1db->getEmployees('', 1000, 0);
$employees = $hr1Response['success'] ? $hr1Response['data'] : [];
$statusCounts = $hr1db->getEmployeeStatusCounts();
$statusData = $statusCounts['success'] ? $statusCounts['data'] : [];
$hr1db->close();

// Calculate stats
$total_employees = count($employees);
$active_employees = $statusData['active'] ?? 0;
$probation_employees = $statusData['probation'] ?? 0;
$onboarding_employees = $statusData['onboarding'] ?? 0;
$on_leave_employees = $statusData['on_leave'] ?? 0;
$new_this_month = 0;
$current_month = date('Y-m');

foreach ($employees as $emp) {
    $emp_created = $emp['created_at'] ?? '';
    if (substr($emp_created, 0, 7) === $current_month) $new_this_month++;
}

// Get unique departments and sites
$departments = [];
$sites = [];
foreach ($employees as $emp) {
    $dept = $emp['department'] ?? '';
    $site = $emp['site'] ?? '';
    if (!empty($dept) && !in_array($dept, $departments)) $departments[] = $dept;
    if (!empty($site) && !in_array($site, $sites)) $sites[] = $site;
}
sort($departments);
sort($sites);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Employee Directory | Admin Portal</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/module5_sub1_admin.css?v=<?= time(); ?>">
</head>
<body>
<?php include 'partials/sidebar.php'; ?>
<div class="main-content">
    <?php include 'partials/nav.php'; ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-content">
            <div class="header-icon"><i class="fas fa-address-book"></i></div>
            <div class="header-text">
                <h2>Employee Directory</h2>
                <p><i class="fas fa-database" style="color: var(--success);"></i> Real-time data from HR1 System
                    <span class="subtitle-timestamp"><i class="fas fa-clock"></i> <?= date('M d, Y h:i A'); ?></span>
                </p>
            </div>
        </div>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="exportEmployees()">
                <i class="fas fa-download"></i> Export
            </button>
        </div>
    </div>
    
    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card total">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <h3><?= $total_employees ?></h3>
                <p>Total Employees</p>
            </div>
        </div>
        <div class="stat-card active">
            <div class="stat-icon"><i class="fas fa-user-check"></i></div>
            <div class="stat-info">
                <h3><?= $active_employees ?></h3>
                <p>Active</p>
            </div>
        </div>
        <div class="stat-card warning">
            <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
            <div class="stat-info">
                <h3><?= $probation_employees ?></h3>
                <p>Probation</p>
            </div>
        </div>
        <div class="stat-card info">
            <div class="stat-icon"><i class="fas fa-user-plus"></i></div>
            <div class="stat-info">
                <h3><?= $onboarding_employees ?></h3>
                <p>Onboarding</p>
            </div>
        </div>
        <div class="stat-card accent">
            <div class="stat-icon"><i class="fas fa-building"></i></div>
            <div class="stat-info">
                <h3><?= count($departments) ?></h3>
                <p>Departments</p>
            </div>
        </div>
    </div>
    
    <div class="content-container">
        <div class="section-card fade-in">
            <div class="section-header">
                <h3><i class="fas fa-list"></i> All Employees <span class="hr1-live-badge"><i class="fas fa-circle"></i> HR1</span></h3>
                <div class="section-header-controls">
                    <input type="text" class="search-input" id="searchEmployee" placeholder="Search name, email, code...">
                    <select class="filter-select" id="filterDept">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="filter-select" id="filterStatus">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="probation">Probation</option>
                        <option value="onboarding">Onboarding</option>
                        <option value="on_leave">On Leave</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    <div class="view-toggle">
                        <button class="view-btn active" onclick="showTable()"><i class="fas fa-list"></i></button>
                        <button class="view-btn" onclick="showGrid()"><i class="fas fa-th-large"></i></button>
                    </div>
                </div>
            </div>
            <div class="section-body">
                <!-- Table View -->
                <div id="tableView">
                    <table class="data-table" id="employeeTable">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Code</th>
                                <th>Position</th>
                                <th>Department</th>
                                <th>Site</th>
                                <th>Status</th>
                                <th>Type</th>
                                <th>Date Hired</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $emp): 
                                $empName = $emp['name'] ?? 'Unknown';
                                $empEmail = $emp['email'] ?? '';
                                $empCode = $emp['employee_code'] ?? '';
                                $empRole = $emp['role'] ?? 'Employee';
                                $empDept = $emp['department'] ?? 'Operations';
                                $empSite = $emp['site'] ?? 'Main Site';
                                $empStatus = $emp['status'] ?? 'active';
                                $empType = str_replace('_', ' ', ucfirst($emp['employment_type'] ?? 'full_time'));
                                $empPhoto = $emp['photo'] ?? '';
                                $empHired = $emp['date_hired'] ?? '';
                                $empId = $emp['id'] ?? 0;
                            ?>
                            <tr data-name="<?= strtolower(htmlspecialchars($empName . ' ' . $empEmail . ' ' . $empCode)) ?>" 
                                data-dept="<?= htmlspecialchars($empDept) ?>" 
                                data-status="<?= htmlspecialchars($empStatus) ?>">
                                <td>
                                    <div class="employee-info">
                                        <?php if (!empty($empPhoto) && $empPhoto !== 'uploads/avatars/default.png'): ?>
                                        <img src="<?= htmlspecialchars($empPhoto) ?>" class="employee-table-avatar" onerror="this.src='uploads/avatars/default.png'">
                                        <?php else: ?>
                                        <div class="employee-table-avatar">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="employee-name"><?= htmlspecialchars($empName) ?></div>
                                            <div class="employee-email"><?= htmlspecialchars($empEmail) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="employee-code"><?= htmlspecialchars($empCode) ?></span></td>
                                <td><?= htmlspecialchars($empRole) ?></td>
                                <td><?= htmlspecialchars($empDept) ?></td>
                                <td><?= htmlspecialchars($empSite) ?></td>
                                <td>
                                    <span class="employee-table-status status-pill <?= $empStatus ?>">
                                        <i class="fas fa-circle" style="font-size:5px;"></i> <?= ucfirst(str_replace('_', ' ', $empStatus)) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($empType) ?></td>
                                <td><?= !empty($empHired) ? date('M d, Y', strtotime($empHired)) : 'N/A' ?></td>
                                <td>
                                    <button class="action-btn view" onclick="viewEmployee(<?= $empId ?>)" title="View Full Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Grid View -->
                <div id="gridView" class="employee-grid">
                    <?php foreach ($employees as $emp): 
                        $empName = $emp['name'] ?? 'Unknown';
                        $empCode = $emp['employee_code'] ?? '';
                        $empRole = $emp['role'] ?? 'Employee';
                        $empDept = $emp['department'] ?? 'Operations';
                        $empSite = $emp['site'] ?? 'Main Site';
                        $empStatus = $emp['status'] ?? 'active';
                        $empPhoto = $emp['photo'] ?? '';
                        $empId = $emp['id'] ?? 0;
                        $empEmail = $emp['email'] ?? '';
                    ?>
                    <div class="employee-card" data-name="<?= strtolower(htmlspecialchars($empName . ' ' . $empEmail . ' ' . $empCode)) ?>" 
                         data-dept="<?= htmlspecialchars($empDept) ?>" 
                         data-status="<?= htmlspecialchars($empStatus) ?>"
                         onclick="viewEmployee(<?= $empId ?>)">
                        <?php if (!empty($empPhoto) && $empPhoto !== 'uploads/avatars/default.png'): ?>
                        <img src="<?= htmlspecialchars($empPhoto) ?>" class="employee-card-avatar" onerror="this.src='uploads/avatars/default.png'">
                        <?php else: ?>
                        <div class="employee-card-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <?php endif; ?>
                        <div class="employee-card-name"><?= htmlspecialchars($empName) ?></div>
                        <div class="employee-card-position"><?= htmlspecialchars($empRole) ?></div>
                        <span class="status-pill <?= $empStatus ?>">
                            <i class="fas fa-circle" style="font-size:5px;"></i> <?= ucfirst(str_replace('_', ' ', $empStatus)) ?>
                        </span>
                        <div class="employee-card-stats">
                            <div class="employee-card-stat">
                                <div class="employee-card-stat-value"><?= htmlspecialchars($empCode) ?></div>
                                <div class="employee-card-stat-label">Code</div>
                            </div>
                            <div class="employee-card-stat">
                                <div class="employee-card-stat-value"><?= htmlspecialchars($empDept) ?></div>
                                <div class="employee-card-stat-label">Dept</div>
                            </div>
                            <div class="employee-card-stat">
                                <div class="employee-card-stat-value"><?= htmlspecialchars($empSite) ?></div>
                                <div class="employee-card-stat-label">Site</div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
</div><!-- end .main-content -->

<!-- Employee Detail Modal (outside .main-content for proper centering) -->
<div class="m5s1a-modal" id="employeeModal">
    <div class="m5s1a-modal-content">
        <div class="m5s1a-modal-header">
            <h3><i class="fas fa-user-circle"></i> Employee Details</h3>
            <button class="m5s1a-modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="m5s1a-modal-body" id="employeeDetails">
            <div class="loading-spinner">
                <div class="spinner"></div>
            </div>
        </div>
    </div>
</div>

<script>
// View toggle
function showTable() {
    document.getElementById('tableView').style.display = 'block';
    document.getElementById('gridView').style.display = 'none';
    document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.view-btn')[0].classList.add('active');
}

function showGrid() {
    document.getElementById('tableView').style.display = 'none';
    document.getElementById('gridView').style.display = 'grid';
    document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.view-btn')[1].classList.add('active');
}

// Search & Filter functionality
document.getElementById('searchEmployee').addEventListener('input', filterEmployees);
document.getElementById('filterDept').addEventListener('change', filterEmployees);
document.getElementById('filterStatus').addEventListener('change', filterEmployees);

function filterEmployees() {
    const search = document.getElementById('searchEmployee').value.toLowerCase();
    const dept = document.getElementById('filterDept').value;
    const status = document.getElementById('filterStatus').value;
    
    // Filter table rows
    document.querySelectorAll('#employeeTable tbody tr').forEach(row => {
        const name = row.dataset.name;
        const rowDept = row.dataset.dept;
        const rowStatus = row.dataset.status;
        const matchSearch = !search || name.includes(search);
        const matchDept = !dept || rowDept === dept;
        const matchStatus = !status || rowStatus === status;
        row.style.display = (matchSearch && matchDept && matchStatus) ? '' : 'none';
    });
    
    // Filter grid cards
    document.querySelectorAll('.employee-card').forEach(card => {
        const name = card.dataset.name;
        const cardDept = card.dataset.dept;
        const cardStatus = card.dataset.status;
        const matchSearch = !search || name.includes(search);
        const matchDept = !dept || cardDept === dept;
        const matchStatus = !status || cardStatus === status;
        card.style.display = (matchSearch && matchDept && matchStatus) ? '' : 'none';
    });
}

// View employee - fetch full details from HR1 via API
function viewEmployee(id) {
    document.body.classList.add('m5s1a-modal-open');
    document.getElementById('employeeModal').classList.add('active');
    document.getElementById('employeeDetails').innerHTML = '<div class="loading-spinner"><div class="spinner"></div></div>';
    
    fetch('api_hr1_realtime.php?action=employee_detail&id=' + id)
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data) {
            const emp = data.data;
            const statusClass = emp.status || 'active';
            const statusLabel = (emp.status || 'active').replace('_', ' ');
            const empType = (emp.employment_type || 'full_time').replace('_', ' ');
            
            // Format dates
            const formatDate = (d) => {
                if (!d) return 'N/A';
                const date = new Date(d);
                return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            };
            
            // Calculate age
            let age = 'N/A';
            if (emp.birthdate) {
                const bd = new Date(emp.birthdate);
                const today = new Date();
                age = today.getFullYear() - bd.getFullYear();
                if (today.getMonth() < bd.getMonth() || (today.getMonth() === bd.getMonth() && today.getDate() < bd.getDate())) age--;
                age = age + ' years old';
            }
            
            const photoHtml = (emp.photo && emp.photo !== 'uploads/avatars/default.png')
                ? `<img src="${emp.photo}" alt="Photo" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                   <div class="profile-avatar-placeholder" style="display:none;"><i class="fas fa-user"></i></div>`
                : `<div class="profile-avatar-placeholder"><i class="fas fa-user"></i></div>`;
            
            document.getElementById('employeeDetails').innerHTML = `
                <div class="employee-profile-header">
                    ${photoHtml}
                    <div class="profile-info">
                        <h3>${escapeHtml(emp.name)}</h3>
                        <p><i class="fas fa-id-badge"></i> ${escapeHtml(emp.employee_code)}</p>
                        <p><i class="fas fa-briefcase"></i> ${escapeHtml(emp.role)} &bull; ${escapeHtml(emp.department)}</p>
                        <span class="status-pill ${statusClass}">${statusLabel}</span>
                    </div>
                </div>
                
                <div class="employee-detail-grid">
                    <!-- Personal Information -->
                    <div class="detail-section">
                        <h4><i class="fas fa-user"></i> Personal Information</h4>
                        <div class="detail-row">
                            <span class="detail-label">Full Name</span>
                            <span class="detail-value">${escapeHtml(emp.name)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Email</span>
                            <span class="detail-value">${escapeHtml(emp.email || 'N/A')}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Phone</span>
                            <span class="detail-value">${escapeHtml(emp.phone || 'N/A')}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Birthdate</span>
                            <span class="detail-value">${formatDate(emp.birthdate)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Age</span>
                            <span class="detail-value">${age}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Gender</span>
                            <span class="detail-value">${capitalize(emp.gender || 'N/A')}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Civil Status</span>
                            <span class="detail-value">${capitalize(emp.civil_status || 'N/A')}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Nationality</span>
                            <span class="detail-value">${escapeHtml(emp.nationality || 'Filipino')}</span>
                        </div>
                    </div>
                    
                    <!-- Employment Information -->
                    <div class="detail-section">
                        <h4><i class="fas fa-briefcase"></i> Employment Details</h4>
                        <div class="detail-row">
                            <span class="detail-label">Employee Code</span>
                            <span class="detail-value employee-code">${escapeHtml(emp.employee_code)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Position</span>
                            <span class="detail-value">${escapeHtml(emp.role)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Department</span>
                            <span class="detail-value">${escapeHtml(emp.department)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Site</span>
                            <span class="detail-value">${escapeHtml(emp.site)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Type</span>
                            <span class="detail-value">${capitalize(empType)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Status</span>
                            <span class="detail-value"><span class="status-pill ${statusClass}">${statusLabel}</span></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Date Hired</span>
                            <span class="detail-value">${formatDate(emp.date_hired)}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Start Date</span>
                            <span class="detail-value">${formatDate(emp.start_date)}</span>
                        </div>
                        ${emp.probation_start ? `<div class="detail-row">
                            <span class="detail-label">Probation</span>
                            <span class="detail-value">${formatDate(emp.probation_start)} - ${formatDate(emp.probation_end)}</span>
                        </div>` : ''}
                    </div>
                    
                    <!-- Address -->
                    <div class="detail-section">
                        <h4><i class="fas fa-map-marker-alt"></i> Address</h4>
                        <div class="detail-row">
                            <span class="detail-label">Street</span>
                            <span class="detail-value">${escapeHtml(emp.address || 'N/A')}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">City</span>
                            <span class="detail-value">${escapeHtml(emp.city || 'N/A')}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Province</span>
                            <span class="detail-value">${escapeHtml(emp.province || 'N/A')}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Zip Code</span>
                            <span class="detail-value">${escapeHtml(emp.zip_code || 'N/A')}</span>
                        </div>
                    </div>
                    
                    <!-- Emergency Contact -->
                    <div class="detail-section">
                        <h4><i class="fas fa-phone-alt"></i> Emergency Contact</h4>
                        <div class="detail-row">
                            <span class="detail-label">Name</span>
                            <span class="detail-value">${escapeHtml(emp.emergency_contact_name || 'N/A')}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Phone</span>
                            <span class="detail-value">${escapeHtml(emp.emergency_contact_phone || 'N/A')}</span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Relationship</span>
                            <span class="detail-value">${capitalize(emp.emergency_contact_relationship || 'N/A')}</span>
                        </div>
                    </div>
                    
                    <!-- Government IDs -->
                    <div class="detail-section full-width-section">
                        <h4><i class="fas fa-id-card"></i> Government IDs</h4>
                        <div class="govt-id-grid">
                            <div class="govt-id-item">
                                <div class="id-label">SSS No.</div>
                                <div class="id-value">${escapeHtml(emp.sss_no || 'N/A')}</div>
                            </div>
                            <div class="govt-id-item">
                                <div class="id-label">PhilHealth No.</div>
                                <div class="id-value">${escapeHtml(emp.philhealth_no || 'N/A')}</div>
                            </div>
                            <div class="govt-id-item">
                                <div class="id-label">Pag-IBIG No.</div>
                                <div class="id-value">${escapeHtml(emp.pagibig_no || 'N/A')}</div>
                            </div>
                            <div class="govt-id-item">
                                <div class="id-label">TIN No.</div>
                                <div class="id-value">${escapeHtml(emp.tin_no || 'N/A')}</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="m5s1a-modal-source-footer">
                    <span class="m5s1a-modal-source-text"><i class="fas fa-database"></i> Data source: HR1 System &bull; Updated: ${data.timestamp || 'N/A'}</span>
                </div>
            `;
        } else {
            document.getElementById('employeeDetails').innerHTML = `
                <div class="m5s1a-modal-error">
                    <i class="fas fa-exclamation-triangle m5s1a-error-icon warning"></i>
                    <p>${data.error || 'Failed to load employee details.'}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        document.getElementById('employeeDetails').innerHTML = `
            <div class="m5s1a-modal-error">
                <i class="fas fa-times-circle m5s1a-error-icon error"></i>
                <p>Connection error: ${error.message}</p>
            </div>
        `;
    });
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function capitalize(str) {
    if (!str || str === 'N/A') return str || 'N/A';
    return str.charAt(0).toUpperCase() + str.slice(1).replace(/_/g, ' ');
}

function closeModal() {
    document.getElementById('employeeModal').classList.remove('active');
    document.body.classList.remove('m5s1a-modal-open');
}

function exportEmployees() {
    // Generate CSV from HR1 employee data
    const table = document.getElementById('employeeTable');
    if (!table) return;
    
    let csvContent = "Employee Name,Code,Position,Department,Site,Status,Type,Date Hired,Email\n";
    
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        if (row.style.display === 'none') return;
        const cells = row.querySelectorAll('td');
        const name = cells[0]?.querySelector('.employee-name')?.textContent?.trim() || '';
        const email = cells[0]?.querySelector('.employee-email')?.textContent?.trim() || '';
        const code = cells[1]?.textContent?.trim() || '';
        const position = cells[2]?.textContent?.trim() || '';
        const dept = cells[3]?.textContent?.trim() || '';
        const site = cells[4]?.textContent?.trim() || '';
        const status = cells[5]?.textContent?.trim() || '';
        const type = cells[6]?.textContent?.trim() || '';
        const hired = cells[7]?.textContent?.trim() || '';
        
        csvContent += `"${name}","${code}","${position}","${dept}","${site}","${status}","${type}","${hired}","${email}"\n`;
    });
    
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'HR1_Employees_' + new Date().toISOString().slice(0,10) + '.csv';
    link.click();
}

document.getElementById('employeeModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>
</body>
</html>

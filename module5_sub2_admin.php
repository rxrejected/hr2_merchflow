<?php
/**
 * MODULE 5 SUB 2 ADMIN - REQUEST APPROVALS
 * HR2 MerchFlow - Employee Portal Management
 * Manage and approve employee requests
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

// Admin role check
if (!in_array($_SESSION['role'] ?? '', ['admin', 'Super Admin', 'developer'])) {
    header('Location: employee.php');
    exit();
}

// Create requests table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS employee_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    request_type ENUM('leave', 'document', 'schedule_change', 'other') NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    priority ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal',
    approved_by INT,
    approved_at DATETIME,
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id),
    INDEX (status)
)");

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request_id = intval($_POST['request_id']);
    $action = $_POST['action'];
    $remarks = $_POST['remarks'] ?? '';
    $admin_id = $_SESSION['user_id'];
    
    if ($action === 'approve' || $action === 'reject') {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $conn->prepare("UPDATE employee_requests SET status = ?, approved_by = ?, approved_at = NOW(), remarks = ? WHERE id = ?");
        $stmt->bind_param("sisi", $status, $admin_id, $remarks, $request_id);
        $stmt->execute();
        $stmt->close();
        
        header("Location: module5_sub2_admin.php?msg=" . ($action === 'approve' ? 'approved' : 'rejected'));
        exit();
    }
}

// Fetch all requests
$filter = $_GET['filter'] ?? 'pending';
$allowedFilters = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($filter, $allowedFilters)) {
    $filter = 'pending';
}
$where = $filter === 'all' ? '' : "WHERE r.status = '" . $conn->real_escape_string($filter) . "'";

$requests_query = "
    SELECT 
        r.*,
        u.full_name as employee_name,
        u.avatar as employee_avatar,
        u.job_position,
        a.full_name as approved_by_name
    FROM employee_requests r
    JOIN users u ON r.employee_id = u.id
    LEFT JOIN users a ON r.approved_by = a.id
    $where
    ORDER BY 
        CASE r.priority 
            WHEN 'urgent' THEN 1 
            WHEN 'high' THEN 2 
            WHEN 'normal' THEN 3 
            ELSE 4 
        END,
        r.created_at DESC
";
$requests = $conn->query($requests_query)->fetch_all(MYSQLI_ASSOC);

// Count by status
$counts = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'all' => count($requests)
];
$all_requests = $conn->query("SELECT status, COUNT(*) as cnt FROM employee_requests GROUP BY status")->fetch_all(MYSQLI_ASSOC);
foreach ($all_requests as $r) {
    $counts[$r['status']] = $r['cnt'];
}
$counts['all'] = array_sum([$counts['pending'], $counts['approved'], $counts['rejected']]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Request Approvals | Admin Portal</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/module5_sub2_admin.css?v=<?= time(); ?>">
</head>
<body>
<?php include 'partials/sidebar.php'; ?>
<div class="main-content">
    <?php include 'partials/nav.php'; ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-content">
            <div class="header-icon"><i class="fas fa-clipboard-check"></i></div>
            <div class="header-text">
                <h2>Request Approvals</h2>
                <p>Review and process employee requests</p>
            </div>
        </div>
    </div>
    
    <?php if (isset($_GET['msg'])): ?>
    <div class="content-container" style="margin-bottom: 0;">
        <div class="alert fade-in" style="background: <?= $_GET['msg'] === 'approved' ? 'var(--success-green-light)' : 'var(--danger-red-light)' ?>; color: <?= $_GET['msg'] === 'approved' ? 'var(--success-green-dark)' : 'var(--danger-red-dark)' ?>; padding: 1rem; border-radius: var(--radius); display: flex; align-items: center; gap: 0.75rem;">
            <i class="fas <?= $_GET['msg'] === 'approved' ? 'fa-check-circle' : 'fa-times-circle' ?>"></i>
            Request has been <?= htmlspecialchars($_GET['msg']) ?> successfully!
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card warning">
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-info">
                <h3><?= $counts['pending'] ?></h3>
                <p>Pending</p>
            </div>
        </div>
        <div class="stat-card active">
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <h3><?= $counts['approved'] ?></h3>
                <p>Approved</p>
            </div>
        </div>
        <div class="stat-card danger">
            <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
            <div class="stat-info">
                <h3><?= $counts['rejected'] ?></h3>
                <p>Rejected</p>
            </div>
        </div>
        <div class="stat-card total">
            <div class="stat-icon"><i class="fas fa-inbox"></i></div>
            <div class="stat-info">
                <h3><?= $counts['all'] ?></h3>
                <p>Total Requests</p>
            </div>
        </div>
    </div>
    
    <div class="content-container">
        <div class="section-card fade-in">
            <div class="section-header">
                <h3><i class="fas fa-list"></i> All Requests</h3>
                <input type="text" class="search-input" id="searchRequest" placeholder="Search requests...">
            </div>
            <div class="section-body">
                <!-- Filter Tabs -->
                <div class="filter-tabs">
                    <a href="?filter=pending" class="filter-tab <?= $filter === 'pending' ? 'active' : '' ?>">
                        <i class="fas fa-clock"></i> Pending
                        <span class="filter-count"><?= $counts['pending'] ?></span>
                    </a>
                    <a href="?filter=approved" class="filter-tab <?= $filter === 'approved' ? 'active' : '' ?>">
                        <i class="fas fa-check"></i> Approved
                        <span class="filter-count"><?= $counts['approved'] ?></span>
                    </a>
                    <a href="?filter=rejected" class="filter-tab <?= $filter === 'rejected' ? 'active' : '' ?>">
                        <i class="fas fa-times"></i> Rejected
                        <span class="filter-count"><?= $counts['rejected'] ?></span>
                    </a>
                    <a href="?filter=all" class="filter-tab <?= $filter === 'all' ? 'active' : '' ?>">
                        <i class="fas fa-th-list"></i> All
                        <span class="filter-count"><?= $counts['all'] ?></span>
                    </a>
                </div>
                
                <?php if (count($requests) > 0): ?>
                    <?php foreach ($requests as $req): ?>
                    <div class="request-card <?= $req['priority'] ?>" data-title="<?= strtolower(htmlspecialchars($req['title'] . ' ' . $req['employee_name'])) ?>">
                        <div class="request-header">
                            <div class="request-employee">
                                <img src="<?= htmlspecialchars($req['employee_avatar'] ?: 'uploads/avatars/default.png') ?>" class="request-avatar">
                                <div>
                                    <div class="request-employee-name"><?= htmlspecialchars($req['employee_name']) ?></div>
                                    <div class="request-employee-position"><?= htmlspecialchars($req['job_position'] ?? 'Employee') ?></div>
                                </div>
                            </div>
                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                <span class="priority-badge <?= $req['priority'] ?>"><?= ucfirst($req['priority']) ?></span>
                                <span class="request-type <?= $req['request_type'] ?>"><?= ucfirst(str_replace('_', ' ', $req['request_type'])) ?></span>
                            </div>
                        </div>
                        <div class="request-title"><?= htmlspecialchars($req['title']) ?></div>
                        <?php if ($req['description']): ?>
                        <div class="request-description"><?= htmlspecialchars($req['description']) ?></div>
                        <?php endif; ?>
                        <div class="request-footer">
                            <div class="request-meta">
                                <span><i class="fas fa-calendar"></i> <?= date('M d, Y h:i A', strtotime($req['created_at'])) ?></span>
                                <?php if ($req['status'] !== 'pending'): ?>
                                <span>
                                    <i class="fas fa-user-check"></i> 
                                    <?= $req['status'] === 'approved' ? 'Approved' : 'Rejected' ?> by <?= htmlspecialchars($req['approved_by_name'] ?? 'Admin') ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="request-actions">
                                <?php if ($req['status'] === 'pending'): ?>
                                <button class="btn btn-success btn-sm" onclick="processRequest(<?= $req['id'] ?>, 'approve')">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="processRequest(<?= $req['id'] ?>, 'reject')">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                                <?php else: ?>
                                <span class="status-badge <?= $req['status'] ?>">
                                    <i class="fas <?= $req['status'] === 'approved' ? 'fa-check' : 'fa-times' ?>"></i>
                                    <?= ucfirst($req['status']) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h4>No Requests Found</h4>
                    <p>There are no <?= $filter !== 'all' ? $filter : '' ?> requests at the moment.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Process Request Modal -->
    <div class="modal" id="processModal">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header">
                <h3 id="processTitle"><i class="fas fa-check-circle"></i> Approve Request</h3>
                <button class="modal-close" onclick="closeProcessModal()">&times;</button>
            </div>
            <form method="POST" id="processForm">
                <input type="hidden" name="request_id" id="processRequestId">
                <input type="hidden" name="action" id="processAction">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Remarks (Optional)</label>
                        <textarea name="remarks" class="form-control" rows="3" placeholder="Add any notes or remarks..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeProcessModal()">Cancel</button>
                    <button type="submit" class="btn" id="processSubmitBtn">
                        <i class="fas fa-check"></i> Confirm
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Search
document.getElementById('searchRequest').addEventListener('input', function() {
    const query = this.value.toLowerCase();
    document.querySelectorAll('.request-card').forEach(card => {
        const title = card.dataset.title;
        card.style.display = title.includes(query) ? '' : 'none';
    });
});

function processRequest(id, action) {
    document.getElementById('processRequestId').value = id;
    document.getElementById('processAction').value = action;
    
    const title = document.getElementById('processTitle');
    const btn = document.getElementById('processSubmitBtn');
    
    if (action === 'approve') {
        title.innerHTML = '<i class="fas fa-check-circle"></i> Approve Request';
        btn.className = 'btn btn-success';
        btn.innerHTML = '<i class="fas fa-check"></i> Approve';
    } else {
        title.innerHTML = '<i class="fas fa-times-circle"></i> Reject Request';
        btn.className = 'btn btn-danger';
        btn.innerHTML = '<i class="fas fa-times"></i> Reject';
    }
    
    document.getElementById('processModal').classList.add('active');
}

function closeProcessModal() {
    document.getElementById('processModal').classList.remove('active');
}

document.getElementById('processModal').addEventListener('click', function(e) {
    if (e.target === this) closeProcessModal();
});
</script>
</body>
</html>

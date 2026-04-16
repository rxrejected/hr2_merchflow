<?php
/**
 * MODULE 5 SUB 7 - MY REQUESTS
 * HR2 MerchFlow - Employee Self-Service Portal
 * Submit and track personal requests (leave, documents, schedule changes)
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

$employee_id = (int)$_SESSION['user_id'];
$employee_name = $_SESSION['full_name'] ?? 'Employee';

// Create requests table if not exists (same as admin page)
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

// Handle new request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    $type = $_POST['request_type'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description'] ?? '');
    $priority = $_POST['priority'] ?? 'normal';

    if (!empty($title) && in_array($type, ['leave', 'document', 'schedule_change', 'other'])) {
        $stmt = $conn->prepare("INSERT INTO employee_requests (employee_id, request_type, title, description, priority) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issss", $employee_id, $type, $title, $description, $priority);
        $stmt->execute();
        $stmt->close();
        header("Location: module5_sub7.php?msg=submitted");
        exit();
    }
}

// Handle cancel request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_request'])) {
    $request_id = intval($_POST['request_id']);
    // Only allow canceling own pending requests
    $stmt = $conn->prepare("DELETE FROM employee_requests WHERE id = ? AND employee_id = ? AND status = 'pending'");
    $stmt->bind_param("ii", $request_id, $employee_id);
    $stmt->execute();
    $stmt->close();
    header("Location: module5_sub7.php?msg=cancelled");
    exit();
}

// Fetch employee's requests
$filter = $_GET['filter'] ?? 'all';
$where = "WHERE r.employee_id = ?";
if ($filter !== 'all' && in_array($filter, ['pending', 'approved', 'rejected'])) {
    $where .= " AND r.status = '" . $conn->real_escape_string($filter) . "'";
}

$stmt = $conn->prepare("
    SELECT r.*, a.full_name as approved_by_name
    FROM employee_requests r
    LEFT JOIN users a ON r.approved_by = a.id
    $where
    ORDER BY r.created_at DESC
");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Stats
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'all' => 0];
$stmt = $conn->prepare("SELECT status, COUNT(*) as cnt FROM employee_requests WHERE employee_id = ? GROUP BY status");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $counts[$row['status']] = (int)$row['cnt'];
}
$stmt->close();
$counts['all'] = $counts['pending'] + $counts['approved'] + $counts['rejected'];

$msg = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>My Requests | Employee Portal</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/module5_sub7.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="Css/ai_chat_bubble.css?v=<?= time(); ?>">
</head>
<body>
<?php include 'partials/sidebar.php'; ?>
<div class="main-content">
    <?php include 'partials/nav.php'; ?>

    <!-- Success/Error Messages -->
    <?php if ($msg === 'submitted'): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> Request submitted successfully! Admin will review it shortly.</div>
    <?php elseif ($msg === 'cancelled'): ?>
    <div class="alert alert-info"><i class="fas fa-info-circle"></i> Request cancelled.</div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h2><i class="fas fa-paper-plane"></i> My Requests</h2>
            <div class="subtitle">Submit and track your leave, document, and schedule requests</div>
        </div>
        <button class="btn-primary" onclick="document.getElementById('newRequestModal').classList.add('show')">
            <i class="fas fa-plus"></i> New Request
        </button>
    </div>

    <!-- Stats Row -->
    <div class="stats-row">
        <div class="stat-card fade-in">
            <div class="icon blue"><i class="fas fa-list-alt"></i></div>
            <div>
                <div class="value"><?= $counts['all'] ?></div>
                <div class="label">Total Requests</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon yellow"><i class="fas fa-clock"></i></div>
            <div>
                <div class="value"><?= $counts['pending'] ?></div>
                <div class="label">Pending</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon green"><i class="fas fa-check-circle"></i></div>
            <div>
                <div class="value"><?= $counts['approved'] ?></div>
                <div class="label">Approved</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon red"><i class="fas fa-times-circle"></i></div>
            <div>
                <div class="value"><?= $counts['rejected'] ?></div>
                <div class="label">Rejected</div>
            </div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="filter-tabs">
        <a href="?filter=all" class="tab <?= $filter === 'all' ? 'active' : '' ?>">All (<?= $counts['all'] ?>)</a>
        <a href="?filter=pending" class="tab <?= $filter === 'pending' ? 'active' : '' ?>">Pending (<?= $counts['pending'] ?>)</a>
        <a href="?filter=approved" class="tab <?= $filter === 'approved' ? 'active' : '' ?>">Approved (<?= $counts['approved'] ?>)</a>
        <a href="?filter=rejected" class="tab <?= $filter === 'rejected' ? 'active' : '' ?>">Rejected (<?= $counts['rejected'] ?>)</a>
    </div>

    <!-- Requests List -->
    <div class="requests-list">
        <?php if (empty($requests)): ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h3>No Requests Found</h3>
            <p>You haven't submitted any requests yet. Click "New Request" to get started.</p>
        </div>
        <?php else: ?>
            <?php foreach ($requests as $req): ?>
            <div class="request-card fade-in">
                <div class="request-header">
                    <div class="request-type type-<?= $req['request_type'] ?>">
                        <i class="fas <?= $req['request_type'] === 'leave' ? 'fa-calendar-minus' : ($req['request_type'] === 'document' ? 'fa-file-alt' : ($req['request_type'] === 'schedule_change' ? 'fa-clock' : 'fa-question-circle')) ?>"></i>
                        <?= ucfirst(str_replace('_', ' ', $req['request_type'])) ?>
                    </div>
                    <div class="request-status status-<?= $req['status'] ?>">
                        <i class="fas <?= $req['status'] === 'pending' ? 'fa-hourglass-half' : ($req['status'] === 'approved' ? 'fa-check' : 'fa-times') ?>"></i>
                        <?= ucfirst($req['status']) ?>
                    </div>
                </div>
                <h3 class="request-title"><?= htmlspecialchars($req['title']) ?></h3>
                <?php if ($req['description']): ?>
                <p class="request-desc"><?= nl2br(htmlspecialchars($req['description'])) ?></p>
                <?php endif; ?>
                <div class="request-meta">
                    <span class="priority priority-<?= $req['priority'] ?>">
                        <i class="fas fa-flag"></i> <?= ucfirst($req['priority']) ?>
                    </span>
                    <span class="date"><i class="fas fa-calendar"></i> <?= date('M d, Y h:i A', strtotime($req['created_at'])) ?></span>
                </div>
                <?php if ($req['status'] !== 'pending'): ?>
                <div class="request-response">
                    <div class="response-header">
                        <i class="fas fa-user-shield"></i>
                        <strong><?= $req['status'] === 'approved' ? 'Approved' : 'Rejected' ?> by <?= htmlspecialchars($req['approved_by_name'] ?? 'Admin') ?></strong>
                        <span class="response-date"><?= $req['approved_at'] ? date('M d, Y', strtotime($req['approved_at'])) : '' ?></span>
                    </div>
                    <?php if ($req['remarks']): ?>
                    <p class="response-remarks"><?= nl2br(htmlspecialchars($req['remarks'])) ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($req['status'] === 'pending'): ?>
                <form method="POST" class="cancel-form" onsubmit="return confirm('Cancel this request?')">
                    <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                    <button type="submit" name="cancel_request" class="btn-cancel"><i class="fas fa-times"></i> Cancel Request</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- New Request Modal -->
<div class="modal-overlay" id="newRequestModal">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle"></i> Submit New Request</h3>
            <button class="modal-close" onclick="document.getElementById('newRequestModal').classList.remove('show')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <div class="form-group">
                    <label><i class="fas fa-tag"></i> Request Type</label>
                    <select name="request_type" required>
                        <option value="">Select type...</option>
                        <option value="leave">📋 Leave Request</option>
                        <option value="document">📄 Document Request</option>
                        <option value="schedule_change">🕐 Schedule Change</option>
                        <option value="other">📝 Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-heading"></i> Title</label>
                    <input type="text" name="title" placeholder="Brief description of your request" required maxlength="255">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-align-left"></i> Details</label>
                    <textarea name="description" rows="4" placeholder="Provide more details about your request..."></textarea>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-flag"></i> Priority</label>
                    <select name="priority">
                        <option value="low">Low</option>
                        <option value="normal" selected>Normal</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="document.getElementById('newRequestModal').classList.remove('show')">Cancel</button>
                <button type="submit" name="submit_request" class="btn-primary"><i class="fas fa-paper-plane"></i> Submit Request</button>
            </div>
        </form>
    </div>
</div>

<?php include 'partials/ai_chat.php'; ?>
<script>
// Auto-hide alerts after 4 seconds
document.querySelectorAll('.alert').forEach(el => {
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }, 4000);
});
</script>
</body>
</html>

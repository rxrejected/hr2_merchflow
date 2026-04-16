<?php
/**
 * MODULE 5 SUB 3 ADMIN - ANNOUNCEMENTS
 * HR2 MerchFlow - Employee Portal Management
 * Create and manage announcements for employees
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

// Admin role check
if (!in_array($_SESSION['role'] ?? '', ['admin', 'Super Admin', 'developer'])) {
    header('Location: employee.php');
    exit();
}

$admin_id = $_SESSION['user_id'];

// Create announcements table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    category ENUM('general', 'urgent', 'event', 'policy', 'holiday') DEFAULT 'general',
    priority ENUM('low', 'normal', 'high') DEFAULT 'normal',
    target_audience ENUM('all', 'employees', 'managers', 'department') DEFAULT 'all',
    target_department VARCHAR(100),
    is_pinned TINYINT(1) DEFAULT 0,
    published_at DATETIME,
    expires_at DATETIME,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (category),
    INDEX (is_pinned)
)");

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_announcement'])) {
        $title = $_POST['title'];
        $content = $_POST['content'];
        $category = $_POST['category'];
        $priority = $_POST['priority'];
        $target = $_POST['target_audience'];
        $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;
        $published_at = !empty($_POST['publish_date']) ? $_POST['publish_date'] : date('Y-m-d H:i:s');
        $expires_at = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        
        $stmt = $conn->prepare("INSERT INTO announcements (title, content, category, priority, target_audience, is_pinned, published_at, expires_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssisii", $title, $content, $category, $priority, $target, $is_pinned, $published_at, $expires_at, $admin_id);
        $stmt->execute();
        $stmt->close();
        
        header("Location: module5_sub3_admin.php?msg=created");
        exit();
    }
    
    if (isset($_POST['delete_announcement'])) {
        $id = intval($_POST['announcement_id']);
        $conn->query("DELETE FROM announcements WHERE id = $id");
        header("Location: module5_sub3_admin.php?msg=deleted");
        exit();
    }
    
    if (isset($_POST['toggle_pin'])) {
        $id = intval($_POST['announcement_id']);
        $conn->query("UPDATE announcements SET is_pinned = NOT is_pinned WHERE id = $id");
        header("Location: module5_sub3_admin.php");
        exit();
    }
}

// Fetch announcements
$announcements = $conn->query("
    SELECT a.*, u.full_name as author_name 
    FROM announcements a 
    JOIN users u ON a.created_by = u.id 
    ORDER BY a.is_pinned DESC, a.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Calculate stats
$total = count($announcements);
$pinned = 0;
$active = 0;
$expired = 0;
$now = new DateTime();

foreach ($announcements as $ann) {
    if ($ann['is_pinned']) $pinned++;
    if ($ann['expires_at']) {
        $expiry = new DateTime($ann['expires_at']);
        if ($expiry < $now) {
            $expired++;
        } else {
            $active++;
        }
    } else {
        $active++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Announcements | Admin Portal</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/module5_sub3_admin.css?v=<?= time(); ?>">
</head>
<body>
<?php include 'partials/sidebar.php'; ?>
<div class="main-content">
    <?php include 'partials/nav.php'; ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-content">
            <div class="header-icon"><i class="fas fa-bullhorn"></i></div>
            <div class="header-text">
                <h2>Announcements</h2>
                <p>Create and manage announcements for employees</p>
            </div>
        </div>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> New Announcement
            </button>
        </div>
    </div>
    
    <?php if (isset($_GET['msg'])): ?>
    <div class="content-container" style="margin-bottom: 0;">
        <div class="alert fade-in" style="background: var(--success-green-light); color: var(--success-green-dark); padding: 1rem; border-radius: var(--radius); display: flex; align-items: center; gap: 0.75rem;">
            <i class="fas fa-check-circle"></i>
            Announcement <?= $_GET['msg'] === 'created' ? 'created' : 'deleted' ?> successfully!
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card total">
            <div class="stat-icon"><i class="fas fa-bullhorn"></i></div>
            <div class="stat-info">
                <h3><?= $total ?></h3>
                <p>Total</p>
            </div>
        </div>
        <div class="stat-card active">
            <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info">
                <h3><?= $active ?></h3>
                <p>Active</p>
            </div>
        </div>
        <div class="stat-card warning">
            <div class="stat-icon"><i class="fas fa-thumbtack"></i></div>
            <div class="stat-info">
                <h3><?= $pinned ?></h3>
                <p>Pinned</p>
            </div>
        </div>
        <div class="stat-card danger">
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-info">
                <h3><?= $expired ?></h3>
                <p>Expired</p>
            </div>
        </div>
    </div>
    
    <div class="content-container">
        <div class="section-card fade-in">
            <div class="section-header">
                <h3><i class="fas fa-list"></i> All Announcements</h3>
                <input type="text" class="search-input" id="searchAnnouncement" placeholder="Search announcements...">
            </div>
            <div class="section-body">
                <?php if (count($announcements) > 0): ?>
                    <?php foreach ($announcements as $ann): ?>
                    <div class="announcement-card <?= $ann['is_pinned'] ? 'pinned' : '' ?> <?= $ann['category'] ?>" data-title="<?= strtolower(htmlspecialchars($ann['title'])) ?>">
                        <?php if ($ann['is_pinned']): ?>
                        <div class="pin-badge"><i class="fas fa-thumbtack"></i></div>
                        <?php endif; ?>
                        <div class="announcement-header">
                            <div class="announcement-icon <?= $ann['category'] ?>">
                                <?php
                                $icons = [
                                    'general' => 'fa-info-circle',
                                    'urgent' => 'fa-exclamation-triangle',
                                    'event' => 'fa-calendar-star',
                                    'policy' => 'fa-file-alt',
                                    'holiday' => 'fa-umbrella-beach'
                                ];
                                ?>
                                <i class="fas <?= $icons[$ann['category']] ?? 'fa-info-circle' ?>"></i>
                            </div>
                            <div style="flex: 1;">
                                <div class="announcement-title"><?= htmlspecialchars($ann['title']) ?></div>
                                <div class="announcement-meta">
                                    <span><i class="fas fa-user"></i> <?= htmlspecialchars($ann['author_name']) ?></span>
                                    <span><i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($ann['created_at'])) ?></span>
                                    <span class="category-badge <?= $ann['category'] ?>"><?= ucfirst($ann['category']) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="announcement-content">
                            <?= nl2br(htmlspecialchars(substr($ann['content'], 0, 250))) ?><?= strlen($ann['content']) > 250 ? '...' : '' ?>
                        </div>
                        <div class="announcement-footer">
                            <div class="announcement-meta">
                                <span><i class="fas fa-users"></i> <?= ucfirst($ann['target_audience']) ?></span>
                                <?php if ($ann['expires_at']): ?>
                                <span><i class="fas fa-clock"></i> Expires: <?= date('M d, Y', strtotime($ann['expires_at'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="announcement-actions">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="announcement_id" value="<?= $ann['id'] ?>">
                                    <button type="submit" name="toggle_pin" class="action-btn pin" title="<?= $ann['is_pinned'] ? 'Unpin' : 'Pin' ?>">
                                        <i class="fas fa-thumbtack"></i>
                                    </button>
                                </form>
                                <button class="action-btn edit" onclick="editAnnouncement(<?= $ann['id'] ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this announcement?')">
                                    <input type="hidden" name="announcement_id" value="<?= $ann['id'] ?>">
                                    <button type="submit" name="delete_announcement" class="action-btn delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-bullhorn"></i>
                    <h4>No Announcements</h4>
                    <p>Create your first announcement to communicate with employees.</p>
                    <button class="btn btn-primary" onclick="openCreateModal()">
                        <i class="fas fa-plus"></i> Create Announcement
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Create Announcement Modal -->
    <div class="modal" id="createModal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h3><i class="fas fa-plus-circle"></i> New Announcement</h3>
                <button class="modal-close" onclick="closeCreateModal()">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" class="form-control" required placeholder="Announcement title">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Content *</label>
                        <textarea name="content" class="form-control" rows="5" required placeholder="Write your announcement here..."></textarea>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-control">
                                <option value="general">General</option>
                                <option value="urgent">Urgent</option>
                                <option value="event">Event</option>
                                <option value="policy">Policy Update</option>
                                <option value="holiday">Holiday</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Priority</label>
                            <select name="priority" class="form-control">
                                <option value="normal">Normal</option>
                                <option value="high">High</option>
                                <option value="low">Low</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Target Audience</label>
                            <select name="target_audience" class="form-control">
                                <option value="all">All Users</option>
                                <option value="employees">Employees Only</option>
                                <option value="managers">Managers Only</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Expires On (Optional)</label>
                            <input type="date" name="expires_at" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="checkbox" name="is_pinned" style="width: auto;">
                            <span>Pin this announcement</span>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
                    <button type="submit" name="create_announcement" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Publish
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Search
document.getElementById('searchAnnouncement').addEventListener('input', function() {
    const query = this.value.toLowerCase();
    document.querySelectorAll('.announcement-card').forEach(card => {
        const title = card.dataset.title;
        card.style.display = title.includes(query) ? '' : 'none';
    });
});

function openCreateModal() {
    document.getElementById('createModal').classList.add('active');
}

function closeCreateModal() {
    document.getElementById('createModal').classList.remove('active');
}

function editAnnouncement(id) {
    alert('Edit announcement ID: ' + id);
}

document.getElementById('createModal').addEventListener('click', function(e) {
    if (e.target === this) closeCreateModal();
});
</script>
</body>
</html>

<?php
/**
 * MODULE 5 SUB 8 - MY ANNOUNCEMENTS
 * HR2 MerchFlow - Employee Self-Service Portal
 * View company announcements, policies, and updates
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

$employee_id = (int)$_SESSION['user_id'];
$employee_role = $_SESSION['role'] ?? 'employee';

// Ensure announcements table exists
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

// Create read tracking table
$conn->query("CREATE TABLE IF NOT EXISTS announcement_reads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    announcement_id INT NOT NULL,
    user_id INT NOT NULL,
    read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_read (announcement_id, user_id)
)");

// Mark as read if requested
if (isset($_GET['mark_read'])) {
    $annId = intval($_GET['mark_read']);
    $stmt = $conn->prepare("INSERT IGNORE INTO announcement_reads (announcement_id, user_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $annId, $employee_id);
    $stmt->execute();
    $stmt->close();
}

// Fetch active announcements targeted at employees
$now = date('Y-m-d H:i:s');
$announcements = $conn->query("
    SELECT a.*, u.full_name as author_name, u.avatar as author_avatar,
           ar.read_at
    FROM announcements a
    JOIN users u ON a.created_by = u.id
    LEFT JOIN announcement_reads ar ON a.id = ar.announcement_id AND ar.user_id = {$employee_id}
    WHERE (a.target_audience IN ('all', 'employees') OR a.target_audience = '{$employee_role}')
    AND (a.published_at IS NULL OR a.published_at <= '{$now}')
    AND (a.expires_at IS NULL OR a.expires_at >= '{$now}')
    ORDER BY a.is_pinned DESC, a.priority DESC, a.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Stats
$total = count($announcements);
$unread = 0;
$pinned = 0;
$urgent = 0;
foreach ($announcements as $ann) {
    if (!$ann['read_at']) $unread++;
    if ($ann['is_pinned']) $pinned++;
    if ($ann['category'] === 'urgent') $urgent++;
}

// Category info helper
function getCategoryInfo($cat) {
    $map = [
        'general' => ['icon' => 'fa-info-circle', 'color' => '#3b82f6', 'bg' => '#dbeafe', 'label' => 'General'],
        'urgent' => ['icon' => 'fa-exclamation-triangle', 'color' => '#ef4444', 'bg' => '#fee2e2', 'label' => 'Urgent'],
        'event' => ['icon' => 'fa-calendar-star', 'color' => '#8b5cf6', 'bg' => '#ede9fe', 'label' => 'Event'],
        'policy' => ['icon' => 'fa-gavel', 'color' => '#f59e0b', 'bg' => '#fef3c7', 'label' => 'Policy'],
        'holiday' => ['icon' => 'fa-umbrella-beach', 'color' => '#10b981', 'bg' => '#d1fae5', 'label' => 'Holiday'],
    ];
    return $map[$cat] ?? $map['general'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Announcements | Employee Portal</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/module5_sub8.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="Css/ai_chat_bubble.css?v=<?= time(); ?>">
</head>
<body>
<?php include 'partials/sidebar.php'; ?>
<div class="main-content">
    <?php include 'partials/nav.php'; ?>

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h2><i class="fas fa-bullhorn"></i> Announcements</h2>
            <div class="subtitle">Stay updated with company news, policies, and events</div>
        </div>
        <?php if ($unread > 0): ?>
        <a href="?mark_all_read=1" class="btn-mark-all" id="markAllBtn">
            <i class="fas fa-check-double"></i> Mark All as Read (<?= $unread ?>)
        </a>
        <?php endif; ?>
    </div>

    <!-- Stats Row -->
    <div class="stats-row">
        <div class="stat-card fade-in">
            <div class="icon blue"><i class="fas fa-bullhorn"></i></div>
            <div>
                <div class="value"><?= $total ?></div>
                <div class="label">Total Active</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon purple"><i class="fas fa-envelope"></i></div>
            <div>
                <div class="value"><?= $unread ?></div>
                <div class="label">Unread</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon yellow"><i class="fas fa-thumbtack"></i></div>
            <div>
                <div class="value"><?= $pinned ?></div>
                <div class="label">Pinned</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon red"><i class="fas fa-exclamation-circle"></i></div>
            <div>
                <div class="value"><?= $urgent ?></div>
                <div class="label">Urgent</div>
            </div>
        </div>
    </div>

    <!-- Announcements Feed -->
    <div class="announcements-feed">
        <?php if (empty($announcements)): ?>
        <div class="empty-state">
            <i class="fas fa-newspaper"></i>
            <h3>No Announcements</h3>
            <p>There are no active announcements at this time. Check back later!</p>
        </div>
        <?php else: ?>
            <?php foreach ($announcements as $ann): 
                $catInfo = getCategoryInfo($ann['category']);
                $isRead = !empty($ann['read_at']);
                $isPinned = $ann['is_pinned'];
                $timeAgo = time() - strtotime($ann['created_at']);
                if ($timeAgo < 3600) $timeLabel = floor($timeAgo / 60) . 'm ago';
                elseif ($timeAgo < 86400) $timeLabel = floor($timeAgo / 3600) . 'h ago';
                elseif ($timeAgo < 604800) $timeLabel = floor($timeAgo / 86400) . 'd ago';
                else $timeLabel = date('M d, Y', strtotime($ann['created_at']));
            ?>
            <div class="announcement-card <?= !$isRead ? 'unread' : '' ?> <?= $isPinned ? 'pinned' : '' ?> <?= $ann['category'] === 'urgent' ? 'urgent' : '' ?> fade-in"
                 onclick="openAnnouncement(<?= $ann['id'] ?>)">
                
                <?php if ($isPinned): ?>
                <div class="pin-badge"><i class="fas fa-thumbtack"></i> Pinned</div>
                <?php endif; ?>

                <?php if (!$isRead): ?>
                <div class="unread-dot"></div>
                <?php endif; ?>

                <div class="announcement-header">
                    <div class="category-badge" style="background: <?= $catInfo['bg'] ?>; color: <?= $catInfo['color'] ?>;">
                        <i class="fas <?= $catInfo['icon'] ?>"></i>
                        <?= $catInfo['label'] ?>
                    </div>
                    <?php if ($ann['priority'] === 'high'): ?>
                    <span class="priority-badge high"><i class="fas fa-arrow-up"></i> High Priority</span>
                    <?php endif; ?>
                    <span class="time-label"><i class="fas fa-clock"></i> <?= $timeLabel ?></span>
                </div>

                <h3 class="announcement-title"><?= htmlspecialchars($ann['title']) ?></h3>
                
                <div class="announcement-preview">
                    <?= nl2br(htmlspecialchars(mb_strimwidth(strip_tags($ann['content']), 0, 200, '...'))) ?>
                </div>

                <div class="announcement-footer">
                    <div class="author">
                        <div class="author-avatar">
                            <?php if ($ann['author_avatar']): ?>
                                <img src="<?= $ann['author_avatar'] ?>" alt="">
                            <?php else: ?>
                                <i class="fas fa-user"></i>
                            <?php endif; ?>
                        </div>
                        <span><?= htmlspecialchars($ann['author_name'] ?? 'Admin') ?></span>
                    </div>
                    <span class="read-more">Read More <i class="fas fa-arrow-right"></i></span>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Announcement Detail Modal -->
<div class="modal-overlay" id="announcementModal">
    <div class="modal">
        <div class="modal-header" id="modalHeader">
            <h3 id="modalTitle"></h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="modal-meta" id="modalMeta"></div>
            <div class="modal-content" id="modalContent"></div>
        </div>
    </div>
</div>

<?php include 'partials/ai_chat.php'; ?>

<script>
// Store announcement data for modal
const announcements = <?= json_encode($announcements) ?>;

function openAnnouncement(id) {
    const ann = announcements.find(a => a.id == id);
    if (!ann) return;

    document.getElementById('modalTitle').textContent = ann.title;
    
    const catColors = {
        general: '#3b82f6', urgent: '#ef4444', event: '#8b5cf6', 
        policy: '#f59e0b', holiday: '#10b981'
    };
    const catLabels = {
        general: 'General', urgent: 'Urgent', event: 'Event', 
        policy: 'Policy', holiday: 'Holiday'
    };
    
    document.getElementById('modalHeader').style.background = 
        `linear-gradient(135deg, ${catColors[ann.category] || '#3b82f6'}, ${catColors[ann.category] || '#3b82f6'}dd)`;
    
    const date = new Date(ann.created_at).toLocaleDateString('en-US', { 
        year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' 
    });
    
    document.getElementById('modalMeta').innerHTML = `
        <span class="meta-item"><i class="fas fa-tag"></i> ${catLabels[ann.category] || 'General'}</span>
        <span class="meta-item"><i class="fas fa-user"></i> ${ann.author_name || 'Admin'}</span>
        <span class="meta-item"><i class="fas fa-calendar"></i> ${date}</span>
    `;
    
    document.getElementById('modalContent').innerHTML = ann.content.replace(/\n/g, '<br>');
    document.getElementById('announcementModal').classList.add('show');

    // Mark as read
    if (!ann.read_at) {
        fetch(`?mark_read=${id}`, { method: 'GET' });
        // Update UI
        const card = document.querySelector(`.announcement-card[onclick="openAnnouncement(${id})"]`);
        if (card) {
            card.classList.remove('unread');
            const dot = card.querySelector('.unread-dot');
            if (dot) dot.remove();
        }
    }
}

function closeModal() {
    document.getElementById('announcementModal').classList.remove('show');
}

// Close modal on overlay click
document.getElementById('announcementModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// Mark all as read
const markAllBtn = document.getElementById('markAllBtn');
if (markAllBtn) {
    markAllBtn.addEventListener('click', function(e) {
        e.preventDefault();
        announcements.forEach(ann => {
            if (!ann.read_at) fetch(`?mark_read=${ann.id}`);
        });
        document.querySelectorAll('.unread-dot').forEach(d => d.remove());
        document.querySelectorAll('.announcement-card.unread').forEach(c => c.classList.remove('unread'));
        this.style.display = 'none';
    });
}
</script>
</body>
</html>

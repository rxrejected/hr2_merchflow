<?php
/**
 * MODULE 5 SUB 3 - MY COURSES
 * HR2 MerchFlow - Employee Self-Service Portal
 * View assigned courses and track learning progress
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

$employee_id = (int)$_SESSION['user_id'];
$from_hr1 = isset($_SESSION['from_hr1']) && $_SESSION['from_hr1'] === true;

// Check if course_progress table exists
$cpTableExists = $conn->query("SHOW TABLES LIKE 'course_progress'")->num_rows > 0;
$has_last_watched = false;

if ($cpTableExists) {
    $cp_columns = $conn->query("SHOW COLUMNS FROM course_progress");
    while ($col = $cp_columns->fetch_assoc()) {
        if ($col['Field'] === 'last_watched') {
            $has_last_watched = true;
            break;
        }
    }
}

// Check if courses table exists
$tables_check = $conn->query("SHOW TABLES LIKE 'courses'");
$has_courses_table = $tables_check->num_rows > 0;

$courses = [];

if ($has_courses_table && $cpTableExists) {
    // Check courses table columns
    $courses_columns = $conn->query("SHOW COLUMNS FROM courses");
    $courses_has_created_at = false;
    $courses_id_column = 'id'; // default
    $course_columns_list = [];
    while ($col = $courses_columns->fetch_assoc()) {
        $course_columns_list[] = $col['Field'];
        if ($col['Field'] === 'created_at') {
            $courses_has_created_at = true;
        }
        if ($col['Key'] === 'PRI') {
            $courses_id_column = $col['Field'];
        }
    }
    
    // Check if id column exists, otherwise use course_id
    if (!in_array('id', $course_columns_list) && in_array('course_id', $course_columns_list)) {
        $courses_id_column = 'course_id';
    }
    
    // Fetch all courses with progress
    $order_clause = $courses_has_created_at ? "ORDER BY c.created_at DESC" : "ORDER BY c.$courses_id_column DESC";
    $last_watched_col = $has_last_watched ? ', cp.last_watched' : '';
    
    $courses_query = "
        SELECT 
            c.*,
            COALESCE(cp.watched_percent, 0) as progress
            $last_watched_col
        FROM courses c
        LEFT JOIN course_progress cp ON c.$courses_id_column = cp.course_id AND cp.employee_id = ?
        $order_clause
    ";
    $stmt = $conn->prepare($courses_query);
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Normalize course id field for template
    foreach ($courses as &$course) {
        if (!isset($course['id']) && isset($course[$courses_id_column])) {
            $course['id'] = $course[$courses_id_column];
        }
    }
    unset($course);
} else {
    // No courses table - get progress records only
    $courses = [];
}

// Calculate stats
$total_courses = count($courses);
$completed_courses = 0;
$in_progress = 0;
$total_progress = 0;

foreach ($courses as $course) {
    $total_progress += $course['progress'];
    if ($course['progress'] >= 100) {
        $completed_courses++;
    } elseif ($course['progress'] > 0) {
        $in_progress++;
    }
}

$avg_progress = $total_courses > 0 ? round($total_progress / $total_courses) : 0;

// Update progress via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_progress'])) {
    $course_id = intval($_POST['course_id']);
    $progress = min(100, max(0, intval($_POST['progress'])));
    
    $check = $conn->prepare("SELECT id FROM course_progress WHERE employee_id = ? AND course_id = ?");
    $check->bind_param("ii", $employee_id, $course_id);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();
    
    if ($exists) {
        if ($has_last_watched) {
            $update = $conn->prepare("UPDATE course_progress SET watched_percent = ?, last_watched = NOW() WHERE employee_id = ? AND course_id = ?");
        } else {
            $update = $conn->prepare("UPDATE course_progress SET watched_percent = ? WHERE employee_id = ? AND course_id = ?");
        }
        $update->bind_param("iii", $progress, $employee_id, $course_id);
    } else {
        if ($has_last_watched) {
            $update = $conn->prepare("INSERT INTO course_progress (employee_id, course_id, watched_percent, last_watched) VALUES (?, ?, ?, NOW())");
        } else {
            $update = $conn->prepare("INSERT INTO course_progress (employee_id, course_id, watched_percent) VALUES (?, ?, ?)");
        }
        $update->bind_param("iii", $employee_id, $course_id, $progress);
    }
    $update->execute();
    $update->close();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>My Courses | Employee Portal</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/module5_sub3.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="Css/ai_chat_bubble.css?v=<?= time(); ?>">
</head>
<body>
<?php include 'partials/sidebar.php'; ?>
<div class="main-content">
    <?php include 'partials/nav.php'; ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h2><i class="fas fa-book"></i> My Courses</h2>
            <div class="subtitle">Track your courses and learning progress</div>
        </div>
    </div>
    
    <!-- Stats Row -->
    <div class="stats-row">
        <div class="stat-card fade-in">
            <div class="icon blue"><i class="fas fa-book-open"></i></div>
            <div>
                <div class="value"><?= $total_courses ?></div>
                <div class="label">Total Courses</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon green"><i class="fas fa-check-circle"></i></div>
            <div>
                <div class="value"><?= $completed_courses ?></div>
                <div class="label">Completed</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon yellow"><i class="fas fa-spinner"></i></div>
            <div>
                <div class="value"><?= $in_progress ?></div>
                <div class="label">In Progress</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon purple"><i class="fas fa-percentage"></i></div>
            <div>
                <div class="value"><?= $avg_progress ?>%</div>
                <div class="label">Avg Progress</div>
            </div>
        </div>
    </div>
    
    <div class="content-container">
        <div class="section-card fade-in">
            <div class="section-header">
                <h3><i class="fas fa-play-circle"></i> My Courses</h3>
                <input type="text" class="search-input" id="searchCourse" placeholder="Search courses...">
            </div>
            <div class="section-body">
                <!-- Filter Tabs -->
                <div class="filter-tabs">
                    <button class="filter-tab active" data-filter="all">All Courses</button>
                    <button class="filter-tab" data-filter="completed">Completed</button>
                    <button class="filter-tab" data-filter="in-progress">In Progress</button>
                    <button class="filter-tab" data-filter="not-started">Not Started</button>
                </div>
                
                <?php if (count($courses) > 0): ?>
                <div class="course-grid" id="courseGrid">
                    <?php foreach ($courses as $course): 
                        $status = 'not-started';
                        if ($course['progress'] >= 100) $status = 'completed';
                        elseif ($course['progress'] > 0) $status = 'in-progress';
                        
                        // Parse video_path to determine type
                        $vpath = $course['video_path'] ?? '';
                        $videoType = 'none';
                        $videoSrc = '';
                        if (strpos($vpath, 'youtube:') === 0) {
                            $videoType = 'youtube';
                            $videoSrc = substr($vpath, 8);
                        } elseif (strpos($vpath, 'gdrive:') === 0) {
                            $videoType = 'gdrive';
                            $videoSrc = substr($vpath, 7);
                        } elseif (strpos($vpath, 'vimeo:') === 0) {
                            $videoType = 'vimeo';
                            $videoSrc = substr($vpath, 6);
                        } elseif (strpos($vpath, 'url:') === 0) {
                            $videoType = 'url';
                            $videoSrc = substr($vpath, 4);
                        } elseif (!empty($vpath)) {
                            $videoType = 'local';
                            $videoSrc = $vpath;
                        }
                        
                        // Generate thumbnail based on type
                        $thumbnail = 'uploads/courses/default.jpg';
                        if ($videoType === 'youtube') {
                            $thumbnail = "https://img.youtube.com/vi/{$videoSrc}/hqdefault.jpg";
                        }
                        
                        $skillType = $course['skill_type'] ?? 'General';
                        $trainingType = $course['training_type'] ?? 'Theoretical';
                    ?>
                    <div class="course-card" data-status="<?= $status ?>" data-title="<?= strtolower(htmlspecialchars($course['title'])) ?>">
                        <img src="<?= htmlspecialchars($thumbnail) ?>" alt="<?= htmlspecialchars($course['title']) ?>" class="course-thumbnail" onerror="this.src='uploads/courses/default.jpg'">
                        <div class="course-content">
                            <span class="course-category"><?= htmlspecialchars($skillType) ?> &bull; <?= htmlspecialchars($trainingType) ?></span>
                            <h4 class="course-title"><?= htmlspecialchars($course['title']) ?></h4>
                            <div class="course-meta">
                                <span><i class="fas fa-video"></i> <?= ucfirst($videoType) ?></span>
                                <?php if (isset($course['last_watched']) && !empty($course['last_watched'])): ?>
                                <span><i class="fas fa-history"></i> <?= date('M d', strtotime($course['last_watched'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="course-progress-bar">
                                <div class="course-progress-fill <?= $status ?>" style="width: <?= $course['progress'] ?>%"></div>
                            </div>
                            <div class="course-footer">
                                <span class="course-progress-text"><?= $course['progress'] ?>% Complete</span>
                                <?php if ($status === 'completed'): ?>
                                <span class="badge badge-success"><i class="fas fa-check"></i> Done</span>
                                <?php else: ?>
                                <button class="btn btn-primary btn-sm" onclick="openCourse(<?= $course['id'] ?>, '<?= $videoType ?>', '<?= htmlspecialchars($videoSrc) ?>')">
                                    <?= $status === 'in-progress' ? 'Continue' : 'Start' ?>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-book"></i>
                    <h4>No Courses Available</h4>
                    <p>There are no courses assigned to you yet. Check back later!</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Course Player Modal -->
    <div class="modal-overlay" id="courseModal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">
                <h3 id="courseModalTitle"><i class="fas fa-play-circle"></i> Course Player</h3>
                <button class="modal-close" onclick="closeCourse()">&times;</button>
            </div>
            <div class="modal-body" style="padding: 0;">
                <div id="coursePlayer" style="aspect-ratio: 16/9; background: #000; display: flex; align-items: center; justify-content: center;">
                    <div style="text-align: center; color: white;">
                        <i class="fas fa-play-circle" style="font-size: 4rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                        <p>Video player will load here</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content: space-between;">
                <div>
                    <span style="color: var(--text-secondary);">Progress: </span>
                    <strong id="currentProgress">0</strong>%
                </div>
                <div style="display: flex; gap: 0.5rem;">
                    <button class="btn btn-success btn-sm" onclick="markComplete()">
                        <i class="fas fa-check"></i> Mark Complete
                    </button>
                    <button class="btn btn-secondary" onclick="closeCourse()">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentCourseId = null;

// Filter functionality
document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        
        const filter = this.dataset.filter;
        document.querySelectorAll('.course-card').forEach(card => {
            if (filter === 'all' || card.dataset.status === filter) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
    });
});

// Search functionality
document.getElementById('searchCourse').addEventListener('input', function() {
    const query = this.value.toLowerCase();
    document.querySelectorAll('.course-card').forEach(card => {
        const title = card.dataset.title;
        card.style.display = title.includes(query) ? '' : 'none';
    });
});

function openCourse(courseId, videoType, videoSrc) {
    currentCourseId = courseId;
    document.getElementById('courseModal').classList.add('active');
    
    const player = document.getElementById('coursePlayer');
    
    if (!videoSrc) {
        player.innerHTML = '<div style="text-align:center;color:white;padding:2rem;"><i class="fas fa-exclamation-triangle" style="font-size:3rem;margin-bottom:1rem;opacity:0.5;"></i><p>No video available for this course</p></div>';
        return;
    }
    
    switch(videoType) {
        case 'youtube':
            player.innerHTML = `<iframe src="https://www.youtube.com/embed/${videoSrc}?autoplay=1&rel=0" style="width:100%;height:100%;border:none;" allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture" allowfullscreen></iframe>`;
            break;
        case 'gdrive':
            player.innerHTML = `<iframe src="https://drive.google.com/file/d/${videoSrc}/preview" style="width:100%;height:100%;border:none;" allow="autoplay" allowfullscreen></iframe>`;
            break;
        case 'vimeo':
            player.innerHTML = `<iframe src="https://player.vimeo.com/video/${videoSrc}?autoplay=1" style="width:100%;height:100%;border:none;" allow="autoplay;fullscreen" allowfullscreen></iframe>`;
            break;
        case 'url':
        case 'local':
        default:
            player.innerHTML = `<video controls autoplay style="width:100%;height:100%;" id="videoElement"><source src="${videoSrc}" type="video/mp4">Your browser does not support the video tag.</video>`;
            break;
    }
}

function closeCourse() {
    document.getElementById('courseModal').classList.remove('active');
    currentCourseId = null;
}

function markComplete() {
    if (!currentCourseId) return;
    
    fetch('module5_sub3.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `update_progress=1&course_id=${currentCourseId}&progress=100`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        }
    });
}

// Close modal on outside click
document.getElementById('courseModal').addEventListener('click', function(e) {
    if (e.target === this) closeCourse();
});
</script>
<?php include 'partials/ai_chat.php'; ?>
</body>
</html>

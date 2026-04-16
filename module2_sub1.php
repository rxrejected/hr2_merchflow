<?php
// Include centralized session handler (handles session start, timeout, and activity tracking)
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

// Admin role guard
$userRole = strtolower(str_replace(' ', '', $_SESSION['role'] ?? ''));
if (!in_array($userRole, ['admin', 'manager', 'superadmin'])) {
    header('Location: employee.php');
    exit();
}

// Initialize message variables
$success_msg = '';
$error_msg = '';

/* ==========================
   ADD COURSE
========================== */
if(isset($_POST['add_course'])){
    try {
        // Initialize file path
        $file_path = '';
        
        // Check for external URL first
        if(!empty($_POST['external_url'])){
            $external_url = filter_var($_POST['external_url'], FILTER_VALIDATE_URL);
            if($external_url){
                // Convert YouTube URL to embed format
                if(preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $external_url, $matches)){
                    $file_path = 'youtube:' . $matches[1];
                }
                // Convert Google Drive URL to embed format
                elseif(preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $external_url, $matches)){
                    $file_path = 'gdrive:' . $matches[1];
                }
                // Convert Vimeo URL
                elseif(preg_match('/vimeo\.com\/(\d+)/', $external_url, $matches)){
                    $file_path = 'vimeo:' . $matches[1];
                }
                // Direct URL (mp4, pdf, etc)
                else {
                    $file_path = 'url:' . $external_url;
                }
            } else {
                throw new Exception("Invalid URL format.");
            }
        }
        // Process file upload if no external URL
        elseif(!empty($_FILES['file']['name'])){
            // Check for upload errors
            if($_FILES['file']['error'] !== UPLOAD_ERR_OK){
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'File exceeds PHP upload_max_filesize limit (check php.ini)',
                    UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE',
                    UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                    UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk - check permissions',
                    UPLOAD_ERR_EXTENSION => 'Upload stopped by PHP extension'
                ];
                throw new Exception($uploadErrors[$_FILES['file']['error']] ?? 'Unknown upload error (code: '.$_FILES['file']['error'].')');
            }
            
            // Check file size (max 50MB)
            $maxSize = 50 * 1024 * 1024;
            if($_FILES['file']['size'] > $maxSize){
                throw new Exception("File size exceeds 50MB limit. Your file: " . round($_FILES['file']['size']/1024/1024, 2) . "MB");
            }
            
            $upload_dir = "uploads/";
            if(!is_dir($upload_dir)){
                if(!mkdir($upload_dir, 0755, true)){
                    throw new Exception("Failed to create uploads directory.");
                }
            }
            
            // Check if uploads directory is writable
            if(!is_writable($upload_dir)){
                throw new Exception("Uploads directory is not writable. Check folder permissions.");
            }
            
            $file_name = time().'_'.preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['file']['name']));
            $target_file = $upload_dir.$file_name;
            $fileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

            // Accept video/mp4 and pdf only
            if(in_array($fileType, ['mp4','pdf'])){
                if(move_uploaded_file($_FILES['file']['tmp_name'], $target_file)){
                    $file_path = $target_file;
                } else {
                    throw new Exception("Failed to move uploaded file. Temp: ".$_FILES['file']['tmp_name']." Target: ".$target_file);
                }
            } else {
                throw new Exception("Invalid file type '{$fileType}'. Only MP4 and PDF are allowed.");
            }
        }

        // Now insert into database with the file path
        $stmt = $conn->prepare(
            "INSERT INTO courses (title, description, video_path, skill_type, training_type)
             VALUES (?, ?, ?, ?, ?)"
        );
        
        if(!$stmt){
            throw new Exception("Database prepare failed: " . $conn->error);
        }

        $title = $_POST['title'];
        $description = $_POST['description'];
        $skill_type = $_POST['skill_type'];
        $training_type = $_POST['training_type'];

        $stmt->bind_param(
            "sssss",
            $title,
            $description,
            $file_path,
            $skill_type,
            $training_type
        );
        
        if($stmt->execute()){
            $success_msg = "Course added successfully!" . ($file_path ? " File saved: " . basename($file_path) : " (No file uploaded)");
        } else {
            throw new Exception("Database insert failed: " . $stmt->error);
        }
        $stmt->close();
    } catch(Exception $e){
        $error_msg = $e->getMessage();
    }
}

/* ==========================
   EDIT COURSE
========================== */
if(isset($_POST['edit_course'])){
    try {
        $stmt = $conn->prepare(
            "UPDATE courses SET title=?, description=?, skill_type=?, training_type=? WHERE course_id=?"
        );
        $stmt->bind_param(
            "ssssi",
            $_POST['title'],
            $_POST['description'],
            $_POST['skill_type'],
            $_POST['training_type'],
            $_POST['course_id']
        );
        $stmt->execute();
        $stmt->close();

        // Handle file replacement
        if(!empty($_FILES['file']['name'])){
            $maxSize = 50 * 1024 * 1024;
            if($_FILES['file']['size'] > $maxSize){
                throw new Exception("File size exceeds 50MB limit.");
            }
            
            $upload_dir = "uploads/";
            if(!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            
            $file_name = time().'_'.preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['file']['name']));
            $target_file = $upload_dir.$file_name;
            $fileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

            if(in_array($fileType, ['mp4','pdf'])){
                // Delete old file if exists
                $old = $conn->query("SELECT video_path FROM courses WHERE course_id=".(int)$_POST['course_id'])->fetch_assoc();
                if($old && $old['video_path'] && file_exists($old['video_path'])){
                    unlink($old['video_path']);
                }
                
                if(move_uploaded_file($_FILES['file']['tmp_name'], $target_file)){
                    $file_path = $target_file;
                    $conn->query("UPDATE courses SET video_path='".mysqli_real_escape_string($conn, $file_path)."' WHERE course_id=".(int)$_POST['course_id']);
                }
            }
        }

        $success_msg = "Course updated successfully!";
    } catch(Exception $e){
        $error_msg = $e->getMessage();
    }
}

/* ==========================
   DELETE COURSE
========================== */
if(isset($_POST['delete_course'])){
    try {
        // Delete associated file
        $course = $conn->query("SELECT video_path FROM courses WHERE course_id=".(int)$_POST['course_id'])->fetch_assoc();
        if($course && $course['video_path'] && file_exists($course['video_path'])){
            unlink($course['video_path']);
        }
        
        $stmt = $conn->prepare("DELETE FROM courses WHERE course_id=?");
        $stmt->bind_param("i", $_POST['course_id']);
        if($stmt->execute()){
            $success_msg = "Course deleted successfully!";
        }
        $stmt->close();
    } catch(Exception $e){
        $error_msg = $e->getMessage();
    }
}

// FETCH COURSES with search/filter support
$where = "1=1";
$params = [];
$types = "";

if(!empty($_GET['search'])){
    $search = '%'.$_GET['search'].'%';
    $where .= " AND (title LIKE ? OR description LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $types .= "ss";
}
if(!empty($_GET['skill'])){
    $where .= " AND skill_type = ?";
    $params[] = $_GET['skill'];
    $types .= "s";
}
if(!empty($_GET['training'])){
    $where .= " AND training_type = ?";
    $params[] = $_GET['training'];
    $types .= "s";
}

$query = "SELECT * FROM courses WHERE $where ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
if($params){
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$courses = $stmt->get_result();

// Get statistics
$totalCourses = $conn->query("SELECT COUNT(*) as cnt FROM courses")->fetch_assoc()['cnt'] ?? 0;
$softSkills = $conn->query("SELECT COUNT(*) as cnt FROM courses WHERE skill_type = 'Soft'")->fetch_assoc()['cnt'] ?? 0;
$hardSkills = $conn->query("SELECT COUNT(*) as cnt FROM courses WHERE skill_type = 'Hard'")->fetch_assoc()['cnt'] ?? 0;
$theoretical = $conn->query("SELECT COUNT(*) as cnt FROM courses WHERE training_type = 'Theoretical'")->fetch_assoc()['cnt'] ?? 0;
$actual = $conn->query("SELECT COUNT(*) as cnt FROM courses WHERE training_type = 'Actual'")->fetch_assoc()['cnt'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="description" content="Learning Management System - Course Library">
<meta name="theme-color" content="#e11d48">
<title>Learning Management | Course</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="icon" href="osicon.png">
<link rel="stylesheet" href="Css/module2_sub1.css?v=<?= time(); ?>">
<link rel="stylesheet" href="Css/ai_chat_bubble.css?v=<?= time(); ?>">
</head>
<body>
<?php include 'partials/sidebar.php'; ?>
<div class="main-content">
<?php include 'partials/nav.php'; ?>

<!-- Page Header -->
<div class="page-header">
  <div class="header-content">
    <div class="header-icon">
      <i class="fas fa-chart-bar"></i>
    </div>
    <div class="header-text">
      <h1>Courses</h1>
    </div>
  </div>
  <div class="header-actions">
    <button class="header-btn ai-btn" type="button" onclick="generateAIRecommendations()">
      <i class="fas fa-robot"></i>
      <span>AI Analysis</span>
    </button>
    <button class="header-btn" type="button" onclick="exportData()" title="Export">
      <i class="fas fa-download"></i>
      <span>Export</span>
    </button>
    <button class="header-btn primary" type="button" onclick="openModal('addCourseModal')">
      <i class="fas fa-plus"></i>
      <span>Add Course</span>
    </button>
  </div>
</div>

<!-- Statistics Banner -->
<div class="stats-grid">
  <div class="stat-card total">
    <div class="stat-icon">
      <i class="fas fa-graduation-cap"></i>
    </div>
    <div class="stat-info">
      <h3><?= number_format($totalCourses) ?></h3>
      <p>Total Courses</p>
    </div>
    <div class="stat-trend up">
      <i class="fas fa-chart-line"></i>
    </div>
  </div>
  
  <div class="stat-card soft">
    <div class="stat-icon">
      <i class="fas fa-user-friends"></i>
    </div>
    <div class="stat-info">
      <h3><?= number_format($softSkills) ?></h3>
      <p>Soft Skills</p>
    </div>

  </div>
  
  <div class="stat-card hard">
    <div class="stat-icon">
      <i class="fas fa-tools"></i>
    </div>
    <div class="stat-info">
      <h3><?= number_format($hardSkills) ?></h3>
      <p>Hard Skills</p>
    </div>

  </div>
  
  <div class="stat-card theory">
    <div class="stat-icon">
      <i class="fas fa-chalkboard-teacher"></i>
    </div>
    <div class="stat-info">
      <h3><?= number_format($theoretical) ?></h3>
      <p>Theoretical</p>
    </div>
    <div class="stat-badge">
      <span><?= $totalCourses ? round(($theoretical/$totalCourses)*100) : 0 ?>%</span>
    </div>
  </div>
  
  <div class="stat-card actual">
    <div class="stat-icon">
      <i class="fas fa-hands-helping"></i>
    </div>
    <div class="stat-info">
      <h3><?= number_format($actual) ?></h3>
      <p>Actual Training</p>
    </div>
    <div class="stat-badge">
      <span><?= $totalCourses ? round(($actual/$totalCourses)*100) : 0 ?>%</span>
    </div>
  </div>
</div>

<!-- Filter & Search Section -->
<div class="toolbar">
  <div class="search-wrapper">
    <i class="fas fa-search"></i>
    <input type="text" id="searchInput" placeholder="Search courses by title or description..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
    <button class="search-clear" id="clearSearch" style="display: none;">
      <i class="fas fa-times"></i>
    </button>
  </div>
  
  <div class="filter-group">
    <div class="filter-item">
      <label><i class="fas fa-brain"></i> Skill Type</label>
      <select id="skillFilter">
        <option value="">All Skills</option>
        <option value="Soft" <?= ($_GET['skill'] ?? '') == 'Soft' ? 'selected' : '' ?>>Soft Skills</option>
        <option value="Hard" <?= ($_GET['skill'] ?? '') == 'Hard' ? 'selected' : '' ?>>Hard Skills</option>
      </select>
    </div>
    
    <div class="filter-item">
      <label><i class="fas fa-chalkboard"></i> Training Type</label>
      <select id="trainingFilter">
        <option value="">All Training</option>
        <option value="Theoretical" <?= ($_GET['training'] ?? '') == 'Theoretical' ? 'selected' : '' ?>>Theoretical</option>
        <option value="Actual" <?= ($_GET['training'] ?? '') == 'Actual' ? 'selected' : '' ?>>Actual</option>
      </select>
    </div>
    
    <button class="filter-reset" id="resetFilters" title="Reset Filters">
      <i class="fas fa-undo"></i>
      <span>Reset</span>
    </button>
  </div>
</div>

<!-- Results Info -->
<div class="results-bar">
  <div class="results-count">
    <i class="fas fa-list"></i>
    Showing <strong id="visibleCount"><?= $courses->num_rows ?></strong> of <strong><?= $totalCourses ?></strong> courses
  </div>
  <div class="view-toggle">
    <button class="view-btn active" data-view="table" title="Table View">
      <i class="fas fa-table"></i>
    </button>
    <button class="view-btn" data-view="grid" title="Grid View">
      <i class="fas fa-th-large"></i>
    </button>
  </div>
</div>

<!-- Course Table -->
<div class="table-container" id="tableView">
  <table>
    <thead>
      <tr>
        <th class="sortable" data-sort="title">
          <i class="fas fa-book"></i> Course
          <i class="fas fa-sort sort-icon"></i>
        </th>
        <th><i class="fas fa-tags"></i> Classification</th>
        <th><i class="fas fa-file-alt"></i> Media</th>
        <th class="sortable" data-sort="date">
          <i class="fas fa-calendar"></i> Created
          <i class="fas fa-sort sort-icon"></i>
        </th>
        <th><i class="fas fa-cogs"></i> Actions</th>
      </tr>
    </thead>
    <tbody id="courseTable">
    <?php if($courses->num_rows > 0): ?>
      <?php $modals_html = ''; ?>
      <?php while($row = $courses->fetch_assoc()): ?>
      <tr data-skill="<?= htmlspecialchars($row['skill_type']) ?>" 
          data-training="<?= htmlspecialchars($row['training_type']) ?>" 
          data-title="<?= strtolower(htmlspecialchars($row['title'])) ?>"
          data-date="<?= $row['created_at'] ?>">
        <td data-label="Course">
          <div class="course-cell">
            <div class="course-avatar <?= strtolower($row['skill_type']) ?>">
              <i class="fas fa-<?= $row['skill_type'] == 'Soft' ? 'user-tie' : 'cogs' ?>"></i>
            </div>
            <div class="course-info">
              <strong class="course-title"><?= htmlspecialchars($row['title']) ?></strong>
              <p class="course-desc"><?= htmlspecialchars(substr($row['description'], 0, 80)) ?><?= strlen($row['description']) > 80 ? '...' : '' ?></p>
            </div>
          </div>
        </td>

        <td data-label="Classification">
          <div class="badge-stack">
            <span class="badge <?= strtolower($row['skill_type']) ?>">
              <i class="fas fa-<?= $row['skill_type'] == 'Soft' ? 'heart' : 'wrench' ?>"></i>
              <?= $row['skill_type'] ?>
            </span>
            <span class="badge <?= strtolower($row['training_type']) ?>">
              <i class="fas fa-<?= $row['training_type'] == 'Theoretical' ? 'book-reader' : 'hand-paper' ?>"></i>
              <?= $row['training_type'] ?>
            </span>
          </div>
        </td>

        <td data-label="Media">
          <?php 
          $videoPath = $row['video_path'];
          $isExternal = strpos($videoPath, ':') !== false && in_array(explode(':', $videoPath)[0], ['youtube', 'gdrive', 'vimeo', 'url']);
          
          if($isExternal):
            $parts = explode(':', $videoPath, 2);
            $mediaType = $parts[0];
            $mediaId = $parts[1];
          ?>
            <div class="media-cell">
              <?php if($mediaType == 'youtube'): ?>
                <div class="media-thumb youtube" onclick="previewMedia('<?= $mediaId ?>', 'youtube')">
                  <i class="fab fa-youtube"></i>
                  <span>YouTube</span>
                </div>
              <?php elseif($mediaType == 'gdrive'): ?>
                <div class="media-thumb gdrive" onclick="previewMedia('<?= $mediaId ?>', 'gdrive')">
                  <i class="fab fa-google-drive"></i>
                  <span>Drive</span>
                </div>
              <?php elseif($mediaType == 'vimeo'): ?>
                <div class="media-thumb vimeo" onclick="previewMedia('<?= $mediaId ?>', 'vimeo')">
                  <i class="fab fa-vimeo-v"></i>
                  <span>Vimeo</span>
                </div>
              <?php else: ?>
                <div class="media-thumb url" onclick="previewMedia('<?= htmlspecialchars($mediaId) ?>', 'url')">
                  <i class="fas fa-external-link-alt"></i>
                  <span>Link</span>
                </div>
              <?php endif; ?>
              <small class="file-size">External</small>
            </div>
          <?php elseif($videoPath && file_exists($videoPath)): 
            $ext = strtolower(pathinfo($videoPath, PATHINFO_EXTENSION));
            $fileSize = filesize($videoPath);
            $fileSizeFormatted = $fileSize > 1048576 ? round($fileSize/1048576, 1).'MB' : round($fileSize/1024).'KB';
          ?>
            <div class="media-cell">
              <?php if($ext == 'mp4'): ?>
                <div class="media-thumb video" onclick="previewMedia('<?= htmlspecialchars($videoPath) ?>', 'video')">
                  <i class="fas fa-play-circle"></i>
                  <span>Video</span>
                </div>
              <?php else: ?>
                <div class="media-thumb pdf" onclick="previewMedia('<?= htmlspecialchars($videoPath) ?>', 'pdf')">
                  <i class="fas fa-file-pdf"></i>
                  <span>PDF</span>
                </div>
              <?php endif; ?>
              <small class="file-size"><?= $fileSizeFormatted ?></small>
            </div>
          <?php else: ?>
            <div class="no-media">
              <i class="fas fa-image-slash"></i>
              <span>No media</span>
            </div>
          <?php endif; ?>
        </td>

        <td data-label="Created">
          <div class="date-cell">
            <i class="far fa-calendar-alt"></i>
            <div class="date-info">
              <span class="date-main"><?= date('M d, Y', strtotime($row['created_at'])) ?></span>
              <small class="date-time"><?= date('h:i A', strtotime($row['created_at'])) ?></small>
            </div>
          </div>
        </td>

        <td data-label="Actions">
          <div class="action-btns">
            <?php if($row['video_path'] && file_exists($row['video_path'])): ?>
            <button type="button" class="btn-icon view" onclick="previewMedia('<?= htmlspecialchars($row['video_path']) ?>', '<?= $ext ?>')" title="Preview">
              <i class="fas fa-eye"></i>
            </button>
            <?php endif; ?>
            <button type="button" class="btn-icon edit" onclick="openModal('editCourse<?= $row['course_id'] ?>')" title="Edit">
              <i class="fas fa-edit"></i>
            </button>
            <button type="button" class="btn-icon delete" onclick="openModal('deleteCourse<?= $row['course_id'] ?>')" title="Delete">
              <i class="fas fa-trash-alt"></i>
            </button>
          </div>
        </td>
      </tr>

      <?php ob_start(); ?>
      <!-- EDIT MODAL -->
      <div class="modal" id="editCourse<?= $row['course_id'] ?>">
        <div class="modal-content">
          <form action="" method="post" enctype="multipart/form-data">
            <input type="hidden" name="course_id" value="<?= $row['course_id'] ?>">
            <div class="modal-header">
              <h4><i class="fas fa-edit"></i> Edit Course</h4>
              <button type="button" class="modal-close" onclick="closeModal('editCourse<?= $row['course_id'] ?>')">
                <i class="fas fa-times"></i>
              </button>
            </div>
            <div class="modal-form">
              <div class="form-group">
                <label><i class="fas fa-heading"></i> Course Title <span class="required">*</span></label>
                <input type="text" name="title" value="<?= htmlspecialchars($row['title']) ?>" placeholder="Enter course title" required>
              </div>
              
              <div class="form-group">
                <label><i class="fas fa-align-left"></i> Description</label>
                <textarea name="description" placeholder="Enter course description" rows="4" maxlength="500"><?= htmlspecialchars($row['description']) ?></textarea>
                <small class="char-count">0/500 characters</small>
              </div>
              
              <div class="form-row">
                <div class="form-group">
                  <label><i class="fas fa-brain"></i> Skill Type <span class="required">*</span></label>
                  <select name="skill_type" required>
                    <option value="Soft" <?= $row['skill_type']=='Soft'?'selected':'' ?>>Soft Skill</option>
                    <option value="Hard" <?= $row['skill_type']=='Hard'?'selected':'' ?>>Hard Skill</option>
                  </select>
                </div>
                <div class="form-group">
                  <label><i class="fas fa-chalkboard"></i> Training Type <span class="required">*</span></label>
                  <select name="training_type" required>
                    <option value="Theoretical" <?= $row['training_type']=='Theoretical'?'selected':'' ?>>Theoretical</option>
                    <option value="Actual" <?= $row['training_type']=='Actual'?'selected':'' ?>>Actual</option>
                  </select>
                </div>
              </div>
              
              <div class="form-group">
                <label><i class="fas fa-file-upload"></i> Replace Media File</label>
                <div class="file-upload-wrapper">
                  <input type="file" name="file" id="editFile<?= $row['course_id'] ?>" accept="video/mp4,application/pdf">
                  <label for="editFile<?= $row['course_id'] ?>" class="file-upload-label">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <span>Choose file or drag here</span>
                    <small>MP4 or PDF (Max 50MB)</small>
                  </label>
                </div>
                <?php if($row['video_path']): ?>
                <div class="current-file">
                  <i class="fas fa-file"></i>
                  <span>Current: <?= basename($row['video_path']) ?></span>
                </div>
                <?php endif; ?>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn-secondary" onclick="closeModal('editCourse<?= $row['course_id'] ?>')">
                <i class="fas fa-times"></i> Cancel
              </button>
              <button type="submit" name="edit_course" class="btn-primary">
                <i class="fas fa-save"></i> Save Changes
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- DELETE MODAL -->
      <div class="modal" id="deleteCourse<?= $row['course_id'] ?>">
        <div class="modal-content modal-sm">
          <form action="" method="post">
            <input type="hidden" name="course_id" value="<?= $row['course_id'] ?>">
            <div class="modal-header danger">
              <h4><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h4>
              <button type="button" class="modal-close" onclick="closeModal('deleteCourse<?= $row['course_id'] ?>')">
                <i class="fas fa-times"></i>
              </button>
            </div>
            <div class="modal-form">
              <div class="delete-confirmation">
                <div class="delete-icon">
                  <i class="fas fa-trash-alt"></i>
                </div>
                <h3>Delete this course?</h3>
                <p>You are about to delete:</p>
                <strong class="delete-item">"<?= htmlspecialchars($row['title']) ?>"</strong>
                <p class="warning-text"><i class="fas fa-info-circle"></i> This action cannot be undone. All associated files will be removed.</p>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn-secondary" onclick="closeModal('deleteCourse<?= $row['course_id'] ?>')">
                <i class="fas fa-arrow-left"></i> Go Back
              </button>
              <button type="submit" name="delete_course" class="btn-danger">
                <i class="fas fa-trash"></i> Yes, Delete
              </button>
            </div>
          </form>
        </div>
      </div>
      <?php $modals_html .= ob_get_clean(); ?>
      <?php endwhile; ?>
    <?php else: ?>
      <tr class="empty-row">
        <td colspan="5">
          <div class="empty-state">
            <div class="empty-icon">
              <i class="fas fa-book-open"></i>
            </div>
            <h3>No Courses Found</h3>
            <p>Get started by adding your first learning course.</p>
            <button type="button" class="btn-primary" onclick="openModal('addCourseModal')">
              <i class="fas fa-plus"></i> Add Your First Course
            </button>
          </div>
        </td>
      </tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Grid View (Hidden by default) -->
<div class="grid-container" id="gridView" style="display: none;">
  <?php 
  $courses->data_seek(0);
  while($row = $courses->fetch_assoc()): 
  ?>
  <div class="course-card" data-skill="<?= htmlspecialchars($row['skill_type']) ?>" data-training="<?= htmlspecialchars($row['training_type']) ?>">
    <div class="card-header <?= strtolower($row['skill_type']) ?>">
      <span class="card-badge"><?= $row['skill_type'] ?> Skill</span>
      <div class="card-actions">
        <button type="button" class="card-btn" onclick="openModal('editCourse<?= $row['course_id'] ?>')"><i class="fas fa-edit"></i></button>
        <button type="button" class="card-btn" onclick="openModal('deleteCourse<?= $row['course_id'] ?>')"><i class="fas fa-trash"></i></button>
      </div>
    </div>
    <div class="card-body">
      <h4><?= htmlspecialchars($row['title']) ?></h4>
      <p><?= htmlspecialchars(substr($row['description'], 0, 100)) ?>...</p>
      <div class="card-meta">
        <span class="badge <?= strtolower($row['training_type']) ?>"><?= $row['training_type'] ?></span>
        <span class="card-date"><i class="far fa-clock"></i> <?= date('M d, Y', strtotime($row['created_at'])) ?></span>
      </div>
    </div>
    <?php if($row['video_path']): ?>
    <div class="card-footer">
      <?php $ext = strtolower(pathinfo($row['video_path'], PATHINFO_EXTENSION)); ?>
      <button type="button" class="btn-view" onclick="previewMedia('<?= htmlspecialchars($row['video_path']) ?>', '<?= $ext ?>')">
        <i class="fas fa-<?= $ext == 'mp4' ? 'play' : 'file-pdf' ?>"></i>
        View <?= strtoupper($ext) ?>
      </button>
    </div>
    <?php endif; ?>
  </div>
  <?php endwhile; ?>
</div>

</div> <!-- End main-content -->

<!-- Edit/Delete Modals (rendered outside main-content to avoid containing block issues) -->
<?php echo $modals_html ?? ''; ?>

<!-- AI Chat Bubble for Course Analysis -->
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
        <h4>AI Course Analyzer</h4>
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
          <p>👋 Hi! I can analyze your course library and provide insights on skill distribution, training types, and recommendations.</p>
          <span class="ai-message-time">Just now</span>
        </div>
      </div>
    </div>
    
    <div class="ai-chat-footer">
      <button class="ai-analyze-btn" onclick="generateAIRecommendations()" id="aiRefreshBtn">
        <i class="fas fa-brain"></i> Analyze Courses
      </button>
    </div>
  </div>
</div>

<!-- ADD COURSE MODAL -->
<div class="modal" id="addCourseModal">
  <div class="modal-content">
    <form action="" method="post" enctype="multipart/form-data" id="addCourseForm">
      <div class="modal-header">
        <h4><i class="fas fa-plus-circle"></i> Add New Course</h4>
        <button type="button" class="modal-close" onclick="closeModal('addCourseModal')">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div class="modal-form">
        <div class="form-group">
          <label><i class="fas fa-heading"></i> Course Title <span class="required">*</span></label>
          <input type="text" name="title" placeholder="Enter course title" required maxlength="200">
        </div>
        
        <div class="form-group">
          <label><i class="fas fa-align-left"></i> Description</label>
          <textarea name="description" placeholder="Enter course description" rows="4" maxlength="500"></textarea>
          <small class="char-count">0/500 characters</small>
        </div>
        
        <div class="form-row">
          <div class="form-group">
            <label><i class="fas fa-brain"></i> Skill Type <span class="required">*</span></label>
            <select name="skill_type" required>
              <option value="">Select Skill Type</option>
              <option value="Soft">Soft Skill</option>
              <option value="Hard">Hard Skill</option>
            </select>
          </div>
          <div class="form-group">
            <label><i class="fas fa-chalkboard"></i> Training Type <span class="required">*</span></label>
            <select name="training_type" required>
              <option value="">Select Training Type</option>
              <option value="Theoretical">Theoretical</option>
              <option value="Actual">Actual</option>
            </select>
          </div>
        </div>
        
        <div class="form-group">
          <label><i class="fas fa-file-upload"></i> Upload Media File</label>
          <div class="upload-tabs">
            <button type="button" class="upload-tab active" onclick="switchUploadTab('file')">
              <i class="fas fa-upload"></i> Upload File
            </button>
            <button type="button" class="upload-tab" onclick="switchUploadTab('url')">
              <i class="fas fa-link"></i> External URL
            </button>
          </div>
          
          <div id="fileUploadSection" class="upload-section">
            <div class="file-upload-wrapper" id="dropZone">
              <input type="file" name="file" id="addCourseFile" accept="video/mp4,application/pdf">
              <label for="addCourseFile" class="file-upload-label">
                <i class="fas fa-cloud-upload-alt"></i>
                <span>Choose file or drag & drop here</span>
                <small>MP4 or PDF (Max <?= ini_get('upload_max_filesize') ?>)</small>
              </label>
            </div>
            <div class="file-preview" id="filePreview" style="display: none;">
              <i class="fas fa-file"></i>
              <span id="fileName"></span>
              <button type="button" class="remove-file" onclick="removeFile()"><i class="fas fa-times"></i></button>
            </div>
          </div>
          
          <div id="urlUploadSection" class="upload-section" style="display: none;">
            <input type="url" name="external_url" id="externalUrl" placeholder="Paste YouTube, Google Drive, or direct video URL">
            <small class="url-hint"><i class="fas fa-info-circle"></i> Supports: YouTube, Google Drive, Vimeo, or direct MP4/PDF links</small>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-secondary" onclick="closeModal('addCourseModal')">
          <i class="fas fa-times"></i> Cancel
        </button>
        <button type="submit" name="add_course" class="btn-primary">
          <i class="fas fa-plus"></i> Add Course
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Media Preview Modal -->
<div class="modal" id="mediaPreviewModal">
  <div class="modal-content modal-lg">
    <div class="modal-header">
      <h4><i class="fas fa-play-circle"></i> Media Preview</h4>
      <button type="button" class="modal-close" onclick="closeModal('mediaPreviewModal')">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="modal-form">
      <div class="media-preview-container" id="mediaPreviewContent">
        <!-- Dynamic content -->
      </div>
    </div>
  </div>
</div>

<!-- Toast Notifications -->
<div id="toastContainer" class="toast-container">
  <?php if($success_msg): ?>
  <div class="toast success show">
    <i class="fas fa-check-circle"></i>
    <span><?= htmlspecialchars($success_msg) ?></span>
    <button class="toast-close"><i class="fas fa-times"></i></button>
  </div>
  <?php endif; ?>
  <?php if($error_msg): ?>
  <div class="toast error show">
    <i class="fas fa-exclamation-circle"></i>
    <span><?= htmlspecialchars($error_msg) ?></span>
    <button class="toast-close"><i class="fas fa-times"></i></button>
  </div>
  <?php endif; ?>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay" style="display: none;">
  <div class="loader">
    <div class="spinner"></div>
    <p>Processing...</p>
  </div>
</div>

<script>
// ===== MODAL FUNCTIONS =====
function openModal(id) {
  const modal = document.getElementById(id);
  if(modal) {
    modal.style.display = 'flex';
    modal.style.alignItems = 'center';
    modal.style.justifyContent = 'center';
    document.body.style.overflow = 'hidden';
    // Delay animation for smooth effect
    setTimeout(() => {
      const content = modal.querySelector('.modal-content');
      if(content) content.classList.add('animate-in');
    }, 10);
  }
}

function closeModal(id) {
  const modal = document.getElementById(id);
  if(modal) {
    const content = modal.querySelector('.modal-content');
    if(content) content.classList.remove('animate-in');
    setTimeout(() => {
      modal.style.display = 'none';
      document.body.style.overflow = '';
      // Stop any playing video when closing media preview
      if(id === 'mediaPreviewModal') {
        const container = document.getElementById('mediaPreviewContent');
        if(container) container.innerHTML = '';
      }
    }, 200);
  }
}

// Close modal on outside click
document.querySelectorAll('.modal').forEach(modal => {
  modal.addEventListener('click', function(e) {
    if (e.target === this) {
      const id = this.id;
      closeModal(id);
    }
  });
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal').forEach(modal => {
      if(modal.style.display === 'flex') {
        closeModal(modal.id);
      }
    });
  }
});

// ===== FILTER & SEARCH =====
const skillFilter = document.getElementById('skillFilter');
const trainingFilter = document.getElementById('trainingFilter');
const searchInput = document.getElementById('searchInput');
const clearSearchBtn = document.getElementById('clearSearch');
const resetFiltersBtn = document.getElementById('resetFilters');

function filterCourses() {
  const skillValue = skillFilter.value;
  const trainingValue = trainingFilter.value;
  const searchValue = searchInput.value.toLowerCase().trim();
  let visibleCount = 0;
  
  // Update clear button visibility
  clearSearchBtn.style.display = searchValue ? 'flex' : 'none';
  
  // Filter table rows
  document.querySelectorAll('#courseTable tr:not(.empty-row)').forEach(row => {
    const matchSkill = !skillValue || row.dataset.skill === skillValue;
    const matchTraining = !trainingValue || row.dataset.training === trainingValue;
    const matchSearch = !searchValue || row.dataset.title.includes(searchValue);
    
    const show = matchSkill && matchTraining && matchSearch;
    row.style.display = show ? '' : 'none';
    if(show) visibleCount++;
  });
  
  // Filter grid cards
  document.querySelectorAll('.course-card').forEach(card => {
    const matchSkill = !skillValue || card.dataset.skill === skillValue;
    const matchTraining = !trainingValue || card.dataset.training === trainingValue;
    
    card.style.display = (matchSkill && matchTraining) ? '' : 'none';
  });
  
  // Update count
  document.getElementById('visibleCount').textContent = visibleCount;
  
  // Show/hide empty state
  const emptyRow = document.querySelector('.empty-row');
  if(emptyRow) {
    emptyRow.style.display = visibleCount === 0 ? '' : 'none';
  }
}

// Debounce for search
function debounce(func, wait) {
  let timeout;
  return function(...args) {
    clearTimeout(timeout);
    timeout = setTimeout(() => func.apply(this, args), wait);
  };
}

skillFilter.addEventListener('change', filterCourses);
trainingFilter.addEventListener('change', filterCourses);
searchInput.addEventListener('input', debounce(filterCourses, 300));

clearSearchBtn.addEventListener('click', () => {
  searchInput.value = '';
  clearSearchBtn.style.display = 'none';
  filterCourses();
});

resetFiltersBtn.addEventListener('click', () => {
  searchInput.value = '';
  skillFilter.value = '';
  trainingFilter.value = '';
  clearSearchBtn.style.display = 'none';
  filterCourses();
});

// ===== VIEW TOGGLE =====
const viewBtns = document.querySelectorAll('.view-btn');
const tableView = document.getElementById('tableView');
const gridView = document.getElementById('gridView');

viewBtns.forEach(btn => {
  btn.addEventListener('click', function() {
    viewBtns.forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    
    if(this.dataset.view === 'grid') {
      tableView.style.display = 'none';
      gridView.style.display = 'grid';
    } else {
      tableView.style.display = 'block';
      gridView.style.display = 'none';
    }
  });
});

// ===== FILE UPLOAD =====
const fileInput = document.getElementById('addCourseFile');
const filePreview = document.getElementById('filePreview');
const fileName = document.getElementById('fileName');
const dropZone = document.getElementById('dropZone');

if(fileInput) {
  fileInput.addEventListener('change', function() {
    if(this.files.length > 0) {
      fileName.textContent = this.files[0].name;
      filePreview.style.display = 'flex';
      dropZone.style.display = 'none';
    }
  });
}

function removeFile() {
  fileInput.value = '';
  filePreview.style.display = 'none';
  dropZone.style.display = 'block';
}

// Drag & Drop
if(dropZone) {
  ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
    dropZone.addEventListener(eventName, preventDefaults, false);
  });
  
  function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
  }
  
  ['dragenter', 'dragover'].forEach(eventName => {
    dropZone.addEventListener(eventName, () => dropZone.classList.add('drag-over'), false);
  });
  
  ['dragleave', 'drop'].forEach(eventName => {
    dropZone.addEventListener(eventName, () => dropZone.classList.remove('drag-over'), false);
  });
  
  dropZone.addEventListener('drop', function(e) {
    const files = e.dataTransfer.files;
    if(files.length > 0) {
      fileInput.files = files;
      fileName.textContent = files[0].name;
      filePreview.style.display = 'flex';
      dropZone.style.display = 'none';
    }
  });
}

// ===== UPLOAD TABS =====
function switchUploadTab(tab) {
  const tabs = document.querySelectorAll('.upload-tab');
  tabs.forEach(t => t.classList.remove('active'));
  event.target.closest('.upload-tab').classList.add('active');
  
  const fileSection = document.getElementById('fileUploadSection');
  const urlSection = document.getElementById('urlUploadSection');
  
  if(tab === 'url') {
    fileSection.style.display = 'none';
    urlSection.style.display = 'block';
    // Clear file input when switching to URL
    document.getElementById('addCourseFile').value = '';
    document.getElementById('filePreview').style.display = 'none';
    document.getElementById('dropZone').style.display = 'block';
  } else {
    fileSection.style.display = 'block';
    urlSection.style.display = 'none';
    // Clear URL input when switching to file
    document.getElementById('externalUrl').value = '';
  }
}

// ===== MEDIA PREVIEW =====
function previewMedia(path, type) {
  const container = document.getElementById('mediaPreviewContent');
  
  if(type === 'youtube') {
    container.innerHTML = `
      <iframe width="100%" height="500" src="https://www.youtube.com/embed/${path}?autoplay=1" 
              frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
              allowfullscreen style="max-height:70vh;"></iframe>
    `;
  } else if(type === 'gdrive') {
    container.innerHTML = `
      <iframe src="https://drive.google.com/file/d/${path}/preview" width="100%" height="500" 
              frameborder="0" allowfullscreen style="max-height:70vh;"></iframe>
    `;
  } else if(type === 'vimeo') {
    container.innerHTML = `
      <iframe src="https://player.vimeo.com/video/${path}?autoplay=1" width="100%" height="500" 
              frameborder="0" allow="autoplay; fullscreen; picture-in-picture" 
              allowfullscreen style="max-height:70vh;"></iframe>
    `;
  } else if(type === 'url') {
    // Check if it's a video or other content
    if(path.match(/\.(mp4|webm|ogg)$/i)) {
      container.innerHTML = `
        <video controls autoplay style="max-width:100%; max-height:70vh;">
          <source src="${path}" type="video/mp4">
          Your browser does not support video playback.
        </video>
      `;
    } else {
      container.innerHTML = `
        <iframe src="${path}" style="width:100%; height:70vh; border:none;"></iframe>
      `;
    }
  } else if(type === 'mp4' || type === 'video') {
    container.innerHTML = `
      <video controls autoplay style="max-width:100%; max-height:70vh;">
        <source src="${path}" type="video/mp4">
        Your browser does not support video playback.
      </video>
    `;
  } else {
    container.innerHTML = `
      <iframe src="${path}" style="width:100%; height:70vh; border:none;"></iframe>
    `;
  }
  
  openModal('mediaPreviewModal');
}

// ===== TOAST NOTIFICATIONS =====
function showToast(message, type = 'success') {
  const container = document.getElementById('toastContainer');
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.innerHTML = `
    <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
    <span>${message}</span>
    <button class="toast-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
  `;
  container.appendChild(toast);
  
  setTimeout(() => toast.classList.add('show'), 10);
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 300);
  }, 5000);
}

// Close existing toasts
document.querySelectorAll('.toast-close').forEach(btn => {
  btn.addEventListener('click', function() {
    this.parentElement.classList.remove('show');
    setTimeout(() => this.parentElement.remove(), 300);
  });
});

// Auto-hide toasts
document.querySelectorAll('.toast.show').forEach(toast => {
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 300);
  }, 5000);
});

// ===== FORM SUBMISSION =====
document.querySelectorAll('form').forEach(form => {
  form.addEventListener('submit', function() {
    document.getElementById('loadingOverlay').style.display = 'flex';
  });
});

// ===== CHARACTER COUNT =====
document.querySelectorAll('textarea[maxlength]').forEach(textarea => {
  const counter = textarea.parentElement.querySelector('.char-count');
  if(counter) {
    const max = textarea.getAttribute('maxlength');
    textarea.addEventListener('input', function() {
      counter.textContent = `${this.value.length}/${max} characters`;
    });
    // Initialize
    counter.textContent = `${textarea.value.length}/${max} characters`;
  }
});

// ===== EXPORT DATA =====
function exportData() {
  const rows = document.querySelectorAll('#courseTable tr:not(.empty-row)');
  if(rows.length === 0) {
    showToast('No data to export', 'error');
    return;
  }
  
  let csv = 'Title,Skill Type,Training Type,Created\n';
  rows.forEach(row => {
    const title = row.querySelector('.course-title')?.textContent || '';
    const skill = row.dataset.skill || '';
    const training = row.dataset.training || '';
    const date = row.dataset.date || '';
    csv += `"${title}","${skill}","${training}","${date}"\n`;
  });
  
  const blob = new Blob([csv], { type: 'text/csv' });
  const url = window.URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = 'courses_export_' + new Date().toISOString().slice(0,10) + '.csv';
  a.click();
  window.URL.revokeObjectURL(url);
  
  showToast('Data exported successfully!');
}

// ===== AI CHAT BUBBLE FUNCTIONS =====
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

// ===== AI COURSE ANALYSIS =====
function generateAIRecommendations() {
  const chatWindow = document.getElementById('aiChatWindow');
  const btn = document.getElementById('aiRefreshBtn');
  
  // Open chat if closed
  if (!chatWindow.classList.contains('active')) {
    toggleAiChat();
  }
  
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing...';
  
  addChatMessage('<p>Analyze course library</p>', false);
  setTimeout(() => showTypingIndicator(), 500);

  const payload = new URLSearchParams({
    action: 'course_analytics',
    total: '<?= (int)$totalCourses ?>',
    soft: '<?= (int)$softSkills ?>',
    hard: '<?= (int)$hardSkills ?>',
    theoretical: '<?= (int)$theoretical ?>',
    actual: '<?= (int)$actual ?>'
  });

  fetch('ai_analyze.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: payload.toString()
  })
  .then(response => {
    if (!response.ok) {
      throw new Error('Server error: ' + response.status);
    }
    return response.json();
  })
  .then(data => {
    hideTypingIndicator();
    
    if (data.success) {
      // Show summary bubble
      addChatMessage(`<p>📊 Found <strong><?= (int)$totalCourses ?></strong> courses in your library. Here's my analysis:</p>`);
      
      setTimeout(() => {
        // Course distribution bubble
        const bubbleHtml = `
          <div class="ai-message-avatar">
            <i class="fas fa-robot"></i>
          </div>
          <div class="ai-message-bubble" style="max-width: 300px;">
            <div class="ai-course-bubble">
              <strong>📚 Course Distribution</strong>
              <p>• Soft Skills: <?= (int)$softSkills ?> courses<br>• Hard Skills: <?= (int)$hardSkills ?> courses<br>• Theoretical: <?= (int)$theoretical ?><br>• Actual: <?= (int)$actual ?></p>
            </div>
          </div>
        `;
        addChatMessage(bubbleHtml, true, true);
      }, 500);
      
      setTimeout(() => {
        // AI Analysis response
        let analysis = data.data || '';
        analysis = analysis.replace(/\*\*/g, '').replace(/\*/g, '').replace(/#{1,6}\s?/g, '');
        analysis = analysis.substring(0, 400);
        
        addChatMessage(`<p>${analysis}${analysis.length >= 400 ? '...' : ''}</p>`);
      }, 1000);
      
      setTimeout(() => {
        addChatMessage(`<p>✅ Analysis complete! Need more details? Just ask for another analysis.</p>`);
      }, 1500);
      
    } else {
      addChatMessage(`<p>⚠️ ${data.error || 'Unable to analyze courses.'}</p>`);
    }
  })
  .catch(error => {
    hideTypingIndicator();
    console.error('AI Analysis Error:', error);
    addChatMessage(`<p>❌ Error: ${error.message || 'Connection failed.'}</p>`);
  })
  .finally(() => {
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-brain"></i> Analyze Courses';
  });
}

function formatAiResponse(text) {
  return text
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\n/g, '<br>')
    .replace(/- /g, '• ');
}

// ===== ANIMATIONS ON LOAD =====
document.addEventListener('DOMContentLoaded', function() {
  // Animate stats
  document.querySelectorAll('.stat-card').forEach((card, i) => {
    card.style.opacity = '0';
    card.style.transform = 'translateY(20px)';
    setTimeout(() => {
      card.style.transition = 'all 0.4s cubic-bezier(0.4, 0, 0.2, 1)';
      card.style.opacity = '1';
      card.style.transform = 'translateY(0)';
    }, 100 + (i * 80));
  });
  
  // Animate table rows
  document.querySelectorAll('#courseTable tr').forEach((row, i) => {
    row.style.opacity = '0';
    setTimeout(() => {
      row.style.transition = 'opacity 0.3s ease';
      row.style.opacity = '1';
    }, 300 + (i * 40));
  });
  
  // Initialize search clear button
  if(searchInput.value) {
    clearSearchBtn.style.display = 'flex';
  }
});

// ===== TABLE SORTING =====
document.querySelectorAll('.sortable').forEach(th => {
  th.addEventListener('click', function() {
    const sort = this.dataset.sort;
    const tbody = document.getElementById('courseTable');
    const rows = Array.from(tbody.querySelectorAll('tr:not(.empty-row)'));
    
    const isAsc = this.classList.contains('asc');
    
    document.querySelectorAll('.sortable').forEach(h => h.classList.remove('asc', 'desc'));
    this.classList.add(isAsc ? 'desc' : 'asc');
    
    rows.sort((a, b) => {
      let valA, valB;
      if(sort === 'title') {
        valA = a.dataset.title;
        valB = b.dataset.title;
      } else if(sort === 'date') {
        valA = new Date(a.dataset.date);
        valB = new Date(b.dataset.date);
      }
      
      if(valA < valB) return isAsc ? 1 : -1;
      if(valA > valB) return isAsc ? -1 : 1;
      return 0;
    });
    
    rows.forEach(row => tbody.appendChild(row));
  });
});
</script>

</body>
</html>

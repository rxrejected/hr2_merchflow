<?php
// Include centralized session handler (handles session start, timeout, and activity tracking)
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php'; // database connection
require_once 'Connection/notifications_helper.php';

$user_id = $_SESSION['user_id'] ?? 0;
if ($user_id <= 0) {
    die("Unauthorized access.");
}

// Determine which table to query based on login source
$from_employee_table = isset($_SESSION['from_employee_table']) && $_SESSION['from_employee_table'] === true;
$user_table = $from_employee_table ? 'users_employee' : 'users';

// Kunin current user info (may job_position na at created_at)
$sql = "SELECT full_name, email, phone, address, role, avatar, job_position, created_at FROM `$user_table` WHERE id = ?";
$stmt_user = $conn->prepare($sql);
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$result = $stmt_user->get_result();
$stmt_user->close();
if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
    // Employee table users always have 'employee' role
    if ($from_employee_table) {
        $user['role'] = $user['role'] ?? 'employee';
    }
    if (empty($user['avatar']) || !file_exists($_SERVER['DOCUMENT_ROOT'].$user['avatar'])) {
        $user['avatar'] = '/uploads/avatars/default.png';
    }
} else {
    die("User not found.");
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = $conn->real_escape_string($_POST['full_name']);
    $phone     = $conn->real_escape_string($_POST['phone']);
    $address   = $conn->real_escape_string($_POST['address']);
    $job_position = $conn->real_escape_string($_POST['job_position']); // bagong field

    $email = $user['email'];

    if (($user['role'] === 'developer' || $user['role'] === 'admin') && isset($_POST['role'])) {
        $role = $conn->real_escape_string($_POST['role']);
    } else {
        $role = $user['role'];
    }

    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/avatars';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $avatar = $user['avatar'];
    $avatarChanged = false;

    // Check avatar upload
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg','jpeg','png','gif'];
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));

        if (in_array($ext, $allowed)) {
            $avatar_path = $uploadDir.'/user_'.$user_id.'.'.$ext;
            $avatar_web  = '/uploads/avatars/user_'.$user_id.'.'.$ext;

            if ($user['avatar'] != '/uploads/avatars/default.png') {
                $oldAvatar = $_SERVER['DOCUMENT_ROOT'].$user['avatar'];
                if (file_exists($oldAvatar)) unlink($oldAvatar);
            }

            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $avatar_path)) {
                $avatar = $avatar_web; 
                $avatarChanged = true;
            } else {
                $msg = "❌ Upload failed! Check folder permission or path.";
            }
        } else {
            $msg = "❌ Invalid file type! Only jpg, jpeg, png, gif allowed.";
        }
    }

    if (
        $full_name === $user['full_name'] &&
        $phone     === $user['phone'] &&
        $address   === $user['address'] &&
        $role      === $user['role'] &&
        $job_position === $user['job_position'] &&
        !$avatarChanged
    ) {
        $msg = "⚠️ No changes detected.";
    } else {
        $updateSql = "UPDATE `$user_table` 
                      SET full_name=?, phone=?, address=?, 
                          role=?, job_position=?, avatar=? 
                      WHERE id=?";
        $stmt_update = $conn->prepare($updateSql);
        $stmt_update->bind_param("ssssssi", $full_name, $phone, $address, $role, $job_position, $avatar, $user_id);

        if ($stmt_update->execute()) {
            $msg = "Profile updated successfully!";
            $user['full_name'] = $full_name;
            $user['phone']     = $phone;
            $user['address']   = $address;
            $user['role']      = $role;
            $user['avatar']    = $avatar;
            $user['job_position'] = $job_position;

            $_SESSION['full_name'] = $full_name;
            $_SESSION['phone']     = $phone;
            $_SESSION['address']   = $address;
            $_SESSION['role']      = $role;
            $_SESSION['avatar']    = $avatar;
            $_SESSION['job_position'] = $job_position;

            create_notification($conn, $user_id, 'profile', 'Your profile information was updated.');
        } else {
            $msg = "❌ Database update failed: " . $conn->error;
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Profile | Osave HR2</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="osicon.png">
<link rel="stylesheet" href="Css/profile.css?v=<?php echo time(); ?>">
<!-- Cropper.js CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css">
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
                <i class="fas fa-user-circle"></i>
            </div>
            <div class="header-text">
                <h1>My Profile</h1>
                <p>Manage your personal information and settings</p>
            </div>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" id="profileForm">
        <div class="profile-layout">
            <!-- Left Column - Avatar Section -->
            <div class="profile-sidebar">
                <div class="avatar-card">
                    <div class="avatar-wrapper">
                        <img src="<?php echo htmlspecialchars($user['avatar']).'?'.time(); ?>" alt="Avatar" class="avatar-preview" id="avatarPreview">
                        <label class="avatar-upload-btn" for="avatarInput">
                            <i class="fas fa-camera"></i>
                        </label>
                        <input type="file" name="avatar" id="avatarInput" accept="image/*" hidden>
                    </div>
                    <h2 class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></h2>
                    <span class="profile-role">
                        <i class="fas fa-shield-alt"></i>
                        <?php echo ucfirst(htmlspecialchars($user['role'])); ?>
                    </span>
                    <span class="profile-position">
                        <i class="fas fa-briefcase"></i>
                        <?php echo htmlspecialchars($user['job_position'] ?? 'Not Set'); ?>
                    </span>
                </div>

                <!-- Quick Stats -->
                <div class="stats-card">
                    <h3><i class="fas fa-chart-bar"></i> Account Status</h3>
                    <div class="stat-item">
                        <span class="stat-label">Status</span>
                        <span class="stat-value status-active"><i class="fas fa-check-circle"></i> Active</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Member Since</span>
                        <span class="stat-value"><?php echo isset($user['created_at']) ? date('M d, Y', strtotime($user['created_at'])) : 'N/A'; ?></span>
                    </div>
                </div>
            </div>

            <!-- Right Column - Info Cards -->
            <div class="profile-main">
                <!-- Personal Information -->
                <div class="info-card">
                    <div class="card-header">
                        <h3><i class="fas fa-user"></i> Personal Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> Full Name</label>
                                <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required placeholder="Enter your full name">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-envelope"></i> Email Address</label>
                                <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" readonly class="readonly-input">
                                <small class="input-hint"><i class="fas fa-lock"></i> Email cannot be changed</small>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-phone"></i> Phone Number</label>
                                <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="Enter phone number">
                            </div>
                            <div class="form-group full-width">
                                <label><i class="fas fa-map-marker-alt"></i> Address</label>
                                <input type="text" name="address" value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>" placeholder="Enter your address">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Work Information -->
                <div class="info-card">
                    <div class="card-header">
                        <h3><i class="fas fa-briefcase"></i> Work Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="form-grid">
                            <div class="form-group">
                                <label><i class="fas fa-user-tag"></i> Role</label>
                                <?php if($user['role'] === 'developer' || $user['role'] === 'admin' || $user['role'] === 'Super Admin'): ?>
                                <select name="role" class="form-select">
                                    <?php
                                    $roles = ['admin','Super Admin','manager','employee'];
                                    foreach($roles as $r){
                                        $sel = ($r === $user['role']) ? 'selected' : '';
                                        echo "<option value='$r' $sel>".ucfirst($r)."</option>";
                                    }
                                    ?>
                                </select>
                                <?php else: ?>
                                <input type="text" value="<?php echo htmlspecialchars(ucfirst($user['role'])); ?>" readonly class="readonly-input">
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-id-badge"></i> Job Position</label>
                                <?php
                                $positions = [
                                    'Area Operations Manager',
                                    'Store Manager',
                                    'Assistant Store Manager',
                                    'Warehouse Supervisor',
                                    'Store Helper',
                                    'Delivery Driver',
                                    'Central Purchasing Assistant'
                                ];
                                $currentPos = isset($user['job_position']) ? $user['job_position'] : '';
                                ?>
                                <?php if ($user['role'] === 'employee'): ?>
                                <input type="text" name="job_position" value="<?php echo htmlspecialchars($currentPos); ?>" readonly class="readonly-input">
                                <?php else: ?>
                                <select name="job_position" class="form-select">
                                    <option value="">-- Select Job Position --</option>
                                    <?php foreach ($positions as $p): ?>
                                        <option value="<?php echo htmlspecialchars($p, ENT_QUOTES); ?>" <?php echo ($p === $currentPos) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($p); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="form-actions">
                    <button type="reset" class="btn btn-ghost">
                        <i class="fas fa-undo"></i> Reset Changes
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
</div>


<!-- Toast Notification -->
<div id="toast" class="toast"></div>

<!-- Image Cropper Modal -->
<div class="crop-modal" id="cropModal">
    <div class="crop-container">
        <div class="crop-header">
            <h3><i class="fas fa-crop-alt"></i> Crop Profile Picture</h3>
            <button type="button" class="crop-close" onclick="closeCropModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="crop-body">
            <div class="crop-preview-wrapper">
                <img id="cropImage" src="" alt="Crop Preview">
            </div>
        </div>
        <div class="crop-tools">
            <button type="button" class="crop-tool-btn" onclick="rotateCrop(-90)" title="Rotate Left">
                <i class="fas fa-undo"></i> Rotate Left
            </button>
            <button type="button" class="crop-tool-btn" onclick="rotateCrop(90)" title="Rotate Right">
                <i class="fas fa-redo"></i> Rotate Right
            </button>
            <button type="button" class="crop-tool-btn" onclick="flipCrop('horizontal')" title="Flip Horizontal">
                <i class="fas fa-arrows-alt-h"></i> Flip H
            </button>
            <button type="button" class="crop-tool-btn" onclick="flipCrop('vertical')" title="Flip Vertical">
                <i class="fas fa-arrows-alt-v"></i> Flip V
            </button>
            <button type="button" class="crop-tool-btn" onclick="resetCrop()" title="Reset">
                <i class="fas fa-sync"></i> Reset
            </button>
        </div>
        <div class="crop-footer">
            <button type="button" class="crop-btn crop-btn-cancel" onclick="closeCropModal()">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="button" class="crop-btn crop-btn-save" onclick="saveCroppedImage()">
                <i class="fas fa-check"></i> Apply & Save
            </button>
        </div>
    </div>
</div>

<!-- Image Viewer Modal -->
<div class="image-viewer" id="imageViewer" onclick="closeImageViewer(event)">
    <button type="button" class="viewer-close" onclick="closeImageViewer(event)"><i class="fas fa-times"></i></button>
    <img id="viewerImage" src="" alt="Profile Picture">
    <div class="viewer-info"><i class="fas fa-info-circle"></i> Click anywhere to close</div>
</div>

<!-- Cropper.js Library -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>

<script>
let cropper = null;
let originalFile = null;

function showToast(message, type='success', duration=4000){
    const toast = document.getElementById('toast');
    const icons = {
        success: 'fa-check-circle',
        warning: 'fa-exclamation-triangle',
        error: 'fa-times-circle',
        info: 'fa-info-circle'
    };
    toast.className = `toast show ${type}`;
    toast.innerHTML = `
        <i class="fas ${icons[type] || icons.info}"></i>
        <span>${message}</span>
        <button type="button" class="close-btn" onclick="closeToast()"><i class="fas fa-times"></i></button>
    `;
    setTimeout(() => { toast.className = 'toast'; toast.innerHTML = ''; }, duration);
}

function closeToast(){ 
    const toast = document.getElementById('toast');
    toast.className = 'toast'; 
    toast.innerHTML = ''; 
}

// ==========================================
// IMAGE CROPPER FUNCTIONS
// ==========================================
function openCropModal(imageSrc) {
    const modal = document.getElementById('cropModal');
    const cropImage = document.getElementById('cropImage');
    
    cropImage.src = imageSrc;
    modal.classList.add('active');
    
    // Initialize Cropper after image loads
    cropImage.onload = function() {
        if (cropper) {
            cropper.destroy();
        }
        cropper = new Cropper(cropImage, {
            aspectRatio: 1, // Square crop for profile picture
            viewMode: 2,
            dragMode: 'move',
            autoCropArea: 0.9,
            restore: false,
            guides: true,
            center: true,
            highlight: true,
            cropBoxMovable: true,
            cropBoxResizable: true,
            toggleDragModeOnDblclick: false,
            background: true,
        });
    };
}

function closeCropModal(resetInput = true) {
    const modal = document.getElementById('cropModal');
    modal.classList.remove('active');
    if (cropper) {
        cropper.destroy();
        cropper = null;
    }
    // Only reset file input if explicitly requested (cancel button)
    if (resetInput) {
        document.getElementById('avatarInput').value = '';
    }
}

function rotateCrop(degree) {
    if (cropper) {
        cropper.rotate(degree);
    }
}

function flipCrop(direction) {
    if (cropper) {
        if (direction === 'horizontal') {
            const scaleX = cropper.getData().scaleX || 1;
            cropper.scaleX(-scaleX);
        } else {
            const scaleY = cropper.getData().scaleY || 1;
            cropper.scaleY(-scaleY);
        }
    }
}

function resetCrop() {
    if (cropper) {
        cropper.reset();
    }
}

function saveCroppedImage() {
    if (!cropper) return;
    
    // Get cropped canvas
    const canvas = cropper.getCroppedCanvas({
        width: 400,
        height: 400,
        imageSmoothingEnabled: true,
        imageSmoothingQuality: 'high',
    });
    
    // Convert to blob and update preview
    canvas.toBlob(function(blob) {
        // Update avatar preview
        const previewUrl = URL.createObjectURL(blob);
        document.getElementById('avatarPreview').src = previewUrl;
        
        // Create new File object with .jpg extension for form submission
        const fileName = 'cropped_avatar.jpg';
        const croppedFile = new File([blob], fileName, { type: 'image/jpeg' });
        
        // Update file input with cropped image
        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(croppedFile);
        document.getElementById('avatarInput').files = dataTransfer.files;
        
        // Close modal WITHOUT resetting file input
        closeCropModal(false);
        showToast('Image cropped successfully! Click "Save Changes" to update.', 'success');
    }, 'image/jpeg', 0.9);
}

// ==========================================
// IMAGE VIEWER FUNCTIONS
// ==========================================
function openImageViewer() {
    const avatarSrc = document.getElementById('avatarPreview').src;
    const viewer = document.getElementById('imageViewer');
    const viewerImg = document.getElementById('viewerImage');
    
    viewerImg.src = avatarSrc;
    viewer.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeImageViewer(event) {
    if (event.target.id === 'viewerImage') return; // Don't close when clicking on image
    
    const viewer = document.getElementById('imageViewer');
    viewer.classList.remove('active');
    document.body.style.overflow = '';
}

// ==========================================
// EVENT LISTENERS
// ==========================================
// Avatar input change - open crop modal
document.getElementById('avatarInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (!allowedTypes.includes(file.type)) {
            showToast('Invalid file type! Only jpg, jpeg, png, gif allowed.', 'error');
            this.value = '';
            return;
        }
        
        // Validate file size (max 5MB)
        if (file.size > 5 * 1024 * 1024) {
            showToast('File is too large! Maximum size is 5MB.', 'error');
            this.value = '';
            return;
        }
        
        originalFile = file;
        const reader = new FileReader();
        reader.onload = function(e) {
            openCropModal(e.target.result);
        };
        reader.readAsDataURL(file);
    }
});

// Avatar click - open image viewer
document.getElementById('avatarPreview').addEventListener('click', function(e) {
    e.preventDefault();
    e.stopPropagation();
    openImageViewer();
});

// Escape key to close modals
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const cropModal = document.getElementById('cropModal');
        const imageViewer = document.getElementById('imageViewer');
        
        if (cropModal.classList.contains('active')) {
            closeCropModal();
        }
        if (imageViewer.classList.contains('active')) {
            closeImageViewer({ target: { id: 'imageViewer' } });
        }
    }
});

// Form submit loading state
document.getElementById('profileForm').addEventListener('submit', function(e) {
    const submitBtn = this.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
});

<?php if(!empty($msg)): ?>
    showToast("<?php echo addslashes($msg); ?>",
        "<?php echo (strpos($msg, 'successfully') !== false) ? 'success' : ((strpos($msg, 'No changes') !== false) ? 'warning' : 'error'); ?>");
<?php endif; ?>
</script>
</body>
</html>

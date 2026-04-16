<?php
/**
 * MODULE 5 SUB 1 - MY PROFILE
 * HR2 MerchFlow - Employee Self-Service Portal
 * View and manage personal profile information
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

$employee_id = (int)$_SESSION['user_id'];
$from_hr1 = isset($_SESSION['from_hr1']) && $_SESSION['from_hr1'] === true;
$from_employee_table = isset($_SESSION['from_employee_table']) && $_SESSION['from_employee_table'] === true;

// Determine which table to use
$user_table = $from_employee_table ? 'users_employee' : 'users';

// Check if required columns exist and add them if not
$columns_check = $conn->query("SHOW COLUMNS FROM `$user_table`");
$existing_columns = [];
while ($col = $columns_check->fetch_assoc()) {
    $existing_columns[] = $col['Field'];
}

$needed_columns = [
    'phone' => 'VARCHAR(50) DEFAULT NULL',
    'address' => 'TEXT DEFAULT NULL',
    'emergency_contact' => 'VARCHAR(255) DEFAULT NULL',
    'emergency_phone' => 'VARCHAR(50) DEFAULT NULL',
    'department' => 'VARCHAR(100) DEFAULT NULL'
];

foreach ($needed_columns as $colName => $colDef) {
    if (!in_array($colName, $existing_columns)) {
        $conn->query("ALTER TABLE `$user_table` ADD COLUMN `$colName` $colDef");
    }
}

// Fetch employee from the correct table
$query = "SELECT * FROM `$user_table` WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
$stmt->close();

// If HR1 user and has additional HR1 data, enrich from HR1
if (($from_hr1 || $from_employee_table) && !empty($_SESSION['hr1_employee_id'])) {
    require_once 'Connection/hr1_db.php';
    $hr1db = new HR1Database();
    $hr1_conn = $hr1db->getPDO();
    
    if ($hr1_conn) {
        try {
            $hr1stmt = $hr1_conn->prepare("SELECT * FROM employees WHERE id = ?");
            $hr1stmt->execute([$_SESSION['hr1_employee_id']]);
            $hr1_emp = $hr1stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($hr1_emp) {
                // Enrich with HR1 employee data
                $employee['job_position'] = $employee['job_position'] ?? $hr1_emp['position'] ?? 'Employee';
                $employee['department'] = $employee['department'] ?? $hr1_emp['department'] ?? $_SESSION['hr1_department'] ?? '';
                $employee['phone'] = $employee['phone'] ?? $hr1_emp['phone'] ?? '';
                $employee['address'] = $employee['address'] ?? $hr1_emp['address'] ?? '';
                
                // Update table with enriched data if empty
                if (empty($employee['department']) && !empty($hr1_emp['department'])) {
                    $conn->query("UPDATE `$user_table` SET department = '" . $conn->real_escape_string($hr1_emp['department']) . "' WHERE id = $employee_id");
                }
            }
        } catch (Exception $e) {
            // Silent fail, use existing data
        }
    }
}

$can_edit = true; // All users can now edit their HR2 profile

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $phone = $_POST['phone'] ?? '';
    $address = $_POST['address'] ?? '';
    $emergency_contact = $_POST['emergency_contact'] ?? '';
    $emergency_phone = $_POST['emergency_phone'] ?? '';
    
    // Handle avatar upload
    $avatar_path = $employee['avatar'] ?? null;
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/avatars/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $file_ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($file_ext, $allowed_ext) && $_FILES['avatar']['size'] <= 5000000) {
            $new_filename = 'avatar_' . $employee_id . '_' . time() . '.' . $file_ext;
            $avatar_path = $upload_dir . $new_filename;
            move_uploaded_file($_FILES['avatar']['tmp_name'], $avatar_path);
        }
    }
    
    $update_query = "UPDATE `$user_table` SET phone = ?, address = ?, emergency_contact = ?, emergency_phone = ?, avatar = ? WHERE id = ?";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bind_param("sssssi", $phone, $address, $emergency_contact, $emergency_phone, $avatar_path, $employee_id);
    
    if ($update_stmt->execute()) {
        $_SESSION['avatar'] = $avatar_path;
        $_SESSION['success_msg'] = "Profile updated successfully!";
        header("Location: module5_sub1.php");
        exit();
    }
    $update_stmt->close();
    
    // Refetch employee after update
    $stmt = $conn->prepare("SELECT * FROM `$user_table` WHERE id = ?");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $employee = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>My Profile | Employee Portal</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/module5_sub1.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="Css/ai_chat_bubble.css?v=<?= time(); ?>">
</head>
<body>
<?php include 'partials/sidebar.php'; ?>
<div class="main-content">
    <?php include 'partials/nav.php'; ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h2><i class="fas fa-user-circle"></i> My Profile</h2>
            <div class="subtitle">View and manage your personal information</div>
        </div>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="openEditModal()">
                <i class="fas fa-edit"></i> Edit Profile
            </button>
        </div>
    </div>
    
    <?php if (isset($_SESSION['success_msg'])): ?>
    <div class="content-container">
        <div class="alert alert-success fade-in" style="background: var(--success-green-light); color: var(--success-green-dark); padding: 1rem; border-radius: var(--radius); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.75rem;">
            <i class="fas fa-check-circle"></i>
            <?= htmlspecialchars($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="content-container">
        <div class="grid-2" style="gap: 2rem;">
            <!-- Profile Card -->
            <div class="profile-card fade-in">
                <div class="profile-header">
                    <img src="<?= htmlspecialchars($employee['avatar'] ?: 'uploads/avatars/default.png') ?>" alt="Avatar" class="profile-avatar">
                    <h3 class="profile-name"><?= htmlspecialchars($employee['full_name']) ?></h3>
                    <div class="profile-role"><?= htmlspecialchars($employee['job_position'] ?? 'Employee') ?></div>
                </div>
                <div class="profile-body">
                    <div class="profile-info-item">
                        <div class="profile-info-icon"><i class="fas fa-envelope"></i></div>
                        <div class="profile-info-content">
                            <div class="profile-info-label">Email Address</div>
                            <div class="profile-info-value"><?= htmlspecialchars($employee['email']) ?></div>
                        </div>
                    </div>
                    <div class="profile-info-item">
                        <div class="profile-info-icon"><i class="fas fa-phone"></i></div>
                        <div class="profile-info-content">
                            <div class="profile-info-label">Phone Number</div>
                            <div class="profile-info-value"><?= htmlspecialchars($employee['phone'] ?: 'Not set') ?></div>
                        </div>
                    </div>
                    <div class="profile-info-item">
                        <div class="profile-info-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="profile-info-content">
                            <div class="profile-info-label">Address</div>
                            <div class="profile-info-value"><?= htmlspecialchars($employee['address'] ?: 'Not set') ?></div>
                        </div>
                    </div>
                    <div class="profile-info-item">
                        <div class="profile-info-icon"><i class="fas fa-calendar"></i></div>
                        <div class="profile-info-content">
                            <div class="profile-info-label">Date Joined</div>
                            <div class="profile-info-value"><?= date('F j, Y', strtotime($employee['created_at'])) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Additional Info -->
            <div class="section-card fade-in">
                <div class="section-header">
                    <h3><i class="fas fa-info-circle"></i> Additional Information</h3>
                </div>
                <div class="section-body">
                    <div class="profile-info-item">
                        <div class="profile-info-icon" style="background: var(--danger-red-light); color: var(--danger-red);"><i class="fas fa-user-shield"></i></div>
                        <div class="profile-info-content">
                            <div class="profile-info-label">Emergency Contact</div>
                            <div class="profile-info-value"><?= htmlspecialchars($employee['emergency_contact'] ?: 'Not set') ?></div>
                        </div>
                    </div>
                    <div class="profile-info-item">
                        <div class="profile-info-icon" style="background: var(--danger-red-light); color: var(--danger-red);"><i class="fas fa-phone-alt"></i></div>
                        <div class="profile-info-content">
                            <div class="profile-info-label">Emergency Phone</div>
                            <div class="profile-info-value"><?= htmlspecialchars($employee['emergency_phone'] ?: 'Not set') ?></div>
                        </div>
                    </div>
                    <div class="profile-info-item">
                        <div class="profile-info-icon" style="background: var(--info-blue-light); color: var(--info-blue);"><i class="fas fa-id-badge"></i></div>
                        <div class="profile-info-content">
                            <div class="profile-info-label">Employee ID</div>
                            <div class="profile-info-value">EMP-<?= str_pad($employee['id'], 5, '0', STR_PAD_LEFT) ?></div>
                        </div>
                    </div>
                    <div class="profile-info-item">
                        <div class="profile-info-icon" style="background: var(--purple-light); color: var(--purple);"><i class="fas fa-building"></i></div>
                        <div class="profile-info-content">
                            <div class="profile-info-label">Department</div>
                            <div class="profile-info-value"><?= htmlspecialchars($employee['department'] ?? 'General') ?></div>
                        </div>
                    </div>
                    <div class="profile-info-item">
                        <div class="profile-info-icon" style="background: var(--success-green-light); color: var(--success-green);"><i class="fas fa-check-circle"></i></div>
                        <div class="profile-info-content">
                            <div class="profile-info-label">Account Status</div>
                            <div class="profile-info-value">
                                <span class="status-badge active">
                                    <i class="fas fa-circle" style="font-size: 0.5rem;"></i> Active
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Profile Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Profile</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="form-group" style="text-align: center; margin-bottom: 1.5rem;">
                        <img src="<?= htmlspecialchars($employee['avatar'] ?: 'uploads/avatars/default.png') ?>" id="avatarPreview" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid var(--accent-blue); margin-bottom: 1rem;">
                        <div>
                            <label class="btn btn-secondary btn-sm" style="cursor: pointer;">
                                <i class="fas fa-camera"></i> Change Photo
                                <input type="file" name="avatar" accept="image/*" style="display: none;" onchange="previewAvatar(this)">
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($employee['phone'] ?? '') ?>" placeholder="Enter phone number">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="2" placeholder="Enter your address"><?= htmlspecialchars($employee['address'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Emergency Contact Name</label>
                        <input type="text" name="emergency_contact" class="form-control" value="<?= htmlspecialchars($employee['emergency_contact'] ?? '') ?>" placeholder="Emergency contact name">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Emergency Contact Phone</label>
                        <input type="tel" name="emergency_phone" class="form-control" value="<?= htmlspecialchars($employee['emergency_phone'] ?? '') ?>" placeholder="Emergency contact phone">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" name="update_profile" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditModal() {
    document.getElementById('editModal').classList.add('active');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
}

function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('avatarPreview').src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Close modal on outside click
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeEditModal();
});
</script>
<?php include 'partials/ai_chat.php'; ?>
</body>
</html>

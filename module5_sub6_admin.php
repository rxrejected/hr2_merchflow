<?php
/**
 * MODULE 5 SUB 6 ADMIN - PORTAL SETTINGS
 * HR2 MerchFlow - Employee Portal Management
 * Configure employee portal features and settings
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

// Admin role check
if (!in_array($_SESSION['role'] ?? '', ['admin', 'Super Admin', 'developer'])) {
    header('Location: employee.php');
    exit();
}

$admin_id = $_SESSION['user_id'];

// Create portal_settings table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS portal_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Default settings
$default_settings = [
    'portal_name' => 'Employee Self-Service Portal',
    'welcome_message' => 'Welcome to the Employee Portal! Access your profile, documents, training, and more.',
    'enable_documents' => '1',
    'enable_training' => '1',
    'enable_learning' => '1',
    'enable_requests' => '1',
    'enable_goals' => '1',
    'enable_announcements' => '1',
    'max_file_size' => '10',
    'allowed_file_types' => 'pdf,doc,docx,xls,xlsx,jpg,jpeg,png',
    'request_approval_required' => '1',
    'email_notifications' => '1',
    'auto_logout_minutes' => '30',
    'maintenance_mode' => '0',
    'maintenance_message' => 'The portal is currently under maintenance. Please try again later.',
    'theme_primary_color' => '#3b82f6',
    'show_employee_photos' => '1'
];

// Initialize settings
foreach ($default_settings as $key => $value) {
    $stmt = $conn->prepare("INSERT IGNORE INTO portal_settings (setting_key, setting_value) VALUES (?, ?)");
    $stmt->bind_param("ss", $key, $value);
    $stmt->execute();
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $settings_to_save = [
        'portal_name', 'welcome_message', 'enable_documents', 'enable_training', 
        'enable_learning', 'enable_requests', 'enable_goals', 'enable_announcements',
        'max_file_size', 'allowed_file_types', 'request_approval_required',
        'email_notifications', 'auto_logout_minutes', 'maintenance_mode', 
        'maintenance_message', 'theme_primary_color', 'show_employee_photos'
    ];
    
    foreach ($settings_to_save as $key) {
        $value = isset($_POST[$key]) ? $_POST[$key] : '0';
        $stmt = $conn->prepare("UPDATE portal_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?");
        $stmt->bind_param("sis", $value, $admin_id, $key);
        $stmt->execute();
        $stmt->close();
    }
    
    header("Location: module5_sub6_admin.php?msg=saved");
    exit();
}

// Fetch current settings
$result = $conn->query("SELECT setting_key, setting_value FROM portal_settings");
$settings = [];
while ($row = $result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Get last updated info
$last_update = $conn->query("SELECT ps.updated_at, u.full_name FROM portal_settings ps LEFT JOIN users u ON ps.updated_by = u.id ORDER BY ps.updated_at DESC LIMIT 1")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Portal Settings | Admin</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/module5_sub6_admin.css?v=<?= time(); ?>">
</head>
<body>
<?php include 'partials/sidebar.php'; ?>
<div class="main-content">
    <?php include 'partials/nav.php'; ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h2><i class="fas fa-cog"></i> Portal Settings</h2>
            <div class="subtitle">Configure employee portal features and preferences</div>
        </div>
    </div>
    
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'saved'): ?>
    <div class="content-container" style="margin-bottom: 0;">
        <div class="alert fade-in" style="background: var(--success-green-light); color: var(--success-green-dark); padding: 1rem; border-radius: var(--radius); display: flex; align-items: center; gap: 0.75rem;">
            <i class="fas fa-check-circle"></i>
            Settings saved successfully!
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (($settings['maintenance_mode'] ?? '0') === '1'): ?>
    <div class="maintenance-banner fade-in">
        <i class="fas fa-tools fa-lg"></i>
        <div>
            <strong>Maintenance Mode is Active</strong>
            <div style="font-size: 0.875rem;">Employees cannot access the portal while maintenance mode is enabled.</div>
        </div>
    </div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="settings-layout">
            <!-- Settings Navigation -->
            <div class="settings-nav fade-in">
                <div class="nav-item active" data-section="general">
                    <i class="fas fa-sliders-h"></i> General
                </div>
                <div class="nav-item" data-section="features">
                    <i class="fas fa-puzzle-piece"></i> Features
                </div>
                <div class="nav-item" data-section="uploads">
                    <i class="fas fa-upload"></i> File Uploads
                </div>
                <div class="nav-item" data-section="notifications">
                    <i class="fas fa-bell"></i> Notifications
                </div>
                <div class="nav-item" data-section="security">
                    <i class="fas fa-shield-alt"></i> Security
                </div>
                <div class="nav-item" data-section="appearance">
                    <i class="fas fa-palette"></i> Appearance
                </div>
            </div>
            
            <!-- Settings Content -->
            <div class="settings-content">
                <!-- General Settings -->
                <div class="settings-section fade-in" id="general">
                    <h3><i class="fas fa-sliders-h"></i> General Settings</h3>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Portal Name</div>
                            <div class="setting-desc">The name displayed in the portal header</div>
                        </div>
                        <div class="setting-control">
                            <input type="text" name="portal_name" class="form-control" 
                                value="<?= htmlspecialchars($settings['portal_name'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Welcome Message</div>
                            <div class="setting-desc">Message shown on the employee dashboard</div>
                        </div>
                        <div class="setting-control" style="min-width: 300px;">
                            <textarea name="welcome_message" class="form-control" rows="3"><?= htmlspecialchars($settings['welcome_message'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Features -->
                <div class="settings-section fade-in" id="features">
                    <h3><i class="fas fa-puzzle-piece"></i> Feature Toggles</h3>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Enable Documents</div>
                            <div class="setting-desc">Allow employees to view their documents</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_documents" value="1" 
                                <?= ($settings['enable_documents'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Enable Training</div>
                            <div class="setting-desc">Allow employees to view and register for training</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_training" value="1"
                                <?= ($settings['enable_training'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Enable Learning</div>
                            <div class="setting-desc">Allow employees to access online courses</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_learning" value="1"
                                <?= ($settings['enable_learning'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Enable Requests</div>
                            <div class="setting-desc">Allow employees to submit requests (leave, WFH, etc.)</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_requests" value="1"
                                <?= ($settings['enable_requests'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Enable Goals</div>
                            <div class="setting-desc">Allow employees to set and track development goals</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_goals" value="1"
                                <?= ($settings['enable_goals'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Show Employee Photos</div>
                            <div class="setting-desc">Display employee avatars in directory and lists</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="show_employee_photos" value="1"
                                <?= ($settings['show_employee_photos'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
                
                <!-- File Uploads -->
                <div class="settings-section fade-in" id="uploads">
                    <h3><i class="fas fa-upload"></i> File Upload Settings</h3>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Maximum File Size (MB)</div>
                            <div class="setting-desc">Maximum size for uploaded files</div>
                        </div>
                        <div class="setting-control">
                            <input type="number" name="max_file_size" class="form-control" min="1" max="50"
                                value="<?= htmlspecialchars($settings['max_file_size'] ?? '10') ?>">
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Allowed File Types</div>
                            <div class="setting-desc">Comma-separated list of allowed extensions</div>
                        </div>
                        <div class="setting-control">
                            <input type="text" name="allowed_file_types" class="form-control"
                                value="<?= htmlspecialchars($settings['allowed_file_types'] ?? '') ?>"
                                placeholder="pdf,doc,docx,jpg,png">
                        </div>
                    </div>
                </div>
                
                <!-- Notifications -->
                <div class="settings-section fade-in" id="notifications">
                    <h3><i class="fas fa-bell"></i> Notification Settings</h3>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Email Notifications</div>
                            <div class="setting-desc">Send email notifications for portal activities</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="email_notifications" value="1"
                                <?= ($settings['email_notifications'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Enable Announcements</div>
                            <div class="setting-desc">Show announcements on employee dashboard</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="enable_announcements" value="1"
                                <?= ($settings['enable_announcements'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Request Approval Required</div>
                            <div class="setting-desc">Require admin approval for employee requests</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="request_approval_required" value="1"
                                <?= ($settings['request_approval_required'] ?? '1') === '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
                
                <!-- Security -->
                <div class="settings-section fade-in" id="security">
                    <h3><i class="fas fa-shield-alt"></i> Security Settings</h3>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Auto Logout (Minutes)</div>
                            <div class="setting-desc">Automatically log out inactive users</div>
                        </div>
                        <div class="setting-control">
                            <select name="auto_logout_minutes" class="form-control">
                                <option value="15" <?= ($settings['auto_logout_minutes'] ?? '30') === '15' ? 'selected' : '' ?>>15 minutes</option>
                                <option value="30" <?= ($settings['auto_logout_minutes'] ?? '30') === '30' ? 'selected' : '' ?>>30 minutes</option>
                                <option value="60" <?= ($settings['auto_logout_minutes'] ?? '30') === '60' ? 'selected' : '' ?>>1 hour</option>
                                <option value="120" <?= ($settings['auto_logout_minutes'] ?? '30') === '120' ? 'selected' : '' ?>>2 hours</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Maintenance Mode</div>
                            <div class="setting-desc">Temporarily disable access to the employee portal</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="maintenance_mode" value="1"
                                <?= ($settings['maintenance_mode'] ?? '0') === '1' ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Maintenance Message</div>
                            <div class="setting-desc">Message displayed during maintenance</div>
                        </div>
                        <div class="setting-control" style="min-width: 300px;">
                            <textarea name="maintenance_message" class="form-control" rows="2"><?= htmlspecialchars($settings['maintenance_message'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Appearance -->
                <div class="settings-section fade-in" id="appearance">
                    <h3><i class="fas fa-palette"></i> Appearance Settings</h3>
                    
                    <div class="setting-row">
                        <div class="setting-info">
                            <div class="setting-label">Primary Color</div>
                            <div class="setting-desc">Main accent color used throughout the portal</div>
                        </div>
                        <div class="color-picker">
                            <input type="color" name="theme_primary_color" id="colorPicker"
                                value="<?= htmlspecialchars($settings['theme_primary_color'] ?? '#3b82f6') ?>"
                                onchange="updateColorPreview(this.value)">
                            <span class="color-preview" id="colorPreview" 
                                style="background: <?= htmlspecialchars($settings['theme_primary_color'] ?? '#3b82f6') ?>; color: white;">
                                <?= htmlspecialchars($settings['theme_primary_color'] ?? '#3b82f6') ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Save Bar -->
                <div class="save-bar">
                    <div class="last-updated">
                        <?php if ($last_update && $last_update['updated_at']): ?>
                        Last updated <?= date('M d, Y H:i', strtotime($last_update['updated_at'])) ?>
                        <?php if ($last_update['full_name']): ?> by <?= htmlspecialchars($last_update['full_name']) ?><?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; gap: 0.75rem;">
                        <button type="button" class="btn btn-secondary" onclick="window.location.reload()">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" name="save_settings" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
// Settings navigation
document.querySelectorAll('.nav-item').forEach(item => {
    item.addEventListener('click', function() {
        document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
        this.classList.add('active');
        
        const sectionId = this.dataset.section;
        const section = document.getElementById(sectionId);
        if (section) {
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});

// Color picker preview
function updateColorPreview(color) {
    const preview = document.getElementById('colorPreview');
    preview.style.background = color;
    preview.textContent = color;
}

// Highlight active section on scroll
window.addEventListener('scroll', () => {
    const sections = document.querySelectorAll('.settings-section');
    let current = '';
    
    sections.forEach(section => {
        const rect = section.getBoundingClientRect();
        if (rect.top <= 150) {
            current = section.id;
        }
    });
    
    if (current) {
        document.querySelectorAll('.nav-item').forEach(item => {
            item.classList.remove('active');
            if (item.dataset.section === current) {
                item.classList.add('active');
            }
        });
    }
});
</script>
</body>
</html>

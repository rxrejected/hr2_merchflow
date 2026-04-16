<?php
/**
 * OSAVE CONVENIENCE STORE - HR2 MERCHFLOW
 * Settings Page
 * Enhanced with Osave Branding and Full Functionality
 */

// Include centralized session handler (handles session start, timeout, and activity tracking)
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';
require_once 'Connection/notifications_helper.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

// User session info
$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'] ?? 'User';
$username = $_SESSION['username'] ?? '';
$email = $_SESSION['email'] ?? 'user@example.com';
$role = $_SESSION['role'] ?? 'user';

// Determine which table to query based on login source
$from_employee_table = isset($_SESSION['from_employee_table']) && $_SESSION['from_employee_table'] === true;
$user_table = $from_employee_table ? 'users_employee' : 'users';

// Get additional user info from database
$user_query = $conn->prepare("SELECT full_name, email, phone, address, role, job_position, avatar, created_at, is_verified FROM `$user_table` WHERE id = ?");
$user_query->bind_param("i", $user_id);
$user_query->execute();
$user_data = $user_query->get_result()->fetch_assoc();
$user_query->close();

// Use database values (more accurate than session)
$full_name = $user_data['full_name'] ?? $_SESSION['full_name'] ?? 'User';
$email = $user_data['email'] ?? $_SESSION['email'] ?? 'user@example.com';
$phone = $user_data['phone'] ?? 'Not set';
$address = $user_data['address'] ?? 'Not set';
$role = $user_data['role'] ?? $_SESSION['role'] ?? 'employee';
$job_position = $user_data['job_position'] ?? 'Not set';
$avatar = $user_data['avatar'] ?? 'uploads/avatars/default.png';
$created_at = $user_data['created_at'] ?? null;
$is_verified = $user_data['is_verified'] ?? 0;

// Get user devices
$user_devices = get_user_devices($conn, $user_id);

// Handle device actions (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['device_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['device_action'];
    $device_id = intval($_POST['device_id'] ?? 0);
    
    if ($action === 'remove' && $device_id > 0) {
        $result = remove_user_device($conn, $user_id, $device_id);
        echo json_encode(['status' => $result ? 'success' : 'error', 'message' => $result ? 'Device removed' : 'Failed to remove device']);
    } elseif ($action === 'toggle_trust' && $device_id > 0) {
        $result = toggle_device_trust($conn, $user_id, $device_id);
        echo json_encode(['status' => $result ? 'success' : 'error', 'message' => $result ? 'Trust status updated' : 'Failed to update']);
    } elseif ($action === 'trust_current') {
        $result = trust_current_device($conn, $user_id);
        echo json_encode(['status' => $result ? 'success' : 'error', 'message' => $result ? 'This device is now trusted' : 'Failed to trust device']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
    exit;
}

// Notifications
$notif = '';
$notif_type = 'success';
if (isset($_SESSION['notif'])) {
    $notif = $_SESSION['notif'];
    $notif_type = $_SESSION['notif_type'] ?? 'success';
    unset($_SESSION['notif'], $_SESSION['notif_type']);
}

// Password Reset Handling
$pass_msg = '';
$pass_type = 'success';
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['reset_password'])) {
    $current_password = trim($_POST['current_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    // Verify current password
    $check = $conn->prepare("SELECT password FROM `$user_table` WHERE id = ?");
    $check->bind_param("i", $user_id);
    $check->execute();
    $result = $check->get_result()->fetch_assoc();
    $check->close();

    if (!password_verify($current_password, $result['password'])) {
        $pass_msg = "Current password is incorrect!";
        $pass_type = "error";
    } elseif (strlen($new_password) < 8) {
        $pass_msg = "New password must be at least 8 characters!";
        $pass_type = "error";
    } elseif ($new_password !== $confirm_password) {
        $pass_msg = "New passwords do not match!";
        $pass_type = "error";
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE `$user_table` SET password = ? WHERE id = ?");
        $update->bind_param("si", $hashed, $user_id);
        $update->execute();
        $update->close();

        $pass_msg = "Password successfully updated!";
        $pass_type = "success";
        create_notification($conn, $user_id, 'security', 'Your password was updated.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Settings | Osave HR2</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="Css/settings.css?v=<?php echo time(); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                    <i class="fas fa-cog"></i>
                </div>
                <div class="header-text">
                    <h1>Settings</h1>
                    <p>Manage your account preferences and security</p>
                </div>
            </div>
        </div>

        <!-- Settings Grid -->
        <div class="settings-grid">
            
            <!-- Change Password Card -->
            <div class="settings-card">
                <div class="card-header red">
                    <div class="card-header-content">
                        <h3><i class="fas fa-lock"></i> Change Password</h3>
                        <p>Update your password to keep your account secure</p>
                    </div>
                    <i class="fas fa-shield-alt card-icon-bg"></i>
                </div>
                <div class="card-body">
                    <form method="POST" id="passwordForm">
                        <input type="hidden" name="reset_password" value="1">
                        <div class="form-group">
                            <label><i class="fas fa-key"></i> Current Password</label>
                            <div class="input-wrapper">
                                <input type="password" name="current_password" id="currentPassword" 
                                       placeholder="Enter current password" required autocomplete="current-password">
                                <i class="fas fa-eye toggle-password" onclick="togglePassword('currentPassword', this)"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> New Password</label>
                            <div class="input-wrapper">
                                <input type="password" name="new_password" id="newPassword" 
                                       placeholder="Enter new password" required autocomplete="new-password"
                                       oninput="checkPasswordStrength(this.value)">
                                <i class="fas fa-eye toggle-password" onclick="togglePassword('newPassword', this)"></i>
                            </div>
                            <div class="password-strength" id="passwordStrength">
                                <div class="strength-bar"></div>
                            </div>
                            <span class="input-hint"><i class="fas fa-info-circle"></i> Minimum 8 characters</span>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-check-double"></i> Confirm Password</label>
                            <div class="input-wrapper">
                                <input type="password" name="confirm_password" id="confirmPassword" 
                                       placeholder="Confirm new password" required autocomplete="new-password">
                                <i class="fas fa-eye toggle-password" onclick="togglePassword('confirmPassword', this)"></i>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>

            <!-- Update Email Card -->
            <div class="settings-card">
                <div class="card-header blue">
                    <div class="card-header-content">
                        <h3><i class="fas fa-envelope"></i> Update Email</h3>
                        <p>Change your email address with OTP verification</p>
                    </div>
                    <i class="fas fa-at card-icon-bg"></i>
                </div>
                <div class="card-body">
                    <!-- Step Indicators -->
                    <div class="form-steps">
                        <div class="step active" id="step1Indicator">
                            <span class="step-number">1</span>
                            <span class="step-label">Verify</span>
                        </div>
                        <div class="step-divider"></div>
                        <div class="step" id="step2Indicator">
                            <span class="step-number">2</span>
                            <span class="step-label">OTP</span>
                        </div>
                        <div class="step-divider"></div>
                        <div class="step" id="step3Indicator">
                            <span class="step-number">3</span>
                            <span class="step-label">New Email</span>
                        </div>
                    </div>

                    <!-- Step 1: Current Email -->
                    <div class="step-content active" id="step1Content">
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Current Email</label>
                            <input type="email" value="<?= htmlspecialchars($email) ?>" readonly>
                        </div>
                        <button type="button" class="btn btn-primary" onclick="requestOTP()">
                            <i class="fas fa-paper-plane"></i> Send OTP
                        </button>
                    </div>

                    <!-- Step 2: OTP Verification -->
                    <div class="step-content" id="step2Content">
                        <p style="text-align: center; color: var(--text-secondary); margin-bottom: 1rem;">
                            Enter the 6-digit code sent to your email
                        </p>
                        <div class="otp-inputs">
                            <input type="text" maxlength="1" class="otp-digit" data-index="0" autocomplete="off">
                            <input type="text" maxlength="1" class="otp-digit" data-index="1" autocomplete="off">
                            <input type="text" maxlength="1" class="otp-digit" data-index="2" autocomplete="off">
                            <input type="text" maxlength="1" class="otp-digit" data-index="3" autocomplete="off">
                            <input type="text" maxlength="1" class="otp-digit" data-index="4" autocomplete="off">
                            <input type="text" maxlength="1" class="otp-digit" data-index="5" autocomplete="off">
                        </div>
                        <div class="otp-timer">
                            <span id="resendTimer">Resend in 60s</span>
                            <span class="resend-link disabled" id="resendLink" onclick="requestOTP()">Resend OTP</span>
                        </div>
                        <button type="button" class="btn btn-primary" onclick="verifyOTP()" style="margin-top: 1rem;">
                            <i class="fas fa-check"></i> Verify OTP
                        </button>
                    </div>

                    <!-- Step 3: New Email -->
                    <div class="step-content" id="step3Content">
                        <div class="form-group">
                            <label><i class="fas fa-envelope-open"></i> New Email Address</label>
                            <input type="email" id="newEmailInput" placeholder="Enter new email address">
                        </div>
                        <button type="button" class="btn btn-primary" onclick="confirmEmail()">
                            <i class="fas fa-save"></i> Update Email
                        </button>
                    </div>
                </div>
            </div>

            <!-- Account Information -->
            <div class="settings-card">
                <div class="card-header green">
                    <div class="card-header-content">
                        <h3><i class="fas fa-user-circle"></i> Account Information</h3>
                        <p>Your account details and status</p>
                    </div>
                    <i class="fas fa-id-card card-icon-bg"></i>
                </div>
                <div class="card-body">
                    <div class="account-info">
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-user"></i> Full Name</span>
                            <span class="info-value"><?= htmlspecialchars($full_name) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-envelope"></i> Email</span>
                            <span class="info-value"><?= htmlspecialchars($email) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-phone"></i> Phone</span>
                            <span class="info-value"><?= htmlspecialchars($phone) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-map-marker-alt"></i> Address</span>
                            <span class="info-value"><?= htmlspecialchars($address) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-user-tag"></i> Role</span>
                            <span class="info-value"><?= ucfirst(htmlspecialchars($role)) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-briefcase"></i> Position</span>
                            <span class="info-value"><?= htmlspecialchars($job_position) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-check-circle"></i> Status</span>
                            <span class="info-value">
                                <?php if ($is_verified): ?>
                                <span style="color: #28a745;"><i class="fas fa-check"></i> Verified</span>
                                <?php else: ?>
                                <span style="color: #dc3545;"><i class="fas fa-times"></i> Not Verified</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label"><i class="fas fa-calendar-plus"></i> Member Since</span>
                            <span class="info-value"><?= $created_at ? date('M d, Y', strtotime($created_at)) : 'N/A' ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security Settings -->
            <div class="settings-card">
                <div class="card-header purple">
                    <div class="card-header-content">
                        <h3><i class="fas fa-shield-alt"></i> Security</h3>
                        <p>Manage your security settings</p>
                    </div>
                    <i class="fas fa-fingerprint card-icon-bg"></i>
                </div>
                <div class="card-body">
                    <div class="security-list">
                        <div class="security-item">
                            <div class="security-item-info">
                                <div class="security-item-icon">
                                    <i class="fas fa-lock"></i>
                                </div>
                                <div class="security-item-text">
                                    <h4>Password</h4>
                                    <p>Last changed: Recently</p>
                                </div>
                            </div>
                            <span class="security-status active">
                                <i class="fas fa-check"></i> Secure
                            </span>
                        </div>
                        <div class="security-item">
                            <div class="security-item-info">
                                <div class="security-item-icon">
                                    <i class="fas fa-envelope"></i>
                                </div>
                                <div class="security-item-text">
                                    <h4>Email Verification</h4>
                                    <p>Your email is verified</p>
                                </div>
                            </div>
                            <span class="security-status active">
                                <i class="fas fa-check"></i> Verified
                            </span>
                        </div>
                        <div class="security-item">
                            <div class="security-item-info">
                                <div class="security-item-icon">
                                    <i class="fas fa-mobile-alt"></i>
                                </div>
                                <div class="security-item-text">
                                    <h4>Two-Factor Auth</h4>
                                    <p>Add extra security layer</p>
                                </div>
                            </div>
                            <span class="security-status active">
                                <i class="fas fa-check"></i> Enabled (OTP)
                            </span>
                        </div>
                        <div class="security-item">
                            <div class="security-item-info">
                                <div class="security-item-icon">
                                    <i class="fas fa-desktop"></i>
                                </div>
                                <div class="security-item-text">
                                    <h4>Device Verification</h4>
                                    <p>New IP/device requires OTP</p>
                                </div>
                            </div>
                            <span class="security-status active">
                                <i class="fas fa-check"></i> Active
                            </span>
                        </div>
                    </div>
                </div>
            </div>


            <!-- Notification Preferences -->
            <div class="settings-card">
                <div class="card-header orange">
                    <div class="card-header-content">
                        <h3><i class="fas fa-bell"></i> Notifications</h3>
                        <p>Control your notification preferences</p>
                    </div>
                    <i class="fas fa-bell card-icon-bg"></i>
                </div>
                <div class="card-body">
                    <div class="preference-item">
                        <div class="preference-info">
                            <h4>Email Notifications</h4>
                            <p>Receive updates via email</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="preference-item">
                        <div class="preference-info">
                            <h4>Training Reminders</h4>
                            <p>Get notified about upcoming trainings</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" checked>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                    <div class="preference-item">
                        <div class="preference-info">
                            <h4>System Updates</h4>
                            <p>Receive system maintenance alerts</p>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Danger Zone -->
            <div class="settings-card danger-zone">
                <div class="card-header">
                    <div class="card-header-content">
                        <h3><i class="fas fa-exclamation-triangle"></i> Danger Zone</h3>
                        <p>Irreversible account actions</p>
                    </div>
                    <i class="fas fa-skull-crossbones card-icon-bg"></i>
                </div>
                <div class="card-body">
                    <div class="danger-item">
                        <div class="danger-info">
                            <h4>Deactivate Account</h4>
                            <p>Temporarily disable your account</p>
                        </div>
                        <button class="btn btn-danger btn-sm" onclick="alert('Please contact administrator')">
                            <i class="fas fa-pause"></i> Deactivate
                        </button>
                    </div>
                    <div class="danger-item">
                        <div class="danger-info">
                            <h4>Delete Account</h4>
                            <p>Permanently delete all your data</p>
                        </div>
                        <button class="btn btn-danger btn-sm" onclick="alert('Please contact administrator')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </div>
            </div>

            <!-- Trusted Devices -->
            <div class="settings-card full-width">
                <div class="card-header cyan">
                    <div class="card-header-content">
                        <h3><i class="fas fa-laptop"></i> Trusted Devices</h3>
                        <p>Manage devices that can access your account</p>
                    </div>
                    <i class="fas fa-shield-alt card-icon-bg"></i>
                </div>
                <div class="card-body">
                    <div class="devices-info-banner">
                        <i class="fas fa-info-circle"></i>
                        <span>Trusted devices won't require OTP verification when logging in. Unknown devices will always require OTP for security.</span>
                    </div>
                    
                    <?php if (empty($user_devices)): ?>
                    <div class="empty-devices">
                        <i class="fas fa-laptop"></i>
                        <p>No devices recorded yet</p>
                    </div>
                    <?php else: ?>
                    <div class="devices-list">
                        <?php foreach ($user_devices as $device): 
                            $is_current = is_current_device($device);
                        ?>
                        <div class="device-item <?= $is_current ? 'current-device' : '' ?>" data-device-id="<?= $device['id'] ?>">
                            <div class="device-icon">
                                <?php
                                $ua = strtolower($device['user_agent'] ?? '');
                                if (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false || strpos($ua, 'iphone') !== false) {
                                    echo '<i class="fas fa-mobile-alt"></i>';
                                } elseif (strpos($ua, 'tablet') !== false || strpos($ua, 'ipad') !== false) {
                                    echo '<i class="fas fa-tablet-alt"></i>';
                                } else {
                                    echo '<i class="fas fa-desktop"></i>';
                                }
                                ?>
                            </div>
                            <div class="device-info">
                                <div class="device-name">
                                    <?= htmlspecialchars($device['device_name'] ?? 'Unknown Device') ?>
                                    <?php if ($is_current): ?>
                                    <span class="current-badge">This Device</span>
                                    <?php endif; ?>
                                </div>
                                <div class="device-meta">
                                    <span><i class="fas fa-globe"></i> <?= htmlspecialchars($device['ip_address'] ?? 'Unknown') ?></span>
                                    <span><i class="fas fa-clock"></i> Last seen: <?= isset($device['last_seen']) ? date('M d, Y h:i A', strtotime($device['last_seen'])) : 'N/A' ?></span>
                                </div>
                            </div>
                            <div class="device-status">
                                <?php if (!empty($device['is_trusted'])): ?>
                                <span class="trust-badge trusted"><i class="fas fa-shield-alt"></i> Trusted</span>
                                <?php else: ?>
                                <span class="trust-badge not-trusted"><i class="fas fa-question-circle"></i> Not Trusted</span>
                                <?php endif; ?>
                            </div>
                            <div class="device-actions">
                                <?php if (!$is_current): ?>
                                <button class="btn-device-action btn-toggle-trust" onclick="toggleDeviceTrust(<?= $device['id'] ?>)" title="<?= !empty($device['is_trusted']) ? 'Remove Trust' : 'Trust Device' ?>">
                                    <i class="fas <?= !empty($device['is_trusted']) ? 'fa-unlock' : 'fa-lock' ?>"></i>
                                </button>
                                <button class="btn-device-action btn-remove" onclick="removeDevice(<?= $device['id'] ?>)" title="Remove Device">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php else: ?>
                                <?php if (empty($device['is_trusted'])): ?>
                                <button class="btn-device-action btn-trust-current" onclick="trustCurrentDevice()" title="Trust This Device">
                                    <i class="fas fa-shield-alt"></i> Trust
                                </button>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Toast Notification -->
<div id="toast" class="toast">
    <i class="fas fa-check-circle"></i>
    <span id="toastMessage"></span>
    <button class="close-btn" onclick="closeToast()">&times;</button>
</div>

<script>
// Toggle password visibility
function togglePassword(inputId, icon) {
    const input = document.getElementById(inputId);
    if (input.type === "password") {
        input.type = "text";
        icon.classList.replace("fa-eye", "fa-eye-slash");
    } else {
        input.type = "password";
        icon.classList.replace("fa-eye-slash", "fa-eye");
    }
}

// Password strength checker
function checkPasswordStrength(password) {
    const strengthEl = document.getElementById('passwordStrength');
    strengthEl.classList.remove('weak', 'medium', 'strong');
    
    if (password.length === 0) return;
    
    let strength = 0;
    if (password.length >= 8) strength++;
    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^a-zA-Z0-9]/.test(password)) strength++;
    
    if (strength <= 1) strengthEl.classList.add('weak');
    else if (strength <= 2) strengthEl.classList.add('medium');
    else strengthEl.classList.add('strong');
}

// OTP Input handling
document.querySelectorAll('.otp-digit').forEach((input, index, inputs) => {
    input.addEventListener('input', (e) => {
        const value = e.target.value;
        if (value.length === 1 && index < inputs.length - 1) {
            inputs[index + 1].focus();
        }
    });
    
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !e.target.value && index > 0) {
            inputs[index - 1].focus();
        }
    });
    
    input.addEventListener('paste', (e) => {
        e.preventDefault();
        const paste = (e.clipboardData || window.clipboardData).getData('text');
        const digits = paste.replace(/\D/g, '').split('').slice(0, 6);
        digits.forEach((digit, i) => {
            if (inputs[i]) inputs[i].value = digit;
        });
        if (digits.length > 0) inputs[Math.min(digits.length, inputs.length - 1)].focus();
    });
});

// Step navigation
function goToStep(step) {
    document.querySelectorAll('.step-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.step').forEach(el => el.classList.remove('active', 'completed'));
    
    document.getElementById(`step${step}Content`).classList.add('active');
    
    for (let i = 1; i <= step; i++) {
        const indicator = document.getElementById(`step${i}Indicator`);
        if (i < step) indicator.classList.add('completed');
        if (i === step) indicator.classList.add('active');
    }
}

// OTP Timer
let timerInterval;
function startOTPTimer() {
    let seconds = 60;
    const timerEl = document.getElementById('resendTimer');
    const resendLink = document.getElementById('resendLink');
    
    resendLink.classList.add('disabled');
    timerEl.style.display = 'inline';
    resendLink.style.display = 'none';
    
    timerInterval = setInterval(() => {
        seconds--;
        timerEl.textContent = `Resend in ${seconds}s`;
        
        if (seconds <= 0) {
            clearInterval(timerInterval);
            timerEl.style.display = 'none';
            resendLink.style.display = 'inline';
            resendLink.classList.remove('disabled');
        }
    }, 1000);
}

// AJAX: Request OTP
function requestOTP() {
    const btn = event.target;
    btn.classList.add('loading');
    btn.disabled = true;
    
    fetch('ajax_email_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=send_otp'
    })
    .then(res => res.json())
    .then(data => {
        showToast(data.message, data.status);
        if (data.status === 'success') {
            goToStep(2);
            startOTPTimer();
        }
    })
    .catch(() => showToast('An error occurred', 'error'))
    .finally(() => {
        btn.classList.remove('loading');
        btn.disabled = false;
    });
}

// AJAX: Verify OTP
function verifyOTP() {
    const otpInputs = document.querySelectorAll('.otp-digit');
    let otp = '';
    otpInputs.forEach(input => otp += input.value);
    
    if (otp.length !== 6) {
        showToast('Please enter complete OTP', 'warning');
        return;
    }
    
    const btn = event.target;
    btn.classList.add('loading');
    btn.disabled = true;
    
    fetch('ajax_email_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=verify_otp&otp=${encodeURIComponent(otp)}`
    })
    .then(res => res.json())
    .then(data => {
        showToast(data.message, data.status);
        if (data.status === 'success') {
            clearInterval(timerInterval);
            goToStep(3);
        }
    })
    .catch(() => showToast('An error occurred', 'error'))
    .finally(() => {
        btn.classList.remove('loading');
        btn.disabled = false;
    });
}

// AJAX: Confirm new email
function confirmEmail() {
    const newEmail = document.getElementById('newEmailInput').value.trim();
    
    if (!newEmail || !newEmail.includes('@')) {
        showToast('Please enter a valid email', 'warning');
        return;
    }
    
    const btn = event.target;
    btn.classList.add('loading');
    btn.disabled = true;
    
    fetch('ajax_email_actions.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=confirm_email&new_email=${encodeURIComponent(newEmail)}`
    })
    .then(res => res.json())
    .then(data => {
        showToast(data.message, data.status);
        if (data.status === 'success') {
            setTimeout(() => location.reload(), 1500);
        }
    })
    .catch(() => showToast('An error occurred', 'error'))
    .finally(() => {
        btn.classList.remove('loading');
        btn.disabled = false;
    });
}

// Toast functions
function showToast(message, type = 'success', duration = 4000) {
    const toast = document.getElementById('toast');
    const toastMessage = document.getElementById('toastMessage');
    const icon = toast.querySelector('i:first-child');
    
    // Set icon based on type
    icon.className = 'fas';
    switch(type) {
        case 'success': icon.classList.add('fa-check-circle'); break;
        case 'error': icon.classList.add('fa-times-circle'); break;
        case 'warning': icon.classList.add('fa-exclamation-circle'); break;
        default: icon.classList.add('fa-info-circle');
    }
    
    toast.className = `toast show ${type}`;
    toastMessage.textContent = message;
    
    setTimeout(() => toast.classList.remove('show'), duration);
}

function closeToast() {
    document.getElementById('toast').classList.remove('show');
}

// Show PHP notifications
<?php if(!empty($notif)): ?>
showToast("<?= addslashes($notif) ?>", "<?= $notif_type ?>");
<?php endif; ?>
<?php if(!empty($pass_msg)): ?>
showToast("<?= addslashes($pass_msg) ?>", "<?= $pass_type ?>");
<?php endif; ?>

// Form submit loading state
document.getElementById('passwordForm')?.addEventListener('submit', function(e) {
    const btn = this.querySelector('button[type="submit"]');
    btn.classList.add('loading');
    btn.disabled = true;
});

// ==========================================
// DEVICE MANAGEMENT FUNCTIONS
// ==========================================

function removeDevice(deviceId) {
    if (!confirm('Are you sure you want to remove this device? You will need to verify again when logging in from this device.')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('device_action', 'remove');
    formData.append('device_id', deviceId);
    
    fetch('settings.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            showToast(data.message, 'success');
            // Remove the device item from DOM
            const deviceItem = document.querySelector(`[data-device-id="${deviceId}"]`);
            if (deviceItem) {
                deviceItem.style.opacity = '0';
                deviceItem.style.transform = 'translateX(20px)';
                setTimeout(() => deviceItem.remove(), 300);
            }
        } else {
            showToast(data.message || 'Failed to remove device', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('An error occurred. Please try again.', 'error');
    });
}

function toggleDeviceTrust(deviceId) {
    const formData = new FormData();
    formData.append('device_action', 'toggle_trust');
    formData.append('device_id', deviceId);
    
    fetch('settings.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            showToast(data.message, 'success');
            // Reload page to reflect changes
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.message || 'Failed to update device', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('An error occurred. Please try again.', 'error');
    });
}

function trustCurrentDevice() {
    const formData = new FormData();
    formData.append('device_action', 'trust_current');
    
    fetch('settings.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            showToast(data.message, 'success');
            // Reload page to reflect changes
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.message || 'Failed to trust device', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('An error occurred. Please try again.', 'error');
    });
}
</script>

</body>
</html>

    <?php
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    session_start();
    header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");

    include(__DIR__ . "/Connection/Config.php");
    require_once __DIR__ . "/Connection/notifications_helper.php";
    require_once __DIR__ . "/Connection/hr1_db.php";

    // ================= LOGIN ATTEMPT LIMITER =================
    $maxAttempts = 5;          // Maximum failed login attempts
    $lockoutTime = 5 * 60;    // Lockout duration in seconds (5 minutes)

    // ===== DEVELOPER RESET: Add ?reset_lock=osave2025 to URL to reset lockout =====
    if (isset($_GET['reset_lock']) && $_GET['reset_lock'] === 'osave2025') {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['last_attempt_time'] = 0;
        $_SESSION['lockout_until'] = 0;
        echo "<script>alert('🔓 Lockout reset successfully!'); window.location.href='index.php';</script>";
        exit;
    }

    // Initialize login attempts tracking
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['last_attempt_time'] = 0;
        $_SESSION['lockout_until'] = 0;
    };

    // Check if user is currently locked out
    function isLockedOut() {
        global $lockoutTime;
        if ($_SESSION['lockout_until'] > time()) {
            return true;
        }
        // Reset if lockout expired
        if ($_SESSION['lockout_until'] > 0 && $_SESSION['lockout_until'] <= time()) {
            $_SESSION['login_attempts'] = 0;
            $_SESSION['lockout_until'] = 0;
        }
        return false;
    }

    // Get remaining lockout time in minutes
    function getRemainingLockoutTime() {
        $remaining = $_SESSION['lockout_until'] - time();
        return ceil($remaining / 60);
    }

    // Record failed login attempt
    function recordFailedAttempt() {
        global $maxAttempts, $lockoutTime;
        $_SESSION['login_attempts']++;
        $_SESSION['last_attempt_time'] = time();
        
        if ($_SESSION['login_attempts'] >= $maxAttempts) {
            $_SESSION['lockout_until'] = time() + $lockoutTime;
        }
    }

    // Reset login attempts on successful login
    function resetLoginAttempts() {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['last_attempt_time'] = 0;
        $_SESSION['lockout_until'] = 0;
    }

    // Get remaining attempts
    function getRemainingAttempts() {
        global $maxAttempts;
        return max(0, $maxAttempts - $_SESSION['login_attempts']);
    }

    // ================= PHPMailer =================
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;

    require __DIR__ . "/PHPMailer/src/PHPMailer.php";
    require __DIR__ . "/PHPMailer/src/SMTP.php";
    require __DIR__ . "/PHPMailer/src/Exception.php";

    // ================= OTP VERIFY (AJAX) =================
    if (isset($_POST['verify_otp'])) {
        $otp = trim($_POST['otp'] ?? '');
        $uid = $_SESSION['otp_user_id'] ?? 0;

        $stmt = $conn->prepare("SELECT otp_code, otp_expiry, role FROM users WHERE id=?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            echo json_encode(['status'=>'error','msg'=>'Session expired']);
            exit;
        }

        if (strtotime($user['otp_expiry']) < time()) {
            echo json_encode(['status'=>'error','msg'=>'OTP expired']);
            exit;
        }

        if ($otp !== $user['otp_code']) {
            echo json_encode(['status'=>'error','msg'=>'Invalid OTP']);
            exit;
        }

        // SUCCESS
        $conn->query("UPDATE users SET otp_code=NULL, otp_expiry=NULL WHERE id=$uid");

        $_SESSION['user_id'] = $uid;
        $_SESSION['role']    = $user['role'];
        
        // Get full user data for session
        $user_stmt = $conn->prepare("SELECT full_name, email FROM users WHERE id = ?");
        $user_stmt->bind_param("i", $uid);
        $user_stmt->execute();
        $user_full = $user_stmt->get_result()->fetch_assoc();
        $user_stmt->close();
        
        $_SESSION['full_name'] = $user_full['full_name'] ?? '';
        $_SESSION['email'] = $user_full['email'] ?? '';
        $_SESSION['username'] = explode('@', $user_full['email'] ?? '')[0];

        // Check if user wants to trust this device
        $trust_device = isset($_POST['trust_device']) && $_POST['trust_device'] === '1';
        
        // Only notify for new device logins (avoid login notification spam)
        $isNewDevice = record_device_login($conn, $uid, $trust_device);
        if ($isNewDevice) {
            $ip = get_client_ip();
            create_notification($conn, $uid, 'security', "New device login detected from IP: $ip");
        }

        unset($_SESSION['otp_user_id'], $_SESSION['otp_email'], $_SESSION['new_device_login']);

        $redirect = ($user['role'] === 'admin' || $user['role'] === 'Super Admin')
            ? 'admin.php'
            : 'employee.php';

        echo json_encode(['status'=>'success','redirect'=>$redirect]);
        exit;
    }

    // ================= LOGIN =================
    $flash = null;

    if ($_SERVER["REQUEST_METHOD"] === "POST" && !isset($_POST['verify_otp'])) {

        // Check if locked out
        if (isLockedOut()) {
            $minutes = getRemainingLockoutTime();
            $flash = [
                'type'=>'error',
                'title'=>'Account Temporarily Locked',
                'text'=>"Too many failed login attempts. Please try again in $minutes minute(s)."
            ];
        } else {
            $email    = trim($_POST['email'] ?? '');
            $password = trim($_POST['password'] ?? '');

            if ($email === '' || $password === '') {
                $flash = ['type'=>'error','title'=>'Missing Fields','text'=>'Please enter both email and password.'];
            } elseif (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
                $flash = ['type'=>'error','title'=>'Weak Password','text'=>'Password must be at least 8 characters with letters, numbers, and special characters.'];
            } else {

                $stmt = $conn->prepare("SELECT id, full_name, email, password, role, is_verified FROM users WHERE email=?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $res = $stmt->get_result();
                
                $user = null;
                $from_hr1 = false;
                $from_employee_table = false;

                if ($res->num_rows === 1) {
                    $user = $res->fetch_assoc();
                } else {
                    // ===== CHECK users_employee TABLE (HR1 synced employee accounts) =====
                    $empTableExists = $conn->query("SHOW TABLES LIKE 'users_employee'")->num_rows > 0;
                    if ($empTableExists) {
                        $empStmt = $conn->prepare("SELECT id, hr1_employee_id, hr1_user_id, employee_code, full_name, email, password, job_position, department, site, avatar, employment_status, is_active FROM users_employee WHERE email = ? AND is_active = 1 LIMIT 1");
                        $empStmt->bind_param("s", $email);
                        $empStmt->execute();
                        $empResult = $empStmt->get_result();
                        
                        if ($empResult->num_rows === 1) {
                            $empUser = $empResult->fetch_assoc();
                            $user = [
                                'id' => $empUser['id'],
                                'full_name' => $empUser['full_name'],
                                'email' => $empUser['email'],
                                'password' => $empUser['password'],
                                'role' => 'employee',
                                'is_verified' => 1,
                                'hr1_employee_id' => $empUser['hr1_employee_id'],
                                'hr1_user_id' => $empUser['hr1_user_id'],
                                'employee_code' => $empUser['employee_code'],
                                'job_position' => $empUser['job_position'],
                                'department' => $empUser['department'],
                                'site' => $empUser['site'],
                                'avatar' => $empUser['avatar'],
                                'emp_table_id' => $empUser['id']
                            ];
                            $from_employee_table = true;
                        }
                        $empStmt->close();
                    }
                    
                    // ===== HR1 USER FALLBACK (direct DB check) =====
                    if (!$user) {
                        $hr1db = new HR1Database();
                        $hr1pdo = $hr1db->getPDO();
                        
                        if ($hr1pdo) {
                            try {
                                $hr1stmt = $hr1pdo->prepare("SELECT id, name, email, password_hash, role, department, employee_id, is_active FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
                                $hr1stmt->execute([$email]);
                                $hr1user = $hr1stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if ($hr1user) {
                                    // Convert HR1 user to HR2 format
                                    $user = [
                                        'id' => $hr1user['id'],
                                        'full_name' => $hr1user['name'],
                                        'email' => $hr1user['email'],
                                        'password' => $hr1user['password_hash'],
                                        'role' => $hr1user['role'] === 'admin' || $hr1user['role'] === 'HR Manager' || $hr1user['role'] === 'HR Administrator' ? 'admin' : 'employee',
                                        'is_verified' => 1,
                                        'hr1_role' => $hr1user['role'],
                                        'hr1_department' => $hr1user['department'],
                                        'hr1_employee_id' => $hr1user['employee_id']
                                    ];
                                    $from_hr1 = true;
                                }
                            } catch (Exception $e) {
                                error_log("HR1 Login Check Error: " . $e->getMessage());
                            }
                        }
                    }
                }

                if (!$user) {
                    recordFailedAttempt();
                    $remaining = getRemainingAttempts();
                    $flash = [
                        'type'=>'error',
                        'title'=>'Account Not Found',
                        'text'=>$remaining > 0 
                            ? "No account found. $remaining attempt(s) remaining."
                            : 'Account locked. Please try again later.'
                    ];
                } else {
                    $valid = password_verify($password, $user['password']) || $password === $user['password'];

                    if (!$valid) {
                        recordFailedAttempt();
                        $remaining = getRemainingAttempts();
                        $flash = [
                            'type'=>'error',
                            'title'=>'Invalid Password',
                            'text'=>$remaining > 0 
                                ? "Incorrect password. $remaining attempt(s) remaining."
                                : 'Account locked due to too many failed attempts. Please try again in 5 minutes.'
                        ];
                    } elseif ($user['is_verified'] == 0) {
                        $flash = ['type'=>'error','title'=>'Account Not Verified','text'=>'Please verify your account first.'];
                    } else {
                        // Successful login - reset attempts
                        resetLoginAttempts();
                        
                        // ===== HR1 USER: SYNC TO HR2 & LOGIN =====
                        if ($from_hr1) {
                            // Check if user already exists in HR2 users table
                            $check_hr2 = $conn->prepare("SELECT id FROM users WHERE email = ?");
                            $check_hr2->bind_param("s", $email);
                            $check_hr2->execute();
                            $hr2_exists = $check_hr2->get_result();
                            
                            if ($hr2_exists->num_rows > 0) {
                                // User exists in HR2, get their ID
                                $hr2_user = $hr2_exists->fetch_assoc();
                                $hr2_user_id = $hr2_user['id'];
                                
                                // Update HR2 user with latest HR1 data
                                $update_hr2 = $conn->prepare("UPDATE users SET full_name = ?, role = ?, is_verified = 1 WHERE id = ?");
                                $update_hr2->bind_param("ssi", $user['full_name'], $user['role'], $hr2_user_id);
                                $update_hr2->execute();
                                $update_hr2->close();
                            } else {
                                // Create new user in HR2 from HR1 data
                                $insert_hr2 = $conn->prepare("INSERT INTO users (full_name, email, password, role, is_verified, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
                                $insert_hr2->bind_param("ssss", $user['full_name'], $user['email'], $user['password'], $user['role']);
                                $insert_hr2->execute();
                                $hr2_user_id = $conn->insert_id;
                                $insert_hr2->close();
                            }
                            $check_hr2->close();
                            
                            // Use HR2 user ID for session (important for profile.php compatibility)
                            $_SESSION['user_id'] = (int)$hr2_user_id;
                            $_SESSION['full_name'] = $user['full_name'];
                            $_SESSION['email'] = $user['email'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['username'] = explode('@', $user['email'])[0];
                            $_SESSION['from_hr1'] = true;
                            $_SESSION['hr1_user_id'] = (int)$user['id']; // Store original HR1 user ID
                            $_SESSION['hr1_role'] = $user['hr1_role'] ?? '';
                            $_SESSION['hr1_department'] = $user['hr1_department'] ?? '';
                            $_SESSION['hr1_employee_id'] = $user['hr1_employee_id'] ?? null;
                            $_SESSION['LAST_ACTIVITY'] = time();
                            
                            // Redirect based on role
                            $redirect = ($user['role'] === 'admin') ? 'admin.php' : 'employee.php';
                            header("Location: $redirect");
                            exit;
                        }
                        
                        // ===== EMPLOYEE TABLE LOGIN (users_employee) =====
                        if ($from_employee_table) {
                            // Update last_login and login_count
                            $updateLogin = $conn->prepare("UPDATE users_employee SET last_login = NOW(), login_count = login_count + 1 WHERE id = ?");
                            $empTableId = (int)$user['emp_table_id'];
                            $updateLogin->bind_param("i", $empTableId);
                            $updateLogin->execute();
                            $updateLogin->close();
                            
                            // Set session variables for employee
                            $_SESSION['user_id'] = (int)$user['emp_table_id'];
                            $_SESSION['full_name'] = $user['full_name'];
                            $_SESSION['email'] = $user['email'];
                            $_SESSION['role'] = 'employee';
                            $_SESSION['username'] = explode('@', $user['email'])[0];
                            $_SESSION['from_employee_table'] = true;
                            $_SESSION['hr1_employee_id'] = $user['hr1_employee_id'] ?? null;
                            $_SESSION['hr1_user_id'] = $user['hr1_user_id'] ?? null;
                            $_SESSION['employee_code'] = $user['employee_code'] ?? '';
                            $_SESSION['job_position'] = $user['job_position'] ?? '';
                            $_SESSION['department'] = $user['department'] ?? '';
                            $_SESSION['site'] = $user['site'] ?? '';
                            $_SESSION['avatar'] = $user['avatar'] ?? '';
                            $_SESSION['LAST_ACTIVITY'] = time();
                            
                            header("Location: employee.php");
                            exit;
                        }
                        
                        // Check device trust status: 'trusted' | 'known' | 'new'
                        $device_status = check_device_status($conn, $user['id']);
                        $is_trusted_device = ($device_status === 'trusted');
                        $is_new_device = ($device_status === 'new');
                        $_SESSION['new_device_login'] = $is_new_device;

                        // ===== TRUSTED DEVICE: SKIP OTP =====
                        if ($is_trusted_device) {
                            // Direct login for trusted devices
                            $_SESSION['user_id'] = $user['id'];
                            $_SESSION['full_name'] = $user['full_name'];
                            $_SESSION['email'] = $user['email'];
                            $_SESSION['role'] = $user['role'];
                            $_SESSION['username'] = explode('@', $user['email'])[0];
                            
                            // Record login and update last_seen (no notification for trusted device - avoid spam)
                            record_device_login($conn, $user['id'], false);
                            
                            // Redirect based on role
                            $redirect = ($user['role'] === 'admin' || $user['role'] === 'Super Admin')
                                ? 'admin.php'
                                : 'employee.php';
                            
                            header("Location: $redirect");
                            exit;
                        }

                    // ===== NON-TRUSTED DEVICE: REQUIRE OTP =====
                    $otp    = rand(100000, 999999);
                    $expiry = date("Y-m-d H:i:s", strtotime("+2 minutes"));

                    $up = $conn->prepare("UPDATE users SET otp_code=?, otp_expiry=? WHERE id=?");
                    $up->bind_param("ssi", $otp, $expiry, $user['id']);
                    $up->execute();

                    $_SESSION['otp_user_id'] = $user['id'];
                    $_SESSION['otp_email']   = $user['email'];
                    $_SESSION['otp_full_name'] = $user['full_name'];
                    
                    // Get device info for email
                    $client_ip = get_client_ip();
                    $device_name = get_device_name($_SERVER['HTTP_USER_AGENT'] ?? '');
                    $login_time = date('M d, Y h:i A');

                    // ===== SEND OTP EMAIL =====
                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host       = 'smtp.gmail.com';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = 'osaveproject2025@gmail.com';
                        $mail->Password   = 'bimyaxskvwiytmxl';
                        $mail->SMTPSecure = 'tls';
                        $mail->Port       = 587;

                        $mail->setFrom('osaveproject2025@gmail.com', 'O!SAVE Verification');
                        $mail->addAddress($user['email']);

                        $mail->isHTML(true);
                        
                        // Different email based on device status
                        // $is_new_device = true for new devices, false for known (but not trusted) devices
                        if ($is_new_device) {
                            $mail->Subject = '⚠️ New Device Login Attempt - OTP Required';
                            $mail->Body = "
                                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                                    <div style='background: linear-gradient(135deg, #dc3545, #c82333); padding: 20px; text-align: center;'>
                                        <h1 style='color: #fff; margin: 0;'>⚠️ Security Alert</h1>
                                    </div>
                                    <div style='padding: 30px; background: #f8f9fa;'>
                                        <h2 style='color: #dc3545;'>New Device Login Detected</h2>
                                        <p>A login attempt was made from an <strong>unrecognized device</strong>:</p>
                                        <table style='width: 100%; background: #fff; padding: 15px; border-radius: 8px; margin: 15px 0;'>
                                            <tr><td style='padding: 8px; color: #666;'>Device:</td><td style='padding: 8px; font-weight: bold;'>$device_name</td></tr>
                                            <tr><td style='padding: 8px; color: #666;'>IP Address:</td><td style='padding: 8px; font-weight: bold;'>$client_ip</td></tr>
                                            <tr><td style='padding: 8px; color: #666;'>Time:</td><td style='padding: 8px; font-weight: bold;'>$login_time</td></tr>
                                        </table>
                                        <p>If this was you, enter this OTP to continue:</p>
                                        <div style='background: #dc3545; color: #fff; font-size: 32px; font-weight: bold; text-align: center; padding: 20px; border-radius: 8px; letter-spacing: 8px; margin: 20px 0;'>$otp</div>
                                        <p style='color: #666; font-size: 14px;'>⏰ This code expires in 2 minutes.</p>
                                        <p style='color: #dc3545; font-size: 14px;'><strong>If this wasn't you</strong>, please ignore this email and consider changing your password immediately.</p>
                                        <p style='color: #666; font-size: 13px; margin-top: 15px;'>💡 Tip: You can mark this device as \"Trusted\" during login to skip OTP next time.</p>
                                    </div>
                                </div>
                            ";
                        } else {
                            // Known but not trusted device
                            $mail->Subject = '🔐 OTP Verification - O!SAVE HR2';
                            $mail->Body = "
                                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                                    <div style='background: linear-gradient(135deg, #e67e22, #d35400); padding: 20px; text-align: center;'>
                                        <h1 style='color: #fff; margin: 0;'>O!SAVE HR2</h1>
                                    </div>
                                    <div style='padding: 30px; background: #f8f9fa;'>
                                        <h2 style='color: #e67e22;'>OTP Verification Required</h2>
                                        <p>Login from a <strong>recognized but untrusted device</strong>:</p>
                                        <table style='width: 100%; background: #fff; padding: 15px; border-radius: 8px; margin: 15px 0;'>
                                            <tr><td style='padding: 8px; color: #666;'>Device:</td><td style='padding: 8px; font-weight: bold;'>$device_name</td></tr>
                                            <tr><td style='padding: 8px; color: #666;'>IP Address:</td><td style='padding: 8px; font-weight: bold;'>$client_ip</td></tr>
                                            <tr><td style='padding: 8px; color: #666;'>Time:</td><td style='padding: 8px; font-weight: bold;'>$login_time</td></tr>
                                        </table>
                                        <p>Your One-Time Password is:</p>
                                        <div style='background: #e67e22; color: #fff; font-size: 32px; font-weight: bold; text-align: center; padding: 20px; border-radius: 8px; letter-spacing: 8px; margin: 20px 0;'>$otp</div>
                                        <p style='color: #666; font-size: 14px;'>⏰ This code expires in 2 minutes.</p>
                                        <p style='color: #666; font-size: 13px; margin-top: 15px;'>💡 Tip: Mark this device as \"Trusted\" in Settings to skip OTP verification.</p>
                                    </div>
                                </div>
                            ";
                        }

                        $mail->send();

                        create_notification($conn, $user['id'], 'security', 'OTP sent to your email for login.');

                        $flash = [
                            'type'=>'success',
                            'title'=> $is_new_device ? 'New Device Detected' : 'Verification Required',
                            'text'=> $is_new_device ? 'Verify your identity with OTP.' : 'This device is not trusted. OTP required.',
                            'otp'=>true,
                            'new_device'=>$is_new_device
                        ];

                    } catch (Exception $e) {
                        $flash = ['type'=>'error','title'=>'Email Error','text'=>$mail->ErrorInfo];
                    }
                    }
                }
            }
        }
    }

    ?>

    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <title>O!SAVE | Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <link rel="stylesheet" href="Css/login.css?v=<?php echo time(); ?>">
    <link rel="icon" type="image/png" href="osicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    </head>

    <body>
    <!-- Background Shapes -->
    <div class="bg-shapes">
    <div class="shape"></div>
    <div class="shape"></div>
    <div class="shape"></div>
    <div class="shape"></div>
    <div class="shape"></div>
    <div class="shape"></div>
</div>

    <div class="login-wrapper">
        <div class="login-card">
            <!-- Left Side - Form -->
            <div class="login-left">
                <!-- Header -->
                <div class="login-header">
                    <h1 class="brand-name">O!SAVE</h1>
                    <p class="brand-tagline">Workforce Portal</p>
                </div>

                <!-- Welcome -->
                <div class="welcome-section">
                    <h2>Welcome Back!</h2>
                    <p>Sign in to access your account</p>
                </div>

                <!-- Form -->
                <form class="login-form" method="POST" id="loginForm">
                    <!-- EMAIL -->
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <div class="input-group">
                            <input type="email" name="email" id="email" placeholder="Enter your email" required
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" autocomplete="email">
                            <i class="fas fa-envelope input-icon"></i>
                        </div>
                    </div>

                    <!-- PASSWORD -->
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-group">
                            <input type="password" name="password" id="password" placeholder="Enter your password" required autocomplete="current-password">
                            <i class="fas fa-lock input-icon"></i>
                            <button type="button" class="toggle-password" id="togglePassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Password Strength Indicator -->
                    <div class="password-strength" id="passwordStrength" style="display:none;">
                        <div class="strength-bars">
                            <div class="strength-bar" id="bar1"></div>
                            <div class="strength-bar" id="bar2"></div>
                            <div class="strength-bar" id="bar3"></div>
                            <div class="strength-bar" id="bar4"></div>
                        </div>
                        <span class="strength-text" id="strengthText"></span>
                        <div class="password-requirements" id="passwordReqs">
                            <div class="req" id="reqLength"><i class="fas fa-circle"></i> Min. 8 characters</div>
                            <div class="req" id="reqLetter"><i class="fas fa-circle"></i> Contains letter</div>
                            <div class="req" id="reqNumber"><i class="fas fa-circle"></i> Contains number</div>
                            <div class="req" id="reqSpecial"><i class="fas fa-circle"></i> Contains special character</div>
                        </div>
                    </div>

                    <button type="submit" class="login-btn" id="loginBtn">
                        <span class="btn-text">Sign In</span>
                        <span class="btn-loader"><i class="fas fa-circle-notch fa-spin"></i> Signing in...</span>
                        <i class="fas fa-arrow-right btn-icon"></i>
                    </button>
                </form>

                <!-- Footer -->
                <div class="login-footer">
                    <p>&copy; <?= date('Y') ?> O!SAVE Convenience Store</p>
                </div>
            </div>

            <!-- Right Side - Branding -->
            <div class="login-right">
                <div class="brand-illustration">
                    <div class="brand-logo-large">
                        <img src="oslogo.png" alt="O!SAVE Logo">
                    </div>
                    <h2 class="brand-title">O!SAVE</h2>
                    <p class="brand-subtitle">Convenience Store</p>
                    <div class="brand-features">
                        <div class="brand-feature">
                            <i class="fas fa-users"></i>
                            <span>Employee Management</span>
                        </div>
                        <div class="brand-feature">
                            <i class="fas fa-chart-line"></i>
                            <span>Performance Tracking</span>
                        </div>
                        <div class="brand-feature">
                            <i class="fas fa-shield-alt"></i>
                            <span>Secure Access</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const flash = <?= json_encode($flash); ?>;
        
        // Toggle password visibility
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            const icon = this.querySelector('i');
            icon.classList.toggle('fa-eye');
            icon.classList.toggle('fa-eye-slash');
        });
        
        // Password strength checker
        const passwordField = document.getElementById('password');
        const strengthContainer = document.getElementById('passwordStrength');
        const strengthText = document.getElementById('strengthText');
        const bars = [document.getElementById('bar1'), document.getElementById('bar2'), document.getElementById('bar3'), document.getElementById('bar4')];
        const reqLength = document.getElementById('reqLength');
        const reqLetter = document.getElementById('reqLetter');
        const reqNumber = document.getElementById('reqNumber');
        const reqSpecial = document.getElementById('reqSpecial');
        
        passwordField.addEventListener('input', function() {
            const val = this.value;
            if (val.length === 0) {
                strengthContainer.style.display = 'none';
                return;
            }
            strengthContainer.style.display = 'block';
            
            const hasLength = val.length >= 8;
            const hasLetter = /[A-Za-z]/.test(val);
            const hasNumber = /[0-9]/.test(val);
            const hasSpecial = /[^A-Za-z0-9]/.test(val);
            
            // Update requirement checks
            updateReq(reqLength, hasLength);
            updateReq(reqLetter, hasLetter);
            updateReq(reqNumber, hasNumber);
            updateReq(reqSpecial, hasSpecial);
            
            let score = [hasLength, hasLetter, hasNumber, hasSpecial].filter(Boolean).length;
            
            // Reset bars
            bars.forEach(b => { b.className = 'strength-bar'; });
            
            const levels = [
                { min: 1, cls: 'weak', text: 'Weak', color: '#ef4444' },
                { min: 2, cls: 'fair', text: 'Fair', color: '#f59e0b' },
                { min: 3, cls: 'good', text: 'Good', color: '#22c55e' },
                { min: 4, cls: 'strong', text: 'Strong', color: '#16a34a' }
            ];
            
            const level = levels[score - 1] || levels[0];
            for (let i = 0; i < score; i++) {
                bars[i].classList.add(level.cls);
            }
            strengthText.textContent = level.text;
            strengthText.style.color = level.color;
        });
        
        function updateReq(el, met) {
            const icon = el.querySelector('i');
            if (met) {
                el.classList.add('met');
                icon.className = 'fas fa-check-circle';
            } else {
                el.classList.remove('met');
                icon.className = 'fas fa-circle';
            }
        }
        
        // Form validation & loading state
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const pw = passwordField.value;
            if (pw.length < 8 || !/[A-Za-z]/.test(pw) || !/[0-9]/.test(pw) || !/[^A-Za-z0-9]/.test(pw)) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Password Requirements',
                    html: 'Password must have:<br><strong>8+ characters</strong>, <strong>letters</strong>, <strong>numbers</strong>, and <strong>special characters</strong>',
                    confirmButtonColor: '#dc2626'
                });
                return;
            }
            const btn = document.getElementById('loginBtn');
            btn.classList.add('loading');
        });
        
        // Input focus effects
        document.querySelectorAll('.input-group input').forEach(input => {
            input.addEventListener('focus', () => {
                input.closest('.input-group').classList.add('focused');
            });
            input.addEventListener('blur', () => {
                input.closest('.input-group').classList.remove('focused');
            });
        });
        
        if (!flash) return;

        // Custom OTP Modal
        if (flash.otp) {
            const isNewDevice = flash.new_device || false;
            
            Swal.fire({
                icon: isNewDevice ? 'warning' : 'info',
                title: isNewDevice ? '⚠️ New Device Detected' : '🔐 Verification Required',
                text: isNewDevice 
                    ? 'Login attempt from an unrecognized device. Please verify with OTP.' 
                    : 'This device is not trusted. OTP verification required.',
                confirmButtonColor: '#dc2626'
            }).then(() => {
                Swal.fire({
                    title: '<span style="font-weight: 700; color: #dc2626;">🔐 Security Verification</span>',
                    html: `
                        ${isNewDevice 
                            ? '<div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px; margin-bottom: 1rem;"><p style="color: #dc2626; margin: 0; font-size: 0.9rem;"><i class="fas fa-exclamation-triangle"></i> New device/IP detected. Enter OTP to continue.</p></div>' 
                            : '<div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px; margin-bottom: 1rem;"><p style="color: #dc2626; margin: 0; font-size: 0.9rem;"><i class="fas fa-shield-alt"></i> Device recognized but not trusted. OTP required.</p></div>'
                        }
                        <p style="color: #6b7280; margin-bottom: 1.5rem;">We sent a 6-digit code to your email</p>
                        <div style="display: flex; gap: 0.5rem; justify-content: center; margin-bottom: 1rem;">
                            <input type="text" maxlength="1" class="otp-box" data-index="0" style="width: 48px; height: 56px; text-align: center; font-size: 1.5rem; font-weight: 700; border: 2px solid #e5e7eb; border-radius: 10px; outline: none;">
                            <input type="text" maxlength="1" class="otp-box" data-index="1" style="width: 48px; height: 56px; text-align: center; font-size: 1.5rem; font-weight: 700; border: 2px solid #e5e7eb; border-radius: 10px; outline: none;">
                            <input type="text" maxlength="1" class="otp-box" data-index="2" style="width: 48px; height: 56px; text-align: center; font-size: 1.5rem; font-weight: 700; border: 2px solid #e5e7eb; border-radius: 10px; outline: none;">
                            <input type="text" maxlength="1" class="otp-box" data-index="3" style="width: 48px; height: 56px; text-align: center; font-size: 1.5rem; font-weight: 700; border: 2px solid #e5e7eb; border-radius: 10px; outline: none;">
                            <input type="text" maxlength="1" class="otp-box" data-index="4" style="width: 48px; height: 56px; text-align: center; font-size: 1.5rem; font-weight: 700; border: 2px solid #e5e7eb; border-radius: 10px; outline: none;">
                            <input type="text" maxlength="1" class="otp-box" data-index="5" style="width: 48px; height: 56px; text-align: center; font-size: 1.5rem; font-weight: 700; border: 2px solid #e5e7eb; border-radius: 10px; outline: none;">
                        </div>
                        <p style="font-size: 0.85rem; color: #9ca3af;">Code expires in 2 minutes</p>
                        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e5e7eb;">
                            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; justify-content: center;">
                                <input type="checkbox" id="trustDevice" style="width: 18px; height: 18px; accent-color: #dc2626; cursor: pointer;">
                                <span style="color: #374151; font-size: 0.9rem;">Trust this device (skip OTP next time)</span>
                            </label>
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-check"></i> Verify',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#6b7280',
                    showLoaderOnConfirm: true,
                    didOpen: () => {
                        const boxes = document.querySelectorAll('.otp-box');
                        boxes[0].focus();
                        
                        boxes.forEach((box, index) => {
                            box.addEventListener('input', (e) => {
                                const val = e.target.value;
                                if (val.length === 1 && index < boxes.length - 1) {
                                    boxes[index + 1].focus();
                                }
                            });
                            
                            box.addEventListener('keydown', (e) => {
                                if (e.key === 'Backspace' && !e.target.value && index > 0) {
                                    boxes[index - 1].focus();
                                }
                            });
                            
                            box.addEventListener('paste', (e) => {
                                e.preventDefault();
                                const paste = (e.clipboardData || window.clipboardData).getData('text');
                                const digits = paste.replace(/\D/g, '').split('').slice(0, 6);
                                digits.forEach((digit, i) => {
                                    if (boxes[i]) boxes[i].value = digit;
                                });
                                if (digits.length > 0) boxes[Math.min(digits.length - 1, 5)].focus();
                            });
                            
                            box.addEventListener('focus', () => {
                                box.style.borderColor = '#dc2626';
                                box.style.boxShadow = '0 0 0 3px rgba(220, 38, 38, 0.2)';
                            });
                            
                            box.addEventListener('blur', () => {
                                box.style.borderColor = '#e5e7eb';
                                box.style.boxShadow = 'none';
                            });
                        });
                    },
                    preConfirm: () => {
                        const boxes = document.querySelectorAll('.otp-box');
                        let otp = '';
                        boxes.forEach(box => otp += box.value);
                        
                        if (otp.length !== 6) {
                            Swal.showValidationMessage('Please enter complete 6-digit OTP');
                            return false;
                        }
                        
                        // Get trust device checkbox value
                        const trustCheckbox = document.getElementById('trustDevice');
                        const trustDevice = trustCheckbox ? (trustCheckbox.checked ? '1' : '0') : '0';
                        
                        return fetch('index.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'verify_otp=1&otp=' + otp + '&trust_device=' + trustDevice
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.status !== 'success') {
                                Swal.showValidationMessage(data.msg);
                            }
                            return data;
                        })
                        .catch(error => {
                            Swal.showValidationMessage('Connection error. Please try again.');
                        });
                    }
                }).then(result => {
                    if (result.value && result.value.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Welcome!',
                            text: 'Login successful. Redirecting...',
                            timer: 1500,
                            showConfirmButton: false,
                            confirmButtonColor: '#dc2626'
                        }).then(() => {
                            window.location.href = result.value.redirect;
                        });
                    }
                });
            });
        } else {
            // Regular error/info messages
            Swal.fire({
                icon: flash.type,
                title: flash.title,
                text: flash.text,
                confirmButtonColor: '#dc2626'
            });
        }
    });
    </script>

    </body>
    </html>
<?php
session_start();
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");


include("Connection/Config.php");
require_once 'Connection/notifications_helper.php';

// PHPMailer files
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot.php");
    exit();
}

$email = $_SESSION['reset_email'];

function sendMail($to, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'osaveproject2025@gmail.com';
        $mail->Password   = 'bimyaxskvwiytmxl';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('osaveproject2025@gmail.com', 'O!SAVE Password Reset');
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (isset($_POST['reset'])) {
        $otp = trim($_POST['otp']);
        $new_password = trim($_POST['new_password']);
        $confirm_password = trim($_POST['confirm_password']);

        if ($new_password !== $confirm_password) {
            $_SESSION['alert'] = [
                "icon" => "error",
                "title" => "Password Mismatch",
                "text" => "New password and confirm password do not match."
            ];
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND verification_code = ? AND is_verified = 1");
            $stmt->bind_param("ss", $email, $otp);
            $stmt->execute();
            $result = $stmt->get_result();
            $userRow = $result ? $result->fetch_assoc() : null;

            if ($userRow) {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);

                $update = $conn->prepare("UPDATE users SET password = ?, verification_code = NULL WHERE email = ?");
                $update->bind_param("ss", $hashed, $email);
                $update->execute();
                $update->close();

                create_notification($conn, (int) $userRow['id'], 'security', 'Your password was reset successfully.');

                unset($_SESSION['reset_email']);
                $_SESSION['alert'] = [
                    "icon" => "success",
                    "title" => "Password Reset Successful",
                    "text" => "You can now login with your new password.",
                    "redirect" => "index.php"
                ];
            } else {
                $_SESSION['alert'] = [
                    "icon" => "error",
                    "title" => "Invalid OTP",
                    "text" => "The verification code is incorrect."
                ];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>O!SAVE | Reset Password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                <h2>Reset Password</h2>
                <p>Enter your code and new password</p>
            </div>

            <!-- Form -->
            <form class="login-form" method="POST" id="resetForm">
                <!-- OTP CODE -->
                <div class="form-group">
                    <label for="otp">Verification Code</label>
                    <div class="input-group">
                        <input type="text" name="otp" id="otp" placeholder="Enter 6-digit code" required maxlength="6">
                        <i class="fas fa-key input-icon"></i>
                    </div>
                </div>

                <!-- NEW PASSWORD -->
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="input-group">
                        <input type="password" name="new_password" id="new_password" placeholder="Enter new password" required>
                        <i class="fas fa-lock input-icon"></i>
                        <button type="button" class="toggle-password" onclick="togglePass('new_password', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- CONFIRM PASSWORD -->
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="input-group">
                        <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm new password" required>
                        <i class="fas fa-lock input-icon"></i>
                        <button type="button" class="toggle-password" onclick="togglePass('confirm_password', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" name="reset" class="login-btn" id="resetBtn">
                    <span class="btn-text">Reset Password</span>
                    <span class="btn-loader"><i class="fas fa-circle-notch fa-spin"></i> Resetting...</span>
                    <i class="fas fa-check btn-icon"></i>
                </button>
            </form>

            <a href="index.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>

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
                        <i class="fas fa-key"></i>
                        <span>Secure Reset</span>
                    </div>
                    <div class="brand-feature">
                        <i class="fas fa-shield-alt"></i>
                        <span>Password Protection</span>
                    </div>
                    <div class="brand-feature">
                        <i class="fas fa-check-circle"></i>
                        <span>Quick Recovery</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePass(inputId, btn) {
    const input = document.getElementById(inputId);
    const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
    input.setAttribute('type', type);
    const icon = btn.querySelector('i');
    icon.classList.toggle('fa-eye');
    icon.classList.toggle('fa-eye-slash');
}

document.addEventListener('DOMContentLoaded', () => {
    // Form loading state
    document.getElementById('resetForm').addEventListener('submit', function() {
        const btn = document.getElementById('resetBtn');
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
});
</script>

<?php if (isset($_SESSION['alert'])): ?>
<script>
    const alertData = <?= json_encode($_SESSION['alert']) ?>;
    Swal.fire({
        icon: alertData.icon,
        title: alertData.title,
        text: alertData.text,
        confirmButtonColor: '#dc2626',
        timer: alertData.redirect ? 2500 : undefined,
        timerProgressBar: alertData.redirect ? true : false,
        didClose: () => {
            if(alertData.redirect){
                window.location.href = alertData.redirect;
            }
        }
    });
</script>
<?php unset($_SESSION['alert']); endif; ?>
</body>
</html>

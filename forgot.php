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

// Function to send email
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
    $email = trim($_POST['email']);

    // Check if email exists
    $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $otp = rand(100000, 999999);

        // Update verification code for password reset
        $update = $conn->prepare("UPDATE users SET verification_code = ? WHERE email = ?");
        $update->bind_param("ss", $otp, $email);
        $update->execute();
        $update->close();

        // O!SAVE-themed HTML email with logo
        $emailBody = "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
        <meta charset='UTF-8'>
        <title>O!SAVE Password Reset</title>
        <style>
            body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 0; }
            .email-container { background: #fff; max-width: 600px; margin: 50px auto; border-radius: 10px; overflow: hidden; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
            .header { background: #ff4d4d; color: #fff; padding: 20px; text-align: center; }
            .header img { width: 80px; margin-bottom: 10px; }
            .header h1 { margin: 0; font-size: 28px; }
            .content { padding: 30px 20px; text-align: center; }
            .otp-code { font-size: 36px; font-weight: bold; color: #ff4d4d; margin: 20px 0; letter-spacing: 5px; }
            .footer { background: #eee; padding: 15px; text-align: center; font-size: 12px; color: #555; }
            .btn { display: inline-block; padding: 10px 25px; background: #ff4d4d; color: #fff; text-decoration: none; border-radius: 8px; font-weight: bold; margin-top: 20px; }
        </style>
        </head>
        <body>
            <div class='email-container'>
                <div class='header'>
                    <h1>Password Reset</h1>
                </div>
                <div class='content'>
                    <p>Hello <b>{$user['full_name']}</b>,</p>
                    <p>Use the code below to reset your password:</p>
                    <div class='otp-code'>$otp</div>
                    <p>Enter this code on the password reset page to continue.</p>
                    <a href='#' class='btn'>Go to O!SAVE</a>
                </div>
                <div class='footer'>
                    &copy; ".date('Y')." O!SAVE. All rights reserved.
                </div>
            </div>
        </body>
        </html>
        ";

        // Send reset code
        sendMail($email, "Password Reset Request", $emailBody);

        create_notification($conn, (int) $user['id'], 'security', 'Password reset OTP sent to your email.');

        $_SESSION['reset_email'] = $email;

        $_SESSION['alert'] = [
            "icon" => "success",
            "title" => "Code Sent!",
            "text" => "A password reset code has been sent to your email."
        ];

        header("Location: reset.php");
        exit();
    } else {
        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Email Not Found",
            "text" => "This email is not registered."
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>O!SAVE | Forgot Password</title>
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
                <h2>Forgot Password?</h2>
                <p>Enter your email to receive a reset code</p>
            </div>

            <!-- Form -->
            <form class="login-form" method="POST" id="forgotForm">
                <!-- EMAIL -->
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-group">
                        <input type="email" name="email" id="email" placeholder="Enter your registered email" required autocomplete="email">
                        <i class="fas fa-envelope input-icon"></i>
                    </div>
                </div>

                <button type="submit" class="login-btn" id="forgotBtn">
                    <span class="btn-text">Send Reset Code</span>
                    <span class="btn-loader"><i class="fas fa-circle-notch fa-spin"></i> Sending...</span>
                    <i class="fas fa-paper-plane btn-icon"></i>
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
                        <i class="fas fa-lock"></i>
                        <span>Secure Recovery</span>
                    </div>
                    <div class="brand-feature">
                        <i class="fas fa-envelope"></i>
                        <span>Email Verification</span>
                    </div>
                    <div class="brand-feature">
                        <i class="fas fa-shield-alt"></i>
                        <span>Protected Access</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Form loading state
    document.getElementById('forgotForm').addEventListener('submit', function() {
        const btn = document.getElementById('forgotBtn');
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
    Swal.fire({
        icon: '<?= $_SESSION['alert']['icon'] ?>',
        title: '<?= $_SESSION['alert']['title'] ?>',
        text: <?= json_encode($_SESSION['alert']['text']) ?>,
        confirmButtonColor: '#dc2626'
    });
</script>
<?php unset($_SESSION['alert']); endif; ?>
</body>
</html>

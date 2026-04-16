<?php
session_start();

// Prevent browser caching
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies


include("Connection/Config.php");

// PHPMailer files
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

define('OTP_EXPIRATION', 600); // 10 minutes

if (!isset($_SESSION['pending_email'])) {
    header("Location: signup.php");
    exit();
}

$email = $_SESSION['pending_email'];

// Function to send HTML email (O!SAVE-themed)
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

        $mail->setFrom('osaveproject2025@gmail.com', 'O!SAVE Verification');
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

// Function to generate O!SAVE-themed HTML email
function generateOtpEmail($otp, $user_name, $title="Account Verification", $message="Please enter this code to activate your account.") {
    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
    <meta charset='UTF-8'>
    <title>$title</title>
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
                <h1>$title</h1>
            </div>
            <div class='content'>
                <p>Hello <b>$user_name</b>,</p>
                <p>$message</p>
                <div class='otp-code'>$otp</div>
                <a href='#' class='btn'>Go to O!SAVE</a>
            </div>
            <div class='footer'>
                &copy; ".date('Y')." O!SAVE. All rights reserved.
            </div>
        </div>
    </body>
    </html>
    ";
}

// Handle code verification
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['verify'])) {
    $code = trim($_POST['code']);

    $stmt = $conn->prepare("SELECT id, created_at, full_name FROM users WHERE email = ? AND verification_code = ? AND is_verified = 0");
    $stmt->bind_param("ss", $email, $code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $created = strtotime($user['created_at']);
        $now = time();

        if (($now - $created) <= OTP_EXPIRATION) {
            $update = $conn->prepare("UPDATE users SET is_verified = 1, verification_code = NULL WHERE email = ?");
            $update->bind_param("s", $email);
            $update->execute();

            unset($_SESSION['pending_email']);
            $_SESSION['alert'] = [
                "icon" => "success",
                "title" => "Verified!",
                "text" => "Your account has been verified. You can now login."
            ];
            header("Location: signup.php");
            exit();
        } else {
            $_SESSION['alert'] = [
                "icon" => "error",
                "title" => "Expired Code",
                "text" => "Your verification code has expired. Please signup again."
            ];
        }
    } else {
        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Invalid Code",
            "text" => "The verification code is incorrect."
        ];
    }
}

// Handle resend verification
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['resend'])) {
    $otp = rand(100000, 999999);

    $stmt = $conn->prepare("UPDATE users SET verification_code = ? WHERE email = ?");
    $stmt->bind_param("ss", $otp, $email);
    $stmt->execute();
    $stmt->close();

    sendMail(
        $email,
        "Resend Verification Code",
        generateOtpEmail($otp, "User", "Resend Verification Code", "Your new verification code is:")
    );

    $_SESSION['alert'] = [
        "icon" => "success",
        "title" => "Verification Code Sent",
        "text" => "A new verification code has been sent to your email."
    ];

    header("Location: verify.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify Account</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="Css/verify.css">
</head>
<body>
    <div class="verify-container">
        <h2>Account Verification</h2>
        <p>Enter the 6-digit code we sent to your email</p>

        <!-- Verification Form -->
        <form method="POST">
            <div class="form-group">
                <input type="text" name="code" placeholder="Enter verification code" required>
            </div>
            <button type="submit" name="verify" class="verify-btn">Verify</button>
        </form>

        <!-- Resend Code Form -->
        <form method="POST" style="margin-top:10px;">
            <button type="submit" name="resend" class="verify-btn" style="background:orange;">Resend Verification Code</button>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php if (isset($_SESSION['alert'])): ?>
    <script>
        Swal.fire({
            icon: '<?= $_SESSION['alert']['icon'] ?>',
            title: '<?= $_SESSION['alert']['title'] ?>',
            text: <?= json_encode($_SESSION['alert']['text']) ?>
        });
    </script>
    <?php unset($_SESSION['alert']); endif; ?>
</body>
</html>

<?php
session_start();
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");


include("Connection/Config.php");

// PHPMailer files
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $full_name = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // Check if email exists
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Signup Failed",
            "text" => "This email is already registered!",
        ];
        header("Location: signup.php");
        exit();
    }
    $check->close();

    // Generate OTP code
    $otp = rand(100000, 999999);

    // Insert new user with OTP and is_verified = 0
    $stmt = $conn->prepare("INSERT INTO users (full_name, email, password, verification_code, is_verified) VALUES (?, ?, ?, ?, 0)");
    $stmt->bind_param("ssss", $full_name, $email, $password, $otp);

    if ($stmt->execute()) {
        // O!SAVE-themed HTML email
        $emailBody = "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
        <meta charset='UTF-8'>
        <title>O!SAVE Account Verification</title>
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
                    <h1>Account Verification</h1>
                </div>
                <div class='content'>
                    <p>Hello <b>$full_name</b>,</p>
                    <p>Your verification code is:</p>
                    <div class='otp-code'>$otp</div>
                    <p>Please enter this code on the verification page to activate your account.</p>
                    <a href='#' class='btn'>Go to O!SAVE</a>
                </div>
                <div class='footer'>
                    &copy; ".date('Y')." O!SAVE. All rights reserved.
                </div>
            </div>
        </body>
        </html>
        ";

        // send verification email
        sendMail($email, "Verify Your O!SAVE Account", $emailBody);

        $_SESSION['pending_email'] = $email;

        $_SESSION['alert'] = [
            "icon" => "success",
            "title" => "Account Created!",
            "text"  => "We sent a verification code to your email. Please verify your account.",
        ];

        header("Location: verify.php");
        exit();
    } else {
        $_SESSION['alert'] = [
            "icon" => "error",
            "title" => "Signup Failed",
            "text" => "Something went wrong. Please try again!"
        ];
        header("Location: signup.php");
        exit();
    }

    $stmt->close();
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NextGen MMS | Sign Up</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Google Fonts & Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/signup.css?v=1.0">
    <link rel="icon" type="image/png" href="osicon.png">

</head>
<body>
    <div class="signup-container">
        <div class="signup-left">
            <img src="oslogo.png" alt="MerchFlow Logo" class="signup-logo">
            <div class="brand-title"></div>
            <div class="brand-subtitle">Sign up for an account</div>
            <form class="signup-form" method="POST" action="signup.php">
                <div class="form-group">
                    <i class="fas fa-user"></i>
                    <input type="text" name="fullname" placeholder="Full Name" required>
                </div>
                <div class="form-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" placeholder="Email Address" required>
                </div>
                <div class="form-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" placeholder="Password" required>
                </div>
                <button type="submit" class="signup-btn">Sign Up</button>
            </form>
            <div class="login-link">
                Already have an account? <a href="index.php">Login</a>
            </div>
        </div>
        <div class="signup-right">
            <img src="Osave.png" alt="Signup Graphic" class="signup-graphic">
        </div>
    </div>

    <!-- SweetAlert -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <?php if (isset($_SESSION['alert'])): ?>
    <script>
        Swal.fire({
            icon: '<?= $_SESSION['alert']['icon'] ?>',
            title: '<?= $_SESSION['alert']['title'] ?>',
            text: <?= isset($_SESSION['alert']['text']) ? json_encode($_SESSION['alert']['text']) : "undefined" ?>,
            html: <?= isset($_SESSION['alert']['html']) ? json_encode($_SESSION['alert']['html']) : "undefined" ?>,
            timer: <?= isset($_SESSION['alert']['timer']) ? $_SESSION['alert']['timer'] : "undefined" ?>,
            timerProgressBar: <?= isset($_SESSION['alert']['timerProgressBar']) ? 'true' : 'false' ?>,
            didOpen: <?= $_SESSION['alert']['didOpen'] ?? "undefined" ?>,
            willClose: <?= $_SESSION['alert']['willClose'] ?? "undefined" ?>,
        });
    </script>
    <?php unset($_SESSION['alert']); endif; ?>
</body>
</html>

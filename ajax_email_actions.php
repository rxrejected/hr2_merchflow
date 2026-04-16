<?php
session_start();
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Update last activity on AJAX requests to keep session alive
if (isset($_SESSION['user_id'])) {
    $_SESSION['LAST_ACTIVITY'] = time();
}

require 'Connection/Config.php'; // database connection
require 'Connection/notifications_helper.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

header('Content-Type: application/json');

// Helper function to send email
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

$action = $_POST['action'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// Get current email
$stmt = $conn->prepare("SELECT email FROM users WHERE id=?");
$stmt->bind_param("i",$user_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$current_email = $row['email'];

switch($action){
    case 'send_otp':
        $otp = rand(100000,999999);
        $_SESSION['otp'] = $otp;
        $_SESSION['otp_sent'] = true;

        $stmt = $conn->prepare("UPDATE users SET email_verification_code=? WHERE id=?");
        $stmt->bind_param("si",$otp,$user_id);
        $stmt->execute();
        $stmt->close();

        // O!SAVE-themed HTML email
        $emailBody = "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
        <meta charset='UTF-8'>
        <title>O!SAVE OTP</title>
        <style>
            body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 0; }
            .email-container { background: #fff; max-width: 600px; margin: 50px auto; border-radius: 10px; overflow: hidden; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
            .header { background: #ff4d4d; color: #fff; padding: 20px; text-align: center; }
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
                    <h1>O!SAVE Verification</h1>
                </div>
                <div class='content'>
                    <p>Hello,</p>
                    <p>Use the OTP below to verify your email address:</p>
                    <div class='otp-code'>$otp</div>
                    <p>This OTP will expire in 10 minutes.</p>
                    <a href='#' class='btn'>Go to O!SAVE</a>
                </div>
                <div class='footer'>
                    &copy; ".date('Y')." O!SAVE. All rights reserved.
                </div>
            </div>
        </body>
        </html>
        ";

        if(sendMail($current_email, "Your OTP Code", $emailBody)){
            create_notification($conn, $user_id, 'security', 'Email change OTP sent to your email.');
            echo json_encode(["status"=>"success","message"=>"OTP sent to $current_email"]);
        } else {
            echo json_encode(["status"=>"error","message"=>"Failed to send OTP. Check your mail settings."]);
        }
    break;

    case 'verify_otp':
        $enteredOTP = $_POST['otp'] ?? '';
        if($enteredOTP == ($_SESSION['otp'] ?? '')){
            $_SESSION['otp_verified'] = true;
            echo json_encode(["status"=>"success","message"=>"OTP verified! Now enter new email."]);
        } else {
            echo json_encode(["status"=>"error","message"=>"Invalid OTP!"]);
        }
    break;

    case 'confirm_email':
        if(!isset($_SESSION['otp_verified']) || $_SESSION['otp_verified']!==true){
            echo json_encode(["status"=>"error","message"=>"OTP not verified yet!"]);
            exit;
        }

        $newEmail = $_POST['new_email'] ?? '';
        if(!filter_var($newEmail, FILTER_VALIDATE_EMAIL)){
            echo json_encode(["status"=>"error","message"=>"Invalid email format!"]);
            exit;
        }

        $stmt = $conn->prepare("UPDATE users SET email=?, email_verification_code=NULL WHERE id=?");
        $stmt->bind_param("si",$newEmail,$user_id);
        $stmt->execute();
        $stmt->close();

        $_SESSION['email'] = $newEmail;
        unset($_SESSION['otp'], $_SESSION['otp_sent'], $_SESSION['otp_verified']);

        create_notification($conn, $user_id, 'settings', 'Your email address was updated.');
        echo json_encode(["status"=>"success","message"=>"Email successfully updated!"]);
    break;

    default:
        echo json_encode(["status"=>"error","message"=>"Invalid action"]);
}
?>

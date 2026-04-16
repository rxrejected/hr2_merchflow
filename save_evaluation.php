<?php
session_start();
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Auth check - only admin/Super Admin can save evaluations
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}
if (!in_array($_SESSION['role'] ?? '', ['admin', 'Super Admin', 'developer'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden: Admin access required']);
    exit();
}

// Update last activity on AJAX requests to keep session alive
$_SESSION['LAST_ACTIVITY'] = time();

require 'Connection/Config.php';
require_once 'Connection/notifications_helper.php';

$employee_id = $_POST['employee_id'];
$evaluator_id = $_SESSION['user_id'];
$customer_service = $_POST['customer_service'];
$cash_handling = $_POST['cash_handling'];
$inventory = $_POST['inventory'];
$teamwork = $_POST['teamwork'];
$attendance = $_POST['attendance'];
$comments = $_POST['comments'] ?? '';

$overall_rating = 'Good'; // Default overall rating
$evaluation_date = date('Y-m-d');
$stmt = $conn->prepare("INSERT INTO evaluations 
(employee_id, customer_service, cash_handling, inventory, teamwork, attendance, overall_rating, comments, evaluation_date) 
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("issssssss", $employee_id, $customer_service, $cash_handling, $inventory, $teamwork, $attendance, $overall_rating, $comments, $evaluation_date);

if($stmt->execute()){
    $_SESSION['success_evaluation'] = "Evaluation saved successfully!";
    create_notification($conn, (int) $employee_id, 'evaluation', 'A new performance evaluation was added to your account.');
} else {
    $_SESSION['success_evaluation'] = "Failed to save evaluation.";
}

$stmt->close();
$conn->close();

// Redirect back to employee list
header("Location: module1_sub1.php");
exit();
?>

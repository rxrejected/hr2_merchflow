<?php
session_start();
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Update last activity on AJAX requests to keep session alive
$_SESSION['LAST_ACTIVITY'] = time();

require 'Connection/Config.php';
$user_id = $_SESSION['user_id'] ?? 0;
$course_id = $_GET['course_id'] ?? 0;

$stmt = $conn->prepare("SELECT watched_percent, last_time FROM course_progress WHERE employee_id=? AND course_id=?");
$stmt->bind_param("ii", $user_id, $course_id);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();
echo json_encode(['percent'=>$data['watched_percent']??0, 'current_time'=>$data['last_time']??0]);
$stmt->close();

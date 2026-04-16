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
if(!isset($_SESSION['user_id'])) exit;

$employee_id = $_SESSION['user_id'];
$course_id = $_POST['course_id'];
$percent = $_POST['percent'];

$stmt = $conn->prepare("INSERT INTO course_progress (employee_id, course_id, watched_percent, last_watched_at)
  VALUES (?, ?, ?, NOW())
  ON DUPLICATE KEY UPDATE watched_percent=VALUES(watched_percent), last_watched_at=NOW()");
$stmt->bind_param("iii",$employee_id,$course_id,$percent);
$stmt->execute();
$stmt->close();

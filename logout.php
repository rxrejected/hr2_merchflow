<?php
session_start();
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Check if user is logged in
if(!isset($_SESSION['user_id'])){
    header("Location: index.php");
    exit();
}include(__DIR__ . "/Connection/Config.php");

if (isset($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
    $conn->query("DELETE FROM user_sessions WHERE user_id = $user_id");
}

session_unset();
session_destroy();

header("Location: index.php");
exit();
?>

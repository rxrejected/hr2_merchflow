<?php
session_start();
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// ===== AUTH & ADMIN CHECK =====
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "unauthorized";
    exit();
}
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'Super Admin', 'developer'])) {
    http_response_code(403);
    echo "forbidden";
    exit();
}

// Update last activity on AJAX requests to keep session alive
$_SESSION['LAST_ACTIVITY'] = time();

require 'Connection/Config.php';

if(isset($_POST['user_id']) && isset($_POST['role'])){
    $userId = intval($_POST['user_id']);
    $newRole = $_POST['role'];

    // Validate allowed roles
    $allowedRoles = ['employee', 'admin', 'Super Admin'];
    if (!in_array($newRole, $allowedRoles)) {
        echo "invalid_role";
        exit();
    }

    // Prevent changing own role
    if ($userId === (int)$_SESSION['user_id']) {
        echo "cannot_change_own_role";
        exit();
    }

    $stmt = $conn->prepare("UPDATE users SET role=? WHERE id=?");
    $stmt->bind_param("si", $newRole, $userId);

    if($stmt->execute()){
        echo "success";
    } else {
        echo "error";
    }
    $stmt->close();
}
$conn->close();
?>

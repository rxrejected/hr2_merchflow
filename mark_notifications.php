<?php
session_start();
header('Content-Type: application/json');

require 'Connection/Config.php';

$user_id = $_SESSION['user_id'] ?? 0;
if ($user_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'mark_all') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['status' => 'success', 'message' => 'All notifications marked as read']);
    exit;
}

if ($action === 'mark_one') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        if ($stmt) {
            $stmt->bind_param("ii", $id, $user_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    echo json_encode(['status' => 'success', 'message' => 'Notification marked as read']);
    exit;
}

if ($action === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        if ($stmt) {
            $stmt->bind_param("ii", $id, $user_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    echo json_encode(['status' => 'success', 'message' => 'Notification deleted']);
    exit;
}

if ($action === 'delete_all') {
    $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['status' => 'success', 'message' => 'All notifications deleted']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);

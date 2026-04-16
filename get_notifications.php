<?php
session_start();
header('Content-Type: application/json');
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Update last activity on AJAX requests to keep session alive
$_SESSION['LAST_ACTIVITY'] = time();

require 'Connection/Config.php';

$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? '';
$limit = isset($_GET['limit']) ? max(1, min(50, (int) $_GET['limit'])) : 15;
$since_id = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;

$items = [];
$unread = 0;
$latest_id = 0;

if ($user_id > 0) {
    // Count unread notifications for this user
    $countStmt = $conn->prepare("SELECT COUNT(*) AS unread_count FROM notifications WHERE user_id = ? AND is_read = 0");
    if ($countStmt) {
        $countStmt->bind_param("i", $user_id);
        $countStmt->execute();
        $countRes = $countStmt->get_result()->fetch_assoc();
        $unread = (int) ($countRes['unread_count'] ?? 0);
        $countStmt->close();
    }

    // Get notifications (user-specific)
    // If since_id is provided, only get newer notifications (for real-time updates)
    if ($since_id > 0) {
        $stmt = $conn->prepare("SELECT id, type, message, is_read, created_at FROM notifications WHERE user_id = ? AND id > ? ORDER BY created_at DESC LIMIT ?");
        if ($stmt) {
            $stmt->bind_param("iii", $user_id, $since_id, $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => $row['type'],
                    'message' => $row['message'],
                    'is_read' => (int) $row['is_read'],
                    'created_at' => $row['created_at'],
                    'is_new' => true
                ];
                if ((int)$row['id'] > $latest_id) {
                    $latest_id = (int)$row['id'];
                }
            }
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("SELECT id, type, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        if ($stmt) {
            $stmt->bind_param("ii", $user_id, $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $items[] = [
                    'id' => (int) $row['id'],
                    'type' => $row['type'],
                    'message' => $row['message'],
                    'is_read' => (int) $row['is_read'],
                    'created_at' => $row['created_at'],
                    'is_new' => false
                ];
                if ((int)$row['id'] > $latest_id) {
                    $latest_id = (int)$row['id'];
                }
            }
            $stmt->close();
        }
    }
}

echo json_encode([
    'unread' => $unread,
    'items' => $items,
    'latest_id' => $latest_id,
    'timestamp' => time()
]);
?>

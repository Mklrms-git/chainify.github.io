<?php
require_once '../config/config.php';
requireLogin();

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get':
        // Get all notifications for current user
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            $notifications[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'message' => $row['message'],
                'type' => $row['notification_type'],
                'is_read' => (bool)$row['is_read'],
                'created_at' => $row['created_at'],
                'reference_type' => $row['reference_type'],
                'reference_id' => $row['reference_id']
            ];
        }
        $stmt->close();
        closeDBConnection($conn);
        
        echo json_encode(['success' => true, 'notifications' => $notifications]);
        break;
        
    case 'count':
        // Get unread notification count
        $count = getUnreadNotificationCount();
        echo json_encode(['success' => true, 'count' => $count]);
        break;
        
    case 'mark_read':
        // Mark a specific notification as read
        $notification_id = $_POST['notification_id'] ?? 0;
        if ($notification_id) {
            $conn = getDBConnection();
            $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
            $stmt->bind_param("ii", $notification_id, $_SESSION['user_id']);
            $result = $stmt->execute();
            $stmt->close();
            closeDBConnection($conn);
            
            echo json_encode(['success' => $result]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid notification ID']);
        }
        break;
        
    case 'mark_all_read':
        // Mark all notifications as read for current user
        $conn = getDBConnection();
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $result = $stmt->execute();
        $stmt->close();
        closeDBConnection($conn);
        
        echo json_encode(['success' => $result]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
?>


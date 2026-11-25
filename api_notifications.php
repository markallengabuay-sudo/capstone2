<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

require_once 'includes/db.php';
require_once 'includes/notifications.php';

$branchNumber = isset($_SESSION['branch_number']) ? (int) $_SESSION['branch_number'] : null;
ensureRoleNotifications($pdo, (int) $_SESSION['user_id'], (string) $_SESSION['role'], $branchNumber);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'mark_all_read') {
        $stmt = $pdo->prepare('UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$_SESSION['user_id']]);
    } elseif ($action === 'mark_read') {
        $stmt = $pdo->prepare('UPDATE user_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) ($_POST['id'] ?? 0), $_SESSION['user_id']]);
    }
}

$stmt = $pdo->prepare('SELECT id, title, message, link_url, is_read, created_at FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20');
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM user_notifications WHERE user_id = ? AND is_read = 0');
$stmt->execute([$_SESSION['user_id']]);

echo json_encode(['success' => true, 'unread_count' => (int) $stmt->fetchColumn(), 'notifications' => $notifications]);

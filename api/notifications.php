<?php
ini_set('display_errors', 0);
ob_start();
session_start();
ob_clean();

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

function respond($payload) {
    echo json_encode($payload);
    exit;
}

function ensureNotificationsTable($con) {
    $sql = "CREATE TABLE IF NOT EXISTS exam_notifications (
        notification_id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL,
        body TEXT NOT NULL,
        priority ENUM('Normal', 'Important', 'Urgent') NOT NULL DEFAULT 'Normal',
        publish_date DATE NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_active_publish (is_active, publish_date),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if (!$con->query($sql)) {
        respond(['success' => false, 'message' => 'Failed to initialize notifications table']);
    }
}

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    respond(['success' => false, 'message' => 'Not logged in']);
}

$role = $_SESSION['role'] ?? '';
$isAdmin = ($role === 'admin' || $role === 'exam_cell');

$con = getConnection();
ensureNotificationsTable($con);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$act = $_GET['action'] ?? ($method === 'POST' ? (json_decode(file_get_contents('php://input'), true)['action'] ?? '') : '');

if ($act === 'list') {
    $includeInactive = $isAdmin && (($_GET['include_inactive'] ?? '0') === '1');

    if ($includeInactive) {
        $sql = "SELECT notification_id, title, body, priority, publish_date, is_active, created_at, updated_at
                FROM exam_notifications
                ORDER BY is_active DESC, COALESCE(publish_date, DATE(created_at)) DESC, notification_id DESC
                LIMIT 200";
        $result = $con->query($sql);
    } else {
        $sql = "SELECT notification_id, title, body, priority, publish_date, is_active, created_at, updated_at
                FROM exam_notifications
                WHERE is_active = 1 AND (publish_date IS NULL OR publish_date <= CURDATE())
                ORDER BY COALESCE(publish_date, DATE(created_at)) DESC, notification_id DESC
                LIMIT 100";
        $result = $con->query($sql);
    }

    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    respond(['success' => true, 'data' => $rows]);
}

if (!$isAdmin) {
    respond(['success' => false, 'message' => 'Admin access required']);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if ($act === 'create') {
    $title = trim((string)($input['title'] ?? ''));
    $body = trim((string)($input['body'] ?? ''));
    $priority = trim((string)($input['priority'] ?? 'Normal'));
    $publishDate = trim((string)($input['publish_date'] ?? ''));

    if ($title === '' || $body === '') {
        respond(['success' => false, 'message' => 'Title and body are required']);
    }

    if (!in_array($priority, ['Normal', 'Important', 'Urgent'], true)) {
        $priority = 'Normal';
    }

    $publishDateValue = $publishDate !== '' ? $publishDate : null;
    $createdBy = (int)($_SESSION['user_id'] ?? 0);

    $stmt = $con->prepare(
        "INSERT INTO exam_notifications (title, body, priority, publish_date, is_active, created_by)
         VALUES (?, ?, ?, ?, 1, ?)"
    );
    if (!$stmt) {
        respond(['success' => false, 'message' => 'Unable to prepare create query']);
    }

    $stmt->bind_param('ssssi', $title, $body, $priority, $publishDateValue, $createdBy);
    $ok = $stmt->execute();
    $stmt->close();

    respond([
        'success' => (bool)$ok,
        'message' => $ok ? 'Notification created' : 'Failed to create notification'
    ]);
}

if ($act === 'update') {
    $id = (int)($input['notification_id'] ?? 0);
    $title = trim((string)($input['title'] ?? ''));
    $body = trim((string)($input['body'] ?? ''));
    $priority = trim((string)($input['priority'] ?? 'Normal'));
    $publishDate = trim((string)($input['publish_date'] ?? ''));
    $isActive = isset($input['is_active']) ? (int)!!$input['is_active'] : 1;

    if ($id <= 0 || $title === '' || $body === '') {
        respond(['success' => false, 'message' => 'notification_id, title and body are required']);
    }

    if (!in_array($priority, ['Normal', 'Important', 'Urgent'], true)) {
        $priority = 'Normal';
    }

    $publishDateValue = $publishDate !== '' ? $publishDate : null;

    $stmt = $con->prepare(
        "UPDATE exam_notifications
         SET title = ?, body = ?, priority = ?, publish_date = ?, is_active = ?
         WHERE notification_id = ?"
    );
    if (!$stmt) {
        respond(['success' => false, 'message' => 'Unable to prepare update query']);
    }

    $stmt->bind_param('ssssii', $title, $body, $priority, $publishDateValue, $isActive, $id);
    $ok = $stmt->execute();
    $stmt->close();

    respond([
        'success' => (bool)$ok,
        'message' => $ok ? 'Notification updated' : 'Failed to update notification'
    ]);
}

if ($act === 'delete') {
    $id = (int)($input['notification_id'] ?? 0);
    if ($id <= 0) {
        respond(['success' => false, 'message' => 'notification_id is required']);
    }

    $stmt = $con->prepare("DELETE FROM exam_notifications WHERE notification_id = ?");
    if (!$stmt) {
        respond(['success' => false, 'message' => 'Unable to prepare delete query']);
    }

    $stmt->bind_param('i', $id);
    $ok = $stmt->execute();
    $stmt->close();

    respond([
        'success' => (bool)$ok,
        'message' => $ok ? 'Notification deleted' : 'Failed to delete notification'
    ]);
}

respond(['success' => false, 'message' => 'Invalid action']);

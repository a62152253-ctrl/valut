<?php
session_start();
include_once '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Database unavailable']);
    exit;
}

$user_id  = (int)$_SESSION['user_id'];
$uuid     = sanitize($_POST['uuid'] ?? '');
$type     = sanitize($_POST['type'] ?? 'login');
$title    = sanitize($_POST['title'] ?? '');
$username = sanitize($_POST['username'] ?? '');
$password = sanitize($_POST['password'] ?? '');
$url      = sanitize($_POST['url'] ?? '');
$notes    = sanitize($_POST['notes'] ?? '');
$folder_id = isset($_POST['folder_id']) && $_POST['folder_id'] !== '' ? (int)$_POST['folder_id'] : null;

if (!$uuid || !$title) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'UUID and title are required']);
    exit;
}

$valid_types = ['login', 'note', 'card', 'identity'];
if (!in_array($type, $valid_types, true)) {
    $type = 'login';
}

// Check ownership with prepared statement
$check_query = "SELECT id FROM vault_entries WHERE uuid = ? AND user_id = ? LIMIT 1";
$check_stmt = $conn->prepare($check_query);
if (!$check_stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

$check_stmt->bind_param('si', $uuid, $user_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result()->fetch_assoc();
$check_stmt->close();

if (!$check_result) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Item not found or not owned']);
    logSecurityEvent('update_item_unauthorized', $user_id, "UUID: {$uuid}");
    exit;
}

$encrypted_data = json_encode([
    'title'    => $title,
    'username' => $username,
    'password' => $password,
    'url'      => $url,
    'notes'    => $notes,
], JSON_UNESCAPED_UNICODE);

$iv    = base64_encode(random_bytes(12));
$query = "UPDATE vault_entries SET type = ?, encrypted_data = ?, iv = ?, folder_id = ?, updated_at = NOW() WHERE uuid = ? AND user_id = ?";
$stmt  = $conn->prepare($query);

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Prepare failed']);
    exit;
}

$stmt->bind_param('sssisi', $type, $encrypted_data, $iv, $folder_id, $uuid, $user_id);

if ($stmt->execute() && $stmt->affected_rows >= 0) {
    logSecurityEvent('update_item_success', $user_id, "Type: {$type}, UUID: {$uuid}");
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Item updated', 'affected' => $stmt->affected_rows]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Update failed: ' . $stmt->error]);
    logSecurityEvent('update_item_error', $user_id, $stmt->error);
}
$stmt->close();
?>

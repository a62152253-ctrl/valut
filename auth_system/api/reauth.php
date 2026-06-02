<?php
/**
 * Re-authentication endpoint
 * Verifies the user's password for sensitive operations
 * Accepts both JSON and form-encoded POST data
 */
session_start();
header('Content-Type: application/json');

require_once '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Parse input — handle both JSON and form-encoded POST
$input = [];
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';

if (strpos($content_type, 'application/json') !== false) {
    // JSON request body
    $raw_input = file_get_contents('php://input');
    if ($raw_input) {
        $input = json_decode($raw_input, true) ?? [];
    }
} else {
    // Form-encoded
    $input = $_POST;
}

$password = isset($input['password']) ? trim((string)$input['password']) : '';

if (empty($password)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Password required']);
    exit;
}

// Get current password hash
$stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}

$stmt->bind_param('i', $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row && password_verify($password, $row['password'])) {
    // Password is correct — set re-auth flag in session
    $_SESSION['reauth_time'] = time();
    http_response_code(200);
    echo json_encode(['ok' => true]);
} else {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Incorrect password']);
}

$conn->close();

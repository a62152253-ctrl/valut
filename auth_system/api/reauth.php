<?php
/**
 * api/reauth.php — Real-time password verification for sensitive operations.
 * Used before enabling/disabling 2FA, changing password, etc.
 */
session_start();
include '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Not authenticated']));
}

$user_id = (int)$_SESSION['user_id'];
$method  = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

// Rate limit re-auth attempts
vaultRateLimit('reauth_' . $user_id, 10, 300);  // 10 attempts per 5 min

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (empty($input['password'])) {
    http_response_code(400);
    die(json_encode(['error' => 'Password required']));
}

$password = $input['password'];

// Fetch user's password hash
$stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
if (!$stmt) {
    http_response_code(500);
    die(json_encode(['error' => 'Database error']));
}
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

if (!$result || $result->num_rows === 0) {
    http_response_code(401);
    die(json_encode(['error' => 'User not found']));
}

$row = $result->fetch_assoc();

// Verify password
if (password_verify($password, $row['password'])) {
    // Generate short-lived re-auth token (valid for 10 minutes)
    $token = bin2hex(random_bytes(32));
    $_SESSION['reauth_token'] = $token;
    $_SESSION['reauth_time']  = time();
    $_SESSION['reauth_ttl']   = 600;  // 10 minutes

    logSecurityEvent('user_reauth_success', $user_id);
    http_response_code(200);
    die(json_encode(['ok' => true, 'token' => $token]));
} else {
    logSecurityEvent('user_reauth_failed', $user_id, 'Incorrect password provided');
    http_response_code(401);
    die(json_encode(['error' => 'Incorrect password']));
}

<?php
/**
 * API v1 - Email Verification Endpoint
 * POST /api/v1/auth/verify-email
 */

header('Content-Type: application/json');
header('X-API-Version: v1');

session_start();
include '../../includes/db.php';
include '../../includes/EmailManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Method not allowed']));
}

$input = json_decode(file_get_contents('php://input'), true);
$token = $input['token'] ?? null;

if (!$token) {
    http_response_code(400);
    die(json_encode(['error' => 'Verification token required']));
}

// Find verification record
$stmt = $conn->prepare("SELECT user_id, expires_at FROM email_verification WHERE token = ? AND verified_at IS NULL");
if (!$stmt) {
    http_response_code(500);
    die(json_encode(['error' => 'Database error']));
}

$stmt->bind_param('s', $token);
$stmt->execute();
$verification = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$verification) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid or already verified token']));
}

if (strtotime($verification['expires_at']) < time()) {
    http_response_code(400);
    die(json_encode(['error' => 'Verification token expired']));
}

// Mark email as verified
$user_id = $verification['user_id'];
$stmt = $conn->prepare("UPDATE email_verification SET verified_at = NOW() WHERE token = ?");
$stmt->bind_param('s', $token);
$stmt->execute();
$stmt->close();

// Update user table
$stmt = $conn->prepare("UPDATE users SET email_verified = 1 WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->close();

logSecurityEvent('email_verified', $user_id, 'Email verified successfully');

http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Email verified successfully',
    'user_id' => $user_id
]);
?>

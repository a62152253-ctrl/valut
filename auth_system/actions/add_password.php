<?php
/**
 * add_password.php — proxy that validates the session and returns an error.
 * All vault writes must go through api/vault.php with client-side AES-256-GCM
 * encryption. Direct plaintext storage is NOT permitted.
 */
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

// Validate CSRF
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token mismatch']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$type    = $_POST['type'] ?? 'login';
$title   = trim($_POST['title'] ?? '');

if (!$title) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Title is required']);
    exit;
}

$valid_types = ['login', 'note', 'card', 'identity'];
if (!in_array($type, $valid_types, true)) {
    $type = 'login';
}

// Incoming data is already encrypted by the client (AES-256-GCM via CryptoEngine).
// We only accept pre-encrypted blobs — never plaintext.
$encrypted_data = $_POST['encrypted_data'] ?? '';
$iv             = $_POST['iv']             ?? '';

if (empty($encrypted_data) || empty($iv)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Missing encrypted_data or iv. Encrypt client-side before submitting.',
    ]);
    exit;
}

// Generate UUIDv4
$uuid_bytes    = random_bytes(16);
$uuid_bytes[6] = chr(ord($uuid_bytes[6]) & 0x0f | 0x40);
$uuid_bytes[8] = chr(ord($uuid_bytes[8]) & 0x3f | 0x80);
$uuid          = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($uuid_bytes), 4));

$conn = getDbConnection();
if (!$conn) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Database unavailable']);
    exit;
}

$stmt = $conn->prepare(
    "INSERT INTO vault_entries (user_id, uuid, type, encrypted_data, iv, favorite)
     VALUES (?, ?, ?, ?, ?, 0)"
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Prepare failed']);
    exit;
}

$stmt->bind_param('issss', $user_id, $uuid, $type, $encrypted_data, $iv);

if ($stmt->execute()) {
    logSecurityEvent('add_entry_success', $user_id, "Type: {$type}, UUID: {$uuid}");
    http_response_code(201);
    echo json_encode(['success' => true, 'uuid' => $uuid]);
} else {
    http_response_code(500);
    logSecurityEvent('add_entry_error', $user_id, $stmt->error);
    echo json_encode(['success' => false, 'error' => 'Insert failed']);
}
$stmt->close();

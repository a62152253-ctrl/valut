<?php
session_start();
include '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$uuid = sanitize($_POST['uuid'] ?? '');

if (!$uuid) {
    echo json_encode(['success' => false, 'error' => 'UUID required']);
    exit;
}

$query = "UPDATE vault_entries SET favorite = NOT favorite WHERE user_id = ? AND uuid = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('is', $user_id, $uuid);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Favorite updated']);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}
$stmt->close();
?>

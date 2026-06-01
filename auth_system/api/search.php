<?php
session_start();
include '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$query = sanitize($_GET['q'] ?? '');

if (strlen($query) < 2) {
    echo json_encode(['success' => false, 'error' => 'Query too short']);
    exit;
}

// Vault entries are AES-256-GCM encrypted; only type (plaintext) can be searched server-side.
// Title search must be done client-side after decryption.
$valid_types = ['login', 'note', 'card', 'identity'];
$type_filter = in_array($query, $valid_types, true) ? $query : null;

if ($type_filter) {
    $search_query = "SELECT uuid, type, encrypted_data, iv, created_at FROM vault_entries
                     WHERE user_id = ? AND type = ? LIMIT 20";
    $stmt = $conn->prepare($search_query);
    $stmt->bind_param('is', $user_id, $type_filter);
} else {
    $search_query = "SELECT uuid, type, encrypted_data, iv, created_at FROM vault_entries
                     WHERE user_id = ? LIMIT 20";
    $stmt = $conn->prepare($search_query);
    $stmt->bind_param('i', $user_id);
}
$stmt->execute();
$entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Return raw encrypted entries for client-side decryption and filtering
$processed = array_map(function($e) {
    return [
        'uuid'          => $e['uuid'],
        'type'          => $e['type'],
        'encrypted_data'=> $e['encrypted_data'],
        'iv'            => $e['iv'],
        'created_at'    => $e['created_at'],
    ];
}, $entries);

echo json_encode([
    'success' => true,
    'count' => count($processed),
    'results' => $processed
]);
?>

<?php
session_start();
include '../includes/db.php';
include '../includes/vault_auth.php';

vaultRequireAuth();
$userId = (int)$_SESSION['user_id'];
$body   = vaultInput();
$action = $body['action'] ?? $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':
        $stmt = $conn->prepare("SELECT id, name, color FROM vault_folders WHERE user_id = ? ORDER BY name");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        vaultJson(['folders' => $result]);
        break;

    case 'create':
        vaultRateLimit('folder_create_' . $userId, 50, 3600);
        $name  = substr(strip_tags($body['name'] ?? ''), 0, 100);
        $color = preg_match('/^#[0-9a-f]{6}$/i', $body['color'] ?? '') ? $body['color'] : '#5865f2';
        if (empty($name)) vaultJson(['error' => 'Name required'], 400);

        $stmt = $conn->prepare("INSERT INTO vault_folders (user_id, name, color) VALUES (?, ?, ?)");
        $stmt->bind_param('iss', $userId, $name, $color);
        if (!$stmt->execute()) vaultJson(['error' => $conn->error], 500);
        $folderId = $conn->insert_id;
        $stmt->close();
        vaultJson(['ok' => true, 'id' => $folderId]);
        break;

    case 'update':
        $id    = (int)($body['id'] ?? 0);
        $name  = substr(strip_tags($body['name'] ?? ''), 0, 100);
        $color = preg_match('/^#[0-9a-f]{6}$/i', $body['color'] ?? '') ? $body['color'] : '#5865f2';
        if (empty($name)) vaultJson(['error' => 'Name required'], 400);
        $stmt  = $conn->prepare("UPDATE vault_folders SET name=?, color=? WHERE id=? AND user_id=?");
        if (!$stmt) vaultJson(['error' => $conn->error], 500);
        $stmt->bind_param('ssii', $name, $color, $id, $userId);
        if (!$stmt->execute()) vaultJson(['error' => $conn->error], 500);
        $stmt->close();
        vaultJson(['ok' => true]);
        break;

    case 'delete':
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) vaultJson(['error' => 'Invalid folder ID'], 400);
        
        // Null out entries in that folder first
        $nullStmt = $conn->prepare("UPDATE vault_entries SET folder_id=NULL WHERE folder_id=? AND user_id=?");
        if (!$nullStmt) vaultJson(['error' => $conn->error], 500);
        $nullStmt->bind_param('ii', $id, $userId);
        if (!$nullStmt->execute()) vaultJson(['error' => $conn->error], 500);
        $nullStmt->close();
        
        // Delete the folder
        $delStmt = $conn->prepare("DELETE FROM vault_folders WHERE id=? AND user_id=?");
        if (!$delStmt) vaultJson(['error' => $conn->error], 500);
        $delStmt->bind_param('ii', $id, $userId);
        if (!$delStmt->execute()) vaultJson(['error' => $conn->error], 500);
        $delStmt->close();
        vaultJson(['ok' => true]);
        break;

    default:
        vaultJson(['error' => 'Unknown action'], 400);
}

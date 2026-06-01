<?php
session_start();
include '../includes/db.php';
include '../includes/vault_auth.php';

vaultRequireAuth();
$userId = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

vaultRateLimit('vault_api_' . $userId, 300, 60);

// ── GET: list all entries ────────────────────────────────────────────────────
if ($method === 'GET') {
    $stmt = $conn->prepare(
        "SELECT uuid, folder_id, type, encrypted_data, iv, favorite,
                created_at, updated_at
         FROM vault_entries WHERE user_id = ? ORDER BY updated_at DESC"
    );
    if (!$stmt) vaultJson(['error' => $conn->error], 500);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    vaultJson(['entries' => $result]);
}

if ($method !== 'POST') vaultJson(['error' => 'Method not allowed'], 405);

$body   = vaultInput();
$action = $body['action'] ?? 'create';

// SECURITY: Whitelist allowed actions only
$allowedActions = ['create', 'update', 'delete', 'history', 'export'];
if (!in_array($action, $allowedActions)) {
    logSecurityEvent('invalid_vault_action', $userId, "Attempted action: $action");
    vaultJson(['error' => 'Invalid action'], 400);
}

// ── CREATE ───────────────────────────────────────────────────────────────────
if ($action === 'create') {
    vaultRateLimit('vault_create_' . $userId, 100, 3600);

    $bytes = random_bytes(16);
    $h     = bin2hex($bytes);
    // Proper UUID v4 format: 8-4-4-4-12 (lowercase hex)
    $uuid  = substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);

    $type      = in_array($body['type']??'',['login','note','card','identity']) ? $body['type'] : 'login';
    $folderId  = isset($body['folder_id']) && $body['folder_id'] !== '' ? (int)$body['folder_id'] : null;
    $encData   = $body['encrypted_data'] ?? '';
    $iv        = $body['iv'] ?? '';
    $favorite  = empty($body['favorite']) ? 0 : 1;

    if (empty($encData) || empty($iv)) vaultJson(['error' => 'Missing encrypted data'], 400);
    if (!validateEncryptedDataLength($encData)) vaultJson(['error' => 'Encrypted data exceeds max size (5MB)'], 413);

    $stmt = $conn->prepare(
        "INSERT INTO vault_entries (user_id, uuid, folder_id, type, encrypted_data, iv, favorite)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) vaultJson(['error' => $conn->error], 500);
    $stmt->bind_param('isisssi', $userId, $uuid, $folderId, $type, $encData, $iv, $favorite);
    if (!$stmt->execute()) vaultJson(['error' => $conn->error], 500);
    $stmt->close();
    vaultJson(['ok' => true, 'uuid' => $uuid]);
}

// ── UPDATE ───────────────────────────────────────────────────────────────────
if ($action === 'update') {
    $uuid     = $body['uuid'] ?? '';
    $encData  = $body['encrypted_data'] ?? '';
    $iv       = $body['iv'] ?? '';
    $folderId = isset($body['folder_id']) && $body['folder_id'] !== '' ? (int)$body['folder_id'] : null;
    $favorite = empty($body['favorite']) ? 0 : 1;
    $type     = in_array($body['type']??'',['login','note','card','identity']) ? $body['type'] : null;

    if (empty($uuid) || empty($encData) || empty($iv)) vaultJson(['error' => 'Missing data'], 400);
    if (!validateEncryptedDataLength($encData)) vaultJson(['error' => 'Encrypted data exceeds max size (5MB)'], 413);

    // Save to history first
    $histStmt = $conn->prepare(
        "INSERT INTO vault_history (entry_uuid, user_id, encrypted_data, iv)
         SELECT uuid, user_id, encrypted_data, iv FROM vault_entries
         WHERE uuid = ? AND user_id = ?"
    );
    if (!$histStmt) vaultJson(['error' => $conn->error], 500);
    $histStmt->bind_param('si', $uuid, $userId);
    $histStmt->execute();
    $histStmt->close();

    // Prune old history (keep last 10 versions)
    $pruneStmt = $conn->prepare(
        "DELETE FROM vault_history WHERE entry_uuid = ? AND id NOT IN (
           SELECT id FROM (
             SELECT id FROM vault_history WHERE entry_uuid = ? ORDER BY changed_at DESC LIMIT 10
           ) sub
         )"
    );
    if (!$pruneStmt) vaultJson(['error' => $conn->error], 500);
    $pruneStmt->bind_param('ss', $uuid, $uuid);
    $pruneStmt->execute();
    $pruneStmt->close();

    if ($type) {
        $stmt = $conn->prepare(
            "UPDATE vault_entries
             SET encrypted_data = ?, iv = ?, folder_id = ?, favorite = ?, type = ?
             WHERE uuid = ? AND user_id = ?"
        );
        if (!$stmt) vaultJson(['error' => $conn->error], 500);
        $stmt->bind_param('ssiissi', $encData, $iv, $folderId, $favorite, $type, $uuid, $userId);
    } else {
        $stmt = $conn->prepare(
            "UPDATE vault_entries
             SET encrypted_data = ?, iv = ?, folder_id = ?, favorite = ?
             WHERE uuid = ? AND user_id = ?"
        );
        if (!$stmt) vaultJson(['error' => $conn->error], 500);
        $stmt->bind_param('ssiisi', $encData, $iv, $folderId, $favorite, $uuid, $userId);
    }
    if (!$stmt->execute()) vaultJson(['error' => $conn->error], 500);
    $stmt->close();
    vaultJson(['ok' => true]);
}

// ── DELETE ───────────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $uuid = $body['uuid'] ?? '';
    if (empty($uuid)) vaultJson(['error' => 'UUID required'], 400);

    $del = $conn->prepare("DELETE FROM vault_entries WHERE uuid = ? AND user_id = ?");
    if (!$del) vaultJson(['error' => $conn->error], 500);
    $del->bind_param('si', $uuid, $userId);
    $del->execute();
    $del->close();

    $delHist = $conn->prepare("DELETE FROM vault_history WHERE entry_uuid = ? AND user_id = ?");
    if (!$delHist) vaultJson(['error' => $conn->error], 500);
    $delHist->bind_param('si', $uuid, $userId);
    $delHist->execute();
    $delHist->close();
    vaultJson(['ok' => true]);
}

// ── HISTORY ──────────────────────────────────────────────────────────────────
if ($action === 'history') {
    $uuid = $body['uuid'] ?? '';
    $stmt = $conn->prepare(
        "SELECT encrypted_data, iv, changed_at FROM vault_history
         WHERE entry_uuid = ? AND user_id = ? ORDER BY changed_at DESC LIMIT 10"
    );
    if (!$stmt) vaultJson(['error' => $conn->error], 500);
    $stmt->bind_param('si', $uuid, $userId);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    vaultJson(['history' => $history]);
}

// ── EXPORT (encrypted JSON blob) ─────────────────────────────────────────────
if ($action === 'export') {
    $stmt = $conn->prepare(
        "SELECT uuid, folder_id, type, encrypted_data, iv, favorite, created_at, updated_at
         FROM vault_entries WHERE user_id = ? ORDER BY type, updated_at DESC"
    );
    if (!$stmt) vaultJson(['error' => $conn->error], 500);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $folderStmt = $conn->prepare("SELECT id, name, color FROM vault_folders WHERE user_id = ?");
    if (!$folderStmt) vaultJson(['error' => $conn->error], 500);
    $folderStmt->bind_param('i', $userId);
    $folderStmt->execute();
    $folders = $folderStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $folderStmt->close();

    vaultJson([
        'export_version' => 1,
        'exported_at'    => date('c'),
        'entries'        => $entries,
        'folders'        => $folders
    ]);
}

vaultJson(['error' => 'Unknown action'], 400);

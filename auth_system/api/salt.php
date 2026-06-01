<?php
session_start();
include '../includes/db.php';
include '../includes/vault_auth.php';

vaultRequireAuth();
$userId = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $conn->prepare(
        "SELECT salt, hint, verification_blob, verification_iv FROM vault_salt WHERE user_id = ?"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    vaultJson($row ?: ['salt' => null]);
}

if ($method === 'POST') {
    vaultRateLimit('salt_setup_' . $userId, 5, 3600);

    $body = vaultInput();
    $salt             = preg_replace('/[^a-f0-9]/i', '', $body['salt'] ?? '');
    $hint             = substr(strip_tags($body['hint'] ?? ''), 0, 200);
    $verificationBlob = $body['verification_blob'] ?? '';
    $verificationIv   = $body['verification_iv'] ?? '';

    if (strlen($salt) !== 64) vaultJson(['error' => 'Invalid salt length'], 400);
    if (empty($verificationBlob) || empty($verificationIv)) {
        vaultJson(['error' => 'Missing verification data'], 400);
    }

    $check = $conn->prepare("SELECT user_id FROM vault_salt WHERE user_id = ?");
    $check->bind_param('i', $userId);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        vaultJson(['error' => 'Vault already initialised'], 409);
    }

    $stmt = $conn->prepare(
        "INSERT INTO vault_salt (user_id, salt, hint, verification_blob, verification_iv)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('issss', $userId, $salt, $hint, $verificationBlob, $verificationIv);
    $stmt->execute() ? vaultJson(['ok' => true]) : vaultJson(['error' => 'DB error'], 500);
}

vaultJson(['error' => 'Method not allowed'], 405);

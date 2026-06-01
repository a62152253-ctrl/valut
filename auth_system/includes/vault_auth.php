<?php
// Shared middleware for vault API endpoints

function vaultRequireAuth() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
}

function vaultJson($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

function vaultInput() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?: [];
}

function vaultRateLimit($key, $max, $window = 60) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_id'])) return;
    
    $sessionKey = 'rl_' . $key;
    $windowKey  = 'rl_w_' . $key;
    $now = time();
    
    if (!isset($_SESSION[$windowKey]) || $now - $_SESSION[$windowKey] > $window) {
        $_SESSION[$windowKey] = $now;
        $_SESSION[$sessionKey] = 0;
    }
    
    $_SESSION[$sessionKey]++;
    if ($_SESSION[$sessionKey] > $max) {
        vaultJson(['error' => 'Rate limit exceeded'], 429);
    }
}

<?php
session_start();
include '../includes/db.php';
include '../includes/recent-items.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized']));
}

$user_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['entry_uuid'], $data['entry_title'], $data['entry_type'])) {
        http_response_code(400);
        die(json_encode(['error' => 'Missing required fields']));
    }
    
    $success = logRecentItem(
        $user_id,
        $data['entry_uuid'],
        $data['entry_title'],
        $data['entry_type']
    );
    
    die(json_encode(['ok' => $success]));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 6;
    $items = getRecentItems($user_id, $limit);
    
    $items_with_icons = array_map(function($item) {
        return array_merge($item, [
            'icon' => getServiceIcon($item['entry_title'])
        ]);
    }, $items);
    
    die(json_encode(['items' => $items_with_icons]));
}

http_response_code(405);
die(json_encode(['error' => 'Method not allowed']));
?>

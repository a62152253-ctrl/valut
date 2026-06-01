<?php
// Test script to verify Recently Used Items feature

session_start();
include 'includes/db.php';
include 'includes/recent-items.php';

// For testing - set test user_id
$test_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1);

header('Content-Type: application/json');

$response = [
    'success' => true,
    'test_user_id' => $test_user_id,
    'services_detected' => []
];

// Test service icon detection
$test_titles = [
    'GitHub Account',
    'Google Gmail',
    'Notion Workspace',
    'Minecraft Account',
    'AWS Console',
    'Discord Server',
    'Netflix',
    'Random App'
];

foreach ($test_titles as $title) {
    $response['services_detected'][$title] = getServiceIcon($title);
}

// Try to get recent items (if user has any)
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $recent = getRecentItems($user_id, 10);
    $response['recent_items_count'] = count($recent);
    $response['recent_items_sample'] = array_slice($recent, 0, 3);
    
    // Add icons to sample
    foreach ($response['recent_items_sample'] as &$item) {
        $item['icon'] = getServiceIcon($item['entry_title']);
    }
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>

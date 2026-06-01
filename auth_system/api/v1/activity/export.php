<?php
/**
 * API v1 - Activity Export Endpoint
 * GET /api/v1/activity/export?format=json|csv
 * Requires authentication
 */

header('X-API-Version: v1');

session_start();
include '../../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized']));
}

$format = $_GET['format'] ?? 'json';
$limit = min((int)($_GET['limit'] ?? 100), 1000); // Max 1000
$offset = (int)($_GET['offset'] ?? 0);
$user_id = (int)$_SESSION['user_id'];

// Validate format
if (!in_array($format, ['json', 'csv'])) {
    http_response_code(400);
    die(json_encode(['error' => 'Invalid format. Use json or csv']));
}

// Fetch security events
$stmt = $conn->prepare("
    SELECT event_type, ip_address, details, created_at 
    FROM security_logs 
    WHERE user_id = ? OR (user_id IS NULL AND ip_address = ?)
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");

$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$stmt->bind_param('isii', $user_id, $ip, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();
$events = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Format output
if ($format === 'json') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="activity-export-' . date('Y-m-d') . '.json"');
    
    echo json_encode([
        'export_date' => date('Y-m-d H:i:s'),
        'user_id' => $user_id,
        'format' => 'JSON',
        'total_records' => count($events),
        'events' => $events
    ], JSON_PRETTY_PRINT);

} else {
    // CSV format
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity-export-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // BOM for Excel UTF-8 support
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header row
    fputcsv($output, [
        'Date/Time',
        'Event Type',
        'IP Address',
        'Details'
    ]);
    
    // Data rows
    foreach ($events as $event) {
        fputcsv($output, [
            $event['created_at'],
            $event['event_type'],
            $event['ip_address'],
            $event['details'] ?? ''
        ]);
    }
    
    fclose($output);
}

logSecurityEvent('activity_exported', $user_id, "Activity export ($format format, $limit records)");
?>

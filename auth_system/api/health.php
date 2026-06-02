<?php
header('Content-Type: application/json');
include '../includes/db.php';

// ═══════════════════════════════════════════════════════════════════
// HEALTH CHECK ENDPOINT
// ═══════════════════════════════════════════════════════════════════
// Used by Kubernetes, Docker, or monitoring systems to verify service health

$checks = [
    'database'  => (bool)($conn && $conn->ping()),
    'disk_free' => (bool)(disk_free_space('/') > 100 * 1024 * 1024),  // > 100MB threshold
];

$healthy = array_reduce($checks, function($carry, $item) { return $carry && $item; }, true);
$status  = $healthy ? 200 : 503;

http_response_code($status);
echo json_encode([
    'ok'      => $healthy,
    'status'  => $status === 200 ? 'healthy' : 'unhealthy',
    'checks'  => $checks,
    'uptime'  => time() - ($_SERVER['REQUEST_TIME'] ?? time()),
    'timestamp' => date('c'),
]);
?>

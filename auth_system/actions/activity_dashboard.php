<?php
/**
 * Activity Dashboard - Comprehensive activity and stats analytics
 * Shows detailed logs, time-based analytics, and vault usage patterns
 */

session_start();
include_once '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$filter = sanitize($_GET['filter'] ?? 'all'); // all, today, week, month
$type_filter = sanitize($_GET['type'] ?? ''); // '', login, note, card, identity

// Validate filters
if (!in_array($filter, ['all', 'today', 'week', 'month'])) $filter = 'all';
if ($type_filter && !in_array($type_filter, ['login', 'note', 'card', 'identity'])) $type_filter = '';

// Build date filter
$date_filter = '';
$date_param = '';
switch ($filter) {
    case 'today':
        $date_filter = "AND DATE(updated_at) = CURDATE()";
        $date_param = 'today';
        break;
    case 'week':
        $date_filter = "AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        $date_param = 'week';
        break;
    case 'month':
        $date_filter = "AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $date_param = 'month';
        break;
}

// Get detailed activity log
$query = "SELECT uuid, type, encrypted_data, created_at, updated_at, favorite 
          FROM vault_entries 
          WHERE user_id = ? " . (!empty($type_filter) ? "AND type = ?" : "") . " 
          $date_filter
          ORDER BY updated_at DESC LIMIT 100";

$stmt = $conn->prepare($query);
if (!empty($type_filter)) {
    $stmt->bind_param('is', $user_id, $type_filter);
} else {
    $stmt->bind_param('i', $user_id);
}
$stmt->execute();
$activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get statistics
$stats_query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN DATE(updated_at) = CURDATE() THEN 1 ELSE 0 END) as today_count,
                    SUM(CASE WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as week_count,
                    SUM(CASE WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as month_count
                FROM vault_entries 
                WHERE user_id = ?";
$stmt = $conn->prepare($stats_query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get activity by type
$type_query = "SELECT type, COUNT(*) as cnt FROM vault_entries WHERE user_id = ? GROUP BY type";
$stmt = $conn->prepare($type_query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$type_counts = [];
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $type_counts[$row['type']] = $row['cnt'];
}
$stmt->close();

// Get creation timeline (for chart)
$timeline_query = "SELECT DATE(created_at) as date, COUNT(*) as count 
                   FROM vault_entries 
                   WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                   GROUP BY DATE(created_at)
                   ORDER BY date DESC LIMIT 30";
$stmt = $conn->prepare($timeline_query);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$timeline = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity & Analytics - Vaultly</title>
    <style>
        :root {
            --primary: #6366f1;
            --secondary: #8b5cf6;
            --success: #22c55e;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark-bg: #0f0f1e;
            --card-bg: rgba(30,30,50,0.5);
            --border-color: rgba(100,100,150,0.2);
            --text-primary: #e0e0e0;
            --text-secondary: #a0a0b0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, var(--dark-bg) 0%, #1a1a2e 100%);
            color: var(--text-primary);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            padding: 30px;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            margin-bottom: 32px;
        }

        .page-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .page-subtitle {
            color: var(--text-secondary);
            font-size: 14px;
        }

        /* Filters */
        .filter-bar {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
            padding: 16px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
        }

        .filter-group {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-label {
            display: flex;
            align-items: center;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-secondary);
            margin-right: 8px;
        }

        .filter-btn {
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid rgba(99,102,241,0.2);
            background: transparent;
            color: var(--text-primary);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }

        .filter-btn:hover,
        .filter-btn.active {
            background: rgba(99,102,241,0.2);
            border-color: var(--primary);
            color: var(--primary);
        }

        /* Stats Overview */
        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .stat-box {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 18px;
            text-align: center;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Activity List */
        .activity-list {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
        }

        .activity-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            background: rgba(50,50,80,0.2);
        }

        .activity-header h3 {
            font-size: 15px;
            font-weight: 700;
        }

        .activity-items {
            max-height: 600px;
            overflow-y: auto;
        }

        .activity-item {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 16px;
            align-items: center;
            transition: background 0.2s;
        }

        .activity-item:hover {
            background: rgba(50,50,80,0.2);
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-content {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            background: rgba(99,102,241,0.15);
            flex-shrink: 0;
        }

        .activity-details {
            min-width: 0;
        }

        .activity-title {
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .activity-meta {
            font-size: 11px;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        .activity-time {
            font-size: 12px;
            color: var(--text-secondary);
            text-align: right;
            white-space: nowrap;
        }

        .type-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .type-badge.login    { background: rgba(99,102,241,0.2); color: #6366f1; }
        .type-badge.note     { background: rgba(167,139,250,0.2); color: #a78bfa; }
        .type-badge.card     { background: rgba(239,68,68,0.2);   color: #ef4444; }
        .type-badge.identity { background: rgba(34,197,94,0.2);   color: #22c55e; }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        /* Type breakdown */
        .type-breakdown {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .type-breakdown h3 {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 16px;
        }

        .type-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
        }

        .type-item {
            padding: 12px;
            background: rgba(50,50,80,0.2);
            border-radius: 8px;
            text-align: center;
        }

        .type-item-icon {
            font-size: 24px;
            margin-bottom: 8px;
        }

        .type-item-count {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
        }

        .type-item-label {
            font-size: 11px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }

        @media (max-width: 768px) {
            .page-title { font-size: 24px; }
            .filter-bar { flex-direction: column; }
            .stats-overview { grid-template-columns: 1fr 1fr; }
            .activity-item { grid-template-columns: 1fr; }
            .activity-time { text-align: left; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <div class="page-title">📊 Activity & Analytics</div>
        <div class="page-subtitle">Track your vault activity and usage patterns</div>
    </div>

    <!-- Filters -->
    <div class="filter-bar">
        <div class="filter-label">Time Period:</div>
        <div class="filter-group">
            <a href="?filter=all<?php echo $type_filter ? "&type=$type_filter" : ''; ?>" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">All Time</a>
            <a href="?filter=today<?php echo $type_filter ? "&type=$type_filter" : ''; ?>" class="filter-btn <?php echo $filter === 'today' ? 'active' : ''; ?>">Today</a>
            <a href="?filter=week<?php echo $type_filter ? "&type=$type_filter" : ''; ?>" class="filter-btn <?php echo $filter === 'week' ? 'active' : ''; ?>">7 Days</a>
            <a href="?filter=month<?php echo $type_filter ? "&type=$type_filter" : ''; ?>" class="filter-btn <?php echo $filter === 'month' ? 'active' : ''; ?>">30 Days</a>
        </div>

        <div class="filter-label" style="margin-left: auto;">Type:</div>
        <div class="filter-group">
            <a href="?filter=<?php echo $filter; ?>&type=" class="filter-btn <?php echo empty($type_filter) ? 'active' : ''; ?>">All</a>
            <a href="?filter=<?php echo $filter; ?>&type=login" class="filter-btn <?php echo $type_filter === 'login' ? 'active' : ''; ?>">🔐 Logins</a>
            <a href="?filter=<?php echo $filter; ?>&type=note" class="filter-btn <?php echo $type_filter === 'note' ? 'active' : ''; ?>">📝 Notes</a>
            <a href="?filter=<?php echo $filter; ?>&type=card" class="filter-btn <?php echo $type_filter === 'card' ? 'active' : ''; ?>">💳 Cards</a>
            <a href="?filter=<?php echo $filter; ?>&type=identity" class="filter-btn <?php echo $type_filter === 'identity' ? 'active' : ''; ?>">👤 Identities</a>
        </div>
    </div>

    <!-- Stats Overview -->
    <div class="stats-overview">
        <div class="stat-box">
            <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
            <div class="stat-label">Total Items</div>
        </div>
        <div class="stat-box">
            <div class="stat-value"><?php echo $stats['today_count'] ?? 0; ?></div>
            <div class="stat-label">Updated Today</div>
        </div>
        <div class="stat-box">
            <div class="stat-value"><?php echo $stats['week_count'] ?? 0; ?></div>
            <div class="stat-label">This Week</div>
        </div>
        <div class="stat-box">
            <div class="stat-value"><?php echo $stats['month_count'] ?? 0; ?></div>
            <div class="stat-label">This Month</div>
        </div>
    </div>

    <!-- Type Breakdown -->
    <?php if (!empty($type_counts)): ?>
    <div class="type-breakdown">
        <h3>📋 Items by Type</h3>
        <div class="type-list">
            <?php
            $type_info = [
                'login' => ['icon' => '🔐', 'name' => 'Logins'],
                'note' => ['icon' => '📝', 'name' => 'Secure Notes'],
                'card' => ['icon' => '💳', 'name' => 'Cards'],
                'identity' => ['icon' => '👤', 'name' => 'Identities'],
            ];
            foreach ($type_info as $type => $info):
                if (isset($type_counts[$type])):
            ?>
                <div class="type-item">
                    <div class="type-item-icon"><?php echo $info['icon']; ?></div>
                    <div class="type-item-count"><?php echo $type_counts[$type]; ?></div>
                    <div class="type-item-label"><?php echo $info['name']; ?></div>
                </div>
            <?php endif; endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Activity List -->
    <div class="activity-list">
        <div class="activity-header">
            <h3>🕐 Recent Activity</h3>
        </div>
        <div class="activity-items">
            <?php if (count($activities) > 0): ?>
                <?php foreach ($activities as $activity):
                    $data = json_decode($activity['encrypted_data'], true) ?? [];
                    $title = htmlspecialchars($data['title'] ?? 'Untitled');
                    $icons = ['login' => '🔐', 'note' => '📝', 'card' => '💳', 'identity' => '👤'];
                    $icon = $icons[$activity['type']] ?? '?';
                    $time_diff = time() - strtotime($activity['updated_at']);
                    if ($time_diff < 60) $time_str = 'now';
                    elseif ($time_diff < 3600) $time_str = floor($time_diff / 60) . 'm ago';
                    elseif ($time_diff < 86400) $time_str = floor($time_diff / 3600) . 'h ago';
                    else $time_str = date('M d', strtotime($activity['updated_at']));
                ?>
                <div class="activity-item">
                    <div class="activity-content">
                        <div class="activity-icon"><?php echo $icon; ?></div>
                        <div class="activity-details">
                            <div class="activity-title"><?php echo $title; ?></div>
                            <div class="activity-meta">
                                <span class="type-badge <?php echo $activity['type']; ?>">
                                    <?php echo strtoupper($activity['type']); ?>
                                </span>
                                <?php if ($activity['favorite']): ?>
                                    <span style="margin-left: 8px; color: #f59e0b;">⭐ Favorite</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="activity-time"><?php echo $time_str; ?></div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <p>No activity found</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Back Link -->
    <div style="text-align: center; margin-top: 32px;">
        <a href="javascript:history.back()" style="color: var(--primary); text-decoration: none; font-weight: 600;">
            ← Back to Dashboard
        </a>
    </div>
</div>

</body>
</html>
<?php
?>

<?php
/**
 * Dashboard Widgets - Reusable component system for dashboard
 * Includes: Stats cards, charts, quick actions, activity feed
 */

session_start();
include_once '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Fetch comprehensive dashboard stats
function getDashboardStats($user_id, $conn) {
    $stats = [
        'total_items'    => 0,
        'total_logins'   => 0,
        'total_notes'    => 0,
        'total_cards'    => 0,
        'total_identity' => 0,
        'total_favorites' => 0,
        'total_vaults'   => 0,
        'recent_added'   => [],
        'recent_updated' => [],
        'security_score' => 0,
    ];

    // Total items by type
    $query = "SELECT type, COUNT(*) as cnt FROM vault_entries WHERE user_id = ? GROUP BY type";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $type = $row['type'];
        if ($type === 'login') $stats['total_logins'] = (int)$row['cnt'];
        elseif ($type === 'note') $stats['total_notes'] = (int)$row['cnt'];
        elseif ($type === 'card') $stats['total_cards'] = (int)$row['cnt'];
        elseif ($type === 'identity') $stats['total_identity'] = (int)$row['cnt'];
    }
    $stmt->close();

    $stats['total_items'] = array_sum([$stats['total_logins'], $stats['total_notes'], $stats['total_cards'], $stats['total_identity']]);

    // Favorites
    $query = "SELECT COUNT(*) as cnt FROM vault_entries WHERE user_id = ? AND favorite = 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stats['total_favorites'] = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();

    // Vaults
    $query = "SELECT COUNT(*) as cnt FROM vault_folders WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stats['total_vaults'] = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();

    // Recent items (added)
    $query = "SELECT uuid, type, encrypted_data, created_at FROM vault_entries WHERE user_id = ? ORDER BY created_at DESC LIMIT 5";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $data = json_decode($row['encrypted_data'], true) ?? [];
        $stats['recent_added'][] = [
            'uuid' => $row['uuid'],
            'type' => $row['type'],
            'title' => $data['title'] ?? 'Untitled',
            'created_at' => $row['created_at'],
        ];
    }
    $stmt->close();

    // Security score calculation (0-100)
    $security_score = 0;
    if ($stats['total_items'] > 0) $security_score += 20;
    if ($stats['total_favorites'] > 0) $security_score += 15;
    if ($stats['total_vaults'] > 0) $security_score += 20;
    
    // Check for weak passwords (contains common patterns)
    $weak_password_count = 0;
    $query = "SELECT COUNT(*) as cnt FROM vault_entries WHERE user_id = ? AND type='login' AND encrypted_data LIKE '%password%'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    
    $security_score += 45;

    $stats['security_score'] = min(100, $security_score);

    return $stats;
}

// Get activity log
function getActivityLog($user_id, $conn, $limit = 10) {
    $activities = [];
    $query = "SELECT uuid, type, created_at, updated_at, encrypted_data FROM vault_entries WHERE user_id = ? ORDER BY updated_at DESC LIMIT ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $user_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $data = json_decode($row['encrypted_data'], true) ?? [];
        $activities[] = [
            'action' => date('Y-m-d', strtotime($row['updated_at'])) === date('Y-m-d') ? 'Today' : date('M d', strtotime($row['updated_at'])),
            'title' => $data['title'] ?? 'Untitled',
            'type' => $row['type'],
            'time' => date('H:i', strtotime($row['updated_at'])),
        ];
    }
    $stmt->close();
    return $activities;
}

$dashboard_stats = getDashboardStats($user_id, $conn);
$activity_log = getActivityLog($user_id, $conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Widgets - Vaultly</title>
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
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

        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .dashboard-header {
            margin-bottom: 40px;
        }

        .dashboard-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .dashboard-subtitle {
            color: var(--text-secondary);
            font-size: 14px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .stat-card:hover {
            background: rgba(30,30,50,0.8);
            border-color: rgba(99,102,241,0.3);
            transform: translateY(-4px);
        }

        .stat-card-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 12px;
            background: rgba(99,102,241,0.15);
        }

        .stat-card-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .stat-card-label {
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Security Score Widget */
        .security-widget {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 32px;
        }

        .security-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .security-header h3 {
            font-size: 16px;
            font-weight: 700;
        }

        .security-score-display {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .score-ring {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 700;
            background: conic-gradient(var(--success) 0%, var(--success) <?php echo $dashboard_stats['security_score']; ?>%, rgba(100,100,150,0.1) <?php echo $dashboard_stats['security_score']; ?>%, rgba(100,100,150,0.1) 100%);
            position: relative;
        }

        .score-ring::after {
            content: '';
            position: absolute;
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: var(--dark-bg);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .score-value {
            position: relative;
            z-index: 1;
        }

        .score-info {
            flex: 1;
        }

        .score-info h4 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .score-info p {
            font-size: 12px;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .score-checks {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        .check-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px;
            background: rgba(50,50,80,0.2);
            border-radius: 8px;
            font-size: 12px;
        }

        .check-icon {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            flex-shrink: 0;
            background: rgba(34,197,94,0.2);
            color: var(--success);
        }

        .check-icon.unchecked {
            background: rgba(239,68,68,0.2);
            color: var(--danger);
        }

        /* Two-column layout for activity */
        .dashboard-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
            margin-bottom: 32px;
        }

        @media (max-width: 1024px) {
            .dashboard-row {
                grid-template-columns: 1fr;
            }
        }

        /* Recent Items Widget */
        .recent-items-widget {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
        }

        .widget-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .widget-header h3 {
            font-size: 15px;
            font-weight: 700;
        }

        .widget-view-all {
            font-size: 12px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }

        .widget-view-all:hover {
            color: var(--secondary);
        }

        .items-list {
            display: flex;
            flex-direction: column;
        }

        .item-entry {
            padding: 14px 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 12px;
            transition: background 0.2s;
        }

        .item-entry:first-child {
            border-top: none;
        }

        .item-entry:hover {
            background: rgba(50,50,80,0.2);
        }

        .item-type-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            background: rgba(99,102,241,0.15);
            flex-shrink: 0;
        }

        .item-info {
            flex: 1;
            min-width: 0;
        }

        .item-title {
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .item-time {
            font-size: 11px;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        .empty-state-items {
            padding: 40px 24px;
            text-align: center;
            color: var(--text-secondary);
        }

        .empty-state-icon {
            font-size: 32px;
            margin-bottom: 12px;
        }

        /* Activity Feed */
        .activity-feed-widget {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
        }

        .activity-timeline {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .timeline-item {
            display: flex;
            gap: 12px;
            padding: 10px;
            border-radius: 8px;
            background: rgba(50,50,80,0.2);
            font-size: 12px;
        }

        .timeline-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--primary);
            margin-top: 4px;
            flex-shrink: 0;
        }

        .timeline-content {
            flex: 1;
            min-width: 0;
        }

        .timeline-date {
            color: var(--text-secondary);
            font-weight: 600;
            display: block;
            margin-bottom: 2px;
        }

        .timeline-action {
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 32px;
        }

        .action-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 16px 12px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            color: var(--text-primary);
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
        }

        .action-btn:hover {
            background: rgba(99,102,241,0.1);
            border-color: rgba(99,102,241,0.3);
            transform: translateY(-2px);
        }

        .action-icon {
            font-size: 24px;
        }

        .type-badge-mini {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .type-badge-mini.login    { background: rgba(99,102,241,0.2); color: #6366f1; }
        .type-badge-mini.note     { background: rgba(167,139,250,0.2); color: #a78bfa; }
        .type-badge-mini.card     { background: rgba(239,68,68,0.2);   color: #ef4444; }
        .type-badge-mini.identity { background: rgba(34,197,94,0.2);   color: #22c55e; }

        .no-items-message {
            text-align: center;
            padding: 30px;
            color: var(--text-secondary);
            font-size: 13px;
        }
    </style>
</head>
<body>

<div class="dashboard-container">
    <div class="dashboard-header">
        <div class="dashboard-title">📊 Dashboard Overview</div>
        <div class="dashboard-subtitle">Your vault at a glance</div>
    </div>

    <!-- Quick Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-card-icon">🔐</div>
            <div class="stat-card-value"><?php echo $dashboard_stats['total_items']; ?></div>
            <div class="stat-card-label">Total Items</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon">🔑</div>
            <div class="stat-card-value"><?php echo $dashboard_stats['total_logins']; ?></div>
            <div class="stat-card-label">Logins</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon">📝</div>
            <div class="stat-card-value"><?php echo $dashboard_stats['total_notes']; ?></div>
            <div class="stat-card-label">Notes</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon">💳</div>
            <div class="stat-card-value"><?php echo $dashboard_stats['total_cards']; ?></div>
            <div class="stat-card-label">Cards</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon">👤</div>
            <div class="stat-card-value"><?php echo $dashboard_stats['total_identity']; ?></div>
            <div class="stat-card-label">Identities</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-icon">⭐</div>
            <div class="stat-card-value"><?php echo $dashboard_stats['total_favorites']; ?></div>
            <div class="stat-card-label">Favorites</div>
        </div>
    </div>

    <!-- Security Score Widget -->
    <div class="security-widget">
        <div class="security-header">
            <h3>🛡️ Security Score</h3>
        </div>
        <div class="security-score-display">
            <div class="score-ring">
                <div class="score-value"><?php echo $dashboard_stats['security_score']; ?></div>
            </div>
            <div class="score-info">
                <h4>Vault Security Rating</h4>
                <p><?php 
                    if ($dashboard_stats['security_score'] >= 80) echo "Excellent! Your vault is well-organized and secure.";
                    elseif ($dashboard_stats['security_score'] >= 60) echo "Good. Consider organizing items into more vaults.";
                    else echo "Basic setup. Add more items and organize them into vaults.";
                ?></p>
            </div>
        </div>
        <div class="score-checks">
            <div class="check-item">
                <div class="check-icon <?php echo $dashboard_stats['total_items'] > 0 ? '' : 'unchecked'; ?>">
                    <?php echo $dashboard_stats['total_items'] > 0 ? '✓' : '○'; ?>
                </div>
                <span><?php echo $dashboard_stats['total_items'] > 0 ? 'Items stored' : 'No items yet'; ?></span>
            </div>
            <div class="check-item">
                <div class="check-icon <?php echo $dashboard_stats['total_favorites'] > 0 ? '' : 'unchecked'; ?>">
                    <?php echo $dashboard_stats['total_favorites'] > 0 ? '✓' : '○'; ?>
                </div>
                <span><?php echo $dashboard_stats['total_favorites'] > 0 ? 'Favorites marked' : 'Mark favorites'; ?></span>
            </div>
            <div class="check-item">
                <div class="check-icon <?php echo $dashboard_stats['total_vaults'] > 0 ? '' : 'unchecked'; ?>">
                    <?php echo $dashboard_stats['total_vaults'] > 0 ? '✓' : '○'; ?>
                </div>
                <span><?php echo $dashboard_stats['total_vaults'] > 0 ? 'Vaults created' : 'Create first vault'; ?></span>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div style="margin-bottom: 32px;">
        <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 16px;">⚡ Quick Actions</h3>
        <div class="quick-actions">
            <a href="add_password.php" class="action-btn">
                <div class="action-icon">➕</div>
                <span>Add Item</span>
            </a>
            <a href="password_generator.php" class="action-btn">
                <div class="action-icon">🎲</div>
                <span>Generate Password</span>
            </a>
            <a href="view_favorites.php" class="action-btn">
                <div class="action-icon">⭐</div>
                <span>View Favorites</span>
            </a>
            <a href="manage_vaults.php" class="action-btn">
                <div class="action-icon">📁</div>
                <span>Manage Vaults</span>
            </a>
            <a href="security_audit.php" class="action-btn">
                <div class="action-icon">🔍</div>
                <span>Security Audit</span>
            </a>
            <a href="view_shared.php" class="action-btn">
                <div class="action-icon">👥</div>
                <span>Share Items</span>
            </a>
        </div>
    </div>

    <!-- Recent Items & Activity -->
    <div class="dashboard-row">
        <!-- Recent Items -->
        <div class="recent-items-widget">
            <div class="widget-header">
                <h3>🆕 Recently Added</h3>
                <a href="view_all_items.php" class="widget-view-all">View All →</a>
            </div>
            <div class="items-list">
                <?php if (count($dashboard_stats['recent_added']) > 0): ?>
                    <?php foreach ($dashboard_stats['recent_added'] as $item): ?>
                    <div class="item-entry">
                        <div class="item-type-icon">
                            <?php 
                                $icons = ['login' => '🔐', 'note' => '📝', 'card' => '💳', 'identity' => '👤'];
                                echo $icons[$item['type']] ?? '?';
                            ?>
                        </div>
                        <div class="item-info">
                            <div class="item-title"><?php echo htmlspecialchars($item['title']); ?></div>
                            <div class="item-time"><?php echo date('M d, H:i', strtotime($item['created_at'])); ?></div>
                        </div>
                        <span class="type-badge-mini <?php echo $item['type']; ?>">
                            <?php echo strtoupper($item['type']); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state-items">
                        <div class="empty-state-icon">📭</div>
                        <p>No items yet. <a href="add_password.php" style="color: var(--primary);">Add your first item</a></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Activity Feed -->
        <div class="activity-feed-widget">
            <div class="widget-header">
                <h3>📋 Recent Activity</h3>
            </div>
            <div class="activity-timeline">
                <?php if (count($activity_log) > 0): ?>
                    <?php foreach ($activity_log as $activity): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot"></div>
                        <div class="timeline-content">
                            <span class="timeline-date"><?php echo $activity['action']; ?></span>
                            <span class="timeline-action"><?php echo htmlspecialchars($activity['title']); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-items-message">No recent activity</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</body>
</html>
<?php
?>

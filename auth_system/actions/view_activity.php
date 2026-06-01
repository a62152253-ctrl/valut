<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 50;
$offset = ($page - 1) * $limit;

$filter_event = $_GET['event'] ?? '';
$filter_date  = $_GET['date']  ?? '';

$where  = "WHERE user_id = ?";
$params = [$user_id];
$types  = 'i';

if ($filter_event) {
    $where   .= " AND event_type = ?";
    $params[] = $filter_event;
    $types   .= 's';
}
if ($filter_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) {
    $where   .= " AND DATE(created_at) = ?";
    $params[] = $filter_date;
    $types   .= 's';
}

// Total count
$count_params = $params;
$cnt_stmt     = $conn->prepare("SELECT COUNT(*) as cnt FROM security_logs $where");
$cnt_stmt->bind_param($types, ...$count_params);
$cnt_stmt->execute();
$total     = (int)($cnt_stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$cnt_stmt->close();
$total_pages = max(1, (int)ceil($total / $limit));

// Events
$params[] = $limit;
$params[] = $offset;
$types   .= 'ii';
$stmt = $conn->prepare(
    "SELECT event_type, ip_address, user_agent, details, created_at
     FROM security_logs $where ORDER BY created_at DESC LIMIT ? OFFSET ?"
);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Available event types for filter dropdown
$ev_stmt = $conn->prepare("SELECT DISTINCT event_type FROM security_logs WHERE user_id=? ORDER BY event_type");
$ev_stmt->bind_param('i', $user_id);
$ev_stmt->execute();
$event_types = array_column($ev_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'event_type');
$ev_stmt->close();

$event_icons = [
    'login_success'      => '✅',
    'login_failed'       => '❌',
    'logout'             => '👋',
    'csrf_violation'     => '🚨',
    'reauth_success_pw'  => '🔓',
    'reauth_failed_pw'   => '🔒',
    'reauth_success_webauthn' => '🔑',
    'add_entry_success'  => '➕',
    'update_item_success'=> '✏️',
    'delete'             => '🗑',
    'passkey_deleted'    => '🔑',
    'share_revoked'      => '🔗',
    '2fa_enabled'        => '🛡',
    '2fa_disabled'       => '⚠️',
    'password_changed'   => '🔐',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Activity – Vaultly</title>
<link rel="stylesheet" href="../css/app.css">
<style>
.activity-list{display:flex;flex-direction:column;gap:2px;max-width:840px;}
.activity-row{display:flex;align-items:flex-start;gap:14px;padding:14px 16px;background:var(--s1);border:1px solid var(--b0);border-radius:var(--r3);transition:border-color var(--t);}
.activity-row:hover{border-color:var(--b2);}
.act-icon{font-size:18px;flex-shrink:0;width:28px;text-align:center;padding-top:1px;}
.act-body{flex:1;min-width:0;}
.act-type{font-size:13px;font-weight:600;color:var(--t0);}
.act-detail{font-size:12px;color:var(--t3);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.act-meta{font-size:11px;color:var(--t4);margin-top:3px;}
.act-time{font-size:11px;color:var(--t4);flex-shrink:0;white-space:nowrap;}
.filters-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;align-items:center;}
.filter-select{padding:7px 12px;background:var(--s2);border:1px solid var(--b1);color:var(--t2);border-radius:var(--r2);font-size:12.5px;cursor:pointer;outline:none;font-family:inherit;}
.filter-select:focus{border-color:var(--b3);}
.pagination{display:flex;gap:8px;margin-top:20px;align-items:center;justify-content:center;}
.page-btn{padding:7px 14px;background:var(--s2);border:1px solid var(--b1);color:var(--t2);border-radius:var(--r2);cursor:pointer;font-size:13px;text-decoration:none;transition:all var(--t);}
.page-btn:hover,.page-btn.active{background:var(--a-dim);border-color:var(--b3);color:var(--a3);}
.page-btn.disabled{opacity:.35;pointer-events:none;}
</style>
</head>
<body>
<div class="layout">
<?php $active_nav = 'activity'; include '../includes/sidebar.php'; ?>
<div class="main">
    <div class="page-header">
        <div>
            <div class="page-title">🔔 Activity</div>
            <div class="page-subtitle"><?php echo $total; ?> security events</div>
        </div>
    </div>

    <form method="get" class="filters-bar">
        <select name="event" class="filter-select" onchange="this.form.submit()">
            <option value="">All events</option>
            <?php foreach ($event_types as $et): ?>
            <option value="<?php echo htmlspecialchars($et); ?>" <?php echo $filter_event===$et?'selected':''; ?>>
                <?php echo htmlspecialchars($et); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>"
               class="filter-select" onchange="this.form.submit()"
               style="color:var(--t2);" max="<?php echo date('Y-m-d'); ?>">
        <?php if ($filter_event || $filter_date): ?>
        <a href="view_activity.php" class="page-btn">Clear filters</a>
        <?php endif; ?>
    </form>

    <div class="activity-list stagger-in">
        <?php if (count($events) > 0): ?>
            <?php foreach ($events as $ev):
                $icon = $event_icons[$ev['event_type']] ?? '📋';
                $ua   = $ev['user_agent'] ?? '';
                $browser = '';
                if (strpos($ua, 'Chrome') !== false)       $browser = 'Chrome';
                elseif (strpos($ua, 'Firefox') !== false)  $browser = 'Firefox';
                elseif (strpos($ua, 'Safari') !== false)   $browser = 'Safari';
                elseif (strpos($ua, 'Edge') !== false)     $browser = 'Edge';
            ?>
            <div class="activity-row">
                <div class="act-icon"><?php echo $icon; ?></div>
                <div class="act-body">
                    <div class="act-type"><?php echo htmlspecialchars(str_replace('_', ' ', $ev['event_type'])); ?></div>
                    <?php if ($ev['details']): ?>
                    <div class="act-detail"><?php echo htmlspecialchars($ev['details']); ?></div>
                    <?php endif; ?>
                    <div class="act-meta">
                        <?php echo htmlspecialchars($ev['ip_address']); ?>
                        <?php if ($browser): ?> · <?php echo $browser; ?><?php endif; ?>
                    </div>
                </div>
                <div class="act-time"><?php echo date('M d, H:i', strtotime($ev['created_at'])); ?></div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <span class="empty-icon">🔔</span>
                <h3>No activity yet</h3>
                <p>Security events will appear here.</p>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <a href="?page=<?php echo $page-1; ?>&event=<?php echo urlencode($filter_event); ?>&date=<?php echo urlencode($filter_date); ?>"
           class="page-btn <?php echo $page<=1?'disabled':''; ?>">← Prev</a>
        <span style="font-size:13px;color:var(--t3);">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
        <a href="?page=<?php echo $page+1; ?>&event=<?php echo urlencode($filter_event); ?>&date=<?php echo urlencode($filter_date); ?>"
           class="page-btn <?php echo $page>=$total_pages?'disabled':''; ?>">Next →</a>
    </div>
    <?php endif; ?>
</div>
</div>
</body>
</html>

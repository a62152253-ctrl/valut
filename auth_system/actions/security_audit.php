<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// ── Account-level security metrics (no decryption needed) ────────────────────

// Item counts by type
$stmt = $conn->prepare(
    "SELECT type, COUNT(*) as cnt FROM vault_entries WHERE user_id = ? GROUP BY type"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$type_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$counts = ['login' => 0, 'note' => 0, 'card' => 0, 'identity' => 0];
$total_items = 0;
foreach ($type_rows as $r) {
    $counts[$r['type']] = (int)$r['cnt'];
    $total_items += (int)$r['cnt'];
}

// 2FA / WebAuthn status
$stmt = $conn->prepare("SELECT totp_enabled FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user_row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$totp_enabled = (bool)($user_row['totp_enabled'] ?? false);

$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM webauthn_credentials WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$passkey_count = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$stmt->close();

// Vault initialized?
$stmt = $conn->prepare("SELECT 1 FROM vault_salt WHERE user_id = ? LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$vault_init = (bool)$stmt->get_result()->fetch_assoc();
$stmt->close();

// Recent security events (last 30 days)
$stmt = $conn->prepare(
    "SELECT event_type, COUNT(*) as cnt
     FROM security_logs
     WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
     GROUP BY event_type ORDER BY cnt DESC"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$recent_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$failed_logins = 0;
foreach ($recent_events as $e) {
    if ($e['event_type'] === 'login_failed') {
        $failed_logins = (int)$e['cnt'];
    }
}

// Recent security log entries
$stmt = $conn->prepare(
    "SELECT event_type, ip_address, details, created_at
     FROM security_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 20"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$recent_log = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Score calculation ─────────────────────────────────────────────────────────
$score = 0;
$deductions = [];

if ($vault_init)    { $score += 20; } else { $deductions[] = 'Vault not initialized'; }
if ($totp_enabled)  { $score += 30; } else { $deductions[] = 'Two-factor authentication not enabled'; }
if ($passkey_count) { $score += 20; } else { $deductions[] = 'No passkey registered'; }
if ($total_items > 0) { $score += 10; }
if ($failed_logins === 0) { $score += 10; } else { $deductions[] = "$failed_logins failed login attempt(s) in last 30 days"; }
if ($counts['login'] >= 3) { $score += 10; }

$score = min(100, $score);
$level = $score >= 80 ? 'Excellent' : ($score >= 60 ? 'Good' : ($score >= 40 ? 'Fair' : 'Poor'));
$level_color = $score >= 80 ? '#10b981' : ($score >= 60 ? '#5865f2' : ($score >= 40 ? '#f59e0b' : '#ef4444'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Security Audit – Vaultly</title>
<link rel="stylesheet" href="../css/app.css">
<style>
.audit-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:860px;}
@media(max-width:640px){.audit-grid{grid-template-columns:1fr;}}
.audit-card{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r5);padding:22px 24px;}
.audit-card h3{font-size:13px;font-weight:700;color:var(--t2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;}
.score-ring{display:flex;align-items:center;gap:20px;margin-bottom:16px;}
.score-num{font-size:48px;font-weight:800;line-height:1;}
.score-label{font-size:16px;font-weight:600;}
.score-desc{font-size:12px;color:var(--t3);margin-top:3px;}
.check-list{display:flex;flex-direction:column;gap:10px;}
.check-item{display:flex;align-items:center;gap:10px;font-size:13px;}
.check-item .icon{font-size:16px;flex-shrink:0;}
.check-item.pass{color:var(--t0);}
.check-item.fail{color:var(--t3);}
.stat-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--b0);font-size:13px;}
.stat-row:last-child{border-bottom:none;}
.stat-val{font-weight:700;color:var(--t0);}
.deduction-list{display:flex;flex-direction:column;gap:8px;}
.deduction-item{display:flex;align-items:flex-start;gap:8px;font-size:12.5px;color:var(--t2);background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:var(--r3);padding:10px 12px;}
.log-row{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--b0);font-size:12px;}
.log-row:last-child{border-bottom:none;}
.log-type{flex:1;color:var(--t1);font-weight:500;}
.log-ip{color:var(--t3);font-family:monospace;}
.log-time{color:var(--t4);white-space:nowrap;}
</style>
</head>
<body>
<div class="layout">
<?php $active_nav = 'security'; include '../includes/sidebar.php'; ?>
<div class="main">
    <div class="page-header">
        <div>
            <div class="page-title">🛡 Security Audit</div>
            <div class="page-subtitle">Account security overview</div>
        </div>
    </div>

    <div class="audit-grid stagger-in">

        <!-- Score card -->
        <div class="audit-card" style="border-color:<?php echo $level_color; ?>44;">
            <h3>Security Score</h3>
            <div class="score-ring">
                <div class="score-num" style="color:<?php echo $level_color; ?>"><?php echo $score; ?></div>
                <div>
                    <div class="score-label" style="color:<?php echo $level_color; ?>"><?php echo $level; ?></div>
                    <div class="score-desc">out of 100 points</div>
                </div>
            </div>
            <?php if ($deductions): ?>
            <div class="deduction-list">
                <?php foreach ($deductions as $d): ?>
                <div class="deduction-item">⚠️ <?php echo htmlspecialchars($d); ?></div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="font-size:13px;color:#10b981;">✅ No issues found</div>
            <?php endif; ?>
        </div>

        <!-- Security checks -->
        <div class="audit-card">
            <h3>Security Checks</h3>
            <div class="check-list">
                <div class="check-item <?php echo $vault_init ? 'pass' : 'fail'; ?>">
                    <span class="icon"><?php echo $vault_init ? '✅' : '❌'; ?></span>
                    Vault initialized
                </div>
                <div class="check-item <?php echo $totp_enabled ? 'pass' : 'fail'; ?>">
                    <span class="icon"><?php echo $totp_enabled ? '✅' : '❌'; ?></span>
                    Two-factor authentication (TOTP)
                    <?php if (!$totp_enabled): ?>
                    <a href="settings.php" style="font-size:11px;color:var(--a3);margin-left:4px;">Enable</a>
                    <?php endif; ?>
                </div>
                <div class="check-item <?php echo $passkey_count ? 'pass' : 'fail'; ?>">
                    <span class="icon"><?php echo $passkey_count ? '✅' : '❌'; ?></span>
                    Passkey registered (<?php echo $passkey_count; ?>)
                    <?php if (!$passkey_count): ?>
                    <a href="settings.php" style="font-size:11px;color:var(--a3);margin-left:4px;">Add</a>
                    <?php endif; ?>
                </div>
                <div class="check-item <?php echo $failed_logins === 0 ? 'pass' : 'fail'; ?>">
                    <span class="icon"><?php echo $failed_logins === 0 ? '✅' : '⚠️'; ?></span>
                    Failed logins (30 days): <?php echo $failed_logins; ?>
                </div>
                <div class="check-item <?php echo $total_items > 0 ? 'pass' : 'fail'; ?>">
                    <span class="icon"><?php echo $total_items > 0 ? '✅' : '⚪'; ?></span>
                    Vault has items (<?php echo $total_items; ?>)
                </div>
            </div>
        </div>

        <!-- Vault statistics -->
        <div class="audit-card">
            <h3>Vault Statistics</h3>
            <div class="stat-row"><span>Total items</span><span class="stat-val"><?php echo $total_items; ?></span></div>
            <div class="stat-row"><span>🔐 Logins</span><span class="stat-val"><?php echo $counts['login']; ?></span></div>
            <div class="stat-row"><span>📝 Secure notes</span><span class="stat-val"><?php echo $counts['note']; ?></span></div>
            <div class="stat-row"><span>💳 Cards</span><span class="stat-val"><?php echo $counts['card']; ?></span></div>
            <div class="stat-row"><span>👤 Identities</span><span class="stat-val"><?php echo $counts['identity']; ?></span></div>
            <div class="stat-row"><span>🔑 Passkeys</span><span class="stat-val"><?php echo $passkey_count; ?></span></div>
        </div>

        <!-- Recent activity -->
        <div class="audit-card">
            <h3>Recent Events</h3>
            <?php if ($recent_log): ?>
            <div class="log-row" style="border-bottom:1px solid var(--b1);padding-bottom:6px;margin-bottom:4px;font-size:11px;color:var(--t4);">
                <span style="flex:1">Event</span><span class="log-ip">IP</span><span class="log-time" style="margin-left:12px">Time</span>
            </div>
            <?php foreach ($recent_log as $ev): ?>
            <div class="log-row">
                <span class="log-type"><?php echo htmlspecialchars(str_replace('_', ' ', $ev['event_type'])); ?></span>
                <span class="log-ip"><?php echo htmlspecialchars($ev['ip_address']); ?></span>
                <span class="log-time" style="margin-left:12px"><?php echo date('M d H:i', strtotime($ev['created_at'])); ?></span>
            </div>
            <?php endforeach; ?>
            <div style="text-align:right;margin-top:10px;">
                <a href="view_activity.php" style="font-size:12px;color:var(--a3);">View all →</a>
            </div>
            <?php else: ?>
            <div style="font-size:13px;color:var(--t3);">No recent activity.</div>
            <?php endif; ?>
        </div>

    </div>
</div>
</div>
</body>
</html>

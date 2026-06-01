<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Handle DELETE via POST JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($body['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
    $stmt = $conn->prepare("DELETE FROM webauthn_credentials WHERE id=? AND user_id=?");
    $stmt->bind_param('ii', $id, $user_id);
    $stmt->execute();
    logSecurityEvent('passkey_deleted', $user_id, "credential id: $id");
    echo json_encode(['ok' => true]);
    exit;
}

// GET: load all passkeys for this user
$stmt = $conn->prepare(
    "SELECT id, credential_id, device_name, created_at, last_used
     FROM webauthn_credentials WHERE user_id = ? ORDER BY created_at DESC"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$passkeys = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Passkeys – Vaultly</title>
<link rel="stylesheet" href="../css/app.css">
<style>
.passkey-list{display:flex;flex-direction:column;gap:12px;max-width:720px;}
.passkey-card{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r4);padding:18px 20px;display:flex;align-items:center;gap:16px;transition:border-color var(--t);}
.passkey-card:hover{border-color:var(--b3);}
.passkey-icon{width:44px;height:44px;border-radius:var(--r3);background:var(--a-dim);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;}
.passkey-info{flex:1;min-width:0;}
.passkey-name{font-size:14px;font-weight:600;color:var(--t0);}
.passkey-meta{font-size:12px;color:var(--t3);margin-top:3px;}
.passkey-cred{font-size:11px;color:var(--t4);font-family:monospace;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:320px;}
.badge-new{display:inline-block;background:var(--a-dim);color:var(--a3);font-size:10px;font-weight:700;padding:2px 7px;border-radius:99px;margin-left:8px;letter-spacing:.4px;}
</style>
</head>
<body>
<div class="layout">
<?php $active_nav = 'passkeys'; include '../includes/sidebar.php'; ?>
<div class="main">
    <div class="page-header">
        <div>
            <div class="page-title">🔑 Passkeys</div>
            <div class="page-subtitle"><?php echo count($passkeys); ?> registered passkey<?php echo count($passkeys) !== 1 ? 's' : ''; ?></div>
        </div>
        <div class="header-right">
            <a href="../actions/settings.php#passkeys" class="btn-primary">＋ Add Passkey</a>
        </div>
    </div>

    <div class="passkey-list stagger-in">
        <?php if (count($passkeys) > 0): ?>
            <?php foreach ($passkeys as $pk):
                $isNew = (strtotime($pk['created_at']) > strtotime('-7 days'));
            ?>
            <div class="passkey-card card-glow" id="pk-<?php echo $pk['id']; ?>">
                <div class="passkey-icon">🔑</div>
                <div class="passkey-info">
                    <div class="passkey-name">
                        <?php echo htmlspecialchars($pk['device_name'] ?: 'Unnamed device'); ?>
                        <?php if ($isNew): ?><span class="badge-new">NEW</span><?php endif; ?>
                    </div>
                    <div class="passkey-meta">
                        Registered <?php echo date('M d, Y', strtotime($pk['created_at'])); ?>
                        <?php if ($pk['last_used']): ?>
                            &nbsp;·&nbsp; Last used <?php echo date('M d, Y', strtotime($pk['last_used'])); ?>
                        <?php endif; ?>
                    </div>
                    <div class="passkey-cred"><?php echo htmlspecialchars($pk['credential_id']); ?></div>
                </div>
                <button class="btn-sm btn-del" onclick="deletePasskey(<?php echo $pk['id']; ?>, this)">Remove</button>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <span class="empty-icon">🔑</span>
                <h3>No passkeys registered</h3>
                <p>Add a passkey (Windows Hello, Touch ID, security key) for passwordless login.</p>
                <a href="../actions/settings.php#passkeys" class="btn-primary">Register Passkey</a>
            </div>
        <?php endif; ?>
    </div>

    <div style="margin-top:32px;padding:20px;background:var(--s1);border:1px solid var(--b1);border-radius:var(--r4);max-width:720px;">
        <div style="font-size:13px;font-weight:700;color:var(--t1);margin-bottom:8px;">About passkeys</div>
        <p style="font-size:12.5px;color:var(--t3);line-height:1.7;margin:0;">
            Passkeys use your device's biometrics (fingerprint, face, PIN) to authenticate without a password.
            They are phishing-resistant and cannot be stolen via data breaches. Each device requires its own passkey registration.
        </p>
    </div>
</div>
</div>

<div id="toast" class="toast"></div>
<script>
function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast show ' + type;
    setTimeout(() => t.className = 'toast', 2800);
}

async function deletePasskey(id, btn) {
    if (!confirm('Remove this passkey? You will not be able to use it to log in.')) return;
    btn.disabled = true;
    try {
        const data = await (await fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        })).json();
        if (data.error) throw new Error(data.error);
        const card = document.getElementById('pk-' + id);
        card.style.opacity = '0'; card.style.transition = 'opacity .2s';
        setTimeout(() => { card.remove(); showToast('🔑 Passkey removed'); }, 200);
    } catch(e) {
        showToast('Error: ' + e.message, 'error');
        btn.disabled = false;
    }
}
</script>
</body>
</html>

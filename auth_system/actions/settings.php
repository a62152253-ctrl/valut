<?php
session_start();
include_once '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id     = (int)$_SESSION['user_id'];
$user_name   = htmlspecialchars($_SESSION['username'] ?? 'User');
$user_email  = htmlspecialchars($_SESSION['email'] ?? '');
$user_avatar = strtoupper(substr($user_name, 0, 1));

$message      = '';
$message_type = '';

getCSRFToken(); // Ensure token exists in session

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF validation (timing-safe) for all POST actions
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $message = 'Security token mismatch. Please try again.';
        $message_type = 'error';
        logSecurityEvent('csrf_violation', $user_id, 'Settings form CSRF mismatch');
    } elseif ($_POST['action'] === 'change_password') {
        // Passwords must NOT go through sanitize() — rawString() only trims whitespace
        $current = rawString($_POST['current_password'] ?? '');
        $new_pw  = rawString($_POST['new_password']     ?? '');
        $confirm = rawString($_POST['confirm_password'] ?? '');

        if (!$current || !$new_pw || !$confirm) {
            $message = 'All password fields are required.'; $message_type = 'error';
        } elseif ($new_pw !== $confirm) {
            $message = 'New passwords do not match.'; $message_type = 'error';
        } elseif (strlen($new_pw) < 8) {
            $message = 'New password must be at least 8 characters.'; $message_type = 'error';
        } else {
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row && password_verify($current, $row['password'])) {
                $hashed = password_hash($new_pw, PASSWORD_BCRYPT);
                $stmt2  = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt2->bind_param('si', $hashed, $user_id);
                $stmt2->execute();
                $stmt2->close();
                $message = 'Password updated successfully!'; $message_type = 'success';
            } else {
                $message = 'Current password is incorrect.'; $message_type = 'error';
            }
        }
    } // end change_password
} // end POST

$stmt = $conn->prepare("SELECT COUNT(*) as total FROM vault_entries WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$total_items = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT totp_enabled FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$totp_enabled = (bool)($stmt->get_result()->fetch_assoc()['totp_enabled'] ?? false);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Settings – Vaultly</title>
<link rel="stylesheet" href="../css/app.css">
<script src="../js/qrcode.min.js"></script>
<style>
/* ── Settings-specific components ─────────────────────────── */
.settings-wrap { max-width: 860px; }

.settings-card {
    background: var(--s1);
    border: 1px solid var(--b1);
    border-radius: var(--r5);
    overflow: hidden;
    margin-bottom: 18px;
    transition: border-color var(--t);
}

.settings-card-head {
    padding: 20px 24px 0;
}
.settings-card-head h2 { font-size: 15px; font-weight: 700; margin-bottom: 4px; }
.settings-card-head p  { font-size: 12.5px; color: var(--t3); margin-bottom: 20px; line-height: 1.5; }

.settings-divider { height: 1px; background: var(--b1); margin: 0 24px; }

.setting-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 17px 24px;
    gap: 20px;
    border-top: 1px solid var(--b0);
}
.setting-row:first-of-type { border-top: none; }
.setting-label h3 { font-size: 14px; font-weight: 600; margin-bottom: 3px; }
.setting-label p  { font-size: 12px; color: var(--t2); }

/* Toggle switch */
.toggle {
    width: 46px; height: 25px;
    background: var(--s3);
    border: 1px solid var(--b2);
    border-radius: 13px;
    position: relative;
    cursor: pointer;
    transition: all var(--t);
    flex-shrink: 0;
}
.toggle::after {
    content: '';
    position: absolute;
    width: 19px; height: 19px;
    background: var(--t2);
    border-radius: 50%;
    top: 2px; left: 2px;
    transition: all var(--t);
}
.toggle.on { background: linear-gradient(135deg, var(--a), var(--a2)); border-color: var(--a); }
.toggle.on::after { left: 23px; background: #fff; }

/* Password form */
.pw-form  { padding: 20px 24px; }
.pw-row   { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-group input { max-width: 380px; }

/* Strength bar */
.strength-bar {
    height: 4px; border-radius: 2px;
    background: var(--s3);
    max-width: 380px; margin-bottom: 12px; overflow: hidden;
}
.strength-fill { height: 100%; width: 0; background: var(--red); transition: all .3s; border-radius: 2px; }

/* Profile header */
.profile-header { display: flex; align-items: center; gap: 18px; padding: 24px; }
.avatar-big {
    width: 64px; height: 64px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--amber), var(--pink));
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; font-weight: 800; color: #fff; flex-shrink: 0;
    box-shadow: 0 8px 20px rgba(245,158,11,.3);
}
.profile-info h3 { font-size: 17px; font-weight: 700; letter-spacing: -.2px; }
.profile-info p  { font-size: 13px; color: var(--t2); margin-top: 3px; }

/* Export */
.export-stats { display: flex; gap: 16px; flex-wrap: wrap; padding: 18px 24px; }
.export-stat {
    background: var(--s2);
    border: 1px solid var(--b1);
    border-radius: var(--r3);
    padding: 14px 20px;
    flex: 1; min-width: 120px; text-align: center;
}
.export-stat-val { font-size: 20px; font-weight: 800; color: var(--a3); letter-spacing: -.5px; }
.export-stat-lbl { font-size: 11px; color: var(--t3); margin-top: 3px; }
.export-buttons  { display: flex; gap: 10px; padding: 0 24px 22px; flex-wrap: wrap; }

/* Passkey credential list */
.cred-row {
    display: flex; align-items: center; gap: 12px;
    padding: 13px 24px;
    border-top: 1px solid var(--b0);
    transition: background var(--t);
}
.cred-row:hover { background: rgba(255,255,255,.02); }
.cred-icon {
    width: 36px; height: 36px;
    background: var(--a-dim);
    border-radius: var(--r2);
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; flex-shrink: 0;
}
.cred-info { flex: 1; min-width: 0; }
.cred-name { font-size: 13.5px; font-weight: 600; }
.cred-meta { font-size: 11px; color: var(--t3); margin-top: 2px; }
.cred-del {
    padding: 5px 11px;
    background: var(--red-s);
    border: 1px solid rgba(239,68,68,.22);
    color: var(--red); border-radius: var(--r1);
    font-size: 11px; font-weight: 600;
    cursor: pointer; transition: all var(--t);
    font-family: inherit;
}
.cred-del:hover { background: rgba(239,68,68,.2); }

/* Danger zone */
.danger-zone { border-color: rgba(239,68,68,.2) !important; }
.danger-zone .settings-card-head h2 { color: var(--red); }

/* Buttons */
.btn {
    padding: 9px 20px; border-radius: var(--r2);
    font-size: 13px; font-weight: 600;
    cursor: pointer; border: none;
    transition: all var(--t); font-family: inherit;
}
.btn-save    { background: linear-gradient(135deg, var(--a), var(--a2)); color: #fff; box-shadow: 0 2px 10px rgba(99,102,241,.25); }
.btn-save:hover { transform: translateY(-1px); box-shadow: var(--sh-a); }
.btn-outline { background: transparent; border: 1px solid rgba(99,102,241,.3); color: var(--a3); }
.btn-outline:hover { background: var(--a-dim); }
.btn-danger  { background: var(--red-s); border: 1px solid rgba(239,68,68,.2); color: var(--red); }
.btn-danger:hover  { background: rgba(239,68,68,.2); }

/* Alert */
.alert { padding: 13px 18px; border-radius: var(--r2); margin-bottom: 22px; font-size: 13.5px; font-weight: 500; }
.alert-success { background: var(--green-s); border: 1px solid rgba(34,197,94,.3); color: var(--green); }
.alert-error   { background: var(--red-s);   border: 1px solid rgba(239,68,68,.3);  color: var(--red); }

@media (max-width: 700px) { .pw-row { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="layout">
<?php $active_nav = 'settings'; include '../includes/sidebar.php'; ?>

<div class="main">
    <div class="settings-wrap">
        <div class="page-header" style="margin-bottom:var(--s-6);">
            <div>
                <div class="page-title">Settings</div>
                <div class="page-subtitle">Manage your account, security, and vault preferences</div>
            </div>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <!-- Profile card -->
        <div class="settings-card">
            <div class="profile-header">
                <div class="avatar-big"><?php echo $user_avatar; ?></div>
                <div class="profile-info">
                    <h3><?php echo $user_name; ?></h3>
                    <p><?php echo $user_email; ?></p>
                    <p style="margin-top:6px;font-size:12px;color:var(--green);">✓ Account active</p>
                </div>
            </div>
        </div>

        <!-- Change Password -->
        <div class="settings-card">
            <div class="settings-card-head">
                <h2>🔒 Change Password</h2>
                <p>Update your master account password. This does not affect your vault encryption key.</p>
            </div>
            <div class="settings-divider"></div>
            <form class="pw-form" method="POST">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCSRFToken()); ?>">
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password"
                           placeholder="Enter current password" autocomplete="current-password">
                </div>
                <div class="pw-row">
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" id="new_password" name="new_password"
                               placeholder="Min. 8 characters" autocomplete="new-password"
                               oninput="checkStrength(this.value)">
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password"
                               placeholder="Repeat new password" autocomplete="new-password">
                    </div>
                </div>
                <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                <div id="strengthLabel" style="font-size:12px;margin-bottom:14px;"></div>
                <button type="submit" class="btn btn-save">Update Password</button>
            </form>
        </div>

        <!-- Windows Hello / Passkeys -->
        <div class="settings-card" id="passkeyCard">
            <div class="settings-card-head">
                <h2>🔑 Windows Hello &amp; Passkeys</h2>
                <p>Zaloguj się jednym kliknięciem — Windows poprosi o PIN lub hasło do konta komputera.<br>
                   Działa tylko na tym samym urządzeniu gdzie klucz został zarejestrowany.</p>
            </div>
            <div class="settings-divider"></div>

            <div id="credList">
                <div style="padding:16px 24px;font-size:13px;color:var(--t3);" id="credLoadMsg">Ładowanie…</div>
            </div>

            <div style="padding:14px 24px 20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <button class="btn btn-save" id="registerBtn" onclick="registerPasskey()">
                    ＋ Dodaj Windows Hello / PIN
                </button>
                <span style="font-size:12px;color:var(--t3);" id="pkStatus"></span>
            </div>
        </div>

        <!-- Security Preferences -->
        <div class="settings-card">
            <div class="settings-card-head">
                <h2>🛡️ Security Preferences</h2>
                <p>Configure how your vault behaves to keep your data safe.</p>
            </div>
            <div class="settings-divider"></div>
            <div class="setting-row">
                <div class="setting-label">
                    <h3>Auto-lock after inactivity</h3>
                    <p>Lock the vault after 5 minutes with no interaction</p>
                </div>
                <button class="toggle on" onclick="this.classList.toggle('on')" aria-label="Toggle auto-lock"></button>
            </div>
            <div class="setting-row">
                <div class="setting-label">
                    <h3>Two-Factor Authentication</h3>
                    <p>Require a second factor on every login &mdash;
                        <?php echo $totp_enabled ? '<strong style="color:var(--green)">Enabled</strong>' : '<strong style="color:var(--amber)">Not enabled</strong>'; ?>
                    </p>
                </div>
                <?php if ($totp_enabled): ?>
                    <button class="btn btn-secondary btn-sm" onclick="open2FADisable()">Disable 2FA</button>
                <?php else: ?>
                    <button class="btn btn-primary btn-sm" onclick="open2FASetup()">Enable 2FA</button>
                <?php endif; ?>
            </div>
            <div class="setting-row">
                <div class="setting-label">
                    <h3>Clipboard clear after copy</h3>
                    <p>Automatically clear clipboard 30 seconds after copying a password</p>
                </div>
                <button class="toggle on" onclick="this.classList.toggle('on')" aria-label="Toggle clipboard clear"></button>
            </div>
            <div class="setting-row">
                <div class="setting-label">
                    <h3>Breach monitoring alerts</h3>
                    <p>Notify when your passwords appear in known data breaches</p>
                </div>
                <button class="toggle" onclick="this.classList.toggle('on')" aria-label="Toggle breach monitoring"></button>
            </div>
        </div>

        <!-- Export -->
        <div class="settings-card">
            <div class="settings-card-head">
                <h2>📤 Export Vault</h2>
                <p>Download a copy of your vault data for backup or migration.</p>
            </div>
            <div class="settings-divider"></div>
            <div class="export-stats">
                <div class="export-stat">
                    <div class="export-stat-val"><?php echo $total_items; ?></div>
                    <div class="export-stat-lbl">Total items</div>
                </div>
                <div class="export-stat">
                    <div class="export-stat-val"><?php echo date('M d'); ?></div>
                    <div class="export-stat-lbl">Last export</div>
                </div>
                <div class="export-stat">
                    <div class="export-stat-val">AES-256</div>
                    <div class="export-stat-lbl">Encryption</div>
                </div>
            </div>
            <div class="export-buttons">
                <button class="btn btn-outline" onclick="showToast('Encrypted export (.vaultly) coming soon')">Export Encrypted</button>
                <button class="btn btn-outline" onclick="showToast('CSV export coming soon')">Export CSV</button>
                <button class="btn btn-outline" onclick="showToast('JSON export coming soon')">Export JSON</button>
            </div>
        </div>

        <!-- Danger Zone -->
        <div class="settings-card danger-zone">
            <div class="settings-card-head">
                <h2>⚠️ Danger Zone</h2>
                <p>These actions are permanent and cannot be undone.</p>
            </div>
            <div class="settings-divider"></div>
            <div class="setting-row">
                <div class="setting-label">
                    <h3>Delete All Vault Items</h3>
                    <p>Permanently removes all <?php echo $total_items; ?> items from your vault</p>
                </div>
                <button class="btn btn-danger" onclick="confirmWipe()">Wipe Vault</button>
            </div>
            <div class="setting-row">
                <div class="setting-label">
                    <h3>Delete Account</h3>
                    <p>Permanently delete your Vaultly account and all associated data</p>
                </div>
                <button class="btn btn-danger"
                        onclick="if(confirm('This cannot be undone.')) showToast('Account deletion coming soon')">
                    Delete Account
                </button>
            </div>
        </div>
    </div>
</div>
</div>

<!-- ── 2FA Setup Modal ── -->
<div id="modal2FASetup" class="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(6px);z-index:2000;align-items:center;justify-content:center;">
    <div class="modal-box" style="width:min(480px,94vw);">
        <div class="modal-head">
            <h2>Enable Two-Factor Auth</h2>
            <button class="modal-close" onclick="close2FASetup()">×</button>
        </div>

        <!-- Step 1: scan QR -->
        <div id="step-scan">
            <p style="font-size:.875rem;color:var(--t2);margin-bottom:1.25rem;line-height:1.5;">
                Scan this QR code with your authenticator app
                (<strong>Google Authenticator</strong>, <strong>Authy</strong>, <strong>1Password</strong>, etc.),
                then enter the 6-digit code to confirm.
            </p>
            <div style="text-align:center;margin-bottom:1.25rem;">
                <div id="qrLoadingSpinner" style="padding:1.5rem;color:var(--t3);font-size:.875rem;">Generating QR code…</div>
                <div id="qrCanvas" style="display:none;border-radius:12px;overflow:hidden;border:3px solid var(--b2);width:220px;height:220px;margin:0 auto;background:#fff;"></div>
            </div>
            <p style="font-size:.75rem;color:var(--t3);text-align:center;margin-bottom:1rem;">
                Or enter the secret manually:
                <button id="totpSecret" onclick="copySecret()" title="Click to copy" style="background:var(--s3);border:none;padding:2px 10px;border-radius:4px;font-size:.8rem;letter-spacing:.08em;cursor:pointer;color:var(--t1);font-family:monospace;"></button>
            </p>
            <div class="form-group">
                <label for="setupCode">Verification code from app</label>
                <input type="text" id="setupCode" inputmode="numeric" maxlength="6" placeholder="000000"
                    style="text-align:center;letter-spacing:.25em;font-size:1.25rem;font-weight:700;" autocomplete="one-time-code">
            </div>
            <div id="setup2FAErr" style="color:var(--red);font-size:.8rem;margin-bottom:.75rem;display:none;"></div>
            <div style="display:flex;gap:.75rem;">
                <button class="modal-actions" style="all:unset;cursor:pointer;flex:1;padding:.75rem;background:var(--s2);border:1px solid var(--b1);border-radius:9px;color:var(--t2);text-align:center;font-size:.875rem;" onclick="close2FASetup()">Cancel</button>
                <button class="btn btn-primary" style="flex:1;" onclick="enable2FA()">Confirm &amp; Enable</button>
            </div>
        </div>

        <!-- Step 2: backup codes -->
        <div id="step-backup" style="display:none;">
            <div style="text-align:center;margin-bottom:1rem;">
                <div style="font-size:2.5rem;margin-bottom:.5rem;">✅</div>
                <h3 style="font-size:1.1rem;font-weight:700;color:var(--green);margin-bottom:.25rem;">2FA Enabled!</h3>
                <p style="font-size:.8rem;color:var(--t2);">Save these backup codes somewhere safe. Each can only be used once.</p>
            </div>
            <div id="backupCodesList" style="background:var(--s3);border-radius:10px;padding:1rem;font-family:monospace;font-size:.875rem;line-height:2;text-align:center;letter-spacing:.08em;margin-bottom:1rem;"></div>
            <div style="display:flex;gap:.75rem;">
                <button class="btn btn-secondary" style="flex:1;" onclick="copyBackupCodes()">Copy all codes</button>
                <button class="btn btn-primary"   style="flex:1;" onclick="finish2FASetup()">Done</button>
            </div>
        </div>
    </div>
</div>

<!-- ── 2FA Disable Modal ── -->
<div id="modal2FADisable" class="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(6px);z-index:2000;align-items:center;justify-content:center;">
    <div class="modal-box" style="width:min(420px,94vw);">
        <div class="modal-head">
            <h2>Disable Two-Factor Auth</h2>
            <button class="modal-close" onclick="close2FADisable()">×</button>
        </div>
        <p style="font-size:.875rem;color:var(--t2);margin-bottom:1.25rem;line-height:1.5;">
            Enter your account password to confirm. Disabling 2FA will remove the extra security layer from your login.
        </p>
        <div class="form-group">
            <label for="disablePassword">Current password</label>
            <input type="password" id="disablePassword" placeholder="••••••••">
        </div>
        <div id="disable2FAErr" style="color:var(--red);font-size:.8rem;margin-bottom:.75rem;display:none;"></div>
        <div style="display:flex;gap:.75rem;">
            <button class="btn btn-secondary" style="flex:1;" onclick="close2FADisable()">Cancel</button>
            <button class="btn btn-danger"    style="flex:1;" onclick="disable2FA()">Disable 2FA</button>
        </div>
    </div>
</div>

<!-- ── Re-authentication modal for 2FA ── -->
<div id="modalReauth" class="modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(6px);z-index:2000;align-items:center;justify-content:center;">
    <div class="modal-box" style="width:min(380px,94vw);">
        <div class="modal-head">
            <h2>Verify Your Identity</h2>
            <button class="modal-close" onclick="closeReauth()">×</button>
        </div>
        <p style="font-size:.875rem;color:var(--t2);margin-bottom:1.25rem;line-height:1.5;">
            This is a sensitive security operation. Please enter your account password to continue.
        </p>
        <div class="form-group">
            <label for="reauthPassword">Current password</label>
            <input type="password" id="reauthPassword" placeholder="••••••••" autocomplete="current-password">
        </div>
        <div id="reauthErr" style="color:var(--red);font-size:.8rem;margin-bottom:.75rem;display:none;"></div>
        <div style="display:flex;gap:.75rem;">
            <button class="btn btn-secondary" style="flex:1;" onclick="closeReauth()">Cancel</button>
            <button class="btn btn-primary"   style="flex:1;" onclick="submitReauth()" id="reauthBtn">Verify</button>
        </div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
// ── Re-authentication ──────────────────────────────────────────────────────────
function openReauth(callback) {
    window._reauthCallback = callback;
    document.getElementById('modalReauth').style.display = 'flex';
    document.getElementById('reauthPassword').value = '';
    document.getElementById('reauthErr').style.display = 'none';
    setTimeout(() => document.getElementById('reauthPassword').focus(), 100);
}

function closeReauth() {
    document.getElementById('modalReauth').style.display = 'none';
    window._reauthCallback = null;
}

async function submitReauth() {
    const pw    = document.getElementById('reauthPassword').value;
    const errEl = document.getElementById('reauthErr');
    const btn   = document.getElementById('reauthBtn');
    errEl.style.display = 'none';
    
    if (!pw) {
        errEl.textContent = 'Please enter your password.';
        errEl.style.display = 'block';
        return;
    }
    
    btn.disabled = true;
    btn.textContent = 'Verifying…';
    
    try {
        const resp = await fetch('../api/reauth.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: pw })
        });
        const data = await resp.json();
        
        if (resp.ok && data.ok) {
            closeReauth();
            if (window._reauthCallback) {
                window._reauthCallback();
            }
        } else {
            errEl.textContent = data.error || 'Verification failed.';
            errEl.style.display = 'block';
        }
    } catch (e) {
        errEl.textContent = 'Network error: ' + e.message;
        errEl.style.display = 'block';
    } finally {
        btn.disabled = false;
        btn.textContent = 'Verify';
    }
}

document.getElementById('modalReauth')?.addEventListener('click', e => {
    if (e.target.id === 'modalReauth') closeReauth();
});

// ── Toast ──────────────────────────────────────────────────────────────────────
function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show ' + (type === 'error' ? 'error' : 'success');
    clearTimeout(window._toastT);
    window._toastT = setTimeout(() => t.className = 'toast', 2800);
}

// ── Password strength ──────────────────────────────────────────────────────────
function checkStrength(pw) {
    let s = 0;
    if (pw.length >= 8)        s++;
    if (pw.length >= 14)       s++;
    if (/[A-Z]/.test(pw))     s++;
    if (/[0-9]/.test(pw))     s++;
    if (/[^A-Za-z0-9]/.test(pw)) s++;
    const fill  = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLabel');
    const colors = ['','#ef4444','#f59e0b','#f59e0b','#22c55e','#22c55e'];
    const labels = ['','Very weak','Weak','Fair','Strong','Very strong'];
    fill.style.width      = (s / 5 * 100) + '%';
    fill.style.background = colors[s] || '#ef4444';
    label.textContent     = labels[s] || '';
    label.style.color     = colors[s] || '#ef4444';
}

// ── Danger zone ────────────────────────────────────────────────────────────────
function confirmWipe() {
    if (!confirm('This will permanently delete ALL vault items. Continue?')) return;
    if (prompt('Type WIPE to confirm:') === 'WIPE') showToast('Vault wipe coming soon');
}

// ── WebAuthn helpers ───────────────────────────────────────────────────────────
function b64u(buf) {
    return btoa(String.fromCharCode(...new Uint8Array(buf)))
        .replace(/\+/g,'-').replace(/\//g,'_').replace(/=/g,'');
}
function b64u_to_buf(s) {
    s = s.replace(/-/g,'+').replace(/_/g,'/');
    while (s.length % 4) s += '=';
    return Uint8Array.from(atob(s), c => c.charCodeAt(0)).buffer;
}
function esc(s) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
}

// ── Load registered keys ───────────────────────────────────────────────────────
async function loadCreds() {
    const el = document.getElementById('credList');
    try {
        const r = await fetch('../api/webauthn.php?action=list');
        const d = await r.json();
        if (d.error) { el.innerHTML = `<div class="cred-row"><span style="color:var(--red);font-size:13px;">${esc(d.error)}</span></div>`; return; }
        if (!d.credentials?.length) {
            el.innerHTML = '<div style="padding:14px 24px;font-size:13px;color:var(--t3);">Brak zarejestrowanych kluczy.</div>';
            return;
        }
        el.innerHTML = d.credentials.map(c => `
            <div class="cred-row" id="cred-${c.id}">
                <div class="cred-icon">🔑</div>
                <div class="cred-info">
                    <div class="cred-name">${esc(c.name)}</div>
                    <div class="cred-meta">
                        Dodano: ${c.created_at.split(' ')[0]}${c.last_used ? ' · Użyty: ' + c.last_used.split(' ')[0] : ''}
                    </div>
                </div>
                <button class="cred-del" onclick="deleteCred(${c.id})">Usuń</button>
            </div>`).join('');
    } catch(e) {
        el.innerHTML = '<div style="padding:14px 24px;font-size:12px;color:var(--red);">Błąd ładowania kluczy.</div>';
    }
}

async function deleteCred(id) {
    if (!confirm('Usunąć ten klucz Windows Hello?')) return;
    const r = await fetch('../api/webauthn.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'delete', id})
    });
    const d = await r.json();
    if (d.ok) { document.getElementById('cred-' + id)?.remove(); showToast('🗑 Klucz usunięty'); }
    else showToast(d.error || 'Błąd', 'error');
}

// ── Register passkey ───────────────────────────────────────────────────────────
async function registerPasskey() {
    if (!window.PublicKeyCredential) {
        showToast('Ta przeglądarka nie obsługuje WebAuthn / Passkeys.', 'error'); return;
    }

    const btn  = document.getElementById('registerBtn');
    const stat = document.getElementById('pkStatus');
    btn.disabled = true;
    stat.textContent = 'Pobieranie opcji…';

    try {
        // 1. Get challenge + options
        const startR = await fetch('../api/webauthn.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({action:'register_start'})
        });
        const opts = await startR.json();
        if (opts.error) { showToast(opts.error, 'error'); stat.textContent = ''; return; }

        // 2. Base64url → ArrayBuffer
        opts.challenge = b64u_to_buf(opts.challenge);
        opts.user.id   = b64u_to_buf(opts.user.id);
        if (opts.excludeCredentials?.length) {
            opts.excludeCredentials = opts.excludeCredentials.map(c =>
                ({...c, id: b64u_to_buf(c.id)}));
        }

        stat.textContent = 'Czekam na Windows Hello / PIN…';

        // 3. Trigger Windows Hello dialog
        const cred = await navigator.credentials.create({ publicKey: opts });

        const name = prompt('Nazwa klucza (możesz zmienić):', 'Windows Hello') || 'Windows Hello';
        stat.textContent = 'Weryfikacja…';

        // 4. Send to server
        const finishR = await fetch('../api/webauthn.php', {
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({
                action:            'register_finish',
                name,
                id:                b64u(cred.rawId),
                clientDataJSON:    b64u(cred.response.clientDataJSON),
                attestationObject: b64u(cred.response.attestationObject),
            })
        });
        const res = await finishR.json();

        if (res.ok) {
            showToast('✓ Klucz Windows Hello zarejestrowany!');
            await loadCreds();
        } else {
            showToast(res.error || 'Błąd rejestracji', 'error');
        }
    } catch(e) {
        if (e.name === 'NotAllowedError') showToast('Anulowano lub przekroczono czas.', 'error');
        else showToast('Błąd: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        stat.textContent = '';
    }
}

document.addEventListener('DOMContentLoaded', loadCreds);

// ── 2FA ───────────────────────────────────────────────────────────────────────
let _backupCodes = [];

function open2FASetup() {
    openReauth(() => _open2FASetupAfterReauth());
}

function _open2FASetupAfterReauth() {
    document.getElementById('modal2FASetup').style.display = 'flex';
    document.getElementById('step-scan').style.display   = 'block';
    document.getElementById('step-backup').style.display = 'none';
    document.getElementById('setupCode').value = '';
    document.getElementById('setup2FAErr').style.display = 'none';
    const qrEl = document.getElementById('qrCanvas');
    qrEl.style.display = 'none';
    qrEl.innerHTML     = '';
    document.getElementById('qrLoadingSpinner').style.display = 'block';
    fetch('../api/2fa.php?action=setup')
        .then(r => r.text())
        .then(raw => {
            let d;
            try { d = JSON.parse(raw); }
            catch (e) {
                console.error('2FA setup raw response:', raw);
                showToast('Server error — check console', 'error');
                close2FASetup();
                return;
            }
            if (!d.ok) { showToast(d.error || 'Error', 'error'); close2FASetup(); return; }
            document.getElementById('totpSecret').textContent = d.secret;
            document.getElementById('qrLoadingSpinner').style.display = 'none';
            if (typeof window.QRCode === 'undefined' || !window.QRCode) {
                qrEl.style.display = 'block';
                qrEl.innerHTML = '<p style="color:#ef4444;font-size:.8rem;padding:1rem;">QR library failed to load.<br>Enter the secret manually below.</p>';
                return;
            }
            try {
                qrEl.innerHTML = '';
                new window.QRCode(qrEl, {
                    text:         d.otpauth_uri,
                    width:        214,
                    height:       214,
                    colorDark:    '#000000',
                    colorLight:   '#ffffff',
                    correctLevel: window.QRCode.CorrectLevel.M,
                });
                qrEl.style.display = 'block';
            } catch (e) {
                console.error('QRCode error:', e);
                qrEl.style.display = 'block';
                qrEl.innerHTML = '<p style="color:#ef4444;font-size:.8rem;padding:1rem;">QR render failed.<br>Enter the secret manually.</p>';
            }
        })
        .catch(e => {
            console.error('2FA setup fetch error:', e);
            showToast('Network error: ' + e.message, 'error');
            close2FASetup();
        });
}

function close2FASetup() {
    document.getElementById('modal2FASetup').style.display = 'none';
}

function copySecret() {
    const t = document.getElementById('totpSecret').textContent;
    navigator.clipboard.writeText(t).then(() => showToast('Secret copied!'));
}

async function enable2FA() {
    const code = document.getElementById('setupCode').value.trim();
    const errEl = document.getElementById('setup2FAErr');
    errEl.style.display = 'none';
    if (!/^\d{6}$/.test(code)) { errEl.textContent = 'Enter a 6-digit code.'; errEl.style.display = 'block'; return; }
    const fd = new FormData();
    fd.append('action', 'enable');
    fd.append('code', code);
    const r = await fetch('../api/2fa.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) { errEl.textContent = d.error || 'Failed.'; errEl.style.display = 'block'; return; }
    _backupCodes = d.backup_codes;
    document.getElementById('step-scan').style.display   = 'none';
    document.getElementById('step-backup').style.display = 'block';
    document.getElementById('backupCodesList').innerHTML  = _backupCodes.join('<br>');
}

function copyBackupCodes() {
    navigator.clipboard.writeText(_backupCodes.join('\n')).then(() => showToast('Backup codes copied!'));
}

function finish2FASetup() {
    close2FASetup();
    showToast('2FA enabled! 🔐');
    setTimeout(() => location.reload(), 1000);
}

function open2FADisable() {
    openReauth(() => _open2FADisableAfterReauth());
}

function _open2FADisableAfterReauth() {
    document.getElementById('modal2FADisable').style.display = 'flex';
    document.getElementById('disablePassword').value = '';
    document.getElementById('disable2FAErr').style.display = 'none';
    setTimeout(() => document.getElementById('disablePassword').focus(), 100);
}

function close2FADisable() {
    document.getElementById('modal2FADisable').style.display = 'none';
}

async function disable2FA() {
    const pw    = document.getElementById('disablePassword').value;
    const errEl = document.getElementById('disable2FAErr');
    errEl.style.display = 'none';
    if (!pw) { errEl.textContent = 'Enter your password.'; errEl.style.display = 'block'; return; }
    const fd = new FormData();
    fd.append('action', 'disable');
    fd.append('password', pw);
    const r = await fetch('../api/2fa.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) { errEl.textContent = d.error || 'Failed.'; errEl.style.display = 'block'; return; }
    close2FADisable();
    showToast('2FA disabled.');
    setTimeout(() => location.reload(), 800);
}

// Close modals on backdrop click
['modal2FASetup', 'modal2FADisable'].forEach(id => {
    document.getElementById(id).addEventListener('click', e => {
        if (e.target.id === id) document.getElementById(id).style.display = 'none';
    });
});
</script>
</body>
</html>

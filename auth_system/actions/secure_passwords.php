<?php
session_start();
include_once '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id     = (int) $_SESSION['user_id'];
$user_name   = htmlspecialchars($_SESSION['username'] ?? 'User');
$user_email  = htmlspecialchars($_SESSION['email']    ?? '');
$user_avatar = strtoupper(substr($user_name, 0, 1));

// 10-minute re-auth window
define('UNLOCK_TTL', 600);
$unlocked_at = $_SESSION['passwords_unlocked_at'] ?? 0;
$is_locked   = (time() - $unlocked_at) > UNLOCK_TTL;

// Check if user has any passkeys registered
$has_passkey = false;
$stmt = $conn->prepare("SELECT COUNT(*) as n FROM webauthn_credentials WHERE user_id = ?");
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        $has_passkey = (int)($res->fetch_assoc()['n'] ?? 0) > 0;
        $res->free();
    }
    $stmt->close();
}

// Fetch entries only when unlocked (never expose to locked page)
$entries = [];
if (!$is_locked) {
    $stmt = $conn->prepare(
        "SELECT uuid, encrypted_data, favorite, created_at, updated_at
         FROM vault_entries WHERE user_id = ? AND type = 'login' ORDER BY updated_at DESC"
    );
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $entries = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Secure Passwords – Vaultly</title>
<link rel="stylesheet" href="../css/app.css">
<style>
/* ── Lock screen ─────────────────────────────────────────────────── */
.lock-screen {
    position: fixed;
    inset: 0;
    background: linear-gradient(135deg, #080c17 0%, #0d1425 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 500;
    padding: 1rem;
}

.lock-card {
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 20px;
    padding: 2.5rem 2rem;
    max-width: 400px;
    width: 100%;
    text-align: center;
    box-shadow: 0 32px 80px rgba(0,0,0,.5);
    animation: lockIn .3s cubic-bezier(.34,1.56,.64,1);
}

@keyframes lockIn {
    from { transform: translateY(20px) scale(.97); opacity: 0; }
    to   { transform: translateY(0) scale(1);    opacity: 1; }
}

.lock-icon {
    width: 72px;
    height: 72px;
    background: linear-gradient(135deg, rgba(59,130,246,.2), rgba(139,92,246,.2));
    border: 1px solid rgba(59,130,246,.3);
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 1.5rem;
    color: #60a5fa;
}

.lock-title {
    font-size: 1.3rem;
    font-weight: 700;
    color: #f1f5f9;
    margin-bottom: .4rem;
}

.lock-sub {
    font-size: .85rem;
    color: #64748b;
    line-height: 1.5;
    margin-bottom: 2rem;
}

.lock-btn-hello {
    width: 100%;
    padding: .875rem;
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    border: none;
    border-radius: 12px;
    color: #fff;
    font-size: .9rem;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .625rem;
    transition: transform .2s, box-shadow .2s;
    margin-bottom: .75rem;
    font-family: inherit;
}

.lock-btn-hello:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 10px 28px rgba(59,130,246,.35);
}

.lock-btn-hello:disabled { opacity: .6; cursor: not-allowed; }

.lock-divider {
    display: flex;
    align-items: center;
    gap: .75rem;
    margin: 1rem 0;
    color: #334155;
    font-size: .75rem;
}
.lock-divider::before,
.lock-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: rgba(255,255,255,.06);
}

.lock-pw-row {
    display: flex;
    gap: .5rem;
}

.lock-pw-row input {
    flex: 1;
    padding: .75rem 1rem;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 10px;
    color: #f1f5f9;
    font-size: .875rem;
    outline: none;
    transition: border-color .2s;
    font-family: inherit;
}

.lock-pw-row input:focus { border-color: rgba(59,130,246,.5); }

.lock-pw-btn {
    padding: .75rem 1rem;
    background: rgba(59,130,246,.15);
    border: 1px solid rgba(59,130,246,.3);
    border-radius: 10px;
    color: #60a5fa;
    font-size: .875rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .2s;
    white-space: nowrap;
    font-family: inherit;
}

.lock-pw-btn:hover { background: rgba(59,130,246,.25); }

.lock-err {
    background: rgba(239,68,68,.1);
    border: 1px solid rgba(239,68,68,.2);
    color: #f87171;
    border-radius: 8px;
    padding: .625rem .875rem;
    font-size: .8rem;
    margin-top: .75rem;
    display: none;
    text-align: left;
}

.lock-err.show { display: block; }

.lock-msg {
    font-size: .75rem;
    color: #475569;
    margin-top: .875rem;
    display: none;
}

/* ── Vault content ───────────────────────────────────────────────── */
.vault-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.vault-lock-btn {
    display: flex;
    align-items: center;
    gap: .5rem;
    padding: .5rem .875rem;
    background: rgba(239,68,68,.08);
    border: 1px solid rgba(239,68,68,.18);
    border-radius: 8px;
    color: #ef4444;
    font-size: .8rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .2s;
    font-family: inherit;
}

.vault-lock-btn:hover { background: rgba(239,68,68,.15); }

.session-badge {
    display: inline-flex;
    align-items: center;
    gap: .375rem;
    font-size: .75rem;
    color: #22c55e;
    background: rgba(34,197,94,.08);
    border: 1px solid rgba(34,197,94,.18);
    padding: .25rem .75rem;
    border-radius: 20px;
}

.session-dot {
    width: 7px;
    height: 7px;
    background: #22c55e;
    border-radius: 50%;
    animation: sdot 2s infinite;
}
@keyframes sdot { 0%,100%{opacity:1} 50%{opacity:.3} }

/* ── Password cards ──────────────────────────────────────────────── */
.pw-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1rem;
}

.pw-card {
    background: linear-gradient(135deg, rgba(255,255,255,.05), rgba(255,255,255,.02));
    border: 1px solid rgba(255,255,255,.07);
    border-radius: 14px;
    padding: 1.25rem;
    transition: border-color .2s, transform .2s;
    position: relative;
}

.pw-card:hover {
    border-color: rgba(255,255,255,.14);
    transform: translateY(-2px);
}

.pw-card-top {
    display: flex;
    align-items: center;
    gap: .875rem;
    margin-bottom: 1rem;
}

.pw-site-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.1rem;
    color: #fff;
    flex-shrink: 0;
}

.pw-title {
    font-size: .95rem;
    font-weight: 700;
    color: #f1f5f9;
}

.pw-username {
    font-size: .78rem;
    color: #64748b;
    margin-top: .1rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.pw-field-row {
    display: flex;
    align-items: center;
    gap: .5rem;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.06);
    border-radius: 8px;
    padding: .5rem .75rem;
    margin-bottom: .5rem;
}

.pw-field-label {
    font-size: .68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #334155;
    width: 60px;
    flex-shrink: 0;
}

.pw-field-val {
    flex: 1;
    font-size: .85rem;
    color: #94a3b8;
    font-family: 'Courier New', monospace;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
}

.pw-field-val.revealed { color: #e2e8f0; }

.pw-field-actions {
    display: flex;
    gap: .25rem;
    flex-shrink: 0;
}

.pw-icon-btn {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    border: none;
    background: rgba(255,255,255,.06);
    color: #64748b;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background .15s, color .15s;
    font-family: inherit;
}

.pw-icon-btn:hover { background: rgba(59,130,246,.15); color: #60a5fa; }

.pw-card-footer {
    display: flex;
    gap: .5rem;
    margin-top: .875rem;
    padding-top: .875rem;
    border-top: 1px solid rgba(255,255,255,.05);
}

.pw-action-btn {
    flex: 1;
    padding: .45rem;
    border-radius: 7px;
    border: 1px solid rgba(255,255,255,.07);
    background: transparent;
    font-size: .75rem;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s, color .15s;
    font-family: inherit;
    color: #64748b;
}

.pw-action-btn.edit  { border-color: rgba(99,102,241,.25); color: #818cf8; }
.pw-action-btn.edit:hover  { background: rgba(99,102,241,.12); }
.pw-action-btn.del   { border-color: rgba(239,68,68,.2);  color: #f87171; }
.pw-action-btn.del:hover   { background: rgba(239,68,68,.1); }
.pw-action-btn.copy  { border-color: rgba(34,197,94,.2);  color: #4ade80; }
.pw-action-btn.copy:hover  { background: rgba(34,197,94,.1); }

.fav-pill {
    position: absolute;
    top: .875rem;
    right: .875rem;
    background: none;
    border: none;
    font-size: 1rem;
    cursor: pointer;
    padding: .25rem;
    border-radius: 6px;
    transition: background .15s;
    color: #475569;
}
.fav-pill.active { color: #f59e0b; }
.fav-pill:hover  { background: rgba(255,255,255,.06); }

/* ── Add/Edit modal ──────────────────────────────────────────────── */
.pw-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.65);
    backdrop-filter: blur(6px);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.pw-modal-overlay.open { display: flex; }

.pw-modal-box {
    background: #111827;
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 18px;
    width: min(480px, 100%);
    padding: 2rem;
    position: relative;
    animation: slideUp .22s ease;
}

@keyframes slideUp {
    from { transform: translateY(14px); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}

.pw-modal-title {
    font-size: 1.15rem;
    font-weight: 700;
    color: #f1f5f9;
    margin-bottom: 1.5rem;
}

.pw-modal-close {
    position: absolute;
    top: 1.25rem;
    right: 1.25rem;
    width: 30px;
    height: 30px;
    background: rgba(255,255,255,.07);
    border: none;
    border-radius: 7px;
    color: #94a3b8;
    font-size: 1.1rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background .15s;
}
.pw-modal-close:hover { background: rgba(255,255,255,.12); }

.pw-form-group { margin-bottom: .875rem; }

.pw-form-group label {
    display: block;
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #64748b;
    margin-bottom: .4rem;
}

.pw-form-group input,
.pw-form-group textarea {
    width: 100%;
    padding: .7rem 1rem;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 9px;
    color: #f1f5f9;
    font-size: .875rem;
    outline: none;
    transition: border-color .2s;
    box-sizing: border-box;
    font-family: inherit;
}

.pw-form-group input:focus,
.pw-form-group textarea:focus { border-color: rgba(59,130,246,.5); }

.pw-form-group textarea { resize: vertical; min-height: 72px; }

.pw-input-row {
    display: flex;
    gap: .5rem;
    align-items: center;
}

.pw-input-row input { flex: 1; }

.pw-gen-btn {
    padding: .7rem .875rem;
    background: rgba(139,92,246,.15);
    border: 1px solid rgba(139,92,246,.3);
    border-radius: 9px;
    color: #a78bfa;
    font-size: .8rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    transition: background .15s;
    font-family: inherit;
}
.pw-gen-btn:hover { background: rgba(139,92,246,.25); }

.pw-modal-actions {
    display: flex;
    gap: .75rem;
    margin-top: 1.25rem;
}

.pw-save-btn {
    flex: 1;
    padding: .75rem;
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    border: none;
    border-radius: 9px;
    color: #fff;
    font-size: .875rem;
    font-weight: 700;
    cursor: pointer;
    transition: transform .2s, box-shadow .2s;
    font-family: inherit;
}
.pw-save-btn:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(59,130,246,.3); }

.pw-cancel-btn {
    flex: 1;
    padding: .75rem;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 9px;
    color: #94a3b8;
    font-size: .875rem;
    cursor: pointer;
    transition: background .15s;
    font-family: inherit;
}
.pw-cancel-btn:hover { background: rgba(255,255,255,.09); }

/* ── Search ──────────────────────────────────────────────────────── */
.vault-search {
    display: flex;
    align-items: center;
    gap: .625rem;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 10px;
    padding: .6rem 1rem;
    max-width: 300px;
    transition: border-color .2s;
}
.vault-search:focus-within { border-color: rgba(59,130,246,.4); }
.vault-search svg { color: #475569; flex-shrink: 0; }
.vault-search input {
    background: none;
    border: none;
    outline: none;
    color: #e2e8f0;
    font-size: .875rem;
    flex: 1;
    font-family: inherit;
}
.vault-search input::placeholder { color: #475569; }

.add-pw-btn {
    display: flex;
    align-items: center;
    gap: .5rem;
    padding: .625rem 1.125rem;
    background: linear-gradient(135deg, #3b82f6, #8b5cf6);
    border: none;
    border-radius: 9px;
    color: #fff;
    font-size: .875rem;
    font-weight: 600;
    cursor: pointer;
    transition: transform .2s, box-shadow .2s;
    font-family: inherit;
}
.add-pw-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(59,130,246,.3); }

/* ── Strength bar ────────────────────────────────────────────────── */
.strength-wrap { height: 3px; background: rgba(255,255,255,.06); border-radius: 2px; margin-top: .375rem; }
.strength-fill { height: 100%; border-radius: 2px; transition: width .3s, background .3s; }

/* ── Empty state ─────────────────────────────────────────────────── */
.vault-empty {
    grid-column: 1 / -1;
    text-align: center;
    padding: 4rem 1rem;
    color: #334155;
}
.vault-empty svg { margin-bottom: 1rem; opacity: .3; }
.vault-empty h3 { font-size: 1.1rem; font-weight: 600; margin-bottom: .5rem; color: #475569; }
.vault-empty p  { font-size: .875rem; }
</style>
</head>
<body>
<div class="layout">
<?php $active_nav = 'secure_passwords'; $is_root = false; include_once '../includes/sidebar.php'; ?>

<div class="main">

<?php if ($is_locked): ?>
<!-- ═══════════════ LOCK SCREEN ═══════════════ -->
<div class="lock-screen" id="lockScreen">
    <div class="lock-card">
        <div class="lock-icon">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <rect x="3" y="11" width="18" height="11" rx="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                <circle cx="12" cy="16" r="1.5" fill="currentColor" stroke="none"/>
            </svg>
        </div>
        <div class="lock-title">Secure Passwords</div>
        <div class="lock-sub">
            This area is protected. Verify your identity to access your stored passwords.
            <br>Session expires after 10 minutes of inactivity.
        </div>

        <?php if ($has_passkey): ?>
        <button class="lock-btn-hello" id="helloBtn" onclick="verifyWithHello()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                <circle cx="12" cy="16" r="1" fill="currentColor" stroke="none"/>
            </svg>
            Verify with Windows Hello / PIN
        </button>
        <div id="helloMsg" class="lock-msg"></div>
        <div class="lock-divider">or use account password</div>
        <?php endif; ?>

        <div class="lock-pw-row">
            <input type="password" id="lockPw" placeholder="Account password" autocomplete="current-password"
                   onkeydown="if(event.key==='Enter') verifyWithPassword()">
            <button class="lock-pw-btn" onclick="verifyWithPassword()">Unlock</button>
        </div>
        <div id="lockErr" class="lock-err"></div>
    </div>
</div>

<?php else: ?>
<!-- ═══════════════ VAULT CONTENT ═══════════════ -->
<div class="page-header">
    <div>
        <div class="page-title">Passwords</div>
        <div class="page-subtitle">
            <span class="session-badge">
                <span class="session-dot"></span>
                Session unlocked · auto-locks in <span id="ttlDisplay">10:00</span>
            </span>
        </div>
    </div>
    <div class="header-right" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
        <div class="vault-search">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="searchInput" placeholder="Search…" oninput="filterCards()">
        </div>
        <button class="add-pw-btn" onclick="openAddModal()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add password
        </button>
        <button class="vault-lock-btn" onclick="lockVault()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Lock
        </button>
    </div>
</div>

<div class="pw-grid" id="pwGrid">
    <?php if (empty($entries)): ?>
    <div class="vault-empty">
        <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <h3>No passwords yet</h3>
        <p>Click "Add password" to store your first login.</p>
    </div>
    <?php else: ?>
    <?php foreach ($entries as $e):
        $d     = json_decode($e['encrypted_data'], true) ?? [];
        $title = htmlspecialchars($d['title']    ?? 'Untitled');
        $uname = htmlspecialchars($d['username'] ?? '');
        $pw    = htmlspecialchars($d['password'] ?? '', ENT_QUOTES);
        $url   = htmlspecialchars($d['url']      ?? '');
        $notes = htmlspecialchars($d['notes']    ?? '');
        $icon  = strtoupper(substr(strip_tags($d['title'] ?? '?'), 0, 1)) ?: '?';
        $uuid  = $e['uuid'];
        $fav   = (bool)$e['favorite'];
        $updated = date('M j, Y', strtotime($e['updated_at']));
    ?>
    <div class="pw-card" data-uuid="<?php echo $uuid; ?>"
         data-search="<?php echo strtolower($title . ' ' . $uname . ' ' . $url); ?>">

        <button class="fav-pill <?php echo $fav ? 'active' : ''; ?>"
                onclick="toggleFav('<?php echo $uuid; ?>', this)" title="Favourite">
            <?php echo $fav ? '⭐' : '☆'; ?>
        </button>

        <div class="pw-card-top">
            <div class="pw-site-icon"><?php echo $icon; ?></div>
            <div style="min-width:0;">
                <div class="pw-title"><?php echo $title; ?></div>
                <?php if ($url): ?>
                <div class="pw-username"><?php echo $url; ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($uname): ?>
        <div class="pw-field-row">
            <div class="pw-field-label">User</div>
            <div class="pw-field-val"><?php echo $uname; ?></div>
            <div class="pw-field-actions">
                <button class="pw-icon-btn" onclick="copyToClipboard('<?php echo htmlspecialchars($uname, ENT_QUOTES); ?>', this)" title="Copy username">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <div class="pw-field-row">
            <div class="pw-field-label">Pass</div>
            <div class="pw-field-val" id="pw-val-<?php echo $uuid; ?>" data-pw="<?php echo $pw; ?>">••••••••••••</div>
            <div class="pw-field-actions">
                <button class="pw-icon-btn" onclick="toggleReveal('<?php echo $uuid; ?>', this)" title="Show/hide password">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
                <button class="pw-icon-btn" onclick="copyPw('<?php echo $uuid; ?>')" title="Copy password">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                </button>
            </div>
        </div>

        <div class="pw-card-footer">
            <button class="pw-action-btn copy" onclick="copyPw('<?php echo $uuid; ?>')">Copy password</button>
            <button class="pw-action-btn edit" onclick='openEditModal(<?php echo json_encode([
                'uuid' => $uuid, 'title' => $d['title'] ?? '', 'username' => $d['username'] ?? '',
                'password' => $d['password'] ?? '', 'url' => $d['url'] ?? '', 'notes' => $d['notes'] ?? ''
            ]); ?>)'>Edit</button>
            <button class="pw-action-btn del" onclick="deleteEntry('<?php echo $uuid; ?>', this)">Delete</button>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

</div><!-- .main -->
</div><!-- .layout -->

<!-- ═══════════════ ADD / EDIT MODAL ═══════════════ -->
<div id="pwModal" class="pw-modal-overlay">
    <div class="pw-modal-box">
        <button class="pw-modal-close" onclick="closeModal()">×</button>
        <div class="pw-modal-title" id="modalTitle">Add password</div>

        <div class="pw-form-group">
            <label for="fTitle">Site / App name</label>
            <input type="text" id="fTitle" placeholder="e.g. Gmail, GitHub" autocomplete="off">
        </div>
        <div class="pw-form-group">
            <label for="fUrl">Website URL</label>
            <input type="url" id="fUrl" placeholder="https://example.com" autocomplete="off">
        </div>
        <div class="pw-form-group">
            <label for="fUser">Username / Email</label>
            <input type="text" id="fUser" placeholder="you@example.com" autocomplete="off">
        </div>
        <div class="pw-form-group">
            <label for="fPw">Password</label>
            <div class="pw-input-row">
                <input type="password" id="fPw" placeholder="••••••••" autocomplete="new-password" oninput="checkStrength(this.value)">
                <button type="button" class="pw-icon-btn" onclick="toggleModalPw()" title="Show/hide" style="width:36px;height:36px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
                <button type="button" class="pw-gen-btn" onclick="generatePassword()">Generate</button>
            </div>
            <div class="strength-wrap"><div class="strength-fill" id="strengthFill" style="width:0"></div></div>
            <div style="font-size:.72rem;color:#475569;margin-top:.2rem;" id="strengthLabel"></div>
        </div>
        <div class="pw-form-group">
            <label for="fNotes">Notes <span style="color:#334155;font-weight:400;">(optional)</span></label>
            <textarea id="fNotes" placeholder="Recovery codes, hints…"></textarea>
        </div>

        <input type="hidden" id="editUuid" value="">
        <div class="pw-modal-actions">
            <button class="pw-save-btn" onclick="saveEntry()" id="saveBtn">Save</button>
            <button class="pw-cancel-btn" onclick="closeModal()">Cancel</button>
        </div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
const HAS_PASSKEY = <?php echo json_encode($has_passkey); ?>;
const IS_LOCKED   = <?php echo json_encode($is_locked); ?>;

// ── Toast ─────────────────────────────────────────────────────────────────────
function toast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = 'toast show ' + type;
    clearTimeout(t._t);
    t._t = setTimeout(() => t.className = 'toast', 2800);
}

// ── Session TTL countdown ─────────────────────────────────────────────────────
<?php if (!$is_locked): ?>
(function() {
    const expires = <?php echo $unlocked_at + UNLOCK_TTL; ?>;
    const el = document.getElementById('ttlDisplay');
    function tick() {
        const left = expires - Math.floor(Date.now() / 1000);
        if (left <= 0) { lockVault(); return; }
        const m = String(Math.floor(left / 60)).padStart(2, '0');
        const s = String(left % 60).padStart(2, '0');
        if (el) el.textContent = m + ':' + s;
        setTimeout(tick, 1000);
    }
    tick();
})();
<?php endif; ?>

// ── Lock / unlock ─────────────────────────────────────────────────────────────
async function lockVault() {
    await fetch('../api/reauth.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=lock'
    });
    location.reload();
}

// ── Windows Hello ─────────────────────────────────────────────────────────────
function b64uDec(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    return Uint8Array.from(atob(s), c => c.charCodeAt(0)).buffer;
}
function b64uEnc(buf) {
    return btoa(String.fromCharCode(...new Uint8Array(buf)))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

async function verifyWithHello() {
    if (!window.PublicKeyCredential) {
        showLockErr('This browser does not support WebAuthn.'); return;
    }
    const btn = document.getElementById('helloBtn');
    const msg = document.getElementById('helloMsg');
    btn.disabled = true;
    msg.style.display = 'block';
    msg.textContent   = 'Waiting for Windows Hello…';

    try {
        const startR = await fetch('../api/reauth.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=webauthn_start'
        });
        const opts = await startR.json();
        if (!opts.ok) { showLockErr(opts.error); return; }

        opts.challenge = b64uDec(opts.challenge);
        if (opts.allowCredentials?.length) {
            opts.allowCredentials = opts.allowCredentials.map(c => ({...c, id: b64uDec(c.id)}));
        }

        const assertion = await navigator.credentials.get({ publicKey: opts });

        const finishR = await fetch('../api/reauth.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action:            'webauthn_finish',
                id:                b64uEnc(assertion.rawId),
                clientDataJSON:    b64uEnc(assertion.response.clientDataJSON),
                authenticatorData: b64uEnc(assertion.response.authenticatorData),
                signature:         b64uEnc(assertion.response.signature),
            })
        });
        const result = await finishR.json();
        if (result.ok) { location.reload(); }
        else { showLockErr(result.error || 'Verification failed.'); }
    } catch(e) {
        if (e.name === 'NotAllowedError') showLockErr('Cancelled or timed out.');
        else showLockErr('Error: ' + e.message);
    } finally {
        btn.disabled = false;
        if (msg) msg.textContent = '';
    }
}

async function verifyWithPassword() {
    const pw  = document.getElementById('lockPw').value;
    const err = document.getElementById('lockErr');
    err.classList.remove('show');
    if (!pw) { showLockErr('Enter your account password.'); return; }

    const fd = new FormData();
    fd.append('action',   'password');
    fd.append('password', pw);
    const r = await fetch('../api/reauth.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.ok) { location.reload(); }
    else { showLockErr(d.error || 'Incorrect password.'); }
}

function showLockErr(msg) {
    const el = document.getElementById('lockErr');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('show');
}

document.getElementById('lockPw')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') verifyWithPassword();
});

// ── Reveal password ───────────────────────────────────────────────────────────
const _revealTimers = {};
function toggleReveal(uuid, btn) {
    const el = document.getElementById('pw-val-' + uuid);
    if (!el) return;
    if (el.dataset.shown === '1') {
        el.textContent = '••••••••••••';
        el.classList.remove('revealed');
        el.dataset.shown = '';
        clearTimeout(_revealTimers[uuid]);
    } else {
        el.textContent = el.dataset.pw || '(empty)';
        el.classList.add('revealed');
        el.dataset.shown = '1';
        // Auto-hide after 15 seconds
        clearTimeout(_revealTimers[uuid]);
        _revealTimers[uuid] = setTimeout(() => {
            el.textContent = '••••••••••••';
            el.classList.remove('revealed');
            el.dataset.shown = '';
        }, 15000);
    }
}

function copyPw(uuid) {
    const el = document.getElementById('pw-val-' + uuid);
    if (!el) return;
    const pw = el.dataset.pw || '';
    if (!pw) { toast('No password stored', 'error'); return; }
    navigator.clipboard.writeText(pw).then(() => {
        toast('✓ Password copied — will clear in 30s');
        setTimeout(() => navigator.clipboard.writeText('').catch(() => {}), 30000);
    });
}

function copyToClipboard(text, btn) {
    navigator.clipboard.writeText(text).then(() => toast('✓ Copied'));
}

// ── Toggle favourite ──────────────────────────────────────────────────────────
async function toggleFav(uuid, btn) {
    const fd = new FormData();
    fd.append('uuid', uuid);
    const r = await fetch('toggle_favorite.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) {
        const on = btn.classList.toggle('active');
        btn.textContent = on ? '⭐' : '☆';
    }
}

// ── Search ────────────────────────────────────────────────────────────────────
function filterCards() {
    const q = document.getElementById('searchInput')?.value.toLowerCase() || '';
    document.querySelectorAll('.pw-card').forEach(c => {
        c.style.display = c.dataset.search?.includes(q) ? '' : 'none';
    });
}

// ── Delete ────────────────────────────────────────────────────────────────────
async function deleteEntry(uuid, btn) {
    if (!confirm('Delete this password? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append('uuid', uuid);
    const r = await fetch('delete_item.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) {
        const card = document.querySelector(`[data-uuid="${uuid}"]`);
        if (card) {
            card.style.transition = 'all .22s';
            card.style.opacity = '0';
            card.style.transform = 'scale(.96)';
            setTimeout(() => card.remove(), 220);
        }
        toast('🗑 Deleted');
    } else { toast('Error: ' + d.error, 'error'); }
}

// ── Modal ─────────────────────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('editUuid').value = '';
    document.getElementById('modalTitle').textContent = 'Add password';
    document.getElementById('saveBtn').textContent = 'Save';
    ['fTitle','fUrl','fUser','fPw','fNotes'].forEach(id => {
        document.getElementById(id).value = '';
    });
    document.getElementById('strengthFill').style.width = '0';
    document.getElementById('strengthLabel').textContent = '';
    document.getElementById('pwModal').classList.add('open');
    setTimeout(() => document.getElementById('fTitle').focus(), 80);
}

function openEditModal(data) {
    document.getElementById('editUuid').value = data.uuid;
    document.getElementById('modalTitle').textContent = 'Edit password';
    document.getElementById('saveBtn').textContent = 'Save changes';
    document.getElementById('fTitle').value = data.title    || '';
    document.getElementById('fUrl').value   = data.url      || '';
    document.getElementById('fUser').value  = data.username || '';
    document.getElementById('fPw').value    = data.password || '';
    document.getElementById('fNotes').value = data.notes    || '';
    checkStrength(data.password || '');
    document.getElementById('pwModal').classList.add('open');
    setTimeout(() => document.getElementById('fTitle').focus(), 80);
}

function closeModal() { document.getElementById('pwModal').classList.remove('open'); }

document.getElementById('pwModal').addEventListener('click', e => {
    if (e.target === document.getElementById('pwModal')) closeModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

// ── Save ──────────────────────────────────────────────────────────────────────
async function saveEntry() {
    const uuid  = document.getElementById('editUuid').value;
    const title = document.getElementById('fTitle').value.trim();
    if (!title) { toast('Title is required', 'error'); return; }

    const fd = new FormData();
    fd.append('type',     'login');
    fd.append('title',    title);
    fd.append('url',      document.getElementById('fUrl').value.trim());
    fd.append('username', document.getElementById('fUser').value.trim());
    fd.append('password', document.getElementById('fPw').value);
    fd.append('notes',    document.getElementById('fNotes').value.trim());
    if (uuid) fd.append('uuid', uuid);

    const url = uuid ? 'update_item.php' : 'add_password.php';
    const r   = await fetch(url, { method: 'POST', body: fd });
    const d   = await r.json();

    if (d.success) {
        toast(uuid ? '✓ Updated' : '✓ Password saved');
        closeModal();
        setTimeout(() => location.reload(), 700);
    } else { toast('Error: ' + (d.error || 'Unknown'), 'error'); }
}

// ── Password generator ────────────────────────────────────────────────────────
function generatePassword() {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}';
    const arr   = crypto.getRandomValues(new Uint32Array(20));
    const pw    = Array.from(arr).map(n => chars[n % chars.length]).join('');
    const input = document.getElementById('fPw');
    input.value = pw;
    input.type  = 'text';
    checkStrength(pw);
    setTimeout(() => input.type = 'password', 2000);
}

function toggleModalPw() {
    const i = document.getElementById('fPw');
    i.type = i.type === 'password' ? 'text' : 'password';
}

// ── Strength checker ──────────────────────────────────────────────────────────
function checkStrength(pw) {
    let s = 0;
    if (pw.length >= 8)               s++;
    if (pw.length >= 14)              s++;
    if (/[A-Z]/.test(pw))            s++;
    if (/[0-9]/.test(pw))            s++;
    if (/[^A-Za-z0-9]/.test(pw))    s++;
    const pct    = (s / 5) * 100;
    const colors = ['#ef4444','#f97316','#f59e0b','#84cc16','#22c55e'];
    const labels = ['Very weak','Weak','Fair','Good','Strong'];
    const fill   = document.getElementById('strengthFill');
    const label  = document.getElementById('strengthLabel');
    fill.style.width      = pct + '%';
    fill.style.background = colors[s - 1] || '#334155';
    label.textContent     = pw ? labels[s - 1] || '' : '';
    label.style.color     = colors[s - 1] || '#475569';
}
</script>
</body>
</html>

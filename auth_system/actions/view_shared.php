<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_name = htmlspecialchars($_SESSION['username'] ?? 'User');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sharing – Vaultly</title>
<link rel="stylesheet" href="../css/app.css">
<script src="../js/crypto-engine.js"></script>
<style>
.tabs { display:flex; gap:2px; margin-bottom:24px; border-bottom:1px solid var(--b1); padding-bottom:0; flex-wrap:wrap; }
.tab-btn { padding:10px 18px; background:none; border:none; color:var(--t3); font-size:13.5px; font-family:inherit; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-1px; transition:all var(--t); white-space:nowrap; }
.tab-btn:hover { color:var(--t1); }
.tab-btn.active { color:var(--a3); border-bottom-color:var(--a3); font-weight:600; }
.tab-panel { display:none; }
.tab-panel.active { display:block; }

.code-box { background:var(--s2); border:1px solid var(--b2); border-radius:var(--r4); padding:28px; text-align:center; margin:16px 0; }
.code-digits { font-size:44px; font-weight:700; letter-spacing:12px; color:var(--a3); font-family:monospace; }
.code-hint { font-size:12.5px; color:var(--t3); margin-top:8px; }
.code-sub  { font-size:12px; color:var(--t2); margin-top:5px; }

.code-input-row { display:flex; gap:8px; margin-bottom:8px; }
.code-input { flex:1; padding:11px 14px; background:var(--s2); border:1px solid var(--b1); color:var(--t0); border-radius:var(--r2); font-size:22px; font-family:monospace; letter-spacing:10px; text-align:center; outline:none; transition:border-color var(--t); box-sizing:border-box; }
.code-input:focus { border-color:var(--a3); }

.share-card { background:var(--s1); border:1px solid var(--b1); border-radius:var(--r3); padding:14px 16px; margin-bottom:10px; display:flex; align-items:center; justify-content:space-between; gap:12px; }
.sc-left  { display:flex; align-items:center; gap:12px; flex:1; min-width:0; }
.sc-icon  { width:38px; height:38px; border-radius:var(--r3); display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.sc-icon.login    { background:var(--a-dim); }
.sc-icon.note     { background:var(--amber-s); }
.sc-icon.card     { background:var(--red-s); }
.sc-icon.identity { background:var(--green-s); }
.sc-icon.device   { background:var(--s2); border:1px solid var(--b1); }
.sc-title  { font-size:14px; font-weight:600; color:var(--t0); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.sc-meta   { font-size:12px; color:var(--t3); margin-top:2px; }
.sc-acts   { display:flex; gap:7px; flex-shrink:0; }

.sbadge { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:99px; font-size:11px; font-weight:600; vertical-align:middle; }
.sbadge.pending      { background:rgba(245,158,11,.15); color:#f59e0b; }
.sbadge.code_entered { background:rgba(99,102,241,.15); color:var(--a3); }
.sbadge.active       { background:rgba(16,185,129,.15); color:#10b981; }
.sbadge.cancelled    { background:var(--s2); color:var(--t3); }

.item-row { display:flex; align-items:center; justify-content:space-between; padding:11px 14px; border:1px solid var(--b1); border-radius:var(--r2); margin-bottom:8px; background:var(--s1); transition:all var(--t); }
.item-row:hover { border-color:var(--b3); background:var(--s2); }
.ir-left { display:flex; align-items:center; gap:10px; }
.ir-icon { font-size:18px; }
.ir-name { font-size:13.5px; font-weight:500; color:var(--t0); }
.ir-type { font-size:11.5px; color:var(--t3); }

.unlock-inline { background:var(--s1); border:1px solid var(--b2); border-radius:var(--r4); padding:32px; text-align:center; max-width:400px; margin:0 auto; }
.unlock-inline .lock-icon { font-size:36px; margin-bottom:10px; }
.unlock-inline h3 { font-size:18px; font-weight:700; margin-bottom:6px; }
.unlock-inline p { font-size:13px; color:var(--t3); margin-bottom:18px; }
.unlock-inline input { width:100%; padding:11px 14px; background:var(--s2); border:1px solid var(--b1); color:var(--t0); border-radius:var(--r2); font-size:14px; margin-bottom:10px; font-family:inherit; outline:none; transition:border-color var(--t); box-sizing:border-box; }
.unlock-inline input:focus { border-color:var(--a3); }
.unlock-err { color:var(--red); font-size:12.5px; min-height:18px; margin-bottom:8px; }

.modal-sm { position:fixed; inset:0; background:rgba(8,8,18,.85); backdrop-filter:blur(6px); z-index:8000; display:flex; align-items:center; justify-content:center; }
.modal-sm.hidden { display:none; }
.modal-sm-box { background:var(--s1); border:1px solid var(--b2); border-radius:var(--r5); padding:32px; width:100%; max-width:420px; }
.msm-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }
.msm-head h3 { font-size:17px; font-weight:700; }
.msm-close { background:none; border:none; color:var(--t3); font-size:18px; cursor:pointer; padding:4px 8px; border-radius:var(--r1); transition:all var(--t); }
.msm-close:hover { color:var(--t0); background:var(--s2); }

.sec-hdr { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--t3); margin:24px 0 12px; }
.sec-hdr:first-child { margin-top:0; }
.empty-msg { text-align:center; padding:32px 16px; color:var(--t3); font-size:13.5px; }
.empty-msg-icon { font-size:32px; margin-bottom:8px; }

.conn-card { background:var(--s1); border:1px solid var(--b1); border-radius:var(--r3); padding:14px 16px; margin-bottom:10px; display:flex; align-items:center; justify-content:space-between; gap:12px; }
.cc-info h4 { font-size:14px; font-weight:600; color:var(--t0); margin-bottom:3px; }
.cc-info p  { font-size:12px; color:var(--t3); }

@media(max-width:768px){
    .share-card, .conn-card { flex-wrap:wrap; }
    .sc-acts { width:100%; }
    .sc-acts .btn-sm { flex:1; }
    .tabs { overflow-x:auto; flex-wrap:nowrap; }
}
</style>
</head>
<body>
<div class="layout">
<?php $active_nav = 'shared'; include_once '../includes/sidebar.php'; ?>

<div class="main">
    <div class="page-header">
        <div>
            <div class="page-title">Sharing</div>
            <div class="page-subtitle">Share items via one-time codes and link devices</div>
        </div>
    </div>

    <div class="tabs">
        <button class="tab-btn active" id="tbMyItems"    onclick="switchTab('MyItems',this)">My Items</button>
        <button class="tab-btn"        id="tbByMe"       onclick="switchTab('ByMe',this)">Shared by Me</button>
        <button class="tab-btn"        id="tbWithMe"     onclick="switchTab('WithMe',this)">Shared with Me</button>
        <button class="tab-btn"        id="tbDevices"    onclick="switchTab('Devices',this)">Device Connect</button>
    </div>

    <!-- My Items -->
    <div id="tabMyItems" class="tab-panel active">
        <p style="font-size:13.5px;color:var(--t3);margin-bottom:20px;">
            Unlock your vault to select an item. A one-time 6-digit code will be generated — share it with the recipient.
        </p>
        <div id="myItemsUnlock" class="unlock-inline">
            <div class="lock-icon">🔐</div>
            <h3>Unlock Vault</h3>
            <p>Enter your master password to see your vault items.</p>
            <input type="password" id="masterPwdInput" placeholder="Master password…" autocomplete="current-password"
                   onkeydown="if(event.key==='Enter')doUnlockShare()">
            <div class="unlock-err" id="unlockErr"></div>
            <button class="btn-primary" style="width:100%" id="unlockBtn" onclick="doUnlockShare()">Unlock</button>
        </div>
        <div id="myItemsList" class="hidden"></div>
    </div>

    <!-- Shared by Me -->
    <div id="tabByMe" class="tab-panel">
        <div class="sec-hdr">Active &amp; Pending Shares</div>
        <div id="sharedByMeList"><div class="empty-msg"><div class="empty-msg-icon">⏳</div>Loading…</div></div>
    </div>

    <!-- Shared with Me -->
    <div id="tabWithMe" class="tab-panel">
        <div class="sec-hdr">Enter Share Code</div>
        <p style="font-size:13px;color:var(--t3);margin-bottom:14px;">Enter the 6-digit code you received from another user.</p>
        <div class="code-input-row">
            <input type="text" class="code-input" id="enterShareCode" maxlength="6" placeholder="000000"
                   inputmode="numeric" pattern="[0-9]*"
                   onkeydown="if(event.key==='Enter')submitShareCode()">
            <button class="btn-primary" onclick="submitShareCode()">Accept</button>
        </div>
        <div id="enterShareErr" style="color:var(--red);font-size:12.5px;min-height:18px;margin-bottom:12px;"></div>

        <div class="sec-hdr" style="margin-top:28px;">Received Items</div>
        <div id="sharedWithMeList"><div class="empty-msg"><div class="empty-msg-icon">⏳</div>Loading…</div></div>
    </div>

    <!-- Device Connect -->
    <div id="tabDevices" class="tab-panel">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
            <div>
                <div class="sec-hdr" style="margin:0 0 4px;">Generate Device Code</div>
                <p style="font-size:13px;color:var(--t3);max-width:480px;">
                    Click <strong>Generate Code</strong> to get a 10-minute code. Enter it on your other device to create a secure link.
                </p>
            </div>
            <button class="btn-primary" onclick="createDeviceConnect()">Generate Code</button>
        </div>

        <div class="sec-hdr">Enter Device Code</div>
        <div class="code-input-row" style="margin-bottom:6px;">
            <input type="text" class="code-input" id="enterDeviceCode" maxlength="6" placeholder="000000"
                   inputmode="numeric" pattern="[0-9]*"
                   onkeydown="if(event.key==='Enter')submitDeviceCode()">
            <button class="btn-primary" onclick="submitDeviceCode()">Connect</button>
        </div>
        <div id="enterDeviceErr" style="color:var(--red);font-size:12.5px;min-height:18px;margin-bottom:16px;"></div>

        <div class="sec-hdr">My Connections</div>
        <div id="connectionsList"><div class="empty-msg"><div class="empty-msg-icon">⏳</div>Loading…</div></div>
    </div>

</div><!-- .main -->
</div><!-- .layout -->

<!-- Share code modal -->
<div id="shareCodeModal" class="modal-sm hidden">
    <div class="modal-sm-box">
        <div class="msm-head">
            <h3>Share Code Ready</h3>
            <button class="msm-close" onclick="closeModal('shareCodeModal')">✕</button>
        </div>
        <p style="font-size:13px;color:var(--t3);margin-bottom:16px;">
            Share this code with the recipient. It expires in <strong>24 hours</strong> and is single-use.
        </p>
        <div class="code-box">
            <div class="code-digits" id="shareCodeDisplay"></div>
            <div class="code-hint">Send this code to the recipient</div>
            <div class="code-sub" id="shareCodeSub"></div>
        </div>
        <div style="display:flex;gap:8px;margin-top:16px;">
            <button class="btn-secondary" style="flex:1" onclick="copyCode('shareCodeDisplay')">Copy Code</button>
            <button class="btn-primary" style="flex:1" onclick="closeModal('shareCodeModal')">Done</button>
        </div>
    </div>
</div>

<!-- Device code modal -->
<div id="deviceCodeModal" class="modal-sm hidden">
    <div class="modal-sm-box">
        <div class="msm-head">
            <h3>Device Code Ready</h3>
            <button class="msm-close" onclick="closeModal('deviceCodeModal')">✕</button>
        </div>
        <p style="font-size:13px;color:var(--t3);margin-bottom:16px;">
            Enter this code on your other device within <strong>10 minutes</strong>.
        </p>
        <div class="code-box">
            <div class="code-digits" id="deviceCodeDisplay"></div>
            <div class="code-hint">Enter on the other device</div>
        </div>
        <div style="display:flex;gap:8px;margin-top:16px;">
            <button class="btn-secondary" style="flex:1" onclick="copyCode('deviceCodeDisplay')">Copy Code</button>
            <button class="btn-primary" style="flex:1" onclick="closeModal('deviceCodeModal')">Done</button>
        </div>
    </div>
</div>

<!-- Confirm received share modal -->
<div id="confirmShareModal" class="modal-sm hidden">
    <div class="modal-sm-box">
        <div class="msm-head">
            <h3>Accept Shared Item?</h3>
            <button class="msm-close" onclick="closeModal('confirmShareModal')">✕</button>
        </div>
        <p style="font-size:13.5px;color:var(--t2);margin-bottom:10px;"><strong id="confirmSender"></strong> wants to share:</p>
        <div style="background:var(--s2);border:1px solid var(--b1);border-radius:var(--r3);padding:14px 16px;margin-bottom:22px;">
            <div style="font-size:15px;font-weight:600;color:var(--t0)" id="confirmTitle"></div>
            <div style="font-size:12px;color:var(--t3);margin-top:4px;" id="confirmType"></div>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn-secondary" style="flex:1" onclick="closeModal('confirmShareModal')">Decline</button>
            <button class="btn-primary" style="flex:1" onclick="doConfirmShare()">Accept</button>
        </div>
    </div>
</div>

<div id="toast" class="toast hidden"></div>

<script>
const userId = <?php echo $user_id; ?>;
let vaultKey = null;
let pendingShareId = null;

/* ── Tabs ── */
function switchTab(id, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab' + id).classList.add('active');
    btn.classList.add('active');
    if (id === 'ByMe')    loadSharedByMe();
    if (id === 'WithMe')  loadSharedWithMe();
    if (id === 'Devices') loadConnections();
}

/* ── Toast ── */
function toast(msg, ok) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast' + (ok === false ? ' toast-err' : '');
    t.classList.remove('hidden');
    clearTimeout(t._tid);
    t._tid = setTimeout(() => t.classList.add('hidden'), 3200);
}

/* ── Modal helpers ── */
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
function copyCode(elId) {
    const code = document.getElementById(elId).textContent.trim();
    navigator.clipboard.writeText(code)
        .then(() => toast('Code copied!'))
        .catch(() => toast('Copy failed', false));
}

/* ── Vault unlock ── */
async function doUnlockShare() {
    const btn = document.getElementById('unlockBtn');
    const err = document.getElementById('unlockErr');
    const pwd = document.getElementById('masterPwdInput').value;
    if (!pwd) { err.textContent = 'Enter your master password.'; return; }
    btn.disabled = true; btn.textContent = 'Unlocking…'; err.textContent = '';
    try {
        const res  = await fetch('../api/salt.php');
        const data = await res.json();
        if (!data.salt) throw new Error('no salt');
        vaultKey = await CryptoEngine.deriveKey(pwd, data.salt);
        document.getElementById('myItemsUnlock').classList.add('hidden');
        document.getElementById('myItemsList').classList.remove('hidden');
        await loadMyItems();
    } catch {
        err.textContent = 'Incorrect password or vault error.';
        vaultKey = null;
    }
    btn.disabled = false; btn.textContent = 'Unlock';
}

/* ── My Items ── */
async function loadMyItems() {
    const el = document.getElementById('myItemsList');
    el.innerHTML = '<div class="empty-msg"><div class="empty-msg-icon">⏳</div>Loading…</div>';
    try {
        const res  = await fetch('../api/vault.php');
        const data = await res.json();
        const entries = data.entries || [];
        if (!entries.length) {
            el.innerHTML = '<div class="empty-msg"><div class="empty-msg-icon">📭</div>Your vault is empty.</div>';
            return;
        }
        const icons = { login:'🔐', note:'📝', card:'💳', identity:'👤' };
        const items = [];
        for (const e of entries) {
            let title = 'Untitled';
            try {
                const obj = await CryptoEngine.decrypt(vaultKey, e.iv, e.encrypted_data);
                title = obj.title || obj.name || 'Untitled';
            } catch { /* wrong key or corrupted, leave as Untitled */ }
            items.push({ uuid: e.uuid, type: e.type, title });
        }
        el.innerHTML = items.map(it => `
            <div class="item-row">
                <div class="ir-left">
                    <span class="ir-icon">${icons[it.type] || '?'}</span>
                    <div>
                        <div class="ir-name">${esc(it.title)}</div>
                        <div class="ir-type">${it.type}</div>
                    </div>
                </div>
                <button class="btn-sm btn-edit" onclick="createShare('${it.uuid}','${esc(it.title).replace(/'/g,'&#39;')}')">
                    Share
                </button>
            </div>
        `).join('');
    } catch {
        el.innerHTML = '<div class="empty-msg">Error loading items.</div>';
    }
}

async function createShare(uuid, title) {
    const fd = new FormData();
    fd.append('action', 'create_share');
    fd.append('uuid',   uuid);
    fd.append('title',  title);
    try {
        const res  = await fetch('../api/sharing.php', { method:'POST', body:fd });
        const data = await res.json();
        if (!data.ok) { toast(data.msg || 'Failed to create share', false); return; }
        document.getElementById('shareCodeDisplay').textContent = data.code;
        document.getElementById('shareCodeSub').textContent = 'Share ID: ' + data.share_id + '  ·  Expires in 24 h';
        document.getElementById('shareCodeModal').classList.remove('hidden');
    } catch { toast('Network error', false); }
}

/* ── Shared by Me ── */
async function loadSharedByMe() {
    const el = document.getElementById('sharedByMeList');
    el.innerHTML = '<div class="empty-msg"><div class="empty-msg-icon">⏳</div>Loading…</div>';
    try {
        const res  = await fetch('../api/sharing.php?action=list_shared_by_me');
        const data = await res.json();
        const list = data.shares || [];
        if (!list.length) {
            el.innerHTML = '<div class="empty-msg"><div class="empty-msg-icon">📭</div>No active shares yet.</div>';
            return;
        }
        const icons = { login:'🔐', note:'📝', card:'💳', identity:'👤' };
        el.innerHTML = list.map(s => `
            <div class="share-card">
                <div class="sc-left">
                    <div class="sc-icon ${s.item_type||'login'}">${icons[s.item_type]||'🔐'}</div>
                    <div>
                        <div class="sc-title">${esc(s.item_title)}</div>
                        <div class="sc-meta">
                            ${s.recipient_name ? 'To: ' + esc(s.recipient_name) + '  ·  ' : 'Awaiting recipient  ·  '}
                            ${statusBadge(s.status)}
                        </div>
                    </div>
                </div>
                <div class="sc-acts">
                    ${s.status === 'code_entered' && !+s.sender_confirmed
                        ? `<button class="btn-sm btn-edit" onclick="confirmShare(${s.id}, this)">Confirm</button>` : ''}
                    <button class="btn-sm btn-del" onclick="cancelShare(${s.id}, this)">Cancel</button>
                </div>
            </div>
        `).join('');
    } catch { el.innerHTML = '<div class="empty-msg">Error loading shares.</div>'; }
}

/* ── Shared with Me ── */
async function loadSharedWithMe() {
    const el = document.getElementById('sharedWithMeList');
    el.innerHTML = '<div class="empty-msg"><div class="empty-msg-icon">⏳</div>Loading…</div>';
    try {
        const res  = await fetch('../api/sharing.php?action=list_shared_with_me');
        const data = await res.json();
        const list = data.shares || [];
        if (!list.length) {
            el.innerHTML = '<div class="empty-msg"><div class="empty-msg-icon">📭</div>No items shared with you yet.</div>';
            return;
        }
        const icons = { login:'🔐', note:'📝', card:'💳', identity:'👤' };
        el.innerHTML = list.map(s => `
            <div class="share-card">
                <div class="sc-left">
                    <div class="sc-icon ${s.item_type||'login'}">${icons[s.item_type]||'🔐'}</div>
                    <div>
                        <div class="sc-title">${esc(s.item_title)}</div>
                        <div class="sc-meta">From: ${esc(s.sender_name||'Unknown')}  ·  ${statusBadge(s.status)}</div>
                    </div>
                </div>
                <div class="sc-acts">
                    ${s.status === 'code_entered' && !+s.recipient_confirmed
                        ? `<button class="btn-sm btn-edit" onclick="confirmShare(${s.id}, this)">Confirm</button>` : ''}
                    <button class="btn-sm btn-del" onclick="cancelShare(${s.id}, this)">Remove</button>
                </div>
            </div>
        `).join('');
    } catch { el.innerHTML = '<div class="empty-msg">Error loading shares.</div>'; }
}

async function submitShareCode() {
    const code = document.getElementById('enterShareCode').value.trim();
    const err  = document.getElementById('enterShareErr');
    err.textContent = '';
    if (!/^\d{6}$/.test(code)) { err.textContent = 'Enter a 6-digit numeric code.'; return; }
    const fd = new FormData();
    fd.append('action', 'enter_share_code');
    fd.append('code',   code);
    try {
        const res  = await fetch('../api/sharing.php', { method:'POST', body:fd });
        const data = await res.json();
        if (!data.ok) { err.textContent = data.msg || 'Invalid or expired code.'; return; }
        pendingShareId = data.share_id;
        document.getElementById('confirmSender').textContent = data.sender_name || 'Someone';
        document.getElementById('confirmTitle').textContent  = data.item_title  || 'Untitled';
        document.getElementById('confirmType').textContent   = 'Vault item';
        document.getElementById('confirmShareModal').classList.remove('hidden');
        document.getElementById('enterShareCode').value = '';
    } catch { err.textContent = 'Network error.'; }
}

async function doConfirmShare() {
    if (!pendingShareId) return;
    const fd = new FormData();
    fd.append('action',   'confirm_share');
    fd.append('share_id', pendingShareId);
    try {
        const res  = await fetch('../api/sharing.php', { method:'POST', body:fd });
        const data = await res.json();
        closeModal('confirmShareModal');
        toast(data.msg || (data.status === 'active' ? 'Share active!' : 'Waiting for the other side to confirm.'), data.ok);
        pendingShareId = null;
        loadSharedWithMe();
    } catch { toast('Network error', false); }
}

async function confirmShare(shareId, btn) {
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action',   'confirm_share');
    fd.append('share_id', shareId);
    try {
        const res  = await fetch('../api/sharing.php', { method:'POST', body:fd });
        const data = await res.json();
        toast(data.msg || 'Done', data.ok);
        loadSharedByMe();
        loadSharedWithMe();
    } catch { toast('Network error', false); btn.disabled = false; }
}

async function cancelShare(shareId, btn) {
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action',   'cancel_share');
    fd.append('share_id', shareId);
    try {
        const res  = await fetch('../api/sharing.php', { method:'POST', body:fd });
        const data = await res.json();
        toast(data.ok ? 'Share cancelled.' : (data.msg || 'Error'), data.ok);
        loadSharedByMe();
        loadSharedWithMe();
    } catch { toast('Network error', false); btn.disabled = false; }
}

/* ── Device Connections ── */
async function createDeviceConnect() {
    const fd = new FormData();
    fd.append('action', 'create_device_connect');
    fd.append('label',  'My Device');
    try {
        const res  = await fetch('../api/sharing.php', { method:'POST', body:fd });
        const data = await res.json();
        if (!data.ok) { toast(data.msg || 'Failed', false); return; }
        document.getElementById('deviceCodeDisplay').textContent = data.code;
        document.getElementById('deviceCodeModal').classList.remove('hidden');
        loadConnections();
    } catch { toast('Network error', false); }
}

async function submitDeviceCode() {
    const code = document.getElementById('enterDeviceCode').value.trim();
    const err  = document.getElementById('enterDeviceErr');
    err.textContent = '';
    if (!/^\d{6}$/.test(code)) { err.textContent = 'Enter a 6-digit code.'; return; }
    const fd = new FormData();
    fd.append('action', 'enter_device_code');
    fd.append('code',   code);
    try {
        const res  = await fetch('../api/sharing.php', { method:'POST', body:fd });
        const data = await res.json();
        if (!data.ok) { err.textContent = data.msg || 'Invalid or expired code.'; return; }
        // Auto-confirm from this side
        const fd2 = new FormData();
        fd2.append('action',  'confirm_device');
        fd2.append('conn_id', data.conn_id);
        const r2   = await fetch('../api/sharing.php', { method:'POST', body:fd2 });
        const d2   = await r2.json();
        toast(d2.msg || 'Connection pending — waiting for the other device to confirm.', d2.ok);
        document.getElementById('enterDeviceCode').value = '';
        loadConnections();
    } catch { err.textContent = 'Network error.'; }
}

async function confirmDevice(connId, btn) {
    btn.disabled = true;
    const fd = new FormData();
    fd.append('action',  'confirm_device');
    fd.append('conn_id', connId);
    try {
        const res  = await fetch('../api/sharing.php', { method:'POST', body:fd });
        const data = await res.json();
        toast(data.msg || 'Done', data.ok);
        loadConnections();
    } catch { toast('Network error', false); btn.disabled = false; }
}

async function loadConnections() {
    const el = document.getElementById('connectionsList');
    el.innerHTML = '<div class="empty-msg"><div class="empty-msg-icon">⏳</div>Loading…</div>';
    try {
        const res  = await fetch('../api/sharing.php?action=list_connections');
        const data = await res.json();
        const list = data.connections || [];
        if (!list.length) {
            el.innerHTML = '<div class="empty-msg"><div class="empty-msg-icon">📱</div>No device connections yet.</div>';
            return;
        }
        el.innerHTML = list.map(c => {
            const isMine   = +c.requester_id === userId;
            const other    = isMine ? (c.target_name || 'Pending') : (c.requester_name || 'Unknown');
            const myRole   = isMine ? 'You initiated' : 'Incoming';
            const myConf   = isMine ? +c.requester_confirmed : +c.target_confirmed;
            const needsConf = c.status === 'code_entered' && !myConf;
            return `
            <div class="conn-card">
                <div class="sc-left">
                    <div class="sc-icon device">📱</div>
                    <div class="cc-info">
                        <h4>${esc(c.device_label || 'Device')}  ${statusBadge(c.status)}</h4>
                        <p>${myRole} · ${esc(other)} · ${new Date(c.created_at).toLocaleDateString()}</p>
                    </div>
                </div>
                <div class="sc-acts">
                    ${needsConf ? `<button class="btn-sm btn-edit" onclick="confirmDevice(${c.id},this)">Confirm</button>` : ''}
                </div>
            </div>`;
        }).join('');
    } catch { el.innerHTML = '<div class="empty-msg">Error loading connections.</div>'; }
}

/* ── Helpers ── */
function statusBadge(status) {
    const map = {
        active:       '<span class="sbadge active">✓ Active</span>',
        code_entered: '<span class="sbadge code_entered">⏳ Awaiting confirmation</span>',
        pending:      '<span class="sbadge pending">Pending</span>',
        cancelled:    '<span class="sbadge cancelled">Cancelled</span>',
    };
    return map[status] || map.pending;
}

function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Init ── */
loadSharedByMe();
loadSharedWithMe();
loadConnections();
</script>
</body>
</html>

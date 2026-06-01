<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Password Generator – Vaultly</title>
<link rel="stylesheet" href="../css/app.css">
<style>
.gen-wrap{max-width:620px;}
.gen-output{
    display:flex;gap:10px;align-items:center;
    background:var(--s2);border:1px solid var(--b1);border-radius:var(--r4);
    padding:18px 20px;margin-bottom:20px;
}
.gen-output .pwd-text{
    flex:1;font-size:20px;font-family:monospace;letter-spacing:.08em;
    color:var(--t0);word-break:break-all;line-height:1.4;
}
.gen-btn-row{display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap;}
.option-row{display:flex;align-items:center;justify-content:space-between;padding:13px 0;border-bottom:1px solid var(--b0);}
.option-row:last-child{border-bottom:none;}
.option-label{font-size:13.5px;color:var(--t1);}
.option-sub{font-size:12px;color:var(--t3);margin-top:2px;}
.slider-wrap{display:flex;align-items:center;gap:10px;}
.slider-wrap input[type=range]{width:160px;accent-color:var(--a3);}
.slider-val{font-size:13px;font-weight:700;color:var(--a3);min-width:28px;text-align:right;}
.toggle-switch{position:relative;width:44px;height:24px;flex-shrink:0;}
.toggle-switch input{opacity:0;width:0;height:0;}
.toggle-slider{position:absolute;inset:0;background:var(--s3);border-radius:12px;cursor:pointer;transition:background .2s;}
.toggle-slider:before{content:'';position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:transform .2s;}
.toggle-switch input:checked + .toggle-slider{background:var(--a3);}
.toggle-switch input:checked + .toggle-slider:before{transform:translateX(20px);}
.strength-bar{height:6px;border-radius:3px;transition:width .3s,background .3s;margin-top:6px;}
.strength-label{font-size:12px;color:var(--t3);margin-top:4px;}
.history-item{display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--b0);font-size:13px;}
.history-item:last-child{border-bottom:none;}
.history-pwd{flex:1;font-family:monospace;color:var(--t2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
</style>
</head>
<body>
<div class="layout">
<?php $active_nav = 'generator'; include '../includes/sidebar.php'; ?>
<div class="main">
    <div class="page-header">
        <div>
            <div class="page-title">⚡ Password Generator</div>
            <div class="page-subtitle">Generate strong, random passwords</div>
        </div>
    </div>

    <div class="gen-wrap stagger-in">

        <!-- Output -->
        <div class="gen-output" id="genOutput">
            <div class="pwd-text" id="pwdDisplay">Click Generate</div>
            <button class="btn-sm btn-edit" onclick="copyPassword()" title="Copy" id="copyBtn">Copy</button>
        </div>

        <!-- Strength -->
        <div style="margin-bottom:20px;">
            <div style="height:6px;background:var(--s2);border-radius:3px;overflow:hidden;">
                <div class="strength-bar" id="strengthBar" style="width:0;background:#ef4444"></div>
            </div>
            <div class="strength-label" id="strengthLabel">—</div>
        </div>

        <!-- Generate buttons -->
        <div class="gen-btn-row">
            <button class="btn-primary" style="flex:1" onclick="generate()">⚡ Generate</button>
            <button class="btn-sm btn-edit" onclick="saveToVault()" id="saveVaultBtn">💾 Save to Vault</button>
        </div>

        <!-- Options -->
        <div style="background:var(--s1);border:1px solid var(--b1);border-radius:var(--r5);padding:4px 20px 8px;margin-bottom:24px;">
            <div class="option-row">
                <div><div class="option-label">Length</div></div>
                <div class="slider-wrap">
                    <input type="range" id="optLength" min="8" max="128" value="20" oninput="onLengthChange(this.value)">
                    <div class="slider-val" id="lengthVal">20</div>
                </div>
            </div>
            <div class="option-row">
                <div><div class="option-label">Uppercase (A–Z)</div></div>
                <label class="toggle-switch"><input type="checkbox" id="optUpper" checked onchange="generate()"><span class="toggle-slider"></span></label>
            </div>
            <div class="option-row">
                <div><div class="option-label">Lowercase (a–z)</div></div>
                <label class="toggle-switch"><input type="checkbox" id="optLower" checked onchange="generate()"><span class="toggle-slider"></span></label>
            </div>
            <div class="option-row">
                <div><div class="option-label">Numbers (0–9)</div></div>
                <label class="toggle-switch"><input type="checkbox" id="optNums" checked onchange="generate()"><span class="toggle-slider"></span></label>
            </div>
            <div class="option-row">
                <div><div class="option-label">Symbols</div><div class="option-sub">! @ # $ % ^ & *</div></div>
                <label class="toggle-switch"><input type="checkbox" id="optSyms" checked onchange="generate()"><span class="toggle-slider"></span></label>
            </div>
            <div class="option-row">
                <div><div class="option-label">Exclude ambiguous</div><div class="option-sub">Removes 0 O l I 1</div></div>
                <label class="toggle-switch"><input type="checkbox" id="optNoAmb" onchange="generate()"><span class="toggle-slider"></span></label>
            </div>
        </div>

        <!-- History -->
        <div style="background:var(--s1);border:1px solid var(--b1);border-radius:var(--r5);padding:16px 20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                <div style="font-size:13px;font-weight:700;color:var(--t2);">Recent Passwords</div>
                <button class="btn-sm" onclick="clearHistory()" style="font-size:11px;padding:4px 10px;">Clear</button>
            </div>
            <div id="historyList"><div style="font-size:12.5px;color:var(--t3);">No history yet.</div></div>
        </div>
    </div>
</div>
</div>

<!-- Save to Vault modal -->
<div id="saveModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Save to Vault</h2>
            <button class="modal-close" onclick="closeSaveModal()">✕</button>
        </div>
        <div id="saveModalBody">
            <p style="font-size:13px;color:var(--t3);margin-bottom:16px;">Unlock your vault to save this password.</p>
            <div class="form-group"><label>Master Password</label><input type="password" id="saveMasterPwd" placeholder="Master password…" autocomplete="current-password"></div>
            <div class="form-group"><label>Site / Title</label><input type="text" id="saveTitle" placeholder="e.g. Gmail" required></div>
            <div class="form-group"><label>Username (optional)</label><input type="text" id="saveUsername" placeholder="username or email"></div>
            <div class="form-group"><label>URL (optional)</label><input type="url" id="saveUrl" placeholder="https://example.com"></div>
            <div class="unlock-err" id="saveErr" style="color:var(--red);font-size:12.5px;min-height:18px;"></div>
            <div class="modal-actions">
                <button class="btn-save" id="saveConfirmBtn" onclick="confirmSave()">Save</button>
                <button class="btn-cancel" onclick="closeSaveModal()">Cancel</button>
            </div>
        </div>
    </div>
</div>

<div id="toast" class="toast"></div>

<script src="../js/crypto-engine.js"></script>
<script>
const UPPER  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
const UPPER_C= 'ABCDEFGHJKMNPQRSTUVWXYZ';
const LOWER  = 'abcdefghijklmnopqrstuvwxyz';
const LOWER_C= 'abcdefghjkmnpqrstuvwxyz';
const NUMS   = '0123456789';
const NUMS_C = '23456789';
const SYMS   = '!@#$%^&*()-_=+[]{}|;:,.<>?';

let currentPwd = '';
const history  = [];

function generate() {
    const length  = parseInt(document.getElementById('optLength').value);
    const noAmb   = document.getElementById('optNoAmb').checked;
    let charset   = '';
    if (document.getElementById('optUpper').checked) charset += noAmb ? UPPER_C : UPPER;
    if (document.getElementById('optLower').checked) charset += noAmb ? LOWER_C : LOWER;
    if (document.getElementById('optNums').checked)  charset += noAmb ? NUMS_C  : NUMS;
    if (document.getElementById('optSyms').checked)  charset += SYMS;
    if (!charset) charset = LOWER + UPPER + NUMS;

    const arr = new Uint32Array(length);
    crypto.getRandomValues(arr);
    currentPwd = Array.from(arr).map(n => charset[n % charset.length]).join('');

    document.getElementById('pwdDisplay').textContent = currentPwd;
    document.getElementById('copyBtn').textContent    = 'Copy';
    updateStrength(currentPwd);
    addHistory(currentPwd);
}

function onLengthChange(v) {
    document.getElementById('lengthVal').textContent = v;
    generate();
}

function updateStrength(pwd) {
    let s = 0;
    if (pwd.length >= 8)  s += 15;
    if (pwd.length >= 12) s += 15;
    if (pwd.length >= 16) s += 10;
    if (/[a-z]/.test(pwd)) s += 15;
    if (/[A-Z]/.test(pwd)) s += 15;
    if (/[0-9]/.test(pwd)) s += 15;
    if (/[^a-zA-Z0-9]/.test(pwd)) s += 20;
    s = Math.min(s, 100);

    const colors = ['#ef4444','#f59e0b','#5865f2','#10b981'];
    const labels = ['Weak','Fair','Strong','Very Strong'];
    const idx    = s < 40 ? 0 : s < 70 ? 1 : s < 85 ? 2 : 3;

    document.getElementById('strengthBar').style.width      = s + '%';
    document.getElementById('strengthBar').style.background = colors[idx];
    document.getElementById('strengthLabel').textContent    = labels[idx] + ' (' + s + '/100)';
}

function copyPassword() {
    if (!currentPwd) return;
    navigator.clipboard.writeText(currentPwd).then(() => {
        const btn = document.getElementById('copyBtn');
        btn.textContent = '✓ Copied'; btn.style.color = '#10b981';
        setTimeout(() => { btn.textContent = 'Copy'; btn.style.color = ''; }, 2000);
    });
}

function addHistory(pwd) {
    history.unshift(pwd);
    if (history.length > 10) history.pop();
    renderHistory();
}

function renderHistory() {
    const el = document.getElementById('historyList');
    if (!history.length) { el.innerHTML = '<div style="font-size:12.5px;color:var(--t3);">No history yet.</div>'; return; }
    el.innerHTML = history.map((p, i) => `
        <div class="history-item">
            <div class="history-pwd">${esc(p)}</div>
            <button class="btn-sm" style="font-size:11px;padding:4px 9px;flex-shrink:0"
                    onclick="usePwd(${i})">Use</button>
            <button class="btn-sm btn-edit" style="font-size:11px;padding:4px 9px;flex-shrink:0"
                    onclick="navigator.clipboard.writeText(history[${i}]).then(()=>showToast('✓ Copied'))">Copy</button>
        </div>`).join('');
}

function usePwd(i) {
    currentPwd = history[i];
    document.getElementById('pwdDisplay').textContent = currentPwd;
    updateStrength(currentPwd);
}

function clearHistory() { history.length = 0; renderHistory(); }

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast show ' + type;
    setTimeout(() => t.className = 'toast', 2800);
}

// ── Save to vault ─────────────────────────────────────────────────────────────
function saveToVault() {
    if (!currentPwd) { showToast('Generate a password first', 'error'); return; }
    document.getElementById('saveErr').textContent = '';
    document.getElementById('saveMasterPwd').value = '';
    document.getElementById('saveTitle').value     = '';
    document.getElementById('saveUsername').value  = '';
    document.getElementById('saveUrl').value       = '';
    document.getElementById('saveModal').classList.add('open');
    setTimeout(() => document.getElementById('saveTitle').focus(), 80);
}

function closeSaveModal() { document.getElementById('saveModal').classList.remove('open'); }

async function confirmSave() {
    const masterPwd = document.getElementById('saveMasterPwd').value;
    const title     = document.getElementById('saveTitle').value.trim();
    const err       = document.getElementById('saveErr');
    const btn       = document.getElementById('saveConfirmBtn');
    if (!masterPwd) { err.textContent = 'Enter your master password.'; return; }
    if (!title)     { err.textContent = 'Title is required.'; return; }

    btn.disabled = true; btn.textContent = 'Saving…'; err.textContent = '';
    try {
        const sd  = await (await fetch('../api/salt.php')).json();
        if (!sd.salt) throw new Error('Vault not initialized.');
        const key = await CryptoEngine.deriveKey(masterPwd, sd.salt);
        if (await CryptoEngine.decrypt(key, sd.verification_iv, sd.verification_blob) !== 'vault:ok')
            throw new Error('Wrong master password.');

        const entry = {
            title,
            username: document.getElementById('saveUsername').value,
            password: currentPwd,
            url:      document.getElementById('saveUrl').value,
        };
        const { iv, data: encData } = await CryptoEngine.encrypt(key, entry);
        const result = await (await fetch('../api/vault.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'create', type: 'login', encrypted_data: encData, iv, favorite: 0 }),
        })).json();
        if (result.error) throw new Error(result.error);
        showToast('✓ Password saved to vault!');
        closeSaveModal();
    } catch(e) {
        err.textContent = e.message;
    } finally {
        btn.disabled = false; btn.textContent = 'Save';
    }
}

document.getElementById('saveModal').addEventListener('click', e => { if (e.target === document.getElementById('saveModal')) closeSaveModal(); });
document.getElementById('saveMasterPwd').addEventListener('keydown', e => { if (e.key === 'Enter') confirmSave(); });

// Generate on load
generate();
</script>
</body>
</html>

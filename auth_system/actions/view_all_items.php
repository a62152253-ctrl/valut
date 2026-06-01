<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$user_name = htmlspecialchars($_SESSION['username'] ?? 'User');

$filter      = $_GET['type'] ?? 'all';
$sort        = $_GET['sort'] ?? 'newest';
$valid_types = ['login', 'note', 'card', 'identity'];
$where_type  = in_array($filter, $valid_types) ? "AND type = ?" : "";
$order_sql   = $sort === 'oldest' ? 'created_at ASC' : 'created_at DESC';

// Only fetch metadata — encrypted_data never decoded server-side
$query = "SELECT uuid, type, favorite, created_at, updated_at
          FROM vault_entries WHERE user_id = ? $where_type ORDER BY $order_sql";
$stmt  = $conn->prepare($query);
if ($where_type) {
    $stmt->bind_param('is', $user_id, $filter);
} else {
    $stmt->bind_param('i', $user_id);
}
$stmt->execute();
$entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$counts_stmt = $conn->prepare(
    "SELECT type, COUNT(*) as cnt FROM vault_entries WHERE user_id = ? GROUP BY type"
);
$counts_stmt->bind_param('i', $user_id);
$counts_stmt->execute();
$counts_rows = $counts_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$counts_stmt->close();

$type_counts = ['all' => 0, 'login' => 0, 'note' => 0, 'card' => 0, 'identity' => 0];
foreach ($counts_rows as $r) {
    $type_counts[$r['type']] = (int)$r['cnt'];
    $type_counts['all']     += (int)$r['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>All Items – Vaultly</title>
<link rel="stylesheet" href="../css/app.css">
<style>
.filters-row { display:flex; align-items:center; justify-content:space-between; margin-bottom:var(--s-6); gap:12px; flex-wrap:wrap; }
.sort-select { padding:7px 12px; background:var(--s2); border:1px solid var(--b1); color:var(--t2); border-radius:var(--r2); font-size:12.5px; cursor:pointer; outline:none; font-family:inherit; transition:all var(--t); }
.sort-select:hover, .sort-select:focus { border-color:var(--b3); color:var(--t1); }
.item-card-top { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:10px; }
.item-icon { width:42px; height:42px; border-radius:var(--r3); display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
.item-icon.login    { background:var(--a-dim); }
.item-icon.note     { background:var(--amber-s); }
.item-icon.card     { background:var(--red-s); }
.item-icon.identity { background:var(--green-s); }
.item-title { font-size:14.5px; font-weight:600; margin-top:5px; color:var(--t0); letter-spacing:-.2px; }
.item-title.locked { color:var(--t3); font-weight:400; font-style:italic; }
.item-meta  { font-size:12px; color:var(--t3); display:flex; align-items:center; gap:5px; margin-top:2px; }
.item-actions { display:flex; gap:7px; margin-top:12px; padding-top:12px; border-top:1px solid var(--b0); }
.item-actions .btn-sm { flex:1; text-align:center; }
.pass-toggle { display:flex; gap:7px; }
.pass-toggle input { flex:1; }
.pass-toggle button { padding:10px 13px; background:var(--a-dim); border:1px solid rgba(99,102,241,.22); color:var(--a3); border-radius:var(--r2); cursor:pointer; font-size:14px; transition:all var(--t); }
.pass-toggle button:hover { background:var(--a-mid); }
.vault-lock-overlay { position:fixed; inset:0; background:rgba(8,8,18,.88); backdrop-filter:blur(6px); z-index:9000; display:flex; align-items:center; justify-content:center; }
.vault-lock-overlay.hidden { display:none; }
.vault-lock-box { background:var(--s1); border:1px solid var(--b2); border-radius:var(--r5); padding:36px 32px; width:100%; max-width:400px; text-align:center; }
.vault-lock-box .lock-icon { font-size:40px; margin-bottom:12px; }
.vault-lock-box h2 { font-size:20px; font-weight:700; margin-bottom:6px; }
.vault-lock-box p  { font-size:13px; color:var(--t3); margin-bottom:22px; }
.vault-lock-box input { width:100%; padding:11px 14px; background:var(--s2); border:1px solid var(--b1); color:var(--t0); border-radius:var(--r2); font-size:14px; margin-bottom:12px; font-family:inherit; outline:none; transition:border-color var(--t); box-sizing:border-box; }
.vault-lock-box input:focus { border-color:var(--a3); }
.unlock-err { color:var(--red); font-size:12.5px; margin-bottom:10px; min-height:18px; }
</style>
</head>
<body>
<div class="layout">
<?php $active_nav = 'all_items'; include '../includes/sidebar.php'; ?>

<div class="main">
    <div class="page-header">
        <div>
            <div class="page-title">All Items</div>
            <div class="page-subtitle"><?php echo $type_counts['all']; ?> items in your vault</div>
        </div>
        <div class="header-right">
            <div class="search-bar">
                <span class="search-icon">🔍</span>
                <input type="text" id="searchInput" placeholder="Filter items…">
            </div>
            <button class="btn-primary" onclick="openAddModal()">＋ Add Item</button>
        </div>
    </div>

    <div class="filters-row">
        <div class="filter-tabs">
            <a href="?type=all&sort=<?php echo $sort; ?>"  class="filter-tab <?php echo $filter==='all'      ? 'active':''; ?>">All <span class="tab-count"><?php echo $type_counts['all']; ?></span></a>
            <a href="?type=login&sort=<?php echo $sort; ?>" class="filter-tab <?php echo $filter==='login'    ? 'active':''; ?>">🔐 Logins <span class="tab-count"><?php echo $type_counts['login']; ?></span></a>
            <a href="?type=note&sort=<?php echo $sort; ?>"  class="filter-tab <?php echo $filter==='note'     ? 'active':''; ?>">📝 Notes <span class="tab-count"><?php echo $type_counts['note']; ?></span></a>
            <a href="?type=card&sort=<?php echo $sort; ?>"  class="filter-tab <?php echo $filter==='card'     ? 'active':''; ?>">💳 Cards <span class="tab-count"><?php echo $type_counts['card']; ?></span></a>
            <a href="?type=identity&sort=<?php echo $sort; ?>" class="filter-tab <?php echo $filter==='identity' ? 'active':''; ?>">👤 Identities <span class="tab-count"><?php echo $type_counts['identity']; ?></span></a>
        </div>
        <select class="sort-select" onchange="window.location='?type=<?php echo $filter; ?>&sort='+this.value">
            <option value="newest" <?php echo $sort==='newest'?'selected':''; ?>>Newest first</option>
            <option value="oldest" <?php echo $sort==='oldest'?'selected':''; ?>>Oldest first</option>
        </select>
    </div>

    <div class="items-grid stagger-in" id="itemsGrid">
        <?php if (count($entries) > 0): ?>
            <?php foreach ($entries as $entry):
                $type  = $entry['type'];
                $icons = ['login'=>'🔐','note'=>'📝','card'=>'💳','identity'=>'👤'];
            ?>
            <div class="item-card card-glow"
                 data-uuid="<?php echo htmlspecialchars($entry['uuid']); ?>"
                 data-type="<?php echo $type; ?>"
                 data-search="">

                <div class="item-card-top">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div class="item-icon <?php echo $type; ?>"><?php echo $icons[$type] ?? '?'; ?></div>
                        <div>
                            <span class="type-badge <?php echo $type; ?>"><?php echo $type; ?></span>
                            <div class="item-title locked" id="title-<?php echo $entry['uuid']; ?>">🔒 Encrypted</div>
                        </div>
                    </div>
                    <button class="fav-btn <?php echo $entry['favorite'] ? 'active' : ''; ?>"
                            onclick="toggleFav('<?php echo $entry['uuid']; ?>', this)"
                            title="Toggle favorite">
                        <?php echo $entry['favorite'] ? '⭐' : '☆'; ?>
                    </button>
                </div>

                <div class="item-meta" id="meta-<?php echo $entry['uuid']; ?>"></div>
                <div class="item-meta">Added <?php echo date('M d, Y', strtotime($entry['created_at'])); ?></div>

                <div class="item-actions">
                    <button class="btn-sm btn-edit"   onclick="openEditModal('<?php echo $entry['uuid']; ?>', '<?php echo $type; ?>')">Edit</button>
                    <button class="btn-sm btn-copy"   onclick="copyUser('<?php echo $entry['uuid']; ?>')">Copy user</button>
                    <button class="btn-sm btn-del"    onclick="deleteItem('<?php echo $entry['uuid']; ?>', this)">Delete</button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="grid-column:1/-1;">
                <div class="empty-state">
                    <span class="empty-icon">🔒</span>
                    <h3>No items yet</h3>
                    <p>Start building your vault by adding your first item.</p>
                    <button class="btn-primary" onclick="openAddModal()">＋ Add First Item</button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- Vault unlock overlay -->
<div id="vaultLockOverlay" class="vault-lock-overlay">
    <div class="vault-lock-box">
        <div class="lock-icon">🔐</div>
        <h2>Unlock Vault</h2>
        <p>Enter your master password to view and edit items.</p>
        <input type="password" id="masterPwdInput" placeholder="Master password…" autocomplete="current-password">
        <div class="unlock-err" id="unlockErr"></div>
        <button class="btn-primary" style="width:100%" id="unlockBtn" onclick="doUnlock()">Unlock</button>
    </div>
</div>

<!-- Item modal -->
<div id="itemModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2 id="modalTitle">Edit Item</h2>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <form id="itemForm">
            <input type="hidden" id="editUuid" value="">
            <div class="form-group">
                <label for="editType">Type</label>
                <select id="editType" name="type" required>
                    <option value="login">🔐 Login</option>
                    <option value="note">📝 Note</option>
                    <option value="card">💳 Card</option>
                    <option value="identity">👤 Identity</option>
                </select>
            </div>
            <div class="form-group"><label for="editTitle">Title</label><input type="text" id="editTitle" placeholder="e.g. Gmail, GitHub" required></div>
            <div class="form-group"><label for="editUsername">Username / Email</label><input type="text" id="editUsername" placeholder="username or email"></div>
            <div class="form-group">
                <label for="editPassword">Password</label>
                <div class="pass-toggle">
                    <input type="password" id="editPassword" placeholder="password">
                    <button type="button" onclick="togglePwd()" aria-label="Show/hide password">👁</button>
                </div>
            </div>
            <div class="form-group"><label for="editUrl">Website URL</label><input type="url" id="editUrl" placeholder="https://example.com"></div>
            <div class="form-group"><label for="editNotes">Notes</label><textarea id="editNotes" placeholder="Additional info…"></textarea></div>
            <div class="modal-actions">
                <button type="submit" class="btn-save" id="saveBtn">Save</button>
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div id="toast" class="toast"></div>

<script src="../js/crypto-engine.js"></script>
<script>
const vault = { key: null, entries: {} };

function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show ' + type;
    setTimeout(() => t.className = 'toast', 2800);
}

// ── Unlock ────────────────────────────────────────────────────────────────────
async function doUnlock() {
    const pwd = document.getElementById('masterPwdInput').value;
    const btn = document.getElementById('unlockBtn');
    const err = document.getElementById('unlockErr');
    if (!pwd) { err.textContent = 'Enter your master password.'; return; }
    btn.textContent = 'Unlocking…'; btn.disabled = true; err.textContent = '';
    try {
        const sd = await (await fetch('../api/salt.php')).json();
        if (!sd.salt) throw new Error('Vault not initialized.');
        const key  = await CryptoEngine.deriveKey(pwd, sd.salt);
        const test = await CryptoEngine.decrypt(key, sd.verification_iv, sd.verification_blob);
        if (test !== 'vault:ok') throw new Error('Wrong master password.');
        vault.key = key;
        document.getElementById('vaultLockOverlay').classList.add('hidden');
        await loadAndDecryptEntries();
    } catch (e) {
        err.textContent = e.message || 'Unlock failed.';
    } finally {
        btn.textContent = 'Unlock'; btn.disabled = false;
    }
}
document.getElementById('masterPwdInput').addEventListener('keydown', e => { if (e.key === 'Enter') doUnlock(); });

// ── Load & decrypt ────────────────────────────────────────────────────────────
async function loadAndDecryptEntries() {
    const data = await (await fetch('../api/vault.php')).json();
    vault.entries = {};
    for (const row of (data.entries || [])) {
        try {
            const plain = await CryptoEngine.decrypt(vault.key, row.iv, row.encrypted_data);
            vault.entries[row.uuid] = plain;
        } catch {}
    }
    applyDecryptedData();
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function applyDecryptedData() {
    for (const [uuid, data] of Object.entries(vault.entries)) {
        const titleEl = document.getElementById('title-' + uuid);
        const metaEl  = document.getElementById('meta-'  + uuid);
        const card    = document.querySelector(`[data-uuid="${uuid}"]`);
        if (!titleEl) continue;
        const title = data.title || 'Untitled';
        titleEl.textContent = title;
        titleEl.classList.remove('locked');
        if (card) card.dataset.search = title.toLowerCase();
        if (metaEl) {
            metaEl.innerHTML = data.username ? `👤 ${esc(data.username)}`
                             : data.url      ? `🔗 ${esc(data.url)}`
                             : '';
        }
    }
    const q = document.getElementById('searchInput').value.toLowerCase();
    if (q) filterCards(q);
}

// ── Modal helpers ─────────────────────────────────────────────────────────────
function openAddModal() {
    if (!vault.key) { showToast('Unlock vault first', 'error'); return; }
    document.getElementById('modalTitle').textContent = 'Add New Item';
    document.getElementById('saveBtn').textContent    = 'Add Item';
    document.getElementById('itemForm').reset();
    document.getElementById('editUuid').value = '';
    document.getElementById('itemModal').classList.add('open');
    setTimeout(() => document.getElementById('editTitle').focus(), 80);
}

function openEditModal(uuid, type) {
    if (!vault.key) { showToast('Unlock vault first', 'error'); return; }
    const data = vault.entries[uuid] || {};
    document.getElementById('modalTitle').textContent = 'Edit Item';
    document.getElementById('saveBtn').textContent    = 'Save Changes';
    document.getElementById('editUuid').value     = uuid;
    document.getElementById('editType').value     = type;
    document.getElementById('editTitle').value    = data.title    || '';
    document.getElementById('editUsername').value = data.username || '';
    document.getElementById('editPassword').value = data.password || '';
    document.getElementById('editUrl').value      = data.url      || '';
    document.getElementById('editNotes').value    = data.notes    || '';
    document.getElementById('itemModal').classList.add('open');
}

function closeModal() { document.getElementById('itemModal').classList.remove('open'); }
function togglePwd() {
    const f = document.getElementById('editPassword');
    f.type = f.type === 'password' ? 'text' : 'password';
}

// ── Save ──────────────────────────────────────────────────────────────────────
document.getElementById('itemForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    if (!vault.key) { showToast('Vault is locked', 'error'); return; }
    const uuid  = document.getElementById('editUuid').value;
    const entry = {
        title:    document.getElementById('editTitle').value,
        username: document.getElementById('editUsername').value,
        password: document.getElementById('editPassword').value,
        url:      document.getElementById('editUrl').value,
        notes:    document.getElementById('editNotes').value,
    };
    const type = document.getElementById('editType').value;
    const btn  = document.getElementById('saveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
        const { iv, data: encData } = await CryptoEngine.encrypt(vault.key, entry);
        if (new TextEncoder().encode(encData).length > 5000000)
            throw new Error('Entry too large (max 5 MB).');
        const payload = { action: uuid ? 'update' : 'create', uuid: uuid || undefined, type, encrypted_data: encData, iv, favorite: 0 };
        const result  = await (await fetch('../api/vault.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) })).json();
        if (result.error) throw new Error(result.error);
        showToast(uuid ? '✓ Item updated!' : '✓ Item added!');
        closeModal();
        await loadAndDecryptEntries();
    } catch (err) {
        showToast('Error: ' + err.message, 'error');
    } finally {
        btn.disabled = false; btn.textContent = uuid ? 'Save Changes' : 'Add Item';
    }
});

// ── Favorite ──────────────────────────────────────────────────────────────────
async function toggleFav(uuid, btn) {
    const fd = new FormData(); fd.append('uuid', uuid);
    const data = await (await fetch('toggle_favorite.php', { method:'POST', body:fd })).json();
    if (data.success) {
        const active = btn.classList.toggle('active');
        btn.textContent = active ? '⭐' : '☆';
        showToast(active ? '⭐ Added to favorites' : '☆ Removed from favorites');
    }
}

// ── Delete ────────────────────────────────────────────────────────────────────
async function deleteItem(uuid, btn) {
    if (!confirm('Delete this item? This cannot be undone.')) return;
    const data = await (await fetch('../api/vault.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body:JSON.stringify({ action:'delete', uuid })
    })).json();
    if (data.ok) {
        const card = btn.closest('.item-card');
        card.style.transition = 'all .25s';
        card.style.opacity = '0'; card.style.transform = 'scale(.96)';
        setTimeout(() => { card.remove(); showToast('🗑 Deleted'); delete vault.entries[uuid]; }, 250);
    } else { showToast('Error: ' + (data.error || 'Unknown'), 'error'); }
}

// ── Copy username ─────────────────────────────────────────────────────────────
function copyUser(uuid) {
    if (!vault.key) { showToast('Unlock vault first', 'error'); return; }
    const text = vault.entries[uuid]?.username || '';
    if (!text) { showToast('No username to copy', 'error'); return; }
    navigator.clipboard.writeText(text).then(() => showToast('✓ Copied to clipboard'));
}

// ── Search ────────────────────────────────────────────────────────────────────
function filterCards(q) {
    document.querySelectorAll('.item-card').forEach(card => {
        card.style.display = (!q || card.dataset.search.includes(q) || card.dataset.type.includes(q)) ? '' : 'none';
    });
}
document.getElementById('searchInput').addEventListener('input', function() { filterCards(this.value.toLowerCase()); });

document.getElementById('itemModal').addEventListener('click', e => { if (e.target === document.getElementById('itemModal')) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
</body>
</html>

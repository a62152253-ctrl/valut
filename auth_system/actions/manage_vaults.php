<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = (int)$_SESSION['user_id'];

// Handle POST actions (create / rename / delete folder)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if (!in_array($action, ['create', 'rename', 'delete'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        exit;
    }

    if ($action === 'create') {
        $name  = trim($body['name'] ?? '');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $body['color'] ?? '') ? $body['color'] : '#5865f2';
        if (!$name || mb_strlen($name) > 100) {
            http_response_code(400); echo json_encode(['error' => 'Name required (max 100 chars)']); exit;
        }
        $stmt = $conn->prepare("INSERT INTO vault_folders (user_id, name, color) VALUES (?, ?, ?)");
        $stmt->bind_param('iss', $user_id, $name, $color);
        $stmt->execute();
        echo json_encode(['ok' => true, 'id' => $conn->insert_id]);
        exit;
    }

    if ($action === 'rename') {
        $id    = (int)($body['id'] ?? 0);
        $name  = trim($body['name'] ?? '');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $body['color'] ?? '') ? $body['color'] : '#5865f2';
        if (!$id || !$name || mb_strlen($name) > 100) {
            http_response_code(400); echo json_encode(['error' => 'id and name required']); exit;
        }
        $stmt = $conn->prepare("UPDATE vault_folders SET name=?, color=? WHERE id=? AND user_id=?");
        $stmt->bind_param('ssii', $name, $color, $id, $user_id);
        $stmt->execute();
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }
        // Null-out folder_id on entries rather than deleting them
        $stmt = $conn->prepare("UPDATE vault_entries SET folder_id=NULL WHERE folder_id=? AND user_id=?");
        $stmt->bind_param('ii', $id, $user_id);
        $stmt->execute();
        $stmt = $conn->prepare("DELETE FROM vault_folders WHERE id=? AND user_id=?");
        $stmt->bind_param('ii', $id, $user_id);
        $stmt->execute();
        echo json_encode(['ok' => true]);
        exit;
    }
}

// GET: load folders with item counts
$stmt = $conn->prepare(
    "SELECT vf.id, vf.name, vf.color, vf.created_at,
            COUNT(ve.id) AS item_count
     FROM vault_folders vf
     LEFT JOIN vault_entries ve ON ve.folder_id = vf.id AND ve.user_id = vf.user_id
     WHERE vf.user_id = ?
     GROUP BY vf.id
     ORDER BY vf.name ASC"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$folders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vaults – Vaultly</title>
<link rel="stylesheet" href="../css/app.css">
<style>
.vaults-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;}
.vault-card{background:var(--s1);border:1px solid var(--b1);border-radius:var(--r5);padding:22px 20px;transition:border-color var(--t);cursor:pointer;position:relative;}
.vault-card:hover{border-color:var(--b3);}
.vault-dot{width:14px;height:14px;border-radius:50%;display:inline-block;margin-right:8px;flex-shrink:0;}
.vault-name{font-size:15px;font-weight:700;color:var(--t0);display:flex;align-items:center;}
.vault-count{font-size:12px;color:var(--t3);margin-top:6px;}
.vault-date{font-size:11px;color:var(--t4);margin-top:4px;}
.vault-actions{display:flex;gap:8px;margin-top:16px;padding-top:14px;border-top:1px solid var(--b0);}
.vault-actions .btn-sm{flex:1;text-align:center;}
.color-swatches{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;}
.swatch{width:24px;height:24px;border-radius:50%;cursor:pointer;border:2px solid transparent;transition:border-color .15s;}
.swatch.active,.swatch:hover{border-color:var(--t0);}
</style>
</head>
<body>
<div class="layout">
<?php $active_nav = 'vaults'; include '../includes/sidebar.php'; ?>
<div class="main">
    <div class="page-header">
        <div>
            <div class="page-title">🗂 Vaults</div>
            <div class="page-subtitle"><?php echo count($folders); ?> folders</div>
        </div>
        <div class="header-right">
            <button class="btn-primary" onclick="openCreateModal()">＋ New Folder</button>
        </div>
    </div>

    <div class="vaults-grid stagger-in" id="vaultsGrid">
        <?php foreach ($folders as $f): ?>
        <div class="vault-card card-glow" id="folder-<?php echo $f['id']; ?>">
            <div class="vault-name">
                <span class="vault-dot" style="background:<?php echo htmlspecialchars($f['color']); ?>"></span>
                <?php echo htmlspecialchars($f['name']); ?>
            </div>
            <div class="vault-count"><?php echo (int)$f['item_count']; ?> item<?php echo $f['item_count'] != 1 ? 's' : ''; ?></div>
            <div class="vault-date">Created <?php echo date('M d, Y', strtotime($f['created_at'])); ?></div>
            <div class="vault-actions">
                <a href="view_all_items.php?folder=<?php echo $f['id']; ?>" class="btn-sm btn-edit">Open</a>
                <button class="btn-sm btn-edit" onclick="openRenameModal(<?php echo $f['id']; ?>, <?php echo htmlspecialchars(json_encode($f['name']), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($f['color']), ENT_QUOTES); ?>)">Rename</button>
                <button class="btn-sm btn-del"  onclick="deleteFolder(<?php echo $f['id']; ?>, this)">Delete</button>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($folders)): ?>
        <div style="grid-column:1/-1;">
            <div class="empty-state">
                <span class="empty-icon">🗂</span>
                <h3>No folders yet</h3>
                <p>Create folders to organise your vault items.</p>
                <button class="btn-primary" onclick="openCreateModal()">＋ New Folder</button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- Create / Rename modal -->
<div id="folderModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2 id="modalTitle">New Folder</h2>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <form id="folderForm">
            <input type="hidden" id="editId" value="">
            <div class="form-group">
                <label for="folderName">Folder Name</label>
                <input type="text" id="folderName" placeholder="e.g. Work, Personal" required maxlength="100">
            </div>
            <div class="form-group">
                <label>Colour</label>
                <div class="color-swatches" id="colorSwatches"></div>
                <input type="hidden" id="folderColor" value="#5865f2">
            </div>
            <div class="modal-actions">
                <button type="submit" class="btn-save" id="saveBtn">Create</button>
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div id="toast" class="toast"></div>
<script>
const COLORS = ['#5865f2','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#f97316','#ec4899','#6b7280'];

function buildSwatches(selected) {
    const wrap = document.getElementById('colorSwatches');
    wrap.innerHTML = COLORS.map(c =>
        `<span class="swatch${c===selected?' active':''}" style="background:${c}" data-color="${c}" onclick="pickColor('${c}')"></span>`
    ).join('');
    document.getElementById('folderColor').value = selected;
}

function pickColor(c) {
    document.querySelectorAll('.swatch').forEach(s => s.classList.toggle('active', s.dataset.color === c));
    document.getElementById('folderColor').value = c;
}

function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    t.textContent = msg; t.className = 'toast show ' + type;
    setTimeout(() => t.className = 'toast', 2800);
}

function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'New Folder';
    document.getElementById('saveBtn').textContent    = 'Create';
    document.getElementById('folderForm').reset();
    document.getElementById('editId').value = '';
    buildSwatches('#5865f2');
    document.getElementById('folderModal').classList.add('open');
    setTimeout(() => document.getElementById('folderName').focus(), 80);
}

function openRenameModal(id, name, color) {
    document.getElementById('modalTitle').textContent = 'Rename Folder';
    document.getElementById('saveBtn').textContent    = 'Save';
    document.getElementById('editId').value    = id;
    document.getElementById('folderName').value = name;
    buildSwatches(color);
    document.getElementById('folderModal').classList.add('open');
    setTimeout(() => document.getElementById('folderName').focus(), 80);
}

function closeModal() { document.getElementById('folderModal').classList.remove('open'); }

document.getElementById('folderForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const id    = document.getElementById('editId').value;
    const name  = document.getElementById('folderName').value.trim();
    const color = document.getElementById('folderColor').value;
    const btn   = document.getElementById('saveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    try {
        const action = id ? 'rename' : 'create';
        const payload = id ? { action, id: parseInt(id), name, color } : { action, name, color };
        const res = await fetch('', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        showToast(id ? '✓ Folder renamed' : '✓ Folder created');
        closeModal();
        setTimeout(() => location.reload(), 400);
    } catch(err) { showToast('Error: ' + err.message, 'error'); }
    finally { btn.disabled = false; btn.textContent = id ? 'Save' : 'Create'; }
});

async function deleteFolder(id, btn) {
    if (!confirm('Delete this folder? Items inside will be moved to the root vault.')) return;
    try {
        const data = await (await fetch('', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ action:'delete', id }) })).json();
        if (data.error) throw new Error(data.error);
        const card = document.getElementById('folder-' + id);
        card.style.opacity = '0'; card.style.transform = 'scale(.96)';
        setTimeout(() => { card.remove(); showToast('🗑 Folder deleted'); }, 250);
    } catch(err) { showToast('Error: ' + err.message, 'error'); }
}

document.getElementById('folderModal').addEventListener('click', e => { if (e.target === document.getElementById('folderModal')) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
</body>
</html>

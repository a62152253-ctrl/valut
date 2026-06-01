<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

$user_id   = (int)$_SESSION['user_id'];
$sort      = $_GET['sort'] ?? 'newest';
$order_sql = $sort === 'oldest' ? 'created_at ASC' : 'created_at DESC';

$stmt = $conn->prepare(
    "SELECT uuid, type, favorite, created_at, updated_at
     FROM vault_entries WHERE user_id = ? AND type = 'identity' ORDER BY $order_sql"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total = count($entries);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Identities – Vaultly</title>
<link rel="stylesheet" href="../css/app.css">
<style>
.item-card-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px;}
.item-icon{width:42px;height:42px;border-radius:var(--r3);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;background:var(--green-s);}
.item-title{font-size:14.5px;font-weight:600;margin-top:5px;color:var(--t0);letter-spacing:-.2px;}
.item-title.locked{color:var(--t3);font-weight:400;font-style:italic;}
.item-meta{font-size:12px;color:var(--t3);display:flex;align-items:center;gap:5px;margin-top:2px;}
.item-actions{display:flex;gap:7px;margin-top:12px;padding-top:12px;border-top:1px solid var(--b0);}
.item-actions .btn-sm{flex:1;text-align:center;}
.sort-select{padding:7px 12px;background:var(--s2);border:1px solid var(--b1);color:var(--t2);border-radius:var(--r2);font-size:12.5px;cursor:pointer;outline:none;font-family:inherit;}
.vault-lock-overlay{position:fixed;inset:0;background:rgba(8,8,18,.88);backdrop-filter:blur(6px);z-index:9000;display:flex;align-items:center;justify-content:center;}
.vault-lock-overlay.hidden{display:none;}
.vault-lock-box{background:var(--s1);border:1px solid var(--b2);border-radius:var(--r5);padding:36px 32px;width:100%;max-width:400px;text-align:center;}
.vault-lock-box .lock-icon{font-size:40px;margin-bottom:12px;}
.vault-lock-box h2{font-size:20px;font-weight:700;margin-bottom:6px;}
.vault-lock-box p{font-size:13px;color:var(--t3);margin-bottom:22px;}
.vault-lock-box input{width:100%;padding:11px 14px;background:var(--s2);border:1px solid var(--b1);color:var(--t0);border-radius:var(--r2);font-size:14px;margin-bottom:12px;font-family:inherit;outline:none;transition:border-color var(--t);box-sizing:border-box;}
.vault-lock-box input:focus{border-color:var(--a3);}
.unlock-err{color:var(--red);font-size:12.5px;margin-bottom:10px;min-height:18px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
</style>
</head>
<body>
<div class="layout">
<?php $active_nav = 'identities'; include '../includes/sidebar.php'; ?>
<div class="main">
    <div class="page-header">
        <div>
            <div class="page-title">👤 Identities</div>
            <div class="page-subtitle"><?php echo $total; ?> identity profiles</div>
        </div>
        <div class="header-right">
            <div class="search-bar">
                <span class="search-icon">🔍</span>
                <input type="text" id="searchInput" placeholder="Search identities…">
            </div>
            <button class="btn-primary" onclick="openAddModal()">＋ Add Identity</button>
        </div>
    </div>

    <div style="display:flex;justify-content:flex-end;margin-bottom:var(--s-6);">
        <select class="sort-select" onchange="window.location='?sort='+this.value">
            <option value="newest" <?php echo $sort==='newest'?'selected':'';?>>Newest first</option>
            <option value="oldest" <?php echo $sort==='oldest'?'selected':'';?>>Oldest first</option>
        </select>
    </div>

    <div class="items-grid stagger-in" id="itemsGrid">
        <?php if (count($entries) > 0): ?>
            <?php foreach ($entries as $entry): ?>
            <div class="item-card card-glow"
                 data-uuid="<?php echo htmlspecialchars($entry['uuid']); ?>"
                 data-type="identity" data-search="">
                <div class="item-card-top">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div class="item-icon">👤</div>
                        <div>
                            <span class="type-badge identity">identity</span>
                            <div class="item-title locked" id="title-<?php echo $entry['uuid']; ?>">🔒 Encrypted</div>
                        </div>
                    </div>
                    <button class="fav-btn <?php echo $entry['favorite']?'active':''; ?>"
                            onclick="toggleFav('<?php echo $entry['uuid']; ?>', this)">
                        <?php echo $entry['favorite']?'⭐':'☆'; ?>
                    </button>
                </div>
                <div class="item-meta" id="meta-<?php echo $entry['uuid']; ?>"></div>
                <div class="item-meta">Added <?php echo date('M d, Y', strtotime($entry['created_at'])); ?></div>
                <div class="item-actions">
                    <button class="btn-sm btn-edit" onclick="openEditModal('<?php echo $entry['uuid']; ?>')">Edit</button>
                    <button class="btn-sm btn-del"  onclick="deleteItem('<?php echo $entry['uuid']; ?>', this)">Delete</button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="grid-column:1/-1;">
                <div class="empty-state">
                    <span class="empty-icon">👤</span>
                    <h3>No identities saved</h3>
                    <p>Store personal documents and ID information securely.</p>
                    <button class="btn-primary" onclick="openAddModal()">＋ Add Identity</button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>

<div id="vaultLockOverlay" class="vault-lock-overlay">
    <div class="vault-lock-box">
        <div class="lock-icon">👤</div>
        <h2>Unlock Vault</h2>
        <p>Enter your master password to access identities.</p>
        <input type="password" id="masterPwdInput" placeholder="Master password…" autocomplete="current-password">
        <div class="unlock-err" id="unlockErr"></div>
        <button class="btn-primary" style="width:100%" id="unlockBtn" onclick="doUnlock()">Unlock</button>
    </div>
</div>

<div id="itemModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <h2 id="modalTitle">Add Identity</h2>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <form id="itemForm">
            <input type="hidden" id="editUuid" value="">
            <div class="form-group"><label>Label / Name</label><input type="text" id="editTitle" placeholder="e.g. Personal ID" required></div>
            <div class="form-row">
                <div class="form-group"><label>First Name</label><input type="text" id="editFirstName" placeholder="First name"></div>
                <div class="form-group"><label>Last Name</label><input type="text" id="editLastName" placeholder="Last name"></div>
            </div>
            <div class="form-group"><label>Email</label><input type="email" id="editEmail" placeholder="email@example.com"></div>
            <div class="form-group"><label>Phone</label><input type="tel" id="editPhone" placeholder="+1 555 000 0000"></div>
            <div class="form-group"><label>Address</label><input type="text" id="editAddress" placeholder="Street address"></div>
            <div class="form-row">
                <div class="form-group"><label>City</label><input type="text" id="editCity" placeholder="City"></div>
                <div class="form-group"><label>ZIP / Postal</label><input type="text" id="editZip" placeholder="ZIP"></div>
            </div>
            <div class="form-group"><label>Country</label><input type="text" id="editCountry" placeholder="Country"></div>
            <div class="form-group"><label>Notes</label><textarea id="editNotes" placeholder="Additional info…"></textarea></div>
            <div class="modal-actions">
                <button type="submit" class="btn-save" id="saveBtn">Save Identity</button>
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div id="toast" class="toast"></div>
<script src="../js/crypto-engine.js"></script>
<script>
const vault = { key: null, entries: {} };
function showToast(msg,type='success'){const t=document.getElementById('toast');t.textContent=msg;t.className='toast show '+type;setTimeout(()=>t.className='toast',2800);}
async function doUnlock(){
    const pwd=document.getElementById('masterPwdInput').value,btn=document.getElementById('unlockBtn'),err=document.getElementById('unlockErr');
    if(!pwd){err.textContent='Enter your master password.';return;}
    btn.textContent='Unlocking…';btn.disabled=true;err.textContent='';
    try{
        const sd=await(await fetch('../api/salt.php')).json();
        if(!sd.salt)throw new Error('Vault not initialized.');
        const key=await CryptoEngine.deriveKey(pwd,sd.salt);
        if(await CryptoEngine.decrypt(key,sd.verification_iv,sd.verification_blob)!=='vault:ok')throw new Error('Wrong master password.');
        vault.key=key;document.getElementById('vaultLockOverlay').classList.add('hidden');await loadEntries();
    }catch(e){err.textContent=e.message;}finally{btn.textContent='Unlock';btn.disabled=false;}
}
document.getElementById('masterPwdInput').addEventListener('keydown',e=>{if(e.key==='Enter')doUnlock();});
async function loadEntries(){
    const data=await(await fetch('../api/vault.php')).json();vault.entries={};
    for(const row of(data.entries||[])){if(row.type!=='identity')continue;try{vault.entries[row.uuid]=await CryptoEngine.decrypt(vault.key,row.iv,row.encrypted_data);}catch{}}
    applyDecryptedData();
}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function applyDecryptedData(){
    for(const[uuid,d]of Object.entries(vault.entries)){
        const titleEl=document.getElementById('title-'+uuid),metaEl=document.getElementById('meta-'+uuid),card=document.querySelector(`[data-uuid="${uuid}"]`);
        if(!titleEl)continue;
        titleEl.textContent=d.title||'Untitled';titleEl.classList.remove('locked');
        if(card)card.dataset.search=(d.title||'').toLowerCase();
        if(metaEl){const name=[d.firstName,d.lastName].filter(Boolean).join(' ');metaEl.innerHTML=name?`👤 ${esc(name)}`:d.email?`✉ ${esc(d.email)}`:'';};
    }
    const q=document.getElementById('searchInput').value.toLowerCase();if(q)filterCards(q);
}
function openAddModal(){
    if(!vault.key){showToast('Unlock vault first','error');return;}
    document.getElementById('modalTitle').textContent='Add Identity';document.getElementById('saveBtn').textContent='Save Identity';
    document.getElementById('itemForm').reset();document.getElementById('editUuid').value='';
    document.getElementById('itemModal').classList.add('open');setTimeout(()=>document.getElementById('editTitle').focus(),80);
}
function openEditModal(uuid){
    if(!vault.key){showToast('Unlock vault first','error');return;}
    const d=vault.entries[uuid]||{};
    document.getElementById('modalTitle').textContent='Edit Identity';document.getElementById('saveBtn').textContent='Save Changes';
    document.getElementById('editUuid').value=uuid;document.getElementById('editTitle').value=d.title||'';
    document.getElementById('editFirstName').value=d.firstName||'';document.getElementById('editLastName').value=d.lastName||'';
    document.getElementById('editEmail').value=d.email||'';document.getElementById('editPhone').value=d.phone||'';
    document.getElementById('editAddress').value=d.address||'';document.getElementById('editCity').value=d.city||'';
    document.getElementById('editZip').value=d.zip||'';document.getElementById('editCountry').value=d.country||'';
    document.getElementById('editNotes').value=d.notes||'';document.getElementById('itemModal').classList.add('open');
}
function closeModal(){document.getElementById('itemModal').classList.remove('open');}
document.getElementById('itemForm').addEventListener('submit',async function(e){
    e.preventDefault();if(!vault.key){showToast('Vault is locked','error');return;}
    const uuid=document.getElementById('editUuid').value;
    const entry={title:document.getElementById('editTitle').value,firstName:document.getElementById('editFirstName').value,lastName:document.getElementById('editLastName').value,email:document.getElementById('editEmail').value,phone:document.getElementById('editPhone').value,address:document.getElementById('editAddress').value,city:document.getElementById('editCity').value,zip:document.getElementById('editZip').value,country:document.getElementById('editCountry').value,notes:document.getElementById('editNotes').value};
    const btn=document.getElementById('saveBtn');btn.disabled=true;btn.textContent='Saving…';
    try{
        const{iv,data:encData}=await CryptoEngine.encrypt(vault.key,entry);
        if(new TextEncoder().encode(encData).length>5000000)throw new Error('Entry too large.');
        const result=await(await fetch('../api/vault.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:uuid?'update':'create',uuid:uuid||undefined,type:'identity',encrypted_data:encData,iv,favorite:0})})).json();
        if(result.error)throw new Error(result.error);
        showToast(uuid?'✓ Updated!':'✓ Saved!');closeModal();await loadEntries();
    }catch(err){showToast('Error: '+err.message,'error');}finally{btn.disabled=false;btn.textContent=uuid?'Save Changes':'Save Identity';}
});
async function toggleFav(uuid,btn){const fd=new FormData();fd.append('uuid',uuid);const d=await(await fetch('toggle_favorite.php',{method:'POST',body:fd})).json();if(d.success){const active=btn.classList.toggle('active');btn.textContent=active?'⭐':'☆';}}
async function deleteItem(uuid,btn){if(!confirm('Delete this identity?'))return;const d=await(await fetch('../api/vault.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',uuid})})).json();if(d.ok){const c=btn.closest('.item-card');c.style.opacity='0';setTimeout(()=>{c.remove();showToast('🗑 Deleted');},250);}else showToast('Error: '+(d.error||'Unknown'),'error');}
function filterCards(q){document.querySelectorAll('.item-card').forEach(c=>{c.style.display=(!q||c.dataset.search.includes(q))?'':'none';});}
document.getElementById('searchInput').addEventListener('input',function(){filterCards(this.value.toLowerCase());});
document.getElementById('itemModal').addEventListener('click',e=>{if(e.target===document.getElementById('itemModal'))closeModal();});
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal();});
</script>
</body>
</html>

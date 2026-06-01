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

// Only metadata — encrypted_data never decoded server-side
$stmt = $conn->prepare(
    "SELECT uuid, type, favorite, created_at, updated_at
     FROM vault_entries WHERE user_id = ? AND favorite = 1 ORDER BY $order_sql"
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
<title>Favorites – Vaultly</title>
<link rel="stylesheet" href="../css/app.css">
<style>
.item-card-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:10px;}
.item-icon{width:42px;height:42px;border-radius:var(--r3);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.item-icon.login{background:var(--a-dim)}.item-icon.note{background:var(--amber-s)}.item-icon.card{background:var(--red-s)}.item-icon.identity{background:var(--green-s)}
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
</style>
</head>
<body>
<div class="layout">
<?php $active_nav = 'favorites'; include '../includes/sidebar.php'; ?>
<div class="main">
    <div class="page-header">
        <div>
            <div class="page-title">⭐ Favorites</div>
            <div class="page-subtitle"><?php echo $total; ?> starred items</div>
        </div>
        <div class="header-right">
            <div class="search-bar">
                <span class="search-icon">🔍</span>
                <input type="text" id="searchInput" placeholder="Search favorites…">
            </div>
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
            <?php foreach ($entries as $entry):
                $type  = $entry['type'];
                $icons = ['login'=>'🔐','note'=>'📝','card'=>'💳','identity'=>'👤'];
            ?>
            <div class="item-card card-glow"
                 data-uuid="<?php echo htmlspecialchars($entry['uuid']); ?>"
                 data-type="<?php echo $type; ?>" data-search="">
                <div class="item-card-top">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div class="item-icon <?php echo $type; ?>"><?php echo $icons[$type]??'?'; ?></div>
                        <div>
                            <span class="type-badge <?php echo $type; ?>"><?php echo $type; ?></span>
                            <div class="item-title locked" id="title-<?php echo $entry['uuid']; ?>">🔒 Encrypted</div>
                        </div>
                    </div>
                    <button class="fav-btn active" onclick="toggleFav('<?php echo $entry['uuid']; ?>', this)" title="Remove from favorites">⭐</button>
                </div>
                <div class="item-meta" id="meta-<?php echo $entry['uuid']; ?>"></div>
                <div class="item-meta">Added <?php echo date('M d, Y', strtotime($entry['created_at'])); ?></div>
                <div class="item-actions">
                    <button class="btn-sm btn-copy" onclick="copyUser('<?php echo $entry['uuid']; ?>')">Copy user</button>
                    <button class="btn-sm btn-del"  onclick="deleteItem('<?php echo $entry['uuid']; ?>', this)">Delete</button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="grid-column:1/-1;">
                <div class="empty-state">
                    <span class="empty-icon">⭐</span>
                    <h3>No favorites yet</h3>
                    <p>Star items in your vault to see them here.</p>
                    <a href="view_all_items.php" class="btn-primary">Browse All Items</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>

<div id="vaultLockOverlay" class="vault-lock-overlay">
    <div class="vault-lock-box">
        <div class="lock-icon">⭐</div>
        <h2>Unlock Vault</h2>
        <p>Enter your master password to view favorites.</p>
        <input type="password" id="masterPwdInput" placeholder="Master password…" autocomplete="current-password">
        <div class="unlock-err" id="unlockErr"></div>
        <button class="btn-primary" style="width:100%" id="unlockBtn" onclick="doUnlock()">Unlock</button>
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
        const test=await CryptoEngine.decrypt(key,sd.verification_iv,sd.verification_blob);
        if(test!=='vault:ok')throw new Error('Wrong master password.');
        vault.key=key;
        document.getElementById('vaultLockOverlay').classList.add('hidden');
        await loadEntries();
    }catch(e){err.textContent=e.message;}
    finally{btn.textContent='Unlock';btn.disabled=false;}
}
document.getElementById('masterPwdInput').addEventListener('keydown',e=>{if(e.key==='Enter')doUnlock();});
async function loadEntries(){
    const data=await(await fetch('../api/vault.php')).json();
    vault.entries={};
    for(const row of(data.entries||[])){
        try{vault.entries[row.uuid]=await CryptoEngine.decrypt(vault.key,row.iv,row.encrypted_data);}catch{}
    }
    applyDecryptedData();
}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function applyDecryptedData(){
    for(const[uuid,d]of Object.entries(vault.entries)){
        const titleEl=document.getElementById('title-'+uuid),metaEl=document.getElementById('meta-'+uuid),card=document.querySelector(`[data-uuid="${uuid}"]`);
        if(!titleEl)continue;
        titleEl.textContent=d.title||'Untitled';titleEl.classList.remove('locked');
        if(card)card.dataset.search=(d.title||'').toLowerCase();
        if(metaEl)metaEl.innerHTML=d.username?`👤 ${esc(d.username)}`:d.url?`🔗 ${esc(d.url)}`:'';
    }
    const q=document.getElementById('searchInput').value.toLowerCase();if(q)filterCards(q);
}
async function toggleFav(uuid,btn){
    const fd=new FormData();fd.append('uuid',uuid);
    const d=await(await fetch('toggle_favorite.php',{method:'POST',body:fd})).json();
    if(d.success){const card=btn.closest('.item-card');card.style.opacity='0';setTimeout(()=>{card.remove();showToast('☆ Removed from favorites');},250);}
}
async function deleteItem(uuid,btn){
    if(!confirm('Delete this item?'))return;
    const d=await(await fetch('../api/vault.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'delete',uuid})})).json();
    if(d.ok){const card=btn.closest('.item-card');card.style.opacity='0';setTimeout(()=>{card.remove();showToast('🗑 Deleted');},250);}
    else showToast('Error: '+(d.error||'Unknown'),'error');
}
function copyUser(uuid){
    if(!vault.key){showToast('Unlock vault first','error');return;}
    const text=vault.entries[uuid]?.username||'';
    if(!text){showToast('No username to copy','error');return;}
    navigator.clipboard.writeText(text).then(()=>showToast('✓ Copied'));
}
function filterCards(q){document.querySelectorAll('.item-card').forEach(c=>{c.style.display=(!q||c.dataset.search.includes(q))?'':'none';});}
document.getElementById('searchInput').addEventListener('input',function(){filterCards(this.value.toLowerCase());});
</script>
</body>
</html>

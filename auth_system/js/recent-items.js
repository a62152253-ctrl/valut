// Recently Used Items Tracker

async function logRecentItemUsed(entryUuid, entryTitle, entryType) {
    try {
        const response = await fetch('api/recent.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                entry_uuid: entryUuid,
                entry_title: entryTitle,
                entry_type: entryType
            })
        });
        const data = await response.json();
        return data.ok;
    } catch (e) {
        console.error('Failed to log recent item:', e);
        return false;
    }
}

async function loadRecentItems() {
    try {
        const response = await fetch('api/recent.php?limit=3');
        const data = await response.json();
        return data.items || [];
    } catch (e) {
        console.error('Failed to load recent items:', e);
        return [];
    }
}

function renderRecentItems(items) {
    if (!items || items.length === 0) return '';
    
    const colors = [
        'linear-gradient(135deg,#6366f1,#8b5cf6)',
        'linear-gradient(135deg,#f59e0b,#ec4899)',
        'linear-gradient(135deg,#22c55e,#16a34a)'
    ];
    
    return `<div class="d-card" style="margin-bottom:1.5rem;">
        <div class="d-card-header">
            <div class="d-card-title">Recently used</div>
        </div>
        <div class="d-qa-list">
            ${items.map((item, i) => {
                const color = colors[i % colors.length];
                const title = escapeHtml(item.entry_title || 'Untitled');
                const type = escapeHtml(item.entry_type);
                const date = new Date(item.accessed_at);
                const timeStr = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                
                return `<div class="d-qa-item" style="opacity:0.85;">
                    <div class="d-qa-icon" style="background:${color}; font-size:1.3rem;">${item.icon}</div>
                    <div class="d-qa-info">
                        <div class="d-qa-name">${title}</div>
                        <div class="d-qa-user">${type.charAt(0).toUpperCase() + type.slice(1)} • ${timeStr}</div>
                    </div>
                </div>`;
            }).join('')}
        </div>
    </div>`;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

// Load and inject recently used items on page load
async function initRecentItems() {
    const recentContainer = document.getElementById('recentItemsContainer');
    if (!recentContainer) return;
    
    const items = await loadRecentItems();
    if (items.length > 0) {
        recentContainer.innerHTML = renderRecentItems(items);
    }
}

// Call on DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initRecentItems);
} else {
    initRecentItems();
}

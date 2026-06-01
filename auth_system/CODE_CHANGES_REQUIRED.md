# Recently Used Items - Exact Code Changes Needed

## File: dashboard.php

### Change 1: Already Done ✅
Verify this is at the top (should already be there):

```php
<?php
session_start();
include 'includes/db.php';
include 'includes/recent-items.php';  // ← This line must exist
```

### Change 2: Add Container (in HTML section)

**Find this section** (around line 850-900):
```html
                    <!-- Your vaults -->
                    <div class="d-card">
                        <div class="d-card-header">
                            <div class="d-card-title">Your vaults</div>
```

**Add this RIGHT BEFORE it:**
```html
                    <!-- Recently Used Items -->
                    <div id="recentItemsContainer"></div>

```

### Change 3: Add Script Tag (at the end)

**Find this section** (near the end, before `</body>`):
```html
<script src="js/vault-ui.js"></script>

<script>
const USER_ID = <?php echo $user_id; ?>;
```

**Add this line** between the script tags:
```html
<script src="js/recent-items.js"></script>

<script>
const USER_ID = <?php echo $user_id; ?>;
```

---

## File: vault.php or Any Item Viewer

When displaying vault items, add this tracking call.

### Example 1: On Item View Modal

```javascript
// When showing item details (in vault.php or modal):
async function displayItemDetails(entry) {
    // Log access
    await fetch('api/recent.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            entry_uuid: entry.uuid,
            entry_title: entry.title,
            entry_type: entry.type
        })
    });
    
    // Show the item...
    showModal(entry);
}
```

### Example 2: On Item Click

```html
<!-- In any item list -->
<div class="item-card" onclick="onItemClicked(this)">
    <div class="item-title" data-uuid="abc123" 
         data-title="GitHub Account" data-type="login">
        GitHub
    </div>
</div>

<script>
async function onItemClicked(element) {
    const uuid = element.querySelector('.item-title').getAttribute('data-uuid');
    const title = element.querySelector('.item-title').getAttribute('data-title');
    const type = element.querySelector('.item-title').getAttribute('data-type');
    
    // Track access
    await logRecentItemUsed(uuid, title, type);
    
    // Show item details
    viewItem(uuid);
}
</script>
```

### Example 3: Copy Button Click

```javascript
// When user copies password or username
async function copyUsername(uuid, title, type, value) {
    // Track access
    await logRecentItemUsed(uuid, title, type);
    
    // Copy to clipboard
    navigator.clipboard.writeText(value);
}
```

---

## File: includes/recent-items.php

**No changes needed!** This file is complete and ready.

To customize service icons, edit this section:

```php
function getServiceIcon($title) {
    $title = strtolower($title);
    $services = [
        'google' => '🔍',
        'github' => '🐙',
        'notion' => '📋',
        'gmail' => '📧',
        // ... existing services
        
        // ADD YOUR CUSTOM SERVICES HERE:
        'my_custom_app' => '🎯',
        'company_portal' => '🏢',
    ];
    
    // ... rest of function
}
```

---

## File: api/recent.php

**No changes needed!** This file is complete and ready.

---

## File: js/recent-items.js

**No changes needed!** This file is complete and ready.

---

## Summary of Changes

| File | Change | Lines | Status |
|------|--------|-------|--------|
| dashboard.php | Add `include 'includes/recent-items.php'` | Already added | ✅ |
| dashboard.php | Add container `<div id="recentItemsContainer"></div>` | 1 line | ⚠️ To do |
| dashboard.php | Add `<script src="js/recent-items.js"></script>` | 1 line | ⚠️ To do |
| vault.php | Add tracking calls on item view | Variable | ⚠️ Optional |
| Other files | None | - | ✅ |

---

## Verification Steps

After making changes:

1. **Check dashboard loads**
   - No JavaScript errors in console
   - Container div is visible

2. **Test service icon detection**
   - Visit `/test-recent-items.php`
   - Should show icons for all services

3. **Test tracking**
   - Add this to browser console: `logRecentItemUsed('test-uuid', 'GitHub', 'login')`
   - Should return `{ok: true}`

4. **Check database**
   ```sql
   SELECT * FROM vault_recent LIMIT 5;
   ```
   Should show tracked items

5. **Test dashboard display**
   - Refresh dashboard
   - Recently used items should appear

---

## Code Snippets Ready to Copy

### Full JS Tracking Example
```javascript
async function handleItemAccess(itemUuid, itemTitle, itemType) {
    try {
        const response = await fetch('api/recent.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                entry_uuid: itemUuid,
                entry_title: itemTitle,
                entry_type: itemType
            })
        });
        
        if (response.ok) {
            console.log('Item access logged');
            return true;
        }
    } catch (error) {
        console.error('Failed to log item access:', error);
    }
    return false;
}

// Usage:
// handleItemAccess('550e8400-e29b-41d4-a716-446655440000', 'GitHub', 'login');
```

### Full PHP Tracking Example
```php
<?php
session_start();
include 'includes/db.php';
include 'includes/recent-items.php';

$user_id = $_SESSION['user_id'];
$entry_uuid = 'abc123';
$entry_title = 'GitHub Account';
$entry_type = 'login';

if (logRecentItem($user_id, $entry_uuid, $entry_title, $entry_type)) {
    echo "Tracked successfully";
} else {
    echo "Failed to track";
}
?>
```

---

## Next Steps

1. Make the 2 dashboard.php changes above
2. Test by visiting dashboard → should load without errors
3. Add tracking calls to your item viewer
4. Test by opening an item → refresh dashboard → item appears!
5. Done! 🎉

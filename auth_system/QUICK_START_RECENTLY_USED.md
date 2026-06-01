# Recently Used Items - Quick Start (5 minutes)

## ✅ What's Already Done

- ✅ Database table created (`vault_recent`)
- ✅ Helper functions ready (`includes/recent-items.php`)
- ✅ API endpoint working (`api/recent.php`)
- ✅ Frontend JS ready (`js/recent-items.js`)
- ✅ Dashboard backend updated (`dashboard.php`)

## 🚀 To Activate (3 Steps)

### Step 1: Open `dashboard.php`

Find the **"Your vaults"** card section. It looks like:
```php
<!-- Your vaults -->
<div class="d-card">
    <div class="d-card-header">
        <div class="d-card-title">Your vaults</div>
```

**RIGHT BEFORE** this section, add:
```html
<!-- Recently Used Items -->
<div id="recentItemsContainer"></div>
```

### Step 2: Add Script Tag

At the very end of `dashboard.php`, find:
```html
<script src="js/vault-ui.js"></script>

<script>
const USER_ID = <?php echo $user_id; ?>;
```

Add this line right before `<script>`:
```html
<script src="js/recent-items.js"></script>
```

### Step 3: Test It

1. Open browser → navigate to dashboard
2. You should see "Recently used" section (empty on first load)
3. Open any vault item in your app
4. Call the tracking function (see Step 4 below)
5. Refresh dashboard → item appears!

---

## 📍 Step 4: Track Item Access (Optional but Recommended)

When a user opens/views a vault item, you need to log it. Choose one approach:

### Approach A: From Server (PHP)
In `actions/view_all_items.php` or wherever items are displayed:

```php
<?php
include '../includes/recent-items.php';

// When showing an item detail:
logRecentItem(
    $user_id,           // Current user ID
    $uuid,              // Item UUID from database
    $title,             // Item title (from decrypted data)
    $type               // Item type: 'login', 'note', 'card', 'identity'
);
?>
```

### Approach B: From JavaScript
In any view that displays items:

```html
<button onclick="viewItem('uuid123', 'GitHub', 'login')">Open</button>

<script src="js/recent-items.js"></script>
<script>
function viewItem(uuid, title, type) {
    // Log the access
    logRecentItemUsed(uuid, title, type);
    // Then show the item...
}
</script>
```

---

## 🎯 Expected Result

After completing setup, your dashboard shows:

```
┌──────────────────────────────────┐
│ Recently used                    │
├──────────────────────────────────┤
│ 🐙 GitHub Account               │
│ Login • Dec 15                   │
│                                  │
│ 🔍 Google Gmail                  │
│ Login • Dec 14                   │
│                                  │
│ 📋 Notion Workspace              │
│ Note • Dec 13                    │
└──────────────────────────────────┘
```

Service icons auto-detect from title:
- **GitHub** → 🐙
- **Google** → 🔍  
- **Notion** → 📋
- **Gmail** → 📧
- **Minecraft** → ⛏️
- **Discord** → 💬
- **And 50+ more...**

---

## 📋 Checklist

- [ ] Read this file
- [ ] Added `<div id="recentItemsContainer"></div>` to dashboard.php
- [ ] Added `<script src="js/recent-items.js"></script>` to dashboard.php
- [ ] Plan where/how to track item access in your app
- [ ] (Optional) Add tracking calls to item viewer
- [ ] Test: Open item → check dashboard

---

## 🧪 Test Without Modifying Code

Visit: `/test-recent-items.php`

This shows:
- Service icon detection
- Database connectivity
- Sample output format

---

## 📚 Full Documentation

See `RECENTLY_USED_FEATURE_DOCS.md` for:
- Complete API reference
- Database schema details
- All 60+ supported services
- Troubleshooting guide
- Performance optimization

---

## ❓ Common Questions

**Q: Does this require user action?**  
A: No! Once you add the tracking call, it works automatically when users view items.

**Q: How many items show?**  
A: Default is 3 most recent. Change in `includes/recent-items-html.php` or `js/recent-items.js`

**Q: Can I add custom icons?**  
A: Yes! Edit the `$services` array in `includes/recent-items.php`

**Q: Is it secure?**  
A: Yes! Only authenticated users see their own items. No sensitive data exposed.

**Q: What if service isn't recognized?**  
A: Shows generic pin emoji 📌 as fallback.

---

## 🎉 You're Done!

The feature is ready to go. Just add the 2 lines to dashboard.php and optionally add tracking calls in your item viewer.

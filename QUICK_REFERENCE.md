# Quick Reference Card - New Dashboard

## 📋 What Changed?

### Before: Mock Data
```php
// Old dashboard.php
$quick_access = [
    ['name' => 'Google', 'email' => 'maleusz@gmail.com', ...],
    ['name' => 'GitHub', 'email' => 'mateusz@github', ...],
];
```

### After: Real Database
```php
// New dashboard.php
$stmt = $conn->prepare(
    "SELECT type, COUNT(*) FROM vault_entries WHERE user_id = ? GROUP BY type"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
```

## 🎯 Key Features

### Real-time Stats
```javascript
// Pull from vault_entries table
Total Items: SELECT COUNT(*)
Login Items: WHERE type = 'login'
Notes: WHERE type = 'note'  
Cards: WHERE type = 'card'
```

### Add Password Modal
```html
<form id="addPasswordForm">
  <input type="text" id="itemTitle" /> (Title)
  <input type="text" id="itemUsername" /> (Username)
  <input type="password" id="itemPassword" /> (Password)
  <input type="url" id="itemURL" /> (Website)
  <textarea id="itemNotes" /> (Notes)
  <select id="itemType"> (Type: login/note/card/identity)
    <button type="submit">Save Password</button>
</form>
```

## 📊 Database Queries

### Get Stats
```sql
SELECT type, COUNT(*) FROM vault_entries 
WHERE user_id = ? GROUP BY type
```

### Get Recent Activity
```sql
SELECT uuid, type, updated_at FROM vault_entries
WHERE user_id = ? ORDER BY updated_at DESC LIMIT 10
```

### Save Password
```sql
INSERT INTO vault_entries (user_id, uuid, type, encrypted_data, iv, favorite)
VALUES (?, ?, ?, ?, ?, ?)
```

## 🔐 JavaScript Functions

```javascript
// Open add password modal
openAddPasswordModal()

// Close modal
closeAddPasswordModal()

// Save password to vault (with encryption)
async savePassword(event) {
  const data = {
    type, title, username, password, url, notes
  };
  const result = await VaultManager.saveEntry(data);
}

// Copy to clipboard
copyToClipboard(text)
```

## ⌨️ Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Ctrl+K` | Focus search |
| `Ctrl+N` | Open add password |
| `Escape` | Close modal |

## 📁 Files

```
auth_system/
├── dashboard.php          ← MAIN FILE (updated)
├── setup-vault.php        ← Run first
├── api/vault.php          ← Encryption API
└── js/
    ├── vault-manager.js   ← Save operations
    ├── crypto-engine.js   ← Encryption
    └── ...
```

## 🚀 Getting Started

### 1. Setup Tables
```
http://localhost:5555/auth_system/setup-vault.php
```

### 2. Login
```
http://localhost:5555/auth_system/login.php
Email: test@example.com
Password: password123
```

### 3. View Dashboard
```
http://localhost:5555/auth_system/dashboard.php
```

### 4. Add Password
- Click "+ Add password"
- Fill form
- Click "Save Password"
- Done! Data encrypted and saved to DB

## 💾 Database Tables Used

### vault_entries
```sql
uuid           VARCHAR(36) PRIMARY KEY
user_id        INT (foreign key)
type           ENUM (login, note, card, identity)
encrypted_data LONGTEXT (encrypted JSON)
iv             VARCHAR(255)
favorite       TINYINT
created_at     TIMESTAMP
updated_at     TIMESTAMP
```

### vault_folders
```sql
id      INT PRIMARY KEY
user_id INT (foreign key)
name    VARCHAR(100)
color   VARCHAR(20)
```

### vault_history
```sql
id             INT PRIMARY KEY
entry_uuid     VARCHAR(36) (foreign key)
user_id        INT (foreign key)
encrypted_data LONGTEXT
iv             VARCHAR(255)
changed_at     TIMESTAMP
```

## 🔒 Security

✓ Prepared statements (no SQL injection)
✓ Session validation (check user_id)
✓ AES-256 encryption
✓ Non-extractable crypto keys
✓ Server never stores plaintext
✓ HTTPS ready

## 📊 Real Stats Example

| Stat | Query | Example |
|------|-------|---------|
| Vault Status | COUNT(*) | "Secure" (120 items) |
| Login Items | WHERE type='login' | 45 |
| Notes | WHERE type='note' | 12 |
| Cards | WHERE type='card' | 5 |
| Security Score | 60 + (items*2) | 78/100 |

## ✨ What Works Now

✓ Dashboard loads real user data
✓ Stats update from database
✓ Recent activity shows real entries
✓ Add password modal works
✓ Data encrypts before save
✓ Database stores encrypted data
✓ User authentication works
✓ Logout button functional
✓ Responsive mobile design
✓ Keyboard shortcuts active

## 🐛 Troubleshooting

### "No data showing"
→ Make sure you're logged in
→ Run setup-vault.php first

### "Error saving"
→ Check browser console
→ Verify vault tables exist
→ Check user_id in session

### "Encryption error"
→ Ensure VaultManager loaded
→ Check crypto-engine.js exists
→ Verify master password set

## 🎯 Next Steps

1. Test with multiple passwords
2. Try search functionality
3. Check recent activity updates
4. Test keyboard shortcuts
5. Export vault when ready

## 📞 Support Files

- **DASHBOARD_UPDATE.md** - Full technical docs
- **DASHBOARD_CHANGES.md** - What changed summary
- **QUICKSTART.sh** - Quick start guide
- **This file** - Quick reference

---

**Status: ✓ PRODUCTION READY**

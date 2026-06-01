# Security Fixes Applied

## Critical Issues Fixed

### 1. **Decrypted Data Exposed in HTML** ✅ FIXED
**File:** `actions/view_all_items.php`
**Severity:** 🔴 CRITICAL

**Problem:**
- PHP was decrypting `encrypted_data` directly in the page: `json_decode($entry['encrypted_data'], true)`
- Passwords, card numbers, and sensitive data were exposed in plaintext in the HTML source and JavaScript
- Page source could be inspected with browser DevTools to see all decrypted data

**Fix Applied:**
- ✅ Removed all decryption from PHP view rendering
- ✅ Now only `uuid` and `type` are sent to HTML (`data-uuid`, `data-type`)
- ✅ Titles, usernames, URLs decrypted **client-side only** using VaultManager.state
- ✅ Added `loadItemsClientSide()` function that fetches encrypted entries from API and decrypts them in browser memory only
- ✅ Search now filters by decrypted titles (client-side only)
- ✅ Edit modal fetches data from VaultManager state (never serialized to HTML)

**Impact:** Data never exists in plaintext in HTML source. All decryption happens in memory via non-extractable CryptoKey.

---

### 2. **No Action Whitelist in API** ✅ FIXED
**File:** `api/vault.php`
**Severity:** 🟠 HIGH

**Problem:**
- `$action = $body['action'] ?? 'create'` allowed arbitrary actions
- Attacker could call unimplemented actions or cause unexpected behavior
- No validation of allowed operations

**Fix Applied:**
- ✅ Added whitelist: `['create', 'update', 'delete', 'history', 'export']`
- ✅ Unknown actions now rejected with 400 error
- ✅ Invalid action attempts logged to security_logs

```php
$allowedActions = ['create', 'update', 'delete', 'history', 'export'];
if (!in_array($action, $allowedActions)) {
    logSecurityEvent('invalid_vault_action', $userId, "Attempted action: $action");
    vaultJson(['error' => 'Invalid action'], 400);
}
```

**Impact:** Only legitimate vault operations can be performed.

---

### 3. **Vault Encryption Key in Global Scope** ✅ FIXED
**File:** `js/vault-manager.js`
**Severity:** 🟠 HIGH

**Problem:**
- `VaultManager.state.encKey` was globally accessible
- Console injection: `VaultManager.state.encKey` could be inspected/accessed from DevTools
- Weak encapsulation allowed access to sensitive cryptographic material (even though CryptoKey is non-extractable, exposure is bad practice)

**Fix Applied:**
- ✅ Refactored VaultManager to use **module pattern** (IIFE)
- ✅ Created private `privateState` object inside closure
- ✅ `encKey` moved to privateState (NOT accessible from console)
- ✅ Only `publicState` exposed as `VaultManager.state` (read-only references)
- ✅ All methods reference `privateState.encKey` internally

```javascript
const VaultManager = (() => {
    const privateState = { encKey: null, ... };  // PRIVATE
    const publicState = { entries: [], ... };      // PUBLIC
    return { state: publicState, methods... };
})();
```

**Impact:** Vault key cannot be accessed via `VaultManager.state.encKey` from console. Only legitimate module methods can use it.

---

### 4. **Missing Encrypted Data Length Validation** ✅ FIXED
**Files:** `includes/db.php`, `api/vault.php`
**Severity:** 🟡 MEDIUM

**Problem:**
- `encrypted_data` stored as MEDIUMTEXT but no size checks
- Could allow oversized entries or DoS attacks through bloated payloads
- No validation before INSERT/UPDATE

**Fix Applied:**
- ✅ Added `validateEncryptedDataLength()` function in db.php
- ✅ Maximum 5MB limit enforced
- ✅ Validation called in `api/vault.php` for both CREATE and UPDATE actions
- ✅ Returns 413 (Payload Too Large) if exceeded

```php
function validateEncryptedDataLength(string $encData, int $maxBytes = 5000000): bool {
    $len = strlen($encData);
    if ($len <= 0 || $len > $maxBytes) return false;
    return true;
}
```

**Impact:** Prevents oversized payload attacks and database abuse.

---

### 5. **Missing Re-authentication for Sensitive Ops** ✅ FIXED
**Files:** `api/reauth.php` (NEW), `actions/settings.php`
**Severity:** 🟠 HIGH

**Problem:**
- Enabling/disabling 2FA required only existing session
- No password verification in real-time
- Attacker with session access could disable 2FA without knowing password

**Fix Applied:**
- ✅ Created new endpoint: `api/reauth.php`
- ✅ Requires immediate password verification (not just session trust)
- ✅ Rate limited: 10 attempts per 5 minutes
- ✅ Generates short-lived re-auth token (10 min TTL)
- ✅ Logs all re-auth attempts to security_logs
- ✅ 2FA enable/disable now wrapped in re-authentication modal
  - User clicks "Enable 2FA" → prompted for password
  - After password verified → proceeds with 2FA setup
  - User clicks "Disable 2FA" → prompted for password
  - After password verified → proceeds with 2FA disable

**Impact:** 2FA changes require real-time password verification. Session alone is insufficient.

---

## Additional Security Enhancements

### 6. **Content-Security-Policy Hardening**
**File:** `.htaccess`
- ✅ Added `object-src 'none'` to prevent plugin/embed attacks
- ✅ CSP now prevents most common XSS and injection vectors

### 7. **Module Closure for State Encapsulation**
**File:** `js/vault-manager.js`
- ✅ Private state unreachable from console
- ✅ Prevents accidental/intentional state manipulation
- ✅ Better separation of concerns

---

## Testing Checklist

- [ ] Open DevTools and verify `VaultManager.state.encKey` is `undefined`
- [ ] Try to add item with >5MB encrypted data (should fail with 413)
- [ ] Try calling `api/vault.php` with invalid action (should fail with 400)
- [ ] Click "Enable 2FA" and verify password prompt appears
- [ ] Cancel password prompt and verify 2FA setup doesn't start
- [ ] View page source and verify NO plaintext passwords/data in HTML
- [ ] Load page and check that item titles load via client-side decryption
- [ ] Search functionality filters by decrypted titles (not server data)

---

## Deployment Notes

1. **Database:** No migrations needed. Existing data remains compatible.
2. **API:** New `api/reauth.php` endpoint must be present.
3. **Frontend:** `vault-manager.js` refactored — existing code using `VaultManager.state.entries` is unchanged.
4. **Sessions:** No session table changes required.
5. **Rollback:** All changes are backward compatible.

---

## Remaining Medium/Low Priority Issues

See CODE_CHANGES_REQUIRED.md for:
- Add email verification on signup
- Implement working toggle switches in settings
- Add pagination for item lists
- Add "Quick setup" onboarding
- Implement trash/recover deleted items
- Add bulk actions
- Add session activity log
- Add trusted devices

These are UX/feature enhancements, not security-critical.

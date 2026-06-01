/**
 * VaultKey — client-side cryptographic key manager.
 *
 * Key is generated with Web Crypto API (CSPRNG), stored in sessionStorage,
 * and NEVER transmitted to any server. All operations are synchronous
 * except fingerprint() which uses crypto.subtle.digest.
 */
const VaultKey = (() => {

    function storageId(userId) {
        return `vk_u${userId}`;
    }

    /** Generate 32 bytes of cryptographically random key material. */
    function generate() {
        const bytes = new Uint8Array(32);
        crypto.getRandomValues(bytes);
        return bytes;
    }

    /** Load key from sessionStorage, returns Uint8Array or null. */
    function load(userId) {
        const raw = sessionStorage.getItem(storageId(userId));
        if (!raw) return null;
        try {
            const bytes = Uint8Array.from(atob(raw), c => c.charCodeAt(0));
            return bytes.length === 32 ? bytes : null;
        } catch {
            return null;
        }
    }

    /** Persist key bytes to sessionStorage (base64-encoded). */
    function save(userId, bytes) {
        sessionStorage.setItem(storageId(userId), btoa(String.fromCharCode(...bytes)));
    }

    /** Return existing key or generate and store a new one. */
    function getOrCreate(userId) {
        let bytes = load(userId);
        if (!bytes) {
            bytes = generate();
            save(userId, bytes);
        }
        return bytes;
    }

    /** Replace existing key with a freshly generated one. */
    function regenerate(userId) {
        const bytes = generate();
        save(userId, bytes);
        return bytes;
    }

    /** Convert Uint8Array to lowercase hex string. */
    function toHex(bytes) {
        return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
    }

    /**
     * Format 32-byte key as 4 rows × 4 groups of 4 hex chars (uppercase).
     * Example row: "A3F2 91BC 4D7E 8A21"
     */
    function formatGrid(bytes) {
        const hex = toHex(bytes).toUpperCase();
        const g = hex.match(/.{4}/g);
        return [
            g.slice(0, 4).join(' '),
            g.slice(4, 8).join(' '),
            g.slice(8, 12).join(' '),
            g.slice(12, 16).join(' '),
        ].join('\n');
    }

    /** First 16 hex chars of SHA-256 of the key bytes, formatted in groups of 4. */
    async function fingerprint(bytes) {
        const hash = await crypto.subtle.digest('SHA-256', bytes);
        return toHex(new Uint8Array(hash)).slice(0, 16).toUpperCase();
    }

    return { getOrCreate, regenerate, formatGrid, fingerprint, toHex };
})();

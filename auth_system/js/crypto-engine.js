/**
 * CryptoEngine — Zero-knowledge AES-256-GCM vault encryption.
 *
 * Key derivation: PBKDF2-SHA256 (100 000 iterations).
 * Encryption:     AES-256-GCM with random 12-byte IV per operation.
 * The derived CryptoKey is non-extractable and lives only in memory.
 * Nothing here ever touches the network.
 */
const CryptoEngine = (() => {
    const ENC = new TextEncoder();
    const DEC = new TextDecoder();

    function b64Encode(buf) {
        return btoa(String.fromCharCode(...new Uint8Array(buf)));
    }

    function b64Decode(str) {
        return Uint8Array.from(atob(str), c => c.charCodeAt(0));
    }

    function hexDecode(hex) {
        const bytes = new Uint8Array(hex.length / 2);
        for (let i = 0; i < bytes.length; i++) bytes[i] = parseInt(hex.slice(i * 2, i * 2 + 2), 16);
        return bytes;
    }

    function hexEncode(buf) {
        return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
    }

    /**
     * Derive a non-extractable AES-256-GCM key from a master password and hex salt.
     * Takes ~300-600 ms in browser (PBKDF2 100 k iterations).
     */
    async function deriveKey(masterPassword, saltHex) {
        const raw = await crypto.subtle.importKey(
            'raw', ENC.encode(masterPassword), 'PBKDF2', false, ['deriveKey']
        );
        return crypto.subtle.deriveKey(
            { name: 'PBKDF2', salt: hexDecode(saltHex), iterations: 100_000, hash: 'SHA-256' },
            raw,
            { name: 'AES-GCM', length: 256 },
            false,          // non-extractable — key NEVER leaves browser memory
            ['encrypt', 'decrypt']
        );
    }

    /** Encrypt any JSON-serialisable value. Returns { iv, data } both base64. */
    async function encrypt(key, value) {
        const iv         = crypto.getRandomValues(new Uint8Array(12));
        const ciphertext = await crypto.subtle.encrypt(
            { name: 'AES-GCM', iv },
            key,
            ENC.encode(JSON.stringify(value))
        );
        return { iv: b64Encode(iv), data: b64Encode(ciphertext) };
    }

    /** Decrypt { iv, data } (both base64). Returns the original value. Throws on wrong key. */
    async function decrypt(key, iv, data) {
        const plain = await crypto.subtle.decrypt(
            { name: 'AES-GCM', iv: b64Decode(iv) },
            key,
            b64Decode(data)
        );
        return JSON.parse(DEC.decode(plain));
    }

    /** SHA-1 hex of a UTF-8 string (used for HIBP k-anonymity). */
    async function sha1Hex(str) {
        const buf = await crypto.subtle.digest('SHA-1', ENC.encode(str));
        return hexEncode(buf).toUpperCase();
    }

    /** SHA-256 hex of a UTF-8 string (used for fingerprints). */
    async function sha256Hex(str) {
        const buf = await crypto.subtle.digest('SHA-256', ENC.encode(str));
        return hexEncode(buf);
    }

    /** Generate 32 random hex bytes for use as PBKDF2 salt. */
    function generateSalt() {
        return hexEncode(crypto.getRandomValues(new Uint8Array(32)));
    }

    return { deriveKey, encrypt, decrypt, sha1Hex, sha256Hex, generateSalt, b64Encode, b64Decode };
})();

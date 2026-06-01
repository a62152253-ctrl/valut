<?php
/**
 * RFC 6238 TOTP implementation — no external dependencies.
 */
class TOTP {

    private const CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Generate a random Base32 secret (20 bytes → 32 chars). */
    public static function generateSecret(): string {
        return self::b32enc(random_bytes(20));
    }

    /** Compute a 6-digit TOTP code for the given secret and time-window offset. */
    public static function getCode(string $secret, int $window = 0): string {
        $counter = (int) floor(time() / 30) + $window;
        $key     = self::b32dec($secret);
        $msg     = pack('NN', 0, $counter);           // 8-byte big-endian counter
        $hash    = hash_hmac('sha1', $msg, $key, true);
        $offset  = ord($hash[19]) & 0x0f;
        $code    = (
            (ord($hash[$offset])   & 0x7f) << 24 |
            (ord($hash[$offset+1]) & 0xff) << 16 |
            (ord($hash[$offset+2]) & 0xff) << 8  |
            (ord($hash[$offset+3]) & 0xff)
        ) % 1_000_000;
        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a user-supplied 6-digit code.
     * Accepts ±1 window (90-second tolerance for clock skew).
     */
    public static function verify(string $secret, string $input): bool {
        $input = preg_replace('/\s+/', '', $input);
        if (!preg_match('/^\d{6}$/', $input)) {
            return false;
        }
        foreach ([-1, 0, 1] as $w) {
            if (hash_equals(self::getCode($secret, $w), $input)) {
                return true;
            }
        }
        return false;
    }

    /** Build the otpauth:// URI (browser renders QR client-side). */
    public static function otpauthUri(string $secret, string $email, string $issuer = 'Vaultly'): string {
        $label = rawurlencode($issuer . ':' . $email);
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label, $secret, rawurlencode($issuer)
        );
    }

    /** Generate 8 random one-time backup codes. */
    public static function generateBackupCodes(int $n = 8): array {
        $codes = [];
        for ($i = 0; $i < $n; $i++) {
            $raw      = bin2hex(random_bytes(4));          // 8 hex chars
            $codes[]  = strtoupper(substr($raw, 0, 4) . '-' . substr($raw, 4)); // XXXX-XXXX
        }
        return $codes;
    }

    // ── Base32 ────────────────────────────────────────────────────────────────

    private static function b32enc(string $data): string {
        $out  = '';
        $buf  = 0;
        $bits = 0;
        foreach (str_split($data) as $ch) {
            $buf   = ($buf << 8) | ord($ch);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out  .= self::CHARS[($buf >> $bits) & 0x1f];
            }
        }
        if ($bits > 0) {
            $out .= self::CHARS[($buf << (5 - $bits)) & 0x1f];
        }
        return $out;
    }

    private static function b32dec(string $data): string {
        $data = strtoupper(rtrim($data, '='));
        $out  = '';
        $buf  = 0;
        $bits = 0;
        foreach (str_split($data) as $ch) {
            $pos = strpos(self::CHARS, $ch);
            if ($pos === false) {
                continue;
            }
            $buf   = ($buf << 5) | $pos;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out  .= chr(($buf >> $bits) & 0xff);
            }
        }
        return $out;
    }
}

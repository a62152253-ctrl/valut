<?php
/**
 * BackupCodesManager - Generate and manage TOTP backup codes
 * Used for account recovery if authenticator app is lost
 */

class BackupCodesManager {
    const CODE_COUNT = 10;
    const CODE_LENGTH = 8;

    /**
     * Generate new backup codes
     * @param int $user_id User ID
     * @param mysqli $conn Database connection
     * @return array ['codes' => ['code1', 'code2', ...], 'hashes' => [...]]
     */
    public static function generate($user_id, &$conn) {
        // Delete old codes
        $stmt = $conn->prepare("DELETE FROM totp_backup_codes WHERE user_id = ? AND used_at IS NULL");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();

        $codes = [];
        $codeHashes = [];

        // Generate new codes
        for ($i = 0; $i < self::CODE_COUNT; $i++) {
            $code = self::generateCode();
            $hash = password_hash($code, PASSWORD_BCRYPT);
            $codes[] = $code;
            $codeHashes[] = $hash;

            // Insert into database
            $stmt = $conn->prepare(
                "INSERT INTO totp_backup_codes (user_id, code_hash) VALUES (?, ?)"
            );
            $stmt->bind_param('is', $user_id, $hash);
            $stmt->execute();
            $stmt->close();
        }

        logSecurityEvent('backup_codes_generated', $user_id, 'New backup codes generated');

        return [
            'codes' => $codes,
            'count' => count($codes),
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Verify and use backup code
     * @param int $user_id User ID
     * @param string $code Backup code
     * @param mysqli $conn Database connection
     * @return bool True if code valid and unused
     */
    public static function verify($user_id, $code, &$conn) {
        // Get unused codes for user
        $stmt = $conn->prepare(
            "SELECT id, code_hash FROM totp_backup_codes 
             WHERE user_id = ? AND used_at IS NULL 
             LIMIT 10"
        );
        
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $codes = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Check each code
        foreach ($codes as $row) {
            if (password_verify($code, $row['code_hash'])) {
                // Mark as used
                $stmt = $conn->prepare(
                    "UPDATE totp_backup_codes SET used_at = NOW() WHERE id = ?"
                );
                $stmt->bind_param('i', $row['id']);
                $stmt->execute();
                $stmt->close();

                logSecurityEvent('backup_code_used', $user_id, 'Backup code used for authentication');

                return true;
            }
        }

        return false;
    }

    /**
     * Count remaining backup codes
     * @param int $user_id User ID
     * @param mysqli $conn Database connection
     * @return int Number of unused codes
     */
    public static function getRemaining($user_id, &$conn) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) as count FROM totp_backup_codes 
             WHERE user_id = ? AND used_at IS NULL"
        );
        
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($result['count'] ?? 0);
    }

    /**
     * Revoke all backup codes
     * @param int $user_id User ID
     * @param mysqli $conn Database connection
     */
    public static function revoke($user_id, &$conn) {
        $stmt = $conn->prepare("DELETE FROM totp_backup_codes WHERE user_id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();

        logSecurityEvent('backup_codes_revoked', $user_id, 'All backup codes revoked');
    }

    /**
     * Generate single backup code
     * @return string 8-character code
     */
    private static function generateCode() {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }
}
?>

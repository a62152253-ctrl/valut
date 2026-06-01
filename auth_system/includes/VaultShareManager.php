<?php
/**
 * VaultShareManager - Create and manage encrypted share links for vault entries
 * Supports time-limited and access-limited shares
 */

class VaultShareManager {
    const SHARE_TOKEN_LENGTH = 64;
    const DEFAULT_EXPIRY = 7 * 86400; // 7 days

    /**
     * Create share link for vault entry
     * @param string $entry_uuid Entry UUID
     * @param int $owner_id User ID
     * @param array $options ['expires_in' => seconds, 'max_accesses' => int, 'password' => string]
     * @param mysqli $conn Database connection
     * @return string|false Share token or false on error
     */
    public static function create($entry_uuid, $owner_id, $options = [], &$conn = null) {
        if (!$conn) {
            global $conn;
        }

        $token = bin2hex(random_bytes(self::SHARE_TOKEN_LENGTH / 2));
        $expiresIn = $options['expires_in'] ?? self::DEFAULT_EXPIRY;
        $maxAccesses = $options['max_accesses'] ?? null;
        $password = $options['password'] ?? null;
        $passwordHash = $password ? password_hash($password, PASSWORD_BCRYPT) : null;

        $expiresAt = $expiresIn ? date('Y-m-d H:i:s', time() + $expiresIn) : null;

        $stmt = $conn->prepare(
            "INSERT INTO vault_shares (entry_uuid, owner_id, share_token, share_password_hash, max_accesses, expires_at) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        if (!$stmt) {
            return false;
        }

        // s=entry_uuid, i=owner_id, s=token, s=passwordHash, i=maxAccesses, s=expiresAt
        $stmt->bind_param('sississ', $entry_uuid, $owner_id, $token, $passwordHash, $maxAccesses, $expiresAt);
        
        if (!$stmt->execute()) {
            $stmt->close();
            return false;
        }

        $stmt->close();

        logSecurityEvent('share_created', $owner_id, "Share created for entry: $entry_uuid");

        return $token;
    }

    /**
     * Verify and access shared entry
     * @param string $share_token Share token
     * @param string $password Optional password
     * @param mysqli $conn Database connection
     * @return array|false Share details or false
     */
    public static function access($share_token, $password = null, &$conn = null) {
        if (!$conn) {
            global $conn;
        }

        // Get share record
        $stmt = $conn->prepare(
            "SELECT id, entry_uuid, owner_id, share_password_hash, access_count, max_accesses, expires_at 
             FROM vault_shares 
             WHERE share_token = ?
             AND (expires_at IS NULL OR expires_at > NOW())"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $share_token);
        $stmt->execute();
        $share = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$share) {
            return false; // Token not found or expired
        }

        // Check max accesses
        if ($share['max_accesses'] && $share['access_count'] >= $share['max_accesses']) {
            return false; // Max accesses reached
        }

        // Check password if required
        if ($share['share_password_hash']) {
            if (!$password || !password_verify($password, $share['share_password_hash'])) {
                return false;
            }
        }

        // Increment access count
        $stmt = $conn->prepare(
            "UPDATE vault_shares SET access_count = access_count + 1 WHERE id = ?"
        );
        $stmt->bind_param('i', $share['id']);
        $stmt->execute();
        $stmt->close();

        logSecurityEvent('share_accessed', $share['owner_id'], "Share token accessed for entry: " . $share['entry_uuid']);

        return [
            'entry_uuid' => $share['entry_uuid'],
            'owner_id' => $share['owner_id'],
            'access_count' => $share['access_count'] + 1,
            'max_accesses' => $share['max_accesses']
        ];
    }

    /**
     * Revoke share link
     * @param string $share_token Share token
     * @param int $owner_id Verify ownership
     * @param mysqli $conn Database connection
     * @return bool Success
     */
    public static function revoke($share_token, $owner_id, &$conn = null) {
        if (!$conn) {
            global $conn;
        }

        $stmt = $conn->prepare(
            "DELETE FROM vault_shares WHERE share_token = ? AND owner_id = ?"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('si', $share_token, $owner_id);
        $success = $stmt->execute();
        $stmt->close();

        if ($success) {
            logSecurityEvent('share_revoked', $owner_id, "Share link revoked: $share_token");
        }

        return $success;
    }

    /**
     * Get all active shares for user
     * @param int $owner_id User ID
     * @param mysqli $conn Database connection
     * @return array List of active shares
     */
    public static function getActive($owner_id, &$conn = null) {
        if (!$conn) {
            global $conn;
        }

        $stmt = $conn->prepare(
            "SELECT entry_uuid, share_token, access_count, max_accesses, expires_at, created_at 
             FROM vault_shares 
             WHERE owner_id = ?
             AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY created_at DESC"
        );

        $stmt->bind_param('i', $owner_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $shares = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $shares;
    }

    /**
     * Clean up expired shares
     * @param mysqli $conn Database connection
     * @return int Number of shares deleted
     */
    public static function cleanup(&$conn = null) {
        if (!$conn) {
            global $conn;
        }

        $stmt = $conn->prepare(
            "DELETE FROM vault_shares WHERE expires_at < NOW()"
        );

        $stmt->execute();
        $rows = $conn->affected_rows;
        $stmt->close();

        return $rows;
    }
}
?>

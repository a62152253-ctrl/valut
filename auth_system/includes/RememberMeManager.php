<?php
/**
 * RememberMeManager - Persistent login tokens for "Remember me" functionality
 * Tokens are stored securely with hash + IP validation
 */

class RememberMeManager {
    const TOKEN_LENGTH = 64;
    const COOKIE_NAME = 'vault_remember';
    const COOKIE_LIFETIME = 7 * 86400; // 7 days

    /**
     * Create remember token for user
     * @param int $user_id User ID
     * @param mysqli $conn Database connection
     * @return string Cookie value to set
     */
    public static function create($user_id, &$conn) {
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH / 2));
        $tokenHash = hash('sha256', $token);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $expiresAt = date('Y-m-d H:i:s', time() + self::COOKIE_LIFETIME);

        $stmt = $conn->prepare(
            "INSERT INTO remember_tokens (user_id, token_hash, ip_address, user_agent, expires_at) 
             VALUES (?, ?, ?, ?, ?)"
        );
        
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('issss', $user_id, $tokenHash, $ip, $userAgent, $expiresAt);
        $success = $stmt->execute();
        $stmt->close();

        if (!$success) {
            return false;
        }

        // 'secure' = true only on real HTTPS (not plain HTTP localhost)
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
        setcookie(
            self::COOKIE_NAME,
            $token,
            [
                'expires'  => time() + self::COOKIE_LIFETIME,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Strict',
            ]
        );

        return $token;
    }

    /**
     * Verify and auto-login using remember token
     * @param mysqli $conn Database connection
     * @return int|null User ID if valid, null otherwise
     */
    public static function verify(&$conn) {
        if (!isset($_COOKIE[self::COOKIE_NAME])) {
            return null;
        }

        $token = $_COOKIE[self::COOKIE_NAME];
        $tokenHash = hash('sha256', $token);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $conn->prepare(
            "SELECT user_id FROM remember_tokens 
             WHERE token_hash = ? 
             AND expires_at > NOW()
             AND ip_address = ?"
        );
        
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('ss', $tokenHash, $ip);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$result) {
            return null;
        }

        // Update last_used timestamp
        $stmt = $conn->prepare("UPDATE remember_tokens SET last_used = NOW() WHERE token_hash = ?");
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $stmt->close();

        return $result['user_id'];
    }

    /**
     * Revoke all remember tokens for user (logout all devices)
     * @param int $user_id User ID
     * @param mysqli $conn Database connection
     */
    public static function revokeAll($user_id, &$conn) {
        $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();

        // Clear cookie
        setcookie(self::COOKIE_NAME, '', ['expires' => time() - 3600]);
    }

    /**
     * Revoke single token
     * @param string $token Token to revoke
     * @param mysqli $conn Database connection
     */
    public static function revoke($token, &$conn) {
        $tokenHash = hash('sha256', $token);
        $stmt = $conn->prepare("DELETE FROM remember_tokens WHERE token_hash = ?");
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $stmt->close();

        setcookie(self::COOKIE_NAME, '', ['expires' => time() - 3600]);
    }

    /**
     * Get all active tokens for user
     * @param int $user_id User ID
     * @param mysqli $conn Database connection
     * @return array List of active tokens
     */
    public static function getActive($user_id, &$conn) {
        $stmt = $conn->prepare(
            "SELECT id, ip_address, created_at, last_used 
             FROM remember_tokens 
             WHERE user_id = ? AND expires_at > NOW()
             ORDER BY last_used DESC"
        );
        
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $tokens = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $tokens;
    }
}
?>

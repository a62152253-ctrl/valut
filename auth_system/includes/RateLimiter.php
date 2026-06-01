<?php
/**
 * RateLimiter - Prevent abuse of sensitive endpoints
 * Tracks attempts by IP + email to prevent enumeration & brute force
 */

class RateLimiter {
    private static $conn = null;

    /**
     * Initialize database connection
     */
    public static function init(&$conn) {
        self::$conn = $conn;
        self::createTable();
    }

    /**
     * Create rate_limits table if not exists
     */
    private static function createTable() {
        if (!self::$conn) return;
        
        $sql = "CREATE TABLE IF NOT EXISTS rate_limits (
            id INT PRIMARY KEY AUTO_INCREMENT,
            ip_address VARCHAR(45) NOT NULL,
            endpoint VARCHAR(100) NOT NULL,
            identifier VARCHAR(255) DEFAULT NULL,
            attempts INT DEFAULT 1,
            first_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_attempt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ip_endpoint (ip_address, endpoint),
            KEY idx_identifier (identifier),
            KEY idx_first_attempt (first_attempt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        self::$conn->query($sql);
    }

    /**
     * Check if action is rate limited
     * @param string $endpoint Endpoint name (e.g., 'password_reset')
     * @param int $maxAttempts Max attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @param string $identifier Optional: email, user_id for more granular limiting
     * @return array [limited => bool, attempts => int, reset_in => int_seconds]
     */
    public static function check($endpoint, $maxAttempts = 5, $windowSeconds = 900, $identifier = null) {
        if (!self::$conn) return ['limited' => false, 'attempts' => 0, 'reset_in' => 0];

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $now = time();
        $windowStart = date('Y-m-d H:i:s', $now - $windowSeconds);

        // Clean old attempts outside window
        $stmt = self::$conn->prepare("DELETE FROM rate_limits 
                                      WHERE endpoint = ? 
                                      AND first_attempt < ?");
        if ($stmt) {
            $stmt->bind_param('ss', $endpoint, $windowStart);
            $stmt->execute();
            $stmt->close();
        }

        // Get current attempt count
        if ($identifier) {
            $stmt = self::$conn->prepare("SELECT attempts, first_attempt FROM rate_limits 
                                          WHERE ip_address = ? 
                                          AND endpoint = ? 
                                          AND identifier = ?
                                          AND first_attempt > ?");
            $stmt->bind_param('ssss', $ip, $endpoint, $identifier, $windowStart);
        } else {
            $stmt = self::$conn->prepare("SELECT attempts, first_attempt FROM rate_limits 
                                          WHERE ip_address = ? 
                                          AND endpoint = ?
                                          AND first_attempt > ?");
            $stmt->bind_param('sss', $ip, $endpoint, $windowStart);
        }

        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $attempts = $result ? (int)$result['attempts'] : 0;
        $firstAttempt = $result ? strtotime($result['first_attempt']) : $now;
        $resetIn = max(0, $windowSeconds - ($now - $firstAttempt));
        $isLimited = $attempts >= $maxAttempts;

        // Log this attempt
        if (!$result) {
            $stmt = self::$conn->prepare("INSERT INTO rate_limits (ip_address, endpoint, identifier, attempts) 
                                          VALUES (?, ?, ?, 1)");
            $stmt->bind_param('sss', $ip, $endpoint, $identifier);
            $stmt->execute();
            $stmt->close();
        } else {
            $newAttempts = $attempts + 1;
            if ($identifier) {
                $stmt = self::$conn->prepare("UPDATE rate_limits SET attempts = ? 
                                              WHERE ip_address = ? 
                                              AND endpoint = ? 
                                              AND identifier = ?");
                $stmt->bind_param('isss', $newAttempts, $ip, $endpoint, $identifier);
            } else {
                $stmt = self::$conn->prepare("UPDATE rate_limits SET attempts = ? 
                                              WHERE ip_address = ? 
                                              AND endpoint = ?");
                $stmt->bind_param('iss', $newAttempts, $ip, $endpoint);
            }
            $stmt->execute();
            $stmt->close();
        }

        return [
            'limited' => $isLimited,
            'attempts' => $attempts + 1,
            'reset_in' => $resetIn,
            'max_attempts' => $maxAttempts
        ];
    }

    /**
     * Clear rate limit for specific action (after success)
     * @param string $endpoint Endpoint name
     * @param string $identifier Optional identifier (email, user_id)
     */
    public static function clear($endpoint, $identifier = null) {
        if (!self::$conn) return;

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        
        if ($identifier) {
            $stmt = self::$conn->prepare("DELETE FROM rate_limits 
                                          WHERE ip_address = ? 
                                          AND endpoint = ? 
                                          AND identifier = ?");
            $stmt->bind_param('sss', $ip, $endpoint, $identifier);
        } else {
            $stmt = self::$conn->prepare("DELETE FROM rate_limits 
                                          WHERE ip_address = ? 
                                          AND endpoint = ?");
            $stmt->bind_param('ss', $ip, $endpoint);
        }

        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get remaining attempts before rate limit
     * @param string $endpoint Endpoint name
     * @param int $maxAttempts Max allowed
     * @param int $windowSeconds Time window
     * @param string $identifier Optional identifier
     * @return int Remaining attempts (0 if limited)
     */
    public static function getRemaining($endpoint, $maxAttempts = 5, $windowSeconds = 900, $identifier = null) {
        $check = self::check($endpoint, $maxAttempts, $windowSeconds, $identifier);
        return max(0, $maxAttempts - $check['attempts']);
    }
}
?>

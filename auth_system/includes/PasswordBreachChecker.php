<?php
/**
 * PasswordBreachChecker - Check password against Have I Been Pwned database
 * Uses k-anonymity model for privacy (doesn't send full password)
 */

class PasswordBreachChecker {
    const API_URL = 'https://api.pwnedpasswords.com/range/';
    const TIMEOUT = 5; // seconds
    const CACHE_TTL = 86400; // 24 hours

    /**
     * Check if password has been breached
     * @param string $password Password to check
     * @return array ['breached' => bool, 'count' => int, 'safe' => bool]
     */
    public static function check($password) {
        // Don't check weak passwords (they'll fail validation anyway)
        if (strlen($password) < 8) {
            return ['breached' => false, 'count' => 0, 'safe' => false, 'reason' => 'password_too_short'];
        }

        try {
            // Hash password with SHA-1
            $sha1 = strtoupper(sha1($password));
            $prefix = substr($sha1, 0, 5);
            $suffix = substr($sha1, 5);

            // Check cache first
            $cached = self::getCache($prefix);
            if ($cached !== null) {
                $count = self::findInHashes($suffix, $cached);
                return [
                    'breached' => $count > 0,
                    'count' => $count,
                    'safe' => $count === 0,
                    'source' => 'cache'
                ];
            }

            // Fetch from HIBP API
            $response = self::fetchFromAPI($prefix);
            if ($response === false) {
                // API unavailable, fail open (allow registration)
                return [
                    'breached' => false,
                    'count' => 0,
                    'safe' => true,
                    'source' => 'offline'
                ];
            }

            // Cache the result
            self::cacheHashes($prefix, $response);

            // Search for our hash
            $count = self::findInHashes($suffix, $response);

            return [
                'breached' => $count > 0,
                'count' => $count,
                'safe' => $count === 0,
                'source' => 'api'
            ];
        } catch (Exception $e) {
            error_log("Breach check error: " . $e->getMessage());
            return [
                'breached' => false,
                'count' => 0,
                'safe' => true,
                'source' => 'error'
            ];
        }
    }

    /**
     * Fetch hashes from HIBP API
     * @param string $prefix First 5 chars of SHA-1 hash
     * @return string|false Hash list or false on failure
     */
    private static function fetchFromAPI($prefix) {
        $url = self::API_URL . $prefix;
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT,
                'user_agent' => 'Vaultly/1.0',
                'header' => "Accept: application/json\r\n"
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            error_log("HIBP API request failed for prefix: $prefix");
            return false;
        }

        return $response;
    }

    /**
     * Find suffix in HIBP response
     * @param string $suffix Last 35 chars of SHA-1 hash
     * @param string $response HIBP API response
     * @return int Count of breaches (0 if not found)
     */
    private static function findInHashes($suffix, $response) {
        $lines = explode("\r\n", trim($response));
        
        foreach ($lines as $line) {
            if (strpos($line, $suffix) === 0) {
                // Format: SUFFIX:COUNT
                $parts = explode(':', $line);
                return (int)($parts[1] ?? 0);
            }
        }
        
        return 0;
    }

    /**
     * Get cached hashes for prefix
     * @param string $prefix First 5 chars of SHA-1 hash
     * @return string|null Cached response or null
     */
    private static function cacheDir(): string {
        return sys_get_temp_dir() . '/vaultly_breach_cache';
    }

    private static function getCache($prefix) {
        $dir      = self::cacheDir();
        $dataFile = $dir . '/' . $prefix . '.cache';
        $metaFile = $dir . '/' . $prefix . '.meta.json';

        if (!file_exists($dataFile) || !file_exists($metaFile)) {
            return null;
        }

        // JSON meta — never unserialize untrusted files
        $meta = json_decode(file_get_contents($metaFile), true);
        if (!$meta || ($meta['expires'] ?? 0) <= time()) {
            @unlink($dataFile);
            @unlink($metaFile);
            return null;
        }

        return file_get_contents($dataFile);
    }

    private static function cacheHashes($prefix, $response) {
        $dir = self::cacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        @file_put_contents($dir . '/' . $prefix . '.cache', $response);
        @file_put_contents($dir . '/' . $prefix . '.meta.json', json_encode([
            'expires' => time() + self::CACHE_TTL,
            'prefix'  => $prefix,
        ]));
    }

    /**
     * Get common weak passwords list (local check, no API call)
     * @param string $password Password to check
     * @return bool True if password is common/weak
     */
    public static function isCommonPassword($password) {
        $commonPasswords = [
            'password', 'password123', '123456', 'qwerty', 'abc123',
            'admin', 'letmein', 'welcome', 'monkey', '1q2w3e',
            'dragon', 'master', 'sunshine', 'password1', 'password!'
        ];

        return in_array(strtolower($password), $commonPasswords);
    }
}
?>

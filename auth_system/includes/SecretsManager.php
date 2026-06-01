<?php
/**
 * SecretsManager - Handle Docker Secrets and environment variables
 * Supports both direct env vars and Docker secret files
 */

class SecretsManager {
    const DOCKER_SECRETS_PATH = '/run/secrets';

    /**
     * Get secret from environment or Docker Secrets
     * @param string $key Environment variable name (without _FILE suffix)
     * @param string $default Default value if not found
     * @return string Secret value
     */
    public static function get($key, $default = '') {
        // Try Docker Secret file first
        $secretFile = self::DOCKER_SECRETS_PATH . '/' . strtolower($key);
        if (file_exists($secretFile) && is_readable($secretFile)) {
            $value = trim(file_get_contents($secretFile));
            if (!empty($value)) {
                return $value;
            }
        }

        // Try with _FILE suffix
        $filePath = getenv($key . '_FILE');
        if ($filePath && file_exists($filePath) && is_readable($filePath)) {
            $value = trim(file_get_contents($filePath));
            if (!empty($value)) {
                return $value;
            }
        }

        // Fall back to direct environment variable
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $default;
    }

    /**
     * Get all required secrets, fail if any missing
     * @param array $required Required secret keys
     * @return array Secrets
     * @throws Exception
     */
    public static function getRequired($required) {
        $secrets = [];
        foreach ($required as $key) {
            $value = self::get($key);
            if (empty($value)) {
                throw new Exception("Required secret not found: $key");
            }
            $secrets[$key] = $value;
        }
        return $secrets;
    }

    /**
     * Get database credentials
     * @return array ['host' => ..., 'user' => ..., 'pass' => ..., 'name' => ...]
     */
    public static function getDBCredentials() {
        return [
            'host' => self::get('DB_HOST', 'localhost'),
            'user' => self::get('DB_USER', 'root'),
            'pass' => self::get('DB_PASS', ''),
            'name' => self::get('DB_NAME', 'vaultly_db')
        ];
    }

    /**
     * Get SMTP credentials
     * @return array ['host' => ..., 'port' => ..., 'user' => ..., 'pass' => ...]
     */
    public static function getSMTPCredentials() {
        return [
            'host' => self::get('SMTP_HOST', 'smtp.gmail.com'),
            'port' => (int)self::get('SMTP_PORT', '587'),
            'user' => self::get('SMTP_USER', ''),
            'pass' => self::get('SMTP_PASS', ''),
            'enabled' => self::get('SMTP_ENABLED', 'true') === 'true'
        ];
    }

    /**
     * Verify all critical secrets are available
     * @return array ['valid' => bool, 'missing' => array, 'message' => string]
     */
    public static function verify() {
        $required = [
            'DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME',
            'SMTP_HOST', 'SMTP_USER', 'SMTP_PASS'
        ];

        $missing = [];
        foreach ($required as $key) {
            if (empty(self::get($key))) {
                $missing[] = $key;
            }
        }

        return [
            'valid' => empty($missing),
            'missing' => $missing,
            'message' => empty($missing) 
                ? 'All secrets verified'
                : 'Missing secrets: ' . implode(', ', $missing)
        ];
    }

    /**
     * Is running in Docker
     * @return bool
     */
    public static function isDocker() {
        return file_exists('/.dockerenv') || getenv('DOCKER_ENV') === 'true';
    }

    /**
     * Get app URL with protocol
     * @return string
     */
    public static function getAppURL() {
        $forceHTTPS = self::get('FORCE_HTTPS', 'false') === 'true';
        $protocol = ($forceHTTPS || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? self::get('APP_URL', 'localhost');
        return $protocol . '://' . $host;
    }
}

// Override getenv if using Docker Secrets
if (SecretsManager::isDocker()) {
    $original_getenv = 'getenv';
    function getenv($varname = null) use ($original_getenv) {
        if ($varname === null) {
            return $original_getenv();
        }
        return SecretsManager::get($varname, false);
    }
}
?>

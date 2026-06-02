<?php
/**
 * VAULTLY SECURITY CONFIGURATION
 * ═══════════════════════════════════════════════════════════════════════════════
 * 
 * Security settings for production deployment
 * Load this file at the very beginning of your application
 */

// ═══════════════════════════════════════════════════════════════════════════════
// ENVIRONMENT DETECTION
// ═══════════════════════════════════════════════════════════════════════════════

define('ENVIRONMENT', getenv('ENVIRONMENT') ?: 'production');
define('DEBUG_MODE', getenv('APP_DEBUG') === 'true');

// Fail fast in production if debug mode is enabled
if (ENVIRONMENT === 'production' && DEBUG_MODE) {
    error_log('WARNING: Debug mode enabled in production');
}

// ═══════════════════════════════════════════════════════════════════════════════
// ERROR HANDLING & LOGGING
// ═══════════════════════════════════════════════════════════════════════════════

// Never display errors to users in production
if (ENVIRONMENT === 'production') {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('error_reporting', E_ALL);
    ini_set('log_errors', 1);
    ini_set('error_log', '/var/log/vaultly/php-error.log');
} else {
    ini_set('display_errors', 1);
    ini_set('error_reporting', E_ALL);
}

// ═══════════════════════════════════════════════════════════════════════════════
// SESSION SECURITY
// ═══════════════════════════════════════════════════════════════════════════════

ini_set('session.name', 'vaultly_session');
ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.use_trans_sid', 0);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', ENVIRONMENT === 'production' ? 1 : 0);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', 3600);
ini_set('session.cookie_lifetime', 0);

// ═══════════════════════════════════════════════════════════════════════════════
// SECURITY HEADERS (Fallback for servers without mod_headers)
// ═══════════════════════════════════════════════════════════════════════════════

if (!function_exists('send_security_headers')) {
    function send_security_headers() {
        // Only send once per request
        static $headers_sent = false;
        if ($headers_sent) return;
        $headers_sent = true;
        
        header('X-Frame-Options: DENY', true);
        header('X-Content-Type-Options: nosniff', true);
        header('X-XSS-Protection: 1; mode=block', true);
        header('Referrer-Policy: strict-origin-when-cross-origin', true);
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com; font-src \'self\' https://fonts.gstatic.com; img-src \'self\' data:; connect-src \'self\' https://api.pwnedpasswords.com; frame-ancestors \'none\'; base-uri \'self\'; form-action \'self\'; object-src \'none\'', true);
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload', true);
        header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()', true);
        header('X-Powered-By: ', true);  // Remove
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// INPUT VALIDATION & SANITIZATION
// ═══════════════════════════════════════════════════════════════════════════════

define('MAX_REQUEST_SIZE', 10 * 1024 * 1024);  // 10MB
define('MAX_INPUT_LENGTH', 65536);              // 64KB per field

if (!function_exists('validate_request_size')) {
    function validate_request_size() {
        if ($_SERVER['CONTENT_LENGTH'] > MAX_REQUEST_SIZE) {
            http_response_code(413);
            die(json_encode(['error' => 'Request entity too large']));
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// RATE LIMITING (In-Memory Backup)
// ═══════════════════════════════════════════════════════════════════════════════

if (!function_exists('check_rate_limit')) {
    function check_rate_limit($identifier, $limit = 100, $window = 3600) {
        // Uses session-based rate limiting (database preferred for distributed systems)
        $key = "rate_limit_$identifier";
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'count' => 0,
                'expires_at' => time() + $window
            ];
        }
        
        $limiter = &$_SESSION[$key];
        
        if (time() > $limiter['expires_at']) {
            $limiter = [
                'count' => 0,
                'expires_at' => time() + $window
            ];
        }
        
        $limiter['count']++;
        
        if ($limiter['count'] > $limit) {
            return false;  // Rate limit exceeded
        }
        
        return true;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// SECURITY CONSTANTS
// ═══════════════════════════════════════════════════════════════════════════════

define('CSRF_TOKEN_LENGTH', 32);
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_MAX_LENGTH', 128);
define('SESSION_TIMEOUT', 3600);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_ATTEMPT_WINDOW', 900);  // 15 minutes
define('BCRYPT_COST', 12);

// ═══════════════════════════════════════════════════════════════════════════════
// CRYPTOGRAPHY
// ═══════════════════════════════════════════════════════════════════════════════

if (!function_exists('generate_random_bytes')) {
    function generate_random_bytes($length = 32) {
        if (!function_exists('random_bytes')) {
            throw new Exception('random_bytes() not available');
        }
        return random_bytes($length);
    }
}

if (!function_exists('generate_token')) {
    function generate_token($length = 32) {
        return bin2hex(generate_random_bytes($length / 2));
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// LOGGING & MONITORING
// ═══════════════════════════════════════════════════════════════════════════════

if (!function_exists('log_security_event')) {
    function log_security_event($event, $details = '') {
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
        $message = "[$timestamp] Event: $event | IP: $ip | Details: $details | UserAgent: $user_agent\n";
        
        error_log($message, 3, '/var/log/vaultly/security.log');
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// INITIALIZATION
// ═══════════════════════════════════════════════════════════════════════════════

// Send security headers on every request
if (!function_exists('register_security_hooks')) {
    function register_security_hooks() {
        // Register shutdown function for late header sending
        register_shutdown_function('send_security_headers');
        
        // Check request size
        validate_request_size();
    }
}

// Call on include
register_security_hooks();

// Set default timezone
if (!ini_get('date.timezone')) {
    ini_set('date.timezone', 'UTC');
}

?>

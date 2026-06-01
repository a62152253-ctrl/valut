<?php
// ── Database configuration — loaded from environment for security ─────────────
// NOTE: security headers (CSP, X-Frame-Options, etc.) are set in .htaccess
// to avoid duplicate headers when both Apache and PHP would send them.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'auth_system');

// Security constants
define('MIN_PASSWORD_LENGTH', 8);
define('MAX_PASSWORD_LENGTH', 128);
define('MAX_USERNAME_LENGTH', 50);
define('MAX_EMAIL_LENGTH', 100);
define('CSRF_TOKEN_LENGTH', 32);
define('RESET_TOKEN_EXPIRY', 3600); // 1 hour
define('SESSION_TIMEOUT', 3600); // 1 hour

// Create connection with retry logic and timeout
$conn = null;
$retries = 3;
$delay = 1;

for ($i = 0; $i < $retries; $i++) {
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS);
    if (!$conn->connect_error) {
        break;
    }
    if ($i < $retries - 1) {
        usleep($delay * 100000); // Non-blocking microsecond sleep
        $delay = min(2, $delay + 1);
    }
}

// Check connection
if (!$conn || $conn->connect_error) {
    http_response_code(503);
    // Never expose MySQL error details in production
    if (getenv('APP_DEBUG') === 'true') {
        $detail = htmlspecialchars($conn->connect_error ?? 'Connection failed');
        die("<div style='color:red;font-family:Arial;padding:20px;background:#fff3cd;'><strong>DB Error (debug):</strong> $detail</div>");
    }
    die("<div style='font-family:Arial;padding:40px;text-align:center;'><h2>Service temporarily unavailable</h2><p>Please try again in a moment.</p></div>");
}

// Create database if not exists
$sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME;
if ($conn->query($sql) === TRUE) {
    // Database created or already exists
}

// Select database
$conn->select_db(DB_NAME);

// ═══════════════════════════════════════════════════════════════════
// ALL TABLE DEFINITIONS
// ═══════════════════════════════════════════════════════════════════

// Create users table
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email_verified TINYINT(1) NOT NULL DEFAULT 0,
    totp_secret VARCHAR(64) DEFAULT NULL,
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sql);

// Add email_verified column if missing
$col_res = $conn->query("SHOW COLUMNS FROM users LIKE 'email_verified'");
if ($col_res && $col_res->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0");
}

// Backup codes for 2FA recovery
$conn->query("CREATE TABLE IF NOT EXISTS totp_backup_codes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tbc_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Create password reset table
$sql = "CREATE TABLE IF NOT EXISTS password_reset (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(100) NOT NULL,
    token VARCHAR(255) UNIQUE NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (email) REFERENCES users(email) ON DELETE CASCADE
)";
$conn->query($sql);

// Create email verification table
$sql = "CREATE TABLE IF NOT EXISTS email_verification (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    token VARCHAR(255) UNIQUE NOT NULL,
    expires_at DATETIME NOT NULL,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($sql);

// Create vault_salt table
$sql = "CREATE TABLE IF NOT EXISTS vault_salt (
    user_id INT NOT NULL,
    salt VARCHAR(64) NOT NULL,
    hint VARCHAR(200) DEFAULT NULL,
    verification_blob TEXT DEFAULT NULL,
    verification_iv VARCHAR(32) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($sql);

// Create vault_folders table
$sql = "CREATE TABLE IF NOT EXISTS vault_folders (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(7) NOT NULL DEFAULT '#5865f2',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vf_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($sql);

// Create vault_entries table
$sql = "CREATE TABLE IF NOT EXISTS vault_entries (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    uuid VARCHAR(36) NOT NULL,
    folder_id INT DEFAULT NULL,
    type ENUM('login','note','card','identity') NOT NULL DEFAULT 'login',
    encrypted_data MEDIUMTEXT NOT NULL,
    iv VARCHAR(32) NOT NULL,
    favorite TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_ve_uuid (uuid),
    KEY idx_ve_user (user_id),
    KEY idx_ve_folder (folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($sql);

// Create vault_history table
$sql = "CREATE TABLE IF NOT EXISTS vault_history (
    id INT NOT NULL AUTO_INCREMENT,
    entry_uuid VARCHAR(36) NOT NULL,
    user_id INT NOT NULL,
    encrypted_data MEDIUMTEXT NOT NULL,
    iv VARCHAR(32) NOT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vh_entry (entry_uuid),
    KEY idx_vh_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($sql);

// Create security logs table
$sql = "CREATE TABLE IF NOT EXISTS security_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    event_type VARCHAR(50) NOT NULL,
    user_id INT DEFAULT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    KEY idx_event (event_type),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($sql);

// Create rate_limits table for brute force protection
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
$conn->query($sql);

// Create webauthn_credentials table for passkeys
$sql = "CREATE TABLE IF NOT EXISTS webauthn_credentials (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    credential_id VARCHAR(255) UNIQUE NOT NULL,
    public_key TEXT NOT NULL,
    sign_count INT DEFAULT 0,
    transports JSON DEFAULT NULL,
    device_name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used TIMESTAMP NULL DEFAULT NULL,
    KEY idx_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($sql);

// Create vault_shares table for sharing entries
$sql = "CREATE TABLE IF NOT EXISTS vault_shares (
    id INT PRIMARY KEY AUTO_INCREMENT,
    entry_uuid VARCHAR(36) NOT NULL,
    owner_id INT NOT NULL,
    share_token VARCHAR(255) UNIQUE NOT NULL,
    share_password_hash VARCHAR(255) DEFAULT NULL,
    access_count INT DEFAULT 0,
    max_accesses INT DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_owner (owner_id),
    KEY idx_token (share_token),
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($sql);

// Create remember_tokens table for "Remember me"
$sql = "CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token_hash VARCHAR(255) UNIQUE NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used TIMESTAMP NULL DEFAULT NULL,
    KEY idx_user (user_id),
    KEY idx_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($sql);

// Add last_used column if missing (for existing databases)
$col_res = $conn->query("SHOW COLUMNS FROM remember_tokens LIKE 'last_used'");
if ($col_res && $col_res->num_rows === 0) {
    $conn->query("ALTER TABLE remember_tokens ADD COLUMN last_used TIMESTAMP NULL DEFAULT NULL");
}

// Create vault_recent table for recently used items tracking
$sql = "CREATE TABLE IF NOT EXISTS vault_recent (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    entry_uuid VARCHAR(36) NOT NULL,
    entry_title VARCHAR(255),
    entry_type ENUM('login','note','card','identity') NOT NULL DEFAULT 'login',
    accessed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    access_count INT DEFAULT 1,
    KEY idx_user_accessed (user_id, accessed_at),
    KEY idx_entry (entry_uuid),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($sql);

// ═══════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════════

function sanitize(mixed $input): mixed {
    if (!is_string($input)) return $input;
    return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
}

function rawString(mixed $input): string {
    if (!is_string($input)) return '';
    return trim($input);
}

function sanitizeArray($input) {
    if (!is_array($input)) return $input;
    return array_map(function($v) {
        return is_string($v) ? sanitize($v) : $v;
    }, $input);
}

function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

function generateToken(int $length = 32): string {
    return bin2hex(random_bytes((int)($length / 2)));
}

function generateSessionToken(): string {
    return bin2hex(random_bytes(32));
}

function validateCSRFToken(string $token): void {
    if (!isset($_SESSION['csrf_token'])) {
        http_response_code(403);
        die(json_encode(['error' => 'CSRF token missing']));
    }
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die(json_encode(['error' => 'CSRF token validation failed']));
    }
}

function getCSRFToken(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken();
    }
    return $_SESSION['csrf_token'];
}

function requirePost(string $key): mixed {
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        http_response_code(400);
        die(json_encode(['error' => "{$key} is required"]));
    }
    return sanitize($_POST[$key]);
}

function validateEmail(string $email): bool {
    $len = strlen($email);
    if ($len < 5 || $len > MAX_EMAIL_LENGTH) return false;
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function isEmailUnique(string $email, ?int $excludeUserId = null): bool {
    global $conn;
    $stmt = $excludeUserId
        ? $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1")
        : $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    if (!$stmt) return false;
    if ($excludeUserId) {
        $stmt->bind_param('si', $email, $excludeUserId);
    } else {
        $stmt->bind_param('s', $email);
    }
    $stmt->execute();
    $res    = $stmt->get_result();
    $result = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return !$result;
}

function validatePasswordStrength(string $password): bool {
    $length = strlen($password);
    if ($length < MIN_PASSWORD_LENGTH || $length > MAX_PASSWORD_LENGTH) {
        return false;
    }
    return (bool)preg_match(
        '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{' . MIN_PASSWORD_LENGTH . ',}$/',
        $password
    );
}

function validateEncryptedDataLength(string $encData, int $maxBytes = 5000000): bool {
    $len = strlen($encData);
    if ($len <= 0 || $len > $maxBytes) {
        return false;
    }
    return true;
}

function getPasswordStrengthMsg(string $password): string {
    $checks = [
        strlen($password) >= MIN_PASSWORD_LENGTH,
        (bool)preg_match('/[A-Z]/', $password),
        (bool)preg_match('/[a-z]/', $password),
        (bool)preg_match('/\d/', $password),
        (bool)preg_match('/[@$!%*?&]/', $password),
    ];
    $score = count(array_filter($checks));
    if ($score === 5) return 'Strong';
    if ($score === 4) return 'Good';
    if ($score === 3) return 'Fair';
    return 'Weak';
}

function logSecurityEvent(string $event_type, ?int $user_id = null, string $details = ''): void {
    global $conn;
    if (!$conn || !$conn->ping()) return;
    $ip         = $_SERVER['REMOTE_ADDR']     ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $stmt = $conn->prepare(
        "INSERT INTO security_logs (event_type, user_id, ip_address, user_agent, details) VALUES (?, ?, ?, ?, ?)"
    );
    if (!$stmt) return;
    $stmt->bind_param('sisss', $event_type, $user_id, $ip, $user_agent, $details);
    $stmt->execute();
    $stmt->close();
}

function getDbConnection($retries = 3) {
    global $conn;
    for ($i = 0; $i < $retries; $i++) {
        if ($conn && $conn->ping()) return $conn;
        sleep(min(1, $i));
    }
    return $conn;
}
?>

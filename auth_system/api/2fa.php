<?php
session_start();
header('Content-Type: application/json');

require_once '../includes/db.php';
require_once '../includes/totp.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id    = (int) $_SESSION['user_id'];
$user_email = $_SESSION['email'] ?? '';
$action     = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Helper ────────────────────────────────────────────────────────────────────
function jsonOk(array $data = []): void {
    echo json_encode(['ok' => true] + $data);
    exit;
}

function jsonErr(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function fetchUser(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT totp_secret, totp_enabled, email FROM users WHERE id = ?");
    if (!$stmt) return null;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ── Routes ────────────────────────────────────────────────────────────────────

switch ($action) {

    // GET status
    case 'status':
        $user = fetchUser($conn, $user_id);
        jsonOk(['enabled' => (bool)($user['totp_enabled'] ?? false)]);
        break;

    // GET setup — generate secret + QR URL (not yet saved)
    case 'setup':
        $user = fetchUser($conn, $user_id);
        if ($user && $user['totp_enabled']) {
            jsonErr('2FA is already enabled.');
        }
        // Generate a fresh secret and store it temporarily in session
        $secret = TOTP::generateSecret();
        $_SESSION['totp_pending_secret'] = $secret;
        jsonOk([
            'secret'      => $secret,
            'otpauth_uri' => TOTP::otpauthUri($secret, $user_email),
        ]);
        break;

    // POST enable — verify first code and persist
    case 'enable':
        $code = trim($_POST['code'] ?? '');
        if (!preg_match('/^\d{6}$/', $code)) {
            jsonErr('Enter a valid 6-digit code.');
        }
        $secret = $_SESSION['totp_pending_secret'] ?? '';
        if (!$secret) {
            jsonErr('No pending setup. Start over.');
        }
        if (!TOTP::verify($secret, $code)) {
            jsonErr('Incorrect code. Check your authenticator app.');
        }

        // Save secret to users table
        $updateStmt = $conn->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?");
        if (!$updateStmt) jsonErr($conn->error, 500);
        $updateStmt->bind_param('si', $secret, $user_id);
        if (!$updateStmt->execute()) jsonErr($conn->error, 500);
        $updateStmt->close();

        // Delete old backup codes
        $delOldStmt = $conn->prepare("DELETE FROM totp_backup_codes WHERE user_id = ?");
        if (!$delOldStmt) jsonErr($conn->error, 500);
        $delOldStmt->bind_param('i', $user_id);
        $delOldStmt->execute();
        $delOldStmt->close();

        // Generate and insert fresh backup codes
        $plain_codes = TOTP::generateBackupCodes();
        foreach ($plain_codes as $c) {
            $hash = password_hash($c, PASSWORD_BCRYPT);
            $insertStmt = $conn->prepare("INSERT INTO totp_backup_codes (user_id, code_hash) VALUES (?, ?)");
            if (!$insertStmt) jsonErr($conn->error, 500);
            $insertStmt->bind_param('is', $user_id, $hash);
            if (!$insertStmt->execute()) jsonErr($conn->error, 500);
            $insertStmt->close();
        }

        unset($_SESSION['totp_pending_secret']);
        logSecurityEvent('2fa_enabled', $user_id, '2FA enabled via TOTP');
        jsonOk(['backup_codes' => $plain_codes]);
        break;

    // POST disable — requires current password
    case 'disable':
        $password = $_POST['password'] ?? '';
        if (!$password) {
            jsonErr('Password required to disable 2FA.');
        }
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        if (!$stmt) jsonErr($conn->error, 500);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || !password_verify($password, $row['password'])) {
            jsonErr('Incorrect password.');
        }

        // Use prepared statements (not raw queries)
        $disableStmt = $conn->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?");
        if (!$disableStmt) jsonErr($conn->error, 500);
        $disableStmt->bind_param('i', $user_id);
        if (!$disableStmt->execute()) jsonErr($conn->error, 500);
        $disableStmt->close();

        $delCodesStmt = $conn->prepare("DELETE FROM totp_backup_codes WHERE user_id = ?");
        if (!$delCodesStmt) jsonErr($conn->error, 500);
        $delCodesStmt->bind_param('i', $user_id);
        $delCodesStmt->execute();
        $delCodesStmt->close();

        logSecurityEvent('2fa_disabled', $user_id, '2FA disabled');
        jsonOk();
        break;

    // POST verify — called from 2fa_verify.php during login
    case 'verify_login':
        $pending = $_SESSION['2fa_pending_user'] ?? null;
        if (!$pending) {
            jsonErr('No pending login.', 403);
        }
        $code = trim($_POST['code'] ?? '');
        $user = fetchUser($conn, $pending['id']);
        if (!$user || !$user['totp_enabled'] || !$user['totp_secret']) {
            jsonErr('2FA not enabled for this account.', 403);
        }

        $verified = false;

        // Check TOTP code (6 digits)
        if (preg_match('/^\d{6}$/', $code) && TOTP::verify($user['totp_secret'], $code)) {
            $verified = true;
        }

        // Check backup code (format XXXX-XXXX)
        if (!$verified && preg_match('/^[A-F0-9]{4}-[A-F0-9]{4}$/i', strtoupper($code))) {
            $clean = strtoupper(str_replace('-', '', $code));
            $bstmt = $conn->prepare(
                "SELECT id, code_hash FROM totp_backup_codes WHERE user_id = ? AND used_at IS NULL"
            );
            if (!$bstmt) jsonErr($conn->error, 500);
            $bstmt->bind_param('i', $pending['id']);
            $bstmt->execute();
            $bresult = $bstmt->get_result();
            while ($brow = $bresult->fetch_assoc()) {
                // Backup codes stored as hash (format without dash)
                if (password_verify($clean, $brow['code_hash'])) {
                    $bid = (int)$brow['id'];
                    $ustmt = $conn->prepare("UPDATE totp_backup_codes SET used_at = NOW() WHERE id = ?");
                    if (!$ustmt) jsonErr($conn->error, 500);
                    $ustmt->bind_param('i', $bid);
                    $ustmt->execute();
                    $ustmt->close();
                    $verified = true;
                    break;
                }
            }
            $bstmt->close();
        }

        if (!$verified) {
            jsonErr('Invalid code. Try again.');
        }

        // Complete login — session setup
        $_SESSION['user_id']    = $pending['id'];
        $_SESSION['username']   = $pending['username'];
        $_SESSION['email']      = $pending['email'];
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        unset($_SESSION['2fa_pending_user']);
        session_regenerate_id(true);
        logSecurityEvent('login_success_2fa', $pending['id'], '2FA login verified');
        jsonOk(['redirect' => '../dashboard.php']);
        break;

    default:
        jsonErr('Unknown action.', 404);
}

<?php
session_start();
include 'includes/db.php';

getCSRFToken(); // Generate CSRF token for form

$error = '';
$success = '';
$token = '';

if (isset($_GET['token'])) {
    $token = trim($_GET['token']);

    $stmt = $conn->prepare("SELECT email FROM password_reset WHERE token = ? AND expires_at > NOW()");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $tokenRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tokenRow) {
        $error = 'Invalid or expired reset link. Please request a new one.';
    }
} else {
    $error = 'No reset token provided.';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && empty($error)) {
    // CSRF validation (timing-safe)
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $error = 'Security token mismatch. Please try again.';
        logSecurityEvent('csrf_violation', null, 'Reset password CSRF mismatch');
    } else {
    $password         = rawString($_POST['password']         ?? '');
    $confirm_password = rawString($_POST['confirm_password'] ?? '');

    if (empty($password) || empty($confirm_password)) {
        $error = 'All fields are required';
    } elseif (strlen($password) < MIN_PASSWORD_LENGTH) {
        $error = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } else {
        // Re-verify token is still valid
        $stmt = $conn->prepare("SELECT email FROM password_reset WHERE token = ? AND expires_at > NOW()");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $tokenRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$tokenRow) {
            $error = 'Reset link expired. Please request a new one.';
        } else {
            $email  = $tokenRow['email'];
            $hashed = hashPassword($password);

            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
            $stmt->bind_param('ss', $hashed, $email);
            if ($stmt->execute()) {
                $stmt->close();
                $stmt = $conn->prepare("DELETE FROM password_reset WHERE token = ?");
                $stmt->bind_param('s', $token);
                $stmt->execute();
                $stmt->close();
                $success = 'Password reset successful! Redirecting to login...';
                header("refresh:2;url=login.php");
            } else {
                $error = 'Error resetting password: ' . $stmt->error;
                $stmt->close();
            }
        }
    } // end CSRF ok
} // end POST
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Auth System</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/login.css">
</head>
<body>
    <div class="container">
        <div class="form-box">
            <div class="form-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    <path d="m9 16 2 2 4-4"/>
                </svg>
            </div>

            <h2>Set New Password</h2>
            <p class="form-subtitle">Choose a strong password for your vault</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <p class="link-text"><a href="forgot_password.php">Request new link</a> &nbsp;·&nbsp; <a href="login.php">Back to Login</a></p>
            <?php elseif ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php else: ?>
                <form id="resetForm" method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCSRFToken()); ?>">
                    <div class="form-group">
                        <div class="input-wrapper">
                            <svg class="input-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                            <input type="password" id="password" name="password" placeholder="New password" required class="has-eye">
                            <button type="button" class="eye-btn" onclick="togglePass('password',this)" tabindex="-1">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                        <span class="error-msg" id="passwordError"></span>
                    </div>

                    <div class="form-group">
                        <div class="input-wrapper">
                            <svg class="input-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required class="has-eye">
                            <button type="button" class="eye-btn" onclick="togglePass('confirm_password',this)" tabindex="-1">
                                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                        <span class="error-msg" id="confirmError"></span>
                    </div>

                    <button type="submit" class="btn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="M12 2L4 6v6c0 5.25 3.6 10.2 8 11.8 4.4-1.6 8-6.55 8-11.8V6L12 2z"/>
                            <path d="m9 12 2 2 4-4"/>
                        </svg>
                        Reset Password
                    </button>
                </form>
                <p class="link-text"><a href="login.php">Back to Login</a></p>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
    function togglePass(id, btn) {
        const inp = document.getElementById(id);
        inp.type = inp.type === 'password' ? 'text' : 'password';
        btn.classList.toggle('active');
    }
    </script>
    <script src="js/validation.js"></script>
</body>
</html>

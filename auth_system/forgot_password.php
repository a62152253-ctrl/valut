<?php
session_start();
include 'includes/db.php';
include 'includes/EmailManager.php';
include 'includes/RateLimiter.php';

RateLimiter::init($conn);

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$success = '';
$resetToken = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Check rate limiting (5 attempts per 15 minutes per email)
    $email = sanitize($_POST['email'] ?? '');
    $rateCheck = RateLimiter::check('password_reset', 5, 900, $email);
    
    if ($rateCheck['limited']) {
        $error = 'Too many password reset requests. Try again in ' . ceil($rateCheck['reset_in'] / 60) . ' minutes.';
        logSecurityEvent('password_reset_rate_limit', null, "Rate limited for email: $email");
    } else {
        if (empty($email)) {
            $error = 'Email is required';
        } elseif (!validateEmail($email)) {
            $error = 'Invalid email format';
        } else {
            // Check if email exists
            $stmt = $conn->prepare("SELECT id, username FROM users WHERE email = ?");
            if (!$stmt) {
                $error = 'Database error. Please try again later.';
            } else {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($row) {
                    $token = generateToken(64);
                    $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour
                    
                    $stmt = $conn->prepare("INSERT INTO password_reset (email, token, expires_at) VALUES (?, ?, ?)");
                    if (!$stmt) {
                        $error = 'Error generating reset token.';
                    } else {
                        $stmt->bind_param('sss', $email, $token, $expires_at);
                        if ($stmt->execute()) {
                            // Send password reset email
                            $emailSent = EmailManager::sendPasswordReset($email, $token, $row['username']);
                            
                            if ($emailSent) {
                                RateLimiter::clear('password_reset', $email); // Success, clear rate limit
                                $success = '✓ Password reset email sent to ' . htmlspecialchars($email) . '. Check your inbox (and spam folder).';
                                logSecurityEvent('password_reset_email_sent', $row['id'], "Password reset email sent: $email");
                            } else {
                                // Email failed but token saved - show link as fallback
                                $resetToken = $token;
                                $success = '⚠️ Reset link generated. Email sending failed. Use token: ' . htmlspecialchars($token);
                                logSecurityEvent('password_reset_email_failed', $row['id'], "Email sending failed for: $email");
                            }
                        } else {
                            $error = 'Error saving reset token: ' . $stmt->error;
                        }
                        $stmt->close();
                    }
                } else {
                    // Don't reveal if email exists (security best practice)
                    $success = 'If this email exists in our system, a password reset link has been sent.';
                    logSecurityEvent('password_reset_nonexistent', null, "Non-existent email reset attempt: $email");
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Auth System</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <div class="form-box">
            <div class="form-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <circle cx="12" cy="12" r="1" fill="currentColor"/>
                    <path d="M12 1v6m0 6v6M4.22 4.22l4.24 4.24m5.08 5.08l4.24 4.24M1 12h6m6 0h6M4.22 19.78l4.24-4.24m5.08-5.08l4.24-4.24"/>
                </svg>
            </div>

            <h2>Reset Password</h2>
            <p class="form-subtitle">Enter your email and we'll send you a recovery link</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php if ($resetToken): ?>
                    <div style="background: #f0f9ff; border: 1px solid #0ea5e9; border-radius: 8px; padding: 12px; margin: 12px 0; font-family: monospace; font-size: 12px; word-break: break-all; color: #0c4a6e;">
                        <?php echo htmlspecialchars($resetToken); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <div class="input-wrapper">
                        <svg class="input-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="4" width="20" height="16" rx="2"/>
                            <path d="m22 7-10 7L2 7"/>
                        </svg>
                        <input type="email" name="email" placeholder="Your email address" required>
                    </div>
                </div>

                <button type="submit" class="btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                    Send Reset Link
                </button>
            </form>

            <div class="security-note">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    <path d="m9 12 2 2 4-4"/>
                </svg>
                Your email is never shared and reset links expire in 1 hour.
            </div>

            <p class="link-text">Remember your password? <a href="login.php">Sign in</a> | <a href="register.php">Create account</a></p>
        </div>
    </div>
</body>
</html>

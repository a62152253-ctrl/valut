<?php
session_start();
include 'includes/db.php';
include 'includes/RateLimiter.php';
include 'includes/PasswordBreachChecker.php';

RateLimiter::init($conn);
getCSRFToken();

$error     = '';
$success   = '';
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check (timing-safe)
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $error = 'Security token mismatch. Please try again.';
        logSecurityEvent('csrf_violation', null, "Registration CSRF mismatch from $client_ip");
    } else {
        // Rate-limit registrations: 3 per 15 min per IP
        $rl = RateLimiter::check('register', 3, 900);
        if ($rl['limited']) {
            $mins  = (int)ceil($rl['reset_in'] / 60);
            $error = "Too many registration attempts. Try again in {$mins} minute" . ($mins !== 1 ? 's' : '') . '.';
            logSecurityEvent('rate_limit_exceeded', null, "Register rate limit from $client_ip");
        }
    }

    if (!$error) {
        $username         = sanitize($_POST['username'] ?? '');
        $email            = sanitize($_POST['email']    ?? '');
        $password         = rawString($_POST['password']         ?? '');
        $confirm_password = rawString($_POST['confirm_password'] ?? '');

        if (empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
            $error = 'All fields are required';
        } elseif (strlen($username) < 3 || strlen($username) > MAX_USERNAME_LENGTH) {
            $error = 'Username must be between 3 and ' . MAX_USERNAME_LENGTH . ' characters';
        } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
            $error = 'Username can only contain letters, numbers, underscores, dots, and hyphens';
        } elseif (!validateEmail($email)) {
            $error = 'Invalid email format';
        } elseif (strlen($password) < MIN_PASSWORD_LENGTH) {
            $error = 'Password must be at least ' . MIN_PASSWORD_LENGTH . ' characters';
        } elseif (strlen($password) > MAX_PASSWORD_LENGTH) {
            $error = 'Password is too long';
        } elseif (!validatePasswordStrength($password)) {
            $error = 'Password does not meet security requirements (needs uppercase, number and symbol)';
        } elseif (PasswordBreachChecker::isCommonPassword($password)) {
            $error = 'This password is too common. Please choose a unique password.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match';
        } else {
            // HIBP breach check (non-blocking — fail open if API is down)
            $breach = PasswordBreachChecker::check($password);
            if ($breach['breached'] && $breach['count'] > 0) {
                $error = "This password has appeared in {$breach['count']} data breach(es). Please choose a different password.";
            }
        }
    }

    if (!$error) {
        $username         = sanitize($_POST['username'] ?? '');
        $email            = sanitize($_POST['email']    ?? '');
        $password         = rawString($_POST['password'] ?? '');
        if (true) { // scope wrapper
            // Check if user already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
            if (!$stmt) {
                $error = 'Database error. Please try again later.';
                logSecurityEvent('db_error', null, $conn->error);
            } else {
                $stmt->bind_param('ss', $email, $username);
                $stmt->execute();
                $exists = $stmt->get_result()->num_rows;
                $stmt->close();

                if ($exists > 0) {
                    $error = 'Email or username already exists';
                    logSecurityEvent('registration_duplicate', null, "Duplicate registration attempt: $email from $client_ip");
                } else {
                    $hashed_password = hashPassword($password);
                    $stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
                    if (!$stmt) {
                        $error = 'Database error. Please try again later.';
                        logSecurityEvent('db_error', null, $conn->error);
                    } else {
                        $stmt->bind_param('sss', $username, $email, $hashed_password);
                        if ($stmt->execute()) {
                            $success = 'Registration successful! Redirecting to login...';
                            logSecurityEvent('registration_success', null, "New user registered: $email from $client_ip");
                            header("refresh:2;url=login.php");
                        } else {
                            $error = 'Error during registration: ' . $stmt->error;
                            logSecurityEvent('registration_error', null, $stmt->error);
                        }
                        $stmt->close();
                    }
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
    <title>Register - Auth System</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/register.css">
</head>
<body>
    <div class="container">
        <div class="form-box">

            <div class="form-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M12 2L4 6v6c0 5.25 3.6 10.2 8 11.8 4.4-1.6 8-6.55 8-11.8V6L12 2z"/>
                    <path d="M12 8v4M12 16h.01"/>
                </svg>
            </div>

            <h2>Create Account</h2>
            <p class="form-subtitle">Join us — your vault is waiting</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>

            <form id="registerForm" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCSRFToken()); ?>">
                <div class="form-group">
                    <div class="input-wrapper">
                        <svg class="input-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="8" r="4"/>
                            <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                        </svg>
                        <input type="text" id="username" name="username" placeholder="Username" required>
                    </div>
                    <span class="error-msg" id="usernameError"></span>
                </div>

                <div class="form-group">
                    <div class="input-wrapper">
                        <svg class="input-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="4" width="20" height="16" rx="2"/>
                            <path d="m22 7-10 7L2 7"/>
                        </svg>
                        <input type="email" id="email" name="email" placeholder="Email address" required>
                    </div>
                    <span class="error-msg" id="emailError"></span>
                </div>

                <div class="form-group">
                    <div class="input-wrapper">
                        <svg class="input-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <input type="password" id="password" name="password" placeholder="Password" required class="has-eye" oninput="updateStrength(this.value)">
                        <button type="button" class="eye-btn" onclick="togglePass('password',this)" tabindex="-1">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    <div class="strength-track"><div class="strength-bar" id="strengthBar"></div></div>
                    <span class="strength-label" id="strengthLabel"></span>
                    <span class="error-msg" id="passwordError"></span>
                </div>

                <div class="form-group">
                    <div class="input-wrapper">
                        <svg class="input-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm password" required class="has-eye">
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
                    Create Account
                </button>
            </form>

            <p class="link-text">Already have an account? <a href="login.php">Sign in</a></p>
        </div>
    </div>

    <script>
    function togglePass(id, btn) {
        const inp = document.getElementById(id);
        inp.type = inp.type === 'password' ? 'text' : 'password';
        btn.classList.toggle('active');
    }
    function updateStrength(val) {
        const bar = document.getElementById('strengthBar');
        const lbl = document.getElementById('strengthLabel');
        let score = 0;
        if (val.length >= 8) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;
        const levels = [
            { w: '0%',   c: 'transparent', t: '' },
            { w: '25%',  c: '#ef4444',     t: 'Weak' },
            { w: '50%',  c: '#f97316',     t: 'Fair' },
            { w: '75%',  c: '#eab308',     t: 'Good' },
            { w: '100%', c: '#22c55e',     t: 'Strong' },
        ];
        const lvl = val.length === 0 ? levels[0] : levels[score] || levels[1];
        bar.style.width = lvl.w;
        bar.style.background = lvl.c;
        lbl.textContent = lvl.t;
        lbl.style.color = lvl.c;
    }
    </script>
    <script src="js/validation.js"></script>
</body>
</html>

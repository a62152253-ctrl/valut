<?php
session_start();
include 'includes/db.php';
include 'includes/RateLimiter.php';
include 'includes/RememberMeManager.php';
include 'includes/CAPTCHAManager.php';

RateLimiter::init($conn);

// ── Session expiry ─────────────────────────────────────────────────────────────
if (!isset($_SESSION['CREATED'])) {
    $_SESSION['CREATED'] = time();
} elseif (time() - $_SESSION['CREATED'] > SESSION_TIMEOUT) {
    session_unset();
    session_destroy();
    session_start();
    $_SESSION['CREATED'] = time();
}

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

// ── Auto-login via Remember Me cookie ─────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    $rememberedId = RememberMeManager::verify($conn);
    if ($rememberedId) {
        $stmt = $conn->prepare("SELECT id, username, email FROM users WHERE id = ?");
        $stmt->bind_param('i', $rememberedId);
        $stmt->execute();
        $remUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($remUser) {
            $_SESSION['user_id']    = $remUser['id'];
            $_SESSION['username']   = $remUser['username'];
            $_SESSION['email']      = $remUser['email'];
            $_SESSION['CREATED']    = time();
            $_SESSION['ip_address'] = $client_ip;
            session_regenerate_id(true);
            logSecurityEvent('login_remember_me', $remUser['id'], "Auto-login via remember token from $client_ip");
            header('Location: dashboard.php');
            exit();
        }
    }
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

getCSRFToken();

$error         = '';
$showCaptcha   = false;
$captchaActive = !empty(getenv('CAPTCHA_SITE_KEY')); // Only enforce if site key is configured

// Track failed attempts in session for CAPTCHA threshold
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}
$showCaptcha = $captchaActive && CAPTCHAManager::shouldRequire($_SESSION['login_attempts']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check (timing-safe)
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $error = 'Security token mismatch. Please try again.';
        logSecurityEvent('csrf_violation', null, "Login CSRF mismatch from $client_ip");
    } else {
        $email    = sanitize($_POST['email'] ?? '');
        $password = rawString($_POST['password'] ?? '');

        // DB-based rate limit: 5 attempts per 15 min per IP+email
        $rl = RateLimiter::check('login', 5, 900, $email);
        if ($rl['limited']) {
            $mins  = (int)ceil($rl['reset_in'] / 60);
            $error = "Too many login attempts. Try again in {$mins} minute" . ($mins !== 1 ? 's' : '') . '.';
            logSecurityEvent('rate_limit_exceeded', null, "Login rate limit from $client_ip for $email");
        } elseif ($showCaptcha) {
            // Verify CAPTCHA when threshold reached
            $captchaResp = CAPTCHAManager::verify($_POST['h-captcha-response'] ?? '', $client_ip);
            if (!$captchaResp['success']) {
                $error = 'Please complete the CAPTCHA.';
            }
        }

        if (!$error) {
            if (empty($email) || empty($password)) {
                $error = 'Email and password are required';
                $_SESSION['login_attempts']++;
            } elseif (!validateEmail($email)) {
                $error = 'Invalid email format';
                $_SESSION['login_attempts']++;
            } else {
                $stmt = $conn->prepare(
                    "SELECT id, username, email, password, totp_enabled FROM users WHERE email = ?"
                );
                if (!$stmt) {
                    $error = 'Database error. Please try again later.';
                    logSecurityEvent('db_error', null, $conn->error);
                } else {
                    $stmt->bind_param('s', $email);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($row && verifyPassword($password, $row['password'])) {
                        // Successful login — clear rate limit and attempt counter
                        RateLimiter::clear('login', $email);
                        $_SESSION['login_attempts'] = 0;

                        // 2FA check
                        if (!empty($row['totp_enabled'])) {
                            $_SESSION['2fa_pending_user'] = [
                                'id'       => $row['id'],
                                'username' => $row['username'],
                                'email'    => $row['email'],
                            ];
                            logSecurityEvent('2fa_required', $row['id'], "2FA required from $client_ip");
                            header('Location: 2fa_verify.php');
                            exit();
                        }

                        // Establish session
                        $_SESSION['user_id']    = $row['id'];
                        $_SESSION['username']   = $row['username'];
                        $_SESSION['email']      = $row['email'];
                        $_SESSION['CREATED']    = time();
                        $_SESSION['ip_address'] = $client_ip;
                        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
                        session_regenerate_id(true);

                        // Remember Me
                        if (!empty($_POST['remember'])) {
                            RememberMeManager::create($row['id'], $conn);
                        }

                        logSecurityEvent('login_success', $row['id'], "Successful login from $client_ip");
                        header('Location: dashboard.php');
                        exit();
                    } else {
                        $error = 'Invalid email or password';
                        $_SESSION['login_attempts']++;
                        logSecurityEvent('login_failed', null, "Failed login for $email from $client_ip");
                        // Refresh CAPTCHA threshold
                        $showCaptcha = $captchaActive && CAPTCHAManager::shouldRequire($_SESSION['login_attempts']);
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
    <title>Login - Auth System</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/login.css">
</head>
<body>
    <div class="container">
        <div class="form-box">

            <div class="form-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M12 2L4 6v6c0 5.25 3.6 10.2 8 11.8 4.4-1.6 8-6.55 8-11.8V6L12 2z"/>
                    <rect x="9" y="11" width="6" height="5" rx="1"/>
                    <path d="M12 11V9a2 2 0 0 0-4 0v2"/>
                </svg>
            </div>

            <h2>Welcome back</h2>
            <p class="form-subtitle">Sign in to access your account securely</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>

            <form id="loginForm" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCSRFToken()); ?>">
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
                        <input type="password" id="password" name="password" placeholder="Master password" required class="has-eye">
                        <button type="button" class="eye-btn" onclick="togglePass('password',this)" tabindex="-1">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                    <span class="error-msg" id="passwordError"></span>
                </div>

                <div class="form-row">
                    <label class="remember-label">
                        <input type="checkbox" name="remember" id="remember">
                        Remember me
                    </label>
                    <a href="forgot_password.php" class="forgot-link">Forgot master password?</a>
                </div>

                <?php if ($showCaptcha): ?>
                    <?php echo CAPTCHAManager::getConditionalHTML(true); ?>
                <?php endif; ?>

                <button type="submit" class="btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <rect x="3" y="11" width="18" height="11" rx="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                    Unlock Vault
                </button>
            </form>

            <div class="alt-separator"><span>or continue with</span></div>

            <div class="alt-buttons">
                <button class="btn-alt" type="button" id="passkeyBtn" onclick="loginPasskey()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        <circle cx="12" cy="16" r="1" fill="currentColor"/>
                    </svg>
                    Windows Hello / PIN
                </button>
            </div>
            <div id="passkeyMsg" style="font-size:12px;color:#a0a0b0;text-align:center;margin-top:8px;display:none;"></div>

            <div class="security-note">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2L4 6v6c0 5.25 3.6 10.2 8 11.8 4.4-1.6 8-6.55 8-11.8V6L12 2z"/>
                    <path d="m9 12 2 2 4-4"/>
                </svg>
                Your data is encrypted end-to-end.
                <a href="#">Learn more</a>
            </div>

            <p class="link-text">Don't have an account? <a href="register.php">Sign up</a></p>
        </div>
    </div>

    <script>
    function togglePass(id, btn) {
        const inp = document.getElementById(id);
        inp.type = inp.type === 'password' ? 'text' : 'password';
        btn.classList.toggle('active');
    }

    // ── WebAuthn login ────────────────────────────────────────────────────────
    function b64u(buf) {
        return btoa(String.fromCharCode(...new Uint8Array(buf)))
            .replace(/\+/g,'-').replace(/\//g,'_').replace(/=/g,'');
    }
    function b64u_to_buf(s) {
        s = s.replace(/-/g,'+').replace(/_/g,'/');
        while (s.length % 4) s += '=';
        return Uint8Array.from(atob(s), c => c.charCodeAt(0)).buffer;
    }

    function pkMsg(msg, color) {
        const el = document.getElementById('passkeyMsg');
        el.textContent = msg;
        el.style.color  = color || '#a0a0b0';
        el.style.display = msg ? 'block' : 'none';
    }

    async function loginPasskey() {
        if (!window.PublicKeyCredential) {
            pkMsg('Ta przeglądarka nie obsługuje WebAuthn.', '#ef4444'); return;
        }

        const btn      = document.getElementById('passkeyBtn');
        const username = document.getElementById('email')?.value.trim() || '';
        btn.disabled   = true;
        pkMsg('Pobieranie opcji…');

        try {
            // 1. Get challenge
            const startR = await fetch('api/webauthn.php', {
                method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({ action:'login_start', username })
            });
            const opts = await startR.json();
            if (opts.error) { pkMsg(opts.error, '#ef4444'); return; }

            // 2. Convert fields
            opts.challenge = b64u_to_buf(opts.challenge);
            if (opts.allowCredentials?.length) {
                opts.allowCredentials = opts.allowCredentials.map(c =>
                    ({ ...c, id: b64u_to_buf(c.id) })
                );
            }

            pkMsg('Czekam na Windows Hello / PIN…');

            // 3. Trigger Windows Hello dialog
            const assertion = await navigator.credentials.get({ publicKey: opts });

            pkMsg('Weryfikacja…');

            // 4. Send assertion to server
            const finishR = await fetch('api/webauthn.php', {
                method:'POST', headers:{'Content-Type':'application/json'},
                body: JSON.stringify({
                    action:            'login_finish',
                    id:                b64u(assertion.rawId),
                    clientDataJSON:    b64u(assertion.response.clientDataJSON),
                    authenticatorData: b64u(assertion.response.authenticatorData),
                    signature:         b64u(assertion.response.signature),
                })
            });
            const result = await finishR.json();

            if (result.ok) {
                pkMsg('✓ Zalogowano! Przekierowuję…', '#22c55e');
                window.location.href = result.redirect || 'dashboard.php';
            } else {
                pkMsg('Błąd: ' + (result.error || 'Nieznany'), '#ef4444');
            }
        } catch(e) {
            if (e.name === 'NotAllowedError') {
                pkMsg('Anulowano lub przekroczono czas.', '#f59e0b');
            } else {
                pkMsg('Błąd: ' + e.message, '#ef4444');
            }
        } finally {
            btn.disabled = false;
        }
    }

    // Hide passkey button if WebAuthn not available
    if (!window.PublicKeyCredential) {
        document.getElementById('passkeyBtn')?.remove();
    }
    </script>
    <script src="js/validation.js"></script>
</body>
</html>

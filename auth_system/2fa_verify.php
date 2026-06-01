<?php
session_start();
include 'includes/db.php';

// Must have a pending 2FA user in session
if (!isset($_SESSION['2fa_pending_user'])) {
    header('Location: login.php');
    exit;
}

$pending = $_SESSION['2fa_pending_user'];
$username = htmlspecialchars($pending['username'] ?? 'User');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Authentication – Vaultly</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/login.css">
    <style>
        .code-inputs {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin: 1.5rem 0 0.5rem;
        }

        .code-inputs input {
            width: 46px;
            height: 56px;
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            font-family: 'Courier New', monospace;
            background: rgba(255,255,255,.05);
            border: 2px solid rgba(255,255,255,.1);
            border-radius: 10px;
            color: #f1f5f9;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
            caret-color: transparent;
        }

        .code-inputs input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,.2);
        }

        .code-inputs input.filled {
            border-color: rgba(59,130,246,.5);
            background: rgba(59,130,246,.08);
        }

        .backup-toggle {
            background: none;
            border: none;
            color: #60a5fa;
            font-size: .8rem;
            cursor: pointer;
            text-decoration: underline;
            margin-top: .25rem;
            display: block;
        }

        .backup-toggle:hover { color: #93c5fd; }

        .backup-field {
            display: none;
            margin-top: 1rem;
        }

        .backup-field input {
            width: 100%;
            padding: .75rem 1rem;
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 9px;
            color: #f1f5f9;
            font-size: .9rem;
            text-align: center;
            letter-spacing: .1em;
            outline: none;
            font-family: 'Courier New', monospace;
            text-transform: uppercase;
        }

        .backup-field input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,.15);
        }

        .timer-bar {
            height: 3px;
            background: rgba(255,255,255,.08);
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: .25rem;
        }

        .timer-fill {
            height: 100%;
            background: linear-gradient(90deg, #22c55e, #3b82f6);
            border-radius: 2px;
            transition: width 1s linear;
        }

        .timer-text {
            font-size: .72rem;
            color: #475569;
            text-align: right;
        }

        .err-msg {
            background: rgba(239,68,68,.1);
            border: 1px solid rgba(239,68,68,.25);
            color: #f87171;
            border-radius: 8px;
            padding: .625rem 1rem;
            font-size: .82rem;
            margin-bottom: 1rem;
            display: none;
        }

        .err-msg.show { display: block; }
    </style>
</head>
<body>
    <div class="container">
        <div class="form-box">

            <div class="form-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <rect x="5" y="2" width="14" height="20" rx="2"/>
                    <line x1="12" y1="18" x2="12" y2="18.01" stroke-width="3" stroke-linecap="round"/>
                </svg>
            </div>

            <h2>Two-Factor Auth</h2>
            <p class="form-subtitle">Hi <strong><?php echo $username; ?></strong> — open your authenticator app and enter the 6-digit code.</p>

            <div class="err-msg" id="errMsg"></div>

            <!-- TOTP code inputs -->
            <div class="code-inputs" id="codeInputs">
                <input type="text" maxlength="1" inputmode="numeric" pattern="\d" autocomplete="one-time-code">
                <input type="text" maxlength="1" inputmode="numeric" pattern="\d">
                <input type="text" maxlength="1" inputmode="numeric" pattern="\d">
                <input type="text" maxlength="1" inputmode="numeric" pattern="\d">
                <input type="text" maxlength="1" inputmode="numeric" pattern="\d">
                <input type="text" maxlength="1" inputmode="numeric" pattern="\d">
            </div>

            <!-- Timer -->
            <div class="timer-bar"><div class="timer-fill" id="timerFill"></div></div>
            <div class="timer-text" id="timerText">30s</div>

            <!-- Backup code toggle -->
            <button class="backup-toggle" id="backupToggle" onclick="toggleBackup()">Use a backup code instead</button>
            <div class="backup-field" id="backupField">
                <input type="text" id="backupInput" placeholder="XXXX-XXXX" maxlength="9" spellcheck="false">
            </div>

            <button type="button" class="btn" id="verifyBtn" onclick="submit()" style="margin-top:1.25rem;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 12 2 2 4-4"/><path d="M12 2L4 6v6c0 5.25 3.6 10.2 8 11.8 4.4-1.6 8-6.55 8-11.8V6L12 2z"/></svg>
                Verify
            </button>

            <p class="link-text" style="margin-top:1.25rem;">
                <a href="login.php">← Back to login</a>
            </p>
        </div>
    </div>

    <script>
    const inputs = Array.from(document.querySelectorAll('#codeInputs input'));
    let usingBackup = false;

    // ── Digit inputs ──────────────────────────────────────────────────────────
    inputs.forEach((inp, i) => {
        inp.addEventListener('input', () => {
            inp.value = inp.value.replace(/\D/g, '').slice(-1);
            inp.classList.toggle('filled', inp.value !== '');
            if (inp.value && i < inputs.length - 1) inputs[i + 1].focus();
            if (inputs.every(x => x.value)) submit();
        });
        inp.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !inp.value && i > 0) inputs[i - 1].focus();
            if (e.key === 'ArrowLeft'  && i > 0) { e.preventDefault(); inputs[i - 1].focus(); }
            if (e.key === 'ArrowRight' && i < inputs.length - 1) { e.preventDefault(); inputs[i + 1].focus(); }
        });
        inp.addEventListener('paste', e => {
            e.preventDefault();
            const digits = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
            digits.split('').forEach((d, j) => {
                if (inputs[j]) { inputs[j].value = d; inputs[j].classList.add('filled'); }
            });
            if (digits.length === 6) submit();
        });
    });

    inputs[0].focus();

    // ── Backup toggle ─────────────────────────────────────────────────────────
    function toggleBackup() {
        usingBackup = !usingBackup;
        document.getElementById('backupField').style.display = usingBackup ? 'block' : 'none';
        document.getElementById('codeInputs').style.display  = usingBackup ? 'none'  : 'flex';
        document.getElementById('backupToggle').textContent   = usingBackup
            ? 'Use authenticator code instead'
            : 'Use a backup code instead';
        if (usingBackup) document.getElementById('backupInput').focus();
        else inputs[0].focus();
    }

    // ── Timer ─────────────────────────────────────────────────────────────────
    function updateTimer() {
        const secs = 30 - (Math.floor(Date.now() / 1000) % 30);
        const pct  = (secs / 30) * 100;
        document.getElementById('timerFill').style.width = pct + '%';
        document.getElementById('timerFill').style.background =
            secs <= 5 ? '#ef4444' : secs <= 10 ? '#f59e0b' : 'linear-gradient(90deg,#22c55e,#3b82f6)';
        document.getElementById('timerText').textContent = secs + 's';
    }
    updateTimer();
    setInterval(updateTimer, 1000);

    // ── Submit ────────────────────────────────────────────────────────────────
    async function submit() {
        const btn  = document.getElementById('verifyBtn');
        const errEl = document.getElementById('errMsg');
        errEl.classList.remove('show');

        let code;
        if (usingBackup) {
            code = document.getElementById('backupInput').value.trim().toUpperCase();
        } else {
            code = inputs.map(x => x.value).join('');
            if (code.length < 6) return;
        }

        btn.disabled = true;
        btn.textContent = 'Verifying…';

        const fd = new FormData();
        fd.append('action', 'verify_login');
        fd.append('code', code);

        try {
            const res  = await fetch('api/2fa.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.ok) {
                btn.textContent = '✓ Verified';
                window.location.href = data.redirect || 'dashboard.php';
            } else {
                errEl.textContent = data.error || 'Invalid code.';
                errEl.classList.add('show');
                inputs.forEach(x => { x.value = ''; x.classList.remove('filled'); });
                if (!usingBackup) inputs[0].focus();
                btn.disabled = false;
                btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m9 12 2 2 4-4"/><path d="M12 2L4 6v6c0 5.25 3.6 10.2 8 11.8 4.4-1.6 8-6.55 8-11.8V6L12 2z"/></svg> Verify';
            }
        } catch {
            errEl.textContent = 'Network error. Please try again.';
            errEl.classList.add('show');
            btn.disabled = false;
        }
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Enter') submit();
    });
    </script>
</body>
</html>

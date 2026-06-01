<?php
/**
 * WebAuthn API — register & authenticate via Windows Hello (PIN / password).
 * No external libraries — pure PHP crypto.
 *
 * Actions:
 *   register_start   → publicKeyCredentialCreationOptions  (requires auth)
 *   register_finish  → verify attestation, save credential  (requires auth)
 *   login_start      → publicKeyCredentialRequestOptions    (no auth needed)
 *   login_finish     → verify assertion, create session      (no auth needed)
 *   list             → list saved credentials               (requires auth)
 *   delete           → remove a credential                  (requires auth)
 */

session_start();
include '../includes/db.php';

/** @var mysqli $conn injected by db.php */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? $_GET['action'] ?? '';

// ── HTTPS guard — WebAuthn requires secure context ────────────────────────
$isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
         || ($_SERVER['SERVER_PORT'] ?? '') === '443'
         || (rp_id() === 'localhost');   // localhost is always allowed by spec

if (!$isSecure) {
    fail('WebAuthn requires HTTPS. Open the app via https://ticfastr.local/', 400);
}

// ── Ensure webauthn_credentials table exists ──────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS `webauthn_credentials` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT NOT NULL,
  `credential_id`  VARCHAR(700) NOT NULL,
  `public_key_pem` TEXT NOT NULL,
  `sign_count`     INT NOT NULL DEFAULT 0,
  `name`           VARCHAR(100) NOT NULL DEFAULT 'Windows Hello',
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `last_used`      TIMESTAMP NULL,
  UNIQUE KEY `uq_cred` (`credential_id`(191)),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Helpers ───────────────────────────────────────────────────────────────

function ok(array $data): never  { echo json_encode($data); exit; }
function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function b64u_enc(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function b64u_dec(string $s): string {
    $pad = (4 - strlen($s) % 4) % 4;
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', $pad));
}
function challenge(): string { return b64u_enc(random_bytes(32)); }

function rp_id(): string {
    // WebAuthn rpId must be hostname ONLY — no port, no protocol
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return strtolower(preg_replace('/:\d+$/', '', $host));
}

function origin(): string {
    // Apache sets HTTPS=on; nginx sets fastcgi_param HTTPS on
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $s    = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Include port in origin only if non-standard
    $port = (int)($_SERVER['SERVER_PORT'] ?? 0);
    $standardPort = ($s === 'https' && $port === 443) || ($s === 'http' && $port === 80);
    if (!$standardPort && $port && !str_contains($host, ':')) {
        $host .= ':' . $port;
    }
    return $s . '://' . $host;
}

// ── Minimal CBOR decoder ──────────────────────────────────────────────────

function cbor_decode(string $buf, int &$off = 0): mixed {
    if ($off >= strlen($buf)) return null;
    $b     = ord($buf[$off++]);
    $major = $b >> 5;
    $info  = $b & 0x1f;

    if ($info < 24)      $val = $info;
    elseif ($info === 24) $val = ord($buf[$off++]);
    elseif ($info === 25) { $val = unpack('n', substr($buf, $off, 2))[1]; $off += 2; }
    elseif ($info === 26) { $val = unpack('N', substr($buf, $off, 4))[1]; $off += 4; }
    elseif ($info === 27) {
        $hi = unpack('N', substr($buf, $off, 4))[1]; $off += 4;
        $lo = unpack('N', substr($buf, $off, 4))[1]; $off += 4;
        $val = ($hi * 4294967296) + $lo;
    } else $val = 0;

    switch ($major) {
        case 0: return (int)$val;
        case 1: return -1 - (int)$val;
        case 2: $r = substr($buf, $off, $val); $off += $val; return $r;
        case 3: $r = substr($buf, $off, $val); $off += $val; return $r;
        case 4:
            $arr = [];
            for ($i = 0; $i < $val; $i++) $arr[] = cbor_decode($buf, $off);
            return $arr;
        case 5:
            $map = [];
            for ($i = 0; $i < $val; $i++) {
                $k = cbor_decode($buf, $off);
                $v = cbor_decode($buf, $off);
                $map[$k] = $v;
            }
            return $map;
        default: return null;
    }
}

// ── DER/ASN.1 helpers ─────────────────────────────────────────────────────

function der_len(int $n): string {
    if ($n < 128) return chr($n);
    $b = ''; $t = $n;
    while ($t > 0) { $b = chr($t & 0xff) . $b; $t >>= 8; }
    return chr(0x80 | strlen($b)) . $b;
}
function der_tlv(int $tag, string $v): string { return chr($tag) . der_len(strlen($v)) . $v; }
function der_seq(string $v): string       { return der_tlv(0x30, $v); }
function der_oid(string $raw): string     { return der_tlv(0x06, $raw); }
function der_bitstr(string $v): string    { return der_tlv(0x03, $v); }
function der_int(string $v): string {
    // Minimal DER integer: strip leading 0x00 unless needed for sign
    while (strlen($v) > 1 && $v[0] === "\x00" && (ord($v[1]) & 0x80) === 0) $v = substr($v, 1);
    if (strlen($v) === 0 || (ord($v[0]) & 0x80)) $v = "\x00" . $v; // add sign byte if needed
    return der_tlv(0x02, $v);
}

// ── COSE public key → PEM ─────────────────────────────────────────────────

function cose_to_pem(array $cose): string|false {
    $kty = $cose[1] ?? null;

    // EC2 (ES256 — P-256) — most common in Windows Hello
    if ($kty === 2) {
        $x = $cose[-2] ?? '';
        $y = $cose[-3] ?? '';
        if (strlen($x) !== 32 || strlen($y) !== 32) return false;

        // OID for id-ecPublicKey  (1.2.840.10045.2.1)
        $oid_ec   = "\x2a\x86\x48\xce\x3d\x02\x01";
        // OID for prime256v1 / P-256  (1.2.840.10045.3.1.7)
        $oid_p256 = "\x2a\x86\x48\xce\x3d\x03\x01\x07";

        $algo = der_seq(der_oid($oid_ec) . der_oid($oid_p256));
        $key  = der_bitstr("\x00\x04" . $x . $y);   // 0x00 = no unused bits; 0x04 = uncompressed
        $spki = der_seq($algo . $key);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64) . "-----END PUBLIC KEY-----\n";
    }

    // RSA (RS256) — also used by Windows Hello
    if ($kty === 3) {
        $n = $cose[-1] ?? '';
        $e = $cose[-2] ?? '';
        if (!$n || !$e) return false;

        $oid_rsa = "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";   // 1.2.840.113549.1.1.1
        $algo    = der_seq(der_oid($oid_rsa) . "\x05\x00");
        $rsaKey  = der_seq(der_int($n) . der_int($e));
        $key     = der_bitstr("\x00" . $rsaKey);
        $spki    = der_seq($algo . $key);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64) . "-----END PUBLIC KEY-----\n";
    }

    return false;
}

// ── Auth guard (for actions that require login) ───────────────────────────

function need_auth(): int {
    if (!isset($_SESSION['user_id'])) fail('Not authenticated', 401);
    return (int)$_SESSION['user_id'];
}

// ── Rate limiter ──────────────────────────────────────────────────────────
function rate_limit(string $key, int $max, int $window): void {
    $k = 'rl_' . $key;
    if (!isset($_SESSION[$k])) $_SESSION[$k] = ['n' => 0, 't' => time()];
    if (time() - $_SESSION[$k]['t'] > $window) $_SESSION[$k] = ['n' => 0, 't' => time()];
    if (++$_SESSION[$k]['n'] > $max) fail('Rate limit exceeded. Try again later.', 429);
}

// ═════════════════════════════════════════════════════════════════════════════
// Actions
// ═════════════════════════════════════════════════════════════════════════════

switch ($action) {

    // ── 1. Start registration ─────────────────────────────────────────────
    case 'register_start': {
        $uid      = need_auth();
        $username = htmlspecialchars($_SESSION['username'] ?? 'user', ENT_QUOTES);
        $ch       = challenge();
        $_SESSION['wau_reg_ch'] = $ch;

        // Collect existing credential IDs so authenticator can skip them
        $excl = [];
        $st   = $conn->prepare("SELECT credential_id FROM webauthn_credentials WHERE user_id = ?");
        $st->bind_param('i', $uid);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $excl[] = ['type' => 'public-key', 'id' => $row['credential_id']];
        }

        ok([
            'challenge' => $ch,
            'rp'        => ['id' => rp_id(), 'name' => 'Vaultly'],
            'user'      => [
                'id'          => b64u_enc(pack('N', $uid)),
                'name'        => $username,
                'displayName' => $username,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],    // ES256
                ['type' => 'public-key', 'alg' => -257],   // RS256
            ],
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',     // Windows Hello only
                'residentKey'             => 'preferred',
                'userVerification'        => 'required',     // forces PIN/password entry
            ],
            'excludeCredentials' => $excl,
            'timeout'     => 60000,
            'attestation' => 'none',
        ]);
    }

    // ── 2. Finish registration ────────────────────────────────────────────
    case 'register_finish': {
        $uid = need_auth();
        rate_limit('wau_reg_' . $uid, 10, 3600);

        $ch = $_SESSION['wau_reg_ch'] ?? '';
        if (!$ch) fail('No challenge in session');
        unset($_SESSION['wau_reg_ch']);

        $credId   = $body['id']                 ?? '';
        $cdJSON_b = $body['clientDataJSON']      ?? '';
        $att_b    = $body['attestationObject']   ?? '';
        $name     = substr(strip_tags($body['name'] ?? 'Windows Hello'), 0, 100);

        if (!$credId || !$cdJSON_b || !$att_b) fail('Missing fields');

        // ── Verify clientDataJSON ──────────────────────────────────────────
        $cd = json_decode(b64u_dec($cdJSON_b), true);
        if (!$cd)                                             fail('Bad clientDataJSON');
        if ($cd['type'] !== 'webauthn.create')                fail('Wrong type');
        if ($cd['challenge'] !== $ch)                         fail('Challenge mismatch');
        $expectedOrigin = rtrim(origin(), '/');
        if (rtrim($cd['origin'], '/') !== $expectedOrigin)    fail('Origin mismatch: ' . $cd['origin']);

        // ── Parse attestationObject (CBOR) ─────────────────────────────────
        $att = cbor_decode(b64u_dec($att_b));
        if (!isset($att['authData']))                         fail('No authData');

        $authData = $att['authData'];
        if (strlen($authData) < 37)                           fail('authData too short');

        $rpIdHash  = substr($authData, 0,  32);
        $flags     = ord($authData[32]);
        $signCount = unpack('N', substr($authData, 33, 4))[1];

        // Verify RP ID hash
        if (!hash_equals(hash('sha256', rp_id(), true), $rpIdHash)) fail('RP ID hash mismatch');

        // UP (user present) flag must be set
        if (!($flags & 0x01)) fail('User not present');
        // UV (user verified) must be set  (ensures PIN was entered)
        if (!($flags & 0x04)) fail('User not verified — PIN not entered');
        // AT (attested credential) flag must be set
        if (!($flags & 0x40)) fail('No attested credential data');

        // ── Parse attested credential data ─────────────────────────────────
        $pos       = 37 + 16;                         // skip aaguid
        $cidLen    = unpack('n', substr($authData, $pos, 2))[1]; $pos += 2;
        $rawCredId = substr($authData, $pos, $cidLen);           $pos += $cidLen;
        $rawPubKey = substr($authData, $pos);

        $cosePubKey = cbor_decode($rawPubKey);
        if (!$cosePubKey) fail('Failed to parse COSE public key');

        $pem = cose_to_pem($cosePubKey);
        if (!$pem) fail('Unsupported key type (need ES256 or RS256)');

        $credIdB64 = b64u_enc($rawCredId);

        // Check duplicate
        $ck = $conn->prepare("SELECT id FROM webauthn_credentials WHERE credential_id = ?");
        $ck->bind_param('s', $credIdB64);
        $ck->execute();
        if ($ck->get_result()->num_rows > 0) fail('Credential already registered', 409);

        // Save
        $ins = $conn->prepare(
            "INSERT INTO webauthn_credentials (user_id, credential_id, public_key_pem, sign_count, name)
             VALUES (?, ?, ?, ?, ?)"
        );
        $ins->bind_param('issis', $uid, $credIdB64, $pem, $signCount, $name);
        $ins->execute() ? ok(['ok' => true, 'id' => $conn->insert_id]) : fail('DB error', 500);
    }

    // ── 3. Start login ────────────────────────────────────────────────────
    case 'login_start': {
        rate_limit('wau_login_' . ($_SERVER['REMOTE_ADDR'] ?? ''), 20, 300);

        $ch = challenge();
        $_SESSION['wau_login_ch'] = $ch;

        $username  = trim(strip_tags($body['username'] ?? ''));
        $allowList = [];

        if ($username) {
            $us = $conn->prepare("SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1");
            $us->bind_param('ss', $username, $username);
            $us->execute();
            $urow = $us->get_result()->fetch_assoc();
            if ($urow) {
                $uid = (int)$urow['id'];
                $_SESSION['wau_login_uid'] = $uid;
                $cs = $conn->prepare("SELECT credential_id FROM webauthn_credentials WHERE user_id = ?");
                $cs->bind_param('i', $uid);
                $cs->execute();
                foreach ($cs->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                    $allowList[] = ['type' => 'public-key', 'id' => $r['credential_id']];
                }
            }
        }

        ok([
            'challenge'        => $ch,
            'rpId'             => rp_id(),
            'allowCredentials' => $allowList,
            'userVerification' => 'required',
            'timeout'          => 60000,
        ]);
    }

    // ── 4. Finish login ───────────────────────────────────────────────────
    case 'login_finish': {
        $ch       = $_SESSION['wau_login_ch']  ?? '';
        $loginUid = $_SESSION['wau_login_uid'] ?? null;
        if (!$ch) fail('No challenge in session');
        unset($_SESSION['wau_login_ch'], $_SESSION['wau_login_uid']);

        $credIdB64 = $body['id']               ?? '';
        $cdJSON_b  = $body['clientDataJSON']    ?? '';
        $authData_b = $body['authenticatorData'] ?? '';
        $sig_b     = $body['signature']         ?? '';

        if (!$credIdB64 || !$cdJSON_b || !$authData_b || !$sig_b) fail('Missing fields');

        // ── Verify clientDataJSON ──────────────────────────────────────────
        $cd = json_decode(b64u_dec($cdJSON_b), true);
        if (!$cd)                                          fail('Bad clientDataJSON');
        if ($cd['type'] !== 'webauthn.get')                fail('Wrong type');
        if ($cd['challenge'] !== $ch)                      fail('Challenge mismatch');
        if (rtrim($cd['origin'], '/') !== rtrim(origin(), '/')) fail('Origin mismatch');

        // ── Look up credential ─────────────────────────────────────────────
        $st = $conn->prepare(
            "SELECT wc.*, u.id AS uid, u.username, u.email
             FROM webauthn_credentials wc JOIN users u ON u.id = wc.user_id
             WHERE wc.credential_id = ?"
        );
        $st->bind_param('s', $credIdB64);
        $st->execute();
        $cred = $st->get_result()->fetch_assoc();
        if (!$cred) fail('Unknown credential');

        // If we know which user should be logging in, verify it matches
        if ($loginUid && (int)$cred['uid'] !== (int)$loginUid) fail('User mismatch');

        // ── Verify authData ────────────────────────────────────────────────
        $authData = b64u_dec($authData_b);
        if (strlen($authData) < 37) fail('authData too short');

        $rpIdHash  = substr($authData, 0,  32);
        $flags     = ord($authData[32]);
        $signCount = unpack('N', substr($authData, 33, 4))[1];

        if (!hash_equals(hash('sha256', rp_id(), true), $rpIdHash)) fail('RP ID mismatch');
        if (!($flags & 0x01)) fail('User not present');
        if (!($flags & 0x04)) fail('User not verified — PIN not entered');

        // ── Verify signature ───────────────────────────────────────────────
        // The signed data is: authData || SHA-256(clientDataJSON)
        $signedData = $authData . hash('sha256', b64u_dec($cdJSON_b), true);
        $sig        = b64u_dec($sig_b);

        $pubKey = openssl_pkey_get_public($cred['public_key_pem']);
        if (!$pubKey) fail('Could not load public key', 500);

        // Both ES256 (ECDSA) and RS256 (RSA) use SHA-256 for openssl_verify
        if (openssl_verify($signedData, $sig, $pubKey, OPENSSL_ALGO_SHA256) !== 1) fail('Signature invalid');

        // ── Replay protection ──────────────────────────────────────────────
        // Some platform authenticators always return 0 — only check if > 0
        if ($cred['sign_count'] > 0 && $signCount <= (int)$cred['sign_count']) {
            fail('Possible replay (sign count regression)');
        }

        // ── Update last_used + sign_count ──────────────────────────────────
        $upd = $conn->prepare("UPDATE webauthn_credentials SET sign_count=?, last_used=NOW() WHERE id=?");
        $upd->bind_param('ii', $signCount, $cred['id']);
        $upd->execute();

        // ── Create session ─────────────────────────────────────────────────
        $_SESSION['user_id']  = (int)$cred['uid'];
        $_SESSION['username'] = $cred['username'];
        $_SESSION['email']    = $cred['email'];
        $_SESSION['CREATED']  = time();
        session_regenerate_id(true);

        ok(['ok' => true, 'redirect' => 'dashboard.php']);
    }

    // ── 5. List credentials ───────────────────────────────────────────────
    case 'list': {
        $uid = need_auth();
        $st  = $conn->prepare(
            "SELECT id, name, created_at, last_used FROM webauthn_credentials
             WHERE user_id=? ORDER BY created_at DESC"
        );
        $st->bind_param('i', $uid);
        $st->execute();
        ok(['credentials' => $st->get_result()->fetch_all(MYSQLI_ASSOC)]);
    }

    // ── 6. Delete credential ──────────────────────────────────────────────
    case 'delete': {
        $uid = need_auth();
        $id  = (int)($body['id'] ?? 0);
        if (!$id) fail('Missing id');
        $st  = $conn->prepare("DELETE FROM webauthn_credentials WHERE id=? AND user_id=?");
        $st->bind_param('ii', $id, $uid);
        $st->execute();
        ok(['ok' => true]);
    }

    default:
        fail('Unknown action');
}

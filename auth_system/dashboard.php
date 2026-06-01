<?php
session_start();
include 'includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id    = (int)$_SESSION['user_id'];
$user_name  = htmlspecialchars($_SESSION['username'] ?? 'User');
$user_email = htmlspecialchars($_SESSION['email'] ?? '');
$user_avatar = strtoupper(substr($user_name, 0, 1));

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Total vault entries
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM vault_entries WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res = $stmt->get_result();
$total_entries = (int)($res->fetch_assoc()['total'] ?? 0);
$res->free();
$stmt->close();

// Type breakdown
$stmt = $conn->prepare("SELECT type, COUNT(*) as count FROM vault_entries WHERE user_id = ? GROUP BY type");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res = $stmt->get_result();
$type_counts = [];
while ($row = $res->fetch_assoc()) {
    $type_counts[$row['type']] = (int)$row['count'];
}
$res->free();
$stmt->close();

// Favorites count (titles decrypted client-side)
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM vault_entries WHERE user_id = ? AND favorite = 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$favorites_count = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$stmt->close();

// Recent activity metadata only (no encrypted_data — decrypted client-side)
$stmt = $conn->prepare("SELECT uuid, type, updated_at FROM vault_entries WHERE user_id = ? ORDER BY updated_at DESC LIMIT 8");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res = $stmt->get_result();
$recent_activity = $res->fetch_all(MYSQLI_ASSOC);
$res->free();
$stmt->close();

// 2FA status
$stmt = $conn->prepare("SELECT totp_enabled FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res = $stmt->get_result();
$totp_enabled = (bool)($res->fetch_assoc()['totp_enabled'] ?? false);
$res->free();
$stmt->close();

// Folders / vaults
$stmt = $conn->prepare("SELECT f.id, f.name, f.color, COUNT(e.id) as item_count
    FROM vault_folders f
    LEFT JOIN vault_entries e ON e.folder_id = f.id AND e.user_id = f.user_id
    WHERE f.user_id = ? GROUP BY f.id, f.name, f.color LIMIT 5");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res = $stmt->get_result();
$vaults = $res->fetch_all(MYSQLI_ASSOC);
$res->free();
$stmt->close();

// WebAuthn credentials count (for security score)
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM webauthn_credentials WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$webauthn_count = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$stmt->close();

// Vault initialized? (salt exists)
$stmt = $conn->prepare("SELECT user_id FROM vault_salt WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$vault_initialized = $stmt->get_result()->num_rows > 0;
$stmt->close();

// Security score — weighted on real factors, not entry count
$security_score = 20; // baseline: account exists
if ($vault_initialized)  $security_score += 20; // vault configured with master password
if ($totp_enabled)       $security_score += 25; // 2FA enabled
if ($webauthn_count > 0) $security_score += 15; // hardware/platform passkey
if ($total_entries >= 1) $security_score += 10; // vault in use
if ($total_entries >= 10) $security_score += 10; // actively managed

if ($security_score >= 80) {
    $score_color = '#22c55e';
} elseif ($security_score >= 50) {
    $score_color = '#f59e0b';
} else {
    $score_color = '#ef4444';
}
$circumference = round(2 * M_PI * 54, 2);
$dash_offset   = round($circumference * (1 - $security_score / 100), 2);

// Activity type meta (used in PHP-rendered timeline)
$type_icon  = ['login' => '🔐', 'note' => '📝', 'card' => '💳', 'identity' => '👤'];
$type_color = ['login' => '#3b82f6', 'note' => '#8b5cf6', 'card' => '#f59e0b', 'identity' => '#22c55e'];

$vault_icons = ['📘', '💼', '💰', '🌐', '🎯', '🔐'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard – Vaultly</title>
    <link rel="stylesheet" href="css/app.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <style>
        /* ── Override / extend dashboard.css for this page ── */
        html, body { height: 100%; }

        body {
            background: #0b0f1a;
            color: #e0e6f0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
        }

        /* ── Layout shell ── */
        .d-shell {
            display: flex;
            min-height: 100vh;
        }

        /* ── Sidebar ── */
        .d-sidebar {
            width: 240px;
            min-width: 240px;
            background: linear-gradient(180deg, #080c17 0%, #0d1425 100%);
            border-right: 1px solid rgba(255,255,255,0.06);
            display: flex;
            flex-direction: column;
            padding: 1.5rem 1rem;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            flex-shrink: 0;
        }

        .d-brand {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            padding: 0.75rem 0.5rem 1.75rem;
            font-size: 1.2rem;
            font-weight: 700;
            text-decoration: none;
            color: #fff;
        }

        .d-brand-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .d-brand-icon svg { color: #fff; }

        .d-brand span {
            background: linear-gradient(135deg, #60a5fa, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .d-nav { flex: 1; display: flex; flex-direction: column; gap: 2px; }

        .d-nav-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #475569;
            padding: 0.25rem 0.75rem;
            margin-top: 1rem;
            margin-bottom: 0.25rem;
        }

        .d-nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 0.875rem;
            border-radius: 9px;
            text-decoration: none;
            color: #94a3b8;
            font-size: 0.9rem;
            transition: background 0.2s, color 0.2s, transform 0.2s;
            position: relative;
        }

        .d-nav-link svg { flex-shrink: 0; opacity: 0.8; }

        .d-nav-link:hover {
            background: rgba(255,255,255,0.07);
            color: #e2e8f0;
            transform: translateX(3px);
        }

        .d-nav-link.active {
            background: linear-gradient(135deg, rgba(59,130,246,0.18), rgba(139,92,246,0.18));
            color: #60a5fa;
            border: 1px solid rgba(59,130,246,0.25);
        }

        .d-nav-link .badge-new {
            margin-left: auto;
            font-size: 0.65rem;
            font-weight: 700;
            background: linear-gradient(135deg, #8b5cf6, #3b82f6);
            color: #fff;
            padding: 0.15rem 0.45rem;
            border-radius: 4px;
        }

        .d-sidebar-footer {
            padding-top: 1rem;
            border-top: 1px solid rgba(255,255,255,0.06);
            margin-top: 1rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .d-vault-status {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.875rem;
            font-size: 0.8rem;
            color: #22c55e;
        }

        .d-vault-status::before {
            content: '';
            width: 8px;
            height: 8px;
            background: #22c55e;
            border-radius: 50%;
            box-shadow: 0 0 8px #22c55e;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        .d-logout {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.625rem 0.875rem;
            border-radius: 9px;
            text-decoration: none;
            color: #64748b;
            font-size: 0.9rem;
            transition: background 0.2s, color 0.2s;
        }

        .d-logout:hover {
            background: rgba(239,68,68,0.12);
            color: #ef4444;
        }

        /* ── Main area ── */
        .d-main {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            background: #0b0f1a;
        }

        /* ── Top bar ── */
        .d-topbar {
            background: rgba(11,15,26,0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            position: sticky;
            top: 0;
            z-index: 200;
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.875rem 2rem;
        }

        .d-search-wrap {
            flex: 1;
            max-width: 420px;
            position: relative;
        }

        .d-search-box {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 10px;
            padding: 0.625rem 1rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .d-search-box:focus-within {
            border-color: rgba(59,130,246,0.5);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.08);
        }

        .d-search-box svg { color: #475569; flex-shrink: 0; }

        .d-search-box input {
            flex: 1;
            background: none;
            border: none;
            outline: none;
            color: #e2e8f0;
            font-size: 0.9rem;
        }

        .d-search-box input::placeholder { color: #475569; }

        .d-kbd {
            font-size: 0.7rem;
            color: #475569;
            background: rgba(255,255,255,0.05);
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            white-space: nowrap;
        }

        .d-search-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            background: #111827;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            box-shadow: 0 16px 40px rgba(0,0,0,0.5);
            z-index: 500;
            overflow: hidden;
        }

        .d-search-dropdown.open { display: block; }

        .d-search-result {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1rem;
            cursor: pointer;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            transition: background 0.15s;
        }

        .d-search-result:last-child { border-bottom: none; }
        .d-search-result:hover { background: rgba(59,130,246,0.08); }

        .d-search-ricon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(59,130,246,0.12);
            color: #60a5fa;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .d-search-rtitle { font-size: 0.875rem; font-weight: 500; color: #e2e8f0; }
        .d-search-rtype  { font-size: 0.75rem; color: #64748b; }
        .d-no-results    { padding: 1.25rem; text-align: center; color: #475569; font-size: 0.875rem; }

        .d-topbar-right {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-left: auto;
        }

        .d-topbar-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 9px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.07);
            color: #94a3b8;
            cursor: pointer;
            transition: background 0.2s, color 0.2s;
            text-decoration: none;
        }

        .d-topbar-btn:hover { background: rgba(255,255,255,0.1); color: #e2e8f0; }

        .d-btn-add {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.125rem;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border: none;
            border-radius: 9px;
            color: #fff;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .d-btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59,130,246,0.35);
        }

        .d-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #8b5cf6, #3b82f6);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
            text-decoration: none;
            transition: box-shadow 0.2s;
        }

        .d-avatar:hover { box-shadow: 0 0 0 3px rgba(139,92,246,0.3); }

        /* ── Content area ── */
        .d-content {
            flex: 1;
            padding: 2rem;
            overflow-x: hidden;
        }

        /* ── Welcome ── */
        .d-welcome { margin-bottom: 2rem; }

        .d-welcome h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #f1f5f9;
            margin-bottom: 0.25rem;
        }

        .d-welcome h1 .grad {
            background: linear-gradient(135deg, #60a5fa, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .d-welcome p { color: #64748b; font-size: 0.9rem; }

        /* ── Stat cards ── */
        .d-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.75rem;
        }

        .d-stat {
            background: linear-gradient(135deg, rgba(255,255,255,0.05), rgba(255,255,255,0.02));
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: transform 0.2s, border-color 0.2s;
            cursor: default;
        }

        .d-stat:hover {
            transform: translateY(-3px);
            border-color: rgba(255,255,255,0.14);
        }

        .d-stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            flex-shrink: 0;
        }

        .d-stat-body { flex: 1; min-width: 0; }

        .d-stat-value {
            font-size: 1.6rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 0.2rem;
        }

        .d-stat-label {
            font-size: 0.8rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }

        .d-stat-sub { font-size: 0.75rem; color: #475569; margin-top: 0.15rem; }

        /* ── Two-column main grid ── */
        .d-grid {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 1.5rem;
        }

        .d-left  { display: flex; flex-direction: column; gap: 1.5rem; }
        .d-right { display: flex; flex-direction: column; gap: 1.5rem; }

        /* ── Card base ── */
        .d-card {
            background: linear-gradient(135deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02));
            border: 1px solid rgba(255,255,255,0.07);
            border-radius: 16px;
            padding: 1.5rem;
        }

        .d-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.25rem;
        }

        .d-card-title {
            font-size: 1rem;
            font-weight: 700;
            color: #f1f5f9;
        }

        .d-link {
            font-size: 0.825rem;
            color: #60a5fa;
            text-decoration: none;
            transition: color 0.2s;
        }

        .d-link:hover { color: #93c5fd; }

        /* ── Quick access ── */
        .d-qa-list { display: flex; flex-direction: column; gap: 0.625rem; }

        .d-qa-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.875rem 1rem;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 11px;
            cursor: pointer;
            transition: background 0.2s, border-color 0.2s, transform 0.2s;
        }

        .d-qa-item:hover {
            background: rgba(255,255,255,0.07);
            border-color: rgba(255,255,255,0.12);
            transform: translateX(3px);
        }

        .d-qa-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }

        .d-qa-info { flex: 1; min-width: 0; }
        .d-qa-name { font-size: 0.9rem; font-weight: 600; color: #e2e8f0; margin-bottom: 0.1rem; }
        .d-qa-user { font-size: 0.775rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .d-qa-actions { display: flex; gap: 0.375rem; opacity: 0; transition: opacity 0.2s; }
        .d-qa-item:hover .d-qa-actions { opacity: 1; }

        .d-qa-btn {
            padding: 0.375rem 0.75rem;
            border-radius: 7px;
            border: 1px solid rgba(59,130,246,0.3);
            background: rgba(59,130,246,0.1);
            color: #60a5fa;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s;
            white-space: nowrap;
        }

        .d-qa-btn:hover {
            background: rgba(59,130,246,0.2);
            box-shadow: 0 0 12px rgba(59,130,246,0.2);
        }

        /* ── Activity timeline ── */
        .d-timeline {
            display: flex;
            flex-direction: column;
            gap: 0;
            position: relative;
            padding-left: 1.5rem;
        }

        .d-timeline::before {
            content: '';
            position: absolute;
            left: 5px;
            top: 8px;
            bottom: 8px;
            width: 2px;
            background: linear-gradient(180deg, rgba(59,130,246,0.5), rgba(139,92,246,0.2), transparent);
            border-radius: 2px;
        }

        .d-act-item {
            position: relative;
            padding: 0.75rem 0 0.75rem 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.04);
        }

        .d-act-item:last-child { border-bottom: none; }

        .d-act-dot {
            position: absolute;
            left: -1.5rem;
            top: 1.05rem;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            border: 2px solid #0b0f1a;
            flex-shrink: 0;
        }

        .d-act-type { font-size: 0.85rem; font-weight: 600; color: #e2e8f0; margin-bottom: 0.15rem; }
        .d-act-meta { font-size: 0.775rem; color: #475569; }
        .d-act-time { font-size: 0.7rem; color: #334155; margin-top: 0.15rem; }

        /* ── Security score ring ── */
        .d-score-card {
            background: linear-gradient(135deg, rgba(34,197,94,0.08), rgba(59,130,246,0.08));
            border: 1px solid rgba(34,197,94,0.2);
            border-radius: 16px;
            padding: 1.75rem 1.5rem;
            text-align: center;
        }

        .d-score-ring-wrap {
            position: relative;
            width: 130px;
            height: 130px;
            margin: 0 auto 1rem;
        }

        .d-score-ring-wrap svg {
            transform: rotate(-90deg);
        }

        .d-score-ring-text {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .d-score-num {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
        }

        .d-score-denom { font-size: 0.75rem; color: #64748b; }

        .d-score-label {
            font-size: 0.875rem;
            color: #94a3b8;
            margin-bottom: 1rem;
        }

        .d-btn-improve {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            border: none;
            color: #fff;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.875rem;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .d-btn-improve:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(34,197,94,0.35);
        }

        /* ── Security features checklist ── */
        .d-features { display: flex; flex-direction: column; gap: 0.625rem; }

        .d-feature {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.875rem;
            color: #94a3b8;
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(255,255,255,0.04);
        }

        .d-feature:last-child { border-bottom: none; }

        .d-feature-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .d-feature-status {
            margin-left: auto;
            font-size: 0.775rem;
            font-weight: 700;
        }

        /* ── Vaults list ── */
        .d-vaults-list { display: flex; flex-direction: column; gap: 0.5rem; }

        .d-vault-item {
            display: flex;
            align-items: center;
            gap: 0.875rem;
            padding: 0.75rem 1rem;
            background: rgba(255,255,255,0.03);
            border-radius: 10px;
            text-decoration: none;
            color: inherit;
            transition: background 0.2s, transform 0.2s;
            border: 1px solid rgba(255,255,255,0.04);
        }

        .d-vault-item:hover {
            background: rgba(255,255,255,0.07);
            transform: translateX(3px);
        }

        .d-vault-icon {
            width: 36px;
            height: 36px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .d-vault-name { font-size: 0.875rem; font-weight: 600; color: #e2e8f0; }
        .d-vault-count { font-size: 0.75rem; color: #475569; }

        .d-vault-arrow { margin-left: auto; color: #334155; font-size: 0.75rem; }

        /* ── CTA Banner ── */
        .d-cta {
            background: linear-gradient(135deg, rgba(59,130,246,0.08), rgba(139,92,246,0.08));
            border: 1px solid rgba(59,130,246,0.18);
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            position: relative;
        }

        .d-cta-close {
            position: absolute;
            top: 0.75rem;
            right: 0.875rem;
            background: none;
            border: none;
            color: #475569;
            font-size: 1.1rem;
            cursor: pointer;
            line-height: 1;
        }

        .d-cta-close:hover { color: #94a3b8; }

        .d-cta-title { font-size: 0.925rem; font-weight: 700; color: #e2e8f0; margin-bottom: 0.35rem; }

        .d-cta-body { font-size: 0.8rem; color: #64748b; line-height: 1.5; margin-bottom: 1rem; }

        .d-cta-btns { display: flex; gap: 0.625rem; }

        .d-cta-btn-primary {
            flex: 1;
            padding: 0.625rem;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border: none;
            color: #fff;
            border-radius: 8px;
            font-size: 0.825rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .d-cta-btn-primary:hover { transform: translateY(-1px); }

        .d-cta-btn-sec {
            flex: 1;
            padding: 0.625rem;
            background: none;
            border: 1px solid rgba(59,130,246,0.3);
            color: #60a5fa;
            border-radius: 8px;
            font-size: 0.825rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .d-cta-btn-sec:hover { background: rgba(59,130,246,0.08); }

        /* ── Modal ── */
        .d-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.65);
            backdrop-filter: blur(6px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .d-modal.open { display: flex; }

        .d-modal-box {
            background: #111827;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 18px;
            width: 90%;
            max-width: 480px;
            padding: 2rem;
            position: relative;
            animation: slideUp 0.25s ease;
        }

        @keyframes slideUp {
            from { transform: translateY(16px); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }

        .d-modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 30px;
            height: 30px;
            border-radius: 7px;
            background: rgba(255,255,255,0.07);
            border: none;
            color: #94a3b8;
            font-size: 1.2rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }

        .d-modal-close:hover { background: rgba(255,255,255,0.12); }

        .d-modal-title { font-size: 1.25rem; font-weight: 700; color: #f1f5f9; margin-bottom: 1.5rem; }

        .d-form-group { margin-bottom: 1rem; }

        .d-form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 0.5rem;
        }

        .d-form-group input,
        .d-form-group select,
        .d-form-group textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            color: #e2e8f0;
            border-radius: 9px;
            font-size: 0.875rem;
            outline: none;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }

        .d-form-group input:focus,
        .d-form-group select:focus,
        .d-form-group textarea:focus {
            border-color: rgba(59,130,246,0.5);
        }

        .d-form-group textarea { resize: vertical; min-height: 80px; }

        .d-modal-actions { display: flex; gap: 0.75rem; margin-top: 1.5rem; }

        .d-modal-save {
            flex: 1;
            padding: 0.75rem;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border: none;
            color: #fff;
            border-radius: 9px;
            font-weight: 700;
            font-size: 0.875rem;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .d-modal-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(59,130,246,0.3);
        }

        .d-modal-cancel {
            flex: 1;
            padding: 0.75rem;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            color: #94a3b8;
            border-radius: 9px;
            font-size: 0.875rem;
            cursor: pointer;
            transition: background 0.2s;
        }

        .d-modal-cancel:hover { background: rgba(255,255,255,0.09); }

        /* ── Toast ── */
        .d-toast {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            padding: 0.875rem 1.25rem;
            border-radius: 12px;
            font-size: 0.875rem;
            font-weight: 600;
            z-index: 2000;
            opacity: 0;
            transform: translateY(12px);
            transition: opacity 0.25s, transform 0.25s;
            pointer-events: none;
        }

        .d-toast.show { opacity: 1; transform: translateY(0); }
        .d-toast.success { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.3); color: #4ade80; }
        .d-toast.error   { background: rgba(239,68,68,0.15);  border: 1px solid rgba(239,68,68,0.3);  color: #f87171; }

        /* ── Empty states ── */
        .d-empty {
            text-align: center;
            padding: 2rem 1rem;
            color: #475569;
            font-size: 0.875rem;
        }

        .d-empty a { color: #60a5fa; text-decoration: none; }
        .d-empty a:hover { text-decoration: underline; }

        /* ── Responsive ── */
        @media (max-width: 1200px) {
            .d-grid { grid-template-columns: 1fr; }
            .d-right { flex-direction: row; flex-wrap: wrap; }
            .d-right > * { flex: 1 1 280px; }
        }

        @media (max-width: 900px) {
            .d-stats { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 700px) {
            .d-sidebar {
                width: 64px;
                min-width: 64px;
                padding: 1rem 0.5rem;
                align-items: center;
            }
            .d-brand span,
            .d-nav-link span:not(.badge-new),
            .d-nav-label,
            .d-vault-status span,
            .d-logout span { display: none; }
            .d-nav-link { justify-content: center; padding: 0.625rem; }
            .d-logout { justify-content: center; padding: 0.625rem; }
            .d-brand { padding: 0.5rem 0; justify-content: center; }
            .d-content { padding: 1rem; }
            .d-topbar { padding: 0.75rem 1rem; }
            .d-stats { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="d-shell">

    <!-- ═══════════════════ SIDEBAR ═══════════════════ -->
    <nav class="d-sidebar" aria-label="Main navigation">
        <a href="dashboard.php" class="d-brand">
            <div class="d-brand-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <span>Vaultly</span>
        </a>

        <nav class="d-nav" aria-label="Sidebar links">
            <div class="d-nav-label">Main</div>

            <a href="dashboard.php" class="d-nav-link active">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                <span>Dashboard</span>
            </a>
            <a href="actions/view_all_items.php" class="d-nav-link">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2"/></svg>
                <span>All Items</span>
            </a>
            <a href="actions/view_favorites.php" class="d-nav-link">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                <span>Favorites</span>
            </a>
            <a href="actions/password_manager.php" class="d-nav-link">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                <span>Password Manager</span>
            </a>

            <div class="d-nav-label">Vault</div>

            <a href="actions/manage_vaults.php" class="d-nav-link">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span>Vaults</span>
            </a>
            <a href="actions/view_shared.php" class="d-nav-link">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span>Shared</span>
            </a>
            <a href="actions/view_passkeys.php" class="d-nav-link">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21 2-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                <span>Passkeys</span>
                <span class="badge-new">New</span>
            </a>

            <div class="d-nav-label">Items</div>

            <a href="actions/view_secure_notes.php" class="d-nav-link">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <span>Secure Notes</span>
            </a>
            <a href="actions/view_cards.php" class="d-nav-link">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                <span>Cards</span>
            </a>
            <a href="actions/view_identities.php" class="d-nav-link">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <span>Identities</span>
            </a>

            <div class="d-nav-label">Tools</div>

            <a href="actions/security_audit.php" class="d-nav-link">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <span>Security Audit</span>
            </a>
            <a href="actions/password_generator.php" class="d-nav-link">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93 17.66 6.34M19.07 19.07l-1.41-1.41M4.93 19.07l1.41-1.41M4.93 4.93l1.41 1.41M21 12h-2M5 12H3M12 19v2M12 3V5"/></svg>
                <span>Generator</span>
            </a>
            <a href="actions/view_activity.php" class="d-nav-link">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <span>Activity</span>
            </a>
            <a href="actions/settings.php" class="d-nav-link">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93 17.66 6.34M19.07 19.07l-1.41-1.41M4.93 19.07l1.41-1.41M4.93 4.93l1.41 1.41M21 12h-2M5 12H3M12 19v2M12 3V5"/></svg>
                <span>Settings</span>
            </a>
        </nav>

        <div class="d-sidebar-footer">
            <div class="d-vault-status">Vault secure</div>
            <a href="dashboard.php?logout=true" class="d-logout">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                <span>Logout</span>
            </a>
        </div>
    </nav>

    <!-- ═══════════════════ MAIN ═══════════════════ -->
    <div class="d-main">

        <!-- Top bar -->
        <header class="d-topbar">
            <div class="d-search-wrap">
                <div class="d-search-box">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="searchInput" placeholder="Search vault…">
                    <span class="d-kbd">⌘ K</span>
                </div>
                <div id="searchDropdown" class="d-search-dropdown"></div>
            </div>

            <div class="d-topbar-right">
                <a href="actions/password_generator.php" class="d-topbar-btn" title="Generator">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93 17.66 6.34M19.07 19.07l-1.41-1.41M4.93 19.07l1.41-1.41M4.93 4.93l1.41 1.41M21 12h-2M5 12H3M12 19v2M12 3V5"/></svg>
                </a>
                <a href="actions/view_activity.php" class="d-topbar-btn" title="Activity">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                </a>
                <button class="d-btn-add" onclick="openModal()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    Add item
                </button>
                <a href="actions/settings.php" class="d-avatar"><?php echo $user_avatar; ?></a>
            </div>
        </header>

        <!-- Content -->
        <main class="d-content">

            <!-- Welcome -->
            <div class="d-welcome">
                <h1>Welcome back, <span class="grad"><?php echo $user_name; ?></span></h1>
                <p>Here's your vault overview for today.</p>
            </div>

            <!-- Stats -->
            <div class="d-stats">
                <div class="d-stat">
                    <div class="d-stat-icon" style="background:rgba(34,197,94,0.12);color:#22c55e;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </div>
                    <div class="d-stat-body">
                        <div class="d-stat-value" style="color:#22c55e;"><?php echo $total_entries; ?></div>
                        <div class="d-stat-label">Total items</div>
                        <div class="d-stat-sub">Vault <?php echo $total_entries > 0 ? 'active' : 'empty'; ?></div>
                    </div>
                </div>

                <div class="d-stat">
                    <div class="d-stat-icon" style="background:rgba(59,130,246,0.12);color:#60a5fa;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </div>
                    <div class="d-stat-body">
                        <div class="d-stat-value" style="color:#60a5fa;"><?php echo $type_counts['login'] ?? 0; ?></div>
                        <div class="d-stat-label">Passwords</div>
                        <div class="d-stat-sub">Login entries</div>
                    </div>
                </div>

                <div class="d-stat">
                    <div class="d-stat-icon" style="background:rgba(139,92,246,0.12);color:#a78bfa;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div class="d-stat-body">
                        <div class="d-stat-value" style="color:#a78bfa;"><?php echo $type_counts['note'] ?? 0; ?></div>
                        <div class="d-stat-label">Secure Notes</div>
                        <div class="d-stat-sub">Encrypted notes</div>
                    </div>
                </div>

                <div class="d-stat">
                    <div class="d-stat-icon" style="background:rgba(249,115,22,0.12);color:#fb923c;">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    </div>
                    <div class="d-stat-body">
                        <div class="d-stat-value" style="color:#fb923c;"><?php echo $type_counts['card'] ?? 0; ?></div>
                        <div class="d-stat-label">Cards</div>
                        <div class="d-stat-sub">Payment methods</div>
                    </div>
                </div>
            </div>

            <!-- Main grid -->
            <div class="d-grid">
                <!-- LEFT column -->
                <div class="d-left">

                    <!-- Quick access (rendered by JS after vault unlock) -->
                    <div class="d-card">
                        <div class="d-card-header">
                            <div class="d-card-title">Quick access</div>
                            <a href="actions/view_favorites.php" class="d-link">View favorites →</a>
                        </div>
                        <div class="d-qa-list" id="qaList">
                            <?php if ($favorites_count === 0): ?>
                                <div class="d-empty" id="qaEmpty">
                                    No favorites yet.<br>
                                    <button onclick="openVaultModal()" style="background:none;border:none;color:#60a5fa;cursor:pointer;padding:0;font-size:inherit;">Open vault</button> and star items to see them here.
                                </div>
                            <?php else: ?>
                                <div class="d-empty" id="qaLocked">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-bottom:6px;opacity:.4"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg><br>
                                    <?php echo $favorites_count; ?> favorite<?php echo $favorites_count !== 1 ? 's' : ''; ?> — <button onclick="openVaultModal()" style="background:none;border:none;color:#60a5fa;cursor:pointer;padding:0;font-size:inherit;text-decoration:underline;">unlock vault</button> to view.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent activity (type + timestamp shown; titles decrypted client-side in vault.php) -->
                    <div class="d-card">
                        <div class="d-card-header">
                            <div class="d-card-title">Recent activity</div>
                            <a href="actions/view_activity.php" class="d-link">View all →</a>
                        </div>
                        <?php if (count($recent_activity) > 0): ?>
                        <div class="d-timeline">
                            <?php foreach ($recent_activity as $act):
                                $atype = $act['type'];
                                $adot  = $type_color[$atype] ?? '#475569';
                                $aicon = $type_icon[$atype]  ?? '•';
                            ?>
                            <div class="d-act-item">
                                <div class="d-act-dot" style="background:<?php echo $adot; ?>;box-shadow:0 0 6px <?php echo $adot; ?>40;"></div>
                                <div class="d-act-type"><?php echo $aicon; ?> <span style="opacity:.5;font-size:.8em;">(encrypted)</span></div>
                                <div class="d-act-meta"><?php echo ucfirst(htmlspecialchars($atype)); ?> · <?php echo htmlspecialchars(substr($act['uuid'], 0, 8)); ?></div>
                                <div class="d-act-time"><?php echo date('M j, H:i', strtotime($act['updated_at'])); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="text-align:center;margin-top:.75rem;font-size:.8rem;color:#475569;">
                            <button onclick="openVaultModal()" style="background:none;border:none;color:#60a5fa;cursor:pointer;padding:0;font-size:inherit;text-decoration:none;">Unlock vault</button> to see entry names.
                        </div>
                        <?php else: ?>
                            <div class="d-empty">
                                No activity yet. <button onclick="openVaultModal()" style="background:none;border:none;color:#60a5fa;cursor:pointer;padding:0;font-size:inherit;">Open vault</button> to add your first item.
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- RIGHT column -->
                <div class="d-right">

                    <!-- Security score -->
                    <div class="d-score-card">
                        <div style="font-size:0.775rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;margin-bottom:1rem;">Security score</div>
                        <div class="d-score-ring-wrap">
                            <svg width="130" height="130" viewBox="0 0 130 130">
                                <circle cx="65" cy="65" r="54" fill="none" stroke="rgba(255,255,255,0.06)" stroke-width="10"/>
                                <circle cx="65" cy="65" r="54" fill="none"
                                    stroke="<?php echo $score_color; ?>"
                                    stroke-width="10"
                                    stroke-linecap="round"
                                    stroke-dasharray="<?php echo $circumference; ?>"
                                    stroke-dashoffset="<?php echo $dash_offset; ?>"
                                    style="filter:drop-shadow(0 0 8px <?php echo $score_color; ?>60);"/>
                            </svg>
                            <div class="d-score-ring-text">
                                <div class="d-score-num" style="color:<?php echo $score_color; ?>;"><?php echo $security_score; ?></div>
                                <div class="d-score-denom">/100</div>
                            </div>
                        </div>
                        <div class="d-score-label"><?php echo $security_score > 70 ? 'Great job! Keep it up.' : 'Room for improvement.'; ?></div>
                        <button class="d-btn-improve" onclick="window.location='actions/security_audit.php'">Improve score</button>
                    </div>

                    <!-- Security features -->
                    <div class="d-card">
                        <div class="d-card-title" style="margin-bottom:1rem;">Security checklist</div>
                        <div class="d-features">
                            <div class="d-feature">
                                <div class="d-feature-dot" style="background:#22c55e;box-shadow:0 0 6px #22c55e80;"></div>
                                Vault active
                                <div class="d-feature-status" style="color:#22c55e;">Enabled</div>
                            </div>
                            <div class="d-feature">
                                <div class="d-feature-dot" style="background:#22c55e;box-shadow:0 0 6px #22c55e80;"></div>
                                AES-256 encryption
                                <div class="d-feature-status" style="color:#22c55e;">On</div>
                            </div>
                            <div class="d-feature">
                                <div class="d-feature-dot" style="background:#22c55e;box-shadow:0 0 6px #22c55e80;"></div>
                                Master password
                                <div class="d-feature-status" style="color:#22c55e;">Set</div>
                            </div>
                            <div class="d-feature">
                                <?php if ($totp_enabled): ?>
                                <div class="d-feature-dot" style="background:#22c55e;box-shadow:0 0 6px #22c55e80;"></div>
                                2FA authentication
                                <div class="d-feature-status" style="color:#22c55e;">Enabled</div>
                                <?php else: ?>
                                <div class="d-feature-dot" style="background:#f59e0b;box-shadow:0 0 6px #f59e0b80;"></div>
                                2FA authentication
                                <a href="actions/settings.php" class="d-feature-status" style="color:#f59e0b;text-decoration:none;">Enable →</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Your vaults -->
                    <div class="d-card">
                        <div class="d-card-header">
                            <div class="d-card-title">Your vaults</div>
                            <a href="actions/manage_vaults.php" class="d-link">Manage →</a>
                        </div>
                        <div class="d-vaults-list">
                            <?php if (count($vaults) > 0):
                                foreach ($vaults as $vi => $v): ?>
                            <a href="actions/manage_vaults.php" class="d-vault-item">
                                <div class="d-vault-icon" style="background:<?php echo htmlspecialchars($v['color']); ?>20;color:<?php echo htmlspecialchars($v['color']); ?>;">
                                    <?php echo $vault_icons[$vi % count($vault_icons)]; ?>
                                </div>
                                <div>
                                    <div class="d-vault-name"><?php echo htmlspecialchars($v['name']); ?></div>
                                    <div class="d-vault-count"><?php echo (int)$v['item_count']; ?> item<?php echo (int)$v['item_count'] !== 1 ? 's' : ''; ?></div>
                                </div>
                                <div class="d-vault-arrow">→</div>
                            </a>
                            <?php endforeach; else: ?>
                            <div class="d-empty" style="padding:1rem 0;">
                                No vaults yet. <a href="actions/manage_vaults.php">Create one</a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- CTA -->
                    <div class="d-cta" id="ctaBanner">
                        <button class="d-cta-close" onclick="dismissBanner()" aria-label="Close">✕</button>
                        <div class="d-cta-title">Get more from Vaultly</div>
                        <div class="d-cta-body">Enable two-factor authentication and use unique passwords for every site to maximize your security score.</div>
                        <div class="d-cta-btns">
                            <button class="d-cta-btn-primary" onclick="window.location='actions/security_audit.php'">Learn more</button>
                            <button class="d-cta-btn-sec" onclick="dismissBanner()">Dismiss</button>
                        </div>
                    </div>

                </div>
            </div>

        </main>
    </div>
</div>

<!-- Add item modal -->
<div id="addModal" class="d-modal">
    <div class="d-modal-box">
        <button class="d-modal-close" onclick="closeModal()">×</button>
        <div class="d-modal-title">Add new item</div>
        <form id="addForm">
            <div class="d-form-group">
                <label for="itemType">Type</label>
                <select id="itemType" name="type">
                    <option value="login">Login</option>
                    <option value="note">Secure note</option>
                    <option value="card">Payment card</option>
                    <option value="identity">Identity</option>
                </select>
            </div>
            <div class="d-form-group">
                <label for="itemTitle">Title</label>
                <input type="text" id="itemTitle" name="title" placeholder="e.g. Gmail, Bank Account" required>
            </div>
            <div class="d-form-group">
                <label for="itemUsername">Username / Email</label>
                <input type="text" id="itemUsername" name="username" placeholder="your@email.com">
            </div>
            <div class="d-form-group">
                <label for="itemPassword">Password</label>
                <input type="password" id="itemPassword" name="password" placeholder="••••••••">
            </div>
            <div class="d-form-group">
                <label for="itemURL">URL</label>
                <input type="url" id="itemURL" name="url" placeholder="https://example.com">
            </div>
            <div class="d-form-group">
                <label for="itemNotes">Notes</label>
                <textarea id="itemNotes" name="notes" placeholder="Additional notes…"></textarea>
            </div>
            <div class="d-modal-actions">
                <button type="button" class="d-modal-save" onclick="saveItem()">Save item</button>
                <button type="button" class="d-modal-cancel" onclick="closeModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Vault Unlock Modal ───────────────────────────────────────────────────── -->
<div id="vaultModal" class="d-modal" style="z-index:1100;">
    <div class="d-modal-box" style="max-width:400px;">
        <button class="d-modal-close" onclick="closeVaultModal()">×</button>
        <div id="vaultModalBody">
            <!-- filled by JS based on vault state -->
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toast" class="d-toast"></div>

<script src="js/crypto-engine.js"></script>
<script src="js/password-generator.js"></script>
<script src="js/security-analyzer.js"></script>
<script src="js/validation.js"></script>
<script src="js/vault-key.js"></script>
<script src="js/vault-manager.js"></script>
<script src="js/vault-ui.js"></script>

<script>
const USER_ID = <?php echo $user_id; ?>;
const VAULT_INITIALIZED = <?php echo $vault_initialized ? 'true' : 'false'; ?>;

// ── In-memory vault key (never stored, cleared on tab close) ─────────────────
let _vaultKey  = null;
let _saltData  = null;

const QA_COLORS = [
    'linear-gradient(135deg,#6366f1,#8b5cf6)',
    'linear-gradient(135deg,#f59e0b,#ec4899)',
    'linear-gradient(135deg,#22c55e,#16a34a)',
    'linear-gradient(135deg,#3b82f6,#1e40af)',
    'linear-gradient(135deg,#e50914,#b20710)',
    'linear-gradient(135deg,#06b6d4,#0284c7)',
];

// ── Modal ──
function openModal() {
    document.getElementById('addModal').classList.add('open');
}

function closeModal() {
    document.getElementById('addModal').classList.remove('open');
    document.getElementById('addForm').reset();
}

window.addEventListener('click', e => {
    if (e.target === document.getElementById('addModal'))   closeModal();
    if (e.target === document.getElementById('vaultModal')) closeVaultModal();
});

// ── Vault Modal ──────────────────────────────────────────────────────────────
function openVaultModal() {
    const body = document.getElementById('vaultModalBody');
    if (VAULT_INITIALIZED) {
        body.innerHTML = `
            <div class="d-modal-title" style="display:flex;align-items:center;gap:.5rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Unlock Vault
            </div>
            <p style="font-size:.85rem;color:#64748b;margin-bottom:1.25rem;">Enter your vault master password to decrypt your items.</p>
            <div class="d-form-group">
                <label>Master password</label>
                <input type="password" id="vaultPwd" class="d-form-group input" placeholder="••••••••"
                    style="width:100%;padding:.75rem 1rem;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);color:#e2e8f0;border-radius:9px;font-size:.875rem;outline:none;box-sizing:border-box;"
                    onkeydown="if(event.key==='Enter')doVaultUnlock()">
            </div>
            <div id="vaultModalErr" style="color:#f87171;font-size:.8rem;margin-top:-.5rem;margin-bottom:.75rem;display:none;"></div>
            <div class="d-modal-actions">
                <button class="d-modal-save" id="vaultUnlockBtn" onclick="doVaultUnlock()">Unlock</button>
                <button class="d-modal-cancel" onclick="closeVaultModal()">Cancel</button>
            </div>`;
        document.getElementById('vaultModal').classList.add('open');
        setTimeout(() => document.getElementById('vaultPwd')?.focus(), 50);
    } else {
        body.innerHTML = `
            <div class="d-modal-title" style="display:flex;align-items:center;gap:.5rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L4 6v6c0 5.25 3.6 10.2 8 11.8 4.4-1.6 8-6.55 8-11.8V6L12 2z"/><path d="m9 12 2 2 4-4"/></svg>
                Set Up Vault
            </div>
            <p style="font-size:.85rem;color:#64748b;margin-bottom:1.25rem;">Create a master password. It encrypts all your items — we never see it.</p>
            <div class="d-form-group">
                <label>Master password</label>
                <input type="password" id="setupPwd" placeholder="Choose a strong password"
                    style="width:100%;padding:.75rem 1rem;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);color:#e2e8f0;border-radius:9px;font-size:.875rem;outline:none;box-sizing:border-box;">
            </div>
            <div class="d-form-group" style="margin-top:.75rem;">
                <label>Confirm password</label>
                <input type="password" id="setupPwd2" placeholder="Repeat password"
                    style="width:100%;padding:.75rem 1rem;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);color:#e2e8f0;border-radius:9px;font-size:.875rem;outline:none;box-sizing:border-box;"
                    onkeydown="if(event.key==='Enter')doVaultSetup()">
            </div>
            <div class="d-form-group" style="margin-top:.75rem;">
                <label>Hint <span style="color:#475569;font-weight:400;">(optional — visible to you)</span></label>
                <input type="text" id="setupHint" placeholder="Something to help you remember"
                    style="width:100%;padding:.75rem 1rem;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);color:#e2e8f0;border-radius:9px;font-size:.875rem;outline:none;box-sizing:border-box;">
            </div>
            <div id="vaultModalErr" style="color:#f87171;font-size:.8rem;margin-top:-.5rem;margin-bottom:.75rem;display:none;"></div>
            <div class="d-modal-actions">
                <button class="d-modal-save" id="vaultUnlockBtn" onclick="doVaultSetup()">Create Vault</button>
                <button class="d-modal-cancel" onclick="closeVaultModal()">Cancel</button>
            </div>`;
        document.getElementById('vaultModal').classList.add('open');
        setTimeout(() => document.getElementById('setupPwd')?.focus(), 50);
    }
}

function closeVaultModal() {
    document.getElementById('vaultModal').classList.remove('open');
}

function _vaultModalErr(msg) {
    const el = document.getElementById('vaultModalErr');
    if (el) { el.textContent = msg; el.style.display = msg ? 'block' : 'none'; }
}

function _vaultModalLoading(loading) {
    const btn = document.getElementById('vaultUnlockBtn');
    if (!btn) return;
    btn.disabled = loading;
    btn.textContent = loading ? 'Please wait…' : (VAULT_INITIALIZED ? 'Unlock' : 'Create Vault');
}

async function doVaultUnlock() {
    const pwd = document.getElementById('vaultPwd')?.value;
    if (!pwd) { _vaultModalErr('Enter your master password.'); return; }
    _vaultModalLoading(true);
    _vaultModalErr('');
    try {
        const salt = _saltData || await fetch('api/salt.php').then(r => r.json());
        _saltData = salt;
        if (!salt?.salt) { _vaultModalErr('Vault not set up.'); _vaultModalLoading(false); return; }
        const key = await CryptoEngine.deriveKey(pwd, salt.salt);
        const test = await CryptoEngine.decrypt(key, salt.verification_iv, salt.verification_blob).catch(() => null);
        if (test !== 'vault:ok') { _vaultModalErr('Wrong master password.'); _vaultModalLoading(false); return; }
        _vaultKey = key;
        closeVaultModal();
        toast('Vault unlocked!', 'success');
        await loadAndRenderFavorites();
    } catch (e) {
        _vaultModalErr('Error: ' + e.message);
        _vaultModalLoading(false);
    }
}

async function doVaultSetup() {
    const pwd  = document.getElementById('setupPwd')?.value;
    const pwd2 = document.getElementById('setupPwd2')?.value;
    const hint = document.getElementById('setupHint')?.value.trim() || '';
    if (!pwd)            { _vaultModalErr('Enter a master password.'); return; }
    if (pwd.length < 8)  { _vaultModalErr('Password must be at least 8 characters.'); return; }
    if (pwd !== pwd2)    { _vaultModalErr('Passwords do not match.'); return; }
    _vaultModalLoading(true);
    _vaultModalErr('');
    try {
        const salt = CryptoEngine.generateSalt();
        const key  = await CryptoEngine.deriveKey(pwd, salt);
        const ver  = await CryptoEngine.encrypt(key, 'vault:ok');
        const resp = await fetch('api/salt.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ salt, hint, verification_blob: ver.data, verification_iv: ver.iv }),
        });
        const result = await resp.json();
        if (result.error) { _vaultModalErr(result.error); _vaultModalLoading(false); return; }
        _vaultKey = key;
        _saltData = { salt, hint, verification_blob: ver.data, verification_iv: ver.iv };
        closeVaultModal();
        toast('Vault created!', 'success');
        // Reload so PHP picks up the new vault_initialized state
        setTimeout(() => location.reload(), 800);
    } catch (e) {
        _vaultModalErr('Error: ' + e.message);
        _vaultModalLoading(false);
    }
}

async function loadAndRenderFavorites() {
    if (!_vaultKey) return;
    const qaList = document.getElementById('qaList');
    if (!qaList) return;
    qaList.innerHTML = '<div class="d-empty" style="opacity:.5;">Decrypting…</div>';
    try {
        const data = await fetch('api/vault.php').then(r => r.json());
        const favorites = [];
        for (const row of (data.entries || [])) {
            if (!row.favorite) continue;
            try {
                const plain = await CryptoEngine.decrypt(_vaultKey, row.iv, row.encrypted_data);
                favorites.push({ ...plain, uuid: row.uuid, type: row.type });
            } catch { /* skip corrupted */ }
        }
        if (favorites.length === 0) {
            qaList.innerHTML = '<div class="d-empty">No favorites yet. Star items in the vault to see them here.</div>';
            return;
        }
        qaList.innerHTML = favorites.slice(0, 6).map((item, i) => {
            const icon  = (item.title || '?').charAt(0).toUpperCase();
            const color = QA_COLORS[i % QA_COLORS.length];
            const name  = esc(item.title || 'Untitled');
            const user  = esc(item.username || item.email || item.type || '');
            const userAttr = item.username || item.email || '';
            return `<div class="d-qa-item">
                <div class="d-qa-icon" style="background:${color};">${icon}</div>
                <div class="d-qa-info">
                    <div class="d-qa-name">${name}</div>
                    <div class="d-qa-user">${user}</div>
                </div>
                <div class="d-qa-actions">
                    ${userAttr ? `<button class="d-qa-btn" onclick="copyText('${userAttr.replace(/'/g,"\\'")}',this)">Copy user</button>` : ''}
                    ${item.password ? `<button class="d-qa-btn" onclick="copyText('${(item.password||'').replace(/'/g,"\\'")}',this)">Copy pwd</button>` : ''}
                </div>
            </div>`;
        }).join('');
    } catch (e) {
        qaList.innerHTML = '<div class="d-empty" style="color:#f87171;">Failed to load: ' + esc(e.message) + '</div>';
    }
}

// ── Save item (E2E encrypted via api/vault.php) ──────────────────────────────
async function saveItem() {
    const type     = document.getElementById('itemType').value;
    const title    = document.getElementById('itemTitle').value.trim();
    const username = document.getElementById('itemUsername').value.trim();
    const password = document.getElementById('itemPassword').value;
    const url      = document.getElementById('itemURL').value.trim();
    const notes    = document.getElementById('itemNotes').value.trim();

    if (!title) { toast('Title is required.', 'error'); return; }

    // If vault not unlocked yet — unlock first, then save
    if (!_vaultKey) {
        closeModal();
        toast('Unlock your vault first.', 'error');
        openVaultModal();
        return;
    }

    try {
        const plaintext = { title, username, password, url, notes };
        const { iv, data: encrypted_data } = await CryptoEngine.encrypt(_vaultKey, plaintext);
        const resp = await fetch('api/vault.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: 'create', type, encrypted_data, iv }),
        });
        const result = await resp.json();
        if (result.ok) {
            closeModal();
            toast('Item saved!', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            toast(result.error || 'Error saving item.', 'error');
        }
    } catch (e) {
        toast('Encryption error: ' + e.message, 'error');
    }
}

// ── Copy ──
function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✓ Copied';
        btn.disabled = true;
        setTimeout(() => { btn.textContent = orig; btn.disabled = false; }, 1500);
    }).catch(() => toast('Clipboard unavailable', 'error'));
}

// ── Toast ──
function toast(msg, type = 'success') {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className = 'd-toast ' + type;
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 3000);
}

// ── CTA Banner ──
(function() {
    if (localStorage.getItem('vaultly_cta_v2') === '1') {
        const b = document.getElementById('ctaBanner');
        if (b) b.style.display = 'none';
    }
})();

function dismissBanner() {
    localStorage.setItem('vaultly_cta_v2', '1');
    const b = document.getElementById('ctaBanner');
    if (b) {
        b.style.transition = 'opacity .25s, transform .25s';
        b.style.opacity = '0';
        b.style.transform = 'scale(0.97)';
        setTimeout(() => b.style.display = 'none', 250);
    }
}

// ── Search ──
const searchDrop  = document.getElementById('searchDropdown');
const typeIcons   = { login: '🔐', note: '📝', card: '💳', identity: '👤' };

function esc(s) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
}

document.getElementById('searchInput').addEventListener('input', function() {
    doSearch(this.value.trim());
});

async function doSearch(q) {
    if (!q || q.length < 2) { searchDrop.classList.remove('open'); searchDrop.innerHTML = ''; return; }
    try {
        const res  = await fetch('api/search.php?q=' + encodeURIComponent(q));
        const data = await res.json();
        if (data.success && data.results.length > 0) {
            searchDrop.innerHTML = data.results.map(r =>
                `<div class="d-search-result" onclick="window.location='actions/view_all_items.php'">
                    <div class="d-search-ricon">${typeIcons[r.type] || '?'}</div>
                    <div><div class="d-search-rtitle">${esc(r.title)}</div><div class="d-search-rtype">${r.type.toUpperCase()}</div></div>
                </div>`
            ).join('');
        } else {
            searchDrop.innerHTML = '<div class="d-no-results">No results found</div>';
        }
        searchDrop.classList.add('open');
    } catch {}
}

document.addEventListener('click', e => {
    if (!e.target.closest('.d-search-wrap')) searchDrop.classList.remove('open');
});

// ── Keyboard shortcuts ──
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); document.getElementById('searchInput').focus(); }
    if ((e.ctrlKey || e.metaKey) && e.key === 'n') { e.preventDefault(); openModal(); }
    if (e.key === 'Escape') { closeModal(); closeVaultModal(); }
});

// ── Auto-open from URL param ──
document.addEventListener('DOMContentLoaded', () => {
    const p = new URLSearchParams(location.search).get('add');
    if (p && ['login','note','card','identity'].includes(p)) {
        openModal();
        document.getElementById('itemType').value = p;
        history.replaceState(null, '', 'dashboard.php');
    }
});
</script>
</body>
</html>

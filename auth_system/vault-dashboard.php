<?php
session_start();
include 'includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$userId   = (int)$_SESSION['user_id'];
$username = htmlspecialchars($_SESSION['username']);
$email    = htmlspecialchars($_SESSION['email']);
$initials = strtoupper(mb_substr($_SESSION['username'], 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vault Dashboard – VaultAuth</title>
    <link rel="stylesheet" href="css/vault-dashboard.css">
</head>
<body class="vault-body">

<div class="vault-container">
    
    <!-- LEFT SIDEBAR (NAVIGATION) -->
    <aside class="vault-sidebar-left">
        <div class="sidebar-brand">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="7.5" cy="15.5" r="5.5"/>
                <path d="m21 2-9.6 9.6M15.5 2H21v5.5"/>
            </svg>
            <span>Vaultly</span>
        </div>

        <nav class="sidebar-nav">
            <a href="#" class="nav-item active" data-view="dashboard">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="12 3 20 7.5 20 16.5 12 21 4 16.5 4 7.5 12 3"></polyline>
                    <line x1="12" y1="12" x2="20" y2="7.5"></line>
                    <line x1="12" y1="12" x2="12" y2="21"></line>
                    <line x1="12" y1="12" x2="4" y2="7.5"></line>
                </svg>
                <span>Dashboard</span>
            </a>
            <a href="#" class="nav-item" data-view="all">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
                <span>All Items</span>
                <span class="nav-count">127</span>
            </a>
            <a href="#" class="nav-item" data-view="favorites">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polygon points="12 2 15.09 10.26 24 10.5 17.18 16.34 19.34 24.5 12 19.77 4.66 24.5 6.82 16.34 0 10.5 8.91 10.26 12 2"></polygon>
                </svg>
                <span>Favorites</span>
                <span class="nav-count">23</span>
            </a>
            <a href="#" class="nav-item" data-view="vaults">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                    <circle cx="12" cy="8" r="4"/>
                </svg>
                <span>Vaults</span>
            </a>
            <a href="#" class="nav-item" data-view="shared">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
                <span>Shared</span>
            </a>
            <a href="#" class="nav-item" data-view="passkeys">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 2H3a1 1 0 0 0-1 1v18a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1z"></path>
                    <line x1="17" y1="11" x2="7" y2="11"></line>
                </svg>
                <span>Passkeys</span>
                <span class="nav-badge">NEW</span>
            </a>
            <a href="#" class="nav-item" data-view="notes">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                </svg>
                <span>Secure Notes</span>
            </a>
            <a href="#" class="nav-item" data-view="cards">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="1" y="4" width="22" height="16" rx="2"></rect>
                    <line x1="1" y1="10" x2="23" y2="10"></line>
                </svg>
                <span>Cards</span>
            </a>
            <a href="#" class="nav-item" data-view="identities">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="8" r="4"></circle>
                    <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"></path>
                </svg>
                <span>Identities</span>
            </a>
            <a href="#" class="nav-item" data-view="security">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    <line x1="12" y1="12" x2="12" y2="17"></line>
                </svg>
                <span>Security Audit</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <a href="?logout=true" class="logout-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <polyline points="16 17 21 12 16 7"></polyline>
                    <line x1="21" y1="12" x2="9" y2="12"></line>
                </svg>
                Logout
            </a>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <div class="vault-main">
        
        <!-- TOP HEADER -->
        <header class="vault-topbar">
            <div class="topbar-search-group">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
                <input type="text" placeholder="Search passwords, notes, cards…" class="topbar-search">
                <span class="search-hint">Ctrl + K</span>
            </div>

            <div class="topbar-actions">
                <button class="topbar-btn btn-primary" onclick="openAddModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    Add Item
                </button>
                <button class="topbar-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 2L4 6v6c0 5.25 3.6 10.2 8 11.8 4.4-1.6 8-6.55 8-11.8V6L12 2z"/>
                        <path d="m9 12 2 2 4-4"/>
                    </svg>
                    Generator
                </button>
                <button class="topbar-btn" onclick="openNotifications()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                    </svg>
                    <span class="notification-badge">2</span>
                </button>
                <button class="topbar-btn avatar-btn" onclick="openUserMenu()">
                    <div class="avatar-small"><?php echo $initials; ?></div>
                </button>
            </div>
        </header>

        <!-- DASHBOARD CONTENT -->
        <main class="vault-content">
            
            <!-- WELCOME HEADER -->
            <section class="welcome-section">
                <div class="welcome-header">
                    <h1>Welcome back, <span class="username"><?php echo $username; ?></span> 👋</h1>
                    <p>Here's what's happening with your vault</p>
                </div>
            </section>

            <div class="dashboard-grid">
                
                <!-- LEFT COLUMN -->
                <div class="dashboard-left">
                    
                    <!-- SECURITY SNAPSHOT CARDS -->
                    <section class="security-snapshot">
                        <div class="snapshot-card vault-secure">
                            <div class="snapshot-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                    <path d="m9 12 2 2 4-4"/>
                                </svg>
                            </div>
                            <div class="snapshot-content">
                                <div class="snapshot-value">Secure</div>
                                <div class="snapshot-label">No issues found</div>
                            </div>
                        </div>

                        <div class="snapshot-card weak-passwords">
                            <div class="snapshot-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                    <line x1="12" y1="9" x2="12" y2="13"/>
                                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                                </svg>
                            </div>
                            <div class="snapshot-content">
                                <div class="snapshot-value">3</div>
                                <div class="snapshot-label">Weak passwords</div>
                            </div>
                            <button class="snapshot-action">Fix →</button>
                        </div>

                        <div class="snapshot-card reused-passwords">
                            <div class="snapshot-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                                    <path d="M3 3v5h5"/>
                                </svg>
                            </div>
                            <div class="snapshot-content">
                                <div class="snapshot-value">5</div>
                                <div class="snapshot-label">Reused passwords</div>
                            </div>
                        </div>

                        <div class="snapshot-card breached-alerts">
                            <div class="snapshot-icon">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <line x1="12" y1="8" x2="12" y2="16"/>
                                    <line x1="8" y1="12" x2="16" y2="12"/>
                                </svg>
                            </div>
                            <div class="snapshot-content">
                                <div class="snapshot-value">1</div>
                                <div class="snapshot-label">Breach alert</div>
                            </div>
                            <button class="snapshot-action danger">Act now</button>
                        </div>
                    </section>

                    <!-- QUICK ACCESS PASSWORDS -->
                    <section class="quick-access">
                        <div class="section-header">
                            <h2>Quick Access</h2>
                            <a href="#" class="view-all">View all →</a>
                        </div>
                        <div class="quick-grid">
                            <div class="quick-card" onclick="openPasswordModal()">
                                <div class="quick-icon google">G</div>
                                <div class="quick-info">
                                    <div class="quick-title">Google</div>
                                    <div class="quick-user">john.doe@gmail.com</div>
                                </div>
                                <div class="quick-actions">
                                    <button class="quick-btn" onclick="event.stopPropagation();copyPassword()">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="9" y="9" width="13" height="13" rx="2"/>
                                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="quick-card">
                                <div class="quick-icon github">G</div>
                                <div class="quick-info">
                                    <div class="quick-title">GitHub</div>
                                    <div class="quick-user">johndoe</div>
                                </div>
                                <div class="quick-actions">
                                    <button class="quick-btn" onclick="event.stopPropagation();copyPassword()">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="9" y="9" width="13" height="13" rx="2"/>
                                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="quick-card">
                                <div class="quick-icon bank">B</div>
                                <div class="quick-info">
                                    <div class="quick-title">Bank of Poland</div>
                                    <div class="quick-user">Personal account</div>
                                </div>
                                <div class="quick-actions">
                                    <button class="quick-btn" onclick="event.stopPropagation();copyPassword()">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="9" y="9" width="13" height="13" rx="2"/>
                                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="quick-card">
                                <div class="quick-icon notion">N</div>
                                <div class="quick-info">
                                    <div class="quick-title">Notion</div>
                                    <div class="quick-user">john@company.com</div>
                                </div>
                                <div class="quick-actions">
                                    <button class="quick-btn" onclick="event.stopPropagation();copyPassword()">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="9" y="9" width="13" height="13" rx="2"/>
                                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="quick-card">
                                <div class="quick-icon netflix">N</div>
                                <div class="quick-info">
                                    <div class="quick-title">Netflix</div>
                                    <div class="quick-user">john.doe@gmail.com</div>
                                </div>
                                <div class="quick-actions">
                                    <button class="quick-btn" onclick="event.stopPropagation();copyPassword()">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="9" y="9" width="13" height="13" rx="2"/>
                                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- PASSWORD HEALTH -->
                    <section class="password-health">
                        <div class="section-header">
                            <h2>Password Health</h2>
                            <a href="#" class="view-all">All issues →</a>
                        </div>
                        <div class="health-list">
                            <div class="health-item high">
                                <div class="health-icon">⚠️</div>
                                <div class="health-info">
                                    <div class="health-title">Change password</div>
                                    <div class="health-domain">linkedin.com</div>
                                </div>
                                <span class="severity-badge high">High</span>
                                <button class="health-action">Fix now</button>
                            </div>

                            <div class="health-item medium">
                                <div class="health-icon">⚡</div>
                                <div class="health-info">
                                    <div class="health-title">Weak password</div>
                                    <div class="health-domain">forum.example.com</div>
                                </div>
                                <span class="severity-badge medium">Medium</span>
                                <button class="health-action">Improve</button>
                            </div>

                            <div class="health-item medium">
                                <div class="health-icon">🔁</div>
                                <div class="health-info">
                                    <div class="health-title">Duplicate password</div>
                                    <div class="health-domain">3 accounts</div>
                                </div>
                                <span class="severity-badge medium">Medium</span>
                                <button class="health-action">View</button>
                            </div>

                            <div class="health-item low">
                                <div class="health-icon">🔐</div>
                                <div class="health-info">
                                    <div class="health-title">2FA not enabled</div>
                                    <div class="health-domain">amazon.com</div>
                                </div>
                                <span class="severity-badge low">Low</span>
                                <button class="health-action">Enable</button>
                            </div>
                        </div>
                    </section>

                </div>

                <!-- CENTER COLUMN (ACTIVITY TIMELINE) -->
                <div class="dashboard-center">
                    <section class="recent-activity">
                        <div class="section-header">
                            <h2>Recent Activity</h2>
                        </div>
                        <div class="activity-timeline">
                            <div class="activity-item">
                                <div class="activity-dot" style="background:#22c55e;"></div>
                                <div class="activity-content">
                                    <div class="activity-title">Login from new device</div>
                                    <div class="activity-meta">Warsaw, Poland • Chrome</div>
                                    <div class="activity-time">2 hours ago</div>
                                </div>
                            </div>

                            <div class="activity-item">
                                <div class="activity-dot" style="background:#3b82f6;"></div>
                                <div class="activity-content">
                                    <div class="activity-title">Password updated</div>
                                    <div class="activity-meta">GitHub account</div>
                                    <div class="activity-time">1 day ago</div>
                                </div>
                            </div>

                            <div class="activity-item">
                                <div class="activity-dot" style="background:#8b5cf6;"></div>
                                <div class="activity-content">
                                    <div class="activity-title">Autofill used</div>
                                    <div class="activity-meta">Google Login • Firefox</div>
                                    <div class="activity-time">3 days ago</div>
                                </div>
                            </div>

                            <div class="activity-item alert">
                                <div class="activity-dot" style="background:#ef4444;"></div>
                                <div class="activity-content">
                                    <div class="activity-title">Breach monitoring alert</div>
                                    <div class="activity-meta">Your email found in data breach</div>
                                    <div class="activity-time">5 days ago</div>
                                    <button class="activity-action">Review</button>
                                </div>
                            </div>

                            <div class="activity-item">
                                <div class="activity-dot" style="background:#22c55e;"></div>
                                <div class="activity-content">
                                    <div class="activity-title">Vault unlocked</div>
                                    <div class="activity-meta">iOS device</div>
                                    <div class="activity-time">1 week ago</div>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- RIGHT SIDEBAR (SECURITY PANEL) -->
                <aside class="dashboard-right">
                    
                    <!-- SECURITY SCORE -->
                    <section class="security-score-card">
                        <div class="score-ring">
                            <svg width="140" height="140" viewBox="0 0 140 140">
                                <circle cx="70" cy="70" r="63" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="8"/>
                                <circle cx="70" cy="70" r="63" fill="none" stroke="#22c55e" stroke-width="8" 
                                        stroke-dasharray="264 330" stroke-linecap="round"
                                        style="transform:rotate(-90deg); transform-origin:70px 70px;"/>
                            </svg>
                            <div class="score-text">
                                <div class="score-number">78</div>
                                <div class="score-label">Good</div>
                            </div>
                        </div>
                        <p class="score-message">Keep it up! Your vault is well protected.</p>
                        <button class="btn-improve">Improve security</button>
                    </section>

                    <!-- SECURITY BREAKDOWN -->
                    <section class="security-breakdown">
                        <h3>Security Breakdown</h3>
                        <div class="breakdown-stat">
                            <div class="stat-label">Strong passwords</div>
                            <div class="stat-value">78%</div>
                            <div class="stat-bar">
                                <div class="stat-fill" style="width:78%; background:#22c55e;"></div>
                            </div>
                        </div>
                        <div class="breakdown-stat">
                            <div class="stat-label">2FA usage</div>
                            <div class="stat-value">56%</div>
                            <div class="stat-bar">
                                <div class="stat-fill" style="width:56%; background:#eab308;"></div>
                            </div>
                        </div>
                        <div class="breakdown-stat">
                            <div class="stat-label">Reused passwords</div>
                            <div class="stat-value">35%</div>
                            <div class="stat-bar">
                                <div class="stat-fill" style="width:35%; background:#f97316;"></div>
                            </div>
                        </div>
                        <div class="breakdown-stat">
                            <div class="stat-label">Breached accounts</div>
                            <div class="stat-value">1</div>
                            <div class="stat-bar">
                                <div class="stat-fill" style="width:100%; background:#ef4444;"></div>
                            </div>
                        </div>
                    </section>

                    <!-- YOUR VAULTS -->
                    <section class="your-vaults">
                        <h3>Your Vaults</h3>
                        <div class="vaults-list">
                            <a href="#" class="vault-item">
                                <div class="vault-icon" style="background:#8b5cf6;">📁</div>
                                <div class="vault-info">
                                    <div class="vault-name">Personal</div>
                                    <div class="vault-count">120 items</div>
                                </div>
                            </a>
                            <a href="#" class="vault-item">
                                <div class="vault-icon" style="background:#3b82f6;">💼</div>
                                <div class="vault-info">
                                    <div class="vault-name">Work</div>
                                    <div class="vault-count">56 items</div>
                                </div>
                            </a>
                            <a href="#" class="vault-item">
                                <div class="vault-icon" style="background:#f97316;">💰</div>
                                <div class="vault-info">
                                    <div class="vault-name">Finance</div>
                                    <div class="vault-count">23 items</div>
                                </div>
                            </a>
                            <a href="#" class="vault-item">
                                <div class="vault-icon" style="background:#ec4899;">🌐</div>
                                <div class="vault-info">
                                    <div class="vault-name">Social</div>
                                    <div class="vault-count">15 items</div>
                                </div>
                            </a>
                            <a href="#" class="vault-item">
                                <div class="vault-icon" style="background:#06b6d4;">👥</div>
                                <div class="vault-info">
                                    <div class="vault-name">Shared Vault</div>
                                    <div class="vault-count">8 items</div>
                                </div>
                            </a>
                        </div>
                    </section>

                </aside>

            </div>
        </main>
    </div>

</div>

<!-- MODAL: Password Details -->
<div class="modal-overlay" id="passwordModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closePasswordModal()">×</button>
        <div class="modal-header">
            <div class="modal-icon google">G</div>
            <div>
                <h2>Google</h2>
                <p>john.doe@gmail.com</p>
            </div>
        </div>
        <div class="modal-fields">
            <div class="field">
                <label>Username</label>
                <div class="field-value">john.doe@gmail.com</div>
            </div>
            <div class="field">
                <label>Password</label>
                <div class="field-value password-field">
                    <span class="pwd-mask">••••••••••••••</span>
                    <button class="field-btn">Show</button>
                    <button class="field-btn" onclick="copyToClip('j0hN_P@ssw0rd!')">Copy</button>
                </div>
            </div>
            <div class="field">
                <label>Website</label>
                <a href="https://google.com" target="_blank" class="field-link">https://google.com</a>
            </div>
        </div>
        <div class="modal-actions">
            <button class="modal-btn secondary">Edit</button>
            <button class="modal-btn primary">Autofill</button>
        </div>
    </div>
</div>

<!-- TOAST NOTIFICATION -->
<div class="toast" id="toast"></div>

<!-- All JS modules -->
<script src="js/crypto-engine.js"></script>
<script src="js/password-generator.js"></script>
<script src="js/security-analyzer.js"></script>
<script src="js/validation.js"></script>
<script src="js/vault-key.js"></script>
<script src="js/vault-manager.js"></script>
<script src="js/vault-ui.js"></script>

<script>
// ────────────────────────────────────────────────────────────────
// DASHBOARD UI FUNCTIONS
// ────────────────────────────────────────────────────────────────

function openAddModal() {
    showToast('Add item modal would open here', 'info');
}

function openPasswordModal() {
    document.getElementById('passwordModal').classList.add('active');
}

function closePasswordModal() {
    document.getElementById('passwordModal').classList.remove('active');
}

function openNotifications() {
    showToast('You have 2 security alerts', 'warning');
}

function openUserMenu() {
    showToast('User menu opened', 'info');
}

function copyPassword() {
    const pwd = 'j0hN_P@ssw0rd!';
    navigator.clipboard.writeText(pwd);
    showToast('Password copied!', 'success');
}

function copyToClip(text) {
    navigator.clipboard.writeText(text);
    showToast('Copied!', 'success');
}

function showToast(message, type = 'info') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = `toast ${type} active`;
    
    setTimeout(() => {
        toast.classList.remove('active');
    }, 3000);
}

// Navigation
document.querySelectorAll('.nav-item').forEach(item => {
    item.addEventListener('click', (e) => {
        e.preventDefault();
        document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));
        item.classList.add('active');
        showToast(`Viewing: ${item.textContent.trim()}`, 'info');
    });
});

// Search functionality
document.querySelector('.topbar-search').addEventListener('keyup', (e) => {
    if (e.key === 'Enter') {
        showToast(`Searching for: ${e.target.value}`, 'info');
    }
});

// Close modals on background click
document.getElementById('passwordModal').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) {
        closePasswordModal();
    }
});

// Ctrl + K search focus
document.addEventListener('keydown', (e) => {
    if (e.ctrlKey && e.key === 'k') {
        e.preventDefault();
        document.querySelector('.topbar-search').focus();
        showToast('Search focused', 'info');
    }
});
</script>

</body>
</html>

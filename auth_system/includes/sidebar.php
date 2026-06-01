<?php
// Shared sidebar component
// $active_nav: dashboard, all_items, favorites, password_manager, vaults,
//              shared, passkeys, notes, cards, identities, security, generator, activity, settings
$active_nav = $active_nav ?? '';
$is_root    = $is_root ?? false;
$prefix     = $is_root ? '' : '../';

function renderNav(string $href, string $key, string $active, string $icon, string $label, string $badge = ''): void {
    $cls = 'nav-item' . ($active === $key ? ' active' : '');
    echo '<a href="' . htmlspecialchars($href) . '" class="' . $cls . '">';
    echo '<span class="nav-icon">' . $icon . '</span>';
    echo '<span>' . htmlspecialchars($label) . '</span>';
    if ($badge) {
        echo '<span class="nav-badge">' . htmlspecialchars($badge) . '</span>';
    }
    echo '</a>';
}

$ico = [
    'dashboard'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
    'all_items'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2"/></svg>',
    'favorites'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
    'pm'         => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>',
    'vaults'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
    'shared'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    'passkeys'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21 2-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>',
    'notes'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
    'cards'      => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
    'identities' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    'security'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    'generator'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/></svg>',
    'activity'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
    'settings'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93 17.66 6.34M19.07 19.07l-1.41-1.41M4.93 19.07l1.41-1.41M4.93 4.93l1.41 1.41M21 12h-2M5 12H3M12 19v2M12 3V5"/></svg>',
    'logout'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>',
    'lock'       => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
];
?>
<nav class="sidebar" aria-label="Main navigation">
    <a href="<?php echo $prefix; ?>dashboard.php" class="logo">
        <div class="logo-icon">
            <?php echo $ico['lock']; ?>
        </div>
        <span>Vaultly</span>
    </a>

    <div class="nav-section-label">Main</div>
    <?php renderNav($prefix.'dashboard.php',              'dashboard',        $active_nav, $ico['dashboard'], 'Dashboard'); ?>
    <?php renderNav($prefix.'actions/view_all_items.php', 'all_items',        $active_nav, $ico['all_items'], 'All Items'); ?>
    <?php renderNav($prefix.'actions/view_favorites.php', 'favorites',        $active_nav, $ico['favorites'], 'Favorites'); ?>
    <?php renderNav($prefix.'actions/password_manager.php','password_manager',$active_nav, $ico['pm'],        'Password Manager'); ?>
    <?php renderNav($prefix.'actions/secure_passwords.php','secure_passwords',$active_nav,
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1.5" fill="currentColor" stroke="none"/></svg>',
        'Passwords'
    ); ?>

    <div class="nav-sep"></div>
    <div class="nav-section-label">Vault</div>
    <?php renderNav($prefix.'actions/manage_vaults.php',  'vaults',   $active_nav, $ico['vaults'],   'Vaults'); ?>
    <?php renderNav($prefix.'actions/view_shared.php',    'shared',   $active_nav, $ico['shared'],   'Shared'); ?>
    <?php renderNav($prefix.'actions/view_passkeys.php',  'passkeys', $active_nav, $ico['passkeys'], 'Passkeys', 'New'); ?>

    <div class="nav-sep"></div>
    <div class="nav-section-label">Items</div>
    <?php renderNav($prefix.'actions/view_secure_notes.php','notes',      $active_nav, $ico['notes'],      'Secure Notes'); ?>
    <?php renderNav($prefix.'actions/view_cards.php',       'cards',      $active_nav, $ico['cards'],      'Cards'); ?>
    <?php renderNav($prefix.'actions/view_identities.php',  'identities', $active_nav, $ico['identities'], 'Identities'); ?>

    <div class="nav-sep"></div>
    <div class="nav-section-label">Tools</div>
    <?php renderNav($prefix.'actions/security_audit.php',     'security',  $active_nav, $ico['security'],  'Security Audit'); ?>
    <?php renderNav($prefix.'actions/password_generator.php', 'generator', $active_nav, $ico['generator'], 'Generator'); ?>
    <?php renderNav($prefix.'actions/view_activity.php',      'activity',  $active_nav, $ico['activity'],  'Activity'); ?>
    <?php renderNav($prefix.'actions/settings.php',           'settings',  $active_nav, $ico['settings'],  'Settings'); ?>

    <div class="sidebar-footer">
        <div class="sidebar-status">
            <span class="status-dot"></span>
            <span>Vault secure</span>
        </div>
        <a href="<?php echo $prefix; ?>dashboard.php?logout=true" class="logout-btn">
            <?php echo $ico['logout']; ?>
            <span>Logout</span>
        </a>
    </div>
</nav>

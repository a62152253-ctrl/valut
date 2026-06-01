<?php
// ─────────────────────────────────────────────────────────────────
// Recently Used Items Functions
// ─────────────────────────────────────────────────────────────────

function getServiceIcon($title) {
    $title = strtolower($title);
    $services = [
        'google' => '🔍',
        'github' => '🐙',
        'notion' => '📋',
        'gmail' => '📧',
        'facebook' => '📱',
        'twitter' => '𝕏',
        'instagram' => '📷',
        'linkedin' => '💼',
        'dropbox' => '📁',
        'google drive' => '☁️',
        'onedrive' => '☁️',
        'icloud' => '☁️',
        'amazon' => '📦',
        'netflix' => '🎬',
        'spotify' => '🎵',
        'steam' => '🎮',
        'discord' => '💬',
        'slack' => '💼',
        'zoom' => '📹',
        'microsoft' => '💻',
        'apple' => '🍎',
        'paypal' => '💳',
        'stripe' => '💳',
        'bank' => '🏦',
        'bitcoin' => '₿',
        'crypto' => '🪙',
        'minecraft' => '⛏️',
        'epic' => '🎮',
        'ubisoft' => '🎮',
        'twitch' => '📺',
        'youtube' => '▶️',
        'reddit' => '👾',
        'mastodon' => '🐘',
        'bluesky' => '🦋',
        'x.com' => '𝕏',
        'telegram' => '✈️',
        'whatsapp' => '💬',
        'signal' => '🔐',
        'aws' => '☁️',
        'gcp' => '☁️',
        'azure' => '☁️',
        'vercel' => '▲',
        'netlify' => '⚡',
        'heroku' => '📦',
        'docker' => '🐳',
        'github pages' => '📄',
        'gitlab' => '🦊',
        'bitbucket' => '🪣',
        'jira' => '⚙️',
        'confluence' => '📚',
        'asana' => '✓',
        'monday' => '📅',
        'trello' => '📊',
        'figma' => '🎨',
        'sketch' => '🎨',
        'adobe' => '🎨',
        'photoshop' => '🖼️',
        'canva' => '🎨',
        'gsuite' => '📊',
        'office365' => '📊',
        'outlook' => '📧',
        'wordpress' => '📝',
        'shopify' => '🛒',
        'wix' => '🌐',
        'squarespace' => '🌐',
        'webflow' => '🌐',
        'passport' => '📋',
        'visa' => '💳',
        'mastercard' => '💳',
        'amex' => '💳',
    ];
    
    foreach ($services as $key => $icon) {
        if (stripos($title, $key) !== false) {
            return $icon;
        }
    }
    
    return '📌';
}

function logRecentItem($user_id, $entry_uuid, $entry_title, $entry_type) {
    global $conn;
    if (!$conn || !$conn->ping()) return false;
    
    $stmt = $conn->prepare("SELECT id FROM vault_recent WHERE user_id = ? AND entry_uuid = ?");
    if (!$stmt) return false;
    $stmt->bind_param('is', $user_id, $entry_uuid);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    
    if ($exists) {
        $stmt = $conn->prepare("UPDATE vault_recent SET accessed_at = CURRENT_TIMESTAMP, access_count = access_count + 1, entry_title = ? WHERE user_id = ? AND entry_uuid = ?");
        if (!$stmt) return false;
        $stmt->bind_param('sis', $entry_title, $user_id, $entry_uuid);
    } else {
        $stmt = $conn->prepare("INSERT INTO vault_recent (user_id, entry_uuid, entry_title, entry_type) VALUES (?, ?, ?, ?)");
        if (!$stmt) return false;
        $stmt->bind_param('isss', $user_id, $entry_uuid, $entry_title, $entry_type);
    }
    
    $success = $stmt->execute();
    $stmt->close();
    return $success;
}

function getRecentItems($user_id, $limit = 6) {
    global $conn;
    if (!$conn || !$conn->ping()) return [];
    
    $stmt = $conn->prepare("SELECT entry_uuid, entry_title, entry_type, accessed_at FROM vault_recent WHERE user_id = ? ORDER BY accessed_at DESC LIMIT ?");
    if (!$stmt) return [];
    $stmt->bind_param('ii', $user_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $items;
}
?>

<?php
// Example: Add this to your vault viewer (e.g., actions/view_all_items.php or a modal handler)

// When user opens/views an item, call logRecentItem on the server:

include 'includes/db.php';
include 'includes/recent-items.php';

// Get the item details
$entry_uuid = 'user-uuid-here';   // From GET/POST param
$entry_type = 'login';              // From database query  
$entry_title = 'GitHub Account';    // From decrypted data

// Log the access
$user_id = (int)$_SESSION['user_id'];
$success = logRecentItem($user_id, $entry_uuid, $entry_title, $entry_type);

if ($success) {
    // Item tracked successfully
    http_response_code(200);
    echo json_encode(['ok' => true, 'message' => 'Item access logged']);
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to log item access']);
}

?>

<!-- 
OR use the API endpoint from JavaScript:

When user clicks "View Item" or similar action:
-->

<script>
async function openVaultItem(uuid, title, type) {
    // Log the access
    await fetch('api/recent.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            entry_uuid: uuid,
            entry_title: title,
            entry_type: type
        })
    });
    
    // Then proceed to open/display the item
    showItemDetails(uuid, title, type);
}

// Usage:
// <button onclick="openVaultItem('uuid123', 'GitHub Account', 'login')">View</button>
</script>

<!--
Additional Enhancement: Show access frequency in UI
-->

<?php
// Get total access count for an item
$stmt = $conn->prepare("SELECT access_count FROM vault_recent WHERE user_id = ? AND entry_uuid = ? LIMIT 1");
$stmt->bind_param('is', $user_id, $entry_uuid);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$access_count = $row ? (int)$row['access_count'] : 0;
$stmt->close();

// Display access frequency
echo "Accessed {$access_count} times";
?>

<!--
Example Output in Dashboard:

┌─────────────────────────────────────────┐
│ Recently used                           │
├─────────────────────────────────────────┤
│                                         │
│  🐙 GitHub                              │
│  Login • Dec 15                         │
│                                         │
│  🔍 Google                              │
│  Login • Dec 14                         │
│                                         │
│  📋 Notion Workspace                    │
│  Note • Dec 14                          │
│                                         │
└─────────────────────────────────────────┘
-->

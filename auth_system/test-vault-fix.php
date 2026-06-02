<?php
/**
 * Vault Module Test Suite
 * Tests vault creation, folder creation, and password/entry addition
 */

session_start();
include 'includes/db.php';
include 'includes/vault_auth.php';

// Simulate authenticated session for testing
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
}

$userId = (int)$_SESSION['user_id'];
$results = [];

echo "<h2>Vault Module Tests</h2>\n";

// ════════════════════════════════════════════════════════════════════
// TEST 1: Vault folder creation
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 1: Folder Creation</h3>\n";
try {
    $name = 'Test Folder ' . date('YmdHis');
    $color = '#8b5cf6';
    
    $stmt = $conn->prepare("INSERT INTO vault_folders (user_id, name, color) VALUES (?, ?, ?)");
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param('iss', $userId, $name, $color);
    if (!$stmt->execute()) throw new Exception($conn->error);
    $folderId = $conn->insert_id;
    $stmt->close();
    
    echo "✓ Folder created successfully with ID: $folderId<br>\n";
    $results['folder_creation'] = true;
} catch (Exception $e) {
    echo "✗ Folder creation failed: " . $e->getMessage() . "<br>\n";
    $results['folder_creation'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 2: Vault entry creation (simulated encrypted password entry)
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 2: Vault Entry (Password) Creation</h3>\n";
try {
    $bytes = random_bytes(16);
    $h = bin2hex($bytes);
    $uuid = substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);
    
    // Simulate encrypted data (in real scenario this comes from client)
    $encryptedData = base64_encode('{"username":"testuser@example.com","password":"SecurePass123!"}');
    $iv = base64_encode(random_bytes(12));
    
    $type = 'login';
    $folderId_ref = isset($folderId) ? $folderId : null;
    
    $stmt = $conn->prepare(
        "INSERT INTO vault_entries (user_id, uuid, folder_id, type, encrypted_data, iv, favorite)
         VALUES (?, ?, ?, ?, ?, ?, 0)"
    );
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param('isisssi', $userId, $uuid, $folderId_ref, $type, $encryptedData, $iv);
    if (!$stmt->execute()) throw new Exception($conn->error);
    $stmt->close();
    
    echo "✓ Vault entry created successfully with UUID: $uuid<br>\n";
    $results['entry_creation'] = true;
} catch (Exception $e) {
    echo "✗ Vault entry creation failed: " . $e->getMessage() . "<br>\n";
    $results['entry_creation'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 3: Listing all folders
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 3: Listing Folders</h3>\n";
try {
    $stmt = $conn->prepare("SELECT id, name, color FROM vault_folders WHERE user_id = ? ORDER BY name");
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $folders = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo "✓ Found " . count($folders) . " folder(s)<br>\n";
    foreach ($folders as $f) {
        echo "  - {$f['name']} ({$f['color']})<br>\n";
    }
    $results['folder_list'] = true;
} catch (Exception $e) {
    echo "✗ Folder listing failed: " . $e->getMessage() . "<br>\n";
    $results['folder_list'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 4: Listing all vault entries
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 4: Listing Vault Entries</h3>\n";
try {
    $stmt = $conn->prepare(
        "SELECT uuid, type, folder_id, created_at FROM vault_entries WHERE user_id = ? ORDER BY created_at DESC LIMIT 5"
    );
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $entries = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo "✓ Found " . count($entries) . " vault entry/entries<br>\n";
    foreach ($entries as $e) {
        echo "  - {$e['uuid']} ({$e['type']}) created: {$e['created_at']}<br>\n";
    }
    $results['entry_list'] = true;
} catch (Exception $e) {
    echo "✗ Entry listing failed: " . $e->getMessage() . "<br>\n";
    $results['entry_list'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 5: Update entry (simulate password update)
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 5: Vault Entry Update</h3>\n";
if (isset($uuid)) {
    try {
        $newEncryptedData = base64_encode('{"username":"testuser@example.com","password":"UpdatedPass456!"}');
        $newIv = base64_encode(random_bytes(12));
        
        $stmt = $conn->prepare(
            "UPDATE vault_entries SET encrypted_data = ?, iv = ? WHERE uuid = ? AND user_id = ?"
        );
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('sssi', $newEncryptedData, $newIv, $uuid, $userId);
        if (!$stmt->execute()) throw new Exception($conn->error);
        $stmt->close();
        
        echo "✓ Entry updated successfully<br>\n";
        $results['entry_update'] = true;
    } catch (Exception $e) {
        echo "✗ Entry update failed: " . $e->getMessage() . "<br>\n";
        $results['entry_update'] = false;
    }
} else {
    echo "⊘ Skipped (no entry UUID available)<br>\n";
}

// ════════════════════════════════════════════════════════════════════
// TEST SUMMARY
// ════════════════════════════════════════════════════════════════════
echo "<h3>Test Summary</h3>\n";
$passed = count(array_filter($results));
$total = count($results);
echo "Passed: $passed / $total<br>\n";

if ($passed === $total) {
    echo "<div style='color:green; font-weight:bold;'>✓ All tests passed</div>\n";
} else {
    echo "<div style='color:red; font-weight:bold;'>✗ Some tests failed</div>\n";
    foreach ($results as $test => $result) {
        if (!$result) echo "  - $test: FAILED<br>\n";
    }
}

$conn->close();
?>

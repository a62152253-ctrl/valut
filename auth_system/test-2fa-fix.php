<?php
/**
 * 2FA Module Test Suite
 * Tests TOTP setup, verification, and backup code handling
 */

session_start();
include 'includes/db.php';
include 'includes/totp.php';

// Simulate authenticated session for testing
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['email'] = 'testuser@example.com';
}

$userId = (int)$_SESSION['user_id'];
$results = [];

echo "<h2>2FA Module Tests</h2>\n";

// ════════════════════════════════════════════════════════════════════
// TEST 1: TOTP Secret Generation
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 1: TOTP Secret Generation</h3>\n";
try {
    $secret = TOTP::generateSecret();
    if (strlen($secret) === 32 && preg_match('/^[A-Z2-7]+$/', $secret)) {
        echo "✓ Secret generated: $secret (length: " . strlen($secret) . ")<br>\n";
        $results['secret_generation'] = true;
    } else {
        throw new Exception("Invalid secret format: $secret");
    }
} catch (Exception $e) {
    echo "✗ Secret generation failed: " . $e->getMessage() . "<br>\n";
    $results['secret_generation'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 2: TOTP Code Generation
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 2: TOTP Code Generation</h3>\n";
try {
    if (!isset($secret)) {
        $secret = TOTP::generateSecret();
    }
    $code = TOTP::getCode($secret, 0);
    if (preg_match('/^\d{6}$/', $code)) {
        echo "✓ TOTP code generated: $code<br>\n";
        $results['code_generation'] = true;
    } else {
        throw new Exception("Invalid code format: $code");
    }
} catch (Exception $e) {
    echo "✗ Code generation failed: " . $e->getMessage() . "<br>\n";
    $results['code_generation'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 3: TOTP Code Verification
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 3: TOTP Code Verification</h3>\n";
try {
    if (!isset($secret)) {
        $secret = TOTP::generateSecret();
    }
    $code = TOTP::getCode($secret, 0);
    if (TOTP::verify($secret, $code)) {
        echo "✓ TOTP code verified successfully<br>\n";
        $results['code_verification'] = true;
    } else {
        throw new Exception("Code verification failed");
    }
} catch (Exception $e) {
    echo "✗ Code verification failed: " . $e->getMessage() . "<br>\n";
    $results['code_verification'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 4: Backup Code Generation
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 4: Backup Code Generation</h3>\n";
try {
    $backupCodes = TOTP::generateBackupCodes(8);
    $allValid = true;
    foreach ($backupCodes as $code) {
        if (!preg_match('/^[A-F0-9]{4}-[A-F0-9]{4}$/', $code)) {
            $allValid = false;
            break;
        }
    }
    
    if (count($backupCodes) === 8 && $allValid) {
        echo "✓ Generated " . count($backupCodes) . " backup codes<br>\n";
        foreach ($backupCodes as $code) {
            echo "  - $code<br>\n";
        }
        $results['backup_generation'] = true;
    } else {
        throw new Exception("Invalid backup code format");
    }
} catch (Exception $e) {
    echo "✗ Backup code generation failed: " . $e->getMessage() . "<br>\n";
    $results['backup_generation'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 5: OTPAuth URI Generation
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 5: OTPAuth URI Generation</h3>\n";
try {
    if (!isset($secret)) {
        $secret = TOTP::generateSecret();
    }
    $uri = TOTP::otpauthUri($secret, 'test@example.com', 'Vaultly');
    if (strpos($uri, 'otpauth://totp/') === 0) {
        echo "✓ OTPAuth URI generated<br>\n";
        echo "  URI: " . htmlspecialchars(substr($uri, 0, 80)) . "...<br>\n";
        $results['otpauth_uri'] = true;
    } else {
        throw new Exception("Invalid URI format");
    }
} catch (Exception $e) {
    echo "✗ OTPAuth URI generation failed: " . $e->getMessage() . "<br>\n";
    $results['otpauth_uri'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 6: Database 2FA Setup
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 6: Database 2FA Setup</h3>\n";
try {
    if (!isset($secret)) {
        $secret = TOTP::generateSecret();
    }
    
    // Update user with TOTP secret
    $stmt = $conn->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?");
    if (!$stmt) throw new Exception($conn->error);
    $stmt->bind_param('si', $secret, $userId);
    if (!$stmt->execute()) throw new Exception($conn->error);
    $stmt->close();
    
    // Verify it was saved
    $checkStmt = $conn->prepare("SELECT totp_secret, totp_enabled FROM users WHERE id = ?");
    if (!$checkStmt) throw new Exception($conn->error);
    $checkStmt->bind_param('i', $userId);
    $checkStmt->execute();
    $row = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    
    if ($row && $row['totp_enabled'] && $row['totp_secret'] === $secret) {
        echo "✓ 2FA saved to database<br>\n";
        $results['db_2fa_setup'] = true;
    } else {
        throw new Exception("2FA not properly saved");
    }
} catch (Exception $e) {
    echo "✗ Database 2FA setup failed: " . $e->getMessage() . "<br>\n";
    $results['db_2fa_setup'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 7: Backup Codes Database Storage
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 7: Backup Codes Database Storage</h3>\n";
try {
    // Delete old codes
    $delStmt = $conn->prepare("DELETE FROM totp_backup_codes WHERE user_id = ?");
    if (!$delStmt) throw new Exception($conn->error);
    $delStmt->bind_param('i', $userId);
    $delStmt->execute();
    $delStmt->close();
    
    // Insert backup codes
    $backupCodes = TOTP::generateBackupCodes(8);
    $insertedCount = 0;
    foreach ($backupCodes as $code) {
        $hash = password_hash($code, PASSWORD_BCRYPT);
        $insertStmt = $conn->prepare("INSERT INTO totp_backup_codes (user_id, code_hash) VALUES (?, ?)");
        if (!$insertStmt) throw new Exception($conn->error);
        $insertStmt->bind_param('is', $userId, $hash);
        if (!$insertStmt->execute()) throw new Exception($conn->error);
        $insertStmt->close();
        $insertedCount++;
    }
    
    // Count stored codes
    $countStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM totp_backup_codes WHERE user_id = ? AND used_at IS NULL");
    if (!$countStmt) throw new Exception($conn->error);
    $countStmt->bind_param('i', $userId);
    $countStmt->execute();
    $countRow = $countStmt->get_result()->fetch_assoc();
    $countStmt->close();
    
    if ($countRow['cnt'] === $insertedCount) {
        echo "✓ " . $insertedCount . " backup codes stored in database<br>\n";
        $results['db_backup_codes'] = true;
    } else {
        throw new Exception("Mismatch: inserted $insertedCount, found " . $countRow['cnt']);
    }
} catch (Exception $e) {
    echo "✗ Backup codes database storage failed: " . $e->getMessage() . "<br>\n";
    $results['db_backup_codes'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 8: Backup Code Verification
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 8: Backup Code Verification</h3>\n";
try {
    // Get a stored backup code
    $getStmt = $conn->prepare("SELECT code_hash FROM totp_backup_codes WHERE user_id = ? AND used_at IS NULL LIMIT 1");
    if (!$getStmt) throw new Exception($conn->error);
    $getStmt->bind_param('i', $userId);
    $getStmt->execute();
    $codeRow = $getStmt->get_result()->fetch_assoc();
    $getStmt->close();
    
    if (!$codeRow) {
        throw new Exception("No backup code found");
    }
    
    // Verify against the original code (from backupCodes array)
    $testCode = $backupCodes[0];
    $testCodeClean = str_replace('-', '', $testCode);
    
    if (password_verify($testCodeClean, $codeRow['code_hash'])) {
        echo "✓ Backup code verification successful<br>\n";
        echo "  Tested code: $testCode<br>\n";
        $results['backup_verification'] = true;
    } else {
        throw new Exception("Backup code verification failed");
    }
} catch (Exception $e) {
    echo "✗ Backup code verification failed: " . $e->getMessage() . "<br>\n";
    $results['backup_verification'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 9: 2FA Disable
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 9: 2FA Disable</h3>\n";
try {
    // Disable 2FA
    $disableStmt = $conn->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?");
    if (!$disableStmt) throw new Exception($conn->error);
    $disableStmt->bind_param('i', $userId);
    if (!$disableStmt->execute()) throw new Exception($conn->error);
    $disableStmt->close();
    
    // Verify it's disabled
    $checkStmt = $conn->prepare("SELECT totp_enabled FROM users WHERE id = ?");
    if (!$checkStmt) throw new Exception($conn->error);
    $checkStmt->bind_param('i', $userId);
    $checkStmt->execute();
    $row = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    
    if (!$row['totp_enabled']) {
        echo "✓ 2FA disabled successfully<br>\n";
        $results['2fa_disable'] = true;
    } else {
        throw new Exception("2FA still enabled");
    }
} catch (Exception $e) {
    echo "✗ 2FA disable failed: " . $e->getMessage() . "<br>\n";
    $results['2fa_disable'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST SUMMARY
// ════════════════════════════════════════════════════════════════════
echo "<h3>Test Summary</h3>\n";
$passed = count(array_filter($results));
$total = count($results);
echo "Passed: $passed / $total<br>\n";

if ($passed === $total) {
    echo "<div style='color:green; font-weight:bold;'>✓ All 2FA tests passed</div>\n";
} else {
    echo "<div style='color:red; font-weight:bold;'>✗ Some 2FA tests failed</div>\n";
    foreach ($results as $test => $result) {
        if (!$result) echo "  - $test: FAILED<br>\n";
    }
}

$conn->close();
?>

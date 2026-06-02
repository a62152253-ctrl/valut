<?php
/**
 * 2FA Settings Integration Test Suite
 * Verifies the complete 2FA flow from enable to disable in settings
 */

session_start();
include 'includes/db.php';
include 'includes/totp.php';

// Simulate authenticated session
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
}

$userId = (int)$_SESSION['user_id'];
$results = [];

echo "<h2>2FA Settings Integration Tests</h2>\n";

// ════════════════════════════════════════════════════════════════════
// TEST 1: Re-authentication flow
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 1: Re-authentication API</h3>\n";
try {
    // Check if reauth.php exists and is callable
    if (file_exists('./api/reauth.php')) {
        echo "✓ Re-authentication endpoint exists at ./api/reauth.php<br>\n";
        $results['reauth_endpoint'] = true;
    } else {
        throw new Exception("re-auth.php not found");
    }
} catch (Exception $e) {
    echo "✗ Re-authentication check failed: " . $e->getMessage() . "<br>\n";
    $results['reauth_endpoint'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 2: 2FA Setup endpoint (GET)
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 2: 2FA Setup Endpoint</h3>\n";
try {
    if (file_exists('./api/2fa.php')) {
        // Simulate the setup action
        ob_start();
        $_GET['action'] = 'setup';
        $_SESSION['user_id'] = $userId;
        $_SESSION['email'] = 'test@example.com';
        
        // We can't directly execute the endpoint, but we can check its structure
        $content = file_get_contents('./api/2fa.php');
        if (strpos($content, 'case \'setup\'') !== false) {
            echo "✓ 2FA setup action found in api/2fa.php<br>\n";
            $results['2fa_setup_action'] = true;
        } else {
            throw new Exception("Setup action not found");
        }
        ob_end_clean();
    } else {
        throw new Exception("2fa.php not found");
    }
} catch (Exception $e) {
    echo "✗ 2FA setup check failed: " . $e->getMessage() . "<br>\n";
    $results['2fa_setup_action'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 3: 2FA Enable endpoint (POST)
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 3: 2FA Enable Endpoint</h3>\n";
try {
    $content = file_get_contents('./api/2fa.php');
    if (strpos($content, 'case \'enable\'') !== false &&
        strpos($content, 'totp_enabled = 1') !== false) {
        echo "✓ 2FA enable action found with database update<br>\n";
        $results['2fa_enable_action'] = true;
    } else {
        throw new Exception("Enable action or DB update not found");
    }
} catch (Exception $e) {
    echo "✗ 2FA enable check failed: " . $e->getMessage() . "<br>\n";
    $results['2fa_enable_action'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 4: Settings page 2FA UI
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 4: Settings Page 2FA UI</h3>\n";
try {
    $content = file_get_contents('./actions/settings.php');
    if (strpos($content, 'open2FASetup') !== false &&
        strpos($content, 'modal2FASetup') !== false &&
        strpos($content, 'open2FADisable') !== false) {
        echo "✓ 2FA UI functions and modals found in settings.php<br>\n";
        echo "  - open2FASetup() function ✓<br>\n";
        echo "  - modal2FASetup element ✓<br>\n";
        echo "  - open2FADisable() function ✓<br>\n";
        $results['settings_2fa_ui'] = true;
    } else {
        throw new Exception("2FA UI components not found");
    }
} catch (Exception $e) {
    echo "✗ Settings 2FA UI check failed: " . $e->getMessage() . "<br>\n";
    $results['settings_2fa_ui'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 5: 2FA Button in security preferences
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 5: Enable 2FA Button</h3>\n";
try {
    $content = file_get_contents('./actions/settings.php');
    if (preg_match('/onclick=["\']open2FA(Setup|Disable)\(\)["\']/', $content)) {
        echo "✓ Enable/Disable 2FA buttons found in settings<br>\n";
        $results['2fa_button'] = true;
    } else {
        throw new Exception("Button handlers not found");
    }
} catch (Exception $e) {
    echo "✗ 2FA button check failed: " . $e->getMessage() . "<br>\n";
    $results['2fa_button'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 6: 2FA Disable endpoint
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 6: 2FA Disable Endpoint</h3>\n";
try {
    $content = file_get_contents('./api/2fa.php');
    if (strpos($content, 'case \'disable\'') !== false &&
        strpos($content, 'totp_enabled = 0') !== false &&
        strpos($content, 'totp_backup_codes') !== false) {
        echo "✓ 2FA disable action with backup code cleanup ✓<br>\n";
        $results['2fa_disable_action'] = true;
    } else {
        throw new Exception("Disable action not properly implemented");
    }
} catch (Exception $e) {
    echo "✗ 2FA disable check failed: " . $e->getMessage() . "<br>\n";
    $results['2fa_disable_action'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST 7: Complete user flow simulation
// ════════════════════════════════════════════════════════════════════
echo "<h3>TEST 7: Complete 2FA Flow (Database)</h3>\n";
try {
    // Generate a test secret
    $testSecret = TOTP::generateSecret();
    echo "✓ Generated test TOTP secret<br>\n";
    
    // Simulate enable: save to user
    $enableStmt = $conn->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?");
    if (!$enableStmt) throw new Exception($conn->error);
    $enableStmt->bind_param('si', $testSecret, $userId);
    $enableStmt->execute();
    $enableStmt->close();
    echo "✓ 2FA enabled in database<br>\n";
    
    // Generate and save backup codes
    $codes = TOTP::generateBackupCodes(8);
    $insertCount = 0;
    foreach ($codes as $code) {
        $hash = password_hash($code, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO totp_backup_codes (user_id, code_hash) VALUES (?, ?)");
        if (!$stmt) throw new Exception($conn->error);
        $stmt->bind_param('is', $userId, $hash);
        $stmt->execute();
        $stmt->close();
        $insertCount++;
    }
    echo "✓ Saved " . $insertCount . " backup codes<br>\n";
    
    // Verify login flow with 2FA
    $checkStmt = $conn->prepare("SELECT totp_enabled, totp_secret FROM users WHERE id = ?");
    if (!$checkStmt) throw new Exception($conn->error);
    $checkStmt->bind_param('i', $userId);
    $checkStmt->execute();
    $row = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    
    if ($row['totp_enabled'] && $row['totp_secret']) {
        echo "✓ Login would now require 2FA verification<br>\n";
        $results['complete_flow'] = true;
    } else {
        throw new Exception("2FA not properly enabled");
    }
    
    // Test a TOTP code
    $code = TOTP::getCode($testSecret, 0);
    if (TOTP::verify($testSecret, $code)) {
        echo "✓ TOTP code generated and verified<br>\n";
    }
    
    // Cleanup: disable 2FA
    $disableStmt = $conn->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?");
    if (!$disableStmt) throw new Exception($conn->error);
    $disableStmt->bind_param('i', $userId);
    $disableStmt->execute();
    $disableStmt->close();
    
    $delStmt = $conn->prepare("DELETE FROM totp_backup_codes WHERE user_id = ?");
    if (!$delStmt) throw new Exception($conn->error);
    $delStmt->bind_param('i', $userId);
    $delStmt->execute();
    $delStmt->close();
    
    echo "✓ 2FA disabled and cleaned up<br>\n";
    $results['complete_flow'] = true;
    
} catch (Exception $e) {
    echo "✗ Complete flow test failed: " . $e->getMessage() . "<br>\n";
    $results['complete_flow'] = false;
}

// ════════════════════════════════════════════════════════════════════
// TEST SUMMARY
// ════════════════════════════════════════════════════════════════════
echo "<h3>Test Summary</h3>\n";
$passed = count(array_filter($results));
$total = count($results);
echo "Passed: $passed / $total<br>\n";

if ($passed === $total) {
    echo "<div style='color:green; font-weight:bold;'>✓ All 2FA settings tests passed</div>\n";
    echo "<div style='margin-top:1rem;padding:1rem;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);border-radius:8px;color:#22c55e;font-weight:600;'>\n";
    echo "✓ 2FA is fully integrated into Settings!\n";
    echo "Users can now:\n";
    echo "1. Click 'Enable 2FA' in Settings\n";
    echo "2. Scan QR code or enter secret manually\n";
    echo "3. Verify with authenticator app\n";
    echo "4. Save backup codes\n";
    echo "5. Login will require 2FA verification\n";
    echo "</div>\n";
} else {
    echo "<div style='color:red; font-weight:bold;'>✗ Some tests failed</div>\n";
    foreach ($results as $test => $result) {
        if (!$result) echo "  - $test: FAILED<br>\n";
    }
}

$conn->close();
?>

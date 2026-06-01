<?php
// Mock database for testing dashboard UI without MySQL running
// This allows the dashboard to load without database connection

// Store mock session data
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['username'] = 'John Doe';
    $_SESSION['email'] = 'john@example.com';
}

// Mock connection object
class MockConnection {
    public $connect_error = null;
    
    public function select_db($db) {
        return true;
    }
    
    public function query($sql) {
        return true;
    }
    
    public function real_escape_string($str) {
        return addslashes($str);
    }
}

$conn = new MockConnection();

// Helper functions
function sanitize($input) {
    return addslashes(trim($input));
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function generateToken($length = 32) {
    return bin2hex(random_bytes($length / 2));
}
?>

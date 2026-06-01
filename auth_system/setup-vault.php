<?php
// Setup vault tables if they don't exist
session_start();
include 'includes/db.php';

// Create vault_folders table
$sql = "CREATE TABLE IF NOT EXISTS vault_folders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    color VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_folder (user_id, name)
)";

if ($conn->query($sql) !== TRUE && strpos($conn->error, 'already exists') === false) {
    echo "Error creating vault_folders: " . $conn->error;
}

// Create vault_entries table
$sql = "CREATE TABLE IF NOT EXISTS vault_entries (
    uuid VARCHAR(36) PRIMARY KEY,
    user_id INT NOT NULL,
    folder_id INT,
    type ENUM('login', 'note', 'card', 'identity') DEFAULT 'login',
    encrypted_data LONGTEXT NOT NULL,
    iv VARCHAR(255) NOT NULL,
    favorite TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (folder_id) REFERENCES vault_folders(id) ON DELETE SET NULL,
    INDEX idx_user_type (user_id, type)
)";

if ($conn->query($sql) !== TRUE && strpos($conn->error, 'already exists') === false) {
    echo "Error creating vault_entries: " . $conn->error;
}

// Create vault_history table for version control
$sql = "CREATE TABLE IF NOT EXISTS vault_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    entry_uuid VARCHAR(36) NOT NULL,
    user_id INT NOT NULL,
    encrypted_data LONGTEXT NOT NULL,
    iv VARCHAR(255) NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (entry_uuid) REFERENCES vault_entries(uuid) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_entry_changed (entry_uuid, changed_at DESC)
)";

if ($conn->query($sql) !== TRUE && strpos($conn->error, 'already exists') === false) {
    echo "Error creating vault_history: " . $conn->error;
}

echo "✓ Vault tables setup complete!";
?>

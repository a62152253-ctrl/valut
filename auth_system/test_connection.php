<?php
$conn = new mysqli('localhost', 'root', '');
if ($conn->connect_error) {
    echo 'Connection Error: ' . $conn->connect_error;
} else {
    echo 'Connection Successful! ✓';
}
?>

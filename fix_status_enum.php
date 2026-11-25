<?php
require_once 'includes/db.php';

try {
    $pdo->exec("ALTER TABLE complaints MODIFY COLUMN status ENUM('New', 'Under Investigation', 'For Resolution', 'Forwarded to Clerk', 'Filed in Court', 'Dismissed', 'Archived') DEFAULT 'New'");
    echo "Success: 'Forwarded to Clerk' status added to complaints table.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>

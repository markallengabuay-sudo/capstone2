<?php
// Fix the status ENUM to include 'Forwarded to Clerk'
require_once 'includes/db.php';

try {
    // Get current ENUM values
    $stmt = $pdo->query("SHOW COLUMNS FROM complaints LIKE 'status'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    $type = $column['Type'];
    
    echo "Current ENUM: $type\n";
    
    // Update ENUM to include 'Forwarded to Clerk'
    $sql = "ALTER TABLE complaints MODIFY COLUMN status ENUM('New', 'Under Investigation', 'For Resolution', 'Forwarded to Clerk', 'Filed in Court', 'Dismissed', 'Archived') DEFAULT 'New'";
    $pdo->exec($sql);
    
    echo "Successfully updated status ENUM to include 'Forwarded to Clerk'\n";
    
    // Verify the change
    $stmt = $pdo->query("SHOW COLUMNS FROM complaints LIKE 'status'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "New ENUM: " . $column['Type'] . "\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

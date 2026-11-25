<?php
// Add statement field to witnesses table
require_once 'includes/db.php';

echo "Adding statement field to witnesses table...\n";

try {
    // Check if statement column already exists
    $stmt = $pdo->query("SHOW COLUMNS FROM witnesses LIKE 'statement'");
    $columnExists = $stmt->fetch();
    
    if (!$columnExists) {
        $stmt = $pdo->query("ALTER TABLE witnesses ADD COLUMN statement TEXT AFTER contact_number");
        echo "Statement field added successfully!\n";
    } else {
        echo "Statement field already exists.\n";
    }
    
    // Show updated table structure
    echo "\nUpdated witnesses table structure:\n";
    $stmt = $pdo->query("DESCRIBE witnesses");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo "- {$col['Field']} ({$col['Type']})\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

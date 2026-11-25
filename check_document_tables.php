<?php
// Check for document-related tables
$host = 'localhost';
$dbname = 'fiscal_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check all tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "All tables in database:\n";
    foreach ($tables as $table) {
        echo "- $table\n";
    }
    
    // Check if there's a documents table
    if (in_array('documents', $tables)) {
        echo "\nDocuments table exists.\n";
        $stmt = $pdo->query("DESCRIBE documents");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Documents table structure:\n";
        foreach ($columns as $col) {
            echo "- {$col['Field']} ({$col['Type']})\n";
        }
    } else {
        echo "\nDocuments table does NOT exist.\n";
    }
    
    // Check complaints table for arraignment date
    $stmt = $pdo->query("DESCRIBE complaints");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nChecking complaints table for arraignment-related fields:\n";
    foreach ($columns as $col) {
        if (stripos($col['Field'], 'arraign') !== false) {
            echo "- {$col['Field']} ({$col['Type']})\n";
        }
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

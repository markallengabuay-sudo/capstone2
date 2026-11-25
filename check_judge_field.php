<?php
// Check if there's a judge field in complaints table
$host = 'localhost';
$dbname = 'fiscal_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check complaints table structure
    $stmt = $pdo->query("DESCRIBE complaints");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Complaints table columns:\n";
    foreach ($columns as $col) {
        echo "- {$col['Field']} ({$col['Type']})\n";
    }
    
    // Check if there's a branches table
    $stmt = $pdo->query("SHOW TABLES LIKE 'branches'");
    $table = $stmt->fetch();
    
    if ($table) {
        echo "\nBranches table exists.\n";
        $stmt = $pdo->query("DESCRIBE branches");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Branches table columns:\n";
        foreach ($columns as $col) {
            echo "- {$col['Field']} ({$col['Type']})\n";
        }
    } else {
        echo "\nBranches table does NOT exist.\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

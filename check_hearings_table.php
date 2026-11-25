<?php
// Check if hearings table exists
$host = 'localhost';
$dbname = 'fiscal_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if hearings table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'hearings'");
    $table = $stmt->fetch();
    
    if ($table) {
        echo "Hearings table exists.\n\n";
        
        // Show table structure
        $stmt = $pdo->query("DESCRIBE hearings");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "Table structure:\n";
        foreach ($columns as $col) {
            echo "- {$col['Field']} ({$col['Type']})\n";
        }
        
        // Check if there are any hearings
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM hearings");
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "\nTotal hearings in database: {$count['count']}\n";
        
    } else {
        echo "Hearings table does NOT exist.\n";
        echo "The schedule hearing functionality is NOT working - it needs a database table.\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

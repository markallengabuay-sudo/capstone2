<?php
// Check case_documents table structure
$host = 'localhost';
$dbname = 'fiscal_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query("DESCRIBE case_documents");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "case_documents table structure:\n";
    foreach ($columns as $col) {
        echo "- {$col['Field']} ({$col['Type']})\n";
    }
    
    // Check if there are any documents
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM case_documents");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "\nTotal documents in database: {$count['count']}\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

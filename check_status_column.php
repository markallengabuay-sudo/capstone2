<?php
// Check the status column definition
$host = 'localhost';
$dbname = 'fiscal_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query("SHOW COLUMNS FROM complaints LIKE 'status'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Status column definition:\n";
    print_r($column);
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

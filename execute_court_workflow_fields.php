<?php
// Execute SQL to add court workflow fields to complaints table
$host = 'localhost';
$dbname = 'fiscal_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Read the SQL file
    $sql = file_get_contents('add_court_workflow_fields.sql');
    
    // Execute the SQL
    $pdo->exec($sql);
    
    echo "Successfully added court workflow fields to complaints table.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

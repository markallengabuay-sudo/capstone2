<?php
// Script to update bail workflow database schema
error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$username = 'root';
$password = '';
$database = 'fiscal_system';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$database", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to database successfully.<br>";
    
    // Read and execute the SQL file
    $sqlFile = 'add_bail_workflow_fields.sql';
    if (file_exists($sqlFile)) {
        $sql = file_get_contents($sqlFile);
        
        // Split by semicolon to execute each statement
        $statements = explode(';', $sql);
        
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                try {
                    $pdo->exec($statement);
                    echo "Executed: " . substr($statement, 0, 50) . "...<br>";
                } catch (PDOException $e) {
                    echo "Error: " . $e->getMessage() . "<br>";
                }
            }
        }
        
        echo "<br>Bail workflow database schema updated successfully!";
    } else {
        echo "SQL file not found: $sqlFile";
    }
    
} catch (PDOException $e) {
    echo "Database connection failed: " . $e->getMessage();
}
?>

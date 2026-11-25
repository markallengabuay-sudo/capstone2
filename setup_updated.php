<?php
// Setup script to create/update fiscal_system database with new schema

// Database configuration
$host = 'localhost';
$username = 'root';
$password = '';

// Connect to MySQL server
$conn = new mysqli($host, $username, $password);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>Setting up Fiscal System Database...</h2>";

// Drop and recreate database
echo "<p>Dropping existing database (if exists)...</p>";
$conn->query("DROP DATABASE IF EXISTS fiscal_system");

echo "<p>Creating new database...</p>";
$conn->query("CREATE DATABASE fiscal_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->select_db("fiscal_system");

// Read SQL file
$sql_file = file_get_contents('database/fiscal_system_updated.sql');

// Extract just the CREATE TABLE and INSERT statements (skip CREATE DATABASE)
// Split by semicolons and execute each statement
$statements = array_filter(array_map('trim', explode(';', $sql_file)));

$success = 0;
$errors = [];

foreach ($statements as $statement) {
    // Skip CREATE DATABASE and USE statements
    if (stripos($statement, 'CREATE DATABASE') === 0 || stripos($statement, 'USE ') === 0) {
        continue;
    }
    
    if (!empty($statement)) {
        if ($conn->query($statement . ';')) {
            $success++;
        } else {
            $errors[] = $conn->error;
        }
    }
}

if (empty($errors)) {
    echo "<h2 style='color: green;'>✓ Database setup completed successfully!</h2>";
    echo "<p>Executed $success SQL statements.</p>";
    echo "<h3>Sample Login Credentials:</h3>";
    echo "<ul>";
    echo "<li><strong>Admin:</strong> username: <code>admin</code>, password: <code>admin123</code></li>";
    echo "<li><strong>Fiscal:</strong> username: <code>fiscal1</code>, password: <code>fiscal123</code></li>";
    echo "<li><strong>Prosecutor:</strong> username: <code>prosecutor1</code>, password: <code>pros123</code></li>";
    echo "<li><strong>Clerk:</strong> username: <code>clerk1</code>, password: <code>clerk123</code></li>";
    echo "</ul>";
    echo "<a href='index.php' style='padding: 10px 20px; background: #1a73e8; color: white; text-decoration: none; border-radius: 4px;'>Go to Login Page</a>";
} else {
    echo "<h2 style='color: red;'>Some errors occurred:</h2>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>$error</li>";
    }
    echo "</ul>";
}

$conn->close();
?>

<?php
// Setup script to create fiscal_system database

// Database configuration (without database name for initial connection)
$host = 'localhost';
$username = 'root';
$password = '';

// Connect to MySQL server
$conn = new mysqli($host, $username, $password);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Read SQL file
$sql_file = file_get_contents('database/fiscal_system.sql');

// Execute multi-query
if ($conn->multi_query($sql_file)) {
    do {
        // Store result to free up connection
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());
    
    echo "<h2 style='color: green;'>✓ Database setup completed successfully!</h2>";
    echo "<p>The fiscal_system database has been created with sample data.</p>";
    echo "<h3>Sample Login Credentials:</h3>";
    echo "<ul>";
    echo "<li><strong>Admin:</strong> username: admin, password: admin123</li>";
    echo "<li><strong>Fiscal:</strong> username: fiscal1, password: fiscal123</li>";
    echo "<li><strong>Prosecutor:</strong> username: prosecutor1, password: pros123</li>";
    echo "<li><strong>Clerk:</strong> username: clerk1, password: clerk123</li>";
    echo "</ul>";
    echo "<a href='index.php' style='padding: 10px 20px; background: #1a73e8; color: white; text-decoration: none; border-radius: 4px;'>Go to Login Page</a>";
} else {
    echo "<h2 style='color: red;'>Error setting up database:</h2>";
    echo "<p>" . $conn->error . "</p>";
}

$conn->close();
?>

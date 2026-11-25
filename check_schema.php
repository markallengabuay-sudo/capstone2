<?php
require_once 'includes/db.php';

// Get column names from complaints table
$stmt = $pdo->query("DESCRIBE complaints");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Columns in complaints table:\n\n";
foreach ($columns as $col) {
    echo $col['Field'] . " - " . $col['Type'] . "\n";
}

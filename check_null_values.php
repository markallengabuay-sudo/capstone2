<?php
require_once 'includes/db.php';

echo "=== Checking for NULL values in complaints table ===\n\n";

// Get all columns in complaints table
$stmt = $pdo->query("DESCRIBE complaints");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($columns as $col) {
    $field = $col['Field'];
    $type = $col['Type'];
    
    // Count null values for this column
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM complaints WHERE $field IS NULL");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $nullCount = $result['count'];
    
    // Count total rows
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM complaints");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalRows = $result['count'];
    
    if ($nullCount > 0) {
        $percentage = round(($nullCount / $totalRows) * 100, 2);
        echo "$field ($type): $nullCount null values ($percentage% of $totalRows rows)\n";
    }
}

echo "\n=== Checking for NULL values in complainants table ===\n\n";

// Get all columns in complainants table
$stmt = $pdo->query("DESCRIBE complainants");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($columns as $col) {
    $field = $col['Field'];
    $type = $col['Type'];
    
    // Count null values for this column
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM complainants WHERE $field IS NULL");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $nullCount = $result['count'];
    
    // Count total rows
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM complainants");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalRows = $result['count'];
    
    if ($nullCount > 0) {
        $percentage = round(($nullCount / $totalRows) * 100, 2);
        echo "$field ($type): $nullCount null values ($percentage% of $totalRows rows)\n";
    }
}

echo "\n=== Checking for NULL values in respondents table ===\n\n";

// Get all columns in respondents table
$stmt = $pdo->query("DESCRIBE respondents");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($columns as $col) {
    $field = $col['Field'];
    $type = $col['Type'];
    
    // Count null values for this column
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM respondents WHERE $field IS NULL");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $nullCount = $result['count'];
    
    // Count total rows
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM respondents");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalRows = $result['count'];
    
    if ($nullCount > 0) {
        $percentage = round(($nullCount / $totalRows) * 100, 2);
        echo "$field ($type): $nullCount null values ($percentage% of $totalRows rows)\n";
    }
}

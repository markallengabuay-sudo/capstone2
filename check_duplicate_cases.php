<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=fiscal_system', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check for duplicate case numbers
    $stmt = $pdo->query("
        SELECT case_number, COUNT(*) as count 
        FROM complaints 
        GROUP BY case_number 
        HAVING COUNT(*) > 1
    ");
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($duplicates) > 0) {
        echo "Found duplicate case numbers:\n";
        foreach ($duplicates as $dup) {
            echo "- Case {$dup['case_number']}: {$dup['count']} records\n";
        }
    } else {
        echo "No duplicate case numbers found in complaints table.\n";
    }
    
    // Check total cases
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM complaints");
    $total = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "\nTotal cases in database: {$total['total']}\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>

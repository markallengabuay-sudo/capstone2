<?php
// Fix cases that are assigned to branches but still have Archived status
$host = 'localhost';
$dbname = 'fiscal_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Update cases that have court_branch assigned but status is still Archived
    $stmt = $pdo->prepare("UPDATE complaints SET status = 'Filed in Court' WHERE status = 'Archived' AND court_branch IS NOT NULL AND court_branch != 0");
    $result = $stmt->execute();
    
    $affected = $stmt->rowCount();
    
    echo "Updated $affected case(s) from 'Archived' to 'Filed in Court' status.\n";
    
    // Verify the update
    $stmt = $pdo->query("
        SELECT c.id, c.case_number, c.status, c.court_branch as assigned_branch
        FROM complaints c
        WHERE c.court_branch IS NOT NULL AND c.court_branch != 0
        ORDER BY c.id
    ");
    
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nCurrent assigned cases:\n";
    echo str_repeat("-", 60) . "\n";
    foreach ($cases as $case) {
        echo "ID: {$case['id']} | Case: {$case['case_number']} | Status: {$case['status']} | Branch: {$case['assigned_branch']}\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

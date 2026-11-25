<?php
// Check case status in database
$host = 'localhost';
$dbname = 'fiscal_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get all cases with full details
    $stmt = $pdo->query("
        SELECT c.id, c.case_number, c.status, c.court_branch as assigned_branch,
               comp.full_name as complainant, resp.full_name as respondent,
               c.date_filed, c.date_docketed
        FROM complaints c
        LEFT JOIN complainants comp ON c.complainant_id = comp.id
        LEFT JOIN respondents resp ON c.respondent_id = resp.id
        ORDER BY c.date_filed DESC
    ");
    
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "All Cases:\n\n";
    foreach ($cases as $case) {
        echo "ID: {$case['id']}\n";
        echo "Case Number: {$case['case_number']}\n";
        echo "Status: {$case['status']}\n";
        echo "Assigned Branch: " . ($case['assigned_branch'] ?? 'None') . "\n";
        echo "Complainant: " . ($case['complainant'] ?? 'N/A') . "\n";
        echo "Respondent: " . ($case['respondent'] ?? 'N/A') . "\n";
        echo "Date Filed: " . ($case['date_filed'] ?? 'N/A') . "\n";
        echo "Date Docketed: " . ($case['date_docketed'] ?? 'N/A') . "\n";
        echo str_repeat("-", 50) . "\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

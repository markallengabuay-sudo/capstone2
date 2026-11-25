<?php
// Check case status in database
$host = 'localhost';
$dbname = 'fiscal_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get all cases with their status and branch assignment
    $stmt = $pdo->query("
        SELECT c.id, c.case_number, c.status, c.court_branch as assigned_branch,
               comp.full_name as complainant, resp.full_name as respondent,
               c.date_filed, c.date_docketed
        FROM complaints c
        LEFT JOIN complainants comp ON c.complainant_id = comp.id
        LEFT JOIN respondents resp ON c.respondent_id = resp.id
        ORDER BY c.date_filed DESC
        LIMIT 20
    ");
    
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Recent Cases:\n";
    echo str_repeat("-", 100) . "\n";
    printf("%-5s %-15s %-15s %-15s %-20s %-20s\n", "ID", "Case Number", "Status", "Branch", "Complainant", "Respondent");
    echo str_repeat("-", 100) . "\n";
    
    foreach ($cases as $case) {
        printf("%-5s %-15s %-15s %-15s %-20s %-20s\n", 
            $case['id'],
            $case['case_number'],
            $case['status'],
            $case['assigned_branch'] ?? 'None',
            substr($case['complainant'] ?? 'N/A', 0, 20),
            substr($case['respondent'] ?? 'N/A', 0, 20)
        );
    }
    
    echo "\n\nStatus breakdown:\n";
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM complaints GROUP BY status");
    $status_counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($status_counts as $sc) {
        echo "{$sc['status']}: {$sc['count']}\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

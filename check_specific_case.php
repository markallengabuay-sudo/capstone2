<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=fiscal_system', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check specific case 2026-004
    $stmt = $pdo->prepare("
        SELECT c.id, c.case_number, c.court_branch, c.offense, 
               comp.full_name as complainant, comp.id as complainant_id,
               resp.full_name as respondent, resp.id as respondent_id
        FROM complaints c
        LEFT JOIN complainants comp ON c.complainant_id = comp.id
        LEFT JOIN respondents resp ON c.respondent_id = resp.id
        WHERE c.case_number = '2026-004'
    ");
    $stmt->execute();
    $case_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Case 2026-004 details:\n";
    echo "Total records found: " . count($case_details) . "\n\n";
    
    foreach ($case_details as $row) {
        echo "ID: {$row['id']}, Case: {$row['case_number']}, Branch: {$row['court_branch']}\n";
        echo "Complainant: {$row['complainant']} (ID: {$row['complainant_id']})\n";
        echo "Respondent: {$row['respondent']} (ID: {$row['respondent_id']})\n";
        echo "---\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>

<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=fiscal_system', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Test the exact query used in branch/cases.php for branch 29
    $branch_number = 29;
    $stmt = $pdo->prepare("
        SELECT c.id, c.case_number as no, c.offense, c.offense_type, c.status, c.date_filed as date,
               GROUP_CONCAT(DISTINCT comp.full_name) as complainant,
               GROUP_CONCAT(DISTINCT resp.full_name) as respondent
        FROM complaints c
        LEFT JOIN complainants comp ON c.complainant_id = comp.id
        LEFT JOIN respondents resp ON c.respondent_id = resp.id
        WHERE c.court_branch = ?
        GROUP BY c.id
        ORDER BY c.date_filed DESC
    ");
    $stmt->execute([$branch_number]);
    $all_cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Branch 29 cases from SQL query:\n";
    echo "Total cases: " . count($all_cases) . "\n\n";
    
    foreach ($all_cases as $case) {
        echo "ID: {$case['id']}, Case No: {$case['no']}, Status: {$case['status']}\n";
        echo "Complainant: {$case['complainant']}\n";
        echo "Respondent: {$case['respondent']}\n";
        echo "---\n";
    }
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>

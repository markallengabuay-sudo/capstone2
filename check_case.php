<?php
require_once 'includes/db.php';

$case_id = 5;

try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.case_number, c.nps_docket_no, c.status,
               comp.full_name as complainant_name, comp.age as complainant_age, 
               comp.sex as complainant_sex, comp.civil_status as complainant_civil,
               resp.full_name as respondent_name, resp.age as respondent_age,
               resp.sex as respondent_sex, resp.status as respondent_status,
               c.offense, c.law_statute,
               c.date_received, c.time_received, c.date_filed,
               u.full_name as assigned_prosecutor,
               c.incident_date, c.incident_time, c.incident_place,
               c.resolution_date, c.resolution_type,
               c.approved_by, c.case_summary
        FROM complaints c
        LEFT JOIN complainants comp ON c.complainant_id = comp.id
        LEFT JOIN respondents resp ON c.respondent_id = resp.id
        LEFT JOIN users u ON c.assigned_prosecutor_id = u.id
        WHERE c.id = ?
    ");
    $stmt->execute([$case_id]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($case) {
        echo "Case ID $case_id found:\n";
        echo "Case Number: " . $case['case_number'] . "\n";
        echo "Status: " . $case['status'] . "\n";
        echo "Complainant: " . ($case['complainant_name'] ?? 'NULL') . "\n";
        echo "Respondent: " . ($case['respondent_name'] ?? 'NULL') . "\n";
        echo "Offense: " . $case['offense'] . "\n";
    } else {
        echo "Case ID $case_id not found in database\n";
    }
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?>

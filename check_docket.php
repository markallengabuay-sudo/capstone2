<?php
require_once 'includes/db.php';

// Check for cases with 'Archived' status
$stmt = $pdo->query("SELECT id, case_number, nps_docket_no, criminal_case_no, date_docketed, status FROM complaints WHERE status = 'Archived'");
$cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total Archived cases: " . count($cases) . "\n\n";

foreach ($cases as $case) {
    echo "ID: " . $case['id'] . "\n";
    echo "Case Number: " . $case['case_number'] . "\n";
    echo "NPS Docket: " . $case['nps_docket_no'] . "\n";
    echo "Criminal Case No: " . ($case['criminal_case_no'] ?? 'NULL') . "\n";
    echo "Date Docketed: " . ($case['date_docketed'] ?? 'NULL') . "\n";
    echo "Status: " . $case['status'] . "\n";
    echo "-------------------\n";
}

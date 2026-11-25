<?php
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

require_once '../includes/db.php';

$action = isset($_POST['action']) ? $_POST['action'] : '';

try {
    if ($action === 'save_docket') {
        $case_id = isset($_POST['case_id']) ? intval($_POST['case_id']) : 0;
        $criminal_case_no = isset($_POST['criminal_case_no']) ? trim($_POST['criminal_case_no']) : '';
        $date_docketed = isset($_POST['date_docketed']) ? trim($_POST['date_docketed']) : '';

        error_log("Docket save attempt - Case ID: $case_id, Criminal Case No: $criminal_case_no, Date: $date_docketed");

        if (!$case_id || !$date_docketed) {
            error_log("Docket save failed - Missing required fields");
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit();
        }

        // Auto-generate criminal case number if not provided
        if (!$criminal_case_no) {
            $year = date('Y');
            // Get the next sequence number for this year
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM complaints WHERE criminal_case_no LIKE ?");
            $stmt->execute(["Crim-$year-%"]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $sequence = $result['count'] + 1;
            $criminal_case_no = sprintf("Crim-%s-%03d", $year, $sequence);
            error_log("Auto-generated criminal case no: $criminal_case_no");
        }

        // Update the case with docket information - keep status as 'Forwarded to Clerk' for raffle
        $stmt = $pdo->prepare("UPDATE complaints SET criminal_case_no = ?, date_docketed = ? WHERE id = ?");
        $result = $stmt->execute([$criminal_case_no, $date_docketed, $case_id]);

        error_log("Docket save result: " . ($result ? 'Success' : 'Failed'));

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Case docketed successfully', 'criminal_case_no' => $criminal_case_no]);

    } elseif ($action === 'assign_branch') {
        $case_id = isset($_POST['case_id']) ? intval($_POST['case_id']) : 0;
        $branch = isset($_POST['branch']) ? trim($_POST['branch']) : '';

        if (!$case_id || !$branch) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            exit();
        }

        // Update the case with assigned branch and change status to 'Assigned to Branch'
        $stmt = $pdo->prepare("UPDATE complaints SET court_branch = ?, status = 'Assigned to Branch' WHERE id = ?");
        $stmt->execute([$branch, $case_id]);

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Case assigned to branch successfully']);

    } else {
        error_log("Invalid action: $action");
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

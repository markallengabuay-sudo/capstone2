<?php
session_start();

if (!isset($_SESSION['logged_in'])) {
    header("Location: ../index.php");
    exit();
}

// Include database connection
require_once '../includes/db.php';

// Get case_id from URL - only show cases that are "Filed in Court"
$case_id = isset($_GET['case_id']) ? intval($_GET['case_id']) : 0;

// Debug logging
error_log("Court Forwarding - case_id from URL: " . $case_id);

// Get cases from database with "Filed in Court" or "Forwarded to Clerk" status
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.case_number, c.nps_docket_no as nps_docket,
               comp.full_name as complainant_name, comp.age as complainant_age, 
               comp.sex as complainant_sex, comp.civil_status as complainant_civil,
               resp.full_name as respondent_name, resp.age as respondent_age,
               resp.sex as respondent_sex, resp.status as respondent_status,
               c.offense, c.law_statute as law_violated,
               c.date_received, c.time_received, c.date_filed,
               u.full_name as assigned_prosecutor,
               c.incident_date, c.incident_time, c.incident_place,
               c.resolution_date, c.resolution_type as resolution,
               c.case_summary,
               c.status,
               c.criminal_case_no,
               c.date_docketed,
               c.court_branch
        FROM complaints c
        LEFT JOIN complainants comp ON c.complainant_id = comp.id
        LEFT JOIN respondents resp ON c.respondent_id = resp.id
        LEFT JOIN users u ON c.assigned_prosecutor_id = u.id
        WHERE c.status IN ('Filed in Court', 'Forwarded to Clerk')
        ORDER BY c.date_filed DESC
    ");
    $stmt->execute();
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Court Forwarding - Found " . count($cases) . " cases with 'Filed in Court' status");
    
    // Convert to keyed array by id
    $cases_by_id = [];
    foreach ($cases as $case) {
        $cases_by_id[$case['id']] = $case;
    }
    
    // If case_id is provided but case is not in the list, show error message
    if ($case_id > 0 && !isset($cases_by_id[$case_id])) {
        error_log("Court Forwarding - Case ID $case_id not in Filed in Court list, trying direct query");
        $stmt = $pdo->prepare("
            SELECT c.id, c.case_number, c.nps_docket_no as nps_docket,
                   comp.full_name as complainant_name, comp.age as complainant_age, 
                   comp.sex as complainant_sex, comp.civil_status as complainant_civil,
                   resp.full_name as respondent_name, resp.age as respondent_age,
                   resp.sex as respondent_sex, resp.status as respondent_status,
                   c.offense, c.law_statute as law_violated,
                   c.date_received, c.time_received, c.date_filed,
                   u.full_name as assigned_prosecutor,
                   c.incident_date, c.incident_time, c.incident_place,
                   c.resolution_date, c.resolution_type as resolution,
                   c.case_summary,
                   c.status,
                   c.criminal_case_no,
                   c.date_docketed,
                   c.court_branch
            FROM complaints c
            LEFT JOIN complainants comp ON c.complainant_id = comp.id
            LEFT JOIN respondents resp ON c.respondent_id = resp.id
            LEFT JOIN users u ON c.assigned_prosecutor_id = u.id
            WHERE c.id = ?
        ");
        $stmt->execute([$case_id]);
        $specific_case = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($specific_case) {
            error_log("Court Forwarding - Found case directly: " . $specific_case['case_number'] . " with status: " . $specific_case['status']);
            // Only add if status is appropriate for court forwarding
            if (in_array($specific_case['status'], ['Filed in Court', 'Forwarded to Clerk'])) {
                $cases_by_id[$case_id] = $specific_case;
            } else {
                // Case exists but has wrong status - set error message
                $case_status_error = "Case '{$specific_case['case_number']}' has status '{$specific_case['status']}'. Only cases with 'Filed in Court' or 'Forwarded to Clerk' status can be forwarded to court. Please complete the investigation and resolution stages first.";
                error_log("Court Forwarding - Case has wrong status: " . $specific_case['status']);
            }
        } else {
            error_log("Court Forwarding - Case ID $case_id not found in database");
            $case_status_error = "Case not found in database.";
        }
    }
} catch (PDOException $e) {
    error_log("Court Forwarding - Database error: " . $e->getMessage());
    $cases_by_id = [];
}

$case = isset($cases_by_id[$case_id]) ? $cases_by_id[$case_id] : (!empty($cases_by_id) ? reset($cases_by_id) : null);

// If no case found, show empty state instead of redirecting
if (!$case) {
    $case = null;
}

// Get fiscal settings for document preview
$fiscal_settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM fiscal_settings");
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($result as $row) {
        $fiscal_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (PDOException $e) {
    $fiscal_settings = [];
}

// Get actual documents for the selected case
$case_documents = [];
if ($case) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, document_name, document_type, file_path, uploaded_at
            FROM case_documents
            WHERE complaint_id = ?
            ORDER BY uploaded_at DESC
        ");
        $stmt->execute([$case['id']]);
        $case_documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create a map of document types to their existence
        $document_types = [];
        foreach ($case_documents as $doc) {
            $document_types[$doc['document_type']] = $doc;
        }
    } catch (PDOException $e) {
        error_log("Error fetching case documents: " . $e->getMessage());
        $document_types = [];
    }
} else {
    $document_types = [];
}

// Format displays
if ($case) {
    $comp_display = $case['complainant_name'];
    if ($case['complainant_age']) $comp_display .= ', ' . $case['complainant_age'];
    if ($case['complainant_sex']) $comp_display .= '/' . $case['complainant_sex'];

    $resp_display = $case['respondent_name'];
    if ($case['respondent_age']) $resp_display .= ', ' . $case['respondent_age'];
    if ($case['respondent_sex']) $resp_display .= '/' . $case['respondent_sex'];
    $resp_display .= ', ' . $case['respondent_status'];
} else {
    $comp_display = '';
    $resp_display = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Court Forwarding - Office of the Prosecutor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        :root {
            --primary-blue: #1a73e8;
            --danger-red: #d93025;
            --success-green: #0f9d58;
            --warning-yellow: #f4b400;
            --purple: #9c27b0;
            --dark-navy: #202124;
            --light-gray: #f8f9fa;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--light-gray);
        }

        .app-container {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            padding: 20px;
        }

        .top-header {
            background: white;
            padding: 15px 20px;
            border-radius: 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .forwarding-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }

        .forwarding-card h5 {
            margin-bottom: 20px;
            color: var(--dark-navy);
            font-weight: 600;
            border-left: 4px solid var(--success-green);
            padding-left: 15px;
        }

        .info-preview {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 20px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
            max-height: 500px;
            overflow-y: auto;
        }

        .step-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 20px;
            border: 1px solid #eee;
            border-radius: 8px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }

        .step-item.completed {
            background: #f1f8e9;
            border-color: var(--success-green);
        }

        .step-item.pending {
            background: white;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            flex-shrink: 0;
        }

        .step-item.completed .step-number {
            background: var(--success-green);
            color: white;
        }

        .step-item.pending .step-number {
            background: #ddd;
            color: #666;
        }

        .step-content {
            flex: 1;
        }

        .step-title {
            font-weight: 600;
            color: var(--dark-navy);
            margin-bottom: 5px;
        }

        .step-desc {
            color: #666;
            font-size: 14px;
        }

        .btn-generate {
            background: var(--primary-blue);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-generate:hover {
            background: #1557b0;
        }

        .btn-transmit {
            background: var(--success-green);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-transmit:hover {
            background: #0d8a4a;
        }

        .document-checklist {
            list-style: none;
            padding: 0;
        }

        .document-checklist li {
            padding: 12px;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .document-checklist li:last-child {
            border-bottom: none;
        }

        .check-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .check-icon.completed {
            background: var(--success-green);
            color: white;
        }

        .check-icon.pending {
            background: #ddd;
            color: #666;
        }

        .court-selection {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .status-badge {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-filed { background: #e8f5e9; color: #2e7d32; }
        .status-pending { background: #fff3e0; color: #e65100; }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/navigation_fiscal.php'; ?>

        <div class="main-content">
            <div class="top-header">
                <div>
                    <a href="cases.php" class="btn btn-sm btn-outline-secondary mb-2">
                        <i class="fas fa-arrow-left"></i> Back to Case List
                    </a>
                    <h2>Court Forwarding</h2>
                    <p class="text-muted mb-0">Generate Criminal Information and transmit case to court</p>
                </div>
                <div class="text-end">
                    <select class="form-select form-select-sm mb-2" style="width: 250px;" onchange="if(this.value) window.location.href='court_forwarding.php?case_id='+this.value;">
                        <option value="">Select Case to File...</option>
                        <?php foreach ($cases as $id => $c): ?>
                        <option value="<?php echo $id; ?>" <?php echo $id == $case_id ? 'selected' : ''; ?>><?php echo $c['case_number']; ?> - <?php echo $c['offense']; ?> (<?php echo $c['complainant_name']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <br>
                    <?php if ($case && $case['criminal_case_no']): ?>
                        <span class="status-badge status-filed">Assigned to Court</span>
                    <?php elseif ($case): ?>
                        <span class="status-badge status-pending">Ready for Filing</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isset($case_status_error)): ?>
            <!-- Status Error -->
            <div class="forwarding-card" style="border-left: 4px solid var(--danger-red);">
                <h5><i class="fas fa-exclamation-triangle" style="color: var(--danger-red);"></i> Case Not Ready for Court Forwarding</h5>
                <div class="alert alert-danger">
                    <i class="fas fa-info-circle"></i> 
                    <?php echo htmlspecialchars($case_status_error); ?>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <p class="text-muted mb-2"><strong>Required Workflow:</strong></p>
                        <ol class="mb-3">
                            <li>Case Intake (Status: New)</li>
                            <li>Investigation & Review (Status: Under Investigation)</li>
                            <li>Resolution (Status: Filed in Court - after resolution approval)</li>
                            <li>Court Forwarding (Status: Forwarded to Clerk)</li>
                        </ol>
                        <a href="investigation.php" class="btn btn-primary">
                            <i class="fas fa-search"></i> Go to Investigation & Review
                        </a>
                    </div>
                </div>
            </div>
            <?php elseif ($case): ?>
            <!-- Case Summary -->
            <div class="forwarding-card">
                <h5>Case Summary for Filing | NPS Docket: <?php echo $case['nps_docket']; ?></h5>
                <div class="row">
                    <div class="col-md-4">
                        <p class="mb-1"><strong>I.S. No.:</strong> <?php echo $case['case_number']; ?></p>
                        <p class="mb-1"><strong>NPS Docket:</strong> <?php echo $case['nps_docket']; ?></p>
                        <p class="mb-1"><strong>Date Received:</strong> <?php echo date('M d, Y', strtotime($case['date_received'])); ?> @ <?php echo $case['time_received']; ?></p>
                        <?php if ($case['criminal_case_no']): ?>
                            <p class="mb-1"><strong>Criminal Case No.:</strong> <span class="badge bg-success"><?php echo htmlspecialchars($case['criminal_case_no']); ?></span></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <p class="mb-1"><strong>Complainant:</strong> <?php echo $comp_display; ?></p>
                        <p class="mb-1"><strong>Accused:</strong> <?php echo $resp_display; ?></p>
                        <p class="mb-1"><strong>Offense:</strong> <?php echo $case['offense']; ?></p>
                    </div>
                    <div class="col-md-4">
                        <p class="mb-1"><strong>Law Violated:</strong> <?php echo $case['law_violated']; ?></p>
                        <p class="mb-1"><strong>Incident:</strong> <?php echo $case['incident_date']; ?> @ <?php echo $case['incident_time']; ?></p>
                        <p class="mb-1"><strong>Place:</strong> <?php echo $case['incident_place']; ?></p>
                    </div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Resolution Date:</strong> <?php echo $case['resolution_date'] ? date('M d, Y', strtotime($case['resolution_date'])) : 'Pending'; ?></p>
                        <p class="mb-1"><strong>Resolution:</strong> <?php echo $case['resolution'] ?? 'Pending'; ?></p>
                        <?php if ($case['date_docketed']): ?>
                            <p class="mb-1"><strong>Date Docketed:</strong> <?php echo date('M d, Y', strtotime($case['date_docketed'])); ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Investigating Prosecutor:</strong> <?php echo $case['assigned_prosecutor'] ?? 'N/A'; ?></p>
                        <p class="mb-1"><strong>Status:</strong> <span class="badge <?php echo $case['criminal_case_no'] ? 'bg-success' : 'bg-warning'; ?>"><?php echo $case['status']; ?></span></p>
                    </div>
                </div>
            </div>

            <!-- Filing Information -->
            <div class="forwarding-card">
                <h5>Filing Information</h5>
                <div class="court-selection">
                    <div class="row">
                        <div class="col-md-12">
                            <label class="form-label">Date of Filing</label>
                            <input type="date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Document Checklist -->
            <div class="forwarding-card">
                <h5>Filing Documents Checklist</h5>
                <ul class="document-checklist">
                    <li>
                        <span class="check-icon <?php echo isset($document_types['resolution']) ? 'completed' : 'pending'; ?>">
                            <i class="fas fa-<?php echo isset($document_types['resolution']) ? 'check' : 'clock'; ?>"></i>
                        </span>
                        <span>Resolution (approved)</span>
                        <?php if (isset($document_types['resolution'])): ?>
                            <button class="btn btn-sm btn-outline-primary ms-auto" data-bs-toggle="modal" data-bs-target="#docModal" onclick="viewActualDocument(<?php echo $document_types['resolution']['id']; ?>, '<?php echo addslashes($document_types['resolution']['document_name']); ?>')"><i class="fas fa-eye"></i> View</button>
                        <?php else: ?>
                            <span class="text-muted ms-auto small">Not submitted</span>
                        <?php endif; ?>
                    </li>
                    <li>
                        <span class="check-icon <?php echo isset($document_types['information']) ? 'completed' : 'pending'; ?>">
                            <i class="fas fa-<?php echo isset($document_types['information']) ? 'check' : 'clock'; ?>"></i>
                        </span>
                        <span>Criminal Information</span>
                        <?php if (isset($document_types['information'])): ?>
                            <button class="btn btn-sm btn-outline-primary ms-auto" data-bs-toggle="modal" data-bs-target="#docModal" onclick="viewActualDocument(<?php echo $document_types['information']['id']; ?>, '<?php echo addslashes($document_types['information']['document_name']); ?>')"><i class="fas fa-eye"></i> View</button>
                        <?php else: ?>
                            <span class="text-muted ms-auto small">Not submitted</span>
                        <?php endif; ?>
                    </li>
                    <li>
                        <span class="check-icon <?php echo isset($document_types['complaint_affidavit']) ? 'completed' : 'pending'; ?>">
                            <i class="fas fa-<?php echo isset($document_types['complaint_affidavit']) ? 'check' : 'clock'; ?>"></i>
                        </span>
                        <span>Complaint-Affidavit</span>
                        <?php if (isset($document_types['complaint_affidavit'])): ?>
                            <button class="btn btn-sm btn-outline-primary ms-auto" data-bs-toggle="modal" data-bs-target="#docModal" onclick="viewActualDocument(<?php echo $document_types['complaint_affidavit']['id']; ?>, '<?php echo addslashes($document_types['complaint_affidavit']['document_name']); ?>')"><i class="fas fa-eye"></i> View</button>
                        <?php else: ?>
                            <span class="text-muted ms-auto small">Not submitted</span>
                        <?php endif; ?>
                    </li>
                    <li>
                        <span class="check-icon <?php echo isset($document_types['counter_affidavit']) ? 'completed' : 'pending'; ?>">
                            <i class="fas fa-<?php echo isset($document_types['counter_affidavit']) ? 'check' : 'clock'; ?>"></i>
                        </span>
                        <span>Counter-Affidavit (if any)</span>
                        <?php if (isset($document_types['counter_affidavit'])): ?>
                            <button class="btn btn-sm btn-outline-primary ms-auto" data-bs-toggle="modal" data-bs-target="#docModal" onclick="viewActualDocument(<?php echo $document_types['counter_affidavit']['id']; ?>, '<?php echo addslashes($document_types['counter_affidavit']['document_name']); ?>')"><i class="fas fa-eye"></i> View</button>
                        <?php else: ?>
                            <span class="text-muted ms-auto small">Not submitted</span>
                        <?php endif; ?>
                    </li>
                    <li>
                        <span class="check-icon <?php echo isset($document_types['evidence']) ? 'completed' : 'pending'; ?>">
                            <i class="fas fa-<?php echo isset($document_types['evidence']) ? 'check' : 'clock'; ?>"></i>
                        </span>
                        <span>Evidence (CCTV, Witness statements)</span>
                        <?php if (isset($document_types['evidence'])): ?>
                            <button class="btn btn-sm btn-outline-primary ms-auto" data-bs-toggle="modal" data-bs-target="#docModal" onclick="viewActualDocument(<?php echo $document_types['evidence']['id']; ?>, '<?php echo addslashes($document_types['evidence']['document_name']); ?>')"><i class="fas fa-eye"></i> View</button>
                        <?php else: ?>
                            <span class="text-muted ms-auto small">Not submitted</span>
                        <?php endif; ?>
                    </li>
                    <li>
                        <span class="check-icon <?php echo isset($document_types['endorsement']) ? 'completed' : 'pending'; ?>">
                            <i class="fas fa-<?php echo isset($document_types['endorsement']) ? 'check' : 'clock'; ?>"></i>
                        </span>
                        <span>Endorsement Letter to Court</span>
                        <?php if (isset($document_types['endorsement'])): ?>
                            <button class="btn btn-sm btn-outline-primary ms-auto" data-bs-toggle="modal" data-bs-target="#docModal" onclick="viewActualDocument(<?php echo $document_types['endorsement']['id']; ?>, '<?php echo addslashes($document_types['endorsement']['document_name']); ?>')"><i class="fas fa-eye"></i> View</button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-generate ms-auto" onclick="generateEndorsement()">Generate</button>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>

            <!-- Document Viewer Modal -->
            <div class="modal fade" id="docModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="docTitle">Document Viewer</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" id="docContent">
                            <!-- Content loaded dynamically -->
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-primary" onclick="printDocument()"><i class="fas fa-print"></i> Print</button>
                            <button type="button" class="btn btn-outline-success" onclick="downloadDocumentPDF()"><i class="fas fa-download"></i> Download PDF</button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Criminal Information Preview -->
            <div class="forwarding-card">
                <h5>Criminal Information Document Preview</h5>
                <div class="info-preview">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <strong>REPUBLIC OF THE PHILIPPINES</strong><br>
                        <?php echo htmlspecialchars($fiscal_settings['court_name'] ?? 'REGIONAL TRIAL COURT'); ?><br>
                        <?php echo htmlspecialchars($fiscal_settings['court_address'] ?? 'Catbalogan City'); ?><br><br>

                        <strong>CRIMINAL INFORMATION</strong><br><br>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <strong>THE PEOPLE OF THE PHILIPPINES,</strong><br>
                        Plaintiff,<br><br>

                        - versus -<br><br>

                        <strong><?php echo htmlspecialchars($case['respondent_name'] ?? 'RESPONDENT NAME'); ?></strong><br>
                        Accused.<br><br>

                        <strong>x--------------------------------------x</strong><br><br>
                    </div>

                    <div style="text-align: right; margin-bottom: 20px;">
                        Criminal Case No.: <u>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</u><br><br>
                    </div>

                    <div style="text-align: center; margin-bottom: 20px;">
                        <strong>FOR: <?php echo strtoupper(htmlspecialchars($case['offense'] ?? 'OFFENSE')); ?></strong><br><br>
                    </div>

                    <div style="text-indent: 40px; margin-bottom: 15px;">
                        The undersigned City Prosecutor, upon preliminary investigation conducted and sworn statement of witnesses, accuses <?php echo htmlspecialchars($case['respondent_name'] ?? 'RESPONDENT NAME'); ?> of the crime of <?php echo htmlspecialchars($case['offense'] ?? 'OFFENSE'); ?>, defined and penalized under <?php echo htmlspecialchars($case['law_violated'] ?? 'LAW'); ?>, committed as follows:
                    </div>

                    <div style="text-indent: 40px; margin-bottom: 15px;">
                        That on or about <?php echo $case['incident_date'] ? date('F d, Y', strtotime($case['incident_date'])) : 'DATE'; ?>, in <?php echo htmlspecialchars($case['incident_place'] ?? 'PLACE'); ?>, Philippines, and within the jurisdiction of this Honorable Court, the above-named accused, did then and there willfully, unlawfully and feloniously <?php echo htmlspecialchars($case['case_summary'] ?? 'case details'); ?>, thereby committing the crime of <?php echo htmlspecialchars($case['offense'] ?? 'OFFENSE'); ?>.
                    </div>

                    <div style="text-indent: 40px; margin-bottom: 30px;">
                        Contrary to law.
                    </div>

                    <div style="margin-bottom: 30px;">
                        <strong>Subscribed and sworn to</strong><br>
                        before me this <?php echo date('F d, Y'); ?> at <?php echo htmlspecialchars($fiscal_settings['court_address'] ?? 'Catbalogan City'); ?>.<br><br>
                    </div>

                    <div style="display: flex; justify-content: space-between; margin-bottom: 30px;">
                        <div style="width: 45%;">
                            <strong>CITY PROSECUTOR</strong><br>
                            <?php echo htmlspecialchars($fiscal_settings['court_address'] ?? 'Catbalogan City'); ?><br><br>

                            <strong>By:</strong><br><br>

                            <u>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</u><br>
                            <strong><?php echo htmlspecialchars($fiscal_settings['city_prosecutor'] ?? ($case['assigned_prosecutor'] ?? 'CITY PROSECUTOR')); ?></strong><br>
                            City Prosecutor
                        </div>

                        <div style="width: 45%;">
                            <u>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</u><br>
                            <strong><?php echo htmlspecialchars($fiscal_settings['administrating_officer'] ?? 'ADMINISTRATING OFFICER'); ?></strong><br>
                            Administrating Officer
                        </div>
                    </div>

                    <div style="font-size: 11px; color: #666;">
                        <strong>Doc. No. _____</strong><br>
                        <strong>Page No. _____</strong><br>
                        <strong>Book No. _____</strong><br>
                        <strong>Series of <?php echo date('Y'); ?>.</strong>
                    </div>
                </div>

                <div class="mt-3 text-end">
                    <button class="btn btn-generate" onclick="generateInfo()">
                        <i class="fas fa-file-pdf"></i> Generate Final Document
                    </button>
                </div>
            </div>

            <!-- Forward to Clerk -->
            <div class="forwarding-card" style="border-left: 4px solid #9c27b0;">
                <?php if (!empty($case['court_branch'])): ?>
                    <!-- Case assigned to court branch - already filed -->
                    <h5><i class="fas fa-check-circle" style="color: var(--success-green);"></i> Case Filed in Court</h5>
                    <div class="row">
                        <div class="col-md-8">
                            <p class="text-muted mb-2">This case has been assigned to a court branch:</p>
                            <ul class="mb-3">
                                <?php if (!empty($case['criminal_case_no'])): ?>
                                    <li><strong>Criminal Case No.:</strong> <?php echo htmlspecialchars($case['criminal_case_no']); ?></li>
                                <?php endif; ?>
                                <li><strong>Assigned Branch:</strong> Branch <?php echo htmlspecialchars($case['court_branch']); ?></li>
                                <li><strong>Date Docketed:</strong> <?php echo $case['date_docketed'] ? date('M d, Y', strtotime($case['date_docketed'])) : 'N/A'; ?></li>
                                <li><strong>Status:</strong> Assigned to Branch (via Clerk Raffle)</li>
                            </ul>
                            <div class="alert alert-success">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Note:</strong> This case is now under the jurisdiction of the assigned court branch.
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <button class="btn btn-outline-success" onclick="viewFilingDetails()">
                                <i class="fas fa-eye"></i> View Filing Details
                            </button>
                        </div>
                    </div>
                <?php elseif ($case['status'] === 'Forwarded to Clerk'): ?>
                    <!-- Case forwarded to clerk but not yet assigned -->
                    <h5><i class="fas fa-clock" style="color: #ff9800;"></i> Case Forwarded to Clerk</h5>
                    <div class="row">
                        <div class="col-md-8">
                            <p class="text-muted mb-2">This case has been forwarded to the Clerk of Court and is awaiting processing:</p>
                            <ul class="mb-3">
                                <li><strong>Status:</strong> Forwarded to Clerk</li>
                                <li><strong>Forwarded Date:</strong> <?php echo $case['date_filed'] ? date('M d, Y', strtotime($case['date_filed'])) : date('M d, Y'); ?></li>
                                <li><strong>Next Steps:</strong> Recording in Court Docket, Assignment of Criminal Case Number, Raffle to Branch</li>
                            </ul>
                            <div class="alert alert-warning">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Note:</strong> This case is now with the Clerk of Court. The Fiscal Office can view status but not modify the case.
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <button class="btn btn-outline-success" onclick="viewFilingDetails()">
                                <i class="fas fa-eye"></i> View Filing Details
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Case not yet assigned - show forward to clerk -->
                    <?php if ($case['status'] === 'Filed in Court'): ?>
                    <h5><i class="fas fa-paper-plane" style="color: #9c27b0;"></i> Forward to Clerk of Court</h5>
                    <div class="row">
                        <div class="col-md-8">
                            <p class="text-muted mb-2">Upon completion of all documents, forward the case to the Clerk of Court for:</p>
                            <ul class="mb-3">
                                <li>Recording in Court Docket</li>
                                <li>Assignment of Criminal Case Number</li>
                                <li>Raffle to appropriate Branch (27, 28, 29)</li>
                            </ul>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Note:</strong> After forwarding, this case will be tracked by the Clerk of Court system. The Fiscal Office can view status but not modify the case.
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="mb-3">
                                <label class="form-label">Endorsement Date</label>
                                <input type="date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <button class="btn btn-generate" style="background: #9c27b0; width: 100%;" onclick="forwardToClerk()">
                                <i class="fas fa-share-square"></i> Forward to Clerk
                            </button>
                        </div>
                    </div>
                    <?php else: ?>
                    <h5><i class="fas fa-lock" style="color: #666;"></i> Case Not Ready for Forwarding</h5>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle"></i> 
                        <strong>Note:</strong> This case cannot be forwarded. Current status: <?php echo htmlspecialchars($case['status']); ?>. Only cases with 'Filed in Court' status can be forwarded to the Clerk of Court.
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <!-- Empty State -->
            <div class="forwarding-card" style="text-align: center; padding: 60px 20px;">
                <i class="fas fa-folder-open" style="font-size: 64px; color: #ccc; margin-bottom: 20px;"></i>
                <h4>No Cases Ready for Court Forwarding</h4>
                <p class="text-muted">There are currently no cases with "Filed in Court" status. Cases will appear here after they have been approved in the Resolution stage.</p>
                <p class="text-muted small">Debug: Case ID from URL: <?php echo $case_id; ?></p>
                <a href="resolution.php" class="btn btn-primary mt-3">
                    <i class="fas fa-arrow-left me-2"></i>Go to Resolution
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function generateEndorsement() {
            const caseId = <?php echo $case_id; ?>;
            if (!caseId) {
                alert('No case selected');
                return;
            }
            window.open('generate_endorsement.php?case_id=' + caseId, 'endorsement_tab');
        }

        function generateInfo() {
            const caseId = <?php echo $case_id; ?>;
            if (!caseId) {
                alert('No case selected');
                return;
            }
            window.open('generate_criminal_info.php?case_id=' + caseId, 'criminal_info_tab');
        }

        function forwardToClerk() {
            const caseId = <?php echo $case_id; ?>;
            if (!caseId) {
                alert('No case selected');
                return;
            }
            
            if (confirm('Forward this case to Clerk of Court? The case will be marked as "Forwarded" and can no longer be modified by Fiscal.')) {
                fetch('api_case_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=forward_to_clerk&case_id=' + caseId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Case forwarded to Clerk of Court successfully!\n\nNext steps:\n- Clerk will record in Docket\n- Assign Criminal Case Number\n- Raffle to Branch 27/28/29');
                        window.location.href = 'cases.php';
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error forwarding to clerk: ' + error);
                });
            }
        }

        function viewFilingDetails() {
            const caseId = <?php echo $case_id; ?>;
            if (!caseId) {
                alert('No case selected');
                return;
            }
            
            const modalTitle = document.getElementById('docTitle');
            const modalContent = document.getElementById('docContent');
            
            modalTitle.textContent = 'Filing Details - Case <?php echo $case['case_number']; ?>';
            modalContent.innerHTML = `
                <div style="font-family: 'Times New Roman', serif; line-height: 1.6; padding: 20px;">
                    <h5 class="mb-4">Court Filing Information</h5>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <p><strong>I.S. No.:</strong> <?php echo $case['case_number']; ?></p>
                            <p><strong>NPS Docket:</strong> <?php echo $case['nps_docket']; ?></p>
                            <p><strong>Criminal Case No.:</strong> <span class="badge bg-success"><?php echo htmlspecialchars($case['criminal_case_no']); ?></span></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Date Docketed:</strong> <?php echo $case['date_docketed'] ? date('F d, Y', strtotime($case['date_docketed'])) : 'N/A'; ?></p>
                            <p><strong>Status:</strong> <?php echo $case['status']; ?></p>
                            <p><strong>Assigned Branch:</strong> <?php echo $case['criminal_case_no'] ? 'Branch ' . substr($case['criminal_case_no'], -2) : 'Pending'; ?></p>
                        </div>
                    </div>
                    <hr>
                    <h6 class="mb-3">Case Details</h6>
                    <p><strong>Complainant:</strong> <?php echo addslashes($case['complainant_name']); ?></p>
                    <p><strong>Accused:</strong> <?php echo addslashes($case['respondent_name']); ?></p>
                    <p><strong>Offense:</strong> <?php echo $case['offense']; ?></p>
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle"></i>
                        This case has been successfully filed and assigned to a court branch. The case is now under the jurisdiction of the assigned branch.
                    </div>
                </div>
            `;
            
            const modal = new bootstrap.Modal(document.getElementById('docModal'));
            modal.show();
        }

        function viewActualDocument(documentId, documentName) {
            const title = document.getElementById('docTitle');
            const content = document.getElementById('docContent');
            
            title.textContent = documentName;
            content.innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">Loading document...</p></div>';
            
            // Fetch the actual document file path
            fetch('get_document_path.php?document_id=' + documentId)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.file_path) {
                        // Show the actual document in an iframe or link
                        content.innerHTML = `
                            <div class="text-center">
                                <p class="mb-3">Document: <strong>${documentName}</strong></p>
                                <p class="mb-3">Submitted: ${data.uploaded_at}</p>
                                <a href="${data.file_path}" target="_blank" class="btn btn-primary">
                                    <i class="fas fa-external-link-alt me-2"></i>Open Document
                                </a>
                                <p class="text-muted small mt-3">Click to view the actual submitted document</p>
                            </div>
                        `;
                    } else {
                        content.innerHTML = '<div class="alert alert-danger">Error loading document: ' + (data.message || 'Unknown error') + '</div>';
                    }
                })
                .catch(error => {
                    content.innerHTML = '<div class="alert alert-danger">Error loading document: ' + error + '</div>';
                });
        }

        function viewDocument(type) {
            const title = document.getElementById('docTitle');
            const content = document.getElementById('docContent');
            
            if (type === 'resolution') {
                title.textContent = 'Resolution - Case <?php echo $case['case_number']; ?>';
                content.innerHTML = `
                    <div style="font-family: 'Times New Roman', serif; line-height: 1.6; padding: 20px;">
                        <div class="text-center mb-4">
                            <h6>REPUBLIC OF THE PHILIPPINES</h6>
                            <p class="small">OFFICE OF THE CITY PROSECUTOR</p>
                            <p class="small">Catbalogan City</p>
                            <h5 class="mt-3">RESOLUTION</h5>
                            <p class="small">I.S. No. <?php echo $case['case_number']; ?></p>
                        </div>
                        <p><strong>COMPLAINANT:</strong> <?php echo addslashes($case['complainant_name']); ?></p>
                        <p><strong>ACCUSED:</strong> <?php echo addslashes($case['respondent_name']); ?></p>
                        <p><strong>OFFENSE:</strong> <?php echo $case['offense']; ?> (<?php echo $case['law_violated']; ?>)</p>
                        <hr>
                        <p><strong>FINDINGS:</strong></p>
                        <p>After a careful and thorough examination of the evidence presented, this Office finds that there is <strong>SUFFICIENT GROUND</strong> to engender a well-founded belief that the crime of <?php echo $case['offense']; ?> has been committed and that the accused <?php echo addslashes($case['respondent_name']); ?> is probably guilty thereof.</p>
                        <p><strong>RESOLUTION:</strong></p>
                        <p>It is hereby recommended that an Information for <?php echo $case['offense']; ?> be filed against the accused <?php echo addslashes($case['respondent_name']); ?> before the proper court.</p>
                        <div class="mt-4 pt-4 border-top">
                            <p class="mb-0"><strong><?php echo addslashes($case['assigned_prosecutor']); ?></strong></p>
                            <p class="small text-muted">Investigating Prosecutor</p>
                        </div>
                        <div class="mt-4">
                            <p class="mb-0"><strong>City Prosecutor</strong></p>
                            <p class="small text-muted">Approved By</p>
                        </div>
                    </div>
                `;
            } else if (type === 'criminal_info') {
                title.textContent = 'Criminal Information - Case <?php echo $case['case_number']; ?>';
                content.innerHTML = `
                    <div style="font-family: 'Times New Roman', serif; line-height: 1.6; padding: 20px;">
                        <div class="text-center mb-4">
                            <h6>REPUBLIC OF THE PHILIPPINES</h6>
                            <p class="small">REGIONAL TRIAL COURT</p>
                            <p class="small">Catbalogan City</p>
                            <h5 class="mt-3">CRIMINAL INFORMATION</h5>
                            <p class="small">Crim. Case No. ________</p>
                        </div>
                        <p>THE PEOPLE OF THE PHILIPPINES,</p>
                        <p style="text-align: center;">- versus -</p>
                        <p style="text-align: right;"><strong><?php echo addslashes($case['respondent_name']); ?></strong></p>
                        <p style="text-align: right;">Accused.</p>
                        <hr>
                        <p><strong>FOR: <?php echo strtoupper($case['offense']); ?></strong> (Violation of <?php echo $case['law_violated']; ?>)</p>
                        <p>The undersigned accuses <?php echo addslashes($case['respondent_name']); ?> of the crime of <?php echo $case['offense']; ?> committed as follows:</p>
                        <p>That on or about <?php echo $case['incident_date']; ?>, in Catbalogan City, Philippines, and within the jurisdiction of this Honorable Court, the said accused, did then and there willfully, unlawfully and feloniously <?php echo addslashes($case['case_summary']); ?>, thereby committing the crime of <?php echo $case['offense']; ?>.</p>
                        <p>CONTRARY TO LAW.</p>
                        <div class="mt-4 pt-4 border-top">
                            <p class="mb-0"><strong><?php echo addslashes($case['assigned_prosecutor']); ?></strong></p>
                            <p class="small text-muted">City Prosecutor/Representative</p>
                            <p class="small">Office of the City Prosecutor</p>
                            <p class="small">Catbalogan City</p>
                        </div>
                    </div>
                `;
            } else if (type === 'affidavit') {
                title.textContent = 'Complaint-Affidavit - <?php echo addslashes($case['complainant_name']); ?>';
                content.innerHTML = `
                    <div style="font-family: 'Times New Roman', serif; line-height: 1.6; padding: 20px;">
                        <div class="text-center mb-4">
                            <h5>COMPLAINT-AFFIDAVIT</h5>
                            <p class="small text-muted">Case No. <?php echo $case['case_number']; ?> | NPS Docket: <?php echo $case['nps_docket']; ?></p>
                        </div>
                        <p><strong>I, <?php echo addslashes($case['complainant_name']); ?>,</strong> of legal age, Filipino, after being sworn to in accordance with law, hereby depose and state:</p>
                        <p>1. That I am the complainant in the above-entitled case;</p>
                        <p>2. That on or about <?php echo $case['incident_date']; ?> at around <?php echo $case['incident_time']; ?>, at <?php echo addslashes($case['incident_place']); ?>, the herein respondent <?php echo addslashes($case['respondent_name']); ?> did then and there willfully, unlawfully, and feloniously commit the offense of <?php echo $case['offense']; ?> in violation of <?php echo $case['law_violated']; ?>;</p>
                        <p>3. <?php echo addslashes($case['case_summary']); ?>;</p>
                        <p>4. I am executing this affidavit to attest to the truth of the foregoing facts and to file charges against the respondent.</p>
                        <div class="mt-4">
                            <p class="mb-0"><strong><?php echo addslashes($case['complainant_name']); ?></strong></p>
                            <p class="small text-muted">Complainant/Affiant</p>
                        </div>
                        <div class="mt-4 pt-4 border-top">
                            <p class="small"><strong>SUBSCRIBED AND SWORN</strong> to before me this _____ day of ____________, 20___, at Catbalogan City, Philippines.</p>
                            <p class="mt-4 mb-0"><strong>_________________________</strong></p>
                            <p class="small text-muted">Administering Officer</p>
                        </div>
                    </div>
                `;
            } else if (type === 'counter') {
                title.textContent = 'Counter-Affidavit - <?php echo addslashes($case['respondent_name']); ?>';
                content.innerHTML = `
                    <div style="font-family: 'Times New Roman', serif; line-height: 1.6; padding: 20px;">
                        <div class="text-center mb-4">
                            <h5>COUNTER-AFFIDAVIT</h5>
                            <p class="small text-muted">Case No. <?php echo $case['case_number']; ?> | NPS Docket: <?php echo $case['nps_docket']; ?></p>
                        </div>
                        <p><strong>I, <?php echo addslashes($case['respondent_name']); ?>,</strong> of legal age, Filipino, after being sworn to in accordance with law, hereby depose and state:</p>
                        <p>1. That I am the respondent in the above-entitled case;</p>
                        <p>2. That I categorically DENY the accusations against me;</p>
                        <p>3. That the allegations in the complaint-affidavit are purely fabricated and without basis;</p>
                        <p>4. I am executing this counter-affidavit to refute the false charges against me.</p>
                        <div class="mt-4">
                            <p class="mb-0"><strong><?php echo addslashes($case['respondent_name']); ?></strong></p>
                            <p class="small text-muted">Respondent/Affiant</p>
                        </div>
                        <div class="mt-4 pt-4 border-top">
                            <p class="small"><strong>SUBSCRIBED AND SWORN</strong> to before me this _____ day of ____________, 20___, at Catbalogan City, Philippines.</p>
                        </div>
                    </div>
                `;
            } else if (type === 'evidence') {
                title.textContent = 'Evidence Summary - Case <?php echo $case['case_number']; ?>';
                content.innerHTML = `
                    <div class="p-4">
                        <h6 class="mb-3">EVIDENCE SUMMARY</h6>
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>No.</th>
                                    <th>Evidence Description</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>1</td>
                                    <td>CCTV Footage from Catbalogan City Public Market - incident on <?php echo $case['incident_date']; ?> @ <?php echo $case['incident_time']; ?></td>
                                    <td><span class="badge bg-success">Verified</span></td>
                                </tr>
                                <tr>
                                    <td>2</td>
                                    <td>Police Blotter Entry - Report No. 2024-0123</td>
                                    <td><span class="badge bg-success">Authenticated</span></td>
                                </tr>
                                <tr>
                                    <td>3</td>
                                    <td>Witness Statement - Robert Lim</td>
                                    <td><span class="badge bg-warning">Under Review</span></td>
                                </tr>
                                <tr>
                                    <td>4</td>
                                    <td>Medical Certificate (if applicable)</td>
                                    <td><span class="badge bg-secondary">N/A</span></td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="row mt-4">
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-body bg-secondary text-white text-center" style="height: 150px; display: flex; align-items: center; justify-content: center;">
                                        <i class="fas fa-image fa-2x"></i>
                                    </div>
                                    <div class="card-footer small">CCTV Screenshot 1</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-body bg-secondary text-white text-center" style="height: 150px; display: flex; align-items: center; justify-content: center;">
                                        <i class="fas fa-image fa-2x"></i>
                                    </div>
                                    <div class="card-footer small">CCTV Screenshot 2</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-body bg-secondary text-white text-center" style="height: 150px; display: flex; align-items: center; justify-content: center;">
                                        <i class="fas fa-file-alt fa-2x"></i>
                                    </div>
                                    <div class="card-footer small">Witness Statement</div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }
        }

        function printDocument() {
            const content = document.getElementById('docContent').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write('<html><head><title>Print Document</title>');
            printWindow.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">');
            printWindow.document.write('</head><body>');
            printWindow.document.write(content);
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            printWindow.print();
        }

        function downloadDocumentPDF() {
            const content = document.getElementById('docContent').innerHTML;
            const title = document.getElementById('docTitle').textContent;
            const printWindow = window.open('', '_blank');
            printWindow.document.write('<html><head><title>' + title + '</title>');
            printWindow.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">');
            printWindow.document.write('<style>body { padding: 20px; font-family: Times New Roman, serif; }</style>');
            printWindow.document.write('</head><body>');
            printWindow.document.write(content);
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            printWindow.print();
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

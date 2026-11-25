<?php
session_start();

if (!isset($_SESSION['logged_in'])) {
    header("Location: ../index.php");
    exit();
}

// Include database connection
require_once '../includes/db.php';

// Get fiscal settings
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

// Get case_id from URL - only show cases that are "For Resolution"
$case_id = isset($_GET['case_id']) ? intval($_GET['case_id']) : 0;

// Get cases from database with "For Resolution" status
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
               c.case_summary,
               c.investigation_start_date as investigation_start,
               c.subpoena_issued_date as subpoena_issued,
               c.counter_affidavit_date as counter_received,
               c.status
        FROM complaints c
        LEFT JOIN complainants comp ON c.complainant_id = comp.id
        LEFT JOIN respondents resp ON c.respondent_id = resp.id
        LEFT JOIN users u ON c.assigned_prosecutor_id = u.id
        WHERE c.status = 'For Resolution'
        ORDER BY c.date_filed DESC
    ");
    $stmt->execute();
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert to keyed array by id
    $cases_by_id = [];
    foreach ($cases as $case) {
        $cases_by_id[$case['id']] = $case;
    }
} catch (PDOException $e) {
    $cases_by_id = [];
}

$case = isset($cases_by_id[$case_id]) ? $cases_by_id[$case_id] : (!empty($cases_by_id) ? reset($cases_by_id) : null);

// If no case found, redirect
if (!$case) {
    header("Location: dashboard.php?error=no_cases");
    exit();
}

// Get documents for this case
$documents = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, document_name, document_type, file_path, uploaded_at, evidence_notes
        FROM case_documents
        WHERE complaint_id = ?
        ORDER BY uploaded_at ASC
    ");
    $stmt->execute([$case_id]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $documents = [];
}

// Get witnesses for this case
$witnesses = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, full_name, address, contact_number, statement
        FROM witnesses
        WHERE complaint_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$case_id]);
    $witnesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $witnesses = [];
}

// Get general evidence notes for this case
$general_evidence_notes = '';
try {
    $stmt = $pdo->prepare("
        SELECT notes FROM evidence_notes
        WHERE complaint_id = ?
        LIMIT 1
    ");
    $stmt->execute([$case_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result) {
        $general_evidence_notes = $result['notes'];
    }
} catch (PDOException $e) {
    $general_evidence_notes = '';
}

// Format displays
$comp_display = $case['complainant_name'];
if ($case['complainant_age']) $comp_display .= ', ' . $case['complainant_age'];
if ($case['complainant_sex']) $comp_display .= '/' . $case['complainant_sex'];

$resp_display = $case['respondent_name'];
if ($case['respondent_age']) $resp_display .= ', ' . $case['respondent_age'];
if ($case['respondent_sex']) $resp_display .= '/' . $case['respondent_sex'];
$resp_display .= ', ' . $case['respondent_status'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resolution - Office of the Prosecutor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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

        .resolution-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }

        .resolution-card h5 {
            margin-bottom: 20px;
            color: var(--dark-navy);
            font-weight: 600;
            border-left: 4px solid var(--primary-blue);
            padding-left: 15px;
        }

        .probable-cause-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .probable-cause-box h6 {
            margin-bottom: 10px;
        }

        .action-box {
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .action-box:hover {
            border-color: var(--primary-blue);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .action-box.selected {
            border-color: var(--success-green);
            background: #f1f8e9;
        }

        .action-box input[type="radio"] {
            margin-right: 10px;
        }

        .action-title {
            font-weight: 600;
            color: var(--dark-navy);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .action-desc {
            color: #666;
            font-size: 14px;
            margin-top: 8px;
            margin-left: 28px;
        }

        .resolution-preview {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 20px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
            max-height: 400px;
            overflow-y: auto;
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

        .btn-approve {
            background: var(--success-green);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-approve:hover {
            background: #0d8a4a;
        }

        .findings-list {
            list-style: none;
            padding: 0;
        }

        .findings-list li {
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
        }

        .findings-list li:last-child {
            border-bottom: none;
        }

        .evidence-summary {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .timeline-item {
            display: flex;
            gap: 15px;
            padding: 10px 0;
        }

        .timeline-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
        }

        .status-badge {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-resolution { background: #fce4ec; color: #c2185b; }
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
                    <h2>Resolution</h2>
                    <p class="text-muted mb-0">Determine probable cause and generate resolution</p>
                </div>
                <div class="text-end">
                    <select class="form-select form-select-sm mb-2" style="width: 250px;" onchange="if(this.value) window.location.href='resolution.php?case_id='+this.value;">
                        <option value="">Select Case to Resolve...</option>
                        <?php foreach ($cases as $id => $c): ?>
                        <option value="<?php echo $id; ?>" <?php echo $id == $case_id ? 'selected' : ''; ?>><?php echo $c['case_number']; ?> - <?php echo $c['offense']; ?> (<?php echo $c['complainant_name']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <br>
                    <span class="status-badge status-resolution">For Resolution</span>
                </div>
            </div>

            <!-- Case Summary for Resolution -->
            <div class="resolution-card">
                <h5>Case Summary | NPS Docket: <?php echo $case['nps_docket']; ?></h5>
                <div class="row">
                    <div class="col-md-4">
                        <p class="mb-1"><strong>Case Number:</strong> <?php echo $case['case_number']; ?></p>
                        <p class="mb-1"><strong>Date Received:</strong> <?php echo date('F d, Y', strtotime($case['date_received'])); ?> @ <?php echo $case['time_received']; ?></p>
                        <p class="mb-1"><strong>Date Filed:</strong> <?php echo $case['date_filed']; ?></p>
                    </div>
                    <div class="col-md-4">
                        <p class="mb-1"><strong>Complainant:</strong> <?php echo $comp_display; ?></p>
                        <p class="mb-1"><strong>Respondent:</strong> <?php echo $resp_display; ?></p>
                        <p class="mb-1"><strong>Offense:</strong> <?php echo $case['offense']; ?></p>
                    </div>
                    <div class="col-md-4">
                        <p class="mb-1"><strong>Law Violated:</strong> <?php echo $case['law_violated']; ?></p>
                        <p class="mb-1"><strong>Incident Date/Time:</strong> <?php echo $case['incident_date']; ?> @ <?php echo $case['incident_time']; ?></p>
                        <p class="mb-1"><strong>Place:</strong> <?php echo $case['incident_place']; ?></p>
                    </div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Investigation Started:</strong> <?php echo $case['investigation_start']; ?></p>
                        <p class="mb-1"><strong>Subpoena Issued:</strong> <?php echo $case['subpoena_issued']; ?></p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Counter-Affidavit:</strong> Received <?php echo $case['counter_received']; ?></p>
                        <p class="mb-1"><strong>Status:</strong> <span class="badge bg-warning"><?php echo $case['status']; ?></span></p>
                    </div>
                </div>
            </div>

            <!-- Evidence Summary -->
            <div class="resolution-card">
                <h5>Evidence Summary</h5>
                
                <?php if (!empty($documents)): ?>
                    <?php foreach ($documents as $doc): ?>
                    <div class="evidence-summary">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span><i class="fas fa-file text-primary"></i> <?php echo htmlspecialchars($doc['document_name']); ?></span>
                                <small class="d-block text-muted"><?php echo htmlspecialchars($doc['document_type']); ?> - Uploaded <?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?></small>
                                <?php if ($doc['evidence_notes']): ?>
                                    <small class="d-block text-muted fst-italic">Note: <?php echo htmlspecialchars($doc['evidence_notes']); ?></small>
                                <?php endif; ?>
                            </div>
                            <span class="badge bg-primary">Document</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="evidence-summary" style="background: #fff3e0;">
                        <div class="d-flex justify-content-between">
                            <span><i class="fas fa-exclamation-circle text-warning"></i> No documents uploaded</span>
                            <span class="badge bg-warning">Pending</span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($witnesses)): ?>
                    <?php foreach ($witnesses as $witness): ?>
                    <div class="evidence-summary" style="background: #e8f5e9;">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span><i class="fas fa-user text-success"></i> <?php echo htmlspecialchars($witness['full_name']); ?></span>
                                <small class="d-block text-muted">Witness Statement</small>
                                <?php if ($witness['statement']): ?>
                                    <small class="d-block text-muted fst-italic">"<?php echo htmlspecialchars(substr($witness['statement'], 0, 100)); ?>..."</small>
                                <?php endif; ?>
                            </div>
                            <span class="badge bg-success">Credible</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="evidence-summary" style="background: #fff3e0;">
                        <div class="d-flex justify-content-between">
                            <span><i class="fas fa-exclamation-circle text-warning"></i> No witness statements</span>
                            <span class="badge bg-warning">Pending</span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($general_evidence_notes): ?>
                    <div class="evidence-summary" style="background: #e3f2fd;">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span><i class="fas fa-clipboard text-info"></i> Investigator's Notes</span>
                                <small class="d-block text-muted"><?php echo htmlspecialchars($general_evidence_notes); ?></small>
                            </div>
                            <span class="badge bg-info">Analysis</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Probable Cause Determination -->
            <div class="resolution-card">
                <h5>Probable Cause Determination</h5>
                
                <div class="probable-cause-box">
                    <h6><i class="fas fa-balance-scale" style="margin-right: 10px;"></i>Legal Standard</h6>
                    <p class="mb-0">Probable cause exists when the evidence submitted to the investigating prosecutor engenders a well-founded belief that a crime has been committed and that the accused is probably guilty thereof.</p>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Resolution Decision</label>
                    
                    <div class="action-box selected" onclick="selectAction('file')">
                        <label class="action-title">
                            <input type="radio" name="resolution" value="file" checked>
                            <i class="fas fa-gavel" style="color: var(--success-green);"></i>
                            File Information in Court
                        </label>
                        <div class="action-desc">
                            There is sufficient evidence to establish probable cause. Prepare Criminal Information for filing in court.
                        </div>
                    </div>

                    <div class="action-box" onclick="selectAction('dismiss')">
                        <label class="action-title">
                            <input type="radio" name="resolution" value="dismiss">
                            <i class="fas fa-times-circle" style="color: var(--danger-red);"></i>
                            Dismiss Case
                        </label>
                        <div class="action-desc">
                            No probable cause exists. Dismiss the complaint with advise to file appropriate civil action if any.
                        </div>
                    </div>

                    <div class="action-box" onclick="selectAction('reinvestigate')">
                        <label class="action-title">
                            <input type="radio" name="resolution" value="reinvestigate">
                            <i class="fas fa-redo" style="color: var(--warning-yellow);"></i>
                            Order Reinvestigation
                        </label>
                        <div class="action-desc">
                            Evidence is insufficient. Order further investigation or additional evidence.
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Findings & Rationale</label>
                    <textarea class="form-control" rows="5" id="findingsRationale" placeholder="Enter detailed findings and rationale for the resolution..."></textarea>
                </div>
            </div>

            <!-- Resolution Preview -->
            <div class="resolution-card">
                <h5>Resolution Document Preview</h5>
                <div class="resolution-preview">
                    <strong>REPUBLIC OF THE PHILIPPINES</strong><br>
                    CITY PROSECUTOR'S OFFICE<br>
                    Catbalogan City<br><br>

                    <strong>RESOLUTION</strong><br><br>

                    I.S. No. <?php echo $case['case_number']; ?><br>
                    For: <?php echo strtoupper($case['offense']); ?><br><br>

                    <strong>COMPLAINANT:</strong> <?php echo strtoupper($case['complainant_name']); ?><br><br>

                    <strong>RESPONDENT:</strong> <?php echo strtoupper($case['respondent_name']); ?><br><br>

                    <strong>x--------------------------------------x</strong><br><br>

                    <strong>PROSECUTOR'S RESOLUTION</strong><br><br>

                    This is a complaint for <?php echo $case['offense']; ?> filed by <?php echo $case['complainant_name']; ?> against <?php echo $case['respondent_name']; ?>.<br><br>

                    <strong>FINDINGS:</strong><br><br>

                    After careful evaluation of the evidence on record, this Office finds that probable cause exists to file an Information for <?php echo $case['offense']; ?> against the respondent.<br><br>

                    <strong>RESOLUTION:</strong><br><br>

                    WHEREFORE, premises considered, this Office resolves to FILE the corresponding Information for <?php echo $case['offense']; ?> under <?php echo $case['law_violated']; ?> against <?php echo strtoupper($case['respondent_name']); ?> before the proper court.<br><br>

                    Catbalogan City, Philippines, <?php echo date('F j, Y'); ?>.<br><br><br>

                    <strong>SUBMITTED BY:</strong><br><br>

                    <?php echo $case['assigned_prosecutor'] ?? 'Investigating Prosecutor'; ?><br>
                    Investigating Prosecutor<br><br><br>

                    <strong>APPROVED BY:</strong><br><br>

                    <?php echo $fiscal_settings['prosecutor_name'] ?? 'City Prosecutor'; ?><br>
                    City Prosecutor
                </div>

                <div class="mt-3 text-end">
                    <button class="btn btn-outline-primary me-2" onclick="editDocument()">
                        <i class="fas fa-edit"></i> Edit Document
                    </button>
                    <button class="btn btn-generate" onclick="generateResolution()">
                        <i class="fas fa-file-pdf"></i> Generate Resolution
                    </button>
                </div>
            </div>

            <!-- Final Review -->
            <div class="resolution-card">
                <h5>Final Review & Approval</h5>
                
                <div class="timeline-item">
                    <div class="timeline-icon" style="background: var(--primary-blue);">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <strong>Investigating Prosecutor</strong>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars($case['assigned_prosecutor'] ?? 'Not Assigned'); ?> - Recommended for Filing</p>
                        <small class="text-muted"><?php echo $case['date_filed'] ? date('F j, Y', strtotime($case['date_filed'])) : 'N/A'; ?></small>
                    </div>
                </div>

                <div class="timeline-item">
                    <div class="timeline-icon" style="background: #ddd;">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div>
                        <strong>City Prosecutor Review</strong>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars($fiscal_settings['prosecutor_name'] ?? 'City Prosecutor'); ?></p>
                        <small class="text-muted">Pending final approval</small>
                    </div>
                </div>

                <div class="text-end mt-3">
                    <button class="btn btn-secondary me-2" onclick="returnForRevision()">
                        <i class="fas fa-undo"></i> Return for Revision
                    </button>
                    <button class="btn btn-approve" id="approveBtn" onclick="approveResolution()">
                        <i class="fas fa-check-circle"></i> File in Court
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize button text on page load
        document.addEventListener('DOMContentLoaded', function() {
            const approveBtn = document.getElementById('approveBtn');
            if (approveBtn) {
                approveBtn.innerHTML = '<i class="fas fa-check-circle"></i> File in Court';
            }
        });

        function selectAction(action) {
            document.querySelectorAll('.action-box').forEach(box => box.classList.remove('selected'));
            event.currentTarget.classList.add('selected');
            event.currentTarget.querySelector('input').checked = true;
            
            // Update button text based on selected action
            const approveBtn = document.getElementById('approveBtn');
            switch(action) {
                case 'file':
                    approveBtn.innerHTML = '<i class="fas fa-check-circle"></i> File in Court';
                    break;
                case 'dismiss':
                    approveBtn.innerHTML = '<i class="fas fa-ban"></i> Dismiss Case';
                    break;
                case 'reinvestigate':
                    approveBtn.innerHTML = '<i class="fas fa-redo"></i> Order Reinvestigation';
                    break;
            }
        }

        function generateResolution() {
            const caseId = <?php echo $case_id; ?>;
            if (!caseId) {
                alert('No case selected');
                return;
            }
            window.open('generate_resolution.php?case_id=' + caseId, 'resolution_tab');
        }

        function editDocument() {
            const caseId = <?php echo $case_id; ?>;
            if (!caseId) {
                alert('No case selected');
                return;
            }
            window.open('generate_resolution.php?case_id=' + caseId, 'resolution_tab');
        }

        function approveResolution() {
            const caseId = <?php echo $case_id; ?>;
            if (!caseId) {
                alert('No case selected');
                return;
            }
            
            if (confirm('Are you sure you want to approve this resolution? This will forward the case for filing.')) {
                fetch('api_case_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=approve_resolution&case_id=' + caseId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Resolution approved! Case forwarded for filing.');
                        window.location.href = 'court_forwarding.php';
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error approving resolution: ' + error);
                });
            }
        }

        function returnForRevision() {
            const caseId = <?php echo $case_id; ?>;
            if (!caseId) {
                alert('No case selected');
                return;
            }
            
            const revisionNotes = prompt('Please enter revision notes explaining what needs to be revised:');
            if (revisionNotes === null) {
                return; // User cancelled
            }
            
            if (!revisionNotes.trim()) {
                alert('Please provide revision notes.');
                return;
            }
            
            if (confirm('Are you sure you want to return this case for revision? The case will be sent back to investigation.')) {
                fetch('api_case_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=return_for_revision&case_id=' + caseId + '&notes=' + encodeURIComponent(revisionNotes)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Case returned for revision. The investigating prosecutor has been notified.');
                        window.location.href = 'investigation.php?case_id=' + caseId;
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error returning for revision: ' + error);
                });
            }
        }
    </script>
</body>
</html>

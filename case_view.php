<?php
session_start();

// Check if user is logged in and has branch access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'branch') {
    header("Location: ../index.php");
    exit();
}

// Get branch number from username
$username = $_SESSION['username'] ?? '';
$branch_number = 27;
if (strpos($username, 'branch') === 0) {
    $branch_num = intval(substr($username, 6));
    if (in_array($branch_num, [27, 28, 29])) {
        $branch_number = $branch_num;
    }
}

$branch_name = "RTC Branch " . $branch_number;

// Branch judges configuration (shared with settings)
$branch_judges = [
    27 => ['name' => 'Hon. Judge A. Santos', 'title' => 'Presiding Judge', 'since' => '2020-01-15'],
    28 => ['name' => 'Hon. Judge B. Reyes', 'title' => 'Presiding Judge', 'since' => '2019-06-01'],
    29 => ['name' => 'Hon. Judge C. Cruz', 'title' => 'Presiding Judge', 'since' => '2021-03-10'],
];
$branch_judge = $branch_judges[$branch_number]['name'] ?? 'Not Assigned';

// Get case ID from URL
$case_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Include database connection
require_once 'db.php';

// Get case from database
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.case_number as no, c.offense, c.offense_type, c.law_statute, c.law_section,
               c.status, c.date_filed as date_filed, c.date_received, c.time_received,
               c.incident_date, c.incident_time, c.incident_place, c.priority,
               c.case_summary, c.description, c.case_type, c.nps_docket_no as nps_docket,
               c.criminal_case_no, c.date_docketed,
               comp.full_name as complainant, resp.full_name as respondent,
               resp.status as respondent_status, resp.arrest_date, resp.detention_location, resp.custody_details
        FROM complaints c
        LEFT JOIN complainants comp ON c.complainant_id = comp.id
        LEFT JOIN respondents resp ON c.respondent_id = resp.id
        WHERE c.id = ?
    ");
    $stmt->execute([$case_id]);
    $case = $stmt->fetch();
    
    // If case found in database, generate missing fields
    if ($case) {
        $case['title'] = ($case['complainant'] ?? 'Unknown') . ' vs. ' . ($case['respondent'] ?? 'Unknown');
        $case['date_assigned'] = $case['date_filed'];
    }
} catch (PDOException $e) {
    $case = null;
}

// If no case found, show error instead of redirecting
if (!$case) {
    die("Case not found. ID: " . $case_id . "<br>Error: Case does not exist in database or query failed.");
}

// Calculate case age
$filed = new DateTime($case['date_filed']);
$now = new DateTime();
$age_days = $filed->diff($now)->days;

// Determine aging category
if ($age_days < 30) {
    $age_category = 'Fresh';
    $age_class = 'text-success';
} elseif ($age_days < 60) {
    $age_category = 'Moderate';
    $age_class = 'text-warning';
} elseif ($age_days < 90) {
    $age_category = 'Stale';
    $age_class = 'text-danger';
} else {
    $age_category = 'Delayed';
    $age_class = 'text-danger fw-bold';
}

// Get hearings from database for this case
try {
    $stmt = $pdo->prepare("
        SELECT h.id, h.date, h.type, 
               h.status, h.notes, h.judge_decision, h.dismissal_reason, h.outcome
        FROM hearings h
        WHERE h.complaint_id = ?
        ORDER BY h.date DESC
    ");
    $stmt->execute([$case_id]);
    $hearings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $hearings = [];
}

// Get documents from database for this case
try {
    $stmt = $pdo->prepare("
        SELECT cd.id, cd.document_name as name, cd.document_type as type, 
               cd.uploaded_at as date, cd.file_path
        FROM case_documents cd
        WHERE cd.complaint_id = ?
        ORDER BY cd.uploaded_at DESC
    ");
    $stmt->execute([$case_id]);
    $documents = $stmt->fetchAll();
    
    // Add file size to documents and fix file paths
    foreach ($documents as &$doc) {
        $doc['file_path'] = '../' . $doc['file_path']; // Add ../ for branch directory structure
        if (file_exists($doc['file_path'])) {
            $doc['size'] = round(filesize($doc['file_path']) / 1024, 2) . ' KB';
        } else {
            $doc['size'] = 'File not found';
        }
    }
} catch (PDOException $e) {
    $documents = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Case - <?php echo $case['no']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary-purple: #9c27b0; --light-gray: #f5f5f5; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--light-gray); margin: 0; }
        .app-container { display: flex; min-height: 100vh; }
        .main-content { flex: 1; padding: 20px; }
        .top-header { background: white; padding: 15px 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .btn-primary { background: var(--primary-purple); border: none; }
        .btn-primary:hover { background: #7b1fa2; }
        .status-badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; }
        .status-pending { background: #fff3e0; color: #e65100; }
        .status-pretrial { background: #f3e5f5; color: #7b1fa2; }
        .status-trial { background: #e3f2fd; color: #1565c0; }
        .status-resolved { background: #e8f5e9; color: #2e7d32; }
        .info-row { display: flex; margin-bottom: 15px; }
        .info-label { width: 150px; font-weight: 500; color: #666; }
        .info-value { flex: 1; }
        .timeline-item { border-left: 3px solid var(--primary-purple); padding-left: 15px; margin-bottom: 20px; position: relative; }
        .timeline-item::before { content: ''; position: absolute; left: -6px; top: 0; width: 10px; height: 10px; border-radius: 50%; background: var(--primary-purple); }
        .doc-icon { width: 40px; height: 40px; background: #f0f0f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 10px; }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/navigation_branch.php'; ?>
        <div class="main-content">
            <div class="top-header d-flex justify-content-between align-items-center">
                <div>
                    <h4><i class="fas fa-folder-open me-2" style="color: var(--primary-purple);"></i>Case Details</h4>
                    <small class="text-muted"><?php echo $branch_name; ?></small>
                </div>
                <div>
                    <a href="cases.php" class="btn btn-outline-secondary btn-sm me-2"><i class="fas fa-arrow-left me-1"></i>Back to Cases</a>
                    <a href="case_edit.php?id=<?php echo $case_id; ?>" class="btn btn-primary btn-sm"><i class="fas fa-edit me-1"></i>Edit Case</a>
                </div>
            </div>

            <div class="row">
                <!-- Main Case Info -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h5 class="mb-1"><?php echo $case['no']; ?></h5>
                                <p class="text-muted mb-0"><?php echo $case['title']; ?></p>
                            </div>
                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $case['status'])); ?>">
                                <?php echo $case['status']; ?>
                            </span>
                        </div>
                        
                        <hr>
                        
                        <div class="info-row">
                            <div class="info-label">Case Type:</div>
                            <div class="info-value"><?php echo $case['case_type'] ?? 'Not specified'; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">NPS Docket No:</div>
                            <div class="info-value"><?php echo $case['nps_docket'] ?? 'Not specified'; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Criminal Case No:</div>
                            <div class="info-value"><?php echo $case['criminal_case_no'] ?? 'Not specified'; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Offense:</div>
                            <div class="info-value"><?php echo $case['offense']; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Offense Type:</div>
                            <div class="info-value"><?php echo $case['offense_type'] ?? 'Not specified'; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Law Violated:</div>
                            <div class="info-value"><?php echo $case['law_statute'] ?? 'Not specified'; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Law Section:</div>
                            <div class="info-value"><?php echo $case['law_section'] ?? 'Not specified'; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Complainant:</div>
                            <div class="info-value"><?php echo $case['complainant']; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Respondent:</div>
                            <div class="info-value"><?php echo $case['respondent']; ?></div>
                        </div>
                        <?php if ($case['respondent_status']): ?>
                        <div class="info-row">
                            <div class="info-label">Accused Status:</div>
                            <div class="info-value">
                                <span class="badge bg-<?php 
                                    echo match($case['respondent_status']) {
                                        'arrested' => 'danger',
                                        'detained' => 'warning',
                                        'on_bail' => 'info',
                                        'at_large' => 'secondary',
                                        'deceased' => 'dark',
                                        default => 'secondary'
                                    };
                                ?> text-capitalize">
                                    <?php echo str_replace('_', ' ', $case['respondent_status']); ?>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if ($case['arrest_date']): ?>
                        <div class="info-row">
                            <div class="info-label">Arrest Date:</div>
                            <div class="info-value"><?php echo date('F d, Y', strtotime($case['arrest_date'])); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($case['detention_location']): ?>
                        <div class="info-row">
                            <div class="info-label">Detention Location:</div>
                            <div class="info-value"><?php echo htmlspecialchars($case['detention_location']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($case['custody_details']): ?>
                        <div class="info-row">
                            <div class="info-label">Custody Details:</div>
                            <div class="info-value"><?php echo htmlspecialchars($case['custody_details']); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="info-row">
                            <div class="info-label">Priority:</div>
                            <div class="info-value"><?php echo $case['priority'] ?? 'Normal'; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Incident Date:</div>
                            <div class="info-value"><?php echo $case['incident_date'] ? date('F d, Y', strtotime($case['incident_date'])) : 'Not specified'; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Incident Place:</div>
                            <div class="info-value"><?php echo $case['incident_place'] ?? 'Not specified'; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Presiding Judge:</div>
                            <div class="info-value"><?php echo $branch_judge; ?> <span class="badge bg-info"><?php echo $branch_judges[$branch_number]['title'] ?? ''; ?></span></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Date Filed:</div>
                            <div class="info-value"><?php echo date('F d, Y', strtotime($case['date_filed'])); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Date Received:</div>
                            <div class="info-value"><?php echo $case['date_received'] ? date('F d, Y', strtotime($case['date_received'])) : 'Not specified'; ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Date Assigned:</div>
                            <div class="info-value"><?php echo date('F d, Y', strtotime($case['date_assigned'])); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Case Age:</div>
                            <div class="info-value <?php echo $age_class; ?>">
                                <?php echo $age_days; ?> days (<?php echo $age_category; ?>)
                            </div>
                        </div>
                        
                        <hr>
                        
                        <h6>Case Summary</h6>
                        <p class="text-muted"><?php echo $case['case_summary'] ?? 'No summary provided'; ?></p>
                        
                        <h6>Case Description</h6>
                        <p class="text-muted"><?php echo $case['description'] ?? 'No description provided'; ?></p>
                    </div>

                    <!-- Hearing History -->
                    <div class="card">
                        <h6 class="mb-3"><i class="fas fa-calendar-alt me-2" style="color: var(--primary-purple);"></i>Hearing History</h6>
                        <?php if (count($hearings) > 0): ?>
                        <?php foreach ($hearings as $hearing): ?>
                        <?php 
                        $is_completed = strtolower($hearing['status']) === 'completed';
                        $outcome_class = 'primary';
                        if ($hearing['outcome'] === 'dismissed') $outcome_class = 'danger';
                        elseif ($hearing['outcome'] === 'resolved') $outcome_class = 'success';
                        elseif ($hearing['outcome'] === 'adjourned') $outcome_class = 'warning';
                        elseif ($hearing['outcome'] === 'completed') $outcome_class = 'success';
                        ?>
                        <div class="timeline-item">
                            <div class="d-flex justify-content-between">
                                <strong><?php echo $hearing['type']; ?></strong>
                                <div>
                                    <?php if ($is_completed && $hearing['outcome']): ?>
                                    <span class="badge bg-<?php echo $outcome_class; ?> me-1">
                                        <?php echo ucfirst($hearing['outcome']); ?>
                                    </span>
                                    <?php endif; ?>
                                    <span class="badge bg-<?php echo $is_completed ? 'success' : 'primary'; ?>">
                                        <?php echo ucfirst($hearing['status']); ?>
                                    </span>
                                </div>
                            </div>
                            <small class="text-muted"><?php echo date('F d, Y', strtotime($hearing['date'])); ?></small>
                            <p class="small text-muted mb-0 mt-1"><?php echo $hearing['notes'] ?? 'No notes'; ?></p>
                            <?php if ($is_completed && !empty($hearing['judge_decision'])): ?>
                            <div class="mt-2 p-2" style="background: #f8f9fa; border-radius: 6px; border-left: 3px solid #28a745;">
                                <small class="fw-bold text-success"><i class="fas fa-gavel me-1"></i>Judge's Decision:</small>
                                <p class="small mb-0 mt-1"><?php echo $hearing['judge_decision']; ?></p>
                                <?php if (!empty($hearing['dismissal_reason'])): ?>
                                <small class="text-muted"><strong>Dismissal Reason:</strong> <?php echo $hearing['dismissal_reason']; ?></small>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <p class="text-muted">No hearings recorded for this case.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="col-md-4">
                    <!-- Actions -->
                    <div class="card">
                        <h6 class="mb-3">Actions</h6>
                        <a href="case_edit.php?id=<?php echo $case_id; ?>" class="btn btn-primary w-100 mb-2">
                            <i class="fas fa-edit me-2"></i>Edit Case
                        </a>
                        <button class="btn btn-outline-secondary w-100" onclick="window.print()">
                            <i class="fas fa-print me-2"></i>Print Case
                        </button>
                    </div>

                    <!-- Documents -->
                    <div class="card">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0"><i class="fas fa-file-alt me-2" style="color: var(--primary-purple);"></i>Documents</h6>
                            <a href="documents.php" class="btn btn-sm btn-link">View All</a>
                        </div>
                        <?php if (count($documents) > 0): ?>
                        <?php foreach ($documents as $doc): ?>
                        <div class="d-flex align-items-center mb-2 p-2" style="background: #f8f9fa; border-radius: 6px;">
                            <div class="doc-icon">
                                <i class="fas fa-file-pdf text-danger"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="small fw-bold"><?php echo $doc['name']; ?></div>
                                <small class="text-muted"><?php echo $doc['type']; ?> | <?php echo $doc['size']; ?></small>
                            </div>
                            <?php if (file_exists($doc['file_path'])): ?>
                            <a href="<?php echo $doc['file_path']; ?>" target="_blank" class="btn btn-sm btn-link"><i class="fas fa-eye"></i></a>
                            <?php else: ?>
                            <span class="text-muted"><i class="fas fa-exclamation-circle"></i></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <p class="text-muted small">No documents uploaded for this case.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Quick Stats -->
                    <div class="card">
                        <h6 class="mb-3">Case Statistics</h6>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Hearings:</span>
                            <strong><?php echo count($hearings); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Documents:</span>
                            <strong><?php echo count($documents); ?></strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">Days Active:</span>
                            <strong><?php echo $age_days; ?></strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Document Modal for Case View -->
    <div class="modal fade" id="uploadDocModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--primary-purple); color: white;">
                    <h5 class="modal-title"><i class="fas fa-cloud-upload-alt me-2"></i>Upload Document</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="caseUploadForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Document Type</label>
                            <select class="form-select" name="doc_type" required>
                                <option value="">-- Select Type --</option>
                                <option value="Information">Information</option>
                                <option value="Resolution">Resolution</option>
                                <option value="Affidavit">Affidavit</option>
                                <option value="Statement">Statement</option>
                                <option value="Motion">Motion</option>
                                <option value="Court Order">Court Order</option>
                                <option value="Judgment">Judgment</option>
                                <option value="Evidence">Evidence</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Document Name</label>
                            <input type="text" class="form-control" name="doc_name" placeholder="e.g., Evidence Photo #1" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Select File</label>
                            <input type="file" class="form-control" name="document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                            <small class="text-muted">PDF, Word, Images. Max 10MB.</small>
                        </div>
                        <input type="hidden" name="case_id" value="<?php echo $case_id; ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-upload me-1"></i>Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Success Toast -->
    <div class="toast-container position-fixed top-0 end-0 p-3">
        <div id="uploadSuccessToast" class="toast align-items-center text-white bg-success" role="alert">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fas fa-check-circle me-2"></i>Document uploaded successfully!
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Download document function
    function downloadDoc(docId) {
        alert('Downloading document ID: ' + docId + '\n\nIn production, this downloads the file from server.');
    }

    // Handle case document upload
    document.getElementById('caseUploadForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const fileInput = this.querySelector('input[name="document"]');
        const file = fileInput.files[0];
        
        if (!file) {
            alert('Please select a file');
            return;
        }
        
        if (file.size > 10 * 1024 * 1024) {
            alert('File too large. Max 10MB.');
            return;
        }
        
        // Demo upload
        console.log('Uploading to case <?php echo $case_id; ?>:', file.name);
        
        const modal = bootstrap.Modal.getInstance(document.getElementById('uploadDocModal'));
        modal.hide();
        
        const toast = new bootstrap.Toast(document.getElementById('uploadSuccessToast'));
        toast.show();
        
        this.reset();
    });
    </script>
</body>
</html>

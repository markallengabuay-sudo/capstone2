<?php
session_start();

if (!isset($_SESSION['logged_in'])) {
    header("Location: ../index.php");
    exit();
}

// Include database connection
require_once '../includes/db.php';

// Get case_id from URL
$case_id = isset($_GET['case_id']) ? intval($_GET['case_id']) : 0;

// Get cases from database with "Under Investigation" or "New" status
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
               c.similar_complaint, c.respondent_aware, c.related_case,
               c.status, c.reply_allowed
        FROM complaints c
        LEFT JOIN complainants comp ON c.complainant_id = comp.id
        LEFT JOIN respondents resp ON c.respondent_id = resp.id
        LEFT JOIN users u ON c.assigned_prosecutor_id = u.id
        WHERE c.status IN ('Pending', 'Under Investigation', 'New')
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

// Get witnesses for the selected case
$witnesses = [];
if ($case && $case['id']) {
    try {
        $stmt = $pdo->prepare("
            SELECT id, full_name, address, contact_number, statement
            FROM witnesses
            WHERE complaint_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$case['id']]);
        $witnesses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $witnesses = [];
    }
}

// Get documents for the selected case
$documents = [];
$has_counter_affidavit = false;
if ($case && $case['id']) {
    try {
        // Check if evidence_notes column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM case_documents LIKE 'evidence_notes'");
        $column_exists = $stmt->fetch();
        
        if ($column_exists) {
            $stmt = $pdo->prepare("
                SELECT id, document_name, document_type, file_path, uploaded_at, evidence_notes
                FROM case_documents
                WHERE complaint_id = ?
                ORDER BY uploaded_at DESC
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT id, document_name, document_type, file_path, uploaded_at
                FROM case_documents
                WHERE complaint_id = ?
                ORDER BY uploaded_at DESC
            ");
        }
        $stmt->execute([$case['id']]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check if counter affidavit exists
        foreach ($documents as $doc) {
            if ($doc['document_type'] === 'counter_affidavit') {
                $has_counter_affidavit = true;
                break;
            }
        }
    } catch (PDOException $e) {
        $documents = [];
    }
}

// If no case found, redirect
if (!$case) {
    header("Location: dashboard.php?error=no_cases");
    exit();
}

// Format complainant/respondent display
$comp_display = $case['complainant_name'];
if ($case['complainant_age']) $comp_display .= ', ' . $case['complainant_age'];
if ($case['complainant_sex']) $comp_display .= '/' . $case['complainant_sex'];
if ($case['complainant_civil']) $comp_display .= ', ' . $case['complainant_civil'];

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
    <title>Investigation & Review - Office of the Prosecutor</title>
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

        .case-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }

        .case-card h5 {
            margin-bottom: 15px;
            color: var(--dark-navy);
            font-weight: 600;
        }

        .status-badge {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-new { background: #e3f2fd; color: #1565c0; }
        .status-investigation { background: #fff3e0; color: #e65100; }
        .status-resolution { background: #fce4ec; color: #c2185b; }
        .status-filed { background: #e8f5e9; color: #2e7d32; }
        .status-dismissed { background: #f5f5f5; color: #616161; }

        .document-list {
            list-style: none;
            padding: 0;
        }

        .document-list li {
            padding: 12px;
            border: 1px solid #eee;
            border-radius: 6px;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .document-list li i {
            color: var(--primary-blue);
            margin-right: 10px;
        }

        .action-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            margin-right: 5px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-subpoena {
            background: #fff3e0;
            color: #e65100;
        }

        .btn-subpoena:hover {
            background: #ffe0b2;
        }

        .btn-counter {
            background: #e3f2fd;
            color: #1565c0;
        }

        .btn-counter:hover {
            background: #bbdefb;
        }

        .btn-complete {
            background: var(--success-green);
            color: white;
        }

        .btn-complete:hover {
            background: #0d8a4a;
        }

        .evidence-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 10px;
        }

        .timeline {
            position: relative;
            padding-left: 30px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #ddd;
        }

        .timeline-item {
            position: relative;
            padding-bottom: 20px;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -26px;
            top: 0;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--primary-blue);
        }

        .timeline-date {
            font-size: 12px;
            color: #666;
        }

        .tabs {
            display: flex;
            border-bottom: 2px solid #eee;
            margin-bottom: 20px;
        }

        .tab {
            padding: 12px 20px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.3s;
        }

        .tab.active {
            color: var(--primary-blue);
            border-bottom-color: var(--primary-blue);
        }

        .notes-area {
            width: 100%;
            min-height: 100px;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
            resize: vertical;
        }
    </style>
    <script>
        const caseId = <?php echo $case_id; ?>;
        
        function showTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
            document.getElementById(tabName).style.display = 'block';
            event.target.classList.add('active');
        }

        function generateSubpoena() {
            if (!caseId) {
                alert('No case selected');
                return;
            }
            window.open('generate_subpoena.php?case_id=' + caseId, 'subpoena_tab');
        }

        function generateCounterAffidavit() {
            if (!caseId) {
                alert('No case selected');
                return;
            }
            window.open('generate_counter_affidavit.php?case_id=' + caseId, 'counter_affidavit_tab');
        }

        function issueSubpoena() {
            if (!caseId) {
                alert('No case selected');
                return;
            }
            
            if (confirm('Are you sure you want to issue a subpoena for this case?')) {
                fetch('api_case_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=issue_subpoena&case_id=' + caseId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Subpoena issued successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error issuing subpoena: ' + error);
                });
            }
        }

        function receiveCounter() {
            if (!caseId) {
                alert('No case selected');
                return;
            }
            
            if (confirm('Are you sure you want to mark the counter-affidavit as received?')) {
                fetch('api_case_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=receive_counter&case_id=' + caseId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Counter-affidavit received!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error receiving counter-affidavit: ' + error);
                });
            }
        }

        function completeInvestigation() {
            if (!caseId) {
                alert('No case selected');
                return;
            }
            
            if (confirm('Are you sure you want to complete the investigation? This will forward the case for resolution.')) {
                fetch('api_case_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=complete_investigation&case_id=' + caseId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Investigation completed and case forwarded for resolution');
                        // Redirect to the next case in investigation queue or to resolution page with case_id
                        window.location.href = 'resolution.php?case_id=' + caseId;
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error completing investigation: ' + error);
                });
            }
        }

        function requestReply() {
            if (!caseId) {
                alert('No case selected');
                return;
            }
            
            if (confirm('Request reply-affidavit for this case? This will allow the complainant to file their reply.')) {
                fetch('api_case_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=allow_reply&case_id=' + caseId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Reply-affidavit request approved! Opening reply-affidavit generator...');
                        window.open('generate_reply_affidavit.php?case_id=' + caseId, 'reply_affidavit_tab');
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error requesting reply: ' + error);
                });
            }
        }

        function showAddWitnessModal() {
            const modal = new bootstrap.Modal(document.getElementById('addWitnessModal'));
            modal.show();
        }

        function saveWitness() {
            if (!caseId) {
                alert('No case selected');
                return;
            }

            const form = document.getElementById('addWitnessForm');
            const fullName = form.querySelector('[name="full_name"]').value.trim();
            const address = form.querySelector('[name="address"]').value.trim();
            const contactNumber = form.querySelector('[name="contact_number"]').value.trim();
            const statement = form.querySelector('[name="statement"]').value.trim();

            if (!fullName) {
                alert('Please enter witness name');
                return;
            }

            fetch('api_case_actions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=add_witness&case_id=' + caseId + '&full_name=' + encodeURIComponent(fullName) + '&address=' + encodeURIComponent(address) + '&contact_number=' + encodeURIComponent(contactNumber) + '&statement=' + encodeURIComponent(statement)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Witness added successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error adding witness: ' + error);
            });
        }

        function saveAllEvidenceNotes() {
            if (!caseId) {
                alert('No case selected');
                return;
            }

            // Get all evidence note textareas
            const noteTextareas = document.querySelectorAll('.evidence-notes');
            const notesToSave = [];

            noteTextareas.forEach(textarea => {
                const documentId = textarea.getAttribute('data-document-id');
                const notes = textarea.value;

                if (documentId) {
                    notesToSave.push({
                        documentId: documentId,
                        notes: notes
                    });
                }
            });

            if (notesToSave.length === 0) {
                alert('No notes to save');
                return;
            }

            if (!confirm(`Save notes for ${notesToSave.length} documents?`)) {
                return;
            }

            // Save each note
            let completed = 0;
            let failed = 0;

            notesToSave.forEach(item => {
                fetch('api_case_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=save_evidence_note&case_id=' + caseId + '&document_id=' + item.documentId + '&notes=' + encodeURIComponent(item.notes)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        completed++;
                    } else {
                        failed++;
                    }

                    // Check if all requests are complete
                    if (completed + failed === notesToSave.length) {
                        if (failed === 0) {
                            alert(`Successfully saved all ${completed} notes!`);
                        } else {
                            alert(`Saved ${completed} notes. ${failed} failed.`);
                        }
                    }
                })
                .catch(error => {
                    failed++;
                    console.error('Error saving evidence note:', error);
                });
            });
        }

        function saveEvidenceNote(documentId) {
            if (!caseId) {
                alert('No case selected');
                return;
            }

            const textarea = document.querySelector(`textarea[data-document-id="${documentId}"]`);
            const notes = textarea.value;

            fetch('api_case_actions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=save_evidence_note&case_id=' + caseId + '&document_id=' + documentId + '&notes=' + encodeURIComponent(notes)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Evidence notes saved successfully!');
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error saving evidence notes: ' + error);
            });
        }

        function addGeneralEvidenceNote() {
            const note = prompt('Enter general evidence note:');
            if (note && note.trim()) {
                fetch('api_case_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=add_general_evidence_note&case_id=' + caseId + '&note=' + encodeURIComponent(note)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('General evidence note added successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error adding general evidence note: ' + error);
                });
            }
        }

        function uploadDocument() {
            if (!caseId) {
                alert('No case selected');
                return;
            }

            const form = document.getElementById('uploadDocumentForm');
            const formData = new FormData(form);
            formData.append('action', 'upload_document');
            formData.append('case_id', caseId);

            const fileInput = form.querySelector('input[name="document_file"]');
            if (fileInput.files.length === 0) {
                alert('Please select a file to upload');
                return;
            }

            formData.append('document_file', fileInput.files[0]);

            fetch('api_case_actions.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Document uploaded successfully!');
                    // Close modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('uploadDocumentModal'));
                    modal.hide();
                    // Reset form
                    form.reset();
                    // Reload page to show new document
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error uploading document: ' + error);
            });
        }

        function addAllToEvidence() {
            if (!caseId) {
                alert('No case selected');
                return;
            }

            // Get all document IDs from the document list
            const documentItems = document.querySelectorAll('.document-list li[data-document-id]');
            const documentIds = [];

            documentItems.forEach(item => {
                const docId = item.getAttribute('data-document-id');
                if (docId) {
                    documentIds.push(docId);
                }
            });

            if (documentIds.length === 0) {
                alert('No documents to add to evidence');
                return;
            }

            if (!confirm(`Add all ${documentIds.length} documents to evidence?`)) {
                return;
            }

            // Add each document to evidence
            let completed = 0;
            let failed = 0;

            documentIds.forEach(docId => {
                fetch('api_case_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=add_to_evidence&case_id=' + caseId + '&document_id=' + docId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        completed++;
                    } else {
                        failed++;
                    }

                    // Check if all requests are complete
                    if (completed + failed === documentIds.length) {
                        if (failed === 0) {
                            alert(`Successfully added all ${completed} documents to evidence!`);
                        } else {
                            alert(`Added ${completed} documents to evidence. ${failed} failed.`);
                        }
                        location.reload();
                    }
                })
                .catch(error => {
                    failed++;
                    console.error('Error adding document to evidence:', error);
                });
            });
        }

        function addToEvidence(documentId) {
            if (!caseId) {
                alert('No case selected');
                return;
            }

            if (confirm('Add this document to evidence? This will mark it as evidence for the case.')) {
                fetch('api_case_actions.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=add_to_evidence&case_id=' + caseId + '&document_id=' + documentId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Document added to evidence successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error adding to evidence: ' + error);
                });
            }
        }
    </script>
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
                    <h2>Investigation & Review</h2>
                    <p class="text-muted mb-0">Case details, evidence analysis, and investigation actions</p>
                </div>
                <div class="text-end">
                    <select class="form-select form-select-sm mb-2" style="width: 250px;" onchange="if(this.value) window.location.href='investigation.php?case_id='+this.value;">
                        <option value="">Select Case to Review...</option>
                        <?php foreach ($cases as $id => $c): ?>
                        <option value="<?php echo $id; ?>" <?php echo $id == $case_id ? 'selected' : ''; ?>><?php echo $c['case_number']; ?> - <?php echo $c['offense']; ?> (<?php echo $c['complainant_name']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <br>
                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $case['status'])); ?>"><?php echo $case['status']; ?></span>
                </div>
            </div>

            <!-- Case Summary -->
            <div class="case-card">
                <div class="row">
                    <div class="col-md-8">
                        <h5>Case #<?php echo $case['case_number']; ?> | NPS Docket: <?php echo $case['nps_docket']; ?></h5>
                        <div class="row">
                            <div class="col-md-6">
                                <p class="text-muted mb-1"><strong>Complainant:</strong> <?php echo $comp_display; ?></p>
                                <p class="text-muted mb-1"><strong>Respondent:</strong> <?php echo $resp_display; ?></p>
                                <p class="text-muted mb-1"><strong>Offense:</strong> <?php echo $case['offense']; ?> - <?php echo $case['law_violated']; ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-muted mb-1"><strong>Date/Time Received:</strong> <?php echo date('M d, Y', strtotime($case['date_received'])); ?> @ <?php echo $case['time_received']; ?></p>
                                <p class="text-muted mb-1"><strong>Date Filed:</strong> <?php echo $case['date_filed']; ?></p>
                                <p class="text-muted mb-1"><strong>Assigned Prosecutor:</strong> <?php echo $case['assigned_prosecutor']; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <h6>Case Timeline</h6>
                        <div class="timeline">
                            <div class="timeline-item">
                                <div class="timeline-date"><?php echo date('M d, Y', strtotime($case['date_received'])); ?></div>
                                <div>Case Intake - <?php echo $case['nps_docket']; ?></div>
                            </div>
                            <?php if (!empty($case['investigation_start_date'])): ?>
                            <div class="timeline-item">
                                <div class="timeline-date"><?php echo date('M d, Y', strtotime($case['investigation_start_date'])); ?></div>
                                <div>Preliminary Investigation Started</div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($case['subpoena_issued_date'])): ?>
                            <div class="timeline-item">
                                <div class="timeline-date"><?php echo date('M d, Y', strtotime($case['subpoena_issued_date'])); ?></div>
                                <div>Subpoena Issued to Respondent</div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($case['resolution_date'])): ?>
                            <div class="timeline-item">
                                <div class="timeline-date"><?php echo date('M d, Y', strtotime($case['resolution_date'])); ?></div>
                                <div>Resolution Completed</div>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($case['date_filed'])): ?>
                            <div class="timeline-item">
                                <div class="timeline-date"><?php echo date('M d, Y', strtotime($case['date_filed'])); ?></div>
                                <div>Case Filed in Court</div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <div class="tab active" onclick="showTab('details')">Case Details</div>
                <div class="tab" onclick="showTab('documents')">Documents</div>
                <div class="tab" onclick="showTab('evidence')">Evidence Analysis</div>
                <div class="tab" onclick="showTab('actions')">Actions</div>
            </div>

            <!-- Tab Content: Details -->
            <div id="details" class="tab-content">
                <div class="case-card">
                    <div class="row">
                        <div class="col-md-6">
                            <h5>Incident Details</h5>
                            <table class="table table-sm">
                                <tr><td><strong>Date of Commission:</strong></td><td><?php echo $case['incident_date']; ?></td></tr>
                                <tr><td><strong>Time of Commission:</strong></td><td><?php echo $case['incident_time']; ?></td></tr>
                                <tr><td><strong>Place of Commission:</strong></td><td><?php echo $case['incident_place']; ?></td></tr>
                                <tr><td><strong>Law Violated:</strong></td><td><?php echo $case['law_violated']; ?></td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h5>Related Case Information</h5>
                            <table class="table table-sm">
                                <tr><td><strong>Similar complaint elsewhere?</strong></td><td><span class="badge bg-secondary"><?php echo $case['similar_complaint']; ?></span></td></tr>
                                <tr><td><strong>Respondent aware?</strong></td><td><span class="badge bg-secondary"><?php echo $case['respondent_aware']; ?></span></td></tr>
                                <tr><td><strong>Related to pending case?</strong></td><td><span class="badge bg-secondary"><?php echo $case['related_case']; ?></span></td></tr>
                            </table>
                        </div>
                    </div>
                    
                    <h5 class="mt-4">Case Summary</h5>
                    <div class="bg-light p-3 rounded">
                        <p class="mb-0"><?php echo $case['case_summary']; ?></p>
                    </div>
                    
                    <h5 class="mt-4">Witnesses</h5>
                    <button class="btn btn-sm btn-outline-primary mb-3" onclick="showAddWitnessModal()">
                        <i class="fas fa-plus"></i> Add Witness
                    </button>
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Address</th>
                                <th>Contact</th>
                                <th>Statement</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($witnesses)): ?>
                                <?php foreach ($witnesses as $witness): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($witness['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($witness['address']); ?></td>
                                    <td><?php echo htmlspecialchars($witness['contact_number']); ?></td>
                                    <td>
                                        <?php if ($witness['statement']): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#witnessModal<?php echo $witness['id']; ?>">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted">No statement</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted">No witnesses recorded</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tab Content: Documents -->
            <div id="documents" class="tab-content" style="display:none;">
                <div class="case-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5>Submitted Documents</h5>
                        <button class="btn btn-sm btn-success" onclick="addAllToEvidence()">
                            <i class="fas fa-plus"></i> Add All to Evidence
                        </button>
                    </div>
                    <ul class="document-list">
                        <?php if (!empty($documents)): ?>
                            <?php foreach ($documents as $doc): ?>
                                <?php 
                                    $doc['file_path'] = '../' . $doc['file_path'];
                                    $file_exists = file_exists($doc['file_path']);
                                ?>
                                <li data-document-id="<?php echo $doc['id']; ?>">
                                    <span>
                                        <i class="fas fa-file-pdf"></i> <?php echo htmlspecialchars($doc['document_name']); ?>
                                        <small class="text-muted">(<?php echo htmlspecialchars($doc['document_type']); ?>)</small>
                                    </span>
                                    <div>
                                        <?php if ($file_exists): ?>
                                            <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">File not found</span>
                                        <?php endif; ?>
                                        <small class="text-muted"><?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?></small>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="text-muted">No documents uploaded for this case</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Tab Content: Evidence -->
            <div id="evidence" class="tab-content" style="display:none;">
                <div class="case-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5>Evidence Analysis</h5>
                        <button class="btn btn-sm btn-success" onclick="saveAllEvidenceNotes()">
                            <i class="fas fa-save"></i> Save All Notes
                        </button>
                    </div>

                    <div class="mb-4">
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                            <i class="fas fa-upload me-2"></i>Upload Document
                        </button>
                    </div>

                    <?php if (!empty($documents)): ?>
                        <?php foreach ($documents as $doc): ?>
                        <div class="evidence-item mb-3 p-3 bg-light rounded">
                            <div class="d-flex justify-content-between">
                                <strong><?php echo htmlspecialchars($doc['document_name']); ?></strong>
                                <span class="badge bg-info"><?php echo htmlspecialchars($doc['document_type']); ?></span>
                            </div>
                            <p class="text-muted mb-1">Submitted: <?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?></p>
                            <div class="mb-2">
                                <label class="form-label small">Prosecutor's Analysis Notes:</label>
                                <textarea class="form-control evidence-notes" rows="3"
                                    data-document-id="<?php echo $doc['id']; ?>"
                                    placeholder="Enter prosecutor's analysis notes for this evidence..."><?php echo htmlspecialchars($doc['evidence_notes'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">No documents/evidence uploaded for this case</p>
                    <?php endif; ?>

                    <div class="text-end mt-3">
                        <button class="btn btn-outline-primary" onclick="addGeneralEvidenceNote()">
                            <i class="fas fa-plus"></i> Add General Evidence Note
                        </button>
                    </div>
                    
                    <div id="general-evidence-notes" class="mt-3">
                        <?php 
                        // Fetch general evidence notes
                        try {
                            $stmt = $pdo->prepare("SELECT * FROM evidence_notes WHERE complaint_id = ? AND document_id IS NULL ORDER BY created_at DESC");
                            $stmt->execute([$case_id]);
                            $general_notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($general_notes as $note): 
                            ?>
                            <div class="mb-2 p-2 bg-white border rounded">
                                <small class="text-muted"><?php echo date('M d, Y H:i', strtotime($note['created_at'])); ?></small>
                                <p class="mb-0"><?php echo htmlspecialchars($note['note']); ?></p>
                            </div>
                            <?php endforeach; 
                        } catch (PDOException $e) {
                            // Table doesn't exist yet, will be created when first note is added
                            echo '<p class="text-muted small">No general evidence notes yet</p>';
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- Tab Content: Actions -->
            <div id="actions" class="tab-content" style="display:none;">
                <div class="case-card">
                    <h5>Investigation Actions</h5>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6><i class="fas fa-envelope" style="color: var(--primary-blue);"></i> Issue Subpoena</h6>
                                    <p class="text-muted">Issue subpoena to respondent for counter-affidavit submission</p>
                                    <button class="action-btn btn-subpoena" onclick="generateSubpoena()">
                                        <i class="fas fa-file-pdf"></i> Generate Subpoena
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6><i class="fas fa-file-alt text-success"></i> Receive Counter-Affidavit</h6>
                                    <p class="text-muted">Record receipt of respondent's counter-affidavit</p>
                                    <button class="action-btn btn-counter" onclick="generateCounterAffidavit()">
                                        <i class="fas fa-file-pdf"></i> Generate Form
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6><i class="fas fa-reply" style="color: var(--warning-yellow);"></i> Request Reply</h6>
                                    <p class="text-muted">Generate reply-affidavit form for complainant</p>
                                    <button class="action-btn" style="background: #fff8e1; color: #f57f17;" 
                                            onclick="requestReply()" 
                                            <?php echo $has_counter_affidavit ? '' : 'disabled style="opacity: 0.5; cursor: not-allowed;"'; ?>>
                                        <i class="fas fa-comment"></i> Request Reply
                                    </button>
                                    <?php if (!$has_counter_affidavit): ?>
                                        <small class="text-muted d-block mt-2">
                                            <i class="fas fa-info-circle"></i> Counter-affidavit must be submitted first
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <div class="card h-100">
                                <div class="card-body">
                                    <h6><i class="fas fa-clipboard-check" style="color: var(--success-green);"></i> Complete Investigation</h6>
                                    <p class="text-muted">Mark investigation as complete and forward for resolution</p>
                                    <button class="action-btn btn-complete" onclick="completeInvestigation()">
                                        <i class="fas fa-check-circle"></i> Complete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Witness Statement Modals -->
    <?php if (!empty($witnesses)): ?>
        <?php foreach ($witnesses as $witness): ?>
            <?php if ($witness['statement']): ?>
            <div class="modal fade" id="witnessModal<?php echo $witness['id']; ?>" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Witness Statement - <?php echo htmlspecialchars($witness['full_name']); ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <strong>Address:</strong> <?php echo htmlspecialchars($witness['address']); ?>
                            </div>
                            <div class="mb-3">
                                <strong>Contact:</strong> <?php echo htmlspecialchars($witness['contact_number']); ?>
                            </div>
                            <div class="mb-3">
                                <strong>Statement:</strong>
                                <div class="mt-2 p-3 bg-light border rounded">
                                    <?php echo nl2br(htmlspecialchars($witness['statement'])); ?>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Add Witness Modal -->
    <div class="modal fade" id="addWitnessModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Witness</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addWitnessForm">
                        <div class="mb-3">
                            <label class="form-label">Full Name <span class="required">*</span></label>
                            <input type="text" class="form-control" name="full_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Address</label>
                            <input type="text" class="form-control" name="address">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contact Number</label>
                            <input type="text" class="form-control" name="contact_number">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Statement</label>
                            <textarea class="form-control" name="statement" rows="4" placeholder="Enter witness statement..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveWitness()">Save Witness</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Upload Document Modal -->
    <div class="modal fade" id="uploadDocumentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Document to Evidence</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="uploadDocumentForm">
                        <div class="mb-3">
                            <label class="form-label">Document Name <span class="required">*</span></label>
                            <input type="text" class="form-control" name="document_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Document Type <span class="required">*</span></label>
                            <select class="form-select" name="document_type" required>
                                <option value="">Select type...</option>
                                <option value="complaint_affidavit">Complaint Affidavit</option>
                                <option value="inv_form">Investigation Form</option>
                                <option value="counter_affidavit">Counter-Affidavit</option>
                                <option value="reply_affidavit">Reply-Affidavit</option>
                                <option value="evidence">Evidence</option>
                                <option value="subpoena">Subpoena</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">File <span class="required">*</span></label>
                            <input type="file" class="form-control" name="document_file" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="uploadDocument()">Upload Document</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

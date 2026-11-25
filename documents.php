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

// Include database connection
require_once 'db.php';

// Get documents from database grouped by case
try {
    $stmt = $pdo->prepare("
        SELECT cd.id, cd.document_name as name, cd.document_type as type, 
               cd.uploaded_at as date, cd.file_path, cd.complaint_id,
               c.case_number as case_no, resp.full_name as respondent,
               c.offense
        FROM case_documents cd
        LEFT JOIN complaints c ON cd.complaint_id = c.id
        LEFT JOIN respondents resp ON c.respondent_id = resp.id
        WHERE c.court_branch = ?
        ORDER BY c.case_number ASC, cd.uploaded_at DESC
    ");
    $stmt->execute([$branch_number]);
    $all_documents = $stmt->fetchAll();
    
    // Group documents by case
    $documents_by_case = [];
    foreach ($all_documents as $doc) {
        $case_id = $doc['complaint_id'];
            // Root uploads are shared by Fiscal and other user pages.
            if (strpos($doc['file_path'], 'uploads/') === 0) {
                $doc['file_path'] = '../' . $doc['file_path'];
        }
        if (!isset($documents_by_case[$case_id])) {
            $documents_by_case[$case_id] = [
                'case_no' => $doc['case_no'],
                'respondent' => $doc['respondent'] ?? 'Unknown',
                'offense' => $doc['offense'] ?? 'N/A',
                'documents' => []
            ];
        }
        $documents_by_case[$case_id]['documents'][] = $doc;
    }
} catch (PDOException $e) {
    $documents_by_case = [];
}

// Get cases assigned to current branch for the dropdown
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.case_number, c.offense,
               resp.full_name as respondent
        FROM complaints c
        LEFT JOIN respondents resp ON c.respondent_id = resp.id
        WHERE c.court_branch = ?
        ORDER BY c.date_filed DESC
    ");
    $stmt->execute([$branch_number]);
    $branch_cases = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $branch_cases = [];
}

// Get search and filter parameters
$search = isset($_GET['search']) ? strtolower(trim($_GET['search'])) : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$case_filter = isset($_GET['case']) ? intval($_GET['case']) : 0;

// Filter documents by case first
if ($case_filter) {
    foreach ($documents_by_case as $case_id => $case_data) {
        if ($case_id != $case_filter) {
            unset($documents_by_case[$case_id]);
        }
    }
}

// Then filter within remaining cases by search and type
foreach ($documents_by_case as $case_id => $case_data) {
    $filtered_docs = [];
    foreach ($case_data['documents'] as $doc) {
        $include = true;
        
        // Search filter
        if ($search) {
            $found = false;
            foreach (['name', 'type', 'case_no'] as $field) {
                if (strpos(strtolower($doc[$field] ?? ''), $search) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) $include = false;
        }
        
        // Type filter
        if ($type_filter && $doc['type'] !== $type_filter) {
            $include = false;
        }
        
        if ($include) {
            $filtered_docs[] = $doc;
        }
    }
    
    // Remove case if no documents match filters
    if (empty($filtered_docs)) {
        unset($documents_by_case[$case_id]);
    } else {
        $documents_by_case[$case_id]['documents'] = $filtered_docs;
    }
}

// Get unique document types for dropdown
$all_doc_types = [];
foreach ($all_documents as $doc) {
    if (!empty($doc['type'])) {
        $all_doc_types[] = $doc['type'];
    }
}
$doc_types = array_unique($all_doc_types);
sort($doc_types);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents - <?php echo $branch_name; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary-purple: #9c27b0; --light-gray: #f5f5f5; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--light-gray); margin: 0; }
        .app-container { display: flex; min-height: 100vh; }
        .main-content { flex: 1; padding: 20px; }
        .top-header { background: white; padding: 15px 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .doc-item { display: flex; justify-content: space-between; align-items: center; padding: 15px; border-bottom: 1px solid #eee; }
        .doc-icon { font-size: 32px; color: #e74c3c; margin-right: 15px; }
        .btn-primary { background: var(--primary-purple); border: none; }
        .search-box { position: relative; }
        .search-box i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #888; }
        .search-box input { padding-left: 35px; border-radius: 20px; border: 1px solid #ddd; }
        .filter-bar { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .no-results { text-align: center; padding: 40px; color: #666; }
        .case-section { margin-bottom: 20px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; }
        .case-header { background: #f8f9fa; padding: 15px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; }
        .case-header:hover { background: #f0f0f0; }
        .case-documents { padding: 0; display: none; }
        .case-documents.show { display: block; }
        .doc-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; border-bottom: 1px solid #eee; }
        .doc-item:last-child { border-bottom: none; }
        .case-badge { background: var(--primary-purple); color: white; padding: 4px 10px; border-radius: 12px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/navigation_branch.php'; ?>
        <div class="main-content">
            <div class="top-header d-flex justify-content-between align-items-center">
                <div>
                    <h4><i class="fas fa-file-alt me-2" style="color: var(--primary-purple);"></i>Documents</h4>
                    <small class="text-muted"><?php echo $branch_name; ?></small>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal"><i class="fas fa-upload me-1"></i>Upload Document</button>
            </div>

            <!-- Search & Filter Bar -->
            <div class="filter-bar">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" class="form-control" placeholder="Search documents..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <select name="case" class="form-select">
                            <option value="">All Cases</option>
                            <?php foreach ($branch_cases as $case): ?>
                            <option value="<?php echo $case['id']; ?>" <?php echo $case_filter == $case['id'] ? 'selected' : ''; ?>>
                                <?php echo $case['case_number'] ?? 'N/A'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="type" class="form-select">
                            <option value="">All Types</option>
                            <?php foreach ($doc_types as $type): ?>
                            <option value="<?php echo $type; ?>" <?php echo $type_filter == $type ? 'selected' : ''; ?>><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i>Filter</button>
                    </div>
                </form>
                <?php if ($search || $type_filter || $case_filter): ?>
                <div class="mt-2">
                    <a href="documents.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times me-1"></i>Clear Filters</a>
                    <span class="text-muted ms-2"><?php 
                        $filtered_count = 0;
                        foreach ($documents_by_case as $case_data) {
                            $filtered_count += count($case_data['documents']);
                        }
                        echo $filtered_count; 
                    ?> document(s) in <?php echo count($documents_by_case); ?> case(s)</span>
                </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Case Documents</h5>
                    <span class="badge bg-primary"><?php echo count($all_documents); ?> Documents</span>
                </div>
                
                <?php if (count($documents_by_case) > 0): ?>
                <?php foreach ($documents_by_case as $case_id => $case_data): ?>
                <div class="case-section">
                    <div class="case-header" onclick="toggleCase(<?php echo $case_id; ?>)">
                        <div>
                            <strong><?php echo $case_data['case_no']; ?></strong>
                            <span class="text-muted">| <?php echo $case_data['respondent']; ?></span>
                            <div class="small text-muted"><?php echo $case_data['offense']; ?></div>
                        </div>
                        <div>
                            <span class="case-badge"><?php echo count($case_data['documents']); ?> documents</span>
                            <i class="fas fa-chevron-down ms-2" id="chevron-<?php echo $case_id; ?>"></i>
                        </div>
                    </div>
                    <div class="case-documents" id="case-<?php echo $case_id; ?>">
                        <?php foreach ($case_data['documents'] as $doc): ?>
                        <div class="doc-item">
                            <div class="d-flex align-items-center">
                                <div class="doc-icon"><i class="fas fa-file-pdf"></i></div>
                                <div>
                                    <strong><?php echo $doc['name']; ?></strong>
                                    <div class="text-muted"><?php echo $doc['type']; ?> | <?php echo date('M d, Y', strtotime($doc['date'])); ?></div>
                                </div>
                            </div>
                            <div>
                                <button class="btn btn-sm btn-outline-primary me-1" onclick="viewDocument(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['name']); ?>')"><i class="fas fa-eye"></i></button>
                                <a href="#" class="btn btn-sm btn-outline-secondary me-1" onclick="downloadDocument(<?php echo $doc['id']; ?>); return false;"><i class="fas fa-download"></i></a>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteDocument(<?php echo $doc['id']; ?>, '<?php echo htmlspecialchars($doc['name']); ?>')"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="no-results">
                    <i class="fas fa-search fa-3x text-muted mb-3"></i>
                    <h5>No documents found</h5>
                    <p>Try adjusting your search or filter criteria</p>
                    <a href="documents.php" class="btn btn-primary btn-sm">Clear Filters</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Upload Document Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--primary-purple); color: white;">
                    <h5 class="modal-title"><i class="fas fa-cloud-upload-alt me-2"></i>Upload Document</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="uploadForm" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Select Case</label>
                            <select class="form-select" name="case_id" required>
                                <option value="">-- Select Case --</option>
                                <?php foreach ($branch_cases as $case): ?>
                                <option value="<?php echo $case['id']; ?>">
                                    <?php echo $case['case_number'] ?? 'N/A'; ?> - People vs. <?php echo $case['respondent'] ?? 'Unknown'; ?>
                                </option>
                                <?php endforeach; ?>
                                <?php if (empty($branch_cases)): ?>
                                <option value="" disabled>No cases assigned to this branch</option>
                                <?php endif; ?>
                            </select>
                        </div>
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
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Document Name/Description</label>
                            <input type="text" class="form-control" name="doc_name" placeholder="e.g., Witness Statement of Maria Garcia" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Select File</label>
                            <input type="file" class="form-control" name="document" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                            <small class="text-muted">Accepted: PDF, Word (DOC/DOCX), Images (JPG/PNG). Max 10MB.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Any additional information..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-upload me-1"></i>Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Document Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--primary-purple); color: white;">
                    <h5 class="modal-title" id="viewDocTitle"><i class="fas fa-file-alt me-2"></i>Document Viewer</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" style="min-height: 500px;">
                    <div id="documentViewer" class="text-center">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Document viewer would display the file here.<br>
                            <small>Supported formats: PDF, Word, Images</small>
                        </div>
                        <div class="mt-4">
                            <i class="fas fa-file-pdf fa-5x text-danger mb-3"></i>
                            <p>Preview not available in demo mode</p>
                            <button class="btn btn-primary" onclick="downloadCurrentDoc()">
                                <i class="fas fa-download me-1"></i>Download to View
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="downloadCurrentDoc()">
                        <i class="fas fa-download me-1"></i>Download
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Toast -->
    <div class="toast-container position-fixed top-0 end-0 p-3">
        <div id="successToast" class="toast align-items-center text-white bg-success" role="alert">
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
    let currentDocId = null;

    // Handle upload form submission
    document.getElementById('uploadForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const fileInput = this.querySelector('input[name="document"]');
        const file = fileInput.files[0];
        
        if (!file) {
            alert('Please select a file to upload');
            return;
        }
        
        // Validate file size (10MB max)
        if (file.size > 10 * 1024 * 1024) {
            alert('File size too large. Maximum is 10MB.');
            return;
        }
        
        // In real implementation, send to PHP via AJAX
        // For demo, show success and log
        console.log('Uploading:', {
            case: formData.get('case_id'),
            type: formData.get('doc_type'),
            name: formData.get('doc_name'),
            file: file.name,
            size: file.size
        });
        
        // Show success
        const modal = bootstrap.Modal.getInstance(document.getElementById('uploadModal'));
        modal.hide();
        
        const toast = new bootstrap.Toast(document.getElementById('successToast'));
        toast.show();
        
        // Reset form
        this.reset();
        
        // Alert user (in real app, would refresh list)
        setTimeout(() => {
            alert('Document would be saved to: uploads/cases/' + formData.get('case_id') + '/');
        }, 500);
    });

    // View document function
    function viewDocument(docId, docName) {
        currentDocId = docId;
        document.getElementById('viewDocTitle').innerHTML = '<i class="fas fa-file-alt me-2"></i>' + docName;
        
        // Check if this is a generated document (warrant/summons)
        fetch('api_branch_actions.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=get_document_info&doc_id=' + docId
        })
        .then(response => response.json())
        .then(data => {
            let viewerContent = '';
            
            if (data.success && (data.type === 'Warrant' || data.type === 'Summons')) {
                // Display generated HTML document in iframe
                viewerContent = `
                    <iframe src="download_document.php?file=${encodeURIComponent(data.file_path)}&name=${encodeURIComponent(data.name)}&view=1" style="width: 100%; height: 500px; border: none;">
                    </iframe>
                `;
                document.getElementById('documentViewer').innerHTML = viewerContent;
            } else {
                // Display uploaded document in iframe
                const fileExtension = data.file_path.split('.').pop().toLowerCase();
                
                if (['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExtension)) {
                    // Use download script with view parameter
                    viewerContent = `
                        <iframe src="download_document.php?file=${encodeURIComponent(data.file_path)}&name=${encodeURIComponent(data.name)}&view=1" style="width: 100%; height: 500px; border: none;">
                        </iframe>
                    `;
                } else if (['doc', 'docx', 'xls', 'xlsx'].includes(fileExtension)) {
                    // For Office documents, show download message
                    viewerContent = `
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            This document type (${fileExtension.toUpperCase()}) cannot be previewed in the browser.
                            <br><br>
                            <strong>Please download the file to view it.</strong>
                        </div>
                        <div class="mt-4">
                            <i class="fas fa-file-word fa-5x text-primary mb-3"></i>
                            <button class="btn btn-primary" onclick="downloadCurrentDoc()">
                                <i class="fas fa-download me-1"></i>Download to View
                            </button>
                        </div>
                    `;
                } else {
                    // Unknown file type
                    viewerContent = `
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Unknown file type: ${fileExtension}
                        </div>
                        <button class="btn btn-primary" onclick="downloadCurrentDoc()">
                            <i class="fas fa-download me-1"></i>Download File
                        </button>
                    `;
                }
                
                document.getElementById('documentViewer').innerHTML = viewerContent;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('documentViewer').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    Error loading document information.
                </div>
            `;
        });
        
        const modal = new bootstrap.Modal(document.getElementById('viewModal'));
        modal.show();
    }

    // Download document function
    function downloadDocument(docId) {
        // Check if this is a generated document
        fetch('api_branch_actions.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=get_document_info&doc_id=' + docId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Download the file using a download script
                window.open('download_document.php?file=' + encodeURIComponent(data.file_path) + '&name=' + encodeURIComponent(data.name), '_blank');
            } else {
                alert('Error: ' + (data.message || 'Failed to download document'));
            }
        })
        .catch(error => {
            alert('Error downloading document: ' + error);
        });
    }

    // Download current viewed document
    function downloadCurrentDoc() {
        if (currentDocId) {
            downloadDocument(currentDocId);
        }
    }

    // Delete document function
    function deleteDocument(docId, docName) {
        if (confirm('Are you sure you want to delete "' + docName + '"?\n\nThis action cannot be undone.')) {
            const formData = new FormData();
            formData.append('action', 'delete_document');
            formData.append('doc_id', docId);

            fetch('api_branch_actions.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Document deleted successfully');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error deleting document: ' + error);
            });
        }
    }
    
    // Toggle case section visibility
    function toggleCase(caseId) {
        const caseDocs = document.getElementById('case-' + caseId);
        const chevron = document.getElementById('chevron-' + caseId);
        
        if (caseDocs.classList.contains('show')) {
            caseDocs.classList.remove('show');
            chevron.classList.remove('fa-chevron-up');
            chevron.classList.add('fa-chevron-down');
        } else {
            caseDocs.classList.add('show');
            chevron.classList.remove('fa-chevron-down');
            chevron.classList.add('fa-chevron-up');
        }
    }
    </script>
</body>
</html>

<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);

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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("Document intake form submitted");
    error_log("POST data: " . print_r($_POST, true));
    error_log("FILES data: " . print_r($_FILES, true));
    
    // Check if statement column exists in witnesses table
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM witnesses LIKE 'statement'");
        $column_exists = $stmt->fetch();
        
        if (!$column_exists) {
            error_log("Adding statement column to witnesses table");
            $pdo->query("ALTER TABLE witnesses ADD COLUMN statement TEXT");
        }
    } catch (PDOException $e) {
        error_log("Error checking/adding statement column: " . $e->getMessage());
    }
    
    try {
        $pdo->beginTransaction();
        
        // Insert complainant (minimal info)
        $stmt = $pdo->prepare("
            INSERT INTO complainants (full_name, address)
            VALUES (?, ?)
        ");
        $stmt->execute([
            $_POST['doc_comp_name'] ?? 'Unknown',
            $_POST['doc_comp_address'] ?? 'Unknown'
        ]);
        $complainant_id = $pdo->lastInsertId();
        error_log("Complainant inserted with ID: " . $complainant_id);
        
        // Insert respondent (minimal info)
        $stmt = $pdo->prepare("
            INSERT INTO respondents (full_name, address, status, arrest_date, detention_location, custody_details)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_POST['doc_resp_name'] ?? 'Unknown',
            $_POST['doc_resp_address'] ?? 'Unknown',
            $_POST['doc_resp_status'] ?? 'at_large',
            !empty($_POST['doc_arrest_date']) ? $_POST['doc_arrest_date'] : null,
            $_POST['doc_detention_location'] ?? null,
            $_POST['doc_custody_details'] ?? null
        ]);
        $respondent_id = $pdo->lastInsertId();
        error_log("Respondent inserted with ID: " . $respondent_id);
        
        // Generate case number and NPS docket
        $year = date('Y');
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM complaints WHERE YEAR(date_filed) = ?");
        $stmt->execute([$year]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] + 1;
        $case_number = $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
        $nps_docket = 'NPS-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
        
        // Insert complaint
        error_log("Inserting complaint with case number: " . $case_number);
        $stmt = $pdo->prepare("
            INSERT INTO complaints (
                case_number, nps_docket_no, date_received, time_received, date_filed,
                receiving_staff, offense, case_type, offense_type, law_statute,
                case_summary, priority, incident_date, incident_time, incident_place,
                complainant_id, respondent_id, assigned_prosecutor_id, status,
                related_case, related_case_details
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $case_number,
            $nps_docket,
            $_POST['date_received'] ?? date('Y-m-d'),
            $_POST['time_received'] ?? date('H:i'),
            $_POST['date_received'] ?? date('Y-m-d'),
            $_SESSION['full_name'] ?? 'Unknown',
            $_POST['offense'] ?? 'Document Submission',
            $_POST['offense_type'] ?? 'other',
            $_POST['offense_type'] ?? 'other',
            $_POST['law_violated'] ?? '',
            $_POST['case_summary'] ?? 'Case submitted via document intake',
            'normal',
            !empty($_POST['incident_date']) ? $_POST['incident_date'] : null,
            !empty($_POST['incident_time']) ? $_POST['incident_time'] : null,
            $_POST['incident_place'] ?? '',
            $complainant_id,
            $respondent_id,
            $_SESSION['user_id'] ?? null,
            'New',
            $_POST['related_case'] ?? 'no',
            $_POST['related_details'] ?? ''
        ]);
        $complaint_id = $pdo->lastInsertId();
        error_log("Complaint inserted with ID: " . $complaint_id);
        
        // Insert witnesses if provided
        if (isset($_POST['doc_witness_name']) && is_array($_POST['doc_witness_name'])) {
            error_log("Processing witnesses");
            $stmt = $pdo->prepare("
                INSERT INTO witnesses (complaint_id, full_name, address, contact_number, statement)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($_POST['doc_witness_name'] as $index => $witness_name) {
                if (!empty(trim($witness_name))) {
                    error_log("Inserting witness: " . $witness_name);
                    $stmt->execute([
                        $complaint_id,
                        $witness_name,
                        $_POST['doc_witness_address'][$index] ?? '',
                        $_POST['doc_witness_contact'][$index] ?? '',
                        $_POST['doc_witness_statement'][$index] ?? ''
                    ]);
                    error_log("Witness inserted successfully");
                }
            }
            error_log("Witnesses processed");
        }
        
        // Handle file uploads
        error_log("Starting file upload processing");
        $upload_dir = '../uploads/documents/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
            error_log("Created upload directory");
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO case_documents (complaint_id, document_name, document_type, uploaded_at, file_path)
            VALUES (?, ?, ?, NOW(), ?)
        ");
        
        // Upload complaint affidavit
        if (isset($_FILES['complaint_affidavit']) && $_FILES['complaint_affidavit']['error'] === UPLOAD_ERR_OK) {
            error_log("Uploading complaint affidavit");
            $tmp_name = $_FILES['complaint_affidavit']['tmp_name'];
            $file_ext = pathinfo($_FILES['complaint_affidavit']['name'], PATHINFO_EXTENSION);
            $new_filename = uniqid() . '_affidavit_' . $complaint_id . '.' . $file_ext;
            $file_path = $upload_dir . $new_filename;
            $web_path = 'uploads/documents/' . $new_filename;
            
            if (move_uploaded_file($tmp_name, $file_path)) {
                error_log("Affidavit uploaded successfully: " . $web_path);
                $stmt->execute([
                    $complaint_id,
                    $_FILES['complaint_affidavit']['name'],
                    'complaint_affidavit',
                    $web_path
                ]);
            } else {
                error_log("Failed to move affidavit file");
            }
        }
        
        // Upload investigation data
        if (isset($_FILES['investigation_data']) && $_FILES['investigation_data']['error'] === UPLOAD_ERR_OK) {
            error_log("Uploading investigation data");
            $tmp_name = $_FILES['investigation_data']['tmp_name'];
            $file_ext = pathinfo($_FILES['investigation_data']['name'], PATHINFO_EXTENSION);
            $new_filename = uniqid() . '_investigation_' . $complaint_id . '.' . $file_ext;
            $file_path = $upload_dir . $new_filename;
            $web_path = 'uploads/documents/' . $new_filename;
            
            if (move_uploaded_file($tmp_name, $file_path)) {
                error_log("Investigation data uploaded successfully: " . $web_path);
                $stmt->execute([
                    $complaint_id,
                    $_FILES['investigation_data']['name'],
                    'inv_form',
                    $web_path
                ]);
            } else {
                error_log("Failed to move investigation data file");
            }
        }
        
        // Upload police documents (multiple)
        if (isset($_FILES['police_documents']) && !empty($_FILES['police_documents']['name'][0])) {
            error_log("Uploading police documents");
            foreach ($_FILES['police_documents']['name'] as $key => $name) {
                if ($_FILES['police_documents']['error'][$key] === UPLOAD_ERR_OK) {
                    $tmp_name = $_FILES['police_documents']['tmp_name'][$key];
                    $file_ext = pathinfo($name, PATHINFO_EXTENSION);
                    $new_filename = uniqid() . '_police_' . $complaint_id . '_' . $key . '.' . $file_ext;
                    $file_path = $upload_dir . $new_filename;
                    $web_path = 'uploads/documents/' . $new_filename;
                    
                    if (move_uploaded_file($tmp_name, $file_path)) {
                        error_log("Police document uploaded: " . $web_path);
                        $stmt->execute([
                            $complaint_id,
                            $name,
                            'evidence',
                            $web_path
                        ]);
                    } else {
                        error_log("Failed to move police document: " . $name);
                    }
                }
            }
        }
        
        // Upload other documents (multiple)
        if (isset($_FILES['other_documents']) && !empty($_FILES['other_documents']['name'][0])) {
            error_log("Uploading other documents");
            foreach ($_FILES['other_documents']['name'] as $key => $name) {
                if ($_FILES['other_documents']['error'][$key] === UPLOAD_ERR_OK) {
                    $tmp_name = $_FILES['other_documents']['tmp_name'][$key];
                    $file_ext = pathinfo($name, PATHINFO_EXTENSION);
                    $new_filename = uniqid() . '_other_' . $complaint_id . '_' . $key . '.' . $file_ext;
                    $file_path = $upload_dir . $new_filename;
                    $web_path = 'uploads/documents/' . $new_filename;
                    
                    if (move_uploaded_file($tmp_name, $file_path)) {
                        error_log("Other document uploaded: " . $web_path);
                        $stmt->execute([
                            $complaint_id,
                            $name,
                            'other',
                            $web_path
                        ]);
                    } else {
                        error_log("Failed to move other document: " . $name);
                    }
                }
            }
        }
        
        error_log("Committing transaction");
        $pdo->commit();
        error_log("Transaction committed successfully. Redirecting to cases.php");
        header("Location: cases.php?success=document_intake");
        exit();
        
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Error saving case: " . $e->getMessage();
        error_log("Error: " . $error);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Intake - Office of the Prosecutor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #1a73e8;
            --danger-red: #d93025;
            --light-gray: #f8f9fa;
            --dark-navy: #202124;
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
        }
        .form-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .form-card h5 {
            margin-bottom: 20px;
            color: #202124;
            font-weight: 600;
            border-bottom: 2px solid var(--primary-blue);
            padding-bottom: 10px;
        }
        .form-label {
            font-weight: 500;
            color: #555;
        }
        .btn-submit {
            background: var(--primary-blue);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-weight: 500;
        }
        .btn-submit:hover {
            background: #1557b0;
        }
        .required {
            color: var(--danger-red);
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/navigation_fiscal.php'; ?>
        
        <div class="main-content">
            <div class="top-header">
                <div>
                    <h2>Document Intake</h2>
                    <p class="text-muted mb-0">Quick case submission with document uploads</p>
                </div>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form action="" method="POST" enctype="multipart/form-data" onsubmit="return validateForm(event);">
                <div class="form-card">
                    <h5><i class="fas fa-info-circle" style="margin-right: 10px; color: var(--primary-blue);"></i>Basic Information</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Complainant Name <span class="required">*</span></label>
                            <input type="text" class="form-control" name="doc_comp_name" placeholder="Full name of complainant" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Respondent Name <span class="required">*</span></label>
                            <input type="text" class="form-control" name="doc_resp_name" placeholder="Full name of respondent" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Complainant Address</label>
                            <input type="text" class="form-control" name="doc_comp_address" placeholder="Complete address">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Respondent Address</label>
                            <input type="text" class="form-control" name="doc_resp_address" placeholder="Complete address">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Respondent Status</label>
                            <select class="form-select" name="doc_resp_status" id="doc_resp_status" onchange="toggleDocArrestFields()">
                                <option value="at_large">At Large</option>
                                <option value="arrested">Arrested</option>
                                <option value="detained">Detained</option>
                                <option value="on_bail">On Bail</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Arrest Date</label>
                            <input type="date" class="form-control" name="doc_arrest_date" id="doc_arrest_date">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Detention Location</label>
                            <input type="text" class="form-control" name="doc_detention_location" id="doc_detention_location" placeholder="e.g., Catbalogan City Jail">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Custody Details</label>
                            <textarea class="form-control" name="doc_custody_details" id="doc_custody_details" rows="2" placeholder="Additional custody information"></textarea>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Offense/Case Type <span class="required">*</span></label>
                            <input type="text" class="form-control" name="offense" placeholder="e.g., Theft, Estafa, VAWC" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Law Violated/Statute</label>
                            <select class="form-select" name="law_violated">
                                <option value="">Select Law...</option>
                                <option value="RA9165">RA 9165 - Comprehensive Dangerous Drugs Act</option>
                                <option value="RA7610">RA 7610 - Child Protection</option>
                                <option value="RA9262">RA 9262 - VAWC</option>
                                <option value="RA8353">RA 8353 - Anti-Child Abuse</option>
                                <option value="RA10364">RA 10364 - Anti-Trafficking in Persons</option>
                                <option value="RA9208">RA 9208 - Anti-Bullying</option>
                                <option value="RA10591">RA 10591 - Anti-Enforced or Involuntary Disappearance</option>
                                <option value="RA9995">RA 9995 - Anti-Photo and Video Voyeurism</option>
                                <option value="RA9710">RA 9710 - Safe Spaces Act</option>
                                <option value="RPC">Revised Penal Code</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Date Received</label>
                            <input type="date" class="form-control" name="date_received" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Time Received</label>
                            <input type="time" class="form-control" name="time_received" value="<?php echo date('H:i'); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Incident Date</label>
                            <input type="date" class="form-control" name="incident_date">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Incident Time</label>
                            <input type="time" class="form-control" name="incident_time">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Incident Place</label>
                            <input type="text" class="form-control" name="incident_place" placeholder="Location of incident">
                        </div>
                    </div>
                </div>

                <div class="form-card">
                    <h5><i class="fas fa-file-upload" style="margin-right: 10px; color: var(--primary-blue);"></i>Document Uploads</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Complaint Affidavit <span class="required">*</span></label>
                            <input type="file" class="form-control" name="complaint_affidavit" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Investigation Data <span class="required">*</span></label>
                            <input type="file" class="form-control" name="investigation_data" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Police Documents</label>
                            <input type="file" class="form-control" name="police_documents[]" multiple>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Other Documents</label>
                            <input type="file" class="form-control" name="other_documents[]" multiple>
                        </div>
                    </div>
                </div>

                <div class="form-card">
                    <h5><i class="fas fa-users" style="margin-right: 10px; color: var(--primary-blue);"></i>Witnesses (Optional)</h5>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Witness Name</label>
                            <input type="text" class="form-control" name="doc_witness_name[]" placeholder="Full name">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Witness Address</label>
                            <input type="text" class="form-control" name="doc_witness_address[]" placeholder="Complete address">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Witness Contact</label>
                            <input type="text" class="form-control" name="doc_witness_contact[]" placeholder="Contact number">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Witness Statement</label>
                            <textarea class="form-control" name="doc_witness_statement[]" rows="2" placeholder="Witness statement"></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-card">
                    <h5><i class="fas fa-info" style="margin-right: 10px; color: var(--primary-blue);"></i>Additional Information</h5>
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Case Summary</label>
                            <textarea class="form-control" name="case_summary" rows="3" placeholder="Brief summary of the case"></textarea>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Related Case?</label>
                            <select class="form-select" name="related_case">
                                <option value="no">No</option>
                                <option value="yes">Yes</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Related Case Details</label>
                            <input type="text" class="form-control" name="related_details" placeholder="Case number or details">
                        </div>
                    </div>
                </div>

                <div class="text-end">
                    <button type="reset" class="btn btn-secondary me-2">Clear All</button>
                    <button type="submit" class="btn btn-submit">
                        <i class="fas fa-save" style="margin-right: 8px;"></i>Submit Document Intake
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleDocArrestFields() {
            const status = document.getElementById('doc_resp_status').value;
            const arrestDate = document.getElementById('doc_arrest_date');
            const detentionLocation = document.getElementById('doc_detention_location');
            const custodyDetails = document.getElementById('doc_custody_details');
            
            if (status === 'at_large') {
                arrestDate.disabled = true;
                arrestDate.value = '';
                detentionLocation.disabled = true;
                detentionLocation.value = '';
                custodyDetails.disabled = true;
                custodyDetails.value = '';
            } else {
                arrestDate.disabled = false;
                detentionLocation.disabled = false;
                custodyDetails.disabled = false;
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleDocArrestFields();
        });
        
        function validateForm(event) {
            const compName = document.querySelector('input[name="doc_comp_name"]');
            const respName = document.querySelector('input[name="doc_resp_name"]');
            const offense = document.querySelector('input[name="offense"]');
            const affidavit = document.querySelector('input[name="complaint_affidavit"]');
            const investigation = document.querySelector('input[name="investigation_data"]');
            
            if (!compName || !compName.value.trim()) {
                alert('Please enter the complainant name');
                if (compName) compName.focus();
                event.preventDefault();
                return false;
            }
            
            if (!respName || !respName.value.trim()) {
                alert('Please enter the respondent name');
                if (respName) respName.focus();
                event.preventDefault();
                return false;
            }
            
            if (!offense || !offense.value.trim()) {
                alert('Please enter the offense/case type');
                if (offense) offense.focus();
                event.preventDefault();
                return false;
            }
            
            if (!affidavit || affidavit.files.length === 0) {
                alert('Please upload the complaint affidavit');
                if (affidavit) affidavit.focus();
                event.preventDefault();
                return false;
            }
            
            if (!investigation || investigation.files.length === 0) {
                alert('Please upload the investigation data');
                if (investigation) investigation.focus();
                event.preventDefault();
                return false;
            }
            
            return true;
        }
    </script>
</body>
</html>

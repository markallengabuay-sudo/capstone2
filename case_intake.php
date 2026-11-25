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
    error_log("Full intake form submitted");
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
        error_log("Transaction started");
        
        // Full intake - detailed form
        error_log("Starting full intake processing");
        
            // Insert complainant
            error_log("Inserting complainant");
            $stmt = $pdo->prepare("
                INSERT INTO complainants (full_name, age, sex, civil_status, contact_number, address)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['comp_name'] ?? '',
                $_POST['comp_age'] ?? null,
                $_POST['comp_sex'] ?? '',
                $_POST['comp_civil'] ?? '',
                $_POST['comp_contact'] ?? '',
                $_POST['comp_address'] ?? ''
            ]);
            $complainant_id = $pdo->lastInsertId();
            error_log("Complainant ID: " . $complainant_id);
            
            // Insert respondent
            error_log("Inserting respondent");
            $stmt = $pdo->prepare("
                INSERT INTO respondents (full_name, age, sex, status, address, arrest_date, detention_location, custody_details)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['resp_name'] ?? 'Unknown',
                $_POST['resp_age'] ?? null,
                $_POST['resp_sex'] ?? null,
                $_POST['resp_status'] ?? 'At Large',
                $_POST['resp_address'] ?? '',
                !empty($_POST['arrest_date']) ? $_POST['arrest_date'] : null,
                $_POST['detention_location'] ?? null,
                $_POST['custody_details'] ?? null
            ]);
            $respondent_id = $pdo->lastInsertId();
            error_log("Respondent ID: " . $respondent_id);
            
            // Generate case number and NPS docket
            error_log("Generating case number");
            $year = date('Y');
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM complaints WHERE YEAR(date_filed) = ?");
            $stmt->execute([$year]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] + 1;
            $case_number = $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
            $nps_docket = 'NPS-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
            error_log("Case number: " . $case_number);
            
            // Insert complaint
            error_log("Inserting complaint");
            $stmt = $pdo->prepare("
                INSERT INTO complaints (
                    case_number, nps_docket_no, date_received, time_received, date_filed,
                    receiving_staff, offense, case_type, offense_type, law_statute, law_section,
                    case_summary, description, priority, incident_date, incident_time, incident_place,
                    complainant_id, respondent_id, assigned_prosecutor_id, status,
                    similar_complaint, respondent_aware, related_case, related_case_details
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            try {
                error_log("Executing complaint insert with case_number: " . $case_number);
                $params = [
                    $case_number,
                    $nps_docket,
                    $_POST['date_received'] ?? date('Y-m-d'),
                    $_POST['time_received'] ?? date('H:i'),
                    $_POST['date_received'] ?? date('Y-m-d'),
                    $_SESSION['full_name'] ?? 'System',
                    $_POST['offense'] ?? '',
                    $_POST['case_type'] ?? 'criminal',
                    $_POST['offense_type'] ?? 'other',
                    $_POST['law_violated'] ?? '',
                    $_POST['law_section'] ?? '',
                    $_POST['case_summary'] ?? '',
                    $_POST['description'] ?? '',
                    'normal',
                    !empty($_POST['incident_date']) ? $_POST['incident_date'] : null,
                    !empty($_POST['incident_time']) ? $_POST['incident_time'] : null,
                    $_POST['incident_place'] ?? '',
                    $complainant_id,
                    $respondent_id,
                    $_SESSION['user_id'] ?? null,
                    'New',
                    $_POST['similar_complaint'] ?? 'no',
                    $_POST['respondent_aware'] ?? 'no',
                    $_POST['related_case'] ?? 'no',
                    $_POST['related_details'] ?? ''
                ];
                error_log("Parameter count: " . count($params));
                $stmt->execute($params);
                $complaint_id = $pdo->lastInsertId();
                error_log("Complaint ID: " . $complaint_id);
            } catch (PDOException $e) {
                error_log("Error inserting complaint: " . $e->getMessage());
                throw $e;
            }
            
            // Insert witnesses
            if (isset($_POST['witness_name']) && is_array($_POST['witness_name'])) {
                foreach ($_POST['witness_name'] as $index => $witness_name) {
                    if (!empty(trim($witness_name))) {
                        $stmt = $pdo->prepare("
                            INSERT INTO witnesses (complaint_id, full_name, address, contact_number, statement)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $complaint_id,
                            $witness_name,
                            $_POST['witness_address'][$index] ?? '',
                            $_POST['witness_contact'][$index] ?? '',
                            $_POST['witness_statement'][$index] ?? ''
                        ]);
                    }
                }
            }
            
            // Handle file uploads for full intake
            if (isset($_FILES['affidavit']) && $_FILES['affidavit']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/affidavits/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $filename = uniqid() . '_' . basename($_FILES['affidavit']['name']);
                $web_path = 'uploads/affidavits/' . $filename;
                if (move_uploaded_file($_FILES['affidavit']['tmp_name'], $upload_dir . $filename)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO case_documents (complaint_id, document_type, document_name, file_path, uploaded_by)
                        VALUES (?, 'affidavit', ?, ?, ?)
                    ");
                    $stmt->execute([
                        $complaint_id,
                        $_FILES['affidavit']['name'],
                        $web_path,
                        $_SESSION['user_id'] ?? null
                    ]);
                }
            }
            
            if (isset($_FILES['investigation_data']) && $_FILES['investigation_data']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/investigation/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $filename = uniqid() . '_' . basename($_FILES['investigation_data']['name']);
                $web_path = 'uploads/investigation/' . $filename;
                if (move_uploaded_file($_FILES['investigation_data']['tmp_name'], $upload_dir . $filename)) {
                    $stmt = $pdo->prepare("
                        INSERT INTO case_documents (complaint_id, document_type, document_name, file_path, uploaded_by)
                        VALUES (?, 'investigation_data', ?, ?, ?)
                    ");
                    $stmt->execute([
                        $complaint_id,
                        $_FILES['investigation_data']['name'],
                        $web_path,
                        $_SESSION['user_id'] ?? null
                    ]);
                }
            }
            
            // Handle other document uploads
            if (isset($_FILES['other_documents'])) {
                $upload_dir = '../uploads/other/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                foreach ($_FILES['other_documents']['name'] as $index => $name) {
                    if ($_FILES['other_documents']['error'][$index] === UPLOAD_ERR_OK) {
                        $filename = uniqid() . '_' . basename($name);
                        $web_path = 'uploads/other/' . $filename;
                        if (move_uploaded_file($_FILES['other_documents']['tmp_name'][$index], $upload_dir . $filename)) {
                            $stmt = $pdo->prepare("
                                INSERT INTO case_documents (complaint_id, document_type, document_name, file_path, uploaded_by)
                                VALUES (?, 'other', ?, ?, ?)
                            ");
                            $stmt->execute([
                                $complaint_id,
                                $name,
                                $web_path,
                                $_SESSION['user_id'] ?? null
                            ]);
                        } else {
                            error_log("Failed to move other document: " . $name);
                        }
                    }
                }
            }

            // Handle evidence uploads from the Evidence Upload tab
            if (isset($_FILES['evidence_files']) && is_array($_FILES['evidence_files']['name'])) {
                $upload_dir = __DIR__ . '/../uploads/evidence/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $stmt = $pdo->prepare("
                    INSERT INTO case_documents (complaint_id, document_type, document_name, file_path, uploaded_by)
                    VALUES (?, 'evidence', ?, ?, ?)
                ");

                foreach ($_FILES['evidence_files']['name'] as $index => $name) {
                    if ($_FILES['evidence_files']['error'][$index] !== UPLOAD_ERR_OK) {
                        continue;
                    }

                    $filename = uniqid() . '_' . basename($name);
                    $full_path = $upload_dir . $filename;
                    $web_path = 'uploads/evidence/' . $filename;

                    if (move_uploaded_file($_FILES['evidence_files']['tmp_name'][$index], $full_path)) {
                        $stmt->execute([
                            $complaint_id,
                            $name,
                            $web_path,
                            $_SESSION['user_id'] ?? null
                        ]);
                    } else {
                        error_log("Failed to move evidence file: " . $name);
                    }
                }
            }
        
            $pdo->commit();
            error_log("Transaction committed successfully");
            
            // Redirect to cases page with success message
            error_log("Redirecting to cases.php");
            header("Location: cases.php?success=case_created");
            exit();
        
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
            error_log("Transaction rolled back due to error");
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
    <title>Case Intake - Office of the Prosecutor</title>
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

        .form-card {
            background: white;
            padding: 25px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }

        .form-card h5 {
            margin-bottom: 20px;
            color: var(--dark-navy);
            font-weight: 600;
            border-bottom: 2px solid var(--primary-blue);
            padding-bottom: 10px;
        }

        .form-label {
            font-weight: 500;
            color: #555;
            font-size: 14px;
        }

        .form-control, .form-select {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px 15px;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 0.2rem rgba(26, 115, 232, 0.25);
        }

        .upload-area {
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            background: #fafafa;
            transition: all 0.3s;
            cursor: pointer;
        }

        .upload-area:hover {
            border-color: var(--primary-blue);
            background: #f0f7ff;
        }

        .upload-area i {
            font-size: 40px;
            color: var(--primary-blue);
            margin-bottom: 10px;
        }

        .btn-submit {
            background: var(--primary-blue);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-weight: 500;
            transition: all 0.3s;
            min-height: 45px;
        }

        .btn-submit:hover {
            background: #1557b0;
        }

        .btn-preview {
            background: var(--success-green);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-weight: 500;
            transition: all 0.3s;
            min-height: 45px;
        }

        .btn-preview:hover {
            background: #0d8a4a;
        }

        .btn-generate {
            background: #6c757d;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-weight: 500;
            transition: all 0.3s;
            min-height: 45px;
        }

        .btn-generate:hover {
            background: #5a6268;
        }

        .btn-secondary {
            padding: 12px 30px;
            min-height: 45px;
        }

        /* Ensure all buttons have consistent size */
        .btn {
            min-height: 45px;
            padding: 12px 30px;
            font-weight: 500;
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

        .party-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .party-section h6 {
            color: var(--primary-blue);
            margin-bottom: 15px;
            font-weight: 600;
        }

        /* Document Preview Styles */
        .document-preview {
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 40px;
            font-family: 'Times New Roman', serif;
            font-size: 14px;
            line-height: 1.8;
            max-height: 600px;
            overflow-y: auto;
        }

        .doc-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .doc-title {
            font-weight: bold;
            font-size: 16px;
            text-transform: uppercase;
            margin: 20px 0;
        }

        .doc-section {
            margin-bottom: 20px;
        }

        .doc-line {
            border-bottom: 1px solid #333;
            display: inline-block;
            min-width: 200px;
            text-align: center;
        }

        .doc-paragraph {
            text-indent: 40px;
            margin-bottom: 15px;
        }

        .doc-signature {
            margin-top: 40px;
            text-align: center;
        }

        .doc-witness {
            margin-top: 30px;
        }

        .preview-box {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .toggle-group {
            display: flex;
            gap: 10px;
        }

        .toggle-btn {
            padding: 8px 20px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .toggle-btn.active {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .form-section {
            display: none;
        }

        .form-section.active {
            display: block;
        }

        .intake-type-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .intake-tab {
            padding: 12px 25px;
            border: 2px solid #ddd;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            color: #555;
            transition: all 0.3s;
        }

        .intake-tab:hover {
            border-color: var(--primary-blue);
            color: var(--primary-blue);
        }

        .intake-tab.active {
            background: var(--primary-blue);
            color: white;
            border-color: var(--primary-blue);
        }

        .required {
            color: var(--danger-red);
        }

        .upload-section {
            display: block;
        }

        .upload-area {
            border: 2px dashed #ccc;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #fafafa;
            min-height: 80px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .file-preview-container {
            display: block;
            margin-top: 15px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
        }

        .upload-area:hover {
            border-color: var(--primary-blue);
            background: #f0f7ff;
        }

        .upload-area.drag-over {
            border-color: var(--primary-blue);
            background: #e3f2fd;
            transform: scale(1.02);
        }

        .upload-area i {
            font-size: 24px;
            margin-bottom: 8px;
        }

        .upload-area h6 {
            font-size: 14px;
            margin-bottom: 4px;
        }

        .upload-area p {
            font-size: 12px;
            margin-bottom: 0;
        }

        .file-preview-container {
            display: block;
            max-height: 300px;
            overflow-y: auto;
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            min-height: 50px;
        }

        .file-preview-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
        }

        .file-icon {
            font-size: 20px;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: #f0f0f0;
            overflow: hidden;
            flex-shrink: 0;
        }

        .file-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .file-icon.pdf { color: #dc3545; background: #ffebee; }
        .file-icon.doc { color: #007bff; background: #e3f2fd; }
        .file-icon.docx { color: #007bff; background: #e3f2fd; }
        .file-icon.jpg { color: #28a745; background: #e8f5e9; }
        .file-icon.png { color: #28a745; background: #e8f5e9; }
        .file-icon.default { color: #6c757d; background: #e9ecef; }

        .file-info {
            flex: 1;
            min-width: 0;
        }

        .file-name {
            font-weight: 500;
            font-size: 12px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 2px;
        }

        .file-size {
            font-size: 10px;
            color: #6c757d;
        }

        .file-remove {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 4px;
            transition: all 0.2s;
            font-size: 12px;
        }

        .file-remove:hover {
            background: #ffebee;
        }

        .file-error {
            background: #fff3cd;
            border-color: #ffc107;
        }

        .file-error .file-name {
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <?php include '../includes/navigation_fiscal.php'; ?>

        <div class="main-content">
            <div class="top-header">
                <div>
                    <h2>Full Case Intake</h2>
                    <p class="text-muted mb-0">Create new complaint record with detailed information</p>
                </div>
                <div>
                    <span class="badge bg-primary">NPS INV Form No. 1</span>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs" id="full-intake-tabs">
                <div class="tab active" onclick="showTab('input')">
                    <i class="fas fa-edit"></i> Enter Data
                </div>
                <div class="tab" onclick="showTab('preview')">
                    <i class="fas fa-file-alt"></i> Preview Documents
                </div>
                <div class="tab" onclick="showTab('upload')">
                    <i class="fas fa-upload"></i> Evidence Upload
                </div>
            </div>

            <form action="" method="POST" enctype="multipart/form-data" onsubmit="return validateForm(event);">
                <!-- Full Intake Form -->
                <div id="full-intake-form">
                <!-- Tab 1: Data Input -->
                <div id="input" class="form-section active">
                    <!-- Office Use Section -->
                    <div class="form-card">
                        <h5><i class="fas fa-building" style="margin-right: 10px; color: var(--primary-blue);"></i>A. OFFICE USE ONLY</h5>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Date Received</label>
                                <input type="date" class="form-control" name="date_received" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">NPS Docket No.</label>
                                <input type="text" class="form-control" name="nps_docket" placeholder="Auto-generate" readonly>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Handling Prosecutor</label>
                                <input type="text" class="form-control" name="prosecutor" value="<?php echo htmlspecialchars($fiscal_settings['default_prosecutor'] ?? $_SESSION['full_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Time Received</label>
                                <input type="time" class="form-control" name="time_received" value="<?php echo date('H:i'); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Complainant Section -->
                    <div class="form-card">
                        <h5><i class="fas fa-user" style="margin-right: 10px; color: var(--primary-blue);"></i>B. COMPLAINANT/S</h5>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name</label>
                                <input type="text" class="form-control" name="comp_name" placeholder="First Middle Last">
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Sex</label>
                                <select class="form-select" name="comp_sex">
                                    <option value="">Select</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                </select>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Age</label>
                                <input type="number" class="form-control" name="comp_age" placeholder="Age">
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Civil Status</label>
                                <select class="form-select" name="comp_civil">
                                    <option value="single">Single</option>
                                    <option value="married">Married</option>
                                    <option value="widowed">Widowed</option>
                                    <option value="separated">Separated</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Address</label>
                                <input type="text" class="form-control" name="comp_address" placeholder="Complete address">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Contact No.</label>
                                <input type="text" class="form-control" name="comp_contact" placeholder="09XX-XXX-XXXX">
                            </div>
                        </div>
                    </div>

                    <!-- Respondent Section -->
                    <div class="form-card">
                        <h5><i class="fas fa-user-slash" style="margin-right: 10px; color: var(--primary-blue);"></i>C. RESPONDENT/S</h5>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name</label>
                                <input type="text" class="form-control" name="resp_name" placeholder="First Middle Last">
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Sex</label>
                                <select class="form-select" name="resp_sex">
                                    <option value="">Select</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                </select>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Age</label>
                                <input type="number" class="form-control" name="resp_age" placeholder="Age">
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="resp_status" onchange="toggleArrestDetails(this)">
                                    <option value="at_large">At Large</option>
                                    <option value="arrested">Arrested</option>
                                    <option value="detained">Detained</option>
                                    <option value="on_bail">On Bail</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Address</label>
                                <input type="text" class="form-control" name="resp_address" placeholder="Complete address or 'Unknown'">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Alias/Nickname</label>
                                <input type="text" class="form-control" name="resp_alias" placeholder="If any">
                            </div>
                        </div>
                        <div class="row" id="arrest-details" style="display: none;">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Arrest Date</label>
                                <input type="date" class="form-control" name="arrest_date">
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Detention Location</label>
                                <input type="text" class="form-control" name="detention_location" placeholder="e.g., Catbalogan City Jail, Police Station, etc.">
                            </div>
                        </div>
                        <div class="row" id="custody-details" style="display: none;">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Custody Details</label>
                                <textarea class="form-control" name="custody_details" rows="2" placeholder="Additional custody information (e.g., cell number, booking details, etc.)"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Law Violated -->
                    <div class="form-card">
                        <h5><i class="fas fa-gavel" style="margin-right: 10px; color: var(--primary-blue);"></i>D. LAW VIOLATED</h5>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Offense/Case Type <span class="required">*</span></label>
                                <input type="text" class="form-control" name="offense" placeholder="e.g., Theft, Estafa, VAWC">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Statute/Law</label>
                                <select class="form-select" name="law_violated">
                                    <option value="">Select Law...</option>
                                    <option value="RA9165">RA 9165 - Comprehensive Dangerous Drugs Act</option>
                                    <option value="RA7610">RA 7610 - Child Protection</option>
                                    <option value="RA9262">RA 9262 - VAWC</option>
                                    <option value="RA8353">RA 8353 - Anti-Child Abuse</option>
                                    <option value="RA7610">RA 7610 - Special Protection of Children</option>
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
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Specific Section/Article</label>
                                <input type="text" class="form-control" name="law_section" placeholder="e.g., Art. II Sec. 5 & 11">
                            </div>
                        </div>
                    </div>

                    <!-- Witnesses -->
                    <div class="form-card">
                        <h5><i class="fas fa-users" style="margin-right: 10px; color: var(--primary-blue);"></i>E. WITNESSES</h5>
                        <div id="witness-container">
                            <div class="row witness-row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Name</label>
                                    <input type="text" class="form-control" name="witness_name[]" placeholder="Full name">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Address</label>
                                    <input type="text" class="form-control" name="witness_address[]" placeholder="Complete address">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Contact Number</label>
                                    <input type="text" class="form-control" name="witness_contact[]" placeholder="09XX-XXX-XXXX">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Statement</label>
                                    <textarea class="form-control" name="witness_statement[]" rows="3" placeholder="Witness statement..."></textarea>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-add" onclick="addWitness()">
                            <i class="fas fa-plus"></i> Add Witness
                        </button>
                    </div>

                    <!-- Incident Details -->
                    <div class="form-card">
                        <h5><i class="fas fa-calendar-alt" style="margin-right: 10px; color: var(--primary-blue);"></i>F. INCIDENT DETAILS</h5>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Date of Commission</label>
                                <input type="date" class="form-control" name="incident_date">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Time of Commission</label>
                                <input type="time" class="form-control" name="incident_time">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Place of Commission</label>
                                <input type="text" class="form-control" name="incident_place" placeholder="Barangay, City, Province">
                            </div>
                        </div>
                    </div>

                    <!-- Related Case Questions -->
                    <div class="form-card">
                        <h5><i class="fas fa-question-circle" style="margin-right: 10px; color: var(--primary-blue);"></i>G. RELATED CASE QUESTIONS</h5>
                        <div class="mb-3">
                            <label class="form-label">Has a similar complaint been filed in another office?</label>
                            <div class="toggle-group">
                                <button type="button" class="toggle-btn active" onclick="toggleBtn(this, 'similar_yes')">NO</button>
                                <button type="button" class="toggle-btn" onclick="toggleBtn(this, 'similar_yes')">YES</button>
                            </div>
                            <input type="hidden" name="similar_complaint" id="similar_yes" value="no">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Is the respondent aware of the complaint?</label>
                            <div class="toggle-group">
                                <button type="button" class="toggle-btn active" onclick="toggleBtn(this, 'aware_yes')">NO</button>
                                <button type="button" class="toggle-btn" onclick="toggleBtn(this, 'aware_yes')">YES</button>
                            </div>
                            <input type="hidden" name="respondent_aware" id="aware_yes" value="no">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Is this related to another pending case?</label>
                            <div class="toggle-group">
                                <button type="button" class="toggle-btn active" onclick="toggleBtn(this, 'related_yes')">NO</button>
                                <button type="button" class="toggle-btn" onclick="toggleBtn(this, 'related_yes')">YES</button>
                            </div>
                            <input type="hidden" name="related_case" id="related_yes" value="no">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">If YES, provide details:</label>
                            <textarea class="form-control" name="related_details" rows="2" placeholder="Case number, court, status..."></textarea>
                        </div>
                    </div>

                    <!-- Case Summary -->
                    <div class="form-card">
                        <h5><i class="fas fa-file-alt" style="margin-right: 10px; color: var(--primary-blue);"></i>H. CASE SUMMARY</h5>
                        <div class="mb-3">
                            <label class="form-label">Narrative of the Incident</label>
                            <textarea class="form-control" name="case_summary" rows="6" placeholder="Enter detailed narrative of what happened..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Case Details Description</label>
                            <textarea class="form-control" name="description" rows="5" placeholder="Enter additional case details..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Evidence to be Submitted</label>
                            <textarea class="form-control" name="evidence_list" rows="3" placeholder="List all evidence (affidavits, photos, documents, etc.)"></textarea>
                        </div>
                    </div>

                    <div class="form-card text-end">
                        <button type="button" class="btn btn-preview me-2" onclick="generatePreview()">
                            <i class="fas fa-eye"></i> Preview Documents
                        </button>
                        <button type="button" class="btn btn-secondary me-2" onclick="showTab('upload')">Next: Upload Evidence</button>
                    </div>
                </div>

                <!-- Tab 2: Document Preview -->
                <div id="preview" class="form-section">
                    <div class="form-card">
                        <h5><i class="fas fa-file-alt" style="margin-right: 10px; color: var(--primary-blue);"></i>Complaint-Affidavit Preview</h5>
                        <div class="document-preview" id="affidavit-preview">
                            <div class="doc-header">
                                <strong>REPUBLIC OF THE PHILIPPINES</strong><br>
                                City of Catbalogan )<br>
                                Province of Samar ) S.S.<br>
                                x-------------------------------------x
                            </div>
                            
                            <div class="doc-title">COMPLAINT-AFFIDAVIT</div>
                            
                            <div class="doc-paragraph">
                                I, <span class="doc-line" id="prev-comp-name">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>, of legal age, 
                                <span class="doc-line" id="prev-comp-civil">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>, Filipino, and residing at 
                                <span class="doc-line" id="prev-comp-address">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>, 
                                after being duly sworn, depose and state:
                            </div>
                            
                            <div class="doc-paragraph">
                                <strong>1.</strong> I am the complainant in this case.
                            </div>
                            
                            <div class="doc-paragraph">
                                <strong>2.</strong> That I am filing a complaint against 
                                <span class="doc-line" id="prev-resp-name">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>, 
                                of legal age, residing at 
                                <span class="doc-line" id="prev-resp-address">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>.
                            </div>
                            
                            <div class="doc-paragraph">
                                <strong>3.</strong> That on or about 
                                <span class="doc-line" id="prev-incident-date">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>, 
                                at around 
                                <span class="doc-line" id="prev-incident-time">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>, 
                                in 
                                <span class="doc-line" id="prev-incident-place">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>, Philippines, 
                                the respondent did willfully, unlawfully, and feloniously commit the following act:
                            </div>
                            
                            <div class="doc-paragraph" id="prev-case-summary" style="margin-left: 40px;">
                                [Case summary will appear here...]
                            </div>
                            
                            <div class="doc-paragraph">
                                <strong>4.</strong> That the above act constitutes a violation of 
                                <span class="doc-line" id="prev-law">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>.
                            </div>
                            
                            <div class="doc-paragraph">
                                <strong>5.</strong> That I am submitting the following evidence to support this complaint:
                            </div>
                            
                            <div id="prev-evidence-list" style="margin-left: 60px;">
                                • <span class="doc-line">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span><br>
                            </div>
                            
                            <div class="doc-paragraph">
                                <strong>6.</strong> That I am executing this Complaint-Affidavit to attest to the truth of the foregoing 
                                facts and to support the filing of a criminal case against the respondent.
                            </div>
                            
                            <div class="doc-signature">
                                <strong>IN WITNESS WHEREOF,</strong> I have hereunto affixed my signature this <span class="doc-line" style="width: 50px;">&nbsp;</span> 
                                day of <span class="doc-line" style="width: 120px;">&nbsp;</span>, 
                                20<span class="doc-line" style="width: 40px;">&nbsp;</span> 
                                at <span class="doc-line" style="width: 150px;">&nbsp;</span>.
                            </div>
                            
                            <div class="doc-signature">
                                <br><br>
                                <strong>_________________________</strong><br>
                                Complainant
                            </div>
                            
                            <div class="doc-witness">
                                <strong>SUBSCRIBED AND SWORN</strong> to before me this <span class="doc-line" style="width: 50px;">&nbsp;</span> 
                                day of <span class="doc-line" style="width: 120px;">&nbsp;</span>, 
                                20<span class="doc-line" style="width: 40px;">&nbsp;</span> 
                                at <span class="doc-line" style="width: 150px;">&nbsp;</span>. 
                                I certify that I have personally examined the complainant and that they voluntarily executed 
                                and understood this affidavit.
                                <br><br>
                                <strong>_________________________</strong><br>
                                Prosecutor
                            </div>
                        </div>
                    </div>

                    <div class="form-card">
                        <h5><i class="fas fa-file-alt" style="margin-right: 10px; color: var(--primary-blue);"></i>Investigation Data Form Preview (NPS INV Form No. 1)</h5>
                        <div class="document-preview" id="inv-form-preview" style="font-family: Arial, sans-serif; font-size: 12px;">
                            <div style="text-align: center; margin-bottom: 20px;">
                                <strong style="font-size: 14px;">INVESTIGATION DATA FORM</strong><br>
                                <span style="font-size: 11px;">(NPS INV FORM NO. 1)</span>
                            </div>
                            
                            <table style="width: 100%; border-collapse: collapse;" border="1" cellpadding="5">
                                <tr style="background: #f0f0f0;">
                                    <td colspan="4"><strong>A. OFFICE USE ONLY</strong></td>
                                </tr>
                                <tr>
                                    <td>Date Received: <span id="prev-date-received">____________</span></td>
                                    <td>NPS Docket No.: <span id="prev-nps-docket">____________</span></td>
                                    <td colspan="2">Handling Prosecutor: <span id="prev-prosecutor">____________</span></td>
                                </tr>
                                <tr>
                                    <td>Time Received: <span id="prev-time-received">____________</span></td>
                                    <td>Receiving Staff: <span id="prev-staff">____________</span></td>
                                    <td colspan="2">Assigned To: <span>____________</span></td>
                                </tr>
                                
                                <tr style="background: #f0f0f0;">
                                    <td colspan="4"><strong>B. COMPLAINANT/S</strong></td>
                                </tr>
                                <tr>
                                    <td>Name: <span id="prev-form-comp-name">____________</span></td>
                                    <td>Sex: <span id="prev-form-comp-sex">____________</span></td>
                                    <td>Age: <span id="prev-form-comp-age">____________</span></td>
                                    <td>Contact No.: <span id="prev-form-comp-contact">____________</span></td>
                                </tr>
                                <tr>
                                    <td colspan="4">Address: <span id="prev-form-comp-address">____________</span></td>
                                </tr>
                                
                                <tr style="background: #f0f0f0;">
                                    <td colspan="4"><strong>C. RESPONDENT/S</strong></td>
                                </tr>
                                <tr>
                                    <td>Name: <span id="prev-form-resp-name">____________</span></td>
                                    <td>Sex: <span id="prev-form-resp-sex">____________</span></td>
                                    <td>Age: <span id="prev-form-resp-age">____________</span></td>
                                    <td>Status: <span id="prev-form-resp-status">At Large</span></td>
                                </tr>
                                <tr>
                                    <td colspan="4">Address: <span id="prev-form-resp-address">____________</span></td>
                                </tr>
                                
                                <tr style="background: #f0f0f0;">
                                    <td colspan="4"><strong>D. LAW VIOLATED</strong></td>
                                </tr>
                                <tr>
                                    <td colspan="4"><span id="prev-form-law">____________</span></td>
                                </tr>
                                
                                <tr style="background: #f0f0f0;">
                                    <td colspan="4"><strong>E. WITNESSES</strong></td>
                                </tr>
                                <tr>
                                    <td colspan="4" id="prev-form-witnesses">Name and Address:<br>1. ____________</td>
                                </tr>
                                
                                <tr style="background: #f0f0f0;">
                                    <td colspan="4"><strong>F. INCIDENT DETAILS</strong></td>
                                </tr>
                                <tr>
                                    <td colspan="4">
                                        Date and Time of Commission: <span id="prev-form-datetime">____________</span><br>
                                        Place of Commission: <span id="prev-form-place">____________</span>
                                    </td>
                                </tr>
                                
                                <tr style="background: #f0f0f0;">
                                    <td colspan="4"><strong>G. RELATED CASE QUESTIONS</strong></td>
                                </tr>
                                <tr>
                                    <td colspan="4">
                                        Similar complaint filed elsewhere? <span id="prev-form-similar">NO</span> &nbsp;&nbsp;
                                        Respondent aware? <span id="prev-form-aware">NO</span> &nbsp;&nbsp;
                                        Related to pending case? <span id="prev-form-related">NO</span>
                                    </td>
                                </tr>
                                
                                <tr style="background: #f0f0f0;">
                                    <td colspan="4"><strong>H. CASE SUMMARY</strong></td>
                                </tr>
                                <tr>
                                    <td colspan="4" id="prev-form-summary" style="height: 80px; vertical-align: top;">____________</td>
                                </tr>
                                
                                <tr style="background: #f0f0f0;">
                                    <td colspan="4"><strong>I. CERTIFICATION</strong></td>
                                </tr>
                                <tr>
                                    <td colspan="4">
                                        I certify that the information provided is true and correct.<br><br>
                                        Signature of Complainant: ____________ Date: ____________<br><br>
                                        SUBSCRIBED AND SWORN before me this ____ day of ____________.<br>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div class="form-card text-end">
                        <button type="button" class="btn btn-secondary me-2" onclick="showTab('input')">Back to Edit</button>
                        <button type="button" class="btn btn-secondary me-2" onclick="showTab('upload')">Next: Upload Evidence</button>
                        <button type="button" class="btn btn-generate me-2" onclick="generatePDF('affidavit')">
                            <i class="fas fa-file-pdf"></i> Download Affidavit
                        </button>
                        <button type="button" class="btn btn-generate" onclick="generatePDF('inv-form')">
                            <i class="fas fa-file-pdf"></i> Download INV Form
                        </button>
                    </div>
                </div>

                <!-- Tab 3: Evidence Upload -->
                <div id="upload" class="form-section">
                    <div class="form-card">
                        <h5><i class="fas fa-upload" style="margin-right: 10px; color: var(--primary-blue);"></i>Evidence Upload</h5>
                        <p class="text-muted">Upload supporting documents, evidence, and attachments.</p>
                        
                        <div class="mb-4">
                            <label class="form-label">Evidence Files</label>
                            <input type="file" id="evidence" name="evidence_files[]" multiple accept=".pdf,.jpg,.png,.doc,.docx" class="form-control" onchange="showFileNames(this, 'evidence-preview')">
                            <div id="evidence-preview" class="mt-2 text-muted small"></div>
                            <small class="text-muted">PDF, JPG, PNG, DOC, DOCX (Max 10MB each)</small>
                        </div>

                        <div>
                            <h6>Evidence Description</h6>
                            <textarea class="form-control" name="evidence_description" rows="5" placeholder="Describe each uploaded evidence (e.g., Photo 1: CCTV screenshot showing respondent...)"></textarea>
                        </div>
                    </div>

                    <div class="form-card text-end">
                        <button type="reset" class="btn btn-secondary me-2">Clear All</button>
                        <button type="submit" class="btn btn-submit">
                            <i class="fas fa-save" style="margin-right: 8px;"></i>Submit Case Intake
                        </button>
                    </div>
                </div>
                </div>
                <!-- End Full Intake Form -->
            </form>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            document.querySelectorAll('.form-section').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }

        function toggleBtn(btn, hiddenId) {
            const parent = btn.parentElement;
            parent.querySelectorAll('.toggle-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(hiddenId).value = btn.textContent.toLowerCase();
        }

        function toggleArrestDetails(statusSelect) {
            const arrestDetails = document.getElementById('arrest-details');
            const custodyDetails = document.getElementById('custody-details');
            
            if (statusSelect.value === 'arrested' || statusSelect.value === 'detained') {
                arrestDetails.style.display = 'flex';
                custodyDetails.style.display = 'flex';
            } else {
                arrestDetails.style.display = 'none';
                custodyDetails.style.display = 'none';
            }
        }

        function addWitness() {
            const container = document.getElementById('witness-container');
            const row = document.createElement('div');
            row.className = 'row witness-row';
            row.innerHTML = `
                <div class="col-md-4 mb-3">
                    <label class="form-label">Name</label>
                    <input type="text" class="form-control" name="witness_name[]" placeholder="Full name">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Address</label>
                    <input type="text" class="form-control" name="witness_address[]" placeholder="Complete address">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Contact Number</label>
                    <input type="text" class="form-control" name="witness_contact[]" placeholder="09XX-XXX-XXXX">
                </div>
                <div class="col-md-12 mb-3">
                    <label class="form-label">Statement</label>
                    <textarea class="form-control" name="witness_statement[]" rows="3" placeholder="Witness statement..."></textarea>
                </div>
            `;
            container.appendChild(row);
        }

        function generatePreview() {
            // Update Complaint-Affidavit preview
            document.getElementById('prev-comp-name').textContent = document.querySelector('[name="comp_name"]').value || '____________________';
            document.getElementById('prev-comp-civil').textContent = document.querySelector('[name="comp_civil"]').value || '________';
            document.getElementById('prev-comp-address').textContent = document.querySelector('[name="comp_address"]').value || '____________________';
            document.getElementById('prev-resp-name').textContent = document.querySelector('[name="resp_name"]').value || '____________________';
            document.getElementById('prev-resp-address').textContent = document.querySelector('[name="resp_address"]').value || '____________________';
            document.getElementById('prev-incident-date').textContent = document.querySelector('[name="incident_date"]').value || '________';
            document.getElementById('prev-incident-time').textContent = document.querySelector('[name="incident_time"]').value || '________';
            document.getElementById('prev-incident-place').textContent = document.querySelector('[name="incident_place"]').value || '____________________';
            document.getElementById('prev-case-summary').textContent = document.querySelector('[name="case_summary"]').value || '[No summary provided]';
            document.getElementById('prev-law').textContent = (document.querySelector('[name="law_violated"]').value || '____________________').toUpperCase();
            
            // Update evidence list
            const evidenceList = document.querySelector('[name="evidence_list"]').value || '';
            if (evidenceList) {
                const evidenceHtml = evidenceList.split('\n').map(item => `• ${item}`).join('<br>');
                document.getElementById('prev-evidence-list').innerHTML = evidenceHtml;
            } else {
                document.getElementById('prev-evidence-list').innerHTML = '• [No evidence listed]';
            }
            
            // Update INV Form preview
            document.getElementById('prev-date-received').textContent = document.querySelector('[name="date_received"]').value || '____________';
            // Generate preview docket number since the actual one is auto-generated on submit
            const year = new Date().getFullYear();
            const docketPreview = 'NPS-' + year + '-XXXX';
            document.getElementById('prev-nps-docket').textContent = docketPreview;
            document.getElementById('prev-prosecutor').textContent = document.querySelector('[name="prosecutor"]').value || '____________';
            document.getElementById('prev-time-received').textContent = document.querySelector('[name="time_received"]').value || '____________';
            
            document.getElementById('prev-form-comp-name').textContent = document.querySelector('[name="comp_name"]').value || '____________';
            document.getElementById('prev-form-comp-sex').textContent = document.querySelector('[name="comp_sex"]').value || '____________';
            document.getElementById('prev-form-comp-age').textContent = document.querySelector('[name="comp_age"]').value || '____________';
            document.getElementById('prev-form-comp-contact').textContent = document.querySelector('[name="comp_contact"]').value || '____________';
            document.getElementById('prev-form-comp-address').textContent = document.querySelector('[name="comp_address"]').value || '____________';
            
            document.getElementById('prev-form-resp-name').textContent = document.querySelector('[name="resp_name"]').value || '____________';
            document.getElementById('prev-form-resp-sex').textContent = document.querySelector('[name="resp_sex"]').value || '____________';
            document.getElementById('prev-form-resp-age').textContent = document.querySelector('[name="resp_age"]').value || '____________';
            document.getElementById('prev-form-resp-status').textContent = document.querySelector('[name="resp_status"]').value || 'At Large';
            document.getElementById('prev-form-resp-address').textContent = document.querySelector('[name="resp_address"]').value || '____________';
            
            // Update witnesses preview
            const witnessNames = document.querySelectorAll('[name="witness_name[]"]');
            const witnessAddresses = document.querySelectorAll('[name="witness_address[]"]');
            let witnessHtml = 'Name and Address:<br>';
            if (witnessNames.length > 0) {
                witnessNames.forEach((nameInput, index) => {
                    const name = nameInput.value || '____________';
                    const address = witnessAddresses[index]?.value || '____________';
                    witnessHtml += `${index + 1}. ${name} - ${address}<br>`;
                });
            } else {
                witnessHtml += '1. ____________';
            }
            document.getElementById('prev-form-witnesses').innerHTML = witnessHtml;
            
            document.getElementById('prev-form-law').textContent = (document.querySelector('[name="law_violated"]').value || '____________').toUpperCase();
            document.getElementById('prev-form-datetime').textContent = (document.querySelector('[name="incident_date"]').value + ' ' + document.querySelector('[name="incident_time"]').value) || '____________';
            document.getElementById('prev-form-place').textContent = document.querySelector('[name="incident_place"]').value || '____________';
            document.getElementById('prev-form-summary').textContent = document.querySelector('[name="case_summary"]').value || '____________';
            
            document.getElementById('prev-form-similar').textContent = document.getElementById('similar_yes').value.toUpperCase();
            document.getElementById('prev-form-aware').textContent = document.getElementById('aware_yes').value.toUpperCase();
            document.getElementById('prev-form-related').textContent = document.getElementById('related_yes').value.toUpperCase();
            
            showTab('preview');
        }

        function generatePDF(docType) {
            // First generate the preview to populate the data
            generatePreview();
            
            let element;
            let filename;
            
            if (docType === 'affidavit') {
                element = document.querySelector('#affidavit-preview');
                filename = 'complaint_affidavit_' + new Date().toISOString().slice(0,10) + '.pdf';
            } else if (docType === 'inv-form') {
                element = document.querySelector('#inv-form-preview');
                filename = 'inv_form_' + new Date().toISOString().slice(0,10) + '.pdf';
            } else {
                alert('Please switch to the Preview tab first.');
                return;
            }
            
            if (!element) {
                alert('Document not found. Please switch to the Preview tab first.');
                return;
            }
            
            // Ensure element is visible for PDF generation
            element.style.display = 'block';
            
            const opt = {
                margin: 10,
                filename: filename,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };
            
            html2pdf().set(opt).from(element).save();
        }

        function validateForm(event) {
            // Validate full intake required fields
            const compName = document.querySelector('input[name="comp_name"]');
            const respName = document.querySelector('input[name="resp_name"]');
            const offense = document.querySelector('input[name="offense"]');
            const lawViolated = document.querySelector('select[name="law_violated"]');
            
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
            
            if (!lawViolated || !lawViolated.value.trim()) {
                alert('Please select the law violated');
                if (lawViolated) lawViolated.focus();
                event.preventDefault();
                return false;
            }
            
            return true;
        }

        function showFileNames(input, displayId) {
            const display = document.getElementById(displayId);
            if (input.files.length > 0) {
                let html = '<strong>' + input.files.length + ' file(s) selected:</strong><br>';
                for (let i = 0; i < input.files.length; i++) {
                    html += '<div style="margin-left: 10px;">• ' + input.files[i].name + '</div>';
                }
                display.innerHTML = html;
            } else {
                display.innerHTML = '';
            }
        }

        function showFileName(input, displayId) {
            const display = document.getElementById(displayId);
            if (input.files.length > 0) {
                display.innerHTML = '<i class="fas fa-check-circle text-success me-2"></i>' + input.files[0].name;
            } else {
                display.innerHTML = '';
            }
        }
    </script>
</body>
</html>

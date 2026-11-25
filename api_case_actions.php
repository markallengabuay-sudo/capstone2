<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || !in_array($_SESSION['role'] ?? '', ['fiscal', 'prosecutor', 'admin'], true)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Include database connection
require_once '../includes/db.php';

// Get action and case_id from POST data
$action = $_POST['action'] ?? '';
$case_id = $_POST['case_id'] ?? 0;

if (empty($action) || empty($case_id)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

try {
    if ($action === 'issue_subpoena') {
        // Update subpoena_issued_date
        $stmt = $pdo->prepare("UPDATE complaints SET subpoena_issued_date = CURDATE() WHERE id = ?");
        $stmt->execute([$case_id]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Subpoena issued successfully', 'date' => date('Y-m-d')]);
        
    } elseif ($action === 'submit_subpoena') {
        $document_name = $_POST['document_name'] ?? 'Subpoena';
        
        // Update subpoena_issued_date
        $stmt = $pdo->prepare("UPDATE complaints SET subpoena_issued_date = CURDATE() WHERE id = ?");
        $stmt->execute([$case_id]);
        
        // Add document record to case_documents
        $stmt = $pdo->prepare("
            INSERT INTO case_documents (complaint_id, document_name, document_type, uploaded_at, file_path)
            VALUES (?, ?, 'subpoena', NOW(), 'generated')
        ");
        $stmt->execute([$case_id, $document_name]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Subpoena submitted successfully']);
        
    } elseif ($action === 'receive_counter') {
        // Update counter_affidavit_date
        $stmt = $pdo->prepare("UPDATE complaints SET counter_affidavit_date = CURDATE() WHERE id = ?");
        $stmt->execute([$case_id]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Counter-affidavit received', 'date' => date('Y-m-d')]);
        
    } elseif ($action === 'submit_counter_affidavit') {
        $document_name = $_POST['document_name'] ?? 'Counter-Affidavit';
        
        // Update counter_affidavit_date
        $stmt = $pdo->prepare("UPDATE complaints SET counter_affidavit_date = CURDATE() WHERE id = ?");
        $stmt->execute([$case_id]);
        
        // Add document record to case_documents
        $stmt = $pdo->prepare("
            INSERT INTO case_documents (complaint_id, document_name, document_type, uploaded_at, file_path)
            VALUES (?, ?, 'counter_affidavit', NOW(), 'generated')
        ");
        $stmt->execute([$case_id, $document_name]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Counter-affidavit submitted successfully']);
        
    } elseif ($action === 'submit_reply_affidavit') {
        $document_name = $_POST['document_name'] ?? 'Reply-Affidavit';
        
        // Check if reply_affidavit_date column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM complaints LIKE 'reply_affidavit_date'");
        $column_exists = $stmt->fetch();
        
        if (!$column_exists) {
            // Add column if it doesn't exist
            $pdo->query("ALTER TABLE complaints ADD COLUMN reply_affidavit_date DATE");
        }
        
        // Update reply_affidavit_date
        $stmt = $pdo->prepare("UPDATE complaints SET reply_affidavit_date = CURDATE() WHERE id = ?");
        $stmt->execute([$case_id]);
        
        // Add document record to case_documents
        $stmt = $pdo->prepare("
            INSERT INTO case_documents (complaint_id, document_name, document_type, uploaded_at, file_path)
            VALUES (?, ?, 'reply_affidavit', NOW(), 'generated')
        ");
        $stmt->execute([$case_id, $document_name]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Reply-affidavit submitted successfully']);
        
    } elseif ($action === 'upload_document') {
        $document_name = $_POST['document_name'] ?? '';
        $document_type = $_POST['document_type'] ?? 'other';
        
        if (empty($document_name) || empty($document_type)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Document name and type are required']);
            exit();
        }
        
        // Handle file upload
        if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/documents/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $tmp_name = $_FILES['document_file']['tmp_name'];
            $file_ext = pathinfo($_FILES['document_file']['name'], PATHINFO_EXTENSION);
            $new_filename = uniqid() . '_' . $document_type . '_' . $case_id . '.' . $file_ext;
            $file_path = $upload_dir . $new_filename;
            $web_path = 'uploads/documents/' . $new_filename;
            
            if (move_uploaded_file($tmp_name, $file_path)) {
                // Add document record to case_documents
                $stmt = $pdo->prepare("
                    INSERT INTO case_documents (complaint_id, document_name, document_type, uploaded_at, file_path)
                    VALUES (?, ?, ?, NOW(), ?)
                ");
                $stmt->execute([$case_id, $document_name, $document_type, $web_path]);
                
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Document uploaded successfully']);
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
            }
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
        }
        
    } elseif ($action === 'add_to_evidence') {
        $document_id = $_POST['document_id'] ?? 0;

        if (empty($document_id)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Document ID is required']);
            exit();
        }

        // Check if document is already evidence
        $stmt = $pdo->prepare("SELECT document_type FROM case_documents WHERE id = ? AND complaint_id = ?");
        $stmt->execute([$document_id, $case_id]);
        $doc = $stmt->fetch();

        if (!$doc) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Document not found']);
            exit();
        }

        if ($doc['document_type'] === 'evidence') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Document already in evidence']);
            exit();
        }

        // Update document type to evidence
        $stmt = $pdo->prepare("UPDATE case_documents SET document_type = 'evidence' WHERE id = ? AND complaint_id = ?");
        $stmt->execute([$document_id, $case_id]);

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Document added to evidence']);
        
    } elseif ($action === 'complete_investigation') {
        // Update status to 'For Resolution' and set investigation completion date
        $stmt = $pdo->prepare("UPDATE complaints SET status = 'For Resolution', investigation_start_date = COALESCE(investigation_start_date, CURDATE()) WHERE id = ?");
        $stmt->execute([$case_id]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Investigation completed and case forwarded for resolution']);
        
    } elseif ($action === 'allow_reply') {
        // Check if reply_allowed column exists
        $stmt = $pdo->query("SHOW COLUMNS FROM complaints LIKE 'reply_allowed'");
        $column_exists = $stmt->fetch();
        
        if (!$column_exists) {
            // Add column if it doesn't exist
            $pdo->query("ALTER TABLE complaints ADD COLUMN reply_allowed TINYINT(1) DEFAULT 0");
        }
        
        // Update reply_allowed flag
        $stmt = $pdo->prepare("UPDATE complaints SET reply_allowed = 1 WHERE id = ?");
        $stmt->execute([$case_id]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Reply-affidavit allowed']);
        
    } elseif ($action === 'add_witness') {
        // Add witness to case
        $full_name = $_POST['full_name'] ?? '';
        $address = $_POST['address'] ?? '';
        $contact_number = $_POST['contact_number'] ?? '';
        $statement = $_POST['statement'] ?? '';
        
        if (empty($full_name)) {
            echo json_encode(['success' => false, 'message' => 'Witness name is required.']);
            exit();
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO witnesses (complaint_id, full_name, address, contact_number, statement)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$case_id, $full_name, $address, $contact_number, $statement]);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Witness added successfully']);
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        
    } elseif ($action === 'save_evidence_note') {
        // Save evidence notes for a specific document
        $document_id = $_POST['document_id'] ?? 0;
        $notes = $_POST['notes'] ?? '';
        
        try {
            // Check if case_documents has evidence_notes column
            $stmt = $pdo->query("SHOW COLUMNS FROM case_documents LIKE 'evidence_notes'");
            $column_exists = $stmt->fetch();
            
            if (!$column_exists) {
                // Add column if it doesn't exist
                $pdo->query("ALTER TABLE case_documents ADD COLUMN evidence_notes TEXT");
            }
            
            $stmt = $pdo->prepare("UPDATE case_documents SET evidence_notes = ? WHERE id = ? AND complaint_id = ?");
            $stmt->execute([$notes, $document_id, $case_id]);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Evidence notes saved successfully']);
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        
    } elseif ($action === 'add_general_evidence_note') {
        // Add general evidence note (not tied to specific document)
        $note = $_POST['note'] ?? '';
        
        if (empty($note)) {
            echo json_encode(['success' => false, 'message' => 'Note is required.']);
            exit();
        }
        
        try {
            // Check if evidence_notes table exists
            $stmt = $pdo->query("SHOW TABLES LIKE 'evidence_notes'");
            $table_exists = $stmt->fetch();
            
            if (!$table_exists) {
                // Create table if it doesn't exist
                $pdo->query("
                    CREATE TABLE evidence_notes (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        complaint_id INT NOT NULL,
                        document_id INT NULL,
                        note TEXT NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
                        FOREIGN KEY (document_id) REFERENCES case_documents(id) ON DELETE CASCADE
                    )
                ");
            }
            
            $stmt = $pdo->prepare("INSERT INTO evidence_notes (complaint_id, note) VALUES (?, ?)");
            $stmt->execute([$case_id, $note]);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'General evidence note added successfully']);
        } catch (PDOException $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        
    } elseif ($action === 'submit_resolution') {
        $document_name = $_POST['document_name'] ?? 'Resolution';
        
        // Generate HTML document and save as file
        $case_id = isset($_POST['case_id']) ? intval($_POST['case_id']) : 0;
        
        // Get case details
        $stmt = $pdo->prepare("SELECT case_number FROM complaints WHERE id = ?");
        $stmt->execute([$case_id]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($case) {
            // Create uploads directory if it doesn't exist
            $upload_dir = __DIR__ . '/uploads/generated/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate HTML file path
            $filename = 'resolution_' . $case['case_number'] . '_' . time() . '.html';
            $file_path = 'uploads/generated/' . $filename;
            $full_path = $upload_dir . $filename;
            
            // Generate HTML content
            $html = '<!DOCTYPE html><html><head><title>' . htmlspecialchars($document_name) . '</title></head><body>';
            $html .= '<h1>' . htmlspecialchars($document_name) . '</h1>';
            $html .= '<p>Case Number: ' . htmlspecialchars($case['case_number']) . '</p>';
            $html .= '<p>Generated: ' . date('F d, Y') . '</p>';
            $html .= '<p><em>This document was generated by the Fiscal Office system.</em></p>';
            $html .= '</body></html>';
            
            // Save HTML file
            file_put_contents($full_path, $html);
            
            // Add document record with actual file path
            $stmt = $pdo->prepare("
                INSERT INTO case_documents (complaint_id, document_name, document_type, uploaded_at, file_path)
                VALUES (?, ?, 'resolution', NOW(), ?)
            ");
            $stmt->execute([$case_id, $document_name, $file_path]);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Resolution submitted successfully']);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Case not found']);
        }
        
    } elseif ($action === 'submit_endorsement') {
        $document_name = $_POST['document_name'] ?? 'Endorsement Letter';
        
        // Generate HTML document and save as file
        $case_id = isset($_POST['case_id']) ? intval($_POST['case_id']) : 0;
        
        // Get case details
        $stmt = $pdo->prepare("SELECT case_number FROM complaints WHERE id = ?");
        $stmt->execute([$case_id]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($case) {
            // Create uploads directory if it doesn't exist
            $upload_dir = __DIR__ . '/uploads/generated/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate HTML file path
            $filename = 'endorsement_' . $case['case_number'] . '_' . time() . '.html';
            $file_path = 'uploads/generated/' . $filename;
            $full_path = $upload_dir . $filename;
            
            // Generate HTML content
            $html = '<!DOCTYPE html><html><head><title>' . htmlspecialchars($document_name) . '</title></head><body>';
            $html .= '<h1>' . htmlspecialchars($document_name) . '</h1>';
            $html .= '<p>Case Number: ' . htmlspecialchars($case['case_number']) . '</p>';
            $html .= '<p>Generated: ' . date('F d, Y') . '</p>';
            $html .= '<p><em>This document was generated by the Fiscal Office system.</em></p>';
            $html .= '</body></html>';
            
            // Save HTML file
            file_put_contents($full_path, $html);
            
            // Add document record with actual file path
            $stmt = $pdo->prepare("
                INSERT INTO case_documents (complaint_id, document_name, document_type, uploaded_at, file_path)
                VALUES (?, ?, 'endorsement', NOW(), ?)
            ");
            $stmt->execute([$case_id, $document_name, $file_path]);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Endorsement letter submitted successfully']);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Case not found']);
        }
        
    } elseif ($action === 'submit_information') {
        $document_name = $_POST['document_name'] ?? 'Criminal Information';
        
        // Generate HTML document and save as file
        $case_id = isset($_POST['case_id']) ? intval($_POST['case_id']) : 0;
        
        // Get case details
        $stmt = $pdo->prepare("SELECT case_number FROM complaints WHERE id = ?");
        $stmt->execute([$case_id]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($case) {
            // Create uploads directory if it doesn't exist
            $upload_dir = __DIR__ . '/uploads/generated/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            // Generate HTML file path
            $filename = 'information_' . $case['case_number'] . '_' . time() . '.html';
            $file_path = 'uploads/generated/' . $filename;
            $full_path = $upload_dir . $filename;
            
            // Generate HTML content (simplified - in production, this would use the actual template)
            $html = '<!DOCTYPE html><html><head><title>' . htmlspecialchars($document_name) . '</title></head><body>';
            $html .= '<h1>' . htmlspecialchars($document_name) . '</h1>';
            $html .= '<p>Case Number: ' . htmlspecialchars($case['case_number']) . '</p>';
            $html .= '<p>Generated: ' . date('F d, Y') . '</p>';
            $html .= '<p><em>This document was generated by the Fiscal Office system.</em></p>';
            $html .= '</body></html>';
            
            // Save HTML file
            file_put_contents($full_path, $html);
            
            // Add document record with actual file path
            $stmt = $pdo->prepare("
                INSERT INTO case_documents (complaint_id, document_name, document_type, uploaded_at, file_path)
                VALUES (?, ?, 'information', NOW(), ?)
            ");
            $stmt->execute([$case_id, $document_name, $file_path]);
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Criminal information submitted successfully']);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Case not found']);
        }
        
    } elseif ($action === 'approve_resolution') {
        // Validate current status before approving
        $stmt = $pdo->prepare("SELECT status FROM complaints WHERE id = ?");
        $stmt->execute([$case_id]);
        $current_status = $stmt->fetch(PDO::FETCH_ASSOC)['status'];
        
        // Only allow approval from 'For Resolution' status
        if ($current_status !== 'For Resolution') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Case must be in "For Resolution" status to approve. Current status: ' . $current_status]);
            exit();
        }
        
        // Update status to 'Filed in Court' for court forwarding workflow
        // This ensures the case goes through Court Forwarding page before being sent to Clerk
        $stmt = $pdo->prepare("UPDATE complaints SET status = 'Filed in Court', resolution_date = CURDATE() WHERE id = ?");
        $stmt->execute([$case_id]);
        
        // Get case details
        $stmt = $pdo->prepare("SELECT case_number FROM complaints WHERE id = ?");
        $stmt->execute([$case_id]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Create uploads directory if it doesn't exist
        $upload_dir = __DIR__ . '/uploads/generated/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Automatically save resolution, endorsement, and criminal information as case documents
        $document_types = [
            'resolution' => 'Resolution',
            'endorsement' => 'Endorsement Letter', 
            'information' => 'Criminal Information'
        ];
        
        foreach ($document_types as $doc_type => $doc_name) {
            // Check if document already exists
            $stmt = $pdo->prepare("SELECT id FROM case_documents WHERE complaint_id = ? AND document_type = ?");
            $stmt->execute([$case_id, $doc_type]);
            $existing = $stmt->fetch();
            
            // Only insert if doesn't exist
            if (!$existing && $case) {
                // Generate HTML file path
                $filename = $doc_type . '_' . $case['case_number'] . '_' . time() . '.html';
                $file_path = 'uploads/generated/' . $filename;
                $full_path = $upload_dir . $filename;
                
                // Generate HTML content
                $html = '<!DOCTYPE html><html><head><title>' . htmlspecialchars($doc_name) . '</title></head><body>';
                $html .= '<h1>' . htmlspecialchars($doc_name) . '</h1>';
                $html .= '<p>Case Number: ' . htmlspecialchars($case['case_number']) . '</p>';
                $html .= '<p>Generated: ' . date('F d, Y') . '</p>';
                $html .= '<p><em>This document was generated by the Fiscal Office system.</em></p>';
                $html .= '</body></html>';
                
                // Save HTML file
                file_put_contents($full_path, $html);
                
                // Add document record with actual file path
                $stmt = $pdo->prepare("
                    INSERT INTO case_documents (complaint_id, document_name, document_type, uploaded_at, file_path)
                    VALUES (?, ?, ?, NOW(), ?)
                ");
                $stmt->execute([$case_id, $doc_name, $doc_type, $file_path]);
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Resolution approved and case forwarded for filing']);
        
    } elseif ($action === 'return_for_revision') {
        // Update status to 'Under Investigation' and save revision notes
        $notes = $_POST['notes'] ?? '';
        $stmt = $pdo->prepare("UPDATE complaints SET status = 'Under Investigation', resolution_notes = ? WHERE id = ?");
        $stmt->execute([$notes, $case_id]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Case returned for revision']);
        
    } elseif ($action === 'transmit_case') {
        // Transmission to court is a filing step, not a court-ordered archive.
        $stmt = $pdo->prepare("UPDATE complaints SET status = 'Filed in Court' WHERE id = ?");
        $stmt->execute([$case_id]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Case transmitted to court and marked Filed in Court']);
        
    } elseif ($action === 'forward_to_clerk') {
        // Update status to 'Forwarded to Clerk' (not Archived)
        $stmt = $pdo->prepare("UPDATE complaints SET status = 'Forwarded to Clerk' WHERE id = ?");
        $stmt->execute([$case_id]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Case forwarded to Clerk of Court successfully']);
        
    } elseif ($action === 'delete_case') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM case_archive_events WHERE complaint_id = ?");
        $stmt->execute([$case_id]);
        if ((int) $stmt->fetchColumn() > 0) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Cases with archive/revival orders cannot be deleted.']);
            exit();
        }

        // Get case information first
        $stmt = $pdo->prepare("SELECT complainant_id, respondent_id FROM complaints WHERE id = ?");
        $stmt->execute([$case_id]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$case) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Case not found']);
            exit();
        }
        
        // Get document file paths
        $stmt = $pdo->prepare("SELECT file_path FROM case_documents WHERE complaint_id = ?");
        $stmt->execute([$case_id]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Delete actual files
        foreach ($documents as $doc) {
            $file_path = '../' . $doc['file_path'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
        
        // Delete case documents
        $stmt = $pdo->prepare("DELETE FROM case_documents WHERE complaint_id = ?");
        $stmt->execute([$case_id]);
        
        // Delete witnesses
        $stmt = $pdo->prepare("DELETE FROM witnesses WHERE complaint_id = ?");
        $stmt->execute([$case_id]);
        
        // Delete hearings
        $stmt = $pdo->prepare("DELETE FROM hearings WHERE complaint_id = ?");
        $stmt->execute([$case_id]);
        
        // Delete case
        $stmt = $pdo->prepare("DELETE FROM complaints WHERE id = ?");
        $stmt->execute([$case_id]);
        
        // Delete complainant
        $stmt = $pdo->prepare("DELETE FROM complainants WHERE id = ?");
        $stmt->execute([$case['complainant_id']]);
        
        // Delete respondent
        $stmt = $pdo->prepare("DELETE FROM respondents WHERE id = ?");
        $stmt->execute([$case['respondent_id']]);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Case and all associated data deleted successfully']);
        
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

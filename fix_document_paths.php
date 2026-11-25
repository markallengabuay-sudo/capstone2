<?php
// Fix document file paths in database
require_once 'includes/db.php';

echo "Starting document path fix...\n";

try {
    // Fix documents paths
    $stmt = $pdo->prepare("UPDATE case_documents SET file_path = REPLACE(file_path, '../uploads/documents/', 'uploads/documents/') WHERE file_path LIKE '../uploads/documents/%'");
    $result = $stmt->execute();
    $affected = $stmt->rowCount();
    echo "Fixed $affected document paths in uploads/documents/\n";
    
    // Fix evidence paths  
    $stmt = $pdo->prepare("UPDATE case_documents SET file_path = REPLACE(file_path, '../uploads/evidence/', 'uploads/evidence/') WHERE file_path LIKE '../uploads/evidence/%'");
    $result = $stmt->execute();
    $affected = $stmt->rowCount();
    echo "Fixed $affected document paths in uploads/evidence/\n";
    
    // Show sample of updated paths
    echo "\nSample updated paths:\n";
    $stmt = $pdo->query("SELECT id, document_name, file_path FROM case_documents LIMIT 5");
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($docs as $doc) {
        echo "ID: {$doc['id']}, Name: {$doc['document_name']}, Path: {$doc['file_path']}\n";
    }
    
    echo "\nDocument path fix completed successfully!\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>

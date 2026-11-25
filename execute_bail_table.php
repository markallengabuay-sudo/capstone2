<?php
// Execute bail applications table creation
require_once 'includes/db.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS bail_applications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        complaint_id INT NOT NULL,
        bail_type ENUM('cash', 'property', 'surety') NOT NULL,
        bail_amount DECIMAL(10,2) NOT NULL,
        status ENUM('pending', 'verified', 'granted', 'denied', 'posted') DEFAULT 'pending',
        fiscal_recommendation DECIMAL(10,2) NULL,
        fiscal_recommendation_notes TEXT NULL,
        fiscal_recommendation_date DATE NULL,
        fiscal_recommended_by INT NULL,
        verified_by INT NULL,
        verified_date DATE NULL,
        approved_by INT NULL,
        approved_date DATE NULL,
        rejection_reason TEXT NULL,
        bail_posted_date DATE NULL,
        posted_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        
        FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
        FOREIGN KEY (fiscal_recommended_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB";
    
    $pdo->exec($sql);
    echo "Bail applications table created successfully!";
} catch (PDOException $e) {
    echo "Error creating table: " . $e->getMessage();
}
?>

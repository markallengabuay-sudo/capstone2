-- Add bail applications table to fiscal_system database
USE fiscal_system;

-- Create bail_applications table
CREATE TABLE IF NOT EXISTS bail_applications (
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
    
    -- Property Bond fields
    property_description TEXT NULL,
    property_location VARCHAR(255) NULL,
    tct_number VARCHAR(100) NULL,
    property_value DECIMAL(10,2) NULL,
    property_equity DECIMAL(10,2) NULL,
    
    -- Surety Bond fields
    insurance_company VARCHAR(255) NULL,
    premium_amount DECIMAL(10,2) NULL,
    bond_certificate_number VARCHAR(100) NULL,
    
    FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE CASCADE,
    FOREIGN KEY (fiscal_recommended_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Add detailed bail workflow fields to bail_applications table
USE fiscal_system;

-- Add document verification fields
ALTER TABLE bail_applications 
ADD COLUMN documents_submitted TEXT NULL AFTER bond_certificate_number,
ADD COLUMN documents_verified_by INT NULL AFTER documents_submitted,
ADD COLUMN documents_verified_date DATE NULL AFTER documents_verified_by,
ADD COLUMN documents_verification_notes TEXT NULL AFTER documents_verified_date;

-- Add payment processing fields (OCC Cashier)
ALTER TABLE bail_applications 
ADD COLUMN payment_method ENUM('cash', 'surety', 'property') DEFAULT 'cash' AFTER documents_verification_notes,
ADD COLUMN payment_date DATE NULL AFTER payment_method,
ADD COLUMN payment_processed_by INT NULL AFTER payment_date,
ADD COLUMN official_receipt_number VARCHAR(100) NULL AFTER payment_processed_by,
ADD COLUMN official_receipt_amount DECIMAL(10,2) NULL AFTER official_receipt_number;

-- Add release order fields
ALTER TABLE bail_applications 
ADD COLUMN release_order_issued_by INT NULL AFTER official_receipt_amount,
ADD COLUMN release_order_date DATE NULL AFTER release_order_issued_by,
ADD COLUMN release_order_notes TEXT NULL AFTER release_order_date;

-- Update status ENUM to include new workflow stages
ALTER TABLE bail_applications 
MODIFY COLUMN status ENUM(
    'pending',
    'documents_submitted',
    'documents_verified',
    'fiscal_reviewed',
    'verified',
    'payment_pending',
    'payment_processed',
    'release_pending',
    'granted',
    'posted',
    'denied'
) DEFAULT 'pending';

-- Add foreign key for release order issuer (judge)
ALTER TABLE bail_applications 
ADD CONSTRAINT fk_release_order_issuer 
FOREIGN KEY (release_order_issued_by) REFERENCES users(id) ON DELETE SET NULL;

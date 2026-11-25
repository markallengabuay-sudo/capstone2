-- Add document notarization tracking columns to complaints table
USE fiscal_system;

-- Add columns for tracking document notarization status
ALTER TABLE complaints 
ADD COLUMN complaint_affidavit_sworn BOOLEAN DEFAULT FALSE AFTER status,
ADD COLUMN nps_inv_form_sworn BOOLEAN DEFAULT FALSE AFTER complaint_affidavit_sworn,
ADD COLUMN documents_sworn_date DATE NULL AFTER nps_inv_form_sworn,
ADD COLUMN sworn_before_prosecutor_id INT NULL AFTER documents_sworn_date;

-- Add foreign key for prosecutor who administered oath
ALTER TABLE complaints 
ADD CONSTRAINT fk_sworn_prosecutor 
FOREIGN KEY (sworn_before_prosecutor_id) REFERENCES users(id) ON DELETE SET NULL;

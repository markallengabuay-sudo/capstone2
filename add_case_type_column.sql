-- Add case_type column to complaints table in fiscal_system database
ALTER TABLE complaints ADD COLUMN case_type VARCHAR(100) DEFAULT 'Criminal' AFTER offense;

-- Update existing records to have case_type
UPDATE complaints SET case_type = 'Criminal' WHERE case_type IS NULL OR case_type = '';

-- Verify the column was added
DESCRIBE complaints;

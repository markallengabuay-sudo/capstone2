-- Add default values to complaints table to avoid null values
ALTER TABLE complaints MODIFY COLUMN receiving_staff VARCHAR(100) DEFAULT 'Unknown';
ALTER TABLE complaints MODIFY COLUMN law_section VARCHAR(50) DEFAULT '';
ALTER TABLE complaints MODIFY COLUMN description TEXT DEFAULT '';
ALTER TABLE complaints MODIFY COLUMN resolution_date DATE DEFAULT NULL;
ALTER TABLE complaints MODIFY COLUMN resolution_type ENUM('dismissed','filed','archived','settled') DEFAULT NULL;
ALTER TABLE complaints MODIFY COLUMN resolution_notes TEXT DEFAULT '';
ALTER TABLE complaints MODIFY COLUMN court_branch INT(11) DEFAULT NULL;
ALTER TABLE complaints MODIFY COLUMN subpoena_issued_date DATE DEFAULT NULL;
ALTER TABLE complaints MODIFY COLUMN counter_affidavit_date DATE DEFAULT NULL;
ALTER TABLE complaints MODIFY COLUMN preliminary_investigation_date DATE DEFAULT NULL;
ALTER TABLE complaints MODIFY COLUMN criminal_case_no VARCHAR(50) DEFAULT NULL;
ALTER TABLE complaints MODIFY COLUMN date_docketed DATE DEFAULT NULL;

-- Add default values to complainants table
ALTER TABLE complainants MODIFY COLUMN email VARCHAR(100) DEFAULT '';

-- Add default values to respondents table
ALTER TABLE respondents MODIFY COLUMN aliases VARCHAR(100) DEFAULT '';
ALTER TABLE respondents MODIFY COLUMN contact_number VARCHAR(20) DEFAULT '';

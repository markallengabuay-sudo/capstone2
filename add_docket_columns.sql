-- Add columns for docket functionality to complaints table
ALTER TABLE complaints ADD COLUMN criminal_case_no VARCHAR(50) DEFAULT NULL;
ALTER TABLE complaints ADD COLUMN date_docketed DATE DEFAULT NULL;

.
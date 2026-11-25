-- Add court workflow tracking fields to complaints table
USE fiscal_system;

-- Add current stage field to track the court process stage
ALTER TABLE complaints 
ADD COLUMN current_stage ENUM(
    'Initial Court Action',
    'Arraignment',
    'Pre-Trial Conference',
    'Trial',
    'Submission for Decision',
    'Decision/Judgment',
    'Post-Judgment',
    'Final Disposition'
) DEFAULT 'Initial Court Action' AFTER status;

-- Add date fields for each stage
ALTER TABLE complaints 
ADD COLUMN arraignment_date DATE NULL AFTER current_stage;

ALTER TABLE complaints 
ADD COLUMN pretrial_date DATE NULL AFTER arraignment_date;

ALTER TABLE complaints 
ADD COLUMN trial_date DATE NULL AFTER pretrial_date;

ALTER TABLE complaints 
ADD COLUMN submission_date DATE NULL AFTER trial_date;

ALTER TABLE complaints 
ADD COLUMN decision_date DATE NULL AFTER submission_date;

ALTER TABLE complaints 
ADD COLUMN final_disposition_date DATE NULL AFTER decision_date;

-- Add warrant and bail tracking fields
ALTER TABLE complaints 
ADD COLUMN warrant_issued TINYINT(1) DEFAULT 0 AFTER final_disposition_date;

ALTER TABLE complaints 
ADD COLUMN warrant_issued_date DATE NULL AFTER warrant_issued;

ALTER TABLE complaints 
ADD COLUMN bail_set DECIMAL(10,2) NULL AFTER warrant_issued_date;

ALTER TABLE complaints 
ADD COLUMN bail_status ENUM('pending', 'granted', 'denied', 'posted') NULL AFTER bail_set;

ALTER TABLE complaints 
ADD COLUMN bail_posted_date DATE NULL AFTER bail_status;

-- Add plea information for criminal cases
ALTER TABLE complaints 
ADD COLUMN plea ENUM('guilty', 'not_guilty', 'no_contest') NULL AFTER bail_posted_date;

ALTER TABLE complaints 
ADD COLUMN plea_date DATE NULL AFTER plea;

-- Add judgment details
ALTER TABLE complaints 
ADD COLUMN judgment_type ENUM('conviction', 'acquittal', 'dismissal', 'settlement') NULL AFTER plea_date;

ALTER TABLE complaints 
ADD COLUMN judgment_details TEXT NULL AFTER judgment_type;

-- Add post-judgment tracking
ALTER TABLE complaints 
ADD COLUMN appeal_filed TINYINT(1) DEFAULT 0 AFTER judgment_details;

ALTER TABLE complaints 
ADD COLUMN appeal_filed_date DATE NULL AFTER appeal_filed;

ALTER TABLE complaints 
ADD COLUMN reconsideration_filed TINYINT(1) DEFAULT 0 AFTER appeal_filed_date;

ALTER TABLE complaints 
ADD COLUMN reconsideration_filed_date DATE NULL AFTER reconsideration_filed;

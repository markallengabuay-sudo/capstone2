-- Remove court workflow tracking fields from complaints table
USE fiscal_system;

-- Remove fields in reverse order of addition
ALTER TABLE complaints DROP COLUMN reconsideration_filed_date;
ALTER TABLE complaints DROP COLUMN reconsideration_filed;
ALTER TABLE complaints DROP COLUMN appeal_filed_date;
ALTER TABLE complaints DROP COLUMN appeal_filed;
ALTER TABLE complaints DROP COLUMN judgment_details;
ALTER TABLE complaints DROP COLUMN judgment_type;
ALTER TABLE complaints DROP COLUMN plea_date;
ALTER TABLE complaints DROP COLUMN plea;
ALTER TABLE complaints DROP COLUMN bail_posted_date;
ALTER TABLE complaints DROP COLUMN bail_status;
ALTER TABLE complaints DROP COLUMN bail_set;
ALTER TABLE complaints DROP COLUMN warrant_issued_date;
ALTER TABLE complaints DROP COLUMN warrant_issued;
ALTER TABLE complaints DROP COLUMN final_disposition_date;
ALTER TABLE complaints DROP COLUMN decision_date;
ALTER TABLE complaints DROP COLUMN submission_date;
ALTER TABLE complaints DROP COLUMN trial_date;
ALTER TABLE complaints DROP COLUMN pretrial_date;
ALTER TABLE complaints DROP COLUMN arraignment_date;
ALTER TABLE complaints DROP COLUMN current_stage;

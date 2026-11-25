-- Add judgment and dismissal fields to hearings table
-- Run this in MySQL to update the database schema

ALTER TABLE hearings 
ADD COLUMN outcome VARCHAR(50) DEFAULT NULL AFTER status,
ADD COLUMN judge_decision TEXT DEFAULT NULL AFTER outcome,
ADD COLUMN dismissal_reason VARCHAR(50) DEFAULT NULL AFTER judge_decision;

-- Update existing completed hearings to have 'completed' as default outcome
UPDATE hearings SET outcome = 'completed' WHERE status = 'completed' AND outcome IS NULL;

-- Update cases with dismissed hearings to have 'Dismissed' status
UPDATE complaints c
JOIN hearings h ON c.id = h.complaint_id
SET c.status = 'Dismissed'
WHERE h.outcome = 'dismissed' AND c.status != 'Dismissed';
 
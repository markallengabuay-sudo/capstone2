-- Add 'Forwarded to Clerk' to the status ENUM in complaints table
ALTER TABLE complaints MODIFY COLUMN status ENUM('New', 'Under Investigation', 'For Resolution', 'Forwarded to Clerk', 'Filed in Court', 'Dismissed', 'Archived') DEFAULT 'New';

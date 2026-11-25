-- Add statement field to witnesses table
ALTER TABLE witnesses ADD COLUMN statement TEXT AFTER contact_number;

-- Add accused status tracking columns to respondents table
-- Run this to update existing database

USE fiscal_system;

-- Add new columns to respondents table for tracking accused status
ALTER TABLE respondents 
ADD COLUMN arrest_date DATE DEFAULT NULL AFTER status,
ADD COLUMN detention_location VARCHAR(255) DEFAULT NULL AFTER arrest_date,
ADD COLUMN custody_details TEXT DEFAULT NULL AFTER detention_location;

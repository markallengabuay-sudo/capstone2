-- Fix document file paths in database
-- Remove ../ prefix from existing file paths to match new format

UPDATE case_documents 
SET file_path = REPLACE(file_path, '../uploads/documents/', 'uploads/documents/')
WHERE file_path LIKE '../uploads/documents/%';

UPDATE case_documents 
SET file_path = REPLACE(file_path, '../uploads/evidence/', 'uploads/evidence/')
WHERE file_path LIKE '../uploads/evidence/%';

-- Verify the changes
SELECT id, document_name, file_path FROM case_documents;

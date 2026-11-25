<?php
// Update bail_applications table with new columns
try {
    $pdo = new PDO('mysql:host=localhost;dbname=fiscal_system', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Add Property Bond fields
    $pdo->exec("ALTER TABLE bail_applications ADD COLUMN IF NOT EXISTS property_description TEXT NULL AFTER posted_by");
    $pdo->exec("ALTER TABLE bail_applications ADD COLUMN IF NOT EXISTS property_location VARCHAR(255) NULL AFTER property_description");
    $pdo->exec("ALTER TABLE bail_applications ADD COLUMN IF NOT EXISTS tct_number VARCHAR(100) NULL AFTER property_location");
    $pdo->exec("ALTER TABLE bail_applications ADD COLUMN IF NOT EXISTS property_value DECIMAL(10,2) NULL AFTER tct_number");
    $pdo->exec("ALTER TABLE bail_applications ADD COLUMN IF NOT EXISTS property_equity DECIMAL(10,2) NULL AFTER property_value");

    // Add Surety Bond fields
    $pdo->exec("ALTER TABLE bail_applications ADD COLUMN IF NOT EXISTS insurance_company VARCHAR(255) NULL AFTER property_equity");
    $pdo->exec("ALTER TABLE bail_applications ADD COLUMN IF NOT EXISTS premium_amount DECIMAL(10,2) NULL AFTER insurance_company");
    $pdo->exec("ALTER TABLE bail_applications ADD COLUMN IF NOT EXISTS bond_certificate_number VARCHAR(100) NULL AFTER premium_amount");

    echo "Columns added successfully to bail_applications table!";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>

-- KBMC Asset Management - Database Migration
-- Remove brand, model, specifications, and mac_address columns from devices table
-- This migration removes unused fields from the device inventory

USE kbmc_asset_db;

-- Remove the brand, model, specifications, and mac_address columns from devices table
ALTER TABLE devices 
DROP COLUMN IF EXISTS brand,
DROP COLUMN IF EXISTS model,
DROP COLUMN IF EXISTS specifications,
DROP COLUMN IF EXISTS mac_address;

-- Confirmation message (visible in phpMyAdmin)
SELECT 'Migration completed: Removed brand, model, specifications, and mac_address columns from devices table' AS migration_status;

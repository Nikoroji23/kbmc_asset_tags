-- Remove UNIQUE constraint from asset_tag to allow duplicate asset tags
-- This allows multiple devices to have the same asset tag (e.g., Laptop + Charger set)
-- and allows multiple N/A entries (NULL values)

ALTER TABLE devices 
DROP INDEX asset_tag,
DROP INDEX serial_number,
MODIFY COLUMN asset_tag VARCHAR(100) NULL DEFAULT NULL;
MODIFY COLUMN serial_number VARCHAR(100) NULL DEFAULT NULL;

-- Verify the change
-- SELECT * FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_NAME='devices' AND COLUMN_NAME='asset_tag';

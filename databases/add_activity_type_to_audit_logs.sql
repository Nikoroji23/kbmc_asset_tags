-- Add activity_type column to audit_logs table
-- This allows tracking whether an audit log entry is from Maintenance, Repair, Device Assignment, or other activities

ALTER TABLE audit_logs ADD COLUMN activity_type VARCHAR(50) DEFAULT NULL AFTER table_name;

-- Add index for faster filtering
ALTER TABLE audit_logs ADD INDEX idx_activity_type (activity_type);

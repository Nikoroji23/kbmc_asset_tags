-- Add issue category and severity tracking to device_repairs
-- File: databases/add_issue_category_severity.sql
-- Date: June 2, 2026

ALTER TABLE device_repairs 
ADD COLUMN severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium' AFTER issue_description,
ADD COLUMN issue_category VARCHAR(50) DEFAULT 'other' AFTER severity;

-- Create index for better query performance
CREATE INDEX idx_repairs_severity ON device_repairs(severity);
CREATE INDEX idx_repairs_category ON device_repairs(issue_category);
CREATE INDEX idx_repairs_status_severity ON device_repairs(repair_status, severity);

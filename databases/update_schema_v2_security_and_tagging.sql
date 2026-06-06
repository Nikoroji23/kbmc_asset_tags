-- KBMC Asset Management - Database Schema Update
-- Comprehensive updates to support login security, audit logging, and flexible asset tagging
-- Date: June 4, 2026

USE kbmc_asset_db;

-- ============================================================
-- 1. UPDATE USERS TABLE - Add Login Security Columns
-- ============================================================
-- Add columns for tracking failed login attempts and account lockouts

ALTER TABLE users 
ADD COLUMN IF NOT EXISTS failed_logins INT DEFAULT 0,
ADD COLUMN IF NOT EXISTS last_failed_attempt TIMESTAMP NULL,
ADD COLUMN IF NOT EXISTS locked_until TIMESTAMP NULL;

-- ============================================================
-- 2. UPDATE AUDIT_LOGS TABLE - Add Activity Type Column
-- ============================================================
-- Add activity_type column to categorize audit log entries (Login, Device, etc.)

ALTER TABLE audit_logs 
ADD COLUMN IF NOT EXISTS activity_type VARCHAR(50) DEFAULT NULL;

-- ============================================================
-- 3. UPDATE DEVICES TABLE - Support Flexible Asset Tagging
-- ============================================================
-- Remove UNIQUE constraint to allow:
--   - Duplicate asset tags (equipment sets with same tag)
--   - NULL asset tags (devices without tags display as "N/A")

ALTER TABLE devices 
DROP INDEX IF EXISTS asset_tag,
MODIFY COLUMN asset_tag VARCHAR(100) NULL DEFAULT NULL;

-- ============================================================
-- Update Complete
-- ============================================================
-- All schema updates have been successfully applied.
-- The database now supports:
--   ✓ Login security tracking (failed attempts, account lockout)
--   ✓ Detailed audit logging with activity categorization
--   ✓ Flexible asset tagging (NULL values and duplicates allowed)

SELECT 'Database schema update completed successfully' AS status;

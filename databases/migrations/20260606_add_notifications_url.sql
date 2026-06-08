-- Migration: add `url` column to notifications
ALTER TABLE notifications
ADD COLUMN `url` VARCHAR(255) DEFAULT NULL AFTER `related_id`;

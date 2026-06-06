-- ============================================================
-- KBMC Asset Management - Device Lifespan Forecast
-- Migration: adds lifespan tracking table + default lifespan
--            standards per device type.
-- Run this once in phpMyAdmin against kbmc_asset_db.
-- ============================================================

USE kbmc_asset_db;

-- 1. Add expected_lifespan_years column to devices table (if not yet present)
ALTER TABLE devices
    ADD COLUMN IF NOT EXISTS expected_lifespan_years TINYINT UNSIGNED DEFAULT NULL
        COMMENT 'Estimated useful lifespan in years (set per device or inherited from type default)';

-- 2. Device-type default lifespans (lookup / config table)
CREATE TABLE IF NOT EXISTS device_type_lifespans (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    device_type_id   INT          NOT NULL UNIQUE,
    default_years    TINYINT UNSIGNED NOT NULL DEFAULT 5
        COMMENT 'Default lifespan in years for this device category',
    created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (device_type_id) REFERENCES device_types(id) ON DELETE CASCADE
);

-- Seed sensible defaults (adjust to match your device_type IDs)
-- IDs correspond to the default INSERT in kbmcdatabase.sql:
-- 1=Laptop, 2=Desktop, 3=Printer, 4=Tablet, 5=Monitor,
-- 6=Network Equipment, 7=Peripherals, 8=Server, 9=Phone, 10=Other
INSERT INTO device_type_lifespans (device_type_id, default_years) VALUES
(1,  5),   -- Laptop
(2,  6),   -- Desktop
(3,  6),   -- Printer
(4,  4),   -- Tablet
(5,  7),   -- Monitor
(6,  8),   -- Network Equipment
(7,  3),   -- Peripherals
(8, 10),   -- Server
(9,  3),   -- Phone
(10, 5)    -- Other
ON DUPLICATE KEY UPDATE default_years = VALUES(default_years);

-- 3. Main lifespan forecast / remarks table
--    One row per device; IT staff can update condition & remarks at any time.
CREATE TABLE IF NOT EXISTS device_lifespan_forecast (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    device_id           INT          NOT NULL UNIQUE,
    reviewed_by         INT          DEFAULT NULL
        COMMENT 'IT staff who last reviewed this forecast',
    last_reviewed_date  DATE         DEFAULT NULL,
    override_lifespan_years TINYINT UNSIGNED DEFAULT NULL
        COMMENT 'Per-device lifespan override (NULL = use device_type_lifespans default)',
    forecast_status     ENUM(
                            'good',         -- on track, no action needed
                            'monitor',      -- approaching end-of-life (1–2 yrs left)
                            'replace_soon', -- <1 year left, plan replacement
                            'overdue',      -- past expected EOL, still in use
                            'replaced',     -- device has been replaced / retired
                            'extended'      -- lifespan officially extended by IT
                        ) NOT NULL DEFAULT 'good',
    remarks             TEXT         DEFAULT NULL
        COMMENT 'Free-text IT remarks: condition notes, replacement plan, budget cycle, etc.',
    created_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id)   REFERENCES devices(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id)   ON DELETE SET NULL,
    INDEX idx_forecast_status (forecast_status),
    INDEX idx_last_reviewed   (last_reviewed_date)
);
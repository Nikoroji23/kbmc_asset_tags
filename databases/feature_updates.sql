-- KBMC Asset Management - Feature Updates (May 2026)
-- Email Notifications, Serial Number Search, Dashboard Color Coding, User Asset Dashboard

-- 1. Maintenance Schedule Table (for preventive maintenance reminders)
CREATE TABLE IF NOT EXISTS maintenance_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    maintenance_type ENUM('preventive', 'corrective', 'calibration', 'update', 'inspection') DEFAULT 'preventive',
    description TEXT,
    scheduled_date DATE NOT NULL,
    due_reminder_days INT DEFAULT 7,
    last_performed_date DATE,
    next_due_date DATE,
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    assigned_to INT,
    requested_by INT,
    completed_by INT,
    completed_at DATETIME DEFAULT NULL,
    completion_notes TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id),
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (completed_by) REFERENCES users(id),
    INDEX idx_device_due (device_id, next_due_date),
    INDEX idx_priority (priority)
);

-- 2. Email Notification Queue (tracks sent/pending reminder emails)
CREATE TABLE IF NOT EXISTS email_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    recipient_email VARCHAR(100) NOT NULL,
    notification_type ENUM('maintenance_due', 'repair_pending', 'warranty_expiring', 'device_assigned', 'device_returned', 'custom') DEFAULT 'maintenance_due',
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    related_device_id INT,
    related_repair_id INT,
    status ENUM('pending', 'sent', 'failed', 'bounced') DEFAULT 'pending',
    sent_at TIMESTAMP NULL,
    failure_reason TEXT,
    retry_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (related_device_id) REFERENCES devices(id),
    FOREIGN KEY (related_repair_id) REFERENCES device_repairs(id),
    INDEX idx_status (status),
    INDEX idx_type (notification_type),
    INDEX idx_due (created_at)
);

-- 3. Extend devices table with serial number index if not exists (for faster searches)
-- Serial number already exists; just ensure it's indexed
ALTER TABLE devices ADD INDEX idx_serial_number (serial_number);
ALTER TABLE devices ADD INDEX idx_status_location (status, location);

-- Extend device_repairs table with color-coding reference
ALTER TABLE device_repairs 
ADD COLUMN IF NOT EXISTS severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
ADD COLUMN IF NOT EXISTS estimated_completion_date DATE,
ADD INDEX IF NOT EXISTS idx_repair_status_date (repair_status, started_date);

-- Update notifications table to include repair_completed type if not exists
ALTER TABLE notifications MODIFY COLUMN type ENUM('device_deployed', 'device_returned', 'low_stock', 'repair_needed', 'repair_completed', 'request_approved', 'request_rejected', 'warranty_expiring', 'audit_reminder');

-- 5. Create user preferences table for dashboard settings
CREATE TABLE IF NOT EXISTS user_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    dashboard_theme ENUM('light', 'dark') DEFAULT 'light',
    show_assigned_devices TINYINT(1) DEFAULT 1,
    show_maintenance_alerts TINYINT(1) DEFAULT 1,
    show_warranty_expiring TINYINT(1) DEFAULT 1,
    email_reminders_enabled TINYINT(1) DEFAULT 1,
    reminder_days_before INT DEFAULT 7,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 6. Create device status color mapping table
CREATE TABLE IF NOT EXISTS device_status_colors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    status VARCHAR(50) NOT NULL UNIQUE,
    color_code VARCHAR(7) NOT NULL,
    icon_class VARCHAR(50),
    display_label VARCHAR(100),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default status colors
INSERT INTO device_status_colors (status, color_code, icon_class, display_label, description) VALUES
('in_stock', '#27ae60', 'fas fa-box', 'In Stock', 'Device is in inventory and available'),
('deployed', '#3498db', 'fas fa-user-check', 'Deployed', 'Device is assigned to a user'),
('under_repair', '#f39c12', 'fas fa-wrench', 'Under Repair', 'Device is being repaired'),
('retired', '#95a5a6', 'fas fa-ban', 'Retired', 'Device is retired/end-of-life'),
('disposed', '#7f8c8d', 'fas fa-trash', 'Disposed', 'Device has been disposed'),
('pending_inspection', '#e67e22', 'fas fa-search', 'Pending Inspection', 'Device awaiting quality check'),
('rejected', '#e74c3c', 'fas fa-times-circle', 'Rejected', 'Device failed inspection')
ON DUPLICATE KEY UPDATE color_code = VALUES(color_code);

-- 7. Create maintenance reminders tracking
CREATE TABLE IF NOT EXISTS maintenance_reminders_sent (
    id INT AUTO_INCREMENT PRIMARY KEY,
    maintenance_id INT NOT NULL,
    email_notification_id INT,
    sent_to_user_id INT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (maintenance_id) REFERENCES maintenance_schedules(id) ON DELETE CASCADE,
    FOREIGN KEY (email_notification_id) REFERENCES email_notifications(id),
    FOREIGN KEY (sent_to_user_id) REFERENCES users(id)
);

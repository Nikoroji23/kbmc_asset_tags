-- KBMC Asset Management - Database Updates
-- Run ALL of these in phpMyAdmin SQL tab

-- 1. Add remember_token and failed_login columns to users
ALTER TABLE users 
ADD COLUMN remember_token VARCHAR(255) DEFAULT NULL,
ADD COLUMN failed_logins INT DEFAULT 0,
ADD COLUMN locked_until DATETIME DEFAULT NULL;

-- 2. Create password_resets table (if not exists)
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 3. Create account_recovery_requests table
CREATE TABLE IF NOT EXISTS account_recovery_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    request_reason TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME DEFAULT NULL,
    resolved_by INT DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(id)
);

-- 4. Add pc_name hostname support for devices
ALTER TABLE devices ADD COLUMN pc_name VARCHAR(100) DEFAULT NULL AFTER ip_address;

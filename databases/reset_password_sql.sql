-- Add password reset columns to users table
-- Run this in phpMyAdmin SQL tab

ALTER TABLE users 
ADD COLUMN reset_token VARCHAR(255) DEFAULT NULL,
ADD COLUMN reset_expires DATETIME DEFAULT NULL;

-- Create password_resets table (alternative approach)
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

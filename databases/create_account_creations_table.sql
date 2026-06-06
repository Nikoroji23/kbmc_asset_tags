-- KBMC Asset Management - Account Creations Tracking Table
-- Run this SQL to add account creation tracking

-- Create account_creations table if not exists
CREATE TABLE IF NOT EXISTS account_creations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    employee_id VARCHAR(50) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    department VARCHAR(100),
    position VARCHAR(100),
    phone VARCHAR(20),
    created_by VARCHAR(100) DEFAULT 'self_registration',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_created_at (created_at),
    INDEX idx_employee_id (employee_id),
    INDEX idx_email (email)
);

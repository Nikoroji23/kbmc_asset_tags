-- KBMC Asset Management - Security System Updates
-- Add Master Key & Admin Approval System

-- Add security fields to users table
ALTER TABLE users ADD COLUMN IF NOT EXISTS master_key_hash VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_security_admin BOOLEAN DEFAULT 0 COMMENT 'Only the main IT admin with security access';
ALTER TABLE users ADD COLUMN IF NOT EXISTS security_key_verified BOOLEAN DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS security_key_verified_at TIMESTAMP NULL;

-- Create table for IT/Admin user approval requests
CREATE TABLE IF NOT EXISTS user_approval_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requested_by INT NOT NULL,
    employee_id VARCHAR(50),
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    requested_role ENUM('it_staff', 'admin') NOT NULL,
    department VARCHAR(100),
    position VARCHAR(100),
    phone VARCHAR(20),
    password_hash VARCHAR(255) NOT NULL,
    reason TEXT,
    status ENUM('pending', 'approved', 'rejected', 'cancelled') DEFAULT 'pending',
    approved_by INT NULL,
    approved_at TIMESTAMP NULL,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
);

-- Create table for master key usage audit
CREATE TABLE IF NOT EXISTS master_key_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    security_key_used BOOLEAN DEFAULT 0,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Create table for security key verification logs
CREATE TABLE IF NOT EXISTS security_key_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(100),
    success BOOLEAN DEFAULT 1,
    attempt_ip VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Set initial security admin (first admin user - ID 1 usually)
-- Uncomment after checking your first admin user ID:
-- UPDATE users SET is_security_admin = 1 WHERE id = 1 AND role = 'admin';

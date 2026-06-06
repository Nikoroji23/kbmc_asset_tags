-- KBMC Device Arrival & Asset Management System
-- Database Schema for XAMPP MySQL
-- Run this in phpMyAdmin to create the database

CREATE DATABASE IF NOT EXISTS kbmc_asset_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE kbmc_asset_db;

-- Users Table (Admin, IT Staff, Employees)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) UNIQUE NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'it_staff', 'employee') DEFAULT 'employee',
    department VARCHAR(100),
    position VARCHAR(100),
    phone VARCHAR(20),
    profile_image VARCHAR(255),
    status ENUM('active', 'inactive') DEFAULT 'active',
    failed_logins INT DEFAULT 0,
    last_failed_attempt TIMESTAMP NULL,
    locked_until TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Device Categories/Types
CREATE TABLE device_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type_name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Devices Table
CREATE TABLE devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_tag VARCHAR(100) NULL DEFAULT NULL,
    device_type_id INT NOT NULL,
    serial_number VARCHAR(100) UNIQUE NOT NULL,
    ip_address VARCHAR(50),
    pc_name VARCHAR(100),
    purchase_date DATE,
    vendor VARCHAR(100),
    warranty_expiry DATE,
    purchase_price DECIMAL(12,2),
    status ENUM('in_stock', 'deployed', 'under_repair', 'retired', 'disposed', 'pending_inspection', 'rejected') DEFAULT 'pending_inspection',
    condition_notes TEXT,
    location VARCHAR(100),
    image VARCHAR(255),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (device_type_id) REFERENCES device_types(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Device Assignments (Deployment)
CREATE TABLE device_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    employee_id INT NOT NULL,
    assigned_by INT NOT NULL,
    assigned_date DATE NOT NULL,
    returned_date DATE,
    purpose TEXT,
    accountability_form_signed TINYINT(1) DEFAULT 0,
    form_file VARCHAR(255),
    notes TEXT,
    status ENUM('active', 'returned', 'transferred') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id),
    FOREIGN KEY (employee_id) REFERENCES users(id),
    FOREIGN KEY (assigned_by) REFERENCES users(id)
);

-- Device Inspections
CREATE TABLE device_inspections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    inspected_by INT NOT NULL,
    inspection_date DATE NOT NULL,
    physical_condition ENUM('excellent', 'good', 'fair', 'poor', 'damaged') NOT NULL,
    functionality_status ENUM('fully_functional', 'partially_functional', 'not_functional') NOT NULL,
    result ENUM('passed', 'rejected') NOT NULL,
    notes TEXT,
    rejection_reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id),
    FOREIGN KEY (inspected_by) REFERENCES users(id)
);

-- Device Repairs
CREATE TABLE device_repairs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    reported_by INT NOT NULL,
    issue_description TEXT NOT NULL,
    incident_report_file VARCHAR(255),
    repair_status ENUM('pending', 'under_repair', 'completed', 'not_repairable') DEFAULT 'pending',
    repair_cost DECIMAL(10,2),
    repair_notes TEXT,
    started_date DATE,
    completed_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id),
    FOREIGN KEY (reported_by) REFERENCES users(id)
);

-- Device Requests (Employees can request devices)
CREATE TABLE device_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requester_id INT NOT NULL,
    device_type_id INT,
    request_reason TEXT NOT NULL,
    urgency ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    status ENUM('pending', 'approved', 'rejected', 'fulfilled') DEFAULT 'pending',
    approved_by INT,
    approved_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requester_id) REFERENCES users(id),
    FOREIGN KEY (device_type_id) REFERENCES device_types(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
);

-- Notifications
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM('device_deployed', 'device_returned', 'low_stock', 'repair_needed', 'request_approved', 'request_rejected', 'warranty_expiring', 'audit_reminder') NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    related_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Audit Logs
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    table_name VARCHAR(50),
    record_id INT,
    activity_type VARCHAR(50),
    old_values TEXT,
    new_values TEXT,
    ip_address VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Periodic Audits
CREATE TABLE periodic_audits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    audit_date DATE NOT NULL,
    conducted_by INT NOT NULL,
    total_devices INT DEFAULT 0,
    devices_found INT DEFAULT 0,
    devices_missing INT DEFAULT 0,
    notes TEXT,
    status ENUM('in_progress', 'completed') DEFAULT 'in_progress',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conducted_by) REFERENCES users(id)
);

-- Audit Details
CREATE TABLE audit_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    audit_id INT NOT NULL,
    device_id INT NOT NULL,
    expected_status VARCHAR(50),
    actual_status VARCHAR(50),
    found TINYINT(1) DEFAULT 1,
    notes TEXT,
    FOREIGN KEY (audit_id) REFERENCES periodic_audits(id),
    FOREIGN KEY (device_id) REFERENCES devices(id)
);

-- Insert Default Device Types
INSERT INTO device_types (type_name, description) VALUES
('Laptop', 'Portable computers for mobile work'),
('Desktop', 'Stationary desktop computers'),
('Printer', 'Printers and multifunction devices'),
('Tablet', 'Tablet devices'),
('Monitor', 'Display monitors'),
('Network Equipment', 'Routers, switches, access points'),
('Peripherals', 'Keyboards, mice, webcams, etc.'),
('Server', 'Server hardware'),
('Phone', 'Office phones and mobile devices'),
('Other', 'Other miscellaneous devices');

-- Insert Default Admin User
-- Passwords: admin = admin123, it_staff = itstaff123, employee = employee123
INSERT INTO users (employee_id, full_name, email, password, role, department, position, status) VALUES
('KBMC-ADMIN-001', 'System Administrator', 'admin@kbmc.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'IT Department', 'System Administrator', 'active'),
('KBMC-IT-001', 'IT Staff Member', 'itstaff@kbmc.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'it_staff', 'IT Department', 'IT Specialist', 'active'),
('KBMC-EMP-001', 'Sample Employee', 'employee@kbmc.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 'Sales Department', 'Sales Associate', 'active');

-- Insert Sample Devices
INSERT INTO devices (asset_tag, device_type_id, serial_number, ip_address, purchase_date, vendor, warranty_expiry, purchase_price, status, condition_notes, location, created_by) VALUES
('KBMC-LAP-001', 1, 'SN123456789', '192.168.1.101', '2024-01-15', 'Dell Philippines', '2027-01-15', 45000.00, 'in_stock', 'Intel i5, 16GB RAM, 512GB SSD, Windows 11', 'IT Stock Room', 1),
('KBMC-DESK-001', 2, 'SN987654321', '192.168.1.102', '2024-02-20', 'HP Philippines', '2027-02-20', 55000.00, 'in_stock', 'Intel i7, 32GB RAM, 1TB SSD, Windows 11 Pro', 'IT Stock Room', 1),
('KBMC-PRT-001', 3, 'SN456789123', '192.168.1.103', '2024-03-10', 'HP Philippines', '2027-03-10', 18000.00, 'deployed', 'Monochrome Laser, Network Ready', 'Sales Department', 1),
('KBMC-MON-001', 5, 'SN789123456', NULL, '2024-01-25', 'Samsung Philippines', '2027-01-25', 12000.00, 'deployed', '27-inch, Full HD, IPS Panel', 'Sales Department', 1),
('KBMC-LAP-002', 1, 'SN321654987', '192.168.1.104', '2024-04-05', 'Lenovo Philippines', '2027-04-05', 68000.00, 'under_repair', 'Intel i7, 16GB RAM, 512GB SSD, Windows 11 Pro', 'IT Stock Room', 1);

-- Insert Sample Assignment
INSERT INTO device_assignments (device_id, employee_id, assigned_by, assigned_date, purpose, accountability_form_signed, status) VALUES
(3, 3, 1, '2024-03-15', 'Daily sales operations and reporting', 1, 'active'),
(4, 3, 1, '2024-03-15', 'Secondary monitor for productivity', 1, 'active');

-- Insert Sample Notification
INSERT INTO notifications (user_id, type, title, message, related_id) VALUES
(3, 'device_deployed', 'Device Deployed', 'A HP LaserJet Pro M404n has been assigned to you.', 1),
(1, 'low_stock', 'Low Stock Alert', 'Only 1 Dell Latitude 5520 remaining in stock.', 1);

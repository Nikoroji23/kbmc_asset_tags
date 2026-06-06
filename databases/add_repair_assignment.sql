-- Add assigned_to field to device_repairs table for tracking who is repairing the device
ALTER TABLE device_repairs ADD COLUMN assigned_to INT DEFAULT NULL AFTER reported_by;
ALTER TABLE device_repairs ADD FOREIGN KEY (assigned_to) REFERENCES users(id);

-- Add completed_by field to track who completed the repair
ALTER TABLE device_repairs ADD COLUMN completed_by INT DEFAULT NULL AFTER completed_date;
ALTER TABLE device_repairs ADD FOREIGN KEY (completed_by) REFERENCES users(id);

-- Create index for faster queries
ALTER TABLE device_repairs ADD INDEX idx_assigned_to (assigned_to);
ALTER TABLE device_repairs ADD INDEX idx_completed_by (completed_by);

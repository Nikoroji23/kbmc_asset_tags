-- Add disposal tracking columns to devices table
-- Records which user disposed of the device and when

ALTER TABLE devices ADD COLUMN IF NOT EXISTS disposed_by INT DEFAULT NULL AFTER updated_at;
ALTER TABLE devices ADD COLUMN IF NOT EXISTS disposed_at TIMESTAMP NULL AFTER disposed_by;

-- Add foreign key for disposed_by
ALTER TABLE devices ADD CONSTRAINT fk_devices_disposed_by 
FOREIGN KEY (disposed_by) REFERENCES users(id) ON DELETE SET NULL;

-- Create index for faster queries on disposed devices
CREATE INDEX IF NOT EXISTS idx_devices_disposed_by ON devices(disposed_by);
CREATE INDEX IF NOT EXISTS idx_devices_disposed_at ON devices(disposed_at);

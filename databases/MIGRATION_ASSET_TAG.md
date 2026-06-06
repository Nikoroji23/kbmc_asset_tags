# Database Update: Remove Asset Tag UNIQUE Constraint

## Purpose
Allow multiple devices to have the same asset tag number. This supports equipment sets where a Laptop, Charger, Mouse, and Keyboard all share the same asset tag (e.g., KBMC-LT-001).

## Required Action
Run the migration script to update the database schema:

### Option 1: Using PHPMyAdmin
1. Go to PHPMyAdmin
2. Select database `kbmcdatabase`
3. Click "SQL" tab
4. Copy and paste the contents of `remove_asset_tag_unique_constraint.sql`
5. Click Execute

### Option 2: Using Command Line
```bash
mysql -u root kbmcdatabase < remove_asset_tag_unique_constraint.sql
```

### Option 3: Manual Query
```sql
ALTER TABLE devices 
DROP INDEX asset_tag,
MODIFY COLUMN asset_tag VARCHAR(100) NULL DEFAULT NULL;
```

## What This Does
- ✓ Removes UNIQUE constraint on asset_tag column
- ✓ Allows NULL values for asset_tag (used for N/A entries)
- ✓ Allows duplicate asset tags across all devices
- ✓ Supports equipment sets with shared asset tags

## Examples After Update
- Device 1 (Laptop) → KBMC-LT-001 ✓
- Device 2 (Charger) → KBMC-LT-001 ✓ (now allowed!)
- Device 3 (Mouse) → KBMC-LT-001 ✓ (now allowed!)
- Device 4 (Keyboard) → KBMC-LT-001 ✓ (now allowed!)
- Device 5 (Unknown) → N/A (NULL) ✓
- Device 6 (Unknown) → N/A (NULL) ✓ (multiple N/A allowed!)

## After Running the Migration
1. Users can add devices with the same asset tag
2. Success alerts will show device details
3. Edit devices will show when asset tag was changed
4. No more "Duplicate entry" errors

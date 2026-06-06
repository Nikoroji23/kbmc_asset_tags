# Asset Import System - Implementation Guide

## Overview
The Asset Import System allows administrators to bulk import employee and device information from an Excel CSV file. This creates user accounts and automatically assigns devices to employees.

## Features Implemented

### 1. **Bulk User Account Creation**
- Creates user accounts from CSV file with employee names
- Generates automatic email addresses: `firstname.lastname@kbmc.com`
- Auto-generates Employee IDs (5-digit incremental numbers)
- Sets default password: `password`
- Assigns user role: `employee`
- Links users to their department

### 2. **Bulk Device Creation & Assignment**
- Automatically creates device records for all asset columns
- Devices include: Monitors, Keyboards, Mice, System Units, UPS, Laptops, Chargers, Printers, Storage, Switches
- Skips empty or invalid asset tags (N/A, KBM-IT-00)
- Automatically links devices to employees
- Sets all devices to "deployed" status

### 3. **Editable Profile Fields**
Users can now edit the following profile information:
- **Email Address** - Can be changed by the user
- **Employee ID** - Can be updated
- **Contact (Phone)** - Store phone number
- **Password** - Change password with current password verification
- Full Name, Department, and Position remain for reference

### 4. **Device Dashboard**
- Employees can view all their assigned devices in "My Devices"
- Displays device details: Asset Tag, Type, Brand, Model, Status
- Shows assigned date and maintenance status
- View full device details

## How to Use

### Step 1: Prepare Your Import File
The file must be a **CSV or Excel (.xlsx)** workbook with the following columns (in this exact order):
1. NAME
2. DEPARTMENT
3. PC NAME
4. IP ADDRESS
5. MONITOR 1
6. MONITOR 2
7. MOUSE
8. KEYBOARD
9. SYSTEM UNIT
10. UPS
11. LAPTOP
12. CHARGER
13. MOUSE 1
14. PRINTER 1
15. PRINTER 2
16. STORAGE
17. SWITCH
18. REMARKS

**Sample Format:**
```
NAME,DEPARTMENT,PC NAME,IP ADDRESS,MONITOR 1,MONITOR 2,MOUSE,KEYBOARD,SYSTEM UNIT,UPS,LAPTOP,CHARGER,MOUSE 1,PRINTER 1,PRINTER 2,STORAGE,SWITCH,REMARKS
KATHLEEN DE GUZMAN,ACCOUNTING,PC22111,192.168.22.111,KBM-IT-00001,KBM-IT-00002,KBM-IT-00003,KBM-IT-00004,KBM-IT-00005,KBM-IT-00006,KBM-IT-00007,KBM-IT-00008,KBM-IT-00009,,,
```
### Step 2: Access Import Page

1. Log in as Administrator
2. Go to **Admin Dashboard**
3. Click **Import Assets** button
4. Or navigate directly to: `/import_assets.php`

### Step 3: Upload CSV File

1. Click "Select CSV File"
2. Choose your prepared CSV file (max 5MB)
3. Click "Import Assets"
4. Wait for processing to complete

### Step 4: Review Import Results

The system will display:
- Number of users created
- Number of devices created
- Number of assignments created
- Any errors encountered during import

## Default Login Credentials

After import, each employee can login with:
- **Email:** `firstname.lastname@kbmc.com` (auto-generated)
- **Password:** `password` (default)

**⚠️ Important:** Users should change their password immediately upon first login.

## What Gets Created

### For Each Employee:
✓ User account with role "employee"
✓ Auto-generated email address
✓ Auto-generated Employee ID
✓ Department assignment
✓ Active status

### For Each Asset:
✓ Device record with asset tag
✓ Device type (Monitor, Keyboard, Mouse, etc.)
✓ Serial number = Asset tag
✓ Deployment status = "deployed"
✓ Link to employee (if asset tag is valid)

## User Profile Editable Fields

After account creation, users can edit:

1. **Employee ID** - Update their employee identification number
2. **Email Address** - Change email (must be unique)
3. **Contact (Phone)** - Add or update phone number
4. **Full Name** - Update display name
5. **Password** - Change password (requires current password verification)

Access at: `/profile.php`

## Device Assignment Display

Users can view all assigned devices:
- Path: `/user_asset_dashboard.php` or "My Devices" in navigation
- Displays: Asset tag, device type, brand, model, status
- Shows: Assignment date, maintenance schedule, repair status
- Option to: View full device details

## Data Import Verification

After import, administrators can:
1. Check **Manage Users** for newly created employee accounts
2. Check **All Devices** for new device records
3. Verify device assignments under each user's profile
4. Review **Audit Logs** for import activity

## Important Notes

### Duplicate Prevention:
- System checks if email/employee ID already exists
- Existing users won't be duplicated
- Existing devices won't be recreated

### Asset Tag Validation:
- Assets marked as "N/A" are skipped
- Assets marked as "KBM-IT-00" are skipped (placeholder)
- Empty asset fields are skipped
- Only valid asset tags create device records

### Device Status:
- All imported devices start as "deployed"
- This means they are considered assigned to employees
- IT staff can change status through device management

## Troubleshooting

### Import fails with "Cannot read CSV header"
- Ensure file is properly formatted as CSV
- Check that header row is present
- Verify Excel file was exported as CSV, not XLSX

### Duplicate email errors
- Check if user with this email already exists
- System will skip creating duplicate users
- Existing user will have devices linked

### Assets not creating devices
- Check asset tag format (should match KBM-IT-XXXXX)
- Verify "KBM-IT-00" and "N/A" are skipped
- Review error list in import summary

## Security Considerations

1. **Default Password:** All users get "password" - enforce password change on first login
2. **Email Verification:** Verify email addresses are correct during import
3. **Employee ID:** Ensure Employee IDs are unique and sequential
4. **Audit Trail:** All imports are logged in audit logs
5. **Validation:** Email addresses are validated before update

## Next Steps

1. **Import your CSV file** using the Import Assets page
2. **Verify user creation** in Manage Users
3. **Distribute login credentials** to employees
4. **Encourage password changes** on first login
5. **Review assigned devices** in device management
6. **Set up maintenance schedules** for devices as needed

## Support

For issues with import:
- Check the CSV file format matches the requirements
- Ensure all required columns are present
- Review error messages in import summary
- Check audit logs for detailed information

---

**Created:** 2026-05-25
**Version:** 1.0
**Last Updated:** 2026-05-25

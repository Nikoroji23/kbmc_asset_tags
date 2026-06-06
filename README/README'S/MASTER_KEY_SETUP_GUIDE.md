# KBMC Asset Management - Master Key Security System & Dedicated Dashboards
## Complete Setup & Usage Guide

---

## 📋 OVERVIEW

### System Features Implemented

1. **Dedicated Dashboards**
   - ✅ IT Dashboard (`it_dashboard.php`) - For IT Staff
   - ✅ Admin Dashboard (`admin_dashboard.php`) - For Administrators
   - ✅ Role-specific navigation in sidebar

2. **Master Key Security System**
   - ✅ Security Admin designation for main IT administrator
   - ✅ Master key generation and verification
   - ✅ IT/Admin user approval workflow
   - ✅ Master key audit logging

3. **User Approval Workflow**
   - ✅ Approval request system for IT/Admin user creation
   - ✅ Security control center for approvals
   - ✅ Role-based access control

---

## 🚀 SETUP INSTRUCTIONS

### Step 1: Database Setup

Run the SQL script to add new tables:

```sql
-- In phpMyAdmin, go to your kbmc_asset_db database
-- Copy and paste the contents of: database_security_updates.sql
-- This creates:
-- - user_approval_requests table
-- - master_key_audit table
-- - security_key_logs table
```

**Quick Check**: After running SQL, verify tables exist:
```sql
SHOW TABLES LIKE '%approval%';
SHOW TABLES LIKE '%key_audit%';
```

### Step 2: Designate Security Admin

Only ONE admin should be the Security Admin. To set this up:

```sql
-- Find your main admin user ID (usually 1)
SELECT id, full_name, email, role FROM users WHERE role = 'admin' LIMIT 1;

-- Mark as security admin (replace 1 with actual ID)
UPDATE users SET is_security_admin = 1 WHERE id = 1 AND role = 'admin';

-- Verify
SELECT id, full_name, is_security_admin FROM users WHERE role = 'admin';
```

### Step 3: Generate Master Key

The Security Admin needs to generate their master key:

1. Log in as the designated Security Admin
2. Go to: **Admin Dashboard** → **Security Control**
3. Click: **Verify Master Key** button
4. Since no key exists yet, you'll set up a new one (in production, use `security_setup.php`)

**For now**, generate a master key manually and set it:

```php
<?php
require 'includes/functions.php';
// Generate a secure master key
$masterKey = generateMasterKey(); // 32 characters
echo "Master Key: " . $masterKey;
echo "\nSave this securely! It cannot be recovered.";
?>
```

Or use the SQL approach to set a test key:
```php
// In a test PHP file:
$masterKey = 'test_master_key_1234567890abcdef';
$hashedKey = password_hash($masterKey, PASSWORD_BCRYPT);
$pdo->prepare("UPDATE users SET master_key_hash = ? WHERE id = 1")->execute([$hashedKey]);
```

---

## 📊 DEDICATED DASHBOARDS

### IT Dashboard (`it_dashboard.php`)

**Accessible By**: IT Staff only  
**Features**:
- 📈 IT-specific metrics (Pending Repairs, Inspections, Deployments)
- 🔧 Quick Actions (Add Device, Inspect, Manage Repairs, Deploy)
- 📋 Pending Repairs list
- ✓ Recent Inspections
- 📥 Pending Device Requests
- 📤 Recent Deployments
- 📊 Device status overview

**Navigation**: Click "IT Dashboard" in sidebar (appears for IT Staff)

### Admin Dashboard (`admin_dashboard.php`)

**Accessible By**: Admins only  
**Features**:
- 👥 User management metrics (Total Users, Admins, IT Staff, Employees)
- ⚠️ Pending Approvals counter
- 🛡️ Security control access
- 🔄 Admin controls (Manage Users, Account Records, Audit Logs, Reports)
- 📋 Pending IT/Admin user approvals (if Security Admin)
- 📨 Account recovery requests
- 📜 Recent system activity

**Navigation**: Click "Admin Dashboard" in sidebar (appears for Admins)

---

## 🔐 MASTER KEY & APPROVAL WORKFLOW

### User Creation Flow

#### For Regular Employees:
1. Admin: Go to **Users** → **Manage Users**
2. Click **Add New User**
3. Select Role: **Employee**
4. Fill form and submit
5. ✅ User created immediately

#### For IT Staff or Admin:
1. Admin: Go to **Users** → **Manage Users**
2. Click **Add New User**
3. Select Role: **IT Staff** or **Administrator**
4. ⚠️ Warning appears: "Approval Required"
5. Fill in: Full Name, Email, Password, Reason for creation
6. Click **Add User**
7. Request submitted for Security Admin approval
8. Security Admin receives approval request
9. Security Admin approves with Master Key
10. ✅ New IT/Admin user created

### Approval Process

**Security Admin**: Go to **Admin Dashboard** → **Security Control**

1. **Master Key Status** shows: NOT VERIFIED or VERIFIED
2. Click **Verify Master Key** button
3. Enter your 32-character master key
4. ✅ Master key verified (session timeout: 30 minutes)

**Approve User**:
1. In "Pending IT/Admin User Approvals" table
2. Click **✓** (approve) button on request
3. Enter Master Key in modal
4. Click **Approve & Create User**
5. ✅ New user created and activated

**Reject User**:
1. Click **✕** (reject) button on request
2. (Optional) Add rejection reason
3. Click **Reject Request**
4. ✅ Request denied

---

## 📁 NEW FILES CREATED

```
c:\xampp\htdocs\kbmc_new_asset\
├── it_dashboard.php                    (NEW - IT Staff Dashboard)
├── admin_dashboard.php                 (NEW - Admin Dashboard)
├── security_control.php                (NEW - Master Key & Approval Center)
├── database_security_updates.sql       (NEW - Database schema updates)
├── MASTER_KEY_SETUP_GUIDE.md          (This file)
```

## ✏️ FILES MODIFIED

```
├── includes/functions.php              (+ Security functions)
├── includes/header.php                 (+ Role-based dashboard navigation)
├── user_actions.php                    (+ Approval workflow for IT/Admin)
├── users.php                           (+ Request reason field, approval notice)
├── login.php                           (Already updated - role validation)
├── dashboard.php                       (Already updated - role banner)
```

---

## 🧪 TESTING THE SYSTEM

### Test 1: Create IT Staff User (with Approval)

1. **As Admin**, go to Users → Add New User
2. Enter:
   - Full Name: "Test IT User"
   - Email: "test_it@kbmc.com"
   - Password: "TestPass123"
   - Role: **IT Staff** ← SELECT THIS
   - Department: "IT"
   - Reason: "Need IT staff to manage devices"
3. Click **Add User**
4. ✅ Message: "User creation request submitted for IT Staff approval"

### Test 2: Approve User as Security Admin

1. **As Security Admin**, go to Admin Dashboard
2. See "Pending IT/Admin User Approvals (1)"
3. Click **Security Control**
4. Click **Verify Master Key**
5. Enter your master key
6. ✅ Message: "Master key verified!"
7. In approval table, click **✓** on the pending request
8. Enter master key again in modal
9. Click **Approve & Create User**
10. ✅ Message: "User approved and created successfully"

### Test 3: Test IT Staff Login

1. Log out
2. Log in as: test_it@kbmc.com / TestPass123
3. ✅ Dashboard shows: **IT Staff Dashboard** (blue badge)
4. Sidebar shows: "IT Dashboard" link
5. Can access device management features
6. Cannot access user management (Admin only)

### Test 4: Test Admin Login

1. Go to Users → Add New User
2. Role: **Administrator**
3. Similar process - needs approval
4. Once approved, login
5. ✅ Dashboard shows: **Administrator Dashboard** (red badge)
6. Full admin access

---

## 🔑 MASTER KEY MANAGEMENT

### Generating a New Master Key

For production:
```php
<?php
require 'includes/functions.php';

// Generate secure 32-character master key
$masterKey = generateMasterKey();

// Store ONLY the hash in database
$hash = password_hash($masterKey, PASSWORD_BCRYPT);

// Display to admin ONCE
echo "New Master Key: " . $masterKey;
echo "\nImmediately save this in a secure location (password manager, encrypted file)";
echo "\nThis key cannot be recovered if lost!";
?>
```

### Master Key Security Rules

1. **Never share** your master key
2. **Never commit** master key to version control
3. **Only the Security Admin** should have the key
4. **Backup the key** in a secure location
5. **Change the key** if compromised
6. **Log all usage** - check security_key_logs table
7. **Session timeout**: 30 minutes of inactivity

### Viewing Master Key Audit

To see who approved what and when:

```sql
-- View master key usage
SELECT u.full_name, ska.action, ska.success, ska.attempt_ip, ska.created_at 
FROM security_key_logs ska 
JOIN users u ON ska.user_id = u.id 
ORDER BY ska.created_at DESC;

-- View user approvals
SELECT u1.full_name as requested_by, uar.full_name as new_user, uar.requested_role, uar.status, u2.full_name as approved_by, uar.approved_at
FROM user_approval_requests uar
LEFT JOIN users u1 ON uar.requested_by = u1.id
LEFT JOIN users u2 ON uar.approved_by = u2.id
ORDER BY uar.created_at DESC;
```

---

## ⚠️ IMPORTANT NOTES

### Access Control
- ✅ Only Admins can access: `/users.php`
- ✅ Only IT Staff+ can access: `/devices.php`, `/repairs.php`, etc.
- ✅ Only Security Admin can access: `/security_control.php`
- ✅ Employees can only request devices, view own assignments

### Master Key Security
- Generate in development: `generateMasterKey()` function
- Store hashed version in database only
- Never log the actual key
- Verify before sensitive operations

### Approval Process
- Approval requests are **permanent records**
- Cannot be deleted, only approved/rejected
- Rejection reason is optional but recommended
- All actions are audit logged

### Dashboard Access
- Automatic redirect based on role
- IT Staff → `it_dashboard.php`
- Admin → `admin_dashboard.php`
- Employee → `dashboard.php`
- Role verified on each login

---

## 🐛 TROUBLESHOOTING

### Issue: "Invalid master key" error

**Solution**: Verify the key was entered correctly
```sql
-- Check if key exists for user
SELECT is_security_admin, master_key_hash FROM users WHERE id = YOUR_ADMIN_ID;
-- If master_key_hash is NULL, key hasn't been set
```

### Issue: Approval button doesn't appear

**Ensure**: User is designated as Security Admin
```sql
UPDATE users SET is_security_admin = 1 WHERE id = YOUR_ADMIN_ID;
```

### Issue: IT Dashboard doesn't appear

**Check**:
1. User role is 'it_staff' in database
2. User logged in fresh (clear cookies/cache)
3. Navigate directly: `/it_dashboard.php`

### Issue: Pending approvals not showing

**Verify**:
```sql
SELECT * FROM user_approval_requests WHERE status = 'pending';
```

---

## ✅ CHECKLIST

- [ ] Database schema updated (`database_security_updates.sql`)
- [ ] Designated Security Admin (is_security_admin = 1)
- [ ] Master key generated and saved securely
- [ ] Master key hash stored in database
- [ ] Test creating Employee user (works immediately)
- [ ] Test requesting IT Staff user (shows approval warning)
- [ ] Test approving with master key (works)
- [ ] Test IT Staff login (sees IT Dashboard)
- [ ] Test Admin login (sees Admin Dashboard)
- [ ] Security Control page accessible
- [ ] Audit logs showing approval history

---

## 📞 SUPPORT

For issues or questions about the Master Key System:
1. Check the audit logs in **Audit Logs** page
2. Verify role and permissions in **Users** → **Manage Users**
3. Review security settings in **Security Control**


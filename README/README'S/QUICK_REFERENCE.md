# 🔐 MASTER KEY SYSTEM - QUICK REFERENCE

## What's New

### 1. **Dedicated Dashboards**
| Role | Dashboard URL | Features |
|------|---------------|----------|
| 👥 IT Staff | `/it_dashboard.php` | Repairs, Inspections, Deployments, Device Requests |
| 👑 Admin | `/admin_dashboard.php` | User Management, Approvals, Audit Logs, System Stats |
| 💼 Employee | `/dashboard.php` | Basic dashboard (unchanged) |

### 2. **Master Key System**

**Purpose**: Only authorize main IT admin to approve creation of IT and Admin users

**Process**:
```
Admin Creates IT/Admin User
          ↓
Creates Approval Request (NOT direct creation)
          ↓
Security Admin Verifies Master Key
          ↓
Security Admin Approves Request
          ↓
User Created & Activated
```

### 3. **Who Can Do What**

| Action | Employee | IT Staff | Admin | Security Admin |
|--------|----------|----------|-------|----------------|
| Create Employee User | ✗ | ✗ | ✓ | ✓ |
| Request IT/Admin User | ✗ | ✗ | ✓ | ✓ |
| Approve IT/Admin User | ✗ | ✗ | ✗ | ✓ |
| View IT Dashboard | ✗ | ✓ | ✓ | ✓ |
| View Admin Dashboard | ✗ | ✗ | ✓ | ✓ |
| Manage Devices | ✗ | ✓ | ✓ | ✓ |
| View Audit Logs | ✗ | ✗ | ✓ | ✓ |

---

## 📝 Initial Setup (5 minutes)

### 1. Run Database Updates
```sql
-- Open phpMyAdmin → kbmc_asset_db → SQL tab
-- Copy entire contents of: database_security_updates.sql
-- Execute
```

### 2. Set Security Admin
```sql
-- Replace ID 1 with your main admin's actual ID
UPDATE users SET is_security_admin = 1 WHERE id = 1 AND role = 'admin';
```

### 3. Generate Master Key
```php
<?php
require 'includes/functions.php';
$key = generateMasterKey();
echo $key; // Save this securely!
// Set it with: setMasterKey($admin_id, $key);
?>
```

### 4. Test It Out!
- Log in as Admin
- Go to Users → Add New User
- Select Role: IT Staff
- Fill form + enter reason
- See "Approval Required" warning ✓

---

## 🎯 User Creation Flows

### Create Regular Employee (Direct)
```
Admin → Users → Add New User
→ Role: Employee → Add User ✓
→ User Created Immediately
```

### Create IT Staff / Admin (Approval)
```
Admin → Users → Add New User
→ Role: IT Staff/Admin → Add User
→ "Approval Required" ⚠️
→ Request Submitted
  ↓
Security Admin → Admin Dashboard → Security Control
→ Verify Master Key
→ Approve Request (enter master key)
→ User Created ✓
```

---

## 🔑 Master Key Rules

- **32 characters** long (alphanumeric)
- **Never share** with anyone
- **Backup securely** (password manager, encrypted file)
- **Cannot be recovered** if lost
- **Session expires** after 30 minutes
- **All usage logged** automatically

---

## 📊 Dashboard Overview

### IT Dashboard Shows:
- Pending Repairs counter
- Pending Inspections counter  
- Active Deployments
- Pending Device Requests
- Device Status breakdown
- Quick action buttons
- Recent repairs, inspections, deployments

### Admin Dashboard Shows:
- Total users count
- Admin count, IT Staff count, Employee count
- Total devices
- **Pending approvals** counter (Security Admin)
- Pending account recovery requests
- Quick admin control buttons
- Recent system activity log

---

## 🧪 Test Scenario

**Goal**: Verify entire Master Key + Approval system works

### Step 1: Create Request
1. Login as Admin (main account)
2. Users → Manage Users → Add New User
3. Name: "Test User"
4. Email: "test@kbmc.com"
5. Password: "TestPass123"
6. **Role: IT Staff** ← KEY!
7. Reason: "Testing approval system"
8. Click Add User
9. ✅ See: "User creation request submitted for IT Staff approval"

### Step 2: View Request
1. Admin Dashboard
2. See "Pending Approvals (1)"
3. View details of request

### Step 3: Approve Request
1. Admin Dashboard → **Security Control**
2. Click **Verify Master Key**
3. Enter master key (your 32-char key)
4. ✅ "Master key verified!"
5. In Pending Approvals table
6. Click **✓** (approve button)
7. Enter master key again
8. Click **Approve & Create User**
9. ✅ "User approved and created successfully"

### Step 4: Test New IT User
1. Logout
2. Login as: test@kbmc.com / TestPass123
3. ✅ See **IT Dashboard** (blue badge top)
4. ✅ Can see Device Management options
5. ✅ Can access repairs, inspections, etc.
6. ✅ Cannot access User Management

---

## 📂 New Files

| File | Purpose |
|------|---------|
| `it_dashboard.php` | IT Staff dedicated dashboard |
| `admin_dashboard.php` | Admin dedicated dashboard |
| `security_control.php` | Master Key verification & approvals |
| `database_security_updates.sql` | New database tables |
| `MASTER_KEY_SETUP_GUIDE.md` | Detailed setup instructions |
| `QUICK_REFERENCE.md` | This file |

---

## 🔍 Verification Commands

### Check if Security Admin is set:
```sql
SELECT full_name, is_security_admin FROM users WHERE role = 'admin';
```

### Check pending approvals:
```sql
SELECT full_name, requested_role, reason, created_at FROM user_approval_requests WHERE status = 'pending';
```

### View approval history:
```sql
SELECT u1.full_name as requested_by, uar.full_name as new_user, uar.requested_role, uar.status, u2.full_name as approved_by, uar.approved_at FROM user_approval_requests uar LEFT JOIN users u1 ON uar.requested_by = u1.id LEFT JOIN users u2 ON uar.approved_by = u2.id;
```

### Check master key audit:
```sql
SELECT u.full_name, ska.action, ska.success, ska.created_at FROM security_key_logs ska JOIN users u ON ska.user_id = u.id ORDER BY ska.created_at DESC;
```

---

## ⚡ Quick Troubleshooting

| Problem | Solution |
|---------|----------|
| "Invalid master key" error | Verify key was entered exactly. Check DB: `SELECT master_key_hash FROM users WHERE id=X;` should NOT be NULL |
| Approval button not showing | Verify `is_security_admin=1` in DB for your admin account |
| IT Dashboard not accessible | Verify user role is 'it_staff' in DB. Clear browser cache. Try direct URL `/it_dashboard.php` |
| Request not created | Check `user_approval_requests` table in DB. Verify role is 'it_staff' or 'admin' |
| New user not appearing after approval | Check `users` table. Verify status='active'. Check `user_approval_requests` status='approved' |

---

## 🚀 Next Steps

1. ✅ Run database updates
2. ✅ Set Security Admin
3. ✅ Generate master key
4. ✅ Test the workflow (see Test Scenario above)
5. ✅ Train admin team on approval process
6. ✅ Backup master key securely
7. ✅ Review audit logs in Security Control

---

**Created**: May 25, 2026  
**System**: KBMC Asset Management v2.0 with Master Key Security

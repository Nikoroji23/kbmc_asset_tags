# Master IT Approver Setup Guide
## alfonsoaninias0527@gmail.com

This guide explains how to set up a master IT account that approves new admin and IT staff account creation requests with security controls.

---

## Overview of the Approval System

Your system has a **two-tier approval workflow** already built in:

1. **Request Stage**: When an admin creates an IT staff or admin user, it creates a pending approval request (not a direct account)
2. **Approval Stage**: Security IT approvers review and approve/reject requests
3. **Master Key Verification**: Security approvers must verify their master key before approving users

---

## Step-by-Step Setup

### Step 1: Create IT Staff Account (if not exists)

**Access**: `http://localhost/kbmc_new_asset/users.php`

1. Login as an admin
2. Click **"Add User"** button
3. Fill in the form:
   - **Full Name**: Alfonso Aninias (or preferred name)
   - **Email**: `alfonsoaninias0527@gmail.com`
   - **Employee ID**: `IT-001` (or appropriate ID)
   - **Password**: Set a strong password
   - **Role**: Select **IT Staff**
   - **Department**: IT Department
   - **Position**: IT Approver / Security Administrator
   - **Phone**: Optional

4. Click **"Add User"**
5. Account is created directly (employee accounts are immediate)

---

### Step 2: Set Master Security Key

This is the critical step that enables approval privileges.

**Access**: `http://localhost/kbmc_new_asset/set_master_key.php`

**IMPORTANT**: This helper file should be deleted after use for security reasons.

#### Generate a Secure Master Key

Option A: **On Windows (XAMPP)**
```powershell
C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(16)).PHP_EOL;"
```

Option B: **On Linux/Mac**
```bash
php -r "echo bin2hex(random_bytes(16)).PHP_EOL;"
```

Option C: **Copy this 32-character example** (for testing only):
```
a7f3e2c1b9d4f8a5e6c3d2b1f9e4a7c6
```

#### Apply the Master Key

1. Go to `set_master_key.php`
2. Select **Alfonso Aninias** from the IT Staff dropdown
3. Paste the generated 32-character key into the **"Master Key (32 characters)"** field
4. Click **"Set Master Key & Grant Security IT"**
5. **Expected result**: Success message + account now has Security IT approval privileges
6. **Delete** `set_master_key.php` after this step:
   ```bash
   rm set_master_key.php
   ```

---

### Step 3: Verify Security IT Approver Status

**Access**: `http://localhost/kbmc_new_asset/assign_security_it.php`

1. Login as admin
2. Look for **Alfonso Aninias** in the IT Staff list
3. **Status column** should show **"Security IT"** (green badge)
4. If not, click the **"Grant Approval"** button

---

## How the Approval Workflow Works

### User Creation Request Flow

#### When an Admin Creates New IT/Admin User:

1. Admin goes to **Manage Users** (`users.php`)
2. Admin selects **IT Staff** or **Admin** role
3. Admin fills in details and clicks **"Add User"**
4. Instead of creating the user immediately:
   - A **pending approval request** is created
   - All Security IT approvers get **notifications**
   - Admin sees: *"User creation request submitted for approval"*

#### When Your Master IT Reviews Requests:

1. Login as **alfonsoaninias0527@gmail.com**
2. Go to **Security Control Center** (`security_control.php`)
3. You'll see a **Master Key Status** section:
   - If NOT VERIFIED: Click **"Verify Master Key"** button
   - Enter your 32-character master key
   - Once verified, session is active for 30 minutes
4. Under **"Pending IT/Admin User Approvals"**:
   - View all pending requests
   - See requester, requested role, email, and reason
5. For each request:
   - **To Approve**: Click **"Approve"** button
     - Requires verified master key
     - Creates the user account in database
     - Sends notification to requester
   - **To Reject**: Click **"Reject"** button
     - Provide rejection reason
     - Request marked as rejected
     - Sends notification to requester

---

## Security Features

### 1. Master Key Protection
- **32-character secure key** hashed with BCrypt
- Key is verified per-session (30-minute timeout)
- After timeout, re-verification required
- Prevents unauthorized approvals even if account is compromised

### 2. Audit Trail
All approval activities logged:
- **Approval date/time**
- **Who approved**
- **Requester identity**
- **New user details**
- **Master key verification timestamp**

**View logs**: `audit_logs.php` → Filter by "Approve User Creation"

### 3. Notifications
- Admins notified when requesting new IT/Admin users
- Security IT approvers notified of pending requests
- Users notified when approval completes

---

## Admin Dashboard Integration

### Pending Approvals Counter
- **Admin Dashboard** (`admin_dashboard.php`) shows:
  - **"Pending Approvals"** card with count
  - Click to navigate to Security Control Center

### User Management
- **Manage Users** (`users.php`):
  - Shows all users with roles
  - Displays created user status
  - Direct link to Manage Users in Quick Actions

---

## Key Differences: Employee vs IT/Admin Creation

| Aspect | Employee | IT Staff / Admin |
|--------|----------|-----------------|
| **Created by** | Admin or self-signup | Admin only |
| **Process** | Direct account creation | Approval workflow |
| **Approver** | None | Security IT with master key |
| **Master Key** | Not required | Required for approval |
| **Audit** | Logged as created | Logged as approved |

---

## Important Emails & Credentials

For your Master IT Approver account:

```
Email:        alfonsoaninias0527@gmail.com
Role:         IT Staff (with Security IT approval privileges)
Master Key:   [Your 32-character key - KEEP SECURE]
Position:     Security Administrator / IT Approver
Access:       Security Control Center (security_control.php)
```

---

## Troubleshooting

### Issue: "You do not have Security IT approval privileges"
**Solution**: 
- Verify master key was set via `set_master_key.php`
- Check in `assign_security_it.php` that Status shows "Security IT"
- Re-run `set_master_key.php` if needed

### Issue: Master Key verification fails
**Solution**:
- Confirm you're using the exact 32-character key (case-sensitive)
- Keys are BCrypt hashed - cannot be recovered if lost
- If lost, regenerate via `set_master_key.php`

### Issue: Pending approval count not updating
**Solution**:
- Refresh page
- Check `user_approval_requests` table for status='pending'
- Verify user creating request has admin role

### Issue: New user not created after approval
**Solution**:
- Check master key was verified before approval
- Verify user email doesn't already exist
- Check `users` table for new user record
- Review `audit_logs.php` for approval transaction

---

## Security Best Practices

### For Your Master Key:
1. ✅ **Store securely** - Use password manager
2. ✅ **Do not share** - Never send via email/chat
3. ✅ **Change periodically** - Regenerate every 90 days
4. ✅ **Log out after approving** - Session expires in 30 minutes anyway
5. ❌ Don't leave set_master_key.php accessible
6. ❌ Don't share approver password
7. ❌ Don't use for personal account access

### System-Level:
- All approvals require verified master key
- 30-minute verification timeout
- All actions logged with timestamp and IP address
- Rejections require reason (auditable)
- Email notifications sent to admins and approvers

---

## Quick Reference

### Key Files
- **set_master_key.php** - Set master key (DELETE after use)
- **security_control.php** - Approval management center
- **assign_security_it.php** - Grant/revoke approver privileges
- **users.php** - Manage all users
- **admin_dashboard.php** - View pending approvals count
- **audit_logs.php** - View all approval history

### Key Database Tables
- `users` - User accounts (has is_security_admin column)
- `user_approval_requests` - Pending/approved/rejected requests
- `audit_logs` - All approval actions
- `security_key_logs` - Master key verification attempts

### Key Functions (in includes/functions.php)
- `isSecurityAdmin($userId)` - Check if user is approver
- `setSecurityITApprover($userId, $enabled)` - Grant/revoke approver
- `createUserApprovalRequest()` - Admin creates request
- `approveUserCreation()` - Approver approves
- `rejectUserCreation()` - Approver rejects
- `verifyMasterKey()` - Master key verification

---

## Support & Maintenance

### Monthly Tasks
- Review `audit_logs.php` for any unusual approval patterns
- Verify all Security IT approvers still have is_security_admin=1
- Check `user_approval_requests` for any stuck/pending requests

### Quarterly Tasks
- Regenerate master key via `set_master_key.php`
- Review and revoke approver access for departed staff
- Audit all created users for validity

### Yearly Tasks
- Security review of all approvals
- Update passwords for all IT staff
- Verify compliance with approval policies

---

## What's Next?

After setup, your IT approver workflow is ready:

1. ✅ Admins can request IT/Admin user creation
2. ✅ Requests appear in Security Control Center
3. ✅ You verify master key and approve/reject
4. ✅ Users are created after approval
5. ✅ All actions are audited

**System is now secured with master key approval control!**

---

*Last updated: June 2, 2026*
*For questions, check audit_logs.php or security_control.php*

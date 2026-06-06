# Master IT Approver - System Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    MASTER IT APPROVER SYSTEM FLOW                           │
└─────────────────────────────────────────────────────────────────────────────┘

ACCOUNT SETUP PHASE
═══════════════════════════════════════════════════════════════════════════════

Step 1: Create IT Staff Account
┌────────────────────────────────┐
│ Admin: Create New User         │
│ Email: alfonsoaninias@...      │
│ Role: IT Staff                 │
│ Status: CREATED                │
└────────┬───────────────────────┘
         │
         ↓
         ✓ Account ready in 'users' table
         ✓ Role = 'it_staff'
         ✓ is_security_admin = 0 (not yet approver)


Step 2: Set Master Key
┌────────────────────────────────┐
│ Admin: Run set_master_key.php  │
│ • Select: Alfonso Aninias      │
│ • Enter: 32-char master key    │
│ • Submit: Hashes & saves       │
└────────┬───────────────────────┘
         │
         ↓
         ✓ master_key_hash stored (hashed with BCrypt)
         ✓ is_security_admin = 1 (now approver!)
         ✓ Delete set_master_key.php


Step 3: Verify Status
┌────────────────────────────────┐
│ Admin: assign_security_it.php  │
│ Confirm: "Security IT" badge   │
│ Status: ACTIVE                 │
└────────┬───────────────────────┘
         │
         ↓
         ✓ Ready for approvals!


APPROVAL WORKFLOW PHASE
═══════════════════════════════════════════════════════════════════════════════

User Creation Request Flow:

┌─────────────────────────────────────────────────────────────────────┐
│ STEP 1: ADMIN REQUESTS NEW USER                                    │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Admin Dashboard → Manage Users → Add User                         │
│  ├─ Full Name: [New IT Staff Name]                                │
│  ├─ Email: [Email]                                                │
│  ├─ Role: IT Staff OR Admin  ← TRIGGERS APPROVAL WORKFLOW         │
│  ├─ Department: [Department]                                      │
│  └─ Password: [Password]                                          │
│                                                                     │
│  Admin clicks "Add User"                                           │
│         ↓                                                           │
│  SYSTEM ACTION:                                                     │
│  • Creates pending request in user_approval_requests table         │
│  • Sets status = 'pending'                                        │
│  • Stores password_hash                                           │
│  • Logs: "Submit User Approval Request"                           │
│  • Sends notification to all Security IT approvers                │
│                                                                     │
│  ADMIN SEES:                                                       │
│  ✓ "User creation request submitted for approval"                │
│  ✓ Request is pending                                             │
│  ✓ Cannot create user directly                                    │
│                                                                     │
│  DATABASE:                                                         │
│  INSERT INTO user_approval_requests (                             │
│    requested_by, full_name, email, requested_role,               │
│    department, position, password_hash, reason,                  │
│    status                                                         │
│  ) VALUES (...)                                                    │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘


┌─────────────────────────────────────────────────────────────────────┐
│ STEP 2: NOTIFICATION SENT TO APPROVERS                              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  All IT staff with is_security_admin = 1 receive:                │
│  • In-app notification                                            │
│  • Email notification (if configured)                             │
│                                                                     │
│  Message: "New IT/Admin User Request"                            │
│  Detail: "[Name] - [Role] - Awaiting approval"                   │
│  Link: Security Control Center                                    │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘


┌─────────────────────────────────────────────────────────────────────┐
│ STEP 3: SECURITY IT APPROVER REVIEWS REQUEST                        │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Approver (Alfonso) Login                                         │
│  ├─ Email: alfonsoaninias0527@gmail.com                          │
│  └─ Password: [Password]                                          │
│                                                                     │
│  Navigate: security_control.php                                   │
│         ↓                                                           │
│  Master Key Status Check:                                         │
│  ├─ If NOT VERIFIED:                                             │
│  │  ├─ Click "Verify Master Key" button                          │
│  │  ├─ Enter 32-character master key                             │
│  │  └─ System verifies using password_verify()                   │
│  │     ├─ SUCCESS: "VERIFIED ✓" (30-min session)               │
│  │     └─ FAILURE: "Invalid master key"                         │
│  │                                                                │
│  └─ If ALREADY VERIFIED:                                         │
│     └─ Session remains active (< 30 min elapsed)                 │
│                                                                     │
│  View Pending Approvals:                                          │
│  ├─ Requester: [Admin Name]                                      │
│  ├─ New User: [Full Name]                                        │
│  ├─ Email: [Email]                                               │
│  ├─ Requested Role: [IT Staff / Admin]                          │
│  ├─ Department: [Department]                                     │
│  └─ Reason: [Reason provided]                                    │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘


┌─────────────────────────────────────────────────────────────────────┐
│ STEP 4A: APPROVER APPROVES REQUEST                                  │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Approver clicks "Approve" button                                 │
│         ↓                                                           │
│  REQUIREMENT CHECK:                                                │
│  ✓ Master key must be verified                                   │
│  ✓ User email must not already exist                             │
│                                                                     │
│  SYSTEM ACTION:                                                    │
│  1. CREATE USER:                                                  │
│     INSERT INTO users (                                           │
│       employee_id, full_name, email, password,                   │
│       role, department, position, phone,                         │
│       status                                                      │
│     ) VALUES (...user_approval_requests data...)                 │
│     ↓ Returns new user ID                                         │
│                                                                     │
│  2. UPDATE APPROVAL REQUEST:                                      │
│     UPDATE user_approval_requests                                │
│     SET status = 'approved',                                     │
│         approved_by = [Alfonso ID],                              │
│         approved_at = NOW()                                      │
│     WHERE id = [request ID]                                      │
│                                                                     │
│  3. LOG AUDIT:                                                    │
│     INSERT INTO audit_logs (                                     │
│       user_id = Alfonso ID,                                      │
│       action = 'Approve User Creation',                          │
│       table_name = 'user_approval_requests',                     │
│       details = 'New [role] user created: [name]'                │
│     )                                                             │
│                                                                     │
│  4. SEND NOTIFICATIONS:                                          │
│     • To: Admin who requested (requester)                        │
│     • Message: "Your user request was approved!"                 │
│     • To: New user (if email configured)                        │
│     • Message: "Your account has been created"                  │
│                                                                     │
│  APPROVER SEES:                                                    │
│  ✓ "User approved and created successfully"                     │
│  ✓ User now appears in Users list                                │
│  ✓ Request disappears from pending                               │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘


┌─────────────────────────────────────────────────────────────────────┐
│ STEP 4B: APPROVER REJECTS REQUEST                                   │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Approver clicks "Reject" button                                  │
│         ↓                                                           │
│  SYSTEM PROMPTS:                                                   │
│  • Reason for rejection (text field)                             │
│                                                                     │
│  SYSTEM ACTION:                                                    │
│  1. UPDATE APPROVAL REQUEST:                                      │
│     UPDATE user_approval_requests                                │
│     SET status = 'rejected',                                     │
│         approved_by = [Alfonso ID],                              │
│         approved_at = NOW(),                                     │
│         rejection_reason = '[Reason]'                            │
│     WHERE id = [request ID]                                      │
│                                                                     │
│  2. LOG AUDIT:                                                    │
│     INSERT INTO audit_logs (                                     │
│       user_id = Alfonso ID,                                      │
│       action = 'Reject User Creation',                           │
│       table_name = 'user_approval_requests'                      │
│     )                                                             │
│                                                                     │
│  3. SEND NOTIFICATION:                                           │
│     • To: Admin who requested                                    │
│     • Message: "Your user request was rejected"                 │
│     • Reason: [Rejection reason provided]                       │
│                                                                     │
│  APPROVER SEES:                                                    │
│  ✓ "User creation request rejected"                             │
│  ✓ Request moves to rejected status                              │
│  ✓ Can view rejection history                                    │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘


SECURITY FEATURES
═══════════════════════════════════════════════════════════════════════════════

Master Key Protection:
├─ 32-character hex key (generated via random_bytes(16))
├─ Hashed with BCrypt (password_hash & password_verify)
├─ Per-session verification (30-minute timeout)
├─ Cannot approve without verification
├─ All attempts logged in security_key_logs

Audit Trail:
├─ request_by: Which admin requested
├─ approved_by: Which approver approved/rejected
├─ approved_at: When approval happened
├─ rejection_reason: Why rejected (if applicable)
├─ Master key verification timestamp
└─ All in audit_logs for compliance

Access Control:
├─ Only IT staff with is_security_admin = 1 can approve
├─ Admin-only creation requires Security IT approval
├─ Employee creation is direct (no approval needed)
├─ Notifications only to authorized approvers
└─ Session-based access verification

Notifications:
├─ Admins notified when their request is approved/rejected
├─ Approvers notified of new pending requests
├─ Email notifications (if email configured)
├─ In-app notification system always active
└─ Full audit trail of all notifications


DATABASE SCHEMA
═══════════════════════════════════════════════════════════════════════════════

users table (modified):
├─ id (INT, PRIMARY KEY)
├─ email (VARCHAR, UNIQUE)
├─ full_name (VARCHAR)
├─ password (VARCHAR, BCRYPT hashed)
├─ role (ENUM: admin, it_staff, employee)
├─ department (VARCHAR)
├─ position (VARCHAR)
├─ status (ENUM: active, inactive)
├─ is_security_admin (BOOLEAN) ← APPROVER FLAG
├─ master_key_hash (VARCHAR, BCrypt hashed) ← SECURITY KEY
├─ created_at (TIMESTAMP)
└─ updated_at (TIMESTAMP)

user_approval_requests table (NEW):
├─ id (INT, PRIMARY KEY)
├─ requested_by (INT, FK → users.id) ← ADMIN WHO REQUESTED
├─ full_name (VARCHAR)
├─ email (VARCHAR, UNIQUE)
├─ requested_role (ENUM: it_staff, admin)
├─ employee_id (VARCHAR)
├─ department (VARCHAR)
├─ position (VARCHAR)
├─ password_hash (VARCHAR, BCRYPT) ← PASSWORD FOR NEW USER
├─ reason (TEXT) ← REQUEST REASON
├─ status (ENUM: pending, approved, rejected)
├─ approved_by (INT, FK → users.id) ← APPROVER
├─ approved_at (TIMESTAMP)
├─ rejection_reason (TEXT)
├─ created_at (TIMESTAMP)
└─ updated_at (TIMESTAMP)

security_key_logs table (NEW):
├─ id (INT, PRIMARY KEY)
├─ user_id (INT, FK → users.id) ← APPROVER
├─ action (VARCHAR) ← "Key Verified" / "Key Verification Failed"
├─ success (BOOLEAN)
├─ attempt_ip (VARCHAR)
└─ created_at (TIMESTAMP)

master_key_audit table (NEW):
├─ id (INT, PRIMARY KEY)
├─ user_id (INT, FK → users.id)
├─ action (VARCHAR) ← ACTION PERFORMED
├─ details (TEXT)
├─ ip_address (VARCHAR)
├─ user_agent (VARCHAR)
└─ created_at (TIMESTAMP)

audit_logs table (modified):
├─ id (INT, PRIMARY KEY)
├─ user_id (INT, FK → users.id) ← WHO DID IT
├─ action (VARCHAR) ← "Approve User Creation", "Reject User Creation"
├─ table_name (VARCHAR)
├─ record_id (INT) ← REQUEST ID
├─ old_value (TEXT)
├─ new_value (TEXT)
├─ created_at (TIMESTAMP)
└─ ip_address (VARCHAR)


KEY FILES
═══════════════════════════════════════════════════════════════════════════════

/set_master_key.php
├─ Purpose: Set master key for Security IT approver
├─ Access: Admin only
├─ Action: Sets is_security_admin=1 + master_key_hash
├─ Security: DELETE AFTER USE
└─ Location: Root directory

/security_control.php
├─ Purpose: Manage approval requests
├─ Access: IT staff with is_security_admin=1
├─ Features: Verify key, view pending, approve, reject
├─ Notifications: Shows approval count
└─ Audit: All actions logged

/assign_security_it.php
├─ Purpose: Grant/revoke approver privileges
├─ Access: Security admin only
├─ Action: Toggle is_security_admin flag
├─ Users: Shows IT staff list with status
└─ Notifications: Shows who has approval privileges

/users.php
├─ Purpose: Manage all users
├─ Access: Admin
├─ Features: Create, edit, delete users
├─ Workflow: IT/Admin creation triggers approval
└─ Display: Shows user status and roles

/admin_dashboard.php
├─ Purpose: Admin overview
├─ Display: Pending approvals count
├─ Link: Quick access to Security Control
├─ Stats: User counts by role
└─ Audit: Recent admin actions


WORKFLOW SUMMARY
═══════════════════════════════════════════════════════════════════════════════

👤 Admin Account: alfonsoaninias0527@gmail.com
├─ Role: IT Staff
├─ Privileges: is_security_admin = 1
├─ Master Key: 32-character secured key (BCrypt hashed)
└─ Function: Approve new IT/Admin user creation requests

📋 Approval Process:
├─ Admin requests new user (IT/Admin role)
├─ Request stored in user_approval_requests table
├─ Security IT approver notified
├─ Approver verifies master key
├─ Approver approves/rejects request
├─ User created or request rejected
└─ All parties notified of outcome

🔐 Security Controls:
├─ Master key per-session verification (30 min)
├─ All approvals require master key verification
├─ Complete audit trail of all approvals
├─ IP logging for security monitoring
├─ Rejection reason tracking
└─ Email notifications to stakeholders

✅ System Status:
├─ Approval workflow: ACTIVE
├─ Master key system: ACTIVE
├─ Audit logging: ACTIVE
├─ Email notifications: CONFIGURABLE
└─ All security features: ENABLED

```

---

## File Organization

```
MASTER IT APPROVER FILES
├─ MASTER_IT_APPROVER_SETUP.md (This guide - detailed setup)
├─ MASTER_IT_APPROVER_CHECKLIST.html (Interactive checklist)
├─ MASTER_IT_APPROVER_ARCHITECTURE.md (This file - system flow)
│
ACTIVE WORKFLOW FILES
├─ set_master_key.php ← DELETE AFTER USE
├─ security_control.php ← Main approval interface
├─ assign_security_it.php ← Grant/revoke privileges
├─ users.php ← User management
├─ admin_dashboard.php ← Overview with pending count
│
DATABASE FILES
├─ databases/database_security_updates.sql (Schema)
├─ databases/kbmcdatabase.sql (Main schema)
│
SUPPORT FILES
├─ includes/functions.php (isSecurityAdmin, setSecurityITApprover, etc.)
├─ includes/config.php (Database config)
├─ includes/header.php (Navigation & layout)
└─ includes/footer.php (Footer)
```

---

**Setup Complete!** When ready, Alfonso Aninias (alfonsoaninias0527@gmail.com) will be your master IT approver with full security controls.

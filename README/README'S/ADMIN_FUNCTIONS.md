# ADMIN FUNCTIONS & CAPABILITIES

## Core Admin Permissions
- **Role**: `admin`
- **Access Control Function**: `requireAdmin()` / `hasRole('admin')`
- **Primary Dashboard**: `admin_dashboard.php`

---

## 1. NOTIFICATION MANAGEMENT

### View Notifications
- **Files**: `notification_admin.php`, `notifications.php`
- **Features**:
  - Filter by: All, Unread, Previous (read)
  - View URL links for each notification
  - Mark individual notifications as read
  - Mark all notifications as read at once
  
### Notification Types (Admin Relevant)
- Account recovery requests
- Account recovery approved/rejected
- User approval requested
- User creation approved/rejected
- Audit reminders
- Device critical alerts
- Maintenance overdue alerts
- Failed login alerts
- System alerts
- Security warnings
- Device issue alerts
- Custom alerts
- New user account created

### Send Notifications (API Endpoints)
- `api_send_admin_recovery_notification.php` - Account recovery alerts
- `api_send_admin_user_approval_notification.php` - User approval alerts
- `api_send_admin_new_user_notification.php` - New employee alerts
- `api_send_admin_critical_alert.php` - Critical system alerts

---

## 2. USER MANAGEMENT

### Access File: `users.php` / `admin_accounts.php`
- **Requires**: `requireAdmin()` or `hasRole('admin')`

#### Functions:
- ✅ Create new user accounts
- ✅ Edit user details (name, email, role, department)
- ✅ Change user role (admin, it_staff, employee)
- ✅ Deactivate/delete user accounts
- ✅ View account creation records
- ✅ Search & filter users by:
  - Name
  - Email
  - Employee ID
  - Department
  - Role
  - Status (active/inactive)
- ✅ Export user lists
- ✅ Approve pending user accounts

### User Approval Workflow
- View pending approval requests
- Approve new user accounts
- Reject new user accounts
- Track approval history

---

## 3. DEVICE MANAGEMENT

### Access Files: `devices.php`, `view_device.php`, `edit_device.php`, `add_device.php`

#### Functions:
- ✅ Add new devices to inventory
- ✅ Edit device information:
  - Asset tag
  - Device name
  - Type (Laptop, Desktop, etc.)
  - Status (in_stock, deployed, retired)
  - Serial number
  - Model
  - Manufacturer
  - Purchase date
  - Warranty info
  - Location
- ✅ View complete device details
- ✅ Mark device as deployed/returned
- ✅ Retire devices
- ✅ Delete devices
- ✅ Search & filter devices
- ✅ View device assignments

---

## 4. RECOVERY & SECURITY MANAGEMENT

### Access File: `recovery_requests.php`
- **Requires**: `requireAdmin()`

#### Functions:
- ✅ View all pending account recovery requests
- ✅ Approve recovery requests
- ✅ Reject recovery requests
- ✅ View recovery request history
- ✅ Filter by status (pending, approved, rejected)
- ✅ View user details in recovery requests

### Security Functions
- `isSecurityAdmin($userId)` - Check if user is security admin
- `processRecoveryRequest($recoveryId, $action, $adminId)` - Process recovery requests
- Manage master keys for users

---

## 5. REQUEST MANAGEMENT

### Access Files: `requests.php`, `device_requests.php`
- Can be accessed by admin or IT staff

#### Functions:
- ✅ View all device requests (pending, approved, rejected)
- ✅ Approve pending device requests
- ✅ Reject pending device requests
- ✅ View request history
- ✅ Assign devices to approved requests
- ✅ Track request status

---

## 6. MAINTENANCE & REPAIRS

### Access Files: `maintenance_repairs.php`
- Shared with IT staff

#### Functions:
- ✅ View repair requests
- ✅ Mark repairs as completed
- ✅ Assign maintenance tasks
- ✅ Track maintenance history
- ✅ View maintenance schedules
- ✅ Send maintenance reminders

---

## 7. AUDIT & LOGGING

### Access Files: `admin_dashboard.php`

#### Functions:
- ✅ View recent audit logs
- ✅ Track user actions:
  - Login/Logout
  - Account creation/deletion
  - Device additions
  - Device status changes
  - User role changes
  - Approval actions
- ✅ View activity history
- ✅ Generate audit reports

---

## 8. ADMIN DASHBOARD

### Access File: `admin_dashboard.php`
- **Requires**: `requireAdmin()`

#### Dashboard Features:
- 📊 Total device count
- 👥 Total active users count
- 👨‍💼 IT staff count
- 🔑 Admin count
- 👤 Employee count
- ⏳ Pending user approvals count
- 🔄 Pending recovery requests
- 📋 Recent audit logs (last 10)
- 🔔 Latest notifications (last 50)
- 🔐 Master key regeneration
- Security admin status check

### Statistics & Reports
- Device inventory status
- User account overview
- System activity summary
- Alert summaries

---

## 9. DEVICE LIFECYCLE MANAGEMENT

### Access Files: `device_lifespan.php`, `deployments.php`, `retired.php`

#### Functions:
- ✅ Track device lifespan (acquisition → retirement)
- ✅ Monitor device deployment status
- ✅ View device returns/disposals
- ✅ Manage device disposal tracking
- ✅ Track device maintenance history
- ✅ Monitor warranty expiration

---

## 10. IMPORT & EXPORT

### Access Files: `import_assets.php`

#### Functions:
- ✅ Bulk import devices from CSV
- ✅ Validate import data
- ✅ Create devices from import
- ✅ Track import history
- ✅ Handle import errors

---

## 11. REPORTING

### Access Files: `reports.php`

#### Functions:
- ✅ Generate device reports
- ✅ Generate user reports
- ✅ Generate activity reports
- ✅ Filter by date range
- ✅ Filter by department
- ✅ Export reports

---

## 12. SECURITY & ACCESS CONTROL

### Security Admin Features (`isSecurityAdmin()`)
- Master key management
- User account security
- Recovery request approval
- Audit log review
- IT clearance management

### Key Security Functions:
```php
requireAdmin()              // Enforce admin-only access
hasRole('admin')            // Check admin role
isSecurityAdmin($userId)    // Check security admin status
processRecoveryRequest()    // Handle account recovery
logAudit()                  // Log admin actions
```

---

## 13. NOTIFICATION SETTINGS

### Admin-Only Notifications
- Account recovery requests
- New user approvals
- System alerts
- Device critical alerts
- Failed login attempts
- Security warnings
- Audit reminders

---

## 14. DATABASE MANAGEMENT

### Admin Functions:
- ✅ Schema updates (columns, tables)
- ✅ User table management
- ✅ Notification management
- ✅ Audit log management
- ✅ Device tracking

---

## ACCESS CONTROL HIERARCHY

```
Public Access:
├── Login
├── Signup
├── Password Recovery
└── Account Recovery Request

Employee Role:
├── View My Devices
├── My Notifications
├── My Profile
└── Device Requests

IT Staff Role (Inherits Admin View):
├── All of Employee Access
├── View All Devices
├── Manage Repairs/Maintenance
├── Approve Device Requests
└── View System Notifications

Admin Role (Full Access):
├── All of Employee Access
├── All of IT Staff Access
├── User Management (Create/Edit/Delete)
├── Approve User Accounts
├── Handle Account Recovery
├── Device Management
├── Audit Logs
├── System Configuration
├── Master Key Management
└── Emergency Alerts
```

---

## KEY ADMIN FUNCTIONS (Code)

```php
// Authentication
requireAdmin()              // Must be admin or redirect
hasRole('admin')            // Check if admin

// User Management
getPendingUserApprovals()   // Get pending user approvals
processRecoveryRequest()    // Handle recovery requests
logAudit()                  // Log administrative actions

// Notifications
addNotification()           // Send notification to user
isAdminRelevantNotification() // Check notification type

// Devices
getTotalDeviceCount()       // Get total devices in system
getDeviceById()             // Get specific device info

// Security
isSecurityAdmin()           // Check security admin status
ensureUserSecuritySchema()  // Ensure security columns exist
```

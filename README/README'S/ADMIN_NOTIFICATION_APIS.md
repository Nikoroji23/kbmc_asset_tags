# Admin Notification API Endpoints - Complete Documentation

**Created:** June 2, 2026  
**Status:** ✅ Complete

## Overview

Four new API endpoints have been created to provide comprehensive admin notification functionality for various administrative workflows in the KBMC Asset Management System.

---

## 1. Account Recovery Request Notifications

### Endpoint: `api_send_admin_recovery_notification.php`

**Purpose:** Alert admins about pending account recovery requests requiring approval

**Method:** `POST`

**Authorization:**
- Requires login (`isLoggedIn()`)
- Requires admin role (`hasRole('admin')`)

**Request Payload:**
```json
{
  "recovery_id": 123
}
```

**Response (Success):**
```json
{
  "success": true,
  "message": "Notification sent to 3 admin(s)",
  "sent_count": 3,
  "admins_notified": 3
}
```

**Response (Error):**
```json
{
  "success": false,
  "message": "Recovery request not found or already processed"
}
```

**Features:**
- ✅ Sends email to all active admins
- ✅ Includes employee details (name, ID, email, department)
- ✅ Shows recovery reason and request timestamp
- ✅ Provides direct link to recovery_requests.php
- ✅ Creates system in-app notifications
- ✅ Logs all notification sends in audit trail
- ✅ Only works for pending requests
- ✅ Deduplicates emails from admins with same address

**Email Content:**
- Subject: `[KBMC Alert] Account Recovery Request - Employee Name (ID)`
- Background color: Yellow warning banner
- Includes: Employee info, request details, action required message
- Button: "Review Recovery Requests"

**Sample Usage:**
```javascript
fetch('api_send_admin_recovery_notification.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ recovery_id: 123 })
})
.then(r => r.json())
.then(data => console.log(data));
```

---

## 2. User Account Approval Notifications

### Endpoint: `api_send_admin_user_approval_notification.php`

**Purpose:** Alert admins about pending user account approval requests (for IT staff or admin accounts)

**Method:** `POST`

**Authorization:**
- Requires login (`isLoggedIn()`)
- Requires admin role (`hasRole('admin')`)

**Request Payload:**
```json
{
  "approval_id": 456
}
```

**Response (Success):**
```json
{
  "success": true,
  "message": "Notification sent to 3 admin(s)",
  "sent_count": 3,
  "admins_notified": 3
}
```

**Response (Error):**
```json
{
  "success": false,
  "message": "Approval request not found or already processed"
}
```

**Features:**
- ✅ Sends email to all active admins
- ✅ Shows user role being requested (IT Staff, Admin, etc.)
- ✅ Includes complete user details (name, ID, email, department, position)
- ✅ Shows who requested the account
- ✅ Includes request timestamp
- ✅ Notes that approval requires master security key
- ✅ Provides direct link to users.php?tab=approvals
- ✅ Creates system in-app notifications
- ✅ Logs notification sends in audit trail
- ✅ Only works for pending approval requests

**Email Content:**
- Subject: `[KBMC Alert] New User Approval Required - Name (Role)`
- Background color: Blue information banner
- Includes: User details, role badge, request info, security notice
- Button: "Review Pending Approvals"

**Sample Usage:**
```javascript
fetch('api_send_admin_user_approval_notification.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ approval_id: 456 })
})
.then(r => r.json())
.then(data => console.log(data));
```

---

## 3. New Employee Account Notifications

### Endpoint: `api_send_admin_new_user_notification.php`

**Purpose:** Manually trigger notifications to admins/IT staff about new employee account registrations

**Method:** `POST`

**Authorization:**
- Requires login (`isLoggedIn()`)
- Requires admin role (`hasRole('admin')`)

**Request Payload:**
```json
{
  "creation_id": 789
}
```

**Response (Success):**
```json
{
  "success": true,
  "message": "Notification sent to 5 admin/IT staff member(s)",
  "sent_count": 5,
  "recipients_notified": 5
}
```

**Response (Error):**
```json
{
  "success": false,
  "message": "Account creation record not found"
}
```

**Features:**
- ✅ Sends email to all active admins and IT staff
- ✅ Shows complete employee details
- ✅ Indicates account creation method (self-registration vs. admin-created)
- ✅ Shows current account status (active or pending)
- ✅ Includes registration timestamp
- ✅ Provides direct link to admin_accounts.php
- ✅ Creates system in-app notifications
- ✅ Logs all notification sends
- ✅ Deduplicates emails from staff with same address
- ✅ Can be manually triggered for resending notifications

**Email Content:**
- Subject: `[KBMC Alert] New Employee Account Registration - Name (ID)`
- Background color: Green success banner
- Includes: Full employee details, registration info, account status
- Button: "View Account Records"

**Sample Usage:**
```javascript
fetch('api_send_admin_new_user_notification.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ creation_id: 789 })
})
.then(r => r.json())
.then(data => console.log(data));
```

---

## 4. Critical System Alerts

### Endpoint: `api_send_admin_critical_alert.php`

**Purpose:** Send critical system alerts to all admins with flexible alert types and priority levels

**Method:** `POST`

**Authorization:**
- Requires login (`isLoggedIn()`)
- Requires admin or IT staff role (`hasRole('admin') || hasRole('it_staff')`)

**Request Payload:**
```json
{
  "alert_type": "device_critical|maintenance_overdue|failed_logins|system_alert|security_warning|device_issue|custom",
  "title": "Alert Title",
  "message": "Detailed alert message with context",
  "priority": "low|normal|high|critical",
  "related_device_id": 123,
  "action_url": "https://custom-url/"
}
```

**Response (Success):**
```json
{
  "success": true,
  "message": "Alert notification sent to 3 admin(s)",
  "sent_count": 3,
  "admins_notified": 3,
  "alert_type": "device_critical",
  "priority": "critical"
}
```

**Response (Error):**
```json
{
  "success": false,
  "message": "Missing title"
}
```

**Alert Types:**
| Type | Use Case |
|------|----------|
| `device_critical` | Device hardware failure or critical status |
| `maintenance_overdue` | Maintenance severely overdue |
| `failed_logins` | Multiple failed login attempts detected |
| `system_alert` | General system alerts |
| `security_warning` | Security-related issues |
| `device_issue` | Device problems or errors |
| `custom` | Custom alert for other scenarios |

**Priority Levels & Colors:**
| Priority | Color | Usage |
|----------|-------|-------|
| `critical` | 🔴 Red (#d32f2f) | Urgent action required immediately |
| `high` | 🟠 Orange (#f57c00) | Important, needs attention soon |
| `normal` | 🔵 Blue (#0288d1) | Standard alert (default) |
| `low` | 🟢 Green (#388e3c) | Informational, can wait |

**Features:**
- ✅ Flexible alert system for various admin notifications
- ✅ Multiple alert types for different scenarios
- ✅ 4 priority levels with color-coded styling
- ✅ Optional device context with asset tag and status
- ✅ Custom action URL support
- ✅ Professional HTML formatting with priority badges
- ✅ Creates system in-app notifications
- ✅ Logs all critical alerts in audit trail
- ✅ Sends to all active admins
- ✅ Auto-links to device details if device_id provided
- ✅ Email subject includes priority level for filtering

**Email Content Example (Critical):**
- Subject: `[KBMC CRITICAL] Device Critical - Asset Tag SX-001`
- Banner: Red background with alert icon
- Includes: Alert title, detailed message, device info (if applicable)
- Button: "View Details" (links to device or custom URL)

**Sample Usage:**
```javascript
// Example: Device Critical Alert
fetch('api_send_admin_critical_alert.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    alert_type: 'device_critical',
    title: 'Critical Device Status',
    message: 'Device SX-001 is showing critical hardware errors and needs immediate attention.',
    priority: 'critical',
    related_device_id: 123
  })
})
.then(r => r.json())
.then(data => console.log(data));

// Example: Maintenance Overdue Alert
fetch('api_send_admin_critical_alert.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    alert_type: 'maintenance_overdue',
    title: 'Maintenance Severely Overdue',
    message: 'Several devices are 30+ days overdue for required maintenance. Immediate action required.',
    priority: 'high',
    action_url: 'http://localhost/kbmc_new_asset/maintenance_repairs.php'
  })
})
.then(r => r.json())
.then(data => console.log(data));

// Example: Security Warning
fetch('api_send_admin_critical_alert.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    alert_type: 'security_warning',
    title: 'Multiple Failed Login Attempts Detected',
    message: 'Admin account has received 5 failed login attempts in the last 10 minutes from IP: 192.168.1.100',
    priority: 'critical',
    action_url: 'http://localhost/kbmc_new_asset/admin_dashboard.php'
  })
})
.then(r => r.json())
.then(data => console.log(data));
```

---

## Common Response Codes

| Code | Meaning |
|------|---------|
| 200 | Success |
| 400 | Bad Request (missing required fields) |
| 401 | Unauthorized (not logged in or insufficient role) |
| 405 | Method Not Allowed (must use POST) |
| 500 | Server Error |

---

## Email Configuration

All endpoints respect the `isEmailConfigured()` check:
- ✅ If email is configured: Sends emails + creates system notifications
- ✅ If email is NOT configured: Still creates system notifications
- ✅ Graceful degradation ensures system works even without email

---

## Audit Logging

All endpoints log their actions in the `audit_logs` table:
- Action: "Send Admin [Type] Notification"
- Table: Related table (recovery requests, user approvals, etc.)
- Details: Who was notified, what was sent
- Timestamp: Automatically recorded

**Examples:**
```
[Admin User] sent recovery request notification to [Admin Name]
[Admin User] sent user approval notification to [Admin Name] for [New User]
[Admin User] sent new user notification to [Admin Name] for [Employee]
[Admin User] sent critical alert "Device Critical" to [Admin Name] (Priority: critical)
```

---

## Integration Patterns

### From Page Action Buttons
```php
// In recovery_requests.php
?>
<button onclick="sendRecoveryNotification(<?php echo $recovery['id']; ?>)" class="btn btn-sm btn-info">
  <i class="fas fa-bell"></i> Notify Admins
</button>

<script>
function sendRecoveryNotification(recoveryId) {
  fetch('api_send_admin_recovery_notification.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ recovery_id: recoveryId })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      alert(`Notification sent to ${data.sent_count} admin(s)`);
    } else {
      alert('Error: ' + data.message);
    }
  })
  .catch(err => alert('Error: ' + err));
}
</script>
```

### From Scheduled Tasks/Cron
```php
// Pseudo-code for background job
$pendingRecoveries = $pdo->query("SELECT id FROM account_recovery_requests WHERE status = 'pending' AND requested_at < NOW() - INTERVAL 1 HOUR");
foreach ($pendingRecoveries as $recovery) {
    // Trigger notification if older than 1 hour
    $ch = curl_init('http://localhost/kbmc_new_asset/api_send_admin_recovery_notification.php');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['recovery_id' => $recovery['id']]));
    curl_exec($ch);
}
```

---

## Error Handling

All endpoints return consistent error responses:

```json
{
  "success": false,
  "message": "Human-readable error description"
}
```

**Common Errors:**
- `"Unauthorized"` - User not logged in or lacks admin role
- `"Method not allowed"` - Must use POST request
- `"Missing recovery_id"` - Required parameter not provided
- `"Recovery request not found or already processed"` - Invalid ID or already handled
- `"No active admins found to notify"` - No admin users to send to

---

## Security Considerations

✅ **CSRF Protection:** Recommended to add CSRF tokens for page integrations  
✅ **Authentication:** All endpoints verify login status and admin role  
✅ **Authorization:** Each user can only trigger notifications for their own actions  
✅ **Audit Trail:** All sends are logged for security review  
✅ **Input Validation:** All required fields validated before processing  
✅ **Email Verification:** Uses standard email validation filter  

---

## Performance Notes

- ✅ Sends up to N emails (N = number of active admins)
- ✅ Uses deduplication to avoid sending duplicates to same email
- ✅ Asynchronous if integrated with queue system
- ✅ Lightweight JSON responses for quick feedback

---

## Testing Checklist

- [ ] Test with admin user logged in
- [ ] Test with non-admin user (should fail)
- [ ] Test with invalid recovery_id (should return error)
- [ ] Test with pending recovery request (should send)
- [ ] Test with already-processed request (should fail)
- [ ] Verify email received by all active admins
- [ ] Check audit log entry created
- [ ] Verify system notification visible to admins
- [ ] Test alert priorities and colors render correctly
- [ ] Test custom action_url functionality

---

## Related Functions Used

All endpoints utilize these existing system functions:

| Function | Purpose |
|----------|---------|
| `isLoggedIn()` | Check user authentication |
| `hasRole()` | Check user role/permissions |
| `addSystemNotificationOnlyIfNotExists()` | Create in-app notifications |
| `sendEmail()` | Send email via PHPMailer |
| `isEmailConfigured()` | Check email setup status |
| `emailTemplate()` | Format professional HTML emails |
| `filterUniqueEmails()` | Deduplicate email addresses |
| `logAudit()` | Record action in audit trail |

---

Created: June 2, 2026  
System: KBMC Asset Management System

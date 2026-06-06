# Admin Notification API - Quick Integration Guide

## Files Created

1. **api_send_admin_recovery_notification.php** - Account recovery alerts
2. **api_send_admin_user_approval_notification.php** - User approval alerts
3. **api_send_admin_new_user_notification.php** - New employee alerts
4. **api_send_admin_critical_alert.php** - Critical system alerts

---

## Quick Integration

### 1. Add Button to Recovery Requests Page

**File:** `recovery_requests.php`

Add this button next to each pending recovery request:

```html
<button class="btn btn-sm btn-info" onclick="notifyAdminsRecovery(<?php echo $request['id']; ?>)" title="Send email notification to all admins">
  <i class="fas fa-bell"></i> Notify Admins
</button>
```

Add this JavaScript at the bottom:

```javascript
function notifyAdminsRecovery(recoveryId) {
  if (!confirm('Send notification to all active admins?')) return;
  
  fetch('api_send_admin_recovery_notification.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ recovery_id: recoveryId })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      setFlashMessage('success', 'Notification sent to ' + data.sent_count + ' admin(s)');
      location.reload();
    } else {
      setFlashMessage('error', 'Error: ' + data.message);
    }
  })
  .catch(err => setFlashMessage('error', 'Error: ' + err));
}
```

---

### 2. Add Button to User Approvals (users.php)

**File:** `users.php` (Approvals tab)

Add this button next to each pending approval request:

```html
<button class="btn btn-sm btn-info" onclick="notifyAdminsApproval(<?php echo $approval['id']; ?>)" title="Send email notification to all admins">
  <i class="fas fa-bell"></i> Notify Admins
</button>
```

Add this JavaScript:

```javascript
function notifyAdminsApproval(approvalId) {
  if (!confirm('Send notification to all active admins?')) return;
  
  fetch('api_send_admin_user_approval_notification.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ approval_id: approvalId })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      setFlashMessage('success', 'Notification sent to ' + data.sent_count + ' admin(s)');
      location.reload();
    } else {
      setFlashMessage('error', 'Error: ' + data.message);
    }
  })
  .catch(err => setFlashMessage('error', 'Error: ' + err));
}
```

---

### 3. Add Button to Account Creations (admin_accounts.php)

**File:** `admin_accounts.php`

Add this button next to each account creation record:

```html
<button class="btn btn-sm btn-info" onclick="notifyAdminsNewUser(<?php echo $record['id']; ?>)" title="Resend notification to admins/IT staff">
  <i class="fas fa-bell"></i> Resend Notification
</button>
```

Add this JavaScript:

```javascript
function notifyAdminsNewUser(creationId) {
  if (!confirm('Send notification to all active admins and IT staff?')) return;
  
  fetch('api_send_admin_new_user_notification.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ creation_id: creationId })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      setFlashMessage('success', 'Notification sent to ' + data.sent_count + ' recipient(s)');
      location.reload();
    } else {
      setFlashMessage('error', 'Error: ' + data.message);
    }
  })
  .catch(err => setFlashMessage('error', 'Error: ' + err));
}
```

---

### 4. Send Critical Alert from Admin Dashboard

**File:** `admin_dashboard.php`

Add a quick alert form in the admin dashboard:

```html
<div class="card" style="margin-top: 20px;">
  <div class="card-body">
    <h4><i class="fas fa-exclamation-triangle"></i> Send Critical Alert</h4>
    <form id="criticalAlertForm" style="display: flex; gap: 10px; flex-wrap: wrap;">
      <input type="text" id="alertTitle" placeholder="Alert Title" required style="flex: 1; min-width: 200px;">
      <textarea id="alertMessage" placeholder="Alert Message" required style="flex: 1; min-width: 200px; height: 50px;"></textarea>
      <select id="alertPriority" style="min-width: 120px;">
        <option value="normal">Normal</option>
        <option value="high">High</option>
        <option value="critical">Critical</option>
      </select>
      <button type="button" class="btn btn-danger" onclick="sendCriticalAlert()">
        <i class="fas fa-paper-plane"></i> Send Alert
      </button>
    </form>
  </div>
</div>

<script>
function sendCriticalAlert() {
  const title = document.getElementById('alertTitle').value;
  const message = document.getElementById('alertMessage').value;
  const priority = document.getElementById('alertPriority').value;
  
  if (!title || !message) {
    alert('Please fill in all required fields');
    return;
  }
  
  if (!confirm('Send ' + priority + ' alert to all admins?')) return;
  
  fetch('api_send_admin_critical_alert.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      alert_type: 'custom',
      title: title,
      message: message,
      priority: priority
    })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      alert('Alert sent to ' + data.sent_count + ' admin(s)');
      document.getElementById('criticalAlertForm').reset();
    } else {
      alert('Error: ' + data.message);
    }
  })
  .catch(err => alert('Error: ' + err));
}
</script>
```

---

## Testing Commands

### Using cURL

```bash
# Test Recovery Notification
curl -X POST http://localhost/kbmc_new_asset/api_send_admin_recovery_notification.php \
  -H "Content-Type: application/json" \
  -d '{"recovery_id": 1}'

# Test User Approval Notification
curl -X POST http://localhost/kbmc_new_asset/api_send_admin_user_approval_notification.php \
  -H "Content-Type: application/json" \
  -d '{"approval_id": 1}'

# Test New User Notification
curl -X POST http://localhost/kbmc_new_asset/api_send_admin_new_user_notification.php \
  -H "Content-Type: application/json" \
  -d '{"creation_id": 1}'

# Test Critical Alert
curl -X POST http://localhost/kbmc_new_asset/api_send_admin_critical_alert.php \
  -H "Content-Type: application/json" \
  -d '{
    "alert_type": "custom",
    "title": "Test Alert",
    "message": "This is a test critical alert",
    "priority": "high"
  }'
```

### Using JavaScript (Browser Console)

```javascript
// Test Recovery Notification
fetch('/kbmc_new_asset/api_send_admin_recovery_notification.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ recovery_id: 1 })
})
.then(r => r.json())
.then(d => console.log(d));

// Test Critical Alert
fetch('/kbmc_new_asset/api_send_admin_critical_alert.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    alert_type: 'system_alert',
    title: 'System Test',
    message: 'Testing the new API',
    priority: 'normal'
  })
})
.then(r => r.json())
.then(d => console.log(d));
```

---

## Email Preview

All emails include:
- ✅ Professional HTML formatting
- ✅ Color-coded priority badges
- ✅ Contextual information
- ✅ Direct action buttons linking back to system
- ✅ Footer with system info

---

## Features Summary

| API | Purpose | When to Use |
|-----|---------|-----------|
| **Recovery** | Account recovery alerts | When employee submits recovery request |
| **User Approval** | New IT/Admin account alerts | When IT staff requests new admin account |
| **New User** | Employee registration alerts | When employee creates account or manual resend |
| **Critical Alert** | Flexible system alerts | For urgent device issues, maintenance alerts, security warnings |

---

## API Response Handling

All APIs return JSON in this format:

**Success:**
```json
{
  "success": true,
  "message": "Notification sent to X admin(s)",
  "sent_count": X,
  "admins_notified": X
}
```

**Error:**
```json
{
  "success": false,
  "message": "Error description"
}
```

---

## Permissions

- ✅ Recovery & User Approval APIs: Admin only
- ✅ New User API: Admin only
- ✅ Critical Alert API: Admin or IT staff

---

## System Integration

All APIs integrate with:
- ✅ **Email System** - Uses existing PHPMailer setup
- ✅ **Database** - Creates notification records
- ✅ **Audit Logging** - Logs all sends
- ✅ **System Notifications** - Creates in-app alerts

---

Created: June 2, 2026

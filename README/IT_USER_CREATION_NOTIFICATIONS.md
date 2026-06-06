# IT User Creation Notification System - Implementation Guide

## Problem Fixed
When an admin created a new IT user, the system **failed to**:
- Send email notifications to IT staff with master key/Security IT approval access
- Create in-app notifications visible in the notification bell
- Persist notifications after Security IT grant/approval is completed

## Solution Overview

The system now has a complete notification workflow for IT user creation:

```
Admin Creates IT User → Email to Security IT Approvers + In-App Notification
                              ↓
                    Notification shows in bell (5 recent)
                              ↓
                    Security Admin Grants Security IT Access
                              ↓
                    New "Security Granted" Notification Created + Email sent
                              ↓
                    Notification persists in bell after approval
```

## New Components Created

### 1. **api_send_it_user_creation_notification.php**
**When:** Called automatically when admin creates new IT staff or admin user
**What it does:**
- Finds all IT staff with `is_security_admin = 1` (Security IT Approvers)
- Sends email to each approver with new user details
- Creates in-app notification in bell for each approver
- Logs notification send in audit trail

**Email includes:**
- New user employee ID, name, email
- User role (IT STAFF or ADMIN badge)
- Department and position
- Link to Assign Security IT Management page

### 2. **api_sync_it_user_security_notifications.php**
**When:** Called automatically when Security IT grant is executed
**What it does:**
- Creates "Security Access Granted" notifications for all IT staff/admins
- Sends confirmation emails about the grant
- Shows whether user became Security IT Approver or Standard IT Staff
- Ensures notifications persist after approval

### 3. **api_sync_pending_it_user_notifications.php**
**When:** Manual endpoint (admins/IT staff can call to re-sync)
**What it does:**
- Finds all IT users created in last 24 hours without notifications
- Creates missing notifications for each
- Useful for emergency notification recovery

## Modified Files

### **users.php** (Line 120-143)
```php
// After creating new user
if (in_array($role, ['it_staff', 'admin'])) {
    // Send notification API call
    curl_setopt_array($ch, [
        CURLOPT_URL => '.../api_send_it_user_creation_notification.php',
        CURLOPT_POSTFIELDS => json_encode(['user_id' => $newUserId])
    ]);
}
```

### **assign_security_it.php** (Line 33-55)
```php
// After granting Security IT access
if ($enabled) {
    // Sync notifications
    curl_setopt_array($ch, [
        CURLOPT_URL => '.../api_sync_it_user_security_notifications.php',
        CURLOPT_POSTFIELDS => json_encode(['user_id' => $targetUserId])
    ]);
}
```

### **includes/functions.php**
- **Line 130-131:** Added notification types to filter:
  - `'it_user_created'`
  - `'it_user_security_granted'`
- **Line 168-172:** Added types to admin relevance checker
- **Line 668-670, 686-688:** Added routing for new notification types
  - Both admin and IT staff route to `assign_security_it.php`

### **includes/header.php** (Line 301-302)
```php
'it_user_created' => 'user-plus',      // Shows user+ icon in bell
'it_user_security_granted' => 'user-shield',  // Shows user-shield icon
```

## Notification Types

### `it_user_created`
- **Trigger:** When admin creates new IT staff or admin user
- **Recipients:** All IT staff with Security IT approver status
- **Icon:** `fa-user-plus` (👤+ icon)
- **Action Link:** `assign_security_it.php`
- **Email:** Sent with full new user details

### `it_user_security_granted`
- **Trigger:** When Security IT approval/grant is executed
- **Recipients:** All active IT staff and admins
- **Icon:** `fa-user-shield` (🛡️ icon)
- **Action Link:** `assign_security_it.php`
- **Email:** Confirms security access was granted

## Testing Checklist

1. **Create New IT User**
   - ✅ Go to Manage Users (users.php)
   - ✅ Add new IT Staff or Admin user
   - ✅ Check email received by Security IT Approvers
   - ✅ Check notification bell shows "New IT Staff User Created"

2. **Grant Security IT Access**
   - ✅ Go to Assign Security IT (assign_security_it.php)
   - ✅ Click "Grant" for the new IT user
   - ✅ Check email received: "Security Access Granted"
   - ✅ Check notification bell shows new notification
   - ✅ Verify notification persists after page reload

3. **Manual Sync (Emergency)**
   - ✅ POST to `api_sync_pending_it_user_notifications.php`
   - ✅ Should create any missing notifications
   - ✅ Response shows count of synced notifications

## Database
- No schema changes required
- Uses existing `notifications` table
- Uses existing `users` table with `is_security_admin` column

## Email Configuration
- Uses existing PHPMailer setup (includes/PHPMailer/email_config.php)
- Only sends if `isEmailConfigured()` returns true
- Professional HTML email templates
- [KBMC Alert] prefix for easy filtering

## Audit Trail
- All notification sends logged to `audit_logs` table
- Includes recipient name and email
- Tracks which IT user was notified about

## User Flow

### For IT Staff with Security IT Access
1. Receive email when new IT user is created
2. Check notification bell for "New IT Staff User Created" 
3. Click notification → Goes to Assign Security IT page
4. Review new user details
5. Click "Grant" to give security access
6. Receive confirmation email about grant
7. New "Security Access Granted" notification appears in bell

### For System Administrator
1. Create new IT staff user in Manage Users
2. System automatically notifies Security IT approvers
3. Can monitor approval process via notifications
4. Both admin and IT staff see notifications in bell

## Emergency Recovery
If notifications are missing:
```bash
# POST to manual sync endpoint
curl -X POST http://localhost/kbmc_new_asset/api_sync_pending_it_user_notifications.php \
  -H "Content-Type: application/json" \
  -d '{}' \
  -b "PHPSESSID=<session_cookie>"
```

Will restore all missed notifications from last 24 hours.

---

## Benefits of This Implementation

✅ **Email Notifications** - IT staff with approval access always notified
✅ **In-App Notifications** - Shows in bell icon for quick visibility  
✅ **Notification Persistence** - Doesn't disappear after approval
✅ **Audit Trail** - All notification sends logged
✅ **Emergency Sync** - Can recover lost notifications anytime
✅ **Professional UX** - Consistent with rest of notification system
✅ **Database Efficient** - No new tables, just uses existing structures
✅ **Email Safe** - Only sends if email is configured, gracefully degrades

---

**Created:** June 2, 2026
**Status:** ✅ Production Ready

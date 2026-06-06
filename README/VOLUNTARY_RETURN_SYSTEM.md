# KBMC Asset Management - Device Return System

## Overview

This document describes the improved **My Devices Dashboard** with a professional **Voluntary Device Return System**. Employees can now voluntarily request device returns directly from their dashboard, and IT staff are automatically notified via email.

---

## ✨ UI Improvements (user_asset_dashboard.php)

### 1. Enhanced Statistics Cards
- **New Design**: Gradient backgrounds with semi-transparent icons
- **Better Spacing**: 20px gap between cards, 25px padding
- **Professional Icons**: Device types have unique icons (laptop, check-circle, tools, clock)
- **Shadow Effects**: Box-shadow for depth (0 4px 15px with appropriate opacity)
- **Hover Effects**: Cards can be extended with hover animations
- **Responsive Grid**: Auto-fit layout that adapts to screen width (min 220px)

**Stats Displayed:**
- Total Devices Assigned (Blue gradient)
- Active & Functional (Green gradient)
- Under Repair (Orange gradient)
- Pending Repairs (Red gradient)

### 2. Improved Devices Table
- **Column Widths**: Optimized widths for each column (Asset Tag: 15%, Device Type: 15%, etc.)
- **Status Badges**: Color-coded with borders and icons
- **Maintenance Alerts**: 
  - Overdue: White background with icon
  - On Schedule: Green text with checkmark
- **Better Visual Hierarchy**: Font sizes and weights optimized for scanning
- **Responsive**: Horizontal scroll on mobile devices

### 3. Enhanced Action Buttons
Three action buttons in each row, styled with hover effects:

- **View Button** (Blue): Opens device details
  - Icon: Eye icon
  - Hover: Darker blue with enhanced shadow
  
- **Report Issue Button** (Orange): Report device problems
  - Icon: Exclamation circle
  - Hover: Darker orange with enhanced shadow
  
- **Voluntary Return Button** (Red): Request device return
  - Icon: Hand holding icon
  - Hover: Darker red with enhanced shadow
  - **New Feature** ✅

---

## 🔄 Voluntary Device Return System

### How It Works for Employees

1. **Navigate to My Devices Dashboard**
   - Go to "My Devices" from the sidebar
   - View all assigned devices

2. **Click Voluntary Return Button**
   - Red button with hand icon on each device row
   - Opens a professional modal dialog

3. **Submit Return Request**
   - Device asset tag is pre-populated
   - Optionally provide reason for return
   - Click "Request Return" button

4. **Automatic Notifications**
   - ✅ Confirmation: Employee receives in-app notification
   - ✅ IT Notification: All IT staff receive email alerts
   - ✅ Audit Trail: Request logged for compliance

### IT Staff Workflow

1. **Receive Email Notification**
   - Subject: `[KBMC Alert] Voluntary Device Return Requested`
   - Contains:
     - Employee name and department
     - Device asset tag and type
     - Return reason (if provided)
     - Direct link to IT Clearance page

2. **Access IT Clearance Page**
   - Click link in email or navigate to IT Clearance
   - View employee details and device information
   - Complete security clearance checks

3. **Process Device Return**
   - Mark clearance as complete
   - System notifies employee of next steps
   - Device removed from employee's active assignments

### Modal UI - Voluntary Return

```
┌────────────────────────────────────────┐
│ 🤲 Request Device Return          [X] │
├────────────────────────────────────────┤
│ 📋 Important Info:                     │
│  A return request will notify IT staff │
│  They will assess your device          │
│  clearance and coordinate the return   │
├────────────────────────────────────────┤
│ Device:                                │
│ [KBM-IT-001492]                        │
├────────────────────────────────────────┤
│ Reason for Return (optional):          │
│ [Textarea for reason]                  │
├────────────────────────────────────────┤
│ [Cancel] [Request Return]              │
└────────────────────────────────────────┘
```

---

## 📧 Email Notifications to IT Staff

When an employee requests a voluntary return, all active IT staff members receive an email with:

**Email Subject:**
```
[KBMC Alert] Voluntary Device Return Requested
```

**Email Content:**
```
Hello [IT Staff Name],

[Employee Name] ([Asset Tag] - [Device Type]) has requested 
voluntary device return. Reason: [Return Reason]

┌─────────────────────────────────┐
│ Clearance Request Details:      │
│ 👤 Employee: [Full Name]        │
│    (ID: [Emp ID], Dept: [Dept]) │
│ 💻 Device: [Asset Tag] ([Type]) │
│ 🕐 Requested: [Date/Time]       │
└─────────────────────────────────┘

[Review in System] → Opens it_clearance.php

This is an automated notification from KBMC Asset Management.
```

---

## 🔧 Technical Implementation

### Files Created/Modified

#### 1. **user_asset_dashboard.php** (Modified)
- Enhanced header with descriptive subtitle
- Improved stat cards with gradient backgrounds
- Better styled devices table
- New voluntary return modal
- JavaScript handlers for modal control

#### 2. **api_voluntary_device_return.php** (New)
- API endpoint for processing return requests
- Validates assignment ownership
- Calls `notifyITStaff()` to send emails
- Creates audit logs
- Returns JSON response

### Key Functions Used

**From includes/functions.php:**

1. **`notifyITStaff($type, $title, $message, $related_id)`**
   - Creates system notifications for all IT staff
   - Sends emails to all active IT/admin users
   - Handles email context based on notification type
   
2. **`addNotificationIfNotExists($userId, $type, $title, $message, $related_id)`**
   - Creates system notification for employee
   - Prevents duplicates
   
3. **`logAudit($userId, $action, $table, $record_id, $notes)`**
   - Records activity for compliance
   - Tracks who did what and when

### API Endpoint Details

**Endpoint:** `POST /api_voluntary_device_return.php`

**Request:**
```json
{
  "assignment_id": 123,
  "return_reason": "No longer needed for current project"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Voluntary return request submitted successfully...",
  "assignment_id": 123,
  "device_asset_tag": "KBM-IT-001492",
  "notification_sent": true
}
```

**Error Response (4xx/5xx):**
```json
{
  "success": false,
  "message": "Error description"
}
```

---

## 🔐 Security Features

✅ **Authentication Required**
- User must be logged in to request return
- Session validation on API call

✅ **Ownership Validation**
- Employee can only request return for their own devices
- Device must be in active status
- Assignment ownership verified

✅ **Audit Logging**
- All return requests logged with:
  - User ID who requested
  - Device details
  - Return reason
  - Timestamp

✅ **Email Security**
- Emails only sent to active IT staff and admins
- No personal data exposed in logs
- Professional HTML templates with context

---

## 📱 Responsive Design

The dashboard is fully responsive:

- **Desktop**: Full stat card grid (4 columns)
- **Tablet**: 2-3 stat cards per row
- **Mobile**: Single column stat cards, table scrolls horizontally

---

## 🚀 Testing the System

### Manual Testing Steps

1. **Log in as an Employee**
   - Navigate to: `http://localhost/kbmc_new_asset/user_asset_dashboard.php`
   - You should see assigned devices

2. **Test Voluntary Return**
   - Click red return button on any device
   - Modal should open with device pre-filled
   - Enter optional return reason
   - Click "Request Return"

3. **Verify Notifications**
   - Check IT staff email inbox
   - Should receive email within seconds
   - Check notifications.php in system

4. **Verify Audit Logs**
   - Check audit_logs table
   - Should have VOLUNTARY_RETURN and DEVICE_RETURN_INITIATED entries

### Email Testing

If email is configured via PHPMailer:
- Emails automatically sent to all active IT staff (role = 'admin' or 'it_staff')
- Email includes device/employee context
- Includes direct link to IT Clearance page

If email is NOT configured:
- System notifications still created
- No emails sent (graceful degradation)
- System message indicates email not configured

---

## 📊 Database Tables Affected

### `notifications`
- New row created for each IT staff member
- Type: `user_clearance_required`
- Contains device and employee details

### `audit_logs`
- New entries for return request tracking
- Actions: `VOLUNTARY_RETURN`, `DEVICE_RETURN_INITIATED`
- Includes reason and full context

### `device_assignments`
- No direct modifications (status stays 'active' until IT marks complete)
- Can be queried to find pending returns

---

## 🔄 Integration with Existing Systems

This system integrates with:

1. **View Device Page** (view_device.php)
   - Has alternative "Voluntarily Return" button
   - Same notification flow

2. **IT Clearance** (it_clearance.php)
   - Receives notifications
   - Processes clearance
   - Marks device as returned

3. **IT Dashboard** (it_dashboard.php)
   - Shows notifications in bell icon
   - Can trigger IT clearance workflow

4. **Email System** (includes/email_config.php + PHPMailer)
   - Sends all notification emails
   - Professional HTML templates

---

## ✅ Features Summary

| Feature | Status | Details |
|---------|--------|---------|
| Improved UI | ✅ | Gradient cards, better buttons |
| Voluntary Return Button | ✅ | Red button in actions column |
| Return Request Modal | ✅ | Professional dialog with validation |
| Email Notifications | ✅ | Sent to all IT staff automatically |
| System Notifications | ✅ | Created for bell icon alerts |
| Audit Logging | ✅ | Full compliance tracking |
| Responsive Design | ✅ | Works on all screen sizes |
| Security Checks | ✅ | Auth, ownership validation |
| Error Handling | ✅ | Graceful degradation |

---

## 📝 Notes for Developers

- All modals use consistent styling (border-radius: 12px)
- Action buttons use hover effects (transition: 0.3s)
- Status colors: Blue #3498db, Green #27ae60, Orange #f39c12, Red #e74c3c
- Email templates use existing `emailTemplate()` function
- All API calls use fetch with JSON
- Support for both configured and unconfigured email systems

---

## 🆘 Troubleshooting

**Return button not showing?**
- Verify device is actively assigned (status = 'active')
- Check user is logged in as employee
- Verify assignment_id is in database

**Emails not sent to IT staff?**
- Check `includes/PHPMailer/email_config.php` is configured
- Verify IT staff have `status = 'active'` in database
- Check email logs for errors

**Modal not opening?**
- Ensure JavaScript is enabled
- Check browser console for errors
- Verify device ID is correct

**Return reason lost?**
- Make sure text is entered before submit
- Check form validation in JavaScript

---

## 📞 Support

For issues or questions about this system, contact:
- **IT Staff**: Check notifications in system
- **System Admin**: Review audit logs for troubleshooting

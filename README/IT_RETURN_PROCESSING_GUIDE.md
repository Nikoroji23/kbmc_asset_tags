# IT Staff Guide - Processing Voluntary Device Returns

## Quick Start

When an employee submits a voluntary device return request:

1. **You receive an email** with device and employee details
2. **Email includes a direct link** to the IT Clearance page
3. **You process the clearance** and complete the return
4. **Employee is notified** of completion

---

## Email Notification Format

**Subject Line:**
```
[KBMC Alert] Voluntary Device Return Requested
```

**Email Content:**
```
Hello [Your Name],

[Employee Name] (KBM-IT-001492 - Monitor) has requested 
voluntary device return. Reason: No longer needed

┌────────────────────────────────────────┐
│ Clearance Request Details:             │
│ 👤 Employee: John Smith                │
│    (ID: EMP-123, Dept: Marketing)      │
│ 💻 Device: KBM-IT-001492 (Monitor)     │
│ 🕐 Requested: December 12, 2024 2:30PM │
└────────────────────────────────────────┘

[Review in System] ← Click here to process
```

---

## Processing Steps

### Step 1: Receive Notification
- ✉️ Email arrives in your inbox automatically
- 🔔 System notification appears in bell icon
- ⏱️ No action required yet - take your time

### Step 2: Access IT Clearance Page
**Option A: Via Email Link**
- Click "Review in System" button in email
- Automatically navigates to clearance page

**Option B: Manual Navigation**
- Navigate to: `it_clearance.php`
- Employee ID and device info pre-filled if via email link

### Step 3: Perform Clearance Checks
IT Clearance page allows you to:
- View employee details (name, department, contact)
- View device details (asset tag, serial number, condition)
- Review device condition / inspection status
- Note any issues or concerns

### Step 4: Complete Clearance
- Verify employee has no outstanding violations
- Verify device has no security holds
- Verify device passes inspection (if needed)
- Mark clearance as "Complete"

### Step 5: Device Return Scheduled
After you mark complete:
- ✅ System notifies employee
- ✅ Device removed from active assignments
- ✅ Return scheduled/pickup arranged
- ✅ Audit log updated with completion details

---

## System Notifications

### What You'll See in Bell Icon
When there's a pending voluntary return:
- **Title:** "Voluntary Device Return Requested"
- **Message:** Employee name, device tag, and type
- **Link:** Direct to IT Clearance page
- **Status:** Marked as unread until you view it

### Notification Count
- Appears in red badge on bell icon
- Updates when new return requests arrive
- Clears when you view all notifications

---

## Response Times

**Recommended Response Times:**
- **Immediate:** Review and process within 1-2 hours
- **Priority:** Device in excellent condition - process same day
- **Standard:** Normal device condition - within 1-2 business days
- **Hold:** Device issues detected - escalate to supervisor

---

## Escalation Procedures

### If Device Has Issues
1. **Contact the employee** for clarification
2. **Inspect device thoroughly** before clearance
3. **If major damage:** Document in notes, contact supervisor
4. **If minor issues:** Note it in audit trail, continue

### If Employee Unreachable
1. **Check last known contact** information
2. **Send follow-up email** to employee
3. **Leave system notification** for employee
4. **Contact supervisor** if no response in 48 hours

### If Security Hold
1. **DO NOT complete clearance** without approval
2. **Contact Security Admin** or supervisor
3. **Document hold reason** in system notes
4. **Schedule follow-up review** date

---

## Audit Trail

All voluntary return requests are tracked:

### What's Logged
- Employee name and ID
- Device asset tag and type
- Return request time
- Return reason (if provided)
- IT staff member who processed
- Clearance completion time
- Any notes or issues

### Accessing Audit Trail
- Go to: `asset_tag_audit.php`
- Filter by device or employee
- See full history of all transactions

### For Compliance
- Use audit trail for device accountability reports
- Reference in return disposition documentation
- Track return processing efficiency

---

## Common Scenarios

### Scenario 1: Device is Good Condition
**Timeline:** 30 minutes - 1 hour

1. Receive email
2. Click link to clearance page
3. Review employee and device info
4. Click "Mark as Cleared"
5. System confirms and notifies employee
6. Device added to return inventory

**Action:** Routine clearance ✅

### Scenario 2: Device Needs Inspection
**Timeline:** 1-2 hours

1. Receive email
2. Navigate to device location
3. Physical inspection of device
4. Return to IT Clearance page
5. Review inspection results
6. If OK: Mark as cleared
7. If issues: Document and escalate

**Action:** Full inspection → Clear or Hold

### Scenario 3: Employee Reason is Concerning
**Timeline:** Varies (escalation)

1. Receive email
2. Review reason: "Device suspected compromised"
3. **STOP:** Do not clear without security review
4. Contact security team
5. Security team reviews device
6. Once cleared by security: Mark clearance complete

**Action:** Escalate to Security team

### Scenario 4: Personal Device Returned
**Timeline:** 30 minutes (special handling)

1. Receive email
2. Device is personal (employee brought own device)
3. Follow standard clearance (usually simpler)
4. Confirm personal device status
5. Mark cleared for personal device return

**Action:** Standard clearance ✅

---

## Best Practices

### ✅ DO:
- Respond to requests within 24 hours
- Document everything in clearance notes
- Inspect devices for damage/data security
- Follow proper chain of command for holds
- Communicate with employees if delays
- Use standardized clearance procedures
- Keep audit trail complete and accurate

### ❌ DON'T:
- Skip security checks
- Clear devices with unresolved issues
- Leave requests unprocessed for days
- Ignore system notifications
- Process without documentation
- Clear devices placed on hold
- Skip employee contact if problems found

---

## Quick Reference Commands

### View All Pending Returns
- Navigate to: `notifications.php`
- Filter by type: "user_clearance_required"
- Shows all pending requests

### Search Employee Returns
- Go to: `asset_tag_audit.php`
- Search by employee name
- View all returns for that employee

### View Device History
- Go to: `view_device.php`
- View all past assignments and returns
- See previous clearance records

### Check Email Status
- If email NOT received within 5 minutes:
  1. Check spam/junk folder
  2. Check system notifications in bell icon
  3. Contact admin if emails misconfigured

---

## FAQ

**Q: What if I miss the email?**
A: Check your system notifications (bell icon) or navigate to IT Clearance page directly. All pending requests are listed there.

**Q: How do I know if device is cleared for return?**
A: Look for "Device Status: Ready for Return" on IT Clearance page. If not showing, device has a hold.

**Q: Can I bulk process multiple returns?**
A: Yes - navigate to IT Clearance, filter by date range, process multiple clearances in sequence.

**Q: What if device is damaged?**
A: Document damage in clearance notes, contact supervisor before marking complete. Damaged devices may need repair before return.

**Q: How long to keep audit trail?**
A: Keep indefinitely. Audit trail is compliance documentation required for asset management records.

---

## Support

**Email System Issues?**
- Contact IT Admin
- Check email configuration in system settings

**System Notification Issues?**
- Refresh your browser
- Check if notifications are enabled
- Clear browser cache

**Device/Employee Information Missing?**
- Contact System Admin
- May indicate missing database records
- Request data reconciliation

---

*Last Updated: December 2024*
*For Questions: Contact IT Management*

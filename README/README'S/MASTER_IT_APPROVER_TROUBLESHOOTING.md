# Master IT Approver - Troubleshooting & Quick Reference

## 🚀 Quick Start (TL;DR)

```
1. Admin: Create IT staff account → alfonsoaninias0527@gmail.com
2. Admin: Run set_master_key.php → Select Alfonso, paste 32-char key
3. Admin: Delete set_master_key.php file
4. Alfonso: Login → Go to security_control.php
5. Alfonso: Verify master key when approving users
6. ✓ Done!
```

---

## 📋 Quick Reference Card

### For Admin (Creating Users)

| Task | URL | Steps |
|------|-----|-------|
| Create Employee | `/users.php` | Add User → Role: Employee → Submit (immediate) |
| Create IT Staff | `/users.php` | Add User → Role: IT Staff → Submit → Pending Approval |
| Create Admin | `/users.php` | Add User → Role: Admin → Submit → Pending Approval |
| Set Master Key | `/set_master_key.php` | Select user → Paste key → Submit (then delete file) |
| Grant Approver | `/assign_security_it.php` | Click "Grant Approval" for IT staff |
| View Pending | `/admin_dashboard.php` | See "Pending Approvals" card |

### For Approver (Alfonso - alfonsoaninias0527@gmail.com)

| Task | URL | Steps |
|------|-----|-------|
| Verify Master Key | `/security_control.php` | Click "Verify Master Key" → Enter 32-char key |
| Review Requests | `/security_control.php` | See "Pending IT/Admin User Approvals" section |
| Approve User | `/security_control.php` | Click "Approve" → Master key must be verified |
| Reject User | `/security_control.php` | Click "Reject" → Enter reason → Submit |
| View History | `/audit_logs.php` | Filter by user or action |
| Manage Users | `/users.php` | View all created users |

---

## ❌ Troubleshooting

### Problem: "You do not have Security IT approval privileges"

**Symptoms:**
- Cannot access security_control.php
- Redirected to dashboard
- Error message appears

**Solutions:**
1. Check master key was set:
   ```sql
   SELECT is_security_admin, master_key_hash FROM users 
   WHERE email = 'alfonsoaninias0527@gmail.com';
   ```
   - Should show: `is_security_admin = 1`, `master_key_hash = [not null]`

2. If `is_security_admin = 0`:
   - Re-run `set_master_key.php`
   - Select Alfonso, enter master key, submit

3. If user not found:
   - Create user first in `/users.php`
   - Ensure role is "IT Staff"

---

### Problem: Master Key Verification Fails

**Symptoms:**
- "Invalid master key. Please try again."
- Cannot proceed with approvals

**Solutions:**
1. **Verify you're using the EXACT key:**
   - Keys are case-sensitive
   - Watch for extra spaces
   - 32 characters exactly

2. **Key was lost:**
   - Only option: Regenerate via `set_master_key.php`
   - Run as admin, select Alfonso, create new key
   - Old key becomes invalid

3. **Session expired:**
   - 30-minute timeout is by design
   - Click "Re-verify Master Key"
   - Enter key again

---

### Problem: Pending Approvals Count Shows 0

**Symptoms:**
- Admin sees "Pending Approvals: 0"
- Created IT staff user but not in security_control.php

**Solutions:**
1. **Check database:**
   ```sql
   SELECT * FROM user_approval_requests WHERE status = 'pending';
   ```
   - If empty, no requests exist

2. **Did you create the user correctly?**
   - Must be IT Staff or Admin role
   - Employee users don't need approval (direct creation)

3. **Refresh page:**
   - Admin dashboard caches counts
   - Hard refresh: Ctrl+Shift+R

---

### Problem: New User Not Created After Approval

**Symptoms:**
- Clicked "Approve" successfully
- User doesn't appear in Users.php
- No error message shown

**Solutions:**
1. **Check request was actually approved:**
   ```sql
   SELECT * FROM user_approval_requests WHERE id = [request_id];
   ```
   - Should show: `status = 'approved'`, `approved_at` not null

2. **Check if user was created:**
   ```sql
   SELECT * FROM users WHERE email = '[user_email]';
   ```
   - If not found, approval failed silently

3. **Check master key was verified:**
   - Must verify BEFORE approving
   - Status should show "VERIFIED ✓"
   - 30-minute timeout applies

4. **Check for duplicate email:**
   - If email already exists, user not created
   - Get error in browser console (F12)

---

### Problem: set_master_key.php Won't Load

**Symptoms:**
- 404 Not Found error
- Cannot access page

**Solutions:**
1. **File was already deleted:**
   - This is correct! File should be deleted after use
   - No need to run again unless regenerating key

2. **File doesn't exist at root:**
   - Should be at: `http://localhost/kbmc_new_asset/set_master_key.php`
   - Check file is in correct directory

3. **File was corrupted:**
   - Re-create from backup or documentation

---

### Problem: Can't See Approval Requests in Security Control

**Symptoms:**
- Logged in as Alfonso
- security_control.php loads but no requests shown
- "No pending approvals" message

**Solutions:**
1. **No requests actually pending:**
   - Check admin created IT/Admin users (not employees)
   - Run: `SELECT * FROM user_approval_requests WHERE status = 'pending';`

2. **Requests are old:**
   - Approved/rejected requests won't show
   - Check with: `SELECT * FROM user_approval_requests LIMIT 10;`

3. **Database issue:**
   - Check table exists: `SHOW TABLES LIKE 'user_approval_requests';`
   - Check schema: `DESCRIBE user_approval_requests;`

---

### Problem: Audit Log Doesn't Show Approval Actions

**Symptoms:**
- Approved users but audit_logs.php empty
- No record of approval action

**Solutions:**
1. **Check filter settings:**
   - Audit logs might be filtered
   - Clear filters to show all

2. **Check for actions:**
   ```sql
   SELECT * FROM audit_logs WHERE action LIKE '%Approve%' LIMIT 10;
   ```

3. **Check timestamp:**
   - Logs are timestamped
   - May be sorting by date (newest last)

---

### Problem: Email Notifications Not Sending

**Symptoms:**
- Approved user but no email sent
- Admin not notified

**Solutions:**
1. **Email not configured:**
   - Check `/includes/email_config.php` exists
   - Review EMAIL_SETUP.md

2. **User has no email:**
   - Verify user has valid email address
   - Check database for null email

3. **Email service disabled:**
   - In functions.php: `isEmailConfigured()` returns false
   - Configure email settings to enable

---

## 🔒 Security Checklist

### Setup Security

- [ ] Master key is 32 characters random hex
- [ ] Master key stored in password manager
- [ ] set_master_key.php file deleted after use
- [ ] Only admin has access to set_master_key.php during setup
- [ ] Database backups made before running migration

### Operational Security

- [ ] Master key never shared via email/chat
- [ ] Master key changed every 90 days
- [ ] Audit logs reviewed monthly
- [ ] Approver account logs off after use
- [ ] No one else has approver password
- [ ] Unauthorized requests are rejected/logged
- [ ] IP addresses monitored for unusual access

### Database Security

- [ ] Master key hashed with BCrypt
- [ ] Password fields use bcrypt hashing
- [ ] SQL injection protections active
- [ ] CSRF tokens required on all forms
- [ ] Database backups scheduled
- [ ] Encryption at rest configured (if needed)

---

## 📊 Monitoring Dashboard

### Key Metrics to Monitor

```
Daily:
├─ Pending approval requests count
├─ Failed master key verification attempts
└─ Failed login attempts

Weekly:
├─ Total approvals granted
├─ Total approvals rejected
├─ New users created
└─ Security incidents

Monthly:
├─ Access patterns by user
├─ Peak approval times
├─ System performance
└─ Compliance audit
```

### Check Health

```sql
-- Total active approvers
SELECT COUNT(*) FROM users 
WHERE role = 'it_staff' AND is_security_admin = 1 AND status = 'active';

-- Pending approvals
SELECT COUNT(*) FROM user_approval_requests WHERE status = 'pending';

-- Recent approvals
SELECT COUNT(*) FROM user_approval_requests 
WHERE status = 'approved' AND approved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY);

-- Recent rejections
SELECT COUNT(*) FROM user_approval_requests 
WHERE status = 'rejected' AND approved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY);

-- Failed key verifications
SELECT COUNT(*) FROM security_key_logs 
WHERE success = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY);

-- Recent approvals detail
SELECT ar.id, ar.full_name, ar.requested_role, ar.approved_at, u.full_name as approved_by
FROM user_approval_requests ar
JOIN users u ON ar.approved_by = u.id
WHERE ar.status = 'approved'
ORDER BY ar.approved_at DESC
LIMIT 10;
```

---

## 💡 Tips & Best Practices

### Master Key Management
1. **Generate once, use many times** - Key doesn't change per approval
2. **Store securely** - Password manager (1Password, LastPass, etc.)
3. **Never in code** - Don't hardcode or version control
4. **Change quarterly** - Regenerate every 90 days for security
5. **Backup securely** - Keep encrypted backup separate

### Approval Workflow
1. **Verify before approving** - Always verify master key first
2. **Check details** - Review email, role, department before approving
3. **Reject with reason** - Always provide reason for rejection
4. **Timely reviews** - Check security_control.php daily
5. **Log out after** - Session expires in 30 min anyway, but good practice

### Admin Tasks
1. **Use descriptive reasons** - When requesting new users
2. **One request at a time** - Easier to track
3. **Monitor approvals** - Check admin_dashboard.php status
4. **Audit regularly** - Review audit_logs.php monthly
5. **Update contacts** - Keep user contact info current

### Monitoring
1. **Watch for patterns** - Unusual approval patterns may indicate issues
2. **Check failed attempts** - Failed key verifications signal problems
3. **Review rejections** - Understand why requests are rejected
4. **Monitor timestamps** - Unusual hours may indicate compromise
5. **Alert on anomalies** - Set up alerts for unusual activity

---

## 🛠️ Maintenance Tasks

### Daily
- [ ] Check pending approvals count
- [ ] Approve/reject pending requests
- [ ] Monitor failed verification attempts

### Weekly
- [ ] Review audit logs for unusual patterns
- [ ] Check user creation logs
- [ ] Verify all active approvers still have privileges

### Monthly
- [ ] Full audit log review
- [ ] Compliance check
- [ ] Verify master key access security
- [ ] Update documentation

### Quarterly
- [ ] Regenerate master key
- [ ] Review all approver privileges
- [ ] Audit all created users for legitimacy
- [ ] Security assessment

### Yearly
- [ ] Full security audit
- [ ] Update all passwords
- [ ] Review and revoke departed staff access
- [ ] Compliance certification

---

## 📞 Support Contacts

### System Errors
1. Check this troubleshooting guide
2. Review audit_logs.php for details
3. Check PHP error log: `C:\xampp\apache\logs\error.log`

### Database Issues
- Database: `kbmc_asset_db`
- Host: `localhost`
- User: `root`
- Tables: `users`, `user_approval_requests`, `security_key_logs`, `master_key_audit`, `audit_logs`

### Feature Questions
- Review: `MASTER_IT_APPROVER_SETUP.md` (detailed guide)
- Review: `MASTER_IT_APPROVER_ARCHITECTURE.md` (system flow)
- Check: `security_control.php` (source code)

---

## 📎 Related Documentation

- **MASTER_IT_APPROVER_SETUP.md** - Complete setup guide
- **MASTER_IT_APPROVER_ARCHITECTURE.md** - System architecture
- **MASTER_IT_APPROVER_CHECKLIST.html** - Interactive checklist
- **EMAIL_SETUP.md** - Email configuration
- **QUICK_REFERENCE.md** - System quick reference
- **VERIFICATION_CHECKLIST.md** - System verification

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | June 2, 2026 | Initial release |

---

*For additional help, check the inline code comments in security_control.php and includes/functions.php*

**Last Updated: June 2, 2026**

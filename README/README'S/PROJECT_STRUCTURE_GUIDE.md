# KBMC Asset Management System - Project Structure Guide

**Version**: 1.0  
**Last Updated**: June 2, 2026  
**Status**: ✅ Clean & Organized

---

## Quick Navigation

This guide helps you understand the KBMC project folder structure and locate files quickly.

### Finding Files by Function

| What You Need | Location | Files |
|---------------|----------|-------|
| **User Management** | Root directory | `users.php`, `admin_accounts.php`, `profile.php` |
| **Device Management** | Root directory | `devices.php`, `edit_device.php`, `delete_device.php`, `view_device.php` |
| **Security & Approvals** | Root directory | `security_control.php`, `audit_logs.php`, `assign_security_it.php` |
| **Maintenance & Repairs** | Root directory | `maintenance_repairs.php`, `maintenance_reminders.php`, `repairs.php` |
| **Reports & Analytics** | Root directory | `reports.php`, `dashboard.php`, `admin_dashboard.php`, `it_dashboard.php` |
| **Notifications** | Root directory | `notifications.php`, `get_new_notifications.php`, `mark_notification_read.php` |
| **Configuration** | `includes/` | `config.php`, `email_config.php`, `functions.php` |
| **Database Setup** | `databases/` | `kbmcdatabase.sql`, migration files |
| **Frontend Assets** | `assets/` | CSS, JavaScript, images, uploads |
| **Documentation** | `README'S/` | Setup guides, troubleshooting, architecture |
| **Utilities** | `tools/` | `cleanup_orphan_assignments.php` |

---

## Directory Structure (Detailed)

```
kbmc_new_asset/                                 # Application root
│
├─── README'S/                                   # 📚 Documentation (13 files)
│    ├── MASTER_IT_APPROVER_SETUP.md            # How to set up master IT approver account
│    ├── MASTER_IT_APPROVER_TROUBLESHOOTING.md  # Q&A and problem solving
│    ├── MASTER_IT_APPROVER_ARCHITECTURE.md     # System design and flows
│    ├── MASTER_IT_APPROVER_CHECKLIST.html      # Interactive 4-phase setup checklist
│    ├── MASTER_IT_APPROVER_REFERENCE_CARD.html # Quick reference cards (10 cards)
│    ├── CLEANUP_SUMMARY.md                     # This cleanup project documentation
│    ├── ASSET_IMPORT_GUIDE.md
│    ├── ASSET_IMPORT_README.md
│    ├── EMAIL_SETUP.md
│    ├── IMPLEMENTATION_COMPLETE.md
│    ├── IMPLEMENTATION_SUMMARY.md
│    ├── MASTER_KEY_SETUP_GUIDE.md
│    ├── QUICK_REFERENCE.md
│    └── VERIFICATION_CHECKLIST.md
│
├─── databases/                                  # 🗄️ Database files
│    ├── kbmcdatabase.sql                       # Main database schema
│    ├── database_updates.sql
│    ├── database_security_updates.sql
│    ├── feature_updates.sql
│    ├── device_lifespan_mitation.sql
│    ├── reset_password_sql.sql
│    ├── remove_asset_tag_unique_constraint.sql
│    ├── MIGRATION_ASSET_TAG.md
│    └── create_account_creations_table.sql
│
├─── includes/                                   # 🔧 Core application code
│    ├── config.php                             # Database connection (confidential)
│    ├── email_config.php                       # Email settings (Mailer)
│    ├── functions.php                          # 15+ core functions:
│    │                                          #  - User approvals
│    │                                          #  - Master key verification
│    │                                          #  - Security checks
│    │                                          #  - Data validation
│    ├── header.php                             # Navigation & UI template
│    └── footer.php                             # Page footer template
│
├─── assets/                                     # 🎨 Frontend assets
│    ├── css/                                   # Stylesheets
│    ├── js/                                    # JavaScript files
│    ├── images/                                # UI images & logos
│    └── uploads/                               # User file uploads (devices, docs)
│
├─── tools/                                      # 🛠️ Production utilities
│    └── cleanup_orphan_assignments.php         # Cleans up orphaned device assignments
│
├─── logs/                                       # 📝 Runtime logs
│    └── (Empty - created at runtime)
│
├─── sessions/                                   # 🔐 PHP session files
│    └── (Created automatically by PHP)
│
├─── .git/                                       # 📦 Version control
├─── .vscode/                                    # ⚙️ VS Code settings
├─── .qodo/                                      # 📋 Task tracking
│
├─── [Core Application Pages - 40+ files]        # 🏢 Main functionality
│    │
│    ├── User & Account Management
│    │   ├── index.php                           # Login/landing page
│    │   ├── signup.php                          # New account registration
│    │   ├── login.php                           # User login
│    │   ├── logout.php                          # User logout
│    │   ├── profile.php                         # User profile
│    │   ├── account_recovery.php                # Account recovery
│    │   ├── forgot_password.php                 # Password reset request
│    │   └── reset_password.php                  # Password reset form
│    │
│    ├── Admin Functions
│    │   ├── admin_dashboard.php                 # Admin overview
│    │   ├── admin_accounts.php                  # Manage admin accounts
│    │   ├── users.php                           # User management
│    │   ├── assign_security_it.php              # Grant Security IT privileges
│    │   └── user_actions.php                    # Log user actions
│    │
│    ├── IT Staff Functions
│    │   ├── it_dashboard.php                    # IT staff overview
│    │   ├── it_clearance.php                    # IT device clearance
│    │   └── security_control.php                # Master key verification & approvals
│    │
│    ├── Device Management
│    │   ├── devices.php                         # Device inventory list
│    │   ├── view_device.php                     # Device detail view
│    │   ├── add_device.php                      # Add new device
│    │   ├── edit_device.php                     # Edit device
│    │   ├── delete_device.php                   # Delete device
│    │   ├── import_assets.php                   # Bulk import devices
│    │   ├── deployments.php                     # Device deployment tracking
│    │   ├── device_search.php                   # Search devices
│    │   ├── return_device.php                   # Device return process
│    │   ├── retired.php                         # Retired devices list
│    │   ├── device_lifespan.php                 # Device lifecycle tracking
│    │   └── asset_tag_audit.php                 # Asset tag validation
│    │
│    ├── Maintenance & Repairs
│    │   ├── maintenance_repairs.php             # Repair management
│    │   ├── maintenance_reminders.php           # Maintenance reminders
│    │   ├── repairs.php                         # Repair tracking
│    │   ├── api_send_repair_notification.php    # Repair notifications
│    │   ├── api_mark_repair_done.php            # Mark repair complete
│    │   └── api_report_device_issue.php         # Report device issues
│    │
│    ├── Inspections & Requests
│    │   ├── inspections.php                     # Device inspections
│    │   ├── requests.php                        # User requests
│    │   ├── api_send_inspection_notification.php
│    │   └── api_send_maintenance_reminder.php
│    │
│    ├── Notifications
│    │   ├── notifications.php                   # Notification center
│    │   ├── get_new_notifications.php           # Fetch new notifications
│    │   └── mark_notification_read.php          # Mark as read
│    │
│    ├── Reporting & Analytics
│    │   ├── dashboard.php                       # User dashboard
│    │   ├── user_asset_dashboard.php            # Asset dashboard
│    │   ├── reports.php                         # Reports interface
│    │   └── audit_logs.php                      # Audit trail viewer
│    │
│    ├── Approvals & Security
│    │   └── security_control.php                # Master IT approvals
│    │
│    └── API Endpoints
│        ├── api_user_details.php
│        ├── api_send_inspection_notification.php
│        ├── api_send_maintenance_reminder.php
│        ├── api_send_repair_notification.php
│        ├── api_mark_repair_done.php
│        └── api_report_device_issue.php
│
└─── Configuration Files
     ├── sample_assets_import.csv                # Asset import template
     ├── README/                                 # Legacy folder (consolidation)
     └── [.git files, .vscode files, etc.]
```

---

## Key File Descriptions

### Essential Files (Don't Delete)

| File | Purpose | Access Level |
|------|---------|--------------|
| `includes/config.php` | Database connection (contains credentials) | ⚠️ Restricted |
| `includes/functions.php` | Core approval logic & security functions | ✅ All code |
| `includes/header.php` | Navigation & UI template | ✅ All pages |
| `security_control.php` | Master IT approver interface | 🔐 Security IT only |
| `audit_logs.php` | Compliance & audit trail | 🔐 Admin only |
| `users.php` | User management & approvals | 🔐 Admin only |

### Data Flow Files

```
User Creates Approval Request
    ↓
users.php (create approval request)
    ↓
functions.php (createUserApprovalRequest)
    ↓
user_approval_requests table (pending)
    ↓
security_control.php (Security IT views)
    ↓
Security IT verifies master key
    ↓
functions.php (approveUserCreation)
    ↓
User created ✅
    ↓
audit_logs.php (logs approval)
```

---

## Important Notes

### Security Files
- **includes/config.php**: Contains database password - NEVER commit to Git
- **Master key**: Stored as BCrypt hash in database, 32-character hex stored securely
- **Credentials**: All passwords BCrypt hashed ($2y$ algorithm)

### Database Tables
- **users**: User accounts with role-based access
- **user_approval_requests**: New admin/IT staff approvals pending Security IT review
- **audit_logs**: Complete audit trail of all approvals
- **security_key_logs**: Master key usage tracking
- **master_key_audit**: Master key verification history

### Configuration
- Database: `kbmc_asset_db` (MySQL on localhost)
- Email: Configured via includes/email_config.php (PHPMailer)
- Sessions: PHP default session handler (stored in `/sessions/` directory)

---

## Cleanup History

**Latest Cleanup (June 2, 2026)**:
- ✅ Removed 15 temporary/debug files
- ✅ Organized documentation to README'S/
- ✅ Streamlined tools/ folder
- ✅ Created comprehensive guides

See `README'S/CLEANUP_SUMMARY.md` for complete details.

---

## How to Use This Guide

1. **Finding a file?** → Look in the "Finding Files by Function" table
2. **Understand folder structure?** → View the detailed directory tree
3. **Need to add a file?** → Check the file naming conventions below
4. **Debugging an issue?** → See the "Key File Descriptions" section

## File Naming Conventions

```
✅ Production files:        devices.php, users.php, security_control.php
✅ Utility files:           cleanup_orphan_assignments.php
✅ Configuration:           config.php, email_config.php
✅ Documentation:           *.md in README'S/ folder
❌ Test files:             test_*.php (DELETE after testing)
❌ Debug files:            debug_*.php (DELETE after debugging)
```

---

## Support & Questions

Refer to the appropriate documentation:

| Question | Document |
|----------|----------|
| How do I set up Master IT Approver? | `MASTER_IT_APPROVER_SETUP.md` |
| How does the approval workflow work? | `MASTER_IT_APPROVER_ARCHITECTURE.md` |
| I have a problem with X | `MASTER_IT_APPROVER_TROUBLESHOOTING.md` |
| How do I import assets? | `ASSET_IMPORT_GUIDE.md` |
| How do I set up email? | `EMAIL_SETUP.md` |

---

**Project Status**: ✅ Well-Organized | 🔒 Secure | 📚 Well-Documented

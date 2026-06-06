<?php
// ── Bootstrap ──
$pageTitle = 'Manage Users';
require_once __DIR__ . '/includes/functions.php';

global $pdo;

// ═══════════════════════════════════════════════════════
// AJAX: return JSON for the employee details modal in devices.php
// Called by: fetch('users.php?view_user=ID&ajax=1')
// No new file needed — handled right here.
// ═══════════════════════════════════════════════════════
if (isset($_GET['view_user']) && isset($_GET['ajax'])) {
    // Avoid redirecting for AJAX; return JSON 403 when not authorized
    if (!isLoggedIn() || (!hasRole('it_staff') && !hasRole('admin'))) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    $uid = (int)($_GET['view_user'] ?? 0);
    if (!$uid) { echo json_encode(['error' => 'Invalid user']); exit; }

    $uStmt = $pdo->prepare(
        "SELECT id, employee_id, full_name, email, department, position, status, created_at
         FROM   users WHERE id = :id LIMIT 1"
    );
    $uStmt->execute([':id' => $uid]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { echo json_encode(['error' => 'User not found']); exit; }

    $aStmt = $pdo->prepare(
        "SELECT d.id, d.asset_tag, d.pc_name, d.ip_address, d.status,
                dt.type_name AS category,
                da.assigned_date AS assigned_at,
                da.purpose
         FROM   device_assignments da
         JOIN   devices      d  ON da.device_id     = d.id
         JOIN   device_types dt ON d.device_type_id = dt.id
         WHERE  da.employee_id = :uid
           AND  da.status = 'active'
         ORDER  BY da.assigned_date DESC"
    );
    $aStmt->execute([':uid' => $uid]);
    $assets = $aStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($assets as &$a) {
        $a['pc_name']     = ($a['pc_name']     !== null && $a['pc_name']     !== '') ? $a['pc_name']     : 'N/A';
        $a['ip_address']  = ($a['ip_address']  !== null && $a['ip_address']  !== '') ? $a['ip_address']  : 'N/A';
        $a['asset_tag']   = ($a['asset_tag']   !== null && $a['asset_tag']   !== '') ? $a['asset_tag']   : 'N/A';
        $a['category']    = ($a['category']    !== null && $a['category']    !== '') ? $a['category']    : 'N/A';
        $a['status']      = ($a['status']      !== null && $a['status']      !== '') ? $a['status']      : 'N/A';
        $a['assigned_at'] = ($a['assigned_at'] !== null && $a['assigned_at'] !== '')
                            ? date('M d, Y', strtotime($a['assigned_at'])) : 'N/A';
        $a['name'] = $a['category'];
    }
    unset($a);

    echo json_encode(['user' => $user, 'assets' => $assets], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/includes/header.php';
requireAdmin();

// ═══════════════════════════════════════════════════════
// POST handling at TOP — before any SELECT queries or HTML
// ═══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        empty($_POST['csrf_token']) ||
        !isset($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        http_response_code(403);
        die('CSRF verification failed. Please go back and try again.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $allowed_roles = ['employee', 'it_staff', 'admin'];
        $role = $_POST['role'] ?? '';
        if (!in_array($role, $allowed_roles, true)) {
            header("Location: users.php?error=Invalid+role+selected");
            exit;
        }
        $email = trim($_POST['email'] ?? '');
        if (!isValidEmail($email)) {
            header("Location: users.php?error=Invalid+email+address");
            exit;
        }
        $employee_id = trim($_POST['employee_id'] ?? '');
        $full_name   = trim($_POST['full_name']   ?? '');
        $department  = trim($_POST['department']  ?? '');
        $dept_map = [
            'it'            => 'IT Department',
            'i.t.'          => 'IT Department',
            'it dept'       => 'IT Department',
            'it department' => 'IT Department',
        ];
        $dept_key = strtolower($department);
        if (isset($dept_map[$dept_key])) {
            $department = $dept_map[$dept_key];
        }
        $password = $_POST['password'] ?? '';
        if (!$employee_id || !$full_name || !$department || !$password) {
            header("Location: users.php?error=All+required+fields+must+be+filled");
            exit;
        }
        $masterKey = strtoupper(bin2hex(random_bytes(4)));
        $hashedPw  = password_hash($password, PASSWORD_DEFAULT);
        $ins = $pdo->prepare("INSERT INTO users
            (employee_id, full_name, email, role, department, position, password, master_key, status, created_at)
            VALUES (:eid, :full_name, :email, :role, :dept, :pos, :pw, :mk, 'active', NOW())");
        $ins->execute([
            ':eid'       => $employee_id,
            ':full_name' => $full_name,
            ':email'     => $email,
            ':role'      => $role,
            ':dept'      => $department,
            ':pos'       => trim($_POST['position'] ?? ''),
            ':pw'        => $hashedPw,
            ':mk'        => $masterKey,
        ]);
        $newUserId = $pdo->lastInsertId();
        
        // Send notifications if IT user or admin was created
        if (in_array($role, ['it_staff', 'admin'])) {
            try {
                $ch = curl_init();
                $sessionCookie = session_name() . '=' . session_id();
                curl_setopt_array($ch, [
                    CURLOPT_URL => 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/api_send_it_user_creation_notification.php',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_COOKIE => $sessionCookie,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                    CURLOPT_POSTFIELDS => json_encode(['user_id' => (int)$newUserId]),
                    CURLOPT_TIMEOUT => 5
                ]);
                $response = curl_exec($ch);
                curl_close($ch);
                error_log("IT user creation notification sent for user {$newUserId}");
            } catch (Exception $e) {
                error_log("Failed to send IT user creation notification: " . $e->getMessage());
            }
        }
        
        header("Location: users.php?success=User+added+successfully");
        exit;
    }

    if ($action === 'edit_user') {
        $allowed_roles = ['employee', 'it_staff', 'admin'];
        $role = $_POST['role'] ?? '';
        if (!in_array($role, $allowed_roles, true)) {
            header("Location: users.php?error=Invalid+role+selected");
            exit;
        }
        $email = trim($_POST['email'] ?? '');
        if (!isValidEmail($email)) {
            header("Location: users.php?error=Invalid+email+address");
            exit;
        }
        $user_id    = (int)($_POST['user_id'] ?? 0);
        $full_name  = trim($_POST['full_name']  ?? '');
        $department = trim($_POST['department'] ?? '');
        $position   = trim($_POST['position']   ?? '');
        if (!$user_id || !$full_name || !$department) {
            header("Location: users.php?error=Required+fields+missing");
            exit;
        }
        $upd = $pdo->prepare("UPDATE users
            SET full_name=:full_name, email=:email, role=:role,
                department=:dept, position=:pos
            WHERE id=:id");
        $upd->execute([
            ':full_name' => $full_name,
            ':email'     => $email,
            ':role'      => $role,
            ':dept'      => $department,
            ':pos'       => $position,
            ':id'        => $user_id,
        ]);
        header("Location: users.php?success=User+updated+successfully");
        exit;
    }

    if ($action === 'deactivate') {
        $pdo->prepare("UPDATE users SET status='inactive' WHERE id=:id")
            ->execute([':id' => (int)$_POST['user_id']]);
        header("Location: users.php?success=User+deactivated");
        exit;
    }

    if ($action === 'activate') {
        $pdo->prepare("UPDATE users SET status='active' WHERE id=:id")
            ->execute([':id' => (int)$_POST['user_id']]);
        header("Location: users.php?success=User+activated");
        exit;
    }

    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM users WHERE id=:id")
            ->execute([':id' => (int)$_POST['user_id']]);
        header("Location: users.php?success=User+deleted");
        exit;
    }
}

// ── Generate / persist CSRF token ──
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ── Filters & Sorting ──
$search      = trim($_GET['search']     ?? '');
$dept_filter = trim($_GET['department'] ?? '');
$name_sort   = $_GET['name_sort']       ?? '';

// ── Pagination ──
$page     = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset   = ($page - 1) * $per_page;

// ── Build WHERE ──
$where  = "WHERE 1=1";
$params = [];
if ($search !== '') {
    $where .= " AND (u.full_name LIKE :search_full OR u.email LIKE :search_email
                     OR u.employee_id LIKE :search_eid OR u.department LIKE :search_dept)";
    $params['search_full']  = "%$search%";
    $params['search_email'] = "%$search%";
    $params['search_eid']   = "%$search%";
    $params['search_dept']  = "%$search%";
}
if ($dept_filter !== '') {
    $where .= " AND u.department = :dept";
    $params['dept'] = $dept_filter;
}

// ── ORDER BY ──
$order = "ORDER BY u.id DESC";
if ($name_sort === 'asc')  $order = "ORDER BY u.full_name ASC";
if ($name_sort === 'desc') $order = "ORDER BY u.full_name DESC";

// ── Total count for pagination ──
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $where");
$countStmt->execute($params);
$total_users = (int)$countStmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_users / $per_page));
$page = min($page, $total_pages);

// ── Fetch departments for dropdown ──
$deptStmt    = $pdo->query("SELECT DISTINCT department FROM users
                              WHERE department IS NOT NULL AND department != ''
                              ORDER BY department ASC");
$departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

// ── Fetch paginated users ──
$stmt = $pdo->prepare("SELECT u.*, u.employee_id AS emp_id
                        FROM users u $where $order
                        LIMIT :limit OFFSET :offset");
foreach ($params as $k => $v) {
    $paramName = str_starts_with($k, ':') ? $k : ':' . $k;
    $stmt->bindValue($paramName, $v);
}
$stmt->bindValue(':limit',  $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,   PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Recovery requests count (for tab badge only) ──
$recoveryCount = (int)$pdo->query("SELECT COUNT(*) FROM account_recovery_requests WHERE status='pending'")->fetchColumn();
?>

<!-- ══════════════════════════════════════════════
     PAGE-SPECIFIC STYLES ONLY
     (layout/sidebar/topbar come from assets/css/style.css via header.php)
     ══════════════════════════════════════════════ -->
<style>
    /* ── Page layout wrapper ── */
    .users-page { padding: 28px; }

    /* ── Page Header ── */
    .page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:12px; }
    .page-header h1 { font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; margin:0; }

    /* ── Buttons ── */
    .btn { display:inline-flex; align-items:center; gap:7px; padding:9px 18px; border-radius:7px; border:none; cursor:pointer; font-size:13px; font-weight:600; text-decoration:none; transition:.15s; }
    .btn-primary { background:#c0392b; color:#fff; }
    .btn-primary:hover { background:#a93226; }
    .btn-outline { background:#fff; color:#555; border:1px solid #d5d9e0; }
    .btn-outline:hover { background:#f4f6fb; }
    .btn-sm { padding:6px 12px; font-size:12px; }
    .btn-danger  { background:#c0392b; color:#fff; }
    .btn-danger:hover  { background:#a93226; }
    .btn-warning { background:#e67e22; color:#fff; }
    .btn-warning:hover { background:#d35400; }
    .btn-success { background:#27ae60; color:#fff; }
    .btn-success:hover { background:#1e8449; }
    .btn-info    { background:#2980b9; color:#fff; }
    .btn-info:hover    { background:#2471a3; }
    .btn-icon { background:none; border:none; cursor:pointer; color:#555; font-size:15px; padding:5px 7px; border-radius:5px; transition:.15s; }
    .btn-icon:hover { background:#f0f0f0; color:#c0392b; }

    /* ── Alerts ── */
    .u-alert { padding:11px 16px; border-radius:7px; margin-bottom:18px; font-size:13px; display:flex; align-items:center; gap:8px; }
    .u-alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .u-alert-danger  { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

    /* ── Filter Bar ── */
    .filter-bar {
        background:#fff; border:1px solid #e5e9f0; border-radius:10px;
        padding:16px 20px; margin-bottom:20px;
        display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;
    }
    .filter-bar .fg { display:flex; flex-direction:column; gap:5px; flex:1; min-width:160px; }
    .filter-bar label { font-size:11px; font-weight:600; color:#888; text-transform:uppercase; letter-spacing:.5px; }
    .filter-bar input, .filter-bar select {
        padding:9px 12px; border:1px solid #d5d9e0; border-radius:7px; font-size:13px;
        background:#fff; color:#333; outline:none; transition:.15s; width:100%;
    }
    .filter-bar input:focus, .filter-bar select:focus { border-color:#c0392b; box-shadow:0 0 0 3px rgba(192,57,43,.1); }
    .filter-bar .fg-actions { display:flex; gap:8px; align-items:flex-end; flex-shrink:0; }

    /* ── Tabs ── */
    .u-tabs { display:flex; gap:4px; margin-bottom:18px; border-bottom:2px solid #e5e9f0; }
    .u-tab { padding:10px 18px; font-size:13px; font-weight:600; color:#888; border:none; background:none; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; display:flex; align-items:center; gap:7px; transition:.15s; text-decoration:none; }
    .u-tab.active { color:#c0392b; border-bottom-color:#c0392b; }
    .u-tab-badge { background:#c0392b; color:#fff; border-radius:50%; font-size:10px; width:18px; height:18px; display:inline-flex; align-items:center; justify-content:center; }
    .u-tab-count { background:#e5e9f0; color:#555; border-radius:20px; padding:2px 8px; font-size:11px; }

    /* ── Card & Table ── */
    .u-card { background:#fff; border-radius:12px; border:1px solid #e5e9f0; overflow:hidden; }
    .table-wrap { width:100%; overflow-x:auto; }
    .u-table { width:100%; border-collapse:collapse; font-size:13px; }
    .u-table thead th { background:#f8f9fc; color:#555; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; padding:12px 16px; border-bottom:1px solid #e5e9f0; white-space:nowrap; }
    .u-table tbody tr { border-bottom:1px solid #f0f2f8; transition:.12s; }
    .u-table tbody tr:last-child { border-bottom:none; }
    .u-table tbody tr:hover { background:#fafbff; }
    .u-table tbody td { padding:13px 16px; vertical-align:middle; }
    .user-name  { font-weight:700; color:#222; }
    .user-email { font-size:12px; color:#888; }

    /* ── Status badges ── */
    .badge-status { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; }
    .badge-active   { background:#d4edda; color:#155724; }
    .badge-inactive { background:#f8d7da; color:#721c24; }

    /* ── Action dropdown ── */
    .action-wrap { position:relative; display:inline-block; }
    .action-trigger { display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:7px; border:1px solid #d5d9e0; background:#fff; cursor:pointer; font-size:12px; font-weight:600; color:#555; transition:.15s; white-space:nowrap; }
    .action-trigger:hover { background:#f4f6fb; border-color:#bbb; }
    .action-trigger i.fa-chevron-down { font-size:10px; transition:transform .2s; }
    .action-wrap.open .action-trigger i.fa-chevron-down { transform:rotate(180deg); }
    .action-menu { display:none; position:absolute; right:0; top:calc(100% + 5px); background:#fff; border:1px solid #e5e9f0; border-radius:9px; box-shadow:0 8px 24px rgba(0,0,0,.12); min-width:168px; z-index:500; overflow:hidden; }
    .action-wrap.open .action-menu { display:block; }
    .action-menu button, .action-menu .act-form button { display:flex; align-items:center; gap:9px; width:100%; padding:10px 14px; border:none; background:none; font-size:13px; color:#333; cursor:pointer; text-align:left; transition:.12s; }
    .action-menu button:hover, .action-menu .act-form button:hover { background:#f4f6fb; }
    .action-menu .act-form { display:block; }
    .action-menu .menu-divider { height:1px; background:#f0f2f8; margin:4px 0; }
    .action-menu .act-danger { color:#c0392b !important; }
    .action-menu .act-danger:hover { background:#fdf0ef !important; }
    .action-menu .act-warning { color:#e67e22 !important; }
    .action-menu .act-warning:hover { background:#fef6ee !important; }
    .action-menu .act-info { color:#2980b9 !important; }
    .action-menu .act-info:hover { background:#eef5fb !important; }

    /* ── Pagination ── */
    .u-pagination { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; border-top:1px solid #e5e9f0; font-size:13px; flex-wrap:wrap; gap:10px; }
    .pagination-info { color:#888; }
    .pagination-btns { display:flex; gap:6px; }
    .pg-btn { display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:7px; border:1px solid #d5d9e0; background:#fff; cursor:pointer; font-size:13px; color:#555; text-decoration:none; transition:.15s; }
    .pg-btn:hover { background:#f4f6fb; }
    .pg-btn.active { background:#c0392b; color:#fff; border-color:#c0392b; }
    .pg-btn.disabled { opacity:.4; pointer-events:none; }

    /* ── Empty State ── */
    .empty-state { text-align:center; padding:60px 20px; color:#aaa; }
    .empty-state i { font-size:40px; margin-bottom:14px; display:block; }
    .empty-state p { font-size:14px; }

    /* ── Modals ── */
    .u-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; align-items:center; justify-content:center; }
    .u-modal-overlay.open { display:flex; }
    .u-modal { background:#fff; border-radius:12px; width:520px; max-width:95vw; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.2); }
    .u-modal-header { padding:20px 24px 16px; border-bottom:1px solid #e5e9f0; display:flex; align-items:center; justify-content:space-between; }
    .u-modal-header h3 { font-size:16px; font-weight:700; margin:0; }
    .u-modal-body { padding:24px; display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .u-modal-body .full { grid-column:1/-1; }
    .form-group { display:flex; flex-direction:column; gap:6px; }
    .form-group label { font-size:12px; font-weight:600; color:#666; }
    .form-group input, .form-group select { padding:9px 12px; border:1px solid #d5d9e0; border-radius:7px; font-size:13px; outline:none; transition:.15s; }
    .form-group input:focus, .form-group select:focus { border-color:#c0392b; box-shadow:0 0 0 3px rgba(192,57,43,.1); }
    .u-modal-footer { padding:16px 24px; border-top:1px solid #e5e9f0; display:flex; justify-content:flex-end; gap:10px; }

    /* ── Confirm Modal ── */
    .confirm-body { padding:24px; text-align:center; }
    .confirm-body i { font-size:44px; color:#c0392b; margin-bottom:14px; display:block; }
    .confirm-body h4 { font-size:16px; font-weight:700; margin:8px 0 8px; }
    .confirm-body p { font-size:13px; color:#666; }
    .confirm-footer { padding:16px 24px; border-top:1px solid #e5e9f0; display:flex; justify-content:center; gap:10px; }
</style>

<div class="users-page">

    <div class="page-header">
        <h1><i class="fa fa-users" style="color:#c0392b"></i> Manage Users</h1>
        <button class="btn btn-primary" onclick="openModal('addUserModal')">
            <i class="fa fa-plus"></i> Add User
        </button>
    </div>

    <?php if (!empty($_GET['success'])): ?>
    <div class="u-alert u-alert-success">
        <i class="fa fa-circle-check"></i> <?= htmlspecialchars($_GET['success']) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($_GET['error'])): ?>
    <div class="u-alert u-alert-danger">
        <i class="fa fa-circle-xmark"></i> <?= htmlspecialchars($_GET['error']) ?>
    </div>
    <?php endif; ?>

    <!-- ── Filter Bar ── -->
    <form method="GET" action="users.php" id="filterForm">
        <div class="filter-bar">
            <div class="fg" style="flex:2">
                <label><i class="fa fa-magnifying-glass"></i> Search</label>
                <input type="text" name="search"
                       value="<?= htmlspecialchars($search) ?>"
                       placeholder="Search by name, email, employee ID, department…">
            </div>
            <div class="fg">
                <label><i class="fa fa-building"></i> Department</label>
                <select name="department">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>"
                            <?= $dept_filter === $d ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label><i class="fa fa-arrow-down-a-z"></i> Sort by Name</label>
                <select name="name_sort">
                    <option value=""     <?= $name_sort === ''     ? 'selected' : '' ?>>Default</option>
                    <option value="asc"  <?= $name_sort === 'asc'  ? 'selected' : '' ?>>A → Z</option>
                    <option value="desc" <?= $name_sort === 'desc' ? 'selected' : '' ?>>Z → A</option>
                </select>
            </div>
            <div class="fg-actions">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fa fa-magnifying-glass"></i> Search
                </button>
                <a href="users.php" class="btn btn-outline btn-sm">
                    <i class="fa fa-rotate-right"></i> Reset
                </a>
            </div>
        </div>
    </form>

    <!-- ── Tabs ── -->
    <div class="u-tabs">
        <button class="u-tab active">
            <i class="fa fa-users"></i> All Users
            <span class="u-tab-count"><?= $total_users ?></span>
        </button>
        <a href="recovery_requests.php" class="u-tab">
            <i class="fa fa-rotate-left"></i> Recovery Requests
            <?php if ($recoveryCount > 0): ?>
                <span class="u-tab-badge"><?= $recoveryCount ?></span>
            <?php endif; ?>
        </a>
    </div>

    <?php if ($search || $dept_filter || $name_sort): ?>
    <p style="font-size:12px;color:#888;margin-bottom:12px">
        <i class="fa fa-filter"></i>
        Showing <?= count($users) ?> of <?= $total_users ?> result<?= $total_users !== 1 ? 's' : '' ?>
        <?php if ($dept_filter): ?>
            in <strong><?= htmlspecialchars($dept_filter, ENT_QUOTES) ?></strong>
        <?php endif; ?>
        <?php if ($search): ?>
            matching <strong>"<?= htmlspecialchars($search) ?>"</strong>
        <?php endif; ?>
        <?php if ($name_sort): ?>
            · sorted <strong><?= $name_sort === 'asc' ? 'A→Z' : 'Z→A' ?></strong>
        <?php endif; ?>
        — <a href="users.php" style="color:#c0392b">Clear filters</a>
    </p>
    <?php endif; ?>

    <div class="u-card">
        <div class="table-wrap">
            <?php if (empty($users)): ?>
            <div class="empty-state">
                <i class="fa fa-users-slash"></i>
                <p>No users found matching your filters.</p>
            </div>
            <?php else: ?>
            <table class="u-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Department</th>
                        <th>Position</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td style="color:#888;font-size:12px">
                            <?= htmlspecialchars($u['employee_id'] ?? $u['id']) ?>
                        </td>
                        <td>
                            <div class="user-name"><?= htmlspecialchars($u['full_name']) ?></div>
                            <div class="user-email"><?= htmlspecialchars($u['email']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($u['role'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($u['department'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($u['position'] ?? '—') ?></td>
                        <td>
                            <?php $st = strtolower($u['status'] ?? 'active'); ?>
                            <span class="badge-status badge-<?= $st ?>">
                                <i class="fa fa-circle" style="font-size:7px"></i>
                                <?= strtoupper($st) ?>
                            </span>
                        </td>
                        <td style="color:#888;font-size:12px;white-space:nowrap">
                            <?= isset($u['created_at']) ? date('M d, Y', strtotime($u['created_at'])) : '—' ?>
                        </td>
                        <td style="white-space:nowrap">
                            <div class="action-wrap" id="aw-<?= $u['id'] ?>">
                                <button class="action-trigger"
                                        onclick="toggleActionMenu('aw-<?= $u['id'] ?>')">
                                    <i class="fa fa-ellipsis-vertical"></i> Actions
                                    <i class="fa fa-chevron-down"></i>
                                </button>
                                <div class="action-menu">
                                    <button onclick="closeAllMenus();viewUser(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)">
                                        <i class="fa fa-eye" style="color:#888;width:14px"></i> View Details
                                    </button>
                                    <button class="act-info" onclick="closeAllMenus();editUser(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)">
                                        <i class="fa fa-pen" style="width:14px"></i> Edit User
                                    </button>
                                    <div class="menu-divider"></div>
                                    <?php if ($st === 'active'): ?>
                                    <button class="act-warning"
                                            onclick="closeAllMenus();confirmDeactivate(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'])) ?>')">
                                        <i class="fa fa-ban" style="width:14px"></i> Deactivate
                                    </button>
                                    <?php else: ?>
                                    <form class="act-form" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" style="color:#27ae60">
                                            <i class="fa fa-check" style="width:14px"></i> Activate
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <div class="menu-divider"></div>
                                    <button class="act-danger"
                                            onclick="closeAllMenus();confirmDelete(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['full_name'])) ?>')">
                                        <i class="fa fa-trash" style="width:14px"></i> Delete User
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $pq = http_build_query(array_filter([
                'search'     => $search,
                'department' => $dept_filter,
                'name_sort'  => $name_sort,
            ]));
            $pq    = $pq ? '&' . $pq : '';
            $start = $offset + 1;
            $end   = min($offset + $per_page, $total_users);
            ?>
            <div class="u-pagination">
                <span class="pagination-info">
                    Showing <?= $start ?>–<?= $end ?> of <?= $total_users ?> users
                </span>
                <div class="pagination-btns">
                    <a href="?page=<?= $page - 1 . $pq ?>"
                       class="pg-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                        <i class="fa fa-chevron-left" style="font-size:11px"></i>
                    </a>
                    <?php
                    $range_start = max(1, $page - 2);
                    $range_end   = min($total_pages, $page + 2);
                    if ($range_start > 1) echo '<span style="padding:0 4px;color:#aaa">…</span>';
                    for ($p = $range_start; $p <= $range_end; $p++):
                    ?>
                        <a href="?page=<?= $p . $pq ?>"
                           class="pg-btn <?= $p === $page ? 'active' : '' ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor;
                    if ($range_end < $total_pages) echo '<span style="padding:0 4px;color:#aaa">…</span>';
                    ?>
                    <a href="?page=<?= $page + 1 . $pq ?>"
                       class="pg-btn <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <i class="fa fa-chevron-right" style="font-size:11px"></i>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /users-page -->


<!-- ══════════════════════════════════════════════
     MODALS
     ══════════════════════════════════════════════ -->

<!-- Add User -->
<div class="u-modal-overlay" id="addUserModal">
    <div class="u-modal">
        <div class="u-modal-header">
            <h3><i class="fa fa-user-plus" style="color:#c0392b"></i> Add New User</h3>
            <button class="btn-icon" onclick="closeModal('addUserModal')"><i class="fa fa-xmark"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="add_user">
            <div class="u-modal-body">
                <div class="form-group">
                    <label>Employee ID</label>
                    <input type="text" name="employee_id" required placeholder="e.g. KBM-IT-00999">
                </div>
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" required placeholder="Full name">
                </div>
                <div class="form-group full">
                    <label>Email Address</label>
                    <input type="email" name="email" required placeholder="user@kbmc.com">
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" required>
                        <option value="">Select role…</option>
                        <option value="employee">Employee</option>
                        <option value="it_staff">IT Staff</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Department</label>
                    <input type="text" name="department" required placeholder="e.g. QC/TECHNICAL">
                </div>
                <div class="form-group">
                    <label>Position</label>
                    <input type="text" name="position" placeholder="e.g. IT Employee">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required placeholder="Set initial password">
                </div>
                <div class="form-group full" style="background:#fff8f0;padding:12px;border-radius:8px;border:1px dashed #e67e22;">
                    <label style="color:#e67e22"><i class="fa fa-key"></i> Master Key</label>
                    <p style="font-size:12px;color:#888;margin-top:4px">
                        A unique master key will be <strong>auto-generated</strong> and visible to Super Admin on the dashboard.
                    </p>
                </div>
            </div>
            <div class="u-modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('addUserModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa fa-user-plus"></i> Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User -->
<div class="u-modal-overlay" id="editUserModal">
    <div class="u-modal">
        <div class="u-modal-header">
            <h3><i class="fa fa-pen" style="color:#2980b9"></i> Edit User</h3>
            <button class="btn-icon" onclick="closeModal('editUserModal')"><i class="fa fa-xmark"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="editUserId">
            <div class="u-modal-body">
                <div class="form-group">
                    <label>Employee ID</label>
                    <input type="text" id="editEmpId" disabled
                           style="background:#f8f9fc;color:#999;cursor:not-allowed">
                    <small style="font-size:11px;color:#aaa">Employee ID cannot be changed</small>
                </div>
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" id="editFullName" required placeholder="Full name">
                </div>
                <div class="form-group full">
                    <label>Email Address</label>
                    <input type="email" name="email" id="editEmail" required placeholder="user@kbmc.com">
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" id="editRole" required>
                        <option value="employee">Employee</option>
                        <option value="it_staff">IT Staff</option>
                        <option value="admin">Administrator</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Department</label>
                    <input type="text" name="department" id="editDepartment" required placeholder="e.g. QC/TECHNICAL">
                </div>
                <div class="form-group">
                    <label>Position</label>
                    <input type="text" name="position" id="editPosition" placeholder="e.g. IT Employee">
                </div>
            </div>
            <div class="u-modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editUserModal')">Cancel</button>
                <button type="submit" class="btn btn-info"><i class="fa fa-floppy-disk"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- View User -->
<div class="u-modal-overlay" id="viewUserModal">
    <div class="u-modal">
        <div class="u-modal-header">
            <h3><i class="fa fa-id-card" style="color:#c0392b"></i> User Details</h3>
            <button class="btn-icon" onclick="closeModal('viewUserModal')"><i class="fa fa-xmark"></i></button>
        </div>
        <div class="u-modal-body" id="viewUserBody" style="grid-template-columns:1fr 1fr">
            <!-- filled by JS -->
        </div>
        <div class="u-modal-footer">
            <button class="btn btn-outline" onclick="closeModal('viewUserModal')">Close</button>
        </div>
    </div>
</div>

<!-- Deactivate Confirm -->
<div class="u-modal-overlay" id="deactivateModal">
    <div class="u-modal" style="max-width:400px">
        <div class="confirm-body">
            <i class="fa fa-ban"></i>
            <h4>Deactivate User?</h4>
            <p id="deactivateMsg">The user will lose access but their data will be kept.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="deactivate">
            <input type="hidden" name="user_id" id="deactivateUserId">
            <div class="confirm-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('deactivateModal')">Cancel</button>
                <button type="submit" class="btn btn-warning"><i class="fa fa-ban"></i> Yes, Deactivate</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirm -->
<div class="u-modal-overlay" id="deleteModal">
    <div class="u-modal" style="max-width:400px">
        <div class="confirm-body">
            <i class="fa fa-triangle-exclamation"></i>
            <h4>Delete User?</h4>
            <p id="deleteMsg">This action cannot be undone.</p>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" id="deleteUserId">
            <div class="confirm-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn btn-danger"><i class="fa fa-trash"></i> Yes, Delete</button>
            </div>
        </form>
    </div>
</div>


<script>
function toggleActionMenu(id) {
    var wrap   = document.getElementById(id);
    var isOpen = wrap.classList.contains('open');
    closeAllMenus();
    if (!isOpen) wrap.classList.add('open');
}
function closeAllMenus() {
    document.querySelectorAll('.action-wrap.open').forEach(function(w){ w.classList.remove('open'); });
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.action-wrap')) closeAllMenus();
});

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.u-modal-overlay').forEach(function(o) {
    o.addEventListener('click', function(e){ if (e.target === o) o.classList.remove('open'); });
});

function confirmDeactivate(id, name) {
    document.getElementById('deactivateUserId').value = id;
    document.getElementById('deactivateMsg').textContent =
        'Deactivate "' + name + '"? They will lose access immediately.';
    openModal('deactivateModal');
}

function confirmDelete(id, name) {
    document.getElementById('deleteUserId').value = id;
    document.getElementById('deleteMsg').textContent =
        'Are you sure you want to delete "' + name + '"? This cannot be undone.';
    openModal('deleteModal');
}

function viewUser(u) {
    var b   = document.getElementById('viewUserBody');
    var row = function(label, val) {
        return '<div class="form-group">'
             + '<label>' + label + '</label>'
             + '<div style="padding:9px 12px;background:#f8f9fc;border-radius:7px;font-size:13px">'
             + (val || '—') + '</div></div>';
    };
    b.innerHTML =
        row('Employee ID', u.employee_id || u.id) +
        row('Full Name',   u.full_name) +
        row('Email',       u.email) +
        row('Role',        u.role) +
        row('Department',  u.department) +
        row('Position',    u.position) +
        row('Status',      u.status) +
        row('Joined',      u.created_at);
    openModal('viewUserModal');
}

function editUser(u) {
    document.getElementById('editUserId').value     = u.id;
    document.getElementById('editEmpId').value      = u.employee_id || u.id;
    document.getElementById('editFullName').value   = u.full_name   || '';
    document.getElementById('editEmail').value      = u.email       || '';
    document.getElementById('editDepartment').value = u.department  || '';
    document.getElementById('editPosition').value   = u.position    || '';
    var roleSelect = document.getElementById('editRole');
    for (var i = 0; i < roleSelect.options.length; i++) {
        roleSelect.options[i].selected = (roleSelect.options[i].value === u.role);
    }
    openModal('editUserModal');
}
</script>

</main>
</div>
</body>
</html>
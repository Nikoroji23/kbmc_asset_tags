    <?php
/**
 * KBMC Asset Management - Account Creation Records (Admin Only)
 * Tracks all employee account registrations
 */
$pageTitle = 'Account Creation Records';
require_once 'includes/header.php';

// Check admin access
if (!hasRole('admin')) {
    setFlashMessage('error', 'Access denied. Admin only.');
    header('Location: dashboard.php');
    exit();
}

// Handle deletion (soft delete - mark as inactive)
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $recordId = (int)$_GET['id'];
    try {
        $record = $pdo->query("SELECT user_id FROM account_creations WHERE id = $recordId")->fetch();
        if ($record) {
            // Deactivate the user account
            $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?")->execute([$record['user_id']]);
            logAudit($_SESSION['user_id'], 'Delete Account', 'account_creations', $recordId);
            setFlashMessage('success', 'Account has been deactivated.');
        }
    } catch (Exception $e) {
        setFlashMessage('error', 'Error deleting account: ' . $e->getMessage());
    }
    header('Location: admin_accounts.php');
    exit();
}

// Filter options
$search = $_GET['search'] ?? '';
$department = $_GET['department'] ?? '';
$sort = $_GET['sort'] ?? 'created_at';
$order = $_GET['order'] ?? 'DESC';

// Build query
$where = "WHERE 1=1";
$params = [];

if (!empty($search)) {
    $where .= " AND (ac.full_name LIKE ? OR ac.email LIKE ? OR ac.employee_id LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
}

if (!empty($department)) {
    $where .= " AND ac.department = ?";
    $params[] = $department;
}

// Validate sort field
$allowed_sorts = ['created_at', 'full_name', 'department', 'email'];
if (!in_array($sort, $allowed_sorts)) $sort = 'created_at';
if (!in_array(strtoupper($order), ['ASC', 'DESC'])) $order = 'DESC';

$query = "SELECT ac.*, u.status as user_status FROM account_creations ac 
          LEFT JOIN users u ON ac.user_id = u.id 
          $where 
          ORDER BY ac.$sort $order";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Get departments for filter dropdown
$departments = $pdo->query("SELECT DISTINCT department FROM account_creations WHERE department IS NOT NULL ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="page-header">
    <h1><i class="fas fa-user-check"></i> Account Creation Records</h1>
    <a href="users.php" class="btn btn-outline">Manage Users</a>
</div>

<div class="card">
    <div class="card-body">
        <!-- Search & Filter Section -->
        <form method="GET" style="margin-bottom: 20px;">
            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                <div style="flex: 1; min-width: 200px;">
                    <label for="search" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 5px;">Search</label>
                    <input type="text" name="search" id="search" class="form-control" 
                           placeholder="Name, Email, or Employee ID"
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div style="min-width: 180px;">
                    <label for="department" style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 5px;">Department</label>
                    <select name="department" id="department" class="form-control">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept; ?>" <?php echo $dept === $department ? 'selected' : ''; ?>>
                            <?php echo $dept; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Filter
                </button>
                <a href="admin_accounts.php" class="btn btn-outline">Clear</a>
            </div>
        </form>

        <?php if (empty($records)): ?>
        <div class="empty-state">
            <i class="fas fa-file-alt" style="font-size: 40px;"></i>
            <h4>No account creation records found</h4>
        </div>
        <?php else: ?>
        <!-- Records Table -->
        <div style="overflow-x: auto;">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>
                            <a href="?search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department); ?>&sort=full_name&order=<?php echo $sort === 'full_name' && $order === 'ASC' ? 'DESC' : 'ASC'; ?>" style="color: inherit; text-decoration: none;">
                                Name <?php if ($sort === 'full_name') echo $order === 'ASC' ? '↑' : '↓'; ?>
                            </a>
                        </th>
                        <th>Employee ID</th>
                        <th>Email</th>
                        <th>
                            <a href="?search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department); ?>&sort=department&order=<?php echo $sort === 'department' && $order === 'ASC' ? 'DESC' : 'ASC'; ?>" style="color: inherit; text-decoration: none;">
                                Department <?php if ($sort === 'department') echo $order === 'ASC' ? '↑' : '↓'; ?>
                            </a>
                        </th>
                        <th>Position</th>
                        <th>Phone</th>
                        <th>
                            <a href="?search=<?php echo urlencode($search); ?>&department=<?php echo urlencode($department); ?>&sort=created_at&order=<?php echo $sort === 'created_at' && $order === 'ASC' ? 'DESC' : 'ASC'; ?>" style="color: inherit; text-decoration: none;">
                                Registered <?php if ($sort === 'created_at') echo $order === 'ASC' ? '↑' : '↓'; ?>
                            </a>
                        </th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                    <tr>
                        <td><strong><?php echo sanitize($record['full_name']); ?></strong></td>
                        <td><?php echo sanitize($record['employee_id']); ?></td>
                        <td><?php echo sanitize($record['email']); ?></td>
                        <td><?php echo sanitize($record['department'] ?: '-'); ?></td>
                        <td><?php echo sanitize($record['position'] ?: '-'); ?></td>
                        <td><?php echo sanitize($record['phone'] ?: '-'); ?></td>
                        <td><?php echo date('M d, Y h:i A', strtotime($record['created_at'])); ?></td>
                        <td>
                            <?php 
                            $status = $record['user_status'] ?? 'active';
                            $statusColor = $status === 'active' ? '#27ae60' : '#e74c3c';
                            $statusLabel = $status === 'active' ? 'Active' : 'Inactive';
                            ?>
                            <span class="status-badge" style="background-color: <?php echo $statusColor; ?>20; color: <?php echo $statusColor; ?>; border: 1px solid <?php echo $statusColor; ?>;">
                                <?php echo $statusLabel; ?>
                            </span>
                        </td>
                        <td>
                            <a href="user_profile.php?id=<?php echo $record['user_id']; ?>" class="btn btn-sm btn-info" title="View Profile">
                                <i class="fas fa-eye"></i>
                            </a>
                            <?php if ($record['user_status'] === 'active'): ?>
                            <a href="?action=delete&id=<?php echo $record['id']; ?>" class="btn btn-sm btn-danger delete-confirm" title="Deactivate Account">
                                <i class="fas fa-times"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 20px; color: #666; font-size: 13px;">
            <p>Total Records: <strong><?php echo count($records); ?></strong></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

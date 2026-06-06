<?php
// Lightweight API to return user details + assigned devices as JSON
require_once __DIR__ . '/includes/functions.php';
// For AJAX endpoints, return JSON 403 instead of redirecting via requireITStaffOnly()
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
}
unset($a);

echo json_encode(['user' => $user, 'assets' => $assets], JSON_UNESCAPED_UNICODE);
exit;

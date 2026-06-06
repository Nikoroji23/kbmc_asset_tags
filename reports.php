<?php
/**
 * KBMC Asset Management - Reports & Analytics
 */
$pageTitle = 'Reports & Analytics';
require_once 'includes/header.php';

// Get stats
$totalDevices = getTotalDeviceCount();
$inStock = getDeviceCountByStatus('in_stock');
$deployed = getDeviceCountByStatus('deployed');
$underRepair = getDeviceCountByStatus('under_repair');
$retired = getDeviceCountByStatus('retired') + getDeviceCountByStatus('disposed');
$totalValue = $pdo->query("SELECT COALESCE(SUM(purchase_price), 0) FROM devices WHERE status NOT IN ('retired', 'disposed')")->fetchColumn();

// Status distribution
$statusDist = $pdo->query("SELECT status, COUNT(*) as count FROM devices GROUP BY status")->fetchAll();

// Type distribution
$typeDist = $pdo->query("SELECT dt.type_name, COUNT(d.id) as count FROM device_types dt LEFT JOIN devices d ON dt.id = d.device_type_id GROUP BY dt.id")->fetchAll();

// Monthly additions
$monthlyData = $pdo->query("SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count FROM devices GROUP BY month ORDER BY month DESC LIMIT 12")->fetchAll();

// Department distribution
$deptDist = $pdo->query("SELECT u.department, COUNT(da.id) as count FROM device_assignments da JOIN users u ON da.employee_id = u.id WHERE da.status = 'active' GROUP BY u.department ORDER BY count DESC")->fetchAll();
?>

<div class="page-header">
    <h1><i class="fas fa-chart-bar"></i> Reports & Analytics</h1>
    <div style="display: flex; gap: 10px;">
        <button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        <button class="btn btn-primary" onclick="exportAllCSV()"><i class="fas fa-download"></i> Export All CSV</button>
    </div>
</div>

<!-- Summary Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-laptop"></i></div>
        <div class="stat-info"><h3><?php echo $totalDevices; ?></h3><span>Total Devices</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-box"></i></div>
        <div class="stat-info"><h3><?php echo $inStock; ?></h3><span>In Stock</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-hand-holding"></i></div>
        <div class="stat-info"><h3><?php echo $deployed; ?></h3><span>Deployed</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-tools"></i></div>
        <div class="stat-info"><h3><?php echo $underRepair; ?></h3><span>Under Repair</span></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-peso-sign"></i></div>
        <div class="stat-info"><h3><?php echo number_format($totalValue, 0); ?></h3><span>Total Value (PHP)</span></div>
    </div>
</div>

<!-- Charts -->
<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Device Status Distribution</h3></div>
        <div class="card-body"><div class="chart-container"><canvas id="statusChart"></canvas></div></div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Devices by Type</h3></div>
        <div class="card-body"><div class="chart-container"><canvas id="typeChart"></canvas></div></div>
    </div>
</div>

<div class="grid-2">
    <div class="card">
        <div class="card-header"><h3>Devices by Department</h3></div>
        <div class="card-body"><div class="chart-container"><canvas id="deptChart"></canvas></div></div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Monthly Device Additions</h3></div>
        <div class="card-body"><div class="chart-container"><canvas id="monthlyChart"></canvas></div></div>
    </div>
</div>

<!-- Device Inventory Summary Table -->
<div class="card">
    <div class="card-header"><h3>Inventory Summary</h3></div>
    <div class="card-body">
        <div class="data-table-wrapper">
            <table class="data-table" id="summaryTable">
                <thead><tr><th>Device Type</th><th>Total</th><th>In Stock</th><th>Deployed</th><th>Under Repair</th><th>Retired</th><th>Est. Value</th></tr></thead>
                <tbody>
                    <?php
                    foreach ($typeDist as $td):
                        $typeStats = $pdo->query("SELECT status, COUNT(*) as c, SUM(purchase_price) as val FROM devices WHERE device_type_id IN (SELECT id FROM device_types WHERE type_name = '{$td['type_name']}') GROUP BY status")->fetchAll();
                        $tTotal = 0; $tStock = 0; $tDeploy = 0; $tRepair = 0; $tRetired = 0; $tValue = 0;
                        foreach ($typeStats as $ts) { $tTotal += $ts['c']; if ($ts['status'] == 'in_stock') $tStock = $ts['c']; if ($ts['status'] == 'deployed') $tDeploy = $ts['c']; if ($ts['status'] == 'under_repair') $tRepair = $ts['c']; if ($ts['status'] == 'retired' || $ts['status'] == 'disposed') $tRetired += $ts['c']; $tValue += $ts['val']; }
                    ?>
                    <tr>
                        <td><strong><?php echo sanitize($td['type_name']); ?></strong></td>
                        <td><?php echo $tTotal; ?></td>
                        <td style="color: #27AE60;"><?php echo $tStock; ?></td>
                        <td style="color: #3498DB;"><?php echo $tDeploy; ?></td>
                        <td style="color: #F39C12;"><?php echo $tRepair; ?></td>
                        <td style="color: #7F8C8D;"><?php echo $tRetired; ?></td>
                        <td><?php echo number_format($tValue, 2); ?> PHP</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: [<?php foreach ($statusDist as $s) echo "'" . ucwords(str_replace('_', ' ', $s['status'])) . "',"; ?>],
        datasets: [{ data: [<?php foreach ($statusDist as $s) echo $s['count'] . ','; ?>], backgroundColor: [<?php foreach ($statusDist as $s) echo "'" . ($status_colors[$s['status']] ?? '#666') . "',"; ?>], borderWidth: 2, borderColor: '#fff' }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
});

new Chart(document.getElementById('typeChart'), {
    type: 'bar',
    data: {
        labels: [<?php foreach ($typeDist as $t) echo "'" . sanitize($t['type_name']) . "',"; ?>],
        datasets: [{ label: 'Count', data: [<?php foreach ($typeDist as $t) echo $t['count'] . ','; ?>], backgroundColor: '#D9232E', borderRadius: 5 }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } }, x: { grid: { display: false } } } }
});

new Chart(document.getElementById('deptChart'), {
    type: 'bar',
    data: {
        labels: [<?php foreach ($deptDist as $d) echo "'" . sanitize($d['department']) . "',"; ?>],
        datasets: [{ label: 'Devices', data: [<?php foreach ($deptDist as $d) echo $d['count'] . ','; ?>], backgroundColor: '#3498DB', borderRadius: 5 }]
    },
    options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } } }
});

new Chart(document.getElementById('monthlyChart'), {
    type: 'line',
    data: {
        labels: [<?php foreach (array_reverse($monthlyData) as $m) echo "'" . $m['month'] . "',"; ?>],
        datasets: [{ label: 'Devices Added', data: [<?php foreach (array_reverse($monthlyData) as $m) echo $m['count'] . ','; ?>], borderColor: '#D9232E', backgroundColor: '#D9232E20', fill: true, tension: 0.4 }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
});

function exportAllCSV() {
    const rows = [];
    document.querySelectorAll('#summaryTable tbody tr').forEach(row => {
        const cells = row.querySelectorAll('td');
        rows.push([cells[0]?.textContent.trim(), cells[1]?.textContent.trim(), cells[2]?.textContent.trim(), cells[3]?.textContent.trim(), cells[4]?.textContent.trim(), cells[5]?.textContent.trim(), cells[6]?.textContent.trim()]);
    });
    exportToCSV('inventory_summary_<?php echo date('Y-m-d'); ?>.csv', ['Device Type', 'Total', 'In Stock', 'Deployed', 'Under Repair', 'Retired', 'Est. Value'], rows);
}
</script>

<?php require_once 'includes/footer.php'; ?>

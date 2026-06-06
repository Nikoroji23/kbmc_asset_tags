<?php
/**
 * KBMC Asset Management - All Devices
 */
$pageTitle = 'All Devices';
require_once 'includes/header.php';
requireITStaffOnly();
// Force-add columns if missing — runs before any SELECT
try {
    if (!columnExists('devices', 'ip_address')) {
        $pdo->exec("ALTER TABLE devices ADD COLUMN ip_address VARCHAR(50) DEFAULT NULL");
    }
    if (!columnExists('devices', 'pc_name')) {
        $pdo->exec("ALTER TABLE devices ADD COLUMN pc_name VARCHAR(100) DEFAULT NULL");
    }
} catch (PDOException $e) { /* ignore */ }

// Ensure pc_name and ip_address columns exist before any queries
ensureDeviceSchema();

$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';
$assetTagFilter = $_GET['asset_tag'] ?? '';
$pcNameFilter = $_GET['pc_name'] ?? '';
$ipAddressFilter = $_GET['ip_address'] ?? '';
$assignedToFilter = $_GET['assigned_to'] ?? '';
$search = $_GET['search'] ?? '';

$sql = "SELECT d.*, dt.type_name, u.id AS assigned_user_id, u.full_name as assigned_to, 
        (SELECT full_name FROM users WHERE id = da.assigned_by) as assigned_by_name,
        da.assigned_date, da.status as assignment_status, da.id as assignment_id
        FROM devices d 
        JOIN device_types dt ON d.device_type_id = dt.id 
        LEFT JOIN device_assignments da ON d.id = da.device_id AND da.status = 'active'
        LEFT JOIN users u ON da.employee_id = u.id
        WHERE d.status NOT IN ('retired', 'disposed')";
$params = [];

if ($status) { $sql .= " AND d.status = ?"; $params[] = $status; }
if ($type) { $sql .= " AND d.device_type_id = ?"; $params[] = $type; }
if ($assetTagFilter) { $sql .= " AND d.asset_tag LIKE ?"; $params[] = "%$assetTagFilter%"; }
if ($pcNameFilter) { $sql .= " AND d.pc_name LIKE ?"; $params[] = "%$pcNameFilter%"; }
if ($ipAddressFilter) { $sql .= " AND d.ip_address LIKE ?"; $params[] = "%$ipAddressFilter%"; }
if ($assignedToFilter) { $sql .= " AND u.full_name LIKE ?"; $params[] = "%$assignedToFilter%"; }
if ($search) { $sql .= " AND (d.asset_tag LIKE ? OR d.pc_name LIKE ? OR d.ip_address LIKE ? OR dt.type_name LIKE ? OR u.full_name LIKE ?)"; 
    $s = "%$search%"; $params = array_merge($params, [$s, $s, $s, $s, $s]); }

$sql .= " ORDER BY d.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$devices = $stmt->fetchAll();

$types = $pdo->query("SELECT * FROM device_types ORDER BY type_name")->fetchAll();
$assetTags = $pdo->query("SELECT DISTINCT asset_tag FROM devices WHERE status NOT IN ('retired', 'disposed') ORDER BY asset_tag")->fetchAll(PDO::FETCH_COLUMN);
$pcNames = $pdo->query("SELECT DISTINCT pc_name FROM devices WHERE status NOT IN ('retired', 'disposed') AND pc_name IS NOT NULL AND pc_name <> '' ORDER BY pc_name")->fetchAll(PDO::FETCH_COLUMN);
$ipAddresses = $pdo->query("SELECT DISTINCT ip_address FROM devices WHERE status NOT IN ('retired', 'disposed') AND ip_address IS NOT NULL AND ip_address <> '' ORDER BY ip_address")->fetchAll(PDO::FETCH_COLUMN);
$assignedUsers = $pdo->query("SELECT DISTINCT u.full_name FROM devices d LEFT JOIN device_assignments da ON d.id = da.device_id AND da.status = 'active' LEFT JOIN users u ON da.employee_id = u.id WHERE d.status NOT IN ('retired', 'disposed') AND u.full_name IS NOT NULL AND u.full_name <> '' ORDER BY u.full_name")->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
    <h1><i class="fas fa-laptop"></i> All Devices</h1>
    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
        <a href="import_assets.php" class="btn" style="background-color: #27ae60; color: white; padding: 8px 16px; border-radius: 4px; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;">
            <i class="fas fa-file-import"></i> Import Assets
        </a>
        <button class="btn btn-outline" onclick="exportDevicesCSV()">
            <i class="fas fa-file-csv"></i> Export CSV
        </button>
        <button class="btn btn-outline" onclick="exportDevicesPDF()">
            <i class="fas fa-file-pdf"></i> Export PDF
        </button>
        <a href="add_device.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Add Device
        </a>
    </div>
</div>

<form method="GET" id="deviceFilterForm">
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body">
        <div class="filter-row" style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
            <input type="text" name="search" placeholder="Search asset tag, PC name, IP, type or assigned..." value="<?php echo sanitize($search); ?>" style="flex: 1; min-width: 220px; padding: 10px 12px; border: 1px solid #d6d8db; border-radius: 8px;">
            <select name="type" class="header-filter-select" style="min-width: 170px;">
                <option value="">All Types</option>
                <?php foreach ($types as $t): ?>
                <option value="<?php echo $t['id']; ?>" <?php echo $type == $t['id'] ? 'selected' : ''; ?>><?php echo sanitize($t['type_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="header-filter-select" style="min-width: 170px;">
                <option value="">All Statuses</option>
                <option value="in_stock" <?php echo $status == 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                <option value="deployed" <?php echo $status == 'deployed' ? 'selected' : ''; ?>>Deployed</option>
                <option value="under_repair" <?php echo $status == 'under_repair' ? 'selected' : ''; ?>>Under Repair</option>
                <option value="retired" <?php echo $status == 'retired' ? 'selected' : ''; ?>>Retired</option>
                <option value="disposed" <?php echo $status == 'disposed' ? 'selected' : ''; ?>>Disposed</option>
                <option value="pending_inspection" <?php echo $status == 'pending_inspection' ? 'selected' : ''; ?>>Pending Inspection</option>
                <option value="rejected" <?php echo $status == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
            </select>
            <select name="assigned_to" class="header-filter-select" style="min-width: 180px;">
                <option value="">All Assignees</option>
                <?php foreach ($assignedUsers as $assignee): ?>
                <option value="<?php echo sanitize($assignee); ?>" <?php echo $assignedToFilter == $assignee ? 'selected' : ''; ?>><?php echo sanitize($assignee); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
            <a href="devices.php" class="btn btn-light btn-sm"><i class="fas fa-undo"></i> Reset</a>
        </div>
    </div>
</div>

<!-- Devices Table -->
<div class="card">
    <div class="card-body">
        <div class="data-table-wrapper">
            <table class="data-table" id="devicesTable">
                <thead>
                    <tr>
                        <th>Asset Tag</th>
                        <th>PC Name</th>
                        <th>Type</th>
                        <th>IP Address</th>
                        <th>Status</th>
                        <th>Assigned To</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($devices)): ?>
                    <tr>
                        <td colspan="7" class="empty-state" style="padding: 40px;">
                            <i class="fas fa-search" style="font-size: 40px; color: #ddd;"></i>
                            <h4 style="margin-top: 10px;">No devices found</h4>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($devices as $dev): ?>
                    <tr>
                        <td><strong><?php echo sanitize($dev['asset_tag'] ?: 'N/A'); ?></strong></td>
                        <td><?php echo sanitize($dev['pc_name'] ?: 'N/A'); ?></td>
                        <td><?php echo sanitize($dev['type_name']); ?></td>
                        <td><?php echo sanitize($dev['ip_address'] ?: 'N/A'); ?></td>
                        <td><?php echo getStatusBadge($dev['status']); ?></td>
                        <td>
                            <?php if ($dev['assigned_to']): ?>
                            <a href="it_user_details.php?id=<?php echo $dev['assigned_user_id']; ?>" class="btn btn-link" style="padding:0; margin:0; color:#007bff; display:flex; align-items:center; gap:6px; font-size: 0.95rem;">
                                <i class="fas fa-user" style="font-size: 11px; color: #999;"></i>
                                <?php echo sanitize($dev['assigned_to']); ?>
                            </a>
                            <?php else: ?>
                            <span style="color: #999; font-size: 12px;">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-btns">
                                <a href="view_device.php?id=<?php echo $dev['id']; ?>" class="action-btn view" title="View Details"><i class="fas fa-eye"></i></a>
                                <a href="edit_device.php?id=<?php echo $dev['id']; ?>" class="action-btn edit" title="Edit"><i class="fas fa-edit"></i></a>
                                <?php if ($dev['status'] == 'in_stock'): ?>
                                <a href="deployments.php?action=assign&device=<?php echo $dev['id']; ?>" class="action-btn assign" title="Assign"><i class="fas fa-hand-holding"></i></a>
                                <?php endif; ?>
                                <a href="delete_device.php?id=<?php echo $dev['id']; ?>" class="action-btn delete delete-confirm" title="Delete"><i class="fas fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</form>

<!-- Assigned User Details Modal -->
<div id="assignedUserModal" class="modal-overlay" style="display:none;">
    <div class="modal-box" style="max-width: 960px; width: 95%;">
        <div class="modal-header" style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
            <h3><i class="fas fa-id-card"></i> Employee Details</h3>
            <div style="display:flex;gap:8px;align-items:center;">
                <button type="button" class="btn btn-outline no-print" onclick="exportAssignedUserPDF()">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </button>
                <button type="button" class="btn btn-outline no-print" onclick="printAssignedUserDetails()">
                    <i class="fas fa-print"></i> Print
                </button>
                <button type="button" class="modal-close btn btn-outline no-print" onclick="closeAssignedUserModal()">&times;</button>
            </div>
        </div>
        <div class="modal-body" id="assignedUserBody" style="padding:20px;">
            <p style="text-align:center;color:#999;padding:30px;">
                <i class="fas fa-spinner fa-spin"></i> Loading...
            </p>
        </div>
    </div>
</div>

<script>
// Delete confirmation handler
document.addEventListener('DOMContentLoaded', function() {
    var deleteLinks = document.querySelectorAll('a.delete-confirm');
    deleteLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to dispose this device? This action will move it to the Retired/Disposed section and cannot be undone.')) {
                e.preventDefault();
                return false;
            }
        });
    });
});

function closeAssignedUserModal() {
    document.getElementById('assignedUserModal').style.display = 'none';
}

function printAssignedUserDetails() {
    var content = document.getElementById('assignedUserBody').innerHTML;
    var w = window.open('', '', 'width=1100,height=850');
    w.document.write('<!DOCTYPE html><html><head><title>Employee Details — KBMC</title><style>'
        + 'body{font-family:Arial,sans-serif;padding:28px;color:#222;font-size:13px;}'
        + 'h1{font-size:20px;margin:0 0 4px;} .sub{color:#888;font-size:12px;margin-bottom:20px;}'
        + '.info-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px;'
        +   'background:#f8f9fc;padding:16px;border-radius:8px;border:1px solid #e5e9f0;}'
        + '.info-cell .lbl{font-size:10px;font-weight:700;color:#999;text-transform:uppercase;'
        +   'letter-spacing:.5px;margin-bottom:3px;}'
        + '.info-cell .val{font-size:13px;color:#1a1a1a;font-weight:600;}'
        + '.highlight{color:#2980b9;}'
        + 'table{width:100%;border-collapse:collapse;margin-top:8px;}'
        + 'th{background:#c0392b;color:#fff;padding:9px 11px;font-size:11px;text-align:left;}'
        + 'td{padding:9px 11px;border-bottom:1px solid #eee;font-size:12px;}'
        + 'tr:nth-child(even) td{background:#f8f9fc;}'
        + '.badge{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10px;font-weight:700;}'
        + '@media print{body{padding:16px;}}'
        + '</style></head><body>'
        + '<h1><img src="" style="display:none"> KBMC Asset Management</h1>'
        + '<p class="sub">Employee Device Report &nbsp;|&nbsp; Printed: ' + new Date().toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'}) + '</p>'
        + content
        + '</body></html>');
    w.document.close();
    w.focus();
    setTimeout(function(){ w.print(); }, 400);
}

function exportAssignedUserPDF() {
    var data = window._currentAssignedUserData;
    if (!data || !data.user) {
        alert('No employee data available to export.');
        return;
    }
    var user = data.user;
    var assets = data.assets || [];

    _loadJsPDF(function() {
        var jsPDF = window.jspdf.jsPDF;
        var doc = new jsPDF({orientation:'portrait', unit:'pt', format:'a4'});
        var margin = 36;
        var y = 36;

        doc.setFontSize(16); doc.setTextColor(34,34,34); doc.setFont(undefined,'bold');
        doc.text('KBMC Asset Management', margin, y);
        doc.setFontSize(11); doc.setFont(undefined,'normal');
        y += 20;
        doc.text('Employee Device Report', margin, y);
        y += 18;
        var printedAt = new Date().toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'});
        doc.setFontSize(9); doc.setTextColor(120,120,120);
        doc.text('Generated: ' + printedAt, margin, y);
        y += 18;

        // User summary box
        doc.setDrawColor(230,233,240);
        doc.setFillColor(248,249,252);
        doc.rect(margin, y, doc.internal.pageSize.getWidth() - margin*2, 62, 'F');
        var ux = margin + 10;
        var uy = y + 16;
        doc.setFontSize(10); doc.setTextColor(80,80,80);
        doc.text('Employee ID: ' + (user.employee_id || user.id || 'N/A'), ux, uy);
        doc.text('Full Name: ' + (user.full_name || 'N/A'), ux + 220, uy);
        uy += 14;
        doc.text('Email: ' + (user.email || 'N/A'), ux, uy);
        doc.text('Department: ' + (user.department || 'N/A'), ux + 220, uy);
        uy += 14;
        doc.text('Position: ' + (user.position || 'N/A'), ux, uy);
        doc.text('Status: ' + (user.status || 'N/A'), ux + 220, uy);
        y += 82;

        // Assets table
        var rows = assets.map(function(a) {
            return [a.asset_tag || 'N/A', a.pc_name || 'N/A', a.ip_address || 'N/A', a.category || 'N/A', a.status || 'N/A', a.assigned_at || 'N/A'];
        });

        if (rows.length === 0) {
            doc.setFontSize(12); doc.setTextColor(120,120,120);
            doc.text('No devices currently assigned to this employee.', margin, y + 10);
        } else {
            doc.autoTable({
                head: [['Asset Tag','PC Name','IP Address','Type','Status','Assigned Date']],
                body: rows,
                startY: y,
                styles:{fontSize:9, cellPadding:6},
                headStyles:{fillColor:[192,57,43], textColor:255, fontStyle:'bold'},
                alternateRowStyles:{fillColor:[248,249,252]},
                margin:{left:margin,right:margin}
            });
        }

        var safeName = (user.full_name || 'employee').replace(/[^a-z0-9\-_]/ig, '_');
        doc.save('employee_' + safeName + '_devices_<?php echo date('Y-m-d'); ?>.pdf');
    });
}

function _esc(s) {
    if (s == null || s === '') return '<span style="color:#bbb">N/A</span>';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function _badge(s) {
    var map = {
        deployed:['#cce5ff','#004085'], in_stock:['#d4edda','#155724'],
        under_repair:['#fff3cd','#856404'], retired:['#e2e3e5','#383d41'],
        disposed:['#f8d7da','#721c24'], pending_inspection:['#fce8b2','#7d4a00'],
        rejected:['#f8d7da','#721c24'], active:['#d4edda','#155724'],
        inactive:['#f8d7da','#721c24']
    };
    var key = (s||'').toLowerCase().replace(/ /g,'_');
    var c = map[key] || ['#e2e3e5','#383d41'];
    var label = (s||'').replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase();});
    return '<span class="badge" style="background:'+c[0]+';color:'+c[1]+';">'+label+'</span>';
}

function setupAssignedUserButtons() {
    document.querySelectorAll('.view-assignee-btn').forEach(function(btn) {
        var fresh = btn.cloneNode(true);
        btn.parentNode.replaceChild(fresh, btn);
        fresh.addEventListener('click', function() {
            var userId = this.dataset.userId;
            var body = document.getElementById('assignedUserBody');
            body.innerHTML = '<div style="text-align:center;padding:40px;color:#999;">'
                + '<i class="fas fa-spinner fa-spin" style="font-size:22px;"></i>'
                + '<p style="margin-top:10px;font-size:13px;">Loading employee details…</p></div>';
            document.getElementById('assignedUserModal').style.display = 'flex';

            fetch('users.php?view_user=' + encodeURIComponent(userId) + '&ajax=1', {
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
            .then(function(res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function(data) {
                if (data.error) throw new Error(data.error);
                 window._currentAssignedUserData = data; // store for printing/export
                 renderUserModal(body, data.user, data.assets || []);
            })
            .catch(function(err) {
                body.innerHTML = '<div style="text-align:center;padding:40px;color:#e74c3c;">'
                    + '<i class="fas fa-exclamation-circle" style="font-size:28px;"></i>'
                    + '<p style="margin-top:10px;font-size:13px;">Failed to load details.<br>'
                    + '<small style="color:#aaa">' + err.message + '</small></p></div>';
            });
        });
    });
}

function renderUserModal(body, u, assets) {
    var primaryIP = 'N/A', primaryPC = 'N/A';
    for (var i = 0; i < assets.length; i++) {
        if (primaryIP === 'N/A' && assets[i].ip_address && assets[i].ip_address !== 'N/A') primaryIP = assets[i].ip_address;
        if (primaryPC === 'N/A' && assets[i].pc_name    && assets[i].pc_name    !== 'N/A') primaryPC = assets[i].pc_name;
    }

    var stColor  = (u.status||'').toLowerCase() === 'active' ? '#27ae60' : '#e74c3c';
    var stLabel  = u.status ? u.status.charAt(0).toUpperCase() + u.status.slice(1) : 'N/A';
    var joined   = u.created_at ? new Date(u.created_at).toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'}) : 'N/A';

    function cell(lbl, val, highlight) {
        return '<div class="info-cell"><div class="lbl">'+lbl+'</div>'
             + '<div class="val' + (highlight ? ' highlight' : '') + '">'+val+'</div></div>';
    }

    var grid = '<div class="info-grid">'
        + cell('Employee ID',  _esc(u.employee_id || u.id))
        + cell('Full Name',    '<strong>'+_esc(u.full_name)+'</strong>')
        + cell('Email',        _esc(u.email))
        + cell('Department',   _esc(u.department))
        + cell('Position',     _esc(u.position))
        + cell('Status',       '<span style="font-weight:700;color:'+stColor+';">● '+stLabel+'</span>')
        + cell('PC Name',      '<strong>'+_esc(primaryPC)+'</strong>', true)
        + cell('IP Address',   '<strong>'+_esc(primaryIP)+'</strong>', true)
        + cell('Joined',       joined)
        + '</div>';

    var table = '';
    if (assets.length === 0) {
        table = '<div style="text-align:center;padding:30px;color:#bbb;">'
              + '<i class="fas fa-box-open" style="font-size:32px;display:block;margin-bottom:8px;"></i>'
              + '<p style="font-size:13px;">No devices currently assigned.</p></div>';
    } else {
        var TH = 'style="padding:9px 12px;background:#c0392b;color:#fff;font-size:11px;'
               + 'font-weight:700;text-transform:uppercase;letter-spacing:.5px;text-align:left;"';
        var rows = assets.map(function(a) {
            return '<tr>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f0f2f8;">'
                +   '<strong>'+_esc(a.asset_tag || 'N/A')+'</strong></td>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f0f2f8;color:#2980b9;font-weight:600;">'
                +   _esc(a.pc_name)+'</td>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f0f2f8;color:#2980b9;font-weight:600;">'
                +   _esc(a.ip_address)+'</td>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f0f2f8;">'+_esc(a.category)+'</td>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f0f2f8;">'+_badge(a.status)+'</td>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f0f2f8;color:#888;font-size:12px;">'
                +   _esc(a.assigned_at)+'</td>'
                + '</tr>';
        }).join('');

        table = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">'
              + '<i class="fas fa-laptop" style="color:#c0392b;font-size:13px;"></i>'
              + '<strong style="font-size:14px;">Assigned Devices</strong>'
              + '<span style="background:#e5e9f0;color:#555;border-radius:20px;padding:2px 9px;font-size:11px;">'+assets.length+'</span>'
              + '</div>'
              + '<div style="overflow-x:auto;border-radius:8px;border:1px solid #e5e9f0;">'
              + '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
              + '<thead><tr>'
              + '<th '+TH+'>Asset Tag</th>'
              + '<th '+TH+'>PC Name</th>'
              + '<th '+TH+'>IP Address</th>'
              + '<th '+TH+'>Type</th>'
              + '<th '+TH+'>Status</th>'
              + '<th '+TH+'>Assigned Date</th>'
              + '</tr></thead><tbody>'+rows+'</tbody></table></div>';
    }

    body.innerHTML = '<style>'
        + '.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;'
        +   'margin-bottom:20px;background:#f8f9fc;padding:16px;border-radius:10px;border:1px solid #e5e9f0;}'
        + '.info-cell .lbl{font-size:10px;font-weight:700;color:#999;text-transform:uppercase;'
        +   'letter-spacing:.5px;margin-bottom:3px;}'
        + '.info-cell .val{font-size:13px;color:#1a1a1a;font-weight:500;word-break:break-word;}'
        + '.info-cell .val.highlight{color:#2980b9;}'
        + '</style>'
        + grid + table;
}

function exportDevicesCSV() {
    var headers = ['Asset Tag','PC Name','Type','IP Address','Status','Assigned To'];
    var rows = [];
    document.querySelectorAll('#devicesTable tbody tr').forEach(function(row) {
        var cells = row.querySelectorAll('td');
        if (cells.length <= 1) return;
        rows.push([
            cells[0].textContent.trim(),
            cells[1].textContent.trim(),
            cells[2].textContent.trim(),
            cells[3].textContent.trim(),
            cells[4].textContent.trim(),
            cells[5].textContent.trim()
        ]);
    });
    var csv = [headers].concat(rows).map(function(r) {
        return r.map(function(c) {
            var v = String(c).replace(/"/g,'""');
            return /[,"\n\r]/.test(v) ? '"'+v+'"' : v;
        }).join(',');
    }).join('\r\n');

    var blob = new Blob(['\uFEFF'+csv], {type:'text/csv;charset=utf-8;'});
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href = url;
    a.download = 'devices_<?php echo date('Y-m-d'); ?>.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function exportDevicesPDF() {
    var rows = [];
    document.querySelectorAll('#devicesTable tbody tr').forEach(function(row) {
        var cells = row.querySelectorAll('td');
        if (cells.length <= 1) return;
        rows.push([
            cells[0].textContent.trim(),
            cells[1].textContent.trim(),
            cells[2].textContent.trim(),
            cells[3].textContent.trim(),
            cells[4].textContent.trim(),
            cells[5].textContent.trim()
        ]);
    });
    _loadJsPDF(function() {
        var jsPDF = window.jspdf.jsPDF;
        var doc   = new jsPDF({orientation:'landscape', unit:'pt', format:'a4'});
        var pw    = doc.internal.pageSize.getWidth();

        doc.setFillColor(192,57,43);
        doc.rect(0,0,pw,48,'F');
        doc.setFontSize(16); doc.setTextColor(255,255,255); doc.setFont(undefined,'bold');
        doc.text('KBMC Asset Management', 36, 28);
        doc.setFontSize(10); doc.setFont(undefined,'normal');
        doc.text('Device Inventory Report', 36, 42);

        doc.setFontSize(9); doc.setTextColor(120,120,120);
        doc.text('Generated: <?php echo date('F d, Y'); ?>   |   Total records: ' + rows.length, 36, 62);

        doc.autoTable({
            head:[['Asset Tag','PC Name','Type','IP Address','Status','Assigned To']],
            body: rows,
            startY: 72,
            styles:{fontSize:9, cellPadding:7, lineColor:[230,233,240], lineWidth:0.5},
            headStyles:{fillColor:[192,57,43], textColor:255, fontStyle:'bold', halign:'left'},
            alternateRowStyles:{fillColor:[248,249,252]},
            columnStyles:{
                0:{cellWidth:90, fontStyle:'bold'},
                1:{cellWidth:105},
                2:{cellWidth:75},
                3:{cellWidth:95},
                4:{cellWidth:85},
                5:{cellWidth:'auto'}
            },
            margin:{left:36,right:36},
            didDrawPage: function(d) {
                var pg  = doc.internal.getCurrentPageInfo().pageNumber;
                var tot = doc.internal.getNumberOfPages();
                doc.setFontSize(8); doc.setTextColor(160,160,160);
                doc.text('KBMC Asset Management  |  Page '+pg+' of '+tot,
                    pw/2, doc.internal.pageSize.getHeight()-14, {align:'center'});
            }
        });
        doc.save('devices_report_<?php echo date('Y-m-d'); ?>.pdf');
    });
}

function _loadJsPDF(cb) {
    if (window.jspdf && window.jspdf.jsPDF) { cb(); return; }
    var s1 = document.createElement('script');
    s1.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
    s1.onload = function() {
        var s2 = document.createElement('script');
        s2.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js';
        s2.onload = cb;
        s2.onerror = function(){ alert('Could not load PDF library. Check internet connection.'); };
        document.head.appendChild(s2);
    };
    s1.onerror = function(){ alert('Could not load PDF library. Check internet connection.'); };
    document.head.appendChild(s1);
}

function enableDeviceFilterEnter() {
    var filterForm = document.getElementById('deviceFilterForm');
    if (!filterForm) return;
    filterForm.addEventListener('keydown', function(event) {
        if (event.key === 'Enter') {
            var target = event.target;
            if (target && target.tagName === 'INPUT' && target.name === 'search') {
                event.preventDefault();
                filterForm.submit();
            }
        }
    });
}

window.addEventListener('DOMContentLoaded', function() {
    setupAssignedUserButtons();
    enableDeviceFilterEnter();
});
</script>

<?php require_once 'includes/footer.php'; ?>
<?php

// DEBUG: Log every redirect attempt
register_shutdown_function(function() {
    $headers = headers_list();
    foreach ($headers as $h) {
        if (stripos($h, 'Location:') !== false) {
            error_log("REDIRECT DETECTED: $h | URI=" . ($_SERVER['REQUEST_URI'] ?? 'none'));
        }
    }
});
$pageTitle = 'Device Lifespan Forecast';
require_once 'includes/functions.php';
requireITStaffOnly();

// ============================================================
// SCHEMA BOOTSTRAP  (runs once; safe to run every page load)
// ============================================================
$pdo->exec("
    ALTER TABLE devices
        ADD COLUMN IF NOT EXISTS expected_lifespan_years TINYINT UNSIGNED DEFAULT NULL;
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS device_type_lifespans (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        device_type_id   INT NOT NULL UNIQUE,
        default_years    TINYINT UNSIGNED NOT NULL DEFAULT 5,
        created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (device_type_id) REFERENCES device_types(id) ON DELETE CASCADE
    );
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS device_lifespan_forecast (
        id                      INT AUTO_INCREMENT PRIMARY KEY,
        device_id               INT NOT NULL UNIQUE,
        reviewed_by             INT DEFAULT NULL,
        last_reviewed_date      DATE DEFAULT NULL,
        override_lifespan_years TINYINT UNSIGNED DEFAULT NULL,
        forecast_status         ENUM('good','monitor','replace_soon','overdue','replaced','extended')
                                NOT NULL DEFAULT 'good',
        remarks                 TEXT DEFAULT NULL,
        created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (device_id)   REFERENCES devices(id) ON DELETE CASCADE,
        FOREIGN KEY (reviewed_by) REFERENCES users(id)   ON DELETE SET NULL
    );
");

// Seed type defaults if table is empty
$seedCount = $pdo->query("SELECT COUNT(*) FROM device_type_lifespans")->fetchColumn();
if ($seedCount == 0) {
    $defaults = [
        ['Laptop',            5],
        ['Desktop',           6],
        ['Printer',           6],
        ['Tablet',            4],
        ['Monitor',           7],
        ['Network Equipment', 8],
        ['Peripherals',       3],
        ['Server',           10],
        ['Phone',             3],
        ['Other',             5],
    ];
    $typeRows = $pdo->query("SELECT id, type_name FROM device_types ORDER BY id")->fetchAll();
    $nameMap  = [];
    foreach ($typeRows as $t) $nameMap[$t['type_name']] = $t['id'];
    $ins = $pdo->prepare("INSERT IGNORE INTO device_type_lifespans (device_type_id, default_years) VALUES (?,?)");
    foreach ($defaults as [$name, $yrs]) {
        if (isset($nameMap[$name])) $ins->execute([$nameMap[$name], $yrs]);
    }
}

// ============================================================
// AUTO STATUS SYNC
// Runs once per session every 6 hours.
// Recalculates forecast_status based on EOL date for ALL devices
// EXCEPT those manually set to 'extended' or 'replaced'.
// Also fires notifications when status worsens.
// ============================================================
if (empty($_SESSION['last_lifespan_sync']) || (time() - $_SESSION['last_lifespan_sync']) > 21600) {

    $autoRows = $pdo->query("
        SELECT
            d.id,
            d.asset_tag,
            d.purchase_date,
            COALESCE(dlf.override_lifespan_years, d.expected_lifespan_years, dtl.default_years, 5) AS lifespan_years,
            dlf.forecast_status   AS current_status,
            dlf.id                AS forecast_row_id
        FROM devices d
        LEFT JOIN device_type_lifespans dtl      ON d.device_type_id = dtl.device_type_id
        LEFT JOIN device_lifespan_forecast dlf   ON d.id = dlf.device_id
    ")->fetchAll();

    $today = new DateTime('today');

    $upsert = $pdo->prepare("
        INSERT INTO device_lifespan_forecast (device_id, forecast_status, last_reviewed_date)
        VALUES (?, ?, CURDATE())
        ON DUPLICATE KEY UPDATE
            forecast_status    = VALUES(forecast_status),
            last_reviewed_date = CURDATE(),
            updated_at         = NOW()
    ");

    // Status severity order — used to detect "worsening"
    $severity = ['good' => 0, 'monitor' => 1, 'replace_soon' => 2, 'overdue' => 3];

    foreach ($autoRows as $row) {
        // Never auto-override manually set statuses
        if (in_array($row['current_status'], ['extended', 'replaced'])) continue;

        if (!$row['purchase_date']) continue;

        $purchase     = new DateTime($row['purchase_date']);
        $eol          = (clone $purchase)->modify("+{$row['lifespan_years']} years");
        $diff         = $today->diff($eol);
        $daysLeft     = (int)$diff->days * ($eol >= $today ? 1 : -1);

        // Calculate new status — thresholds match the legend:
        //   Good        = more than 12 months left
        //   Monitor     = 6–12 months left
        //   Replace Soon= 0–6 months left
        //   Overdue     = past EOL
        if ($daysLeft < 0) {
            $newStatus = 'overdue';
        } elseif ($daysLeft <= 182) {   // 0–6 months  → Replace Soon
            $newStatus = 'replace_soon';
        } elseif ($daysLeft <= 365) {   // 6–12 months → Monitor
            $newStatus = 'monitor';
        } else {                        // > 12 months → Good
            $newStatus = 'good';
        }

        // Only write to DB if status changed
        if ($newStatus === $row['current_status']) continue;

        $upsert->execute([$row['id'], $newStatus]);

        // Fire notification only when status WORSENS (not when it recovers to 'good')
        $oldSev = $severity[$row['current_status']] ?? -1;
        $newSev = $severity[$newStatus]             ?? -1;

        if ($newSev > $oldSev && $newStatus !== 'good') {
            $labelMap = [
                'monitor'      => 'Monitor',
                'replace_soon' => 'Replace Soon',
                'overdue'      => 'Overdue',
            ];
            $statusLabel = $labelMap[$newStatus];
            $message = "Device {$row['asset_tag']} lifespan forecast has been automatically updated to '{$statusLabel}'.";
            notifyITStaff('lifespan_' . $newStatus, "Device Lifespan: {$statusLabel}", $message, $row['id']);
        }
    }

    $_SESSION['last_lifespan_sync'] = time();
}

// ============================================================
// HANDLE AJAX SAVE (inline edit)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_save'])) {
    header('Content-Type: application/json');

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($csrfToken)) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid token.']);
        exit;
    }

    $deviceId       = (int)($_POST['device_id']       ?? 0);
    $status         = $_POST['forecast_status']        ?? 'good';
    $remarks        = trim($_POST['remarks']           ?? '');
    $overrideYears  = $_POST['override_years'] !== '' ? (int)$_POST['override_years'] : null;
    $allowedStatuses = ['good','monitor','replace_soon','overdue','replaced','extended'];

    if ($deviceId <= 0 || !in_array($status, $allowedStatuses)) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid data.']);
        exit;
    }

    // Fetch existing row so we can detect what changed
    $oldRowStmt = $pdo->prepare("
        SELECT dlf.forecast_status, dlf.override_lifespan_years,
               d.purchase_date,
               COALESCE(dlf.override_lifespan_years, d.expected_lifespan_years, dtl.default_years, 5) AS current_lifespan
        FROM devices d
        LEFT JOIN device_lifespan_forecast dlf ON d.id = dlf.device_id
        LEFT JOIN device_type_lifespans dtl    ON d.device_type_id = dtl.device_type_id
        WHERE d.id = ?
    ");
    $oldRowStmt->execute([$deviceId]);
    $oldRow    = $oldRowStmt->fetch(PDO::FETCH_ASSOC);
    $oldStatus = $oldRow['forecast_status'] ?? null;

    // If override years changed (or status is NOT manually protected),
    // recalculate the correct auto-status so the DB stays truthful.
    // Exception: if IT explicitly chose 'extended' or 'replaced', honour that choice.
    $manuallyProtected = in_array($status, ['extended', 'replaced']);

    if (!$manuallyProtected) {
        // Determine effective lifespan after this save
        $effectiveYears = $overrideYears
            ?? ($oldRow['current_lifespan'] ?? 5);

        $purchaseDate = $oldRow['purchase_date'] ?? null;

        if ($purchaseDate) {
            $todayDt  = new DateTime('today');
            $eolDt    = (new DateTime($purchaseDate))->modify("+{$effectiveYears} years");
            $diffDays = (int)(new DateTime('today'))->diff($eolDt)->days
                        * ($eolDt >= $todayDt ? 1 : -1);

            if ($diffDays < 0) {
                $status = 'overdue';
            } elseif ($diffDays <= 182) {
                $status = 'replace_soon';
            } elseif ($diffDays <= 365) {
                $status = 'monitor';
            } else {
                $status = 'good';
            }
        }
    }

    // Clear the session throttle so the full sync re-runs on next page load
    unset($_SESSION['last_lifespan_sync']);

    $stmt = $pdo->prepare("
        INSERT INTO device_lifespan_forecast
            (device_id, reviewed_by, last_reviewed_date, override_lifespan_years, forecast_status, remarks)
        VALUES (?, ?, CURDATE(), ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            reviewed_by             = VALUES(reviewed_by),
            last_reviewed_date      = CURDATE(),
            override_lifespan_years = VALUES(override_lifespan_years),
            forecast_status         = VALUES(forecast_status),
            remarks                 = VALUES(remarks),
            updated_at              = NOW()
    ");
    $stmt->execute([$deviceId, $_SESSION['user_id'], $overrideYears, $status, $remarks]);

    logAudit($_SESSION['user_id'], 'Lifespan Update', 'device_lifespan_forecast', $deviceId, null, "Status: $status");

    if ($oldStatus !== $status) {
        $labelMap = [
            'monitor'      => 'Monitor',
            'replace_soon' => 'Replace Soon',
            'overdue'      => 'Overdue',
            'replaced'     => 'Replaced',
            'extended'     => 'Extended',
            'good'         => 'Good',
        ];
        $statusLabel = $labelMap[$status] ?? ucfirst(str_replace('_', ' ', $status));
        $asset = $pdo->prepare("SELECT asset_tag FROM devices WHERE id = ?");
        $asset->execute([$deviceId]);
        $assetTag = $asset->fetchColumn() ?: 'device';
        $message = "Device {$assetTag} lifespan forecast has been updated to '{$statusLabel}'.";

        if ($status !== 'good') {
            notifyITStaff('lifespan_' . $status, "Device Lifespan: {$statusLabel}", $message, $deviceId);
        }
    }

    echo json_encode(['ok' => true, 'msg' => 'Saved successfully.']);
    exit;
}

// ============================================================
// FILTERS
// ============================================================
$search          = trim($_GET['search']          ?? '');
$typeFilter      = $_GET['type']                 ?? '';
$deptFilter      = trim($_GET['department']      ?? '');
$forecastFilter  = $_GET['forecast_status']      ?? '';
$deviceIdFilter  = (int)($_GET['device_id']      ?? 0);
$sortBy          = $_GET['sort']                 ?? 'eol_asc';

$types = $pdo->query("SELECT * FROM device_types ORDER BY type_name")->fetchAll();

// ============================================================
// MAIN QUERY
// ============================================================
$sql = "
    SELECT
        d.id,
        d.asset_tag,
        d.vendor,
        d.device_type_id,
        dt.type_name,
        d.purchase_date,
        d.status          AS device_status,
        d.expected_lifespan_years,
        d.serial_number,
        COALESCE(u.department, d.location, 'Unassigned')   AS department,
        u.full_name                                         AS assigned_to,
        da.assigned_date,
        COALESCE(
            dlf.override_lifespan_years,
            d.expected_lifespan_years,
            dtl.default_years,
            5
        )                                                   AS lifespan_years,
        dlf.forecast_status,
        dlf.remarks,
        dlf.last_reviewed_date,
        dlf.reviewed_by,
        ru.full_name                                        AS reviewed_by_name,
        dtl.default_years                                   AS type_default_years
    FROM devices d
    JOIN  device_types              dt  ON d.device_type_id  = dt.id
    LEFT  JOIN device_type_lifespans    dtl ON dt.id = dtl.device_type_id
    LEFT  JOIN device_assignments       da  ON d.id = da.device_id AND da.status = 'active'
    LEFT  JOIN users                    u   ON da.employee_id = u.id
    LEFT  JOIN device_lifespan_forecast dlf ON d.id = dlf.device_id
    LEFT  JOIN users                    ru  ON dlf.reviewed_by = ru.id
    WHERE 1=1
";
$params = [];

if ($search) {
    $sql .= " AND (d.asset_tag LIKE ? OR d.vendor LIKE ? OR dt.type_name LIKE ? OR u.department LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s,$s,$s,$s]);
}
if ($typeFilter) {
    $sql .= " AND d.device_type_id = ?";
    $params[] = $typeFilter;
}
if ($deptFilter) {
    $sql .= " AND (u.department LIKE ? OR d.location LIKE ?)";
    $params[] = "%$deptFilter%";
    $params[] = "%$deptFilter%";
}
if ($forecastFilter) {
    $sql .= " AND dlf.forecast_status = ?";
    $params[] = $forecastFilter;
}
if ($deviceIdFilter > 0) {
    $sql .= " AND d.id = ?";
    $params[] = $deviceIdFilter;
}

$orderMap = [
    'eol_asc'      => "DATE_ADD(d.purchase_date, INTERVAL COALESCE(dlf.override_lifespan_years, d.expected_lifespan_years, dtl.default_years, 5) YEAR) ASC",
    'eol_desc'     => "DATE_ADD(d.purchase_date, INTERVAL COALESCE(dlf.override_lifespan_years, d.expected_lifespan_years, dtl.default_years, 5) YEAR) DESC",
    'purchase_asc' => "d.purchase_date ASC",
    'age_desc'     => "d.purchase_date ASC",
];
$sql .= " ORDER BY " . ($orderMap[$sortBy] ?? $orderMap['eol_asc']);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$devices = $stmt->fetchAll();

// ============================================================
// COMPUTE EOL + DISPLAY VALUES
// NOTE: forecast_status is now always populated by the auto-sync
// above, so we only fall back to computed value for brand-new
// devices that somehow missed the sync (e.g. added mid-session).
// ============================================================
$today = new DateTime('today');

foreach ($devices as &$dev) {
    $purchaseDate  = $dev['purchase_date'] ? new DateTime($dev['purchase_date']) : null;
    $lifespanYears = (int)$dev['lifespan_years'];
    $eolDate       = $purchaseDate ? (clone $purchaseDate)->modify("+{$lifespanYears} years") : null;
    $ageYears      = $purchaseDate ? round($today->diff($purchaseDate)->days / 365.25, 1) : null;
    $yearsLeft     = $eolDate     ? round($eolDate->diff($today)->days / 365.25 * ($eolDate > $today ? 1 : -1), 1) : null;

    $dev['purchase_date_fmt'] = $purchaseDate ? $purchaseDate->format('M d, Y') : 'N/A';
    $dev['eol_date']          = $eolDate      ? $eolDate->format('Y-m-d')       : null;
    $dev['eol_date_fmt']      = $eolDate      ? $eolDate->format('M d, Y')      : 'N/A';
    $dev['age_years']         = $ageYears;
    $dev['years_left']        = $yearsLeft;

    // Fallback only for brand-new devices with no DB row yet (added mid-session)
    // Thresholds mirror the legend and auto-sync above:
    //   Good=12mo+  Monitor=6-12mo  Replace Soon=0-6mo  Overdue=past EOL
    if (!$dev['forecast_status']) {
        if (!$eolDate) {
            $dev['forecast_status'] = 'good';
        } elseif ($yearsLeft < 0) {
            $dev['forecast_status'] = 'overdue';
        } elseif ($yearsLeft <= 0.5) {   // 0–6 months
            $dev['forecast_status'] = 'replace_soon';
        } elseif ($yearsLeft <= 1) {     // 6–12 months
            $dev['forecast_status'] = 'monitor';
        } else {                         // 12+ months
            $dev['forecast_status'] = 'good';
        }
    }
}
unset($dev);

// ============================================================
// SUMMARY COUNTS
// ============================================================
$statCounts = ['good'=>0,'monitor'=>0,'replace_soon'=>0,'overdue'=>0,'replaced'=>0,'extended'=>0,'no_date'=>0];
foreach ($devices as $d) {
    if (!$d['purchase_date']) { $statCounts['no_date']++; continue; }
    $statCounts[$d['forecast_status']] = ($statCounts[$d['forecast_status']] ?? 0) + 1;
}

$departments = $pdo->query(
    "SELECT DISTINCT COALESCE(u.department, d.location) AS dept
     FROM devices d
     LEFT JOIN device_assignments da ON d.id = da.device_id AND da.status = 'active'
     LEFT JOIN users u ON da.employee_id = u.id
     WHERE COALESCE(u.department, d.location) IS NOT NULL
       AND COALESCE(u.department, d.location) != ''
     ORDER BY dept"
)->fetchAll(PDO::FETCH_COLUMN);

$forecastMeta = [
    'good'         => ['label'=>'Good',         'color'=>'#27AE60', 'icon'=>'fa-check-circle'],
    'monitor'      => ['label'=>'Monitor',       'color'=>'#F39C12', 'icon'=>'fa-eye'],
    'replace_soon' => ['label'=>'Replace Soon',  'color'=>'#E67E22', 'icon'=>'fa-exclamation-triangle'],
    'overdue'      => ['label'=>'Overdue',       'color'=>'#E74C3C', 'icon'=>'fa-times-circle'],
    'replaced'     => ['label'=>'Replaced',      'color'=>'#7F8C8D', 'icon'=>'fa-archive'],
    'extended'     => ['label'=>'Extended',      'color'=>'#3498DB', 'icon'=>'fa-plus-circle'],
];

$csrfToken = generateCsrfToken();

// Deep-link highlight: which device_id to scroll to (from notification click)
$highlightId = $deviceIdFilter > 0 ? $deviceIdFilter : 0;

require_once 'includes/header.php';
?>

<!-- ============================================================
     PAGE HEADER
     ============================================================ -->
<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
    <h1><i class="fas fa-hourglass-half"></i> Device Lifespan Forecast</h1>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        <button class="btn btn-outline" onclick="exportLifespanCSV()">
            <i class="fas fa-file-csv"></i> Export CSV
        </button>
        <button class="btn btn-outline" onclick="exportLifespanPDF()">
            <i class="fas fa-file-pdf"></i> Export PDF
        </button>
    </div>
</div>

<!-- ============================================================
     STAT CARDS
     ============================================================ -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:22px;">
    <?php
    $statCards = [
        ['good',         'Healthy',       '#27AE60', 'fa-check-circle'],
        ['monitor',      'Monitor',        '#F39C12', 'fa-eye'],
        ['replace_soon', 'Replace Soon',   '#E67E22', 'fa-exclamation-triangle'],
        ['overdue',      'Overdue',        '#E74C3C', 'fa-times-circle'],
        ['extended',     'Extended',       '#3498DB', 'fa-plus-circle'],
        ['no_date',      'No Date Set',    '#95A5A6', 'fa-question-circle'],
    ];
    foreach ($statCards as [$key, $lbl, $col, $ico]):
        $cnt = $statCounts[$key] ?? 0;
    ?>
    <div style="background:#fff;border-radius:10px;padding:16px 14px;box-shadow:0 1px 6px rgba(0,0,0,.07);border-left:4px solid <?php echo $col; ?>;cursor:pointer;"
         onclick="filterByStatus('<?php echo $key; ?>')" title="Filter by <?php echo $lbl; ?>">
        <div style="font-size:22px;font-weight:700;color:<?php echo $col; ?>;"><?php echo $cnt; ?></div>
        <div style="font-size:12px;color:#666;margin-top:3px;display:flex;align-items:center;gap:5px;">
            <i class="fas <?php echo $ico; ?>" style="color:<?php echo $col; ?>;"></i> <?php echo $lbl; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ============================================================
     FILTER BAR
     ============================================================ -->
<form method="GET" id="lifespanFilterForm">
<div class="card" style="margin-bottom:20px;">
    <div class="card-body">
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
            <input type="text" name="search"
                   value="<?php echo sanitize($search); ?>"
                   placeholder="Search asset tag, brand, model, type, department…"
                   style="flex:1;min-width:220px;padding:10px 12px;border:1px solid #d6d8db;border-radius:8px;">

            <select name="type" style="min-width:160px;padding:10px 12px;border:1px solid #d6d8db;border-radius:8px;">
                <option value="">All Types</option>
                <?php foreach ($types as $t): ?>
                <option value="<?php echo $t['id']; ?>" <?php echo $typeFilter == $t['id'] ? 'selected' : ''; ?>>
                    <?php echo sanitize($t['type_name']); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="department" style="min-width:160px;padding:10px 12px;border:1px solid #d6d8db;border-radius:8px;">
                <option value="">All Departments</option>
                <?php foreach ($departments as $dept): ?>
                <option value="<?php echo sanitize($dept); ?>" <?php echo $deptFilter === $dept ? 'selected' : ''; ?>>
                    <?php echo sanitize($dept); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="forecast_status" id="forecastStatusFilter" style="min-width:160px;padding:10px 12px;border:1px solid #d6d8db;border-radius:8px;">
                <option value="">All Statuses</option>
                <?php foreach ($forecastMeta as $val => $meta): ?>
                <option value="<?php echo $val; ?>" <?php echo $forecastFilter === $val ? 'selected' : ''; ?>>
                    <?php echo $meta['label']; ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="sort" style="min-width:160px;padding:10px 12px;border:1px solid #d6d8db;border-radius:8px;">
                <option value="eol_asc"      <?php echo $sortBy==='eol_asc'      ? 'selected':'' ?>>EOL: Soonest First</option>
                <option value="eol_desc"     <?php echo $sortBy==='eol_desc'     ? 'selected':'' ?>>EOL: Latest First</option>
                <option value="purchase_asc" <?php echo $sortBy==='purchase_asc' ? 'selected':'' ?>>Purchase: Oldest First</option>
                <option value="age_desc"     <?php echo $sortBy==='age_desc'     ? 'selected':'' ?>>Age: Oldest First</option>
            </select>

            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fas fa-search"></i> Filter
            </button>
            <a href="device_lifespan.php" class="btn btn-light btn-sm">
                <i class="fas fa-undo"></i> Reset
            </a>
        </div>
    </div>
</div>
</form>

<!-- ============================================================
     MAIN TABLE
     ============================================================ -->
<div class="card" style="overflow:hidden;">
    <div id="lifespanTableWrap">
        <table id="lifespanTable">
            <thead>
                <tr>
                    <th class="col-tag">Asset Tag</th>
                    <th class="col-type">Type</th>
                    <th class="col-dept">Department</th>
                    <th class="col-date">Purchase Date</th>
                    <th class="col-span">Lifespan</th>
                    <th class="col-eol">EOL Date</th>
                    <th class="col-age">Age / Left</th>
                    <th class="col-status">Status</th>
                    <th class="col-remarks">Remarks</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($devices)): ?>
                <tr>
                    <td colspan="9" style="text-align:center;padding:48px;color:#9ca3af;">
                        <i class="fas fa-search" style="font-size:32px;display:block;margin-bottom:10px;opacity:.4;"></i>
                        No devices match your filters.
                    </td>
                </tr>
            <?php else: ?>
            <?php foreach ($devices as $dev):
                $fm      = $forecastMeta[$dev['forecast_status']] ?? $forecastMeta['good'];
                $col     = $fm['color'];
                $isOver  = ($dev['years_left'] !== null && $dev['years_left'] < 0);
                $ageStr  = $dev['age_years']  !== null
                    ? $dev['age_years'] . ' yr' . (abs($dev['age_years'])  != 1 ? 's' : '')
                    : '—';
                $leftStr = $dev['years_left'] !== null
                    ? abs($dev['years_left']) . ' yr' . (abs($dev['years_left']) != 1 ? 's' : '') . ($isOver ? ' ago' : ' left')
                    : '—';
                if ($dev['forecast_status'] === 'overdue')           { $rowBg = '#fff5f5'; }
                elseif ($dev['forecast_status'] === 'replace_soon')  { $rowBg = '#fff8f0'; }
                else                                                  { $rowBg = ''; }

                // Highlight row if arriving from a notification deep-link
                $isHighlighted = ($highlightId > 0 && (int)$dev['id'] === $highlightId);
            ?>
            <tr id="device-row-<?php echo $dev['id']; ?>"
                style="<?php echo $rowBg ? 'background:'.$rowBg : ''; ?><?php echo $isHighlighted ? ';outline:2px solid #E74C3C;outline-offset:-2px;' : ''; ?>">

                <td class="col-tag">
                    <a href="view_device.php?id=<?php echo $dev['id']; ?>"
                       style="font-weight:700;color:#1e293b;text-decoration:none;display:block;font-size:12px;">
                        <?php echo sanitize($dev['asset_tag']); ?>
                    </a>
                </td>

                <td class="col-type" style="font-size:12px;"><?php echo sanitize($dev['type_name']); ?></td>

                <td class="col-dept" style="font-size:12px;"><?php echo sanitize($dev['department']); ?></td>

                <td class="col-date" style="font-size:12px;white-space:nowrap;"><?php echo sanitize($dev['purchase_date_fmt']); ?></td>

                <td class="col-span" style="font-size:12px;text-align:center;" title="Type default: <?php echo $dev['type_default_years']; ?> yrs">
                    <?php echo (int)$dev['lifespan_years']; ?> yrs
                </td>

                <td class="col-eol" style="font-size:12px;font-weight:600;color:<?php echo $col; ?>;white-space:nowrap;">
                    <?php echo sanitize($dev['eol_date_fmt']); ?>
                </td>

                <td class="col-age">
                    <div style="font-size:11px;color:#9ca3af;"><?php echo $ageStr; ?> old</div>
                    <div style="font-size:11px;font-weight:600;color:<?php echo $col; ?>;"><?php echo $leftStr; ?></div>
                </td>

                <td class="col-status">
                    <span class="ls-badge"
                          style="background:<?php echo $col; ?>15;color:<?php echo $col; ?>;border-color:<?php echo $col; ?>35;">
                        <i class="fas <?php echo $fm['icon']; ?>"></i>
                        <?php echo $fm['label']; ?>
                    </span>
                    <?php if ($dev['last_reviewed_date']): ?>
                    <div style="font-size:10px;color:#c0c4cc;margin-top:3px;line-height:1.3;">
                        <?php echo formatDate($dev['last_reviewed_date']); ?>
                        <?php if ($dev['reviewed_by_name']): ?>· <?php echo sanitize($dev['reviewed_by_name']); ?><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </td>

                <td class="col-remarks">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span class="remarks-text" style="flex:1;font-size:12px;color:<?php echo $dev['remarks'] ? '#4b5563' : '#d1d5db'; ?>;font-style:<?php echo $dev['remarks'] ? 'normal' : 'italic'; ?>;">
                            <?php echo $dev['remarks'] ? sanitize($dev['remarks']) : 'No remarks yet'; ?>
                        </span>
                        <button class="btn-edit-inline edit-btn"
                                data-id="<?php echo $dev['id']; ?>"
                                data-asset="<?php echo sanitize($dev['asset_tag']); ?>"
                                data-device="<?php echo sanitize($dev['asset_tag']); ?>"
                                data-status="<?php echo sanitize($dev['forecast_status']); ?>"
                                data-remarks="<?php echo htmlspecialchars($dev['remarks'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                data-lifespan="<?php echo (int)$dev['lifespan_years']; ?>"
                                data-type-default="<?php echo (int)$dev['type_default_years']; ?>">
                            <i class="fas fa-pen" style="font-size:10px;"></i> Edit
                        </button>
                    </div>
                </td>

            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div style="padding:10px 16px;font-size:12px;color:#9ca3af;border-top:1px solid #f0f2f5;background:#fff;">
        Showing <strong style="color:#374151;"><?php echo count($devices); ?></strong>
        device<?php echo count($devices) !== 1 ? 's' : ''; ?>
    </div>
</div>

<!-- ============================================================
     EDIT MODAL
     ============================================================ -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;width:min(520px,95vw);max-height:90vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,.25);">

        <div style="background:#2C3E50;color:#fff;padding:16px 20px;border-radius:12px 12px 0 0;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <div style="font-weight:700;font-size:15px;"><i class="fas fa-hourglass-half"></i> Edit Lifespan Forecast</div>
                <div id="modal-device-label" style="font-size:12px;opacity:.75;margin-top:2px;"></div>
            </div>
            <button onclick="closeModal()" style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1;">&times;</button>
        </div>

        <div style="padding:20px;">
            <input type="hidden" id="modal-device-id">

            <div style="margin-bottom:16px;">
                <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#555;display:block;margin-bottom:8px;">
                    <i class="fas fa-signal" style="color:#3498db;"></i> Forecast Status
                </label>
                <div style="display:flex;flex-wrap:wrap;gap:8px;" id="status-radio-group">
                    <?php foreach ($forecastMeta as $val => $meta): ?>
                    <label style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:20px;border:2px solid <?php echo $meta['color']; ?>22;background:<?php echo $meta['color']; ?>10;font-size:13px;user-select:none;transition:all .15s;"
                           class="status-pill" data-color="<?php echo $meta['color']; ?>">
                        <input type="radio" name="modal_status" value="<?php echo $val; ?>"
                               style="width:13px;height:13px;accent-color:<?php echo $meta['color']; ?>;">
                        <i class="fas <?php echo $meta['icon']; ?>" style="color:<?php echo $meta['color']; ?>;"></i>
                        <span style="color:<?php echo $meta['color']; ?>;font-weight:600;"><?php echo $meta['label']; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="margin-bottom:16px;">
                <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#555;display:block;margin-bottom:6px;">
                    <i class="fas fa-sliders-h" style="color:#3498db;"></i>
                    Lifespan Override <em style="font-weight:400;color:#aaa;">(years — leave blank to use type default)</em>
                </label>
                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="number" id="modal-override-years" min="1" max="25" step="1"
                           placeholder="e.g. 5"
                           style="width:100px;padding:9px 12px;border:1px solid #d6d8db;border-radius:8px;font-size:14px;">
                    <span style="font-size:12px;color:#888;">Type default: <strong id="modal-type-default"></strong> yrs</span>
                </div>
            </div>

            <div style="margin-bottom:20px;">
                <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#555;display:block;margin-bottom:6px;">
                    <i class="fas fa-comment-alt" style="color:#3498db;"></i> Remarks
                </label>
                <textarea id="modal-remarks" rows="4"
                          placeholder="Condition notes, replacement budget cycle, recommended action, vendor quote, etc."
                          style="width:100%;padding:10px 12px;border:1px solid #d6d8db;border-radius:8px;font-size:13px;box-sizing:border-box;resize:vertical;"></textarea>

                <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;">
                    <span style="font-size:11px;color:#aaa;margin-right:2px;align-self:center;">Quick add:</span>
                    <?php
                    $chips = [
                        'Scheduled for replacement next FY.',
                        'Warranty expired — monitor closely.',
                        'Lifespan extended — hardware still stable.',
                        'Repair cost exceeds replacement value.',
                        'Battery health degraded.',
                        'No issues observed during last inspection.',
                        'Recommended for upgrade to newer model.',
                        'Pending budget approval for replacement.',
                    ];
                    foreach ($chips as $chip): ?>
                    <button type="button" class="remark-chip"
                            onclick="appendRemark('<?php echo addslashes($chip); ?>')"
                            style="font-size:11px;padding:3px 9px;border:1px solid #ddd;border-radius:12px;background:#f8f8f8;cursor:pointer;color:#555;transition:background .1s;">
                        + <?php echo $chip; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" class="btn btn-light" onclick="closeModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveBtn" onclick="saveForecast()">
                    <i class="fas fa-save"></i> Save Forecast
                </button>
            </div>

            <div id="save-feedback" style="display:none;margin-top:12px;padding:10px 14px;border-radius:8px;font-size:13px;"></div>
        </div>
    </div>
</div>

<!-- ============================================================
     LEGEND
     ============================================================ -->
<div class="card" style="margin-top:20px;">
    <div class="card-body">
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#888;margin-bottom:10px;">
            <i class="fas fa-info-circle"></i> Forecast Status Legend &amp; Recommended Actions
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px;">
            <?php
            $legend = [
                'good'         => 'Device is within its expected useful life. No action needed.',
                'monitor'      => '6–12 months remaining. Begin planning for potential replacement.',
                'replace_soon' => 'Under 6 months remaining. Raise budget request; source replacement unit.',
                'overdue'      => 'Past expected EOL. Assess daily; prioritise immediate replacement.',
                'extended'     => 'IT has officially extended the lifespan based on current condition.',
                'replaced'     => 'Device has been replaced or retired; kept for historical reference.',
            ];
            foreach ($legend as $val => $desc):
                $m = $forecastMeta[$val];
            ?>
            <div style="display:flex;gap:10px;align-items:flex-start;">
                <span style="background:<?php echo $m['color']; ?>18;color:<?php echo $m['color']; ?>;border:1px solid <?php echo $m['color']; ?>40;border-radius:12px;padding:3px 10px;font-size:12px;font-weight:600;white-space:nowrap;flex-shrink:0;">
                    <i class="fas <?php echo $m['icon']; ?>"></i> <?php echo $m['label']; ?>
                </span>
                <span style="font-size:12px;color:#666;"><?php echo $desc; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<input type="hidden" id="csrf_token_val" value="<?php echo sanitize($csrfToken); ?>">
<!-- Deep-link target for JS scroll -->
<input type="hidden" id="highlight_device_id" value="<?php echo $highlightId; ?>">

<style>
#lifespanTableWrap {
    width: 100%;
    max-height: 600px;
    overflow-y: auto;
    overflow-x: auto;
    border-radius: 0;
}
#lifespanTable {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
    font-size: 13px;
}
#lifespanTable thead tr {
    position: sticky;
    top: 0;
    z-index: 10;
    background: #f4f6f8;
    box-shadow: 0 1px 0 #e5e8ec;
}
#lifespanTable thead th {
    padding: 10px 12px;
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #6b7280;
    white-space: nowrap;
    border: none;
    text-align: left;
}
.col-tag     { width: 130px; }
.col-type    { width: 100px; }
.col-dept    { width: 130px; }
.col-date    { width: 105px; }
.col-span    { width: 75px;  text-align: center; }
.col-eol     { width: 100px; }
.col-age     { width: 90px;  }
.col-status  { width: 130px; }
.col-remarks { width: auto;  min-width: 180px; }
#lifespanTable tbody tr {
    border-bottom: 1px solid #f0f2f5;
    transition: background .1s;
}
#lifespanTable tbody tr:last-child { border-bottom: none; }
#lifespanTable tbody tr:hover { background: #f8fafc !important; }
#lifespanTable tbody td {
    padding: 9px 12px;
    vertical-align: middle;
    border: none;
    overflow: hidden;
    text-overflow: ellipsis;
}
.ls-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
    border-width: 1px;
    border-style: solid;
}
.btn-edit-inline {
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    font-size: 11px;
    font-weight: 600;
    color: #3b82f6;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 6px;
    cursor: pointer;
    white-space: nowrap;
    transition: all .15s;
    line-height: 1.4;
}
.btn-edit-inline:hover {
    background: #dbeafe;
    border-color: #93c5fd;
    color: #2563eb;
}
#lifespanTableWrap::-webkit-scrollbar        { width: 6px; height: 6px; }
#lifespanTableWrap::-webkit-scrollbar-track  { background: #f1f1f1; border-radius: 4px; }
#lifespanTableWrap::-webkit-scrollbar-thumb  { background: #cbd5e1; border-radius: 4px; }
#lifespanTableWrap::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
.remark-chip {
    font-size: 11px;
    padding: 3px 9px;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #f9fafb;
    cursor: pointer;
    color: #6b7280;
    transition: all .15s;
}
.remark-chip:hover  { background: #eff6ff !important; border-color: #93c5fd !important; color: #2563eb !important; }
.status-pill:hover  { filter: brightness(.96); }

/* Deep-link highlight animation */
@keyframes rowHighlight {
    0%   { background-color: #fdecea !important; }
    60%  { background-color: #fdecea !important; }
    100% { background-color: transparent; }
}
.row-deep-linked {
    animation: rowHighlight 3s ease forwards;
}
</style>

<script>
// ---- Deep-link: scroll to highlighted row ----
(function () {
    var id = parseInt(document.getElementById('highlight_device_id').value, 10);
    if (!id) return;
    var row = document.getElementById('device-row-' + id);
    if (!row) return;
    setTimeout(function () {
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.classList.add('row-deep-linked');
    }, 350);
})();

// ---- Modal helpers ----
function openModal(btn) {
    var id          = btn.dataset.id;
    var asset       = btn.dataset.asset;
    var device      = btn.dataset.device;
    var status      = btn.dataset.status;
    var remarks     = btn.dataset.remarks;
    var typeDefault = btn.dataset.typeDefault;
    var lifespan    = btn.dataset.lifespan;

    document.getElementById('modal-device-id').value          = id;
    document.getElementById('modal-device-label').textContent = asset + '  —  ' + device;
    document.getElementById('modal-remarks').value            = remarks;
    document.getElementById('modal-type-default').textContent = typeDefault;
    document.getElementById('modal-override-years').value     = (parseInt(lifespan) !== parseInt(typeDefault)) ? lifespan : '';

    document.querySelectorAll('input[name="modal_status"]').forEach(function(r) {
        r.checked = (r.value === status);
    });
    refreshPills();
    document.getElementById('save-feedback').style.display = 'none';
    document.getElementById('editModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('editModal').style.display = 'none';
}

function appendRemark(text) {
    var ta = document.getElementById('modal-remarks');
    var cur = ta.value.trim();
    ta.value = cur ? cur + ' ' + text : text;
    ta.focus();
}

function refreshPills() {
    document.querySelectorAll('.status-pill').forEach(function(pill) {
        var radio = pill.querySelector('input[type="radio"]');
        var c     = pill.dataset.color;
        if (radio.checked) {
            pill.style.boxShadow = '0 0 0 3px ' + c + '44';
            pill.style.background = c + '22';
        } else {
            pill.style.boxShadow = '';
            pill.style.background = c + '10';
        }
    });
}

document.querySelectorAll('.status-pill input[type="radio"]').forEach(function(r) {
    r.addEventListener('change', refreshPills);
});

// ---- Save ----
function saveForecast() {
    var deviceId  = document.getElementById('modal-device-id').value;
    var status    = document.querySelector('input[name="modal_status"]:checked')?.value;
    var remarks   = document.getElementById('modal-remarks').value;
    var override  = document.getElementById('modal-override-years').value;
    var csrf      = document.getElementById('csrf_token_val').value;
    var feedback  = document.getElementById('save-feedback');
    var saveBtn   = document.getElementById('saveBtn');

    if (!status) {
        feedback.style.display = 'block';
        feedback.style.background = '#fff5f5';
        feedback.style.color = '#e74c3c';
        feedback.textContent = 'Please select a forecast status.';
        return;
    }

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    var body = new FormData();
    body.append('ajax_save',       '1');
    body.append('csrf_token',       csrf);
    body.append('device_id',        deviceId);
    body.append('forecast_status',  status);
    body.append('remarks',          remarks);
    body.append('override_years',   override);

    fetch('device_lifespan.php', { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Forecast';
            if (data.ok) {
                feedback.style.display = 'block';
                feedback.style.background = '#f0fff4';
                feedback.style.color = '#27AE60';
                feedback.textContent = '✓ ' + data.msg;
                setTimeout(function() { location.reload(); }, 900);
            } else {
                feedback.style.display = 'block';
                feedback.style.background = '#fff5f5';
                feedback.style.color = '#e74c3c';
                feedback.textContent = '✗ ' + (data.msg || 'Save failed.');
            }
        })
        .catch(function() {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Forecast';
            feedback.style.display = 'block';
            feedback.style.background = '#fff5f5';
            feedback.style.color = '#e74c3c';
            feedback.textContent = 'Network error. Please try again.';
        });
}

document.querySelectorAll('.edit-btn').forEach(function(btn) {
    btn.addEventListener('click', function() { openModal(this); });
});

document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

function filterByStatus(key) {
    var sel = document.getElementById('forecastStatusFilter');
    if (sel) {
        sel.value = (key === 'no_date') ? '' : key;
        document.getElementById('lifespanFilterForm').submit();
    }
}

function exportLifespanCSV() {
    var rows = [];
    document.querySelectorAll('#lifespanTable tbody tr').forEach(function(row) {
        var cells = row.querySelectorAll('td');
        if (cells.length > 2) {
            rows.push([
                cells[0].textContent.trim(),
                cells[1].textContent.trim(),
                cells[2].textContent.trim(),
                cells[3].textContent.trim(),
                cells[4].textContent.trim(),
                cells[5].textContent.trim(),
                cells[6].textContent.trim(),
                cells[7].textContent.trim(),
                cells[8].querySelector('.remarks-text')?.textContent.trim() || '',
            ]);
        }
    });
    exportToCSV(
        'device_lifespan_<?php echo date('Y-m-d'); ?>.csv',
        ['Asset Tag','Type','Department','Purchase Date','Life Span','EOL Date','Age / Left','Status','Remarks'],
        rows
    );
}

function exportLifespanPDF() {
    var rows = [];
    document.querySelectorAll('#lifespanTable tbody tr').forEach(function(row) {
        var cells = row.querySelectorAll('td');
        if (cells.length > 2) {
            rows.push([
                cells[0].textContent.trim(),
                cells[1].textContent.trim(),
                cells[2].textContent.trim(),
                cells[3].textContent.trim(),
                cells[4].textContent.trim(),
                cells[5].textContent.trim(),
                cells[7].textContent.trim(),
                cells[8].querySelector('.remarks-text')?.textContent.trim() || '',
            ]);
        }
    });
    exportToPDF(
        'Device Lifespan Forecast Report',
        ['Asset Tag','Type','Department','Purchase Date','Life Span','EOL Date','Status','Remarks'],
        rows,
        'device_lifespan_<?php echo date('Y-m-d'); ?>.pdf'
    );
}
</script>

<?php require_once 'includes/footer.php'; ?>
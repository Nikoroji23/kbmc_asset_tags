<?php
/**
 * KBMC Asset Management - Bulk Import Assets from CSV or Excel
 * Imports employees and their assigned assets from CSV or XLSX files
 */

$pageTitle = 'Import Assets from CSV or Excel';
require_once 'includes/header.php';

// Only admin and IT staff can access this
requireITStaff();

$importStatus = [];
$importSummary = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['csv_file'])) {
    try {
        $file = $_FILES['csv_file'];
        
        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload failed');
        }
        
        if ($file['size'] > 5 * 1024 * 1024) { // 5MB max
            throw new Exception('File size exceeds 5MB limit');
        }
        
        $tmpFile = $file['tmp_name'];
        
        if (!file_exists($tmpFile)) {
            throw new Exception('Temporary file not found');
        }
        
        $fileName = $file['name'] ?? '';
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = ['csv', 'xlsx'];
        if (!in_array($extension, $allowedExtensions)) {
            throw new Exception('Unsupported file type. Upload a CSV or XLSX file.');
        }

        $rows = readImportRows($tmpFile, $extension);
        if (empty($rows)) {
            throw new Exception('Uploaded file contains no data.');
        }

        $header = array_shift($rows);
        if (!is_array($header) || empty($header)) {
            throw new Exception('Cannot read spreadsheet header');
        }
        
        // Normalize headers and build column index map
        $headerMap = [];
        foreach ($header as $index => $columnName) {
            $cleanName = preg_replace('/^\xEF\xBB\xBF/', '', $columnName);
            $cleanName = strtoupper(trim($cleanName));
            $cleanName = preg_replace('/\s+/', ' ', $cleanName);
            // Handle common spelling variations
            if ($cleanName === 'IP ADRESS') {
                $cleanName = 'IP ADDRESS';
            }
            if ($cleanName === 'ASSIGNED TO') {
                $cleanName = 'ASSIGNED TO';
            }
            $headerMap[$cleanName] = $index;
        }

        // Check for required headers (flexible - can work with old format, new format, or hybrid format)
        $hasOldFormat = isset($headerMap['NAME']) && isset($headerMap['DEPARTMENT']) && !isset($headerMap['ASSET TAG']);
        $hasNewFormat = isset($headerMap['ASSET TAG']) && isset($headerMap['TYPE']) && !isset($headerMap['MONITOR']);
        $hasHybridFormat = isset($headerMap['NAME']) && isset($headerMap['DEPARTMENT']) && (isset($headerMap['MONITOR']) || isset($headerMap['LAPTOP']) || isset($headerMap['KEYBOARD']) || isset($headerMap['MOUSE']) || isset($headerMap['SYSTEM UNIT']) || isset($headerMap['UPS']) || isset($headerMap['CHARGER']) || isset($headerMap['PRINTER']) || isset($headerMap['STORAGE']) || isset($headerMap['SWITCH']));
        
        if (!$hasOldFormat && !$hasNewFormat && !$hasHybridFormat) {
            throw new Exception('CSV must contain either (NAME, DEPARTMENT) or (ASSET TAG, TYPE) or hybrid format (NAME with device type columns) columns');
        }

        // Get device type map
        $deviceTypes = getDeviceTypeMap();
        
        $usersCreated = 0;
        $devicesCreated = 0;
        $devicesUpdated = 0;
        $assignmentsCreated = 0;
        $errors = [];
        $lineNumber = 1;
        
        // Get current admin
        $adminId = $_SESSION['user_id'];
        $adminCheck = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $adminCheck->execute([$adminId]);
        if (!$adminCheck->fetch()) {
            throw new Exception('Your session user no longer exists in the database. Please log out and log back in.');
        }

        foreach ($rows as $row) {
            $lineNumber++;
            
            try {
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }
                
                // Handle hybrid format (NAME with device type columns)
                if ($hasHybridFormat) {
                    // Hybrid format: one user per row with device asset tags in type-specific columns
                    $name = trim($row[$headerMap['NAME']] ?? '');
                    $department = emptyToNA($row[$headerMap['DEPARTMENT']] ?? '');
                    $pcName = emptyToNA($row[$headerMap['PC NAME']] ?? '');
                    $ipAddress = emptyToNA($row[$headerMap['IP ADDRESS']] ?? '');
                    
                    if (empty($name)) {
                        continue;
                    }
                    
                    // Create user if not exists
                    $email = generateEmail($name);
                    $employeeId = generateEmployeeId($name);
                    
                    $userCheck = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $userCheck->execute([$email]);
                    $existingUser = $userCheck->fetch();
                    
                    if ($existingUser) {
                        $userId = $existingUser['id'];
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO users (employee_id, full_name, email, password, role, department, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$employeeId, $name, $email, password_hash('password', PASSWORD_BCRYPT), 'employee', $department, 'active']);
                        $userId = $pdo->lastInsertId();
                        $usersCreated++;
                    }
                    
                    // Process each device type column
                    $deviceTypeColumns = ['MONITOR', 'LAPTOP', 'KEYBOARD', 'MOUSE', 'SYSTEM UNIT', 'UPS', 'CHARGER', 'PRINTER', 'STORAGE', 'SWITCH'];
                    
                    // Collect all device columns (including numbered ones like MONITOR 1, MONITOR 2, PRINTER 1, etc.)
                    $deviceColumnsToProcess = [];
                    foreach ($headerMap as $columnName => $columnIndex) {
                        foreach ($deviceTypeColumns as $baseType) {
                            // Match exact column name or numbered column (e.g., "MONITOR", "MONITOR 1", "MONITOR 2")
                            if ($columnName === $baseType || preg_match('/^' . preg_quote($baseType) . '\s+\d+$/i', $columnName)) {
                                $deviceColumnsToProcess[] = [
                                    'name' => $columnName,
                                    'index' => $columnIndex,
                                    'type' => preg_replace('/\s+\d+$/i', '', $columnName) // Remove number suffix for type
                                ];
                            }
                        }
                    }
                    
                    // Process each collected device column
                    foreach ($deviceColumnsToProcess as $deviceColumn) {
                        $columnName = $deviceColumn['name'];
                        $columnIndex = $deviceColumn['index'];
                        $deviceType = $deviceColumn['type'];
                        
                        $assetTag = trim($row[$columnIndex] ?? '');
                        
                        // Check if asset tag is incomplete (e.g., "KBM-IT-00" or ends with -00)
                            if (empty($assetTag) || (preg_match('/^KBM-[A-Z]+-00$/i', $assetTag) || preg_match('/-00$/i', $assetTag))) {
                                // Skip incomplete asset tags - user doesn't have this device
                                continue;
                            }
                            
                            // Handle N/A - create device with NO asset tag (null)
                            $finalAssetTag = null;
                            $serialNumber = null;
                            
                            if (strtoupper($assetTag) === 'N/A') {
                                // N/A means device exists but no asset tag number - always create new device
                                $finalAssetTag = null;
                                $serialNumber = generateUniqueSerialNumber();
                            } else {
                                // Valid asset tag provided - allow duplicates across device types
                                $finalAssetTag = $assetTag;
                                $serialNumber = generateUniqueSerialNumber(); // Always generate unique serial to avoid constraint violations
                            }
                            
                            // Get device type ID
                            $typeStmt = $pdo->prepare("SELECT id FROM device_types WHERE LOWER(type_name) = LOWER(?)");
                            $typeStmt->execute([$deviceType]);
                            $typeResult = $typeStmt->fetch();
                            $typeId = $typeResult['id'] ?? null;
                            
                            if (!$typeId) {
                                // Create device type if not exists
                                $deviceTypeName = $deviceType === 'SYSTEM UNIT' ? 'System Unit' : 
                                                   ($deviceType === 'STORAGE' ? 'Storage Device' : 
                                                   ($deviceType === 'SWITCH' ? 'Network Switch' : ucfirst(strtolower($deviceType))));
                                $insertTypeStmt = $pdo->prepare("INSERT INTO device_types (type_name) VALUES (?)");
                                $insertTypeStmt->execute([$deviceTypeName]);
                                $typeId = $pdo->lastInsertId();
                            }
                            
                            // Always create a new device for this device type column entry
                            // This allows multiple devices with the same asset tag but different device types
                            $createStmt = $pdo->prepare("INSERT INTO devices (asset_tag, device_type_id, serial_number, pc_name, ip_address, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $createStmt->execute([$finalAssetTag, $typeId, $serialNumber, ($pcName === 'N/A' ? null : $pcName), ($ipAddress === 'N/A' ? null : $ipAddress), 'deployed', $adminId]);
                            $deviceId = $pdo->lastInsertId();
                            $devicesCreated++;
                            
                            // Create assignment to user
                            $assignCheck = $pdo->prepare("SELECT id FROM device_assignments WHERE device_id = ? AND employee_id = ? AND status = 'active'");
                            $assignCheck->execute([$deviceId, $userId]);
                            
                            if (!$assignCheck->fetch()) {
                                // Create assignment
                                $assignStmt = $pdo->prepare("INSERT INTO device_assignments (device_id, employee_id, assigned_by, assigned_date, status) VALUES (?, ?, ?, ?, ?)");
                                $assignStmt->execute([$deviceId, $userId, $adminId, date('Y-m-d'), 'active']);
                                $assignmentsCreated++;
                            }
                        }
                } elseif ($hasOldFormat) {
                    // Old format: multiple asset types per user (for backward compatibility)
                    $name = trim($row[$headerMap['NAME']] ?? '');
                    $department = emptyToNA($row[$headerMap['DEPARTMENT']] ?? '');
                    $pcName = emptyToNA($row[$headerMap['PC NAME']] ?? '');
                    $ipAddress = emptyToNA($row[$headerMap['IP ADDRESS']] ?? '');
                    
                    if (empty($name)) {
                        continue;
                    }
                    
                    // Create user if not exists
                    $email = generateEmail($name);
                    $employeeId = generateEmployeeId($name);
                    
                    $userCheck = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $userCheck->execute([$email]);
                    $existingUser = $userCheck->fetch();
                    
                    if ($existingUser) {
                        $userId = $existingUser['id'];
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO users (employee_id, full_name, email, password, role, department, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$employeeId, $name, $email, password_hash('password', PASSWORD_BCRYPT), 'employee', $department, 'active']);
                        $userId = $pdo->lastInsertId();
                        $usersCreated++;
                    }
                    
                } elseif ($hasNewFormat) {
                    // New format: individual device records
                    $assetTag = trim($row[$headerMap['ASSET TAG']] ?? '');
                    $pcName = emptyToNA($row[$headerMap['PC NAME']] ?? '');
                    $type = trim($row[$headerMap['TYPE']] ?? '');
                    $ipAddress = emptyToNA($row[$headerMap['IP ADDRESS']] ?? '');

                    // Normalize ASSIGNED TO and detect truly unassigned values (blank, N/A, Unassigned, or punctuation-only)
                    $rawAssigned = $row[$headerMap['ASSIGNED TO']] ?? '';
                    $assignedTo = emptyToNA($rawAssigned);
                    $assignedClean = trim((string)$assignedTo);
                    $isUnassigned = (
                        $assignedClean === '' ||
                        strtoupper($assignedClean) === 'N/A' ||
                        strcasecmp($assignedClean, 'Unassigned') === 0 ||
                        preg_match('/^[\s\.\,\/\-\\]+$/', $assignedClean)
                    );

                    // Default status to in_stock when device is unassigned; otherwise use provided or 'deployed'
                    $status = $isUnassigned ? 'in_stock' : trim($row[$headerMap['STATUS']] ?? 'deployed');
                    
                    if (empty($type)) {
                        throw new Exception('Device type is required');
                    }
                    
                    // Get device type ID
                    $typeStmt = $pdo->prepare("SELECT id FROM device_types WHERE LOWER(type_name) = LOWER(?)");
                    $typeStmt->execute([$type]);
                    $typeResult = $typeStmt->fetch();
                    $typeId = $typeResult['id'] ?? null;
                    
                    if (!$typeId) {
                        // Try to create device type
                        $insertTypeStmt = $pdo->prepare("INSERT INTO device_types (type_name) VALUES (?)");
                        $insertTypeStmt->execute([$type]);
                        $typeId = $pdo->lastInsertId();
                    }
                    
                    // Skip incomplete tags like KBM-IT-00 or anything ending in -00
                    if (preg_match('/^KBM-[A-Z]+-00$/i', $assetTag) || preg_match('/-00$/i', $assetTag)) {
                        continue;
                    }

                    // Convert blank or N/A asset tags into a null tag record
                    if (empty($assetTag) || strtoupper($assetTag) === 'N/A') {
                        $assetTag = null;
                    }

                    // Determine final PC name when missing and the device is assigned to a user
                    $finalPcName = $pcName;
                    if (($pcName === 'N/A' || empty($pcName)) && !$isUnassigned) {
                        $userStmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(full_name) = LOWER(?)");
                        $userStmt->execute([$assignedTo]);
                        $userResult = $userStmt->fetch();
                        if ($userResult) {
                            $userLaptopTag = getUserLaptopAssetTag($userResult['id'], $pdo);
                            if ($userLaptopTag) {
                                $finalPcName = $userLaptopTag;
                            }
                        }
                    }

                    // Always create a new device record to allow duplicate asset tags
                    $uniqueSerial = generateUniqueSerialNumber();
                    $createStmt = $pdo->prepare("INSERT INTO devices (asset_tag, device_type_id, serial_number, pc_name, ip_address, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $createStmt->execute([
                        $assetTag,
                        $typeId,
                        $uniqueSerial,
                        ($finalPcName === 'N/A' ? null : $finalPcName),
                        ($ipAddress === 'N/A' ? null : $ipAddress),
                        $status,
                        $adminId
                    ]);
                    $deviceId = $pdo->lastInsertId();
                    $devicesCreated++;
                    
                    // Create assignment if assigned to a user
                    if (!$isUnassigned) {
                        // Find or create user by name
                        $userStmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(full_name) = LOWER(?)");
                        $userStmt->execute([$assignedTo]);
                        $userResult = $userStmt->fetch();
                        
                        if ($userResult) {
                            $userId = $userResult['id'];
                            // Check if assignment exists
                            $assignCheck = $pdo->prepare("SELECT id FROM device_assignments WHERE device_id = ? AND employee_id = ? AND status = 'active'");
                            $assignCheck->execute([$deviceId, $userId]);
                            
                            if (!$assignCheck->fetch()) {
                                // Create assignment
                                $assignStmt = $pdo->prepare("INSERT INTO device_assignments (device_id, employee_id, assigned_by, assigned_date, status) VALUES (?, ?, ?, ?, ?)");
                                $assignStmt->execute([$deviceId, $userId, $adminId, date('Y-m-d'), 'active']);
                                $assignmentsCreated++;
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                $errors[] = "Line $lineNumber: " . $e->getMessage();
            }
        }
        
        $importSummary = [
            'users_created' => $usersCreated,
            'devices_created' => $devicesCreated,
            'devices_updated' => $devicesUpdated,
            'assignments_created' => $assignmentsCreated,
            'errors' => $errors
        ];
        
        setFlashMessage('success', "Import completed! Created $usersCreated users, $devicesCreated devices, $assignmentsCreated assignments.");
        
    } catch (Exception $e) {
        setFlashMessage('error', 'Import failed: ' . $e->getMessage());
    }
}

function readImportRows($filePath, $extension) {
    if ($extension === 'csv') {
        return readCsvRows($filePath);
    }
    if ($extension === 'xlsx') {
        return readXlsxRows($filePath);
    }
    throw new Exception('Unsupported import file type.');
}

/**
 * Convert empty string or whitespace to "N/A"
 */
function emptyToNA($value) {
    $trimmed = trim($value ?? '');
    return empty($trimmed) ? 'N/A' : $trimmed;
}

/**
 * Get user's laptop asset tag if they have one assigned
 */
function getUserLaptopAssetTag($userId, $pdo) {
    $stmt = $pdo->prepare("
        SELECT d.asset_tag
        FROM device_assignments da
        JOIN devices d ON da.device_id = d.id
        JOIN device_types dt ON d.device_type_id = dt.id
        WHERE da.employee_id = ? AND da.status = 'active' AND LOWER(dt.type_name) = 'laptop'
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    return $result ? $result['asset_tag'] : null;
}

/**
 * Generate unique serial number for devices without asset tags
 */
function generateUniqueSerialNumber() {
    global $pdo;
    
    $counter = 1;
    while (true) {
        $serial = 'SN-' . date('Ymd') . '-' . str_pad($counter, 5, '0', STR_PAD_LEFT);
        $check = $pdo->prepare("SELECT id FROM devices WHERE serial_number = ?");
        $check->execute([$serial]);
        if (!$check->fetch()) {
            return $serial;
        }
        $counter++;
    }
}

function readCsvRows($filePath) {
    ini_set('auto_detect_line_endings', '1');
    $rows = [];
    if (($handle = fopen($filePath, 'r')) === false) {
        throw new Exception('Cannot open CSV file');
    }
    while (($row = fgetcsv($handle)) !== false) {
        $rows[] = $row;
    }
    fclose($handle);
    return $rows;
}

function readXlsxRows($filePath) {
    if (!class_exists('ZipArchive')) {
        throw new Exception('Excel import requires the PHP Zip extension.');
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        throw new Exception('Cannot open XLSX file');
    }

    $sharedStrings = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sharedStrings = parseXlsxSharedStrings($xml);
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $zip->close();
        throw new Exception('Cannot locate worksheet data in XLSX file');
    }

    $rows = parseXlsxSheet($sheetXml, $sharedStrings);
    $zip->close();
    return $rows;
}

function parseXlsxSharedStrings($xml) {
    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $strings = [];

    foreach ($dom->getElementsByTagName('si') as $si) {
        $text = '';
        foreach ($si->getElementsByTagName('t') as $t) {
            $text .= $t->nodeValue;
        }
        $strings[] = $text;
    }

    return $strings;
}

function parseXlsxSheet($xml, $sharedStrings) {
    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $rows = [];

    foreach ($dom->getElementsByTagName('row') as $rowNode) {
        $row = [];

        foreach ($rowNode->getElementsByTagName('c') as $cell) {
            $cellRef = $cell->getAttribute('r');
            $columnIndex = xlsxColumnIndexFromReference($cellRef);
            $valueNode = $cell->getElementsByTagName('v')->item(0);
            $value = $valueNode ? $valueNode->nodeValue : '';
            if ($cell->getAttribute('t') === 's' && $value !== '') {
                $value = $sharedStrings[intval($value)] ?? $value;
            }
            $row[$columnIndex] = $value;
        }

        if (!empty($row)) {
            ksort($row);
            $rows[] = array_values($row);
        }
    }

    return $rows;
}

function xlsxColumnIndexFromReference($reference) {
    if (!preg_match('/^([A-Z]+)\d+$/', $reference, $matches)) {
        return 0;
    }

    $letters = $matches[1];
    $index = 0;
    foreach (str_split($letters) as $char) {
        $index = $index * 26 + (ord($char) - ord('A') + 1);
    }

    return $index - 1;
}

function generateEmail($fullName) {
    $nameParts = explode(' ', $fullName);
    $firstName = strtolower($nameParts[0] ?? '');
    $lastName = strtolower($nameParts[count($nameParts) - 1] ?? '');
    $baseEmail = $firstName . '.' . $lastName . '@kbmc.com';
    
    global $pdo;
    $counter = 1;
    $email = $baseEmail;
    
    while (true) {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if (!$check->fetch()) {
            return $email;
        }
        $email = $firstName . '.' . $lastName . $counter . '@kbmc.com';
        $counter++;
    }
}

function generateEmployeeId($fullName) {
    global $pdo;
    
    // Find max employee ID
    $stmt = $pdo->query("SELECT MAX(CAST(employee_id AS SIGNED)) as max_id FROM users WHERE employee_id REGEXP '^[0-9]+$'");
    $result = $stmt->fetch();
    $maxId = $result['max_id'] ?? 0;
    
    return str_pad($maxId + 1, 5, '0', STR_PAD_LEFT);
}

function isValidAssetTag($value) {
    return preg_match('/^[A-Z0-9]+(?:-[A-Z0-9]+)*-\d+$/i', trim($value));
}

function normalizeImportAssetTag($assetTagRaw, $deviceTypeId) {
    $assetTagRaw = trim($assetTagRaw);
    if (isValidAssetTag($assetTagRaw)) {
        return strtoupper($assetTagRaw);
    }
    return generateAssetTag($deviceTypeId);
}

function getDeviceTypeMap() {
    global $pdo;
    
    $types = [
        'monitor' => 'Monitor',
        'mouse' => 'Mouse',
        'keyboard' => 'Keyboard',
        'system unit' => 'System Unit',
        'ups' => 'UPS',
        'laptop' => 'Laptop',
        'charger' => 'Charger',
        'printer' => 'Printer',
        'storage' => 'Storage Device',
        'switch' => 'Network Switch'
    ];
    
    $typeMap = [];
    
    foreach ($types as $key => $typeName) {
        $stmt = $pdo->prepare("SELECT id FROM device_types WHERE LOWER(type_name) = LOWER(?)");
        $stmt->execute([$typeName]);
        $result = $stmt->fetch();
        
        if ($result) {
            $typeMap[$key] = $result['id'];
        } else {
            // Create device type if it doesn't exist
            $stmt = $pdo->prepare("INSERT INTO device_types (type_name) VALUES (?)");
            $stmt->execute([$typeName]);
            $typeMap[$key] = $pdo->lastInsertId();
        }
    }
    
    return $typeMap;
}

?>

<div class="page-header">
    <h1><i class="fas fa-file-import"></i> Import Assets from CSV</h1>
</div>

<?php if (!empty($importSummary)): ?>
<div class="card" style="margin-bottom: 20px;">
    <div class="card-header" style="background: #27ae60; color: white;">
        <h3><i class="fas fa-check-circle"></i> Import Summary</h3>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
            <div style="background: #ecf0f1; padding: 15px; border-radius: 5px; text-align: center;">
                <div style="font-size: 24px; font-weight: bold; color: #3498db;"><?php echo $importSummary['users_created']; ?></div>
                <div style="font-size: 12px; color: #7f8c8d;">Users Created</div>
            </div>
            <div style="background: #ecf0f1; padding: 15px; border-radius: 5px; text-align: center;">
                <div style="font-size: 24px; font-weight: bold; color: #27ae60;"><?php echo $importSummary['devices_created']; ?></div>
                <div style="font-size: 12px; color: #7f8c8d;">Devices Created</div>
            </div>
            <?php if ($importSummary['devices_updated'] > 0): ?>
            <div style="background: #ecf0f1; padding: 15px; border-radius: 5px; text-align: center;">
                <div style="font-size: 24px; font-weight: bold; color: #f39c12;"><?php echo $importSummary['devices_updated']; ?></div>
                <div style="font-size: 12px; color: #7f8c8d;">Devices Updated</div>
            </div>
            <?php endif; ?>
            <div style="background: #ecf0f1; padding: 15px; border-radius: 5px; text-align: center;">
                <div style="font-size: 24px; font-weight: bold; color: #e74c3c;"><?php echo $importSummary['assignments_created']; ?></div>
                <div style="font-size: 12px; color: #7f8c8d;">Assignments Created</div>
            </div>
        </div>
        
        <?php if (!empty($importSummary['errors'])): ?>
        <div style="margin-top: 20px;">
            <h4 style="color: #e74c3c;"><i class="fas fa-exclamation-triangle"></i> Errors Encountered:</h4>
            <ul style="color: #7f8c8d; font-size: 12px;">
                <?php foreach ($importSummary['errors'] as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3>Upload CSV File</h3>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="csv_file"><i class="fas fa-file-csv"></i> Select CSV or Excel File</label>
                <input type="file" id="csv_file" name="csv_file" class="form-control" accept=".csv,.xlsx" required>
                <small style="color: #7f8c8d;">Upload a CSV or XLSX file. Header order is flexible and the importer normalizes header names.</small>
            </div>
            <div style="margin-top: 20px;">
                <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> Import Assets</button>
            </div>
        </form>
        
        <div style="margin-top: 30px; padding: 15px; background: #ecf0f1; border-radius: 5px;">
            <h4><i class="fas fa-info-circle"></i> Import Instructions</h4>
            <ul style="font-size: 13px; line-height: 1.8;">
                <li><strong>Upload Format:</strong> CSV or Excel (.xlsx) file</li>
                <li><strong>Supported Formats:</strong>
                    <ul>
                        <li><strong>Hybrid Format (RECOMMENDED - One User + Multiple Devices):</strong> NAME, DEPARTMENT, PC NAME, IP ADDRESS, MONITOR, LAPTOP, KEYBOARD, MOUSE, SYSTEM UNIT, UPS, CHARGER, PRINTER, STORAGE, SWITCH
                            <ul style="margin-top: 5px;">
                                <li>One row per employee with asset tags in device type columns</li>
                                <li>Each device column contains the asset tag (e.g., "KBM-LAP-2024-001" in LAPTOP column)</li>
                                <li>Devices are automatically created and assigned to the user</li>
                                <li>Leave device columns empty or use N/A if employee doesn't have that device type</li>
                            </ul>
                        </li>
                        <li><strong>Old Format (Multiple Asset Types):</strong> NAME, DEPARTMENT, PC NAME, IP ADDRESS, MONITOR 1, MONITOR 2, MOUSE, KEYBOARD, SYSTEM UNIT, UPS, LAPTOP, CHARGER, PRINTER 1, etc.</li>
                        <li><strong>New Format (Individual Devices):</strong> ASSET TAG, PC NAME, TYPE, IP ADDRESS, STATUS, ASSIGNED TO</li>
                    </ul>
                </li>
                <li><strong>Hybrid Format Features:</strong>
                    <ul>
                        <li>Automatically creates/updates user from NAME field</li>
                        <li>Creates devices from asset tags in device type columns</li>
                        <li><strong>Asset Tag Rules:</strong>
                            <ul>
                                <li><strong>Valid asset tag</strong> (e.g., "KBM-LAP-001856"): Creates device with that asset tag</li>
                                <li><strong>N/A</strong>: Creates device with NO asset tag (null) - device exists but asset tag unknown, auto-generates unique serial</li>
                                <li><strong>Incomplete tag</strong> (e.g., "KBM-IT-00" or ends in -00): Skipped - user doesn't have this device</li>
                                <li><strong>Empty/Blank</strong>: Skipped - user doesn't have this device</li>
                                <li><strong>Duplicate asset tags are allowed</strong> (e.g., LAPTOP="KBM-IT-001856" AND CHARGER="KBM-IT-001856"): Both create separate devices with same asset tag</li>
                            </ul>
                        </li>
                        <li>Automatically assigns devices to the user</li>
                        <li>Devices are marked as "deployed" status</li>
                        <li>Supports: Monitor, Laptop, Keyboard, Mouse, System Unit, UPS, Charger, Printer, Storage Device, Network Switch</li>
                    </ul>
                </li>
                <li><strong>New Format Features:</strong>
                    <ul>
                        <li>Each row is an individual device</li>
                        <li>Asset Tag can be blank or N/A (allows duplicates)</li>
                        <li>Type field supports: Monitor, Laptop, Mouse, Keyboard, System Unit, UPS, Charger, Printer, Storage Device, Network Switch</li>
                        <li>IP Address is optional (use N/A for devices without IP)</li>
                        <li>Status options: in_stock, deployed, under_repair, retired, disposed, pending_inspection</li>
                        <li>Assigned To: Use employee full name or leave blank for unassigned devices</li>
                    </ul>
                </li>
                <li><strong>User Creation:</strong> Users are created if they don't exist (all formats create users from NAME field)</li>
                <li><strong>Duplicate Prevention:</strong> Existing users and devices will be updated, not duplicated</li>
            </ul>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

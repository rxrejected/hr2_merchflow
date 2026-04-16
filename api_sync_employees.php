<?php
/**
 * API: Sync HR1 Employees to users_employee table
 * 
 * This API automatically syncs all active HR1 employees to the users_employee table.
 * Can be called via AJAX from admin panel or as a cron job.
 * 
 * Usage:
 *   POST api_sync_employees.php  (from admin session)
 *   GET  api_sync_employees.php?action=status  (check sync status)
 */

// Suppress ALL output except our JSON
error_reporting(0);
ini_set('display_errors', 0);
ob_start(); // Catch any stray output from includes

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    include(__DIR__ . "/Connection/Config.php");
    require_once __DIR__ . "/Connection/hr1_db.php";
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Config load error: ' . $e->getMessage()]);
    exit;
}

// Discard any stray output from includes
ob_end_clean();

// Auth check - must be logged in as admin OR internal call
$userRole = strtolower(str_replace(' ', '', $_SESSION['role'] ?? ''));
$isAdmin = in_array($userRole, ['admin', 'manager', 'superadmin']);
$isInternal = (php_sapi_name() === 'cli') || (isset($_SERVER['HTTP_X_INTERNAL_SYNC']) && $_SERVER['HTTP_X_INTERNAL_SYNC'] === 'hr2_sync_2026');

if (!$isAdmin && !$isInternal) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Role: ' . ($userRole ?: 'none')]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'sync';

try {
    switch ($action) {
        case 'sync':
            $result = syncEmployees($conn);
            break;
        
        case 'status':
            $result = getSyncStatus($conn);
            break;
        
        case 'list':
            $result = listEmployeeAccounts($conn);
            break;
        
        case 'toggle':
            $empId = (int)($_POST['employee_id'] ?? 0);
            $active = (int)($_POST['is_active'] ?? 1);
            $result = toggleAccount($conn, $empId, $active);
            break;
        
        case 'reset_password':
            $empId = (int)($_POST['employee_id'] ?? 0);
            $result = resetPassword($conn, $empId);
            break;

        default:
            $result = ['status' => 'error', 'message' => 'Invalid action'];
    }
    
    echo json_encode($result);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Sync all HR1 employees to users_employee
 */
function syncEmployees($conn) {
    $hr1db = new HR1Database();
    $hr1pdo = $hr1db->getPDO();
    
    if (!$hr1pdo) {
        return ['status' => 'error', 'message' => 'Cannot connect to HR1 database'];
    }
    
    // Ensure table exists
    ensureTable($conn);
    
    // Fetch all HR1 employees
    $hr1query = "
        SELECT 
            e.id as employee_id,
            e.user_id,
            e.employee_code,
            e.emp_code,
            COALESCE(e.name, u.name, 'Unknown') as full_name,
            COALESCE(e.email, u.email) as email,
            e.phone,
            e.role as job_position,
            e.department,
            e.site,
            e.status as employment_status,
            e.employment_type,
            e.date_hired,
            e.is_archived,
            u.password_hash,
            u.is_active as user_is_active
        FROM employees e
        LEFT JOIN users u ON e.user_id = u.id
        WHERE e.is_archived = 0
        ORDER BY e.id ASC
    ";
    
    try {
        $employees = $hr1pdo->query($hr1query)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => 'HR1 query error: ' . $e->getMessage()];
    }
    
    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    $errors = 0;
    $newAccounts = [];
    
    $checkStmt = $conn->prepare("SELECT id FROM users_employee WHERE hr1_employee_id = ?");
    $defaultAvatar = 'uploads/avatars/default.png';
    $insertStmt = $conn->prepare("
        INSERT INTO users_employee 
        (hr1_employee_id, hr1_user_id, employee_code, full_name, email, password, phone, 
         job_position, department, site, employment_status, employment_type, 
         date_hired, is_active, avatar, synced_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $updateStmt = $conn->prepare("
        UPDATE users_employee SET 
            hr1_user_id = ?, employee_code = ?, full_name = ?, email = ?, phone = ?,
            job_position = ?, department = ?, site = ?, employment_status = ?, 
            employment_type = ?, date_hired = ?, is_active = ?, synced_at = NOW()
        WHERE hr1_employee_id = ?
    ");
    
    foreach ($employees as $emp) {
        $email = trim($emp['email'] ?? '');
        if (empty($email)) { $skipped++; continue; }
        
        $hr1_employee_id = (int)$emp['employee_id'];
        $hr1_user_id = $emp['user_id'] ? (int)$emp['user_id'] : null;
        $employee_code = $emp['employee_code'] ?? $emp['emp_code'] ?? null;
        $full_name = $emp['full_name'];
        $phone = $emp['phone'] ?? null;
        $job_position = $emp['job_position'] ?? null;
        $department = $emp['department'] ?? 'Operations';
        $site = $emp['site'] ?? 'Main Store';
        $employment_status = $emp['employment_status'] ?? 'active';
        $employment_type = $emp['employment_type'] ?? 'full_time';
        $date_hired = $emp['date_hired'] ?? null;
        $is_active = ($emp['is_archived'] == 0 && $employment_status !== 'inactive') ? 1 : 0;
        
        // Password
        if (!empty($emp['password_hash'])) {
            $password = $emp['password_hash'];
        } else {
            $emailPrefix = explode('@', $email)[0];
            $password = password_hash($emailPrefix . '2026', PASSWORD_BCRYPT);
        }
        
        $checkStmt->bind_param("i", $hr1_employee_id);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()->num_rows > 0;
        
        if ($exists) {
            $updateStmt->bind_param(
                "isssssssssssi",
                $hr1_user_id, $employee_code, $full_name, $email, $phone,
                $job_position, $department, $site, $employment_status,
                $employment_type, $date_hired, $is_active, $hr1_employee_id
            );
            if ($updateStmt->execute()) { $updated++; } else { $errors++; }
        } else {
            $insertStmt->bind_param(
                "iisssssssssssis",
                $hr1_employee_id, $hr1_user_id, $employee_code, $full_name, $email,
                $password, $phone, $job_position, $department, $site,
                $employment_status, $employment_type, $date_hired, $is_active, $defaultAvatar
            );
            if ($insertStmt->execute()) {
                $inserted++;
                $newAccounts[] = ['name' => $full_name, 'email' => $email];
            } else {
                if ($conn->errno === 1062) { $skipped++; } else { $errors++; }
            }
        }
    }
    
    $checkStmt->close();
    $insertStmt->close();
    $updateStmt->close();
    
    // Get total count
    $total = $conn->query("SELECT COUNT(*) as c FROM users_employee")->fetch_assoc()['c'];
    
    return [
        'status' => 'success',
        'message' => "Sync complete: {$inserted} new, {$updated} updated, {$skipped} skipped",
        'data' => [
            'hr1_total' => count($employees),
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'total_accounts' => (int)$total,
            'new_accounts' => $newAccounts,
            'synced_at' => date('Y-m-d H:i:s')
        ]
    ];
}

/**
 * Get sync status
 */
function getSyncStatus($conn) {
    // Check if table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'users_employee'");
    if ($tableCheck->num_rows === 0) {
        return ['status' => 'success', 'data' => ['table_exists' => false, 'total' => 0]];
    }
    
    $stats = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as disabled,
            SUM(CASE WHEN last_login IS NOT NULL THEN 1 ELSE 0 END) as has_logged_in,
            MAX(synced_at) as last_sync,
            MAX(last_login) as last_login
        FROM users_employee
    ")->fetch_assoc();
    
    // Count by department
    $deptResult = $conn->query("
        SELECT department, COUNT(*) as count 
        FROM users_employee 
        WHERE is_active = 1 
        GROUP BY department 
        ORDER BY count DESC
    ");
    $departments = [];
    while ($row = $deptResult->fetch_assoc()) {
        $departments[] = $row;
    }
    
    return [
        'status' => 'success',
        'data' => [
            'table_exists' => true,
            'total' => (int)$stats['total'],
            'active' => (int)$stats['active'],
            'disabled' => (int)$stats['disabled'],
            'has_logged_in' => (int)$stats['has_logged_in'],
            'last_sync' => $stats['last_sync'],
            'last_login' => $stats['last_login'],
            'departments' => $departments
        ]
    ];
}

/**
 * List all employee accounts
 */
function listEmployeeAccounts($conn) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'users_employee'");
    if ($tableCheck->num_rows === 0) {
        return ['status' => 'error', 'message' => 'Table does not exist. Run sync first.'];
    }
    
    $search = $_GET['search'] ?? '';
    $department = $_GET['department'] ?? '';
    $status = $_GET['status'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    $where = "WHERE 1=1";
    $params = [];
    $types = "";
    
    if ($search) {
        $where .= " AND (full_name LIKE ? OR email LIKE ? OR employee_code LIKE ?)";
        $searchTerm = "%{$search}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $types .= "sss";
    }
    
    if ($department) {
        $where .= " AND department = ?";
        $params[] = $department;
        $types .= "s";
    }
    
    if ($status !== '') {
        $where .= " AND is_active = ?";
        $params[] = (int)$status;
        $types .= "i";
    }
    
    // Count total
    $countQuery = "SELECT COUNT(*) as total FROM users_employee $where";
    $countStmt = $conn->prepare($countQuery);
    if ($params) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();
    
    // Fetch page
    $query = "
        SELECT id, hr1_employee_id, employee_code, full_name, email, phone,
               job_position, department, site, employment_status, employment_type,
               date_hired, is_active, last_login, login_count, synced_at, created_at
        FROM users_employee 
        $where 
        ORDER BY full_name ASC 
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $conn->prepare($query);
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $employees = [];
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    $stmt->close();
    
    return [
        'status' => 'success',
        'data' => [
            'employees' => $employees,
            'total' => (int)$total,
            'page' => $page,
            'pages' => ceil($total / $limit),
            'limit' => $limit
        ]
    ];
}

/**
 * Toggle account active/disabled
 */
function toggleAccount($conn, $empId, $isActive) {
    if ($empId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid employee ID'];
    }
    
    $stmt = $conn->prepare("UPDATE users_employee SET is_active = ? WHERE id = ?");
    $stmt->bind_param("ii", $isActive, $empId);
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $statusText = $isActive ? 'activated' : 'disabled';
        return ['status' => 'success', 'message' => "Account {$statusText} successfully"];
    }
    
    $stmt->close();
    return ['status' => 'error', 'message' => 'Failed to update account'];
}

/**
 * Reset employee password to default
 */
function resetPassword($conn, $empId) {
    if ($empId <= 0) {
        return ['status' => 'error', 'message' => 'Invalid employee ID'];
    }
    
    $stmt = $conn->prepare("SELECT email, full_name FROM users_employee WHERE id = ?");
    $stmt->bind_param("i", $empId);
    $stmt->execute();
    $emp = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$emp) {
        return ['status' => 'error', 'message' => 'Employee not found'];
    }
    
    $emailPrefix = explode('@', $emp['email'])[0];
    $defaultPass = $emailPrefix . '2026';
    $hashedPass = password_hash($defaultPass, PASSWORD_BCRYPT);
    
    $updateStmt = $conn->prepare("UPDATE users_employee SET password = ? WHERE id = ?");
    $updateStmt->bind_param("si", $hashedPass, $empId);
    
    if ($updateStmt->execute()) {
        $updateStmt->close();
        return [
            'status' => 'success',
            'message' => "Password reset for {$emp['full_name']}",
            'default_password' => $defaultPass
        ];
    }
    
    $updateStmt->close();
    return ['status' => 'error', 'message' => 'Failed to reset password'];
}

/**
 * Ensure table exists
 */
function ensureTable($conn) {
    $createTable = "
    CREATE TABLE IF NOT EXISTS `users_employee` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `hr1_employee_id` int(11) NOT NULL,
      `hr1_user_id` int(11) DEFAULT NULL,
      `employee_code` varchar(30) DEFAULT NULL,
      `full_name` varchar(255) NOT NULL,
      `email` varchar(150) NOT NULL,
      `password` varchar(255) NOT NULL,
      `phone` varchar(50) DEFAULT NULL,
      `address` varchar(255) DEFAULT NULL,
      `role` varchar(50) NOT NULL DEFAULT 'employee',
      `job_position` varchar(150) DEFAULT NULL,
      `department` varchar(150) DEFAULT 'Operations',
      `site` varchar(150) DEFAULT 'Main Store',
      `avatar` varchar(500) DEFAULT 'default.png',
      `emergency_contact` varchar(255) DEFAULT NULL,
      `emergency_phone` varchar(50) DEFAULT NULL,
      `employment_status` enum('onboarding','probation','active','on_leave','inactive') DEFAULT 'active',
      `employment_type` enum('full_time','part_time','contractual','probationary') DEFAULT 'full_time',
      `date_hired` date DEFAULT NULL,
      `is_active` tinyint(1) NOT NULL DEFAULT 1,
      `is_verified` tinyint(1) NOT NULL DEFAULT 0,
      `verify_token` varchar(255) DEFAULT NULL,
      `verification_code` varchar(6) DEFAULT NULL,
      `email_verification_code` varchar(255) DEFAULT NULL,
      `otp_code` varchar(6) DEFAULT NULL,
      `otp_expiry` datetime DEFAULT NULL,
      `last_login` datetime DEFAULT NULL,
      `login_count` int(11) NOT NULL DEFAULT 0,
      `synced_at` datetime DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `email` (`email`),
      UNIQUE KEY `hr1_employee_id` (`hr1_employee_id`),
      KEY `idx_employee_code` (`employee_code`),
      KEY `idx_is_active` (`is_active`),
      KEY `idx_department` (`department`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ";
    $conn->query($createTable);
    
    // Set default avatar for existing records with NULL avatar
    $conn->query("UPDATE users_employee SET avatar = 'uploads/avatars/default.png' WHERE avatar IS NULL");
}
?>

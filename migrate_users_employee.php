<?php
/**
 * Migration: Create users_employee table & Auto-Sync HR1 Employees
 * 
 * This script:
 * 1. Creates the `users_employee` table in HR2 database
 * 2. Fetches ALL employees from HR1 database
 * 3. Auto-creates accounts for each HR1 employee in users_employee
 * 4. Generates default passwords (employee email prefix + last 4 digits of employee code)
 * 
 * Run this once, then the sync API will keep it updated.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

include(__DIR__ . "/Connection/Config.php");
require_once __DIR__ . "/Connection/hr1_db.php";

echo "<pre style='font-family: Consolas, monospace; background: #1e293b; color: #e2e8f0; padding: 20px; border-radius: 12px; max-width: 900px; margin: 40px auto;'>";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║   HR2 MerchFlow - Users Employee Migration                  ║\n";
echo "║   Auto-sync HR1 employees → HR2 login accounts             ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n\n";

// ============================================================
// STEP 1: Create users_employee table
// ============================================================
echo "▶ STEP 1: Creating users_employee table...\n";

$createTable = "
CREATE TABLE IF NOT EXISTS `users_employee` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hr1_employee_id` int(11) NOT NULL COMMENT 'Employee ID from HR1 employees table',
  `hr1_user_id` int(11) DEFAULT NULL COMMENT 'User ID from HR1 users table',
  `employee_code` varchar(30) DEFAULT NULL COMMENT 'Employee code from HR1',
  `full_name` varchar(255) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'Bcrypt hashed password',
  `phone` varchar(50) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'employee',
  `job_position` varchar(150) DEFAULT NULL,
  `department` varchar(150) DEFAULT 'Operations',
  `site` varchar(150) DEFAULT 'Main Store',
  `avatar` varchar(500) DEFAULT NULL COMMENT 'Photo URL from HR1',
  `emergency_contact` varchar(255) DEFAULT NULL,
  `emergency_phone` varchar(50) DEFAULT NULL,
  `employment_status` enum('onboarding','probation','active','on_leave','inactive') DEFAULT 'active',
  `employment_type` enum('full_time','part_time','contractual','probationary') DEFAULT 'full_time',
  `date_hired` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=can login, 0=disabled',
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `verify_token` varchar(255) DEFAULT NULL,
  `verification_code` varchar(6) DEFAULT NULL,
  `email_verification_code` varchar(255) DEFAULT NULL,
  `otp_code` varchar(6) DEFAULT NULL,
  `otp_expiry` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `login_count` int(11) NOT NULL DEFAULT 0,
  `synced_at` datetime DEFAULT NULL COMMENT 'Last sync from HR1',
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

if ($conn->query($createTable)) {
    echo "  ✅ Table `users_employee` created (or already exists)\n\n";
} else {
    echo "  ❌ Error creating table: " . $conn->error . "\n\n";
    exit;
}

// Add missing columns if they don't exist (safe for existing tables)
$alterColumns = [
    "ADD COLUMN `address` varchar(255) DEFAULT NULL AFTER `phone`",
    "ADD COLUMN `role` varchar(50) NOT NULL DEFAULT 'employee' AFTER `address`",
    "ADD COLUMN `is_verified` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_active`",
    "ADD COLUMN `verify_token` varchar(255) DEFAULT NULL AFTER `is_verified`",
    "ADD COLUMN `verification_code` varchar(6) DEFAULT NULL AFTER `verify_token`",
    "ADD COLUMN `email_verification_code` varchar(255) DEFAULT NULL AFTER `verification_code`",
    "ADD COLUMN `otp_code` varchar(6) DEFAULT NULL AFTER `email_verification_code`",
    "ADD COLUMN `otp_expiry` datetime DEFAULT NULL AFTER `otp_code`",
    "ADD COLUMN `emergency_contact` varchar(255) DEFAULT NULL AFTER `avatar`",
    "ADD COLUMN `emergency_phone` varchar(50) DEFAULT NULL AFTER `emergency_contact`"
];

echo "▶ STEP 1b: Adding missing columns (if any)...\n";
foreach ($alterColumns as $alter) {
    $sql = "ALTER TABLE `users_employee` $alter";
    if (@$conn->query($sql)) {
        echo "  ✅ $alter\n";
    } else {
        if (strpos($conn->error, 'Duplicate column') !== false) {
            echo "  ⏩ Column already exists, skipping\n";
        } else {
            echo "  ⚠️  " . $conn->error . "\n";
        }
    }
}
echo "\n";

// ============================================================
// STEP 2: Fetch all employees from HR1
// ============================================================
echo "▶ STEP 2: Fetching employees from HR1 database...\n";

$hr1db = new HR1Database();
$hr1pdo = $hr1db->getPDO();

if (!$hr1pdo) {
    echo "  ❌ Cannot connect to HR1 database\n";
    echo "</pre>";
    exit;
}

// Fetch employees with their user account info
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
    $hr1stmt = $hr1pdo->query($hr1query);
    $employees = $hr1stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalFound = count($employees);
    echo "  📋 Found {$totalFound} active employees in HR1\n\n";
} catch (Exception $e) {
    echo "  ❌ Error fetching HR1 employees: " . $e->getMessage() . "\n";
    echo "</pre>";
    exit;
}

// ============================================================
// STEP 3: Sync employees to users_employee table
// ============================================================
echo "▶ STEP 3: Syncing employees to users_employee table...\n";
echo "  ─────────────────────────────────────────────────────\n";

$inserted = 0;
$updated = 0;
$skipped = 0;
$errors = 0;

// Prepare statements
$checkStmt = $conn->prepare("SELECT id FROM users_employee WHERE hr1_employee_id = ?");
$insertStmt = $conn->prepare("
    INSERT INTO users_employee 
    (hr1_employee_id, hr1_user_id, employee_code, full_name, email, password, phone, 
     job_position, department, site, avatar, employment_status, employment_type, 
     date_hired, is_active, synced_at) 
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
    
    // Skip employees without email
    if (empty($email)) {
        echo "  ⚠️  Skipped: {$emp['full_name']} (no email)\n";
        $skipped++;
        continue;
    }
    
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
    
    // Avatar URL from HR1
    $avatar = null;
    
    // Generate password: use HR1 password hash if available, otherwise create default
    if (!empty($emp['password_hash'])) {
        $password = $emp['password_hash']; // Already bcrypt hashed
    } else {
        // Default password: email prefix + "2026" (e.g., john2026)
        $emailPrefix = explode('@', $email)[0];
        $defaultPass = $emailPrefix . '2026';
        $password = password_hash($defaultPass, PASSWORD_BCRYPT);
    }
    
    // Check if already exists
    $checkStmt->bind_param("i", $hr1_employee_id);
    $checkStmt->execute();
    $exists = $checkStmt->get_result()->num_rows > 0;
    
    if ($exists) {
        // Update existing record
        $updateStmt->bind_param(
            "isssssssssssi",
            $hr1_user_id, $employee_code, $full_name, $email, $phone,
            $job_position, $department, $site, $employment_status,
            $employment_type, $date_hired, $is_active, $hr1_employee_id
        );
        
        if ($updateStmt->execute()) {
            $updated++;
        } else {
            echo "  ❌ Error updating {$full_name}: " . $updateStmt->error . "\n";
            $errors++;
        }
    } else {
        // Insert new record
        $insertStmt->bind_param(
            "iisssssssssssssi",
            $hr1_employee_id, $hr1_user_id, $employee_code, $full_name, $email, 
            $password, $phone, $job_position, $department, $site, $avatar,
            $employment_status, $employment_type, $date_hired, $is_active
        );
        
        if ($insertStmt->execute()) {
            echo "  ✅ Created: {$full_name} ({$email})\n";
            $inserted++;
        } else {
            // Check if duplicate email
            if ($conn->errno === 1062) {
                echo "  ⚠️  Duplicate email skipped: {$email}\n";
                $skipped++;
            } else {
                echo "  ❌ Error inserting {$full_name}: " . $insertStmt->error . "\n";
                $errors++;
            }
        }
    }
}

$checkStmt->close();
$insertStmt->close();
$updateStmt->close();

// ============================================================
// STEP 4: Summary
// ============================================================
echo "\n  ─────────────────────────────────────────────────────\n";
echo "\n▶ STEP 4: Migration Summary\n";
echo "  ╔════════════════════════════════════╗\n";
echo "  ║  Total HR1 Employees:  " . str_pad($totalFound, 10) . "  ║\n";
echo "  ║  New Accounts Created: " . str_pad($inserted, 10) . "  ║\n";
echo "  ║  Accounts Updated:     " . str_pad($updated, 10) . "  ║\n";
echo "  ║  Skipped:              " . str_pad($skipped, 10) . "  ║\n";
echo "  ║  Errors:               " . str_pad($errors, 10) . "  ║\n";
echo "  ╚════════════════════════════════════╝\n\n";

// Verify final count
$countResult = $conn->query("SELECT COUNT(*) as total FROM users_employee");
$totalInTable = $countResult->fetch_assoc()['total'];
echo "  📊 Total records in users_employee: {$totalInTable}\n\n";

echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║   ✅ Migration Complete!                                    ║\n";
echo "║                                                            ║\n";
echo "║   Employees can now login to HR2 using their HR1 email     ║\n";
echo "║   and password. If no HR1 password exists, default is:     ║\n";
echo "║   [email_prefix]2026 (e.g., john2026)                      ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "</pre>";
?>

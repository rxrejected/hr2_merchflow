<?php
/**
 * Migration: New Features - HR2 Assessment, Certificates, Contract Bonds
 * Run this file once to create the required database tables.
 * 
 * Features:
 * 1. HR2 Assessment (70% of combined score)
 * 2. Employee Certificates
 * 3. Contract Bond Management
 */

require_once 'Connection/Config.php';

echo "<h2>HR2 MerchFlow - Database Migration</h2>";
echo "<pre>";

$tables = [];

// ==========================================
// TABLE 1: HR2 Assessments (provides the 70% score)
// ==========================================
$tables['hr2_assessments'] = "
CREATE TABLE IF NOT EXISTS `hr2_assessments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `hr1_employee_id` INT(11) NOT NULL COMMENT 'Employee ID from HR1 system',
  `hr1_employee_name` VARCHAR(200) DEFAULT NULL,
  `hr1_employee_email` VARCHAR(200) DEFAULT NULL,
  `evaluator_id` INT(11) NOT NULL COMMENT 'HR2 user who performed assessment',
  `period` VARCHAR(100) DEFAULT NULL COMMENT 'Assessment period (e.g., Q1 2026)',
  `job_knowledge` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quiz answer 1-4 (A-D)',
  `work_quality` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quiz answer 1-4 (A-D)',
  `productivity` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quiz answer 1-4 (A-D)',
  `reliability` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quiz answer 1-4 (A-D)',
  `initiative` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quiz answer 1-4 (A-D)',
  `communication` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quiz answer 1-4 (A-D)',
  `teamwork` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quiz answer 1-4 (A-D)',
  `problem_solving` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quiz answer 1-4 (A-D)',
  `adaptability` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quiz answer 1-4 (A-D)',
  `leadership` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Quiz answer 1-4 (A-D)',
  `overall_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'Calculated percentage 0-100',
  `rating_label` VARCHAR(50) DEFAULT NULL,
  `comments` TEXT DEFAULT NULL,
  `strengths` TEXT DEFAULT NULL,
  `areas_for_improvement` TEXT DEFAULT NULL,
  `status` ENUM('draft','completed','approved') NOT NULL DEFAULT 'completed',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hr1_employee` (`hr1_employee_id`),
  KEY `idx_evaluator` (`evaluator_id`),
  KEY `idx_period` (`period`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
";

// ==========================================
// TABLE 2: Employee Certificates
// ==========================================
$tables['employee_certificates'] = "
CREATE TABLE IF NOT EXISTS `employee_certificates` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `hr1_employee_id` INT(11) NOT NULL COMMENT 'Employee ID from HR1 system',
  `hr1_employee_name` VARCHAR(200) DEFAULT NULL,
  `certificate_name` VARCHAR(255) NOT NULL,
  `issuing_organization` VARCHAR(255) DEFAULT NULL,
  `credential_id` VARCHAR(100) DEFAULT NULL COMMENT 'Certificate/credential ID number',
  `date_issued` DATE DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `certificate_file` VARCHAR(500) DEFAULT NULL COMMENT 'File path to uploaded certificate image/PDF',
  `description` TEXT DEFAULT NULL,
  `category` ENUM('technical','professional','academic','safety','compliance','other') NOT NULL DEFAULT 'professional',
  `uploaded_by` INT(11) NOT NULL COMMENT 'HR2 user who added this record',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hr1_employee` (`hr1_employee_id`),
  KEY `idx_category` (`category`),
  KEY `idx_uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
";

// ==========================================
// TABLE 3: Contract Bonds
// ==========================================
$tables['contract_bonds'] = "
CREATE TABLE IF NOT EXISTS `contract_bonds` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `hr1_employee_id` INT(11) NOT NULL COMMENT 'Employee ID from HR1 system',
  `hr1_employee_name` VARCHAR(200) DEFAULT NULL,
  `bond_type` ENUM('training','scholarship','equipment','relocation','signing','other') NOT NULL DEFAULT 'training',
  `description` TEXT DEFAULT NULL COMMENT 'Bond description/reason',
  `training_program` VARCHAR(255) DEFAULT NULL COMMENT 'Related training/scholarship name',
  `bond_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Bond amount in PHP',
  `company_investment` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Total company investment',
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `bond_duration_months` INT(11) DEFAULT NULL,
  `conditions` TEXT DEFAULT NULL COMMENT 'Bond conditions/terms',
  `penalty_clause` TEXT DEFAULT NULL COMMENT 'Early termination penalty details',
  `remaining_balance` DECIMAL(12,2) DEFAULT NULL COMMENT 'Remaining bond balance if partially served',
  `status` ENUM('active','completed','terminated','breached') NOT NULL DEFAULT 'active',
  `termination_date` DATE DEFAULT NULL,
  `termination_reason` TEXT DEFAULT NULL,
  `created_by` INT(11) NOT NULL COMMENT 'HR2 user who created this record',
  `approved_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hr1_employee` (`hr1_employee_id`),
  KEY `idx_status` (`status`),
  KEY `idx_bond_type` (`bond_type`),
  KEY `idx_dates` (`start_date`, `end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
";

// Run all migrations
$success = 0;
$failed = 0;

foreach ($tables as $name => $sql) {
    echo "Creating table '{$name}'... ";
    if ($conn->query($sql)) {
        echo "✅ SUCCESS\n";
        $success++;
    } else {
        echo "❌ FAILED: " . $conn->error . "\n";
        $failed++;
    }
}

echo "\n=============================\n";
echo "Migration Complete!\n";
echo "Success: {$success} | Failed: {$failed}\n";
echo "=============================\n";

// Create uploads directory for certificates
$certDir = __DIR__ . '/uploads/certificates';
if (!is_dir($certDir)) {
    if (mkdir($certDir, 0755, true)) {
        echo "\n✅ Created uploads/certificates directory\n";
    } else {
        echo "\n⚠️ Could not create uploads/certificates directory - please create manually\n";
    }
}

echo "</pre>";
echo "<br><a href='admin.php'>← Back to Dashboard</a>";

$conn->close();
?>

<?php
/**
 * Migration: Employee Evaluation Pipeline
 * Creates the tables needed for the HR1→HR2 employee evaluation flow:
 * 
 * FLOW:
 * 1. HR1 Evaluation (completed) → Employee enters pipeline
 * 2. HR2 assigns courses to employee
 * 3. Employee completes courses (course_progress)
 * 4. Employee takes course assessments (quiz/MCQ)
 * 5. HR2 evaluates employee based on combined scores
 * 
 * Tables Created:
 * - employee_pipeline: Tracks each employee's journey through the system
 * - course_assignments: Links HR1 employees to required courses
 * - course_quiz_results: Stores quiz/assessment results per employee per course
 */

require_once 'Connection/Config.php';

echo "<h2>🔄 HR2 MerchFlow - Employee Pipeline Migration</h2>";
echo "<pre style='font-family: Consolas, monospace; background: #1e293b; color: #e2e8f0; padding: 20px; border-radius: 12px;'>";

$tables = [];

// ==========================================
// TABLE 1: Employee Pipeline (Master tracker)
// ==========================================
$tables['employee_pipeline'] = "
CREATE TABLE IF NOT EXISTS `employee_pipeline` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `hr1_employee_id` INT(11) NOT NULL COMMENT 'Employee ID from HR1',
  `hr1_employee_name` VARCHAR(200) NOT NULL,
  `hr1_employee_email` VARCHAR(200) DEFAULT NULL,
  `department` VARCHAR(100) DEFAULT 'Operations',
  `position` VARCHAR(100) DEFAULT 'Employee',
  `employee_type` ENUM('new','regular') NOT NULL DEFAULT 'new' COMMENT 'New or Old employee',
  `date_hired` DATE DEFAULT NULL,
  `months_tenure` INT(11) DEFAULT 0,
  
  -- Stage 1: HR1 Evaluation
  `hr1_eval_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'Score from HR1 evaluation (0-100)',
  `hr1_eval_rating` VARCHAR(50) DEFAULT NULL,
  `hr1_eval_date` DATE DEFAULT NULL,
  
  -- Stage 2: Course Assignment
  `courses_assigned` INT(11) DEFAULT 0,
  `courses_completed` INT(11) DEFAULT 0,
  `course_completion_pct` DECIMAL(5,2) DEFAULT 0.00,
  
  -- Stage 3: Assessment/Quiz
  `assessments_taken` INT(11) DEFAULT 0,
  `assessments_passed` INT(11) DEFAULT 0,
  `avg_assessment_score` DECIMAL(5,2) DEFAULT 0.00,
  
  -- Stage 4: HR2 Final Evaluation
  `hr2_eval_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'HR2 assessment score (0-100)',
  `hr2_eval_rating` VARCHAR(50) DEFAULT NULL,
  `hr2_eval_date` DATE DEFAULT NULL,
  
  -- Combined
  `combined_score` DECIMAL(5,2) DEFAULT NULL COMMENT '30% HR1 + 70% HR2',
  `combined_rating` VARCHAR(50) DEFAULT NULL,
  `final_recommendation` TEXT DEFAULT NULL,
  
  -- Pipeline status
  `current_stage` ENUM('hr1_evaluated','courses_assigned','learning','assessment','hr2_evaluated','completed') NOT NULL DEFAULT 'hr1_evaluated',
  `status` ENUM('active','on_hold','completed','terminated') NOT NULL DEFAULT 'active',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_hr1_employee` (`hr1_employee_id`),
  KEY `idx_stage` (`current_stage`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`employee_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
";

// ==========================================
// TABLE 2: Course Assignments (Links employees to courses)
// ==========================================
$tables['course_assignments'] = "
CREATE TABLE IF NOT EXISTS `course_assignments` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `pipeline_id` INT(11) NOT NULL COMMENT 'FK to employee_pipeline',
  `hr1_employee_id` INT(11) NOT NULL,
  `course_id` INT(10) UNSIGNED NOT NULL COMMENT 'FK to courses table',
  `assigned_by` INT(11) NOT NULL COMMENT 'Admin who assigned',
  `due_date` DATE DEFAULT NULL,
  `watch_progress` INT(11) DEFAULT 0 COMMENT 'Video watch percentage',
  `is_completed` TINYINT(1) DEFAULT 0,
  `completed_at` DATETIME DEFAULT NULL,
  `status` ENUM('assigned','in_progress','completed','overdue') NOT NULL DEFAULT 'assigned',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_emp_course` (`hr1_employee_id`, `course_id`),
  KEY `idx_pipeline` (`pipeline_id`),
  KEY `idx_course` (`course_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
";

// ==========================================
// TABLE 3: Course Quiz Results
// ==========================================
$tables['course_quiz_results'] = "
CREATE TABLE IF NOT EXISTS `course_quiz_results` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `pipeline_id` INT(11) NOT NULL,
  `hr1_employee_id` INT(11) NOT NULL,
  `course_id` INT(10) UNSIGNED NOT NULL,
  `assessment_id` INT(11) DEFAULT NULL COMMENT 'FK to assessments table',
  `total_questions` INT(11) DEFAULT 0,
  `correct_answers` INT(11) DEFAULT 0,
  `score_percentage` DECIMAL(5,2) DEFAULT 0.00,
  `passed` TINYINT(1) DEFAULT 0 COMMENT '1=passed (>=70%), 0=failed',
  `time_taken_seconds` INT(11) DEFAULT NULL,
  `attempt_number` INT(11) DEFAULT 1,
  `answers_json` JSON DEFAULT NULL COMMENT 'Detailed answers for review',
  `evaluated_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pipeline` (`pipeline_id`),
  KEY `idx_employee` (`hr1_employee_id`),
  KEY `idx_course` (`course_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
";

// Execute migrations
$success = 0;
$failed = 0;

foreach ($tables as $name => $sql) {
    echo "\n📦 Creating table: <strong>{$name}</strong>...";
    if ($conn->query($sql)) {
        echo " ✅ SUCCESS\n";
        $success++;
    } else {
        echo " ❌ FAILED: " . $conn->error . "\n";
        $failed++;
    }
}

// ==========================================
// INSERT SAMPLE DATA
// ==========================================
echo "\n\n" . str_repeat('=', 60);
echo "\n📊 INSERTING SAMPLE EMPLOYEE PIPELINE DATA\n";
echo str_repeat('=', 60) . "\n";

// Sample employees (mix of new and regular)
$sampleEmployees = [
    // New employees (< 6 months)
    ['hr1_id' => 101, 'name' => 'Maria Clara Santos', 'email' => 'maria.santos@osave.com', 'dept' => 'Retail Operations', 'position' => 'Store Cashier', 'type' => 'new', 'hired' => date('Y-m-d', strtotime('-3 months')), 'months' => 3, 'hr1_score' => 72.5, 'hr1_rating' => 'Very Good', 'hr1_date' => date('Y-m-d', strtotime('-2 months'))],
    ['hr1_id' => 102, 'name' => 'Juan Miguel Reyes', 'email' => 'juan.reyes@osave.com', 'dept' => 'Retail Operations', 'position' => 'Store Helper', 'type' => 'new', 'hired' => date('Y-m-d', strtotime('-1 month')), 'months' => 1, 'hr1_score' => 58.0, 'hr1_rating' => 'Fair', 'hr1_date' => date('Y-m-d', strtotime('-3 weeks'))],
    ['hr1_id' => 103, 'name' => 'Angela Mae Cruz', 'email' => 'angela.cruz@osave.com', 'dept' => 'Customer Service', 'position' => 'Service Associate', 'type' => 'new', 'hired' => date('Y-m-d', strtotime('-5 months')), 'months' => 5, 'hr1_score' => 85.0, 'hr1_rating' => 'Excellent', 'hr1_date' => date('Y-m-d', strtotime('-1 month'))],
    ['hr1_id' => 104, 'name' => 'Patrick James Go', 'email' => 'patrick.go@osave.com', 'dept' => 'Warehouse', 'position' => 'Inventory Clerk', 'type' => 'new', 'hired' => date('Y-m-d', strtotime('-2 months')), 'months' => 2, 'hr1_score' => 45.0, 'hr1_rating' => 'Needs Improvement', 'hr1_date' => date('Y-m-d', strtotime('-1 month'))],
    
    // Regular employees (> 6 months)
    ['hr1_id' => 105, 'name' => 'Ricardo Dela Cruz', 'email' => 'ricardo.delacruz@osave.com', 'dept' => 'Retail Operations', 'position' => 'Assistant Store Manager', 'type' => 'regular', 'hired' => date('Y-m-d', strtotime('-18 months')), 'months' => 18, 'hr1_score' => 91.0, 'hr1_rating' => 'Outstanding', 'hr1_date' => date('Y-m-d', strtotime('-2 weeks'))],
    ['hr1_id' => 106, 'name' => 'Sophia Isabelle Lim', 'email' => 'sophia.lim@osave.com', 'dept' => 'Retail Operations', 'position' => 'Senior Cashier', 'type' => 'regular', 'hired' => date('Y-m-d', strtotime('-14 months')), 'months' => 14, 'hr1_score' => 78.5, 'hr1_rating' => 'Very Good', 'hr1_date' => date('Y-m-d', strtotime('-3 weeks'))],
    ['hr1_id' => 107, 'name' => 'Mark Anthony Bautista', 'email' => 'mark.bautista@osave.com', 'dept' => 'Warehouse', 'position' => 'Warehouse Supervisor', 'type' => 'regular', 'hired' => date('Y-m-d', strtotime('-24 months')), 'months' => 24, 'hr1_score' => 88.0, 'hr1_rating' => 'Excellent', 'hr1_date' => date('Y-m-d', strtotime('-1 month'))],
    ['hr1_id' => 108, 'name' => 'Cherry Anne Villanueva', 'email' => 'cherry.villanueva@osave.com', 'dept' => 'Customer Service', 'position' => 'Customer Service Lead', 'type' => 'regular', 'hired' => date('Y-m-d', strtotime('-10 months')), 'months' => 10, 'hr1_score' => 65.0, 'hr1_rating' => 'Good', 'hr1_date' => date('Y-m-d', strtotime('-2 months'))],
    ['hr1_id' => 109, 'name' => 'Daniel Joseph Tan', 'email' => 'daniel.tan@osave.com', 'dept' => 'Retail Operations', 'position' => 'Store Manager', 'type' => 'regular', 'hired' => date('Y-m-d', strtotime('-30 months')), 'months' => 30, 'hr1_score' => 94.5, 'hr1_rating' => 'Outstanding', 'hr1_date' => date('Y-m-d', strtotime('-1 week'))],
    ['hr1_id' => 110, 'name' => 'Princess Joy Mendoza', 'email' => 'princess.mendoza@osave.com', 'dept' => 'Admin', 'position' => 'HR Assistant', 'type' => 'new', 'hired' => date('Y-m-d', strtotime('-4 months')), 'months' => 4, 'hr1_score' => 70.0, 'hr1_rating' => 'Very Good', 'hr1_date' => date('Y-m-d', strtotime('-3 weeks'))],
];

// Insert pipeline records
$insertPipeline = $conn->prepare("
    INSERT IGNORE INTO employee_pipeline 
    (hr1_employee_id, hr1_employee_name, hr1_employee_email, department, position, employee_type, 
     date_hired, months_tenure, hr1_eval_score, hr1_eval_rating, hr1_eval_date, current_stage, status, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 1)
");

if (!$insertPipeline) {
    echo "❌ Prepare failed: " . $conn->error . "\n";
} else {
    // Assign different stages for variety
    $stages = [
        101 => 'learning',           // Maria: Currently taking courses
        102 => 'courses_assigned',    // Juan: Just got assigned courses
        103 => 'assessment',          // Angela: Taking assessments
        104 => 'courses_assigned',    // Patrick: Just got assigned courses
        105 => 'completed',           // Ricardo: Fully evaluated
        106 => 'hr2_evaluated',       // Sophia: HR2 evaluation done
        107 => 'assessment',          // Mark: Taking assessments
        108 => 'learning',            // Cherry: Currently taking courses
        109 => 'completed',           // Daniel: Fully evaluated
        110 => 'hr1_evaluated',       // Princess: Just entered pipeline
    ];
    
    foreach ($sampleEmployees as $emp) {
        $stage = $stages[$emp['hr1_id']];
        $insertPipeline->bind_param('issssssidss' . 's',
            $emp['hr1_id'], $emp['name'], $emp['email'], $emp['dept'], $emp['position'],
            $emp['type'], $emp['hired'], $emp['months'], $emp['hr1_score'], 
            $emp['hr1_rating'], $emp['hr1_date'], $stage
        );
        
        if ($insertPipeline->execute()) {
            $icon = $emp['type'] === 'new' ? '🆕' : '👤';
            echo "{$icon} Pipeline: {$emp['name']} [{$emp['type']}] → Stage: {$stage}\n";
        } else {
            if ($conn->errno === 1062) {
                echo "⚠️ Already exists: {$emp['name']}\n";
            } else {
                echo "❌ Failed: {$emp['name']} - " . $insertPipeline->error . "\n";
            }
        }
    }
    $insertPipeline->close();
}

// Get available courses
$coursesResult = $conn->query("SELECT course_id, title FROM courses ORDER BY course_id");
$availableCourses = [];
if ($coursesResult) {
    while ($c = $coursesResult->fetch_assoc()) {
        $availableCourses[] = $c;
    }
}

echo "\n📚 Available courses: " . count($availableCourses) . "\n";

// Insert course assignments for employees who are past the assignment stage
if (!empty($availableCourses)) {
    $insertAssignment = $conn->prepare("
        INSERT IGNORE INTO course_assignments 
        (pipeline_id, hr1_employee_id, course_id, assigned_by, due_date, watch_progress, is_completed, completed_at, status)
        VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?)
    ");
    
    if ($insertAssignment) {
        // Get pipeline IDs
        $pipelineIds = [];
        $pRes = $conn->query("SELECT id, hr1_employee_id, current_stage FROM employee_pipeline");
        if ($pRes) {
            while ($p = $pRes->fetch_assoc()) {
                $pipelineIds[$p['hr1_employee_id']] = ['id' => $p['id'], 'stage' => $p['current_stage']];
            }
        }
        
        // Course assignment data per employee
        $assignmentData = [
            101 => ['progress' => [65, 30], 'completed' => [0, 0]],     // Maria: Learning - in progress
            102 => ['progress' => [0, 0], 'completed' => [0, 0]],       // Juan: Just assigned
            103 => ['progress' => [100, 100], 'completed' => [1, 1]],   // Angela: All done, now assessing
            104 => ['progress' => [5, 0], 'completed' => [0, 0]],       // Patrick: Just started
            105 => ['progress' => [100, 100], 'completed' => [1, 1]],   // Ricardo: All completed
            106 => ['progress' => [100, 100], 'completed' => [1, 1]],   // Sophia: All completed
            107 => ['progress' => [100, 80], 'completed' => [1, 0]],    // Mark: Almost done
            108 => ['progress' => [45, 10], 'completed' => [0, 0]],     // Cherry: In progress
            109 => ['progress' => [100, 100], 'completed' => [1, 1]],   // Daniel: All completed
            110 => ['progress' => [0, 0], 'completed' => [0, 0]],       // Princess: Not assigned yet
        ];
        
        $coursesAssigned = 0;
        foreach ($assignmentData as $hr1Id => $data) {
            if ($hr1Id == 110) continue; // Princess hasn't been assigned yet
            
            $pInfo = $pipelineIds[$hr1Id] ?? null;
            if (!$pInfo) continue;
            
            foreach ($availableCourses as $idx => $course) {
                if ($idx >= 2) break; // Assign max 2 courses
                
                $progress = $data['progress'][$idx] ?? 0;
                $completed = $data['completed'][$idx] ?? 0;
                $dueDate = date('Y-m-d', strtotime('+30 days'));
                $completedAt = $completed ? date('Y-m-d H:i:s', strtotime('-' . rand(1, 14) . ' days')) : null;
                $status = $completed ? 'completed' : ($progress > 0 ? 'in_progress' : 'assigned');
                
                $insertAssignment->bind_param('iiisissss',
                    $pInfo['id'], $hr1Id, $course['course_id'],
                    $dueDate, $progress, $completed, $completedAt, $status
                );
                
                if ($insertAssignment->execute()) {
                    $coursesAssigned++;
                }
            }
        }
        $insertAssignment->close();
        echo "📝 Course assignments created: {$coursesAssigned}\n";
    }
    
    // Insert quiz results for employees who have taken assessments
    echo "\n📝 Creating quiz results...\n";
    
    $insertQuiz = $conn->prepare("
        INSERT IGNORE INTO course_quiz_results
        (pipeline_id, hr1_employee_id, course_id, total_questions, correct_answers, score_percentage, passed, attempt_number, evaluated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)
    ");
    
    if ($insertQuiz) {
        $quizData = [
            // Angela (103) - assessment stage, took both quizzes
            ['hr1_id' => 103, 'course_idx' => 0, 'total' => 10, 'correct' => 8, 'score' => 80.0, 'passed' => 1],
            ['hr1_id' => 103, 'course_idx' => 1, 'total' => 10, 'correct' => 9, 'score' => 90.0, 'passed' => 1],
            // Ricardo (105) - completed, took both quizzes
            ['hr1_id' => 105, 'course_idx' => 0, 'total' => 10, 'correct' => 10, 'score' => 100.0, 'passed' => 1],
            ['hr1_id' => 105, 'course_idx' => 1, 'total' => 10, 'correct' => 9, 'score' => 90.0, 'passed' => 1],
            // Sophia (106) - HR2 evaluated, took both quizzes
            ['hr1_id' => 106, 'course_idx' => 0, 'total' => 10, 'correct' => 7, 'score' => 70.0, 'passed' => 1],
            ['hr1_id' => 106, 'course_idx' => 1, 'total' => 10, 'correct' => 8, 'score' => 80.0, 'passed' => 1],
            // Mark (107) - assessment stage, took 1 quiz
            ['hr1_id' => 107, 'course_idx' => 0, 'total' => 10, 'correct' => 9, 'score' => 90.0, 'passed' => 1],
            // Daniel (109) - completed, took both quizzes
            ['hr1_id' => 109, 'course_idx' => 0, 'total' => 10, 'correct' => 10, 'score' => 100.0, 'passed' => 1],
            ['hr1_id' => 109, 'course_idx' => 1, 'total' => 10, 'correct' => 10, 'score' => 100.0, 'passed' => 1],
        ];
        
        $quizzesCreated = 0;
        foreach ($quizData as $q) {
            $pInfo = $pipelineIds[$q['hr1_id']] ?? null;
            if (!$pInfo || !isset($availableCourses[$q['course_idx']])) continue;
            
            $courseId = $availableCourses[$q['course_idx']]['course_id'];
            $insertQuiz->bind_param('iiiiidi',
                $pInfo['id'], $q['hr1_id'], $courseId,
                $q['total'], $q['correct'], $q['score'], $q['passed']
            );
            
            if ($insertQuiz->execute()) {
                $quizzesCreated++;
            }
        }
        $insertQuiz->close();
        echo "✅ Quiz results created: {$quizzesCreated}\n";
    }
    
    // Update pipeline stats
    echo "\n🔄 Updating pipeline statistics...\n";
    
    // Update courses_assigned and courses_completed counts
    $conn->query("
        UPDATE employee_pipeline p SET
            courses_assigned = (SELECT COUNT(*) FROM course_assignments WHERE pipeline_id = p.id),
            courses_completed = (SELECT COUNT(*) FROM course_assignments WHERE pipeline_id = p.id AND is_completed = 1),
            course_completion_pct = IFNULL(
                (SELECT COUNT(*) FROM course_assignments WHERE pipeline_id = p.id AND is_completed = 1) /
                NULLIF((SELECT COUNT(*) FROM course_assignments WHERE pipeline_id = p.id), 0) * 100
            , 0)
    ");
    
    // Update assessment stats
    $conn->query("
        UPDATE employee_pipeline p SET
            assessments_taken = (SELECT COUNT(*) FROM course_quiz_results WHERE pipeline_id = p.id),
            assessments_passed = (SELECT COUNT(*) FROM course_quiz_results WHERE pipeline_id = p.id AND passed = 1),
            avg_assessment_score = IFNULL(
                (SELECT AVG(score_percentage) FROM course_quiz_results WHERE pipeline_id = p.id), 0
            )
    ");
    
    // Update HR2 evaluation scores for those who are hr2_evaluated or completed
    // Use average assessment score as HR2 score
    $conn->query("
        UPDATE employee_pipeline SET 
            hr2_eval_score = avg_assessment_score,
            hr2_eval_date = CURDATE(),
            hr2_eval_rating = CASE
                WHEN avg_assessment_score >= 90 THEN 'Outstanding'
                WHEN avg_assessment_score >= 80 THEN 'Excellent'
                WHEN avg_assessment_score >= 70 THEN 'Very Good'
                WHEN avg_assessment_score >= 60 THEN 'Good'
                WHEN avg_assessment_score >= 50 THEN 'Fair'
                ELSE 'Needs Improvement'
            END
        WHERE current_stage IN ('hr2_evaluated', 'completed') AND avg_assessment_score > 0
    ");
    
    // Calculate combined scores (30% HR1 + 70% HR2)
    $conn->query("
        UPDATE employee_pipeline SET
            combined_score = (IFNULL(hr1_eval_score, 0) * 0.30) + (IFNULL(hr2_eval_score, 0) * 0.70),
            combined_rating = CASE
                WHEN (IFNULL(hr1_eval_score, 0) * 0.30) + (IFNULL(hr2_eval_score, 0) * 0.70) >= 90 THEN 'Outstanding'
                WHEN (IFNULL(hr1_eval_score, 0) * 0.30) + (IFNULL(hr2_eval_score, 0) * 0.70) >= 80 THEN 'Excellent'
                WHEN (IFNULL(hr1_eval_score, 0) * 0.30) + (IFNULL(hr2_eval_score, 0) * 0.70) >= 70 THEN 'Very Good'
                WHEN (IFNULL(hr1_eval_score, 0) * 0.30) + (IFNULL(hr2_eval_score, 0) * 0.70) >= 60 THEN 'Good'
                WHEN (IFNULL(hr1_eval_score, 0) * 0.30) + (IFNULL(hr2_eval_score, 0) * 0.70) >= 50 THEN 'Fair'
                ELSE 'Needs Improvement'
            END
        WHERE hr2_eval_score IS NOT NULL
    ");
    
    echo "✅ Pipeline statistics updated!\n";
}

echo "\n" . str_repeat('=', 60);
echo "\n✅ MIGRATION COMPLETE!";
echo "\n   Tables created: {$success} | Failed: {$failed}";
echo "\n   Sample data: 10 employees across all pipeline stages";
echo "\n" . str_repeat('=', 60);
echo "\n\n🔗 <a href='module1_sub5.php' style='color: #818cf8;'>→ Open Employee Pipeline Dashboard</a>";
echo "\n</pre>";
?>

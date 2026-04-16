<?php
/**
 * API: Contract Bond Management
 * Handles CRUD operations for contract bonds
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$userRole = strtolower(str_replace(' ', '', $_SESSION['role'] ?? ''));
if (!in_array($userRole, ['admin', 'manager', 'superadmin'])) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit();
}

$userId = (int)$_SESSION['user_id'];

// POST - Create or Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    
    // Update status
    if ($action === 'update_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $validStatuses = ['active', 'completed', 'terminated', 'breached'];
        
        if ($id <= 0 || !in_array($status, $validStatuses)) {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
            exit();
        }
        
        $termDate = ($status !== 'active') ? date('Y-m-d') : null;
        $termReason = trim($_POST['termination_reason'] ?? '');
        
        $stmt = $conn->prepare("UPDATE contract_bonds SET status = ?, termination_date = ?, termination_reason = ? WHERE id = ?");
        $stmt->bind_param('sssi', $status, $termDate, $termReason, $id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Bond status updated']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Update failed: ' . $stmt->error]);
        }
        $stmt->close();
        exit();
    }
    
    // Create new bond
    $hr1EmpId = (int)($_POST['hr1_employee_id'] ?? 0);
    $hr1EmpName = trim($_POST['hr1_employee_name'] ?? '');
    $bondType = $_POST['bond_type'] ?? 'training';
    $description = trim($_POST['description'] ?? '');
    $trainingProgram = trim($_POST['training_program'] ?? '');
    $bondAmount = (float)($_POST['bond_amount'] ?? 0);
    $companyInvestment = (float)($_POST['company_investment'] ?? 0);
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $durationMonths = (int)($_POST['bond_duration_months'] ?? 0);
    $conditions = trim($_POST['conditions'] ?? '');
    $penaltyClause = trim($_POST['penalty_clause'] ?? '');
    
    if ($hr1EmpId <= 0 || empty($startDate) || empty($endDate)) {
        echo json_encode(['success' => false, 'error' => 'Employee, start date and end date are required']);
        exit();
    }
    
    $validTypes = ['training', 'scholarship', 'equipment', 'relocation', 'signing', 'other'];
    if (!in_array($bondType, $validTypes)) $bondType = 'other';
    
    // Auto-calculate duration if not provided
    if ($durationMonths <= 0) {
        $d1 = new DateTime($startDate);
        $d2 = new DateTime($endDate);
        $durationMonths = max(1, $d1->diff($d2)->m + ($d1->diff($d2)->y * 12));
    }
    
    $stmt = $conn->prepare("
        INSERT INTO contract_bonds 
        (hr1_employee_id, hr1_employee_name, bond_type, description, training_program,
         bond_amount, company_investment, start_date, end_date, bond_duration_months,
         conditions, penalty_clause, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)
    ");
    
    $stmt->bind_param('issssddssissi',
        $hr1EmpId, $hr1EmpName, $bondType, $description, $trainingProgram,
        $bondAmount, $companyInvestment, $startDate, $endDate, $durationMonths,
        $conditions, $penaltyClause, $userId
    );
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Contract bond created', 'id' => $conn->insert_id]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to create: ' . $stmt->error]);
    }
    $stmt->close();
    exit();
}

// GET - List bonds
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $empId = (int)($_GET['employee_id'] ?? 0);
    $sql = "SELECT * FROM contract_bonds";
    if ($empId > 0) $sql .= " WHERE hr1_employee_id = " . $empId;
    $sql .= " ORDER BY created_at DESC";
    
    $result = $conn->query($sql);
    $data = [];
    if ($result) while ($row = $result->fetch_assoc()) $data[] = $row;
    
    echo json_encode(['success' => true, 'data' => $data]);
    exit();
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);

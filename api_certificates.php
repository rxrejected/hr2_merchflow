<?php
/**
 * API: Certificate Management
 * Handles add, list, delete for employee certificates
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

// POST - Add Certificate
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hr1EmpId = (int)($_POST['hr1_employee_id'] ?? 0);
    $hr1EmpName = trim($_POST['hr1_employee_name'] ?? '');
    $certName = trim($_POST['certificate_name'] ?? '');
    $issuingOrg = trim($_POST['issuing_organization'] ?? '');
    $credentialId = trim($_POST['credential_id'] ?? '');
    $dateIssued = $_POST['date_issued'] ?? null;
    $expiryDate = $_POST['expiry_date'] ?? null;
    $category = $_POST['category'] ?? 'professional';
    $description = trim($_POST['description'] ?? '');
    
    if ($hr1EmpId <= 0 || empty($certName)) {
        echo json_encode(['success' => false, 'error' => 'Employee and certificate name are required']);
        exit();
    }
    
    // Validate category
    $validCategories = ['technical', 'professional', 'academic', 'safety', 'compliance', 'other'];
    if (!in_array($category, $validCategories)) $category = 'other';
    
    // Handle file upload
    $certFilePath = null;
    if (!empty($_FILES['certificate_file']['name'])) {
        $file = $_FILES['certificate_file'];
        $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        if (!in_array($file['type'], $allowedTypes)) {
            echo json_encode(['success' => false, 'error' => 'Invalid file type. Allowed: JPG, PNG, PDF']);
            exit();
        }
        
        if ($file['size'] > $maxSize) {
            echo json_encode(['success' => false, 'error' => 'File too large. Max 5MB']);
            exit();
        }
        
        $uploadDir = __DIR__ . '/uploads/certificates/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'cert_' . $hr1EmpId . '_' . time() . '.' . $ext;
        $targetPath = $uploadDir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $certFilePath = 'uploads/certificates/' . $filename;
        }
    }
    
    // Empty dates should be NULL
    if (empty($dateIssued)) $dateIssued = null;
    if (empty($expiryDate)) $expiryDate = null;
    
    $stmt = $conn->prepare("
        INSERT INTO employee_certificates 
        (hr1_employee_id, hr1_employee_name, certificate_name, issuing_organization, credential_id,
         date_issued, expiry_date, certificate_file, description, category, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->bind_param('isssssssssi',
        $hr1EmpId, $hr1EmpName, $certName, $issuingOrg, $credentialId,
        $dateIssued, $expiryDate, $certFilePath, $description, $category, $userId
    );
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Certificate added', 'id' => $conn->insert_id]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $stmt->error]);
    }
    $stmt->close();
    exit();
}

// GET - List certificates
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    if ($action === 'list') {
        $empId = (int)($_GET['employee_id'] ?? 0);
        $sql = "SELECT * FROM employee_certificates";
        if ($empId > 0) $sql .= " WHERE hr1_employee_id = " . $empId;
        $sql .= " ORDER BY created_at DESC";
        
        $result = $conn->query($sql);
        $data = [];
        if ($result) while ($row = $result->fetch_assoc()) $data[] = $row;
        
        echo json_encode(['success' => true, 'data' => $data]);
        exit();
    }
    
    if ($action === 'count') {
        // Get certificate count per employee
        $result = $conn->query("
            SELECT hr1_employee_id, COUNT(*) as cert_count 
            FROM employee_certificates 
            GROUP BY hr1_employee_id
        ");
        $data = [];
        if ($result) while ($row = $result->fetch_assoc()) $data[$row['hr1_employee_id']] = (int)$row['cert_count'];
        
        echo json_encode(['success' => true, 'data' => $data]);
        exit();
    }
}

// DELETE
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid ID']);
        exit();
    }
    
    // Get file path before deleting
    $stmt = $conn->prepare("SELECT certificate_file FROM employee_certificates WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $cert = $result->fetch_assoc();
    $stmt->close();
    
    $stmt = $conn->prepare("DELETE FROM employee_certificates WHERE id = ?");
    $stmt->bind_param('i', $id);
    
    if ($stmt->execute()) {
        // Delete file if exists
        if ($cert && $cert['certificate_file'] && file_exists(__DIR__ . '/' . $cert['certificate_file'])) {
            unlink(__DIR__ . '/' . $cert['certificate_file']);
        }
        echo json_encode(['success' => true, 'message' => 'Certificate deleted']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Delete failed']);
    }
    $stmt->close();
    exit();
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);

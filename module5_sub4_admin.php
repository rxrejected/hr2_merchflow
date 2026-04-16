<?php
/**
 * MODULE 5 SUB 4 ADMIN - DOCUMENT MANAGEMENT
 * HR2 MerchFlow - Employee Portal Management
 * Upload and manage employee documents
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

// Admin role check
if (!in_array($_SESSION['role'] ?? '', ['admin', 'Super Admin', 'developer'])) {
    header('Location: employee.php');
    exit();
}

$admin_id = $_SESSION['user_id'];

// Create employee_documents table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS employee_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    category ENUM('payslip', 'certificate', 'contract', 'memo', 'other') DEFAULT 'other',
    file_path VARCHAR(500) NOT NULL,
    file_size INT,
    uploaded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id),
    INDEX (category)
)");

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    $employee_id = intval($_POST['employee_id']);
    $title = $_POST['title'];
    $category = $_POST['category'];
    
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/documents/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
        
        if (in_array($file_ext, $allowed_ext) && $_FILES['document']['size'] <= 10485760) { // 10MB max
            $new_filename = 'doc_' . $employee_id . '_' . time() . '.' . $file_ext;
            $file_path = $upload_dir . $new_filename;
            $file_size = $_FILES['document']['size'];
            
            if (move_uploaded_file($_FILES['document']['tmp_name'], $file_path)) {
                $stmt = $conn->prepare("INSERT INTO employee_documents (employee_id, title, category, file_path, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssii", $employee_id, $title, $category, $file_path, $file_size, $admin_id);
                $stmt->execute();
                $stmt->close();
                
                header("Location: module5_sub4_admin.php?msg=uploaded");
                exit();
            }
        }
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_document'])) {
    $doc_id = intval($_POST['document_id']);
    
    // Get file path first
    $result = $conn->query("SELECT file_path FROM employee_documents WHERE id = $doc_id");
    if ($row = $result->fetch_assoc()) {
        if (file_exists($row['file_path'])) {
            unlink($row['file_path']);
        }
    }
    
    $conn->query("DELETE FROM employee_documents WHERE id = $doc_id");
    header("Location: module5_sub4_admin.php?msg=deleted");
    exit();
}

// Fetch employees for dropdown
$employees = $conn->query("SELECT id, full_name, avatar FROM users WHERE role = 'employee' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

// Fetch all documents
$filter_employee = $_GET['employee'] ?? '';
$filter_category = $_GET['category'] ?? '';

$where = "1=1";
if ($filter_employee) $where .= " AND d.employee_id = " . intval($filter_employee);
if ($filter_category) $where .= " AND d.category = '" . $conn->real_escape_string($filter_category) . "'";

$documents = $conn->query("
    SELECT d.*, u.full_name as employee_name, u.avatar as employee_avatar, a.full_name as uploader_name
    FROM employee_documents d
    JOIN users u ON d.employee_id = u.id
    LEFT JOIN users a ON d.uploaded_by = a.id
    WHERE $where
    ORDER BY d.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Calculate stats
$total_docs = count($documents);
$stats = $conn->query("SELECT category, COUNT(*) as cnt FROM employee_documents GROUP BY category")->fetch_all(MYSQLI_ASSOC);
$category_counts = [];
foreach ($stats as $s) {
    $category_counts[$s['category']] = $s['cnt'];
}

// Format file size
function formatFileSize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

// Get file icon
function getFileIcon($filepath) {
    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'pdf': return ['fa-file-pdf', 'pdf'];
        case 'doc': case 'docx': return ['fa-file-word', 'doc'];
        case 'xls': case 'xlsx': return ['fa-file-excel', 'xls'];
        case 'jpg': case 'jpeg': case 'png': case 'gif': return ['fa-file-image', 'img'];
        default: return ['fa-file', 'other'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Document Management | Admin Portal</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/module5_sub4_admin.css?v=<?= time(); ?>">
</head>
<body>
<?php include 'partials/sidebar.php'; ?>
<div class="main-content">
    <?php include 'partials/nav.php'; ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-content">
            <div class="header-icon"><i class="fas fa-folder-open"></i></div>
            <div class="header-text">
                <h2>Document Management</h2>
                <p>Upload and manage employee documents</p>
            </div>
        </div>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="openUploadModal()">
                <i class="fas fa-upload"></i> Upload Document
            </button>
        </div>
    </div>
    
    <?php if (isset($_GET['msg'])): ?>
    <div class="content-container" style="margin-bottom: 0;">
        <div class="alert fade-in" style="background: var(--success-green-light); color: var(--success-green-dark); padding: 1rem; border-radius: var(--radius); display: flex; align-items: center; gap: 0.75rem;">
            <i class="fas fa-check-circle"></i>
            Document <?= $_GET['msg'] === 'uploaded' ? 'uploaded' : 'deleted' ?> successfully!
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card total">
            <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
            <div class="stat-info">
                <h3><?= $total_docs ?></h3>
                <p>Total Documents</p>
            </div>
        </div>
        <div class="stat-card active">
            <div class="stat-icon"><i class="fas fa-money-check-alt"></i></div>
            <div class="stat-info">
                <h3><?= $category_counts['payslip'] ?? 0 ?></h3>
                <p>Payslips</p>
            </div>
        </div>
        <div class="stat-card warning">
            <div class="stat-icon"><i class="fas fa-certificate"></i></div>
            <div class="stat-info">
                <h3><?= $category_counts['certificate'] ?? 0 ?></h3>
                <p>Certificates</p>
            </div>
        </div>
        <div class="stat-card accent">
            <div class="stat-icon"><i class="fas fa-file-contract"></i></div>
            <div class="stat-info">
                <h3><?= $category_counts['contract'] ?? 0 ?></h3>
                <p>Contracts</p>
            </div>
        </div>
    </div>
    
    <div class="content-container">
        <div class="section-card fade-in">
            <div class="section-header">
                <h3><i class="fas fa-list"></i> All Documents</h3>
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                    <select class="filter-select" id="filterEmployee" onchange="applyFilters()">
                        <option value="">All Employees</option>
                        <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>" <?= $filter_employee == $emp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($emp['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <select class="filter-select" id="filterCategory" onchange="applyFilters()">
                        <option value="">All Categories</option>
                        <option value="payslip" <?= $filter_category === 'payslip' ? 'selected' : '' ?>>Payslips</option>
                        <option value="certificate" <?= $filter_category === 'certificate' ? 'selected' : '' ?>>Certificates</option>
                        <option value="contract" <?= $filter_category === 'contract' ? 'selected' : '' ?>>Contracts</option>
                        <option value="memo" <?= $filter_category === 'memo' ? 'selected' : '' ?>>Memos</option>
                        <option value="other" <?= $filter_category === 'other' ? 'selected' : '' ?>>Others</option>
                    </select>
                    <input type="text" class="search-input" id="searchDoc" placeholder="Search documents...">
                </div>
            </div>
            <div class="section-body">
                <?php if (count($documents) > 0): ?>
                    <?php foreach ($documents as $doc): 
                        $icon = getFileIcon($doc['file_path']);
                    ?>
                    <div class="doc-row" data-title="<?= strtolower(htmlspecialchars($doc['title'] . ' ' . $doc['employee_name'])) ?>">
                        <div class="doc-icon <?= $icon[1] ?>">
                            <i class="fas <?= $icon[0] ?>"></i>
                        </div>
                        <div class="doc-info">
                            <div class="doc-title"><?= htmlspecialchars($doc['title']) ?></div>
                            <div class="doc-meta">
                                <span><i class="fas fa-tag"></i> <?= ucfirst($doc['category']) ?></span>
                                <span><i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($doc['created_at'])) ?></span>
                                <span><i class="fas fa-hdd"></i> <?= formatFileSize($doc['file_size']) ?></span>
                            </div>
                        </div>
                        <div class="doc-employee">
                            <img src="<?= htmlspecialchars($doc['employee_avatar'] ?: 'uploads/avatars/default.png') ?>" class="doc-employee-avatar">
                            <span style="color: var(--text-primary); font-weight: 500;"><?= htmlspecialchars($doc['employee_name']) ?></span>
                        </div>
                        <div class="doc-actions">
                            <button class="action-btn view" onclick="viewDocument('<?= htmlspecialchars($doc['file_path']) ?>')" title="View">
                                <i class="fas fa-eye"></i>
                            </button>
                            <a href="<?= htmlspecialchars($doc['file_path']) ?>" download class="action-btn download" title="Download">
                                <i class="fas fa-download"></i>
                            </a>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this document?')">
                                <input type="hidden" name="document_id" value="<?= $doc['id'] ?>">
                                <button type="submit" name="delete_document" class="action-btn delete" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <h4>No Documents Found</h4>
                    <p>Upload documents to share with employees.</p>
                    <button class="btn btn-primary" onclick="openUploadModal()">
                        <i class="fas fa-upload"></i> Upload Document
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Upload Modal -->
    <div class="modal" id="uploadModal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h3><i class="fas fa-upload"></i> Upload Document</h3>
                <button class="modal-close" onclick="closeUploadModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Employee *</label>
                        <select name="employee_id" class="form-control" required>
                            <option value="">Select Employee</option>
                            <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Document Title *</label>
                        <input type="text" name="title" class="form-control" required placeholder="e.g., Payslip - January 2026">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Category *</label>
                        <select name="category" class="form-control" required>
                            <option value="payslip">Payslip</option>
                            <option value="certificate">Certificate</option>
                            <option value="contract">Contract</option>
                            <option value="memo">Memo</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">File *</label>
                        <div class="upload-zone" id="uploadZone" onclick="document.getElementById('fileInput').click()">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <p style="margin: 0; color: var(--text-secondary);">Click to browse or drag & drop</p>
                            <p style="margin: 0.5rem 0 0; font-size: 0.8125rem; color: var(--text-muted);">PDF, DOC, XLS, JPG, PNG (Max 10MB)</p>
                        </div>
                        <input type="file" id="fileInput" name="document" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" style="display: none;" required onchange="updateFileName(this)">
                        <div id="fileName" style="margin-top: 0.5rem; font-size: 0.875rem; color: var(--accent-blue);"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeUploadModal()">Cancel</button>
                    <button type="submit" name="upload_document" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Upload
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Modal -->
    <div class="modal" id="viewModal">
        <div class="modal-content" style="max-width: 900px; max-height: 90vh;">
            <div class="modal-header">
                <h3><i class="fas fa-file"></i> Document Viewer</h3>
                <button class="modal-close" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body" style="padding: 0; height: 70vh;">
                <iframe id="docFrame" style="width: 100%; height: 100%; border: none;"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
// Search
document.getElementById('searchDoc').addEventListener('input', function() {
    const query = this.value.toLowerCase();
    document.querySelectorAll('.doc-row').forEach(row => {
        const title = row.dataset.title;
        row.style.display = title.includes(query) ? '' : 'none';
    });
});

function applyFilters() {
    const employee = document.getElementById('filterEmployee').value;
    const category = document.getElementById('filterCategory').value;
    let url = 'module5_sub4_admin.php?';
    if (employee) url += 'employee=' + employee + '&';
    if (category) url += 'category=' + category;
    window.location.href = url;
}

function openUploadModal() {
    document.getElementById('uploadModal').classList.add('active');
}

function closeUploadModal() {
    document.getElementById('uploadModal').classList.remove('active');
}

function viewDocument(path) {
    document.getElementById('docFrame').src = path;
    document.getElementById('viewModal').classList.add('active');
}

function closeViewModal() {
    document.getElementById('viewModal').classList.remove('active');
    document.getElementById('docFrame').src = '';
}

function updateFileName(input) {
    const name = input.files[0]?.name || '';
    document.getElementById('fileName').textContent = name ? '📄 ' + name : '';
}

// Drag and drop
const uploadZone = document.getElementById('uploadZone');
const fileInput = document.getElementById('fileInput');

uploadZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    uploadZone.classList.add('drag-over');
});

uploadZone.addEventListener('dragleave', () => {
    uploadZone.classList.remove('drag-over');
});

uploadZone.addEventListener('drop', (e) => {
    e.preventDefault();
    uploadZone.classList.remove('drag-over');
    if (e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        updateFileName(fileInput);
    }
});

document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
            if (this.id === 'viewModal') document.getElementById('docFrame').src = '';
        }
    });
});
</script>
</body>
</html>

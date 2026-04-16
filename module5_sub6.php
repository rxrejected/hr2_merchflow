<?php
/**
 * MODULE 5 SUB 6 - MY DOCUMENTS
 * HR2 MerchFlow - Employee Self-Service Portal
 * View and download personal documents (payslips, certificates, contracts)
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

$employee_id = $_SESSION['user_id'];

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

// Fetch employee documents
$docs_query = "SELECT * FROM employee_documents WHERE employee_id = ? ORDER BY created_at DESC";
$stmt = $conn->prepare($docs_query);
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Group documents by category
$grouped_docs = [
    'payslip' => [],
    'certificate' => [],
    'contract' => [],
    'memo' => [],
    'other' => []
];

foreach ($documents as $doc) {
    $grouped_docs[$doc['category']][] = $doc;
}

// Calculate stats
$total_docs = count($documents);
$payslips = count($grouped_docs['payslip']);
$certificates = count($grouped_docs['certificate']);
$contracts = count($grouped_docs['contract']);

// Get file icon based on extension
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

// Format file size
function formatFileSize($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>My Documents | Employee Portal</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/module5_sub6.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="Css/ai_chat_bubble.css?v=<?= time(); ?>">
</head>
<body>
<?php include 'partials/sidebar.php'; ?>
<div class="main-content">
    <?php include 'partials/nav.php'; ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h2><i class="fas fa-folder-open"></i> My Documents</h2>
            <div class="subtitle">Access your personal documents, payslips, and certificates</div>
        </div>
    </div>
    
    <!-- Stats Row -->
    <div class="stats-row">
        <div class="stat-card fade-in">
            <div class="icon blue"><i class="fas fa-file-alt"></i></div>
            <div>
                <div class="value"><?= $total_docs ?></div>
                <div class="label">Total Documents</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon green"><i class="fas fa-money-check-alt"></i></div>
            <div>
                <div class="value"><?= $payslips ?></div>
                <div class="label">Payslips</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon yellow"><i class="fas fa-certificate"></i></div>
            <div>
                <div class="value"><?= $certificates ?></div>
                <div class="label">Certificates</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon purple"><i class="fas fa-file-contract"></i></div>
            <div>
                <div class="value"><?= $contracts ?></div>
                <div class="label">Contracts</div>
            </div>
        </div>
    </div>
    
    <div class="content-container">
        <div class="section-card fade-in">
            <div class="section-header">
                <h3><i class="fas fa-folder"></i> Document Library</h3>
                <input type="text" class="search-input" id="searchDoc" placeholder="Search documents...">
            </div>
            <div class="section-body">
                <!-- Category Tabs -->
                <div class="category-tabs">
                    <button class="category-tab active" data-category="all">
                        <i class="fas fa-th-large"></i> All
                        <span class="count"><?= $total_docs ?></span>
                    </button>
                    <button class="category-tab" data-category="payslip">
                        <i class="fas fa-money-check-alt"></i> Payslips
                        <span class="count"><?= $payslips ?></span>
                    </button>
                    <button class="category-tab" data-category="certificate">
                        <i class="fas fa-certificate"></i> Certificates
                        <span class="count"><?= $certificates ?></span>
                    </button>
                    <button class="category-tab" data-category="contract">
                        <i class="fas fa-file-contract"></i> Contracts
                        <span class="count"><?= $contracts ?></span>
                    </button>
                    <button class="category-tab" data-category="memo">
                        <i class="fas fa-sticky-note"></i> Memos
                        <span class="count"><?= count($grouped_docs['memo']) ?></span>
                    </button>
                    <button class="category-tab" data-category="other">
                        <i class="fas fa-file"></i> Others
                        <span class="count"><?= count($grouped_docs['other']) ?></span>
                    </button>
                </div>
                
                <?php if ($total_docs > 0): ?>
                <div class="document-grid" id="documentGrid">
                    <?php foreach ($documents as $doc): 
                        $icon_info = getFileIcon($doc['file_path']);
                    ?>
                    <div class="document-item" data-category="<?= $doc['category'] ?>" data-title="<?= strtolower(htmlspecialchars($doc['title'])) ?>">
                        <div class="doc-icon <?= $icon_info[1] ?>">
                            <i class="fas <?= $icon_info[0] ?>"></i>
                        </div>
                        <div class="doc-info">
                            <div class="doc-title"><?= htmlspecialchars($doc['title']) ?></div>
                            <div class="doc-meta">
                                <span><i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($doc['created_at'])) ?></span>
                                <?php if ($doc['file_size']): ?>
                                <span><i class="fas fa-hdd"></i> <?= formatFileSize($doc['file_size']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="doc-actions">
                            <button class="doc-btn view" onclick="viewDocument('<?= htmlspecialchars($doc['file_path']) ?>')" title="View">
                                <i class="fas fa-eye"></i>
                            </button>
                            <a href="<?= htmlspecialchars($doc['file_path']) ?>" download class="doc-btn download" title="Download">
                                <i class="fas fa-download"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-folder-open"></i>
                    <h4>No Documents Yet</h4>
                    <p>Your personal documents will appear here once uploaded by HR.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Recent Activity -->
        <?php if ($total_docs > 0): ?>
        <div class="section-card fade-in" style="margin-top: 2rem;">
            <div class="section-header">
                <h3><i class="fas fa-history"></i> Recent Documents</h3>
            </div>
            <div class="section-body" style="padding: 0;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Category</th>
                            <th>Date Added</th>
                            <th>Size</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $recent = array_slice($documents, 0, 5);
                        foreach ($recent as $doc): 
                        ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 0.75rem;">
                                    <?php $icon = getFileIcon($doc['file_path']); ?>
                                    <i class="fas <?= $icon[0] ?>" style="font-size: 1.25rem; color: var(--accent-blue);"></i>
                                    <strong><?= htmlspecialchars($doc['title']) ?></strong>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-info"><?= ucfirst($doc['category']) ?></span>
                            </td>
                            <td><?= date('M d, Y', strtotime($doc['created_at'])) ?></td>
                            <td><?= $doc['file_size'] ? formatFileSize($doc['file_size']) : 'N/A' ?></td>
                            <td>
                                <a href="<?= htmlspecialchars($doc['file_path']) ?>" download class="btn btn-success btn-sm">
                                    <i class="fas fa-download"></i> Download
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Document Viewer Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal-content" style="max-width: 900px; max-height: 90vh;">
            <div class="modal-header">
                <h3><i class="fas fa-file"></i> Document Viewer</h3>
                <button class="modal-close" onclick="closeViewer()">&times;</button>
            </div>
            <div class="modal-body" style="padding: 0; height: 70vh;">
                <iframe id="docFrame" style="width: 100%; height: 100%; border: none;"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
// Category filter
document.querySelectorAll('.category-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.category-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        
        const category = this.dataset.category;
        document.querySelectorAll('.document-item').forEach(item => {
            if (category === 'all' || item.dataset.category === category) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    });
});

// Search functionality
document.getElementById('searchDoc').addEventListener('input', function() {
    const query = this.value.toLowerCase();
    document.querySelectorAll('.document-item').forEach(item => {
        const title = item.dataset.title;
        item.style.display = title.includes(query) ? '' : 'none';
    });
});

// View document
function viewDocument(path) {
    document.getElementById('docFrame').src = path;
    document.getElementById('viewModal').classList.add('active');
}

function closeViewer() {
    document.getElementById('viewModal').classList.remove('active');
    document.getElementById('docFrame').src = '';
}

document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeViewer();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeViewer();
});
</script>
<?php include 'partials/ai_chat.php'; ?>
</body>
</html>

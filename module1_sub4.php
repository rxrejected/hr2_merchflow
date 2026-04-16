<?php
/**
 * Module 1 Sub 4 - Certificate Management
 * Manage employee certificates - more certificates = more knowledgeable
 * Certificates are displayed in evaluation reports
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';
require_once 'Connection/hr1_db.php';

$userRole = strtolower(str_replace(' ', '', $_SESSION['role'] ?? ''));
if (!in_array($userRole, ['admin', 'manager', 'superadmin'])) {
    header('Location: employee.php');
    exit();
}

$userId = $_SESSION['user_id'] ?? 0;

// Fetch HR1 employees
$hr1db = new HR1Database();
$hr1Response = $hr1db->getEmployees('', 500, 0);
$hr1Employees = $hr1Response['success'] ? $hr1Response['data'] : [];
$hr1db->close();

// Fetch all certificates
$certificates = [];
$result = $conn->query("SELECT * FROM employee_certificates ORDER BY created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $certificates[] = $row;
    }
}

// Group certificates by employee
$certsByEmployee = [];
foreach ($certificates as $cert) {
    $empId = $cert['hr1_employee_id'];
    if (!isset($certsByEmployee[$empId])) {
        $certsByEmployee[$empId] = [];
    }
    $certsByEmployee[$empId][] = $cert;
}

$categories = [
    'technical' => ['label' => 'Technical', 'icon' => 'fa-laptop-code', 'color' => '#3b82f6'],
    'professional' => ['label' => 'Professional', 'icon' => 'fa-briefcase', 'color' => '#8b5cf6'],
    'academic' => ['label' => 'Academic', 'icon' => 'fa-graduation-cap', 'color' => '#10b981'],
    'safety' => ['label' => 'Safety', 'icon' => 'fa-hard-hat', 'color' => '#f59e0b'],
    'compliance' => ['label' => 'Compliance', 'icon' => 'fa-shield-alt', 'color' => '#ef4444'],
    'other' => ['label' => 'Other', 'icon' => 'fa-certificate', 'color' => '#6b7280'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Certificate Management | Competency</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <link rel="icon" type="image/png" href="osicon.png" />
  <link rel="stylesheet" href="Css/module1_sub4.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'partials/sidebar.php'; ?>

  <div class="main-content">
    <?php include 'partials/nav.php'; ?>

    <div class="container">
      <div class="page-header">
        <div class="header-content">
          <h2><i class="fas fa-certificate"></i> Certificate Management</h2>
          <p class="page-subtitle">
            <i class="fas fa-info-circle"></i> Track employee certificates and credentials — reflected in evaluation reports
          </p>
        </div>
        <div class="header-actions">
          <button class="action-btn primary" id="addCertBtn">
            <i class="fas fa-plus"></i> <span>Add Certificate</span>
          </button>
        </div>
      </div>

      <!-- Stats -->
      <?php
      $totalCerts = count($certificates);
      $employeesWithCerts = count($certsByEmployee);
      $activeCerts = count(array_filter($certificates, function($c) {
          return empty($c['expiry_date']) || strtotime($c['expiry_date']) > time();
      }));
      $expiredCerts = $totalCerts - $activeCerts;
      ?>
      <div class="stats-banner">
        <div class="stat-card">
          <div class="stat-icon primary"><i class="fas fa-certificate"></i></div>
          <div class="stat-content"><h3><?= $totalCerts ?></h3><p>Total Certificates</p></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon success"><i class="fas fa-users"></i></div>
          <div class="stat-content"><h3><?= $employeesWithCerts ?></h3><p>Employees with Certs</p></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon info"><i class="fas fa-check-circle"></i></div>
          <div class="stat-content"><h3><?= $activeCerts ?></h3><p>Active</p></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon warning"><i class="fas fa-exclamation-triangle"></i></div>
          <div class="stat-content"><h3><?= $expiredCerts ?></h3><p>Expired</p></div>
        </div>
      </div>

      <!-- Search -->
      <div class="search-sort">
        <div class="search-wrapper">
          <i class="fas fa-search search-icon"></i>
          <input type="text" id="searchInput" placeholder="Search employee or certificate..." autocomplete="off" />
        </div>
        <div class="filter-wrapper">
          <select id="filterCategory">
            <option value="all">All Categories</option>
            <?php foreach ($categories as $key => $cat): ?>
            <option value="<?= $key ?>"><?= $cat['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Certificates by Employee -->
      <div class="cert-grid" id="certGrid">
        <?php foreach ($hr1Employees as $emp):
          $empId = $emp['id'];
          $empCerts = $certsByEmployee[$empId] ?? [];
          $certCount = count($empCerts);
        ?>
        <div class="cert-employee-card" data-name="<?= strtolower(htmlspecialchars($emp['name'])) ?>" data-certs="<?= $certCount ?>">
          <div class="cert-emp-header">
            <div class="cert-emp-info">
              <img src="uploads/avatars/default.png" alt="" class="cert-emp-avatar" onerror="this.src='uploads/avatars/default.png'" />
              <div>
                <strong><?= htmlspecialchars($emp['name']) ?></strong>
                <small><?= htmlspecialchars($emp['role'] ?: 'Employee') ?> • <?= htmlspecialchars($emp['department'] ?: 'Operations') ?></small>
              </div>
            </div>
            <div class="cert-count-badge" style="background: <?= $certCount > 0 ? '#10b981' : '#94a3b8' ?>">
              <i class="fas fa-certificate"></i> <?= $certCount ?>
            </div>
          </div>
          
          <?php if ($certCount > 0): ?>
          <div class="cert-list">
            <?php foreach ($empCerts as $cert): 
              $isExpired = !empty($cert['expiry_date']) && strtotime($cert['expiry_date']) < time();
              $catInfo = $categories[$cert['category']] ?? $categories['other'];
            ?>
            <div class="cert-item <?= $isExpired ? 'expired' : '' ?>" data-category="<?= $cert['category'] ?>">
              <div class="cert-item-icon" style="background: <?= $catInfo['color'] ?>20; color: <?= $catInfo['color'] ?>">
                <i class="fas <?= $catInfo['icon'] ?>"></i>
              </div>
              <div class="cert-item-info">
                <strong><?= htmlspecialchars($cert['certificate_name']) ?></strong>
                <small>
                  <?= htmlspecialchars($cert['issuing_organization'] ?: 'N/A') ?>
                  <?php if ($cert['date_issued']): ?>
                    • Issued: <?= date('M Y', strtotime($cert['date_issued'])) ?>
                  <?php endif; ?>
                  <?php if ($isExpired): ?>
                    <span class="expired-badge">Expired</span>
                  <?php endif; ?>
                </small>
              </div>
              <div class="cert-item-actions">
                <?php if ($cert['certificate_file']): ?>
                <a href="<?= htmlspecialchars($cert['certificate_file']) ?>" target="_blank" class="btn-icon" title="View File">
                  <i class="fas fa-external-link-alt"></i>
                </a>
                <?php endif; ?>
                <button class="btn-icon btn-delete" onclick="deleteCert(<?= $cert['id'] ?>)" title="Delete">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="no-certs">
            <i class="fas fa-folder-open"></i>
            <small>No certificates yet</small>
          </div>
          <?php endif; ?>
          
          <button class="add-cert-for-emp" onclick="openAddCert(<?= $empId ?>, '<?= htmlspecialchars(addslashes($emp['name'])) ?>')">
            <i class="fas fa-plus"></i> Add Certificate
          </button>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Add Certificate Modal -->
  <div id="addCertModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 id="certModalTitle"><i class="fas fa-certificate"></i> Add Certificate</h3>
        <button class="close" onclick="closeModal('addCertModal')">&times;</button>
      </div>
      <form id="certForm" enctype="multipart/form-data">
        <div class="modal-body">
          <div class="form-group">
            <label for="cert_employee">Employee</label>
            <select id="cert_employee" name="hr1_employee_id" required>
              <option value="">Select Employee...</option>
              <?php foreach ($hr1Employees as $emp): ?>
              <option value="<?= $emp['id'] ?>" data-name="<?= htmlspecialchars($emp['name']) ?>"><?= htmlspecialchars($emp['name']) ?> — <?= htmlspecialchars($emp['role'] ?: 'Employee') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="cert_name">Certificate Name *</label>
            <input type="text" id="cert_name" name="certificate_name" required placeholder="e.g., Food Safety Certification" />
          </div>
          <div class="form-group">
            <label for="cert_org">Issuing Organization</label>
            <input type="text" id="cert_org" name="issuing_organization" placeholder="e.g., TESDA, PRC, etc." />
          </div>
          <div class="form-group">
            <label for="cert_credential">Credential/License ID</label>
            <input type="text" id="cert_credential" name="credential_id" placeholder="Certificate number..." />
          </div>
          <div class="form-row">
            <div class="form-group">
              <label for="cert_issued">Date Issued</label>
              <input type="date" id="cert_issued" name="date_issued" />
            </div>
            <div class="form-group">
              <label for="cert_expiry">Expiry Date</label>
              <input type="date" id="cert_expiry" name="expiry_date" />
            </div>
          </div>
          <div class="form-group">
            <label for="cert_category">Category</label>
            <select id="cert_category" name="category">
              <?php foreach ($categories as $key => $cat): ?>
              <option value="<?= $key ?>"><?= $cat['label'] ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="cert_file">Certificate File (Image/PDF)</label>
            <input type="file" id="cert_file" name="certificate_file" accept=".jpg,.jpeg,.png,.pdf" />
            <small class="form-help">Max 5MB. Accepted: JPG, PNG, PDF</small>
          </div>
          <div class="form-group">
            <label for="cert_desc">Description</label>
            <textarea id="cert_desc" name="description" rows="2" placeholder="Brief description..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="closeModal('addCertModal')">Cancel</button>
          <button type="submit" class="btn btn-primary" id="saveCertBtn">
            <i class="fas fa-save"></i> Save Certificate
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Toast -->
  <div id="toast" class="toast"><i class="fas fa-check-circle"></i><span id="toastMessage"></span></div>

  <script>
function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    document.getElementById('toastMessage').textContent = msg;
    t.className = 'toast show ' + type;
    setTimeout(() => t.classList.remove('show'), 3000);
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
    document.body.style.overflow = '';
}

function openAddCert(empId, empName) {
    document.getElementById('cert_employee').value = empId;
    document.getElementById('certModalTitle').innerHTML = '<i class="fas fa-certificate"></i> Add Certificate — ' + empName;
    document.getElementById('addCertModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

document.getElementById('addCertBtn').addEventListener('click', function() {
    document.getElementById('certForm').reset();
    document.getElementById('certModalTitle').innerHTML = '<i class="fas fa-certificate"></i> Add Certificate';
    document.getElementById('addCertModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
});

// Submit certificate
document.getElementById('certForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('saveCertBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    const formData = new FormData(this);
    // Add employee name from select
    const sel = document.getElementById('cert_employee');
    formData.append('hr1_employee_name', sel.options[sel.selectedIndex]?.dataset.name || '');
    
    try {
        const resp = await fetch('api_certificates.php', { method: 'POST', body: formData });
        const data = await resp.json();
        if (data.success) {
            showToast('Certificate added successfully!');
            closeModal('addCertModal');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.error || 'Failed to save', 'error');
        }
    } catch (err) {
        showToast('Connection error', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Certificate';
    }
});

// Delete certificate
async function deleteCert(id) {
    if (!confirm('Are you sure you want to delete this certificate?')) return;
    try {
        const resp = await fetch('api_certificates.php?action=delete&id=' + id, { method: 'DELETE' });
        const data = await resp.json();
        if (data.success) {
            showToast('Certificate deleted');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.error || 'Failed to delete', 'error');
        }
    } catch (err) {
        showToast('Connection error', 'error');
    }
}

// Search & Filter
document.getElementById('searchInput').addEventListener('input', applyFilters);
document.getElementById('filterCategory').addEventListener('change', applyFilters);

function applyFilters() {
    const search = document.getElementById('searchInput').value.toLowerCase().trim();
    const cat = document.getElementById('filterCategory').value;
    
    document.querySelectorAll('.cert-employee-card').forEach(card => {
        const name = card.getAttribute('data-name');
        let matchSearch = name.includes(search);
        
        // Also search certificate names within
        if (!matchSearch && search) {
            card.querySelectorAll('.cert-item-info strong').forEach(el => {
                if (el.textContent.toLowerCase().includes(search)) matchSearch = true;
            });
        }
        
        let matchCat = true;
        if (cat !== 'all') {
            const items = card.querySelectorAll('.cert-item');
            if (items.length > 0) {
                matchCat = Array.from(items).some(i => i.getAttribute('data-category') === cat);
                // Hide non-matching items within card
                items.forEach(i => {
                    i.style.display = i.getAttribute('data-category') === cat ? '' : 'none';
                });
            } else {
                matchCat = false;
            }
        } else {
            card.querySelectorAll('.cert-item').forEach(i => i.style.display = '');
        }
        
        card.style.display = (matchSearch && matchCat) ? '' : 'none';
    });
}

// Close modals
document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal').forEach(m => { if (m.style.display === 'flex') closeModal(m.id); });
});
  </script>
</body>
</html>

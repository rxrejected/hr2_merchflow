<?php
/**
 * Module 6 Sub 1 - Contract Bond Management
 * Tracks employee contract bonds (training bonds, scholarship bonds, etc.)
 * When company invests in employee training, employee agrees to stay for a period.
 * If employee leaves early, they must pay back a portion.
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

// Fetch all contract bonds
$bonds = [];
$result = $conn->query("SELECT * FROM contract_bonds ORDER BY created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $bonds[] = $row;
    }
}

// Stats
$activeBonds = array_filter($bonds, fn($b) => $b['status'] === 'active');
$completedBonds = array_filter($bonds, fn($b) => $b['status'] === 'completed');
$breachedBonds = array_filter($bonds, fn($b) => $b['status'] === 'breached');
$totalInvestment = array_sum(array_column($bonds, 'company_investment'));

$bondTypes = [
    'training' => ['label' => 'Training Bond', 'icon' => 'fa-chalkboard-teacher', 'color' => '#3b82f6', 'desc' => 'Employee undergoes company-sponsored training and commits to stay for a set period.'],
    'scholarship' => ['label' => 'Scholarship Bond', 'icon' => 'fa-graduation-cap', 'color' => '#8b5cf6', 'desc' => 'Company sponsors employee education/certification in exchange for service commitment.'],
    'equipment' => ['label' => 'Equipment Bond', 'icon' => 'fa-laptop', 'color' => '#10b981', 'desc' => 'Company provides equipment/tools to employee with return conditions.'],
    'relocation' => ['label' => 'Relocation Bond', 'icon' => 'fa-map-marker-alt', 'color' => '#f59e0b', 'desc' => 'Company covers relocation costs with minimum service period requirement.'],
    'signing' => ['label' => 'Signing Bond', 'icon' => 'fa-file-signature', 'color' => '#ef4444', 'desc' => 'Signing bonus with clawback clause if employee leaves before commitment period.'],
    'other' => ['label' => 'Other Bond', 'icon' => 'fa-file-contract', 'color' => '#6b7280', 'desc' => 'Other types of contract bonds.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Contract Bond Management</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <link rel="icon" type="image/png" href="osicon.png" />
  <link rel="stylesheet" href="Css/module6_sub1.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'partials/sidebar.php'; ?>

  <div class="main-content">
    <?php include 'partials/nav.php'; ?>

    <div class="container m6s1-wrap">
      <div class="m6s1-header">
        <div class="m6s1-header-content">
          <div class="m6s1-header-icon"><i class="fas fa-file-contract"></i></div>
          <div class="m6s1-header-text">
            <h2>Contract Bond Management</h2>
            <p><i class="fas fa-info-circle"></i> Track employee contract bonds — training investments, scholarship commitments, and service agreements</p>
          </div>
        </div>
        <div class="m6s1-header-actions">
          <button class="m6s1-btn primary" id="newBondBtn">
            <i class="fas fa-plus"></i> <span>New Bond</span>
          </button>
        </div>
      </div>

      <!-- Stats -->
      <div class="m6s1-stats">
        <div class="m6s1-stat">
          <div class="m6s1-stat-icon icon-primary"><i class="fas fa-file-contract"></i></div>
          <div class="m6s1-stat-info"><span class="m6s1-stat-val"><?= count($bonds) ?></span><span class="m6s1-stat-lbl">Total Bonds</span></div>
        </div>
        <div class="m6s1-stat">
          <div class="m6s1-stat-icon icon-success"><i class="fas fa-check-circle"></i></div>
          <div class="m6s1-stat-info"><span class="m6s1-stat-val"><?= count($activeBonds) ?></span><span class="m6s1-stat-lbl">Active</span></div>
        </div>
        <div class="m6s1-stat">
          <div class="m6s1-stat-icon icon-info"><i class="fas fa-flag-checkered"></i></div>
          <div class="m6s1-stat-info"><span class="m6s1-stat-val"><?= count($completedBonds) ?></span><span class="m6s1-stat-lbl">Completed</span></div>
        </div>
        <div class="m6s1-stat">
          <div class="m6s1-stat-icon icon-warning"><i class="fas fa-peso-sign"></i></div>
          <div class="m6s1-stat-info"><span class="m6s1-stat-val">₱<?= number_format($totalInvestment, 0) ?></span><span class="m6s1-stat-lbl">Total Investment</span></div>
        </div>
      </div>

      <!-- Bond Type Cards (Info) -->
      <div class="m6s1-bond-info">
        <h4><i class="fas fa-question-circle"></i> What is a Contract Bond?</h4>
        <p class="m6s1-info-text">A contract bond is an agreement where the company invests in an employee (training, education, equipment, etc.) and the employee commits to serve the company for a specified period. If the employee leaves before the bond period ends, they may need to reimburse a portion of the investment.</p>
        <div class="m6s1-bond-grid">
          <?php foreach ($bondTypes as $key => $type): ?>
          <div class="m6s1-bond-type" style="border-left: 4px solid <?= $type['color'] ?>">
            <i class="fas <?= $type['icon'] ?>" style="color: <?= $type['color'] ?>"></i>
            <div>
              <strong><?= $type['label'] ?></strong>
              <small><?= $type['desc'] ?></small>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Search & Filter -->
      <div class="m6s1-filters">
        <div class="m6s1-filter-left">
          <div class="m6s1-search">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" placeholder="Search employee or bond..." autocomplete="off" />
          </div>
        </div>
        <div class="m6s1-filter-right">
          <select id="filterStatus" class="m6s1-select">
            <option value="all">All Status</option>
            <option value="active">Active</option>
            <option value="completed">Completed</option>
            <option value="terminated">Terminated</option>
            <option value="breached">Breached</option>
          </select>
          <select id="filterType" class="m6s1-select">
            <option value="all">All Types</option>
            <?php foreach ($bondTypes as $key => $type): ?>
            <option value="<?= $key ?>"><?= $type['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Bonds Table -->
      <div class="m6s1-card">
        <div class="m6s1-card-hd">
          <h3><i class="fas fa-table"></i> Contract Bonds <span class="m6s1-count"><?= count($bonds) ?></span></h3>
        </div>
        <div class="m6s1-table-wrap">
          <table class="m6s1-table" id="bondTable">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Bond Type</th>
                <th>Program/Description</th>
                <th>Investment</th>
                <th>Bond Amount</th>
                <th>Duration</th>
                <th>Progress</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($bonds) === 0): ?>
              <tr><td colspan="9" class="m6s1-empty">
                <i class="fas fa-folder-open"></i>
                <p>No contract bonds yet. Click "New Bond" to create one.</p>
              </td></tr>
              <?php endif; ?>
              <?php foreach ($bonds as $bond):
                $typeInfo = $bondTypes[$bond['bond_type']] ?? $bondTypes['other'];
                $startDate = new DateTime($bond['start_date']);
                $endDate = new DateTime($bond['end_date']);
                $now = new DateTime();
                $totalDays = max(1, $startDate->diff($endDate)->days);
                $elapsedDays = max(0, min($totalDays, $startDate->diff($now)->days));
                $progress = min(100, ($elapsedDays / $totalDays) * 100);
                $remaining = max(0, $endDate->diff($now)->days);
                if ($now > $endDate) $remaining = 0;
              ?>
              <tr class="bond-row" 
                  data-name="<?= strtolower(htmlspecialchars($bond['hr1_employee_name'] ?? '')) ?>"
                  data-status="<?= $bond['status'] ?>"
                  data-type="<?= $bond['bond_type'] ?>">
                <td>
                  <span class="m6s1-emp-name"><?= htmlspecialchars($bond['hr1_employee_name'] ?: 'Employee #' . $bond['hr1_employee_id']) ?></span>
                </td>
                <td>
                  <span class="m6s1-type-badge" style="background: <?= $typeInfo['color'] ?>20; color: <?= $typeInfo['color'] ?>">
                    <i class="fas <?= $typeInfo['icon'] ?>"></i> <?= $typeInfo['label'] ?>
                  </span>
                </td>
                <td>
                  <span class="m6s1-truncate" title="<?= htmlspecialchars($bond['training_program'] ?: $bond['description'] ?: '—') ?>">
                    <?= htmlspecialchars($bond['training_program'] ?: $bond['description'] ?: '—') ?>
                  </span>
                </td>
                <td><span class="m6s1-amount">₱<?= number_format($bond['company_investment'], 2) ?></span></td>
                <td>₱<?= number_format($bond['bond_amount'], 2) ?></td>
                <td>
                  <small>
                    <?= $startDate->format('M d, Y') ?> — <?= $endDate->format('M d, Y') ?><br>
                    <span class="m6s1-text-muted"><?= $bond['bond_duration_months'] ?> months</span>
                  </small>
                </td>
                <td>
                  <div class="m6s1-progress">
                    <div class="m6s1-progress-bar">
                      <div class="m6s1-progress-fill" style="width: <?= round($progress) ?>%; background: var(--m6-gradient-success);"></div>
                    </div>
                    <span class="m6s1-progress-text"><?= round($progress) ?>%</span>
                  </div>
                  <?php if ($bond['status'] === 'active' && $remaining > 0): ?>
                  <small class="m6s1-text-muted"><?= $remaining ?> days left</small>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="m6s1-badge status-<?= $bond['status'] ?>">
                    <?= ucfirst($bond['status']) ?>
                  </span>
                </td>
                <td>
                  <div class="m6s1-actions">
                    <button class="m6s1-icon-btn" onclick="viewBond(<?= $bond['id'] ?>)" title="View Details">
                      <i class="fas fa-eye"></i>
                    </button>
                    <?php if ($bond['status'] === 'active'): ?>
                    <button class="m6s1-icon-btn complete" onclick="updateBondStatus(<?= $bond['id'] ?>, 'completed')" title="Mark Completed">
                      <i class="fas fa-check"></i>
                    </button>
                    <button class="m6s1-icon-btn danger" onclick="updateBondStatus(<?= $bond['id'] ?>, 'breached')" title="Mark Breached">
                      <i class="fas fa-times"></i>
                    </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- New Bond Modal -->
  <div id="bondModal" class="m6s1-modal">
    <div class="m6s1-modal-box large">
      <div class="m6s1-modal-hd">
        <h3><i class="fas fa-file-contract"></i> New Contract Bond</h3>
        <button class="m6s1-modal-close" onclick="closeModal('bondModal')">&times;</button>
      </div>
      <form id="bondForm">
        <div class="m6s1-modal-bd">
          <div class="m6s1-form-row">
            <div class="m6s1-form-group">
              <label for="bond_employee">Employee *</label>
              <select id="bond_employee" name="hr1_employee_id" class="m6s1-input" required>
                <option value="">Select Employee...</option>
                <?php foreach ($hr1Employees as $emp): ?>
                <option value="<?= $emp['id'] ?>" data-name="<?= htmlspecialchars($emp['name']) ?>"><?= htmlspecialchars($emp['name']) ?> — <?= htmlspecialchars($emp['role'] ?: 'Employee') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="m6s1-form-group">
              <label for="bond_type">Bond Type *</label>
              <select id="bond_type" name="bond_type" class="m6s1-input" required>
                <?php foreach ($bondTypes as $key => $type): ?>
                <option value="<?= $key ?>"><?= $type['label'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          
          <div class="m6s1-form-group">
            <label for="bond_program">Training Program / Scholarship Name</label>
            <input type="text" id="bond_program" name="training_program" class="m6s1-input" placeholder="e.g., Advanced Store Management Training" />
          </div>
          
          <div class="m6s1-form-group">
            <label for="bond_desc">Bond Description *</label>
            <textarea id="bond_desc" name="description" class="m6s1-input" rows="3" required placeholder="Describe the bond agreement, reason, and objectives..."></textarea>
          </div>
          
          <div class="m6s1-form-row">
            <div class="m6s1-form-group">
              <label for="bond_investment">Company Investment (₱) *</label>
              <input type="number" id="bond_investment" name="company_investment" class="m6s1-input" required min="0" step="0.01" placeholder="0.00" />
            </div>
            <div class="m6s1-form-group">
              <label for="bond_amount">Bond Amount (₱) *</label>
              <input type="number" id="bond_amount" name="bond_amount" class="m6s1-input" required min="0" step="0.01" placeholder="0.00" />
              <small class="m6s1-form-help">Amount employee must repay if bond is breached</small>
            </div>
          </div>
          
          <div class="m6s1-form-row">
            <div class="m6s1-form-group">
              <label for="bond_start">Start Date *</label>
              <input type="date" id="bond_start" name="start_date" class="m6s1-input" required value="<?= date('Y-m-d') ?>" />
            </div>
            <div class="m6s1-form-group">
              <label for="bond_end">End Date *</label>
              <input type="date" id="bond_end" name="end_date" class="m6s1-input" required />
            </div>
            <div class="m6s1-form-group">
              <label for="bond_months">Duration (Months)</label>
              <input type="number" id="bond_months" name="bond_duration_months" class="m6s1-input" min="1" max="120" placeholder="12" />
            </div>
          </div>
          
          <div class="m6s1-form-group">
            <label for="bond_conditions">Bond Conditions / Terms</label>
            <textarea id="bond_conditions" name="conditions" class="m6s1-input" rows="3" placeholder="List the conditions and terms of this bond...&#10;&#10;Example:&#10;- Employee must complete the full training program&#10;- Must remain employed for 24 months after training completion&#10;- Pro-rated repayment applies if leaving before bond period"></textarea>
          </div>
          
          <div class="m6s1-form-group">
            <label for="bond_penalty">Early Termination Penalty Clause</label>
            <textarea id="bond_penalty" name="penalty_clause" class="m6s1-input" rows="2" placeholder="e.g., If employee resigns before bond period, they must repay the remaining prorated amount based on months not served."></textarea>
          </div>
        </div>
        <div class="m6s1-modal-ft">
          <button type="button" class="m6s1-btn secondary" onclick="closeModal('bondModal')">Cancel</button>
          <button type="submit" class="m6s1-btn primary" id="saveBondBtn">
            <i class="fas fa-save"></i> Create Bond
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- View Bond Detail Modal -->
  <div id="viewBondModal" class="m6s1-modal">
    <div class="m6s1-modal-box large">
      <div class="m6s1-modal-hd">
        <h3 id="viewBondTitle"><i class="fas fa-file-contract"></i> Bond Details</h3>
        <button class="m6s1-modal-close" onclick="closeModal('viewBondModal')">&times;</button>
      </div>
      <div id="viewBondContent" class="m6s1-modal-bd"></div>
    </div>
  </div>

  <!-- Toast -->
  <div id="toast" class="m6s1-toast"><i class="fas fa-check-circle"></i><span id="toastMessage"></span></div>

  <script>
const bonds = <?= json_encode($bonds) ?>;
const bondTypes = <?= json_encode($bondTypes) ?>;

function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    document.getElementById('toastMessage').textContent = msg;
    t.className = 'm6s1-toast show ' + type;
    setTimeout(() => t.classList.remove('show'), 3000);
}

function closeModal(id) {
    const modal = document.getElementById(id);
    modal.classList.remove('show');
    document.body.style.overflow = '';
}

// Auto-calculate duration when dates change
document.getElementById('bond_start').addEventListener('change', calcDuration);
document.getElementById('bond_end').addEventListener('change', calcDuration);
document.getElementById('bond_months').addEventListener('change', function() {
    const months = parseInt(this.value) || 0;
    if (months > 0) {
        const start = document.getElementById('bond_start').value;
        if (start) {
            const d = new Date(start);
            d.setMonth(d.getMonth() + months);
            document.getElementById('bond_end').value = d.toISOString().split('T')[0];
        }
    }
});

function calcDuration() {
    const start = document.getElementById('bond_start').value;
    const end = document.getElementById('bond_end').value;
    if (start && end) {
        const d1 = new Date(start);
        const d2 = new Date(end);
        const months = (d2.getFullYear() - d1.getFullYear()) * 12 + (d2.getMonth() - d1.getMonth());
        document.getElementById('bond_months').value = Math.max(1, months);
    }
}

// Open new bond modal
document.getElementById('newBondBtn').addEventListener('click', function() {
    document.getElementById('bondForm').reset();
    document.getElementById('bond_start').value = new Date().toISOString().split('T')[0];
    document.getElementById('bondModal').classList.add('show');
    document.body.style.overflow = 'hidden';
});

// Submit bond
document.getElementById('bondForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('saveBondBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    
    const formData = new FormData(this);
    const sel = document.getElementById('bond_employee');
    formData.append('hr1_employee_name', sel.options[sel.selectedIndex]?.dataset.name || '');
    
    try {
        const resp = await fetch('api_contract_bonds.php', { method: 'POST', body: formData });
        const data = await resp.json();
        if (data.success) {
            showToast('Contract bond created successfully!');
            closeModal('bondModal');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.error || 'Failed to save', 'error');
        }
    } catch (err) {
        showToast('Connection error', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Create Bond';
    }
});

// View bond details
function viewBond(id) {
    const bond = bonds.find(b => b.id == id);
    if (!bond) return;
    
    const typeInfo = bondTypes[bond.bond_type] || bondTypes['other'];
    const start = new Date(bond.start_date);
    const end = new Date(bond.end_date);
    const now = new Date();
    const totalDays = Math.max(1, (end - start) / 86400000);
    const elapsed = Math.max(0, Math.min(totalDays, (now - start) / 86400000));
    const progress = Math.min(100, (elapsed / totalDays) * 100);
    
    document.getElementById('viewBondTitle').innerHTML = '<i class="fas fa-file-contract"></i> Bond: ' + (bond.hr1_employee_name || 'Employee');
    document.getElementById('viewBondContent').innerHTML = `
        <div class="m6s1-detail">
            <div class="m6s1-detail-header">
                <div class="m6s1-detail-type" style="background:${typeInfo.color}20; color:${typeInfo.color}; border-left:4px solid ${typeInfo.color}">
                    <i class="fas ${typeInfo.icon}" style="font-size:2rem"></i>
                    <div>
                        <strong>${typeInfo.label}</strong>
                        <p>${bond.training_program || bond.description || '—'}</p>
                    </div>
                </div>
                <span class="m6s1-badge status-${bond.status}">${bond.status.charAt(0).toUpperCase() + bond.status.slice(1)}</span>
            </div>
            
            <div class="m6s1-detail-grid">
                <div class="m6s1-detail-item"><label>Employee</label><span>${bond.hr1_employee_name || '—'}</span></div>
                <div class="m6s1-detail-item"><label>Company Investment</label><span class="m6s1-amount">₱${parseFloat(bond.company_investment).toLocaleString('en-PH', {minimumFractionDigits:2})}</span></div>
                <div class="m6s1-detail-item"><label>Bond Amount</label><span class="m6s1-amount">₱${parseFloat(bond.bond_amount).toLocaleString('en-PH', {minimumFractionDigits:2})}</span></div>
                <div class="m6s1-detail-item"><label>Duration</label><span>${bond.bond_duration_months || '—'} months</span></div>
                <div class="m6s1-detail-item"><label>Start Date</label><span>${start.toLocaleDateString('en-US', {year:'numeric', month:'long', day:'numeric'})}</span></div>
                <div class="m6s1-detail-item"><label>End Date</label><span>${end.toLocaleDateString('en-US', {year:'numeric', month:'long', day:'numeric'})}</span></div>
            </div>
            
            <div class="m6s1-progress-display">
                <label>Bond Progress</label>
                <div class="m6s1-progress-lg">
                    <div class="m6s1-progress-fill" style="width:${progress.toFixed(0)}%"></div>
                </div>
                <small>${progress.toFixed(0)}% completed • ${Math.max(0, Math.ceil((end - now) / 86400000))} days remaining</small>
            </div>
            
            ${bond.conditions ? `<div class="m6s1-detail-section"><h4><i class="fas fa-list"></i> Conditions & Terms</h4><p class="m6s1-pre-wrap">${bond.conditions}</p></div>` : ''}
            ${bond.penalty_clause ? `<div class="m6s1-detail-section"><h4><i class="fas fa-exclamation-triangle"></i> Penalty Clause</h4><p class="m6s1-pre-wrap">${bond.penalty_clause}</p></div>` : ''}
            ${bond.description ? `<div class="m6s1-detail-section"><h4><i class="fas fa-info-circle"></i> Description</h4><p class="m6s1-pre-wrap">${bond.description}</p></div>` : ''}
            
            <div class="m6s1-detail-meta">
                <small>Created: ${new Date(bond.created_at).toLocaleString()}</small>
                ${bond.updated_at ? `<small>Updated: ${new Date(bond.updated_at).toLocaleString()}</small>` : ''}
            </div>
        </div>
    `;
    
    document.getElementById('viewBondModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}

// Update bond status
async function updateBondStatus(id, status) {
    const labels = { completed: 'complete', breached: 'mark as breached', terminated: 'terminate' };
    if (!confirm(`Are you sure you want to ${labels[status] || status} this bond?`)) return;
    
    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('status', status);
        formData.append('action', 'update_status');
        
        const resp = await fetch('api_contract_bonds.php', { method: 'POST', body: formData });
        const data = await resp.json();
        if (data.success) {
            showToast('Bond status updated!');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.error || 'Failed to update', 'error');
        }
    } catch (err) {
        showToast('Connection error', 'error');
    }
}

// Search & Filter
document.getElementById('searchInput').addEventListener('input', applyFilters);
document.getElementById('filterStatus').addEventListener('change', applyFilters);
document.getElementById('filterType').addEventListener('change', applyFilters);

function applyFilters() {
    const search = document.getElementById('searchInput').value.toLowerCase().trim();
    const status = document.getElementById('filterStatus').value;
    const type = document.getElementById('filterType').value;
    
    document.querySelectorAll('.bond-row').forEach(row => {
        const name = row.getAttribute('data-name');
        const rowStatus = row.getAttribute('data-status');
        const rowType = row.getAttribute('data-type');
        
        let show = true;
        if (search && !name.includes(search)) show = false;
        if (status !== 'all' && rowStatus !== status) show = false;
        if (type !== 'all' && rowType !== type) show = false;
        
        row.style.display = show ? '' : 'none';
    });
}

// Close modals
document.querySelectorAll('.m6s1-modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.m6s1-modal.show').forEach(m => closeModal(m.id));
});
  </script>
</body>
</html>

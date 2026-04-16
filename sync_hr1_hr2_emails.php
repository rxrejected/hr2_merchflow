<?php
/**
 * HR1-HR2 Email Synchronization Tool
 * Compare and sync emails between HR1 and HR2 systems
 */
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'Super Admin', 'developer'])) {
    http_response_code(403);
    die('Forbidden: Admin access required.');
}

require_once 'Connection/Config.php';
require_once 'Connection/hr1_integration.php';

// Get HR1 evaluations with emails
$hr1Service = new HR1IntegrationService();
$hr1Response = $hr1Service->getEvaluations(false); // No cache
$hr1Evaluations = $hr1Response['success'] ? $hr1Response['data'] : [];

// Get HR2 employees
$sql = "SELECT id, full_name, email, job_position FROM users WHERE role = 'employee' ORDER BY full_name";
$result = $conn->query($sql);
$hr2Employees = [];
while($row = $result->fetch_assoc()) {
    $hr2Employees[] = $row;
}

// Create email maps
$hr1Emails = [];
foreach($hr1Evaluations as $eval) {
    $email = strtolower(trim($eval['employee_email'] ?? ''));
    $name = $eval['employee_name'] ?? '';
    if($email && $name) {
        $hr1Emails[strtolower(trim($name))] = $email;
    }
}

$hr2Emails = [];
foreach($hr2Employees as $emp) {
    $name = strtolower(trim($emp['full_name']));
    $hr2Emails[$name] = strtolower(trim($emp['email']));
}

// Process sync if submitted
$syncResults = [];
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync'])) {
    $updates = json_decode($_POST['updates'], true);
    foreach($updates as $update) {
        $userId = (int)$update['user_id'];
        $newEmail = $update['new_email'];
        
        $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
        $stmt->bind_param("si", $newEmail, $userId);
        if($stmt->execute()) {
            $syncResults[] = "✅ Updated: " . htmlspecialchars($update['name']) . " → " . htmlspecialchars($newEmail);
        } else {
            $syncResults[] = "❌ Failed: " . htmlspecialchars($update['name']) . " - " . $conn->error;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR1-HR2 Email Sync Tool</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <link rel="stylesheet" href="Css/sync_tool.css?v=<?= time() ?>">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-sync-alt"></i> HR1-HR2 Email Synchronization</h1>
            <p>Compare and sync employee emails between systems</p>
        </div>

        <div class="content">
            <?php if(count($syncResults) > 0): ?>
                <div class="alert alert-success">
                    <h3><i class="fas fa-check-circle"></i> Sync Results:</h3>
                    <?php foreach($syncResults as $result): ?>
                        <div><?= $result ?></div>
                    <?php endforeach; ?>
                    <br>
                    <button onclick="window.location.reload()" class="btn btn-success">
                        <i class="fas fa-redo"></i> Refresh Data
                    </button>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats">
                <div class="stat-card">
                    <i class="fas fa-database" style="font-size: 2rem;"></i>
                    <h3><?= count($hr1Evaluations) ?></h3>
                    <p>HR1 Evaluations</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-users" style="font-size: 2rem;"></i>
                    <h3><?= count($hr2Employees) ?></h3>
                    <p>HR2 Employees</p>
                </div>
                <?php
                $matched = 0;
                $unmatched = 0;
                foreach($hr2Employees as $emp) {
                    $name = strtolower(trim($emp['full_name']));
                    $hr2Email = strtolower(trim($emp['email']));
                    $hr1Email = $hr1Emails[$name] ?? '';
                    
                    if($hr1Email && $hr2Email === $hr1Email) {
                        $matched++;
                    } else if($hr1Email) {
                        $unmatched++;
                    }
                }
                ?>
                <div class="stat-card success">
                    <i class="fas fa-check-circle" style="font-size: 2rem;"></i>
                    <h3><?= $matched ?></h3>
                    <p>Matched Emails</p>
                </div>
                <div class="stat-card error">
                    <i class="fas fa-exclamation-circle" style="font-size: 2rem;"></i>
                    <h3><?= $unmatched ?></h3>
                    <p>Need Sync</p>
                </div>
            </div>

            <?php if($matched === 0 && $unmatched > 0): ?>
                <div class="alert alert-warning">
                    <strong><i class="fas fa-exclamation-triangle"></i> WARNING:</strong> 
                    No emails match between HR1 and HR2! You need to sync the emails for the integration to work.
                </div>
            <?php elseif($matched > 0): ?>
                <div class="alert alert-success">
                    <strong><i class="fas fa-check-circle"></i> SUCCESS:</strong> 
                    <?= $matched ?> employee(s) have matching emails! The integration should work for them.
                </div>
            <?php endif; ?>

            <form method="POST" id="syncForm">
                <input type="hidden" name="updates" id="updatesInput">
                
                <h2 style="margin: 30px 0 20px 0;"><i class="fas fa-table"></i> Email Comparison</h2>
                
                <table class="comparison-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;"><input type="checkbox" id="selectAll"></th>
                            <th>Employee Name</th>
                            <th>HR2 Email (Current)</th>
                            <th>HR1 Email (From Applicants)</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($hr2Employees as $emp): 
                            $name = strtolower(trim($emp['full_name']));
                            $hr2Email = strtolower(trim($emp['email']));
                            $hr1Email = $hr1Emails[$name] ?? '';
                            
                            $isMatched = $hr1Email && $hr2Email === $hr1Email;
                            $needsSync = $hr1Email && !$isMatched;
                            $notInHR1 = !$hr1Email;
                        ?>
                            <tr data-sync="<?= $needsSync ? 'yes' : 'no' ?>" 
                                data-user-id="<?= $emp['id'] ?>"
                                data-name="<?= htmlspecialchars($emp['full_name']) ?>"
                                data-new-email="<?= htmlspecialchars($hr1Email) ?>">
                                <td class="action-cell">
                                    <?php if($needsSync): ?>
                                        <input type="checkbox" class="sync-checkbox" value="<?= $emp['id'] ?>">
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= htmlspecialchars($emp['full_name']) ?></strong></td>
                                <td><span class="email-box"><?= htmlspecialchars($emp['email']) ?></span></td>
                                <td>
                                    <?php if($hr1Email): ?>
                                        <span class="email-box suggested-email"><?= htmlspecialchars($hr1Email) ?></span>
                                    <?php else: ?>
                                        <span style="color: #999;">Not found in HR1</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($isMatched): ?>
                                        <span class="match-status matched">
                                            <i class="fas fa-check"></i> Matched
                                        </span>
                                    <?php elseif($needsSync): ?>
                                        <span class="match-status unmatched">
                                            <i class="fas fa-times"></i> Different
                                        </span>
                                    <?php else: ?>
                                        <span class="match-status not-in-hr1">
                                            <i class="fas fa-question"></i> Not in HR1
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($needsSync): ?>
                                        <span style="color: #ff9800;">
                                            <i class="fas fa-sync"></i> Will sync to HR1 email
                                        </span>
                                    <?php elseif($isMatched): ?>
                                        <span style="color: #4CAF50;">
                                            <i class="fas fa-check-circle"></i> No action needed
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if($unmatched > 0): ?>
                    <div class="sync-actions">
                        <div class="alert alert-info">
                            <strong><i class="fas fa-info-circle"></i> How it works:</strong> 
                            This will update HR2 employee emails to match the emails from HR1 applicants table. 
                            Select the employees you want to sync, then click "Sync Selected Emails".
                        </div>
                        <button type="button" class="btn btn-success" onclick="syncSelected()">
                            <i class="fas fa-sync-alt"></i> Sync Selected Emails (<span id="selectedCount">0</span>)
                        </button>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success" style="text-align: center;">
                        <h3><i class="fas fa-thumbs-up"></i> All emails are synchronized!</h3>
                        <p>Your HR1-HR2 integration should be working perfectly.</p>
                        <a href="module1_sub1.php" class="btn btn-success">
                            <i class="fas fa-arrow-right"></i> Go to Competency Management
                        </a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <script>
        // Select all checkbox
        document.getElementById('selectAll').addEventListener('change', function() {
            document.querySelectorAll('.sync-checkbox').forEach(cb => {
                cb.checked = this.checked;
            });
            updateSelectedCount();
        });

        // Individual checkboxes
        document.querySelectorAll('.sync-checkbox').forEach(cb => {
            cb.addEventListener('change', updateSelectedCount);
        });

        function updateSelectedCount() {
            const count = document.querySelectorAll('.sync-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = count;
        }

        function syncSelected() {
            const selected = [];
            document.querySelectorAll('.sync-checkbox:checked').forEach(cb => {
                const row = cb.closest('tr');
                selected.push({
                    user_id: row.getAttribute('data-user-id'),
                    name: row.getAttribute('data-name'),
                    new_email: row.getAttribute('data-new-email')
                });
            });

            if(selected.length === 0) {
                alert('Please select at least one employee to sync.');
                return;
            }

            if(confirm(`Are you sure you want to sync ${selected.length} email(s)? This will update HR2 database.`)) {
                document.getElementById('updatesInput').value = JSON.stringify(selected);
                document.getElementById('syncForm').submit();
            }
        }

        updateSelectedCount();
    </script>
</body>
</html>

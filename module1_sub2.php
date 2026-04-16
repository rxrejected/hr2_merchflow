<?php
/**
 * Competency Management - AI Skill Analysis
 * Integrated with HR1 Evaluation System + Grok AI (Unlimited)
 */

// Include centralized session handler
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';
require_once 'Connection/hr1_integration.php';
require_once 'Connection/ai_config.php';

header("Expires: 0");

if(!isset($_SESSION['user_id'])){
    header("Location: index.php");
    exit();
}

// Admin role guard
$userRole = strtolower(str_replace(' ', '', $_SESSION['role'] ?? ''));
if (!in_array($userRole, ['admin', 'manager', 'superadmin'])) {
    header('Location: employee.php');
    exit();
}

// Fetch HR1 evaluations - PRIMARY data source (same as module1_sub1.php)
$hr1Service = new HR1IntegrationService();
$hr1Response = $hr1Service->getEvaluations(false); // Fresh data
$hr1Evaluations = $hr1Response['success'] ? $hr1Response['data'] : [];

// Group evaluations by employee
$employeeEvaluations = [];
foreach ($hr1Evaluations as $eval) {
    $empId = $eval['employee_id'];
    if (!isset($employeeEvaluations[$empId])) {
        $employeeEvaluations[$empId] = [
            'employee_id' => $empId,
            'employee_name' => $eval['employee_name'],
            'employee_email' => $eval['employee_email'],
            'role' => $eval['role'] ?? 'Employee',
            'evaluations' => []
        ];
    }
    $employeeEvaluations[$empId]['evaluations'][] = $hr1Service->mapToHR2Format($eval);
}

// Sorting logic
$sort = $_GET['sort'] ?? 'name_asc';
$searchTerm = $_GET['search'] ?? '';

// Filter by search
if (!empty($searchTerm)) {
    $employeeEvaluations = array_filter($employeeEvaluations, function($emp) use ($searchTerm) {
        return stripos($emp['employee_name'], $searchTerm) !== false ||
               stripos($emp['employee_email'], $searchTerm) !== false;
    });
}

// Sort employees
if ($sort === 'name_asc') {
    uasort($employeeEvaluations, function($a, $b) {
        return strcasecmp($a['employee_name'], $b['employee_name']);
    });
} elseif ($sort === 'name_desc') {
    uasort($employeeEvaluations, function($a, $b) {
        return strcasecmp($b['employee_name'], $a['employee_name']);
    });
} elseif ($sort === 'score_desc') {
    uasort($employeeEvaluations, function($a, $b) {
        $scoreA = !empty($a['evaluations']) ? $a['evaluations'][0]['overall_score'] : 0;
        $scoreB = !empty($b['evaluations']) ? $b['evaluations'][0]['overall_score'] : 0;
        return $scoreB <=> $scoreA;
    });
} elseif ($sort === 'score_asc') {
    uasort($employeeEvaluations, function($a, $b) {
        $scoreA = !empty($a['evaluations']) ? $a['evaluations'][0]['overall_score'] : 0;
        $scoreB = !empty($b['evaluations']) ? $b['evaluations'][0]['overall_score'] : 0;
        return $scoreA <=> $scoreB;
    });
}

// Calculate statistics
$totalEmployees = count($employeeEvaluations);
$avgScore = 0;
$needsImprovement = 0;
$excellentPerformers = 0;

foreach($employeeEvaluations as $empData) {
    if(!empty($empData['evaluations'])) {
        $score = (float)$empData['evaluations'][0]['overall_score'];
        $avgScore += $score;
        if($score < 70) $needsImprovement++;
        if($score >= 90) $excellentPerformers++;
    }
}
$avgScore = $totalEmployees > 0 ? round($avgScore / $totalEmployees, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1" />
<title>Competency | AI Skill Analysis</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<link rel="icon" type="image/png" href="osicon.png" />
<link rel="stylesheet" href="Css/module1_sub2.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="Css/ai_chat_bubble.css?v=<?php echo time(); ?>">
</head>

<body>
<?php include 'partials/sidebar.php'; ?>

<div class="main-content">
    <?php include 'partials/nav.php'; ?>

    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="header-content">
                <div class="header-icon">
                    <i class="fas fa-brain"></i>
                </div>
                <div class="header-text">
                    <h2>AI Skill Analysis</h2>
                    <p class="page-subtitle">
                        <i class="fas fa-robot"></i> Groq AI-Powered Competency Mapping
                        <span class="data-source">• Data from HR1 Evaluation System</span>
                    </p>
                </div>
            </div>
            <div class="header-actions">
                <button class="action-btn" id="refreshBtn" onclick="location.reload()" title="Refresh Data">
                    <i class="fas fa-sync-alt"></i>
                    <span>Refresh</span>
                </button>

            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <h3><?= $totalEmployees ?></h3>
                    <p>Total Evaluated</p>
                </div>
            </div>
            <div class="stat-card avg">
                <div class="stat-icon"><i class="fas fa-chart-bar"></i></div>
                <div class="stat-info">
                    <h3><?= $avgScore ?>%</h3>
                    <p>Average Score</p>
                </div>
            </div>
            <div class="stat-card excellent">
                <div class="stat-icon"><i class="fas fa-star"></i></div>
                <div class="stat-info">
                    <h3><?= $excellentPerformers ?></h3>
                    <p>Excellent (90%+)</p>
                </div>
            </div>
            <div class="stat-card needs">
                <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-info">
                    <h3><?= $needsImprovement ?></h3>
                    <p>Needs Improvement</p>
                </div>
            </div>
        </div>

        <!-- AI Chat Bubble -->
        <div class="ai-chat-container" id="aiChatContainer">
            <button class="ai-bubble-btn" id="aiBubbleBtn" onclick="toggleAiChat()">
                <i class="fas fa-robot"></i>
                <span class="ai-pulse"></span>
            </button>
            
            <div class="ai-chat-window" id="aiChatWindow">
                <div class="ai-chat-header">
                    <div class="ai-chat-avatar">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="ai-chat-title">
                        <h4>AI Skill Analyzer</h4>
                        <span class="ai-status"><i class="fas fa-circle"></i> Groq AI Ready</span>
                    </div>
                    <button class="ai-chat-close" onclick="toggleAiChat()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <div class="ai-chat-body" id="aiChatBody">
                    <div class="ai-message ai-bot">
                        <div class="ai-message-avatar">
                            <i class="fas fa-robot"></i>
                        </div>
                        <div class="ai-message-bubble">
                            <p>👋 Hi! I'm your AI Skill Analyzer powered by Groq. Click an employee card to analyze or use the button below for bulk analysis.</p>
                            <span class="ai-message-time">Just now</span>
                        </div>
                    </div>
                </div>
                
                <div class="ai-chat-footer">
                    <button class="ai-analyze-btn" onclick="runBulkAnalysis()" id="aiRefreshBtn">
                        <i class="fas fa-brain"></i> Analyze All Employees
                    </button>
                </div>
            </div>
        </div>

        <!-- Search & Sort -->
        <div class="sort-bar">
            <form method="get" action="" class="filter-form">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Search employee name or email..." 
                           value="<?= htmlspecialchars($searchTerm) ?>" />
                </div>
                <select name="sort" onchange="this.form.submit()">
                    <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name (A-Z)</option>
                    <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name (Z-A)</option>
                    <option value="score_desc" <?= $sort === 'score_desc' ? 'selected' : '' ?>>Score (Highest)</option>
                    <option value="score_asc" <?= $sort === 'score_asc' ? 'selected' : '' ?>>Score (Lowest)</option>
                </select>
                <button type="submit" class="apply-btn">
                    <i class="fas fa-filter"></i> Apply
                </button>
            </form>
        </div>

        <!-- Results Count -->
        <div class="results-info">
            <i class="fas fa-list"></i>
            Showing <strong><?= count($employeeEvaluations) ?></strong> of <strong><?= $totalEmployees ?></strong> employees from HR1
        </div>

        <!-- Employee Grid -->
        <div class="grid">
            <?php if(!empty($employeeEvaluations)): ?>
                <?php foreach($employeeEvaluations as $empData): 
                    $latestEval = !empty($empData['evaluations']) ? $empData['evaluations'][0] : null;
                    $score = $latestEval ? (float)$latestEval['overall_score'] : 0;
                    $ratingLabel = $latestEval ? $latestEval['rating_label'] : 'Not Rated';
                    
                    // Determine score class
                    $scoreClass = 'low';
                    if($score >= 90) $scoreClass = 'excellent';
                    elseif($score >= 80) $scoreClass = 'good';
                    elseif($score >= 70) $scoreClass = 'average';
                ?>
                    <div class="card" data-employee-id="<?= $empData['employee_id'] ?>">
                        <div class="card-header">
                            <div class="avatar">
                                <?= strtoupper(substr($empData['employee_name'], 0, 1)) ?>
                            </div>
                            <div class="employee-info">
                                <h3><?= htmlspecialchars($empData['employee_name']) ?></h3>
                                <span class="role"><?= htmlspecialchars($empData['role']) ?></span>
                                <span class="email"><?= htmlspecialchars($empData['employee_email']) ?></span>
                            </div>
                        </div>
                        
                        <div class="card-body">
                            <div class="score-section">
                                <div class="score-circle <?= $scoreClass ?>">
                                    <span class="score-value"><?= number_format($score, 0) ?></span>
                                    <span class="score-label">%</span>
                                </div>
                                <div class="score-info">
                                    <span class="rating-badge <?= $scoreClass ?>"><?= $ratingLabel ?></span>
                                    <?php if($latestEval): ?>
                                        <span class="eval-date">
                                            <i class="far fa-calendar"></i>
                                            <?= date('M d, Y', strtotime($latestEval['updated_at'] ?: $latestEval['created_at'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if($latestEval && !empty($latestEval['notes'])): ?>
                            <div class="notes-preview">
                                <i class="fas fa-comment-alt"></i>
                                <?= htmlspecialchars(substr($latestEval['notes'], 0, 100)) ?>
                                <?= strlen($latestEval['notes']) > 100 ? '...' : '' ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="eval-count">
                                <i class="fas fa-clipboard-list"></i>
                                <?= count($empData['evaluations']) ?> evaluation(s) from HR1
                            </div>
                        </div>
                        
                        <div class="card-footer">
                            <button class="ai-btn" onclick="analyzeEmployee(<?= $empData['employee_id'] ?>, '<?= htmlspecialchars(addslashes($empData['employee_name'])) ?>', <?= $score ?>, '<?= htmlspecialchars(addslashes($empData['role'])) ?>')">
                                <i class="fas fa-brain"></i> AI Analysis
                            </button>
                            <button class="view-btn" onclick="viewEvaluationHistory(<?= $empData['employee_id'] ?>, '<?= htmlspecialchars(addslashes($empData['employee_name'])) ?>')">
                                <i class="fas fa-history"></i> History
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-inbox"></i>
                    <h3>No Evaluations Found</h3>
                    <p>No employee evaluations available from HR1 system.</p>
                    <?php if(!$hr1Response['success']): ?>
                        <p class="error-msg"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($hr1Response['error'] ?? 'Connection error') ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- AI Analysis Modal (kept for individual employee analysis) -->
<div class="modal-overlay" id="aiModal">
    <div class="modal-content ai-modal">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-robot"></i>
                <span id="aiModalTitle">AI Skill Analysis</span>
            </div>
            <button class="close-btn" onclick="closeAiModal()">&times;</button>
        </div>
        <div class="modal-body" id="aiModalBody">
            <div class="ai-loading">
                <div class="spinner"></div>
                <p>Analyzing with Groq AI...</p>
            </div>
        </div>
    </div>
</div>

<!-- Evaluation History Modal -->
<div class="modal-overlay" id="historyModal">
    <div class="modal-content history-modal">
        <div class="modal-header">
            <div class="modal-title">
                <i class="fas fa-history"></i>
                <span id="historyModalTitle">Evaluation History</span>
            </div>
            <button class="close-btn" onclick="closeHistoryModal()">&times;</button>
        </div>
        <div class="modal-body" id="historyModalBody">
            <!-- Will be populated by JS -->
        </div>
    </div>
</div>

<script>
// Store employee data for JS access
const employeeData = <?= json_encode(array_values($employeeEvaluations)) ?>;

function analyzeEmployee(employeeId, employeeName, score, role) {
    document.getElementById('aiModalTitle').innerText = 'AI Analysis: ' + employeeName;
    document.getElementById('aiModalBody').innerHTML = `
        <div class="ai-loading">
            <div class="spinner"></div>
            <p>Analyzing with Groq AI...</p>
        </div>
    `;
    document.getElementById('aiModal').classList.add('active');
    
    fetch('ai_analyze.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=analyze_hr1_employee&employee_id=${employeeId}&employee_name=${encodeURIComponent(employeeName)}&score=${score}&role=${encodeURIComponent(role)}`
    })
    .then(response => response.json())
    .then(data => {
        console.log('AI Response:', data); // Debug log
        if (data.success) {
            const providerBadge = data.provider === 'groq' 
                ? '<div class="ai-badge groq"><i class="fas fa-bolt"></i> Groq AI (Llama 3.3)</div>'
                : '<div class="ai-badge gemini"><i class="fas fa-robot"></i> ' + (data.provider || 'AI') + ' Analysis</div>';
            document.getElementById('aiModalBody').innerHTML = `
                <div class="ai-response">
                    ${providerBadge}
                    ${data.cached ? '<small style="color:#888;">📦 Cached response</small>' : ''}
                    ${formatAiResponse(data.data)}
                </div>
            `;
        } else {
            document.getElementById('aiModalBody').innerHTML = `
                <div class="ai-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <p><strong>Error:</strong> ${data.error || 'Analysis failed. Please try again.'}</p>
                        ${data.provider ? '<small>Provider: ' + data.provider + '</small>' : ''}
                        <button onclick="clearAICache()" style="margin-top:10px;padding:8px 16px;background:#dc3545;color:#fff;border:none;border-radius:6px;cursor:pointer;">
                            🔄 Clear Cache & Retry
                        </button>
                    </div>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        document.getElementById('aiModalBody').innerHTML = `
            <div class="ai-error">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Connection error: ${error.message || 'Please try again.'}</p>
            </div>
        `;
    });
}

function runBulkAnalysis() {
    document.getElementById('aiModalTitle').innerText = 'Bulk Analysis - All Employees';
    document.getElementById('aiModalBody').innerHTML = `
        <div class="ai-loading">
            <div class="spinner"></div>
            <p>Running Groq AI analysis on all employees...</p>
        </div>
    `;
    document.getElementById('aiModal').classList.add('active');
    
    fetch('ai_analyze.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=bulk_hr1_analysis'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let html = '<div class="bulk-results">';
            html += `<div class="ai-badge"><i class="fas fa-robot"></i> Groq AI Bulk Analysis</div>`;
            
            if (data.analyses && data.analyses.length > 0) {
                data.analyses.forEach((item, index) => {
                    html += `
                        <div class="bulk-item">
                            <div class="bulk-header">
                                <span class="bulk-number">${index + 1}</span>
                                <strong>${item.employee.name}</strong>
                                <span class="bulk-score">${item.employee.score || 'N/A'}%</span>
                            </div>
                            <div class="bulk-content">${formatAiResponse(item.analysis)}</div>
                        </div>
                    `;
                });
            } else {
                html += '<p>No analyses available.</p>';
            }
            
            html += '</div>';
            document.getElementById('aiModalBody').innerHTML = html;
        } else {
            document.getElementById('aiModalBody').innerHTML = `
                <div class="ai-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>${data.error || 'Bulk analysis failed.'}</p>
                </div>
            `;
        }
    })
    .catch(error => {
        document.getElementById('aiModalBody').innerHTML = `
            <div class="ai-error">
                <i class="fas fa-exclamation-triangle"></i>
                <p>Connection error. Please try again.</p>
            </div>
        `;
    });
}

function viewEvaluationHistory(employeeId, employeeName) {
    document.getElementById('historyModalTitle').innerText = 'History: ' + employeeName;
    
    const emp = employeeData.find(e => e.employee_id == employeeId);
    
    if (emp && emp.evaluations && emp.evaluations.length > 0) {
        let html = '<div class="history-list">';
        emp.evaluations.forEach((eval, index) => {
            const scoreClass = eval.overall_score >= 90 ? 'excellent' : 
                              eval.overall_score >= 80 ? 'good' : 
                              eval.overall_score >= 70 ? 'average' : 'low';
            html += `
                <div class="history-item">
                    <div class="history-header">
                        <span class="history-period">${eval.period || 'Evaluation'}</span>
                        <span class="history-score ${scoreClass}">${eval.overall_score}%</span>
                    </div>
                    <div class="history-details">
                        <span><i class="fas fa-tag"></i> ${eval.rating_label}</span>
                        <span><i class="far fa-calendar"></i> ${eval.updated_at ? new Date(eval.updated_at).toLocaleDateString() : 'N/A'}</span>
                    </div>
                    ${eval.notes ? `<p class="history-notes">${eval.notes}</p>` : ''}
                </div>
            `;
        });
        html += '</div>';
        document.getElementById('historyModalBody').innerHTML = html;
    } else {
        document.getElementById('historyModalBody').innerHTML = `
            <div class="no-history">
                <i class="fas fa-inbox"></i>
                <p>No evaluation history available.</p>
            </div>
        `;
    }
    
    document.getElementById('historyModal').classList.add('active');
}

function formatAiResponse(text) {
    if (!text) return '<p class="ai-empty">No analysis available.</p>';
    
    let html = text;
    
    // Convert markdown tables to HTML tables
    html = html.replace(/\|(.+)\|\n\|[-\s|]+\|\n((?:\|.+\|\n?)+)/g, function(match, header, rows) {
        const headerCells = header.split('|').filter(c => c.trim()).map(c => `<th>${c.trim()}</th>`).join('');
        const bodyRows = rows.trim().split('\n').map(row => {
            const cells = row.split('|').filter(c => c.trim()).map(c => `<td>${c.trim()}</td>`).join('');
            return `<tr>${cells}</tr>`;
        }).join('');
        return `<div class="ai-table-wrapper"><table class="ai-table"><thead><tr>${headerCells}</tr></thead><tbody>${bodyRows}</tbody></table></div>`;
    });
    
    // Convert headers ## to styled sections
    html = html.replace(/## (\d+)\. ([A-Z\s&]+)\n/g, '<div class="ai-section"><div class="ai-section-header"><span class="ai-section-num">$1</span><span class="ai-section-title">$2</span></div><div class="ai-section-content">');
    
    // Close sections before next section or end
    html = html.replace(/<div class="ai-section-content">([\s\S]*?)(?=<div class="ai-section"|$)/g, '<div class="ai-section-content">$1</div></div>');
    
    // Convert **bold** to styled spans
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong class="ai-highlight">$1</strong>');
    
    // Convert bullet points
    html = html.replace(/^[-•]\s*(.+)$/gm, '<li>$1</li>');
    html = html.replace(/(<li>.*<\/li>\n?)+/g, '<ul class="ai-list">$&</ul>');
    
    // Convert line breaks
    html = html.replace(/\n\n/g, '</p><p>');
    html = html.replace(/\n/g, '<br>');
    
    // Wrap paragraphs
    if (!html.startsWith('<')) {
        html = '<p>' + html + '</p>';
    }
    
    // Clean up empty paragraphs
    html = html.replace(/<p>\s*<\/p>/g, '');
    html = html.replace(/<p>\s*<br>\s*<\/p>/g, '');
    
    return html;
}

function closeAiModal() {
    document.getElementById('aiModal').classList.remove('active');
}

function closeHistoryModal() {
    document.getElementById('historyModal').classList.remove('active');
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
        }
    });
});

// Close modals on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.classList.remove('active');
        });
    }
});

// Clear AI Cache function
function clearAICache() {
    fetch('ai_analyze.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=clear_ai_cache'
    })
    .then(response => response.json())
    .then(data => {
        alert(data.message || 'Cache cleared!');
        closeAiModal();
    })
    .catch(error => {
        alert('Failed to clear cache: ' + error.message);
    });
}

/* ===== AI CHAT BUBBLE FUNCTIONS ===== */
function toggleAiChat() {
    const chatWindow = document.getElementById('aiChatWindow');
    const bubbleBtn = document.getElementById('aiBubbleBtn');
    chatWindow.classList.toggle('active');
    
    if (chatWindow.classList.contains('active')) {
        bubbleBtn.innerHTML = '<i class="fas fa-times"></i>';
    } else {
        bubbleBtn.innerHTML = '<i class="fas fa-robot"></i><span class="ai-pulse"></span>';
    }
}

function addChatMessage(content, isBot = true, isCustom = false) {
    const body = document.getElementById('aiChatBody');
    const now = new Date();
    const timeStr = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    
    const messageDiv = document.createElement('div');
    messageDiv.className = `ai-message ${isBot ? 'ai-bot' : 'ai-user'}`;
    
    if (isCustom) {
        messageDiv.innerHTML = content;
    } else {
        messageDiv.innerHTML = `
            <div class="ai-message-avatar">
                <i class="fas fa-${isBot ? 'robot' : 'user'}"></i>
            </div>
            <div class="ai-message-bubble">
                ${content}
                <span class="ai-message-time">${timeStr}</span>
            </div>
        `;
    }
    
    body.appendChild(messageDiv);
    body.scrollTop = body.scrollHeight;
}

function showTypingIndicator() {
    const body = document.getElementById('aiChatBody');
    const typingDiv = document.createElement('div');
    typingDiv.className = 'ai-message ai-bot';
    typingDiv.id = 'aiTyping';
    typingDiv.innerHTML = `
        <div class="ai-message-avatar">
            <i class="fas fa-robot"></i>
        </div>
        <div class="ai-message-bubble">
            <div class="ai-typing">
                <span></span><span></span><span></span>
            </div>
        </div>
    `;
    body.appendChild(typingDiv);
    body.scrollTop = body.scrollHeight;
}

function hideTypingIndicator() {
    const typing = document.getElementById('aiTyping');
    if (typing) typing.remove();
}

// Override runBulkAnalysis for chat bubble
const originalRunBulkAnalysis = runBulkAnalysis;
runBulkAnalysis = function() {
    const chatWindow = document.getElementById('aiChatWindow');
    const btn = document.getElementById('aiRefreshBtn');
    
    // Open chat if closed
    if (!chatWindow.classList.contains('active')) {
        toggleAiChat();
    }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analyzing...';
    
    addChatMessage('<p>Analyze all employees from HR1</p>', false);
    setTimeout(() => showTypingIndicator(), 500);
    
    fetch('ai_analyze.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=bulk_hr1_analysis&limit=5'
    })
    .then(response => response.json())
    .then(data => {
        hideTypingIndicator();
        
        if (data.success && data.analyses && data.analyses.length > 0) {
            addChatMessage(`<p>📊 Found <strong>${data.analyses.length}</strong> employees. Here's my analysis:</p>`);
            
            data.analyses.forEach((item, index) => {
                setTimeout(() => {
                    let analysisText = item.analysis || '';
                    analysisText = analysisText.replace(/\*\*/g, '').replace(/\*/g, '').replace(/#{1,6}\s?/g, '');
                    analysisText = analysisText.substring(0, 180);
                    
                    const score = parseFloat(item.employee?.score) || 0;
                    const scorePercent = score.toFixed(0);
                    const scoreClass = score >= 80 ? 'excellent' : score >= 60 ? 'good' : 'needs-improvement';
                    
                    const bubbleHtml = `
                        <div class="ai-message-avatar">
                            <i class="fas fa-robot"></i>
                        </div>
                        <div class="ai-message-bubble" style="max-width: 300px;">
                            <div class="ai-employee-bubble">
                                <strong>${item.employee?.name || 'Employee'}</strong>
                                <span class="score-badge ${scoreClass}">${scorePercent}%</span>
                                <p>${analysisText}${analysisText.length >= 180 ? '...' : ''}</p>
                            </div>
                        </div>
                    `;
                    addChatMessage(bubbleHtml, true, true);
                }, index * 600);
            });
            
            setTimeout(() => {
                addChatMessage(`<p>✅ Analysis complete! Click individual employee cards for detailed reports.</p>`);
            }, data.analyses.length * 600 + 400);
            
        } else {
            addChatMessage(`<p>⚠️ ${data.error || 'No employee data available from HR1.'}</p>`);
        }
    })
    .catch(error => {
        hideTypingIndicator();
        addChatMessage(`<p>❌ Error: ${error.message || 'Unable to connect to AI service.'}</p>`);
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-brain"></i> Analyze All Employees';
    });
};
</script>
</body>
</html>
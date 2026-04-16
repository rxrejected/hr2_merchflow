<?php
/**
 * Module 1 Sub 3 - HR2 Assessment Quiz
 * Admin evaluates HR1 employees via 10 multiple-choice questions
 * This assessment provides 70% of the combined competency score
 * ADMIN POV ONLY
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';
require_once 'Connection/hr1_db.php';

// Admin-only access
$userRole = strtolower(str_replace(' ', '', $_SESSION['role'] ?? ''));
if (!in_array($userRole, ['admin', 'manager', 'superadmin'])) {
    header('Location: employee.php');
    exit();
}

$evaluatorId = $_SESSION['user_id'] ?? 0;
$evaluatorName = $_SESSION['name'] ?? 'Admin';

// Fetch HR1 employees
$hr1db = new HR1Database();
$hr1Response = $hr1db->getEmployees('', 500, 0);
$hr1Employees = $hr1Response['success'] ? $hr1Response['data'] : [];
$hr1db->close();

// Fetch existing HR2 assessments
$assessments = [];
$result = $conn->query("SELECT * FROM hr2_assessments ORDER BY created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $assessments[] = $row;
    }
}

// Group latest assessment per employee
$latestAssessments = [];
foreach ($assessments as $a) {
    $empId = $a['hr1_employee_id'];
    if (!isset($latestAssessments[$empId])) {
        $latestAssessments[$empId] = $a;
    }
}

// Quiz Questions Definition (10 questions, 4 choices each)
$quizQuestions = [
    'job_knowledge' => [
        'number' => 1,
        'question' => 'How well does this employee understand and apply their job-specific knowledge?',
        'icon' => 'fa-brain',
        'choices' => [
            1 => 'Frequently makes errors due to lack of knowledge',
            2 => 'Has basic knowledge but sometimes needs assistance',
            3 => 'Demonstrates strong knowledge and applies it consistently',
            4 => 'Expert-level knowledge; mentors others effectively'
        ]
    ],
    'work_quality' => [
        'number' => 2,
        'question' => 'How would you describe the quality of this employee\'s work output?',
        'icon' => 'fa-gem',
        'choices' => [
            1 => 'Work often requires significant corrections and revisions',
            2 => 'Work is acceptable but occasionally needs revision',
            3 => 'Produces high-quality work consistently with minimal errors',
            4 => 'Delivers exceptional work that exceeds all standards'
        ]
    ],
    'productivity' => [
        'number' => 3,
        'question' => 'How effectively does this employee manage their workload and deadlines?',
        'icon' => 'fa-chart-line',
        'choices' => [
            1 => 'Frequently misses deadlines and has low output',
            2 => 'Usually meets deadlines with standard output',
            3 => 'Consistently meets all deadlines and works efficiently',
            4 => 'Exceeds all targets and optimizes processes for the team'
        ]
    ],
    'reliability' => [
        'number' => 4,
        'question' => 'How dependable is this employee in fulfilling their responsibilities?',
        'icon' => 'fa-shield-alt',
        'choices' => [
            1 => 'Unreliable; frequent absences or missed commitments',
            2 => 'Generally reliable but occasionally inconsistent',
            3 => 'Very dependable with minimal supervision needed',
            4 => 'Exceptionally reliable; trusted with critical tasks and responsibilities'
        ]
    ],
    'initiative' => [
        'number' => 5,
        'question' => 'How does this employee demonstrate initiative and self-motivation?',
        'icon' => 'fa-lightbulb',
        'choices' => [
            1 => 'Waits to be told what to do; shows no self-direction',
            2 => 'Completes assigned tasks but rarely suggests improvements',
            3 => 'Proactively identifies issues and takes action to resolve them',
            4 => 'Drives innovation and leads new initiatives independently'
        ]
    ],
    'communication' => [
        'number' => 6,
        'question' => 'How effectively does this employee communicate with others?',
        'icon' => 'fa-comments',
        'choices' => [
            1 => 'Poor communication; causes frequent misunderstandings',
            2 => 'Communicates adequately but could be clearer',
            3 => 'Clear, professional, and effective communicator',
            4 => 'Exceptional communicator who influences and inspires others'
        ]
    ],
    'teamwork' => [
        'number' => 7,
        'question' => 'How well does this employee collaborate and work with the team?',
        'icon' => 'fa-users',
        'choices' => [
            1 => 'Has difficulty working with team members; causes friction',
            2 => 'Cooperates when required but prefers to work alone',
            3 => 'Active team contributor who supports and helps colleagues',
            4 => 'Natural team leader who elevates overall team performance'
        ]
    ],
    'problem_solving' => [
        'number' => 8,
        'question' => 'How does this employee handle challenges and problem-solving?',
        'icon' => 'fa-puzzle-piece',
        'choices' => [
            1 => 'Struggles to resolve problems; needs constant guidance',
            2 => 'Solves routine problems with some guidance from others',
            3 => 'Effectively solves complex problems independently',
            4 => 'Develops innovative solutions to the most difficult challenges'
        ]
    ],
    'adaptability' => [
        'number' => 9,
        'question' => 'How well does this employee adapt to changes and new situations?',
        'icon' => 'fa-sync-alt',
        'choices' => [
            1 => 'Resists change and struggles with new situations',
            2 => 'Accepts change but takes considerable time to adjust',
            3 => 'Adapts quickly and willingly embraces new approaches',
            4 => 'Thrives in change; helps others navigate transitions smoothly'
        ]
    ],
    'leadership' => [
        'number' => 10,
        'question' => 'How does this employee demonstrate leadership qualities?',
        'icon' => 'fa-crown',
        'choices' => [
            1 => 'Shows no leadership interest or capability',
            2 => 'Occasionally leads by example in basic tasks',
            3 => 'Effectively guides and motivates team members',
            4 => 'Inspires others and drives organizational goals forward'
        ]
    ]
];

$choiceLabels = ['A', 'B', 'C', 'D'];

// Stats
$totalAssessed = count($latestAssessments);
$totalEmployees = count($hr1Employees);
$avgScore = 0;
if ($totalAssessed > 0) {
    $sumScore = array_sum(array_column($latestAssessments, 'overall_score'));
    $avgScore = $sumScore / $totalAssessed;
}
$pendingCount = $totalEmployees - $totalAssessed;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>HR2 Assessment Quiz | Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
  <link rel="icon" type="image/png" href="osicon.png" />
  <link rel="stylesheet" href="Css/module1_sub3.css?v=<?php echo time(); ?>">
</head>
<body>
  <?php include 'partials/sidebar.php'; ?>

  <div class="main-content">
    <?php include 'partials/nav.php'; ?>

    <div class="container">

      <!-- Page Header -->
      <div class="page-header">
        <div class="header-content">
          <h2><i class="fas fa-clipboard-list"></i> HR2 Assessment Quiz</h2>
          <p class="page-subtitle">
            <i class="fas fa-user-shield"></i> Admin Panel — Evaluate employees with a 10-question multiple choice quiz
            <span class="weight-tag">This provides <strong>70%</strong> of the combined score</span>
          </p>
        </div>
      </div>

      <!-- Stats Banner -->
      <div class="stats-banner">
        <div class="stat-card">
          <div class="stat-icon primary"><i class="fas fa-users"></i></div>
          <div class="stat-content">
            <h3><?= $totalEmployees ?></h3>
            <p>Total Employees</p>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon success"><i class="fas fa-check-circle"></i></div>
          <div class="stat-content">
            <h3><?= $totalAssessed ?></h3>
            <p>Assessed</p>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon warning"><i class="fas fa-hourglass-half"></i></div>
          <div class="stat-content">
            <h3><?= max(0, $pendingCount) ?></h3>
            <p>Pending</p>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon info"><i class="fas fa-chart-bar"></i></div>
          <div class="stat-content">
            <h3><?= number_format($avgScore, 1) ?>%</h3>
            <p>Average Score</p>
          </div>
        </div>
      </div>

      <!-- Search & Filter -->
      <div class="search-sort">
        <div class="search-wrapper">
          <i class="fas fa-search search-icon"></i>
          <input type="text" id="searchInput" placeholder="Search employee name..." autocomplete="off" />
        </div>
        <div class="filter-wrapper">
          <select id="filterStatus">
            <option value="all">All Employees</option>
            <option value="assessed">Assessed</option>
            <option value="pending">Not Yet Assessed</option>
          </select>
          <select id="sortSelect">
            <option value="name-asc">Name (A-Z)</option>
            <option value="name-desc">Name (Z-A)</option>
            <option value="score-high">Score (High to Low)</option>
            <option value="score-low">Score (Low to High)</option>
            <option value="recent">Recently Assessed</option>
          </select>
        </div>
      </div>

      <!-- Employee Table -->
      <div class="table-container">
        <table class="assessment-table" id="assessmentTable">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Department</th>
              <th>Position</th>
              <th>Quiz Score</th>
              <th>Rating</th>
              <th>Period</th>
              <th>Date Assessed</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($hr1Employees as $emp): 
              $empId = $emp['id'];
              $assessment = $latestAssessments[$empId] ?? null;
              $score = $assessment ? (float)$assessment['overall_score'] : 0;
              $rating = $assessment ? $assessment['rating_label'] : 'Not Assessed';
              $dateHired = $emp['date_hired'] ?? $emp['start_date'] ?? $emp['created_at'] ?? null;
              $isNew = false;
              if ($dateHired) {
                  $hiredDate = new DateTime($dateHired);
                  $now = new DateTime();
                  $diff = $now->diff($hiredDate);
                  $isNew = ($diff->y == 0 && $diff->m < 6);
              }
            ?>
            <tr class="employee-row" 
                data-id="<?= $empId ?>"
                data-name="<?= strtolower(htmlspecialchars($emp['name'])) ?>"
                data-assessed="<?= $assessment ? '1' : '0' ?>"
                data-score="<?= $score ?>"
                data-date="<?= $assessment ? $assessment['created_at'] : '' ?>">
              <td>
                <div class="emp-info">
                  <div class="emp-avatar">
                    <img src="uploads/avatars/default.png" alt="" onerror="this.src='uploads/avatars/default.png'" />
                  </div>
                  <div>
                    <strong><?= htmlspecialchars($emp['name']) ?></strong>
                    <?php if ($isNew): ?>
                      <span class="badge badge-new"><i class="fas fa-sparkles"></i> New</span>
                    <?php else: ?>
                      <span class="badge badge-regular">Regular</span>
                    <?php endif; ?>
                    <br><small><?= htmlspecialchars($emp['email']) ?></small>
                  </div>
                </div>
              </td>
              <td><?= htmlspecialchars($emp['department'] ?: 'Operations') ?></td>
              <td><?= htmlspecialchars($emp['role'] ?: 'Employee') ?></td>
              <td>
                <?php if ($assessment): ?>
                  <div class="score-display">
                    <div class="score-bar">
                      <div class="score-fill" style="width:<?= $score ?>%; background:<?= $score >= 80 ? '#10b981' : ($score >= 60 ? '#3b82f6' : ($score >= 40 ? '#f59e0b' : '#ef4444')) ?>"></div>
                    </div>
                    <span class="score-value"><?= number_format($score, 1) ?>%</span>
                  </div>
                <?php else: ?>
                  <span class="text-muted">— Not taken</span>
                <?php endif; ?>
              </td>
              <td>
                <span class="rating-badge rating-<?= strtolower(str_replace(' ', '-', $rating)) ?>">
                  <?= htmlspecialchars($rating) ?>
                </span>
              </td>
              <td><?= $assessment ? htmlspecialchars($assessment['period'] ?: '—') : '—' ?></td>
              <td><?= $assessment ? date('M d, Y', strtotime($assessment['created_at'])) : '—' ?></td>
              <td>
                <div class="action-btns">
                  <button class="btn-quiz" onclick="startQuiz(<?= $empId ?>, '<?= htmlspecialchars(addslashes($emp['name'])) ?>', '<?= htmlspecialchars(addslashes($emp['email'])) ?>')" title="<?= $assessment ? 'Retake Quiz' : 'Start Quiz' ?>">
                    <i class="fas fa-<?= $assessment ? 'redo' : 'play-circle' ?>"></i>
                  </button>
                  <?php if ($assessment): ?>
                  <button class="btn-view" onclick="viewResults(<?= $empId ?>)" title="View Results">
                    <i class="fas fa-eye"></i>
                  </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (count($hr1Employees) === 0): ?>
        <div class="empty-state">
          <i class="fas fa-database"></i>
          <h3>No Employees Found</h3>
          <p>Unable to fetch employee data from HR1 system.</p>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <!-- Quiz Modal -->
  <div id="quizModal" class="modal">
    <div class="modal-content modal-large">
      <div class="modal-header">
        <h3 id="quizTitle"><i class="fas fa-clipboard-list"></i> Assessment Quiz</h3>
        <button class="close" onclick="closeModal('quizModal')">&times;</button>
      </div>
      <form id="quizForm">
        <input type="hidden" id="hr1_employee_id" name="hr1_employee_id" />
        <input type="hidden" id="hr1_employee_name" name="hr1_employee_name" />
        <input type="hidden" id="hr1_employee_email" name="hr1_employee_email" />

        <div class="modal-body">
          <!-- Employee Info -->
          <div class="quiz-employee-bar">
            <div class="quiz-emp-icon"><i class="fas fa-user-circle"></i></div>
            <div>
              <strong id="quizEmpName">—</strong>
              <small id="quizEmpEmail">—</small>
            </div>
          </div>

          <!-- Period Selection -->
          <div class="quiz-period-section">
            <label for="period"><i class="fas fa-calendar-alt"></i> Assessment Period</label>
            <select id="period" name="period" required>
              <option value="">Select period...</option>
              <?php 
              $year = date('Y');
              foreach (['Q1','Q2','Q3','Q4'] as $q) {
                  $val = "$q $year";
                  $sel = ($q === 'Q'.ceil(date('n')/3)) ? 'selected' : '';
                  echo "<option value=\"$val\" $sel>$val</option>";
              }
              echo "<option value=\"Annual $year\">Annual $year</option>";
              echo "<option value=\"Probationary\">Probationary Review</option>";
              ?>
            </select>
          </div>

          <!-- Progress Bar -->
          <div class="quiz-progress">
            <div class="quiz-progress-bar">
              <div class="quiz-progress-fill" id="quizProgressFill" style="width:0%"></div>
            </div>
            <span class="quiz-progress-text"><span id="answeredCount">0</span> / 10 answered</span>
          </div>

          <!-- Quiz Questions -->
          <div class="quiz-questions">
            <?php foreach ($quizQuestions as $key => $q): ?>
            <div class="quiz-question-card" data-question="<?= $key ?>">
              <div class="question-header">
                <span class="question-number">Q<?= $q['number'] ?></span>
                <div class="question-text">
                  <i class="fas <?= $q['icon'] ?>"></i>
                  <?= $q['question'] ?>
                </div>
              </div>
              <div class="choices-grid">
                <?php $ci = 0; foreach ($q['choices'] as $val => $text): ?>
                <label class="choice-option" data-value="<?= $val ?>">
                  <input type="radio" name="<?= $key ?>" value="<?= $val ?>" />
                  <div class="choice-card">
                    <span class="choice-letter"><?= $choiceLabels[$ci] ?></span>
                    <span class="choice-text"><?= $text ?></span>
                  </div>
                </label>
                <?php $ci++; endforeach; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Live Score Preview -->
          <div class="quiz-score-preview" id="scorePreviewSection">
            <div class="score-preview-header">
              <i class="fas fa-calculator"></i> Live Score Preview
            </div>
            <div class="score-preview-body">
              <div class="score-circle-preview" id="scoreCircle">
                <svg viewBox="0 0 100 100">
                  <circle class="score-bg" cx="50" cy="50" r="45" />
                  <circle class="score-ring" id="scoreRing" cx="50" cy="50" r="45" 
                          stroke-dasharray="283" stroke-dashoffset="283" />
                </svg>
                <div class="score-center">
                  <span class="score-number" id="scoreNumber">0</span>
                  <span class="score-percent">%</span>
                </div>
              </div>
              <div class="score-info">
                <div class="score-info-row">
                  <span>Rating:</span>
                  <strong id="ratingPreview">Not Yet Rated</strong>
                </div>
                <div class="score-info-row">
                  <span>Points:</span>
                  <strong><span id="pointsPreview">0</span> / 40</strong>
                </div>
                <div class="score-info-row">
                  <span>HR2 Weight (70%):</span>
                  <strong id="weightedPreview">0.0%</strong>
                </div>
              </div>
            </div>
          </div>

          <!-- Optional Comments -->
          <div class="quiz-comments-section">
            <div class="comments-toggle" onclick="toggleComments()">
              <i class="fas fa-comment-alt"></i> Add Comments (Optional)
              <i class="fas fa-chevron-down" id="commentsChevron"></i>
            </div>
            <div class="comments-fields" id="commentsFields" style="display:none">
              <div class="form-group">
                <label>Key Strengths</label>
                <textarea name="strengths" rows="2" placeholder="Notable strengths observed..."></textarea>
              </div>
              <div class="form-group">
                <label>Areas for Improvement</label>
                <textarea name="areas_for_improvement" rows="2" placeholder="Areas that need development..."></textarea>
              </div>
              <div class="form-group">
                <label>Additional Comments</label>
                <textarea name="comments" rows="2" placeholder="Any other observations..."></textarea>
              </div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="closeModal('quizModal')">
            <i class="fas fa-times"></i> Cancel
          </button>
          <button type="submit" class="btn btn-primary" id="submitQuiz" disabled>
            <i class="fas fa-paper-plane"></i> Submit Quiz
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- View Results Modal -->
  <div id="resultsModal" class="modal">
    <div class="modal-content modal-large">
      <div class="modal-header">
        <h3 id="resultsTitle"><i class="fas fa-poll"></i> Quiz Results</h3>
        <button class="close" onclick="closeModal('resultsModal')">&times;</button>
      </div>
      <div id="resultsContent" class="modal-body">
        <div class="loading-state"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
      </div>
    </div>
  </div>

  <!-- Toast -->
  <div id="toast" class="toast">
    <i class="fas fa-check-circle"></i>
    <span id="toastMsg">Done</span>
  </div>

<script>
const hr1Employees = <?= json_encode($hr1Employees) ?>;
const latestAssessments = <?= json_encode($latestAssessments) ?>;
const questionKeys = <?= json_encode(array_keys($quizQuestions)) ?>;
const quizQuestions = <?= json_encode($quizQuestions) ?>;
const choiceLabels = ['A','B','C','D'];
const MAX_PER_Q = 4;
const TOTAL_QUESTIONS = 10;
const MAX_POINTS = TOTAL_QUESTIONS * MAX_PER_Q;

function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    document.getElementById('toastMsg').textContent = msg;
    t.className = 'toast show ' + type;
    setTimeout(() => t.classList.remove('show'), 3000);
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
    document.body.style.overflow = '';
}

function getRatingLabel(pct) {
    if (pct >= 90) return 'Outstanding';
    if (pct >= 80) return 'Excellent';
    if (pct >= 70) return 'Very Good';
    if (pct >= 60) return 'Good';
    if (pct >= 50) return 'Fair';
    return 'Needs Improvement';
}

function getRatingColor(pct) {
    if (pct >= 90) return '#10b981';
    if (pct >= 80) return '#3b82f6';
    if (pct >= 70) return '#8b5cf6';
    if (pct >= 60) return '#f59e0b';
    if (pct >= 50) return '#f97316';
    return '#ef4444';
}

// Start Quiz
function startQuiz(empId, empName, empEmail) {
    document.getElementById('hr1_employee_id').value = empId;
    document.getElementById('hr1_employee_name').value = empName;
    document.getElementById('hr1_employee_email').value = empEmail;
    document.getElementById('quizEmpName').textContent = empName;
    document.getElementById('quizEmpEmail').textContent = empEmail;
    document.getElementById('quizTitle').innerHTML = '<i class="fas fa-clipboard-list"></i> Quiz: ' + empName;

    // Pre-fill if retaking
    const existing = latestAssessments[empId];
    if (existing) {
        questionKeys.forEach(key => {
            const val = parseInt(existing[key]) || 0;
            if (val >= 1 && val <= 4) {
                const radio = document.querySelector(`input[name="${key}"][value="${val}"]`);
                if (radio) {
                    radio.checked = true;
                    radio.closest('.choice-option').classList.add('selected');
                }
            }
        });
        document.getElementById('period').value = existing.period || '';
        document.querySelector('textarea[name="strengths"]').value = existing.strengths || '';
        document.querySelector('textarea[name="areas_for_improvement"]').value = existing.areas_for_improvement || '';
        document.querySelector('textarea[name="comments"]').value = existing.comments || '';
    } else {
        document.getElementById('quizForm').reset();
        document.querySelectorAll('.choice-option').forEach(c => c.classList.remove('selected'));
    }

    updateScorePreview();
    document.getElementById('quizModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// Handle choice selection
document.querySelectorAll('.choice-option input[type="radio"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const card = this.closest('.quiz-question-card');
        card.querySelectorAll('.choice-option').forEach(c => c.classList.remove('selected'));
        this.closest('.choice-option').classList.add('selected');
        card.classList.add('answered');
        updateScorePreview();
    });
});

// Update live score
function updateScorePreview() {
    let total = 0;
    let answered = 0;
    
    questionKeys.forEach(key => {
        const checked = document.querySelector(`input[name="${key}"]:checked`);
        if (checked) {
            total += parseInt(checked.value);
            answered++;
        }
    });

    const pct = answered > 0 ? (total / MAX_POINTS) * 100 : 0;
    const weighted = pct * 0.70;

    document.getElementById('scoreNumber').textContent = pct.toFixed(1);
    document.getElementById('ratingPreview').textContent = answered > 0 ? getRatingLabel(pct) : 'Not Yet Rated';
    document.getElementById('ratingPreview').style.color = getRatingColor(pct);
    document.getElementById('pointsPreview').textContent = total;
    document.getElementById('weightedPreview').textContent = weighted.toFixed(1) + '%';
    document.getElementById('answeredCount').textContent = answered;

    const progressPct = (answered / TOTAL_QUESTIONS) * 100;
    document.getElementById('quizProgressFill').style.width = progressPct + '%';

    // SVG ring animation
    const circumference = 2 * Math.PI * 45;
    const offset = circumference - (pct / 100) * circumference;
    const ring = document.getElementById('scoreRing');
    ring.style.strokeDashoffset = offset;
    ring.style.stroke = getRatingColor(pct);

    document.getElementById('submitQuiz').disabled = (answered < TOTAL_QUESTIONS);
}

function toggleComments() {
    const fields = document.getElementById('commentsFields');
    const chevron = document.getElementById('commentsChevron');
    if (fields.style.display === 'none') {
        fields.style.display = 'block';
        chevron.classList.replace('fa-chevron-down', 'fa-chevron-up');
    } else {
        fields.style.display = 'none';
        chevron.classList.replace('fa-chevron-up', 'fa-chevron-down');
    }
}

// Submit quiz
document.getElementById('quizForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('submitQuiz');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

    try {
        const res = await fetch('api_hr2_assessment.php', { method: 'POST', body: new FormData(this) });
        const data = await res.json();
        if (data.success) {
            showToast('Quiz submitted! Score: ' + data.overall_score + '% — ' + data.rating_label, 'success');
            closeModal('quizModal');
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast('Error: ' + (data.error || 'Failed to save'), 'error');
        }
    } catch (err) {
        showToast('Connection error. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Quiz';
    }
});

// View Results
function viewResults(empId) {
    const emp = hr1Employees.find(e => e.id == empId);
    const a = latestAssessments[empId];
    if (!a || !emp) { showToast('No results found', 'error'); return; }

    const score = parseFloat(a.overall_score);
    const color = getRatingColor(score);

    let questionsHTML = '';
    questionKeys.forEach((key, idx) => {
        const q = quizQuestions[key];
        const val = parseInt(a[key]) || 0;
        const selectedLabel = val >= 1 && val <= 4 ? choiceLabels[val - 1] : '—';
        const selectedText = val >= 1 && val <= 4 ? q.choices[val] : 'No answer';
        const barPct = (val / MAX_PER_Q) * 100;

        questionsHTML += `
            <div class="result-question">
                <div class="result-q-header">
                    <span class="result-q-num">Q${q.number}</span>
                    <span class="result-q-text">${q.question}</span>
                    <span class="result-q-score" style="color:${getRatingColor(barPct)}">${val}/${MAX_PER_Q}</span>
                </div>
                <div class="result-answer">
                    <span class="result-letter" style="background:${getRatingColor(barPct)}">${selectedLabel}</span>
                    <span>${selectedText}</span>
                </div>
                <div class="result-bar">
                    <div class="result-bar-fill" style="width:${barPct}%; background:${getRatingColor(barPct)}"></div>
                </div>
            </div>
        `;
    });

    document.getElementById('resultsTitle').innerHTML = '<i class="fas fa-poll"></i> Results: ' + emp.name;
    document.getElementById('resultsContent').innerHTML = `
        <div class="results-summary">
            <div class="results-score-circle" style="border-color:${color}">
                <span class="results-score-num" style="color:${color}">${score.toFixed(1)}%</span>
                <span class="results-score-label">${a.rating_label}</span>
            </div>
            <div class="results-info">
                <h4>${emp.name}</h4>
                <p>${emp.role || 'Employee'} &bull; ${emp.department || 'Operations'}</p>
                <p class="text-muted">Period: ${a.period || 'N/A'} &bull; ${new Date(a.created_at).toLocaleDateString('en-US', {year:'numeric',month:'long',day:'numeric'})}</p>
                <span class="results-weight-badge">HR2 Weight: ${score.toFixed(1)}% &times; 70% = <strong>${(score * 0.70).toFixed(1)}%</strong></span>
            </div>
        </div>
        <div class="results-questions-section">
            <h4><i class="fas fa-list-ol"></i> Question-by-Question Results</h4>
            ${questionsHTML}
        </div>
        ${a.strengths ? `<div class="results-text-section"><h4><i class="fas fa-star"></i> Key Strengths</h4><p>${a.strengths}</p></div>` : ''}
        ${a.areas_for_improvement ? `<div class="results-text-section"><h4><i class="fas fa-arrow-up"></i> Areas for Improvement</h4><p>${a.areas_for_improvement}</p></div>` : ''}
        ${a.comments ? `<div class="results-text-section"><h4><i class="fas fa-comment"></i> Comments</h4><p>${a.comments}</p></div>` : ''}
    `;

    document.getElementById('resultsModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

// Search & Filter
document.getElementById('searchInput').addEventListener('input', applyFilters);
document.getElementById('filterStatus').addEventListener('change', applyFilters);
document.getElementById('sortSelect').addEventListener('change', applySort);

function applyFilters() {
    const q = document.getElementById('searchInput').value.toLowerCase().trim();
    const f = document.getElementById('filterStatus').value;
    document.querySelectorAll('.employee-row').forEach(row => {
        const name = row.dataset.name;
        const assessed = row.dataset.assessed === '1';
        let show = name.includes(q);
        if (f === 'assessed') show = show && assessed;
        if (f === 'pending') show = show && !assessed;
        row.style.display = show ? '' : 'none';
    });
}

function applySort() {
    const sort = document.getElementById('sortSelect').value;
    const tbody = document.querySelector('#assessmentTable tbody');
    const rows = Array.from(tbody.querySelectorAll('.employee-row'));
    rows.sort((a, b) => {
        switch(sort) {
            case 'name-asc': return a.dataset.name.localeCompare(b.dataset.name);
            case 'name-desc': return b.dataset.name.localeCompare(a.dataset.name);
            case 'score-high': return parseFloat(b.dataset.score||0) - parseFloat(a.dataset.score||0);
            case 'score-low': return parseFloat(a.dataset.score||0) - parseFloat(b.dataset.score||0);
            case 'recent': return (b.dataset.date||'').localeCompare(a.dataset.date||'');
            default: return 0;
        }
    });
    rows.forEach(r => tbody.appendChild(r));
}

document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.modal').forEach(m => {
        if (m.style.display === 'flex') closeModal(m.id);
    });
});

updateScorePreview();
</script>
</body>
</html>

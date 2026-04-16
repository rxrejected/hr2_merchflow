<?php
/**
 * MODULE 5 SUB 5 - MY ASSESSMENTS
 * HR2 MerchFlow - Employee Self-Service Portal
 * Employees can take MCQ assessments and view their results
 * Uses: assessments, assessment_questions, assessment_answers tables
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

$employee_id = (int)$_SESSION['user_id'];
$from_employee_table = isset($_SESSION['from_employee_table']) && $_SESSION['from_employee_table'] === true;
$hr1_employee_id = $_SESSION['hr1_employee_id'] ?? null;
$employee_name = $_SESSION['full_name'] ?? 'Employee';

// ===== HANDLE POST: Submit Assessment Answers =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assessment'])) {
    header('Content-Type: application/json');
    
    $assessment_id = (int)($_POST['assessment_id'] ?? 0);
    $answers = $_POST['answers'] ?? [];
    
    if ($assessment_id <= 0 || empty($answers)) {
        echo json_encode(['success' => false, 'error' => 'Invalid submission']);
        exit;
    }
    
    // Fetch correct answers for this assessment
    $qStmt = $conn->prepare("SELECT id, correct_option FROM assessment_questions WHERE assessment_id = ?");
    $qStmt->bind_param("i", $assessment_id);
    $qStmt->execute();
    $questions = $qStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $qStmt->close();
    
    if (empty($questions)) {
        echo json_encode(['success' => false, 'error' => 'No questions found']);
        exit;
    }
    
    // Build correct answer map
    $correctMap = [];
    foreach ($questions as $q) {
        $correctMap[$q['id']] = strtoupper($q['correct_option']);
    }
    
    // Delete previous answers for this user + assessment (allow retake)
    $delStmt = $conn->prepare("DELETE FROM assessment_answers WHERE assessment_id = ? AND user_id = ?");
    $delStmt->bind_param("ii", $assessment_id, $employee_id);
    $delStmt->execute();
    $delStmt->close();
    
    // Insert new answers
    $insertStmt = $conn->prepare("INSERT INTO assessment_answers (assessment_id, question_id, user_id, selected_option, is_correct, submitted_at) VALUES (?, ?, ?, ?, ?, NOW())");
    
    $totalCorrect = 0;
    $totalQuestions = count($correctMap);
    
    foreach ($answers as $question_id => $selected) {
        $qid = (int)$question_id;
        $sel = strtoupper(trim($selected));
        $isCorrect = (isset($correctMap[$qid]) && $correctMap[$qid] === $sel) ? 1 : 0;
        if ($isCorrect) $totalCorrect++;
        
        $insertStmt->bind_param("iiisi", $assessment_id, $qid, $employee_id, $sel, $isCorrect);
        $insertStmt->execute();
    }
    $insertStmt->close();
    
    $score = $totalQuestions > 0 ? round(($totalCorrect / $totalQuestions) * 100, 1) : 0;
    $passed = $score >= 70;
    
    echo json_encode([
        'success' => true,
        'score' => $score,
        'correct' => $totalCorrect,
        'total' => $totalQuestions,
        'passed' => $passed,
        'rating' => $score >= 90 ? 'Outstanding' : ($score >= 80 ? 'Excellent' : ($score >= 70 ? 'Very Good' : ($score >= 60 ? 'Good' : ($score >= 50 ? 'Fair' : 'Needs Improvement'))))
    ]);
    exit;
}

// ===== FETCH ASSESSMENTS =====
$assessmentsTableExists = $conn->query("SHOW TABLES LIKE 'assessments'")->num_rows > 0;
$questionsTableExists = $conn->query("SHOW TABLES LIKE 'assessment_questions'")->num_rows > 0;
$answersTableExists = $conn->query("SHOW TABLES LIKE 'assessment_answers'")->num_rows > 0;

$assessments = [];
$myResults = [];
$stats = ['total' => 0, 'completed' => 0, 'passed' => 0, 'avg_score' => 0];

if ($assessmentsTableExists && $questionsTableExists) {
    // Fetch all assessments with question count
    $aResult = $conn->query("
        SELECT a.*, COUNT(aq.id) as question_count
        FROM assessments a
        LEFT JOIN assessment_questions aq ON a.id = aq.assessment_id
        GROUP BY a.id
        ORDER BY a.created_at DESC
    ");
    while ($aResult && $row = $aResult->fetch_assoc()) {
        if ($row['question_count'] > 0) {
            $assessments[] = $row;
        }
    }
    
    $stats['total'] = count($assessments);
    
    // Fetch employee's answers (grouped by assessment)
    if ($answersTableExists && count($assessments) > 0) {
        $ansResult = $conn->query("
            SELECT 
                aa.assessment_id,
                COUNT(aa.id) as answered,
                SUM(aa.is_correct) as correct_count,
                MAX(aa.submitted_at) as last_attempt
            FROM assessment_answers aa
            WHERE aa.user_id = {$employee_id}
            GROUP BY aa.assessment_id
        ");
        while ($ansResult && $row = $ansResult->fetch_assoc()) {
            $myResults[$row['assessment_id']] = $row;
        }
        
        // Calculate stats
        $totalScore = 0;
        foreach ($myResults as $r) {
            $stats['completed']++;
            $pct = $r['answered'] > 0 ? ($r['correct_count'] / $r['answered']) * 100 : 0;
            if ($pct >= 70) $stats['passed']++;
            $totalScore += $pct;
        }
        $stats['avg_score'] = $stats['completed'] > 0 ? round($totalScore / $stats['completed'], 1) : 0;
    }
}

// ===== FETCH QUESTIONS FOR EACH ASSESSMENT (for JS) =====
$allQuestions = [];
if ($questionsTableExists) {
    $qResult = $conn->query("SELECT id, assessment_id, question_text, option_a, option_b, option_c, option_d FROM assessment_questions ORDER BY id ASC");
    while ($qResult && $row = $qResult->fetch_assoc()) {
        $allQuestions[$row['assessment_id']][] = $row;
    }
}

// Fetch previous answers for review
$previousAnswers = [];
if ($answersTableExists) {
    $paResult = $conn->query("
        SELECT aa.assessment_id, aa.question_id, aa.selected_option, aa.is_correct, aq.correct_option
        FROM assessment_answers aa
        JOIN assessment_questions aq ON aa.question_id = aq.id
        WHERE aa.user_id = {$employee_id}
    ");
    while ($paResult && $row = $paResult->fetch_assoc()) {
        $previousAnswers[$row['assessment_id']][$row['question_id']] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assessments | Employee Portal</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="Css/nbar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="Css/sbar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="Css/theme.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="Css/module5_sub5.css?v=<?php echo time(); ?>">
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
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="header-text">
                        <h2>My Assessments</h2>
                        <p>Take quizzes and track your knowledge progress</p>
                    </div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-icon"><i class="fas fa-file-alt"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $stats['total']; ?></h3>
                        <p>Available Quizzes</p>
                    </div>
                </div>
                <div class="stat-card active">
                    <div class="stat-icon"><i class="fas fa-check-double"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $stats['completed']; ?></h3>
                        <p>Completed</p>
                    </div>
                </div>
                <div class="stat-card logged">
                    <div class="stat-icon"><i class="fas fa-trophy"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $stats['passed']; ?></h3>
                        <p>Passed</p>
                    </div>
                </div>
                <div class="stat-card never">
                    <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="stat-info">
                        <h3><?php echo $stats['avg_score']; ?>%</h3>
                        <p>Avg Score</p>
                    </div>
                </div>
            </div>

            <?php if (empty($assessments)): ?>
                <!-- Empty State -->
                <div class="empty-state-card">
                    <div class="empty-icon"><i class="fas fa-clipboard-list"></i></div>
                    <h3>No Assessments Available</h3>
                    <p>There are no quizzes assigned yet. Check back later!</p>
                </div>
            <?php else: ?>
                <!-- Assessment Cards -->
                <div class="assessment-grid">
                    <?php foreach ($assessments as $assessment): 
                        $aId = $assessment['id'];
                        $hasResult = isset($myResults[$aId]);
                        $score = 0;
                        $status = 'not-taken';
                        if ($hasResult) {
                            $r = $myResults[$aId];
                            $score = $r['answered'] > 0 ? round(($r['correct_count'] / $r['answered']) * 100, 1) : 0;
                            $status = $score >= 70 ? 'passed' : 'failed';
                        }
                    ?>
                    <div class="assessment-card <?php echo $status; ?>">
                        <div class="assessment-card-header">
                            <div class="assessment-icon">
                                <i class="fas <?php echo $status === 'passed' ? 'fa-check-circle' : ($status === 'failed' ? 'fa-times-circle' : 'fa-clipboard-list'); ?>"></i>
                            </div>
                            <div class="assessment-meta">
                                <span class="question-count"><i class="fas fa-question-circle"></i> <?php echo $assessment['question_count']; ?> Questions</span>
                                <?php if ($hasResult): ?>
                                    <span class="status-badge <?php echo $status; ?>">
                                        <?php echo $status === 'passed' ? 'PASSED' : 'FAILED'; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge not-taken">NEW</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="assessment-card-body">
                            <h3 class="assessment-title"><?php echo htmlspecialchars($assessment['title']); ?></h3>
                            <p class="assessment-desc"><?php echo htmlspecialchars($assessment['description'] ?? 'Complete this assessment to test your knowledge.'); ?></p>
                            
                            <?php if ($hasResult): ?>
                                <div class="score-display">
                                    <div class="score-circle <?php echo $status; ?>">
                                        <svg viewBox="0 0 36 36">
                                            <path class="score-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                                            <path class="score-fill" stroke-dasharray="<?php echo $score; ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                                        </svg>
                                        <span class="score-value"><?php echo $score; ?>%</span>
                                    </div>
                                    <div class="score-details">
                                        <span class="score-correct"><?php echo $myResults[$aId]['correct_count']; ?>/<?php echo $myResults[$aId]['answered']; ?> correct</span>
                                        <span class="score-date"><i class="fas fa-clock"></i> <?php echo date('M d, g:i A', strtotime($myResults[$aId]['last_attempt'])); ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="assessment-card-footer">
                            <?php if ($hasResult): ?>
                                <button class="btn btn-secondary" onclick="reviewAssessment(<?php echo $aId; ?>)">
                                    <i class="fas fa-eye"></i> Review Answers
                                </button>
                                <button class="btn btn-primary" onclick="startAssessment(<?php echo $aId; ?>)">
                                    <i class="fas fa-redo"></i> Retake
                                </button>
                            <?php else: ?>
                                <button class="btn btn-primary btn-lg" onclick="startAssessment(<?php echo $aId; ?>)">
                                    <i class="fas fa-play"></i> Start Quiz
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php ob_start(); ?>
<!-- Hidden: will be shown outside main-content -->
<?php $modalContent = ob_get_clean(); ?>

<!-- Quiz Modal (outside main-content) -->
<div class="modal" id="quizModal">
    <div class="modal-content quiz-modal">
        <div class="modal-header">
            <h3><i class="fas fa-clipboard-check"></i> <span id="quizTitle">Assessment</span></h3>
            <div class="quiz-progress-bar">
                <div class="quiz-progress-fill" id="quizProgressFill"></div>
            </div>
            <span class="quiz-progress-text" id="quizProgressText">Question 1 of 10</span>
            <button class="modal-close" onclick="closeQuiz()">&times;</button>
        </div>
        <div class="modal-body" id="quizBody">
            <!-- Filled by JS -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="prevBtn" onclick="prevQuestion()" style="display:none;">
                <i class="fas fa-arrow-left"></i> Previous
            </button>
            <div class="quiz-timer" id="quizTimer">
                <i class="fas fa-stopwatch"></i> <span id="timerText">00:00</span>
            </div>
            <button class="btn btn-primary" id="nextBtn" onclick="nextQuestion()">
                Next <i class="fas fa-arrow-right"></i>
            </button>
            <button class="btn btn-success" id="submitBtn" onclick="submitQuiz()" style="display:none;">
                <i class="fas fa-paper-plane"></i> Submit Quiz
            </button>
        </div>
    </div>
</div>

<!-- Results Modal -->
<div class="modal" id="resultsModal">
    <div class="modal-content results-modal">
        <div class="modal-header">
            <h3><i class="fas fa-poll"></i> Quiz Results</h3>
            <button class="modal-close" onclick="closeModal('resultsModal')">&times;</button>
        </div>
        <div class="modal-body" id="resultsBody">
            <!-- Filled by JS -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('resultsModal')">Close</button>
            <button class="btn btn-primary" onclick="location.reload()"><i class="fas fa-refresh"></i> Refresh</button>
        </div>
    </div>
</div>

<!-- Review Modal -->
<div class="modal" id="reviewModal">
    <div class="modal-content review-modal">
        <div class="modal-header">
            <h3><i class="fas fa-search"></i> <span id="reviewTitle">Review Answers</span></h3>
            <button class="modal-close" onclick="closeModal('reviewModal')">&times;</button>
        </div>
        <div class="modal-body" id="reviewBody">
            <!-- Filled by JS -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('reviewModal')">Close</button>
        </div>
    </div>
</div>

<script>
// ===== DATA =====
const assessmentQuestions = <?php echo json_encode($allQuestions); ?>;
const assessmentsList = <?php echo json_encode($assessments); ?>;
const previousAnswers = <?php echo json_encode($previousAnswers); ?>;

let currentAssessmentId = null;
let currentQuestions = [];
let currentQuestionIdx = 0;
let userAnswers = {};
let quizStartTime = null;
let timerInterval = null;

// ===== START QUIZ =====
function startAssessment(assessmentId) {
    currentAssessmentId = assessmentId;
    currentQuestions = assessmentQuestions[assessmentId] || [];
    currentQuestionIdx = 0;
    userAnswers = {};
    
    if (currentQuestions.length === 0) {
        alert('No questions found for this assessment.');
        return;
    }
    
    // Set title
    const assessment = assessmentsList.find(a => a.id == assessmentId);
    document.getElementById('quizTitle').textContent = assessment ? assessment.title : 'Assessment';
    
    // Start timer
    quizStartTime = Date.now();
    if (timerInterval) clearInterval(timerInterval);
    timerInterval = setInterval(updateTimer, 1000);
    
    // Show quiz modal
    document.getElementById('quizModal').classList.add('active');
    renderQuestion();
}

// ===== RENDER QUESTION =====
function renderQuestion() {
    const q = currentQuestions[currentQuestionIdx];
    const total = currentQuestions.length;
    const selected = userAnswers[q.id] || '';
    
    // Update progress
    const pct = ((currentQuestionIdx + 1) / total) * 100;
    document.getElementById('quizProgressFill').style.width = pct + '%';
    document.getElementById('quizProgressText').textContent = `Question ${currentQuestionIdx + 1} of ${total}`;
    
    // Render question
    document.getElementById('quizBody').innerHTML = `
        <div class="quiz-question fade-in">
            <div class="question-number">Question ${currentQuestionIdx + 1}</div>
            <h3 class="question-text">${escapeHtml(q.question_text)}</h3>
            <div class="options-list">
                ${['A', 'B', 'C', 'D'].map(opt => {
                    const optKey = 'option_' + opt.toLowerCase();
                    const optText = q[optKey];
                    if (!optText) return '';
                    const isSelected = selected === opt;
                    return `
                        <label class="option-item ${isSelected ? 'selected' : ''}" onclick="selectOption('${opt}', this)">
                            <div class="option-letter">${opt}</div>
                            <div class="option-text">${escapeHtml(optText)}</div>
                            <div class="option-check"><i class="fas fa-check-circle"></i></div>
                        </label>
                    `;
                }).join('')}
            </div>
        </div>
    `;
    
    // Show/hide nav buttons
    document.getElementById('prevBtn').style.display = currentQuestionIdx > 0 ? '' : 'none';
    
    if (currentQuestionIdx === total - 1) {
        document.getElementById('nextBtn').style.display = 'none';
        document.getElementById('submitBtn').style.display = '';
    } else {
        document.getElementById('nextBtn').style.display = '';
        document.getElementById('submitBtn').style.display = 'none';
    }
}

// ===== SELECT OPTION =====
function selectOption(option, element) {
    const q = currentQuestions[currentQuestionIdx];
    userAnswers[q.id] = option;
    
    // Update visual
    document.querySelectorAll('.option-item').forEach(el => el.classList.remove('selected'));
    element.classList.add('selected');
}

// ===== NAVIGATION =====
function nextQuestion() {
    const q = currentQuestions[currentQuestionIdx];
    if (!userAnswers[q.id]) {
        // Shake animation
        document.querySelector('.quiz-question').classList.add('shake');
        setTimeout(() => document.querySelector('.quiz-question').classList.remove('shake'), 500);
        return;
    }
    if (currentQuestionIdx < currentQuestions.length - 1) {
        currentQuestionIdx++;
        renderQuestion();
    }
}

function prevQuestion() {
    if (currentQuestionIdx > 0) {
        currentQuestionIdx--;
        renderQuestion();
    }
}

// ===== SUBMIT QUIZ =====
function submitQuiz() {
    // Check all answered
    const unanswered = currentQuestions.filter(q => !userAnswers[q.id]);
    if (unanswered.length > 0) {
        if (!confirm(`You have ${unanswered.length} unanswered question(s). Submit anyway?`)) return;
    }
    
    if (!confirm('Are you sure you want to submit your answers?')) return;
    
    // Stop timer
    if (timerInterval) clearInterval(timerInterval);
    
    // Send to server
    const formData = new FormData();
    formData.append('submit_assessment', '1');
    formData.append('assessment_id', currentAssessmentId);
    for (const [qid, ans] of Object.entries(userAnswers)) {
        formData.append(`answers[${qid}]`, ans);
    }
    
    // Show loading
    document.getElementById('quizBody').innerHTML = `
        <div class="quiz-loading">
            <div class="spinner"></div>
            <p>Submitting your answers...</p>
        </div>
    `;
    document.getElementById('submitBtn').style.display = 'none';
    document.getElementById('prevBtn').style.display = 'none';
    
    fetch(location.href, { method: 'POST', body: formData })
    .then(r => r.text())
    .then(text => {
        let data;
        try {
            const jsonStart = text.indexOf('{');
            data = JSON.parse(jsonStart > 0 ? text.substring(jsonStart) : text);
        } catch(e) {
            throw new Error('Invalid response');
        }
        
        // Close quiz modal
        document.getElementById('quizModal').classList.remove('active');
        
        if (data.success) {
            showResults(data);
        } else {
            alert('Error: ' + (data.error || 'Submission failed'));
        }
    })
    .catch(err => {
        alert('Error submitting: ' + err.message);
        document.getElementById('quizModal').classList.remove('active');
    });
}

// ===== SHOW RESULTS =====
function showResults(data) {
    const elapsed = quizStartTime ? Math.floor((Date.now() - quizStartTime) / 1000) : 0;
    const mins = Math.floor(elapsed / 60);
    const secs = elapsed % 60;
    
    const isPassed = data.passed;
    
    document.getElementById('resultsBody').innerHTML = `
        <div class="results-card ${isPassed ? 'passed' : 'failed'}">
            <div class="results-icon">
                <i class="fas ${isPassed ? 'fa-trophy' : 'fa-exclamation-triangle'}"></i>
            </div>
            <h2 class="results-title">${isPassed ? 'Congratulations!' : 'Keep Trying!'}</h2>
            <p class="results-subtitle">${isPassed ? 'You passed the assessment!' : 'You need 70% to pass. Try again!'}</p>
            
            <div class="results-score-ring">
                <svg viewBox="0 0 36 36">
                    <path class="score-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                    <path class="score-fill ${isPassed ? 'pass' : 'fail'}" stroke-dasharray="${data.score}, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
                </svg>
                <span class="results-score-num">${data.score}%</span>
            </div>
            
            <div class="results-stats">
                <div class="results-stat">
                    <div class="results-stat-num">${data.correct}</div>
                    <div class="results-stat-label">Correct</div>
                </div>
                <div class="results-stat">
                    <div class="results-stat-num">${data.total - data.correct}</div>
                    <div class="results-stat-label">Wrong</div>
                </div>
                <div class="results-stat">
                    <div class="results-stat-num">${data.total}</div>
                    <div class="results-stat-label">Total</div>
                </div>
                <div class="results-stat">
                    <div class="results-stat-num">${mins}:${secs.toString().padStart(2, '0')}</div>
                    <div class="results-stat-label">Time</div>
                </div>
            </div>
            
            <div class="results-rating">
                <span class="rating-badge ${isPassed ? 'pass' : 'fail'}">${data.rating}</span>
            </div>
        </div>
    `;
    
    document.getElementById('resultsModal').classList.add('active');
}

// ===== REVIEW ANSWERS =====
function reviewAssessment(assessmentId) {
    const questions = assessmentQuestions[assessmentId] || [];
    const answers = previousAnswers[assessmentId] || {};
    const assessment = assessmentsList.find(a => a.id == assessmentId);
    
    if (questions.length === 0) {
        alert('No questions found.');
        return;
    }
    
    document.getElementById('reviewTitle').textContent = 'Review: ' + (assessment ? assessment.title : 'Assessment');
    
    let html = '<div class="review-list">';
    let correctCount = 0;
    
    questions.forEach((q, idx) => {
        const answer = answers[q.id] || {};
        const selectedOpt = answer.selected_option || '—';
        const correctOpt = answer.correct_option || '?';
        const isCorrect = answer.is_correct == 1;
        if (isCorrect) correctCount++;
        
        html += `
            <div class="review-item ${isCorrect ? 'correct' : 'wrong'}">
                <div class="review-question-header">
                    <span class="review-num">Q${idx + 1}</span>
                    <span class="review-status ${isCorrect ? 'correct' : 'wrong'}">
                        <i class="fas ${isCorrect ? 'fa-check-circle' : 'fa-times-circle'}"></i>
                        ${isCorrect ? 'Correct' : 'Wrong'}
                    </span>
                </div>
                <p class="review-question-text">${escapeHtml(q.question_text)}</p>
                <div class="review-options">
                    ${['A', 'B', 'C', 'D'].map(opt => {
                        const optKey = 'option_' + opt.toLowerCase();
                        const optText = q[optKey];
                        if (!optText) return '';
                        const isSelected = selectedOpt === opt;
                        const isAnswer = correctOpt === opt;
                        let cls = '';
                        if (isAnswer) cls = 'correct-answer';
                        if (isSelected && !isCorrect) cls += ' wrong-answer';
                        if (isSelected && isCorrect) cls = 'correct-answer selected';
                        return `
                            <div class="review-option ${cls}">
                                <span class="opt-letter">${opt}</span>
                                <span class="opt-text">${escapeHtml(optText)}</span>
                                ${isAnswer ? '<i class="fas fa-check"></i>' : ''}
                                ${isSelected && !isCorrect ? '<i class="fas fa-times"></i>' : ''}
                            </div>
                        `;
                    }).join('')}
                </div>
            </div>
        `;
    });
    
    const score = questions.length > 0 ? Math.round((correctCount / questions.length) * 100) : 0;
    html = `<div class="review-summary">
        <span><strong>${correctCount}</strong> of <strong>${questions.length}</strong> correct</span>
        <span class="review-score ${score >= 70 ? 'pass' : 'fail'}">${score}%</span>
    </div>` + html;
    
    html += '</div>';
    document.getElementById('reviewBody').innerHTML = html;
    document.getElementById('reviewModal').classList.add('active');
}

// ===== TIMER =====
function updateTimer() {
    if (!quizStartTime) return;
    const elapsed = Math.floor((Date.now() - quizStartTime) / 1000);
    const mins = Math.floor(elapsed / 60);
    const secs = elapsed % 60;
    document.getElementById('timerText').textContent = `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
}

// ===== HELPERS =====
function closeQuiz() {
    if (Object.keys(userAnswers).length > 0) {
        if (!confirm('Are you sure you want to leave? Your progress will be lost.')) return;
    }
    document.getElementById('quizModal').classList.remove('active');
    if (timerInterval) clearInterval(timerInterval);
    currentAssessmentId = null;
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Close modals on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(m => {
            if (m.id === 'quizModal' && Object.keys(userAnswers).length > 0) return;
            m.classList.remove('active');
        });
    }
});

// Close on backdrop
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(modal.id); });
});
</script>

<?php include 'partials/ai_chat.php'; ?>
</body>
</html>

<?php
/**
 * MODULE 5 SUB 4 - MY TRAINING
 * HR2 MerchFlow - Employee Self-Service Portal
 * View training schedules and attendance records
 */
require_once 'Connection/session_handler.php';
require_once 'Connection/Config.php';

$employee_id = (int)$_SESSION['user_id'];
$from_hr1 = isset($_SESSION['from_hr1']) && $_SESSION['from_hr1'] === true;
$hr1_employee_id = $_SESSION['hr1_employee_id'] ?? null;
$lookup_id = $hr1_employee_id ?? $employee_id;

// Check if tables exist
$schedulesTableExists = $conn->query("SHOW TABLES LIKE 'training_schedules'")->num_rows > 0;
$attendanceTableExists = $conn->query("SHOW TABLES LIKE 'training_attendance'")->num_rows > 0;

$upcoming_trainings = [];
$past_trainings = [];
$total_trainings = 0;
$attended_count = 0;
$attendance_rate = 0;

if ($schedulesTableExists && $attendanceTableExists) {
    // Check what columns exist in training_attendance
    $ta_columns = [];
    $ta_cols_result = $conn->query("SHOW COLUMNS FROM training_attendance");
    while ($col = $ta_cols_result->fetch_assoc()) {
        $ta_columns[] = $col['Field'];
    }
    $has_remarks = in_array('remarks', $ta_columns);
    
    // Check what columns exist in training_schedules
    $ts_columns = [];
    $ts_cols_result = $conn->query("SHOW COLUMNS FROM training_schedules");
    while ($col = $ts_cols_result->fetch_assoc()) {
        $ts_columns[] = $col['Field'];
    }
    $has_start_time = in_array('start_time', $ts_columns);
    $has_date = in_array('date', $ts_columns);
    $has_training_date = in_array('training_date', $ts_columns);
    
    // Determine date column name
    $date_col = $has_date ? 'date' : ($has_training_date ? 'training_date' : 'created_at');
    
    // Build select clause dynamically
    $ta_select = "ta.attended";
    if ($has_remarks) $ta_select .= ", ta.remarks";
    
    // Build ORDER BY clause
    $order_by_upcoming = "ts.$date_col ASC";
    $order_by_past = "ts.$date_col DESC";
    if ($has_start_time) {
        $order_by_upcoming .= ", ts.start_time ASC";
    }
    
    // Fetch upcoming trainings
    $upcoming_query = "
        SELECT 
            ts.*,
            $ta_select
        FROM training_schedules ts
        LEFT JOIN training_attendance ta ON ts.id = ta.schedule_id AND ta.user_id = ?
        WHERE ts.$date_col >= CURDATE()
        ORDER BY $order_by_upcoming
        LIMIT 10
    ";
    $stmt = $conn->prepare($upcoming_query);
    $stmt->bind_param("i", $lookup_id);
    $stmt->execute();
    $upcoming_trainings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Fetch past trainings
    $past_query = "
        SELECT 
            ts.*,
            $ta_select
        FROM training_schedules ts
        LEFT JOIN training_attendance ta ON ts.id = ta.schedule_id AND ta.user_id = ?
        WHERE ts.$date_col < CURDATE()
        ORDER BY $order_by_past
        LIMIT 20
    ";
    $stmt = $conn->prepare($past_query);
    $stmt->bind_param("i", $lookup_id);
    $stmt->execute();
    $past_trainings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Calculate stats
    $total_trainings = count($past_trainings);
    foreach ($past_trainings as $t) {
        if ($t['attended'] === 'Yes') $attended_count++;
    }
    $attendance_rate = $total_trainings > 0 ? round(($attended_count / $total_trainings) * 100) : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>My Training | Employee Portal</title>
    <link rel="icon" type="image/png" href="osicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="Css/module5_sub4.css?v=<?= time(); ?>">
    <link rel="stylesheet" href="Css/ai_chat_bubble.css?v=<?= time(); ?>">
</head>
<body>
<?php include 'partials/sidebar.php'; ?>
<div class="main-content">
    <?php include 'partials/nav.php'; ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h2><i class="fas fa-dumbbell"></i> My Training</h2>
            <div class="subtitle">View your training schedule and attendance</div>
        </div>
    </div>
    
    <!-- Stats Row -->
    <div class="stats-row">
        <div class="stat-card fade-in">
            <div class="icon blue"><i class="fas fa-calendar-alt"></i></div>
            <div>
                <div class="value"><?= count($upcoming_trainings) ?></div>
                <div class="label">Upcoming</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon green"><i class="fas fa-check-circle"></i></div>
            <div>
                <div class="value"><?= $attended_count ?></div>
                <div class="label">Attended</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon yellow"><i class="fas fa-history"></i></div>
            <div>
                <div class="value"><?= $total_trainings ?></div>
                <div class="label">Total Trainings</div>
            </div>
        </div>
        <div class="stat-card fade-in">
            <div class="icon purple"><i class="fas fa-percentage"></i></div>
            <div>
                <div class="value"><?= $attendance_rate ?>%</div>
                <div class="label">Attendance Rate</div>
            </div>
        </div>
    </div>
    
    <div class="content-container">
        <div class="grid-2" style="gap: 2rem;">
            <!-- Upcoming Trainings -->
            <div class="section-card fade-in">
                <div class="section-header">
                    <h3><i class="fas fa-calendar-check"></i> Upcoming Trainings</h3>
                </div>
                <div class="section-body">
                    <?php if (count($upcoming_trainings) > 0): ?>
                        <?php foreach ($upcoming_trainings as $training): 
                            $t_date = $training['date'] ?? $training['training_date'] ?? $training['created_at'] ?? date('Y-m-d');
                        ?>
                        <div class="training-card">
                            <div class="training-date-box">
                                <div class="day"><?= date('d', strtotime($t_date)) ?></div>
                                <div class="month"><?= date('M', strtotime($t_date)) ?></div>
                            </div>
                            <div class="training-info">
                                <div class="training-title"><?= htmlspecialchars($training['title'] ?? $training['training_title'] ?? 'Training') ?></div>
                                <div class="training-meta">
                                    <?php if (!empty($training['start_time'])): ?>
                                    <span><i class="fas fa-clock"></i> <?= date('h:i A', strtotime($training['start_time'])) ?><?= !empty($training['end_time']) ? ' - ' . date('h:i A', strtotime($training['end_time'])) : '' ?></span>
                                    <?php endif; ?>
                                    <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($training['location'] ?? $training['venue'] ?? 'TBA') ?></span>
                                </div>
                            </div>
                            <div class="training-status">
                                <span class="badge badge-info"><i class="fas fa-clock"></i> Upcoming</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state" style="padding: 2rem;">
                            <i class="fas fa-calendar-times" style="font-size: 3rem;"></i>
                            <h4>No Upcoming Trainings</h4>
                            <p>You have no scheduled trainings at the moment.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Calendar View -->
            <div class="section-card fade-in">
                <div class="section-header">
                    <h3><i class="fas fa-calendar"></i> Training Calendar</h3>
                    <span id="currentMonth" style="font-weight: 600; color: var(--text-primary);"></span>
                </div>
                <div class="section-body">
                    <div class="calendar-view">
                        <div class="calendar-header">Sun</div>
                        <div class="calendar-header">Mon</div>
                        <div class="calendar-header">Tue</div>
                        <div class="calendar-header">Wed</div>
                        <div class="calendar-header">Thu</div>
                        <div class="calendar-header">Fri</div>
                        <div class="calendar-header">Sat</div>
                        <div id="calendarDays"></div>
                    </div>
                    <div style="margin-top: 1rem; display: flex; gap: 1rem; font-size: 0.8125rem; color: var(--text-secondary);">
                        <span><span style="display: inline-block; width: 12px; height: 12px; background: var(--primary-red-light); border: 1px solid var(--primary-red); border-radius: 3px;"></span> Today</span>
                        <span><span style="display: inline-block; width: 12px; height: 12px; background: var(--info-blue-light); border: 1px solid var(--info-blue); border-radius: 3px;"></span> Training Day</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Training History -->
        <div class="section-card fade-in" style="margin-top: 2rem;">
            <div class="section-header">
                <h3><i class="fas fa-history"></i> Training History</h3>
            </div>
            <div class="section-body" style="padding: 0;">
                <?php if (count($past_trainings) > 0): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Training</th>
                            <th>Time</th>
                            <th>Location</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($past_trainings as $training): 
                            $t_date = $training['date'] ?? $training['training_date'] ?? $training['created_at'] ?? date('Y-m-d');
                        ?>
                        <tr>
                            <td><?= date('M d, Y', strtotime($t_date)) ?></td>
                            <td><strong><?= htmlspecialchars($training['title'] ?? $training['training_title'] ?? 'Training') ?></strong></td>
                            <td><?= !empty($training['start_time']) ? date('h:i A', strtotime($training['start_time'])) : 'N/A' ?></td>
                            <td><?= htmlspecialchars($training['location'] ?? $training['venue'] ?? 'N/A') ?></td>
                            <td>
                                <?php if ($training['attended'] === 'Yes'): ?>
                                    <span class="status-badge approved"><i class="fas fa-check"></i> Attended</span>
                                <?php elseif ($training['attended'] === 'No'): ?>
                                    <span class="status-badge rejected"><i class="fas fa-times"></i> Absent</span>
                                <?php else: ?>
                                    <span class="status-badge pending"><i class="fas fa-question"></i> Pending</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-history"></i>
                    <h4>No Training History</h4>
                    <p>You haven't attended any trainings yet.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Training dates for calendar
const trainingDates = [
    <?php foreach ($upcoming_trainings as $t): 
        $t_date = $t['date'] ?? $t['training_date'] ?? $t['created_at'] ?? '';
    ?>
    '<?= $t_date ?>',
    <?php endforeach; ?>
];

// Generate calendar
function generateCalendar() {
    const now = new Date();
    const year = now.getFullYear();
    const month = now.getMonth();
    
    document.getElementById('currentMonth').textContent = now.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const today = now.getDate();
    
    let html = '';
    
    // Empty cells before first day
    for (let i = 0; i < firstDay; i++) {
        html += '<div class="calendar-day empty"></div>';
    }
    
    // Days of month
    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const isToday = day === today;
        const hasTraining = trainingDates.includes(dateStr);
        
        let classes = 'calendar-day';
        if (isToday) classes += ' today';
        if (hasTraining) classes += ' has-training';
        
        html += `<div class="${classes}">${day}</div>`;
    }
    
    document.getElementById('calendarDays').outerHTML = html;
}

generateCalendar();
</script>
<?php include 'partials/ai_chat.php'; ?>
</body>
</html>

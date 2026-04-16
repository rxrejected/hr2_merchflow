<?php
session_start();
header("Content-Type: application/json");

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status'=>'error', 'message'=>'Unauthorized']);
    exit();
}

// Update last activity on AJAX requests to keep session alive
$_SESSION['LAST_ACTIVITY'] = time();

require 'Connection/Config.php';

// --- Inputs from AJAX
$page = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$limit = 10; // items per page
$offset = ($page-1) * $limit;

$search = $_GET['search'] ?? '';
$sortColumn = $_GET['sortColumn'] ?? 'final_score';
$sortOrder = ($_GET['sortOrder'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

// valid columns for sorting
$validSorts = ['full_name','job_position','assessment_score','quiz_score','course_score','final_score'];
if(!in_array($sortColumn,$validSorts)) $sortColumn = 'final_score';

// --- Helper
function assessmentToScore($val) {
    return match($val) {
        'Excellent'=>100,'Good'=>80,'Average'=>60,'Poor'=>40,default=>0,
    };
}

// --- Fetch employees with search filter
$employees = [];
$totalEmployees = 0;

$whereSearch = '';
$params = [];
$types = '';

if ($search !== '') {
    $whereSearch = " AND (u.full_name LIKE ? OR u.job_position LIKE ?) ";
    $params = ["%$search%","%$search%"];
    $types = "ss";
}

// get total count for pagination
$countSql = "SELECT COUNT(*) as cnt FROM users u WHERE u.role='employee' $whereSearch";
$stmtCount = $conn->prepare($countSql);
if($whereSearch!=='') $stmtCount->bind_param($types, ...$params);
$stmtCount->execute();
$resCount = $stmtCount->get_result();
$totalEmployees = $resCount->fetch_assoc()['cnt'] ?? 0;
$stmtCount->close();

// fetch employees for current page
$sql = "SELECT u.id, u.full_name, u.job_position, u.avatar, u.email, u.phone
        FROM users u
        WHERE u.role='employee' $whereSearch
        ORDER BY $sortColumn $sortOrder
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);

if($whereSearch!=='') {
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;
    $stmt->bind_param($types,...$params);
} else {
    $stmt->bind_param("ii",$limit,$offset);
}

$stmt->execute();
$result = $stmt->get_result();

// --- prepare statement for evaluations (instead of assessment), quiz, course
$evalStmt = $conn->prepare("SELECT customer_service, cash_handling, inventory, teamwork, attendance FROM evaluations WHERE employee_id=? ORDER BY created_at DESC LIMIT 1");
$courseStmt = $conn->prepare("SELECT AVG(watched_percent) as avg_course FROM course_progress WHERE employee_id=?");

while($emp = $result->fetch_assoc()){
    $employee_id = (int)$emp['id'];
    
    // evaluation (from evaluations table instead of assessment)
    $assessmentScore = 0; $comments=[];
    $evalStmt->bind_param("i",$employee_id);
    $evalStmt->execute();
    $aRes = $evalStmt->get_result();
    if($aRes && $aRes->num_rows>0){
        $total=0;$count=0;
        while($a=$aRes->fetch_assoc()){
            $metrics=['customer_service','cash_handling','inventory','teamwork','attendance'];
            foreach($metrics as $metric){
                $val=$a[$metric]??'';
                $score=assessmentToScore($val);
                $total+=$score; $count++;
            }
            $comments[] = "Customer: ".($a['customer_service']??'N/A').", Cash: ".($a['cash_handling']??'N/A').", Inventory: ".($a['inventory']??'N/A');
        }
        $assessmentScore = $count ? ($total/$count):0;
    }

    // quiz score - no longer using assessment_answers table
    $quizScore = 0;

    // course
    $courseScore=0;
    $courseStmt->bind_param("i",$employee_id);
    $courseStmt->execute();
    $cRes=$courseStmt->get_result();
    if($cRes && $cRes->num_rows>0){
        $c=$cRes->fetch_assoc();
        $courseScore = $c['avg_course']!==null?(float)$c['avg_course']:0;
    }

    $finalScore = ($assessmentScore*0.5)+($courseScore*0.5);

    $emp['assessment_score']=round($assessmentScore,2);
    $emp['quiz_score']=round($quizScore,2);
    $emp['course_score']=round($courseScore,2);
    $emp['final_score']=round($finalScore,2);
    $emp['comments']=$comments;
    $emp['avatar']=$emp['avatar']??'';
    $emp['email']=$emp['email']??'';
    $emp['phone']=$emp['phone']??'';

    $employees[]=$emp;
}

// close statements
$stmt->close(); $evalStmt->close(); $courseStmt->close();

// send JSON
echo json_encode([
    'status'=>'success',
    'employees'=>$employees,
    'total'=>$totalEmployees,
    'page'=>$page,
    'pages'=>ceil($totalEmployees/$limit)
]);

<?php
ini_set('display_errors', 0);
ob_start();
session_start();
ob_clean();

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

function respond($payload){
    echo json_encode($payload);
    exit;
}

register_shutdown_function(function(){
    $error = error_get_last();
    if($error && in_array($error['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)){
        if(!headers_sent()){
            header('Content-Type: application/json');
            http_response_code(500);
        }
        echo json_encode([
            'success' => false,
            'message' => 'Schedule API failed: '.$error['message']
        ]);
    }
});

function normalizeExamType($value){
    $v = strtolower(trim((string)$value));
    if($v === '') return '';
    if(strlen($v) > 50) return '';
    if(!preg_match('/^[a-z0-9_\-\s]+$/', $v)) return '';
    return $v;
}

function normalizeShift($value, $examType){
    $v = trim((string)$value);
    $allowed = allowedShifts($examType);
    return in_array($v, $allowed, true) ? $v : '';
}

function allowedShifts($examType){
    if($examType === 'end'){
        return ['Morning', 'Afternoon'];
    }
    if($examType === 'midsem'){
        return ['Morning', 'Afternoon', 'Evening'];
    }
    return ['Morning', 'Afternoon', 'Evening'];
}

function toTimeOrEmpty($value){
    $v = trim((string)$value);
    if($v === '') return '';
    if(preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $v)){
        return strlen($v) === 5 ? $v.':00' : $v;
    }
    $ts = strtotime($v);
    if($ts === false) return '';
    return date('H:i:s', $ts);
}

function normalizeLegacySession($value){
    $v = trim((string)$value);
    return in_array($v, ['Morning', 'Afternoon'], true) ? $v : '';
}

function loadLegacyScheduleRows($con){
    $rows = [];
    $q = $con->query(
        "SELECT e.exam_id, e.exam_name, e.date, e.session,
                es.schedule_id, es.subject_name, es.exam_date,
                es.start_time, es.end_time, es.duration
         FROM exams e
         INNER JOIN exam_schedule es ON es.exam_id = e.exam_id
         ORDER BY es.exam_date DESC, es.start_time ASC, e.exam_id DESC"
    );

    if($q === false){
        respond(['success' => false, 'message' => 'Failed to load schedule: '.$con->error]);
    }

    while($r = $q->fetch_assoc()){
        $rows[] = $r;
    }

    return $rows;
}

if($method === 'GET' && $act === 'list'){
    respond(['success' => true, 'data' => loadLegacyScheduleRows($con)]);
}

if($method === 'POST' && in_array($act, ['add', 'delete'], true)){
    if(!in_array($_SESSION['role'] ?? '', ['admin', 'exam_cell'], true)){
        respond(['success' => false, 'message' => 'Not authorized']);
    }

    if($act === 'delete'){
        $examId = (int)($body['exam_id'] ?? 0);
        if($examId <= 0){
            respond(['success' => false, 'message' => 'exam_id is required']);
        }

        $stmt = $con->prepare('DELETE FROM exams WHERE exam_id=?');
        $stmt->bind_param('i', $examId);
        $ok = $stmt->execute();
        $stmt->close();

        respond($ok ? ['success' => true, 'message' => 'Exam deleted'] : ['success' => false, 'message' => 'Failed to delete exam']);
    }

    $examName = trim((string)($body['exam_name'] ?? ''));
    $subjectName = trim((string)($body['subject_name'] ?? ''));
    $examDate = trim((string)($body['exam_date'] ?? ''));
    $session = normalizeLegacySession($body['session'] ?? '');
    $startTime = toTimeOrEmpty($body['start_time'] ?? '');
    $endTime = toTimeOrEmpty($body['end_time'] ?? '');

    if($examName === '' || $subjectName === '' || $examDate === '' || $session === '' || $startTime === '' || $endTime === ''){
        respond(['success' => false, 'message' => 'Please fill all required fields']);
    }

    $duration = (int)((strtotime($endTime) - strtotime($startTime)) / 60);
    if($duration <= 0){
        respond(['success' => false, 'message' => 'End time must be later than start time']);
    }

    $con->begin_transaction();
    try {
        $examId = 0;
        $find = $con->prepare('SELECT exam_id FROM exams WHERE exam_name=? AND date=? AND session=? LIMIT 1');
        $find->bind_param('sss', $examName, $examDate, $session);
        $find->execute();
        $findRes = $find->get_result();
        if($row = $findRes->fetch_assoc()){
            $examId = (int)$row['exam_id'];
        }
        $find->close();

        if($examId > 0){
            $upExam = $con->prepare('UPDATE exams SET exam_name=?, date=?, session=? WHERE exam_id=?');
            $upExam->bind_param('sssi', $examName, $examDate, $session, $examId);
            if(!$upExam->execute()){
                throw new Exception($upExam->error);
            }
            $upExam->close();
        } else {
            $insExam = $con->prepare('INSERT INTO exams (exam_name, date, session) VALUES (?, ?, ?)');
            $insExam->bind_param('sss', $examName, $examDate, $session);
            if(!$insExam->execute()){
                throw new Exception($insExam->error);
            }
            $examId = (int)$insExam->insert_id;
            $insExam->close();
        }

        $scheduleId = 0;
        $findSchedule = $con->prepare('SELECT schedule_id FROM exam_schedule WHERE exam_id=? LIMIT 1');
        $findSchedule->bind_param('i', $examId);
        $findSchedule->execute();
        $scheduleRes = $findSchedule->get_result();
        if($row = $scheduleRes->fetch_assoc()){
            $scheduleId = (int)$row['schedule_id'];
        }
        $findSchedule->close();

        if($scheduleId > 0){
            $upSchedule = $con->prepare(
                'UPDATE exam_schedule SET subject_name=?, exam_date=?, start_time=?, end_time=?, session=?, duration=? WHERE schedule_id=?'
            );
            $upSchedule->bind_param('sssssii', $subjectName, $examDate, $startTime, $endTime, $session, $duration, $scheduleId);
            if(!$upSchedule->execute()){
                throw new Exception($upSchedule->error);
            }
            $upSchedule->close();
        } else {
            $insSchedule = $con->prepare(
                'INSERT INTO exam_schedule (exam_id, subject_name, exam_date, start_time, end_time, session, duration) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $insSchedule->bind_param('isssssi', $examId, $subjectName, $examDate, $startTime, $endTime, $session, $duration);
            if(!$insSchedule->execute()){
                throw new Exception($insSchedule->error);
            }
            $insSchedule->close();
        }

        $con->commit();
        respond(['success' => true, 'message' => 'Exam scheduled successfully']);
    } catch (Throwable $e) {
        $con->rollback();
        respond(['success' => false, 'message' => 'Failed to save exam: '.$e->getMessage()]);
    }
}

function parseBranchSemester($value){
    $raw = trim((string)$value);
    if($raw === '') return ['', 0];

    if(preg_match('/^(.+?)[\s\-\/]*(?:sem|semester)?[\s\-]*(\d{1,2})$/i', $raw, $m)){
        $branch = strtoupper(trim($m[1]));
        $sem = (int)$m[2];
        return [$branch, $sem];
    }

    return [strtoupper($raw), 0];
}

function normalizeSchool($value, $schoolDeptMap){
    $v = strtoupper(trim((string)$value));
    if(isset($schoolDeptMap[$v])){
        return $v;
    }
    if($v === '' || strlen($v) > 30) return '';
    if(!preg_match('/^[A-Z0-9&\-\s]+$/', $v)) return '';
    return $v;
}

function normalizeDept($value){
    $v = trim((string)$value);
    return $v;
}

function buildDepartmentKey($school, $dept){
    return $school.'::'.$dept;
}

function validateBranchDateSchedule($con, $examType, $school, $dept, $branchCode, $semester, $branchSession, $examDate, $shift, $startTime, $endTime){
    $stmt = $con->prepare(
        "SELECT shift, start_time, end_time
         FROM exam_schedule_matrix
         WHERE exam_type=? AND school=? AND dept=? AND branch_code=? AND semester=? AND branch_session=? AND exam_date=?"
    );
    $stmt->bind_param('ssssiss', $examType, $school, $dept, $branchCode, $semester, $branchSession, $examDate);
    $stmt->execute();
    $res = $stmt->get_result();

    $conflicts = [];
    while($row = $res->fetch_assoc()){
        $existingShift = (string)($row['shift'] ?? '');
        $existingStart = (string)($row['start_time'] ?? '');
        $existingEnd = (string)($row['end_time'] ?? '');

        // Same slot is an update and is allowed.
        if($existingShift === $shift){
            continue;
        }

        $conflicts[] = [
            'shift' => $existingShift,
            'start_time' => $existingStart,
            'end_time' => $existingEnd
        ];
    }
    $stmt->close();

    if(count($conflicts) === 0){
        return ['ok' => true, 'message' => ''];
    }

    foreach($conflicts as $conflict){
        $existingStart = $conflict['start_time'];
        $existingEnd = $conflict['end_time'];
        if($startTime !== '' && $endTime !== '' && $existingStart !== '' && $existingEnd !== ''){
            $overlap = ($startTime < $existingEnd) && ($endTime > $existingStart);
            if($overlap){
                return [
                    'ok' => false,
                    'message' => 'For the same branch and year, two exams cannot be scheduled at the same time on the same date.'
                ];
            }
        }
    }

    return [
        'ok' => false,
        'message' => 'For the same branch and year, an exam is already scheduled on this date. Duplicate slots are not allowed.'
    ];
}

function fillDateRange($dates){
    if(count($dates) === 0) return [];

    $start = new DateTime($dates[0]);
    $end = new DateTime($dates[count($dates) - 1]);
    $endInclusive = clone $end;
    $endInclusive->modify('+1 day');

    $all = [];
    $period = new DatePeriod($start, new DateInterval('P1D'), $endInclusive);
    foreach($period as $d){
        $all[] = $d->format('Y-m-d');
    }
    return $all;
}

function ensureScheduleMatrixTable($con){
    $create = "CREATE TABLE IF NOT EXISTS exam_schedule_matrix (
        matrix_id INT AUTO_INCREMENT PRIMARY KEY,
        exam_id INT NULL,
        exam_type VARCHAR(30) NOT NULL,
        school VARCHAR(30) NOT NULL DEFAULT 'SOICT',
        dept VARCHAR(120) NOT NULL DEFAULT 'ICT',
        department VARCHAR(120) NULL,
        branch_code VARCHAR(50) NOT NULL,
        semester INT NOT NULL,
        branch_session VARCHAR(30) NOT NULL DEFAULT '',
        shift ENUM('Morning', 'Afternoon', 'Evening') NOT NULL,
        exam_date DATE NOT NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        subject_code VARCHAR(50) NULL,
        subject_name VARCHAR(255) NOT NULL,
        duration_minutes INT NULL,
        notes VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_matrix_slot (exam_type, school, dept, branch_code, semester, branch_session, shift, exam_date),
        INDEX idx_matrix_filter (exam_type, school, dept, shift),
        INDEX idx_matrix_date (exam_date),
        INDEX idx_matrix_exam (exam_id),
        CONSTRAINT fk_matrix_exam FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if(!$con->query($create)){
        respond(['success' => false, 'message' => 'Table setup failed: '.$con->error]);
    }

    $columns = [];
    $cq = $con->query("SHOW COLUMNS FROM exam_schedule_matrix");
    while($c = $cq->fetch_assoc()){
        $columns[] = $c['Field'];
    }

    if(!in_array('school', $columns, true)){
        $con->query("ALTER TABLE exam_schedule_matrix ADD COLUMN school VARCHAR(30) NOT NULL DEFAULT 'SOICT' AFTER exam_type");
    }
    if(!in_array('dept', $columns, true)){
        $con->query("ALTER TABLE exam_schedule_matrix ADD COLUMN dept VARCHAR(120) NOT NULL DEFAULT 'ICT' AFTER school");
    }
    if(!in_array('department', $columns, true)){
        $con->query("ALTER TABLE exam_schedule_matrix ADD COLUMN department VARCHAR(120) NULL AFTER dept");
    }
    if(!in_array('notes', $columns, true)){
        $con->query("ALTER TABLE exam_schedule_matrix ADD COLUMN notes VARCHAR(255) NULL AFTER duration_minutes");
    }
    if(!in_array('branch_session', $columns, true)){
        $con->query("ALTER TABLE exam_schedule_matrix ADD COLUMN branch_session VARCHAR(30) NOT NULL DEFAULT '' AFTER semester");
    }

    $con->query("ALTER TABLE exam_schedule_matrix MODIFY COLUMN exam_type VARCHAR(50) NOT NULL");

    $con->query("UPDATE exam_schedule_matrix SET dept = COALESCE(NULLIF(dept, ''), 'ICT')");
    $con->query("UPDATE exam_schedule_matrix SET school = COALESCE(NULLIF(school, ''), 'SOICT')");
    $con->query("UPDATE exam_schedule_matrix SET department = COALESCE(NULLIF(department, ''), CONCAT(school, '::', dept))");

    $indexCols = [];
    $iq = $con->query("SHOW INDEX FROM exam_schedule_matrix WHERE Key_name='uniq_matrix_slot'");
    while($idx = $iq->fetch_assoc()){
        $indexCols[] = $idx['Column_name'];
    }
    if(count($indexCols) > 0 && !in_array('branch_session', $indexCols, true)){
        $con->query("ALTER TABLE exam_schedule_matrix DROP INDEX uniq_matrix_slot");
        $con->query("ALTER TABLE exam_schedule_matrix ADD UNIQUE KEY uniq_matrix_slot (exam_type, school, dept, branch_code, semester, branch_session, shift, exam_date)");
    }
}

if(!isset($_SESSION['logged_in'])){
    respond(['success' => false, 'message' => 'Not logged in']);
}

$con = getConnection();
ensureScheduleMatrixTable($con);

$schoolDeptMap = [
    'SOICT' => ['CSE', 'IT', 'ECE'],
    'SOE' => [],
    'SOM' => [],
    'SOBT' => [],
    'SOVSAS' => [],
    'SOH' => [],
    'SOL&J' => []
];

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$method = $_SERVER['REQUEST_METHOD'];
$act = $body['action'] ?? $_GET['action'] ?? '';

$examTypes = ['back', 'repeat', 'midsem', 'end', 'practical'];

if($method === 'GET' && $act === 'meta'){
    $schools = array_keys($schoolDeptMap);

    $dbRows = [];
    $dq = $con->query("SELECT DISTINCT school, dept FROM exam_schedule_matrix ORDER BY school ASC, dept ASC");
    while($r = $dq->fetch_assoc()){
        $dbRows[] = $r;
    }

    $deptMap = $schoolDeptMap;
    foreach($dbRows as $r){
        $s = strtoupper(trim((string)$r['school']));
        $d = trim((string)$r['dept']);
        if($s === '' || $d === '') continue;
        if(!isset($deptMap[$s])) $deptMap[$s] = [];
        if(!in_array($d, $deptMap[$s], true)){
            $deptMap[$s][] = $d;
        }
    }

    foreach($deptMap as $school => $depts){
        sort($depts);
        $deptMap[$school] = $depts;
    }

    respond([
        'success' => true,
        'exam_types' => $examTypes,
        'schools' => $schools,
        'dept_map' => $deptMap,
        'default_school' => 'SOICT',
        'default_dept' => 'CSE'
    ]);
}

if($method === 'GET' && $act === 'matrix'){
    $examType = normalizeExamType($_GET['exam_type'] ?? 'midsem');
    $school = normalizeSchool($_GET['school'] ?? 'SOICT', $schoolDeptMap);
    $dept = normalizeDept($_GET['dept'] ?? 'CSE');

    if($examType === ''){
        respond(['success' => false, 'message' => 'Invalid exam_type']);
    }
    if($school === '' || $dept === ''){
        respond(['success' => false, 'message' => 'school and dept are required']);
    }

    $departmentKey = buildDepartmentKey($school, $dept);

    $baseDates = [];
    $dq = $con->prepare(
        "SELECT DISTINCT exam_date
         FROM exam_schedule_matrix
         WHERE exam_type=? AND school=? AND dept=?
         ORDER BY exam_date ASC"
    );
    $dq->bind_param('sss', $examType, $school, $dept);
    $dq->execute();
    $dr = $dq->get_result();
    while($d = $dr->fetch_assoc()){
        $baseDates[] = $d['exam_date'];
    }
    $dq->close();

    $dates = fillDateRange($baseDates);

    $rowsMap = [];
    $rq = $con->prepare(
        "SELECT matrix_id, branch_code, semester, branch_session, shift, exam_date, start_time, end_time, subject_code, subject_name, duration_minutes, notes
         FROM exam_schedule_matrix
         WHERE exam_type=? AND school=? AND dept=?
         ORDER BY branch_code ASC, semester ASC, shift ASC, exam_date ASC"
    );
    $rq->bind_param('sss', $examType, $school, $dept);
    $rq->execute();
    $rr = $rq->get_result();

    while($cell = $rr->fetch_assoc()){
        $key = $cell['branch_code'].'__'.$cell['semester'].'__'.($cell['branch_session'] ?? '').'__'.$cell['shift'];
        if(!isset($rowsMap[$key])){
            $rowsMap[$key] = [
                'branch_code' => $cell['branch_code'],
                'semester' => (int)$cell['semester'],
                'branch_session' => $cell['branch_session'] ?? '',
                'shift' => $cell['shift'],
                'cells' => []
            ];
        }

        $rowsMap[$key]['cells'][$cell['exam_date']] = [
            'matrix_id' => (int)$cell['matrix_id'],
            'subject_code' => $cell['subject_code'] ?? '',
            'subject_name' => $cell['subject_name'] ?? '',
            'start_time' => $cell['start_time'] ?? '',
            'end_time' => $cell['end_time'] ?? '',
            'notes' => $cell['notes'] ?? '',
            'duration_minutes' => $cell['duration_minutes'] !== null ? (int)$cell['duration_minutes'] : null,
            'is_filler' => false
        ];
    }
    $rq->close();

    foreach($rowsMap as $k => $row){
        foreach($dates as $date){
            if(!isset($rowsMap[$k]['cells'][$date])){
                $rowsMap[$k]['cells'][$date] = [
                    'matrix_id' => 0,
                    'subject_code' => '',
                    'subject_name' => '',
                    'start_time' => '',
                    'end_time' => '',
                    'notes' => 'Prep Leave',
                    'duration_minutes' => null,
                    'is_filler' => true
                ];
            }
        }
    }

    respond([
        'success' => true,
        'exam_type' => $examType,
        'school' => $school,
        'dept' => $dept,
        'department_key' => $departmentKey,
        'allowed_shifts' => allowedShifts($examType),
        'dates' => $dates,
        'rows' => array_values($rowsMap)
    ]);
}

if($method === 'POST' && $act === 'upsert_cell'){
    if(!in_array($_SESSION['role'] ?? '', ['admin', 'exam_cell'], true)){
        respond(['success' => false, 'message' => 'Not authorized']);
    }

    $examType = normalizeExamType($body['exam_type'] ?? '');
    $school = normalizeSchool($body['school'] ?? '', $schoolDeptMap);
    $dept = normalizeDept($body['dept'] ?? '');
    $branchCode = strtoupper(trim((string)($body['branch_code'] ?? '')));
    $semester = (int)($body['semester'] ?? 0);
    $branchSession = trim((string)($body['branch_session'] ?? ''));
    if(strlen($branchSession) > 30) $branchSession = substr($branchSession, 0, 30);
    $shift = normalizeShift($body['shift'] ?? '', $examType);
    $examDate = trim((string)($body['exam_date'] ?? ''));
    $subjectCode = trim((string)($body['subject_code'] ?? ''));
    $subjectName = trim((string)($body['subject_name'] ?? ''));
    $startTime = toTimeOrEmpty($body['start_time'] ?? '');
    $endTime = toTimeOrEmpty($body['end_time'] ?? '');
    $notes = trim((string)($body['notes'] ?? ''));

    if($examType === '' || $school === '' || $dept === '' || $branchCode === '' || $semester <= 0 || $shift === '' || $examDate === ''){
        respond(['success' => false, 'message' => 'Missing required fields']);
    }

    $departmentKey = buildDepartmentKey($school, $dept);

    if($subjectName === '' && $subjectCode === ''){
        $del = $con->prepare(
            "DELETE FROM exam_schedule_matrix
               WHERE exam_type=? AND school=? AND dept=? AND branch_code=? AND semester=? AND branch_session=? AND shift=? AND exam_date=?"
        );
           $del->bind_param('ssssisss', $examType, $school, $dept, $branchCode, $semester, $branchSession, $shift, $examDate);
        $ok = $del->execute();
        $del->close();
        respond($ok ? ['success' => true, 'message' => 'Cell cleared'] : ['success' => false, 'message' => 'Failed to clear cell']);
    }

    $scheduleValidation = validateBranchDateSchedule(
        $con,
        $examType,
        $school,
        $dept,
        $branchCode,
        $semester,
        $branchSession,
        $examDate,
        $shift,
        $startTime,
        $endTime
    );
    if(!$scheduleValidation['ok']){
        respond(['success' => false, 'message' => $scheduleValidation['message']]);
    }

    $duration = null;
    if($startTime !== '' && $endTime !== ''){
        $duration = (int)((strtotime($endTime) - strtotime($startTime)) / 60);
        if($duration <= 0){
            $duration = null;
        }
    }

    $stmt = $con->prepare(
        "INSERT INTO exam_schedule_matrix
         (exam_id, exam_type, school, dept, department, branch_code, semester, branch_session, shift, exam_date, start_time, end_time, subject_code, subject_name, duration_minutes, notes)
         VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, ?, NULLIF(?, ''))
         ON DUPLICATE KEY UPDATE
            start_time=VALUES(start_time),
            end_time=VALUES(end_time),
            subject_code=VALUES(subject_code),
            subject_name=VALUES(subject_name),
            duration_minutes=VALUES(duration_minutes),
            notes=VALUES(notes),
            department=VALUES(department)"
    );
    $stmt->bind_param('sssssisssssssis', $examType, $school, $dept, $departmentKey, $branchCode, $semester, $branchSession, $shift, $examDate, $startTime, $endTime, $subjectCode, $subjectName, $duration, $notes);

    $ok = $stmt->execute();
    $msg = $ok ? 'Cell updated' : $stmt->error;
    $stmt->close();

    respond($ok ? ['success' => true, 'message' => $msg] : ['success' => false, 'message' => $msg]);
}

if($method === 'POST' && $act === 'delete_cell'){
    if(!in_array($_SESSION['role'] ?? '', ['admin', 'exam_cell'], true)){
        respond(['success' => false, 'message' => 'Not authorized']);
    }

    $matrixId = (int)($body['matrix_id'] ?? 0);
    if($matrixId > 0){
        $del = $con->prepare('DELETE FROM exam_schedule_matrix WHERE matrix_id=?');
        $del->bind_param('i', $matrixId);
        $ok = $del->execute();
        $del->close();
        respond($ok ? ['success' => true, 'message' => 'Cell cleared'] : ['success' => false, 'message' => 'Failed to clear cell']);
    }

    $examType = normalizeExamType($body['exam_type'] ?? '');
    $school = normalizeSchool($body['school'] ?? '', $schoolDeptMap);
    $dept = normalizeDept($body['dept'] ?? '');
    $branchCode = strtoupper(trim((string)($body['branch_code'] ?? '')));
    $semester = (int)($body['semester'] ?? 0);
    $branchSession = trim((string)($body['branch_session'] ?? ''));
    if(strlen($branchSession) > 30) $branchSession = substr($branchSession, 0, 30);
    $shift = normalizeShift($body['shift'] ?? '', $examType);
    $examDate = trim((string)($body['exam_date'] ?? ''));

    if($examType === '' || $school === '' || $dept === '' || $branchCode === '' || $semester <= 0 || $shift === '' || $examDate === ''){
        respond(['success' => false, 'message' => 'Missing required fields']);
    }

    $del = $con->prepare(
        "DELETE FROM exam_schedule_matrix
         WHERE exam_type=? AND school=? AND dept=? AND branch_code=? AND semester=? AND branch_session=? AND shift=? AND exam_date=?"
    );
    $del->bind_param('ssssisss', $examType, $school, $dept, $branchCode, $semester, $branchSession, $shift, $examDate);
    $ok = $del->execute();
    $del->close();

    respond($ok ? ['success' => true, 'message' => 'Cell cleared'] : ['success' => false, 'message' => 'Failed to clear cell']);
}

if($method === 'POST' && $act === 'import_csv'){
    if(!in_array($_SESSION['role'] ?? '', ['admin', 'exam_cell'], true)){
        respond(['success' => false, 'message' => 'Not authorized']);
    }

    $examType = normalizeExamType($body['exam_type'] ?? '');
    $school = normalizeSchool($body['school'] ?? '', $schoolDeptMap);
    $dept = normalizeDept($body['dept'] ?? '');
    $entries = $body['entries'] ?? [];

    if($examType === '' || $school === '' || $dept === ''){
        respond(['success' => false, 'message' => 'Invalid import metadata']);
    }
    if(!is_array($entries) || count($entries) === 0){
        respond(['success' => false, 'message' => 'No entries provided']);
    }

    $departmentKey = buildDepartmentKey($school, $dept);

    $stmt = $con->prepare(
        "INSERT INTO exam_schedule_matrix
         (exam_id, exam_type, school, dept, department, branch_code, semester, branch_session, shift, exam_date, start_time, end_time, subject_code, subject_name, duration_minutes, notes)
         VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''), ?, ?, NULLIF(?, ''))
         ON DUPLICATE KEY UPDATE
            start_time=VALUES(start_time),
            end_time=VALUES(end_time),
            subject_code=VALUES(subject_code),
            subject_name=VALUES(subject_name),
            duration_minutes=VALUES(duration_minutes),
            notes=VALUES(notes),
            department=VALUES(department)"
    );

    $con->begin_transaction();
    $count = 0;

    try {
        foreach($entries as $e){
            $entrySchool = normalizeSchool($e['school'] ?? $school, $schoolDeptMap);
            $entryDept = normalizeDept($e['dept'] ?? $dept);
            if($entrySchool === '') $entrySchool = $school;
            if($entryDept === '') $entryDept = $dept;

            $branchCode = strtoupper(trim((string)($e['branch_code'] ?? '')));
            $semester = (int)($e['semester'] ?? 0);
            $branchSession = trim((string)($e['branch_session'] ?? ''));
            if(strlen($branchSession) > 30) $branchSession = substr($branchSession, 0, 30);

            if(($branchCode === '' || $semester <= 0) && isset($e['branch_sem'])){
                $parsed = parseBranchSemester($e['branch_sem']);
                $branchCode = $branchCode !== '' ? $branchCode : $parsed[0];
                $semester = $semester > 0 ? $semester : $parsed[1];
            }

            $shift = normalizeShift($e['shift'] ?? '', $examType);
            $examDate = trim((string)($e['exam_date'] ?? ''));
            $startTime = toTimeOrEmpty($e['start_time'] ?? '');
            $endTime = toTimeOrEmpty($e['end_time'] ?? '');
            $subjectCode = trim((string)($e['subject_code'] ?? ''));
            $subjectName = trim((string)($e['subject_name'] ?? ''));
            $notes = trim((string)($e['notes'] ?? ''));

            if($branchCode === '' || $semester <= 0 || $shift === '' || $examDate === '' || $subjectName === ''){
                continue;
            }

            $duration = null;
            if($startTime !== '' && $endTime !== ''){
                $duration = (int)((strtotime($endTime) - strtotime($startTime)) / 60);
                if($duration <= 0) $duration = null;
            }

            $scheduleValidation = validateBranchDateSchedule(
                $con,
                $examType,
                $entrySchool,
                $entryDept,
                $branchCode,
                $semester,
                $branchSession,
                $examDate,
                $shift,
                $startTime,
                $endTime
            );
            if(!$scheduleValidation['ok']){
                continue;
            }

            $entryDepartmentKey = buildDepartmentKey($entrySchool, $entryDept);
            $stmt->bind_param('sssssisssssssis', $examType, $entrySchool, $entryDept, $entryDepartmentKey, $branchCode, $semester, $branchSession, $shift, $examDate, $startTime, $endTime, $subjectCode, $subjectName, $duration, $notes);
            if(!$stmt->execute()){
                throw new Exception($stmt->error);
            }
            $count++;
        }

        $con->commit();
        $stmt->close();
        respond(['success' => true, 'message' => "Imported $count rows", 'count' => $count]);
    } catch (Throwable $e) {
        $con->rollback();
        $stmt->close();
        respond(['success' => false, 'message' => 'Import failed: '.$e->getMessage()]);
    }
}

respond(['success' => false, 'message' => 'Invalid action']);

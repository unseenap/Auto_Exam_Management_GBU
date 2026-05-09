<?php
ini_set('display_errors',0);
ob_start();
session_start();
ob_clean();

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/lib/schedule-subject.php';

function respond($payload){
    echo json_encode($payload);
    exit;
}

function queryOrFail($con, $sql){
    $q = $con->query($sql);
    if($q === false){
        respond(['success'=>false, 'message'=>'Query error: '.$con->error]);
    }
    return $q;
}

function normalizeExamType($value){
    $v = strtolower(trim((string)$value));
    $allowed = ['back', 'repeat', 'midsem', 'end', 'practical'];
    return in_array($v, $allowed, true) ? $v : '';
}

function normalizeShift($value){
    $v = ucfirst(strtolower(trim((string)$value)));
    $allowed = ['Morning', 'Afternoon', 'Evening'];
    return in_array($v, $allowed, true) ? $v : '';
}

function examTypeLabel($type){
    $map = [
        'back' => 'Back',
        'repeat' => 'Repeat',
        'midsem' => 'Mid Sem',
        'end' => 'End Sem',
        'practical' => 'Practical'
    ];
    return $map[$type] ?? ucfirst($type);
}

function examTypeSqlCase(){
    return "CASE
        WHEN LOWER(exam_name) LIKE '%practical%' THEN 'practical'
        WHEN LOWER(exam_name) LIKE '%repeat%' THEN 'repeat'
        WHEN LOWER(exam_name) LIKE '%back%' THEN 'back'
        WHEN LOWER(exam_name) LIKE '%mid%' THEN 'midsem'
        WHEN LOWER(exam_name) LIKE '%end%' OR LOWER(exam_name) LIKE '%final%' THEN 'end'
        ELSE 'midsem'
    END";
}

function ensureRoomsLayoutColumns($con){
    $columns = [];
    $q = $con->query("SHOW COLUMNS FROM rooms");
    if($q){
        while($c = $q->fetch_assoc()){
            $columns[] = $c['Field'];
        }
    }

    if(!in_array('whiteboard_area', $columns, true)){
        $con->query("ALTER TABLE rooms ADD COLUMN whiteboard_area VARCHAR(20) NULL AFTER layout_notes");
    }
}

function decodeBlockedSeats($raw){
    $decoded = json_decode((string)$raw, true);
    if(!is_array($decoded)) return [];

    $clean = [];
    foreach($decoded as $seat){
        $seatId = strtoupper(trim((string)$seat));
        if($seatId !== '' && preg_match('/^R\d+C\d+$/', $seatId)){
            $clean[] = $seatId;
        }
    }

    return array_values(array_unique($clean));
}

function yearFromSemesterLabel($semester){
    $sem = (int)$semester;
    if($sem <= 0) return 'Year N/A';
    return 'Year '.(int)ceil($sem / 2);
}

function summarizeByBranchAndYear(array $students, array $branchSubjects, string $defaultSubjectName, string $defaultSubjectCode){
    $branchMap = [];
    $yearMap = [];

    foreach($students as $s){
        $branch = trim((string)($s['branch'] ?? ''));
        if($branch === '') $branch = 'Unknown';

        $branchCode = strtoupper(trim((string)($s['branch_code'] ?? '')));
        if($branchCode === '') $branchCode = strtoupper($branch);

        $yearGroup = yearFromSemesterLabel((int)($s['semester'] ?? 0));

        if(!isset($branchMap[$branch])){
            $subject = $branchSubjects[$branchCode] ?? null;
            $subjectName = trim((string)($subject['subject_name'] ?? $defaultSubjectName));
            $subjectCode = trim((string)($subject['subject_code'] ?? $defaultSubjectCode));
            $branchMap[$branch] = [
                'group' => $branch,
                'subject_name' => $subjectName,
                'subject_code' => $subjectCode,
                'students_count' => 0,
                'question_papers' => 0,
                'answer_sheets' => 0,
            ];
        }

        if(!isset($yearMap[$yearGroup])){
            $yearMap[$yearGroup] = [
                'year_group' => $yearGroup,
                'students_count' => 0,
                'question_papers' => 0,
                'answer_sheets' => 0,
            ];
        }

        $branchMap[$branch]['students_count']++;
        $branchMap[$branch]['question_papers']++;
        $branchMap[$branch]['answer_sheets']++;

        $yearMap[$yearGroup]['students_count']++;
        $yearMap[$yearGroup]['question_papers']++;
        $yearMap[$yearGroup]['answer_sheets']++;
    }

    ksort($branchMap);
    ksort($yearMap);

    return [
        'branch_summary' => array_values($branchMap),
        'year_summary' => array_values($yearMap),
        'multi_branch' => count($branchMap) > 1,
    ];
}

function getExamSessionEnumValues($con){
    $values = ['Morning', 'Afternoon'];
    $q = $con->query("SHOW COLUMNS FROM exams LIKE 'session'");
    if(!$q) return $values;
    $col = $q->fetch_assoc();
    $typeDef = (string)($col['Type'] ?? '');
    if(preg_match_all("/'([^']+)'/", $typeDef, $matches) && !empty($matches[1])){
        $values = array_values(array_unique($matches[1]));
    }
    return $values;
}

function resolveExamSessionForStorage($con, $requestedShift){
    $allowed = getExamSessionEnumValues($con);
    if(in_array($requestedShift, $allowed, true)) return $requestedShift;
    if(in_array('Afternoon', $allowed, true)) return 'Afternoon';
    if(in_array('Morning', $allowed, true)) return 'Morning';
    return $allowed[0] ?? 'Afternoon';
}

function getOrCreateExamForSlot($con, $examType, $examDate, $shift, $allowCreate = true){
    // 1. Find exam directly linked via exam_schedule_matrix
    $matrixExamQ = $con->prepare(
        "SELECT e.exam_id, e.exam_name, e.date, e.session
         FROM exams e
         JOIN exam_schedule_matrix m ON m.exam_id = e.exam_id
         WHERE m.exam_type = ? AND m.exam_date = ? AND m.shift = ?
         ORDER BY e.exam_id DESC
         LIMIT 1"
    );
    $matrixExamQ->bind_param('sss', $examType, $examDate, $shift);
    $matrixExamQ->execute();
    $matrixExam = $matrixExamQ->get_result()->fetch_assoc();
    $matrixExamQ->close();
    if ($matrixExam) return $matrixExam;

    // 2. Find exam by date + session (shift) + exam_type derived from name
    $caseExpr      = examTypeSqlCase();
    $storageSession = resolveExamSessionForStorage($con, $shift);

    $examQ = $con->prepare(
        "SELECT exam_id, exam_name, date, session
         FROM exams
         WHERE date = ? AND session = ? AND $caseExpr = ?
         ORDER BY exam_id DESC
         LIMIT 1"
    );
    $examQ->bind_param('sss', $examDate, $storageSession, $examType);
    $examQ->execute();
    $exam = $examQ->get_result()->fetch_assoc();
    $examQ->close();
    if ($exam) {
        // Back-link matrix rows that are not yet linked
        $sync = $con->prepare(
            "UPDATE exam_schedule_matrix
             SET exam_id = ?
             WHERE exam_id IS NULL AND exam_type = ? AND exam_date = ? AND shift = ?"
        );
        $sync->bind_param('isss', $exam['exam_id'], $examType, $examDate, $shift);
        $sync->execute();
        $sync->close();
        return $exam;
    }

    if (!$allowCreate) return null;

    // 3. Only create a new exam record if a matrix slot actually exists
    $slotCheck = $con->prepare(
        "SELECT 1 FROM exam_schedule_matrix
         WHERE exam_type = ? AND exam_date = ? AND shift = ?
         LIMIT 1"
    );
    $slotCheck->bind_param('sss', $examType, $examDate, $shift);
    $slotCheck->execute();
    $slotExists = $slotCheck->get_result()->fetch_row();
    $slotCheck->close();
    if (!$slotExists) return null;

    $examName       = ucfirst($examType) . ' Exam ' . $examDate . ' ' . $shift;
    $storageSession = resolveExamSessionForStorage($con, $shift);
    $create = $con->prepare('INSERT INTO exams (exam_name, date, session) VALUES (?, ?, ?)');
    $create->bind_param('sss', $examName, $examDate, $storageSession);
    $ok       = $create->execute();
    $insertId = (int)$create->insert_id;
    $create->close();

    if (!$ok || $insertId <= 0) return null;

    $mapQ = $con->prepare(
        "UPDATE exam_schedule_matrix
         SET exam_id = ?
         WHERE exam_type = ? AND exam_date = ? AND shift = ?"
    );
    $mapQ->bind_param('isss', $insertId, $examType, $examDate, $shift);
    $mapQ->execute();
    $mapQ->close();

    return [
        'exam_id'   => $insertId,
        'exam_name' => $examName,
        'date'      => $examDate,
        'session'   => $storageSession,
    ];
}

if(!isset($_SESSION['logged_in'])){
    respond(['success'=>false, 'message'=>'Not logged in']);
}

$con = getConnection();
$con->set_charset('utf8mb4');
ensureRoomsLayoutColumns($con);
$method = $_SERVER['REQUEST_METHOD'];
$act = $_GET['action'] ?? '';

if($method === 'POST'){
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $postAction = $body['action'] ?? '';

    if($postAction !== 'save_manual'){
        respond(['success'=>false, 'message'=>'Invalid action']);
    }

    $examId = (int)($body['exam_id'] ?? 0);
    $roomId = (int)($body['room_id'] ?? 0);
    $updates = $body['updates'] ?? [];

    if($examId <= 0 || $roomId <= 0){
        respond(['success'=>false, 'message'=>'exam_id and room_id are required']);
    }
    if(!is_array($updates) || count($updates) === 0){
        respond(['success'=>false, 'message'=>'No manual seat updates provided']);
    }

    $con->begin_transaction();
    try {
        $phaseOne = $con->prepare(
            "UPDATE seating_allocation sa
             JOIN students s ON sa.student_id=s.student_id
             SET sa.seat_no=sa.seat_no+10000
             WHERE sa.exam_id=? AND sa.room_id=? AND s.roll_no=?"
        );

        foreach($updates as $u){
            $rollNo = trim((string)($u['roll_no'] ?? ''));
            if($rollNo === '') continue;
            $phaseOne->bind_param('iis', $examId, $roomId, $rollNo);
            if(!$phaseOne->execute()) throw new Exception($con->error);
        }
        $phaseOne->close();

        $phaseTwo = $con->prepare(
            "UPDATE seating_allocation sa
             JOIN students s ON sa.student_id=s.student_id
             SET sa.seat_no=?, sa.row_no=?
             WHERE sa.exam_id=? AND sa.room_id=? AND s.roll_no=?"
        );

        foreach($updates as $u){
            $rollNo = trim((string)($u['roll_no'] ?? ''));
            $seatNo = (int)($u['seat_no'] ?? 0);
            $rowNo = (int)($u['row_no'] ?? 1);
            if($rollNo === '' || $seatNo <= 0 || $rowNo <= 0) continue;
            $phaseTwo->bind_param('iiiis', $seatNo, $rowNo, $examId, $roomId, $rollNo);
            if(!$phaseTwo->execute()) throw new Exception($con->error);
        }
        $phaseTwo->close();

        $con->commit();
        respond(['success'=>true, 'message'=>'Manual seating updates saved']);
    } catch (Throwable $e) {
        $con->rollback();
        respond(['success'=>false, 'message'=>'Failed to save manual seating: '.$e->getMessage()]);
    }
}

if($method !== 'GET'){
    respond(['success'=>false, 'message'=>'Only GET and POST are allowed']);
}

if($act === 'list_exam_types'){
    $rows = [];
    $q = $con->query("SELECT DISTINCT exam_type FROM exam_schedule_matrix ORDER BY FIELD(exam_type, 'back', 'repeat', 'midsem', 'end', 'practical'), exam_type");
    if($q){
        while($r = $q->fetch_assoc()){
            $value = normalizeExamType($r['exam_type'] ?? '');
            if($value === '') continue;
            $rows[] = ['value' => $value, 'label' => examTypeLabel($value)];
        }
    }

    if(empty($rows)){
        $fallback = ['back', 'repeat', 'midsem', 'end', 'practical'];
        foreach($fallback as $value){
            $rows[] = ['value' => $value, 'label' => examTypeLabel($value)];
        }
    }

    respond(['success'=>true, 'data'=>$rows]);
}

if($act === 'list_dates'){
    $examType = normalizeExamType($_GET['exam_type'] ?? '');
    if($examType === ''){
        respond(['success'=>false, 'message'=>'exam_type is required']);
    }

    $rows = [];
    $q = $con->prepare("SELECT DISTINCT exam_date FROM exam_schedule_matrix WHERE exam_type=? ORDER BY exam_date ASC");
    $q->bind_param('s', $examType);
    $q->execute();
    $res = $q->get_result();
    while($r = $res->fetch_assoc()){
        $rows[] = $r['exam_date'];
    }
    $q->close();

    if(empty($rows)){
        $caseExpr = examTypeSqlCase();
        $fallbackQ = $con->prepare("SELECT DISTINCT date FROM exams WHERE $caseExpr=? ORDER BY date ASC");
        $fallbackQ->bind_param('s', $examType);
        $fallbackQ->execute();
        $fallbackRes = $fallbackQ->get_result();
        while($r = $fallbackRes->fetch_assoc()){
            $rows[] = $r['date'];
        }
        $fallbackQ->close();
    }

    respond(['success'=>true, 'data'=>$rows]);
}

if($act === 'list_rooms'){
    $examType = normalizeExamType($_GET['exam_type'] ?? '');
    $examDate = trim((string)($_GET['date'] ?? ''));
    $shift = normalizeShift($_GET['shift'] ?? '');

    if($examType === '' || $examDate === '' || $shift === ''){
        respond(['success'=>false, 'message'=>'exam_type, date and shift are required']);
    }

    $rows = [];
    $exam = getOrCreateExamForSlot($con, $examType, $examDate, $shift, false);

    if($exam){
        $examId = (int)$exam['exam_id'];
        $q = $con->prepare(
            "SELECT DISTINCT r.room_id, r.room_no, r.capacity, r.building, r.matrix_rows, r.matrix_cols,
                    r.blocked_seats_json, r.layout_notes, COALESCE(r.whiteboard_area, 'front') AS whiteboard_area
             FROM rooms r
             WHERE r.room_id IN (
                SELECT room_id FROM seating_allocation WHERE exam_id=?
                UNION
                SELECT room_id FROM invigilation_allocation WHERE exam_id=?
             )
             ORDER BY r.building ASC, r.room_no ASC"
        );
        $q->bind_param('ii', $examId, $examId);
        $q->execute();
        $res = $q->get_result();
        while($r = $res->fetch_assoc()){
            $r['room_id'] = (int)$r['room_id'];
            $r['capacity'] = (int)$r['capacity'];
            $r['matrix_rows'] = (int)($r['matrix_rows'] ?? 0);
            $r['matrix_cols'] = (int)($r['matrix_cols'] ?? 0);
            $r['blocked_seats'] = decodeBlockedSeats($r['blocked_seats_json'] ?? '[]');
            unset($r['blocked_seats_json']);
            $rows[] = $r;
        }
        $q->close();
    }

    if(count($rows) === 0){
        $fallback = queryOrFail($con, "SELECT room_id, room_no, capacity, building, matrix_rows, matrix_cols, blocked_seats_json, layout_notes, COALESCE(whiteboard_area, 'front') AS whiteboard_area FROM rooms ORDER BY building ASC, room_no ASC");
        while($r = $fallback->fetch_assoc()){
            $r['room_id'] = (int)$r['room_id'];
            $r['capacity'] = (int)$r['capacity'];
            $r['matrix_rows'] = (int)($r['matrix_rows'] ?? 0);
            $r['matrix_cols'] = (int)($r['matrix_cols'] ?? 0);
            $r['blocked_seats'] = decodeBlockedSeats($r['blocked_seats_json'] ?? '[]');
            unset($r['blocked_seats_json']);
            $rows[] = $r;
        }
    }

    respond(['success'=>true, 'data'=>$rows]);
}

if($act === 'list_shifts'){
    $examType = normalizeExamType($_GET['exam_type'] ?? '');
    $examDate = trim((string)($_GET['date'] ?? ''));

    if($examType === '' || $examDate === ''){
        respond(['success'=>false, 'message'=>'exam_type and date are required']);
    }

    $rows = [];
    $q = $con->prepare(
        "SELECT DISTINCT shift
         FROM exam_schedule_matrix
         WHERE exam_type=? AND exam_date=?
         ORDER BY FIELD(shift, 'Morning', 'Afternoon', 'Evening')"
    );
    $q->bind_param('ss', $examType, $examDate);
    $q->execute();
    $res = $q->get_result();
    while($r = $res->fetch_assoc()){
        $rows[] = $r['shift'];
    }
    $q->close();

    if(empty($rows)){
        $caseExpr = examTypeSqlCase();
        $q = $con->prepare(
            "SELECT DISTINCT session
             FROM exams
             WHERE date=? AND $caseExpr=?
             ORDER BY FIELD(session, 'Morning', 'Afternoon', 'Evening')"
        );
        $q->bind_param('ss', $examDate, $examType);
        $q->execute();
        $res = $q->get_result();
        while($r = $res->fetch_assoc()){
            $rows[] = $r['session'];
        }
        $q->close();
    }

    $rows = array_values(array_unique($rows));
    $rows = array_values(array_filter($rows, function($s){
        return in_array($s, ['Morning', 'Afternoon', 'Evening'], true);
    }));

    respond(['success'=>true, 'data'=>$rows]);
}

$examType = normalizeExamType($_GET['exam_type'] ?? '');
$examDate = trim((string)($_GET['date'] ?? ''));
$shift = normalizeShift($_GET['shift'] ?? '');
$roomId = (int)($_GET['room_id'] ?? 0);

if($examType === '' || $examDate === '' || $shift === '' || $roomId <= 0){
    respond(['success'=>false, 'message'=>'exam_type, date, shift and room_id are required']);
}

$roomQ = queryOrFail(
    $con,
    "SELECT room_id, room_no, capacity, building, matrix_rows, matrix_cols, blocked_seats_json, layout_notes, COALESCE(whiteboard_area, 'front') AS whiteboard_area FROM rooms WHERE room_id=$roomId LIMIT 1"
);
$room = $roomQ->fetch_assoc();
if(!$room){
    respond(['success'=>false, 'message'=>'Room not found']);
}

$exam = getOrCreateExamForSlot($con, $examType, $examDate, $shift);
if(!$exam){
    respond(['success'=>false, 'message'=>'No exam found for selected type and date']);
}

$examId = (int)$exam['exam_id'];

$students = [];
$seatNos = [];
$rowsQ = queryOrFail(
    $con,
    "SELECT sa.seat_no, sa.row_no, s.roll_no, s.name, s.branch, s.branch_code, s.semester, s.section
     FROM seating_allocation sa
     JOIN students s ON sa.student_id = s.student_id
     WHERE sa.exam_id=$examId AND sa.room_id=$roomId
     ORDER BY sa.seat_no ASC"
);
while($r = $rowsQ->fetch_assoc()){
    $r['seat_no'] = (int)$r['seat_no'];
    $r['row_no'] = (int)($r['row_no'] ?? 1);
    $students[] = $r;
    $seatNos[] = $r['seat_no'];
}

$capacity = (int)$room['capacity'];
$count = count($students);
// NOTE: We never hard-fail here. If more students are seated than the declared
// capacity (e.g. because blocked seats were added after allocation), we return
// the data with a warning so the admin can see and fix it rather than getting
// a blank screen.

$invigilators = [];
$invQ = $con->prepare(
    "SELECT ia.duty_type, f.faculty_id, f.name, f.department
     FROM invigilation_allocation ia
     JOIN faculty f ON f.faculty_id = ia.faculty_id
     WHERE ia.exam_id=? AND ia.room_id=?
     ORDER BY ia.duty_type ASC, f.name ASC"
);
$invQ->bind_param('ii', $examId, $roomId);
$invQ->execute();
$invRes = $invQ->get_result();
while($r = $invRes->fetch_assoc()){
    $invigilators[] = [
        'faculty_id' => (int)$r['faculty_id'],
        'name' => $r['name'],
        'department' => $r['department'],
        'duty_type' => $r['duty_type']
    ];
}
$invQ->close();

$sequential = true;
for($i = 0; $i < $count; $i++){
    if($seatNos[$i] !== ($i + 1)){
        $sequential = false;
        break;
    }
}

$subjectPayload = sp_fetch_subject_payload($con, $examId, $examType, $examDate, $shift);
$subjectName = (string)($subjectPayload['subject_name'] ?? '');
$subjectCode = (string)($subjectPayload['subject_code'] ?? '');

$summaryPayload = summarizeByBranchAndYear(
    $students,
    (array)($subjectPayload['branch_subjects'] ?? []),
    $subjectName,
    $subjectCode
);

foreach($students as &$studentRow){
    $studentRow['year_label'] = yearFromSemesterLabel((int)($studentRow['semester'] ?? 0));
}
unset($studentRow);

$response = [
    'success'        => true,
    'exam_id'        => (int)$exam['exam_id'],
    'exam_type'      => $examType,
    'exam_name'      => $exam['exam_name'],
    'subject_name'   => $subjectName,
    'subject_code'   => $subjectCode,
    'date'           => $exam['date'],
    'session'        => $shift,
    'room_id'        => (int)$room['room_id'],
    'room_no'        => $room['room_no'],
    'capacity'       => $capacity,
    'building'       => $room['building'],
    'matrix_rows'    => (int)($room['matrix_rows'] ?? 0),
    'matrix_cols'    => (int)($room['matrix_cols'] ?? 0),
    'blocked_seats'  => decodeBlockedSeats($room['blocked_seats_json'] ?? '[]'),
    'layout_notes'   => (string)($room['layout_notes'] ?? ''),
    'whiteboard_area'=> (string)($room['whiteboard_area'] ?? 'front'),
    'invigilators'   => $invigilators,
    'students'       => $students,
    'student_count'  => $count,
    'is_sequential'  => $sequential,
    'branch_summary' => $summaryPayload['branch_summary'],
    'year_summary'   => $summaryPayload['year_summary'],
    'multi_branch'   => (bool)$summaryPayload['multi_branch'],
];

// Warn (never hard-fail) when seated count exceeds declared capacity.
// This can happen if blocked seats were added after allocation was run.
// The admin should re-run allocation to fix it.
if ($count > $capacity) {
    $response['warning'] = "Room {$room['room_no']} has {$count} students seated but declared capacity is {$capacity}. Re-run seating allocation to redistribute.";
}

if (!$sequential && !isset($response['warning'])) {
    $response['warning'] = 'Seat numbers are not sequential from 1. Consider re-running allocation.';
}

respond($response);

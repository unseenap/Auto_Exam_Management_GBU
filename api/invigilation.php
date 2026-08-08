<?php
ini_set('display_errors', 0);
ob_start();
session_start();
ob_clean();
mysqli_report(MYSQLI_REPORT_OFF);

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if(!isset($_SESSION['logged_in'])){
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

function canManageInvigilation(){
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'exam_cell'], true);
}

function normalizeExamType($value){
    $v = strtolower(trim((string)$value));
    $allowed = ['back', 'repeat', 'midsem', 'end', 'practical'];
    return in_array($v, $allowed, true) ? $v : '';
}

function normalizeShiftFilter($value){
    $v = ucfirst(strtolower(trim((string)$value)));
    $allowed = ['Morning', 'Afternoon', 'Evening'];
    return in_array($v, $allowed, true) ? $v : '';
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

function fetchExam($con, $examId){
    $stmt = $con->prepare('SELECT exam_id, exam_name, date, session FROM exams WHERE exam_id=? LIMIT 1');
    $stmt->bind_param('i', $examId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function requiredInvigilatorsByCapacity($capacity){
    $cap = (int)$capacity;
    return ($cap <= 10) ? 1 : 2;
}

function getRoomsForExam($con, $examId){
    $rooms = [];

    $stmt = $con->prepare("SELECT DISTINCT r.room_id, r.room_no, r.building, COALESCE(r.capacity, 0) AS capacity
                           FROM seating_allocation sa
                           JOIN rooms r ON r.room_id = sa.room_id
                           WHERE sa.exam_id=?
                           ORDER BY r.building, r.room_no");
    $stmt->bind_param('i', $examId);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()){
        $r['room_id'] = (int)$r['room_id'];
        $r['capacity'] = (int)$r['capacity'];
        $rooms[] = $r;
    }
    $stmt->close();

    // Fallback: if no seating allocation exists yet, consider all rooms.
    if(empty($rooms)){
        $q = $con->query('SELECT room_id, room_no, building, COALESCE(capacity, 0) AS capacity FROM rooms ORDER BY building, room_no');
        if($q){
            while($r = $q->fetch_assoc()){
                $r['room_id'] = (int)$r['room_id'];
                $r['capacity'] = (int)$r['capacity'];
                $rooms[] = $r;
            }
        }
    }

    return $rooms;
}

function getBusyFacultyMap($con, $examDate, $examSession, $excludeExamId){
    $busy = [];
    $stmt = $con->prepare("SELECT ia.faculty_id
                           FROM invigilation_allocation ia
                           JOIN exams e ON e.exam_id = ia.exam_id
                           WHERE e.date=? AND e.session=? AND ia.exam_id<>?");
    $stmt->bind_param('ssi', $examDate, $examSession, $excludeExamId);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()) $busy[(int)$row['faculty_id']] = true;
    $stmt->close();
    return $busy;
}

function getBusyRoomMap($con, $examDate, $examSession, $excludeExamId){
    $busy = [];
    $stmt = $con->prepare("SELECT ia.room_id
                           FROM invigilation_allocation ia
                           JOIN exams e ON e.exam_id = ia.exam_id
                           WHERE e.date=? AND e.session=? AND ia.exam_id<>?");
    $stmt->bind_param('ssi', $examDate, $examSession, $excludeExamId);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()) $busy[(int)$row['room_id']] = true;
    $stmt->close();
    return $busy;
}

function getFacultyByIdMap($con){
    $map = [];
    $q = $con->query('SELECT faculty_id, name, department, total_duties FROM faculty ORDER BY total_duties ASC, faculty_id ASC');
    if($q){
        while($r = $q->fetch_assoc()){
            $id = (int)$r['faculty_id'];
            $map[$id] = [
                'faculty_id' => $id,
                'name' => $r['name'],
                'department' => $r['department'],
                'total_duties' => (int)$r['total_duties']
            ];
        }
    }
    return $map;
}

function getBusyFacultySetForSlot($con, $examDate, $examSession, $excludeExamId){
    $set = [];
    $stmt = $con->prepare("SELECT DISTINCT ia.faculty_id
                           FROM invigilation_allocation ia
                           JOIN exams e ON e.exam_id = ia.exam_id
                           WHERE e.date=? AND e.session=? AND ia.exam_id<>?");
    $stmt->bind_param('ssi', $examDate, $examSession, $excludeExamId);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()) $set[(int)$row['faculty_id']] = true;
    $stmt->close();
    return $set;
}

$con = getConnection();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$method = $_SERVER['REQUEST_METHOD'];
$act = $body['action'] ?? $_GET['action'] ?? '';

if($method === 'GET' && $act === 'list_exams'){
    $rows = [];
    $q = $con->query("SELECT exam_id, exam_name, date, session FROM exams ORDER BY date DESC, exam_id DESC");
    if($q){
        while($r = $q->fetch_assoc()) $rows[] = $r;
    }
    echo json_encode(['success' => true, 'data' => $rows]);
    closeConnection($con);
    exit;
}

if($method === 'GET' && $act === 'list_exam_types'){
    $rows = [];
    $q = $con->query("SELECT DISTINCT exam_type FROM exam_schedule_matrix ORDER BY exam_type ASC");
    if($q){
        while($r = $q->fetch_assoc()){
            $et = normalizeExamType($r['exam_type'] ?? '');
            if($et !== '' && !in_array($et, $rows, true)) $rows[] = $et;
        }
    }

    if(empty($rows)){
        $rows = ['back', 'repeat', 'midsem', 'end', 'practical'];
    }

    echo json_encode(['success' => true, 'data' => $rows]);
    closeConnection($con);
    exit;
}

if($method === 'GET' && $act === 'list_dates'){
    $examType = normalizeExamType($_GET['exam_type'] ?? '');
    if($examType === ''){
        echo json_encode(['success' => false, 'message' => 'exam_type is required']);
        closeConnection($con);
        exit;
    }

    $rows = [];
    $stmt = $con->prepare("SELECT DISTINCT exam_date FROM exam_schedule_matrix WHERE exam_type=? ORDER BY exam_date ASC");
    $stmt->bind_param('s', $examType);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()){
        $rows[] = $r['exam_date'];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'data' => $rows]);
    closeConnection($con);
    exit;
}

if($method === 'GET' && $act === 'list_shifts'){
    $examType = normalizeExamType($_GET['exam_type'] ?? '');
    $examDate = trim((string)($_GET['date'] ?? ''));
    if($examType === '' || $examDate === ''){
        echo json_encode(['success' => false, 'message' => 'exam_type and date are required']);
        closeConnection($con);
        exit;
    }

    $rows = [];
    $stmt = $con->prepare("SELECT DISTINCT shift FROM exam_schedule_matrix WHERE exam_type=? AND exam_date=? ORDER BY FIELD(shift, 'Morning', 'Afternoon', 'Evening')");
    $stmt->bind_param('ss', $examType, $examDate);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()){
        $shift = normalizeShiftFilter($r['shift'] ?? '');
        if($shift !== '' && !in_array($shift, $rows, true)) $rows[] = $shift;
    }
    $stmt->close();

    echo json_encode(['success' => true, 'data' => $rows]);
    closeConnection($con);
    exit;
}

if($method === 'GET' && $act === 'list_exams_filtered'){
    $examType = normalizeExamType($_GET['exam_type'] ?? '');
    $examDate = trim((string)($_GET['date'] ?? ''));
    $shift = normalizeShiftFilter($_GET['shift'] ?? '');

    if($examType === '' || $examDate === '' || $shift === ''){
        echo json_encode(['success' => false, 'message' => 'exam_type, date and shift are required', 'data' => []]);
        closeConnection($con);
        exit;
    }

    $rows = [];
    $caseExpr = examTypeSqlCase();
    $stmt = $con->prepare("SELECT exam_id, exam_name, date, session
                           FROM exams
                           WHERE date=? AND session=? AND $caseExpr=?
                           ORDER BY exam_id DESC");
    $stmt->bind_param('sss', $examDate, $shift, $examType);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()){
        $rows[] = $r;
    }
    $stmt->close();

    echo json_encode(['success' => true, 'data' => $rows]);
    closeConnection($con);
    exit;
}

if($method === 'GET' && $act === 'get_duties'){
    $examId = (int)($_GET['exam_id'] ?? 0);
    if($examId <= 0){
        echo json_encode(['success' => false, 'message' => 'Exam is required', 'data' => []]);
        closeConnection($con);
        exit;
    }

    $rows = [];
        $stmt = $con->prepare("SELECT ia.duty_id, ia.exam_id, ia.faculty_id, ia.room_id,
                                  ia.duty_type, ia.created_at,
                                  f.name AS faculty_name, f.department,
                          r.room_no, r.building, COALESCE(r.capacity, 0) AS room_capacity,
                                  e.exam_name, e.date, e.session
                           FROM invigilation_allocation ia
                           JOIN faculty f ON f.faculty_id = ia.faculty_id
                           JOIN rooms r   ON r.room_id = ia.room_id
                           JOIN exams e   ON e.exam_id = ia.exam_id
                           WHERE ia.exam_id=?
                      ORDER BY r.building ASC, r.room_no ASC, ia.duty_id ASC");
    $stmt->bind_param('i', $examId);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();

    $roomNeed = [];
    foreach($rows as $row){
        $rid = (int)$row['room_id'];
        if(!isset($roomNeed[$rid])){
            $roomNeed[$rid] = requiredInvigilatorsByCapacity((int)$row['room_capacity']);
        }
    }
    foreach($rows as &$row){
        $rid = (int)$row['room_id'];
        $row['required_invigilators'] = $roomNeed[$rid] ?? 1;
    }
    unset($row);

    echo json_encode(['success' => true, 'data' => $rows]);
    closeConnection($con);
    exit;
}

if($method === 'GET' && $act === 'list_faculty'){
    $rows = [];
    $q = $con->query('SELECT faculty_id, name, department, total_duties FROM faculty ORDER BY total_duties ASC, name ASC');
    if($q){
        while($r = $q->fetch_assoc()){
            $r['faculty_id'] = (int)$r['faculty_id'];
            $r['total_duties'] = (int)$r['total_duties'];
            $rows[] = $r;
        }
    }
    echo json_encode(['success' => true, 'data' => $rows]);
    closeConnection($con);
    exit;
}

if($method === 'POST' && $act === 'allocate'){
    if(!canManageInvigilation()){
        echo json_encode(['success' => false, 'message' => 'You do not have permission to allocate duties']);
        closeConnection($con);
        exit;
    }

    $examId = (int)($body['exam_id'] ?? 0);
    if($examId <= 0){
        echo json_encode(['success' => false, 'message' => 'Exam is required']);
        closeConnection($con);
        exit;
    }

    $exam = fetchExam($con, $examId);
    if(!$exam){
        echo json_encode(['success' => false, 'message' => 'Selected exam not found']);
        closeConnection($con);
        exit;
    }

    $rooms = getRoomsForExam($con, $examId);
    if(empty($rooms)){
        echo json_encode(['success' => false, 'message' => 'No rooms available for allocation']);
        closeConnection($con);
        exit;
    }

    $facultyMap = getFacultyByIdMap($con);
    $faculty = array_values($facultyMap);
    if(empty($faculty)){
        echo json_encode(['success' => false, 'message' => 'No faculty found']);
        closeConnection($con);
        exit;
    }

    $busyFaculty = getBusyFacultySetForSlot($con, $exam['date'], $exam['session'], $examId);
    $busyRooms = getBusyRoomMap($con, $exam['date'], $exam['session'], $examId);

    // Remove old duties for this exam before recreating allocation.
    $oldFaculty = [];
    $old = $con->prepare('SELECT faculty_id FROM invigilation_allocation WHERE exam_id=?');
    $old->bind_param('i', $examId);
    $old->execute();
    $oldRes = $old->get_result();
    while($r = $oldRes->fetch_assoc()) $oldFaculty[] = (int)$r['faculty_id'];
    $old->close();

    $del = $con->prepare('DELETE FROM invigilation_allocation WHERE exam_id=?');
    $del->bind_param('i', $examId);
    $del->execute();
    $del->close();

    foreach($oldFaculty as $fid){
        $con->query('UPDATE faculty SET total_duties=GREATEST(total_duties-1,0) WHERE faculty_id=' . (int)$fid);
    }

    $insert = $con->prepare('INSERT INTO invigilation_allocation (exam_id, faculty_id, room_id, duty_type) VALUES(?,?,?,?)');

    $assigned = 0;
    $skippedRooms = 0;
    $unfilledSlots = 0;
    $facultyCursor = 0;
    $usedFacultyThisExam = [];

    foreach($rooms as $room){
        $roomId = (int)$room['room_id'];
        if(isset($busyRooms[$roomId])){
            $skippedRooms++;
            continue;
        }

        $required = requiredInvigilatorsByCapacity((int)$room['capacity']);
        for($slot = 1; $slot <= $required; $slot++){
            $pickedFaculty = 0;
            $checked = 0;

            while($checked < count($faculty)){
                $candidate = (int)$faculty[$facultyCursor % count($faculty)]['faculty_id'];
                $facultyCursor++;
                $checked++;

                if(isset($busyFaculty[$candidate])) continue;
                if(isset($usedFacultyThisExam[$candidate])) continue;

                $pickedFaculty = $candidate;
                break;
            }

            if($pickedFaculty === 0){
                $unfilledSlots++;
                continue;
            }

            $dutyType = ($slot === 1) ? 'Hall Invigilator' : 'Relief Invigilator (Reliever)';
            $insert->bind_param('iiis', $examId, $pickedFaculty, $roomId, $dutyType);
            if($insert->execute()){
                $con->query('UPDATE faculty SET total_duties=total_duties+1 WHERE faculty_id=' . (int)$pickedFaculty);
                $busyFaculty[$pickedFaculty] = true;
                $usedFacultyThisExam[$pickedFaculty] = true;
                $assigned++;
            }
        }
    }

    $insert->close();

    $message = 'Assigned ' . $assigned . ' duty slot(s).';
    if($skippedRooms > 0){
        $message .= ' Skipped ' . $skippedRooms . ' room(s) due to same-slot room/faculty conflicts.';
    }
    if($unfilledSlots > 0){
        $message .= ' Unfilled ' . $unfilledSlots . ' slot(s) due to insufficient available faculty.';
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'assigned' => $assigned,
        'skipped' => $skippedRooms,
        'unfilled' => $unfilledSlots,
        'exam_date' => $exam['date'],
        'exam_session' => $exam['session']
    ]);
    closeConnection($con);
    exit;
}

if($method === 'POST' && $act === 'update_duties'){
    if(!canManageInvigilation()){
        echo json_encode(['success' => false, 'message' => 'You do not have permission to edit duties']);
        closeConnection($con);
        exit;
    }

    $examId = (int)($body['exam_id'] ?? 0);
    $updates = $body['updates'] ?? [];

    if($examId <= 0 || !is_array($updates) || empty($updates)){
        echo json_encode(['success' => false, 'message' => 'Exam and duty updates are required']);
        closeConnection($con);
        exit;
    }

    $exam = fetchExam($con, $examId);
    if(!$exam){
        echo json_encode(['success' => false, 'message' => 'Selected exam not found']);
        closeConnection($con);
        exit;
    }

    $busyFaculty = getBusyFacultySetForSlot($con, $exam['date'], $exam['session'], $examId);

    $existingMap = [];
    $stmt = $con->prepare('SELECT duty_id, faculty_id, room_id, duty_type FROM invigilation_allocation WHERE exam_id=?');
    $stmt->bind_param('i', $examId);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()){
        $existingMap[(int)$row['duty_id']] = [
            'faculty_id' => (int)$row['faculty_id'],
            'room_id' => (int)$row['room_id'],
            'duty_type' => $row['duty_type']
        ];
    }
    $stmt->close();

    $seenFaculty = [];
    foreach($updates as $u){
        $dutyId = (int)($u['duty_id'] ?? 0);
        $facultyId = (int)($u['faculty_id'] ?? 0);
        if($dutyId <= 0 || $facultyId <= 0 || !isset($existingMap[$dutyId])){
            echo json_encode(['success' => false, 'message' => 'Invalid duty update payload']);
            closeConnection($con);
            exit;
        }

        if(isset($busyFaculty[$facultyId])){
            echo json_encode(['success' => false, 'message' => 'Faculty conflict: selected faculty already assigned in another exam at same date/shift']);
            closeConnection($con);
            exit;
        }

        if(isset($seenFaculty[$facultyId])){
            echo json_encode(['success' => false, 'message' => 'Faculty conflict: same faculty cannot be assigned to multiple rooms in the same exam slot']);
            closeConnection($con);
            exit;
        }
        $seenFaculty[$facultyId] = true;
    }

    $con->begin_transaction();
    try {
        $updStmt = $con->prepare('UPDATE invigilation_allocation SET faculty_id=?, duty_type=? WHERE duty_id=? AND exam_id=?');

        foreach($updates as $u){
            $dutyId = (int)$u['duty_id'];
            $newFaculty = (int)$u['faculty_id'];
            $newType = trim((string)($u['duty_type'] ?? 'Hall Invigilator'));
            if($newType === '') $newType = 'Hall Invigilator';

            $oldFaculty = (int)$existingMap[$dutyId]['faculty_id'];
            $updStmt->bind_param('isii', $newFaculty, $newType, $dutyId, $examId);
            $updStmt->execute();

            if($newFaculty !== $oldFaculty){
                $con->query('UPDATE faculty SET total_duties=GREATEST(total_duties-1,0) WHERE faculty_id=' . $oldFaculty);
                $con->query('UPDATE faculty SET total_duties=total_duties+1 WHERE faculty_id=' . $newFaculty);
            }
        }

        $updStmt->close();
        $con->commit();
        echo json_encode(['success' => true, 'message' => 'Invigilation duties updated successfully']);
    } catch (Throwable $e) {
        $con->rollback();
        echo json_encode(['success' => false, 'message' => 'Failed to update invigilation duties']);
    }

    closeConnection($con);
    exit;
}

if($method === 'POST' && $act === 'clear'){
    if(!canManageInvigilation()){
        echo json_encode(['success' => false, 'message' => 'You do not have permission to clear duties']);
        closeConnection($con);
        exit;
    }

    $examId = (int)($body['exam_id'] ?? 0);
    if($examId <= 0){
        echo json_encode(['success' => false, 'message' => 'Exam is required']);
        closeConnection($con);
        exit;
    }

    $facultyIds = [];
    $old = $con->prepare('SELECT faculty_id FROM invigilation_allocation WHERE exam_id=?');
    $old->bind_param('i', $examId);
    $old->execute();
    $res = $old->get_result();
    while($r = $res->fetch_assoc()) $facultyIds[] = (int)$r['faculty_id'];
    $old->close();

    $del = $con->prepare('DELETE FROM invigilation_allocation WHERE exam_id=?');
    $del->bind_param('i', $examId);
    $del->execute();
    $affected = $del->affected_rows;
    $del->close();

    foreach($facultyIds as $fid){
        $con->query('UPDATE faculty SET total_duties=GREATEST(total_duties-1,0) WHERE faculty_id=' . (int)$fid);
    }

    echo json_encode(['success' => true, 'message' => 'Cleared ' . (int)$affected . ' duty record(s)']);
    closeConnection($con);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
closeConnection($con);

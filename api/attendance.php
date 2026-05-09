<?php
ini_set('display_errors',0);
ob_start();
session_start();
ob_clean();

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if(!isset($_SESSION['logged_in'])){
    echo json_encode(['success'=>false, 'message'=>'Not logged in']);
    exit;
}

$con    = getConnection();
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$method = $_SERVER['REQUEST_METHOD'];
$act    = $body['action'] ?? $_GET['action'] ?? '';
$role = $_SESSION['role'] ?? '';
$referenceId = (int)($_SESSION['reference_id'] ?? 0);

function deny($message){
    echo json_encode(['success'=>false, 'message'=>$message]);
    exit;
}

function isAdmin($role){
    return $role === 'admin';
}

function isFaculty($role, $referenceId){
    return $role === 'faculty' && $referenceId > 0;
}

function facultyHasDutyForExamRoom($con, $facultyId, $examId, $roomId){
    $st = $con->prepare('SELECT duty_id FROM invigilation_allocation WHERE faculty_id=? AND exam_id=? AND room_id=? LIMIT 1');
    $st->bind_param('iii', $facultyId, $examId, $roomId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (bool)$row;
}

function facultyHasDutyForExam($con, $facultyId, $examId){
    $st = $con->prepare('SELECT duty_id FROM invigilation_allocation WHERE faculty_id=? AND exam_id=? LIMIT 1');
    $st->bind_param('ii', $facultyId, $examId);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (bool)$row;
}

if($method == 'GET' && $act == 'get_sheet'){
    $examId = (int)($_GET['exam_id'] ?? 0);
    $roomId = (int)($_GET['room_id'] ?? 0);

    if(!isAdmin($role) && !isFaculty($role, $referenceId)){
        deny('Only admin/faculty can view attendance sheets');
    }
    if(isFaculty($role, $referenceId) && !facultyHasDutyForExamRoom($con, $referenceId, $examId, $roomId)){
        deny('You can only access sheets for your assigned exam-room duties');
    }

    $rows = [];
    $q = $con->query("SELECT sa.allocation_id, sa.seat_no, s.student_id,
                             s.roll_no, s.name, s.branch,
                             s.semester, s.section,
                             COALESCE(a.status,'Absent') as status
                      FROM seating_allocation sa
                      JOIN students s ON sa.student_id = s.student_id
                      LEFT JOIN (
                          SELECT allocation_id, MAX(attendance_id) AS latest_id
                          FROM attendance
                          GROUP BY allocation_id
                      ) la ON la.allocation_id = sa.allocation_id
                      LEFT JOIN attendance a ON a.attendance_id = la.latest_id
                      WHERE sa.exam_id=$examId AND sa.room_id=$roomId
                      ORDER BY sa.seat_no ASC");
    while($row = $q->fetch_assoc()) $rows[] = $row;
    echo json_encode(['success'=>true, 'data'=>$rows]);
    exit;
}

if($method == 'GET' && $act == 'get_rooms'){
    $examId = (int)($_GET['exam_id'] ?? 0);

    if(!isAdmin($role) && !isFaculty($role, $referenceId)){
        deny('Only admin/faculty can view attendance rooms');
    }
    if(isFaculty($role, $referenceId) && !facultyHasDutyForExam($con, $referenceId, $examId)){
        deny('You can only access rooms for your assigned exam duties');
    }

    $rooms = [];
    if(isFaculty($role, $referenceId)){
        $st = $con->prepare("SELECT DISTINCT r.room_id, r.room_no, r.building
                             FROM invigilation_allocation ia
                             JOIN rooms r ON ia.room_id = r.room_id
                             WHERE ia.exam_id=? AND ia.faculty_id=?
                             ORDER BY r.room_no ASC");
        $st->bind_param('ii', $examId, $referenceId);
        $st->execute();
        $q = $st->get_result();
        while($row = $q->fetch_assoc()) $rooms[] = $row;
        $st->close();
    } else {
        $q = $con->query("SELECT DISTINCT r.room_id, r.room_no, r.building
                          FROM seating_allocation sa
                          JOIN rooms r ON sa.room_id = r.room_id
                          WHERE sa.exam_id=$examId
                          ORDER BY r.room_no ASC");
        while($row = $q->fetch_assoc()) $rooms[] = $row;
    }

    echo json_encode(['success'=>true, 'data'=>$rooms]);
    exit;
}

if($method == 'POST' && $act == 'mark'){
    $examId = (int)($body['exam_id'] ?? 0);
    $roomId = (int)($body['room_id'] ?? 0);

    if(!isAdmin($role) && !isFaculty($role, $referenceId)){
        deny('Only admin/faculty can mark attendance');
    }
    if(isFaculty($role, $referenceId) && !facultyHasDutyForExamRoom($con, $referenceId, $examId, $roomId)){
        deny('You can only mark attendance for your assigned exam-room duties');
    }

    $records = $body['records'] ?? [];
    $saved   = 0;
    $updateStmt = $con->prepare("UPDATE attendance SET status=? WHERE allocation_id=?");
    $insertStmt = $con->prepare("INSERT INTO attendance (allocation_id,status) VALUES(?,?)");

    if(!$updateStmt || !$insertStmt){
        echo json_encode(['success'=>false, 'message'=>'Unable to prepare attendance statements']);
        exit;
    }

    foreach($records as $rec){
        $allocId = (int)$rec['allocation_id'];
        $status = in_array(($rec['status'] ?? ''), ['Present','Late','Absent'], true) ? $rec['status'] : 'Absent';

        if($allocId <= 0) continue;

        $updateStmt->bind_param('si', $status, $allocId);
        $updateStmt->execute();

        if($updateStmt->affected_rows === 0){
            $insertStmt->bind_param('is', $allocId, $status);
            $insertStmt->execute();
        }

        $saved++;
    }

    $updateStmt->close();
    $insertStmt->close();
    echo json_encode(['success'=>true, 'message'=>"$saved records saved"]);
    exit;
}

if($method == 'GET' && $act == 'list_exams'){
    if(!isAdmin($role) && !isFaculty($role, $referenceId)){
        deny('Only admin/faculty can access attendance exams');
    }

    $exams = [];
    if(isFaculty($role, $referenceId)){
        $st = $con->prepare("SELECT DISTINCT e.exam_id, e.exam_name, e.date, e.session
                             FROM invigilation_allocation ia
                             JOIN exams e ON ia.exam_id = e.exam_id
                             WHERE ia.faculty_id = ?
                             ORDER BY e.date DESC, e.exam_id DESC");
        $st->bind_param('i', $referenceId);
        $st->execute();
        $q = $st->get_result();
        while($row = $q->fetch_assoc()) $exams[] = $row;
        $st->close();
    } else {
        $q = $con->query("SELECT DISTINCT e.exam_id, e.exam_name, e.date, e.session
                          FROM exams e
                          JOIN seating_allocation sa ON sa.exam_id = e.exam_id
                          ORDER BY e.date DESC, e.exam_id DESC");
        while($row = $q->fetch_assoc()) $exams[] = $row;
    }

    echo json_encode(['success'=>true, 'data'=>$exams]);
    exit;
}

echo json_encode(['success'=>false, 'message'=>'Invalid action']);
closeConnection($con);

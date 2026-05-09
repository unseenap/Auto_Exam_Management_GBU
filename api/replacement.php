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

function ensureReplacementRequestsTable($con){
    $sql = "CREATE TABLE IF NOT EXISTS replacement_requests (
        request_id INT AUTO_INCREMENT PRIMARY KEY,
        original_faculty_id INT NOT NULL,
        replacement_faculty_id INT DEFAULT NULL,
        exam_id INT NOT NULL,
        room_id INT NOT NULL,
        reason TEXT NOT NULL,
        status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        processed_at TIMESTAMP NULL,
        FOREIGN KEY (original_faculty_id) REFERENCES faculty(faculty_id) ON DELETE CASCADE,
        FOREIGN KEY (replacement_faculty_id) REFERENCES faculty(faculty_id) ON DELETE SET NULL,
        FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
        FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE,
        INDEX idx_req_status (status),
        INDEX idx_req_exam_room (exam_id, room_id),
        INDEX idx_req_faculty (original_faculty_id, replacement_faculty_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    if(!$con->query($sql)){
        respond(['success' => false, 'message' => 'Table setup failed: '.$con->error]);
    }
}

if(!isset($_SESSION['logged_in'])){
    respond(['success' => false, 'message' => 'Not logged in']);
}

$con = getConnection();
ensureReplacementRequestsTable($con);

$role = $_SESSION['role'] ?? '';
$referenceId = (int)($_SESSION['reference_id'] ?? 0);
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$method = $_SERVER['REQUEST_METHOD'];
$act = $body['action'] ?? $_GET['action'] ?? '';

if($method === 'GET' && $act === 'list_exams'){
    $rows = [];
    $q = $con->query(
        "SELECT DISTINCT e.exam_id, e.exam_name, e.date, e.session,
                COUNT(DISTINCT ia.room_id) AS room_count,
                COUNT(DISTINCT ia.faculty_id) AS faculty_count
         FROM exams e
         LEFT JOIN invigilation_allocation ia ON ia.exam_id = e.exam_id
         GROUP BY e.exam_id, e.exam_name, e.date, e.session
         ORDER BY e.date DESC, e.exam_id DESC"
    );
    while($r = $q->fetch_assoc()) $rows[] = $r;
    respond(['success' => true, 'data' => $rows]);
}

if($method === 'GET' && $act === 'list_rooms'){
    $examId = (int)($_GET['exam_id'] ?? 0);
    $rows = [];
    if($examId > 0){
        $stmt = $con->prepare(
            "SELECT DISTINCT r.room_id, r.room_no, r.building, r.capacity
             FROM invigilation_allocation ia
             JOIN rooms r ON r.room_id = ia.room_id
             WHERE ia.exam_id = ?
             ORDER BY r.building ASC, r.room_no ASC"
        );
        $stmt->bind_param('i', $examId);
        $stmt->execute();
        $res = $stmt->get_result();
        while($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
    } else {
        $q = $con->query("SELECT room_id, room_no, building, capacity FROM rooms ORDER BY building ASC, room_no ASC");
        while($r = $q->fetch_assoc()) $rows[] = $r;
    }
    respond(['success' => true, 'data' => $rows]);
}

if($method === 'GET' && $act === 'faculty_list'){
    $rows = [];
    $q = $con->query("SELECT faculty_id, name, department, COALESCE(total_duties, 0) AS total_duties FROM faculty ORDER BY COALESCE(total_duties, 0) ASC, name ASC");
    while($r = $q->fetch_assoc()) $rows[] = $r;
    respond(['success' => true, 'data' => $rows]);
}

if($method === 'GET' && $act === 'my_duties'){
    if($role !== 'faculty' || $referenceId <= 0){
        respond(['success' => false, 'message' => 'Only faculty can access this action']);
    }

    $rows = [];
    $stmt = $con->prepare(
        "SELECT ia.exam_id, ia.room_id, e.exam_name, e.date, e.session, r.room_no, r.building
         FROM invigilation_allocation ia
         JOIN exams e ON ia.exam_id = e.exam_id
         JOIN rooms r ON ia.room_id = r.room_id
         WHERE ia.faculty_id = ?
         ORDER BY e.date DESC, r.room_no ASC"
    );
    $stmt->bind_param('i', $referenceId);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();

    respond(['success' => true, 'data' => $rows]);
}

if($method === 'GET' && $act === 'available_faculty'){
    $examId = (int)($_GET['exam_id'] ?? 0);
    if($examId <= 0){
        respond(['success' => false, 'message' => 'exam_id is required']);
    }

    $rows = [];
    $stmt = $con->prepare(
        "SELECT f.faculty_id, f.name, f.department, COALESCE(f.total_duties, 0) AS total_duties
         FROM faculty f
         WHERE f.faculty_id NOT IN (
            SELECT ia.faculty_id FROM invigilation_allocation ia WHERE ia.exam_id = ?
         )
         ORDER BY COALESCE(f.total_duties, 0) ASC, f.name ASC"
    );
    $stmt->bind_param('i', $examId);
    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();

    respond(['success' => true, 'data' => $rows]);
}

if($method === 'GET' && $act === 'list'){
    $rows = [];
    $base =
        "SELECT rr.request_id AS log_id, rr.request_id, rr.exam_id, rr.room_id, rr.original_faculty_id, rr.replacement_faculty_id,
                rr.reason, rr.status, rr.requested_at, rr.processed_at,
                e.exam_name, e.date, e.session,
                r.room_no, r.building,
                fo.name AS original_faculty_name,
            fr.name AS replacement_faculty_name,
            fo.department AS original_department,
            fr.department AS replacement_department
         FROM replacement_requests rr
         JOIN exams e ON rr.exam_id = e.exam_id
         JOIN rooms r ON rr.room_id = r.room_id
         JOIN faculty fo ON rr.original_faculty_id = fo.faculty_id
         LEFT JOIN faculty fr ON rr.replacement_faculty_id = fr.faculty_id";

    if($role === 'faculty'){
        $stmt = $con->prepare($base." WHERE rr.original_faculty_id = ? OR rr.replacement_faculty_id = ? ORDER BY rr.requested_at DESC");
        $stmt->bind_param('ii', $referenceId, $referenceId);
    } else {
        $stmt = $con->prepare($base." ORDER BY rr.requested_at DESC");
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();

    respond(['success' => true, 'data' => $rows]);
}

if($method === 'POST' && $act === 'request'){
    if($role !== 'faculty' || $referenceId <= 0){
        respond(['success' => false, 'message' => 'Only faculty can request replacement']);
    }

    $examId = (int)($body['exam_id'] ?? 0);
    $roomId = (int)($body['room_id'] ?? 0);
    $reason = trim((string)($body['reason'] ?? ''));

    if($examId <= 0 || $roomId <= 0 || $reason === ''){
        respond(['success' => false, 'message' => 'exam, room and reason are required']);
    }

    $check = $con->prepare(
        "SELECT duty_id FROM invigilation_allocation
         WHERE exam_id = ? AND room_id = ? AND faculty_id = ?
         LIMIT 1"
    );
    $check->bind_param('iii', $examId, $roomId, $referenceId);
    $check->execute();
    $found = $check->get_result()->fetch_assoc();
    $check->close();

    if(!$found){
        respond(['success' => false, 'message' => 'You are not assigned to this exam-room duty']);
    }

    $ins = $con->prepare(
        "INSERT INTO replacement_requests (original_faculty_id, replacement_faculty_id, exam_id, room_id, reason, status)
         VALUES (?, NULL, ?, ?, ?, 'Pending')"
    );
    $ins->bind_param('iiis', $referenceId, $examId, $roomId, $reason);
    $ok = $ins->execute();
    $msg = $ok ? 'Replacement request submitted' : $ins->error;
    $ins->close();

    respond($ok ? ['success' => true, 'message' => $msg] : ['success' => false, 'message' => $msg]);
}

if($method === 'POST' && $act === 'process'){
    if($role !== 'admin'){
        respond(['success' => false, 'message' => 'Not authorized']);
    }

    $requestId = (int)($body['request_id'] ?? ($body['log_id'] ?? 0));
    $status = trim((string)($body['status'] ?? ''));
    $manualReplacementId = (int)($body['replacement_faculty_id'] ?? 0);

    if($requestId <= 0 || !in_array($status, ['Approved', 'Rejected'], true)){
        respond(['success' => false, 'message' => 'Invalid process payload']);
    }

    $reqStmt = $con->prepare(
        "SELECT request_id, original_faculty_id, exam_id, room_id, status
         FROM replacement_requests
         WHERE request_id = ?
         LIMIT 1"
    );
    $reqStmt->bind_param('i', $requestId);
    $reqStmt->execute();
    $req = $reqStmt->get_result()->fetch_assoc();
    $reqStmt->close();

    if(!$req){
        respond(['success' => false, 'message' => 'Request not found']);
    }
    if($req['status'] !== 'Pending'){
        respond(['success' => false, 'message' => 'Only pending requests can be processed']);
    }

    if($status === 'Rejected'){
        $up = $con->prepare("UPDATE replacement_requests SET status='Rejected', processed_at=NOW() WHERE request_id=?");
        $up->bind_param('i', $requestId);
        $ok = $up->execute();
        $up->close();
        respond($ok ? ['success' => true, 'message' => 'Request rejected'] : ['success' => false, 'message' => 'Failed to reject request']);
    }

    $replacementId = $manualReplacementId;
    if($replacementId <= 0){
        $auto = $con->prepare(
            "SELECT f.faculty_id
             FROM faculty f
             WHERE f.faculty_id NOT IN (
                SELECT ia.faculty_id FROM invigilation_allocation ia WHERE ia.exam_id = ?
             )
             ORDER BY COALESCE(f.total_duties, 0) ASC, f.name ASC
             LIMIT 1"
        );
        $auto->bind_param('i', $req['exam_id']);
        $auto->execute();
        $autoRow = $auto->get_result()->fetch_assoc();
        $auto->close();
        $replacementId = (int)($autoRow['faculty_id'] ?? 0);
    }

    if($replacementId <= 0){
        respond(['success' => false, 'message' => 'No available replacement faculty found']);
    }

    $avail = $con->prepare(
        "SELECT duty_id FROM invigilation_allocation WHERE exam_id = ? AND faculty_id = ? LIMIT 1"
    );
    $avail->bind_param('ii', $req['exam_id'], $replacementId);
    $avail->execute();
    $busy = $avail->get_result()->fetch_assoc();
    $avail->close();

    if($busy){
        respond(['success' => false, 'message' => 'Selected replacement faculty is not available for this exam']);
    }

    $con->begin_transaction();
    try {
        $upReq = $con->prepare(
            "UPDATE replacement_requests
             SET replacement_faculty_id = ?, status = 'Approved', processed_at = NOW()
             WHERE request_id = ?"
        );
        $upReq->bind_param('ii', $replacementId, $requestId);
        if(!$upReq->execute()){
            throw new Exception($upReq->error);
        }
        $upReq->close();

        $upDuty = $con->prepare(
            "UPDATE invigilation_allocation
             SET faculty_id = ?
             WHERE exam_id = ? AND room_id = ? AND faculty_id = ?"
        );
        $upDuty->bind_param('iiii', $replacementId, $req['exam_id'], $req['room_id'], $req['original_faculty_id']);
        if(!$upDuty->execute()){
            throw new Exception($upDuty->error);
        }
        $updatedRows = $upDuty->affected_rows;
        $upDuty->close();

        if($updatedRows <= 0){
            throw new Exception('Original invigilation duty not found for replacement');
        }

        $con->query("UPDATE faculty SET total_duties = GREATEST(total_duties - 1, 0) WHERE faculty_id = ".(int)$req['original_faculty_id']);
        $con->query("UPDATE faculty SET total_duties = total_duties + 1 WHERE faculty_id = ".(int)$replacementId);

        $con->commit();
        respond(['success' => true, 'message' => 'Request approved and replacement assigned']);
    } catch (Throwable $e) {
        $con->rollback();
        respond(['success' => false, 'message' => 'Approval failed: '.$e->getMessage()]);
    }
}

respond(['success' => false, 'message' => 'Invalid action']);

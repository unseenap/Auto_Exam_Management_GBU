<?php
ini_set('display_errors',0);
ob_start();
session_start();
ob_clean();

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if(!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'faculty'){
    echo json_encode(['success'=>false, 'message'=>'Access denied']);
    exit;
}

$con = getConnection();
$facultyId = (int)($_SESSION['reference_id'] ?? 0);
$act = $_GET['action'] ?? '';

if($act === 'summary'){
    if($facultyId > 0){
        $st = $con->prepare('SELECT faculty_id, name, department, designation, email, mobile, COALESCE(total_duties,0) AS total_duties FROM faculty WHERE faculty_id = ? LIMIT 1');
        $st->bind_param('i', $facultyId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if($row){
            echo json_encode(['success'=>true, 'data'=>$row]);
            closeConnection($con);
            exit;
        }
    }

    // Fallback: return minimal info from session when faculty profile not linked
    $fallback = [
        'faculty_id' => 0,
        'name' => $_SESSION['username'] ?? '',
        'department' => '',
        'designation' => '',
        'email' => '',
        'mobile' => '',
        'total_duties' => 0
    ];
    echo json_encode(['success'=>true, 'data'=>$fallback]);
    closeConnection($con);
    exit;
}

if($act === 'duties'){
    $rows = [];
    if($facultyId > 0){
        $st = $con->prepare(
            "SELECT ia.duty_id, ia.exam_id, ia.room_id, ia.duty_type,
                    e.exam_name, e.date, e.session,
                    r.room_no, r.building, r.capacity
             FROM invigilation_allocation ia
             JOIN exams e ON ia.exam_id = e.exam_id
             JOIN rooms r ON ia.room_id = r.room_id
             WHERE ia.faculty_id = ?
             ORDER BY e.date DESC, e.exam_id DESC, r.room_no ASC"
        );
        $st->bind_param('i', $facultyId);
        $st->execute();
        $res = $st->get_result();
        while($r = $res->fetch_assoc()) $rows[] = $r;
        $st->close();
    }

    // Return empty array if unlinked
    echo json_encode(['success'=>true, 'data'=>$rows]);
    closeConnection($con);
    exit;
}

if($act === 'attendance_sheet'){
    $examId = (int)($_GET['exam_id'] ?? 0);
    $roomId = (int)($_GET['room_id'] ?? 0);

    if($examId <= 0 || $roomId <= 0){
        echo json_encode(['success'=>false, 'message'=>'exam_id and room_id are required']);
        closeConnection($con);
        exit;
    }

    $own = $con->prepare('SELECT duty_id FROM invigilation_allocation WHERE faculty_id = ? AND exam_id = ? AND room_id = ? LIMIT 1');
    $own->bind_param('iii', $facultyId, $examId, $roomId);
    $own->execute();
    $ownRow = $own->get_result()->fetch_assoc();
    $own->close();

    if(!$ownRow){
        echo json_encode(['success'=>false, 'message'=>'You can only access attendance sheet for your assigned duty']);
        closeConnection($con);
        exit;
    }

    $rows = [];
    $q = $con->query("SELECT sa.allocation_id, sa.seat_no, s.roll_no, s.name, s.branch, s.semester, s.section,
                             COALESCE(a.status, 'Absent') AS status
                      FROM seating_allocation sa
                      JOIN students s ON sa.student_id = s.student_id
                      LEFT JOIN (
                          SELECT allocation_id, MAX(attendance_id) AS latest_id
                          FROM attendance
                          GROUP BY allocation_id
                      ) la ON la.allocation_id = sa.allocation_id
                      LEFT JOIN attendance a ON a.attendance_id = la.latest_id
                      WHERE sa.exam_id = $examId AND sa.room_id = $roomId
                      ORDER BY sa.seat_no ASC");

    while($row = $q->fetch_assoc()) $rows[] = $row;

    echo json_encode(['success'=>true, 'data'=>$rows]);
    closeConnection($con);
    exit;
}

// GET profile details
if($act === 'get_profile'){
    if($facultyId > 0){
        $st = $con->prepare('SELECT faculty_id, name, department, designation, email, mobile, qualification FROM faculty WHERE faculty_id = ? LIMIT 1');
        $st->bind_param('i', $facultyId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if($row){
            $username = $_SESSION['username'] ?? '';
            $row['username'] = $username;
            echo json_encode(['success'=>true, 'data'=>$row]);
            closeConnection($con);
            exit;
        }
    }

    // Fallback when no linked faculty record exists
    $fallback = [
        'faculty_id' => 0,
        'name' => $_SESSION['username'] ?? '',
        'department' => '',
        'designation' => '',
        'email' => '',
        'mobile' => '',
        'qualification' => '',
        'username' => $_SESSION['username'] ?? ''
    ];
    echo json_encode(['success'=>true, 'data'=>$fallback]);
    closeConnection($con);
    exit;
}

// POST update profile
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $postAct = $body['action'] ?? '';

    if($postAct === 'update_profile'){
        $name = trim((string)($body['name'] ?? ''));
        $email = trim((string)($body['email'] ?? ''));
        $mobile = trim((string)($body['mobile'] ?? ''));
        $qualification = trim((string)($body['qualification'] ?? ''));

        if($name === '' || $email === ''){
            echo json_encode(['success'=>false, 'message'=>'Name and email are required']);
            closeConnection($con);
            exit;
        }

        if($facultyId > 0){
            $st = $con->prepare('UPDATE faculty SET name=?, email=?, mobile=?, qualification=? WHERE faculty_id=?');
            $st->bind_param('ssssi', $name, $email, $mobile, $qualification, $facultyId);
            $ok = $st->execute();
            $st->close();

            if($ok){
                echo json_encode(['success'=>true, 'message'=>'Profile updated successfully']);
            } else {
                echo json_encode(['success'=>false, 'message'=>'Failed to update profile: '.$con->error]);
            }
            closeConnection($con);
            exit;
        }

        // If faculty not linked yet, create a new faculty record and link the user
        $defaultDept = 'Computer Science and Engineering';
        $stmt = $con->prepare('INSERT INTO faculty (name, department, designation, email, mobile, qualification) VALUES (?, ?, ?, ?, ?, ?)');
        $designation = '';
        $stmt->bind_param('ssssss', $name, $defaultDept, $designation, $email, $mobile, $qualification);
        $ok = $stmt->execute();
        if($ok){
            $newId = (int)$con->insert_id;
            // Link to users table
            $userId = (int)($_SESSION['user_id'] ?? 0);
            if($userId > 0){
                $u = $con->prepare('UPDATE users SET reference_id=? WHERE user_id=?');
                $u->bind_param('ii', $newId, $userId);
                $u->execute();
                $u->close();
                // update session
                $_SESSION['reference_id'] = $newId;
            }
            echo json_encode(['success'=>true, 'message'=>'Profile created and linked successfully']);
        } else {
            echo json_encode(['success'=>false, 'message'=>'Failed to create profile: '.$con->error]);
        }
        closeConnection($con);
        exit;
    }

    if($postAct === 'change_password'){
        $oldPassword = (string)($body['old_password'] ?? '');
        $newPassword = (string)($body['new_password'] ?? '');
        $confirmPassword = (string)($body['confirm_password'] ?? '');

        if($oldPassword === '' || $newPassword === '' || $confirmPassword === ''){
            echo json_encode(['success'=>false, 'message'=>'All password fields are required']);
            closeConnection($con);
            exit;
        }

        if($newPassword !== $confirmPassword){
            echo json_encode(['success'=>false, 'message'=>'New passwords do not match']);
            closeConnection($con);
            exit;
        }

        if(strlen($newPassword) < 6){
            echo json_encode(['success'=>false, 'message'=>'Password must be at least 6 characters']);
            closeConnection($con);
            exit;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $st = $con->prepare('SELECT password FROM users WHERE user_id=? LIMIT 1');
        $st->bind_param('i', $userId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if(!$row || !password_verify($oldPassword, $row['password'])){
            echo json_encode(['success'=>false, 'message'=>'Current password is incorrect']);
            closeConnection($con);
            exit;
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $con->prepare('UPDATE users SET password=? WHERE user_id=?');
        $stmt->bind_param('si', $hashedPassword, $userId);
        $ok = $stmt->execute();
        $stmt->close();

        if($ok){
            echo json_encode(['success'=>true, 'message'=>'Password changed successfully']);
        } else {
            echo json_encode(['success'=>false, 'message'=>'Failed to change password']);
        }
        closeConnection($con);
        exit;
    }
}

echo json_encode(['success'=>false, 'message'=>'Invalid action']);
closeConnection($con);

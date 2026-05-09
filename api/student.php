<?php
ini_set('display_errors',0);
ob_start();
session_start();
ob_clean();

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if(!isset($_SESSION['logged_in']) || $_SESSION['role'] != 'student'){
    echo json_encode(['success'=>false, 'message'=>'Access denied']);
    exit;
}

$con = getConnection();
$uid = (int)$_SESSION['reference_id'];
$act = $_GET['action'] ?? '';

function respondAndClose($con, $payload){
    echo json_encode($payload);
    closeConnection($con);
    exit;
}

function getStudentProfile($con, $uid){
    $stmt = $con->prepare(
        'SELECT student_id, roll_no, enrollment_no, enrollment_number, username, school, department, father_name, mobile, address, name, branch, branch_code, admission_year, course_duration_years, program_code, serial_no, semester, section
         FROM students
         WHERE student_id = ?
         LIMIT 1'
    );
    if($stmt === false){
        return null;
    }
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$student){
        return null;
    }

    $admissionYear = (int)($student['admission_year'] ?? 0);
    $duration = (int)($student['course_duration_years'] ?? 0);
    $endYear = $admissionYear > 0 ? ($admissionYear + max(0, $duration - 1)) : 0;
    $student['year_label'] = 'Year '.max(1, (int)ceil(((int)($student['semester'] ?? 0)) / 2));
    $student['session_label'] = $admissionYear > 0 ? ($endYear > 0 ? ($admissionYear.'-'.$endYear) : (string)$admissionYear) : '';
    $student['batch_label'] = $student['session_label'];
    return $student;
}

function buildStudentUsername($enrollmentNo){
    $enrollmentNo = strtoupper(trim((string)$enrollmentNo));
    if($enrollmentNo === '') return '';
    $enrollmentNo = preg_replace('/@.*$/', '', $enrollmentNo);
    return $enrollmentNo . '@gbu.ac.in';
}

function syncStudentUsername($con, $studentId, $username){
    $check = $con->prepare('SELECT user_id FROM users WHERE username=? AND (role<>"student" OR reference_id<>?) LIMIT 1');
    if($check === false){
        return false;
    }
    $check->bind_param('si', $username, $studentId);
    $check->execute();
    $conflict = $check->get_result()->fetch_assoc();
    $check->close();
    if($conflict){
        return false;
    }

    $stmt = $con->prepare('UPDATE users SET username=? WHERE role="student" AND reference_id=?');
    if($stmt === false){
        return false;
    }
    $stmt->bind_param('si', $username, $studentId);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function getStudentFilters($student){
    $school = trim((string)($student['school'] ?? 'SOICT'));
    $department = trim((string)($student['department'] ?? ''));
    $branchCode = strtoupper(trim((string)($student['branch_code'] ?? '')));
    if($branchCode === ''){
        $branchCode = strtoupper(trim((string)($student['program_code'] ?? '')));
    }
    $semester = (int)($student['semester'] ?? 0);
    $section = trim((string)($student['section'] ?? ''));

    return [$school, $department, $branchCode, $semester, $section];
}

function fetchStudentScheduleMatrix($con, $student){
    [$school, $department, $branchCode, $semester, $section] = getStudentFilters($student);
    if($school === '' || $branchCode === '' || $semester <= 0){
        return [];
    }

    $rows = [];
    $sql =
        "SELECT m.matrix_id,
                COALESCE(e.exam_name, CONCAT(UPPER(m.exam_type), ' Exam')) AS exam_name,
                m.exam_type,
                m.exam_date,
                m.shift,
                m.branch_code,
                m.semester,
                m.branch_session,
                m.subject_code,
                m.subject_name,
                COALESCE(m.start_time, es.start_time) AS start_time,
                COALESCE(m.end_time, es.end_time) AS end_time,
                COALESCE(m.notes, es.subject_name) AS notes,
                m.duration_minutes
         FROM exam_schedule_matrix m
         LEFT JOIN exams e ON e.exam_id = m.exam_id
         LEFT JOIN exam_schedule es ON es.exam_id = m.exam_id AND es.exam_date = m.exam_date AND es.session = m.shift
         WHERE m.school=? AND m.branch_code=? AND m.semester=?";

    if($section !== ''){
        $sql .= " AND (m.branch_session='' OR m.branch_session=? )";
    }

    $sql .= " ORDER BY m.exam_date ASC, FIELD(m.shift, 'Morning', 'Afternoon', 'Evening'), m.exam_type ASC";

    $stmt = $con->prepare($sql);
    if($stmt === false){
        return [];
    }

    if($section !== ''){
        $stmt->bind_param('ssis', $school, $branchCode, $semester, $section);
    } else {
        $stmt->bind_param('ssi', $school, $branchCode, $semester);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()){
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function fetchSchoolScheduleMatrix($con, $student){
    $school = trim((string)($student['school'] ?? 'SOICT'));
    if($school === ''){
        return [];
    }

    $rows = [];
    $sql =
        "SELECT m.matrix_id,
                COALESCE(e.exam_name, CONCAT(UPPER(m.exam_type), ' Exam')) AS exam_name,
                m.exam_type,
                m.exam_date,
                m.shift,
                m.branch_code,
                m.semester,
                m.branch_session,
                m.subject_code,
                m.subject_name,
                COALESCE(m.start_time, es.start_time) AS start_time,
                COALESCE(m.end_time, es.end_time) AS end_time,
                COALESCE(m.notes, es.subject_name) AS notes,
                m.duration_minutes
         FROM exam_schedule_matrix m
         LEFT JOIN exams e ON e.exam_id = m.exam_id
         LEFT JOIN exam_schedule es ON es.exam_id = m.exam_id AND es.exam_date = m.exam_date AND es.session = m.shift
         WHERE m.school=?
         ORDER BY m.branch_code ASC, m.semester ASC, m.branch_session ASC, m.exam_date ASC, FIELD(m.shift, 'Morning', 'Afternoon', 'Evening'), m.exam_type ASC";

    $stmt = $con->prepare($sql);
    if($stmt === false){
        return [];
    }

    $stmt->bind_param('s', $school);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()){
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function compareMatrixRows($left, $right){
    $leftBranch = strtoupper(trim((string)($left['branch_code'] ?? '')));
    $rightBranch = strtoupper(trim((string)($right['branch_code'] ?? '')));
    if($leftBranch !== $rightBranch){
        return strcmp($leftBranch, $rightBranch);
    }

    $leftSemester = (int)($left['semester'] ?? 0);
    $rightSemester = (int)($right['semester'] ?? 0);
    if($leftSemester !== $rightSemester){
        return $leftSemester <=> $rightSemester;
    }

    $leftSession = strtoupper(trim((string)($left['branch_session'] ?? '')));
    $rightSession = strtoupper(trim((string)($right['branch_session'] ?? '')));
    if($leftSession !== $rightSession){
        return strcmp($leftSession, $rightSession);
    }

    $shiftOrder = ['Morning' => 0, 'Afternoon' => 1, 'Evening' => 2];
    $leftShift = $shiftOrder[$left['shift'] ?? ''] ?? 99;
    $rightShift = $shiftOrder[$right['shift'] ?? ''] ?? 99;
    if($leftShift !== $rightShift){
        return $leftShift <=> $rightShift;
    }

    $leftDate = (string)($left['exam_date'] ?? '');
    $rightDate = (string)($right['exam_date'] ?? '');
    return strcmp($leftDate, $rightDate);
}

function buildStudentSchoolMatrices(array $scheduleRows){
    $groups = [];

    foreach($scheduleRows as $row){
        $examType = trim((string)($row['exam_type'] ?? ''));
        $examDate = trim((string)($row['exam_date'] ?? ''));
        if($examType === '' || $examDate === ''){
            continue;
        }

        if(!isset($groups[$examType])){
            $groups[$examType] = [
                'dates' => [],
                'rows' => []
            ];
        }

        $groups[$examType]['dates'][$examDate] = true;
        $rowKey = strtoupper(trim((string)($row['branch_code'] ?? ''))) . '|' . (int)($row['semester'] ?? 0) . '|' . trim((string)($row['branch_session'] ?? '')) . '|' . trim((string)($row['shift'] ?? ''));

        if(!isset($groups[$examType]['rows'][$rowKey])){
            $groups[$examType]['rows'][$rowKey] = [
                'branch_code' => $row['branch_code'] ?? '',
                'semester' => (int)($row['semester'] ?? 0),
                'branch_session' => $row['branch_session'] ?? '',
                'shift' => $row['shift'] ?? '',
                'cells' => []
            ];
        }

        $groups[$examType]['rows'][$rowKey]['cells'][$examDate] = [
            'matrix_id' => (int)($row['matrix_id'] ?? 0),
            'subject_code' => $row['subject_code'] ?? '',
            'subject_name' => $row['subject_name'] ?? '',
            'start_time' => $row['start_time'] ?? '',
            'end_time' => $row['end_time'] ?? '',
            'notes' => $row['notes'] ?? '',
            'is_filler' => false
        ];
    }

    $matrices = [];
    foreach($groups as $examType => $group){
        $dates = array_keys($group['dates']);
        sort($dates);

        $rows = array_values($group['rows']);
        usort($rows, 'compareMatrixRows');

        foreach($rows as &$row){
            foreach($dates as $date){
                if(!isset($row['cells'][$date])){
                    $row['cells'][$date] = [
                        'matrix_id' => 0,
                        'subject_code' => '',
                        'subject_name' => '',
                        'start_time' => '',
                        'end_time' => '',
                        'notes' => 'Prep Leave',
                        'is_filler' => true
                    ];
                }
            }
        }
        unset($row);

        $matrices[] = [
            'exam_type' => $examType,
            'dates' => $dates,
            'rows' => $rows
        ];
    }

    return $matrices;
}

function findStudentScheduleMatch(array $scheduleRows, array $allocationRow, array $student){
    [$school, $department, $branchCode, $semester, $section] = getStudentFilters($student);
    $examDate = trim((string)($allocationRow['exam_date'] ?? $allocationRow['date'] ?? ''));
    $shift = trim((string)($allocationRow['session'] ?? $allocationRow['shift'] ?? ''));

    foreach($scheduleRows as $row){
        if(trim((string)($row['exam_date'] ?? '')) !== $examDate){
            continue;
        }
        if(trim((string)($row['shift'] ?? '')) !== $shift){
            continue;
        }
        if($branchCode !== '' && strtoupper(trim((string)($row['branch_code'] ?? ''))) !== strtoupper($branchCode)){
            continue;
        }
        if($semester > 0 && (int)($row['semester'] ?? 0) !== $semester){
            continue;
        }
        if($section !== ''){
            $rowSection = trim((string)($row['branch_session'] ?? ''));
            if($rowSection !== '' && strcasecmp($rowSection, $section) !== 0){
                continue;
            }
        }

        return $row;
    }

    return null;
}

function attachScheduleMeta(array &$rows, array $scheduleRows, array $student){
    foreach($rows as &$row){
        $match = findStudentScheduleMatch($scheduleRows, $row, $student);
        $row['subject_code'] = $match['subject_code'] ?? ($row['subject_code'] ?? '');
        $row['subject_name'] = $match['subject_name'] ?? ($row['subject_name'] ?? $row['exam_name'] ?? '');
        $row['start_time'] = $match['start_time'] ?? ($row['start_time'] ?? '');
        $row['end_time'] = $match['end_time'] ?? ($row['end_time'] ?? '');
        $row['matrix_notes'] = $match['notes'] ?? ($row['matrix_notes'] ?? '');
        $row['exam_type'] = $match['exam_type'] ?? ($row['exam_type'] ?? '');
    }
    unset($row);
}

function tableHasColumn($con, $tableName, $columnName){
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tableName);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$columnName);
    if($table === '' || $column === ''){
        return false;
    }

    $safeColumn = $con->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$table}` LIKE '{$safeColumn}'";
    $res = $con->query($sql);
    if($res === false){
        return false;
    }
    return $res->num_rows > 0;
}

if($act == 'info'){
    $student = getStudentProfile($con, $uid);
    respondAndClose($con, ['success'=>true, 'data'=>$student]);
}

if($act == 'get_profile'){
    $student = getStudentProfile($con, $uid);
    respondAndClose($con, ['success'=>true, 'data'=>$student]);
}

if($act == 'schedule'){
    $student = getStudentProfile($con, $uid);
    if(!$student){
        respondAndClose($con, ['success'=>false, 'message'=>'Student profile not found']);
    }

    $rows = fetchStudentScheduleMatrix($con, $student);
    respondAndClose($con, ['success'=>true, 'data'=>$rows, 'student'=>$student]);
}

if($act == 'school_matrix'){
    $student = getStudentProfile($con, $uid);
    if(!$student){
        respondAndClose($con, ['success'=>false, 'message'=>'Student profile not found']);
    }

    $scheduleRows = fetchSchoolScheduleMatrix($con, $student);
    $matrices = buildStudentSchoolMatrices($scheduleRows);
    respondAndClose($con, ['success'=>true, 'student'=>$student, 'matrices'=>$matrices]);
}

if($act == 'seating'){
    $rows = [];
    $student = getStudentProfile($con, $uid);
    $scheduleRows = $student ? fetchStudentScheduleMatrix($con, $student) : [];

    $hasExamScheduleSubjectCode = tableHasColumn($con, 'exam_schedule', 'subject_code');
    $hasRoomWhiteboardArea = tableHasColumn($con, 'rooms', 'whiteboard_area');
    $subjectCodeExpr = $hasExamScheduleSubjectCode ? "COALESCE(es.subject_code, '')" : "''";
    $whiteboardExpr = $hasRoomWhiteboardArea ? "COALESCE(r.whiteboard_area, 'front')" : "'front'";

    $stmt = $con->prepare("SELECT e.exam_id,
                             e.exam_name,
                             COALESCE(es.subject_name, e.exam_name) AS subject_name,
                             {$subjectCodeExpr} AS subject_code,
                             e.date AS exam_date,
                             e.session,
                             r.room_id, r.room_no, r.building, r.capacity, r.matrix_rows, r.matrix_cols, r.blocked_seats_json, {$whiteboardExpr} AS whiteboard_area,
                             sa.seat_no, sa.row_no,
                             s.roll_no, s.name, s.branch, s.semester
                      FROM seating_allocation sa
                      JOIN exams    e  ON sa.exam_id    = e.exam_id
                      LEFT JOIN exam_schedule es ON es.exam_id = e.exam_id AND es.exam_date = e.date AND es.session = e.session
                      JOIN rooms    r  ON sa.room_id    = r.room_id
                      JOIN students s  ON sa.student_id = s.student_id
                      WHERE sa.student_id=?
                      ORDER BY e.date ASC, e.exam_id ASC");
    if($stmt === false){
        respondAndClose($con, ['success'=>false, 'message'=>'Failed to load seating data: '.$con->error]);
    }
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $q = $stmt->get_result();
    while($row = $q->fetch_assoc()) $rows[] = $row;
    $stmt->close();

    if($student){
        attachScheduleMeta($rows, $scheduleRows, $student);
    }

    respondAndClose($con, ['success'=>true, 'data'=>$rows, 'student_id'=>$uid]);
}

if($act == 'attendance'){
    $rows = [];
    $stmt = $con->prepare("SELECT e.exam_id,
                                  e.exam_name,
                                  e.date AS exam_date,
                                  e.session,
                                  r.room_no,
                                  r.building,
                                  sa.seat_no,
                                  COALESCE(a.status, 'Absent') AS status,
                                  COALESCE(a.remarks, '') AS remarks
                           FROM seating_allocation sa
                           JOIN exams e ON sa.exam_id = e.exam_id
                           JOIN rooms r ON sa.room_id = r.room_id
                           LEFT JOIN (
                               SELECT at1.allocation_id, at1.status, at1.remarks
                               FROM attendance at1
                               JOIN (
                                   SELECT allocation_id, MAX(attendance_id) AS latest_id
                                   FROM attendance
                                   GROUP BY allocation_id
                               ) latest ON latest.latest_id = at1.attendance_id
                           ) a ON a.allocation_id = sa.allocation_id
                           WHERE sa.student_id = ?
                           ORDER BY e.date ASC, e.exam_id ASC");
    if($stmt === false){
        respondAndClose($con, ['success'=>false, 'message'=>'Failed to load attendance status: '.$con->error]);
    }
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $q = $stmt->get_result();
    while($row = $q->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    respondAndClose($con, ['success'=>true, 'data'=>$rows]);
}

if($act == 'admit_card'){
    $student = getStudentProfile($con, $uid);
    $scheduleRows = $student ? fetchStudentScheduleMatrix($con, $student) : [];

    $rows = [];

    $hasExamScheduleSubjectCode = tableHasColumn($con, 'exam_schedule', 'subject_code');
    $hasRoomWhiteboardArea = tableHasColumn($con, 'rooms', 'whiteboard_area');
    $subjectCodeExpr = $hasExamScheduleSubjectCode ? "COALESCE(es.subject_code, '')" : "''";
    $whiteboardExpr = $hasRoomWhiteboardArea ? "COALESCE(r.whiteboard_area, 'front')" : "'front'";

    $stmt = $con->prepare("SELECT e.exam_id,
                                  e.exam_name,
                                  COALESCE(es.subject_name, e.exam_name) AS subject_name,
                                  {$subjectCodeExpr} AS subject_code,
                                  e.date AS exam_date,
                                  e.session,
                                  r.room_no,
                                  r.building,
                                  {$whiteboardExpr} AS whiteboard_area,
                                  sa.seat_no,
                                  sa.row_no,
                                  s.roll_no,
                                  s.name,
                                  s.branch,
                                  s.semester
                           FROM seating_allocation sa
                           JOIN exams e ON sa.exam_id = e.exam_id
                           LEFT JOIN exam_schedule es ON es.exam_id = e.exam_id AND es.exam_date = e.date AND es.session = e.session
                           JOIN rooms r ON sa.room_id = r.room_id
                           JOIN students s ON sa.student_id = s.student_id
                           WHERE sa.student_id = ?
                           ORDER BY e.date ASC, e.exam_id ASC");
    if($stmt === false){
        respondAndClose($con, ['success'=>false, 'message'=>'Failed to load admit card data: '.$con->error]);
    }
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $q = $stmt->get_result();
    while($row = $q->fetch_assoc()) $rows[] = $row;
    $stmt->close();

    if($student){
        attachScheduleMeta($rows, $scheduleRows, $student);
    }

    respondAndClose($con, [
        'success' => true,
        'student' => $student,
        'entries' => $rows,
        'generated_at' => date('c')
    ]);
}

if($act == 'notifications'){
    $rows = [];
    $student = getStudentProfile($con, $uid);
    if($student){
        $scheduleRows = fetchStudentScheduleMatrix($con, $student);
        foreach(array_slice($scheduleRows, 0, 8) as $row){
            $rows[] = [
                'title' => 'Upcoming exam',
                'message' => trim((string)($row['subject_name'] ?: $row['exam_name'])) . ' on ' . ($row['exam_date'] ?? '-') . ' (' . ($row['shift'] ?? '-') . ')',
                'date' => $row['exam_date'] ?? ''
            ];
        }
    }

    if(empty($rows)){
        $stmt = $con->prepare("SELECT DISTINCT e.exam_id,
                                      e.exam_name,
                                      e.date AS exam_date,
                                      e.session,
                                      r.room_no,
                                      r.building,
                                      sa.seat_no
                               FROM seating_allocation sa
                               JOIN exams e ON sa.exam_id = e.exam_id
                               JOIN rooms r ON sa.room_id = r.room_id
                               WHERE sa.student_id = ?
                               ORDER BY e.date ASC, e.exam_id ASC
                               LIMIT 8");
        if($stmt === false){
            respondAndClose($con, ['success'=>false, 'message'=>'Failed to load notifications: '.$con->error]);
        }
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $q = $stmt->get_result();
        while($row = $q->fetch_assoc()){
            $rows[] = [
                'title' => 'Exam slot confirmed',
                'message' => $row['exam_name'].' on '.$row['exam_date'].' ('.$row['session'].') - Room '.$row['room_no'].', Seat '.$row['seat_no'],
                'date' => $row['exam_date']
            ];
        }
        $stmt->close();
    }
    respondAndClose($con, ['success'=>true, 'data'=>$rows]);
}

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    respondAndClose($con, ['success'=>false, 'message'=>'Invalid action']);
}

// Handle POST requests for profile update and password change
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $postAct = $body['action'] ?? '';

    if($postAct === 'update_profile'){
        $name = trim((string)($body['name'] ?? ''));
        $enrollmentNo = trim((string)($body['enrollment_no'] ?? $body['roll_no'] ?? ''));
        $enrollmentNumber = trim((string)($body['enrollment_number'] ?? ''));
        $fatherName = trim((string)($body['father_name'] ?? ''));
        $mobile = trim((string)($body['mobile'] ?? $body['phone'] ?? ''));
        $address = trim((string)($body['address'] ?? ''));
        $email = trim((string)($body['email'] ?? ''));
        $phone = trim((string)($body['phone'] ?? ''));

        if($name === ''){
            respondAndClose($con, ['success'=>false, 'message'=>'Name is required']);
        }

        $username = $enrollmentNo !== '' ? buildStudentUsername($enrollmentNo) : '';

        $st = $con->prepare('UPDATE students SET name=?, enrollment_no=COALESCE(NULLIF(?, ""), enrollment_no), enrollment_number=COALESCE(NULLIF(?, ""), enrollment_number), roll_no=COALESCE(NULLIF(?, ""), roll_no), father_name=?, mobile=?, address=?, email=?, phone=? WHERE student_id=?');
        $st->bind_param('sssssssssi', $name, $enrollmentNo, $enrollmentNumber, $enrollmentNo, $fatherName, $mobile, $address, $email, $phone, $uid);
        $ok = $st->execute();
        $st->close();

        if($ok){
            if($username !== ''){
                syncStudentUsername($con, $uid, $username);
                $_SESSION['username'] = $username;
            }
            respondAndClose($con, ['success'=>true, 'message'=>'Profile updated successfully']);
        } else {
            respondAndClose($con, ['success'=>false, 'message'=>'Failed to update profile: '.$con->error]);
        }
    }

    if($postAct === 'change_password'){
        $oldPassword = (string)($body['old_password'] ?? '');
        $newPassword = (string)($body['new_password'] ?? '');
        $confirmPassword = (string)($body['confirm_password'] ?? '');

        if($oldPassword === '' || $newPassword === '' || $confirmPassword === ''){
            respondAndClose($con, ['success'=>false, 'message'=>'All password fields are required']);
        }

        if($newPassword !== $confirmPassword){
            respondAndClose($con, ['success'=>false, 'message'=>'New passwords do not match']);
        }

        if(strlen($newPassword) < 6){
            respondAndClose($con, ['success'=>false, 'message'=>'Password must be at least 6 characters']);
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $st = $con->prepare('SELECT password FROM users WHERE user_id=? LIMIT 1');
        $st->bind_param('i', $userId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();

        if(!$row || !password_verify($oldPassword, $row['password'])){
            respondAndClose($con, ['success'=>false, 'message'=>'Current password is incorrect']);
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $con->prepare('UPDATE users SET password=? WHERE user_id=?');
        $stmt->bind_param('si', $hashedPassword, $userId);
        $ok = $stmt->execute();
        $stmt->close();

        if($ok){
            respondAndClose($con, ['success'=>true, 'message'=>'Password changed successfully']);
        } else {
            respondAndClose($con, ['success'=>false, 'message'=>'Failed to change password']);
        }
    }

    respondAndClose($con, ['success'=>false, 'message'=>'Invalid POST action']);
}


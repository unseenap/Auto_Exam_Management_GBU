<?php
ini_set('display_errors', 0);
ob_start();
session_start();
ob_clean();
mysqli_report(MYSQLI_REPORT_OFF);

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error) return;
    if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;

    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
    }

    error_log('students.php fatal: ' . json_encode($error));
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
});

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

function canManageStudents() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function buildStudentUsername($enrollmentNo) {
    $enrollmentNo = strtoupper(trim((string)$enrollmentNo));
    if ($enrollmentNo === '') return '';
    $enrollmentNo = preg_replace('/@.*$/', '', $enrollmentNo);
    return strtolower($enrollmentNo) . '@gbu.ac.in';
}

function ensureStudentColumns($con) {
    $cols = [];
    $res = $con->query("SHOW COLUMNS FROM students");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
    }

    $changes = [];
    if (!in_array('enrollment_no', $cols, true)) $changes[] = "ADD COLUMN enrollment_no VARCHAR(50) NOT NULL DEFAULT '' AFTER student_id";
    if (!in_array('optional_roll_no', $cols, true)) $changes[] = "ADD COLUMN optional_roll_no VARCHAR(50) NULL AFTER roll_no";
    if (!in_array('enrollment_number', $cols, true)) $changes[] = "ADD COLUMN enrollment_number VARCHAR(50) NOT NULL DEFAULT '1234' AFTER optional_roll_no";
    if (!in_array('session_year', $cols, true)) $changes[] = "ADD COLUMN session_year VARCHAR(20) NOT NULL DEFAULT '' AFTER department";
    if (!in_array('father_name', $cols, true)) $changes[] = "ADD COLUMN father_name VARCHAR(160) NOT NULL DEFAULT '' AFTER session_year";
    if (!in_array('mobile', $cols, true)) $changes[] = "ADD COLUMN mobile VARCHAR(30) NOT NULL DEFAULT '' AFTER father_name";
    if (!in_array('address', $cols, true)) $changes[] = "ADD COLUMN address TEXT NULL AFTER mobile";
    if (!in_array('year_of_study', $cols, true)) $changes[] = "ADD COLUMN year_of_study INT NOT NULL DEFAULT 1 AFTER branch";
    if (!in_array('username', $cols, true)) $changes[] = "ADD COLUMN username VARCHAR(100) DEFAULT NULL AFTER enrollment_no";
    if (!in_array('school', $cols, true)) $changes[] = "ADD COLUMN school VARCHAR(120) NOT NULL DEFAULT 'SOICT' AFTER username";
    if (!in_array('department', $cols, true)) $changes[] = "ADD COLUMN department VARCHAR(160) NOT NULL DEFAULT 'Computer Science and Engineering' AFTER school";

    foreach ($changes as $sql) {
        $con->query("ALTER TABLE students $sql");
    }

    // Backfill required fields for old rows and enforce username format.
    $con->query("UPDATE students SET enrollment_no = UPPER(TRIM(roll_no)) WHERE (enrollment_no IS NULL OR enrollment_no = '') AND roll_no IS NOT NULL");
    $con->query("UPDATE students SET enrollment_no = UPPER(TRIM(enrollment_no)) WHERE enrollment_no IS NOT NULL AND enrollment_no <> ''");
    $con->query("UPDATE students SET username = CONCAT(enrollment_no, '@gbu.ac.in') WHERE enrollment_no IS NOT NULL AND enrollment_no <> '' AND (username IS NULL OR username = '' OR LOWER(username) <> LOWER(CONCAT(enrollment_no, '@gbu.ac.in')))");
    $con->query("UPDATE students SET enrollment_number='1234', mobile='1234', father_name='', address='' ");

    // Refresh columns.
    $cols = [];
    $res2 = $con->query("SHOW COLUMNS FROM students");
    if ($res2) {
        while ($row = $res2->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
    }

    // Create indexes if not present. Ignore errors if key already exists.
    $con->query("ALTER TABLE students ADD UNIQUE KEY uniq_students_enrollment_no (enrollment_no)");
    $con->query("ALTER TABLE students ADD UNIQUE KEY uniq_students_username (username)");

        // Keep linked student user accounts aligned with normalized usernames.
        $con->query("UPDATE users u
                                JOIN students s ON u.role='student' AND u.reference_id = s.student_id
                                LEFT JOIN users u2 ON u2.username = s.username AND u2.user_id <> u.user_id
                                SET u.username = s.username
                                WHERE u2.user_id IS NULL
                                    AND s.username IS NOT NULL AND s.username <> ''
                                    AND (u.username IS NULL OR LOWER(u.username) <> LOWER(s.username))");

        $defaultStudentPassword = password_hash('123', PASSWORD_DEFAULT);
        $role = 'student';
        $insMissingUsers = $con->prepare("INSERT INTO users (username, password, role, reference_id)
                                                                            SELECT s.username, ?, ?, s.student_id
                                                                            FROM students s
                                                                            LEFT JOIN users u ON u.role='student' AND u.reference_id = s.student_id
                                                                            LEFT JOIN users u2 ON u2.username = s.username
                                                                            WHERE u.user_id IS NULL
                                                                                AND s.username IS NOT NULL AND s.username <> ''
                                                                                AND u2.user_id IS NULL");
        if ($insMissingUsers) {
                $insMissingUsers->bind_param('ss', $defaultStudentPassword, $role);
                $insMissingUsers->execute();
                $insMissingUsers->close();
        }

    return $cols;
}

function parseBody() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function norm($value) {
    return trim((string)$value);
}

function parseIntOr($value, $fallback) {
    if ($value === null || $value === '' || !is_numeric($value)) return $fallback;
    return (int)$value;
}

function getFirstRowValue($row, $keys, $default = '') {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null) {
            return (string)$row[$key];
        }
    }
    return $default;
}

function deriveAdmissionYear($sessionYear) {
    if (preg_match('/(\d{4})/', (string)$sessionYear, $m)) {
        return (int)$m[1];
    }
    return (int)date('Y');
}

function normalizeSessionRange($sessionYear) {
    if (!preg_match('/^(\d{4})\s*-\s*(\d{4})$/', (string)$sessionYear, $m)) {
        return null;
    }
    $start = (int)$m[1];
    $end = (int)$m[2];
    if ($end <= $start) return null;
    return $start . '-' . $end;
}

function deriveProgramCode($branch) {
    $branch = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$branch));
    if ($branch === '') return 'UCS';
    return substr($branch, 0, 3);
}

function deriveSerialFromEnrollment($enrollmentNo) {
    if (preg_match('/(\d+)$/', (string)$enrollmentNo, $m)) {
        return max(1, (int)$m[1]);
    }
    return 1;
}

function upsertStudentUser($con, $studentId, $username) {
    $check = $con->prepare("SELECT user_id FROM users WHERE role='student' AND reference_id=? LIMIT 1");
    $check->bind_param('i', $studentId);
    $check->execute();
    $existingUserId = null;
    $check->bind_result($existingUserId);
    $hasExisting = $check->fetch();
    $existing = $hasExisting ? ['user_id' => $existingUserId] : null;
    $check->close();

    $conf = $con->prepare("SELECT user_id FROM users WHERE username=? AND (role<>'student' OR reference_id<>?) LIMIT 1");
    $conf->bind_param('si', $username, $studentId);
    $conf->execute();
    $conflictUserId = null;
    $conf->bind_result($conflictUserId);
    $hasConflict = $conf->fetch();
    $conflict = $hasConflict ? ['user_id' => $conflictUserId] : null;
    $conf->close();

    if ($conflict) {
        return ['success' => false, 'message' => 'Username already used by another account'];
    }

    if ($existing) {
        $upd = $con->prepare("UPDATE users SET username=? WHERE user_id=?");
        $upd->bind_param('si', $username, $existing['user_id']);
        $ok = $upd->execute();
        $err = $upd->error;
        $upd->close();
        if (!$ok) return ['success' => false, 'message' => $err ?: 'Failed to update user account'];
        return ['success' => true];
    }

    $pwd = password_hash('123', PASSWORD_DEFAULT);
    $role = 'student';
    $ins = $con->prepare("INSERT INTO users (username,password,role,reference_id) VALUES (?,?,?,?)");
    $ins->bind_param('sssi', $username, $pwd, $role, $studentId);
    $ok = $ins->execute();
    $err = $ins->error;
    $ins->close();

    if (!$ok) return ['success' => false, 'message' => $err ?: 'Failed to create user account'];
    return ['success' => true];
}

$con = getConnection();
ensureStudentColumns($con);

$method = $_SERVER['REQUEST_METHOD'];
$body = parseBody();
$action = $body['action'] ?? $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'list') {
    $q = norm($_GET['q'] ?? '');

    $sql = "SELECT student_id, enrollment_no, enrollment_no AS roll_number, enrollment_number, username, roll_no, optional_roll_no, session_year, father_name, mobile, address, name, branch, department, school, year_of_study, semester, section FROM students";
    if ($q !== '') {
        $safe = $con->real_escape_string($q);
        $like = "'%$safe%'";
        $sql .= " WHERE enrollment_no LIKE $like OR enrollment_number LIKE $like OR username LIKE $like OR roll_no LIKE $like OR optional_roll_no LIKE $like OR father_name LIKE $like OR mobile LIKE $like OR address LIKE $like OR name LIKE $like OR branch LIKE $like OR department LIKE $like OR school LIKE $like OR session_year LIKE $like";
    }
    $sql .= " ORDER BY enrollment_no ASC";
    $res = $con->query($sql);

    if (!$res) {
        echo json_encode(['success' => false, 'message' => 'Failed to load students']);
        closeConnection($con);
        exit;
    }

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        if (!isset($row['optional_roll_no']) || $row['optional_roll_no'] === null) $row['optional_roll_no'] = '';
        $rows[] = $row;
    }

    echo json_encode([
        'success' => true,
        'can_manage' => canManageStudents(),
        'data' => $rows
    ]);
    closeConnection($con);
    exit;
}

if ($method === 'POST' && $action === 'save') {
    if (!canManageStudents()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        closeConnection($con);
        exit;
    }

    $studentId = parseIntOr($body['student_id'] ?? 0, 0);
    $enrollmentNo = strtoupper(norm($body['roll_number'] ?? $body['enrollment_no'] ?? ''));
    $name = norm($body['name'] ?? '');
    $branch = norm($body['branch'] ?? '');
    $department = norm($body['department'] ?? '');
    $school = norm($body['school'] ?? 'SOICT');
    $sessionYear = norm($body['session_year'] ?? '');
    $enrollmentNumber = norm($body['enrollment_number'] ?? '1234');
    $fatherName = norm($body['father_name'] ?? '');
    $mobile = norm($body['mobile'] ?? '');
    $address = norm($body['address'] ?? '');
    $yearOfStudy = parseIntOr($body['year_of_study'] ?? 1, 1);
    $semester = parseIntOr($body['semester'] ?? 1, 1);
    $section = norm($body['section'] ?? 'A');
    $optionalRollNo = strtoupper(norm($body['optional_roll_no'] ?? ''));

    if ($enrollmentNo === '' || $name === '' || $branch === '' || $department === '' || $school === '' || $sessionYear === '') {
        echo json_encode(['success' => false, 'message' => 'Roll number, name, branch, department, school, and batch are required']);
        closeConnection($con);
        exit;
    }

    $normalizedSession = normalizeSessionRange($sessionYear);
    if ($normalizedSession === null) {
        echo json_encode(['success' => false, 'message' => 'Session must be in YYYY-YYYY format, for example 2023-2027']);
        closeConnection($con);
        exit;
    }
    $sessionYear = $normalizedSession;

    if ($yearOfStudy < 1) $yearOfStudy = 1;
    if ($semester < 1) $semester = 1;

    $minSem = ($yearOfStudy * 2) - 1;
    $maxSem = $yearOfStudy * 2;
    if ($semester < $minSem || $semester > $maxSem) {
        echo json_encode(['success' => false, 'message' => 'Year and semester mismatch. For year ' . $yearOfStudy . ', semester must be ' . $minSem . ' or ' . $maxSem]);
        closeConnection($con);
        exit;
    }

    // Username must always be enrollment number + domain.
    $username = buildStudentUsername($enrollmentNo);

    $admissionYear = deriveAdmissionYear($sessionYear);
    $programCode = deriveProgramCode($branch);
    $serialNo = deriveSerialFromEnrollment($enrollmentNo);

    if ($studentId > 0) {
        $dup = $con->prepare("SELECT student_id FROM students WHERE enrollment_no=? AND student_id<>? LIMIT 1");
        $dup->bind_param('si', $enrollmentNo, $studentId);
        $dup->execute();
        $dupId = null;
        $dup->bind_result($dupId);
        $hasDup = $dup->fetch() ? ['student_id' => $dupId] : null;
        $dup->close();
        if ($hasDup) {
            echo json_encode(['success' => false, 'message' => 'Roll number already exists']);
            closeConnection($con);
            exit;
        }

        $sql = "UPDATE students
            SET enrollment_no=?, enrollment_number=?, username=?, roll_no=?, optional_roll_no=?, session_year=?, father_name=?, mobile=?, address=?, name=?, branch=?, department=?, school=?, year_of_study=?, semester=?, section=?, admission_year=?, program_code=?, serial_no=?, branch_code=?
                WHERE student_id=?";
        $stmt = $con->prepare($sql);
        $stmt->bind_param(
            'sssssssssssssiisisisi',
            $enrollmentNo,
            $enrollmentNumber,
            $username,
            $enrollmentNo,
            $optionalRollNo,
            $sessionYear,
            $fatherName,
            $mobile,
            $address,
            $name,
            $branch,
            $department,
            $school,
            $yearOfStudy,
            $semester,
            $section,
            $admissionYear,
            $programCode,
            $serialNo,
            $programCode,
            $studentId
        );
        $ok = $stmt->execute();
        $err = $stmt->error;
        $stmt->close();

        if (!$ok) {
            echo json_encode(['success' => false, 'message' => $err ?: 'Failed to update student']);
            closeConnection($con);
            exit;
        }

        $userRes = upsertStudentUser($con, $studentId, $username);
        if (!$userRes['success']) {
            echo json_encode($userRes);
            closeConnection($con);
            exit;
        }

        echo json_encode(['success' => true, 'message' => 'Student updated']);
        closeConnection($con);
        exit;
    }

    $chk = $con->prepare("SELECT student_id FROM students WHERE enrollment_no=? LIMIT 1");
    $chk->bind_param('s', $enrollmentNo);
    $chk->execute();
    $existingId = null;
    $chk->bind_result($existingId);
    $exists = $chk->fetch() ? ['student_id' => $existingId] : null;
    $chk->close();
    if ($exists) {
        echo json_encode(['success' => false, 'message' => 'Roll number already exists']);
        closeConnection($con);
        exit;
    }

    $ins = $con->prepare(
        "INSERT INTO students (enrollment_no, enrollment_number, username, roll_no, optional_roll_no, session_year, father_name, mobile, address, name, branch, department, school, year_of_study, semester, section, admission_year, program_code, serial_no, branch_code)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $ins->bind_param(
        'sssssssssssssiisisis',
        $enrollmentNo,
        $enrollmentNumber,
        $username,
        $enrollmentNo,
        $optionalRollNo,
        $sessionYear,
        $fatherName,
        $mobile,
        $address,
        $name,
        $branch,
        $department,
        $school,
        $yearOfStudy,
        $semester,
        $section,
        $admissionYear,
        $programCode,
        $serialNo,
        $programCode
    );
    $ok = $ins->execute();
    $err = $ins->error;
    $newId = (int)$ins->insert_id;
    $ins->close();

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => $err ?: 'Failed to add student']);
        closeConnection($con);
        exit;
    }

    $userRes = upsertStudentUser($con, $newId, $username);
    if (!$userRes['success']) {
        echo json_encode($userRes);
        closeConnection($con);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Student added']);
    closeConnection($con);
    exit;
}

if ($method === 'POST' && $action === 'import_csv') {
    if (!canManageStudents()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        closeConnection($con);
        exit;
    }

    $rows = $body['rows'] ?? null;
    if (!is_array($rows) || count($rows) < 1) {
        echo json_encode(['success' => false, 'message' => 'No CSV rows found']);
        closeConnection($con);
        exit;
    }

    $inserted = 0;
    $updated = 0;
    $failed = 0;
    $errors = [];

    foreach ($rows as $idx => $row) {
        $lineNo = $idx + 2; // includes CSV header
        if (!is_array($row)) {
            $failed++;
            $errors[] = "Row {$lineNo}: Invalid row format";
            continue;
        }

        $enrollmentNo = strtoupper(norm(getFirstRowValue($row, ['roll_number', 'enrollment_no', 'enrollment', 'roll_no', 'roll'])));
        $enrollmentNo = preg_replace('/@.*$/', '', $enrollmentNo);
        $name = norm(getFirstRowValue($row, ['name', 'student_name']));
        $branch = norm(getFirstRowValue($row, ['branch', 'program']));
        $department = norm(getFirstRowValue($row, ['department', 'dept'], 'Computer Science and Engineering'));
        $school = norm(getFirstRowValue($row, ['school'], 'SOICT'));
        $sessionYear = norm(getFirstRowValue($row, ['session_year', 'session']));
        $enrollmentNumber = norm(getFirstRowValue($row, ['enrollment_number', 'enrollmentnumber', 'optional_enrollment_number'], '1234'));
        $fatherName = norm(getFirstRowValue($row, ['father_name', 'fathername', 'father'], ''));
        $mobile = norm(getFirstRowValue($row, ['mobile', 'phone', 'contact'], ''));
        $address = norm(getFirstRowValue($row, ['address', 'addr'], ''));
        $yearOfStudy = parseIntOr(getFirstRowValue($row, ['year_of_study', 'year', 'current_year'], '1'), 1);
        $semester = parseIntOr(getFirstRowValue($row, ['semester', 'sem'], '1'), 1);
        $section = strtoupper(norm(getFirstRowValue($row, ['section'], 'A')));
        $optionalRollNo = strtoupper(norm(getFirstRowValue($row, ['optional_roll_no', 'optional_roll', 'alt_roll_no'], '')));

        if ($enrollmentNo === '' || $name === '' || $branch === '' || $sessionYear === '') {
            $failed++;
            $errors[] = "Row {$lineNo}: Roll number, name, branch, and batch are required";
            continue;
        }

        $normalizedSession = normalizeSessionRange($sessionYear);
        if ($normalizedSession === null) {
            $failed++;
            $errors[] = "Row {$lineNo}: Session must be in YYYY-YYYY format";
            continue;
        }
        $sessionYear = $normalizedSession;

        if ($yearOfStudy < 1) $yearOfStudy = 1;
        if ($semester < 1) $semester = 1;

        $minSem = ($yearOfStudy * 2) - 1;
        $maxSem = $yearOfStudy * 2;
        if ($semester < $minSem || $semester > $maxSem) {
            $failed++;
            $errors[] = "Row {$lineNo}: For year {$yearOfStudy}, semester must be {$minSem} or {$maxSem}";
            continue;
        }

        $username = buildStudentUsername($enrollmentNo);
        $admissionYear = deriveAdmissionYear($sessionYear);
        $programCode = deriveProgramCode($branch);
        $serialNo = deriveSerialFromEnrollment($enrollmentNo);

        try {
            $chk = $con->prepare("SELECT student_id FROM students WHERE enrollment_no=? LIMIT 1");
            if (!$chk) throw new Exception('Failed to prepare duplicate-check query');
            $chk->bind_param('s', $enrollmentNo);
            $chk->execute();
            $existingId = null;
            $chk->bind_result($existingId);
            $exists = $chk->fetch();
            $chk->close();

            if ($exists) {
                $studentId = (int)$existingId;
                $sql = "UPDATE students
                    SET enrollment_no=?, enrollment_number=?, username=?, roll_no=?, optional_roll_no=?, session_year=?, father_name=?, mobile=?, address=?, name=?, branch=?, department=?, school=?, year_of_study=?, semester=?, section=?, admission_year=?, program_code=?, serial_no=?, branch_code=?
                        WHERE student_id=?";
                $stmt = $con->prepare($sql);
                if (!$stmt) throw new Exception('Failed to prepare update query');
                $stmt->bind_param(
                    'sssssssssssssiisisisi',
                    $enrollmentNo,
                    $enrollmentNumber,
                    $username,
                    $enrollmentNo,
                    $optionalRollNo,
                    $sessionYear,
                    $fatherName,
                    $mobile,
                    $address,
                    $name,
                    $branch,
                    $department,
                    $school,
                    $yearOfStudy,
                    $semester,
                    $section,
                    $admissionYear,
                    $programCode,
                    $serialNo,
                    $programCode,
                    $studentId
                );
                $ok = $stmt->execute();
                $err = $stmt->error;
                $stmt->close();
                if (!$ok) throw new Exception($err ?: 'Failed to update student');

                $userRes = upsertStudentUser($con, $studentId, $username);
                if (!$userRes['success']) throw new Exception($userRes['message'] ?? 'Failed to update student login');
                $updated++;
            } else {
                $ins = $con->prepare(
                    "INSERT INTO students (enrollment_no, enrollment_number, username, roll_no, optional_roll_no, session_year, father_name, mobile, address, name, branch, department, school, year_of_study, semester, section, admission_year, program_code, serial_no, branch_code)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                );
                if (!$ins) throw new Exception('Failed to prepare insert query');
                $ins->bind_param(
                    'sssssssssssssiisisis',
                    $enrollmentNo,
                    $enrollmentNumber,
                    $username,
                    $enrollmentNo,
                    $optionalRollNo,
                    $sessionYear,
                    $fatherName,
                    $mobile,
                    $address,
                    $name,
                    $branch,
                    $department,
                    $school,
                    $yearOfStudy,
                    $semester,
                    $section,
                    $admissionYear,
                    $programCode,
                    $serialNo,
                    $programCode
                );
                $ok = $ins->execute();
                $err = $ins->error;
                $newId = (int)$ins->insert_id;
                $ins->close();
                if (!$ok) throw new Exception($err ?: 'Failed to insert student');

                $userRes = upsertStudentUser($con, $newId, $username);
                if (!$userRes['success']) throw new Exception($userRes['message'] ?? 'Failed to create student login');
                $inserted++;
            }
        } catch (Exception $e) {
            $failed++;
            $errors[] = "Row {$lineNo}: " . $e->getMessage();
        }
    }

    $message = "CSV import completed. Added: {$inserted}, Updated: {$updated}, Failed: {$failed}";
    echo json_encode([
        'success' => true,
        'message' => $message,
        'inserted' => $inserted,
        'updated' => $updated,
        'failed' => $failed,
        'errors' => array_slice($errors, 0, 10)
    ]);
    closeConnection($con);
    exit;
}

if ($method === 'POST' && $action === 'delete') {
    if (!canManageStudents()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        closeConnection($con);
        exit;
    }

    $studentId = parseIntOr($body['student_id'] ?? 0, 0);
    if ($studentId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid student id']);
        closeConnection($con);
        exit;
    }

    $con->begin_transaction();
    try {
        $delUser = $con->prepare("DELETE FROM users WHERE role='student' AND reference_id=?");
        $delUser->bind_param('i', $studentId);
        $delUser->execute();
        $delUser->close();

        $delStudent = $con->prepare("DELETE FROM students WHERE student_id=?");
        $delStudent->bind_param('i', $studentId);
        $delStudent->execute();
        $affected = $delStudent->affected_rows;
        $delStudent->close();

        if ($affected < 1) {
            throw new Exception('Student not found');
        }

        $con->commit();
        echo json_encode(['success' => true, 'message' => 'Student deleted']);
    } catch (Exception $e) {
        $con->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }

    closeConnection($con);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
closeConnection($con);

<?php
/**
 * login.php — Form POST handler.
 * Accepts application/x-www-form-urlencoded (standard HTML form POST).
 * Sets session and issues a server-side Location redirect.
 * This bypasses any browser JS/HTML cache completely.
 */
ini_set('display_errors', 0);
ob_start();
session_start();
ob_clean();

require_once __DIR__ . '/../config/database.php';

/* ── helpers ── */
function login_redirect(string $url): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Location: ' . $url);
    exit;
}

function login_error(string $msg): void {
    $enc = urlencode($msg);
    login_redirect('../index.html?error=' . $enc);
}

/* ── only accept POST ── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    login_redirect('../index.html');
}

$uname = trim($_POST['username'] ?? '');
$pwd   = trim($_POST['password'] ?? '');
$role  = trim($_POST['role']     ?? '');

if ($uname === '' || $pwd === '' || $role === '') {
    login_error('Please fill in all fields.');
}

/* normalise exam_cell → admin */
if ($role === 'exam_cell') {
    $role = 'admin';
}

if (!in_array($role, ['admin', 'faculty', 'student'], true)) {
    login_error('Invalid role selected.');
}

try {
    $con = getConnection();

    /* ── upsert demo accounts + sync all student/faculty passwords to '123' ── */
    $sharedPass  = password_hash('123', PASSWORD_DEFAULT);
    $adminPass   = password_hash('admin123', PASSWORD_DEFAULT);
    $examPass    = password_hash('examcell123', PASSWORD_DEFAULT);

    $con->query("INSERT INTO users (username,password,role,reference_id) VALUES ('admin','" . $con->real_escape_string($adminPass) . "','admin',NULL) ON DUPLICATE KEY UPDATE password=VALUES(password),role=VALUES(role)");
    $con->query("INSERT INTO users (username,password,role,reference_id) VALUES ('examcell','" . $con->real_escape_string($examPass) . "','exam_cell',NULL) ON DUPLICATE KEY UPDATE password=VALUES(password),role=VALUES(role)");

    /* Reset ALL student and faculty passwords to '123' so the demo credentials always work.
       This mirrors what auth.php's ensureDemoLoginBase() does. */
    $con->query("UPDATE users SET password='" . $con->real_escape_string($sharedPass) . "' WHERE role IN ('student','faculty')");

    /* Ensure every student in the students table has a matching users row */
    $studentRows = $con->query("SELECT student_id, username, roll_no FROM students WHERE username IS NOT NULL AND username <> ''");
    if ($studentRows) {
        $upsertSt = $con->prepare("INSERT INTO users (username, password, role, reference_id) VALUES (?, ?, 'student', ?) ON DUPLICATE KEY UPDATE password=VALUES(password), reference_id=VALUES(reference_id), role='student'");
        if ($upsertSt) {
            while ($row = $studentRows->fetch_assoc()) {
                $uname_s = trim((string)($row['username'] ?? ''));
                if ($uname_s === '') $uname_s = trim((string)($row['roll_no'] ?? ''));
                if ($uname_s === '') continue;
                $sid = (int)($row['student_id'] ?? 0);
                $upsertSt->bind_param('ssi', $uname_s, $sharedPass, $sid);
                $upsertSt->execute();
            }
            $upsertSt->close();
        }
    }

    /* ── look up user — case-insensitive username match ── */
    if ($role === 'admin') {
        $st = $con->prepare("SELECT * FROM users WHERE LOWER(username)=LOWER(?) AND role IN ('admin','exam_cell') LIMIT 1");
        $st->bind_param('s', $uname);
    } else {
        $st = $con->prepare("SELECT * FROM users WHERE LOWER(username)=LOWER(?) AND role=? LIMIT 1");
        $st->bind_param('ss', $uname, $role);
    }
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$user || !password_verify($pwd, $user['password'])) {
        login_error('Invalid username or password.');
    }

    /* ── set session ── */
    $sessionRole = ($role === 'admin') ? 'admin' : $user['role'];
    $_SESSION['user_id']      = $user['user_id'];
    $_SESSION['username']     = $user['username'];
    $_SESSION['role']         = $sessionRole;
    $_SESSION['reference_id'] = $user['reference_id'];
    $_SESSION['logged_in']    = true;

    closeConnection($con);

    /* ── server-side redirect — cannot be cached ── */
    if ($sessionRole === 'student') {
        login_redirect('../modules/student_portal.html');
    } elseif ($sessionRole === 'faculty') {
        login_redirect('../modules/faculty_portal.html');
    } else {
        login_redirect('../dashboard.html');
    }

} catch (Throwable $e) {
    error_log('login.php error: ' . $e->getMessage());
    login_error('Server error. Please try again.');
}

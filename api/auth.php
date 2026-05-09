<?php
ini_set('display_errors',0);
ob_start();
session_start();
ob_clean();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/database.php';

function auth_json(array $payload): void {
    echo json_encode($payload);
    exit;
}

function ensureDemoLoginBase($con) {
    // Demo accounts are upserted by username so repeated logins do not hit
    // duplicate-key errors on environments with partially seeded data.
    $examUser = 'examcell';
    $examPass = password_hash('examcell123', PASSWORD_DEFAULT);
    $examUpsert = $con->prepare(
        "INSERT INTO users (username, password, role, reference_id)
         VALUES (?, ?, 'exam_cell', NULL)
         ON DUPLICATE KEY UPDATE password = VALUES(password), role = VALUES(role), reference_id = VALUES(reference_id)"
    );
    $examUpsert->bind_param('ss', $examUser, $examPass);
    $examUpsert->execute();
    $examUpsert->close();

    $adminUser = 'admin';
    $adminPass = password_hash('admin123', PASSWORD_DEFAULT);
    $adminUpsert = $con->prepare(
        "INSERT INTO users (username, password, role, reference_id)
         VALUES (?, ?, 'admin', NULL)
         ON DUPLICATE KEY UPDATE password = VALUES(password), role = VALUES(role), reference_id = VALUES(reference_id)"
    );
    $adminUpsert->bind_param('ss', $adminUser, $adminPass);
    $adminUpsert->execute();
    $adminUpsert->close();

    // Keep all student and faculty accounts on the shared demo password.
    $sharedPass = password_hash('123', PASSWORD_DEFAULT);

    $updateRoles = $con->prepare("UPDATE users SET password=? WHERE role IN ('student', 'faculty')");
    $updateRoles->bind_param('s', $sharedPass);
    $updateRoles->execute();
    $updateRoles->close();

    $studentRows = $con->query("SELECT student_id, username, roll_no FROM students WHERE username IS NOT NULL AND username <> ''");
    if($studentRows){
        $upsertStudent = $con->prepare("INSERT INTO users (username, password, role, reference_id) VALUES (?, ?, 'student', ?) ON DUPLICATE KEY UPDATE password=VALUES(password), reference_id=VALUES(reference_id), role='student'");
        while($row = $studentRows->fetch_assoc()){
            $username = trim((string)($row['username'] ?? ''));
            if($username === ''){
                $username = trim((string)($row['roll_no'] ?? ''));
            }
            if(trim($username) === ''){
                continue;
            }
            $studentId = (int)($row['student_id'] ?? 0);
            $upsertStudent->bind_param('ssi', $username, $sharedPass, $studentId);
            $upsertStudent->execute();
        }
        $upsertStudent->close();
    }
}

$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? $_GET['action'] ?? '';

if($action == 'login'){
    try {
        $uname = trim($body['username'] ?? '');
        $pwd   = trim($body['password'] ?? '');
        $role  = trim($body['role'] ?? '');

        // Backward compatibility: exam_cell role is now treated as admin.
        if($role === 'exam_cell'){
            $role = 'admin';
        }

        if(!$uname || !$pwd || !$role){
            auth_json(['success'=>false, 'message'=>'All fields are required']);
        }

        $con  = getConnection();
        ensureDemoLoginBase($con);
        $stmt = null;
        if($role === 'admin'){
            $stmt = $con->prepare("SELECT * FROM users WHERE LOWER(username)=LOWER(?) AND role IN ('admin','exam_cell') LIMIT 1");
            $stmt->bind_param("s", $uname);
        } else {
            $stmt = $con->prepare("SELECT * FROM users WHERE LOWER(username)=LOWER(?) AND role=? LIMIT 1");
            $stmt->bind_param("ss", $uname, $role);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        if($result->num_rows == 1){
            $user = $result->fetch_assoc();
            if(password_verify($pwd, $user['password'])){
                $sessionRole = $role === 'admin' ? 'admin' : $user['role'];
                $_SESSION['user_id']      = $user['user_id'];
                $_SESSION['username']     = $user['username'];
                $_SESSION['role']         = $sessionRole;
                $_SESSION['reference_id'] = $user['reference_id'];
                $_SESSION['logged_in']    = true;

                // Determine redirect URL server-side — client just follows it.
                if($sessionRole === 'student'){
                    $redirect = 'modules/student_portal.html';
                } elseif($sessionRole === 'faculty'){
                    $redirect = 'modules/faculty_portal.html';
                } else {
                    $redirect = 'dashboard.html';
                }

                auth_json(['success'=>true, 'role'=>$sessionRole, 'username'=>$user['username'], 'redirect'=>$redirect]);
            }
        }

        auth_json(['success'=>false, 'message'=>'Invalid username or password']);
    } catch (Throwable $e) {
        error_log('auth.php fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        auth_json(['success'=>false, 'message'=>'Login failed due to a server error. Please try again or check the database schema.']);
    } finally {
        if (isset($stmt) && $stmt) {
            $stmt->close();
        }
        if (isset($con) && $con) {
            closeConnection($con);
        }
    }
}

if($action == 'logout'){
    session_unset();
    session_destroy();
    echo json_encode(['success'=>true]);
    exit;
}

if($action == 'check'){
    $sessionRole = $_SESSION['role'] ?? null;
    if($sessionRole === 'exam_cell'){
        $sessionRole = 'admin';
    }
    echo json_encode([
        'logged_in' => isset($_SESSION['logged_in']) ? (bool)$_SESSION['logged_in'] : false,
        'role'      => $sessionRole,
        'username'  => $_SESSION['username'] ?? null
    ]);
    exit;
}

echo json_encode(['success'=>false, 'message'=>'Invalid action']);

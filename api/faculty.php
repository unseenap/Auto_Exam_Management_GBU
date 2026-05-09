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

function usernameFromFacultyName($name, $fallbackId = 0) {
    $clean = strtolower(trim((string)$name));
    $clean = preg_replace('/^(dr\.?|mr\.?|mrs\.?|ms\.?|prof\.?)\s+/i', '', $clean);
    $clean = preg_replace('/[^a-z0-9]+/i', '.', $clean);
    $clean = trim($clean, '.');
    if($clean === '' || strlen($clean) < 3) {
        $clean = 'faculty'.($fallbackId > 0 ? $fallbackId : 'user');
    }
    return $clean.'@gbu.ac.in';
}

function ensureFacultyColumns($con) {
    $columns = [];
    $q = $con->query("SHOW COLUMNS FROM faculty");
    while($r = $q->fetch_assoc()) {
        $columns[$r['Field']] = true;
    }

    if(!isset($columns['email'])) {
        $con->query("ALTER TABLE faculty ADD COLUMN email VARCHAR(150) DEFAULT NULL AFTER designation");
    }
    if(!isset($columns['mobile'])) {
        $con->query("ALTER TABLE faculty ADD COLUMN mobile VARCHAR(30) DEFAULT NULL AFTER email");
    }
    if(!isset($columns['qualification'])) {
        $con->query("ALTER TABLE faculty ADD COLUMN qualification TEXT DEFAULT NULL AFTER mobile");
    }
    if(!isset($columns['faculty_unique_no'])) {
        $con->query("ALTER TABLE faculty ADD COLUMN faculty_unique_no INT DEFAULT NULL AFTER faculty_id");
    }

    $idx = $con->query("SHOW INDEX FROM faculty WHERE Key_name='uniq_faculty_unique_no'");
    if($idx && $idx->num_rows === 0){
        $con->query("ALTER TABLE faculty ADD UNIQUE KEY uniq_faculty_unique_no (faculty_unique_no)");
    }

    $con->query("UPDATE faculty SET faculty_unique_no = faculty_id WHERE faculty_unique_no IS NULL");
}

function ensureFacultyUniqueNo($con, $facultyId) {
    $fid = (int)$facultyId;
    if($fid <= 0) return;
    $st = $con->prepare("UPDATE faculty SET faculty_unique_no=? WHERE faculty_id=? AND faculty_unique_no IS NULL");
    $st->bind_param('ii', $fid, $fid);
    $st->execute();
    $st->close();
}

ensureFacultyColumns($con);

if($method == 'GET' && $act == 'list'){
    $rows = [];
    $q = $con->query("SELECT f.*, u.username FROM faculty f LEFT JOIN users u ON u.role='faculty' AND u.reference_id=f.faculty_id ORDER BY f.total_duties ASC, f.name ASC");
    while($r = $q->fetch_assoc()) $rows[] = $r;
    echo json_encode(['success'=>true, 'data'=>$rows]);
    exit;
}

if($method == 'POST' && $act == 'add'){
    $name  = trim($body['name']);
    $dept  = trim($body['department']);
    $desig = trim($body['designation']);
    $email = trim((string)($body['email'] ?? ''));
    $mobile = trim((string)($body['mobile'] ?? ''));
    $qualification = trim((string)($body['qualification'] ?? ''));
    $uname = trim((string)($body['username'] ?? ''));

    if($name === '' || $dept === '' || $desig === ''){
        echo json_encode(['success'=>false, 'message'=>'Name, department and designation are required']);
        exit;
    }

    if($uname !== '' && !preg_match('/^[A-Za-z0-9._%+-]+@gbu\.ac\.in$/i', $uname)){
        echo json_encode(['success'=>false, 'message'=>'Username must be in format name@gbu.ac.in']);
        exit;
    }

    $stmt = $con->prepare("INSERT INTO faculty (name,department,designation,email,mobile,qualification,total_duties) VALUES(?,?,?,?,?,?,0)");
    $stmt->bind_param("ssssss", $name, $dept, $desig, $email, $mobile, $qualification);
    if($stmt->execute()){
        $fid   = $stmt->insert_id;
        ensureFacultyUniqueNo($con, $fid);
        if($uname === ''){
            $uname = usernameFromFacultyName($name, $fid);
        }
        $exists = $con->prepare("SELECT user_id FROM users WHERE username=? LIMIT 1");
        $exists->bind_param('s', $uname);
        $exists->execute();
        $existingUser = $exists->get_result()->fetch_assoc();
        $exists->close();
        if($existingUser){
            $uname = preg_replace('/@gbu\.ac\.in$/i', '.'.$fid.'@gbu.ac.in', $uname);
        }
        $hash  = password_hash('faculty123', PASSWORD_DEFAULT);
        $rl    = 'faculty';
        $us = $con->prepare("INSERT INTO users (username,password,role,reference_id) VALUES(?,?,?,?)");
        $us->bind_param("sssi", $uname, $hash, $rl, $fid);
        $us->execute(); $us->close();
        echo json_encode(['success'=>true, 'message'=>"Faculty added. Login: $uname / faculty123"]);
    } else {
        echo json_encode(['success'=>false, 'message'=>$stmt->error]);
    }
    $stmt->close();
    exit;
}

if($method == 'POST' && $act == 'import_csv'){
    $rows = $body['rows'] ?? [];
    if(!is_array($rows) || count($rows) === 0){
        echo json_encode(['success'=>false, 'message'=>'No CSV rows provided']);
        exit;
    }

    $con->begin_transaction();
    $imported = 0;
    try {
        foreach($rows as $row){
            $name = trim((string)($row['name'] ?? ''));
            $dept = trim((string)($row['department'] ?? ''));
            $desig = trim((string)($row['designation'] ?? ''));
            $email = trim((string)($row['email'] ?? ''));
            $mobile = trim((string)($row['mobile'] ?? ''));
            $qualification = trim((string)($row['qualification'] ?? ''));
            $duties = (int)($row['total_duties'] ?? 0);
            $uname = trim((string)($row['username'] ?? ''));

            if($name === '' || $dept === '' || $desig === ''){
                continue;
            }

            if($uname !== '' && !preg_match('/^[A-Za-z0-9._%+-]+@gbu\.ac\.in$/i', $uname)){
                throw new Exception('Username must be in format name@gbu.ac.in');
            }

            $stmt = $con->prepare("INSERT INTO faculty (name,department,designation,email,mobile,qualification,total_duties) VALUES(?,?,?,?,?,?,?)");
            $stmt->bind_param('ssssssi', $name, $dept, $desig, $email, $mobile, $qualification, $duties);
            if(!$stmt->execute()){
                throw new Exception($stmt->error);
            }
            $fid = $stmt->insert_id;
            $stmt->close();
            ensureFacultyUniqueNo($con, $fid);

            if($uname === ''){
                $uname = usernameFromFacultyName($name, $fid);
            }

            $exists = $con->prepare("SELECT user_id FROM users WHERE username=? LIMIT 1");
            $exists->bind_param('s', $uname);
            $exists->execute();
            $existingUser = $exists->get_result()->fetch_assoc();
            $exists->close();
            if($existingUser){
                $uname = preg_replace('/@gbu\.ac\.in$/i', '.'.$fid.'@gbu.ac.in', $uname);
            }

            $hash = password_hash('faculty123', PASSWORD_DEFAULT);
            $role = 'faculty';
            $us = $con->prepare("INSERT INTO users (username,password,role,reference_id) VALUES(?,?,?,?)");
            $us->bind_param('sssi', $uname, $hash, $role, $fid);
            if(!$us->execute()){
                throw new Exception($us->error);
            }
            $us->close();
            $imported++;
        }

        $con->commit();
        echo json_encode(['success'=>true, 'message'=>"Imported $imported faculty rows", 'count'=>$imported]);
    } catch (Throwable $e) {
        $con->rollback();
        echo json_encode(['success'=>false, 'message'=>'Import failed: '.$e->getMessage()]);
    }
    exit;
}

if($method == 'POST' && $act == 'edit'){
    $fid   = (int)$body['faculty_id'];
    $name  = trim($body['name']);
    $dept  = trim($body['department']);
    $desig = trim($body['designation']);
    $email = trim((string)($body['email'] ?? ''));
    $mobile = trim((string)($body['mobile'] ?? ''));
    $qualification = trim((string)($body['qualification'] ?? ''));
    $duties = (int)($body['total_duties'] ?? 0);

    $stmt = $con->prepare("UPDATE faculty SET name=?,department=?,designation=?,email=?,mobile=?,qualification=?,total_duties=? WHERE faculty_id=?");
    $stmt->bind_param("ssssssii", $name, $dept, $desig, $email, $mobile, $qualification, $duties, $fid);
    echo json_encode($stmt->execute()
        ? ['success'=>true,  'message'=>'Faculty updated']
        : ['success'=>false, 'message'=>$stmt->error]);
    $stmt->close();
    exit;
}

if($method == 'POST' && $act == 'delete'){
    $fid = (int)$body['faculty_id'];
    $con->query("DELETE FROM users WHERE role='faculty' AND reference_id=$fid");
    $stmt = $con->prepare("DELETE FROM faculty WHERE faculty_id=?");
    $stmt->bind_param("i", $fid);
    echo json_encode($stmt->execute()
        ? ['success'=>true,  'message'=>'Faculty deleted']
        : ['success'=>false, 'message'=>$stmt->error]);
    $stmt->close();
    exit;
}

echo json_encode(['success'=>false, 'message'=>'Invalid action']);
closeConnection($con);

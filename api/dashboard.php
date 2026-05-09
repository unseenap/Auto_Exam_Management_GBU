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

$con = getConnection();
$act = $_GET['action'] ?? '';

if($act == 'stats'){
    $studCount  = $con->query("SELECT COUNT(*) as c FROM students")->fetch_assoc()['c'];
    $facCount   = $con->query("SELECT COUNT(*) as c FROM faculty")->fetch_assoc()['c'];
    $roomCount  = $con->query("SELECT COUNT(*) as c FROM rooms")->fetch_assoc()['c'];
    $examCount  = $con->query("SELECT COUNT(*) as c FROM exams")->fetch_assoc()['c'];
    $allocCount = $con->query("SELECT COUNT(DISTINCT exam_id) as c FROM seating_allocation")->fetch_assoc()['c'];

    $recent = [];
    $q = $con->query("SELECT exam_name, date, session FROM exams ORDER BY date DESC LIMIT 5");
    while($row = $q->fetch_assoc()) $recent[] = $row;

    echo json_encode([
        'success'   => true,
        'students'  => $studCount,
        'faculty'   => $facCount,
        'rooms'     => $roomCount,
        'exams'     => $examCount,
        'allocated' => $allocCount,
        'recent'    => $recent
    ]);
    exit;
}

echo json_encode(['success'=>false, 'message'=>'Invalid action']);
closeConnection($con);

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

function ensureRoomsMatrixColumns($con){
    $columns = [];
    $q = $con->query("SHOW COLUMNS FROM rooms");
    while($c = $q->fetch_assoc()){
        $columns[] = $c['Field'];
    }

    if(!in_array('matrix_rows', $columns, true)){
        $con->query("ALTER TABLE rooms ADD COLUMN matrix_rows INT NULL AFTER capacity");
    }
    if(!in_array('matrix_cols', $columns, true)){
        $con->query("ALTER TABLE rooms ADD COLUMN matrix_cols INT NULL AFTER matrix_rows");
    }
    if(!in_array('blocked_seats_json', $columns, true)){
        $con->query("ALTER TABLE rooms ADD COLUMN blocked_seats_json LONGTEXT NULL AFTER matrix_cols");
    }
    if(!in_array('layout_notes', $columns, true)){
        $con->query("ALTER TABLE rooms ADD COLUMN layout_notes VARCHAR(255) NULL AFTER blocked_seats_json");
    }
    if(!in_array('whiteboard_area', $columns, true)){
        $con->query("ALTER TABLE rooms ADD COLUMN whiteboard_area VARCHAR(20) NULL AFTER layout_notes");
    }
}

function parsePositiveInt($value){
    $n = (int)$value;
    return $n > 0 ? $n : 0;
}

function normalizeSeatIds($blockedSeats, $rows, $cols){
    $out = [];
    if(!is_array($blockedSeats) || $rows <= 0 || $cols <= 0){
        return $out;
    }

    foreach($blockedSeats as $seat){
        $seatId = strtoupper(trim((string)$seat));
        if(!preg_match('/^R(\d+)C(\d+)$/', $seatId, $m)){
            continue;
        }
        $r = (int)$m[1];
        $c = (int)$m[2];
        if($r < 1 || $r > $rows || $c < 1 || $c > $cols){
            continue;
        }
        $out[$seatId] = true;
    }

    return array_keys($out);
}

function decodeBlockedSeats($raw){
    $decoded = json_decode((string)$raw, true);
    if(!is_array($decoded)) return [];
    $clean = [];
    foreach($decoded as $s){
        $id = strtoupper(trim((string)$s));
        if($id !== '') $clean[] = $id;
    }
    return $clean;
}

if(!isset($_SESSION['logged_in'])){
    respond(['success' => false, 'message' => 'Not logged in']);
}

$con = getConnection();
ensureRoomsMatrixColumns($con);

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$method = $_SERVER['REQUEST_METHOD'];
$act = $body['action'] ?? $_GET['action'] ?? '';

if($method === 'GET' && $act === 'list'){
    $rooms = [];
    $q = $con->query("SELECT room_id, room_no, building, capacity, matrix_rows, matrix_cols, blocked_seats_json, layout_notes, whiteboard_area FROM rooms ORDER BY building ASC, room_no ASC");
    while($r = $q->fetch_assoc()){
        $blockedSeats = decodeBlockedSeats($r['blocked_seats_json'] ?? '[]');
        $rows = (int)($r['matrix_rows'] ?? 0);
        $cols = (int)($r['matrix_cols'] ?? 0);
        $gridTotal = ($rows > 0 && $cols > 0) ? ($rows * $cols) : 0;
        $blockedCount = count($blockedSeats);

        $rooms[] = [
            'room_id' => (int)$r['room_id'],
            'room_no' => $r['room_no'],
            'building' => $r['building'],
            'capacity' => (int)$r['capacity'],
            'matrix_rows' => $rows,
            'matrix_cols' => $cols,
            'grid_total' => $gridTotal,
            'blocked_count' => $blockedCount,
            'blocked_seats' => $blockedSeats,
            'layout_notes' => $r['layout_notes'] ?? '',
            'whiteboard_area' => $r['whiteboard_area'] ?? 'front'
        ];
    }

    $stats = $con->query("SELECT COUNT(*) AS rooms_count, COALESCE(SUM(capacity), 0) AS total_capacity FROM rooms")->fetch_assoc();

    respond([
        'success' => true,
        'data' => $rooms,
        'total_capacity' => (int)$stats['total_capacity'],
        'rooms_count' => (int)$stats['rooms_count']
    ]);
}

if($method === 'POST' && ($act === 'add' || $act === 'edit')){
    if(!in_array($_SESSION['role'] ?? '', ['admin', 'exam_cell'], true)){
        respond(['success' => false, 'message' => 'Not authorized']);
    }

    $roomId = (int)($body['room_id'] ?? 0);
    $roomNo = trim((string)($body['room_no'] ?? ''));
    $building = trim((string)($body['building'] ?? ''));
    $manualCapacity = parsePositiveInt($body['capacity'] ?? 0);

    $matrixRows = parsePositiveInt($body['matrix_rows'] ?? 0);
    $matrixCols = parsePositiveInt($body['matrix_cols'] ?? 0);
    if($matrixRows > 200 || $matrixCols > 200){
        respond(['success' => false, 'message' => 'Matrix rows/columns are too large']);
    }

    $blockedSeats = normalizeSeatIds($body['blocked_seats'] ?? [], $matrixRows, $matrixCols);
    $blockedJson = json_encode($blockedSeats);
    $layoutNotes = trim((string)($body['layout_notes'] ?? ''));
    $whiteboardArea = strtolower(trim((string)($body['whiteboard_area'] ?? 'front')));
    if(!in_array($whiteboardArea, ['front', 'back', 'left', 'right', 'none'], true)){
        $whiteboardArea = 'front';
    }

    if($roomNo === '' || $building === ''){
        respond(['success' => false, 'message' => 'Room number and building are required']);
    }

    $computedCapacity = 0;
    if($matrixRows > 0 && $matrixCols > 0){
        $computedCapacity = ($matrixRows * $matrixCols) - count($blockedSeats);
        if($computedCapacity <= 0){
            respond(['success' => false, 'message' => 'Matrix leaves no usable seats']);
        }
    }

    $capacity = $computedCapacity > 0 ? $computedCapacity : $manualCapacity;
    if($capacity <= 0){
        respond(['success' => false, 'message' => 'Capacity must be greater than 0']);
    }

    if($act === 'add'){
        $stmt = $con->prepare("INSERT INTO rooms (room_no, capacity, matrix_rows, matrix_cols, blocked_seats_json, layout_notes, whiteboard_area, building) VALUES (?, ?, NULLIF(?,0), NULLIF(?,0), ?, NULLIF(?,''), ?, ?)");
        $stmt->bind_param('siiissss', $roomNo, $capacity, $matrixRows, $matrixCols, $blockedJson, $layoutNotes, $whiteboardArea, $building);
        $ok = $stmt->execute();
        $msg = $ok ? 'Room added with layout' : $stmt->error;
        $stmt->close();
        respond($ok ? ['success' => true, 'message' => $msg] : ['success' => false, 'message' => $msg]);
    }

    if($roomId <= 0){
        respond(['success' => false, 'message' => 'Invalid room id']);
    }

    $stmt = $con->prepare("UPDATE rooms SET room_no=?, capacity=?, matrix_rows=NULLIF(?,0), matrix_cols=NULLIF(?,0), blocked_seats_json=?, layout_notes=NULLIF(?,''), whiteboard_area=?, building=? WHERE room_id=?");
    $stmt->bind_param('siiissssi', $roomNo, $capacity, $matrixRows, $matrixCols, $blockedJson, $layoutNotes, $whiteboardArea, $building, $roomId);
    $ok = $stmt->execute();
    $msg = $ok ? 'Room updated with layout' : $stmt->error;
    $stmt->close();

    respond($ok ? ['success' => true, 'message' => $msg] : ['success' => false, 'message' => $msg]);
}

if($method === 'POST' && $act === 'delete'){
    if(!in_array($_SESSION['role'] ?? '', ['admin', 'exam_cell'], true)){
        respond(['success' => false, 'message' => 'Not authorized']);
    }

    $roomId = (int)($body['room_id'] ?? 0);
    if($roomId <= 0){
        respond(['success' => false, 'message' => 'Invalid room id']);
    }

    $stmt = $con->prepare("DELETE FROM rooms WHERE room_id=?");
    $stmt->bind_param('i', $roomId);
    $ok = $stmt->execute();
    $msg = $ok ? 'Room deleted' : $stmt->error;
    $stmt->close();

    respond($ok ? ['success' => true, 'message' => $msg] : ['success' => false, 'message' => $msg]);
}

respond(['success' => false, 'message' => 'Invalid action']);

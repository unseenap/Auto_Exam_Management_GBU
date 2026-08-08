<?php
ini_set('display_errors','0');
error_reporting(0);

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'exam_management');
define('DB_PORT', 3306);

function getConnection(){
    $ports = array_values(array_unique([DB_PORT, 3306, 3307]));
    $lastError = null;

    foreach ($ports as $port) {
        $con = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, $port);
        if (!$con->connect_error) {
            $con->set_charset('utf8mb4');
            return $con;
        }
        $lastError = $con->connect_error;
    }

    header('Content-Type: application/json');
    echo json_encode(['success'=>false, 'message'=>'DB error: '.$lastError]);
    exit;
}

function closeConnection($con){
    if($con) $con->close();
}

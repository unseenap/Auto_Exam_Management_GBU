<?php
ini_set('display_errors', 0);
ob_start();
session_start();
ob_clean();

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

if(function_exists('mysqli_report')){
    mysqli_report(MYSQLI_REPORT_OFF);
}

function respond($payload){
    echo json_encode($payload);
    exit;
}

function toInt($value){
    return (int)($value ?? 0);
}

function tableExists($con, $tableName){
    $stmt = $con->prepare(
        "SELECT 1
         FROM information_schema.tables
         WHERE table_schema = ? AND table_name = ?
         LIMIT 1"
    );
    if(!$stmt) return false;
    $schema = defined('DB_NAME') ? DB_NAME : '';
    $stmt->bind_param('ss', $schema, $tableName);
    if(!$stmt->execute()){
        $stmt->close();
        return false;
    }
    $res = $stmt->get_result();
    $exists = (bool)$res->fetch_row();
    $stmt->close();
    return $exists;
}

function safePrepare($con, $sql){
    $stmt = $con->prepare($sql);
    return $stmt ?: null;
}

function fetchAssocRows($stmt){
    if(!$stmt) return [];

    $rows = [];
    if(!$stmt->execute()){
        $stmt->close();
        return [];
    }

    $res = $stmt->get_result();
    if(!$res){
        $stmt->close();
        return [];
    }

    while($r = $res->fetch_assoc()){
        $rows[] = $r;
    }
    $stmt->close();
    return $rows;
}

function scalarQuery($con, $sql){
    $q = $con->query($sql);
    if(!$q) return 0;
    $row = $q->fetch_row();
    return $row ? toInt($row[0]) : 0;
}

try {
    if(!isset($_SESSION['logged_in'])){
        respond(['success' => false, 'message' => 'Not logged in']);
    }

    $con = getConnection();
    $act = $_GET['action'] ?? '';

    if($act === 'list_exams'){
        $rows = [];
        $hasMatrixForScope = tableExists($con, 'exam_schedule_matrix');
        $sql = $hasMatrixForScope
            ? "SELECT e.exam_id, e.exam_name, e.date, e.session
               FROM exams e
               WHERE EXISTS (
                   SELECT 1
                   FROM exam_schedule_matrix m
                   WHERE m.exam_id = e.exam_id
               )
               ORDER BY e.date DESC, e.exam_id DESC"
            : "SELECT exam_id, exam_name, date, session FROM exams ORDER BY date DESC, exam_id DESC";

        $q = $con->query($sql);
        if($q){
            while($r = $q->fetch_assoc()){
                $rows[] = $r;
            }
        } else {
            // Keep reports module usable even if exams table is temporarily unavailable.
            respond(['success' => true, 'data' => [], 'warning' => 'Unable to read exams table right now.']);
        }
        respond(['success' => true, 'data' => $rows]);
    }

    if($act !== 'analytics'){
        respond(['success' => false, 'message' => 'Invalid action']);
    }

    $examId = toInt($_GET['exam_id'] ?? 0);

    $hasStudents = tableExists($con, 'students');
    $hasFaculty = tableExists($con, 'faculty');
    $hasRooms = tableExists($con, 'rooms');
    $hasSeating = tableExists($con, 'seating_allocation');
    $hasAttendance = tableExists($con, 'attendance');
    $hasInvigilation = tableExists($con, 'invigilation_allocation');
    $hasExamSchedule = tableExists($con, 'exam_schedule');
    $hasMatrix = tableExists($con, 'exam_schedule_matrix');
    $hasReplacementRequests = tableExists($con, 'replacement_requests');
    $hasReplacementLog = tableExists($con, 'replacement_log');

    $context = [
        'exam_id' => $examId,
        'exam_name' => 'All Exams',
        'date' => null,
        'session' => null
    ];

    if($examId > 0){
        $examStmt = safePrepare($con, "SELECT exam_id, exam_name, date, session FROM exams WHERE exam_id = ? LIMIT 1");
        if(!$examStmt){
            respond(['success' => false, 'message' => 'Failed to prepare exam scope query']);
        }
        $examStmt->bind_param('i', $examId);
        $examRows = fetchAssocRows($examStmt);
        if(empty($examRows)){
            respond(['success' => false, 'message' => 'Exam not found']);
        }
        $context = $examRows[0];
    }

    $kpis = [
        'students_total' => $hasStudents ? scalarQuery($con, "SELECT COUNT(*) FROM students") : 0,
        'faculty_total' => $hasFaculty ? scalarQuery($con, "SELECT COUNT(*) FROM faculty") : 0,
        'rooms_total' => $hasRooms ? scalarQuery($con, "SELECT COUNT(*) FROM rooms") : 0,
        'exams_total' => scalarQuery($con, "SELECT COUNT(*) FROM exams"),
        'schedule_rows_total' => $hasExamSchedule ? scalarQuery($con, "SELECT COUNT(*) FROM exam_schedule") : 0,
        'matrix_rows_total' => $hasMatrix ? scalarQuery($con, "SELECT COUNT(*) FROM exam_schedule_matrix") : 0
    ];

    if($hasSeating){
        if($examId > 0){
            $allocatedSeats = scalarQuery($con, "SELECT COUNT(*) FROM seating_allocation WHERE exam_id = ".(int)$examId);
            $uniqueStudents = scalarQuery($con, "SELECT COUNT(DISTINCT student_id) FROM seating_allocation WHERE exam_id = ".(int)$examId);
            $roomsUsed = scalarQuery($con, "SELECT COUNT(DISTINCT room_id) FROM seating_allocation WHERE exam_id = ".(int)$examId);
            $totalCapacity = $hasRooms
                ? scalarQuery($con, "SELECT COALESCE(SUM(r.capacity), 0) FROM rooms r JOIN (SELECT DISTINCT room_id FROM seating_allocation WHERE exam_id = ".(int)$examId.") used ON used.room_id = r.room_id")
                : 0;

            if($hasAttendance){
                $presentCount = scalarQuery(
                    $con,
                    "SELECT COUNT(*)
                     FROM seating_allocation sa
                     LEFT JOIN attendance a ON a.allocation_id = sa.allocation_id
                     WHERE sa.exam_id = ".(int)$examId." AND COALESCE(a.status, 'Absent') = 'Present'"
                );
                $absentCount = scalarQuery(
                    $con,
                    "SELECT COUNT(*)
                     FROM seating_allocation sa
                     LEFT JOIN attendance a ON a.allocation_id = sa.allocation_id
                     WHERE sa.exam_id = ".(int)$examId." AND COALESCE(a.status, 'Absent') <> 'Present'"
                );
                $attendanceMarked = scalarQuery(
                    $con,
                    "SELECT COUNT(*)
                     FROM attendance a
                     JOIN seating_allocation sa ON sa.allocation_id = a.allocation_id
                     WHERE sa.exam_id = ".(int)$examId
                );
            } else {
                $presentCount = 0;
                $absentCount = 0;
                $attendanceMarked = 0;
            }

            $dutyAssignments = $hasInvigilation
                ? scalarQuery($con, "SELECT COUNT(*) FROM invigilation_allocation WHERE exam_id = ".(int)$examId)
                : 0;
            $dutyFaculty = $hasInvigilation
                ? scalarQuery($con, "SELECT COUNT(DISTINCT faculty_id) FROM invigilation_allocation WHERE exam_id = ".(int)$examId)
                : 0;

            $scheduleRows = $hasExamSchedule
                ? scalarQuery($con, "SELECT COUNT(*) FROM exam_schedule WHERE exam_id = ".(int)$examId)
                : 0;
            $matrixRows = $hasMatrix
                ? scalarQuery($con, "SELECT COUNT(*) FROM exam_schedule_matrix WHERE exam_id = ".(int)$examId)
                : 0;

            $kpis['allocated_seats'] = $allocatedSeats;
            $kpis['unique_students'] = $uniqueStudents;
            $kpis['rooms_used'] = $roomsUsed;
            $kpis['attendance_marked'] = $attendanceMarked;
            $kpis['present_count'] = $presentCount;
            $kpis['absent_count'] = $absentCount;
            $kpis['attendance_rate_pct'] = $allocatedSeats > 0 ? round(($presentCount / $allocatedSeats) * 100, 2) : 0;
            $kpis['total_capacity'] = $totalCapacity;
            $kpis['utilization_pct'] = $totalCapacity > 0 ? round(($allocatedSeats / $totalCapacity) * 100, 2) : 0;
            $kpis['duty_assignments'] = $dutyAssignments;
            $kpis['duty_faculty'] = $dutyFaculty;
            $kpis['schedule_rows'] = $scheduleRows;
            $kpis['matrix_rows'] = $matrixRows;
        } else {
            $allocatedSeats = scalarQuery($con, "SELECT COUNT(*) FROM seating_allocation");
            $uniqueStudents = scalarQuery($con, "SELECT COUNT(DISTINCT student_id) FROM seating_allocation");
            $roomsUsed = scalarQuery($con, "SELECT COUNT(DISTINCT room_id) FROM seating_allocation");
            $totalCapacity = $hasRooms
                ? scalarQuery($con, "SELECT COALESCE(SUM(r.capacity), 0) FROM rooms r JOIN (SELECT DISTINCT room_id FROM seating_allocation) used ON used.room_id = r.room_id")
                : 0;

            if($hasAttendance){
                $presentCount = scalarQuery(
                    $con,
                    "SELECT COUNT(*)
                     FROM seating_allocation sa
                     LEFT JOIN attendance a ON a.allocation_id = sa.allocation_id
                     WHERE COALESCE(a.status, 'Absent') = 'Present'"
                );
                $absentCount = scalarQuery(
                    $con,
                    "SELECT COUNT(*)
                     FROM seating_allocation sa
                     LEFT JOIN attendance a ON a.allocation_id = sa.allocation_id
                     WHERE COALESCE(a.status, 'Absent') <> 'Present'"
                );
                $attendanceMarked = scalarQuery($con, "SELECT COUNT(*) FROM attendance");
            } else {
                $presentCount = 0;
                $absentCount = 0;
                $attendanceMarked = 0;
            }

            $kpis['allocated_seats'] = $allocatedSeats;
            $kpis['unique_students'] = $uniqueStudents;
            $kpis['rooms_used'] = $roomsUsed;
            $kpis['attendance_marked'] = $attendanceMarked;
            $kpis['present_count'] = $presentCount;
            $kpis['absent_count'] = $absentCount;
            $kpis['attendance_rate_pct'] = $allocatedSeats > 0 ? round(($presentCount / $allocatedSeats) * 100, 2) : 0;
            $kpis['total_capacity'] = $totalCapacity;
            $kpis['utilization_pct'] = $totalCapacity > 0 ? round(($allocatedSeats / $totalCapacity) * 100, 2) : 0;
            $kpis['duty_assignments'] = $hasInvigilation ? scalarQuery($con, "SELECT COUNT(*) FROM invigilation_allocation") : 0;
            $kpis['duty_faculty'] = $hasInvigilation ? scalarQuery($con, "SELECT COUNT(DISTINCT faculty_id) FROM invigilation_allocation") : 0;
            $kpis['schedule_rows'] = $kpis['schedule_rows_total'];
            $kpis['matrix_rows'] = $kpis['matrix_rows_total'];
        }
    } else {
        $kpis['allocated_seats'] = 0;
        $kpis['unique_students'] = 0;
        $kpis['rooms_used'] = 0;
        $kpis['attendance_marked'] = 0;
        $kpis['present_count'] = 0;
        $kpis['absent_count'] = 0;
        $kpis['attendance_rate_pct'] = 0;
        $kpis['total_capacity'] = 0;
        $kpis['utilization_pct'] = 0;
        $kpis['duty_assignments'] = 0;
        $kpis['duty_faculty'] = 0;
        $kpis['schedule_rows'] = $kpis['schedule_rows_total'];
        $kpis['matrix_rows'] = $kpis['matrix_rows_total'];
    }

    $replacementSummary = [
        'total' => 0,
        'pending' => 0,
        'approved' => 0,
        'rejected' => 0
    ];

    $replacementRows = [];
    if($hasReplacementRequests){
        $baseWhere = $examId > 0 ? " WHERE exam_id = ".(int)$examId : "";
        $replacementSummary['total'] = scalarQuery($con, "SELECT COUNT(*) FROM replacement_requests".$baseWhere);
        $replacementSummary['pending'] = scalarQuery($con, "SELECT COUNT(*) FROM replacement_requests".$baseWhere.($baseWhere ? " AND" : " WHERE")." status = 'Pending'");
        $replacementSummary['approved'] = scalarQuery($con, "SELECT COUNT(*) FROM replacement_requests".$baseWhere.($baseWhere ? " AND" : " WHERE")." status = 'Approved'");
        $replacementSummary['rejected'] = scalarQuery($con, "SELECT COUNT(*) FROM replacement_requests".$baseWhere.($baseWhere ? " AND" : " WHERE")." status = 'Rejected'");

        $repSql = "SELECT rr.request_id, rr.status, rr.requested_at,
                          e.exam_name, e.date, e.session,
                          r.room_no, r.building,
                          fo.name AS original_faculty,
                          COALESCE(fr.name, '-') AS replacement_faculty,
                          rr.reason
                   FROM replacement_requests rr
                   JOIN exams e ON e.exam_id = rr.exam_id
                   JOIN rooms r ON r.room_id = rr.room_id
                   JOIN faculty fo ON fo.faculty_id = rr.original_faculty_id
                   LEFT JOIN faculty fr ON fr.faculty_id = rr.replacement_faculty_id";

        if($examId > 0){
            $repSql .= " WHERE rr.exam_id = ?";
        }

        $repSql .= " ORDER BY rr.requested_at DESC LIMIT 100";
        $repStmt = safePrepare($con, $repSql);
        if($repStmt){
            if($examId > 0){
                $repStmt->bind_param('i', $examId);
            }
            $replacementRows = fetchAssocRows($repStmt);
        }
    } elseif($hasReplacementLog){
        $replacementSummary['total'] = $examId > 0
            ? scalarQuery($con, "SELECT COUNT(*) FROM replacement_log WHERE exam_id = ".(int)$examId)
            : scalarQuery($con, "SELECT COUNT(*) FROM replacement_log");
    }

    $examOperationsRows = [];
    $opsSql = "SELECT e.exam_id, e.exam_name, e.date, e.session";

    if($hasSeating){
        $opsSql .= ",
            (SELECT COUNT(*) FROM seating_allocation sa WHERE sa.exam_id = e.exam_id) AS allocated_seats,
            (SELECT COUNT(DISTINCT sa.student_id) FROM seating_allocation sa WHERE sa.exam_id = e.exam_id) AS students_allocated,
            (SELECT COUNT(DISTINCT sa.room_id) FROM seating_allocation sa WHERE sa.exam_id = e.exam_id) AS rooms_used";

        if($hasAttendance){
            $opsSql .= ",
                (SELECT COUNT(*) FROM seating_allocation sa
                 LEFT JOIN attendance a ON a.allocation_id = sa.allocation_id
                 WHERE sa.exam_id = e.exam_id AND COALESCE(a.status, 'Absent') = 'Present') AS present_count,
                (SELECT COUNT(*) FROM seating_allocation sa
                 LEFT JOIN attendance a ON a.allocation_id = sa.allocation_id
                 WHERE sa.exam_id = e.exam_id AND COALESCE(a.status, 'Absent') <> 'Present') AS absent_count";
        } else {
            $opsSql .= ", 0 AS present_count, 0 AS absent_count";
        }
    } else {
        $opsSql .= ", 0 AS allocated_seats, 0 AS students_allocated, 0 AS rooms_used, 0 AS present_count, 0 AS absent_count";
    }

    if($hasInvigilation){
        $opsSql .= ",
            (SELECT COUNT(DISTINCT ia.faculty_id) FROM invigilation_allocation ia WHERE ia.exam_id = e.exam_id) AS invigilators,
            (SELECT COUNT(*) FROM invigilation_allocation ia WHERE ia.exam_id = e.exam_id) AS duties";
    } else {
        $opsSql .= ", 0 AS invigilators, 0 AS duties";
    }

    $opsSql .= " FROM exams e";
    if($examId > 0){
        $opsSql .= " WHERE e.exam_id = ?";
    }
    $opsSql .= " ORDER BY e.date DESC, e.exam_id DESC";
    if($examId <= 0){
        $opsSql .= " LIMIT 60";
    }

    $opsStmt = safePrepare($con, $opsSql);
    if($opsStmt){
        if($examId > 0){
            $opsStmt->bind_param('i', $examId);
        }
        $examOperationsRows = fetchAssocRows($opsStmt);
    }

    foreach($examOperationsRows as &$row){
        $allocated = toInt($row['allocated_seats'] ?? 0);
        $present = toInt($row['present_count'] ?? 0);
        $row['attendance_rate_pct'] = $allocated > 0 ? round(($present / $allocated) * 100, 2) : 0;
    }
    unset($row);

    $attendanceRows = [];
    if($hasSeating){
        if($examId > 0 && $hasRooms){
            $attSql = "SELECT r.room_no, r.building,
                              (SELECT COUNT(*) FROM seating_allocation sa WHERE sa.exam_id = ? AND sa.room_id = r.room_id) AS allocated";
            if($hasAttendance){
                $attSql .= ",
                    (SELECT COUNT(*) FROM seating_allocation sa
                     LEFT JOIN attendance a ON a.allocation_id = sa.allocation_id
                     WHERE sa.exam_id = ? AND sa.room_id = r.room_id AND COALESCE(a.status, 'Absent') = 'Present') AS present,
                    (SELECT COUNT(*) FROM seating_allocation sa
                     LEFT JOIN attendance a ON a.allocation_id = sa.allocation_id
                     WHERE sa.exam_id = ? AND sa.room_id = r.room_id AND COALESCE(a.status, 'Absent') <> 'Present') AS absent,
                    (SELECT COUNT(*) FROM attendance a
                     JOIN seating_allocation sa ON sa.allocation_id = a.allocation_id
                     WHERE sa.exam_id = ? AND sa.room_id = r.room_id) AS marked";
            } else {
                $attSql .= ", 0 AS present, 0 AS absent, 0 AS marked";
            }
            $attSql .= " FROM rooms r ORDER BY r.room_no ASC";

            $attStmt = safePrepare($con, $attSql);
            if($attStmt){
                if($hasAttendance){
                    $attStmt->bind_param('iiii', $examId, $examId, $examId, $examId);
                } else {
                    $attStmt->bind_param('i', $examId);
                }
                $allRows = fetchAssocRows($attStmt);
                foreach($allRows as $r){
                    if(toInt($r['allocated'] ?? 0) > 0){
                        $attendanceRows[] = $r;
                    }
                }
            }
        } else {
            foreach($examOperationsRows as $op){
                $attendanceRows[] = [
                    'exam_name' => $op['exam_name'] ?? '-',
                    'date' => $op['date'] ?? '-',
                    'session' => $op['session'] ?? '-',
                    'allocated' => toInt($op['allocated_seats'] ?? 0),
                    'present' => toInt($op['present_count'] ?? 0),
                    'absent' => toInt($op['absent_count'] ?? 0),
                    'marked' => $hasAttendance ? toInt($op['allocated_seats'] ?? 0) : 0
                ];
            }
        }
    }

    foreach($attendanceRows as &$row){
        $allocated = toInt($row['allocated'] ?? 0);
        $present = toInt($row['present'] ?? 0);
        $row['attendance_rate_pct'] = $allocated > 0 ? round(($present / $allocated) * 100, 2) : 0;
    }
    unset($row);

    $roomUtilizationRows = [];
    if($hasRooms && $hasSeating){
        if($examId > 0){
            $roomSql = "SELECT r.room_no, r.building, r.capacity,
                               (SELECT COUNT(*) FROM seating_allocation sa WHERE sa.exam_id = ? AND sa.room_id = r.room_id) AS used_seats";
            if($hasInvigilation){
                $roomSql .= ",
                    (SELECT COUNT(DISTINCT ia.faculty_id) FROM invigilation_allocation ia WHERE ia.exam_id = ? AND ia.room_id = r.room_id) AS invigilators";
            } else {
                $roomSql .= ", 0 AS invigilators";
            }
            $roomSql .= " FROM rooms r ORDER BY r.building ASC, r.room_no ASC";
            $roomStmt = safePrepare($con, $roomSql);
            if($roomStmt){
                if($hasInvigilation){
                    $roomStmt->bind_param('ii', $examId, $examId);
                } else {
                    $roomStmt->bind_param('i', $examId);
                }
                $roomRows = fetchAssocRows($roomStmt);
                foreach($roomRows as $r){
                    if(toInt($r['used_seats'] ?? 0) > 0){
                        $roomUtilizationRows[] = $r;
                    }
                }
            }
        } else {
            $roomSql = "SELECT r.room_no, r.building, r.capacity,
                               (SELECT COUNT(*) FROM seating_allocation sa WHERE sa.room_id = r.room_id) AS used_seats,
                               (SELECT COUNT(DISTINCT sa.exam_id) FROM seating_allocation sa WHERE sa.room_id = r.room_id) AS exams_used";
            if($hasInvigilation){
                $roomSql .= ", (SELECT COUNT(DISTINCT ia.faculty_id) FROM invigilation_allocation ia WHERE ia.room_id = r.room_id) AS invigilators";
            } else {
                $roomSql .= ", 0 AS invigilators";
            }
            $roomSql .= " FROM rooms r ORDER BY r.room_no ASC";
            $roomStmt = safePrepare($con, $roomSql);
            if($roomStmt){
                $roomRows = fetchAssocRows($roomStmt);
                foreach($roomRows as $r){
                    if(toInt($r['used_seats'] ?? 0) > 0){
                        $roomUtilizationRows[] = $r;
                    }
                }
            }
        }
    }

    foreach($roomUtilizationRows as &$row){
        $capacity = toInt($row['capacity'] ?? 0);
        $used = toInt($row['used_seats'] ?? 0);
        if($examId > 0){
            $row['utilization_pct'] = $capacity > 0 ? round(($used / $capacity) * 100, 2) : 0;
        } else {
            $examsUsed = max(1, toInt($row['exams_used'] ?? 1));
            $den = $capacity * $examsUsed;
            $row['utilization_pct'] = $den > 0 ? round(($used / $den) * 100, 2) : 0;
        }
    }
    unset($row);

    $facultyWorkloadRows = [];
    if($hasFaculty){
        $facSql = "SELECT f.faculty_id, f.name, f.department, f.designation,
                          COALESCE(f.total_duties, 0) AS recorded_total_duties";
        if($hasInvigilation){
            if($examId > 0){
                $facSql .= ", (SELECT COUNT(*) FROM invigilation_allocation ia WHERE ia.faculty_id = f.faculty_id AND ia.exam_id = ?) AS duties_for_scope";
            } else {
                $facSql .= ", (SELECT COUNT(*) FROM invigilation_allocation ia WHERE ia.faculty_id = f.faculty_id) AS duties_for_scope";
            }
        } else {
            $facSql .= ", 0 AS duties_for_scope";
        }
        $facSql .= " FROM faculty f ORDER BY duties_for_scope DESC, f.name ASC";

        $facStmt = safePrepare($con, $facSql);
        if($facStmt){
            if($hasInvigilation && $examId > 0){
                $facStmt->bind_param('i', $examId);
            }
            $facultyWorkloadRows = fetchAssocRows($facStmt);
        }

        if($examId > 0){
            $filtered = [];
            foreach($facultyWorkloadRows as $row){
                if(toInt($row['duties_for_scope'] ?? 0) > 0){
                    $filtered[] = $row;
                }
            }
            $facultyWorkloadRows = $filtered;
        }
    }

    $branchRows = [];
    if($hasStudents){
        if($examId > 0 && $hasSeating){
            $branchStmt = safePrepare(
                $con,
                "SELECT s.branch_code, s.branch, s.semester,
                        COUNT(sa.allocation_id) AS allocated_students
                 FROM seating_allocation sa
                 JOIN students s ON s.student_id = sa.student_id
                 WHERE sa.exam_id = ?
                 GROUP BY s.branch_code, s.branch, s.semester
                 ORDER BY allocated_students DESC, s.branch_code ASC, s.semester ASC"
            );
            if($branchStmt){
                $branchStmt->bind_param('i', $examId);
                $branchRows = fetchAssocRows($branchStmt);
            }
        } else {
            $branchStmt = safePrepare(
                $con,
                "SELECT s.branch_code, s.branch, s.semester,
                        COUNT(*) AS allocated_students
                 FROM students s
                 GROUP BY s.branch_code, s.branch, s.semester
                 ORDER BY allocated_students DESC, s.branch_code ASC, s.semester ASC"
            );
            $branchRows = fetchAssocRows($branchStmt);
        }
    }

    $timetableRows = [];
    if($hasExamSchedule){
        if($examId > 0){
            $timeStmt = safePrepare(
                $con,
                "SELECT e.exam_name, e.date, e.session,
                        es.subject_name, es.exam_date, es.start_time, es.end_time, es.duration
                 FROM exam_schedule es
                 JOIN exams e ON e.exam_id = es.exam_id
                 WHERE es.exam_id = ?
                 ORDER BY es.exam_date ASC, es.start_time ASC"
            );
            if($timeStmt){
                $timeStmt->bind_param('i', $examId);
                $timetableRows = fetchAssocRows($timeStmt);
            }
        } else {
            $timeStmt = safePrepare(
                $con,
                "SELECT e.exam_name, e.date, e.session,
                        es.subject_name, es.exam_date, es.start_time, es.end_time, es.duration
                 FROM exam_schedule es
                 JOIN exams e ON e.exam_id = es.exam_id
                 ORDER BY es.exam_date DESC, es.start_time ASC
                 LIMIT 120"
            );
            $timetableRows = fetchAssocRows($timeStmt);
        }
    }

    $matrixRowsOut = [];
    if($hasMatrix){
        if($examId > 0){
            $mxStmt = safePrepare(
                $con,
                "SELECT exam_type, branch_code, semester, branch_session, shift, exam_date,
                        subject_code, subject_name, start_time, end_time
                 FROM exam_schedule_matrix
                 WHERE exam_id = ?
                 ORDER BY exam_date ASC, shift ASC, branch_code ASC, semester ASC"
            );
            if($mxStmt){
                $mxStmt->bind_param('i', $examId);
                $matrixRowsOut = fetchAssocRows($mxStmt);
            }
        } else {
            $mxStmt = safePrepare(
                $con,
                "SELECT exam_type, branch_code, semester, branch_session, shift, exam_date,
                        subject_code, subject_name, start_time, end_time
                 FROM exam_schedule_matrix
                 ORDER BY exam_date DESC, shift ASC, branch_code ASC, semester ASC
                 LIMIT 150"
            );
            $matrixRowsOut = fetchAssocRows($mxStmt);
        }
    }

    $attendanceSubjectRows = [];
    if($hasMatrix && $hasStudents && $hasSeating){
        $attSubjectSql = "SELECT
                m.branch_code,
                COALESCE(NULLIF(m.branch_session, ''), 'N/A') AS branch_session,
                m.semester,
                COALESCE(NULLIF(m.subject_code, ''), 'N/A') AS subject_code,
                COUNT(sa.allocation_id) AS total_appeared,
                ";

        if($hasAttendance){
            $attSubjectSql .= "SUM(CASE WHEN COALESCE(a.status, 'Absent') = 'Present' THEN 1 ELSE 0 END) AS present_count,
                SUM(CASE WHEN COALESCE(a.status, 'Absent') <> 'Present' THEN 1 ELSE 0 END) AS absent_count";
        } else {
            $attSubjectSql .= "0 AS present_count,
                COUNT(sa.allocation_id) AS absent_count";
        }

        $attSubjectSql .= "
            FROM exam_schedule_matrix m
            JOIN students s
                ON s.branch_code = m.branch_code
                AND s.semester = m.semester
            LEFT JOIN seating_allocation sa
                ON sa.student_id = s.student_id
                AND sa.exam_id = m.exam_id";

        if($hasAttendance){
            $attSubjectSql .= "
            LEFT JOIN (
                SELECT at1.allocation_id, at1.status
                FROM attendance at1
                INNER JOIN (
                    SELECT allocation_id, MAX(attendance_id) AS max_attendance_id
                    FROM attendance
                    GROUP BY allocation_id
                ) latest
                    ON latest.max_attendance_id = at1.attendance_id
            ) a
                ON a.allocation_id = sa.allocation_id";
        }

        if($examId > 0){
            $attSubjectSql .= " WHERE m.exam_id = ?";
        }

        $attSubjectSql .= "
            GROUP BY m.branch_code, branch_session, m.semester, subject_code
            ORDER BY m.branch_code ASC, m.semester ASC, subject_code ASC";

        $attSubjectStmt = safePrepare($con, $attSubjectSql);
        if($attSubjectStmt){
            if($examId > 0){
                $attSubjectStmt->bind_param('i', $examId);
            }
            $attendanceSubjectRows = fetchAssocRows($attSubjectStmt);
        }
    }

    $seatingRows = [];
    if($hasSeating && $hasStudents && $hasRooms){
        $seatSql = "SELECT sa.allocation_id, sa.exam_id, sa.seat_no, sa.row_no,
                           e.exam_name, e.date, e.session,
                           r.room_no, r.building,
                           s.roll_no, s.name, s.branch, s.branch_code, s.semester, s.section
                    FROM seating_allocation sa
                    JOIN students s ON s.student_id = sa.student_id
                    JOIN rooms r ON r.room_id = sa.room_id
                    JOIN exams e ON e.exam_id = sa.exam_id";
        if($examId > 0){
            $seatSql .= " WHERE sa.exam_id = ?";
        }
        $seatSql .= " ORDER BY e.date DESC, r.room_no ASC, sa.seat_no ASC LIMIT 250";

        $seatStmt = safePrepare($con, $seatSql);
        if($seatStmt){
            if($examId > 0){
                $seatStmt->bind_param('i', $examId);
            }
            $seatingRows = fetchAssocRows($seatStmt);
        }
    }

    $attendanceDetailRows = [];
    if($hasAttendance && $hasSeating && $hasStudents && $hasRooms){
        $attDetailSql = "SELECT a.attendance_id, a.status, COALESCE(a.remarks, '') AS remarks,
                                sa.allocation_id, sa.seat_no, sa.row_no,
                                e.exam_name, e.date, e.session,
                                r.room_no, r.building,
                                s.roll_no, s.name, s.branch, s.semester, s.section
                         FROM attendance a
                         JOIN seating_allocation sa ON sa.allocation_id = a.allocation_id
                         JOIN students s ON s.student_id = sa.student_id
                         JOIN rooms r ON r.room_id = sa.room_id
                         JOIN exams e ON e.exam_id = sa.exam_id";
        if($examId > 0){
            $attDetailSql .= " WHERE sa.exam_id = ?";
        }
        $attDetailSql .= " ORDER BY e.date DESC, r.room_no ASC, sa.seat_no ASC LIMIT 250";

        $attDetailStmt = safePrepare($con, $attDetailSql);
        if($attDetailStmt){
            if($examId > 0){
                $attDetailStmt->bind_param('i', $examId);
            }
            $attendanceDetailRows = fetchAssocRows($attDetailStmt);
        }
    }

    $invigilationRows = [];
    if($hasInvigilation && $hasFaculty && $hasRooms){
        $invSql = "SELECT ia.duty_id, ia.exam_id, ia.duty_type,
                          e.exam_name, e.date, e.session,
                          r.room_no, r.building,
                          f.faculty_id, f.name AS faculty_name, f.department, f.designation
                   FROM invigilation_allocation ia
                   JOIN faculty f ON f.faculty_id = ia.faculty_id
                   JOIN rooms r ON r.room_id = ia.room_id
                   JOIN exams e ON e.exam_id = ia.exam_id";
        if($examId > 0){
            $invSql .= " WHERE ia.exam_id = ?";
        }
        $invSql .= " ORDER BY e.date DESC, r.room_no ASC, f.name ASC LIMIT 250";

        $invStmt = safePrepare($con, $invSql);
        if($invStmt){
            if($examId > 0){
                $invStmt->bind_param('i', $examId);
            }
            $invigilationRows = fetchAssocRows($invStmt);
        }
    }

    $studentRows = [];
    if($hasStudents){
        if($examId > 0 && $hasSeating){
            $studentSql = "SELECT s.student_id, s.roll_no, s.name, s.branch, s.branch_code, s.semester, s.section,
                                  s.school, s.department,
                                  COUNT(sa.allocation_id) AS allocations_in_scope
                           FROM students s
                           JOIN seating_allocation sa ON sa.student_id = s.student_id
                           WHERE sa.exam_id = ?
                           GROUP BY s.student_id
                           ORDER BY s.branch_code ASC, s.semester ASC, s.roll_no ASC
                           LIMIT 300";
            $studentStmt = safePrepare($con, $studentSql);
            if($studentStmt){
                $studentStmt->bind_param('i', $examId);
                $studentRows = fetchAssocRows($studentStmt);
            }
        } else {
            $studentSql = "SELECT student_id, roll_no, name, branch, branch_code, semester, section, school, department
                           FROM students
                           ORDER BY branch_code ASC, semester ASC, roll_no ASC
                           LIMIT 300";
            $studentStmt = safePrepare($con, $studentSql);
            $studentRows = fetchAssocRows($studentStmt);
        }
    }

    respond([
        'success' => true,
        'generated_at' => date('Y-m-d H:i:s'),
        'context' => $context,
        'kpis' => $kpis,
        'replacement_summary' => $replacementSummary,
        'sections' => [
            'exam_operations' => $examOperationsRows,
            'attendance_insights' => $attendanceRows,
            'room_utilization' => $roomUtilizationRows,
            'faculty_workload' => $facultyWorkloadRows,
            'branch_distribution' => $branchRows,
            'timetable' => $timetableRows,
            'matrix_schedule' => $matrixRowsOut,
            'attendance_subject' => $attendanceSubjectRows,
            'replacement_tracker' => $replacementRows,
            'seating_registry' => $seatingRows,
            'attendance_registry' => $attendanceDetailRows,
            'invigilation_registry' => $invigilationRows,
            'students_registry' => $studentRows
        ]
    ]);
} catch (Throwable $e) {
    respond([
        'success' => false,
        'message' => 'Report analytics failed: '.$e->getMessage()
    ]);
}

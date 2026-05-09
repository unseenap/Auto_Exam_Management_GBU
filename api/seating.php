<?php
ini_set('display_errors', 0);
ob_start();
session_start();
ob_clean();

header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/lib/schedule-subject.php';

if (!isset($_SESSION['logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$con    = getConnection();
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$method = $_SERVER['REQUEST_METHOD'];
$act    = $body['action'] ?? $_GET['action'] ?? '';

// ═══════════════════════════════════════════════════════════════════════════
// UTILITY HELPERS
// ═══════════════════════════════════════════════════════════════════════════

function normalizeExamType(string $value): string
{
    $t = strtolower(trim($value));
    if ($t === '') return '';
    if (strpos($t, 'practical') !== false) return 'practical';
    if (strpos($t, 'repeat')    !== false) return 'repeat';
    if (strpos($t, 'back')      !== false) return 'back';
    if (strpos($t, 'mid')       !== false) return 'midsem';
    if (strpos($t, 'end') !== false || strpos($t, 'final') !== false) return 'end';
    $known = ['back', 'repeat', 'midsem', 'end', 'practical'];
    return in_array($t, $known, true) ? $t : $t;
}

function yearFromSemester(int $semester): int
{
    return $semester > 0 ? (int)ceil($semester / 2) : 0;
}

/**
 * Cluster key: branch_code|year|admission_year
 * Same branch but different year or admission_year = different cluster.
 */
function clusterKey(array $student): string
{
    $branch = strtoupper(trim((string)($student['branch_code'] ?? '')));
    if ($branch === '') $branch = strtoupper(trim((string)($student['branch'] ?? 'UNKNOWN')));
    $year = yearFromSemester((int)($student['semester'] ?? 0));
    $ay   = (int)($student['admission_year'] ?? 0);
    return "{$branch}|Y{$year}|AY{$ay}";
}

function clusterLabel(array $student): string
{
    $branch = strtoupper(trim((string)($student['branch_code'] ?? '')));
    if ($branch === '') $branch = strtoupper(trim((string)($student['branch'] ?? 'UNKNOWN')));
    $sem  = (int)($student['semester'] ?? 0);
    $year = yearFromSemester($sem);
    $ay   = (int)($student['admission_year'] ?? 0);
    $subjectCode = strtoupper(trim((string)($student['subject_code'] ?? '')));
    $subjectName = trim((string)($student['subject_name'] ?? ''));
    $subject = $subjectName !== '' || $subjectCode !== ''
        ? ' / ' . trim($subjectName . ($subjectCode !== '' ? ' (' . $subjectCode . ')' : ''))
        : '';
    return "{$branch} / Year {$year} / Sem {$sem} / AY {$ay}{$subject}";
}

function buildClusterMeta(array $clusters): array
{
    $meta = [];
    foreach ($clusters as $key => $bucket) {
        $first = $bucket[0] ?? [];
        $branch = strtoupper(trim((string)($first['branch_code'] ?? $first['branch'] ?? '')));
        if ($branch === '') $branch = strtoupper(trim((string)($first['branch'] ?? 'UNKNOWN')));
        $meta[$key] = [
            'branch'       => $branch,
            'year'         => yearFromSemester((int)($first['semester'] ?? 0)),
            'subject_code' => strtoupper(trim((string)($first['subject_code'] ?? ''))),
            'subject_name' => strtoupper(trim((string)($first['subject_name'] ?? ''))),
        ];
    }
    return $meta;
}

function clusterConflicts(array $leftMeta, array $rightMeta): bool
{
    if (empty($leftMeta) || empty($rightMeta)) return false;

    // Two adjacent columns conflict ONLY when ALL three conditions are true:
    //   same branch AND same year AND same subject code/name
    // This allows: UCS Year2 ↔ UCS Year3 (different year → OK)
    //              UCS Year2 ↔ ICS Year2 (different branch → OK)
    //              IT Year3  ↔ CSE Year2 (different branch AND year → OK)
    // Forbidden:   UCS Year3 ↔ UCS Year3 with same paper

    $sameBranch = (string)($leftMeta['branch'] ?? '') !== ''
               && (string)($leftMeta['branch'] ?? '') === (string)($rightMeta['branch'] ?? '');

    $sameYear = (int)($leftMeta['year'] ?? 0) > 0
             && (int)($leftMeta['year'] ?? 0) === (int)($rightMeta['year'] ?? 0);

    $leftCode  = trim((string)($leftMeta['subject_code'] ?? ''));
    $rightCode = trim((string)($rightMeta['subject_code'] ?? ''));
    $leftName  = trim((string)($leftMeta['subject_name'] ?? ''));
    $rightName = trim((string)($rightMeta['subject_name'] ?? ''));

    $sameSubject = ($leftCode !== '' && $leftCode === $rightCode)
                || ($leftName !== '' && $leftName === $rightName);

    return $sameBranch && $sameYear && $sameSubject;
}

function largestCompatibleCluster(array $clusters, array $clusterMeta, ?array $leftMeta = null, string $exclude = ''): string
{
    $best = '';
    $max  = -1;
    foreach ($clusters as $k => $bucket) {
        $n = count($bucket);
        if ($n <= 0 || $k === $exclude) continue;
        $meta = $clusterMeta[$k] ?? [];
        if ($leftMeta && clusterConflicts($leftMeta, $meta)) continue;
        if ($n > $max || ($n === $max && strcmp($k, $best) < 0)) {
            $max  = $n;
            $best = $k;
        }
    }
    return $best;
}

/**
 * Decode blocked_seats_json -> associative array keyed by "R{r}C{c}".
 */
function decodeBlockedSeats(?string $raw): array
{
    if ($raw === null || $raw === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $s) {
        $id = strtoupper(trim((string)$s));
        if (preg_match('/^R\d+C\d+$/', $id)) $out[$id] = true;
    }
    return $out;
}

// ═══════════════════════════════════════════════════════════════════════════
// DATABASE FETCH HELPERS
// ═══════════════════════════════════════════════════════════════════════════

function fetchScheduleRowsForExam($con, int $examId, array $exam): array
{
    $rows = [];
    $seen = [];

    $stmt = $con->prepare(
        "SELECT matrix_id, branch_code, semester, branch_session, shift, exam_date, subject_name, subject_code
         FROM exam_schedule_matrix
         WHERE exam_id = ?
         ORDER BY branch_code ASC, semester ASC, branch_session ASC"
    );
    $stmt->bind_param('i', $examId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $mid = (int)($row['matrix_id'] ?? 0);
        if ($mid > 0) $seen[$mid] = true;
        $rows[] = $row;
    }
    $stmt->close();

    $examType = normalizeExamType($exam['exam_name'] ?? '');
    $examDate = $exam['date']    ?? '';
    $shift    = $exam['session'] ?? '';

    if ($examType !== '' && $examDate !== '' && $shift !== '') {
        $stmt = $con->prepare(
            "SELECT matrix_id, branch_code, semester, branch_session, shift, exam_date, subject_name, subject_code
             FROM exam_schedule_matrix
             WHERE exam_type = ? AND exam_date = ? AND shift = ?
             ORDER BY branch_code ASC, semester ASC, branch_session ASC"
        );
        $stmt->bind_param('sss', $examType, $examDate, $shift);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $mid = (int)($row['matrix_id'] ?? 0);
            if ($mid > 0 && isset($seen[$mid])) continue;
            if ($mid > 0) $seen[$mid] = true;
            $rows[] = $row;
        }
        $stmt->close();
    }

    return $rows;
}

function fetchStudentsForScheduleRow($con, array $row): array
{
    $branchCode = strtoupper(trim((string)($row['branch_code'] ?? '')));
    $semester   = (int)($row['semester'] ?? 0);
    $section    = trim((string)($row['branch_session'] ?? ''));

    if ($semester <= 0 || $branchCode === '') return [];

    // Strategy 1: exact branch_code / program_code
    $stmt = $con->prepare(
        "SELECT student_id, roll_no, branch, branch_code, semester,
            admission_year, program_code, serial_no
         FROM students
         WHERE semester = ?
           AND (UPPER(COALESCE(branch_code,''))=? OR UPPER(COALESCE(program_code,''))=?)
         ORDER BY roll_no ASC, student_id ASC"
    );
    if (!$stmt) return [];
    $stmt->bind_param('iss', $semester, $branchCode, $branchCode);
    $stmt->execute();
    $students = [];
    $res = $stmt->get_result();
    while ($s = $res->fetch_assoc()) $students[] = $s;
    $stmt->close();
    if (!empty($students)) {
        foreach ($students as &$student) {
            $student['subject_name'] = (string)($row['subject_name'] ?? '');
            $student['subject_code'] = (string)($row['subject_code'] ?? '');
            $student['branch_session'] = $section;
        }
        unset($student);
        return $students;
    }

    // Strategy 2: branch name contains the code
    $stmt = $con->prepare(
        "SELECT student_id, roll_no, branch, branch_code, semester,
                admission_year, program_code, serial_no
         FROM students
         WHERE semester = ?
           AND UPPER(COALESCE(branch,'')) LIKE CONCAT('%',?,'%')
         ORDER BY roll_no ASC, student_id ASC"
    );
    if (!$stmt) return [];
    $stmt->bind_param('is', $semester, $branchCode);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($s = $res->fetch_assoc()) $students[] = $s;
    $stmt->close();
    if (!empty($students)) return $students;

    // Strategy 3: section match
    if ($section !== '') {
        $su = strtoupper($section);
        $stmt = $con->prepare(
            "SELECT student_id, roll_no, branch, branch_code, semester,
                admission_year, program_code, serial_no
             FROM students
             WHERE semester = ? AND UPPER(COALESCE(section,''))=?
             ORDER BY roll_no ASC, student_id ASC"
        );
        if ($stmt) {
            $stmt->bind_param('is', $semester, $su);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($s = $res->fetch_assoc()) $students[] = $s;
            $stmt->close();
            if (!empty($students)) {
                foreach ($students as &$student) {
                    $student['subject_name'] = (string)($row['subject_name'] ?? '');
                    $student['subject_code'] = (string)($row['subject_code'] ?? '');
                    $student['branch_session'] = $section;
                }
                unset($student);
                return $students;
            }
        }
    }

    // Strategy 4: broad LIKE
    $stmt = $con->prepare(
        "SELECT student_id, roll_no, branch, branch_code, semester,
            admission_year, program_code, serial_no
         FROM students
         WHERE semester = ?
           AND (UPPER(COALESCE(branch,''))       LIKE CONCAT('%',?,'%')
             OR UPPER(COALESCE(program_code,'')) LIKE CONCAT('%',?,'%')
             OR UPPER(COALESCE(branch_code,''))  LIKE CONCAT('%',?,'%'))
         ORDER BY roll_no ASC, student_id ASC"
    );
    if (!$stmt) return [];
    $stmt->bind_param('isss', $semester, $branchCode, $branchCode, $branchCode);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($s = $res->fetch_assoc()) $students[] = $s;
    $stmt->close();

    if (!empty($students)) {
        foreach ($students as &$student) {
            $student['subject_name'] = (string)($row['subject_name'] ?? '');
            $student['subject_code'] = (string)($row['subject_code'] ?? '');
            $student['branch_session'] = $section;
        }
        unset($student);
    }

    return $students;
}

// ═══════════════════════════════════════════════════════════════════════════
// CORE ALLOCATION ENGINE
//
// ALGORITHM — A-B-A-B repeating column pattern (anti-cheating, deterministic)
//
// STEP 1 — GROUPING
//   Sort students: branch -> year -> admission_year -> roll_no
//   Group into clusters: one cluster = unique (branch, year, admission_year)
//   Each cluster is a FIFO queue sorted by roll_no.
//
// STEP 2 — PATTERN SLOTS
//   Two active slots drive the column pattern:
//     slotA  owns ODD  physical columns  (1, 3, 5 …)  [index 0, 2, 4 …]
//     slotB  owns EVEN physical columns  (2, 4, 6 …)  [index 1, 3, 5 …]
//
//   Initial selection:
//     slotA = largest cluster
//     slotB = largest cluster with a DIFFERENT branch from slotA
//             (falls back to any other cluster when no diff-branch exists)
//
//   Slot refresh (when a slot's cluster is exhausted):
//     slotA exhausted -> slotA = slotB; slotB = next best cluster != slotA
//     slotB exhausted -> slotB = next best cluster != slotA
//     "next best" = largest remaining cluster with different branch from the
//                   other slot; falls back to any remaining cluster.
//
// STEP 3 — COLUMN FILL
//   For each column left -> right:
//     Determine owner (slotA for even index, slotB for odd index)
//     Refresh owner if exhausted (see above)
//     Fill column top -> bottom, skipping blocked seats
//     If cluster runs out mid-column -> rest of column stays EMPTY
//     (NEVER mix two clusters in one column)
//
// SINGLE-GROUP CASE
//   Only one cluster -> fill only ODD columns (index 0, 2, 4 …)
//   Even columns stay completely EMPTY
//   Pattern: FILLED -> EMPTY -> FILLED -> EMPTY
//
// STEP 4 — OVERFLOW
//   Students not seated in room N carry over to room N+1.
//   slotA / slotB state carries over (pattern continues seamlessly).
//
// BLOCKED SEATS
//   Seat key "R{row}C{col}" in blocked set -> skip, never assign.
//   Seat numbering is column-first:
//     seat 1 = R1C1, seat 2 = R2C1, ..., seat (rows+1) = R1C2, ...
// ═══════════════════════════════════════════════════════════════════════════

// ── Cluster helpers ─────────────────────────────────────────────────────────

/** Branch portion of a cluster key (first segment before '|'). */
function branchOfKey(string $key): string
{
    return explode('|', $key)[0] ?? '';
}

/**
 * Largest non-empty cluster key, optionally excluding one key.
 * Tie-break: alphabetical key (deterministic).
 */
function largestCluster(array $clusters, string $exclude = ''): string
{
    $best = '';
    $max  = -1;
    foreach ($clusters as $k => $bucket) {
        $n = count($bucket);
        if ($n <= 0 || $k === $exclude) continue;
        if ($n > $max || ($n === $max && strcmp($k, $best) < 0)) {
            $max  = $n;
            $best = $k;
        }
    }
    return $best;
}

/**
 * Largest non-empty cluster whose branch DIFFERS from $baseBranch.
 * Falls back to largestCluster($clusters, $exclude) when none found.
 */
function largestDifferentBranch(array $clusters, string $baseBranch, string $exclude = ''): string
{
    $best = '';
    $max  = -1;
    foreach ($clusters as $k => $bucket) {
        $n = count($bucket);
        if ($n <= 0 || $k === $exclude) continue;
        if (branchOfKey($k) === $baseBranch) continue;
        if ($n > $max || ($n === $max && strcmp($k, $best) < 0)) {
            $max  = $n;
            $best = $k;
        }
    }
    return $best !== '' ? $best : largestCluster($clusters, $exclude);
}

// ── STEP 1: Build ordered clusters ──────────────────────────────────────────

function buildOrderedClusters(array $students): array
{
    usort($students, function (array $a, array $b): int {
        $bA = strtoupper(trim((string)($a['branch_code'] ?? $a['branch'] ?? '')));
        $bB = strtoupper(trim((string)($b['branch_code'] ?? $b['branch'] ?? '')));
        if ($bA !== $bB) return strcmp($bA, $bB);

        $yA = yearFromSemester((int)($a['semester'] ?? 0));
        $yB = yearFromSemester((int)($b['semester'] ?? 0));
        if ($yA !== $yB) return $yA <=> $yB;

        $ayA = (int)($a['admission_year'] ?? 0);
        $ayB = (int)($b['admission_year'] ?? 0);
        if ($ayA !== $ayB) return $ayA <=> $ayB;

        return strcmp((string)($a['roll_no'] ?? ''), (string)($b['roll_no'] ?? ''));
    });

    $clusters = [];
    foreach ($students as $s) {
        $k = clusterKey($s);
        if (!isset($clusters[$k])) $clusters[$k] = [];
        $clusters[$k][] = $s;
    }
    return $clusters;
}

// ── STEP 2: Build per-column seat slot lists ─────────────────────────────────

/**
 * Returns array[0..cols-1] of slot arrays.
 * Each slot: ['seat_no'=>int, 'row_no'=>int, 'col_no'=>int]
 * Blocked seats are omitted.
 * Seat numbering is column-first: seat 1=R1C1, seat 2=R2C1, ..., seat(rows+1)=R1C2
 */
function buildColSlots(int $rows, int $cols, array $blocked): array
{
    $colSlots = array_fill(0, $cols, []);
    $seatNo   = 1;
    for ($c = 1; $c <= $cols; $c++) {
        for ($r = 1; $r <= $rows; $r++) {
            if (!isset($blocked["R{$r}C{$c}"])) {
                $colSlots[$c - 1][] = [
                    'seat_no' => $seatNo,
                    'row_no'  => $r,
                    'col_no'  => $c,
                ];
            }
            $seatNo++;
        }
    }
    return $colSlots;
}

// ── STEP 3: Fill one room ────────────────────────────────────────────────────

function fillRoom(
    array  &$clusters,
    array   $clusterLabels,
    array   $clusterMeta,
    array   $room
): array {
    // Resolve grid dimensions
    $matrixRows = (int)($room['matrix_rows'] ?? 0);
    $matrixCols = (int)($room['matrix_cols'] ?? 0);
    $capacity   = (int)($room['capacity']    ?? 0);

    if ($matrixRows > 0 && $matrixCols > 0) {
        $rows = $matrixRows;
        $cols = $matrixCols;
    } else {
        $cols = max(1, $matrixCols > 0 ? $matrixCols : 4);
        $rows = (int)ceil($capacity / $cols);
        if ($rows <= 0) return [];
    }

    $blocked  = decodeBlockedSeats($room['blocked_seats_json'] ?? null);
    $colSlots = buildColSlots($rows, $cols, $blocked);

    $assignments  = [];
    $prevKey      = '';   // cluster key placed in the previous column
    $prevMeta     = null; // meta of previous column's cluster

    for ($c = 0; $c < $cols; $c++) {

        // Count remaining students
        $remaining = array_sum(array_map('count', $clusters));
        if ($remaining <= 0) break;

        // Pick the best cluster for this column:
        //   1. Prefer a cluster that does NOT conflict with the previous column
        //   2. Among non-conflicting, pick the largest
        //   3. If ALL remaining clusters conflict with previous, pick the largest anyway
        //      (empty column is a last resort only when no compatible cluster exists)

        $pickKey = '';

        // Try to find a non-conflicting cluster (different from prev)
        $best    = '';
        $bestN   = -1;
        foreach ($clusters as $k => $bucket) {
            $n = count($bucket);
            if ($n <= 0) continue;
            $meta = $clusterMeta[$k] ?? [];
            // Skip if it conflicts with the previous column's cluster
            if ($prevMeta !== null && clusterConflicts($prevMeta, $meta)) continue;
            if ($n > $bestN || ($n === $bestN && strcmp($k, $best) < 0)) {
                $bestN = $n;
                $best  = $k;
            }
        }

        if ($best !== '') {
            $pickKey = $best;
        } else {
            // All remaining clusters conflict with previous — pick the largest anyway
            // (no empty column — fill it, anti-cheating is best-effort)
            $pickKey = largestCluster($clusters);
        }

        if ($pickKey === '' || !isset($clusters[$pickKey]) || count($clusters[$pickKey]) === 0) {
            // Truly nothing left
            $prevKey  = '';
            $prevMeta = null;
            continue;
        }

        $pickMeta = $clusterMeta[$pickKey] ?? [];
        $placed   = 0;

        // Fill entire column with $pickKey (top -> bottom)
        // If cluster runs out mid-column -> rest of column stays EMPTY (no mixing)
        foreach ($colSlots[$c] as $slot) {
            if (empty($clusters[$pickKey])) break;
            $s = array_shift($clusters[$pickKey]);
            $assignments[] = [
                'student'     => $s,
                'seat_no'     => $slot['seat_no'],
                'row_no'      => $slot['row_no'],
                'col_no'      => $slot['col_no'],
                'cluster_key' => $pickKey,
                'group_label' => $clusterLabels[$pickKey] ?? $pickKey,
            ];
            $placed++;
        }

        if ($placed <= 0) {
            $prevKey  = '';
            $prevMeta = null;
            continue;
        }

        // Clean up exhausted bucket
        if (isset($clusters[$pickKey]) && count($clusters[$pickKey]) === 0) {
            unset($clusters[$pickKey]);
            unset($clusterMeta[$pickKey]);
            // prevMeta stays so next column still avoids conflict with what was just placed
        }

        $prevKey  = $pickKey;
        $prevMeta = $pickMeta;
    }

    return $assignments;
}

// ── STEP 4: Distribute across all rooms ─────────────────────────────────────

/**
 * Allocate all students across all rooms.
 *
 * @param array  $students      All students for this exam slot
 * @param array  $rooms         All rooms ordered by building/room_no
 * @param array  $clusterLabels Output: clusterKey -> human-readable label
 * @return array [ roomPlans[], leftoverStudents[] ]
 */
function allocateStudentsToRooms(array $students, array $rooms, array &$clusterLabels): array
{
    $clusters = buildOrderedClusters($students);
    $clusterMeta = buildClusterMeta($clusters);

    foreach ($clusters as $key => $bucket) {
        if (!empty($bucket)) $clusterLabels[$key] = clusterLabel($bucket[0]);
    }

    $roomPlans = [];

    foreach ($rooms as $room) {
        if (array_sum(array_map('count', $clusters)) <= 0) break;

        $assignments = fillRoom($clusters, $clusterLabels, $clusterMeta, $room);

        if (!empty($assignments)) {
            $roomPlans[] = [
                'room_id'  => (int)$room['room_id'],
                'students' => $assignments,
            ];
        }
    }

    // Collect students that could not be seated (all rooms full)
    $leftover = [];
    foreach ($clusters as $bucket) {
        foreach ($bucket as $s) $leftover[] = $s;
    }

    return [$roomPlans, $leftover];
}

// ═══════════════════════════════════════════════════════════════════════════
// ACTIONS
// ═══════════════════════════════════════════════════════════════════════════

if ($method === 'GET' && $act === 'list_exams') {
    $exams = [];
    $q = $con->query("SELECT exam_id, exam_name, date, session FROM exams ORDER BY date DESC");
    while ($row = $q->fetch_assoc()) $exams[] = $row;
    echo json_encode(['success' => true, 'data' => $exams]);
    exit;
}

if ($method === 'GET' && $act === 'get_chart') {
    $examId = (int)($_GET['exam_id'] ?? 0);
    $seats  = [];
    $q = $con->query(
        "SELECT sa.seat_no, sa.row_no, r.room_no, r.building,
                s.roll_no, s.name, s.branch, s.semester, s.section
         FROM seating_allocation sa
         JOIN rooms    r ON sa.room_id    = r.room_id
         JOIN students s ON sa.student_id = s.student_id
         WHERE sa.exam_id = {$examId}
         ORDER BY r.room_no, sa.seat_no ASC"
    );
    while ($row = $q->fetch_assoc()) $seats[] = $row;
    echo json_encode(['success' => true, 'data' => $seats]);
    exit;
}

if ($method === 'POST' && $act === 'allocate') {
    $examId = (int)($body['exam_id'] ?? 0);
    if ($examId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Valid exam_id is required']);
        exit;
    }

    $examStmt = $con->prepare(
        "SELECT exam_id, exam_name, date, session FROM exams WHERE exam_id = ? LIMIT 1"
    );
    $examStmt->bind_param('i', $examId);
    $examStmt->execute();
    $exam = $examStmt->get_result()->fetch_assoc() ?: [];
    $examStmt->close();

    if (empty($exam)) {
        echo json_encode(['success' => false, 'message' => 'Exam not found']);
        exit;
    }

    $scheduleRows = fetchScheduleRowsForExam($con, $examId, $exam);
    if (empty($scheduleRows)) {
        echo json_encode([
            'success' => false,
            'message' => 'No schedule matrix rows found for this exam. Add the exam to the datesheet first.',
        ]);
        exit;
    }

    $studentMap = [];
    foreach ($scheduleRows as $scheduleRow) {
        foreach (fetchStudentsForScheduleRow($con, $scheduleRow) as $student) {
            $sid = (int)($student['student_id'] ?? 0);
            if ($sid > 0) $studentMap[$sid] = $student;
        }
    }

    $students = array_values($studentMap);
    if (empty($students)) {
        echo json_encode([
            'success' => false,
            'message' => 'No students found matching the schedule rows. Check that student branch_code / program_code matches the datesheet branch codes.',
        ]);
        exit;
    }

    // Load all rooms with full layout info
    $rooms = [];
    $rq = $con->query(
        "SELECT room_id, capacity,
                COALESCE(matrix_rows, 0) AS matrix_rows,
                COALESCE(matrix_cols, 4) AS matrix_cols,
                COALESCE(blocked_seats_json, '[]') AS blocked_seats_json
         FROM rooms
         ORDER BY building ASC, room_no ASC"
    );
    while ($r = $rq->fetch_assoc()) {
        $r['room_id']     = (int)$r['room_id'];
        $r['capacity']    = (int)$r['capacity'];
        $r['matrix_rows'] = (int)$r['matrix_rows'];
        $r['matrix_cols'] = (int)$r['matrix_cols'];

        // Actual usable capacity = grid total minus blocked seats
        if ($r['matrix_rows'] > 0 && $r['matrix_cols'] > 0) {
            $bl = decodeBlockedSeats($r['blocked_seats_json']);
            $r['usable_capacity'] = ($r['matrix_rows'] * $r['matrix_cols']) - count($bl);
        } else {
            $r['usable_capacity'] = $r['capacity'];
        }

        if ($r['usable_capacity'] > 0) $rooms[] = $r;
    }

    if (empty($rooms)) {
        echo json_encode([
            'success' => false,
            'message' => 'No rooms configured. Add rooms with capacity first.',
        ]);
        exit;
    }

    $totalStudents = count($students);
    $totalCapacity = array_sum(array_column($rooms, 'usable_capacity'));

    $con->begin_transaction();
    $con->query("DELETE FROM seating_allocation WHERE exam_id = {$examId}");

    $clusterLabels = [];
    [$roomPlans, $leftoverStudents] = allocateStudentsToRooms($students, $rooms, $clusterLabels);

    $stmt = $con->prepare(
        "INSERT INTO seating_allocation (exam_id, student_id, room_id, seat_no, row_no)
         VALUES (?, ?, ?, ?, ?)"
    );

    try {
        foreach ($roomPlans as $plan) {
            $roomId = (int)$plan['room_id'];
            foreach ($plan['students'] as $a) {
                $sid    = (int)($a['student']['student_id'] ?? 0);
                $seatNo = (int)($a['seat_no'] ?? 0);
                $rowNo  = (int)($a['row_no']  ?? 1);
                if ($sid <= 0 || $seatNo <= 0 || $rowNo <= 0) continue;
                $stmt->bind_param('iiiii', $examId, $sid, $roomId, $seatNo, $rowNo);
                if (!$stmt->execute()) {
                    throw new Exception($stmt->error ?: 'Failed to insert seating row');
                }
            }
        }

        $con->commit();
        $stmt->close();

        $allocatedCount = $totalStudents - count($leftoverStudents);
        $overflowCount  = count($leftoverStudents);
        $roomsUsed      = count($roomPlans);

        $leftoverSummary = [];
        foreach ($leftoverStudents as $s) {
            $key   = clusterKey($s);
            $label = $clusterLabels[$key] ?? clusterLabel($s);
            if (!isset($leftoverSummary[$label])) $leftoverSummary[$label] = 0;
            $leftoverSummary[$label]++;
        }
        $leftoverBatches = [];
        foreach ($leftoverSummary as $group => $cnt) {
            $leftoverBatches[] = ['group' => $group, 'remaining' => $cnt];
        }

        $message = $overflowCount > 0
            ? "{$allocatedCount} students allocated across {$roomsUsed} room(s). "
              . "{$overflowCount} students could not be seated — total usable capacity "
              . "({$totalCapacity}) is less than total students ({$totalStudents}). "
              . "Add more rooms or unblock seats."
            : "{$allocatedCount} students allocated across {$roomsUsed} room(s).";

        echo json_encode([
            'success'              => true,
            'message'              => $message,
            'students'             => $totalStudents,
            'allocated_students'   => $allocatedCount,
            'unallocated_students' => $overflowCount,
            'partial_allocation'   => ($overflowCount > 0),
            'capacity'             => $totalCapacity,
            'rooms'                => $roomsUsed,
            'leftover_batches'     => $leftoverBatches,
        ]);
        exit;

    } catch (Throwable $e) {
        $con->rollback();
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Allocation failed: ' . $e->getMessage()]);
        exit;
    }
}

if ($method === 'POST' && $act === 'clear') {
    $examId = (int)($body['exam_id'] ?? 0);
    if ($examId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Valid exam_id is required']);
        exit;
    }
    $con->query("DELETE FROM seating_allocation WHERE exam_id = {$examId}");
    echo json_encode(['success' => true, 'message' => 'Seating cleared']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
closeConnection($con);

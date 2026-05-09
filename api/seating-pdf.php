<?php
/**
 * Seating Plan — Printable PDF Page
 *
 * Pure PHP, zero dependencies. Opens in a new browser tab.
 * User clicks "Print / Save as PDF" → browser PDF engine handles layout.
 *
 * GET params: exam_type, date, shift, room_id
 */

ini_set('display_errors', 0);
ob_start();
session_start();
ob_clean();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/lib/schedule-subject.php';

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not logged in.';
    exit;
}

// ── Helpers ───────────────────────────────────────────────────────────────

function pdf_h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function pdf_norm_exam_type(string $v): string
{
    $t = strtolower(trim($v));
    return in_array($t, ['back','repeat','midsem','end','practical'], true) ? $t : '';
}

function pdf_norm_shift(string $v): string
{
    $s = ucfirst(strtolower(trim($v)));
    return in_array($s, ['Morning','Afternoon','Evening'], true) ? $s : '';
}

function pdf_year_label(int $semester): string
{
    return $semester > 0 ? 'Year ' . (int)ceil($semester / 2) : 'N/A';
}

function pdf_subject_label(string $name, string $code): string
{
    if ($name === '' && $code === '') return '-';
    if ($code === '') return $name;
    if ($name === '') return $code;
    return $name . ' (' . $code . ')';
}

function pdf_exam_type_sql_case(): string
{
    return "CASE
        WHEN LOWER(exam_name) LIKE '%practical%' THEN 'practical'
        WHEN LOWER(exam_name) LIKE '%repeat%'    THEN 'repeat'
        WHEN LOWER(exam_name) LIKE '%back%'      THEN 'back'
        WHEN LOWER(exam_name) LIKE '%mid%'       THEN 'midsem'
        WHEN LOWER(exam_name) LIKE '%end%' OR LOWER(exam_name) LIKE '%final%' THEN 'end'
        ELSE 'midsem'
    END";
}

// ── Input ─────────────────────────────────────────────────────────────────

$examType = pdf_norm_exam_type($_GET['exam_type'] ?? '');
$examDate = trim($_GET['date'] ?? '');
$shift    = pdf_norm_shift($_GET['shift'] ?? '');
$roomId   = (int)($_GET['room_id'] ?? 0);

if ($examType === '' || $examDate === '' || $shift === '' || $roomId <= 0) {
    http_response_code(400);
    echo '<p style="font-family:sans-serif;padding:20px">Missing required parameters: exam_type, date, shift, room_id</p>';
    exit;
}

$con = getConnection();
$con->set_charset('utf8mb4');

// ── Fetch room ────────────────────────────────────────────────────────────

$roomQ = $con->query(
    "SELECT room_id, room_no, capacity, building, matrix_rows, matrix_cols,
            blocked_seats_json, layout_notes,
            COALESCE(whiteboard_area,'front') AS whiteboard_area
     FROM rooms WHERE room_id = {$roomId} LIMIT 1"
);
$room = $roomQ ? $roomQ->fetch_assoc() : null;
if (!$room) {
    echo '<p style="font-family:sans-serif;padding:20px">Room not found.</p>';
    exit;
}

// ── Resolve exam record ───────────────────────────────────────────────────

$exam = null;

// Try matrix link first
$mq = $con->prepare(
    "SELECT e.exam_id, e.exam_name, e.date, e.session
     FROM exams e
     JOIN exam_schedule_matrix m ON m.exam_id = e.exam_id
     WHERE m.exam_type = ? AND m.exam_date = ? AND m.shift = ?
     ORDER BY e.exam_id DESC LIMIT 1"
);
$mq->bind_param('sss', $examType, $examDate, $shift);
$mq->execute();
$exam = $mq->get_result()->fetch_assoc();
$mq->close();

if (!$exam) {
    $caseExpr = pdf_exam_type_sql_case();
    $eq = $con->prepare(
        "SELECT exam_id, exam_name, date, session FROM exams
         WHERE date = ? AND session = ? AND {$caseExpr} = ?
         ORDER BY exam_id DESC LIMIT 1"
    );
    $eq->bind_param('sss', $examDate, $shift, $examType);
    $eq->execute();
    $exam = $eq->get_result()->fetch_assoc();
    $eq->close();
}

if (!$exam) {
    echo '<p style="font-family:sans-serif;padding:20px">No exam found for the selected slot.</p>';
    exit;
}

$examId = (int)$exam['exam_id'];

// ── Fetch subjects (per-branch) ───────────────────────────────────────────

$subjectPayload  = sp_fetch_subject_payload($con, $examId, $examType, $examDate, $shift);
$defaultSubjName = (string)($subjectPayload['subject_name'] ?? '');
$defaultSubjCode = (string)($subjectPayload['subject_code'] ?? '');
$branchSubjects  = (array)($subjectPayload['branch_subjects'] ?? []);

// ── Fetch students ────────────────────────────────────────────────────────

$students = [];
$stQ = $con->query(
    "SELECT sa.seat_no, sa.row_no, s.roll_no, s.name, s.branch, s.branch_code, s.semester, s.section
     FROM seating_allocation sa
     JOIN students s ON sa.student_id = s.student_id
     WHERE sa.exam_id = {$examId} AND sa.room_id = {$roomId}
     ORDER BY sa.seat_no ASC"
);
if ($stQ) {
    while ($r = $stQ->fetch_assoc()) {
        $r['seat_no']   = (int)$r['seat_no'];
        $r['row_no']    = (int)($r['row_no'] ?? 1);
        $r['year_label'] = pdf_year_label((int)($r['semester'] ?? 0));
        $students[]     = $r;
    }
}

// ── Fetch invigilators ────────────────────────────────────────────────────

$invigilators = [];
$invQ = $con->prepare(
    "SELECT ia.duty_type, f.name
     FROM invigilation_allocation ia
     JOIN faculty f ON f.faculty_id = ia.faculty_id
     WHERE ia.exam_id = ? AND ia.room_id = ?
     ORDER BY ia.duty_type ASC, f.name ASC"
);
$invQ->bind_param('ii', $examId, $roomId);
$invQ->execute();
$invRes = $invQ->get_result();
while ($r = $invRes->fetch_assoc()) $invigilators[] = $r;
$invQ->close();

closeConnection($con);

// ── Decode blocked seats ──────────────────────────────────────────────────

$blockedRaw = json_decode((string)($room['blocked_seats_json'] ?? '[]'), true);
$blocked    = [];
if (is_array($blockedRaw)) {
    foreach ($blockedRaw as $s) {
        $id = strtoupper(trim((string)$s));
        if (preg_match('/^R\d+C\d+$/', $id)) $blocked[$id] = true;
    }
}

// ── Build seat map ────────────────────────────────────────────────────────

$bySeat     = [];
foreach ($students as $s) $bySeat[(int)$s['seat_no']] = $s;

$matrixRows = (int)($room['matrix_rows'] ?? 0);
$matrixCols = (int)($room['matrix_cols'] ?? 0);
$capacity   = (int)$room['capacity'];

if ($matrixRows <= 0 || $matrixCols <= 0) {
    $matrixCols = max(4, (int)ceil(sqrt(max($capacity, 1))));
    $matrixRows = (int)ceil($capacity / $matrixCols);
}

// ── Summary ───────────────────────────────────────────────────────────────

$totalSeated = count($students);
$branchMap   = [];
$yearMap     = [];

foreach ($students as $s) {
    $branch     = $s['branch'] ?: 'Unknown';
    $branchCode = strtoupper(trim((string)($s['branch_code'] ?? '')));
    if ($branchCode === '') $branchCode = strtoupper($branch);
    $yearLabel  = $s['year_label'];

    if (!isset($branchMap[$branch])) {
        $subj = $branchSubjects[$branchCode] ?? null;
        $branchMap[$branch] = [
            'subject_name' => $subj ? (string)($subj['subject_name'] ?? $defaultSubjName) : $defaultSubjName,
            'subject_code' => $subj ? (string)($subj['subject_code'] ?? $defaultSubjCode) : $defaultSubjCode,
            'count'        => 0,
        ];
    }
    $branchMap[$branch]['count']++;

    if (!isset($yearMap[$yearLabel])) $yearMap[$yearLabel] = 0;
    $yearMap[$yearLabel]++;
}
ksort($branchMap);
ksort($yearMap);

// ── Invigilator string ────────────────────────────────────────────────────

$invStr = empty($invigilators)
    ? 'Not assigned'
    : implode(', ', array_map(fn($i) => pdf_h($i['name']) . ' (' . pdf_h($i['duty_type']) . ')', $invigilators));

$wbArea  = strtolower(trim((string)($room['whiteboard_area'] ?? 'front')));
$wbLabel = $wbArea === 'none' ? 'Teacher Area' : 'Whiteboard (' . ucfirst($wbArea) . ') / Teacher Area';

$multiBranch = count($branchMap) > 1;

// ── Seat grid HTML ────────────────────────────────────────────────────────

// Branch colour palette
$palette = ['#fff4cc','#dff5ff','#e9e3ff','#dcffe4','#ffe5d8','#f5e7d7','#f9dcf2','#e4f0d8','#f0ecff','#d9f7f0'];
$branchColors = [];
$ci = 0;
foreach (array_keys($branchMap) as $br) {
    $branchColors[$br] = $palette[$ci % count($palette)];
    $ci++;
}

$gridCells = '';
// Loop ROW-FIRST so the CSS grid renders cells in the correct visual order
// (left-to-right, top-to-bottom). Seat numbering is column-first:
//   seat_no = (col - 1) * matrixRows + row
// This matches exactly how the UI seating plan renders seats.
for ($r = 1; $r <= $matrixRows; $r++) {
    for ($c = 1; $c <= $matrixCols; $c++) {
        $seatKey   = "R{$r}C{$c}";
        $seatNo    = (($c - 1) * $matrixRows) + $r;
        $isBlocked = isset($blocked[$seatKey]);
        $student   = $bySeat[$seatNo] ?? null;

        if ($isBlocked) {
            $gridCells .= '<div class="seat blocked">'
                . '<div class="sno">Seat ' . $seatNo . '</div>'
                . '<div class="sline">Blocked</div>'
                . '</div>';
        } elseif ($student) {
            $bg = $branchColors[$student['branch']] ?? '#eef4ff';
            $gridCells .= '<div class="seat filled" style="background:' . $bg . '">'
                . '<div class="sno">Seat ' . $seatNo . '</div>'
                . '<div class="sline">Roll: ' . pdf_h($student['roll_no']) . '</div>'
                . '<div class="sline">Branch: ' . pdf_h($student['branch']) . '</div>'
                . '<div class="sline">Year: ' . pdf_h($student['year_label']) . '</div>'
                . '</div>';
        } else {
            $gridCells .= '<div class="seat empty">'
                . '<div class="sno">Seat ' . $seatNo . '</div>'
                . '<div class="sline">—</div>'
                . '</div>';
        }
    }
}

// ── Branch summary rows ───────────────────────────────────────────────────

$branchRows = '';
foreach ($branchMap as $br => $data) {
    $cnt       = $data['count'];
    $branchRows .= '<tr>'
        . '<td>' . pdf_h($br) . '</td>'
  . '<td>' . pdf_h((string)($data['subject_name'] ?? '-')) . '</td>'
  . '<td>' . pdf_h((string)($data['subject_code'] ?? '-')) . '</td>'
        . '<td>' . $cnt . '</td>'
        . '<td>' . $cnt . '</td>'
        . '<td>' . $cnt . '</td>'
        . '</tr>';
}

// ── Year summary rows ─────────────────────────────────────────────────────

$yearRows = '';
foreach ($yearMap as $yr => $cnt) {
    $yearRows .= '<tr>'
        . '<td>' . pdf_h($yr) . '</td>'
        . '<td>' . $cnt . '</td>'
        . '<td>' . $cnt . '</td>'
        . '<td>' . $cnt . '</td>'
        . '</tr>';
}

// ── Format date ───────────────────────────────────────────────────────────

$ts      = strtotime($examDate);
$fmtDate = $ts ? date('d M Y', $ts) : $examDate;

// ── Output ────────────────────────────────────────────────────────────────

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Seating Plan — <?= pdf_h($room['room_no']) ?> — <?= pdf_h($examDate) ?></title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }

  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 10.5px;
    color: #1a2535;
    background: #fff;
    padding: 10px;
  }

  /* Print bar — hidden when printing */
  .print-bar {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 10px;
    padding: 8px 12px;
    background: #f0f6ff;
    border: 1px solid #c8ddf0;
    border-radius: 8px;
  }
  .print-bar button {
    padding: 8px 18px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
  }
  .btn-print { background: #1f6aa5; color: #fff; }
  .btn-close  { background: #e5e7eb; color: #374151; }
  .print-bar span { font-size: 12px; color: #4b6279; }

  /* Header */
  .pdf-header {
    text-align: center;
    border-bottom: 2px solid #1f3a5f;
    padding-bottom: 8px;
    margin-bottom: 10px;
  }
  .pdf-header h1 { font-size: 18px; color: #1f3a5f; }
  .pdf-header .subtitle { font-size: 12px; color: #4b6279; margin-top: 3px; }

  /* Details */
  .details-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px 16px;
    padding: 8px 10px;
    background: #f7fbff;
    border: 1px solid #d7e5f3;
    border-radius: 8px;
    margin-bottom: 10px;
  }
  .detail-row { display: flex; gap: 6px; }
  .detail-label { font-weight: 700; color: #2c4a6e; min-width: 120px; font-size: 11px; }
  .detail-value { color: #1a2535; font-size: 12px; }

  /* Stats */
  .stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin-bottom: 10px;
  }
  .stat-box {
    border: 1px solid #d7e5f3;
    border-radius: 8px;
    padding: 6px 8px;
    background: #f7fbff;
    text-align: center;
  }
  .stat-label { font-size: 10px; color: #5f7a96; text-transform: uppercase; letter-spacing: 0.04em; }
  .stat-value { font-size: 20px; font-weight: 800; color: #1f3a5f; line-height: 1.2; margin-top: 2px; }

  /* Summary tables */
  .summary-section {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 10px;
  }
  .summary-wrap h3 {
    font-size: 12px;
    color: #1f3a5f;
    margin-bottom: 5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }
  .summary-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 10px;
  }
  .summary-table th {
    background: #1f3a5f;
    color: #fff;
    padding: 4px 6px;
    text-align: left;
    font-weight: 600;
  }
  .summary-table td {
    padding: 4px 6px;
    border-bottom: 1px solid #e8eef5;
  }
  .summary-table tr:nth-child(even) td { background: #f7fbff; }

  /* Room layout */
  .whiteboard-bar {
    text-align: center;
    font-weight: 700;
    font-size: 11px;
    padding: 5px;
    border: 2px dashed #6f8fb0;
    border-radius: 6px;
    background: #dfeeff;
    color: #1f3a56;
    margin-bottom: 8px;
  }
  .seat-grid {
    display: grid;
    gap: 5px;
    grid-template-columns: repeat(<?= (int)$matrixCols ?>, minmax(0, 1fr));
  }
  .seat {
    border: 1px solid #b0c4d8;
    border-radius: 5px;
    padding: 3px 5px;
    min-height: 52px;
    font-size: 9px;
    line-height: 1.25;
  }
  .seat.filled { border-color: #7aabcf; }
  .seat.blocked { background: #f3f5f8; border-style: dashed; color: #8a9aaa; }
  .seat.empty   { background: #fafbfc; color: #aab4be; border-style: dashed; }
  .sno   { font-weight: 700; color: #2c4a6e; font-size: 9px; text-transform: uppercase; margin-bottom: 2px; }
  .sline { color: #1a2535; }

  /* Footer */
  .layout-footer {
    font-size: 10px;
    color: #5f7a96;
    margin-top: 6px;
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
  }
  .layout-footer strong { color: #1f3a5f; }

  /* Print */
  @media print {
    .print-bar { display: none !important; }
    body {
      padding: 0;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .details-grid,
    .stats-row,
    .summary-section,
    .seat-grid,
    .layout-footer,
    .whiteboard-bar,
    .pdf-header {
      break-inside: avoid;
      page-break-inside: avoid;
    }
  }
  @page { size: A4 landscape; margin: 8mm; }
</style>
</head>
<body>

<div class="print-bar">
  <button class="btn-print" onclick="window.print()">🖨 Print / Save as PDF</button>
  <button class="btn-close" onclick="window.close()">✕ Close</button>
  <span>In the print dialog, choose <strong>Save as PDF</strong> for a downloadable file.</span>
</div>

<!-- Header -->
<div class="pdf-header">
  <h1>Seating Plan</h1>
  <div class="subtitle">
    <?= pdf_h($exam['exam_name']) ?>
    <?php if ($defaultSubjName !== ''): ?>
      &nbsp;·&nbsp; <?= pdf_h(pdf_subject_label($defaultSubjName, $defaultSubjCode)) ?>
    <?php endif; ?>
  </div>
</div>

<!-- Details -->
<div class="details-grid">
  <div class="detail-row">
    <span class="detail-label">Exam:</span>
    <span class="detail-value"><?= pdf_h($exam['exam_name']) ?></span>
  </div>
  <div class="detail-row">
    <span class="detail-label">Date:</span>
    <span class="detail-value"><?= pdf_h($fmtDate) ?></span>
  </div>
  <div class="detail-row">
    <span class="detail-label">Subject Name:</span>
    <span class="detail-value"><?= pdf_h($defaultSubjName !== '' ? $defaultSubjName : '-') ?></span>
  </div>
  <div class="detail-row">
    <span class="detail-label">Subject Code:</span>
    <span class="detail-value"><?= pdf_h($defaultSubjCode !== '' ? $defaultSubjCode : '-') ?></span>
  </div>
  <div class="detail-row">
    <span class="detail-label">Room:</span>
    <span class="detail-value"><?= pdf_h($room['room_no']) ?> (<?= pdf_h($room['building']) ?>)</span>
  </div>
  <div class="detail-row">
    <span class="detail-label">Invigilator(s):</span>
    <span class="detail-value"><?= $invStr ?></span>
  </div>
</div>

<!-- Stats -->
<div class="stats-row">
  <div class="stat-box"><div class="stat-label">Students Seated</div><div class="stat-value"><?= $totalSeated ?></div></div>
  <div class="stat-box"><div class="stat-label">Question Papers</div><div class="stat-value"><?= $totalSeated ?></div></div>
  <div class="stat-box"><div class="stat-label">Answer Sheets</div><div class="stat-value"><?= $totalSeated ?></div></div>
  <div class="stat-box"><div class="stat-label">Room Capacity</div><div class="stat-value"><?= $capacity ?></div></div>
</div>

<!-- Branch + Year Summary -->
<div class="summary-section">
  <div class="summary-wrap">
    <h3>Branch Summary</h3>
    <table class="summary-table">
      <thead><tr><th>Branch</th><th>Subject Name</th><th>Subject Code</th><th>Students count</th><th>Question Papers</th><th>Answer Sheets</th></tr></thead>
      <tbody><?= $branchRows ?: '<tr><td colspan="6">No students seated.</td></tr>' ?></tbody>
    </table>
  </div>
  <div class="summary-wrap">
    <h3>Year Summary</h3>
    <table class="summary-table">
      <thead><tr><th>Year</th><th>Student count</th><th>Question Papers</th><th>Answer Sheets</th></tr></thead>
      <tbody><?= $yearRows ?: '<tr><td colspan="4">No students seated.</td></tr>' ?></tbody>
    </table>
  </div>
</div>

<!-- Room Layout -->
<div class="whiteboard-bar"><?= pdf_h($wbLabel) ?></div>
<div class="seat-grid"><?= $gridCells ?></div>
<div class="layout-footer">
  Columns: <strong><?= $matrixCols ?></strong>
  &nbsp;|&nbsp; Rows: <strong><?= $matrixRows ?></strong>
  &nbsp;|&nbsp; Seated: <strong><?= $totalSeated ?></strong>
  &nbsp;|&nbsp; Capacity: <strong><?= $capacity ?></strong>
  <?php if ($multiBranch): ?>
    &nbsp;|&nbsp; <em>Adjacent columns have different groups (anti-cheating)</em>
  <?php endif; ?>
</div>

</body>
</html>

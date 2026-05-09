<?php

function sp_pdf_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function sp_pdf_subject_label(array $row): string
{
    $name = trim((string)($row['subject_name'] ?? ''));
    $code = trim((string)($row['subject_code'] ?? ''));
    if ($name === '' && $code === '') {
        return '-';
    }
    if ($code === '') {
        return $name;
    }
    if ($name === '') {
        return $code;
    }
    return $name . ' (' . $code . ')';
}

function buildSeatingPlanPdfHtml(array $plan): string
{
    $invigilators = (array)($plan['invigilators'] ?? []);
    $students = (array)($plan['students'] ?? []);
    $branchSummary = (array)($plan['branch_summary'] ?? []);
    $yearSummary = (array)($plan['year_summary'] ?? []);

    $invigilatorText = 'Not assigned';
    if (count($invigilators) > 0) {
        $parts = [];
        foreach ($invigilators as $inv) {
            $name = trim((string)($inv['name'] ?? '-'));
            $role = trim((string)($inv['duty_type'] ?? 'Invigilator'));
            $parts[] = $name . ' (' . $role . ')';
        }
        $invigilatorText = implode(', ', $parts);
    }

    $subjectName = trim((string)($plan['subject_name'] ?? ''));
    $subjectCode = trim((string)($plan['subject_code'] ?? ''));
    $subjectLabel = sp_pdf_subject_label([
        'subject_name' => $subjectName,
        'subject_code' => $subjectCode,
    ]);

    $detailsRows = [
        ['Exam Name', (string)($plan['exam_name'] ?? '-')],
        ['Subject Name / Subject Code', $subjectLabel],
        ['Date', (string)($plan['date'] ?? '-')],
        ['Session', (string)($plan['session'] ?? '-')],
        ['Room', (string)($plan['room_no'] ?? '-') . ' (' . (string)($plan['building'] ?? '-') . ')'],
        ['Invigilator(s)', $invigilatorText],
    ];

    $rows = (int)($plan['matrix_rows'] ?? 0);
    $cols = (int)($plan['matrix_cols'] ?? 0);
    $capacity = (int)($plan['capacity'] ?? 0);
    $blocked = [];
    foreach ((array)($plan['blocked_seats'] ?? []) as $b) {
        $blocked[strtoupper((string)$b)] = true;
    }

    $bySeat = [];
    foreach ($students as $st) {
        $bySeat[(int)($st['seat_no'] ?? 0)] = $st;
    }

    $seatCells = '';
    for ($r = 1; $r <= $rows; $r++) {
        for ($c = 1; $c <= $cols; $c++) {
            $seatNo = (($c - 1) * $rows) + $r;
            $seatKey = 'R' . $r . 'C' . $c;

            if (isset($blocked[$seatKey])) {
                $seatCells .= '<div class="seat blocked"><div class="seat-no">Seat ' . $seatNo . '</div><div class="seat-line">Blocked</div></div>';
                continue;
            }

            $st = $bySeat[$seatNo] ?? null;
            if (!$st) {
                $seatCells .= '<div class="seat empty"><div class="seat-no">Seat ' . $seatNo . '</div><div class="seat-line">Unassigned</div></div>';
                continue;
            }

            $seatCells .= '<div class="seat filled">'
                . '<div class="seat-no">Seat ' . $seatNo . '</div>'
                . '<div class="seat-line">Roll: ' . sp_pdf_escape((string)($st['roll_no'] ?? '-')) . '</div>'
                . '<div class="seat-line">Branch: ' . sp_pdf_escape((string)($st['branch'] ?? '-')) . '</div>'
                . '<div class="seat-line">Year: ' . sp_pdf_escape((string)($st['year_label'] ?? '-')) . '</div>'
                . '</div>';
        }
    }

    $branchRowsHtml = '';
    foreach ($branchSummary as $row) {
        $branchRowsHtml .= '<tr>'
            . '<td>' . sp_pdf_escape((string)($row['group'] ?? '-')) . '</td>'
            . '<td>' . sp_pdf_escape(sp_pdf_subject_label($row)) . '</td>'
            . '<td>' . (int)($row['students_count'] ?? 0) . '</td>'
            . '<td>' . (int)($row['question_papers'] ?? 0) . '</td>'
            . '<td>' . (int)($row['answer_sheets'] ?? 0) . '</td>'
            . '</tr>';
    }

    $yearRowsHtml = '';
    foreach ($yearSummary as $row) {
        $yearRowsHtml .= '<tr>'
            . '<td>' . sp_pdf_escape((string)($row['year_group'] ?? '-')) . '</td>'
            . '<td>' . (int)($row['students_count'] ?? 0) . '</td>'
            . '<td>' . (int)($row['question_papers'] ?? 0) . '</td>'
            . '<td>' . (int)($row['answer_sheets'] ?? 0) . '</td>'
            . '</tr>';
    }

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Seating Plan</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #1f2937; margin: 0; padding: 16px; }
  .title { text-align: center; font-size: 24px; font-weight: 700; margin: 0 0 12px; }
  .panel { border: 1px solid #d0d7e2; border-radius: 8px; padding: 10px; margin-bottom: 12px; }
  .details-grid { width: 100%; border-collapse: collapse; }
  .details-grid td { padding: 4px 6px; vertical-align: top; }
  .details-grid td:first-child { width: 220px; font-weight: 700; color: #1f3b6d; }
  .stats { width: 100%; border-collapse: collapse; }
  .stats td { border: 1px solid #d0d7e2; padding: 8px; }
  .stats .lbl { font-size: 11px; color: #44546a; text-transform: uppercase; }
  .stats .val { font-size: 18px; font-weight: 800; margin-top: 2px; }
  .section-title { font-size: 13px; font-weight: 700; margin: 0 0 6px; }
  .summary { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  .summary th, .summary td { border: 1px solid #d0d7e2; padding: 6px; text-align: left; }
  .summary th { background: #ecf2fb; }
  .front { text-align: center; border: 2px dashed #6f8fb0; padding: 8px; border-radius: 8px; margin-bottom: 8px; font-weight: 700; background: #eef5ff; }
  .seat-grid { display: grid; gap: 6px; grid-template-columns: repeat(<?php echo (int)$cols; ?>, minmax(0, 1fr)); }
  .seat { border: 1px solid #b5c5db; border-radius: 6px; padding: 6px; min-height: 64px; background: #f8fbff; }
  .seat.filled { background: #eef4ff; }
  .seat.blocked { background: #f3f6fb; border-style: dashed; }
  .seat.empty { background: #f9fafb; border-style: dashed; }
  .seat-no { font-size: 10px; font-weight: 700; margin-bottom: 4px; text-transform: uppercase; }
  .seat-line { font-size: 11px; line-height: 1.25; }
  .footer-note { margin-top: 8px; font-size: 11px; color: #334155; }
</style>
</head>
<body>
  <h1 class="title">Seating Plan</h1>

  <div class="panel">
    <table class="details-grid">
      <?php foreach ($detailsRows as $detail): ?>
      <tr>
        <td><?php echo sp_pdf_escape($detail[0]); ?></td>
        <td><?php echo sp_pdf_escape($detail[1]); ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <table class="stats">
    <tr>
      <td><div class="lbl">Students Seated</div><div class="val"><?php echo (int)($plan['student_count'] ?? 0); ?></div></td>
      <td><div class="lbl">Question Papers</div><div class="val"><?php echo (int)($plan['student_count'] ?? 0); ?></div></td>
      <td><div class="lbl">Answer Sheets</div><div class="val"><?php echo (int)($plan['student_count'] ?? 0); ?></div></td>
      <td><div class="lbl">Room Capacity</div><div class="val"><?php echo (int)($plan['capacity'] ?? 0); ?></div></td>
    </tr>
  </table>

  <div class="panel" style="margin-top:12px;">
    <h2 class="section-title">Branch Summary</h2>
    <table class="summary">
      <thead>
        <tr>
          <th>Group (Branch)</th>
          <th>Subject</th>
          <th>Students count</th>
          <th>Question Papers</th>
          <th>Answer Sheets</th>
        </tr>
      </thead>
      <tbody><?php echo $branchRowsHtml !== '' ? $branchRowsHtml : '<tr><td colspan="5">No seated students.</td></tr>'; ?></tbody>
    </table>

    <h2 class="section-title">Year Summary</h2>
    <table class="summary">
      <thead>
        <tr>
          <th>Year group</th>
          <th>Students count</th>
          <th>Question Papers</th>
          <th>Answer Sheets</th>
        </tr>
      </thead>
      <tbody><?php echo $yearRowsHtml !== '' ? $yearRowsHtml : '<tr><td colspan="4">No seated students.</td></tr>'; ?></tbody>
    </table>
  </div>

  <div class="panel">
    <div class="front">Whiteboard (front) / Teacher Area</div>
    <div class="seat-grid"><?php echo $seatCells; ?></div>
    <div class="footer-note">
      Columns count: <?php echo (int)$cols; ?> | Rows count: <?php echo (int)$rows; ?> |
      Seated count: <?php echo (int)($plan['student_count'] ?? 0); ?> | Capacity: <?php echo (int)$capacity; ?>
      <?php if ((bool)($plan['multi_branch'] ?? false)): ?>
      | Multi-branch note: Adjacent columns have different groups
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}

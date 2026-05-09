<?php

/**
 * Resolve subject metadata for a seating slot.
 * Fetches per-branch subjects from exam_schedule_matrix using
 * exam_type + exam_date + shift so ALL branches in the slot are covered,
 * regardless of which exam_id they are linked to.
 */
function sp_fetch_subject_payload($con, int $examId, string $examType, string $examDate, string $shift): array
{
    $rows = [];

    // Primary: fetch by exam_type + exam_date + shift — covers ALL branches in this slot
    $q = $con->prepare(
        "SELECT branch_code, subject_name, subject_code, semester
         FROM exam_schedule_matrix
         WHERE exam_type = ? AND exam_date = ? AND shift = ?
         ORDER BY branch_code ASC, semester ASC, matrix_id ASC"
    );
    if ($q) {
        $q->bind_param('sss', $examType, $examDate, $shift);
        $q->execute();
        $res = $q->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $q->close();
    }

    // Fallback: if nothing found by slot, try by exam_id
    if (count($rows) === 0 && $examId > 0) {
        $q = $con->prepare(
            "SELECT branch_code, subject_name, subject_code, semester
             FROM exam_schedule_matrix
             WHERE exam_id = ?
             ORDER BY branch_code ASC, semester ASC, matrix_id ASC"
        );
        if ($q) {
            $q->bind_param('i', $examId);
            $q->execute();
            $res = $q->get_result();
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
            $q->close();
        }
    }

    $subjectName    = '';
    $subjectCode    = '';
    $branchSubjects = [];

    foreach ($rows as $row) {
        $name       = trim((string)($row['subject_name'] ?? ''));
        $code       = trim((string)($row['subject_code'] ?? ''));
        $branchCode = strtoupper(trim((string)($row['branch_code'] ?? '')));

        // First non-empty subject becomes the default
        if ($subjectName === '' && $name !== '') {
            $subjectName = $name;
            $subjectCode = $code;
        }

        // Map each branch to its own subject (first occurrence wins)
        if ($branchCode !== '' && !isset($branchSubjects[$branchCode])) {
            $branchSubjects[$branchCode] = [
                'subject_name' => $name,
                'subject_code' => $code,
            ];
        }
    }

    // Last fallback: exam_schedule table
    if ($subjectName === '' && $examId > 0) {
        $q = $con->prepare(
            "SELECT subject_name
             FROM exam_schedule
             WHERE exam_id = ?
             ORDER BY schedule_id ASC
             LIMIT 1"
        );
        if ($q) {
            $q->bind_param('i', $examId);
            $q->execute();
            $fallback = $q->get_result()->fetch_assoc();
            $q->close();
            if ($fallback) {
                $subjectName = trim((string)($fallback['subject_name'] ?? ''));
            }
        }
    }

    return [
        'subject_name'    => $subjectName,
        'subject_code'    => $subjectCode,
        'branch_subjects' => $branchSubjects,
    ];
}

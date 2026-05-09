<?php
/**
 * Chatbot API — Fixed keyword responses, role-based, no AI.
 *
 * POST body: { "message": "user text" }
 * Response:  { "success": true, "response": "...", "action_url": "..." }
 *
 * action_url is relative to the project root (e.g. "modules/seating.html").
 * The JS in script.js prepends the correct base path before navigating.
 */

ini_set('display_errors', 0);
ob_start();
session_start();
ob_clean();

header('Content-Type: application/json');

function cb_respond(array $payload): void
{
    echo json_encode($payload);
    exit;
}

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    cb_respond(['success' => false, 'message' => 'Not logged in']);
}

$role = strtolower(trim((string)($_SESSION['role'] ?? 'admin')));
if ($role === 'exam_cell') $role = 'admin';
if (!in_array($role, ['admin', 'faculty', 'student'], true)) $role = 'admin';

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$message = strtolower(trim((string)($body['message'] ?? '')));

if ($message === '') {
    cb_respond(['success' => false, 'message' => 'Message is required']);
}

// ─────────────────────────────────────────────────────────────────────────────
// RULES — each rule has:
//   keywords   : string[]  — any substring match triggers this rule
//   response   : string    — text shown in the chat bubble
//   action_url : string|null — relative URL to navigate to (null = no redirect)
// ─────────────────────────────────────────────────────────────────────────────

$rules = [

    // ── ADMIN ─────────────────────────────────────────────────────────────────
    'admin' => [
        [
            'keywords'   => ['generate seating', 'seating plan', 'seat allocation', 'allocate seat'],
            'response'   => 'Opening the Seating Allocation module.',
            'action_url' => 'modules/seating.html',
        ],
        [
            'keywords'   => ['view reports', 'reports', 'analytics', 'statistics'],
            'response'   => 'Opening Reports & Analytics.',
            'action_url' => 'modules/reports.html',
        ],
        [
            'keywords'   => ['assign invigilators', 'invigilation', 'invigilator', 'duty assignment'],
            'response'   => 'Opening the Invigilation module.',
            'action_url' => 'modules/invigilation.html',
        ],
        [
            'keywords'   => ['mark attendance', 'attendance'],
            'response'   => 'Opening the Attendance module.',
            'action_url' => 'modules/attendance.html',
        ],
        [
            'keywords'   => ['add student', 'students', 'student list', 'student management'],
            'response'   => 'Opening the Students module.',
            'action_url' => 'modules/students.html',
        ],
        [
            'keywords'   => ['add faculty', 'faculty list', 'faculty management'],
            'response'   => 'Opening the Faculty module.',
            'action_url' => 'modules/faculty.html',
        ],
        [
            'keywords'   => ['add room', 'rooms', 'room layout', 'room management'],
            'response'   => 'Opening the Rooms module.',
            'action_url' => 'modules/rooms.html',
        ],
        [
            'keywords'   => ['exam schedule', 'datesheet', 'timetable', 'schedule'],
            'response'   => 'Opening the Schedule / Datesheet module.',
            'action_url' => 'modules/schedule.html',
        ],
        [
            'keywords'   => ['replacement', 'replace faculty', 'faculty replacement'],
            'response'   => 'Opening the Replacement module.',
            'action_url' => 'modules/replacement.html',
        ],
        [
            'keywords'   => ['dashboard', 'home', 'overview'],
            'response'   => 'Going to the Dashboard.',
            'action_url' => 'dashboard.html',
        ],
        [
            'keywords'   => ['help', 'what can you do', 'commands', 'options'],
            'response'   => 'Available commands: generate seating, view reports, assign invigilators, attendance, students, faculty, rooms, schedule, replacement, dashboard.',
            'action_url' => null,
        ],
    ],

    // ── FACULTY ───────────────────────────────────────────────────────────────
    'faculty' => [
        [
            'keywords'   => ['my duty', 'my duties', 'invigilation duty', 'assigned room', 'duty'],
            'response'   => 'Opening your Invigilation Duties.',
            'action_url' => 'faculty/duties.php',
        ],
        [
            'keywords'   => ['mark attendance', 'attendance', 'present', 'absent', 'late'],
            'response'   => 'Opening Digital Attendance.',
            'action_url' => 'faculty/attendance.php',
        ],
        [
            'keywords'   => ['request replacement', 'replacement', 'leave', 'substitute', 'replace'],
            'response'   => 'Opening the Replacement Request form.',
            'action_url' => 'faculty/replacement.php',
        ],
        [
            'keywords'   => ['dashboard', 'home'],
            'response'   => 'Going to the Dashboard.',
            'action_url' => 'dashboard.html',
        ],
        [
            'keywords'   => ['help', 'what can you do', 'commands', 'options'],
            'response'   => 'Available commands: my duty, mark attendance, request replacement.',
            'action_url' => null,
        ],
    ],

    // ── STUDENT ───────────────────────────────────────────────────────────────
    'student' => [
        [
            'keywords'   => ['my seat', 'seating', 'seat number', 'room', 'where do i sit', 'seating slip'],
            'response'   => 'Opening your Seating Slip.',
            'action_url' => 'modules/student_seating_slip.html',
        ],
        [
            'keywords'   => ['exam schedule', 'datesheet', 'timetable', 'schedule', 'exam date', 'when is my exam'],
            'response'   => 'Opening your Datesheet.',
            'action_url' => 'modules/student_datesheet.html',
        ],
        [
            'keywords'   => ['admit card', 'hall ticket', 'admit'],
            'response'   => 'Opening your Admit Card.',
            'action_url' => 'modules/student_admit_card.html',
        ],
        [
            'keywords'   => ['attendance', 'present', 'absent'],
            'response'   => 'Your attendance is view-only. Admin and faculty manage attendance records.',
            'action_url' => null,
        ],
        [
            'keywords'   => ['home', 'dashboard', 'portal'],
            'response'   => 'Going to your Student Portal home.',
            'action_url' => 'modules/student_portal.html',
        ],
        [
            'keywords'   => ['help', 'what can you do', 'commands', 'options'],
            'response'   => 'Available commands: my seat, exam schedule, admit card.',
            'action_url' => null,
        ],
    ],
];

// ── Match ─────────────────────────────────────────────────────────────────────

$match = null;
foreach ($rules[$role] as $rule) {
    foreach ($rule['keywords'] as $keyword) {
        if (strpos($message, $keyword) !== false) {
            $match = $rule;
            break 2;
        }
    }
}

if (!$match) {
    $fallback = [
        'admin'   => 'I didn\'t understand that. Try: generate seating, view reports, assign invigilators, attendance, students, faculty, rooms, schedule, or dashboard.',
        'faculty' => 'I didn\'t understand that. Try: my duty, mark attendance, or request replacement.',
        'student' => 'I didn\'t understand that. Try: my seat, exam schedule, or admit card.',
    ];
    cb_respond([
        'success'    => true,
        'role'       => $role,
        'response'   => $fallback[$role],
        'action_url' => null,
        'matched'    => false,
    ]);
}

cb_respond([
    'success'    => true,
    'role'       => $role,
    'response'   => $match['response'],
    'action_url' => $match['action_url'],
    'matched'    => true,
]);

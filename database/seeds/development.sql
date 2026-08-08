-- Development accounts for the Automated Examination Management System.
-- Import database/schema.sql first, then import this file.
-- This seed is idempotent and may be run more than once.

USE exam_management;
START TRANSACTION;

-- Admin: admin / admin123
INSERT INTO users (username, password, role, reference_id)
VALUES ('admin', '$2y$10$XK3XULp8y3XDqEDUra6vPeo.aWMczaKzzkvhwdwx9CGDIY9h610eS', 'admin', NULL)
ON DUPLICATE KEY UPDATE
    password = VALUES(password),
    role = VALUES(role),
    reference_id = NULL;

-- Exam cell: examcell / examcell123
INSERT INTO users (username, password, role, reference_id)
VALUES ('examcell', '$2y$10$nGw1o/U5Rt9UnnGV7jCUaeE1QiFhwhd5uLMqYVTAnB1KOIscQgQ0S', 'exam_cell', NULL)
ON DUPLICATE KEY UPDATE
    password = VALUES(password),
    role = VALUES(role),
    reference_id = NULL;

-- Sample faculty record and login: faculty.demo / 123
INSERT INTO faculty (
    faculty_unique_no, name, department, designation, email, mobile,
    qualification, total_duties
)
VALUES (
    900001, 'Development Faculty', 'Computer Science and Engineering',
    'Assistant Professor', 'faculty.demo@gbu.ac.in', '0000000000',
    'Development account', 0
)
ON DUPLICATE KEY UPDATE
    faculty_id = LAST_INSERT_ID(faculty_id),
    name = VALUES(name),
    department = VALUES(department),
    designation = VALUES(designation),
    email = VALUES(email);

SET @development_faculty_id = LAST_INSERT_ID();

INSERT INTO users (username, password, role, reference_id)
VALUES ('faculty.demo', '$2y$10$hpuniykN7ni9IGaU2aRaqeilr2mRjPmXY1LdMP4E26L/1wvTCTwOu', 'faculty', @development_faculty_id)
ON DUPLICATE KEY UPDATE
    password = VALUES(password),
    role = VALUES(role),
    reference_id = VALUES(reference_id);

-- Sample student record and login: 23UCS999@gbu.ac.in / 123
INSERT INTO students (
    roll_no, username, school, department, name, branch, branch_code,
    admission_year, course_duration_years, program_code, serial_no,
    semester, section
)
VALUES (
    '23UCS999', '23UCS999@gbu.ac.in', 'SOICT',
    'Computer Science and Engineering', 'Development Student',
    'Computer Science and Engineering', 'UCS', 2023, 4, 'UCS', 999, 1, 'A'
)
ON DUPLICATE KEY UPDATE
    student_id = LAST_INSERT_ID(student_id),
    username = VALUES(username),
    name = VALUES(name),
    school = VALUES(school),
    department = VALUES(department),
    branch = VALUES(branch),
    semester = VALUES(semester),
    section = VALUES(section);

SET @development_student_id = LAST_INSERT_ID();

INSERT INTO users (username, password, role, reference_id)
VALUES ('23UCS999@gbu.ac.in', '$2y$10$hpuniykN7ni9IGaU2aRaqeilr2mRjPmXY1LdMP4E26L/1wvTCTwOu', 'student', @development_student_id)
ON DUPLICATE KEY UPDATE
    password = VALUES(password),
    role = VALUES(role),
    reference_id = VALUES(reference_id);

COMMIT;

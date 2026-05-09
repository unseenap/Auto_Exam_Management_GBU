-- Database: exam_management
-- Create database if not exists
CREATE DATABASE IF NOT EXISTS exam_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE exam_management;

-- USERS Table
CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'exam_cell', 'faculty', 'student') NOT NULL,
    reference_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- STUDENTS Table
CREATE TABLE IF NOT EXISTS students (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    roll_no VARCHAR(50) UNIQUE NOT NULL,
    username VARCHAR(100) UNIQUE DEFAULT NULL,
    school VARCHAR(120) NOT NULL DEFAULT 'SOICT',
    department VARCHAR(160) NOT NULL DEFAULT 'Computer Science and Engineering',
    name VARCHAR(200) NOT NULL,
    branch VARCHAR(100) NOT NULL,
    branch_code VARCHAR(20) NOT NULL DEFAULT 'UCS',
    admission_year INT NOT NULL DEFAULT 2023,
    course_duration_years INT NOT NULL DEFAULT 4,
    program_code VARCHAR(10) NOT NULL DEFAULT 'UCS',
    serial_no INT NOT NULL DEFAULT 1,
    semester INT NOT NULL,
    section VARCHAR(10) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_school (school),
    INDEX idx_department (department),
    INDEX idx_roll_no (roll_no),
    INDEX idx_branch (branch),
    INDEX idx_program_code (program_code),
    INDEX idx_admission_year (admission_year),
    INDEX idx_serial_no (serial_no),
    INDEX idx_semester (semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- FACULTY Table
CREATE TABLE IF NOT EXISTS faculty (
    faculty_id INT AUTO_INCREMENT PRIMARY KEY,
    faculty_unique_no INT UNIQUE DEFAULT NULL,
    name VARCHAR(200) NOT NULL,
    department VARCHAR(100) NOT NULL,
    designation VARCHAR(100) NOT NULL,
    email VARCHAR(150) DEFAULT NULL,
    mobile VARCHAR(30) DEFAULT NULL,
    qualification TEXT DEFAULT NULL,
    total_duties INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_department (department),
    INDEX idx_total_duties (total_duties)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ROOMS Table
CREATE TABLE IF NOT EXISTS rooms (
    room_id INT AUTO_INCREMENT PRIMARY KEY,
    room_no VARCHAR(50) UNIQUE NOT NULL,
    capacity INT NOT NULL,
    matrix_rows INT DEFAULT NULL,
    matrix_cols INT DEFAULT NULL,
    blocked_seats_json LONGTEXT DEFAULT NULL,
    layout_notes VARCHAR(255) DEFAULT NULL,
    building VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_room_no (room_no),
    INDEX idx_building (building)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- EXAMS Table
CREATE TABLE IF NOT EXISTS exams (
    exam_id INT AUTO_INCREMENT PRIMARY KEY,
    exam_name VARCHAR(200) NOT NULL,
    date DATE NOT NULL,
    session ENUM('Morning', 'Afternoon') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_date (date),
    INDEX idx_session (session)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- EXAM_SCHEDULE Table
CREATE TABLE IF NOT EXISTS exam_schedule (
    schedule_id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    subject_name VARCHAR(200) NOT NULL,
    exam_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    session ENUM('Morning', 'Afternoon') NOT NULL,
    duration INT NOT NULL COMMENT 'Duration in minutes',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    INDEX idx_exam_date (exam_date),
    INDEX idx_session (session)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SEATING_ALLOCATION Table
CREATE TABLE IF NOT EXISTS seating_allocation (
    allocation_id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    room_id INT NOT NULL,
    seat_no INT NOT NULL,
    row_no INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE,
    UNIQUE KEY unique_seat (exam_id, room_id, seat_no),
    INDEX idx_exam_student (exam_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- INVIGILATION_ALLOCATION Table
CREATE TABLE IF NOT EXISTS invigilation_allocation (
    duty_id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    faculty_id INT NOT NULL,
    room_id INT NOT NULL,
    duty_type VARCHAR(50) NOT NULL DEFAULT 'Invigilator',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    FOREIGN KEY (faculty_id) REFERENCES faculty(faculty_id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE,
    UNIQUE KEY unique_faculty_exam_room (exam_id, faculty_id, room_id),
    INDEX idx_exam_room (exam_id, room_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ATTENDANCE Table
CREATE TABLE IF NOT EXISTS attendance (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    allocation_id INT NOT NULL,
    status ENUM('Present', 'Late', 'Absent') DEFAULT 'Absent',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (allocation_id) REFERENCES seating_allocation(allocation_id) ON DELETE CASCADE,
    INDEX idx_allocation (allocation_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- REPLACEMENT_LOG Table
CREATE TABLE IF NOT EXISTS replacement_log (
    replacement_id INT AUTO_INCREMENT PRIMARY KEY,
    original_faculty_id INT NOT NULL,
    replacement_faculty_id INT DEFAULT NULL,
    exam_id INT NOT NULL,
    room_id INT NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    FOREIGN KEY (original_faculty_id) REFERENCES faculty(faculty_id) ON DELETE CASCADE,
    FOREIGN KEY (replacement_faculty_id) REFERENCES faculty(faculty_id) ON DELETE SET NULL,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_exam (exam_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- EXAM_SCHEDULE_MATRIX Table (University date-sheet matrix)
CREATE TABLE IF NOT EXISTS exam_schedule_matrix (
    matrix_id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT DEFAULT NULL,
    exam_type ENUM('back', 'repeat', 'midsem', 'end', 'practical') NOT NULL,
    school VARCHAR(30) NOT NULL DEFAULT 'SOICT',
    dept VARCHAR(120) NOT NULL DEFAULT 'ICT',
    department VARCHAR(120) DEFAULT NULL,
    branch_code VARCHAR(50) NOT NULL,
    semester INT NOT NULL,
    branch_session VARCHAR(30) NOT NULL DEFAULT '',
    shift ENUM('Morning', 'Afternoon', 'Evening') NOT NULL,
    exam_date DATE NOT NULL,
    start_time TIME DEFAULT NULL,
    end_time TIME DEFAULT NULL,
    subject_code VARCHAR(50) DEFAULT NULL,
    subject_name VARCHAR(255) NOT NULL,
    duration_minutes INT DEFAULT NULL,
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE SET NULL,
    UNIQUE KEY uniq_matrix_slot (exam_type, school, dept, branch_code, semester, branch_session, shift, exam_date),
    INDEX idx_matrix_filter (exam_type, school, dept, shift),
    INDEX idx_matrix_date (exam_date),
    INDEX idx_matrix_exam (exam_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- REPLACEMENT_REQUESTS Table (Faculty replacement workflow)
CREATE TABLE IF NOT EXISTS replacement_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    original_faculty_id INT NOT NULL,
    replacement_faculty_id INT DEFAULT NULL,
    exam_id INT NOT NULL,
    room_id INT NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    FOREIGN KEY (original_faculty_id) REFERENCES faculty(faculty_id) ON DELETE CASCADE,
    FOREIGN KEY (replacement_faculty_id) REFERENCES faculty(faculty_id) ON DELETE SET NULL,
    FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE CASCADE,
    INDEX idx_req_status (status),
    INDEX idx_req_exam_room (exam_id, room_id),
    INDEX idx_req_faculty (original_faculty_id, replacement_faculty_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default admin user (password: admin123)
-- Password hash generated using PHP password_hash('admin123', PASSWORD_DEFAULT)
INSERT INTO users (username, password, role, reference_id) VALUES 
('admin', '$2y$10$xCIejT7riQh7p9ko0HZqpOeXsEviAmc07jjm4DtKyJOVcXhrBUo7u', 'admin', NULL);

-- Insert default exam cell user (password: examcell123)
INSERT INTO users (username, password, role, reference_id) VALUES 
('examcell', '$2y$10$.wZ5nJSWKVnYi27TAoyPEe0FM7ATnJhN7U.aYyE1kmsi6qSp/VLsm', 'exam_cell', NULL);

-- Note: Change default passwords immediately after installation!

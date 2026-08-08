# Automated Examination Seating Management System

## 1. Project Introduction

This project is a web-based examination management system for handling student records, exam scheduling, seating allocation, invigilation duties, attendance, and student portal views in one place. It is designed to replace manual, spreadsheet-driven workflows that are slow, error-prone, and hard to maintain when exam data changes frequently.

Web-based solutions matter here because they centralize data, reduce duplication, and make it easier for different users to work from the same source of truth. The project reflects current practical needs in campus administration, especially where manual seating plans, printed schedules, and room-wise coordination create avoidable delays and conflicts.

## 2. Project Background

### Literature Review

Existing examination workflows are often managed through paper registers, Excel sheets, or semi-automated desktop tools. Those approaches usually work for small batches, but they become difficult to maintain when the number of students, rooms, sessions, and special cases grows.

Common limitations of manual or semi-automated systems include:

- Seat duplication or allocation conflicts
- Time-consuming updates when schedules change
- Difficult room utilization tracking
- Slow attendance and report preparation
- Poor visibility for students who need quick access to their datesheet or seating slip

### Technologies Used

- Frontend: HTML, CSS, JavaScript
- Backend: PHP
- Database: MySQL
- Optional maintenance scripts: Node.js in the `scripts/` folder for CSV/data import and cleanup tasks

## 3. Application / Use of Project

This system can be used in examination seating arrangement, exam timetable publishing, invigilation allocation, attendance handling, and student self-service portals. It can also be adapted for smart campus administration tasks where structured data, role-based access, and printable reports are needed.

Typical users include:

- Admin and Exam Cell staff
- Faculty members
- Students
- Room and timetable coordinators

## 4. Problem Formulation

Manual examination management is time-consuming and error-prone. When seat plans, schedules, or room assignments are updated repeatedly, it becomes difficult to avoid conflicts, duplication, and delays in publishing information to students and staff.

The main gap this project solves is the lack of a single web-based platform that can manage exam data, allocate seats consistently, and provide instant access to printable outputs and student-facing records.

## 5. Objective

- Automate student seating allocation
- Reduce conflicts, duplication, and manual calculation errors
- Generate printable seating slips, datesheets, and admit cards
- Manage exam schedules, rooms, and invigilation duties from one system
- Provide a student portal for quick exam-related access
- Improve reporting and traceability for exam operations

## 6. System Requirements

### Hardware Requirements

- Computer system for development and deployment
- Stable internet or local network connection
- Printer for report and slip output

### Software Requirements

- Web browser such as Chrome, Edge, or Firefox
- XAMPP or WAMP for local PHP and MySQL hosting
- MySQL database server
- PHP 7.4+ or compatible version

## 7. System Design

### Architecture

The system follows a client-server model:

- Client side: browser-based UI built with HTML, CSS, and JavaScript
- Server side: PHP API endpoints handle authentication, data retrieval, and allocation logic
- Database layer: MySQL stores users, students, rooms, exams, seating, attendance, and reports

### Suggested Diagrams

- ER Diagram
- Data Flow Diagram
- Activity Diagram

### Modules

- Authentication and role-based login
- Student management
- Faculty management
- Room management
- Exam schedule management
- Seating allocation
- Invigilation allocation
- Attendance management
- Replacement requests
- Reports and dashboard
- Student portal pages for datesheet, seating slip, and admit card

## 8. Database Design

| Table Name | Description |
|---|---|
| users | Stores login credentials and role information |
| students | Stores student details such as roll number, branch, semester, and session |
| faculty | Stores faculty profile and duty information |
| rooms | Stores room capacity, building, and seating layout data |
| exams | Stores exam master records |
| exam_schedule | Stores exam dates, times, and sessions |
| exam_schedule_matrix | Stores matrix-based datesheet data |
| seating_allocation | Stores student seat assignments |
| invigilation_allocation | Stores faculty duty assignments |
| attendance | Stores attendance status for each allocation |
| replacement_requests | Stores faculty replacement workflow records |

## 9. Interface Design

Include screenshots of these screens in the final report or project submission:

- Login page
- Admin dashboard
- Student portal home
- Student datesheet page
- Seating slip page
- Admit card page
- Forms for students, rooms, faculty, and schedules
- Output screens for seating charts and reports

## 10. Working of the Project

1. The admin logs in and manages students, faculty, rooms, and exam schedules.
2. The system processes the entered exam and room data.
3. Seating allocation is generated automatically and stored in the database.
4. Invigilation duties and attendance can be managed from the admin side.
5. Students log in to view their datesheet, seating slip, and admit card.
6. Reports and printable outputs are displayed or downloaded as needed.

## 11. Algorithm / Flowchart

### Simple Flow

Input -> Validate -> Process -> Store -> Display -> Print

### Seating Allocation Logic

1. Read student records and room capacity
2. Sort students by roll number
3. Assign students to available rooms sequentially
4. Store the final allocation
5. Generate printable seating output

### Student Portal Logic

1. Student logs in
2. System fetches profile and allocation data
3. Datesheet, seating slip, and admit card are rendered
4. User prints or downloads the required output

## 12. Future Scope

- Mobile app integration
- AI-based allocation and conflict detection
- Cloud deployment for multi-campus access
- Automated notification system for schedule changes
- QR-based admit card verification
- Advanced analytics for room and exam utilization

## Technology Stack

- Frontend: HTML5, CSS3, Vanilla JavaScript
- Backend: PHP
- Database: MySQL
- Local deployment: XAMPP-compatible

## Default Login Credentials

| Role | Username | Password | Access Level |
|------|----------|----------|--------------|
| Admin | admin | admin123 | Full system access |
| Student | Roll number | student123 | Student portal only |
| Faculty | fac1, fac2, etc. | faculty123 | Duty and replacement management |
| Exam Cell | examcell | examcell123 | Exam management access |

## Core Modules

1. Authentication system with role-based access
2. Student and faculty management
3. Room and exam schedule management
4. Seating allocation and printable slips
5. Invigilation allocation and replacement requests
6. Attendance tracking
7. Reporting dashboard
8. Student portal for datesheet, seating slip, and admit card

## Note on Node.js

The running web application does not depend on Node.js. A few utility scripts in `scripts/` use Node.js and `mysql2` for import or cleanup tasks, so those files are kept only as optional maintenance tools.

## Project Structure

- `api/` - PHP endpoints for authentication, students, seating, reports, and related modules
- `config/` - Database connection setup
- `css/` - Shared styling
- `data/` - Source data used by import scripts
<<<<<<< HEAD
- `database/` - Schema and sample SQL seed files
=======
- `database/` - Database schema file
>>>>>>> 5c0461a (Version 1.0)
- `js/` - Shared front-end behavior
- `modules/` - Admin and student-facing HTML pages
- `scripts/` - Optional Node.js maintenance/import scripts
- `README.md` - Project documentation
<<<<<<< HEAD
=======

## Diagrams

Project-specific Architecture, ER, Data Flow, Activity, and Client-Server diagrams are available in `DIAGRAMS.md`.
>>>>>>> 5c0461a (Version 1.0)

## Troubleshooting

### Common Issues

1. **Database connection error**
   - Check if MySQL is running in XAMPP
   - Verify database credentials in `config/database.php`
   - Ensure database `exam_management` exists

2. **Login not working**
   - Clear browser cache and cookies
   - Check if admin user exists in database
   - Verify password is `admin123`

3. **CSV upload fails**
   - Check CSV format matches: Roll No, Name, Branch, Semester, Section
   - Ensure first row is header
   - Check for duplicate roll numbers

4. **Seating allocation fails**
   - Ensure rooms are added with capacity
   - Ensure students exist in database
   - Check total room capacity >= total students

5. **Invigilation allocation fails**
   - Ensure seating is allocated first
   - Ensure enough faculty members exist
   - Check faculty count >= rooms with students

## Browser Support

- Chrome 90+
- Firefox 88+
- Edge 90+
- Safari 14+

## Performance

- Handles 2000+ students efficiently
- Optimized SQL queries with indexes
- Minimal JavaScript for fast loading
- Responsive design for mobile devices

## Future Enhancements

- Email notifications
- SMS alerts
- QR code for seating slips
- Barcode attendance scanning
- Advanced analytics dashboard
- Excel export functionality
- Multi-language support

## Support

For issues or questions:
- Check troubleshooting section
- Review database schema
- Verify file permissions
- Check PHP error logs

## License

This system is developed for educational and institutional use.

## Credits

Developed as a comprehensive exam management solution for universities and educational institutions.

---

**Version:** 1.0.0
**Last Updated:** March 2026
**Developed By:** Exam Management System Team
#   A u t o _ E x a m _ M a n a g e m e n t _ G B U  
 
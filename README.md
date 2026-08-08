# Automated Examination Management System

> A centralized web application for coordinating examination schedules, student seating, invigilation duties, attendance, replacements, and academic reporting.

The Automated Examination Management System brings the operational parts of university examinations into one structured platform. Administrators manage examination resources, faculty members access their assigned responsibilities, and students receive personalized examination information through dedicated portals.

---

## System at a Glance

```mermaid
flowchart LR
    A["Administrator"] --> UI["Web Interface"]
    F["Faculty"] --> UI
    S["Student"] --> UI

    UI --> API["PHP API Layer"]
    API --> AUTH["Session and Role Authorization"]
    API --> CORE["Examination Services"]
    AUTH --> DB[("MySQL Database")]
    CORE --> DB

    CORE --> OUT["Schedules, Seating Plans, Duties, Attendance and Reports"]
    OUT --> UI
```

## Core Capabilities

| Area | Responsibility |
|---|---|
| Authentication | Session-based login and role-aware portal routing |
| Dashboard | High-level examination statistics, activity, and notifications |
| Students | Student records, academic identity, bulk imports, and portal access |
| Faculty | Faculty profiles, workload information, and duty access |
| Rooms | Room capacity, matrix dimensions, blocked seats, and layout metadata |
| Scheduling | Examination dates, shifts, subjects, programs, and schedule matrices |
| Seating | Automated student allocation with room and seat-level placement |
| Invigilation | Faculty duty allocation across examination rooms |
| Attendance | Room-wise attendance recording and absentee reporting |
| Replacements | Faculty replacement requests and approval workflow |
| Reports | Operational summaries, utilization metrics, and printable outputs |
| Portals | Focused student and faculty self-service experiences |

---

## Application Architecture

The project follows a lightweight client-server structure. Static HTML pages provide the interface, shared JavaScript coordinates browser behavior and API requests, PHP endpoints contain server-side operations, and MySQL stores the examination data.

```mermaid
flowchart TB
    subgraph Presentation["Presentation Layer"]
        ENTRY["index.html and dashboard.html"]
        MODULES["Role and Feature Pages"]
        ASSETS["Shared CSS, JavaScript and Images"]
    end

    subgraph Application["Application Layer"]
        AUTHAPI["Authentication API"]
        MANAGEMENT["Management APIs"]
        ALLOCATION["Scheduling and Allocation APIs"]
        PORTAL["Student and Faculty APIs"]
        REPORTING["Reporting and Output APIs"]
    end

    subgraph Data["Data Layer"]
        CONFIG["Database Configuration"]
        MYSQL[("exam_management")]
        MAINTENANCE["Import and Maintenance Scripts"]
    end

    ENTRY --> ASSETS
    MODULES --> ASSETS
    ENTRY --> AUTHAPI
    MODULES --> MANAGEMENT
    MODULES --> ALLOCATION
    MODULES --> PORTAL
    MODULES --> REPORTING

    AUTHAPI --> CONFIG
    MANAGEMENT --> CONFIG
    ALLOCATION --> CONFIG
    PORTAL --> CONFIG
    REPORTING --> CONFIG
    CONFIG --> MYSQL
    MAINTENANCE --> MYSQL
```

### Request Lifecycle

```mermaid
sequenceDiagram
    actor User
    participant Page as HTML Module
    participant Script as Shared JavaScript
    participant API as PHP Endpoint
    participant Session as PHP Session
    participant DB as MySQL

    User->>Page: Opens a feature or submits an action
    Page->>Script: Triggers interface behavior
    Script->>API: Sends an HTTP request
    API->>Session: Verifies login and role
    Session-->>API: Returns session identity
    API->>DB: Reads or updates examination data
    DB-->>API: Returns the result
    API-->>Script: Responds with JSON or printable output
    Script-->>Page: Updates the visible interface
    Page-->>User: Displays the result
```

---

## Repository Structure

```text
Auto_Exam_Management_GBU/
|
|-- api/                         Server-side application endpoints
|   |-- lib/                     Shared PHP domain helpers
|   `-- templates/               Printable output templates
|
|-- assets/                      Browser-facing shared resources
|   |-- css/                     Global visual styling
|   |-- images/                  Logos and image assets
|   `-- js/                      Shared client-side behavior
|
|-- config/                      Runtime configuration
|   `-- database.php             MySQL connection provider
|
|-- data/                        Source data used by imports
|
|-- database/                    Database definition and controlled data
|   |-- schema.sql               Canonical relational schema
|   `-- seeds/                   Development seed data
|
|-- docs/                        Supporting project documentation
|
|-- modules/                     Feature and role-specific HTML pages
|
|-- scripts/                     Optional data and maintenance utilities
|
|-- dashboard.html               Administrative application shell
|-- index.html                   Authentication entry point
|-- composer.json                PHP dependency definition
|-- package.json                 Script dependency definition
`-- README.md                    Architecture and repository guide
```

### Directory Responsibilities

#### `api/` — Server-side operations

Each endpoint groups operations around one business area. The browser communicates with these files using action-based GET or POST requests.

| Endpoint | Domain |
|---|---|
| `auth.php`, `login.php` | Authentication, session state, logout, and role routing |
| `dashboard.php` | Dashboard counts and recent examination activity |
| `students.php`, `student.php` | Administrative student management and student self-service |
| `faculty.php`, `faculty-portal.php` | Faculty administration, profile, and duty views |
| `rooms.php` | Examination room records and physical seating layouts |
| `schedule.php`, `exam-schedule-matrix.php` | Standard schedules and university matrix schedules |
| `seating.php`, `seating-plan.php` | Allocation generation and room-wise seating plans |
| `seating-pdf.php` | Printable seating-plan output |
| `invigilation.php` | Faculty-to-room duty allocation |
| `attendance.php` | Attendance sheets, marking, and absentee exports |
| `replacement.php` | Invigilation replacement workflow |
| `reports.php` | Cross-module analytics and operational reports |
| `notifications.php` | Role-visible examination announcements |
| `chatbot.php` | Contextual application guidance |

#### `modules/` — User-facing features

The modules directory separates major workflows into focused pages while sharing the same visual system and browser utilities.

```mermaid
flowchart LR
    LOGIN["Login"] --> ADMIN["Administrative Dashboard"]
    LOGIN --> FACULTY["Faculty Portal"]
    LOGIN --> STUDENT["Student Portal"]

    ADMIN --> MASTER["Students, Faculty and Rooms"]
    ADMIN --> EXAMS["Schedules, Seating and Invigilation"]
    ADMIN --> CONTROL["Attendance, Replacements and Reports"]

    FACULTY --> DUTIES["Assigned Duties"]
    FACULTY --> FATT["Attendance Responsibilities"]
    FACULTY --> FREP["Replacement Requests"]
    FACULTY --> PROFILE["Faculty Profile"]

    STUDENT --> DATESHEET["Personal Datesheet"]
    STUDENT --> SLIP["Seating Slip"]
    STUDENT --> ADMIT["Admit Card"]
```

#### `assets/` — Shared presentation resources

- `assets/css/style.css` defines the shared visual language across authentication, dashboards, portals, tables, forms, cards, and printable views.
- `assets/js/script.js` provides common navigation, theme handling, reusable interactions, and contextual assistance.
- `assets/images/` contains visual identity assets used throughout the application and generated documents.

#### `database/` — Relational model

- `schema.sql` is the structural definition of the application database.
- `seeds/` contains controlled development data kept separate from the schema.

#### `scripts/` and `data/` — Maintenance workspace

These directories support repeatable imports, cleanup operations, faculty-data synchronization, roll-number processing, and room-capacity maintenance. They are operational helpers rather than runtime browser dependencies.

---

## Role-Based Experience

```mermaid
flowchart TB
    AUTH["Authenticated Session"] --> ROLE{"User Role"}

    ROLE -->|Admin or Exam Cell| A["Administrative Workspace"]
    ROLE -->|Faculty| F["Faculty Workspace"]
    ROLE -->|Student| S["Student Workspace"]

    A --> A1["Manage master records"]
    A --> A2["Coordinate schedules and allocations"]
    A --> A3["Monitor attendance and reports"]

    F --> F1["Review assigned duties"]
    F --> F2["Manage profile and attendance tasks"]
    F --> F3["Request duty replacement"]

    S --> S1["Review examination dates"]
    S --> S2["Locate room and seat"]
    S --> S3["Access examination documents"]
```

| Role | Primary Workspace | Scope |
|---|---|---|
| Admin | Administrative dashboard | Full operational management |
| Exam Cell | Administrative dashboard | Examination coordination |
| Faculty | Faculty portal | Personal duties, attendance responsibilities, and replacements |
| Student | Student portal | Personal schedule, seating, profile, and examination documents |

---

## Examination Workflow

```mermaid
flowchart LR
    RECORDS["Maintain Students, Faculty and Rooms"]
    SCHEDULE["Create Examination Schedule"]
    SEATING["Generate Seating Allocation"]
    DUTIES["Assign Invigilation Duties"]
    CONDUCT["Conduct Examination"]
    ATTENDANCE["Record Attendance"]
    REPORTS["Review Reports and Outcomes"]

    RECORDS --> SCHEDULE
    SCHEDULE --> SEATING
    SEATING --> DUTIES
    DUTIES --> CONDUCT
    CONDUCT --> ATTENDANCE
    ATTENDANCE --> REPORTS

    DUTIES -. Replacement required .-> DUTIES
    SCHEDULE -. Published to students .-> SEATING
```

The workflow is intentionally modular: schedules define when examinations occur, room data defines available capacity, student data determines allocation demand, and faculty data supports duty coverage. Attendance and reporting consume the resulting allocations rather than duplicating them.

---

## Data Model

```mermaid
erDiagram
    USERS {
        int user_id PK
        string username UK
        string password
        string role
        int reference_id
    }

    STUDENTS {
        int student_id PK
        string roll_no UK
        string name
        string branch
        int semester
        string section
    }

    FACULTY {
        int faculty_id PK
        string name
        string department
        string designation
        int total_duties
    }

    ROOMS {
        int room_id PK
        string room_no UK
        int capacity
        int matrix_rows
        int matrix_cols
        string building
    }

    EXAMS {
        int exam_id PK
        string exam_name
        date date
        string session
    }

    EXAM_SCHEDULE {
        int schedule_id PK
        int exam_id FK
        string subject_name
        date exam_date
        time start_time
        time end_time
    }

    EXAM_SCHEDULE_MATRIX {
        int matrix_id PK
        int exam_id FK
        string exam_type
        string branch_code
        int semester
        string shift
        date exam_date
    }

    SEATING_ALLOCATION {
        int allocation_id PK
        int exam_id FK
        int student_id FK
        int room_id FK
        int seat_no
        int row_no
    }

    INVIGILATION_ALLOCATION {
        int duty_id PK
        int exam_id FK
        int faculty_id FK
        int room_id FK
        string duty_type
    }

    ATTENDANCE {
        int attendance_id PK
        int allocation_id FK
        string status
        string remarks
    }

    REPLACEMENT_REQUESTS {
        int request_id PK
        int original_faculty_id FK
        int replacement_faculty_id FK
        int exam_id FK
        int room_id FK
        string status
    }

    STUDENTS ||--o| USERS : "has account"
    FACULTY ||--o| USERS : "has account"
    EXAMS ||--o{ EXAM_SCHEDULE : "contains"
    EXAMS ||--o{ EXAM_SCHEDULE_MATRIX : "organizes"
    EXAMS ||--o{ SEATING_ALLOCATION : "defines"
    STUDENTS ||--o{ SEATING_ALLOCATION : "receives"
    ROOMS ||--o{ SEATING_ALLOCATION : "hosts"
    EXAMS ||--o{ INVIGILATION_ALLOCATION : "requires"
    FACULTY ||--o{ INVIGILATION_ALLOCATION : "performs"
    ROOMS ||--o{ INVIGILATION_ALLOCATION : "is supervised in"
    SEATING_ALLOCATION ||--o{ ATTENDANCE : "records"
    FACULTY ||--o{ REPLACEMENT_REQUESTS : "participates in"
    EXAMS ||--o{ REPLACEMENT_REQUESTS : "relates to"
    ROOMS ||--o{ REPLACEMENT_REQUESTS : "relates to"
```

### Data Ownership by Domain

| Domain | Primary tables | Downstream consumers |
|---|---|---|
| Identity | `users`, `students`, `faculty` | Authentication, portals, allocations |
| Examination planning | `exams`, `exam_schedule`, `exam_schedule_matrix` | Datesheets, seating, duties, reports |
| Physical resources | `rooms` | Seating plans, invigilation, utilization reports |
| Operations | `seating_allocation`, `invigilation_allocation` | Portals, attendance, printable outputs |
| Examination outcomes | `attendance` | Absentee reports and analytics |
| Duty continuity | `replacement_requests`, `replacement_log` | Faculty and administrative workflows |

---

## Design Principles Reflected in the Structure

- **Domain separation:** student, faculty, room, schedule, seating, and reporting responsibilities have distinct endpoints and pages.
- **Shared presentation layer:** common assets prevent individual modules from duplicating styling and browser behavior.
- **Role-focused navigation:** each user type enters a workspace suited to its responsibilities.
- **Relational consistency:** allocations connect existing examinations, students, faculty members, and rooms through foreign keys.
- **Operational traceability:** attendance and replacement records remain connected to the allocations that produced them.
- **Maintainable supporting material:** architecture documents, database files, seed data, imports, and runtime code occupy separate directories.

---

## Technology Profile

| Layer | Technology |
|---|---|
| Interface | HTML5 and CSS3 |
| Browser behavior | Vanilla JavaScript and Fetch API |
| Server | PHP 8-compatible endpoints |
| Persistence | MySQL / MariaDB |
| Sessions | Native PHP sessions |
| Printable documents | Browser print layouts and PHP-generated output |
| Maintenance utilities | Node.js with `mysql2` |
| Dependency management | Composer and npm |

---

<div align="center">

### One system. Three workspaces. A complete examination workflow.

**Plan examinations · Allocate resources · Coordinate people · Inform students · Measure outcomes**

</div>

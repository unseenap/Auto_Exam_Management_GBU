# Exam Management System Diagrams

These diagrams are tailored to the current project structure (`api/`, `modules/`, `config/database.php`, `database/schema.sql`) and the live workflow used by Admin, Faculty, and Student roles.

## 1. Architecture Diagram

```mermaid
flowchart TB
    U1[Admin User]
    U2[Faculty User]
    U3[Student User]

    subgraph CLIENT[Client Layer - Browser]
        I[index.html - Login]
        D[dashboard.html]
        M1[modules - Admin Pages]
        M2[modules/faculty_portal.html]
        M3[modules/student_portal.html]
        JS[assets/js/script.js]
        CSS[assets/css/style.css]
    end

    subgraph APP[Application Layer - PHP]
        AUTH[api/auth.php]
        STU[api/student.php]
        FACP[api/faculty-portal.php]
        ATT[api/attendance.php]
        ADM[api/students.php, faculty.php, rooms.php, schedule.php, seating.php, invigilation.php, reports.php, replacement.php]
    end

    subgraph DATA[Data Layer - MySQL]
        DB[(exam_management)]
        T1[(users)]
        T2[(students)]
        T3[(faculty)]
        T4[(rooms)]
        T5[(exams)]
        T6[(exam_schedule)]
        T7[(seating_allocation)]
        T8[(invigilation_allocation)]
        T9[(attendance)]
        T10[(exam_schedule_matrix)]
        T11[(replacement_requests)]
    end

    U1 --> I
    U1 --> D
    U2 --> M2
    U3 --> M3

    I --> JS
    D --> JS
    M1 --> JS
    M2 --> JS
    M3 --> JS
    JS --> CSS

    I --> AUTH
    D --> AUTH
    M1 --> ADM
    M2 --> FACP
    M2 --> ATT
    M3 --> STU

    AUTH --> DB
    STU --> DB
    FACP --> DB
    ATT --> DB
    ADM --> DB

    DB --- T1
    DB --- T2
    DB --- T3
    DB --- T4
    DB --- T5
    DB --- T6
    DB --- T7
    DB --- T8
    DB --- T9
    DB --- T10
    DB --- T11
```

## 2. ER Diagram

```mermaid
erDiagram
    USERS {
        int user_id PK
        string username UK
        string password
        enum role
        int reference_id
    }

    STUDENTS {
        int student_id PK
        string roll_no UK
        string username UK
        string school
        string department
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
        enum session
    }

    EXAM_SCHEDULE {
        int schedule_id PK
        int exam_id FK
        string subject_name
        date exam_date
        time start_time
        time end_time
        enum session
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
        enum status
        string remarks
    }

    EXAM_SCHEDULE_MATRIX {
        int matrix_id PK
        int exam_id FK
        string exam_type
        string school
        string dept
        string branch_code
        int semester
        date exam_date
        string subject_name
    }

    REPLACEMENT_REQUESTS {
        int request_id PK
        int original_faculty_id FK
        int replacement_faculty_id FK
        int exam_id FK
        int room_id FK
        string reason
        string status
    }

    EXAMS ||--o{ EXAM_SCHEDULE : has
    EXAMS ||--o{ SEATING_ALLOCATION : allocated_for
    STUDENTS ||--o{ SEATING_ALLOCATION : receives
    ROOMS ||--o{ SEATING_ALLOCATION : hosts

    EXAMS ||--o{ INVIGILATION_ALLOCATION : needs
    FACULTY ||--o{ INVIGILATION_ALLOCATION : assigned
    ROOMS ||--o{ INVIGILATION_ALLOCATION : duty_room

    SEATING_ALLOCATION ||--o{ ATTENDANCE : marks
    EXAMS ||--o{ EXAM_SCHEDULE_MATRIX : matrix_rows

    FACULTY ||--o{ REPLACEMENT_REQUESTS : original
    FACULTY ||--o{ REPLACEMENT_REQUESTS : replacement
    EXAMS ||--o{ REPLACEMENT_REQUESTS : for_exam
    ROOMS ||--o{ REPLACEMENT_REQUESTS : for_room
```

## 3. Data Flow Diagram (Level 1)

```mermaid
flowchart LR
    A[Admin]
    F[Faculty]
    S[Student]

    P1((Authentication & Session))
    P2((Exam & Room Management))
    P3((Seating Allocation Engine))
    P4((Invigilation Assignment))
    P5((Attendance Management))
    P6((Student Portal Services))
    P7((Reports & Dashboard))

    D1[(Users DB)]
    D2[(Academic DB: Students, Faculty, Rooms)]
    D3[(Exam DB: Exams, Schedule, Matrix)]
    D4[(Operations DB: Seating, Invigilation, Attendance, Replacement)]

    A -->|Login credentials| P1
    F -->|Login credentials| P1
    S -->|Login credentials| P1
    P1 --> D1
    P1 -->|Session + role token| A
    P1 -->|Session + role token| F
    P1 -->|Session + role token| S

    A -->|Manage students/faculty/rooms/schedule| P2
    P2 <--> D2
    P2 <--> D3

    A -->|Generate seating plan| P3
    P3 --> D2
    P3 --> D3
    P3 --> D4

    A -->|Assign invigilators| P4
    P4 --> D2
    P4 --> D3
    P4 --> D4

    F -->|Mark Present/Late/Absent| P5
    A -->|View/Edit attendance| P5
    P5 --> D4

    S -->|Request datesheet/slip/admit| P6
    P6 --> D2
    P6 --> D3
    P6 --> D4
    P6 -->|Datesheet + Seating Slip + Admit Card| S

    A -->|Request analytics| P7
    P7 --> D3
    P7 --> D4
    P7 -->|Operational reports| A
```

## 4. Activity Diagram (Exam Lifecycle)

```mermaid
flowchart TD
    START([Start]) --> L[Admin Login]
    L --> V{Authenticated?}
    V -- No --> R1[Show Login Error] --> L
    V -- Yes --> M[Manage Master Data: Students, Faculty, Rooms]
    M --> S[Create/Update Exam Schedule]
    S --> C{Schedule Valid?}
    C -- No --> R2[Resolve Time/Room/Session Conflicts] --> S
    C -- Yes --> G[Generate Seating Allocation]
    G --> C2{Capacity/Constraints Satisfied?}
    C2 -- No --> R3[Adjust Rooms or Student Mapping] --> G
    C2 -- Yes --> I[Allocate Invigilation Duties]
    I --> P[Publish Student Views]
    P --> ST[Students View Datesheet/Seating Slip/Admit Card]
    ST --> FD[Faculty Open Digital Attendance]
    FD --> A1[Mark Present/Late/Absent]
    A1 --> REP[Generate Reports & Dashboard Metrics]
    REP --> END([End of Exam Cycle])
```

## 5. Client-Server Model

```mermaid
sequenceDiagram
    actor Student
    actor Faculty
    actor Admin
    participant Browser as Client Browser (HTML/CSS/JS)
    participant API as PHP API Layer
    participant DB as MySQL Database

    Student->>Browser: Open student portal module
    Browser->>API: GET /api/auth.php?action=check
    API->>DB: Validate session/user role
    DB-->>API: Session + role data
    API-->>Browser: Auth response JSON

    Browser->>API: GET /api/student.php?action=seating
    API->>DB: Query student + seating + exam + room
    DB-->>API: Seating dataset
    API-->>Browser: Seating JSON
    Browser-->>Student: Render seating slip/admit card/datesheet

    Faculty->>Browser: Mark attendance
    Browser->>API: POST /api/attendance.php {exam_id, room_id, records}
    API->>DB: Update attendance table
    DB-->>API: Save result
    API-->>Browser: Success/failure JSON
    Browser-->>Faculty: Toast + updated sheet

    Admin->>Browser: Generate seating/report
    Browser->>API: POST /api/seating.php or GET /api/reports.php
    API->>DB: Process allocation/report queries
    DB-->>API: Result set
    API-->>Browser: Data payload
    Browser-->>Admin: UI table/chart/export
```

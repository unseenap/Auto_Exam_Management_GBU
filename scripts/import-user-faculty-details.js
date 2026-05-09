#!/usr/bin/env node
/*
  Imports the faculty details provided by user into exam_management.

  Usage:
    node scripts/import-user-faculty-details.js

  Env (optional):
    DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_NAME
*/

const mysql = require('mysql2/promise');

const DEFAULT_PASSWORD_HASH = '$2y$10$gCJ5umIGdQeJ/ZxtU.PtyODqKyiw8YBD3nlhC3aY1zvk8fJf4Uf.a'; // faculty123

const FACULTY = [
  { name: 'Aakash', department: 'CSE', email: 'Aakash@gbu.ac.in', mobile: '9286894749', qualification: 'Integrated M. Tech' },
  { name: 'Akanksha Singh Rajput', department: 'CSE', email: 'akanksharajput.ocfd@gbu.ac.in', mobile: '987302113', qualification: 'B.Tech (CSE), M.Tech (CSE from IIT Guwahati)' },
  { name: 'Anupriya Priyadarshani', department: 'CSE', email: 'anucsnec@gmail.com', mobile: '9687787496', qualification: 'M.TECH (Data Science & AI), IIIT RANCHI / B.TECH (CSE), NCE' },
  { name: 'Archana', department: 'CSE', email: 'archana.ocfd@gbu.ac.in', mobile: '9412878115', qualification: 'M.Tech (CSE), Ph.D. (Pursuing)' },
  { name: 'Ashi Gautam', department: 'CSE', email: 'ashigautam.ocfd@gbu.ac.in', mobile: '8285828005', qualification: 'M.Tech' },
  { name: 'Charu Singh', department: 'CSE', email: 'charusingh.ocfd@gbu.ac.in', mobile: '7080488876', qualification: 'M.Tech (CSE), Ph.D.(CSE) Pursuing' },
  { name: 'Sangeeta', department: 'CSE', email: 'sangeeta.ocfd@gbu.ac.in', mobile: '8744908838', qualification: 'B.Tech (CSE), M.Tech (Software Engineering)' },
  { name: 'Prashant Gaurav', department: 'CSE', email: 'prashantgaurav.ocfd@gbu.ac.in', mobile: '9798246370', qualification: 'B.Tech (CSE), M.Tech (Artificial Intelligence and Robotics)' },
  { name: 'Shashi Prabha Chahal', department: 'CSE', email: 'shashi.cs2101@gmail.com', mobile: '9315884954', qualification: 'M.Tech(CSE), Ph.D (pursuing)' },
  { name: 'Sumedha Dangi', department: 'CSE', email: 'sumedha.ocfd@gbu.ac.in', mobile: '8848725684', qualification: 'Pursuing Ph.D., M.Tech, B.Tech.' },
  { name: 'Vikash Patel', department: 'CSE', email: 'vikashpatel.ocfd@gbu.ac.in', mobile: '9654173780', qualification: 'B. Tech (CSE), M.Tech(Artificial Intelligence and Robotics)' },
  { name: 'Vishvajeet Yadav', department: 'CSE', email: 'vishvajeetyadav.ocfd@gbu.ac.in', mobile: '9628349743', qualification: 'M.Tech(Artificial Intelligence and Robotics), B.Tech(CSE)' },
  { name: 'Anjana Mall', department: 'IT', email: 'anjana71086@gmail.com', mobile: '09754127644', qualification: 'BE, M. Tech, PhD.(Pursuing) (ECE)' },
  { name: 'HARISHCHANDRA PRASAD', department: 'IT', email: 'harishfet77@gmail.com', mobile: '7906431303', qualification: 'B.E.,M.Tech, PhD.(Pursuing)' },
  { name: 'Anand Prakash Raw', department: 'IT', email: 'anandprao0712@gmail.com', mobile: '9076853989', qualification: 'B.Tech. (IT), M.Tech. (Computer Science and Technology)' },
  { name: 'DR ANA KUMAR', department: 'IT', email: 'anakumar98277@gmail.com', mobile: '9529156840', qualification: 'B.TECH, M.TECH, PHD' },
  { name: 'Kamala Kant Yadav', department: 'IT', email: 'kamlakant17@gmail.com', mobile: '7985755009', qualification: 'M.Tech (CSE), B.Tech (IT), UGC-NET and GATE Qualified' },
  { name: 'Deepshikha Gautam', department: 'IT', email: 'deepgtm27@gmail.com', mobile: '8800753935', qualification: 'Integrated B.tech- M.tech' },
  { name: 'Shiromani Balmukund Rahi', department: 'IT', email: 'sbrahi@gmail.com', mobile: '9264991975', qualification: 'M.Tech, Ph.D. PDF' },
  { name: 'DAIZY SINGH', department: 'ECE', email: 'DAIZY.SINGH2511@GMAIL.COM', mobile: '8750698263', qualification: 'M.tec (wireless and communications Network) B.tec (Electronic and communications Engineering)' },
  { name: 'Brijesh Sahani', department: 'IT', email: 'brijesh08.sahani07@gmail.com', mobile: '9559368331', qualification: 'M.Tech (ECE)' },
  { name: 'Neeraj Kaushik', department: 'IT', email: 'kaushikneeraj6178@gmail.com', mobile: '9999929416', qualification: 'MCA,PhD(pursuing)' },
  { name: 'Sugandha Yadav', department: 'IT', email: 'sugandhayadav555@gmail.com', mobile: '9899494690', qualification: 'M Tech' },
  { name: 'Aniruddh Singh', department: 'IT', email: 'aniruddhsingh3011@gmail.com', mobile: '7291951506', qualification: 'Integrated dual degree Btech+Mtech' },
  { name: 'SARVESH KUMAR', department: 'IT', email: 'sarve9787@gmail.com', mobile: '9540509787', qualification: 'B.Tech. and M.Tech.' },
  { name: 'Bhupendra Kumar', department: 'IT', email: 'bkumar1984@gmail.com', mobile: '9015642832', qualification: 'M.tech' },
  { name: 'Preeti Paras', department: 'IT', email: 'preetiparas100@gmail.com', mobile: '7599997481', qualification: 'M.Tech & MCA' },
  { name: 'Preeti Bhati', department: 'IT', email: 'bpreeti.bhati@gmail.com', mobile: '9910849698', qualification: 'B.Tech , M.Tech' },
  { name: 'Prakash Chandra Saraswat', department: 'IT', email: 'prakash.saraswat73@gmail.com', mobile: '9803199996', qualification: 'M.C.A.,M.B.A.' },
  { name: 'USMAN AHMAD', department: 'IT', email: 'usmanahmad7866692@gmail.com', mobile: '9013858872', qualification: 'M.Tech' },
  { name: 'Atul Kumar', department: 'IT', email: 'atulk9888@gmail.com', mobile: '9891007332', qualification: 'B.Tech,M.Tech' },

  { name: 'Mr. Kartikeya Tiwari', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Computer Science & Engineering', email: 'kartikeya@gbu.ac.in', mobile: '9415033569', qualification: 'M.Tech (CSE), B.Tech (IT)', specialization: 'Programming, Database Management, Algorithm Design, Data Structure.', description: '' },
  { name: 'Dr. Vivek Chaudhary', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Electronics and Communication Engineering', email: 'vivek.chaudhary@gbu.ac.in', mobile: 'Coming Soon', qualification: 'PhD. (IIT Delhi) || M.Tech (IIT Roorkee) || B.Tech (UPTU, Lucknow)', specialization: 'Wireless Communication, Physical Layer Security, Integrated Sensing and Communication, Full-Duplex Radios, New Waveforms for Next Generation Wireless Networks', description: 'Dr. Vivek Chaudhary\'s work delves into enhancing the security and efficiency of wireless communication systems, developing innovative waveform designs, and exploring the potential of full-duplex radios. He is also interested in integrating sensing and communication technologies to improve network performance and optimally utilize resources. Since December 2024, Dr. Chaudhary has been part of Gautam Buddha University.' },
  { name: 'Dr. Vimlesh Kumar Ray', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Computer Science & Engineering', email: 'vimlesh@gbu.ac.in', mobile: 'Coming Soon', qualification: 'PhD (Ad-hoc wireless networks), M.Tech. (Wireless communication and networks), B.Tech. (Electronics and communication engineering)', specialization: 'Signal and system, Control theory, Network theory, Ad-hoc wireless networks', description: '' },
  { name: 'Dr. Shiraz Khurana', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Computer Science & Engineering', email: 'shiraz.khurana@gbu.ac.in', mobile: '9466786100', qualification: 'B.Tech, M.Tech, Ph.D', specialization: 'Machine Learning, Virtual Reality, Augmented Reality, Computer Vision', description: 'Dr. Shiraz Khurana has more than 13 years of teaching experience in India and abroad, with strong work in Python, Java, Unity, and applied AI research.' },
  { name: 'Dr. Rakesh Kumar', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Computer Science & Engineering', email: 'rakesh.kumar@gbu.ac.in', mobile: '+91-9026422990', qualification: 'B.Tech., M.Tech., Ph.D.: IIT (BHU).', specialization: 'Software Engineering, Machine Learning, Data Science, Artificial Intelligence', description: 'Assistant Professor, School of ICT, GBU, with research contributions in IEEE and SCI/SCIE indexed venues.' },
  { name: 'Dr. Rakesh Kumar', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Computer Science & Engineering', email: 'rakesh.k@gbu.ac.in', mobile: 'Coming Soon', qualification: 'B.Tech., M.Tech., Ph.D.', specialization: 'Multicast Network, Vehicular IoT, Security Protocols, Wireless Sensor Networks, Artificial Intelligence', description: 'Experienced educator and researcher with focus on multicast networks, vehicular IoT, and security protocols.' },
  { name: 'Dr. Raju Pal', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Computer Science & Engineering', email: 'raju.pal@gbu.ac.in', mobile: 'Coming Soon', qualification: 'PhD (CSE), M.Tech. (CSE), B.Tech (IT)', specialization: 'Machine Learning, Computer Vision, Artificial Intelligence', description: 'Researcher and educator in computer vision and machine learning with multiple applied projects.' },
  { name: 'Dr. Rajesh Mishra', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Electronics and Communication Engineering', email: 'rmishra@gbu.ac.in', mobile: '+919717949251', qualification: 'B.E. (Electronics Engineering), M. Tech. & Ph.D. (IIT Kharagpur)', specialization: 'Networks, Signal Processing, Communication Systems, Reliability Engineering, RAMS', description: 'Proficient professional in communication networks, signal processing, and reliability engineering.' },
  { name: 'Dr. Rajendra Bahadur Singh', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Computer Science & Engineering', email: 'rajendra@gbu.ac.in', mobile: 'Coming Soon', qualification: 'Ph.D, M.Tech, B.Tech', specialization: 'Soft Computing, Data Analytics, IC Floorplanning', description: '' },
  { name: 'Dr. Priyanka Goyal', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Electronics and Communication Engineering', email: 'priyankag@gbu.ac.in', mobile: 'Coming Soon', qualification: 'PhD (2018) in Optoelectronics and VLSI (On-chip optical interconnects)', specialization: 'Basic electronics, Analog Communication, Network Analysis and Synthesis, VHDL, Verilog, Low Power VLSI, Automation and Physical Design', description: '' },
  { name: 'Dr. Pradeep Tomar', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Computer Science & Engineering', email: 'pradeep.tomar@gbu.ac.in', mobile: '+91-9899874830', qualification: 'Ph.D. (CS), M.Tech. (CSE), MCA', specialization: 'Software Engineering, Artificial Intelligence', description: 'Faculty at USICT, GBU since 2009 with extensive research, patents, supervision, and academic leadership.' },
  { name: 'Dr. Nitesh Singh Bhati', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Computer Science & Engineering', email: 'nsbhati@gbu.ac.in', mobile: 'Coming Soon', qualification: 'Ph.D(CSE), M.Tech(CSE), B.Tech(CSE)', specialization: 'Cyber Security, Machine Learning, Information Security, Artificial Intelligence', description: 'Assistant Professor at USICT with research in intrusion detection, cybersecurity, AI, and ML.' },
  { name: 'Dr. Navaid Zafar Rizvi', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Electronics and Communication Engineering', email: 'navaid@gbu.ac.in', mobile: 'Coming Soon', qualification: 'PhD, M.S- Inf. & Comm. Engg. (TUD-Darmstadt, Germany), M.S- Microsystems Engg. (HFU, Germany)', specialization: 'Machine Intelligence for ICs, MEMS/VLSI Design, Antenna and Microwave Techniques, RF Technology, Microsystems Fabrication', description: '' },
  { name: 'Dr. Mangal Das', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Electronics and Communication Engineering', email: 'mangal.das@gbu.ac.in', mobile: 'Coming Soon', qualification: 'Indian Institute of Technology Indore', specialization: 'Semiconductor Fabrication, Nanotechnology, Robotics, AI/ML', description: 'Professional with patents and SCI publications in semiconductor fabrication, nanotechnology, robotics, and AI/ML.' },
  { name: 'Dr. Maneet Singh', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Information Technology', email: 'maneet.singh@gbu.ac.in', mobile: 'Coming Soon', qualification: 'PhD (Indian Institute of Technology Ropar)', specialization: 'Opinion Mining, Social Network Analysis, Computational Social Science, Machine Learning', description: 'Assistant Professor in IT with research focus on opinion dynamics and data-driven social systems.' },
  { name: 'Dr. Gaurav Kumar', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Computer Science & Engineering', email: 'gaurav.kumar@gbu.ac.in', mobile: '8586968801', qualification: 'B.Tech (Guru Gobind Singh University), M.Tech and PhD (Jawaharlal Nehru University)', specialization: 'Recommendation Systems, Machine Learning, Decision Support Systems, Sentiment Analysis, Cyber Scams', description: 'Assistant Professor and social entrepreneur focused on cyber awareness, decision support systems, and recommender systems.' },
  { name: 'Dr. Anurag Singh Baghel', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Computer Science & Engineering', email: 'asb@gbu.ac.in', mobile: 'Coming Soon', qualification: 'D.Phil (2010), University of Allahabad, Prayagraj', specialization: 'Artificial Intelligence, Soft Computing, Optimization Techniques, Algorithm Design, Embedded Systems', description: 'In-charge, Central Computer Center, Gautam Buddha University; university level teaching experience since 2004.' },

  { name: 'Dr. Anika', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Computer Science & Engineering', email: 'anika@gbu.ac.in', mobile: 'Coming Soon', qualification: 'B.Tech. (CSE), M.E. (CSE) and Ph.D. (CSE)', specialization: 'Data Structures and Algorithms, Data Science and Artificial Intelligence', description: '' },
  { name: 'Dr. Akash Kumar', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Information Technology', email: 'akash.kumar@gbu.ac.in', mobile: '9549539572', qualification: 'Ph.D. (IIIT-Allahabad), M.Tech. (IIIT-Allahabad), B.Tech. (UCE, RTU, Kota)', specialization: 'Battery Less Wireless Sensor Network, Internet of Things, Energy Harvesting, UAVs, Machine Learning, BlockChain', description: 'https://sites.google.com/view/akashkumarkatiyar/home' },
  { name: 'Dr. Aarti Gautam Dinker', designation: 'Assistant Professor', university_school: 'University School of Information and Communication Technology', department: 'Computer Science & Engineering', email: 'aarti@gbu.ac.in', mobile: 'Coming Soon', qualification: 'Ph.D.', specialization: '', description: '' },
  { name: 'Dr. Vidushi Sharma', designation: 'Assistant Professor and HoD', university_school: 'University School of Information and Communication Technology', department: 'Electronics and Communication Engineering', email: 'vidushi@gbu.ac.in', mobile: 'Coming Soon', qualification: 'Ph.D. Computer Science, M.Tech., M.Sc. Computer Science', specialization: 'Information technology, Sensor network, Internet of things, Technology Management', description: '' },
  { name: 'Dr. Neeta Singh', designation: 'Assistant Professor and HoD', university_school: 'University School of Information and Communication Technology', department: 'Information Technology', email: 'neeta@gbu.ac.in', mobile: 'Coming Soon', qualification: 'Ph.D. (Computer Science), M.Tech. ICT, Masters in Computers and Management, BSc.(PCM)', specialization: 'Computer Networks, Wireless Networks, Mobile Computing, Wireless Technology, MANETs, VANETs, Next Generation Networks', description: 'IETE Fellow; research interests include communication networks, next generation networks, machine learning, MANET, VANET, applied probability, queueing theory, and stochastic modeling.' },
  { name: 'Dr. Arun Solanki', designation: 'Assistant Professor and HoD', university_school: 'University School of Information and Communication Technology', department: 'Computer Science & Engineering', email: 'asolanki@gbu.ac.in', mobile: 'Coming Soon', qualification: 'PhD (2014), Computer Science and Engineering, Gautam Buddha University, Greater Noida', specialization: 'Artificial Intelligence, Machine Learning, Deep Learning, NLP', description: 'Academic leader in CSE at GBU with extensive work in AI/ML, research publishing, mentoring, books, editorial roles, and conference leadership.' },
  { name: 'Dr. Arpit Bhardwaj', designation: 'Associate Professor and Dean(I/C)', university_school: 'University School of Information and Communication Technology', department: 'Computer Science & Engineering', email: 'arpit.bhardwaj@gbu.ac.in', mobile: '8878853111', qualification: 'Ph.D: Computer Science, IIT Indore, M.Tech: SGSITS Indore, B.Tech: SDITS Khandwa', specialization: 'Machine Learning, Deep Learning, EEG Signal, Genetic Programming', description: '' },
  { name: 'Prof. Sanjay Kumar Sharma', designation: 'Professor', university_school: 'University School of Information and Communication Technology', department: 'Information Technology', email: 'sanjay.sharma@gbu.ac.in', mobile: 'Coming Soon', qualification: 'Ph. D. 1993, Kurukshetra University, Kurukshetra', specialization: 'Information Technology, Artificial Intelligence, Nanotechnology, Research Methodology, Research and Publication Ethics', description: '' }
];

function normalizeDepartment(dept) {
  const d = String(dept || '').trim().toUpperCase();
  if (d === 'CSE') return 'Computer Science and Engineering';
  if (d === 'IT') return 'Information Technology';
  if (d === 'ECE') return 'Electronics and Communication Engineering';
  if (d === 'COMPUTER SCIENCE & ENGINEERING') return 'Computer Science and Engineering';
  if (d === 'ELECTRONICS AND COMMUNICATION ENGINEERING') return 'Electronics and Communication Engineering';
  return dept;
}

function cleanText(value) {
  return String(value ?? '').replace(/\s+/g, ' ').trim();
}

function normalizeContact(value) {
  const text = cleanText(value);
  if (!text || /^coming\s+soon$/i.test(text) || text === '-') return '';
  return text;
}

function usernameFromName(name, index) {
  const base = cleanText(name)
    .replace(/^(dr\.?|mr\.?|mrs\.?|ms\.?|prof\.?)\s+/i, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '.')
    .replace(/^\.+|\.+$/g, '');
  const local = base.length >= 3 ? base : `faculty${index}`;
  return `${local}@gbu.ac.in`;
}

function isGbuEmail(email) {
  return /@gbu\.ac\.in$/i.test(cleanText(email));
}

async function ensureColumns(conn) {
  const [rows] = await conn.query('SHOW COLUMNS FROM faculty');
  const fields = new Set(rows.map((r) => r.Field));

  if (!fields.has('email')) {
    await conn.query('ALTER TABLE faculty ADD COLUMN email VARCHAR(150) DEFAULT NULL AFTER designation');
  }
  if (!fields.has('mobile')) {
    await conn.query('ALTER TABLE faculty ADD COLUMN mobile VARCHAR(30) DEFAULT NULL AFTER email');
  }
  if (!fields.has('qualification')) {
    await conn.query('ALTER TABLE faculty ADD COLUMN qualification TEXT DEFAULT NULL AFTER mobile');
  }
  if (!fields.has('specialization')) {
    await conn.query('ALTER TABLE faculty ADD COLUMN specialization TEXT DEFAULT NULL AFTER qualification');
  }
  if (!fields.has('description')) {
    await conn.query('ALTER TABLE faculty ADD COLUMN description LONGTEXT DEFAULT NULL AFTER specialization');
  }
  if (!fields.has('university_school')) {
    await conn.query('ALTER TABLE faculty ADD COLUMN university_school VARCHAR(200) DEFAULT NULL AFTER description');
  }
  if (!fields.has('faculty_unique_no')) {
    await conn.query('ALTER TABLE faculty ADD COLUMN faculty_unique_no INT DEFAULT NULL AFTER faculty_id');
  }

  const [indexes] = await conn.query("SHOW INDEX FROM faculty WHERE Key_name='uniq_faculty_unique_no'");
  if (!indexes.length) {
    await conn.query('ALTER TABLE faculty ADD UNIQUE KEY uniq_faculty_unique_no (faculty_unique_no)');
  }

  await conn.query('UPDATE faculty SET faculty_unique_no = faculty_id WHERE faculty_unique_no IS NULL');
}

async function ensureFacultyUniqueNo(conn, facultyId) {
  const id = Number(facultyId || 0);
  if (!id) return;
  await conn.execute('UPDATE faculty SET faculty_unique_no=? WHERE faculty_id=? AND faculty_unique_no IS NULL', [id, id]);
}

async function ensureUser(conn, facultyId, username) {
  const [existingByRef] = await conn.execute(
    "SELECT user_id FROM users WHERE role='faculty' AND reference_id=? LIMIT 1",
    [facultyId]
  );

  const ensureUniqueUsername = async (candidate, ignoreUserId = 0) => {
    let finalUsername = candidate;
    let tries = 0;
    while (tries < 5) {
      const [conflict] = await conn.execute(
        'SELECT user_id FROM users WHERE username=? LIMIT 1',
        [finalUsername]
      );
      if (!conflict.length || conflict[0].user_id === ignoreUserId) {
        return finalUsername;
      }
      const local = String(username).split('@')[0];
      finalUsername = `${local}.${facultyId}${tries ? '.' + tries : ''}@gbu.ac.in`;
      tries++;
    }
    return `${facultyId}.${Date.now()}@gbu.ac.in`;
  };

  if (existingByRef.length) {
    const finalUsername = await ensureUniqueUsername(username, existingByRef[0].user_id);
    await conn.execute('UPDATE users SET username=? WHERE user_id=?', [finalUsername, existingByRef[0].user_id]);
    return;
  }

  const finalUsername = await ensureUniqueUsername(username, 0);

  await conn.execute(
    'INSERT INTO users (username,password,role,reference_id) VALUES (?,?,?,?)',
    [finalUsername, DEFAULT_PASSWORD_HASH, 'faculty', facultyId]
  );
}

async function upsertFaculty(conn, record, index) {
  const department = normalizeDepartment(record.department);
  const designation = cleanText(record.designation || 'Faculty');
  const qualification = cleanText(record.qualification || '');
  const specialization = cleanText(record.specialization || '');
  const description = cleanText(record.description || '');
  const universitySchool = cleanText(record.university_school || 'University School of Information and Communication Technology');
  const email = cleanText(record.email || '');
  const mobile = normalizeContact(record.mobile || '');

  let rows = [];
  if (email) {
    const [byEmail] = await conn.execute(
      'SELECT faculty_id FROM faculty WHERE LOWER(email)=LOWER(?) LIMIT 1',
      [email]
    );
    rows = byEmail;
  }
  if (!rows.length && !email) {
    const [byNameDept] = await conn.execute(
      'SELECT faculty_id FROM faculty WHERE LOWER(name)=LOWER(?) AND LOWER(department)=LOWER(?) LIMIT 1',
      [record.name, department]
    );
    rows = byNameDept;
  }

  if (rows.length) {
    const facultyId = rows[0].faculty_id;
    await ensureFacultyUniqueNo(conn, facultyId);
    await conn.execute(
      'UPDATE faculty SET designation=?, email=?, mobile=?, qualification=?, specialization=?, description=?, university_school=? WHERE faculty_id=?',
      [designation, email, mobile, qualification, specialization, description, universitySchool, facultyId]
    );
    await ensureUser(conn, facultyId, usernameFromName(record.name, index));
    return { inserted: 0, updated: 1 };
  }

  const [insertResult] = await conn.execute(
    'INSERT INTO faculty (name,department,designation,email,mobile,qualification,specialization,description,university_school,total_duties) VALUES (?,?,?,?,?,?,?,?,?,0)',
    [record.name, department, designation, email, mobile, qualification, specialization, description, universitySchool]
  );
  const facultyId = Number(insertResult.insertId);
  await ensureFacultyUniqueNo(conn, facultyId);
  await ensureUser(conn, facultyId, usernameFromName(record.name, index));
  return { inserted: 1, updated: 0 };
}

function buildCanonicalFaculty(rows) {
  const emailMap = new Map();
  for (const row of rows) {
    const email = cleanText(row.email || '').toLowerCase();
    if (!isGbuEmail(email)) {
      continue;
    }
    emailMap.set(email, {
      ...row,
      email,
    });
  }
  return [...emailMap.values()];
}

async function pruneSyntheticFaculty(conn, canonicalRows) {
  const allowedEmails = canonicalRows.map((row) => row.email.toLowerCase());
  const placeholders = allowedEmails.map(() => '?').join(',');

  let query = 'SELECT faculty_id FROM faculty WHERE LOWER(COALESCE(email, "")) NOT LIKE "%@gbu.ac.in"';
  const params = [];
  if (allowedEmails.length) {
    query += ` OR LOWER(COALESCE(email, "")) NOT IN (${placeholders})`;
    params.push(...allowedEmails);
  }

  const [rows] = await conn.execute(query, params);
  if (!rows.length) {
    return 0;
  }

  const ids = rows.map((r) => Number(r.faculty_id)).filter((id) => Number.isFinite(id) && id > 0);
  if (!ids.length) {
    return 0;
  }

  const idPlaceholders = ids.map(() => '?').join(',');
  await conn.execute(`DELETE FROM users WHERE role='faculty' AND reference_id IN (${idPlaceholders})`, ids);
  await conn.execute(`DELETE FROM faculty WHERE faculty_id IN (${idPlaceholders})`, ids);
  return ids.length;
}

async function run() {
  const conn = await mysql.createConnection({
    host: process.env.DB_HOST || '127.0.0.1',
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'exam_management',
  });

  try {
    await ensureColumns(conn);
    await conn.beginTransaction();

    const canonicalRows = buildCanonicalFaculty(FACULTY);

    let inserted = 0;
    let updated = 0;

    for (let i = 0; i < canonicalRows.length; i++) {
      const result = await upsertFaculty(conn, canonicalRows[i], i + 1);
      inserted += result.inserted;
      updated += result.updated;
    }

    const removedSynthetic = await pruneSyntheticFaculty(conn, canonicalRows);

    await conn.commit();
    console.log(`Faculty import complete. Inserted: ${inserted}, Updated: ${updated}, Total processed: ${canonicalRows.length}, Removed synthetic: ${removedSynthetic}`);
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    await conn.end();
  }
}

run().catch((err) => {
  console.error('Faculty import failed:', err.message);
  process.exit(1);
});

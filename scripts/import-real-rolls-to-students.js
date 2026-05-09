#!/usr/bin/env node
/*
  Imports real roll ranges into exam_management.students (and student users) using mysql2.

  Rules implemented (Apr 2026):
  - admission_year from first 3 digits: 235 -> 2023, 245 -> 2024, 255 -> 2025
  - current_year = 2026 - admission_year
  - current_sem  = current_year * 2
  - R- prefix => is_repeat=true
  - L* branch code (LCS, LAI, LIT, LEC, LEA...) or nearby "L Entry" => is_lateral=true
  - ICS programs run for 5 years, UCA/BCA for 3 years, repeaters for 1 year

  Usage:
    node scripts/import-real-rolls-to-students.js --file data/real_roll_ranges.txt
    node scripts/import-real-rolls-to-students.js --ranges "(245UCS002 To 245UCS046) ..."

  Env:
    DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_NAME
*/

const fs = require("fs");
const path = require("path");
const mysql = require("mysql2/promise");

const REFERENCE_YEAR = 2026;

function parseArgs(argv) {
  const out = {};
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    if (a === "--file") out.file = argv[++i];
    else if (a === "--ranges") out.ranges = argv[++i];
    else if (a === "--help" || a === "-h") out.help = true;
  }
  return out;
}

function help() {
  console.log(`
Usage:
  node scripts/import-real-rolls-to-students.js --file data/real_roll_ranges.txt
  node scripts/import-real-rolls-to-students.js --ranges "(245UCS002 To 245UCS046) ..."

Env:
  DB_HOST=127.0.0.1
  DB_PORT=3307
  DB_USER=root
  DB_PASSWORD=Password123
  DB_NAME=exam_management
`);
}

function normYear(yy3) {
  const yy = Number(String(yy3).slice(0, 2));
  if (Number.isNaN(yy)) return null;
  return 2000 + yy;
}

function parseToken(tokenRaw) {
  const original = String(tokenRaw || "").trim();
  if (!original) return null;

  const isRepeatPrefix = /^R\s*-/i.test(original);
  const compact = original
    .replace(/^R\s*-/i, "")
    .replace(/[^A-Za-z0-9]/g, "")
    .toUpperCase();

  const m = compact.match(/^(\d{3})([A-Z]+)(\d+)$/);
  if (!m) return null;

  const yearCode = m[1];
  const branchCode = m[2];
  const studentNumber = Number(m[3]);
  if (Number.isNaN(studentNumber)) return null;

  const admissionYear = normYear(yearCode);
  if (!admissionYear) return null;

  return {
    yearCode,
    branchCode,
    studentNumber,
    isRepeatPrefix,
    original,
  };
}

function getContextFlags(fullText, startIdx, endIdx, localText) {
  const before = fullText.slice(Math.max(0, startIdx - 24), startIdx);
  const after = fullText.slice(endIdx, Math.min(fullText.length, endIdx + 24));
  const scope = `${before} ${localText} ${after}`.toLowerCase();
  return {
    repeatContext: /\brepeat\b/.test(scope),
    lateralContext: /l\.?\s*entry|lateral/.test(scope),
  };
}

function branchToDepartment(branchCode) {
  const b = String(branchCode || "").toUpperCase();
  const eceSet = new Set(["UEC", "LEC", "UEA", "LEA", "IEC", "UVL", "PCW"]);
  if (eceSet.has(b)) return "Electronics and Communication Engineering";
  if (b === "UCF" || b === "UCA" || b === "PCA") return "Cyber Security and Analytics";
  if (b === "PCS" || b === "PCD") return "Data Science";
  return "Computer Science and Engineering";
}

function courseDurationYears(branchCode, isRepeat) {
  if (isRepeat) return 1;

  const b = String(branchCode || "").toUpperCase();
  if (b.startsWith("ICS")) return 5;
  if (b.startsWith("UCA")) return 3;
  if (b.startsWith("L")) return 3;
  return 4;
}

function makeRoll(yearCode, branchCode, n, repeat) {
  const num = String(n).padStart(3, "0");
  return `${repeat ? "R-" : ""}${yearCode}${branchCode}${num}`;
}

function parseRanges(text) {
  const src = String(text || "");
  const blocks = [...src.matchAll(/\(([^()]+)\)/g)];
  const out = [];

  for (const m of blocks) {
    const block = m[1] || "";
    const idx = m.index || 0;
    const flags = getContextFlags(src, idx, idx + m[0].length, block);

    const pieces = block.split(/\bto\b/i).map((x) => x.trim()).filter(Boolean);
    const left = pieces[0] || "";
    const right = pieces[1] || pieces[0] || "";

    const a = parseToken(left);
    const b = parseToken(right);
    if (!a || !b) continue;

    if (a.yearCode !== b.yearCode || a.branchCode !== b.branchCode) continue;

    const lo = Math.min(a.studentNumber, b.studentNumber);
    const hi = Math.max(a.studentNumber, b.studentNumber);

    const isRepeat = Boolean(a.isRepeatPrefix || b.isRepeatPrefix || flags.repeatContext);
    const isLateral = Boolean(/^L/.test(a.branchCode) || flags.lateralContext);

    const admissionYear = normYear(a.yearCode);
    const currentYear = Math.max(1, REFERENCE_YEAR - admissionYear);
    const currentSem = currentYear * 2;
    const durationYears = courseDurationYears(a.branchCode, isRepeat);

    for (let n = lo; n <= hi; n++) {
      const roll = makeRoll(a.yearCode, a.branchCode, n, isRepeat);
      out.push({
        roll_number: roll,
        enrollment_no: roll,
        admission_year: admissionYear,
        branch_code: a.branchCode,
        branch: a.branchCode,
        student_number: n,
        is_repeat: isRepeat ? 1 : 0,
        is_lateral: isLateral ? 1 : 0,
        current_year: currentYear,
        current_sem: currentSem,
        course_duration_years: durationYears,
        session_year: `${admissionYear}-${admissionYear + durationYears}`,
        section: "A",
        school: "SOICT",
        department: branchToDepartment(a.branchCode),
        username: `${roll}@gbu.ac.in`,
      });
    }
  }

  // dedupe by roll_number; merge flags
  const map = new Map();
  for (const r of out) {
    const prev = map.get(r.roll_number);
    if (!prev) {
      map.set(r.roll_number, r);
    } else {
      prev.is_repeat = prev.is_repeat || r.is_repeat ? 1 : 0;
      prev.is_lateral = prev.is_lateral || r.is_lateral ? 1 : 0;
    }
  }

  return [...map.values()].sort((x, y) => x.roll_number.localeCompare(y.roll_number));
}

async function getTableColumns(conn, table) {
  const [rows] = await conn.query(`SHOW COLUMNS FROM \`${table}\``);
  return new Set(rows.map((r) => r.Field));
}

function pickStudentPayload(rec, cols) {
  const obj = {};
  const put = (k, v) => {
    if (cols.has(k)) obj[k] = v;
  };

  put("enrollment_no", rec.enrollment_no);
  put("username", rec.username);
  put("roll_no", rec.roll_number);
  put("optional_roll_no", "");
  put("session_year", rec.session_year);
  put("name", `Student ${rec.roll_number}`);
  put("branch", rec.branch);
  put("department", rec.department);
  put("school", rec.school);
  put("year_of_study", rec.current_year);
  put("semester", rec.current_sem);
  put("section", rec.section);
  put("admission_year", rec.admission_year);
  put("program_code", rec.branch_code);
  put("serial_no", rec.student_number);
  put("branch_code", rec.branch_code);
  put("course_duration_years", rec.course_duration_years);

  return obj;
}

async function upsertStudent(conn, cols, rec) {
  const payload = pickStudentPayload(rec, cols);
  const keys = Object.keys(payload);

  const placeholders = keys.map(() => "?").join(",");
  const updates = keys
    .filter((k) => k !== "roll_no")
    .map((k) => `\`${k}\`=VALUES(\`${k}\`)`)
    .join(",");

  // roll_no is the stable unique key in this schema.
  const sql = `INSERT INTO \`students\` (${keys.map((k) => `\`${k}\``).join(",")}) VALUES (${placeholders}) ON DUPLICATE KEY UPDATE ${updates}`;
  const vals = keys.map((k) => payload[k]);
  await conn.execute(sql, vals);

  // resolve student_id
  const [sRows] = await conn.execute("SELECT student_id FROM students WHERE roll_no=? LIMIT 1", [rec.roll_number]);
  const sid = sRows.length ? Number(sRows[0].student_id) : 0;
  if (!sid) return;

  // ensure user row for student login
  const [uRows] = await conn.execute("SELECT user_id FROM users WHERE role='student' AND reference_id=? LIMIT 1", [sid]);
  const password = "$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi"; // student123

  if (uRows.length) {
    await conn.execute("UPDATE users SET username=? WHERE user_id=?", [rec.username, uRows[0].user_id]);
  } else {
    await conn.execute(
      "INSERT INTO users (username,password,role,reference_id) VALUES(?,?, 'student', ?)",
      [rec.username, password, sid]
    );
  }
}

async function main() {
  const args = parseArgs(process.argv);
  if (args.help) {
    help();
    process.exit(0);
  }

  let raw = "";
  if (args.ranges) raw = args.ranges;
  else if (args.file) raw = fs.readFileSync(path.resolve(process.cwd(), args.file), "utf8");
  else if (process.env.ROLL_RANGES) raw = process.env.ROLL_RANGES;

  if (!raw || !raw.trim()) {
    throw new Error("No input found. Use --file, --ranges, or ROLL_RANGES env var.");
  }

  const parsed = parseRanges(raw);
  if (!parsed.length) throw new Error("No valid records parsed.");

  const conn = await mysql.createConnection({
    host: process.env.DB_HOST || "127.0.0.1",
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER || "root",
    password: process.env.DB_PASSWORD || "",
    database: process.env.DB_NAME || "exam_management",
  });

  try {
    const cols = await getTableColumns(conn, "students");
    let done = 0;

    await conn.beginTransaction();
    try {
      for (const rec of parsed) {
        await upsertStudent(conn, cols, rec);
        done++;
      }
      await conn.commit();
    } catch (e) {
      await conn.rollback();
      throw e;
    }

    const byYear = parsed.reduce((acc, r) => {
      acc[r.admission_year] = (acc[r.admission_year] || 0) + 1;
      return acc;
    }, {});

    console.log("Student import completed.");
    console.log(`Total parsed unique rolls: ${parsed.length}`);
    console.log(`Total inserted/updated: ${done}`);
    console.log("By admission year:", byYear);
    console.log("Sample:", parsed.slice(0, 8).map((x) => x.roll_number).join(", "));
  } finally {
    await conn.end();
  }
}

main().catch((err) => {
  console.error("Import failed:", err.message);
  process.exit(1);
});

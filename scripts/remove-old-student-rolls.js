#!/usr/bin/env node
/*
  Remove legacy student records whose roll numbers are simple numeric IDs like 2021001.
  Safe for the current dataset because real imported rolls use coded formats like 245UCS002,
  R-235UCS020, 225/ICS/001, etc.

  Env:
    DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_NAME
*/

const mysql = require('mysql2/promise');

async function main() {
  const conn = await mysql.createConnection({
    host: process.env.DB_HOST || '127.0.0.1',
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'exam_management',
  });

  try {
    const [studentCols] = await conn.query('SHOW COLUMNS FROM students');
    const studentColSet = new Set(studentCols.map(r => r.Field));
    const hasEnrollmentNo = studentColSet.has('enrollment_no');

    const [studentRows] = await conn.query(
      `SELECT student_id, roll_no${hasEnrollmentNo ? ', enrollment_no' : ''}
       FROM students
       WHERE roll_no REGEXP '^[0-9]{7}$'
          ${hasEnrollmentNo ? 'OR enrollment_no REGEXP "^[0-9]{7}$"' : ''}`
    );

    if (!studentRows.length) {
      console.log('No legacy simple-roll students found. Nothing to remove.');
      return;
    }

    const ids = studentRows.map(r => Number(r.student_id)).filter(n => Number.isFinite(n) && n > 0);
    const rolls = studentRows.map(r => r.roll_no);

    await conn.beginTransaction();
    try {
      if (ids.length) {
        await conn.query(`DELETE FROM users WHERE role='student' AND reference_id IN (${ids.map(() => '?').join(',')})`, ids);
        await conn.query(`DELETE FROM students WHERE student_id IN (${ids.map(() => '?').join(',')})`, ids);
      }
      await conn.commit();
    } catch (err) {
      await conn.rollback();
      throw err;
    }

    console.log(`Removed ${ids.length} legacy student record(s).`);
    console.log(`Sample rolls removed: ${rolls.slice(0, 10).join(', ')}`);
  } finally {
    await conn.end();
  }
}

main().catch(err => {
  console.error('Cleanup failed:', err.message);
  process.exit(1);
});

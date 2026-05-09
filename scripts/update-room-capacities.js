#!/usr/bin/env node
/*
  Sync examination room capacities from the provided workbook sheet.

  Usage:
    node scripts/update-room-capacities.js
    node scripts/update-room-capacities.js --dry-run

  Env:
    DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_NAME
*/

const ROOM_CAPACITIES = [
  { room_no: 'IL-101', capacity: 60 },
  { room_no: 'IL-102', capacity: 60 },
  { room_no: 'IL-103', capacity: 60 },
  { room_no: 'IL-104', capacity: 69 },
  { room_no: 'IL-105', capacity: 63 },
  { room_no: 'IL-106', capacity: 61 },
  { room_no: 'IL-200', capacity: 64 },
  { room_no: 'IL-201', capacity: 50 },
  { room_no: 'IL-202', capacity: 101 },
  { room_no: 'IL-203', capacity: 50 },
  { room_no: 'IL-204', capacity: 50 },
  { room_no: 'IL-205', capacity: 50 },
  { room_no: 'IL-206', capacity: 46 },
  { room_no: 'IP-106', capacity: 28 },
  { room_no: 'IT-201', capacity: 20 },
  { room_no: 'IT-202', capacity: 20 },
  { room_no: 'IT-203', capacity: 20 },
  { room_no: 'IT-204', capacity: 19 },
];

const EXPECTED_TOTAL = ROOM_CAPACITIES.reduce((sum, room) => sum + room.capacity, 0);

function parseArgs(argv) {
  return {
    dryRun: argv.includes('--dry-run') || argv.includes('-n'),
  };
}

async function main() {
  const { dryRun } = parseArgs(process.argv.slice(2));

  if (dryRun) {
    console.log(`Applying workbook capacities for ${ROOM_CAPACITIES.length} room(s); expected total: ${EXPECTED_TOTAL}`);
    ROOM_CAPACITIES.forEach((room) => {
      console.log(`${room.room_no} -> ${room.capacity}`);
    });
    return;
  }

  let mysql;
  try {
    mysql = require('mysql2/promise');
  } catch (error) {
    throw new Error('mysql2 is not installed. Run `npm install` before applying the room capacity update.');
  }

  const conn = await mysql.createConnection({
    host: process.env.DB_HOST || 'localhost',
    port: Number(process.env.DB_PORT || 3307),
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || 'Password123',
    database: process.env.DB_NAME || 'exam_management',
  });

  try {
    const [beforeRows] = await conn.query('SELECT COUNT(*) AS rooms_count, COALESCE(SUM(capacity), 0) AS total_capacity FROM rooms');
    const before = beforeRows[0] || { rooms_count: 0, total_capacity: 0 };

    console.log(`Rooms before update: ${before.rooms_count}, total capacity: ${before.total_capacity}`);
    console.log(`Applying workbook capacities for ${ROOM_CAPACITIES.length} room(s); expected total: ${EXPECTED_TOTAL}`);

    await conn.beginTransaction();
    try {
      let updated = 0;
      let missing = 0;

      for (const room of ROOM_CAPACITIES) {
        const [result] = await conn.execute('UPDATE rooms SET capacity=? WHERE room_no=?', [room.capacity, room.room_no]);
        if (result.affectedRows > 0) {
          updated += 1;
        } else {
          missing += 1;
          console.warn(`Room not found, skipped: ${room.room_no}`);
        }
      }

      await conn.commit();

      const [afterRows] = await conn.query('SELECT COUNT(*) AS rooms_count, COALESCE(SUM(capacity), 0) AS total_capacity FROM rooms');
      const after = afterRows[0] || { rooms_count: 0, total_capacity: 0 };

      console.log(`Updated ${updated} room(s); skipped ${missing} missing room(s).`);
      console.log(`Rooms after update: ${after.rooms_count}, total capacity: ${after.total_capacity}`);
    } catch (error) {
      await conn.rollback();
      throw error;
    }
  } finally {
    await conn.end();
  }
}

main().catch((error) => {
  console.error('Room capacity sync failed:', error.message);
  process.exit(1);
});
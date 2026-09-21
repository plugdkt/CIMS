# QA Finding: `ImportMscAccUsersCommandTest` fails 3/4 with DB grant errors on a
# T-027-restricted database

**สถานะ:** ยังไม่แก้ (Open) — แจ้งไว้เพื่อให้ทีม server ตัดสินใจแนวทาง ไม่ได้แก้เอง (test/สคริปต์นี้ไม่ใช่งานของ session นี้)

---

## อาการ

รัน `vendor/bin/pest --filter=ImportMscAccUsersCommandTest` (commit `ad2cd48`) ได้ 3 จาก 4 เทส fail
ด้วย error เดียวกันทุกครั้ง:

```
SQLSTATE[42000]: Syntax error or access violation: 1142 INSERT command denied to user
'cmis_app'@'172.22.0.4' for table `cmis_testing`.`division`
(Connection: mysql, Host: mariadb, Port: 3306, Database: cmis_testing,
 SQL: insert into `division` (`id_div`, `div_name`) values (1, ชีวเคมี))
```

ผ่านแค่เทสเดียวคือ `'it handles database connection failure cleanly'` (ไม่มีการสร้างตาราง/insert ข้อมูลจริง)

## ต้นตอ

`tests/Feature/Labs/ImportMscAccUsersCommandTest.php`'s `createMockMscAccTables()` (บรรทัด ~11-33)
สร้างตาราง `user`/`position`/`division` แบบสดๆ ด้วย `Schema::dropIfExists()` + `Schema::create()`
**ภายในตัวเทสเอง** ทุกครั้ง (ไม่ใช่ migration) แล้ว insert ข้อมูลทดสอบทันทีหลังจากนั้น

โปรเจกต์นี้จำกัดสิทธิ์ผู้ใช้ DB ของแอป (`cmis_app`) ไว้ตั้งแต่ T-027/SEC-DB-02
(`docker/mariadb/restrict_app_grants.sql`) — สคริปต์นี้ให้สิทธิ์ SELECT/INSERT/UPDATE/DELETE
เฉพาะตารางที่**มีอยู่จริง ณ ตอนรันสคริปต์**เท่านั้น (query จาก `information_schema.tables` ตรงๆ)
ตารางที่ถูกสร้างขึ้นทีหลัง (ไม่ว่าจะด้วย migration ใหม่หรือ `Schema::create()` กลางเทส) จะ**ไม่มีสิทธิ์
DML ใดๆ เลย** จนกว่าจะรันสคริปต์นี้ซ้ำอีกครั้ง — เคยเจอบั๊กคลาสเดียวกันมาแล้วตอน T-052 เพิ่มตาราง
`ledger_export_requests` (ดู `CLAUDE.md`'s T-052 notes)

จุดที่ต่างจาก T-052 คือ **คราวนี้ไม่มีทางแก้ด้วยการรันสคริปต์ซ้ำล่วงหน้าได้เลย** — เพราะตาราง
`user`/`position`/`division` ถูก **สร้างและลบทิ้งภายในเทสเดียวกัน** ทุกครั้ง (`afterEach` เรียก
`dropMockMscAccTables()`) การ `DROP TABLE` ลบสิทธิ์ที่เคย GRANT ไว้บนตารางนั้นไปด้วยเสมอ (MySQL/
MariaDB ผูกสิทธิ์กับ table object ไม่ใช่แค่ชื่อ) ต่อให้รัน `restrict_app_grants.sql` ไปตอนไหนก็ตาม
พอถึงเทสถัดไปที่ drop+create ตารางใหม่อีกรอบ สิทธิ์ก็หายไปเหมือนเดิม — ไม่มีจังหวะไหนให้ "grant ล่วงหน้า"
ได้อย่างถาวรตราบใดที่เทสยังสร้าง/ลบตารางแบบนี้อยู่

น่าจะอธิบายได้ว่าทำไมฝั่งทีม server ถึงรันผ่าน (ตามที่ CHANGELOG ระบุ "Pint clean, PHPStan level 8
clean") — เป็นไปได้ว่า environment ของเขาไม่ได้ apply ข้อจำกัดสิทธิ์ตาม
`docker/mariadb/restrict_app_grants.sql` ไว้กับฐาน `cmis_testing` (สคริปต์นี้เป็น manual step
ที่ต้องรันเองหลัง migrate ทุกครั้ง ตามที่ระบุไว้ใน `CLAUDE.md`) — ไม่ใช่ปัญหาของโค้ด logic เอง

## ผลกระทบ

- ไม่กระทบสวีทอื่นทั้งหมด (fatal เฉพาะไฟล์นี้ ไม่ใช่ fatal ทั้งกระบวนการแบบที่เคยเจอ) —
  เทสอื่นในสวีทยังเขียวปกติ (502/502 ก่อนหน้านี้ ไม่รวมไฟล์นี้)
- แต่ทำให้ `vendor/bin/pest` (สวีทเต็ม) ไม่เขียว 100% บนเครื่องที่ apply T-027 grants ไว้ตามมาตรฐาน
  ของโปรเจกต์ — ใครก็ตามที่ clone ใหม่แล้วทำตาม `restrict_app_grants.sql` ตามที่ `CLAUDE.md` แนะนำ
  จะเจอ fail 3 เทสนี้ทันที

## วิธีแก้ที่เป็นไปได้ (ไม่ได้เลือกให้ — เป็นการตัดสินใจของทีม server)

1. เปลี่ยนจากการสร้างตารางสดๆ กลางเทส เป็น migration จริง (แม้จะเป็น migration เฉพาะ test schema)
   — จะได้เข้า flow ปกติที่ `restrict_app_grants.sql` กวาดสิทธิ์ให้ตอน `migrate:fresh` +
   รัน grants script ครั้งเดียวพอ (เหมือนตารางจริงทุกตารางในระบบ)
2. เพิ่ม DB connection สำรองสำหรับ setup เทส (เช่น connection ที่ใช้ user `root`/`cmis_root`)
   แล้วให้ `createMockMscAccTables()` รัน GRANT ให้ตัวเองผ่าน connection นั้นทันทีหลังสร้างตาราง —
   เพิ่มความซับซ้อนให้ test bootstrap พอสมควร
3. ไม่ใช้ตารางจริงเลย — mock ที่ชั้น `DB::connection('msc_acc')` แทน (เช่นด้วย `Http::fake()`-style
   mocking หรือ query builder mock) แทนที่จะสร้าง schema จริง — เปลี่ยนวิธีทดสอบทั้งหมด

## สถานะการตรวจสอบล่าสุด

- `vendor/bin/pest --filter=ImportMscAccUsersCommandTest`: **3/4 fail** (ทุกเทสที่ insert ข้อมูลจริง),
  ผ่านเฉพาะเทส connection-failure
- สวีทที่เหลือทั้งหมด (ไม่รวมไฟล์นี้): เขียวปกติ (ดูผลใน commit message/CHANGELOG ของรอบนี้)
- Pint / PHPStan level 8 / `composer audit`: clean ทั้งหมด (ไม่กระทบจากปัญหานี้ เพราะเป็น runtime DB
  grant ไม่ใช่ static analysis)

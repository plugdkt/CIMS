# รายงานการทดสอบเจาะระบบ (Penetration Test Report) — CMIS

**วันที่ทดสอบ**: 2026-09-09
**ผู้ทดสอบ**: Claude (agent-conducted security review)
**เป้าหมาย**: nginx + PHP-FPM stack (`localhost:8091`, production-representative — ดู CLAUDE.md's T-052
notes สำหรับเหตุผลที่ไม่ใช้ `localhost:8090`)

## ขอบเขตและข้อจำกัด (อ่านก่อน)

**นี่ไม่ใช่ penetration test แบบเป็นทางการโดยทีม/บริษัทที่ได้รับใบอนุญาต** — เป็นการตรวจสอบเชิงลึกที่ agent
(Claude) ทำได้ด้วยตนเองภายในสภาพแวดล้อมทดสอบ (Docker, ข้อมูลทดสอบ ไม่ใช่ข้อมูลจริง) ประกอบด้วย:

1. OWASP ZAP **Active Scan** (`zap-full-scan.py`) — ส่ง payload โจมตีจริงหลายสิบประเภท (SQLi, XSS, SSRF,
   SSTI, XXE, RCE เช่น Log4Shell/Spring4Shell/Text4Shell, Command Injection, Path Traversal ฯลฯ)
2. การทดสอบด้วยมือ (manual testing) ครอบคลุมสถานการณ์ ST-01 ถึง ST-12 ตาม spec §11.3 โดยเฉพาะรายการที่
   เป็น business-logic authorization ซึ่งเครื่องมืออัตโนมัติทั่วไปตรวจจับได้ยาก (IDOR, privilege escalation
   ข้ามบทบาท)

**สิ่งที่ทำไม่ได้ในการทดสอบนี้**: การทดสอบ social engineering, physical security, wireless security,
การทดสอบกับ production จริง (มี TLS จริง, ข้อมูลจริง, ปริมาณข้อมูลจริง), หรือการตรวจสอบโดยผู้เชี่ยวชาญ
มนุษย์ที่มีประสบการณ์ค้นหาช่องโหว่เชิงสร้างสรรค์ (creative/manual exploitation) — **แนะนำให้จ้างทีม
penetration test มืออาชีพก่อนเปิดใช้งานจริงกับข้อมูลจริง** โดยเฉพาะก่อนที่จะประมวลผลข้อมูลส่วนบุคคลจริงตาม
PDPA

## สรุปผลโดยรวม

| หมวด | ผล |
|---|---|
| ZAP Active Scan — Medium/High risk ที่ยืนยันเป็นช่องโหว่จริง | **0 รายการ** |
| ZAP Active Scan — Medium risk ที่ตรวจสอบแล้วเป็น false positive/ข้อจำกัดสภาพแวดล้อม | 2 รายการ (ดูรายละเอียด) |
| ช่องโหว่ที่ยอมรับไว้แล้วอย่างมีเอกสารรองรับ (CSP) | 2 รายการ (ตั้งแต่ T-053) |
| การทดสอบ Authorization ด้วยมือ (IDOR, privilege escalation) | **ผ่านทุกกรณี** |
| จุดที่พบและแก้ไขจริงระหว่างการทดสอบ | 1 รายการ (bug ด้าน availability ไม่ใช่ช่องโหว่ด้านความปลอดภัยโดยตรง — ดูรายละเอียด) |

## ผลการทดสอบ ZAP Active Scan

รันด้วย `zaproxy/zap-stable`'s `zap-full-scan.py` (Docker) เข้ากับ compose network เดียวกับ nginx —
ผลลัพธ์เต็ม: `storage/app/zap-report/zap-fullscan-report.html` (ไม่ commit เข้า git — gitignored)

**PASS ทั้งหมด 132 กฎ** รวมถึง: SQL Injection (MySQL/MsSQL/PostgreSQL/Oracle/Hypersonic, ทั้งแบบ error-based
และ time-based), Cross-Site Scripting (reflected/persistent/DOM-based), Remote Code Execution
(Log4Shell, Spring4Shell, Text4Shell, React2Shell, CVE-2012-1823), Server-Side Template Injection,
Server-Side Request Forgery, XML External Entity, NoSQL Injection, Path/Remote File Inclusion, Command
Injection (รวม time-based), XPath/LDAP/Expression Language Injection, Buffer Overflow, Format String,
Session Fixation, และอื่น ๆ

**4 Medium-risk findings**, ตรวจสอบทีละรายการ:

1. **CSP script-src unsafe-eval / style-src unsafe-inline** (2 รายการ) — ข้อยกเว้นที่อนุมัติไว้แล้วตั้งแต่
   2026-08-31 (Livewire 3/Alpine.js ต้องการ) มีเอกสารรองรับที่ `docker/zap/baseline.conf` และ CLAUDE.md —
   ไม่ใช่ช่องโหว่ใหม่
2. **"Bypassing 403"** (ZAP rule 40038) — ZAP รายงานว่าการส่ง header `X-Original-URL: /build` อาจ bypass
   การป้องกัน 403 ได้ **ตรวจสอบด้วยมือแล้วพบว่าเป็น false positive**: ทดสอบส่ง header เดียวกันไปยัง route
   ที่มีการป้องกันจริง (`/admin/users` แบบไม่ล็อกอิน) ได้ผลลัพธ์เหมือนกันทุกประการทั้งมีและไม่มี header
   (302 redirect ไปหน้าล็อกอินเสมอ) — nginx และ Laravel ของระบบนี้ไม่ได้ประมวลผล header ตัวนี้เป็นพิเศษเลย
   บันทึกไว้เป็น waiver แล้ว
3. **"HTTP Only Site"** — สภาพแวดล้อมทดสอบนี้ (Docker dev) ไม่ได้เปิด TLS จริง (ตั้งใจ ไม่ใช่ข้อบกพร่อง) —
   production ตาม `docs/iis_installation_guide.md` บังคับ HTTPS อยู่แล้ว **ต้องรันสแกนซ้ำกับ URL จริงที่มี
   TLS ก่อนเปิดใช้งานจริง** เพื่อยืนยันว่าปัญหานี้หายไปตามที่คาดหวัง — บันทึกเป็น known open item

## การทดสอบด้วยมือ (Manual Testing)

ทดสอบด้วยบัญชีจริง 1 บัญชีต่อบทบาท (STUDENT ×2, ADVISOR, SCIENTIST, LAB_MANAGER, ADMIN, AUDITOR, และบัญชี
ไม่มีบทบาทเลย 1 บัญชี) ผ่าน HTTP โดยตรง (ไม่ผ่านหน้าเว็บ เพื่อทดสอบว่า authorization ทำงานที่ระดับ endpoint
จริง ไม่ใช่แค่ซ่อนปุ่มในหน้าเว็บ):

| การทดสอบ | ผลลัพธ์ |
|---|---|
| **ST-04** (IDOR): นิสิต A เปิด URL ใบเบิกของนิสิต B | ✅ 403 ถูกต้อง (นิสิต A เปิดของตัวเองได้ 200 ปกติ) |
| **ST-05**: อัปโหลดไฟล์ PHP webshell เปลี่ยนนามสกุลเป็น `.pdf` | ✅ ถูกปฏิเสธ ไม่มีการบันทึกไฟล์ในระบบ |
| **ST-06/ST-07**: เข้าถึง `/.env`, `/composer.json`, `/vendor/autoload.php`, `/.git/config`, ไฟล์แนบ
  โดยตรง | ✅ ทั้งหมดตอบ 403/404 ถูกต้อง ผ่าน nginx ของจริง (ไม่ใช่แค่ Laravel test client) |
| **ST-10** (role escalation): STUDENT/SCIENTIST/LAB_MANAGER/AUDITOR ยิง endpoint ที่ไม่มีสิทธิ์โดยตรง
  (bypass หน้าเว็บ, ใช้ CSRF token จริงของตนเอง) | ✅ 403 ทุกกรณี รวมการ POST ตรงไปที่ endpoint สร้าง
  รายการปรับยอด (ไม่ใช่แค่หน้า GET) |
| **ST-10b**: บัญชีที่ล็อกอินสำเร็จแต่ยังไม่มีบทบาท เรียก route อื่นนอกจากหน้ารอกำหนดสิทธิ์ | ✅ 403
  ถูกต้อง, หน้ารอกำหนดสิทธิ์เข้าถึงได้ปกติ (200) |

## จุดที่พบและแก้ไขระหว่างการทดสอบ (ไม่ใช่ช่องโหว่ด้านความปลอดภัยโดยตรง แต่กระทบความพร้อมใช้งาน)

**Permission mismatch ระหว่าง `app` container (root) กับ `fpm` container (www-data)**: การรัน
`docker compose exec app php artisan ...` (root) เขียนไฟล์ log/cache เป็นเจ้าของ root ทำให้ `fpm`'s
worker (รันเป็น `www-data` ตามหลัก least-privilege ที่ถูกต้อง) เขียนทับไฟล์เดิมไม่ได้ ส่งผลให้ทุก request
ที่ต้องเขียน log หรือ compile view ใหม่ตอบ HTTP 500 แบบไม่มี error message ที่ชัดเจน (รวมถึง health-check
route `/up` เอง) — **แก้ไขแล้วด้วย `chown -R www-data:www-data storage bootstrap/cache`** บันทึกเป็น
gotcha ในการดูแลระบบไว้ใน CLAUDE.md (ต้องรันคำสั่งนี้ซ้ำทุกครั้งหลังใช้ `docker compose exec app` เขียนไฟล์
แล้วจะทดสอบผ่าน nginx+fpm อีกครั้ง) — ไม่ใช่ปัญหาที่จะเกิดในการติดตั้งจริงบน IIS (ไม่มี container สอง
ตัวเขียนไฟล์เดียวกันแบบนี้)

## ข้อเสนอแนะก่อนเปิดใช้งานจริง

1. รันสแกน ZAP ทั้งแบบ baseline และ active ซ้ำกับ URL production จริงที่มี TLS ก่อน Go-Live
2. พิจารณาจ้างทีม penetration test มืออาชีพสำหรับการตรวจสอบรอบสุดท้ายก่อนประมวลผลข้อมูลส่วนบุคคลจริง
   (โดยเฉพาะเมื่อพิจารณาตาม PDPA และข้อมูลสารเคมีที่มีความอ่อนไหวด้านความปลอดภัย)
3. ทำ UAT ตาม `docs/uat_test_plan.md` คู่ขนานกันได้ (ไม่ต้องรอผลจากกันและกัน)

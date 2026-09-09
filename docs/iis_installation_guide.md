# คู่มือการติดตั้งระบบ CMIS บน IIS 10 / Windows Server 2022

เอกสารนี้ครอบคลุมการติดตั้ง CMIS (Chemical & Material Inventory System) ขึ้นใช้งานจริงตามสถาปัตยกรรมที่กำหนดใน
spec §4.1 (`IIS 10 → FastCGI → PHP 8.3/Laravel → MariaDB 11.4 + Redis 7`) — **ไม่ใช่**สภาพแวดล้อม Docker ที่ใช้
พัฒนา (`docker-compose.yml`) ซึ่งมีไว้สำหรับนักพัฒนาเท่านั้น

> ก่อนเริ่ม: อ่าน `_spec.md_AI_Agent_.md` §9 (Security Requirements ทั้งหมด, โดยเฉพาะ §9.6–§9.9) และ
> `docs/backup_restore_runbook.md` (T-054) ประกอบ — เอกสารนี้อ้างอิงเลข SEC-IIS-xx/SEC-DB-xx จากที่นั่น

## สารบัญ

1. [ข้อกำหนดเบื้องต้น (Prerequisites)](#1-ข้อกำหนดเบื้องต้น-prerequisites)
2. [โครงสร้างไดเรกทอรีบนเซิร์ฟเวอร์จริง](#2-โครงสร้างไดเรกทอรีบนเซิร์ฟเวอร์จริง)
3. [ติดตั้ง MariaDB](#3-ติดตั้ง-mariadb)
4. [ติดตั้ง Redis (ข้อควรระวัง: ไม่มี native Windows build)](#4-ติดตั้ง-redis-ข้อควรระวัง-ไม่มี-native-windows-build)
5. [ติดตั้ง PHP 8.3 + FastCGI บน IIS](#5-ติดตั้ง-php-83--fastcgi-บน-iis)
6. [Deploy โค้ดแอปพลิเคชัน](#6-deploy-โค้ดแอปพลิเคชัน)
7. [ตั้งค่า IIS Site + web.config](#7-ตั้งค่า-iis-site--webconfig)
8. [Queue Worker และ Scheduled Task (แทน cron)](#8-queue-worker-และ-scheduled-task-แทน-cron)
9. [Backup ตามตารางเวลา (NFR-09)](#9-backup-ตามตารางเวลา-nfr-09)
10. [TLS/SSL Certificate](#10-tlsssl-certificate)
11. [Smoke Test หลังติดตั้ง](#11-smoke-test-หลังติดตั้ง)
12. [Checklist ความปลอดภัยก่อนเปิดใช้งานจริง (Go-Live)](#12-checklist-ความปลอดภัยก่อนเปิดใช้งานจริง-go-live)

---

## 1. ข้อกำหนดเบื้องต้น (Prerequisites)

| รายการ | เวอร์ชัน/หมายเหตุ |
|---|---|
| ระบบปฏิบัติการ | Windows Server 2022 |
| Web Server | IIS 10 (เปิด role: Web Server (IIS), CGI, URL Rewrite Module, Application Request Routing ไม่จำเป็น) |
| PHP | 8.3.x (Non-Thread-Safe / NTS build สำหรับ FastCGI) |
| Database | MariaDB 11.4 |
| Cache/Session/Queue | Redis 7 (ดูข้อ 4 — ไม่มี native Windows build) |
| Composer | 2.x |
| PowerShell | 5.1 ขึ้นไป (มีมาพร้อม Windows Server 2022) |
| ใบรับรอง TLS | ใบรับรองจริงสำหรับโดเมนที่ใช้งาน (ไม่ใช่ self-signed สำหรับ production) |

**IIS Module ที่ต้องติดตั้งเพิ่ม** (ไม่มีมาพร้อม Windows Server ปกติ):
- [URL Rewrite Module 2.1](https://www.iis.net/downloads/microsoft/url-rewrite) — จำเป็นสำหรับ `public/web.config`'s
  `<rewrite>` section (Laravel front controller + force HTTPS)
- IIS ต้องเปิด **CGI** role service (Server Manager → Add Roles and Features → Web Server (IIS) → Application
  Development → CGI) — เป็น host สำหรับ php-cgi.exe ผ่าน FastCGI

## 2. โครงสร้างไดเรกทอรีบนเซิร์ฟเวอร์จริง

ตาม spec §4.3 และ §9.8 (open_basedir) ให้แยกโค้ด/ข้อมูล/log ออกจากกันชัดเจน — **ห้ามเก็บทุกอย่างไว้ที่เดียว**:

```
D:\cmis\                  ← โค้ดแอปพลิเคชันทั้งหมด (git clone / deploy artifact)
  ├── app\ ...
  ├── public\              ← IIS Site's Physical Path ต้องชี้ที่นี่เท่านั้น (SEC-IIS-03)
  │     └── web.config      ← มีอยู่แล้วในโค้ด (ตรงตาม spec §9.6 ทุกตัวอักษร)
  ├── storage\             ← ต้องเขียนได้ (SEC-IIS-02)
  └── bootstrap\cache\     ← ต้องเขียนได้ (SEC-IIS-02)

D:\cmis_storage\           ← ไฟล์แนบ/ลายเซ็นที่ผู้ใช้อัปโหลด นอก webroot (attachments/signatures disks)
D:\cmis_logs\              ← php_error.log (§9.8) — laravel.log อยู่ใน storage\logs ตามปกติ
D:\cmis_backups\           ← ผลลัพธ์จาก backup script (T-054) — ดูข้อ 9
```

`.env` ของ production ต้องปรับ path ให้ตรงกับโครงสร้างนี้ (`FILESYSTEM_DISK`, disk `root` ต่าง ๆ ใน
`config/filesystems.php` อ้างอิง `storage_path()` อยู่แล้ว — สิ่งที่ต้องย้ายจริงคือ **สร้าง symlink หรือปรับ
`storage_path()`** ให้ physical location คือ `D:\cmis_storage\` ไม่ใช่ `D:\cmis\storage\app\`, ถ้าต้องการแยก
disk จริงตาม spec — หรือจะเก็บไว้ใต้ `D:\cmis\storage\` ตามที่ Laravel default ก็ได้ ตราบใดที่ `open_basedir`
(§9.8) ครอบคลุมทั้งสอง path).

## 3. ติดตั้ง MariaDB

1. ติดตั้ง MariaDB 11.4 (MSI installer จากเว็บ mariadb.org) — เลือก **"Configure as a Windows Service"**
2. สร้างฐานข้อมูลและผู้ใช้งาน (ผ่าน `mysql`/`mariadb` client หรือ HeidiSQL):
   ```sql
   CREATE DATABASE cmis CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'cmis_app'@'localhost' IDENTIFIED BY '<รหัสผ่านที่แข็งแรง>';
   GRANT ALL PRIVILEGES ON cmis.* TO 'cmis_app'@'localhost';
   FLUSH PRIVILEGES;
   ```
3. **สำคัญ (SEC-DB-03)**: bind MariaDB ที่ `127.0.0.1` เท่านั้น (`my.ini` → `[mysqld]` → `bind-address =
   127.0.0.1`) และปิดพอร์ต 3306 ที่ Windows Firewall ไม่ให้เข้าจากภายนอกเครื่องได้
4. เปิด `sql_mode` ตาม SEC-DB-05: `STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO`
5. รัน migration: `php artisan migrate --force` แล้ว `php artisan db:seed --force`
6. **รัน `docker/mariadb/restrict_app_grants.sql`'s เนื้อหา (ปรับ path ให้เป็น native Windows MariaDB
   client) ทันทีหลัง migrate** — เป็นขั้นตอนบังคับตาม SEC-DB-02 (revoke UPDATE/DELETE บน `stock_ledger`/
   `audit_logs` จาก `cmis_app`) ที่ยังไม่ถูกทำอัตโนมัติที่ไหนเลย ดู CLAUDE.md's T-027 note ประกอบ (สคริปต์
   เขียนไว้สำหรับ Docker container แต่ตรรกะ SQL ใช้ได้เหมือนกันทุกที่ที่รัน MariaDB)
7. **หมายเหตุสำคัญเกี่ยวกับ SEC-DB-01** (3 DB users แยกสิทธิ์: `cmis_app`/`cmis_ledger`/`cmis_report`):
   โค้ดปัจจุบันยังใช้ DB connection เดียว (`cmis_app`) สำหรับทุกการเชื่อมต่อ ไม่ได้แยกเป็น 3 connections ตาม
   spec ตัวอักษร — เป็น known gap ที่บันทึกไว้ใน CLAUDE.md (T-027) แล้ว SEC-DB-02 (ป้องกัน UPDATE/DELETE บน
   ledger) ถูก enforce แล้วผ่าน `cmis_app`'s grants เอง จึงยังไม่ปิดความเสี่ยงหลักที่ SEC-DB-01/02 มุ่งป้องกัน
8. **T-054 (NFR-09)**: เปิด binary log ต่อเนื่องตาม `docker/mariadb/conf.d/backup.cnf`'s เนื้อหา (แปลงเป็น
   `my.ini` ของ native MariaDB) — **ต้องใส่ `log_bin_trust_function_creators=1` ด้วย** มิฉะนั้น migration ที่
   สร้าง trigger (`stock_ledger`'s append-only triggers) จะ error 1419 ทันที (พบจริงระหว่างพัฒนา ดู CLAUDE.md)

## 4. ติดตั้ง Redis (ข้อควรระวัง: ไม่มี native Windows build)

Redis ไม่มี build อย่างเป็นทางการสำหรับ Windows Server อีกต่อไป (Microsoft's fork เลิกดูแลแล้ว) —
spec เลือก Redis 7 สำหรับ Session/Cache/Queue/Rate-limit (§4.1) แต่ deploy จริงบน Windows Server ต้องเลือก
ทางใดทางหนึ่ง:

- **Memurai** (แนะนำ) — Redis-compatible ที่ compile สำหรับ Windows โดยเฉพาะ มีทั้งเวอร์ชันฟรีและ
  commercial license, ติดตั้งเป็น Windows Service ได้ตรง ใช้ protocol เดียวกับ Redis ทุกประการ
- **WSL2 + Redis จริง** — รัน Redis ผ่าน WSL2 บนเครื่องเดียวกัน แล้วให้ Laravel เชื่อมต่อผ่าน `127.0.0.1`
  (WSL2's localhost forwarding) — เพิ่มความซับซ้อนในการดูแลระบบ (ต้องมี WSL2 auto-start ตอน boot)
- **เซิร์ฟเวอร์ Redis แยกต่างหาก** (Linux VM/container) บนเครือข่ายภายในเดียวกัน เข้าถึงผ่าน TLS/firewall
  rule เฉพาะ IIS host

เอกสารนี้ไม่ได้เลือกให้ตายตัว (เป็นการตัดสินใจด้าน infrastructure ที่ต้องมีคนตัดสินใจจริง) — บันทึกไว้ใน
CLAUDE.md's Known open items ว่ายังไม่ได้เลือก

## 5. ติดตั้ง PHP 8.3 + FastCGI บน IIS

1. ดาวน์โหลด PHP 8.3 **Non-Thread-Safe (NTS) x64** build จาก windows.php.net (VC15/VS16 ตาม IIS/Windows
   Server 2022 requirement) แตกไฟล์ไว้ที่ เช่น `C:\PHP83\`
2. เปิด extensions ที่จำเป็นใน `php.ini` (`extension=`): `bcmath`, `pdo_mysql`, `mysqli`, `gd`, `zip`,
   `intl`, `exif`, `pcntl`, `opcache`, `redis` (ถ้าใช้ Memurai ให้ใช้ PECL `redis` extension ตัวเดียวกัน — 
   protocol เข้ากันได้)
3. ใส่ค่า hardening ตาม spec §9.8 ทั้งหมดลงใน `php.ini` (`expose_php=Off`, `display_errors=Off`,
   `disable_functions=exec,passthru,shell_exec,system,proc_open,popen,parse_ini_file,show_source`,
   `open_basedir` ครอบ `D:\cmis\;D:\cmis_storage\;C:\Windows\Temp\`, `session.cookie_secure=1` ฯลฯ) —
   **ข้อควรระวัง**: `disable_functions` ที่รวม `proc_open`/`popen` จะทำให้ `php artisan queue:work`
   (background worker) และ Composer ใช้งานไม่ได้ถ้าใช้ `php.ini` ตัวเดียวกัน — ดูข้อ 8 สำหรับวิธีแยก
   `php.ini` ระหว่าง FastCGI (เว็บ) กับ CLI (worker/scheduler/artisan command)
4. ติดตั้ง PHP Manager for IIS (ปลั๊กอินฟรีจาก Microsoft) หรือทำมือผ่าน `%windir%\system32\inetsrv\
   appcmd.exe` เพื่อสร้าง FastCGI Application ชี้ที่ `C:\PHP83\php-cgi.exe`
5. ตั้งค่า **Application Pool** (SEC-IIS-01): สร้าง App Pool ใหม่เฉพาะ CMIS, Identity เป็น custom local
   account สิทธิ์ต่ำสุด (ไม่ใช่ `ApplicationPoolIdentity` default ก็ได้ถ้าต้องการควบคุมสิทธิ์ไฟล์ละเอียด
   กว่า, ไม่ใช่ `LocalSystem`/`NetworkService` เด็ดขาด) — ให้สิทธิ์ NTFS แค่ Read+Execute บน `D:\cmis\`,
   Modify เฉพาะ `D:\cmis\storage\`, `D:\cmis\bootstrap\cache\`, และ `D:\cmis_storage\` (SEC-IIS-02)

## 6. Deploy โค้ดแอปพลิเคชัน

```powershell
cd D:\
git clone <repository-url> cmis
cd cmis
composer install --no-dev --optimize-autoloader
copy .env.example .env
notepad .env   # ใส่ค่า production จริง: DB, Redis, SSO_CLIENT_SECRET, APP_KEY ฯลฯ
php artisan key:generate
php artisan storage:link
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**ข้อควรระวังจริงที่พบระหว่างพัฒนา (T-052)**: `route:cache` จะ error ทันทีถ้ามี route ที่ใช้ Closure
(`Route::get('/x', function () {...})`) — โค้ด production ไม่มี Closure route หลงเหลืออยู่แล้ว (ลบออกหมด
ตอนจบแต่ละงานที่ใช้ทดสอบ) แต่ถ้าเพิ่ม route ใหม่ในอนาคตต้องเป็น Controller action เสมอ ไม่ใช่ Closure ถ้า
ต้องการให้ `route:cache` ใช้งานได้

**ข้อควรระวังอีกข้อ**: หลัง `config:cache` แล้ว ถ้าจะรัน `php artisan test` (เช่น สำหรับ CI/CD pipeline บน
เซิร์ฟเวอร์นี้) **ต้องรัน `php artisan optimize:clear` ก่อนเสมอ** — config ที่ cache ไว้จะทำให้ค่า env ที่
`phpunit.xml` override (เช่น `DB_DATABASE=cmis_testing`) ไม่มีผล และเทสต์ CSRF จะ fail แบบดูไม่เกี่ยวกัน
(พบจริงระหว่าง T-052 — ดู CLAUDE.md)

## 7. ตั้งค่า IIS Site + web.config

1. IIS Manager → Add Website → **Physical Path ต้องชี้ที่ `D:\cmis\public\` เท่านั้น** (SEC-IIS-03) — ห้าม
   ชี้ที่ `D:\cmis\` ตรง ๆ เด็ดขาด (จะเปิดให้เข้าถึง `.env`, `app/`, `vendor/` ผ่าน URL ได้)
2. ผูก Binding เป็น HTTPS พร้อมใบรับรอง TLS จริง (ดูข้อ 10) — ปิด binding HTTP ธรรมดา (ให้ `web.config`'s
   "Force HTTPS" rule redirect เอง ไม่ต้องมี HTTP binding แยก หรือถ้ามีให้ redirect ผ่าน rule เดิม)
3. `public/web.config` **มีอยู่ในโค้ดแล้ว ตรงตาม spec §9.6 ทุกตัวอักษร** — ไม่ต้องเขียนใหม่ ตรวจสอบแค่ว่า
   URL Rewrite Module (ข้อ 1) ติดตั้งแล้ว มิฉะนั้น IIS จะขึ้น HTTP 500.19 ทันทีที่เข้าเว็บ (config error
   เพราะ `<rewrite>` section ไม่รู้จัก)
4. ปิด **Directory Browsing** และ **Detailed Errors** ที่ระดับ Site (นอกเหนือจากใน web.config) —
   IIS Manager → เลือก Site → Directory Browsing → Disable; ASP → Error Pages → ตั้งเป็น
   "Custom error pages" ไม่ใช่ "Detailed errors" (SEC-IIS-05)
5. เปิด **Dynamic IP Restriction** (SEC-IIS-07): IIS Manager → Dynamic IP Security → ตั้ง "Deny IP
   Address Based on the number of requests" > 100 requests / 10 seconds
6. ปิด HTTP verbs ที่ไม่ใช้ (SEC-IIS-08): `TRACE`, `TRACK`, `OPTIONS` — ผ่าน Request Filtering →
   HTTP Verbs tab
7. เปิด IIS Logging (SEC-IIS-09) และตั้งค่าส่งต่อไปยัง Windows Event Forwarding/SIEM ตามนโยบายของคณะ/
   มหาวิทยาลัย (ไม่มีมาตรฐานเดียวที่ใช้ได้ทุกที่ — ต้องประสานกับทีม Network/Security)

## 8. Queue Worker และ Scheduled Task (แทน cron)

Windows ไม่มี cron — ใช้ **Task Scheduler** แทนสำหรับทุกอย่างที่ spec คาดหวังว่าจะรันเป็นระยะ:

### 8.1 Laravel Scheduler (แทน `* * * * * php artisan schedule:run`)

สร้าง Scheduled Task ใหม่ (`taskschd.msc`):
- Trigger: Daily, repeat every 1 minute, indefinitely
- Action: `C:\PHP83\php.exe D:\cmis\artisan schedule:run`
- Run whether user is logged on or not, สิทธิ์เป็น service account เดียวกับ App Pool

คำสั่งที่ผูกกับ scheduler นี้ (มาจากงานก่อนหน้า — T-044/T-047): `notifications:check-reorder-point`,
`notifications:check-expiry`, `notifications:check-shelf-life`, `notifications:check-hash-chain` (ทุก
ชั่วโมง), `ledger:snapshot` (รายเดือน) — ทั้งหมดลงทะเบียนไว้ใน `routes/console.php` แล้ว ไม่ต้องตั้งค่า
อะไรเพิ่มนอกจาก schedule:run ตัวเดียวนี้

### 8.2 Queue Worker (แทน `php artisan queue:work` ที่ต้องรันค้างตลอดเวลา)

ปัญหา: `queue:work` เป็น process ที่ต้องรันต่อเนื่อง ไม่ใช่งานที่ตั้งเวลาแล้วจบ — Task Scheduler เพียงอย่าง
เดียวไม่เหมาะ (จะสร้าง process ใหม่ซ้อนกันทุกครั้งที่ trigger) วิธีที่ถูกต้อง:

- ใช้ **NSSM (Non-Sucking Service Manager)** ห่อ `php.exe D:\cmis\artisan queue:work --tries=3` เป็น
  Windows Service ตัวจริง (รีสตาร์ทอัตโนมัติถ้า process ตาย, start ตอน boot) — เป็นวิธีที่แนะนำที่สุด
- ทางเลือก: Scheduled Task แบบ "Run whether user is logged on or not" + trigger "At startup" (ไม่มี
  auto-restart ถ้า process ตายกลางทาง ต้องมี monitoring เพิ่มเอง)

**สำคัญ (T-052)**: งานคิว `GenerateLedgerPdfExportJob` (ส่งออก PDF บัญชีคุมขนาดใหญ่ ≥ 5,000 แถว) เรียก
`ini_set('memory_limit', '1536M')` เอง — ค่านี้ override ได้เพราะ `memory_limit` เป็น `PHP_INI_ALL` ใน PHP
แต่ **php.ini ของ queue worker (CLI SAPI) ควรตั้ง `memory_limit` เริ่มต้นให้สูงกว่าเว็บ (256M ตาม §9.8)
อยู่แล้ว** เช่น 512M-1G เพื่อไม่ให้งานอื่นที่ไม่ได้ ini_set เองพลาดไปด้วย — และ**ต้องไม่ใช้ php.ini ตัว
เดียวกับเว็บถ้าเว็บปิด `proc_open`/`popen` ไว้** (§9.8's `disable_functions`) เพราะ queue worker ไม่ได้พึ่ง
ฟังก์ชันเหล่านี้โดยตรง แต่ปลอดภัยกว่าถ้าแยก `php-cli.ini` เฉพาะสำหรับ CLI SAPI (PHP อ่าน `php.ini` ต่างกัน
ระหว่าง SAPI อยู่แล้วตามค่า default ของ php.exe ที่ compile ไว้ — ตรวจสอบด้วย `php --ini`)

## 9. Backup ตามตารางเวลา (NFR-09)

ดู `docs/backup_restore_runbook.md` (T-054) สำหรับกลไกแบบเต็ม — สรุปสำหรับ Windows Server จริง:

- สร้าง Scheduled Task รายวัน รัน `mariadb-dump.exe` (native, path ที่ MariaDB ติดตั้ง) ด้วยชุด flag
  เดียวกับ `docker/mariadb/backup-full.sh` (`--single-transaction --routines --triggers --events
  --master-data=2`) เขียนไฟล์ไปที่ `D:\cmis_backups\`
- **Binlog ไฟล์เองต้อง backup แยกต่างหากด้วย** (ไม่ใช่แค่ `mariadb-dump`) — ใช้ Volume Shadow Copy (VSS)
  หรือ script คัดลอกไฟล์ `.000001`, `.000002` ฯลฯ จากโฟลเดอร์ data ของ MariaDB ไปเก็บที่อื่นเป็นระยะ (เช่น
  ทุกชั่วโมง) มิฉะนั้นถ้า disk เดียวกันเสีย จะกู้คืนแบบ point-in-time ไม่ได้เลย แม้จะมี full backup ก็ตาม
- **ย้าย backup ออกนอกเครื่องเดียวกัน** (object storage, เครื่องอื่น, tape) — ปัจจุบันยังไม่มีการทำแบบนี้
  แม้แต่ในสภาพแวดล้อมพัฒนา (บันทึกเป็น known open item ใน runbook แล้ว) เป็นสิ่งที่ต้องทำก่อนขึ้น production
  จริง มิฉะนั้น NFR-09 จะไม่ครอบคลุมกรณีเครื่องเสียทั้งเครื่อง (เหลือแค่กรณี "ข้อมูลผิดพลาดแต่ดิสก์ยังอยู่")

## 10. TLS/SSL Certificate

1. ขอใบรับรองจากหน่วยงาน CA ของมหาวิทยาลัย/คณะ หรือ Let's Encrypt (ถ้านโยบายอนุญาต) สำหรับโดเมนที่ใช้จริง
2. Import เข้า Windows Certificate Store (Local Computer → Personal) แล้วผูกกับ IIS Site's HTTPS binding
3. บังคับ TLS 1.2/1.3 เท่านั้น ปิด TLS 1.0/1.1/SSLv3 ที่ระดับ Windows Server (IIS Crypto tool หรือ registry
   ตรง — ไม่ใช่การตั้งค่าใน web.config)
4. HSTS header (`Strict-Transport-Security: max-age=31536000; includeSubDomains`) มีอยู่แล้วใน
   `public/web.config` — ทำงานได้ทันทีที่ TLS binding ถูกต้อง

## 11. Smoke Test หลังติดตั้ง

ทำตามลำดับนี้ทุกครั้งหลังติดตั้ง/deploy ใหม่:

1. `https://<โดเมน>/login` โหลดได้ ไม่มี error 500
2. ล็อกอินผ่าน MEDSCI ACC สำเร็จ → เจอหน้า "รอผู้ดูแลระบบกำหนดสิทธิ์การใช้งาน" (ถูกต้องสำหรับผู้ใช้ใหม่)
3. Grant role แรก (ดู `docs/admin_manual.md` §1) แล้วยืนยันว่าเมนูตรงตาม role ที่ให้
4. ตรวจสอบ response header ผ่าน browser dev tools หรือ `curl -I` ว่ามี `Content-Security-Policy`,
   `Strict-Transport-Security`, `X-Content-Type-Options: nosniff` และ**ไม่มี** `Server`/`X-Powered-By`
5. ทดสอบสร้างใบขอเบิก 1 ใบ, รับของ 1 GRN, ดู PDF บัญชีคุม (F-03) ว่าภาษาไทยแสดงถูกต้อง (ฟอนต์ Sarabun ไม่ใช่
   กล่องสี่เหลี่ยม)
6. รัน `php artisan ledger:verify` ต้องรายงาน chain สมบูรณ์ 100% (ฐานข้อมูลใหม่ที่ไม่มี ledger เลยถือว่าผ่าน
   โดยปริยาย)
7. ทดสอบ backup script (ข้อ 9) หนึ่งรอบ ยืนยันว่าไฟล์ถูกสร้างจริงและ restore-drill ทำงานได้ (ตาม runbook)

## 12. Checklist ความปลอดภัยก่อนเปิดใช้งานจริง (Go-Live)

- [ ] SEC-IIS-01..10 ทุกข้อผ่าน (ตรวจซ้ำด้วยมือ — ไม่มีเครื่องมืออัตโนมัติตรวจให้ในเอกสารนี้)
- [ ] SEC-DB-01..05 — อย่างน้อย SEC-DB-02/03/05 ต้องผ่าน; SEC-DB-01 (3 DB users) ยังเป็น known gap ให้
      บันทึกความเสี่ยงไว้อย่างเป็นทางการถ้าจะปล่อยผ่านโดยไม่แก้
- [ ] `.env` ไม่ถูก commit เข้า git, `SESSION_SECURE_COOKIE=true`, `APP_DEBUG=false`, `APP_ENV=production`
- [ ] ผลสแกน OWASP ZAP (T-053) รันซ้ำกับ URL จริงของ production ก่อนเปิดใช้งาน (ผลที่มีอยู่ตอนนี้รันกับ
      `localhost` ใน Docker เท่านั้น) — ต้องไม่พบ Medium ขึ้นไปนอกเหนือจาก waiver ที่บันทึกไว้ใน
      `docker/zap/baseline.conf`
- [ ] Backup + restore drill (T-054) ทำซ้ำกับข้อมูลจริงบนเซิร์ฟเวอร์นี้อย่างน้อย 1 ครั้งก่อนเปิดใช้งาน
- [ ] มี Data Protection Officer / breach-response contact ตัวจริงแล้ว (แทนที่ placeholder ใน
      `pdpa_breach_response_plan.md`)
- [ ] นโยบายเก็บรักษาข้อมูลส่วนบุคคล (data retention) ได้รับการอนุมัติจากมหาวิทยาลัย/คณะแล้ว ก่อนเปิดใช้
      `php artisan users:pseudonymize` แบบตั้งเวลาอัตโนมัติ (ปัจจุบันยังเป็น manual-only โดยเจตนา)

# spec.md — Chemical & Material Inventory System (CMIS)

> **AGENT INSTRUCTION**
> เอกสารนี้คือ Single Source of Truth สำหรับการพัฒนาระบบ
> - อ่านหัวข้อ `0. AGENT OPERATING RULES` ก่อนเสมอ
> - ทำงานทีละ Task ตามลำดับใน `§13 BACKLOG` ห้ามข้ามลำดับ
> - ทุก Task ต้องผ่าน `§12 DEFINITION OF DONE` ก่อนถือว่าเสร็จ
> - หากพบความกำกวม ให้หยุดและถามผู้ใช้ ห้ามเดาแล้ว implement

| Key | Value |
|---|---|
| Project Code | CMIS |
| Version | 1.1.0 |
| Last Updated | 2026-08-31 |
| Language | PHP 8.3 |
| Framework | Laravel 11 |
| Database | MariaDB 11.4 (InnoDB, utf8mb4_unicode_ci) |
| Web Server | IIS 10 / Windows Server 2022 (FastCGI) |
| Frontend | Blade + Livewire 3 + Tailwind CSS 3 + Alpine.js |
| UI Language | Thai (th-TH) primary |
| Security Baseline | OWASP ASVS 4.0 Level 2 |
| Authentication | Delegated SSO — MEDSCI ACC (คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา) |

> **Revision Note (v1.1.0):** เปลี่ยนสถาปัตยกรรม Authentication จาก local username/password + Argon2id + TOTP 2FA (v1.0.0) เป็น **delegate ทั้งหมดให้ระบบ SSO กลางของคณะ (MEDSCI ACC)** ดูรายละเอียด protocol ที่ `sso_integration_guide.md` และ `sso_client_quickstart.md` ในโฟลเดอร์เดียวกัน กระทบ §5.2 (users), §7.2, §9.1, §13 (T-010/T-011), §11.3 — ดูหัวข้อที่เกี่ยวข้องทั้งหมด

---

## 0. AGENT OPERATING RULES

1. **ห้ามใช้ `FLOAT` / `DOUBLE`** สำหรับปริมาณใด ๆ ใช้ `DECIMAL(18,6)` เท่านั้น
2. **ห้ามคำนวณปริมาณด้วย native float ของ PHP** ใช้ `BCMath` (`bcadd/bcsub/bcmul/bcdiv/bccomp`, scale = 6) เสมอ
3. **ห้ามเขียน raw SQL ที่ต่อ string** ใช้ Query Builder / Eloquent / Prepared Statement เท่านั้น
4. **ห้ามใช้ `$guarded = []`** ทุก Model ต้องประกาศ `$fillable`
5. **ห้ามใช้ `{!! !!}` ใน Blade** ยกเว้นผ่าน HTML Purifier และมี comment อธิบายเหตุผล
6. **ห้าม UPDATE/DELETE ตาราง `stock_ledger` และ `audit_logs`** ในทุกกรณี
7. ทุก Controller action ต้องมี **Form Request** (validation) และ **Policy** (authorization)
8. ทุก Route ต้องอยู่หลัง middleware `auth` เว้นแต่ระบุใน `§7.2` ว่าเป็น public
9. Public identifier ใน URL ใช้ **ULID** ไม่ใช้ auto-increment ID
10. เขียน Test คู่กับ Feature เสมอ — ไม่มี Test = Task ยังไม่เสร็จ
11. ข้อความ UI ทั้งหมดผ่าน `lang/th/*.php` ห้าม hardcode ภาษาไทยใน Blade/Controller
12. Commit message ใช้ Conventional Commits: `feat(ledger): ...`, `fix(auth): ...`

---

## 1. OVERVIEW

### 1.1 Problem Statement
ห้องปฏิบัติการใช้แบบฟอร์มกระดาษ 2 ชุดในการควบคุมคลัง:
- **F-01 ใบขอเบิก** — บันทึกคำขอ, การอนุมัติของอาจารย์ที่ปรึกษา, การพิจารณาของนักวิทยาศาสตร์
- **F-03 บัญชีคุมวัสดุ/สารเคมี** — บันทึก รับ / จ่าย / คงเหลือ รายตัว พร้อมหน่วยนับหลักและหน่วยนับย่อย

ปัญหา: สืบค้นยาก, คำนวณยอดคงเหลือผิดพลาด, ไม่ทราบวันหมดอายุ, ไม่มีร่องรอยการตรวจสอบ, ตัดจ่ายระดับ g/mL ทำมือ

### 1.2 Goal
ระบบเว็บที่ทดแทนกระบวนการกระดาษทั้งหมด รองรับการตัดจ่ายละเอียดถึงระดับ **mg / µL** โดยยังพิมพ์เอกสารรูปแบบ F-01 และ F-03 ออกมาได้เหมือนเดิม

### 1.3 In Scope
- Authentication ผ่าน SSO กลางของคณะ (MEDSCI ACC) + Local RBAC/Role Provisioning
- Master Data สารเคมี/วัสดุ + SDS + GHS
- Receiving รายภาชนะ (per-container) + Lot + Expiry + Barcode
- ใบขอเบิกอิเล็กทรอนิกส์ + Workflow อนุมัติ 3 ระดับ
- Stock Ledger แบบ append-only + hash chain
- Return / Stock Take / Adjustment / Disposal
- Mobile web scan (QR/Barcode)
- Reports + Dashboard + Export PDF/Excel
- RBAC + Audit Trail + Notification

### 1.4 Out of Scope
- ระบบจัดซื้อ (e-GP), ระบบบัญชีการเงิน
- เชื่อมต่อเครื่องชั่งอัตโนมัติ
- ระบบจัดการของเสียเคมีเต็มรูปแบบ (v1.0 บันทึกได้เฉพาะการทิ้ง)
- Native mobile app
- Local username/password authentication ของตัวเอง (v1.1.0 ยกเลิก — delegate ให้ SSO กลางทั้งหมด ดู §9.1)

---

## 2. GLOSSARY

| Term | Definition |
|---|---|
| **Item** | รายการในทะเบียน (สารเคมีหรือวัสดุ) 1 record = 1 SKU |
| **Container** | ภาชนะรายใบ มี barcode เฉพาะตัว เช่น ขวด NaOH 500 g ใบที่ 3 |
| **Base Unit** | หน่วยเล็กสุดที่ระบบเก็บค่าจริง: `mg` (MASS), `uL` (VOLUME), `pcs` (COUNT) |
| **Dimension** | มิติของหน่วย: MASS / VOLUME / COUNT |
| **Ledger** | บัญชีคุม เขียนเพิ่มอย่างเดียว (append-only) |
| **FEFO** | First-Expired-First-Out — จ่ายของที่หมดอายุก่อน |
| **Row Hash** | SHA-256 ที่ผูกแถว ledger เข้ากับแถวก่อนหน้า เพื่อพิสูจน์ความสมบูรณ์ |
| **Requisition** | ใบขอเบิก (เทียบเท่า F-01) |
| **GRN** | Goods Receipt Note — ใบรับของ |

---

## 3. ACTORS & ROLES

| Role Code | ชื่อไทย | สิทธิ์หลัก |
|---|---|---|
| `STUDENT` | นิสิต | สร้าง/ส่งใบเบิก, ดูของตนเอง, ดู SDS |
| `ADVISOR` | อาจารย์ที่ปรึกษา | อนุมัติใบเบิกของนิสิตในความดูแล |
| `STAFF` | อาจารย์/เจ้าหน้าที่ | สร้างใบเบิกโดยไม่ต้องผ่านอาจารย์ที่ปรึกษา |
| `SCIENTIST` | นักวิทยาศาสตร์ | พิจารณาเห็นควร/ไม่เห็นควร, จ่ายของ, รับเข้า, ตรวจนับ |
| `LAB_MANAGER` | หัวหน้าห้องปฏิบัติการ | อนุมัติปรับปรุงยอด/ตัดจำหน่าย, จัดการ Master Data |
| `ADMIN` | ผู้ดูแลระบบ | จัดการผู้ใช้/สิทธิ์/ตั้งค่า (**ไม่มีสิทธิ์แตะ Ledger**) |
| `AUDITOR` | ผู้ตรวจสอบ | อ่านอย่างเดียวทั้งระบบ + เข้าถึง Audit Log |

**Separation of Duties (บังคับที่ระดับ Service):**
- ผู้ที่กดจ่ายของ (`SCIENTIST`) ต้องไม่ใช่ผู้อนุมัติปรับปรุงยอด (`LAB_MANAGER`) ใน transaction เดียวกัน
- `ADMIN` ไม่มี grant `UPDATE`/`DELETE` บน `stock_ledger` ที่ระดับฐานข้อมูล

**Role Provisioning (v1.1.0 — สำคัญเพราะ Authentication มาจาก SSO กลาง):**
> SSO กลาง (MEDSCI ACC) ยืนยันได้แค่ "ผู้ใช้นี้คือใคร" (identity) เท่านั้น **ไม่รู้จัก Role ของ CMIS เลย** (`STUDENT`/`ADVISOR`/`STAFF`/`SCIENTIST`/`LAB_MANAGER`/`ADMIN`/`AUDITOR`)
> - Login ผ่าน SSO ครั้งแรก → ระบบสร้าง local `users` record อัตโนมัติ แต่ **ไม่มี role ใด ๆ ผูกไว้** (deny-by-default ตาม SEC-AZ-01)
> - ผู้ใช้ที่ยังไม่มี role จะเห็นเฉพาะหน้า "รอผู้ดูแลระบบกำหนดสิทธิ์การใช้งาน"
> - เฉพาะ `ADMIN` เท่านั้นที่กำหนด role ให้ user คนอื่นได้ (ดู BR-11, FR-AU-04)

---

## 4. ARCHITECTURE

### 4.1 Runtime Topology
```
[Browser / Mobile Web]
        │ HTTPS (TLS 1.2/1.3)
        ▼
[IIS 10]  URL Rewrite + Request Filtering + Dynamic IP Restriction
        │ FastCGI
        ▼
[PHP 8.3 / Laravel 11]
        ├── Http Layer     : Routes, Controllers, Form Requests, Middleware
        ├── Domain Layer   : Services, Value Objects, Domain Exceptions
        ├── Data Layer     : Eloquent Models, Repositories
        └── Jobs / Queue   : Notifications, PDF/Excel generation, Nightly checks
        │
        ├──► MariaDB 11.4   (TLS connection, 3 DB users แยกสิทธิ์)
        ├──► Redis 7        (Session, Cache, Queue, Rate limit)
        └──► File Storage   D:\cmis_storage\  (นอก webroot)
```

### 4.2 Layer Rules (บังคับ)
- **Controller** ห้ามมี business logic — เรียก Service เท่านั้น
- **Service** ห้ามรู้จัก `Request` / `Response` — รับ DTO เข้า คืน DTO/Model ออก
- **Model** ห้ามมี business logic ที่ข้าม aggregate — เก็บได้แค่ relation, cast, scope
- การเขียน `stock_ledger` ทำได้จาก `LedgerService` **ที่เดียวเท่านั้น**

### 4.3 Directory Structure
```
cmis/
├── app/
│   ├── Domain/
│   │   ├── Inventory/
│   │   │   ├── Services/
│   │   │   │   ├── LedgerService.php
│   │   │   │   ├── ReceivingService.php
│   │   │   │   ├── IssueService.php
│   │   │   │   ├── ReturnService.php
│   │   │   │   ├── StockTakeService.php
│   │   │   │   └── LedgerHasher.php
│   │   │   ├── ValueObjects/  (Quantity.php, UnitCode.php)
│   │   │   ├── DTO/
│   │   │   └── Exceptions/
│   │   ├── Requisition/
│   │   │   ├── Services/ (RequisitionService.php, ApprovalService.php)
│   │   │   ├── StateMachine/RequisitionState.php
│   │   │   └── DTO/
│   │   ├── Auth/
│   │   │   ├── Services/
│   │   │   │   ├── SsoClient.php          (redirect URL builder + verify() ยิง API กลาง)
│   │   │   │   └── UserProvisioningService.php  (สร้าง/sync local user จาก SSO payload)
│   │   │   └── DTO/  (SsoUserData.php)
│   │   └── Shared/
│   │       ├── UnitConverter.php
│   │       └── DocumentNumberGenerator.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Auth/  (SsoLoginController.php, SsoCallbackController.php, SsoLogoutController.php)
│   │   ├── Requests/
│   │   ├── Middleware/  (SecurityHeaders, AuditLogger, ForceHttps, EnsureRoleAssigned)
│   │   └── Livewire/
│   ├── Models/
│   ├── Policies/
│   ├── Jobs/
│   ├── Notifications/
│   └── Providers/
├── database/
│   ├── migrations/
│   ├── seeders/  (UnitSeeder, RoleSeeder, DemoDataSeeder)
│   └── factories/
├── lang/th/
├── resources/
│   ├── views/
│   │   ├── layouts/  components/  requisitions/  ledger/  items/  reports/
│   │   └── pdf/  (f01_requisition.blade.php, f03_ledger.blade.php)
│   ├── css/  js/
├── routes/  (web.php, api.php, console.php)
├── storage/
├── tests/
│   ├── Unit/  Feature/  Security/  Concurrency/
├── public/
│   ├── index.php
│   └── web.config          ← Document Root ของ IIS ชี้ที่นี่
├── .env.example
└── spec.md
```

---

## 5. DATA MODEL

### 5.1 Critical Rules
1. ปริมาณทั้งหมดเก็บเป็น **base unit** ชนิด `DECIMAL(18,6)`
2. Dimension: `MASS` → base `mg`, `VOLUME` → base `uL`, `COUNT` → base `pcs`
3. แปลงข้ามมิติ (mL ↔ g) ต้องมี `density_g_per_ml` เท่านั้น — ถ้าไม่มี ให้ **throw exception** ห้ามเดา
4. ปัดเศษเฉพาะตอนแสดงผล ไม่ปัดตอนคำนวณหรือบันทึก
5. ทุกตารางใช้ `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
6. ทุกตารางหลักมีคอลัมน์ `ulid CHAR(26) UNIQUE` สำหรับใช้ใน URL

### 5.2 Schema

```sql
-- ============ UNITS & CATEGORIES ============
CREATE TABLE units (
  id             SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  code           VARCHAR(16)  NOT NULL UNIQUE,     -- mg, g, kg, uL, mL, L, pcs
  name_th        VARCHAR(64)  NOT NULL,
  dimension      ENUM('MASS','VOLUME','COUNT') NOT NULL,
  factor_to_base DECIMAL(24,12) NOT NULL,          -- g -> 1000 (base = mg)
  is_base        TINYINT(1) NOT NULL DEFAULT 0,
  sort_order     SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE item_categories (
  id       INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  code     VARCHAR(32) NOT NULL UNIQUE,   -- CHEMICAL, MATERIAL, CONSUMABLE, GLASSWARE
  name_th  VARCHAR(128) NOT NULL,
  is_chemical TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- ============ ORG ============
CREATE TABLE labs (
  id       INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  code     VARCHAR(32) NOT NULL UNIQUE,
  name_th  VARCHAR(255) NOT NULL,
  faculty  VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE locations (
  id            INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  parent_id     INT UNSIGNED NULL,
  lab_id        INT UNSIGNED NULL,
  code          VARCHAR(32) NOT NULL UNIQUE,
  name          VARCHAR(128) NOT NULL,
  level_type    ENUM('BUILDING','ROOM','CABINET','SHELF') NOT NULL,
  storage_class VARCHAR(64) NULL,   -- ACID/BASE/FLAMMABLE/OXIDIZER/TOXIC/GENERAL
  CONSTRAINT fk_loc_parent FOREIGN KEY (parent_id) REFERENCES locations(id)
) ENGINE=InnoDB;

-- ============ USERS & RBAC ============
-- v1.1.0: ไม่เก็บ credential เอง (password/2FA secret) — authentication delegate ให้ SSO กลาง (MEDSCI ACC)
-- ตารางนี้เก็บเฉพาะ local profile + CMIS-specific attribute ที่ SSO ไม่มี (program, faculty, student code, advisor, lab)
CREATE TABLE users (
  id                 BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  ulid               CHAR(26) NOT NULL UNIQUE,
  sso_subject        VARCHAR(64)  NOT NULL UNIQUE,    -- user_id/username ที่ได้จาก SSO verify API (immutable key)
  username           VARCHAR(64)  NOT NULL UNIQUE,    -- sync จาก SSO ทุกครั้งที่ login (สำหรับแสดงผล/ค้นหา)
  email              VARCHAR(255) NOT NULL UNIQUE,     -- sync จาก SSO
  full_name          VARCHAR(255) NOT NULL,            -- sync จาก SSO (name)
  pos_name           VARCHAR(255) NULL,                -- sync จาก SSO (ตำแหน่ง)
  div_name           VARCHAR(255) NULL,                -- sync จาก SSO (หน่วยงาน/สังกัด)
  phone_encrypted    VARBINARY(512) NULL,            -- AES-256-GCM, กรอกเพิ่มเองใน CMIS (SSO ไม่มี)
  person_code_encrypted VARBINARY(512) NULL,         -- รหัสนิสิต/พนักงาน, กรอกเพิ่มเองใน CMIS
  person_type        ENUM('LECTURER','STAFF','STUDENT') NOT NULL,
  program            VARCHAR(255) NULL,
  faculty            VARCHAR(255) NULL,
  lab_id             INT UNSIGNED NULL,
  advisor_id         BIGINT UNSIGNED NULL,
  profile_completed_at DATETIME NULL,                -- ครบ field ที่ SSO ไม่มีแล้วเมื่อไหร่ (BR-11)
  is_active          TINYINT(1) NOT NULL DEFAULT 1,   -- ปิดสิทธิ์ใช้ CMIS ได้เฉพาะที่นี่ แม้ SSO ยังใช้งานได้
  last_login_at      DATETIME NULL,
  last_sso_sync_at   DATETIME NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  INDEX idx_users_advisor (advisor_id)
) ENGINE=InnoDB;

CREATE TABLE roles (
  id   SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  code VARCHAR(32) NOT NULL UNIQUE,
  name_th VARCHAR(128) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE role_user (
  user_id BIGINT UNSIGNED NOT NULL,
  role_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL UNIQUE,     -- requisition.approve, ledger.adjust, ...
  name_th VARCHAR(128) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE permission_role (
  role_id SMALLINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id)
) ENGINE=InnoDB;

-- ============ ITEMS ============
CREATE TABLE items (
  id                 BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  ulid               CHAR(26) NOT NULL UNIQUE,
  item_code          VARCHAR(32)  NOT NULL UNIQUE,
  category_id        INT UNSIGNED NOT NULL,
  name_th            VARCHAR(255) NOT NULL,
  name_en            VARCHAR(255) NULL,
  cas_no             VARCHAR(20)  NULL,
  formula            VARCHAR(128) NULL,
  brand              VARCHAR(128) NULL,          -- F-03: ยี่ห้อ
  grade              VARCHAR(64)  NULL,          -- F-03: เกรด
  package_size       DECIMAL(18,6) NULL,         -- F-03: ขนาดบรรจุ
  package_unit_id    SMALLINT UNSIGNED NULL,     -- F-03: หน่วยนับ
  sub_unit_id        SMALLINT UNSIGNED NULL,     -- F-03: หน่วยนับย่อย
  base_unit_id       SMALLINT UNSIGNED NOT NULL,
  density_g_per_ml   DECIMAL(12,6) NULL,
  reorder_point_base DECIMAL(18,6) NOT NULL DEFAULT 0,
  is_controlled      TINYINT(1) NOT NULL DEFAULT 0,
  control_class      VARCHAR(64) NULL,
  ghs_codes          JSON NULL,
  h_statements       JSON NULL,
  p_statements       JSON NULL,
  storage_class      VARCHAR(64) NULL,
  shelf_life_days_after_open SMALLINT UNSIGNED NULL,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  INDEX idx_items_cas (cas_no),
  INDEX idx_items_cat (category_id, is_active),
  FULLTEXT KEY ft_items (name_th, name_en, brand)
) ENGINE=InnoDB;

CREATE TABLE attachments (
  id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  ulid          CHAR(26) NOT NULL UNIQUE,
  owner_type    VARCHAR(64) NOT NULL,      -- Item / Requisition / GoodsReceipt
  owner_id      BIGINT UNSIGNED NOT NULL,
  doc_type      ENUM('SDS','INVOICE','PHOTO','OTHER') NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(64) NOT NULL,      -- UUID + ext (สร้างใหม่เสมอ)
  mime_type     VARCHAR(128) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL,
  sha256        CHAR(64) NOT NULL,
  version       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  revised_date  DATE NULL,
  uploaded_by   BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_att_owner (owner_type, owner_id, doc_type)
) ENGINE=InnoDB;

-- ============ CONTAINERS ============
CREATE TABLE containers (
  id                 BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  ulid               CHAR(26) NOT NULL UNIQUE,
  barcode            VARCHAR(64) NOT NULL UNIQUE,
  item_id            BIGINT UNSIGNED NOT NULL,
  location_id        INT UNSIGNED NULL,
  lot_no             VARCHAR(64) NULL,
  received_at        DATE NOT NULL,
  expiry_date        DATE NULL,
  opened_at          DATE NULL,
  initial_qty_base   DECIMAL(18,6) NOT NULL,
  remaining_qty_base DECIMAL(18,6) NOT NULL,
  unit_price         DECIMAL(14,2) NULL,
  status             ENUM('SEALED','IN_USE','EMPTY','DISPOSED','QUARANTINE')
                     NOT NULL DEFAULT 'SEALED',
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  CONSTRAINT chk_cont_remaining CHECK (remaining_qty_base >= 0),
  FOREIGN KEY (item_id) REFERENCES items(id),
  INDEX idx_cont_item_status (item_id, status),
  INDEX idx_cont_expiry (expiry_date),
  INDEX idx_cont_location (location_id)
) ENGINE=InnoDB;

-- ============ RECEIVING ============
CREATE TABLE goods_receipts (
  id           BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  ulid         CHAR(26) NOT NULL UNIQUE,
  doc_no       VARCHAR(32) NOT NULL UNIQUE,   -- GRN-2569-00001
  receipt_date DATE NOT NULL,
  po_no        VARCHAR(64) NULL,
  invoice_no   VARCHAR(64) NULL,
  supplier     VARCHAR(255) NULL,
  lab_id       INT UNSIGNED NOT NULL,
  status       ENUM('DRAFT','CONFIRMED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  received_by  BIGINT UNSIGNED NOT NULL,
  confirmed_at DATETIME NULL,
  remark       VARCHAR(500) NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE goods_receipt_items (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  goods_receipt_id BIGINT UNSIGNED NOT NULL,
  line_no          SMALLINT UNSIGNED NOT NULL,
  item_id          BIGINT UNSIGNED NOT NULL,
  container_count  SMALLINT UNSIGNED NOT NULL,      -- จำนวนภาชนะที่รับ
  qty_per_container DECIMAL(18,6) NOT NULL,
  unit_id          SMALLINT UNSIGNED NOT NULL,
  qty_total_base   DECIMAL(18,6) NOT NULL,
  lot_no           VARCHAR(64) NULL,
  expiry_date      DATE NULL,
  location_id      INT UNSIGNED NULL,
  unit_price       DECIMAL(14,2) NULL,
  UNIQUE KEY uq_grn_line (goods_receipt_id, line_no),
  FOREIGN KEY (goods_receipt_id) REFERENCES goods_receipts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============ REQUISITION (F-01) ============
CREATE TABLE requisitions (
  id                  BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  ulid                CHAR(26) NOT NULL UNIQUE,
  doc_no              VARCHAR(32) NOT NULL UNIQUE,   -- REQ-2569-00001
  lab_id              INT UNSIGNED NOT NULL,
  doc_date            DATE NOT NULL,
  requester_id        BIGINT UNSIGNED NOT NULL,
  requester_status    ENUM('LECTURER','STAFF','STUDENT') NOT NULL,
  requester_phone     VARCHAR(32) NULL,
  student_code        VARCHAR(32) NULL,
  program             VARCHAR(255) NULL,
  faculty             VARCHAR(255) NULL,
  request_type        SET('CHEMICAL','CONSUMABLE') NOT NULL,
  purpose_type        ENUM('TEACHING','RESEARCH','OTHER') NOT NULL,
  purpose_detail      VARCHAR(500) NULL,
  advisor_id          BIGINT UNSIGNED NULL,
  advisor_signed_at   DATETIME NULL,
  advisor_signature_hash CHAR(64) NULL,
  scientist_id        BIGINT UNSIGNED NULL,
  scientist_decision  ENUM('APPROVE','REJECT') NULL,
  reject_reason       VARCHAR(500) NULL,
  scientist_signed_at DATETIME NULL,
  status              ENUM('DRAFT','SUBMITTED','ADVISOR_APPROVED','APPROVED',
                           'REJECTED','PARTIALLY_ISSUED','ISSUED','CANCELLED')
                      NOT NULL DEFAULT 'DRAFT',
  submitted_at        DATETIME NULL,
  completed_at        DATETIME NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
  INDEX idx_req_status (status),
  INDEX idx_req_requester (requester_id, status),
  INDEX idx_req_advisor (advisor_id, status)
) ENGINE=InnoDB;

CREATE TABLE requisition_items (
  id                 BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  requisition_id     BIGINT UNSIGNED NOT NULL,
  line_no            SMALLINT UNSIGNED NOT NULL,
  item_id            BIGINT UNSIGNED NOT NULL,
  qty_requested      DECIMAL(18,6) NOT NULL,
  unit_id            SMALLINT UNSIGNED NOT NULL,
  qty_requested_base DECIMAL(18,6) NOT NULL,
  qty_issued_base    DECIMAL(18,6) NOT NULL DEFAULT 0,
  qty_returned_base  DECIMAL(18,6) NOT NULL DEFAULT 0,
  reference_doc      VARCHAR(255) NULL,
  remark             VARCHAR(255) NULL,
  CONSTRAINT chk_req_qty CHECK (qty_requested > 0),
  UNIQUE KEY uq_req_line (requisition_id, line_no),
  FOREIGN KEY (requisition_id) REFERENCES requisitions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE requisition_approvals (
  id             BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  requisition_id BIGINT UNSIGNED NOT NULL,
  step           ENUM('ADVISOR','SCIENTIST') NOT NULL,
  actor_id       BIGINT UNSIGNED NOT NULL,
  decision       ENUM('APPROVE','REJECT') NOT NULL,
  reason         VARCHAR(500) NULL,
  acted_at       DATETIME NOT NULL,
  ip_address     VARBINARY(16) NULL,
  INDEX idx_appr_req (requisition_id, step)
) ENGINE=InnoDB;

-- ============ ISSUE ============
CREATE TABLE issue_transactions (
  id                  BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  ulid                CHAR(26) NOT NULL UNIQUE,
  requisition_item_id BIGINT UNSIGNED NOT NULL,
  container_id        BIGINT UNSIGNED NOT NULL,
  qty_issued_base     DECIMAL(18,6) NOT NULL,
  issued_at           DATETIME NOT NULL,
  issuer_id           BIGINT UNSIGNED NOT NULL,
  receiver_id         BIGINT UNSIGNED NOT NULL,
  signature_hash      CHAR(64) NULL,
  signature_image_path VARCHAR(255) NULL,
  remark              VARCHAR(500) NULL,
  CONSTRAINT chk_issue_qty CHECK (qty_issued_base > 0),
  INDEX idx_issue_reqitem (requisition_item_id)
) ENGINE=InnoDB;

-- ============ STOCK LEDGER (F-03) — APPEND ONLY ============
CREATE TABLE stock_ledger (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  item_id          BIGINT UNSIGNED NOT NULL,
  container_id     BIGINT UNSIGNED NULL,
  txn_date         DATE NOT NULL,
  txn_type         ENUM('OPENING','RECEIVE','ISSUE','RETURN','ADJUST_IN',
                        'ADJUST_OUT','DISPOSE','TRANSFER_IN','TRANSFER_OUT') NOT NULL,
  ref_type         VARCHAR(32) NULL,     -- REQUISITION / GRN / STOCKTAKE / DISPOSAL
  ref_id           BIGINT UNSIGNED NULL,
  ref_doc_no       VARCHAR(64) NULL,     -- F-03: เลขที่เอกสารอ้างอิง
  qty_in_base      DECIMAL(18,6) NOT NULL DEFAULT 0,   -- F-03: รับ
  qty_out_base     DECIMAL(18,6) NOT NULL DEFAULT 0,   -- F-03: จ่าย
  balance_base     DECIMAL(18,6) NOT NULL,             -- F-03: คงเหลือ
  display_unit_id  SMALLINT UNSIGNED NOT NULL,
  issuer_id        BIGINT UNSIGNED NULL,               -- F-03: ผู้จ่าย
  receiver_id      BIGINT UNSIGNED NULL,               -- F-03: ผู้รับ
  receiver_name    VARCHAR(255) NULL,
  signature_hash   CHAR(64) NULL,                      -- F-03: ลงชื่อผู้รับ
  remark           VARCHAR(500) NULL,                  -- F-03: หมายเหตุ
  created_by       BIGINT UNSIGNED NOT NULL,
  created_at       DATETIME(6) NOT NULL,
  prev_row_hash    CHAR(64) NULL,
  row_hash         CHAR(64) NOT NULL,
  CONSTRAINT chk_ledger_inout CHECK (
    (qty_in_base > 0 AND qty_out_base = 0) OR
    (qty_out_base > 0 AND qty_in_base = 0) OR
    (qty_in_base = 0 AND qty_out_base = 0 AND txn_type = 'OPENING')),
  CONSTRAINT chk_ledger_balance CHECK (balance_base >= 0),
  INDEX idx_ledger_item_date (item_id, txn_date, id),
  INDEX idx_ledger_ref (ref_type, ref_id),
  INDEX idx_ledger_container (container_id)
) ENGINE=InnoDB;

-- Trigger กัน UPDATE/DELETE (Defense in depth ชั้นที่ 2)
DELIMITER $
CREATE TRIGGER trg_ledger_no_update BEFORE UPDATE ON stock_ledger
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_ledger is append-only';
END$
CREATE TRIGGER trg_ledger_no_delete BEFORE DELETE ON stock_ledger
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'stock_ledger is append-only';
END$
DELIMITER ;

-- ============ MONTHLY SNAPSHOT (performance) ============
CREATE TABLE ledger_snapshots (
  id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  item_id       BIGINT UNSIGNED NOT NULL,
  period_ym     CHAR(7) NOT NULL,            -- 2569-08
  opening_base  DECIMAL(18,6) NOT NULL,
  total_in_base DECIMAL(18,6) NOT NULL,
  total_out_base DECIMAL(18,6) NOT NULL,
  closing_base  DECIMAL(18,6) NOT NULL,
  last_ledger_id BIGINT UNSIGNED NOT NULL,
  generated_at  DATETIME NOT NULL,
  UNIQUE KEY uq_snapshot (item_id, period_ym)
) ENGINE=InnoDB;

-- ============ STOCK TAKE ============
CREATE TABLE stock_takes (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  ulid        CHAR(26) NOT NULL UNIQUE,
  doc_no      VARCHAR(32) NOT NULL UNIQUE,
  lab_id      INT UNSIGNED NOT NULL,
  count_date  DATE NOT NULL,
  status      ENUM('OPEN','COUNTING','PENDING_APPROVAL','APPROVED','CANCELLED')
              NOT NULL DEFAULT 'OPEN',
  created_by  BIGINT UNSIGNED NOT NULL,
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE stock_take_lines (
  id             BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  stock_take_id  BIGINT UNSIGNED NOT NULL,
  container_id   BIGINT UNSIGNED NOT NULL,
  system_qty_base DECIMAL(18,6) NOT NULL,
  counted_qty_base DECIMAL(18,6) NULL,
  diff_base      DECIMAL(18,6) NULL,
  reason         VARCHAR(255) NULL,
  counted_by     BIGINT UNSIGNED NULL,
  counted_at     DATETIME NULL,
  UNIQUE KEY uq_take_container (stock_take_id, container_id)
) ENGINE=InnoDB;

-- ============ DISPOSAL ============
CREATE TABLE disposals (
  id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  ulid          CHAR(26) NOT NULL UNIQUE,
  doc_no        VARCHAR(32) NOT NULL UNIQUE,
  container_id  BIGINT UNSIGNED NOT NULL,
  qty_base      DECIMAL(18,6) NOT NULL,
  reason        ENUM('EXPIRED','CONTAMINATED','DAMAGED','WASTE','OTHER') NOT NULL,
  method        VARCHAR(255) NULL,
  disposal_date DATE NOT NULL,
  requested_by  BIGINT UNSIGNED NOT NULL,
  approved_by   BIGINT UNSIGNED NULL,
  approved_at   DATETIME NULL,
  status        ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
) ENGINE=InnoDB;

-- ============ AUDIT & SECURITY ============
CREATE TABLE audit_logs (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  occurred_at DATETIME(6) NOT NULL,
  user_id     BIGINT UNSIGNED NULL,
  username    VARCHAR(64) NULL,
  ip_address  VARBINARY(16) NULL,
  user_agent  VARCHAR(512) NULL,
  action      VARCHAR(64) NOT NULL,     -- LOGIN_SUCCESS, REQ_APPROVE, LEDGER_INSERT
  entity_type VARCHAR(64) NULL,
  entity_id   BIGINT UNSIGNED NULL,
  old_value   JSON NULL,
  new_value   JSON NULL,
  result      ENUM('SUCCESS','FAILURE') NOT NULL,
  message     VARCHAR(500) NULL,
  INDEX idx_audit_time (occurred_at),
  INDEX idx_audit_user (user_id, occurred_at),
  INDEX idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB;

-- v1.1.0: ตัดตาราง login_attempts ออก — ไม่มี credential ให้ brute-force ในฝั่ง CMIS อีกต่อไป
-- (การ lockout หลัง login ผิดหลายครั้งเป็นหน้าที่ของระบบ SSO กลาง)
-- เหตุการณ์ login/callback ทุกครั้ง (สำเร็จ/ล้มเหลว/state ไม่ตรง/token ใช้ซ้ำ) บันทึกลง audit_logs แทน
-- Rate limit ที่ endpoint /login และ /sso/callback ใช้ Laravel RateLimiter (cache-based) ตาม SEC-AU-04

CREATE TABLE notifications (
  id         BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  type       VARCHAR(64) NOT NULL,
  title      VARCHAR(255) NOT NULL,
  body       VARCHAR(1000) NULL,
  link_url   VARCHAR(500) NULL,
  read_at    DATETIME NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_notif_user (user_id, read_at)
) ENGINE=InnoDB;
```

### 5.3 Seed Data — `units`

| code | name_th | dimension | factor_to_base | is_base |
|---|---|---|---|---|
| mg | มิลลิกรัม | MASS | 1 | 1 |
| g | กรัม | MASS | 1000 | 0 |
| kg | กิโลกรัม | MASS | 1000000 | 0 |
| uL | ไมโครลิตร | VOLUME | 1 | 1 |
| mL | มิลลิลิตร | VOLUME | 1000 | 0 |
| L | ลิตร | VOLUME | 1000000 | 0 |
| pcs | ชิ้น | COUNT | 1 | 1 |
| box | กล่อง | COUNT | ตามกำหนด | 0 |

---

## 6. BUSINESS RULES

### BR-01 — Requisition State Machine
```
DRAFT ──submit──► SUBMITTED
SUBMITTED ──(requester=STUDENT)──► รอ ADVISOR
          └─(requester≠STUDENT)──► ข้ามไป รอ SCIENTIST
SUBMITTED ──advisor approve──► ADVISOR_APPROVED
SUBMITTED ──advisor reject───► REJECTED (สิ้นสุด)
ADVISOR_APPROVED | SUBMITTED(non-student) ──scientist APPROVE──► APPROVED
                                          ──scientist REJECT───► REJECTED
APPROVED ──issue บางส่วน──► PARTIALLY_ISSUED
APPROVED | PARTIALLY_ISSUED ──issue ครบ──► ISSUED (สิ้นสุด)
DRAFT | SUBMITTED ──cancel──► CANCELLED
```

### BR-02 — กฎอาจารย์ที่ปรึกษา (บังคับจาก F-01)
> หาก `requester_status = 'STUDENT'` ระบบ **ต้องปฏิเสธ** การเปลี่ยนสถานะเป็น `APPROVED`
> หาก `advisor_signed_at IS NULL`
> Implement ที่ `ApprovalService::scientistDecide()` และเขียน Feature Test บังคับ

### BR-03 — การเลือกภาชนะ (FEFO)
ลำดับความสำคัญในการแนะนำภาชนะที่จะจ่าย:
1. `status = 'IN_USE'` ที่มี `expiry_date` ใกล้ที่สุด
2. `status = 'SEALED'` ที่มี `expiry_date` ใกล้ที่สุด
3. หาก `expiry_date` เป็น NULL ทั้งคู่ → เรียงตาม `received_at` เก่าสุด
- ระบบ **แนะนำ** เท่านั้น ผู้จ่ายเลือกภาชนะอื่นได้ แต่ต้องระบุ `remark`
- ห้ามแนะนำภาชนะที่ `expiry_date < today` — ต้องแสดง warning สีแดง

### BR-04 — การจ่ายจริง ≠ การขอ
- `qty_requested` เก็บค่าที่ผู้ขอกรอก, `qty_issued_base` เก็บค่าที่ชั่ง/ตวงจริง
- อนุญาตให้ `qty_issued > qty_requested` ได้ไม่เกิน 10% โดยต้องระบุ `remark`
- เกิน 10% ต้องได้รับอนุมัติจาก `LAB_MANAGER`

### BR-05 — การคืนของ
- คืนได้เฉพาะรายการที่ `qty_issued_base - qty_returned_base > 0`
- คืนกลับเข้าภาชนะเดิม (`container_id` เดียวกับตอนจ่าย) เท่านั้น
- เขียน Ledger `RETURN` และเพิ่ม `containers.remaining_qty_base`

### BR-06 — การปรับปรุงยอด
- ห้ามแก้ไขแถว ledger เดิมทุกกรณี
- ต้องออกแถวใหม่ `ADJUST_IN` / `ADJUST_OUT` พร้อม `remark` (บังคับกรอก ≥ 10 ตัวอักษร)
- ต้องมี `approved_by` เป็น `LAB_MANAGER` ที่ **ไม่ใช่คนเดียวกับ** `created_by`

### BR-07 — Running Balance
- `balance_base` ของแถวใหม่ = `balance_base` ของแถวล่าสุดของ `item_id` เดียวกัน ± qty
- คำนวณภายใน `DB::transaction` พร้อม `lockForUpdate()` เสมอ
- ห้ามคำนวณ balance ใหม่จากการ SUM ย้อนหลังตอน query — ใช้ค่าที่ lock ไว้แล้ว

### BR-08 — Hash Chain
```
row_hash = SHA256(
    prev_row_hash ?? '' | item_id | txn_type | qty_in_base |
    qty_out_base | balance_base | created_at(ISO8601 µs) | created_by
)
```
- แถวแรกของระบบ: `prev_row_hash = NULL`
- Command `php artisan ledger:verify` ตรวจสอบทั้ง chain และรายงานแถวที่ผิด

### BR-09 — Document Number
รูปแบบ: `{PREFIX}-{พ.ศ.}-{running 5 หลัก}` เช่น `REQ-2569-00001`
- Running รีเซ็ตทุกปีงบประมาณ (1 ต.ค.)
- สร้างภายใน transaction พร้อม `SELECT ... FOR UPDATE` บนตาราง counter

### BR-10 — Incompatibility Warning
เตือน (ไม่บล็อก) เมื่อจัดเก็บ item ที่มี `storage_class` ขัดกันใน `location` เดียวกัน:

| A | B |
|---|---|
| ACID | BASE |
| FLAMMABLE | OXIDIZER |
| TOXIC | FOOD_GRADE |

### BR-11 — SSO First-Login Provisioning (v1.1.0)
1. เมื่อ `SsoClient::verify()` สำเร็จครั้งแรกสำหรับ `sso_subject` หนึ่ง ๆ → สร้าง `users` record ใหม่ทันที โดย **ไม่ผูก role ใด ๆ**
2. Sync `username`, `email`, `full_name`, `pos_name`, `div_name` จาก payload ทุกครั้งที่ login (ค่าจากส่วนกลางเป็น source of truth เสมอ ห้ามให้ CMIS แก้ไขฟิลด์เหล่านี้เอง)
3. ผู้ใช้ที่ `role_user` ว่าง → เห็นเฉพาะหน้า "รอผู้ดูแลระบบกำหนดสิทธิ์" ทุก route อื่นตอบ HTTP 403 (deny-by-default, บังคับที่ middleware `EnsureRoleAssigned`)
4. ฟิลด์ที่ SSO ไม่มีให้ (`phone_encrypted`, `person_code_encrypted`, `program`, `faculty`, `person_type`, และ `advisor_id` กรณี STUDENT) ต้องกรอกให้ครบก่อนสร้างใบเบิกได้ครั้งแรก (`profile_completed_at` ต้องไม่เป็น NULL) — implement เป็นหน้า "กรอกข้อมูลเพิ่มเติม" บังคับหลัง login ครั้งแรก
5. เฉพาะ `ADMIN` เท่านั้นที่กำหนด/แก้ไข role ผ่าน `role_user` ได้

---

## 7. FUNCTIONAL REQUIREMENTS

### 7.0 Authentication (SSO)
| ID | Requirement | Priority |
|---|---|---|
| FR-AU-01 | `GET /login` → redirect ไปยัง MEDSCI ACC พร้อม `client_id`, `redirect_uri`, `state` (random nonce เก็บใน session) | Must |
| FR-AU-02 | `GET /sso/callback` → ตรวจ `state` (timing-safe compare, one-time use) แล้วยิง server-to-server POST ไป verify API ด้วย `token` + `client_id` + `client_secret` | Must |
| FR-AU-03 | Verify สำเร็จ → สร้าง/sync local `users` record (`UserProvisioningService`, BR-11) แล้วสร้าง session ของ CMIS เอง, regenerate session ID | Must |
| FR-AU-04 | ผู้ใช้ที่ยังไม่มี role → บังคับไปหน้า "รอกำหนดสิทธิ์" ทุก route (BR-11 ข้อ 3) | Must |
| FR-AU-05 | หน้ากรอกข้อมูลเพิ่มเติมหลัง first-login (BR-11 ข้อ 4) ก่อนใช้งานฟีเจอร์อื่นได้ | Must |
| FR-AU-06 | `GET /logout` → ทำลาย local session แล้ว redirect ไปยัง Single Logout endpoint ของ MEDSCI ACC | Must |
| FR-AU-07 | หน้า Admin จัดการ role ของผู้ใช้ (มอบหมาย/ถอด role, ปิด `is_active` เฉพาะฝั่ง CMIS) | Must |

### 7.1 Master Data
| ID | Requirement | Priority |
|---|---|---|
| FR-MD-01 | CRUD ทะเบียน Item พร้อมฟิลด์ตาม F-03 (ยี่ห้อ, เกรด, ขนาด, หน่วยนับ, หน่วยนับย่อย) | Must |
| FR-MD-02 | แนบ SDS ได้หลายเวอร์ชัน แสดงเวอร์ชันล่าสุดเป็นค่าเริ่มต้น | Must |
| FR-MD-03 | บันทึก GHS pictogram + H/P statements แสดงเป็นไอคอนในหน้ารายละเอียด | Must |
| FR-MD-04 | ผังจัดเก็บลำดับชั้น 4 ระดับ พร้อม tree view | Must |
| FR-MD-05 | เตือน incompatibility ตาม BR-10 | Should |
| FR-MD-06 | จัดการหน่วยนับและ conversion factor (เฉพาะ ADMIN) | Must |
| FR-MD-07 | ค้นหา item ด้วย ชื่อ/CAS/รหัส แบบ full-text | Must |

### 7.2 Public Routes (ไม่ต้อง login)
- `GET /login` — redirect ไปยัง SSO กลาง (FR-AU-01)
- `GET /sso/callback` — รับผลลัพธ์จาก SSO กลาง (FR-AU-02, FR-AU-03) — rate limit ตาม SEC-AU-04
- `GET /logout` — ทำลาย session + redirect ไป Single Logout กลาง (FR-AU-06)
- `GET /approve/{signedToken}` — ลิงก์อนุมัติของอาจารย์ที่ปรึกษา (มีอายุ 72 ชม., ใช้ Laravel Signed URL)
- `GET /verify/{ulid}` — ตรวจสอบความถูกต้องของเอกสารจาก QR (แสดงเฉพาะเลขที่เอกสาร, วันที่, สถานะ — **ห้ามแสดงข้อมูลส่วนบุคคล**)

### 7.3 Receiving
| ID | Requirement |
|---|---|
| FR-RC-01 | สร้าง GRN อ้างอิง PO/Invoice/Supplier |
| FR-RC-02 | เมื่อ confirm ระบบสร้าง `containers` ตามจำนวน `container_count` พร้อม barcode ไม่ซ้ำ |
| FR-RC-03 | บันทึก Lot No. + Expiry ต่อบรรทัด (สืบทอดไปทุก container ในบรรทัดนั้น) |
| FR-RC-04 | พิมพ์ฉลาก barcode ขนาด 40×25 mm และ 50×30 mm (PDF, หลายดวงต่อหน้า) |
| FR-RC-05 | Confirm GRN → เขียน Ledger `RECEIVE` 1 แถวต่อ container |
| FR-RC-06 | GRN สถานะ `CONFIRMED` แก้ไขไม่ได้ ต้อง cancel แล้วสร้างใหม่ |

### 7.4 Requisition (F-01)
| ID | Requirement |
|---|---|
| FR-RQ-01 | ฟอร์มกรอก: ชื่อ-สกุล, เบอร์โทร, สถานภาพ, รหัสนิสิต, สาขาวิชา/หลักสูตร, คณะ (auto-fill จาก profile) |
| FR-RQ-02 | เลือกประเภท: สารเคมี / วัสดุสิ้นเปลือง (เลือกได้มากกว่า 1) |
| FR-RQ-03 | วัตถุประสงค์: การเรียนการสอน (ระบุรายวิชา) / งานวิจัย (ระบุโครงการ) / อื่น ๆ |
| FR-RQ-04 | ตารางรายการ: ลำดับ, รายการ (autocomplete), จำนวน, หน่วย, เอกสารอ้างอิง — เพิ่ม/ลบแถวได้ |
| FR-RQ-05 | แสดงยอดคงเหลือปัจจุบันข้างรายการที่เลือก แบบ real-time |
| FR-RQ-06 | บังคับ BR-02 (นิสิตต้องมีอาจารย์ที่ปรึกษาอนุมัติ) |
| FR-RQ-07 | อาจารย์อนุมัติผ่านระบบ หรือ Signed Link ทางอีเมล |
| FR-RQ-08 | นักวิทยาศาสตร์เลือก "เห็นควรให้เบิก" / "ไม่เห็นควรให้เบิก" — reject ต้องกรอกเหตุผล |
| FR-RQ-09 | หน้าจ่ายของ: สแกน barcode → เลือก container (แนะนำ FEFO) → กรอกปริมาณจริง |
| FR-RQ-10 | รองรับจ่ายหลาย container ต่อ 1 รายการ (เช่น ขอ 800 mL จ่ายจาก 2 ขวด) |
| FR-RQ-11 | ผู้รับลงลายมือชื่อบน canvas หรือยืนยันด้วย OTP ทางอีเมล |
| FR-RQ-12 | พิมพ์ PDF รูปแบบ F-01 พร้อม QR verify ที่มุมล่างขวา |

### 7.5 Ledger (F-03)
| ID | Requirement |
|---|---|
| FR-LG-01 | หน้าบัญชีคุมรายตัว แสดงหัวเอกสารตาม F-03 (ประเภท, รายการ, ยี่ห้อ, เกรด, ขนาด, หน่วยนับ, หน่วยนับย่อย) |
| FR-LG-02 | ตาราง: วัน/เดือน/ปี, ผู้จ่าย, ผู้รับ, รับ, จ่าย, คงเหลือ, ลายมือชื่อผู้รับ, หมายเหตุ |
| FR-LG-03 | Dropdown สลับหน่วยแสดงผล (g ↔ mg, L ↔ mL) โดยไม่กระทบข้อมูลที่เก็บ |
| FR-LG-04 | Filter: ช่วงวันที่, ประเภทรายการ, ผู้เบิก, container |
| FR-LG-05 | Export PDF รูปแบบ F-03 และ Excel |
| FR-LG-06 | ปุ่ม "ตรวจสอบความสมบูรณ์" เรียก hash chain verification (เฉพาะ AUDITOR/ADMIN) |
| FR-LG-07 | หน้ารายการปรับปรุงยอด พร้อม workflow อนุมัติตาม BR-06 |

### 7.6 Stock Take / Return / Disposal
| ID | Requirement |
|---|---|
| FR-ST-01 | คืนของตาม BR-05 |
| FR-ST-02 | สร้างรอบตรวจนับ → generate lines จาก containers ที่ active ใน lab |
| FR-ST-03 | หน้าสแกนนับบนมือถือ: สแกน barcode → กรอกยอดนับจริง → บันทึก |
| FR-ST-04 | ผลต่างต้องผ่าน `LAB_MANAGER` อนุมัติ ก่อนเขียน Ledger `ADJUST_IN`/`ADJUST_OUT` |
| FR-ST-05 | บันทึกการทำลาย/ทิ้ง พร้อมเหตุผล วิธีกำจัด และผู้อนุมัติ |

### 7.7 Notifications
| ID | Trigger | Channel | Schedule |
|---|---|---|---|
| FR-NT-01 | คงเหลือ < reorder_point | Email + In-app | Daily 07:00 |
| FR-NT-02 | ใกล้หมดอายุ 90/30/7 วัน | Email + In-app | Daily 07:00 |
| FR-NT-03 | มีใบเบิกรออนุมัติ | Email + In-app | Immediate |
| FR-NT-04 | ผลอนุมัติ/ปฏิเสธใบเบิก | Email + In-app | Immediate |
| FR-NT-05 | ภาชนะเปิดใช้เกิน `shelf_life_days_after_open` | In-app | Daily |
| FR-NT-06 | Hash chain ผิดปกติ | Email → ADMIN | Immediate |

### 7.8 Reports
| Report | Filter | Export |
|---|---|---|
| บัญชีคุมรายตัว (F-03) | item, ช่วงวันที่, หน่วยแสดงผล | PDF, Excel |
| ใบเบิก (F-01) | requisition | PDF |
| สรุปการใช้ | ผู้เบิก / โครงการ / รายวิชา / คณะ / ช่วงเวลา | Excel |
| สารใกล้หมดอายุ | ช่วงวัน | Excel |
| สารคงคลังต่ำกว่าจุดสั่งซื้อ | lab | Excel |
| Dead stock (ไม่เคลื่อนไหว > 12 เดือน) | lab | Excel |
| สารควบคุม | ช่วงเวลา | PDF, Excel |
| ผลตรวจนับ + ผลต่าง | รอบตรวจนับ | PDF, Excel |

### 7.9 Dashboard
- จำนวนใบเบิกรอดำเนินการ (แยกตาม role ของผู้ใช้)
- จำนวนรายการต่ำกว่าจุดสั่งซื้อ
- จำนวนภาชนะใกล้หมดอายุ ≤ 30 วัน
- Top 10 รายการที่ใช้มากที่สุด (3 เดือนล่าสุด)
- กราฟการเบิกรายเดือน 12 เดือนย้อนหลัง

---

## 8. NON-FUNCTIONAL REQUIREMENTS

| ID | Category | Criteria |
|---|---|---|
| NFR-01 | Performance | หน้าจอทั่วไป < 2s (P95) ที่ concurrent user 100 |
| NFR-02 | Performance | รายงาน ledger 100,000 แถว → PDF < 15s (ผ่าน Queue + แจ้งเมื่อเสร็จ) |
| NFR-03 | Availability | ≥ 99.5% ในเวลาราชการ (08:00–18:00) |
| NFR-04 | Accuracy | ค่าคงเหลือคลาดเคลื่อน = 0 ที่ทศนิยม 6 ตำแหน่ง |
| NFR-05 | Concurrency | เบิกพร้อมกัน 50 requests บน container เดียว → ยอดไม่ติดลบ, ไม่มี lost update |
| NFR-06 | Browser | Chrome/Edge/Firefox/Safari (latest − 2), responsive ≥ 360px |
| NFR-07 | Accessibility | WCAG 2.1 Level AA |
| NFR-08 | Localization | ไทยเป็นหลัก, รองรับ พ.ศ./ค.ศ., ฟอนต์ Sarabun ใน PDF |
| NFR-09 | Backup | Full daily + binlog ต่อเนื่อง, RPO ≤ 15 นาที, RTO ≤ 4 ชม. |
| NFR-10 | Retention | Ledger + Audit Log ≥ 10 ปี |
| NFR-11 | Test Coverage | Domain layer ≥ 80%, รวมทั้งระบบ ≥ 70% |
| NFR-12 | Static Analysis | PHPStan Level 8 ผ่านโดยไม่มี error |

---

## 9. SECURITY REQUIREMENTS

> Baseline: OWASP ASVS 4.0 L2, OWASP Top 10 (2021), CIS IIS 10 Benchmark, PDPA (พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562)

### 9.1 Authentication & Session (v1.1.0 — Delegated SSO)
> CMIS **ไม่เก็บรหัสผ่านหรือ 2FA secret ของผู้ใช้เอง** ทั้งหมด delegate ให้ระบบกลาง MEDSCI ACC ตาม protocol ใน `sso_integration_guide.md` — ข้อกำหนดด้านล่างครอบคลุมเฉพาะส่วนที่ CMIS ต้องทำเอง (redirect, callback verify, session หลัง login)

| ID | Requirement |
|---|---|
| SEC-AU-01 | ห้ามเก็บ/รับรหัสผ่านผู้ใช้ในฝั่ง CMIS โดยเด็ดขาด — authentication ทั้งหมดผ่าน SSO redirect (FR-AU-01..03) เท่านั้น |
| SEC-AU-02 | สุ่ม `state` ด้วย `random_bytes(16)` เก็บใน session ก่อน redirect ทุกครั้ง, ตรวจด้วย `hash_equals()` (timing-safe) ที่ callback, ลบทิ้งทันทีหลังใช้ (one-time use, ป้องกัน Login CSRF) |
| SEC-AU-03 | Verify `token` กับ SSO เฉพาะแบบ server-to-server (cURL ฝั่ง PHP) เท่านั้น — `client_secret` ต้องไม่ถูกส่งหรือ expose ให้ browser เห็นเด็ดขาด; token มีอายุ 120 วินาทีและใช้ได้ครั้งเดียว ห้าม cache/reuse |
| SEC-AU-04 | Rate limit `/login` และ `/sso/callback`: 20/นาที ต่อ IP (ป้องกัน abuse endpoint สาธารณะ — ไม่ใช่ credential lockout เพราะไม่มี credential ให้เดาแล้ว) |
| SEC-AU-05 | Cookie: `HttpOnly`, `Secure`, `SameSite=Lax`, ชื่อ cookie ไม่สื่อ technology |
| SEC-AU-06 | Idle timeout 30 นาที, absolute timeout 8 ชม., regenerate session ID ทันทีหลัง verify สำเร็จ และทุกครั้งที่สิทธิ์ (role) เปลี่ยน |
| SEC-AU-07 | Session เก็บใน Redis/DB ไม่เก็บเป็นไฟล์ใน webroot |
| SEC-AU-08 | เมื่อ verify ล้มเหลว (state ไม่ตรง/token invalid/expired/ใช้ซ้ำ) แสดงข้อความทั่วไปแก่ผู้ใช้เท่านั้น รายละเอียดจริง log ลง `audit_logs` ฝั่ง server เท่านั้น |
| SEC-AU-09 | Logout ต้อง invalidate local session ฝั่ง server จริง แล้ว redirect ไปยัง Single Logout (SLO) endpoint ของ MEDSCI ACC เสมอ (FR-AU-06) ไม่ใช่แค่ลบ cookie ฝั่ง CMIS |
| SEC-AU-10 | เก็บ `client_id`/`client_secret` ที่ได้จากการลงทะเบียนกับ MEDSCI ACC ใน env var เท่านั้น (ตาม SEC-CR-06) ห้าม hardcode |
| SEC-AU-11 | Redirect ออกจาก `/sso/callback` ทันทีหลัง verify เสร็จ (สำเร็จหรือล้มเหลว) ไม่ปล่อยให้ URL ที่มี `?token=...` ค้างใน browser history |
| SEC-AU-12 | **Open item — ต้องยืนยันกับผู้ดูแลระบบกลาง MEDSCI ACC ก่อนปิด task นี้:** สเปก v1.0.0 บังคับ 2FA (TOTP) สำหรับ SCIENTIST/LAB_MANAGER/ADMIN — ถ้าระบบกลางไม่มี step-up/2FA ของตัวเอง ต้องตัดสินใจว่า CMIS จะเพิ่ม TOTP ชั้นที่สองเฉพาะ role สิทธิ์สูงหลังผ่าน SSO มาแล้วหรือไม่ (ดู RISK ใหม่ใน §15) |

### 9.2 Authorization
| ID | Requirement |
|---|---|
| SEC-AZ-01 | Deny by default — ทุก route ผ่าน middleware `auth` + `can:` |
| SEC-AZ-02 | ตรวจสิทธิ์ระดับ object ด้วย Laravel Policy ทุก resource (กัน IDOR/BOLA) |
| SEC-AZ-03 | ใช้ ULID ใน URL ห้ามใช้ auto-increment ID |
| SEC-AZ-04 | Separation of Duties ตาม §3 บังคับที่ Service layer |
| SEC-AZ-05 | DB grant: `cmis_app` ไม่มี `UPDATE`/`DELETE` บน `stock_ledger`, `audit_logs` |
| SEC-AZ-06 | ตรวจสิทธิ์ก่อน stream ไฟล์แนบทุกครั้ง (ไม่ใช้ direct URL) |

### 9.3 Input Validation & Injection
| ID | Requirement |
|---|---|
| SEC-IN-01 | Prepared statement เท่านั้น — ห้าม string concatenation ใน query |
| SEC-IN-02 | ตั้ง `PDO::ATTR_EMULATE_PREPARES = false` |
| SEC-IN-03 | Escape output ด้วย Blade `{{ }}` เสมอ |
| SEC-IN-04 | เปิด CSRF token ทุก state-changing request + ตรวจ `Origin`/`Referer` |
| SEC-IN-05 | Validation ฝั่ง server เสมอผ่าน Form Request |
| SEC-IN-06 | ปริมาณ: `numeric`, `> 0`, ทศนิยม ≤ 6 ตำแหน่ง, `<= ยอดคงเหลือ` |
| SEC-IN-07 | ประกาศ `$fillable` ทุก Model (กัน mass assignment) |
| SEC-IN-08 | ห้ามให้ผู้ใช้ระบุ URL ปลายทางให้ server เรียก (กัน SSRF) |
| SEC-IN-09 | ชื่อไฟล์อัปโหลดสร้างใหม่เป็น UUID เสมอ (กัน path traversal) |
| SEC-IN-10 | Export CSV/Excel ต้อง prefix `'` หน้าค่าที่ขึ้นต้นด้วย `= + - @` (กัน CSV injection) |

### 9.4 File Upload
| ID | Requirement |
|---|---|
| SEC-FU-01 | อนุญาต `pdf, jpg, jpeg, png, xlsx, docx` ขนาด ≤ 10 MB |
| SEC-FU-02 | ตรวจ MIME จริงด้วย `finfo` — ห้ามเชื่อ extension หรือ `$_FILES['type']` |
| SEC-FU-03 | เก็บนอก webroot ที่ `D:\cmis_storage\` |
| SEC-FU-04 | สแกนไวรัสก่อนบันทึกถาวร (ClamAV / Windows Defender CLI) |
| SEC-FU-05 | ตอบกลับด้วย `Content-Disposition: attachment` + `X-Content-Type-Options: nosniff` |
| SEC-FU-06 | ปิด script handler ในโฟลเดอร์เก็บไฟล์ผ่าน `web.config` |
| SEC-FU-07 | บันทึก SHA-256 ของไฟล์ทุกไฟล์เพื่อตรวจความสมบูรณ์ |

### 9.5 Cryptography & Secrets
| ID | Requirement |
|---|---|
| SEC-CR-01 | บังคับ HTTPS ทั้งระบบ TLS 1.2/1.3, ปิด TLS 1.0/1.1 และ SSL 3.0 ที่ Schannel |
| SEC-CR-02 | Cipher เฉพาะ AEAD (AES-GCM, ChaCha20-Poly1305) + PFS |
| SEC-CR-03 | HSTS `max-age=31536000; includeSubDomains` |
| SEC-CR-04 | PHP ↔ MariaDB เชื่อมต่อผ่าน TLS (`MYSQL_ATTR_SSL_CA`) |
| SEC-CR-05 | เข้ารหัสข้อมูลอ่อนไหวในฐานข้อมูล (เบอร์โทร, รหัสนิสิต) ด้วย AES-256-GCM |
| SEC-CR-06 | Secrets อยู่ใน env var / Windows DPAPI — ห้าม commit `.env` |
| SEC-CR-07 | Key rotation อย่างน้อยปีละครั้ง |
| SEC-CR-08 | เปิด data-at-rest encryption (MariaDB encryption หรือ BitLocker) |

### 9.6 HTTP Security Headers (`public/web.config`)
```xml
<configuration>
  <system.webServer>
    <httpProtocol>
      <customHeaders>
        <remove name="X-Powered-By" />
        <add name="Strict-Transport-Security" value="max-age=31536000; includeSubDomains" />
        <add name="X-Content-Type-Options" value="nosniff" />
        <add name="X-Frame-Options" value="DENY" />
        <add name="Referrer-Policy" value="strict-origin-when-cross-origin" />
        <add name="Permissions-Policy" value="geolocation=(), camera=(self), microphone=(), payment=()" />
        <add name="Cross-Origin-Opener-Policy" value="same-origin" />
        <add name="Cross-Origin-Resource-Policy" value="same-origin" />
      </customHeaders>
    </httpProtocol>

    <security>
      <requestFiltering removeServerHeader="true">
        <requestLimits maxAllowedContentLength="10485760" />
        <verbs allowUnlisted="false">
          <add verb="GET" allowed="true" />
          <add verb="POST" allowed="true" />
          <add verb="PUT" allowed="true" />
          <add verb="PATCH" allowed="true" />
          <add verb="DELETE" allowed="true" />
        </verbs>
        <fileExtensions allowUnlisted="true">
          <add fileExtension=".env" allowed="false" />
          <add fileExtension=".log" allowed="false" />
          <add fileExtension=".lock" allowed="false" />
          <add fileExtension=".md" allowed="false" />
        </fileExtensions>
        <hiddenSegments>
          <add segment=".git" />
          <add segment="vendor" />
          <add segment="storage" />
        </hiddenSegments>
      </requestFiltering>
    </security>

    <rewrite>
      <rules>
        <rule name="Force HTTPS" stopProcessing="true">
          <match url="(.*)" />
          <conditions><add input="{HTTPS}" pattern="off" /></conditions>
          <action type="Redirect" url="https://{HTTP_HOST}/{R:1}" redirectType="Permanent" />
        </rule>
        <rule name="Laravel Front Controller" stopProcessing="true">
          <match url="^" ignoreCase="false" />
          <conditions logicalGrouping="MatchAll">
            <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
            <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
          </conditions>
          <action type="Rewrite" url="index.php" />
        </rule>
      </rules>
      <outboundRules>
        <rule name="Remove Server header">
          <match serverVariable="RESPONSE_Server" pattern=".+" />
          <action type="Rewrite" value="" />
        </rule>
      </outboundRules>
    </rewrite>

    <httpErrors errorMode="Custom" existingResponse="Replace">
      <remove statusCode="500" /><error statusCode="500" path="/error/500" responseMode="ExecuteURL" />
    </httpErrors>
    <directoryBrowse enabled="false" />
  </system.webServer>
</configuration>
```

> **CSP** ตั้งผ่าน Middleware `SecurityHeaders` เพราะต้องใช้ nonce สุ่มต่อ request:
> `default-src 'self'; script-src 'self' 'nonce-{RANDOM}'; style-src 'self' 'nonce-{RANDOM}'; img-src 'self' data:; font-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'`
> **ห้ามใส่ `unsafe-inline` หรือ `unsafe-eval` ใน `script-src` เด็ดขาด**

### 9.7 IIS Hardening
| ID | Requirement |
|---|---|
| SEC-IIS-01 | Application Pool ใช้ identity เฉพาะ สิทธิ์ต่ำสุด (ไม่ใช่ LocalSystem/NetworkService) |
| SEC-IIS-02 | Webroot: Read + Execute เท่านั้น; เขียนได้เฉพาะ `storage/`, `bootstrap/cache/` |
| SEC-IIS-03 | Document Root ชี้ที่ `public/` เท่านั้น |
| SEC-IIS-04 | เปิด Request Filtering ตาม §9.6 |
| SEC-IIS-05 | ปิด Directory Browsing และ Detailed Errors ใน production |
| SEC-IIS-06 | ลบ header `Server`, `X-Powered-By`, `X-AspNet-Version` |
| SEC-IIS-07 | เปิด Dynamic IP Restriction (deny > 100 req/10s ต่อ IP) |
| SEC-IIS-08 | ปิด verb `TRACE`, `TRACK`, `OPTIONS` |
| SEC-IIS-09 | เปิด IIS logging + ส่งเข้า SIEM / Windows Event Forwarding |
| SEC-IIS-10 | ติดตั้ง security patch ภายใน 30 วันหลังประกาศ |

### 9.8 PHP Hardening (`php.ini`)
```ini
expose_php = Off
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = D:\cmis_logs\php_error.log

allow_url_fopen = Off
allow_url_include = Off
enable_dl = Off
disable_functions = exec,passthru,shell_exec,system,proc_open,popen,parse_ini_file,show_source

open_basedir = "D:\cmis\;D:\cmis_storage\;C:\Windows\Temp\"

upload_max_filesize = 10M
post_max_size = 12M
max_execution_time = 60
memory_limit = 256M
max_input_vars = 2000

session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = "Lax"
session.use_strict_mode = 1
session.use_only_cookies = 1
```

### 9.9 Database Security
| ID | Requirement |
|---|---|
| SEC-DB-01 | สร้าง DB user 3 ตัว: `cmis_app` (CRUD), `cmis_ledger` (INSERT-only), `cmis_report` (SELECT-only) |
| SEC-DB-02 | `REVOKE UPDATE, DELETE ON cmis.stock_ledger FROM 'cmis_app'@'localhost';` เช่นเดียวกับ `audit_logs` |
| SEC-DB-03 | Bind `127.0.0.1` หรือจำกัดด้วย Windows Firewall — ไม่เปิด 3306 ออกภายนอก |
| SEC-DB-04 | เปิด MariaDB Audit Plugin บันทึก connection + DDL |
| SEC-DB-05 | `sql_mode = STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO` |
| SEC-DB-06 | ทดสอบ restore จาก backup ทุกไตรมาส และบันทึกผล |

### 9.10 Audit & Logging
| ID | Requirement |
|---|---|
| SEC-LG-01 | บันทึก: login success/failure, เปลี่ยนสิทธิ์, สร้าง/อนุมัติ/จ่ายใบเบิก, ปรับปรุงยอด, ดาวน์โหลดรายงาน, เข้าถึงไฟล์แนบ |
| SEC-LG-02 | โครงสร้างตาม `audit_logs` ใน §5.2 |
| SEC-LG-03 | Append-only — ไม่มี role ใดลบได้ |
| SEC-LG-04 | ห้าม log password, token, secret — ต้อง mask |
| SEC-LG-05 | Alert เมื่อ: login ล้มเหลวผิดปกติ, ปรับปรุงยอดจำนวนมาก, เข้าถึงนอกเวลาราชการ |
| SEC-LG-06 | Sync เวลา NTP |

### 9.11 PDPA
| ID | Requirement |
|---|---|
| SEC-PD-01 | เก็บข้อมูลส่วนบุคคลเท่าที่จำเป็น |
| SEC-PD-02 | แสดง Privacy Notice + บันทึกความยินยอมเมื่อลงทะเบียนครั้งแรก |
| SEC-PD-03 | รองรับสิทธิ์: ขอเข้าถึง / แก้ไข / ขอสำเนาข้อมูล |
| SEC-PD-04 | ข้อมูลใน Ledger เป็นหลักฐาน ลบไม่ได้ — ใช้ pseudonymization เมื่อพ้นระยะเก็บ |
| SEC-PD-05 | มีแผนตอบสนองเหตุข้อมูลรั่วไหล แจ้งภายใน 72 ชม. |

### 9.12 Supply Chain & SDLC
| ID | Requirement |
|---|---|
| SEC-SD-01 | Commit `composer.lock`, `package-lock.json` |
| SEC-SD-02 | รัน `composer audit` + `npm audit` ใน CI ทุกครั้ง |
| SEC-SD-03 | PHPStan Level 8 + Gitleaks (secret scanning) ใน pipeline |
| SEC-SD-04 | Code review ≥ 1 ผู้ตรวจ ก่อน merge เข้า `main` |
| SEC-SD-05 | Penetration test ก่อน Go-Live และทุก 12 เดือน |
| SEC-SD-06 | แยก env `dev` / `uat` / `prod`; ข้อมูลจริงใน dev ต้อง mask |

---

## 10. REFERENCE IMPLEMENTATIONS

### 10.1 `app/Domain/Shared/UnitConverter.php`
```php
<?php
declare(strict_types=1);

namespace App\Domain\Shared;

use App\Domain\Inventory\Exceptions\MissingDensityException;
use App\Models\Unit;

final class UnitConverter
{
    private const SCALE = 6;

    public function toBase(string $qty, Unit $from): string
    {
        return bcmul($qty, (string) $from->factor_to_base, self::SCALE);
    }

    public function fromBase(string $qtyBase, Unit $to): string
    {
        return bcdiv($qtyBase, (string) $to->factor_to_base, self::SCALE);
    }

    /**
     * แปลงข้ามมิติ MASS(mg) <-> VOLUME(uL)
     * หมายเหตุ: 1 g/mL = 1 mg/uL จึงใช้ density ได้ตรง ๆ ที่ระดับ base unit
     */
    public function crossDimension(
        string $qtyBase,
        string $fromDimension,
        string $toDimension,
        ?float $densityGPerMl
    ): string {
        if ($fromDimension === $toDimension) {
            return $qtyBase;
        }
        if ($densityGPerMl === null || $densityGPerMl <= 0) {
            throw new MissingDensityException(
                'ไม่สามารถแปลงหน่วยข้ามมิติได้ เนื่องจากไม่มีค่าความหนาแน่นของสารนี้'
            );
        }
        $d = (string) $densityGPerMl;

        return $fromDimension === 'MASS'
            ? bcdiv($qtyBase, $d, self::SCALE)   // mg -> uL
            : bcmul($qtyBase, $d, self::SCALE);  // uL -> mg
    }
}
```

### 10.2 `app/Domain/Inventory/Services/LedgerService.php` (แกนหลัก)
```php
<?php
declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\DTO\LedgerEntryData;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Models\Container;
use App\Models\StockLedger;
use Illuminate\Support\Facades\DB;

final class LedgerService
{
    private const SCALE = 6;

    public function __construct(private readonly LedgerHasher $hasher) {}

    /** จ่ายออกจาก container พร้อมเขียน ledger — เป็นทางเดียวที่อนุญาตให้ลดสต็อก */
    public function issue(int $containerId, string $qtyBase, LedgerEntryData $ctx): StockLedger
    {
        return DB::transaction(function () use ($containerId, $qtyBase, $ctx) {
            /** @var Container $container */
            $container = Container::whereKey($containerId)->lockForUpdate()->firstOrFail();

            if (bccomp($container->remaining_qty_base, $qtyBase, self::SCALE) < 0) {
                throw new InsufficientStockException(
                    "ปริมาณคงเหลือไม่เพียงพอ (คงเหลือ {$container->remaining_qty_base})"
                );
            }

            $last = StockLedger::where('item_id', $container->item_id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $balance = bcsub($last?->balance_base ?? '0', $qtyBase, self::SCALE);

            $row = new StockLedger([
                'item_id'         => $container->item_id,
                'container_id'    => $container->id,
                'txn_date'        => now()->toDateString(),
                'txn_type'        => 'ISSUE',
                'ref_type'        => $ctx->refType,
                'ref_id'          => $ctx->refId,
                'ref_doc_no'      => $ctx->refDocNo,
                'qty_in_base'     => '0',
                'qty_out_base'    => $qtyBase,
                'balance_base'    => $balance,
                'display_unit_id' => $ctx->displayUnitId,
                'issuer_id'       => $ctx->issuerId,
                'receiver_id'     => $ctx->receiverId,
                'receiver_name'   => $ctx->receiverName,
                'signature_hash'  => $ctx->signatureHash,
                'remark'          => $ctx->remark,
                'created_by'      => $ctx->createdBy,
                'created_at'      => now(),
                'prev_row_hash'   => $last?->row_hash,
            ]);
            $row->row_hash = $this->hasher->compute($row);
            $row->save();

            $container->remaining_qty_base = bcsub(
                $container->remaining_qty_base, $qtyBase, self::SCALE
            );
            $container->status = bccomp($container->remaining_qty_base, '0', self::SCALE) === 0
                ? 'EMPTY'
                : 'IN_USE';
            if ($container->opened_at === null) {
                $container->opened_at = now()->toDateString();
            }
            $container->save();

            return $row;
        }, 3); // retry 3 ครั้งเมื่อ deadlock
    }
}
```

### 10.3 `app/Domain/Inventory/Services/LedgerHasher.php`
```php
<?php
declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Models\StockLedger;

final class LedgerHasher
{
    public function compute(StockLedger $row): string
    {
        $payload = implode('|', [
            $row->prev_row_hash ?? '',
            $row->item_id,
            $row->txn_type,
            $row->qty_in_base,
            $row->qty_out_base,
            $row->balance_base,
            $row->created_at->format('Y-m-d\TH:i:s.u'),
            $row->created_by,
        ]);

        return hash('sha256', $payload);
    }

    /** @return array{ok: bool, broken_at: ?int} */
    public function verifyChain(int $itemId): array
    {
        $prev = null;
        $broken = null;

        StockLedger::where('item_id', $itemId)
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$prev, &$broken) {
                foreach ($rows as $row) {
                    if ($row->prev_row_hash !== $prev || $row->row_hash !== $this->compute($row)) {
                        $broken = $row->id;
                        return false;
                    }
                    $prev = $row->row_hash;
                }
                return true;
            });

        return ['ok' => $broken === null, 'broken_at' => $broken];
    }
}
```

### 10.4 `app/Domain/Auth/Services/SsoClient.php` (v1.1.0)
```php
<?php
declare(strict_types=1);

namespace App\Domain\Auth\Services;

use App\Domain\Auth\DTO\SsoUserData;
use App\Domain\Auth\Exceptions\SsoVerificationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class SsoClient
{
    private const STATE_SESSION_KEY = 'sso_state';
    private const TOKEN_TTL_SECONDS = 120;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $loginUrl,     // https://www.medsci.up.ac.th/msc_acc/sso/login.php
        private readonly string $verifyUrl,    // https://www.medsci.up.ac.th/msc_acc/api/verify.php
        private readonly string $logoutUrl,    // https://www.medsci.up.ac.th/msc_acc/sso/logout.php
        private readonly string $callbackUrl,
    ) {}

    /** สร้าง URL ไป redirect + เก็บ state ลง session (เรียกจาก SsoLoginController) */
    public function buildLoginUrl(): string
    {
        $state = Str::random(32);
        session([self::STATE_SESSION_KEY => $state]);

        return $this->loginUrl . '?' . http_build_query([
            'client_id'    => $this->clientId,
            'redirect_uri' => $this->callbackUrl,
            'state'        => $state,
        ]);
    }

    /**
     * ตรวจ state + verify token กับ API กลาง — throw ทันทีถ้าไม่ผ่านข้อใดข้อหนึ่ง
     * ห้ามเรียก verify API ถ้า state ไม่ตรง (ป้องกัน Login CSRF ตาม SEC-AU-02)
     */
    public function handleCallback(string $token, string $state): SsoUserData
    {
        $savedState = session(self::STATE_SESSION_KEY);
        session()->forget(self::STATE_SESSION_KEY); // one-time use ไม่ว่าผลจะเป็นอย่างไร

        if ($token === '' || $savedState === null || ! hash_equals((string) $savedState, $state)) {
            throw new SsoVerificationException('Invalid SSO state (possible Login CSRF)');
        }

        // server-to-server เท่านั้น — client_secret ต้องไม่ไปถึง browser
        $response = Http::asForm()
            ->timeout(self::TOKEN_TTL_SECONDS)
            ->post($this->verifyUrl, [
                'token'         => $token,
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

        $result = $response->json();

        if (! $response->ok() || ($result['status'] ?? null) !== 'success') {
            throw new SsoVerificationException($result['message'] ?? 'SSO token verification failed');
        }

        return SsoUserData::fromArray($result['user']);
    }

    public function logoutUrl(string $returnUrl): string
    {
        return $this->logoutUrl . '?' . http_build_query(['redirect_uri' => $returnUrl]);
    }
}
```
> หมายเหตุ: ตัวอย่างใน `sso_client_quickstart.md` ตั้ง `CURLOPT_SSL_VERIFYPEER = false` — **ห้ามทำแบบนั้นใน production** ต้องตรวจ TLS certificate จริงเสมอ (Laravel `Http::` client ตรวจให้อัตโนมัติ)

### 10.5 `app/Http/Middleware/SecurityHeaders.php`
```php
<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $nonce = Str::random(24);
        $request->attributes->set('csp_nonce', $nonce);

        $response = $next($request);

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'nonce-{$nonce}'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
```

---

## 11. TEST SPECIFICATION

### 11.1 Unit Tests
| ID | Test | Expected |
|---|---|---|
| UT-01 | `UnitConverter::toBase('12.5', g)` | `12500.000000` |
| UT-02 | `UnitConverter::fromBase('487500', g)` | `487.500000` |
| UT-03 | `crossDimension` โดยไม่มี density | throw `MissingDensityException` |
| UT-04 | `crossDimension('1000', VOLUME→MASS, density=0.789)` | `789.000000` |
| UT-05 | `LedgerHasher::compute` แถวเดียวกัน 2 ครั้ง | ค่าเท่ากัน |
| UT-06 | `DocumentNumberGenerator` ปีงบใหม่ | running รีเซ็ตเป็น 00001 |

### 11.2 Feature Tests
| ID | Scenario | Expected |
|---|---|---|
| FT-01 | นิสิตส่งใบเบิก → นักวิทย์กด approve โดยไม่มีอาจารย์อนุมัติ | HTTP 422 + error BR-02 |
| FT-02 | นิสิตส่ง → อาจารย์อนุมัติ → นักวิทย์อนุมัติ → จ่าย 12.5 g จากขวด 500 g | คงเหลือ 487.5 g, ledger 1 แถว, status `ISSUED` |
| FT-03 | จ่ายเกินยอดคงเหลือ | throw `InsufficientStockException`, ไม่มีแถว ledger ใหม่ |
| FT-04 | จ่ายบางส่วน (ขอ 100 mL จ่าย 60 mL) | status `PARTIALLY_ISSUED` |
| FT-05 | คืน 20 mL จากที่จ่าย 50 mL | ledger `RETURN`, container +20 mL |
| FT-06 | นักวิทย์ reject โดยไม่กรอกเหตุผล | HTTP 422 |
| FT-07 | ตรวจนับพบผลต่าง → LAB_MANAGER อนุมัติ | เกิด ledger `ADJUST_OUT` |
| FT-08 | ปรับปรุงยอดโดยผู้อนุมัติ = ผู้สร้าง | HTTP 403 (BR-06) |
| FT-09 | FEFO แนะนำภาชนะ | เลือกภาชนะที่หมดอายุใกล้ที่สุดที่ `IN_USE` |
| FT-10 | Export PDF F-03 | HTTP 200, `content-type: application/pdf`, มีข้อความไทยถูกต้อง |

### 11.3 Security Tests (บังคับผ่านทุกข้อ)
| ID | Attack | Expected |
|---|---|---|
| ST-01 | `?id=1' OR '1'='1` ใน query string | ไม่มี SQL error, ไม่มีข้อมูลรั่ว |
| ST-02 | ส่ง `<script>alert(1)</script>` ในช่องหมายเหตุ | แสดงเป็น text ธรรมดา ไม่ execute |
| ST-03 | POST โดยไม่มี CSRF token | HTTP 419 |
| ST-04 | ผู้ใช้ A เปิด URL ใบเบิกของผู้ใช้ B | HTTP 403 |
| ST-05 | อัปโหลด `shell.php` เปลี่ยนชื่อเป็น `shell.pdf` | ปฏิเสธจากการตรวจ MIME |
| ST-06 | เข้าถึง `/storage/app/sds/xxx.pdf` โดยตรง | HTTP 404/403 |
| ST-07 | เข้าถึง `/.env`, `/composer.json`, `/vendor/` | HTTP 404 |
| ST-08 | UPDATE แถว `stock_ledger` ผ่าน DB user ของแอป | ปฏิเสธ (grant + trigger) |
| ST-09 | ส่ง `token` เดิมไปที่ `/sso/callback` ซ้ำเป็นครั้งที่ 2 (replay) | ปฏิเสธ (single-use ตาม SEC-AU-03), log เข้า `audit_logs` |
| ST-09b | เรียก `/sso/callback?token=xxx&state=yyy` โดย `state` ไม่ตรงกับที่เก็บใน session (หรือไม่มี session) | ปฏิเสธทันที ไม่ยิงไป verify API (SEC-AU-02) |
| ST-10 | เรียก endpoint ที่ต้องใช้สิทธิ์ ADMIN ด้วย role STUDENT | HTTP 403 |
| ST-10b | User login ผ่าน SSO สำเร็จแต่ยังไม่มี role ผูกไว้ (BR-11) เรียก route ใด ๆ นอกจากหน้า "รอกำหนดสิทธิ์" | HTTP 403 |
| ST-11 | ตรวจ response header | มี HSTS, CSP, nosniff, X-Frame-Options; ไม่มี Server/X-Powered-By |
| ST-12 | Export Excel ที่มีค่า `=cmd\|'/c calc'!A1` | ค่าถูก escape ด้วย `'` |

### 11.4 Concurrency & Precision Tests
| ID | Test | Expected |
|---|---|---|
| CT-01 | 50 requests เบิก 10 mL พร้อมกันจากขวด 400 mL | สำเร็จ 40, ล้มเหลว 10, คงเหลือ = 0 พอดี ไม่ติดลบ |
| CT-02 | เบิก 0.001 g จำนวน 1,000 ครั้งจากขวด 1 g | คงเหลือ = 0.000000 พอดี |
| CT-03 | รัน `ledger:verify` หลัง CT-01 | chain สมบูรณ์ ไม่มีแถวผิด |

---

## 12. DEFINITION OF DONE

Task จะถือว่าเสร็จเมื่อครบทุกข้อ:
- [ ] โค้ดทำงานตรงตาม Requirement ID ที่ระบุใน Task
- [ ] มี Migration + Seeder (ถ้าเกี่ยวข้อง) และรัน `migrate:fresh --seed` ผ่าน
- [ ] มี Form Request สำหรับทุก input และ Policy สำหรับทุก resource
- [ ] มี Unit Test + Feature Test ครอบคลุม happy path และ error path
- [ ] `php artisan test` ผ่านทั้งหมด
- [ ] `./vendor/bin/phpstan analyse --level=8` ไม่มี error
- [ ] `./vendor/bin/pint --test` ผ่าน (PSR-12)
- [ ] `composer audit` ไม่พบช่องโหว่ระดับ High ขึ้นไป
- [ ] ไม่มี hardcoded string ภาษาไทยนอก `lang/th/`
- [ ] ไม่มี secret ในโค้ด (ตรวจด้วย Gitleaks)
- [ ] อัปเดต `CHANGELOG.md`

---

## 13. BACKLOG (ทำตามลำดับนี้)

### Phase 0 — Foundation
| Task ID | Description | Deliverable |
|---|---|---|
| T-000 | Init Laravel 11 + Pint + PHPStan L8 + Pest | โปรเจกต์รันได้ |
| T-001 | ตั้งค่า `.env.example`, MariaDB connection (TLS), Redis | เชื่อมต่อได้ |
| T-002 | Middleware `SecurityHeaders`, `ForceHttps`, `AuditLogger` | ตาม §10.4 |
| T-003 | `public/web.config` ตาม §9.6 | ไฟล์พร้อมใช้ |
| T-004 | Migration ทุกตารางตาม §5.2 + trigger ledger | `migrate:fresh` ผ่าน |
| T-005 | `UnitSeeder`, `RoleSeeder`, `PermissionSeeder`, `ItemCategorySeeder` | seed ผ่าน |
| T-006 | `UnitConverter` + Unit Test UT-01..UT-04 | test ผ่าน |
| T-007 | `DocumentNumberGenerator` + UT-06 | test ผ่าน |

### Phase 1 — Auth & Master Data
| Task ID | Description |
|---|---|
| T-010 | SSO Integration: `SsoClient`, `/login` redirect, `/sso/callback` verify + session, `/logout` SLO (FR-AU-01..03, 06, SEC-AU-01..11) |
| T-011 | `UserProvisioningService` (first-login create/sync), middleware `EnsureRoleAssigned`, หน้า "รอกำหนดสิทธิ์" + "กรอกข้อมูลเพิ่มเติม" (BR-11, FR-AU-04, 05) |
| T-012 | RBAC: Role/Permission + Gate + Policy base class + หน้า Admin จัดการ role (FR-AU-07) |
| T-013 | CRUD Item (FR-MD-01, 07) + Livewire table + search |
| T-014 | Upload SDS + attachment security (SEC-FU-01..07) |
| T-015 | GHS + H/P statements UI (FR-MD-03) |
| T-016 | Location tree + incompatibility warning (FR-MD-04, 05, BR-10) |
| T-017 | Security Test ST-01..ST-07, ST-09, ST-09b, ST-10, ST-10b, ST-11 |

### Phase 2 — Receiving & Ledger
| Task ID | Description |
|---|---|
| T-020 | `LedgerHasher` + `ledger:verify` command (BR-08) |
| T-021 | `LedgerService::receive()` + `issue()` + `return()` + `adjust()` |
| T-022 | GRN CRUD + confirm → สร้าง containers + ledger (FR-RC-01..06) |
| T-023 | Barcode/QR generator + label PDF (FR-RC-04) |
| T-024 | หน้าบัญชีคุม F-03 + unit switcher + filter (FR-LG-01..04) |
| T-025 | PDF F-03 (mPDF + Sarabun) + Excel export (FR-LG-05) |
| T-026 | Concurrency Test CT-01..CT-03 |
| T-027 | DB grant script + Security Test ST-08 |

### Phase 3 — Requisition Workflow
| Task ID | Description |
|---|---|
| T-030 | `RequisitionState` state machine ตาม BR-01 |
| T-031 | ฟอร์มใบเบิก + dynamic line items (FR-RQ-01..05) |
| T-032 | `ApprovalService` + BR-02 enforcement (FR-RQ-06) |
| T-033 | Signed URL อนุมัติทางอีเมล 72 ชม. (FR-RQ-07) |
| T-034 | หน้าพิจารณาของนักวิทยาศาสตร์ (FR-RQ-08) |
| T-035 | หน้าจ่ายของ + FEFO + multi-container (FR-RQ-09, 10, BR-03, BR-04) |
| T-036 | E-signature canvas + OTP fallback (FR-RQ-11) |
| T-037 | PDF F-01 + QR verify + public verify page (FR-RQ-12, §7.2) |
| T-038 | Feature Test FT-01..FT-10 |

### Phase 4 — Operations
| Task ID | Description |
|---|---|
| T-040 | Return flow (FR-ST-01, BR-05) |
| T-041 | Stock take + mobile scan (FR-ST-02..04) |
| T-042 | Disposal (FR-ST-05) |
| T-043 | Adjustment workflow + BR-06 (FR-LG-07) |
| T-044 | Notification jobs + scheduler (FR-NT-01..06) |
| T-045 | Reports ทั้งหมด (FR-8) + CSV injection guard (SEC-IN-10) |
| T-046 | Dashboard (FR-9) |
| T-047 | `ledger_snapshots` monthly job (performance) |

### Phase 5 — Hardening & Release
| Task ID | Description |
|---|---|
| T-050 | PDPA: consent, privacy notice, data export (SEC-PD-01..05) |
| T-051 | Accessibility audit WCAG 2.1 AA (NFR-07) |
| T-052 | Load test 100 concurrent users (NFR-01, 02) |
| T-053 | OWASP ZAP baseline scan — ต้องไม่พบ Medium ขึ้นไป |
| T-054 | Backup + restore drill (NFR-09) |
| T-055 | เอกสารติดตั้ง IIS + คู่มือผู้ใช้ + คู่มือผู้ดูแลระบบ |
| T-056 | UAT + Penetration Test |

---

## 14. ACCEPTANCE CRITERIA (Go / No-Go)

ระบบผ่านการยอมรับเมื่อ:
1. นิสิตส่งใบเบิกโดยไม่มีอาจารย์ที่ปรึกษาอนุมัติ → นักวิทยาศาสตร์กดจ่ายไม่ได้
2. เบิก 12.5 g จากขวด 500 g → ยอดคงเหลือ 487.5 g และ ledger บันทึกถูกต้อง
3. เบิก 0.001 g × 1,000 ครั้ง จากขวด 1 g → คงเหลือ 0.000000 พอดี
4. 50 requests พร้อมกัน → ยอดไม่ติดลบ, ไม่มี lost update
5. พยายาม UPDATE `stock_ledger` โดยตรง → ฐานข้อมูลปฏิเสธ
6. `php artisan ledger:verify` รายงาน chain สมบูรณ์ 100%
7. ผู้ใช้ A เปิด URL ใบเบิกของ B → HTTP 403
8. อัปโหลด `.php` เปลี่ยนนามสกุลเป็น `.pdf` → ปฏิเสธ
9. PDF F-01 และ F-03 ตรงตามแบบฟอร์มเดิม ภาษาไทยแสดงถูกต้อง
10. OWASP ZAP Baseline Scan ไม่พบช่องโหว่ระดับ Medium ขึ้นไป
11. Test coverage: Domain ≥ 80%, รวม ≥ 70%
12. PHPStan Level 8 ไม่มี error

---

## 15. RISKS

| Risk | Impact | Mitigation |
|---|---|---|
| ข้อมูลตั้งต้นไม่ครบ/ไม่ถูกต้อง | สูง | Data cleansing + ตรวจนับจริงก่อนเปิดใช้ → ลง ledger `OPENING` |
| ผู้ใช้ยังคุ้นเคยกับกระดาษ | กลาง | ใช้คู่ขนาน 1 เดือน + อบรม + พิมพ์ฟอร์มได้เหมือนเดิม |
| Encoding ภาษาไทยบน IIS/mPDF | กลาง | ทดสอบ utf8mb4 + ฟอนต์ Sarabun ตั้งแต่ T-004 |
| Ledger โตเร็ว ประสิทธิภาพตก | กลาง | `ledger_snapshots` รายเดือน + partition ตามปี (T-047) |
| Redis ไม่ได้รับอนุญาตบน Windows Server | ต่ำ | Fallback เป็น database driver สำหรับ session/queue |
| BCMath extension ไม่ได้ติดตั้ง | สูง | ตรวจใน T-000 และระบุใน requirement การติดตั้ง |
| ระบบ SSO กลาง (MEDSCI ACC) ล่มหรือช้า → ไม่มีใคร login เข้า CMIS ได้เลย (v1.1.0, single point of failure) | สูง | สอบถาม SLA ของระบบกลางกับผู้ดูแล; พิจารณา emergency local admin account แยกต่างหาก (นอก flow ปกติ ใช้เฉพาะกรณีฉุกเฉิน มี audit เข้มงวด) |
| ระบบกลาง MEDSCI ACC ไม่มี 2FA บังคับสำหรับผู้ใช้สิทธิ์สูง (SEC-AU-12) | กลาง | ต้องยืนยันกับผู้ดูแลระบบกลางก่อนปิด T-010/T-011 — ถ้าไม่มีให้ประเมินเพิ่ม TOTP ชั้นที่สองเฉพาะ role SCIENTIST/LAB_MANAGER/ADMIN ในฝั่ง CMIS เอง |
| CMIS ยังไม่ได้ลงทะเบียนเป็น client กับ MEDSCI ACC (ยังไม่มี client_id/client_secret จริง) | สูง | ติดต่อผู้ดูแลระบบกลางเพื่อขอลงทะเบียนก่อนเริ่ม T-010 มิฉะนั้นทดสอบ end-to-end ไม่ได้ (ใช้ Developer Bypass Mode ตาม `sso_integration_guide.md` §6 คั่นระหว่างรอ) |

---

**END OF SPEC**
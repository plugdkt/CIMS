# Changelog

All notable changes to this project are documented here.
Format loosely follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- T-000: Project foundation — Laravel 13 (see deviation note below), Pint (PSR-12), PHPStan/Larastan level 8,
  Pest, Dockerized dev environment (PHP 8.3, MariaDB 11.4, Redis 7).
- T-001: `.env`/`.env.example` wired to the MariaDB + Redis containers (session/cache/queue all on Redis).
- T-002: `SecurityHeaders`, `ForceHttps`, `AuditLogger` middleware (spec §9.6/§10.5).
- T-003: `public/web.config` for the eventual IIS deployment (spec §9.6).
- T-004: Full schema migration for every table in spec §5.2, plus the append-only triggers for
  `stock_ledger` (spec's own example) and `audit_logs` (AGENT RULE #6 covers both, spec's DDL only
  illustrated one) and the `CHECK` constraints spec's DDL calls for. Verified: append-only triggers
  reject UPDATE/DELETE, `chk_cont_remaining` rejects negative stock.
- T-005: `UnitSeeder`, `ItemCategorySeeder`, `RoleSeeder`, `PermissionSeeder` — a first-pass permission
  catalog derived from spec §3/§7 (not exhaustive; later tasks add more as those features are built).
  Verified: ADMIN never gets a ledger write permission (§3), only the explicit `ledger.verify` read
  (FR-LG-06 grants that to AUDITOR/ADMIN specifically).
- T-006: `UnitConverter` (spec §10.1, verbatim) + UT-01..UT-04.
- T-007: `DocumentNumberGenerator` (BR-09) + UT-06. Needed a `document_counters` table not listed in
  spec §5.2's DDL block — BR-09 requires "SELECT ... FOR UPDATE บนตาราง counter", so that table has to
  exist somewhere; added it as a necessary inference.

- T-010: SSO integration — `SsoClient` (spec §10.4), `/login`, `/sso/callback`, `/logout`, rate limiting
  (SEC-AU-04), session regeneration on login (SEC-AU-06). Frontend tooling set up alongside it since this
  was the first task needing real pages: Tailwind 3 + Livewire 3 + Alpine (via Livewire), fonts
  self-hosted under `public/fonts/` to keep the strict CSP intact.
- T-011: `UserProvisioningService` (BR-11 first-login create/sync), `EnsureRoleAssigned` middleware
  (HTTP 403 for any route but pending-role/login/logout — not a redirect, per BR-11/ST-10b), the
  "waiting for role" and "complete profile" pages. Verified: state-mismatch and replayed tokens are
  both rejected without hitting the verify API; a role-less user gets 403 everywhere except the
  whitelisted routes.
- T-012: RBAC — `Gate::before()` bridges the seeded permission catalog to `$user->can('code')` (deny-by-
  default: no matching permission falls through to null, not a blanket grant), a `Policy` base class,
  `UserPolicy` (FR-AU-07), and the admin "users & roles" page (Livewire) to assign/remove roles and
  toggle `is_active` (CMIS-side only, never touches the SSO account). Every role/status change is written
  to `audit_logs`. This was also the first page inside the authenticated app shell, so
  `layouts/app.blade.php` (sidebar + topbar, purple/gold) landed here too.
- T-013: Item CRUD (FR-MD-01) — `Item` model, `ItemPolicy` (`item.view`/`item.manage`), `ItemRequest`,
  a Livewire table with search (FR-MD-07), and plain-Blade create/edit forms. The app shell layout moved
  to `resources/views/components/layout.blade.php` so both Livewire full-page components (`$slot` via
  `#[Layout(...)]`) and plain Blade views (`<x-layout>` tag) can share the exact same chrome.
- T-014: SDS upload + attachment security (SEC-FU-01..07) — `attachments` disk (private, outside
  `public/`), `Attachment` model (append-only version history per `owner_type`/`owner_id`/`doc_type`),
  `AttachmentUploadService` (the single writer, mirrors `LedgerService`'s pattern), `VirusScanner`
  interface + `ClamAvScanner` (raw `INSTREAM` protocol over TCP, no PHP extension needed),
  `UploadAttachmentRequest` (extension allowlist + size cap from config, real content-sniffed via
  Laravel's `mimes:` rule — not client-supplied MIME/extension), and `AttachmentController` (`store`/
  `download`, the latter authorizing against the owning `Item` per-request — SEC-AZ-06, no direct/public
  file URL). SDS section added to the item edit page: version history with a "latest" badge, uploader
  name, download link, and an upload form gated on `item.manage`. Verified: 8 feature tests (versioning
  from 1, version increments, disallowed extension rejected, a file disguised with a fake extension
  still rejected via real MIME sniffing, oversized file rejected, infected file rejected with nothing
  stored, view-only role forbidden from uploading, download authorization checked independently of
  upload authorization) plus a manual browser walkthrough of the rendered SDS section on the item edit
  page (ULID-addressed URL, correct empty state, upload form present).
- T-015: GHS pictogram + H/P statements UI (FR-MD-03) — `config/ghs.php` (the 9 pictogram classes plus
  the standard UN GHS hazard/precautionary statement codes with official English wording; a static
  reference dataset, not a seeded table), a hand-drawn SVG `<x-ghs-icon>` component for the 9 pictograms
  (own artwork, not sourced image files), a new read-only item detail page (`GET /items/{item}`, gated
  on `item.view` — the first page view-only roles like SCIENTIST can reach for a given item, distinct
  from the `item.manage`-gated edit page), and an editable GHS/H/P-statement section on the item edit
  form (pictogram checkboxes with icons, plus an Alpine-powered searchable checklist for the ~70 H- and
  ~90 P-statement codes). `ItemRequest` validates every submitted code against the reference config.
  Verified: 7 feature tests (save + persist GHS/H/P codes, each of the three code families rejects an
  invalid code, the detail page renders pictograms and statement text to a view-only role, a role with
  no `item.view` gets 403 on the detail page, SCIENTIST can open the detail page but not the edit page)
  plus a manual browser walkthrough (pictogram/statement checkboxes pre-filled correctly from a seeded
  item, detail page rendering for both a manager and a view-only role, edit page 403 for the view-only
  role) — see CLAUDE.md for the two scope decisions this task needed (H/P statement text language, GHS
  icon sourcing).
- T-016: Location tree + BR-10 incompatibility warning (FR-MD-04, FR-MD-05) — a `ulid` column added to
  `locations` (spec's DDL has none, unlike `items`/`attachments`; needed for ULID-addressed routes, see
  CLAUDE.md), `LocationPolicy` (gated on the already-seeded `location.manage` permission), `LocationRequest`
  (validates the 4-level hierarchy: BUILDING has no parent, ROOM's parent must be a BUILDING, CABINET's
  a ROOM, SHELF's a CABINET), a `LocationIncompatibilityChecker` domain service implementing BR-10's
  ACID/BASE, FLAMMABLE/OXIDIZER, TOXIC/FOOD_GRADE pairing (order-independent, pure, unit-tested), and a
  Livewire `LocationTree` tree view (recursive rendering, no pagination — dataset is small by nature).
  A location's own `storage_class` is checked against its parent and siblings on every save, flashing a
  non-blocking warning when they conflict — the checker is written to be reused as-is at goods-receiving/
  container placement (T-022) once items are actually assigned to a location, which is the literal BR-10
  trigger but doesn't exist until Phase 2 (see CLAUDE.md). Verified: 4 unit tests for the pairing logic,
  8 feature tests (permission gate, each hierarchy-level violation rejected, unique code, the sibling-
  conflict warning is flashed, the tree renders) plus a manual browser walkthrough (seeded a BUILDING >
  ROOM > two sibling CABINETs with ACID/BASE > SHELF tree; both cabinets showed the conflict badge, the
  edit form pre-filled every field correctly from a ULID-addressed URL).
- T-017: Security Test suite, closing out Phase 1 — `tests/Feature/Security/*` covers ST-01 (a
  SQLi-shaped payload in the item search matches nothing and doesn't error — Eloquent's parameter
  binding, AGENT RULE #3), ST-02 (a `<script>` tag stored in an item name renders as escaped text on
  the detail page, never executes), ST-03 (a POST with no CSRF token is rejected), ST-06/ST-07 (no
  direct URL reaches raw storage files, `.env`, `composer.json`, or `vendor/`), ST-10 (a STUDENT gets
  403 on an ADMIN-only endpoint), and ST-11 (CSP/nosniff/X-Frame-Options present, HSTS present once the
  request is actually secure, no `Server`/`X-Powered-By`). ST-09, ST-09b, and ST-10b were already
  covered by `SsoLoginTest` (T-010/T-011) and ST-05 by `AttachmentUploadTest` (T-014) — not duplicated,
  just confirmed still passing. ST-08 stays out of scope here per spec (it's T-027, needs the DB grant
  script). ST-04 (IDOR: user A opens user B's private resource URL) has no real target yet — the
  literal example (a requisition) doesn't exist until T-030; nothing in Phase 1 is a per-user-owned
  resource addressed by URL (attachments are owned by an `Item`, gated by role-wide `item.view`, not by
  uploader identity, so it isn't a valid IDOR fixture either). Deferred to whichever task first ships a
  user-owned resource — see CLAUDE.md. Two implementation notes worth keeping in mind for future
  security tests: Laravel's CSRF middleware (`PreventRequestForgery`) self-disables while running unit
  tests, so ST-03 has to instantiate that exact class directly with the bypass overridden, rather than
  going through a normal `$this->post()` call, to actually exercise the real token check; and hitting
  `/storage/{path}` in this environment answers 403 (Laravel's built-in `storage.local` route refusing
  the private disk root), not 404 — both are explicitly acceptable per spec's own "HTTP 404/403"
  wording for ST-06.

## Phase 2 — Receiving & Ledger

### Added

- T-020: `LedgerHasher` + `ledger:verify` command (BR-08) — `StockLedger` model over the `stock_ledger`
  table (built in T-004 but unused until now), and `LedgerHasher::compute()`/`verifyChain()` implemented
  verbatim from spec §10.3 (SHA-256 over `prev_row_hash|item_id|txn_type|qty_in_base|qty_out_base|
  balance_base|created_at(µs)|created_by`, chained row to row). `php artisan ledger:verify` walks every
  item's chain and reports the first broken row, exiting non-zero if any chain is broken. Verified: UT-05
  (`compute()` on the same row twice yields the same hash) plus three more unit tests (64-char digest,
  changing `balance_base` or `prev_row_hash` changes the hash), and two feature tests exercising a real
  save-then-reread round trip through MariaDB — an intact 2-row chain reports OK, and a row inserted with
  a deliberately wrong `row_hash` (append-only triggers block UPDATE, so corruption can only be modeled
  via a bad INSERT) is correctly reported as the broken row.
- T-021: `LedgerService::receive()`/`issue()`/`return()`/`adjust()` — the single writer of `stock_ledger`
  (spec §4.2; no other class may construct a `StockLedger` row). `Container` model added (the table
  shipped in T-004 but was unused until now). `issue()` is spec §10.2's reference implementation verbatim
  (lock the container and the item's last ledger row, BR-07 running balance from that locked row only,
  reject if `remaining_qty_base` is short, retry 3× on deadlock). `receive()` and `return()` mirror it for
  the increasing direction; `return()` additionally reopens an `EMPTY` container back to `IN_USE` (BR-05).
  `adjust()` takes a signed quantity (positive = `ADJUST_IN`, negative = `ADJUST_OUT`) and enforces BR-06
  before writing anything: a remark of at least 10 characters, an `approvedBy` distinct from `createdBy`,
  a non-zero quantity, and a result that can't push the container negative. `return()`/`adjust()` only
  implement the ledger-writing primitive — the surrounding workflow (which issue a return is against,
  the stocktake approval step) is wired later where those flows actually exist (T-040, T-041/043), the
  same scope boundary as `LocationIncompatibilityChecker` in T-016; see CLAUDE.md. Verified: 11 feature
  tests covering every method, the EMPTY/IN_USE status transitions, all four BR-06 rejection paths, and
  a full receive→issue→return→adjust sequence whose hash chain `LedgerHasher::verifyChain()` confirms
  intact end to end.
- A small admin **Labs CRUD** (`lab.manage` permission, granted to ADMIN) — not itself a backlog item,
  but T-022 hard-blocks without it: `goods_receipts.lab_id` is `NOT NULL` and `labs` had no seeder and no
  UI to create one (a known gap flagged back in T-016). User-approved 2026-09-02 to build the small CRUD
  rather than seed a placeholder lab. `labs` also got a `ulid` column (same AGENT RULE #9 gap `locations`
  had before T-016) for its ULID-addressed edit route.
- T-022: GRN CRUD + confirm → containers + ledger (FR-RC-01..06) — `GoodsReceipt`/`GoodsReceiptItem`
  models, `GoodsReceiptPolicy` (gated on the already-seeded `receiving.manage` permission, SCIENTIST per
  §3), and `GoodsReceiptService`. A header (receipt date/PO/invoice/supplier/lab) is created in `DRAFT`
  via `DocumentNumberGenerator` (`GRN-2569-00001`, T-007); lines can be added/removed only while `DRAFT`
  (`GoodsReceiptPolicy::update()` doubles as the one gate for edit/add-line/confirm/cancel — all four
  require the same "still DRAFT" condition). `calculateLineTotalBase()` converts a line's `container_count
  × qty_per_container` (in whatever unit it was received in) into the *item's own* base unit — routing
  through the dimension's canonical base as an intermediate step and crossing dimensions via the item's
  density when the receiving unit and the item's base unit measure different things (e.g. received by
  volume, tracked by mass), reusing `UnitConverter` from T-006. Confirming a DRAFT GRN (FR-RC-02/03/05)
  creates `container_count` containers per line — barcode `{doc_no}-{line_no}-{seq}`, guaranteed unique
  since `doc_no` already is — inheriting lot_no/expiry_date/location from the line, then writes exactly
  one `RECEIVE` ledger row per container through `LedgerService::receive()` (T-021); once `CONFIRMED` a
  GRN can never be edited again (FR-RC-06). `cancel()` only works on a `DRAFT` GRN — see CLAUDE.md for why
  "cancel a CONFIRMED GRN" isn't implemented. Verified: 15 feature tests (unit conversion incl. cross-
  dimension and its `MissingDensityException`, line numbering, the full confirm flow asserting container
  count/quantities/barcodes/ledger rows/hash-chain integrity, every FR-RC-06 immutability path, permission
  gates) plus a manual browser walkthrough (created a lab, created a GRN, added a line, confirmed it, and
  independently verified via tinker that the containers, ledger rows, and hash chain all came out right).
- T-023: Barcode/QR generator + label PDF (FR-RC-04) — `BarcodeGenerator` (Code 128 via
  `picqer/php-barcode-generator`, the one symbology that round-trips any ASCII string, matching
  `containers.barcode`'s plain `VARCHAR`) and `QrCodeGenerator` (`endroid/qr-code`) as a matched pair per
  the task's own name; only the barcode side is wired into a page yet — the QR side sits ready for T-037's
  F-01 PDF QR-verify corner. `ContainerLabelPdfService` (mPDF) lays out barcode labels in a table grid on
  A4 at both required sizes, 40×25mm (5 columns) and 50×30mm (4 columns), each cell showing the item name,
  the barcode, its code as text, and Lot/EXP. `containersFor()` traces a GRN's containers back through the
  `RECEIVE` ledger rows it produced (`ref_type='GRN'`, `ref_id`) — `containers` itself has no
  `goods_receipt_id` column (spec's DDL never added one). Wired into the GRN show page as two print links,
  visible once `CONFIRMED`. Verified: 5 unit tests (barcode/QR generation, uniqueness per input, no stray
  markup), 3 feature tests (containersFor traces correctly and doesn't cross-contaminate between two GRNs,
  render produces a real PDF at both sizes, an unknown size throws), 3 more on the actual route (download
  succeeds for a confirmed GRN, 404 for an unknown size, 404 when there's nothing to label yet), plus a
  manual render-and-read of the actual PDF output (see Fixed below — the first render had a real, visible
  bug this caught).
- T-024: item ledger register (F-03) + display-unit switcher + filters (FR-LG-01..04) — a per-item
  Livewire page (`GET /items/{item}/ledger`, gated on the already-seeded `ledger.view` permission via a
  new `StockLedgerPolicy`) showing the F-03 header (category, item, brand, grade, package size, unit,
  sub-unit) and a table of every `stock_ledger` row for that item (date, txn type, issuer, receiver,
  in/out/balance, signed y/n, remark). FR-LG-03's unit switcher is purely a view transform — every
  `qty_in_base`/`qty_out_base`/`balance_base` is converted from the item's own base unit to whichever
  unit of the *same dimension* is picked (via `UnitConverter`, same toBase→fromBase composition as
  T-022), `stock_ledger` itself is never touched. FR-LG-04's filters (date range, txn type, requester,
  container) all run as reactive Livewire properties against the query directly — the container filter
  is the first real read consumer of `StockLedger::container()`, a relation that didn't exist before this
  task. Export (FR-LG-05, PDF/Excel) is T-025, not this task. Verified: 6 feature tests (permission gate,
  header rendering, RECEIVE/ISSUE rows show correct in/out/balance, the unit switcher converts displayed
  values without altering `qty_in_base` in the database, txn-type filter, container filter) plus a manual
  browser walkthrough (receive 2500 mL, issue 350 mL then 150 mL, balances came out right, then switched
  the display unit to L live and watched every row and the running balance re-render in litres correctly).
- T-025: F-03 PDF (mPDF + real Sarabun, finally) + Excel export (`maatwebsite/excel`) (FR-LG-05). Solved
  T-023's deferred "Sarabun in mPDF" problem without fetching anything new: `resources/fonts/pdf/
  Sarabun-{Regular,Bold}.ttf` were built once by decompressing the Thai + Latin `.woff2` subsets already
  self-hosted under `public/fonts/` (T-010) back to TTF (Debian's `woff2` package) and merging the two
  scripts into one complete font per weight (Python `fontTools.merge`, since a browser-style unicode-range
  split isn't something mPDF's embedder understands) — see CLAUDE.md for the exact recipe. `MpdfFactory`
  centralizes registering these fonts so any future mPDF consumer (T-037's F-01, say) gets Sarabun for
  free. Extracted the ledger row-fetching + FR-LG-03 unit-conversion logic that used to live inside the
  `ItemLedger` Livewire component (T-024) into `LedgerQueryService` + a proper `LedgerRow` DTO, shared by
  the screen, `Fr03PdfService`, and `Fr03Export` — so the three can never drift apart for the same filters.
  Export links on the ledger page carry the page's current filter/unit state via `#[Url]`-bound Livewire
  properties, so what downloads always matches what's on screen. Verified: 7 tests (export rows/headings
  match the screen shape, txn-type filter applies, unit conversion applies, a full write→read round trip
  through a real `.xlsx` file via PhpSpreadsheet confirming actual cell values — not just "didn't throw",
  the PDF is non-empty, permission gate, successful download for both formats) plus a manual render of the
  real PDF and Excel from real ledger data, both read back and visually/textually confirmed correct —
  the PDF in particular now shows real Sarabun Thai text, not the T-023 Garuda fallback.
- T-026: Concurrency Test CT-01..CT-03 — real cross-process concurrency, not a loop inside one test
  process (a single Pest test is single-threaded, so it would never contend `LedgerService`'s
  `lockForUpdate()` row lock the way spec's "50 requests พร้อมกัน" demands). `tests/Concurrency/bin/
  issue_once.php` is a standalone script (not a shipped Artisan command) that boots Laravel and calls
  `LedgerService::issue()` once, spawned as real separate OS processes via Symfony `Process` from
  `tests/Feature/Ledger/ConcurrencyTest.php`, each with its own DB connection genuinely racing for the
  same container row. CT-01: 50 concurrent 10 mL withdrawals from a 400 mL bottle — verified exactly 40
  succeed, 10 fail with `InsufficientStockException`, remaining lands at exactly `0.000000`, container
  status becomes `EMPTY`. CT-02: 1,000 concurrent 0.001 g withdrawals from a 1 g bottle (20 processes ×
  50 sequential attempts each, not 1,000 separate OS processes — still genuine cross-process contention
  on every attempt, at a fraction of the spawn cost) — remaining lands at exactly `0.000000`, all 1,000
  succeed. CT-03: `LedgerHasher::verifyChain()` confirmed intact immediately after CT-01's concurrent
  run — the hash chain survived real lock contention, not just sequential writes. These tests
  deliberately skip `RefreshDatabase` (fixtures must be real committed rows, visible across process
  boundaries) — see CLAUDE.md for the DB hygiene implications.
- T-027: DB grant restriction (SEC-DB-02) + Security Test ST-08. `cmis_app` (the app's own DB user) had
  `GRANT ALL PRIVILEGES` on every table including `stock_ledger`/`audit_logs` — a gap the append-only
  triggers (T-004/T-020) always assumed T-027 would close (AGENT RULE #6 layer #1 of 2). `docker/mariadb/
  restrict_app_grants.sql` replaces that blanket db-level grant with explicit per-table grants: full CRUD
  on every other table, `SELECT, INSERT` only on `stock_ledger`/`audit_logs`, applied to both `cmis` and
  `cmis_testing`. Verified live against both databases (`SHOW GRANTS` confirms no UPDATE/DELETE on either
  table). ST-08 (`tests/Feature/Security/DatabaseGrantRestrictionTest.php`) proves this at the grant
  layer specifically — a raw `DB::table('stock_ledger')->update()/delete()` (bypassing Eloquent's own
  `AuditLog::update()/delete()` guard entirely) fails with MySQL error 1142 / SQLSTATE `42000` ("command
  denied"), which is distinct from the trigger's SQLSTATE `45000` ("append-only") — proving the grant
  itself is what's rejecting it, not an incidental re-trigger. A control test confirms the restriction is
  table-specific, not a broken connection (`items` still updates fine via the same raw-SQL path).
- T-030: `RequisitionState` (BR-01) — a pure, stateless lookup for the requisition status state
  machine (DRAFT → SUBMITTED → ADVISOR_APPROVED/APPROVED → ISSUED, with REJECTED/CANCELLED as the
  other terminals). Callers ask `apply()` for the next status and persist it themselves; nothing in
  this class touches the database. Encodes the one context-dependent edge in spec's diagram itself —
  a SUBMITTED requisition only goes straight to a scientist decision when the requester isn't a
  STUDENT — while BR-02's stronger check (`advisor_signed_at IS NULL` against the real model) is left
  to `ApprovalService` (T-032). 8 unit tests cover every edge and every terminal state.
- T-031: requisition form + dynamic line items (FR-RQ-01..05). `Requisition`/`RequisitionItem`/
  `RequisitionApproval` models, `RequisitionPolicy` (`view_own` scoped to requester/advisor,
  `view_all` for SCIENTIST/LAB_MANAGER/AUDITOR), `RequisitionService` (line items + the DRAFT→
  SUBMITTED/CANCELLED transitions via `RequisitionState`). Requester identity fields (name, phone,
  status, student code, program, faculty) are snapshotted server-side from the authenticated user's
  profile at creation time — never accepted as form input — enforcing BR-11 point 4's complete-profile
  gate (redirects to the complete-profile page) at the one real trigger point that gate was deferred
  to back in T-011. Added `UnitConverter::toItemBase()` (item + arbitrary unit + qty → the item's own
  base unit, crossing dimensions via density same as T-018's GRN line totals) and refactored
  `GoodsReceiptService::calculateLineTotalBase()` to use it, removing the duplicated conversion logic
  between GRN and requisition lines. FR-RQ-05's real-time balance display next to the item picker is a
  small Alpine `fetch()` against a new `GET /requisitions/items/{item}/balance` JSON endpoint — plain
  controller + FormRequest + Blade + a touch of Alpine, matching the GRN precedent (T-022) rather than
  a full Livewire form; see CLAUDE.md for the reasoning. Verified: 12 feature tests (permission gate,
  BR-11 profile-complete redirect, requester-field snapshotting for both STUDENT and STAFF, same-
  dimension and cross-dimension line-item base-unit conversion, add/remove lines, submit requires ≥1
  line and sets `submitted_at`, cancel from both DRAFT and SUBMITTED, view_own vs. view_all scoping,
  the balance endpoint) plus a full manual run through the real UI (create → auto-filled profile →
  add a 2 kg line converted correctly into the item's gram base unit → submit → index list), confirmed
  in the browser end-to-end.
- T-032: `ApprovalService` + BR-02 enforcement (FR-RQ-06). `advisorDecide()`/`scientistDecide()` each
  write one `requisition_approvals` row and advance `Requisition::status` through `RequisitionState`
  (T-030). BR-02 is checked directly against `advisor_signed_at` (not merely inferred from `status`),
  so a STUDENT requisition can never reach APPROVED without real advisor sign-off, even defensively.
  A REJECT decision (either step) requires a non-empty reason; `advisorDecide()` also enforces that
  only the requisition's own `advisor_id` may act (a business rule about *which* advisor, enforced in
  the service the same way BR-06's distinct-approver rule lives inside `LedgerService::adjust()`
  rather than only in a Policy). `advisor_signature_hash` is a lightweight SHA-256 non-repudiation
  marker (`requisition_id|advisor_id|decision|timestamp`) — spec has no formula for this field (unlike
  BR-08's ledger hash chain); documented as an inferred convention in CLAUDE.md. Verified: 9 tests
  including FT-01 (scientist can't approve a STUDENT requisition with no advisor sign-off yet) and
  FT-06 (scientist can't reject without a reason).
- T-033: signed-URL advisor approval, 72 hours (FR-RQ-07). `RequisitionService::submit()` now emails
  the advisor an `AdvisorApprovalMail` (queued) carrying a `URL::temporarySignedRoute()` link the
  moment a STUDENT requisition is submitted — no email for non-student requesters, since BR-02 never
  applies to them. `GET/POST /approve/{requisition}` (outside the `auth` group, spec §7.2, gated only
  by the `signed` route middleware) renders and processes the decision form without requiring the
  advisor to log in at all; the POST target is the page's own current URL (`url()->full()`), which
  carries the same `signature`/`expires` query params forward since Laravel's signature check is
  path+query based, not tied to a specific route name or HTTP verb. Also added the "ผ่านระบบ" in-system
  channel FR-RQ-07 asks for alongside it: an `advisorDecide` Policy ability plus a form on the
  requisition's own show page, visible only to the requisition's own advisor while it's SUBMITTED.
  Verified: 9 tests (email sent only for STUDENT requesters, valid/tampered/expired signed links,
  approve and reject-without-reason via the signed link, in-system decide by the correct vs. a
  different advisor) plus a full manual run — real signed URL generated via tinker, opened in the
  browser, approved, confirmed the requisition actually moved to ADVISOR_APPROVED in the database.
- T-034: scientist review page (FR-RQ-08). Reuses the requisition show page (T-031/T-033) rather than
  a separate screen — a new section, gated by `RequisitionPolicy::scientistDecide` (`requisition.
  approve_scientist` + status SUBMITTED or ADVISOR_APPROVED), posts to `ApprovalService::
  scientistDecide()` (T-032) via a dedicated `RequisitionScientistDecisionRequest`. Uses FR-RQ-08's own
  wording — "เห็นควรให้เบิก" / "ไม่เห็นควรให้เบิก" — distinct from the advisor's "อนุมัติ"/"ไม่อนุมัติ" text
  elsewhere on the same page; reject still requires a reason. Verified: 6 tests (approve, reject
  without/with a reason, BR-02's friendly error surfaces correctly through the HTTP layer, permission
  gate, the decision form only renders when the requisition is actually eligible) plus a manual browser
  run confirming the exact Thai wording renders and a real decision moves the requisition to APPROVED.
- T-035: dispensing page — FEFO + multi-container (FR-RQ-09/10, BR-03, BR-04). `FefoContainerSelector`
  ranks eligible containers (IN_USE before SEALED, empty/disposed/quarantined never eligible) by
  soonest `expiry_date`, falling back to oldest `received_at` when both compared containers have no
  expiry (spec's literal fallback) — a known expiry is ranked ahead of no expiry within the same tier,
  an inferred convention documented in CLAUDE.md since spec only spells out the both-NULL case.
  `IssueService` issues from one container against one requisition line per call (FR-RQ-10 covers
  multiple containers per line by calling it more than once), delegates the actual `stock_ledger` write
  to `LedgerService::issue()` (still the only writer), and owns `issue_transactions` + `requisition_
  items.qty_issued_base` + advancing `Requisition::status` to PARTIALLY_ISSUED/ISSUED via `RequisitionState`.
  BR-04's tolerance is enforced in the service itself: no remark needed at or under the requested
  quantity, a remark required for any overage, and past 10% over, an approver who actually holds a new
  `requisition.issue_override` permission (LAB_MANAGER per PermissionSeeder) — added a migration for
  `requisition_items.overage_approved_by` since spec's own DDL has nowhere to record this (same class of
  gap T-016/T-022 already filled for `ulid` columns). The issue page (`GET /requisitions/{requisition}/
  issue`) shows FEFO ranking with a red expiry warning per container and pre-fills the barcode field
  with the recommended pick; `POST .../items/{requisition_item}/issue` looks the container up by
  barcode ("สแกน barcode" per FR-RQ-09), not by picking an id from a dropdown. Verified: 6 FEFO-ranking
  tests, 7 `IssueService` tests (exact match, two-container split, partial issue, both BR-04 tiers,
  item/container mismatch, wrong requisition status), 5 HTTP tests, plus a full manual run — two real
  containers with different expiry dates, confirmed the sooner-expiring one was recommended and
  correctly decremented while the other was left untouched, and the requisition moved to ISSUED.
- T-036: receiver e-signature canvas + OTP fallback (FR-RQ-11). `IssueService::issue()` now requires a
  `signatureHash` (BR-04's decision-making stays the service's job; whether that hash came from a
  drawn signature or an OTP is the controller's). Two ways to confirm the receiver at issue time, on
  the same page (T-035), same POST: (1) an HTML5 `<canvas>` signature pad — plain pointer/touch event
  handlers in Alpine, no signature-pad library (CSP has no allowance for one anyway, and the project's
  established preference is the simplest tool that works — same reasoning as the mobile nav toggle and
  the complete-profile page's vanilla-JS field) — captured as a PNG data URL, decoded and validated by
  a new `SignatureImageService` (PNG magic-byte check, 512 KB cap, UUID filename on a new `signatures`
  disk that mirrors `AttachmentUploadService`'s "never the client's name" shape) and hashed with
  SHA-256; or (2) a 6-digit OTP emailed to the requisition's requester via a new `ReceiverOtpService` —
  single-use, 10-minute TTL, stored in the cache (Redis) rather than a database table, since it's a
  one-time confirmation of presence, not a credential (SEC-AU-01 still holds: no local passwords
  anywhere). Exactly one of `signature_image`/`otp_code` is required per submission. Verified: 4
  `SignatureImageService` tests (valid PNG, wrong MIME, not a data URL at all, garbage base64), 3
  `ReceiverOtpService` tests (verifies once then fails on reuse, wrong code, code scoped to its own
  requisition), the `IssueService`/HTTP suites updated for the new required parameter, plus a manual
  browser run drawing a real signature (confirmed a valid, correctly-hashed PNG was written to disk)
  and completing the emailed-OTP path end to end. Caught and fixed one real bug from that manual run:
  the canvas read its own width via `offsetWidth` inside `x-init`, before Alpine had finished laying
  out the DOM, producing a 2px-wide canvas — fixed by deferring that read into `$nextTick`. Also
  switched `RequisitionIssueControllerTest` to `Storage::fake('signatures')`, since the earlier version
  (before this fix was caught) was writing real tiny PNGs into the dev disk on every test run.
- T-037: printable F-01 + QR verify + public verify page (FR-RQ-12, §7.2). `Fr01PdfService` renders
  the requisition as a full-page A4 PDF via `MpdfFactory` (real Sarabun, per T-025) — header/requester
  info, the line-items table, the advisor's and scientist's decisions (gracefully showing "รอพิจารณา"/
  "ไม่ต้องผ่านอาจารย์ที่ปรึกษา" for stages not yet reached, since spec's own report list implies F-01
  should be printable at any point in its life, not only once fully issued), the receiver block (embeds
  the actual signature PNG when one was drawn, or states "ยืนยันตัวตนด้วยรหัส OTP" when the OTP channel
  was used instead — T-036), and a QR code in the bottom-right corner (wiring in T-023's `QrCodeGenerator`,
  built and left unused for exactly this) linking to a new public `GET /verify/{ulid}` route. That route
  (`DocumentVerifyController`, no `auth` middleware, spec §7.2) shows only `doc_no`/`doc_date`/`status`
  and explicitly nothing else, per spec's own "ห้ามแสดงข้อมูลส่วนบุคคล" — verified by asserting the
  requester's name and email are both absent from the response, not merely that the page loads. No
  literal visual template for F-01 exists to match pixel-for-pixel (unlike F-03, which spec's own FR-LG-
  01/02 describe column-by-column) — the layout was designed from the data the `Requisition` model
  actually carries, the same kind of judgment call as T-015's GHS statement language and T-023's
  hand-drawn pictograms where no verified source template was available. Verified: 6 PDF/permission
  tests, 2 verify-page tests, plus a manual render of a real, fully-issued requisition — read back and
  visually confirmed correct (real Thai text, all sections populated, QR present) — and the QR's actual
  target URL opened directly, confirming the verify page shows the right status with no personal data.
- T-038: Feature Test FT-01..FT-10 (§11.2). Added `AcceptanceFeatureTest.php` running spec's own
  numbered scenarios verbatim, with spec's own numbers where it gives them: FT-02 (submit → advisor
  approve → scientist approve → issue 12.5 g from a 500 g bottle → remaining exactly 487.5 g, exactly
  one ISSUE ledger row, status ISSUED), FT-03 (issuing more than the container holds throws
  `InsufficientStockException` and writes no new ledger row), FT-04 (request 100 mL, issue 60 mL →
  PARTIALLY_ISSUED). FT-01/06/09/10 already existed verbatim in earlier tasks' own test files
  (`ApprovalServiceTest`, `ScientistDecisionTest`, `FefoContainerSelectorTest`, `Fr03ExportTest`) and
  weren't duplicated. **FT-05, FT-07, and FT-08's HTTP-403 layer are deferred, not written** — they
  exercise Return (T-040), Stock Take variance detection (T-041), and the Adjustment workflow's HTTP/
  Policy layer (T-043), none of which exist as features yet in this phase; only their underlying
  `LedgerService::return()`/`adjust()` primitives do (already tested since T-021, including BR-06's
  same-actor rejection). Writing a shallow test against only the primitive wouldn't exercise what these
  three scenarios actually describe, so — same judgment call as T-017's ST-04 — they're left for the
  tasks that build the real target rather than guessed at now. See CLAUDE.md.

## Phase 4 — Operations

### Added

- T-040: Return flow (FR-ST-01, BR-05). `ReturnService::return()` enforces BR-05's two checks —
  `qty_issued_base - qty_returned_base > 0` (something left to return) and the target container must
  be one this line was actually issued from (queried from `issue_transactions`, since spec's schema has
  no dedicated return table — a return is just another `stock_ledger` RETURN row plus incrementing
  `requisition_items.qty_returned_base`) — then delegates the actual ledger write to
  `LedgerService::return()` (T-021's primitive, still the only writer of `stock_ledger`). Added a
  "คืนของ" section to the existing issue/return page (T-035), gated by a new `RequisitionPolicy::return`
  ability that — unlike `issue` — stays true for both PARTIALLY_ISSUED and ISSUED, since returning
  unused material remains meaningful even after a requisition is fully issued. **Caught and fixed a
  real bug from manual browser verification**: the page itself was still gated on the `issue` ability
  only, so a fully ISSUED requisition — the exact case a return needs — made the whole page 403 before
  the return section could ever render; fixed by gating page access on "can issue OR can return", with
  a regression test added since no automated test had caught it. Verified: 5 `ReturnService` tests
  (FT-05 with spec's own numbers — 20 mL back from 50 mL issued — plus both BR-05 checks and multi-
  return accumulation), 4 HTTP tests (including the regression test for the page-reachability bug),
  plus a full manual run confirming a real return correctly credited the container.
- T-041: stock take + mobile scan (FR-ST-02..04). `StockTakeService::create()` snapshots
  `system_qty_base` from every "active" container (SEALED/IN_USE/QUARANTINE — EMPTY has a trivially
  known count and DISPOSED no longer physically exists, so neither needs counting) in the chosen lab,
  found via `containers.location_id` → `locations.lab_id` (a container with no location can't be
  attributed to any lab and is simply never included). `recordCount()` is FR-ST-03's mobile scan
  target — barcode in, counted quantity in the item's own base unit out, `diff_base` computed
  immediately. `approve()` is the only path that writes `ADJUST_IN`/`ADJUST_OUT` (via `LedgerService::
  adjust()`, still the only writer of `stock_ledger`) and, per BR-06, checks *every* affected line's
  counter against the approver upfront — an approval is all-or-nothing, never partially applied because
  one line happened to have been counted by the same person now approving. The scan page
  (`GET .../scan`) is a deliberately minimal, large-tap-target, single-purpose mobile view that loops
  back to itself after each save, showing a running "counted X / Y" tally. Verified: 6 service tests
  (including FT-07 with a real ADJUST_OUT row and BR-06's same-actor rejection), 5 HTTP tests, plus a
  full manual run through every stage — created a round, counted a real shortage via the mobile scan
  page, submitted, approved as a different user (LAB_MANAGER), and confirmed both the container and a
  real ADJUST_OUT ledger row in the database matched exactly.
- T-042: Disposal (FR-ST-05). Added `LedgerService::dispose()` — the fifth and final ledger-writing
  primitive, same shape as `issue()` but the container's terminal status is DISPOSED (not EMPTY) once
  fully consumed, since a disposed container never becomes available again. `DisposalService` owns the
  `disposals` request → approve/reject workflow: a request is checked against the container's remaining
  stock at request time, and — since time passes between request and approval — checked *again* against
  whatever the container's remaining stock actually is at approval time, not the stale figure from the
  original request. Approving is the only path that writes the `DISPOSE` row; rejecting never touches
  the ledger at all (spec's schema has no column for a rejection reason, unlike requisitions'
  `reject_reason`, so none is stored). Deliberately does **not** add a BR-06-style "approver ≠
  requester" check beyond ordinary role separation (`disposal.request` vs. `disposal.approve` are
  different permissions on different roles) — BR-06's literal wording is specifically about
  adjustments, not disposal, and spec never repeats that requirement here; see CLAUDE.md. Verified: 6
  service tests (including the request-time vs. approval-time re-check), 5 HTTP tests, plus a full
  manual run — requested disposal of an entire expired container, approved as a different user
  (LAB_MANAGER), confirmed the container was fully credited down to zero and flipped to DISPOSED.
- T-043: Adjustment workflow (FR-LG-07, BR-06). Unlike stock take/disposal, spec's schema has no
  "pending adjustment request" table for this flow, so `AdjustmentService::adjust()` is a single-step
  action naming both the adjustment and its distinct, authorized approver at once (mirrors T-035's
  `requisition.issue_override` pattern) rather than a two-phase request → approve workflow.
  `LedgerService::adjust()` (T-021) already enforced "approver ≠ creator" and "remark ≥ 10 chars";
  `AdjustmentService` adds the other half BR-06 implies but that check alone can't see — the named
  approver must actually **hold** `ledger.adjust` (currently LAB_MANAGER only per `PermissionSeeder`),
  not merely be a different user id. Also added `stock_ledger.approved_by` (nullable FK to `users`) via
  migration — BR-06 literally says an adjustment row must "have approved_by = LAB_MANAGER", but spec's
  own §5.2 DDL for `stock_ledger` has no such column (unlike `stock_takes`/`disposals`, which do); same
  class of gap as T-035's `overage_approved_by`. `LedgerService::appendRow()` now persists it for every
  txn type (null for anything but ADJUST_IN/ADJUST_OUT); BR-08's hash formula is an explicit fixed list
  per spec §10.3 and doesn't include this column, so the hash chain is unaffected. New `/adjustments`
  index (plain paginated Blade view, not Livewire — same "simplest tool" precedent as T-031) lists every
  ADJUST_IN/ADJUST_OUT row with its creator and approver; `/adjustments/create` is a single form
  (barcode, direction, qty, remark, approver picker excluding the current user and any inactive account).
  Verified: 7 service tests (including BR-06's four rejection paths and confirming `approved_by` is
  recorded only for adjustment rows, never for RECEIVE/etc.), 6 HTTP tests (including FT-07/FT-08 by
  spec's own numbers), plus a full manual run — recorded a real ADJUST_OUT as one LAB_MANAGER naming a
  second as approver, confirmed the container balance, the ledger row, and the approver's name all
  matched in the browser.
- T-044: Notification jobs + scheduler (FR-NT-01..06). `notifications` was already a real table since
  T-004 (spec's §5.2 DDL, migrated back at project init but never used) — added the missing `ulid`
  column (AGENT RULE #9, same gap as `locations`/`labs` before them) rather than creating a duplicate
  table. New `NotificationService` (`Domain/Notification`) exposes exactly the three channel
  combinations spec's own table lists: `notifyInApp` (FR-NT-05, in-app only), `notifyInAppAndEmail`
  (FR-NT-01..04, both), and `emailOnly` (FR-NT-06, routed to ADMIN) — one generic `NotificationMail`
  covers every type, mirroring the `notifications` table's own generic (type/title/body/link_url)
  shape. FR-NT-03/04 are wired directly into `RequisitionService::submit()` and
  `ApprovalService::advisorDecide()`/`scientistDecide()`: a STUDENT submission notifies the advisor
  in-app only (T-033's signed-URL email already covers that step's "Email" channel — a second generic
  email would just duplicate it); a non-student submission, or an advisor's APPROVE decision, notifies
  every `requisition.approve_scientist` holder in-app **and** by email (this stage had zero notification
  before T-044); every advisor/scientist decision (approve or reject) notifies the requester of the
  result. Four new scheduled Artisan commands cover the daily/periodic checks: `notifications:check-
  reorder` (FR-NT-01, item balance < `reorder_point_base`), `notifications:check-expiry` (FR-NT-02,
  containers hitting exactly 90/30/7 days to `expiry_date`), `notifications:check-shelf-life` (FR-NT-05,
  containers open longer than `shelf_life_days_after_open`, in-app only), and `notifications:check-hash-
  chain` (FR-NT-06, reuses `LedgerHasher::verifyChain()` from T-020, emails every ADMIN on a break) —
  scheduled in `routes/console.php` (`Schedule::command(...)->dailyAt('07:00')` for the first three,
  `->hourly()` for the hash-chain check since spec's own "Immediate" has no real trigger point to hook
  — see CLAUDE.md). New `/notifications` page (plain Blade, not Livewire) plus a header bell with an
  unread-count badge round out the in-app half; every user sees only their own (`NotificationPolicy`).
  Verified: 5 service tests, 5 requisition-integration tests (advisor/scientist notification wiring), 3
  tests each for the reorder/expiry/shelf-life commands, 2 for the hash-chain command (intact + broken
  chain, reusing T-020's tamper-a-row-directly technique since the DB grants block `UPDATE`), 4
  controller tests (index scoping, read, IDOR, mark-all-read) — 19 new tests total — plus a full manual
  run: submitted a real requisition as a STUDENT, approved as the advisor, rejected as the scientist,
  confirming the correct in-app rows and emails (via `queue:work`) at every step; ran all four commands
  against real dev-DB fixtures (a low-stock item, containers landing on real 90/30/7-day thresholds, an
  over-shelf-life container) and confirmed exactly the right recipients and channels for each.
- T-045: Reports (FR-8) + CSV injection guard (SEC-IN-10). Two of §7.8's eight reports already existed
  (F-03 item ledger from T-024/025, F-01 requisition PDF from T-037); this task builds the other six —
  usage summary, near-expiry, below-reorder-point, dead stock, controlled substances, and stock-take
  variance — plus a `CsvInjectionGuard` (prefixes a cell value with `'` when it starts with `= + - @`)
  applied to every free-text cell across every export in the app, including retrofitting T-025's
  existing `Fr03Export` (`issuer`/`receiver`/`remark`), which had shipped with no guard at all. New
  `ReportController` (`/reports` hub + one export action per report) is gated on a bare
  `Gate::authorize('report.view')` call rather than a dedicated Policy class — reports have no
  underlying Eloquent resource for object-level rules the way every other Policy in this app gates one,
  so a Policy class would need a fake marker model just to satisfy Laravel's auto-discovery convention;
  `AppServiceProvider`'s existing `Gate::before()` permission bridge already makes a bare ability string
  work correctly. "Below reorder point" and "dead stock" both accept an optional `lab` filter even
  though item balance is global (spec's schema has no per-lab stock split) — the filter narrows to items/
  containers physically present in that lab via `containers.location_id` → `locations.lab_id`, not a
  lab-scoped balance. "Dead stock" is evaluated per **container** (not per item) — the container is the
  physically actionable unit — as "no `stock_ledger` row referencing this `container_id` in the last 12
  months, while `remaining_qty_base > 0`". Two reports (controlled substances, stock-take variance) ship
  both Excel and PDF, reusing `MpdfFactory` (Sarabun) the same way `Fr03PdfService` does. Verified: a
  `CsvInjectionGuardTest`, one Export test file per report (data correctness, filters, and CSV-injection
  guarding), two PDF smoke tests, a `ReportControllerTest` (permission gating + every route reachable),
  and a regression test added to `Fr03ExportTest` confirming the retrofitted guard — 27 new tests total —
  plus a full manual run: built real fixtures for all six reports (a below-reorder item, a controlled
  substance with a movement, a near-expiry container, a dead-stock container with a 15-month-old ledger
  row, a stock-take round with one counted line, and a real issued requisition) and downloaded every one
  of the 8 export routes through the actual browser session, confirming 200 OK and the correct
  Content-Type on each; confirmed a STUDENT gets a real 403 page and no "รายงาน" nav link at all.
- T-046: Dashboard (FR-9). The home page (`/`, previously a placeholder quick-links grid) is now a real
  dashboard via `DashboardController` + `DashboardService`. The pending-requisitions card is the one
  metric spec explicitly says varies "แยกตาม role ของผู้ใช้" — it sums whichever action queues the
  viewer's permissions make them responsible for (`requisition.approve_advisor` → their own advisees
  awaiting a decision; `requisition.approve_scientist` → every ADVISOR_APPROVED/non-student-SUBMITTED
  requisition system-wide; `requisition.issue` → every APPROVED/PARTIALLY_ISSUED requisition awaiting
  issuance), falling back to the viewer's own in-flight requisitions for a plain requester (STUDENT/
  STAFF) or a bare `0` for a role with none of those permissions (LAB_MANAGER/ADMIN/AUDITOR — correct,
  not a bug, since none of them act on requisitions directly). Every other card (below-reorder count,
  containers expiring ≤30 days, top-10 issued items over 3 months, a 12-month issuance chart) is gated
  behind `report.view` and shown identically to every holder of it, same as the `/reports` hub. Top
  items and the monthly chart both rank/bucket by **issue-transaction frequency, not summed quantity** —
  items are measured in incompatible units (mg vs mL vs pcs), so a quantity total across different items
  would not be a meaningful comparison; both are computed by grouping in PHP (`Collection::countBy()`)
  rather than a raw SQL `GROUP BY`, per AGENT RULE #3. The monthly chart is a hand-rolled inline SVG bar
  chart with no JS/charting library at all — this app's CSP `script-src` has no CDN allowance (self +
  nonce only, same constraint noted for T-036's signature canvas), and a server-rendered SVG needs zero
  JS to begin with. Verified: 10 `DashboardServiceTest` cases (one per role-branch of the pending count,
  plus reorder/expiry/top-items/monthly-series correctness and window boundaries) and 3
  `DashboardControllerTest` cases (guest sees `welcome`, a STUDENT sees only the pending card, a
  SCIENTIST sees every card) — 13 new tests total — plus a full manual run logged in as an ADVISOR,
  STUDENT, SCIENTIST, and LAB_MANAGER in turn, confirming each saw the exact expected numbers (including
  the SVG chart's actual `<rect>`/`<text>` values inspected directly via the browser's DOM, not just a
  screenshot) against real fixtures (a below-reorder item, a near-expiry container, one SUBMITTED and one
  ISSUED requisition).

### Fixed

- `StockLedger` needs `protected $table = 'stock_ledger'` — Eloquent's default pluralization guesses
  `stock_ledgers`, which doesn't exist. Caught immediately by the first feature test run (table-not-found),
  not a silent bug, but worth flagging since every other model in this app so far happened to match
  Eloquent's default guess.
- Larastan infers a `decimal:N`-cast attribute (e.g. `Container::$remaining_qty_base`,
  `StockLedger::$balance_base`) as `float`, not the `string` Laravel actually returns at runtime — harmless
  until the value is passed to a `bcadd`/`bcsub`/`bccomp`/`bcmul` call, which PHPStan's stubs require to be
  `numeric-string`. Fixed the same way `UnitConverter` (T-006) already had to: `@property numeric-string`
  overrides on the model, plus `@param numeric-string` on every `LedgerService` method that takes a
  quantity. Same fix needed for any future model with a decimal column that gets bcmath'd directly.
- Same non-covariant-`Collection` class of PHPStan error as above, different shape: a method returning
  `Collection<int, object>` built from `(object) [...]` casts fails, because PHPStan infers the *exact*
  anonymous shape of the cast (every key and its type) rather than widening to plain `object`, and
  `Collection`'s `TValue` isn't covariant so the precise shape can't satisfy the looser declared return
  type. Turning the anonymous `stdClass` into a real named DTO (`LedgerRow`) fixed it outright — bonus:
  every consumer (`ItemLedger`, `Fr03PdfService`, `Fr03Export`) gets real property names and PHPStan
  actually checks them, instead of `object` erasing all of that. Same fix applied to an array-literal
  return inside a `->map()` closure (`Fr03Export::collection()`) by extracting it to a named private
  method with an explicit `@return array<int, string>` — PHPStan trusts an explicit method return
  annotation over its own shape inference, but not a bare docblock floated above a closure assignment.
- `UnitConverter`'s three methods (T-006) were missing `@return numeric-string` — only their params were
  annotated, so every call site got a plain `string` back. Harmless as long as nothing fed that return
  value into another numeric-string-typed parameter; T-022's `calculateLineTotalBase()` is the first
  caller that chains `toBase()` straight into `fromBase()`/`crossDimension()`, which is exactly the case
  that surfaces the gap. Added the missing return annotations.
- `BarcodeGenerator`/`QrCodeGenerator` both returned a full standalone SVG *document* (leading
  `<?xml version="1.0" ...?>` prolog). Embedded raw inside an HTML label cell, an HTML parser doesn't
  recognise that mid-document — it just prints the prolog as literal visible text, which is exactly what
  the first rendered label PDF showed above every barcode. Not caught by any automated test until a manual
  render-and-read of the actual PDF output; both generators now strip/suppress the prolog so their output
  is safe to embed inline. Worth remembering for any future SVG-generating library: check what a
  "successful" unit test (asserting `str_contains($svg, '<svg')`) actually leaves in front of that tag.
- `Container::$expiry_date` needed the same `@property \Illuminate\Support\Carbon|null` docblock fix
  already applied to other date-cast columns in this app (T-014's `Attachment::$created_at`, etc.) —
  first surfaced when `ContainerLabelPdfService` called `->format()` on it directly, the first code to
  actually use that column as a Carbon instance rather than just storing/displaying it.
- `goods-receipts/show.blade.php` briefly duplicated the `session('status')` flash banner — the shared
  `components/layout.blade.php` already renders it once for every page; adding it again in a specific view
  (same mistake already made and fixed once for `locations/form.blade.php` in T-016) shows it twice. Caught
  by eye during the manual browser walkthrough, not by any automated check — worth a glance in review
  whenever a new plain-Blade view is added.

- `users.person_type` was `NOT NULL` in spec's literal DDL, but BR-11 explicitly says the SSO doesn't
  provide it — it's unknown until profile completion. Made it nullable (migration hadn't shipped
  anywhere yet, so edited directly rather than adding a follow-up migration).

- Test suite now runs against real MariaDB instead of the Laravel default (sqlite in-memory) — the
  spec's own append-only triggers and CHECK constraints are MariaDB-specific syntax sqlite can't
  execute.
- **Tests now run against a dedicated `cmis_testing` database, not the shared dev `cmis` one.**
  `phpunit.xml` previously claimed `RefreshDatabase`'s per-test transaction rollback made sharing the
  dev database safe — that was wrong. `RefreshDatabase`'s *first* run in a process calls
  `migrate:fresh`, which is DDL and auto-commits in MySQL/MariaDB; it is NOT undone by the later
  per-test transaction rollback. Every `php artisan test` run was silently wiping the entire dev
  database. Caught in production use: it deleted a manually-granted ADMIN role (twice) and all seed
  data. Fixed by creating `cmis_testing` (`docker compose exec mariadb mariadb -uroot -p... -e "CREATE
  DATABASE cmis_testing; GRANT ALL ON cmis_testing.* TO 'cmis_app'@'%';"`) and pointing
  `phpunit.xml`'s `DB_DATABASE` override at it. Verified: dev `cmis` role/permission/item counts are
  unchanged after a full test run.
- Item search (FR-MD-07) uses `LIKE`, not the `ft_items` FULLTEXT index the schema defines — MySQL/
  MariaDB's default FULLTEXT parser tokenizes on whitespace, and Thai has none, so a name like
  "โซเดียมไฮดรอกไซด์" indexes as a single token and a partial query via `MATCH AGAINST` never matches it.
  The index stays in the schema (spec's DDL calls for it) but isn't what the search query actually uses.
- **AGENT RULE #9 violation**: `Item` and `Attachment` had no `getRouteKeyName()` override, so
  `/items/{item}/edit` and `/attachments/{attachment}/download` were addressed by auto-increment `id`,
  not ULID — a direct violation of spec's "public URL identifiers are ULIDs, never auto-increment IDs"
  rule. Caught during T-014 manual test setup, not by any automated check (PHPStan/Pint don't catch
  route-model-binding key choice). Fixed by adding `getRouteKeyName(): string { return 'ulid'; }` to
  both models; full test suite re-run afterward to confirm nothing broke (39/39 still passing). Every
  other route-bound model in the app should be audited against this rule as new ones are added.

### Changed

- Spec calls for Laravel 11, but Laravel 11 currently has unpatched security advisories (including one
  affecting signed URLs, used by this app for advisor approval links). Project runs **Laravel 13** instead,
  per user decision on 2026-08-31.
- CSP (`SecurityHeaders` middleware) now includes `'unsafe-eval'` in `script-src` and `'unsafe-inline'` in
  `style-src` (no nonce there) — spec §9.6 says never for `script-src`, but Livewire 3 is built directly on
  Alpine's eval-based expression engine (`wire:click`, `wire:model`, `x-data` all go through it), so there's
  no way to keep Livewire's reactivity under a strict no-eval CSP. User-approved 2026-08-31 after live
  browser testing showed `wire:click` itself failing, not just decorative Alpine usage. See CLAUDE.md.

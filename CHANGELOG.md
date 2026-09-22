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
- T-047: `ledger_snapshots` monthly job (performance). `ledger_snapshots` was already a real table since
  T-004 (same "check the migrations folder first" lesson as T-044's `notifications` table) — no new
  migration needed, just the model, service, and command. `LedgerSnapshotService::generateForPeriod()`
  computes one row per (item, calendar month): `closing_base` is always read directly off the **last
  `stock_ledger` row in that period** (BR-07's own running balance — the single source of truth), never
  recomputed as `opening + in − out`, so a snapshot can never drift from the ledger itself;
  `total_in_base`/`total_out_base` are informational sums only. `opening_base` chains from the previous
  month's `closing_base` when an earlier snapshot exists, or falls back to the last ledger row strictly
  before the period (so jumping straight to month 6 with no snapshots for months 1–5 still derives the
  correct opening balance from raw history). Every item with ledger activity up to the period, **or**
  with an earlier snapshot on record, gets a row for every later month even with zero movement — this
  keeps the monthly chain gap-free, unlike an item that has genuinely never been touched, which is
  skipped entirely (nothing to summarize). `php artisan ledger:snapshot {month?}` (Gregorian `YYYY-MM`,
  defaults to last calendar month) is idempotent via `updateOrCreate` on the schema's own
  `uq_snapshot(item_id, period_ym)`, scheduled `monthlyOn(1, '01:00')` in `routes/console.php`. Per the
  backlog's own literal title ("job", not "job + read-path integration"), nothing else in the app reads
  from `ledger_snapshots` yet — see CLAUDE.md. Verified: 7 service tests (first-ever snapshot, month-to-
  month chaining, a zero-movement month still snapshotting, an untouched item being skipped, jumping
  straight to a later month, idempotent re-run, `last_ledger_id` correctness) and 3 command tests
  (explicit month, invalid format failing cleanly, no-argument defaulting) — 10 new tests total — plus a
  full manual run against the real dev database's actual `stock_ledger` history (spanning 2025-06 to
  2026-09 across 19 items): generated August 2026 (1 item had pre-existing history, correctly derived
  its opening balance from before the period since no snapshot had ever been made), then September 2026
  (19 items), confirmed the chain carried August's closing into September's opening for that one item,
  and confirmed re-running September was a no-op (still exactly 20 rows, not 39).

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

## Phase 5 — Hardening & Release

### Added

- T-050: PDPA (SEC-PD-01..05). §5.2's own `users` DDL has no PDPA-specific columns at all (same class of
  gap as every other "the rule needs enforcing now" addition in this project) — added `privacy_consent_at`/
  `privacy_consent_version`/`pseudonymized_at` via migration. **SEC-PD-02** (Privacy Notice + consent on
  first registration): a new global `EnsurePrivacyConsent` middleware (prepended ahead of
  `EnsureRoleAssigned` in the `web` group) redirects any user whose `privacy_consent_at` is null, or whose
  `privacy_consent_version` doesn't match the currently published `config('privacy.notice_version')`, to
  `/privacy-notice` on every request except the notice itself and bare login/logout — consent applies
  regardless of what role a user ends up with, so `SsoCallbackController`'s post-login redirect now checks
  consent *before* the existing pending-role check. `PrivacyNoticeController::accept()` mirrors that same
  ordering afterward (a still-roleless user who just consented lands on pending-role, not a stray 403 from
  `EnsureRoleAssigned` on whatever `intended()` resolves to). **SEC-PD-03** (access/correction/copy rights):
  a new `/account/my-data` page shows the user's own profile fields plus their own requisitions, with a
  one-click JSON export (`/account/my-data/export`) satisfying the "copy of my data" right; both actions
  need no Policy class since they always operate on the currently authenticated user, never a route
  parameter naming someone else. **SEC-PD-04** (ledger data is unremovable evidence → pseudonymize past
  retention): `PseudonymizeUserService` + `php artisan users:pseudonymize {user}` overwrite a user's PII
  columns in place (full_name/username/email/phone/person_code/program/faculty) while leaving the row's
  `id` (and every FK that references it — `stock_ledger.created_by`, `requisitions.requester_id`, etc., all
  `ON DELETE RESTRICT`) fully intact; deliberately CLI-only with an interactive confirmation prompt, same
  reasoning as `ledger:verify`/`ledger:snapshot` (server access is the access-control boundary, not an
  in-app permission check) — there is no automatic scheduled trigger, since the actual retention period
  that decides *when* a user qualifies is still an open policy question (see CLAUDE.md). **SEC-PD-05**
  (breach response, 72-hour notification): `pdpa_breach_response_plan.md` at the project root (alongside
  the other root-level reference docs) — roles, detection channels, severity levels, the 8-step response
  procedure culminating in the PDPA-mandated 72-hour notification to Thailand's PDPC (สคส.), and required
  incident documentation. **SEC-PD-01** (collect only what's necessary) was a review pass, not new code —
  every column already collected traces to an actual feature (SSO identity, BR-11's requisition-creation
  fields, or operational history); no unused PII column was found to remove. Verified: 24 new tests across
  4 files (`PrivacyNoticeControllerTest`, `AccountDataControllerTest`, `PseudonymizeUserServiceTest`,
  `PseudonymizeUserCommandTest`) plus `SsoLoginTest` updated for the new consent-first redirect ordering;
  `UserFactory` now defaults every fixture to already-consented (with a new `->unconsented()` state for
  tests that exercise the gate itself) so none of the other 335 pre-existing tests needed touching. Full
  manual verification: the one real logged-in user (`wittaya.su`) organically hit the new consent gate
  live in their own browser session while this task was being built, consented, and continued working
  with no errors — confirmed via `audit_logs` (`PRIVACY_CONSENT` row, followed by normal activity, no
  403s). Separately verified `/account/my-data` and its JSON export against a fresh test user in an
  isolated session; caught and fixed one real bug this way (the requisitions table's status column was
  mislabeled "สถานะภาชนะ", a container-status label borrowed from the reports lang file, instead of the
  correct requisition-status label) that no automated test had caught, since the existing test only
  asserted the doc_no was visible, not the column headers.
- T-051: Accessibility audit, WCAG 2.1 Level AA (NFR-07). Spec's entire guidance on this requirement is
  one table row with no elaboration anywhere else in the ~1600-line spec — read as an AUDIT task rather
  than a "build X" task, so this ran a real automated scanner (axe-core 4.10.2, loaded same-origin via a
  temporary `public/` copy since the app's strict CSP blocks any cross-origin `fetch`) against every real
  page type in the app while manually logged in as fixture users covering every role. Found and fixed
  three systemic issues, none of which PHPStan/Pint/any prior manual testing had ever caught: **(1)
  missing form-label association** (WCAG 1.3.1/4.1.2/3.3.2) — every form field app-wide used a bare
  sibling `<label class="block ...">` immediately before its `<input>`/`<select>`, with no `id`/`for`
  pairing at all; fixed across 14 view files (~82 fields), using the requisition line's own PK as a
  unique id suffix on the one page where the labeled fields repeat inside a `@foreach` (`requisitions/
  issue.blade.php`) and `aria-label` in place of `for`/`id` on the one page where independent static forms
  share generic field names (`reports/index.blade.php`). **(2) scrollable regions not keyboard-focusable**
  (WCAG 2.1.1, axe rule `scrollable-region-focusable`) — every `overflow-x-auto`/`overflow-y-auto` wrapper
  around a wide table or a tall scrolling list (GHS statement pickers, the Privacy Notice's own scrollable
  body) had no `tabindex`, so a keyboard-only user could never scroll it; fixed with `tabindex="0"` across
  16 occurrences in 15 files, deliberately not adding `role="region"`/`aria-label` — scoped to exactly what
  the literal WCAG SC and axe's rule require. **(3) insufficient color contrast** (WCAG 1.4.3, axe rule
  `color-contrast`) — the shared Tailwind design token `ink.faint` (`#85769D` on white) measured only
  4.13:1, short of the 4.5:1 minimum for normal-size text; since every "faint helper text" label app-wide
  uses this one token, the fix was a single value change in `tailwind.config.js` (`#85769D` → `#786A8D`,
  ~4.95:1, a deliberately comfortable margin above the 4.5:1 floor rather than a bare pass) plus an asset
  rebuild (`npm run build` — no Node/Vite container exists in this project's Docker Compose setup, so this
  ran from the host, which already had Node installed). Verified: added `AccessibilityStructureTest` (2
  tests) as a lightweight structural regression guard — one statically scans every `.blade.php` file for
  an `overflow-x-auto`/`overflow-y-auto` tag missing `tabindex`, the other hits the richest real form
  (`/items/create`) and asserts every visible `<label for="...">` has a matching `id="..."` in the
  response — plus a full live-browser axe re-scan (0 violations) across every page type reachable by every
  role: item create/edit/show/ledger, GRN create/show, requisition create/show/issue/approve, stock take
  create/scan/show/index, disposal create/show/index, adjustment create/index, admin users/labs, reports,
  dashboard, privacy notice, my-data, pending-role, complete-profile. No Pest tests existed for this class
  of issue before, since none of the app's prior 359 tests ever render-and-scan a full page's HTML
  structure the way this task's audit needed. Full suite: 361/361 passing, PHPStan level 8 clean, Pint
  clean, `composer audit` clean.
- T-052: Load test, 100 concurrent users (NFR-01, NFR-02). Both halves of this task surfaced real,
  previously-unverified infrastructure gaps rather than confirming existing numbers — see CLAUDE.md for
  the full detail; summary below.
  **NFR-02** ("F-03 ledger PDF, 100,000 rows, <15s, via Queue + notify-on-completion") was flagged as
  entirely unbuilt after T-045/T-047. Built: `ledger_export_requests` (tracks each queued export: item,
  requester, filter snapshot, status, file path), `GenerateLedgerPdfExportJob` (ShouldQueue), and
  `LedgerExportController::pdf()` now transparently routes to it once a filtered ledger exceeds 5,000 rows
  (below that, unchanged synchronous behavior). The export's status/download page (`/ledger-exports/{ulid}`)
  is a genuinely user-owned, URL-addressed resource gated by a new `LedgerExportRequestPolicy` — the first
  real target for the IDOR test (ST-04) T-017 deferred for lack of one; that test now exists. On
  completion (or failure) the requester is notified in-app + by email via the existing `NotificationService`.
  A real 100,000-row measurement during this task found `Fr03PdfService`'s HTML/CSS-table renderer
  (`WriteHTML()`, unchanged since T-025) costs ~1.9ms and ~90KB *per row* — 100,000 rows would take ~190s
  and ~9GB, nowhere near the 15s target, regardless of chunking or available memory (mPDF's own per-row
  layout-engine state, not the input HTML string, is what scales badly). **Rewrote the renderer to draw
  the table body with mPDF's raw `Cell()` API instead of `WriteHTML()`** (title/item-info block stays
  HTML — it's small and fixed-size) — this bypasses the HTML/CSS parser entirely for the part that scales
  with row count. Re-measured: 100,000 real rows now render in **9.88s at 761MB peak** (the queued job
  raises its own memory_limit to 1536M — safe since it never runs on the request path). Verified via a new
  `LedgerAsyncExportTest` (threshold routing, job execution + notification, the IDOR check, and the
  download flow) plus the existing `Fr03ExportTest` (unaffected — same rows/headings/filters, just drawn
  differently). Two real MariaDB gotchas hit and fixed while adding the new table: `foreignId()->
  constrained('units')` fails with errno 150 (`units.id` is `smallIncrements`/smallint, not bigint —
  needed `unsignedSmallInteger()` + explicit `foreign()`, same shape as every other `unit_id` FK in the
  schema); and a mid-`CREATE TABLE` FK failure leaves a **partially-created table** behind (not rolled
  back — DDL auto-commits), which then also blocks the *next* attempt with "table already exists" until
  dropped by hand. Every new table still needs `docker/mariadb/restrict_app_grants.sql` re-run afterward
  (already documented) — but doing so only right after `cmis`'s own migration missed `cmis_testing`, since
  that database doesn't get the new table until the test suite's own first `RefreshDatabase` migrate;
  the fix is running the grants script a second time once that's happened at least once.
  **NFR-01** ("general pages <2s P95 at 100 concurrent users") had never been tested at all. Built a
  100-distinct-session load-testing setup (couldn't use a real institutional SSO login for 100 fake users,
  so — same temporary, always-removed pattern already used for manual verification throughout this
  project — a `/dev-login/{user}` route captured real session cookies for 100 throwaway `STAFF` users,
  fed into a small Node script firing genuinely concurrent requests at `/`, `/items`, `/requisitions`).
  First attempt, against this project's only existing server (`php artisan serve`, the `app` service),
  failed badly and for a very specific reason: server-side request handling was fast (~0.1ms per the
  server's own request log) but the *client* saw 6–13 seconds per request — the bottleneck was the dev
  server's own connection-handling path (and, separately, that `php artisan serve`'s multi-worker mode
  gives each worker process its own *unshared* OPcache, so literally the first request to a still-cold
  worker pays a multi-second compile tax no production server would). **This project had never had a
  production-representative web server at all** — added one: `docker/php-fpm/` (a second PHP image,
  `php-fpm` base instead of `cli`, `pm.max_children=60`, OPcache shared across all workers via the pool's
  own shared memory — this is what actually fixes the cold-worker tax) and `docker/nginx/` (a standard
  Laravel `fastcgi_pass` server block), wired into `docker-compose.yml` as new `fpm`/`nginx` services on
  a new port (`FORWARD_NGINX_PORT`, default 8091) — additive only; every existing `docker compose exec
  app ...` dev/test workflow is completely untouched. Re-ran the same 100-concurrent-user test against
  nginx+fpm: **P95 355ms** across 900 requests (100 users × 3 rounds × 3 pages), 0 errors — comfortably
  under the 2s target. **A real workflow trap found along the way**: running `php artisan optimize`
  (config/route/view caching) to make this comparison fairer against a "production-like" config silently
  broke the *next* `php artisan test` run (78 failures, all CSRF 419s) — a cached config freezes every
  `env()` value at cache time, so `phpunit.xml`'s runtime env overrides (`APP_ENV`, `DB_DATABASE`) stop
  taking effect and `PreventRequestForgery`'s `runningUnitTests()` self-disable check silently flips.
  Fixed by `php artisan optimize:clear`; full suite back to 366/366 immediately after. **Any future session
  that runs `artisan optimize`/`config:cache` for its own reasons must run `optimize:clear` before the
  next `php artisan test`** — this won't show up as a config error, only as unrelated-looking test
  failures. Manual DB cleanup after this task: 100 throwaway load-test users and 9 throwaway
  ledger-export-timing items, all deactivated (`is_active = false`, never deleted per AGENT RULE #6/the
  project's own established convention) — the 9 timing items between them left **448,000** permanent
  `stock_ledger` rows in the dev `cmis` database (append-only, cannot be removed), a much larger volume
  than any prior task's test data for the same reason. Full suite: 366/366 passing (2 new tests added
  net of the load-testing/timing work, which used ad hoc scripts, not permanent Pest tests), PHPStan
  level 8 clean, Pint clean, `composer audit` clean.
- T-053: OWASP ZAP baseline scan (spec §14 AC #10 — must find no Medium+ risk alerts). Ran
  `zaproxy/zap-stable`'s `zap-baseline.py` (Docker, joined to the compose network) against the
  production-representative nginx+PHP-FPM stack T-052 built. First run found 3 Medium-risk alerts.
  **Fixed for real**: a completely unmatched path (no route at all) never enters the `web` middleware
  group — there's no route to attach it to — so `SecurityHeaders`/`ForceHttps` never ran and a 404 like
  `/sitemap.xml` came back with no CSP header at all. Added `Route::fallback(fn () => abort(404))` to
  `routes/web.php`, which — being defined in that file — automatically gets the same `web` group
  middleware as every real route; a regression test now covers this directly. **Waived, with a
  documented reason** (user-approved 2026-09-09): the other 2 Medium alerts are both the CSP
  `unsafe-eval`/`unsafe-inline` exception already approved 2026-08-31 for Livewire 3/Alpine.js (see
  CLAUDE.md's original CSP note) — not a new bug, the same accepted trade-off ZAP is now (correctly)
  flagging on its own. `docker/zap/baseline.conf` records the waiver against the specific ZAP rule ID
  with its justification, so the scan stays honest about what's excluded and why rather than silently
  passing. Also fixed, while investigating, several Low-risk findings that were free wins: nginx's own
  version string (`server_tokens off`), PHP-FPM's `X-Powered-By` header (`expose_php=Off`), and missing
  hardening headers (`X-Content-Type-Options`, `Permissions-Policy`, COEP/CORP) on static assets served
  directly by nginx, which — like the fallback-route gap — never reach Laravel's own middleware. Final
  scan: 0 Medium+ findings outside the one documented waiver, 367/367 tests passing, PHPStan level 8
  clean, Pint clean, `composer audit` clean.
- T-054: Backup + restore drill (NFR-09 — "Full daily + binlog ต่อเนื่อง, RPO ≤ 15 นาที, RTO ≤ 4
  ชม."). Enabled continuous binary logging on MariaDB (`docker/mariadb/conf.d/backup.cnf` — ROW
  format, `sync_binlog=1`), added a full-backup script (`mariadb-dump --master-data=2`, embedding the
  binlog position active at dump time — the join point for point-in-time recovery) and a restore-drill
  script that replays binlog into an isolated `cmis_restore_drill` database (via `mariadb-binlog
  --rewrite-db`) rather than the live one, so the mechanism can be verified with zero risk to real
  data. Ran the drill for real: took a full backup, made a tracked change immediately after, then
  proved two things empirically — (1) restoring to a point *after* that change correctly includes it
  (recovers work made since the last backup), and (2) restoring to a point *before* it correctly
  excludes it (genuine point-in-time precision, not "replay everything available"). Documented in
  full, including a real day-of incident, in `docs/backup_restore_runbook.md`.
  **Enabling binlog broke every migration that creates a trigger** (`stock_ledger`'s append-only
  triggers, T-017) — MariaDB refuses `CREATE TRIGGER` for any DB user without the SUPER privilege
  once binary logging is on (error 1419), and `cmis_app` deliberately doesn't have SUPER (T-027).
  Fixed with `log_bin_trust_function_creators=1`, MariaDB's own documented alternative for exactly
  this case — safe here since both triggers are a plain deterministic `SIGNAL`, nothing that could
  diverge on a replica. Full suite (367 tests) failed everywhere until this was found and fixed;
  green again afterward.
  **A real, unplanned recovery happened the same day**: while chasing that test failure, a `php
  artisan migrate:fresh --env=testing --force` was run assuming `--env=testing` would redirect the
  connection to `cmis_testing` — it didn't (no `.env.testing` file exists in this project, so the
  flag is a no-op), and the command wiped the live dev `cmis` database instead. Recovered for real
  using this task's own mechanism: the full backup taken minutes earlier, restored into a freshly
  recreated `cmis`, then binlog replayed up to (but excluding) the exact byte position where the
  accidental drop began — found via Laravel's own "generated by server" comment tag on every table
  its schema builder drops for `migrate:fresh`/`db:wipe`, which made the accidental event
  unambiguously identifiable in the binlog stream. User-approved before any destructive step was
  taken. Separately discovered while verifying the recovery: the backup itself (independent of the
  restore process) already lacked the project's real historical data (no ADMIN user, minimal rows) —
  meaning that data was lost at some earlier, still-unidentified point before this task even started,
  not because of this incident. User-deprioritized investigating that further for now; see CLAUDE.md
  and the runbook's own "Known open items" for what's still open (no admin user currently exists;
  root cause of the earlier loss unknown; backups aren't shipped off-host yet; RTO only validated
  against a near-empty dataset). Full suite: 367/367 passing, PHPStan level 8 clean, Pint clean,
  `composer audit` clean.
- T-055: Documentation — IIS installation guide, user manual, admin manual. Three new Thai-language
  docs (matching `sso_integration_guide.md`'s existing style — Thai prose with English technical
  terms inline), no code changes. `docs/iis_installation_guide.md` covers the real deployment target
  spec §4.1 actually names (IIS 10 / Windows Server 2022 / FastCGI), which this project's Docker
  Compose setup never touches — directory layout, MariaDB/Redis/PHP-FPM install steps, `web.config`
  (already present in the repo, verified identical to spec §9.6's own sample), Task Scheduler in
  place of cron (Laravel scheduler + a Windows-service-wrapped queue worker via NSSM, since
  `queue:work` needs to run continuously, which Task Scheduler alone doesn't suit), backup scheduling
  (referencing T-054's runbook), and a go-live security checklist. Flags two real, unresolved
  deployment gaps rather than glossing over them: **Redis has no native Windows build** (spec names
  Redis 7, but Windows Server needs Memurai, WSL2, or a separate host — not decided here, since it's
  a real infrastructure choice someone has to make) and **SEC-DB-01's 3-separate-DB-users design was
  never built** (T-027 only restricted the single `cmis_app` connection's grants — already a known
  gap, repeated here since it matters most at actual deployment time). `docs/user_manual.md` and
  `docs/admin_manual.md` were written directly against the real routes/permissions/lang strings
  (`php artisan route:list`, `PermissionSeeder`, `lang/th/*.php`) rather than guessed — every claim
  about what a screen does or what text it shows was checked against the actual code before being
  written down; one draft error caught this way: disposal reason `WASTE` labels as "ของเสีย", not
  "ใช้หมด" as first guessed, fixed before finalizing.
- T-056: UAT test plan + penetration testing. Delivered `docs/uat_test_plan.md` (per-role end-to-end
  scenarios mapped to spec §14's acceptance criteria, for real human testers — explicitly not
  something this task could run itself) and `docs/penetration_test_report.md`, scoped honestly as an
  agent-conducted security review, not a substitute for a licensed third-party pentest before real
  PII is processed. Ran OWASP ZAP's **active scan** (`zap-full-scan.py`, real attack payloads — SQLi
  across 5 database engines, XSS, SSRF, SSTI, XXE, RCE including Log4Shell/Spring4Shell/Text4Shell,
  command injection, path traversal, and more) against the nginx+PHP-FPM stack: 132 rules passed, 0
  new confirmed vulnerabilities. Of 4 Medium-risk alerts, 2 were the already-approved CSP exception
  (T-053), 1 ("Bypassing 403", `X-Original-URL` header) was investigated and confirmed a false
  positive by hand (the header has zero effect on this app's routing — verified against both a
  protected and a public route with/without it), and 1 ("HTTP Only Site") is expected for this
  TLS-less local Docker environment and must be re-checked against the real HTTPS production URL
  before go-live. Manually verified (via direct HTTP requests bypassing the UI, using each role's own
  real CSRF token) every business-logic authorization case a generic scanner can't reason about: ST-04
  (IDOR — a second student cannot open another student's requisition by URL), ST-05 (a PHP webshell
  renamed `.pdf` is rejected, no attachment record created), ST-10 (STUDENT/SCIENTIST/LAB_MANAGER/
  AUDITOR all correctly 403 on every endpoint outside their role, including direct POSTs to
  write endpoints, not just GET on the create page), and ST-10b (a logged-in, roleless user can reach
  only the pending-role page). **Found and fixed a real availability bug along the way**: the `app`
  container's operations run as root and write to the same bind-mounted `storage`/`bootstrap/cache`
  the `fpm` container's `www-data`-owned worker also needs to write to — once `app` touches those
  paths, `fpm` loses write access and every request needing to log or compile a view (including the
  `/up` health check) 500s with no clear error. Fixed with `chown -R www-data:www-data storage
  bootstrap/cache`; documented as a recurring gotcha, not a one-time fix, since any future `docker
  compose exec app ...` write will reintroduce it. Full suite: 367/367 passing, PHPStan level 8 clean,
  Pint clean, `composer audit` clean — this task added no application code, only docs and a ZAP
  waiver-config entry.
- Multi-branch (lab-scoped) access control: split the warehouse's access model by branch
  (`labs`, already CRUD-able), giving each branch a LAB_MANAGER whose actions and views are
  now scoped to their own `users.lab_id` — a column that has existed since project init but
  was completely unused by any code until now. Requisition creation now snapshots `lab_id`
  from the requester's own profile (never user-selectable); a requester with no branch
  assigned is redirected to a new "pending branch assignment" page instead of the create
  form. ADMIN gained a branch (`lab_id`) selector per user in `UserRoleManager`
  (`admin.users.index`), and LAB_MANAGER gained a new own-branch member whitelist page
  (`/labs/members`, `lab.manage_members` permission) restricted to the STUDENT/STAFF roles
  that actually hold `requisition.create` — a manager can add an unassigned user or remove
  one already in their own branch, never poach a member of a different branch. Every
  existing LAB_MANAGER action that previously worked system-wide is now scoped to the
  approver/actor's own branch: adjustment approval (`AdjustmentService`), disposal decisions
  (`DisposalPolicy`), location create/update (`LocationPolicy`/`LocationRequest`), and the
  BR-04 overage-issue approver check (`IssueService`) — each throws/403s when a LAB_MANAGER
  acts outside their own branch, but is a no-op (unchanged behavior) when the resource has
  no resolvable lab at all (e.g. a container with no `location_id`), since there's no other
  branch to conflict with. LAB_MANAGER's read-only views are scoped the same way: the
  requisition list/detail (`requisition.view_all`), the per-item ledger (`ledger.view`,
  including its PDF/Excel exports and the async export job), and all six §7.8 reports
  (forced server-side, never trusted from the query string) — a container/ledger row with
  no resolvable lab is hidden from a lab-scoped view rather than shown. SCIENTIST/AUDITOR,
  who share several of these same permission codes, are entirely unaffected (scoping is
  gated on holding the LAB_MANAGER role specifically, via `User::hasRole()`). Explicitly
  out of scope: `item.manage` stays global — Items are a catalog with no `lab_id` column at
  all — and the location tree's own listing page stays unscoped (a hierarchy where only
  some levels carry a `lab_id` doesn't filter cleanly without breaking the tree). New test
  coverage across adjustments,
  disposals, locations, requisitions, the item ledger, and the two new Livewire components.
  Full suite: 385/385 passing, PHPStan level 8 clean, Pint clean, `composer audit` clean.
- Windows Server IIS Deployment & SSO CA Bundle Fix:
  - Configured IIS Application pointing to `public/` directory (`C:\inetpub\wwwroot\CMIS\public`) to ensure source code, configs, and dependency manifests outside `public` cannot be accessed directly via HTTP.
  - Removed root `index.php` and root `web.config` in favor of standard `public/web.config`.
  - Configured Livewire 3 subpath routing (`AppServiceProvider.php` + `config/livewire.php`) to dynamically handle subfolder deployments (`/CMIS/livewire/livewire.js` and `/CMIS/livewire/update`).
  - Resolved cURL error 60 (SSL certificate problem) on Windows by bundling CA certificate (`storage/certs/cacert.pem`) and wiring `ca_bundle` option into `SsoClient` and `config/services.php`.
  - Fixed item creation when `reorder_point_base` is omitted, adding default fallback in `ItemRequest` and `Item` model, and set IIS `httpErrors` to `PassThrough` to prevent IIS from turning 500 errors into 404s.
  - Streamlined item creation/edit form: removed unused density (`density_g_per_ml`), reorder point (`reorder_point_base`), and sub-unit fields; unified unit selection into a single "หน่วยนับ" field; added `expiry_date` column to `items` table with date picker support in the form.
- Reconciliation after pulling 13 commits from the server-side branch (working-stock replenishment +
  single-step requisition + IIS docroot fix + item form rework) — 5 fixes needed to get back to fully
  green: (1) the new stock-in item-search dropdown (`stock-in/create.blade.php`) was a scrollable
  `overflow-y-auto` region missing `tabindex="0"`, caught by the existing `AccessibilityStructureTest`
  regression guard (T-051) — added it, matching every other scrollable region in the app. (2)
  `ItemGhsTest`'s create-flow test still expected the old post-save redirect target (`items.edit`); the
  new `ItemController::store()` redirects to `items.index` instead — updated the assertion to match.
  (3) `ScientistDecisionTest`'s BR-02 test still expected the old rule (scientist blocked from approving
  a STUDENT requisition with no advisor sign-off) — that rule was intentionally removed by the
  single-step-requisition change (the server side's own `ApprovalServiceTest`/`RequisitionStateTest`
  were already updated to match; this HTTP-layer test wasn't) — flipped it to assert the new, intended
  behavior (scientist approves directly), same pattern the server side used. (4) Making
  `items.base_unit_id`/`package_unit_id` nullable (to support stock-in quick-adding a brand-new item)
  surfaced 3 PHPStan errors in `AdjustmentService`/`DisposalService`/`StockTakeService`, all the same
  shape: each passes `$item->base_unit_id` straight into `LedgerEntryData::$displayUnitId` (a plain
  `int`), which no longer typechecks now that the property is `int|null`. A container that physically
  exists to be adjusted/disposed/counted must have been received against an item that already had a
  base unit assigned, so each site got an explicit `if ($item->base_unit_id === null) { throw new
  RuntimeException(...); }` guard (this project's established explicit-narrowing pattern — see
  `DeadStockExport::labNameFor()` for the precedent) rather than silently trusting a `?->`/`??`. Also
  added the missing `@property int|null $base_unit_id`/`$package_unit_id` docblock overrides to
  `Item.php` (the existing `@property numeric-string $reorder_point_base` docblock already established
  this pattern for the same class). Full suite: 374/374 passing, PHPStan level 8 clean, Pint clean,
  `composer audit` clean.
- Restored the multi-branch (lab-scoped) access control feature (previously stashed) on top of the
  merged working-stock/single-step requisition codebase — applied cleanly via `git stash pop` (auto-
  merge, no conflicts) since nothing about the approval-flow simplification touched the lab-scoping
  code paths. Extended it to cover the new stock-in flow, which didn't exist when the feature was
  first built: `StockInController::create()` now only lists a LAB_MANAGER's own branch's locations,
  and `StockInRequest` rejects a submitted `location_id` outside their own branch (`lab_id` mismatch)
  — same pattern as `LocationRequest`'s existing create-time check. Without this, working-stock
  replenishment (now the primary way stock enters the system, GRN having been removed) would have been
  the one write path left completely unscoped, undermining the whole feature's point. Full suite:
  395/395 passing, PHPStan level 8 clean, Pint clean, `composer audit` clean.
- Chemical catalog bulk import (`php artisan chemicals:import`): curated a raw ~8,225-row export from
  the central-store system (`download.csv`, item codes prefixed "AS") down to `database/data/
  chemicals_import.csv` (5,261 rows) at the user's direction — item_code kept verbatim as the "AS" code
  (so it stays reconcilable against the central store), filtered to real chemicals/lab reagents only
  (drug products, dental materials, cosmetics/consumer goods, and Thai/Chinese herbal-medicine raw
  materials excluded — a genuine judgment call given the source data had no category column at all;
  see CLAUDE.md), 23 exact-duplicate-name rows dropped (kept the higher/likely-newer AS code in each
  group), and free-text quantity/unit/CAS/grade/molecular-formula parsed out of each name string into
  real columns (~92% of rows got a clean quantity+unit match; the rest import with those two fields
  left null rather than guessed). Added a new `ug` (microgram) unit (`UnitSeeder`, `factor_to_base =
  0.001`, `mg` stays the MASS dimension's base — this is purely an additional non-base unit, so no
  existing quantity anywhere in the app changes meaning) since ~99 rows used it and the system had no
  unit below `mg`. `ImportChemicalsCommand` is idempotent (skips an `item_code` already present, never
  overwrites) so it's safe to re-run after manually fixing any of the rows the parser couldn't confidently
  handle. Full suite: 399/399 passing (4 new tests for the command), PHPStan level 8 clean, Pint clean,
  `composer audit` clean.
- PubChem (NIH) chemical lookup — two features on top of the same read-only `PubChemClient`
  (`app/Domain/Chemicals/Services/PubChemClient.php`, no API key required): (1) an "auto-fill from
  PubChem" button on the item create/edit form (`resources/views/items/form.blade.php`) that fetches
  molecular formula and GHS pictogram/H-statement/P-statement checkboxes by CAS no. or name and fills
  the form fields for the user to review — never auto-saves, per the user's explicit direction; and
  (2) a standalone `/chemicals/lookup` page (gated on a bare `item.view` permission, same as
  `ReportController`'s convention — no dedicated Policy) for procurement research that doesn't touch
  the `items` table at all. Both go through `ChemicalLookupController::lookup()`, a single JSON
  endpoint. Every PubChem lookup is cached 30 days (`Cache::remember`) since a compound's data is
  effectively static; a network failure or "not found" returns `null` rather than throwing, so a
  PubChem outage degrades to "fill it in yourself," never a broken page. GHS codes/H-statements/
  P-statements returned by PubChem are filtered against this app's own `config/ghs.php` reference
  table before being handed back — a code PubChem knows that our own T-015 reference data doesn't
  (confirmed empirically: PubChem's own official P265 wasn't in our table) is silently dropped rather
  than failing `ItemRequest`'s validation. Full suite: 410/410 passing (11 new tests, `Http::fake()`
  throughout — no real network calls in the test suite), PHPStan level 8 clean, Pint clean, `composer
  audit` clean.
- Purged cancelled chemical entries (`*ยกเลิก*` / `*ยกเลิกไปใช้ AS...*`): identified and removed 12
  cancelled items (e.g. `*ยกเลิก*Methanol HDPE`, `*ยกเลิกไปใช้ AS194688* Myo-Inositol`) from both the
  active `items` database table and `database/data/chemicals_import.csv` (total count reduced from 5,261
  to 5,249 entries). Confirmed all 12 items had 0 containers, 0 stock ledger records, and 0 requisition
  references prior to deletion. Updated `ImportChemicalsCommand` with an explicit filter to skip rows
  containing "ยกเลิก" in name or raw_name on future imports, and added unit test coverage for the filter.
- Chemical registry PubChem synchronization (`ChemicalSyncService`):
  (1) 1-Click Sync & Add to Registry directly from the PubChem lookup page (`/chemicals/lookup`),
  automatically generating the next sequential `item_code` (`CHM-xxxxx`) and populating formula,
  molecular weight, GHS pictograms, and H/P statements without manual form retyping.
  (2) In-lookup detection of already-registered chemicals matching CAS No. or name, displaying a direct
  link to the existing item and a one-click "Sync & Update" action.
  (3) On-demand "⚡ ซิงค์ข้อมูลกับ PubChem" button on item detail pages (`/items/{item}`, `POST /items/{item}/sync-pubchem`)
  allowing lab managers to refresh/enrich an existing chemical's GHS codes, H-statements, P-statements,
  and formula straight from PubChem.
  (4) Registry search fallback on `ItemTable` (`/items`) displaying a direct search link to PubChem
- Hardened PubChem registry synchronization & concurrency safety:
  (1) Replaced naive regex item code generation with `DocumentNumberGenerator` (`CHM-{YYYY}-{NNNNN}`)
  backed by atomic `lockForUpdate()` on `document_counters` and collision-detection fallback loop,
  ensuring BR-09 fiscal-year format consistency and race-free concurrent generation.
  (2) TOCTOU duplicate prevention: `createFromPubChem()` checks existing `cas_no` inside the DB
  transaction with `lockForUpdate()` before inserting, preventing duplicate rows when multiple users click sync.
  (3) Safety & GHS traceability: `syncItem()` captures before/after states and writes an entity-level
  `AuditLog::record()` with action `PUBCHEM_SYNC`, tracking changes to formula, GHS pictograms, H/P statements,
  and specifications.
  (4) Policy authorization enforcement: replaced loose string permission gates in `PubchemLookup`
  and its Blade view with strict `ItemPolicy` methods (`viewAny`, `create`, `update` with target `$item`),
  satisfying AGENT RULE #7 and preventing privilege escalation.
  (5) Explicit `base_unit_id` handling: left as `null` per CMIS working-stock convention, backfilled on initial GRN.
  (6) Refined duplicate matching: prioritized exact CAS No. matching with case-insensitive exact name fallback
  to avoid false positive/negative matches. Added comprehensive unit and feature test coverage; PHPStan level 8
  and Pint clean.
- Fixed pagination reset when searching in Livewire tables (`ItemTable`, `UserRoleManager`, `LabMemberManager`):
  Added `updatedSearch(): void { $this->resetPage(); }` and trimmed search input. Previously, if a user
  browsed to a later page (e.g. page 7+) of the registry and then searched for a term like "Alcohol" (which has
  108 results across 6 pages), Livewire stayed on page 7+, causing it to display 0 results and show the empty
  state ("ไม่พบรายการที่ค้นหา"). Also added `#[Url]` sync on `$search` in `ItemTable` and regression test.
- Batch PubChem synchronization command (`php artisan chemicals:sync-pubchem`):
  Added `SyncPubChemChemicalsCommand` enabling automated batch enrichment of chemical items from PubChem.
  Defaults to syncing items with valid `cas_no` that do not yet have GHS data, with configurable rate limiting
  (`--delay=250` ms to strictly respect NIH PubChem's 5 req/s policy), optional `--limit=`, `--force`, and `--all` flags,
  streaming via Eloquent cursor, CLI progress bar, comprehensive summary table, and entity-level `AuditLog` records.
  Includes full feature tests (3/3 passing), PHPStan level 8 clean, Pint clean.
- Chemical name sanitization for PubChem synchronization (`ChemicalNameSanitizer`):
  Added `ChemicalNameSanitizer` service that extracts embedded CAS numbers and cleans chemical names by
  stripping concentration percentages (e.g. `95%`, `99.9%`, `70% v/v`), parenthesized notes/formulas,
  Thai script, and commercial/grade keywords (`grade`, `liquid`, `solid`, `com`, `hdpe`, `emsure`, etc.)
  before querying PubChem. Integrated into `ChemicalSyncService` as a multi-tier fallback (CAS -> extracted CAS ->
  exact name -> sanitized name) and into `PubchemLookup` Livewire component with automated query adjustment and UI hint.
  Includes unit and feature tests (5 new tests), PHPStan level 8 clean, Pint clean.
- Procurement-usable specification text (user-requested, 2026-09-15): `items.specification` was only ever
  getting a thin "MW: X g/mol (PubChem CID: Y)" line from a sync; user asked for something a procurement
  officer could actually use. `PubChemClient` now also fetches PUG View's "Physical Description" section
  (Chemical and Physical Properties > Experimental Properties) — this field is literature-mined from
  several source agencies per compound and is often noisy (raw semicolon-joined category dumps like
  "Liquid; Wet Solid; CBI; ...", trailing citation tags like "; [NIOSH]", or descriptions of an unrelated
  formulation like "...adulterant added so as to be unfit for use as a beverage" for ethanol) — confirmed
  empirically against real compounds (ethanol CID 702, sodium chloride CID 5234) before writing the
  filter, not guessed. `cleanPhysicalDescription()` strips the trailing citation and discards anything
  with 2+ semicolons, outside a 10-160 char range, or matching a small blocklist (`adulterant`,
  `denatured`, `unfit for`, `CBI`) — first candidate to survive wins, `null` (never a guess) when nothing
  does. Costs one extra PUG View request per lookup (PUG View's `heading` filter only honors a single
  value — confirmed empirically that passing two `heading` params returns just the first — so this
  couldn't be combined with the existing GHS request without pulling the *full* compound record, which
  measured ~30x larger for ethanol (3.4 MB vs 115 KB) and was rejected on that basis); still well within
  NIH's 5 req/s policy given the existing 30-day cache means most compounds only ever pay this once.
  `ChemicalSyncService::buildSpecificationParagraph()` assembles one flowing line — molecular formula, MW,
  CAS No., physical description (English, left untranslated — same T-015 precedent for not inventing a
  Thai translation of literature text), and `grade` **only when the item already has one** (from manual
  entry or the original catalog import's parsing) — PubChem has no concept of commercial grade/purity at
  the compound level, so none is fabricated. `mergeSpecification()` replaces this PubChem-derived line in
  place on every re-sync (matched by its own `(PubChem CID: N)` marker) rather than appending a duplicate
  each time, leaving any other free text already in the field (e.g. imported raw name/packaging notes)
  untouched above or below it. Verified: 2 new `PubChemClientTest` cases (the exact noisy-vs-clean ethanol/
  NaCl fixtures above), 3 new `ChemicalSyncServiceTest` cases (full paragraph assembly including grade,
  idempotent in-place replacement across two syncs, `createFromPubChem`'s new-item wording), PHPStan level
  8 clean, Pint clean. Running the actual backfill (`chemicals:sync-pubchem --force --all`) against the
  full imported catalog is a separate, deliberate step — not run automatically as part of this change.
- AI-assisted chemical specification generator (`ChemicalSpecificationAiService`):
  Integrated KKU GenAI Gateway (OpenAI-compatible) with `gemini-2.5-flash-lite` to automatically draft
  laboratory procurement specifications (appearance/physical state, standard grades like AR/ACS/Technical,
  and packaging/storage requirements):
  (1) "✨ ร่างสเปกด้วย AI" button on the item create/edit form (`resources/views/items/form.blade.php`),
  calling `POST /items/ai-specification` with client-side loading indicator and inline textarea population.
  (2) Hallucination prevention: prompt explicitly restricts the LLM from inventing specific purity numbers/grades
  and instructs purchasers to specify based on actual laboratory usage; prepends a clear `[ร่างโดย AI — โปรดตรวจสอบความถูกต้องและระบุเกรดที่ต้องการก่อนนำไปใช้จัดซื้อจริง]`
  disclaimer banner on all generated specifications.
  (3) Robust security & authorization: gated via `ItemPolicy::create` (`$this->authorize('create', Item::class)`)
  requiring `item.manage` permission (preventing read-only users from draining external API quota), paired with
  `GenerateAiSpecificationRequest` validation and `throttle:10,1` rate limiting middleware.
  (4) Batch generation command (`php artisan chemicals:ai-generate-specs`): allows bulk population of missing
  specifications with configurable `--limit=`, `--delay=`, `--dry-run`, and audit trail via `AuditLog::record(action: 'AI_SPEC_GENERATE')`.
  (5) Production resiliency: 30-day success-only caching (`Cache::remember`), SSL CA bundle verification via
  `services.ai_gateway.ca_bundle`, and graceful degradation returning null on network or API failures.
  Includes 12 new feature tests (all passing), PHPStan level 8 clean (0 errors), Pint PSR-12 clean.
- AI specification prompt refined to differentiate raw chemical reagents from finished medical/pharmaceutical
  solutions (e.g. NSS 0.9%) via few-shot examples — the reagent-grade template (AR/ACS/Technical) was being
  applied even to formulated products where it doesn't apply; the corrected prompt now asks for pharmacopoeia
  standards (USP/BP) and sterile/pyrogen-free wording for that class instead. Cache key bumped to `v3` to
  invalidate specs generated under the old, undifferentiated prompt.
- `chemicals:ai-generate-specs`'s default (non-`--force`) scope widened to also include items whose
  `specification` already has non-AI content (e.g. from the PubChem sync above, or the original catalog
  import) but has never received an AI draft — previously only `NULL`/empty rows were picked up, silently
  skipping every already-populated item forever.
- **Fixed a real data-loss bug found while merging the above with the PubChem-sync specification work**:
  both `GenerateAiSpecificationsCommand` and the "✨ ร่างสเปกด้วย AI" button's own JS were writing
  `specification = <AI draft>` outright — on an item that already carried real, verified facts (formula/
  MW/CAS/physical description from `ChemicalSyncService`, or raw name/packaging from the catalog import),
  running the AI generator silently destroyed all of it, leaving only the unverified AI text behind.
  Confirmed via `tinker` before fixing: a real PubChem-derived specification was completely gone after
  one `generateSpecification()` call. Added `ChemicalSpecificationAiService::mergeIntoSpecification()` —
  same discipline as `ChemicalSyncService::mergeSpecification()`'s `(PubChem CID: N)` marker: appends the
  AI block below whatever's already there the first time, then replaces just its own previous block
  (found via the `SPEC_PREFIX` marker) in place on every later re-run, never touching content that isn't
  its own. `GenerateAiSpecificationsCommand` and the client-side JS in `items/form.blade.php` both now go
  through this merge instead of a bare assignment. Needed one companion fix: the command's own "already
  drafted, skip it" default-scope check (`specification NOT LIKE '[ร่างโดย AI%'`, added in the previous
  commit) only matched the marker at the very *start* of the field — now that the AI block can legitimately
  sit *after* other content, that check is `NOT LIKE '%[ร่างโดย AI%'` (anywhere in the field) instead, or
  every merged item would be endlessly reprocessed on every non-`--force` run. Verified: 3 new
  `mergeIntoSpecification()` unit tests (append when empty, replace-in-place on re-run, pass-through when
  nothing to merge into) and 3 new command-level regression tests (existing PubChem spec survives a
  default run, a re-run replaces only the old AI block not the facts above it, an item that already has an
  AI block after other content is correctly skipped by the default scope) — all against real reproduction
  fixtures, not just the fixed code.

## Post-launch — Lab inventory list ("สต็อกคงคลังย่อยของฉัน", 2026-09-16)

Built per `docs/lab_inventory_handover_spec.md` (a handover doc the server-side team wrote after
adding `physical_state`/`grade` — see that commit history). Implemented directly by this session at
the user's explicit request, an exception to the "server team implements, this session reviews"
default this session otherwise follows now.

- **New `stock-in.index` page (`App\Livewire\Inventory\LabInventoryTable`)** lists every `SEALED`/
  `IN_USE` container with `remaining_qty_base > 0`, scoped to the viewer's own `lab_id` — barcode,
  item name/code/grade/physical-state badge, storage location, remaining qty, expiry (colored
  warning within 30 days, red once past), container status, and a per-row "print label" link reusing
  the existing `ContainerLabelPdfService` route. Search (name/code/barcode), a location filter, and
  a "+ เติมสต็อก" button (shown only to users who already pass the same stock-in permission check the
  sidebar link itself used) round it out. New `ContainerPolicy` (`viewAny`/`view` gated on `item.view`,
  matching the item catalog's own read gate) backs the page's authorization.
- **`/stock-in` now serves this list; the old create form moved to `/stock-in/create`** — a route-name
  change only (`stock-in.create`/`stock-in.store`/`stock-in.labels` keep their names and behavior
  unchanged), so every existing `route('stock-in.create')`/`route('stock-in.store')` call site and
  test kept working with zero edits. The sidebar's "รับเข้าคลังย่อย" link now points at the list (label
  changed to "คลังสารเคมีของฉัน (สต็อกคงคลัง)") and is gated on `can('viewAny', Container::class)`
  instead of the old write-only permission check, matching every other inventory nav item's own
  convention of linking to a list page, not a create form.
- **Item detail page (`/items/{id}`) gained a "รายการคงคลังในห้องปฏิบัติการ" card** — the same
  container list, scoped the same way, for just that one item — so opening an item shows at a glance
  how many containers of it exist in your lab, where, and their expiry, without a separate lookup.
- **Only ADMIN/AUDITOR get a lab picker; everyone else's own `lab_id` always wins over the URL** —
  same "never widened via the query string" rule `ReportController::labIdFor()` already established
  for the §7.8 reports, reused here rather than inventing a second convention for the same problem.
- **Found and fixed a real bug before it shipped**: the first draft computed "no lab_id chosen" and
  "not privileged" as the same `null` value, which meant a user with no `lab_id` assigned yet would
  see *every* lab's inventory instead of none (the intended "no filter" meaning of `null` only makes
  sense for a privileged ADMIN/AUDITOR who chose not to filter). Fixed by tracking "unassigned" as its
  own explicit condition in both the list page and the item-detail card, forcing an empty result
  (`whereRaw('1 = 0')`) instead. Caught during review before writing any test, then covered by a
  regression test proving an unassigned user's list is empty, not everyone else's stock.
- **Found and fixed a real, pre-existing permission gap while implementing this**: `ADMIN` had no
  `item.view` permission at all (`PermissionSeeder`'s original grants were `user.manage`/
  `unit.manage`/`lab.manage`/`ledger.verify`/`audit.view` only) — meaning ADMIN could never open
  `/items` or `/items/{id}` even before this feature existed, unrelated to lab inventory specifically.
  Since the handover spec explicitly wants ADMIN to pick any lab on this new page, asked the user
  directly: broaden ADMIN's permissions vs. use AUDITOR (who already has `item.view`) as the
  cross-lab role instead. User-approved 2026-09-16: grant ADMIN `item.view` (`PermissionSeeder`,
  `syncWithoutDetaching` — safe to re-run, only adds grants, never removes). This surfaced one
  existing test that depended on the old gap as its fixture for "a user with no relevant permission"
  (`AttachmentUploadTest.php`, comment literally said "ADMIN has no item.view") — fixed by giving that
  test its own throwaway zero-permission role instead of relying on any real role staying
  `item.view`-less, which is more robust going forward regardless of future grant changes.
- Verified: 10 new tests (`LabInventoryTableTest.php` — 403/redirect gates, own-lab visibility,
  EMPTY/zero-remaining exclusion, cross-lab isolation, the unassigned-user-sees-nothing fix, ADMIN's
  lab picker on both the list and the item-detail card, search), full suite green (468 tests, up from
  458), Pint clean (357 files), PHPStan level 8 clean (0 errors), `composer audit` clean. Re-ran
  `php artisan db:seed --class=PermissionSeeder` against the real dev database so the permission
  change takes effect immediately, not just in the test suite's own fresh migrations.

## Post-launch — Requisition item picker: type-to-search, own-lab only (2026-09-17)

User-reported: the requisition add-line form's item `<select>` listed the entire (thousands-of-rows)
item catalog, unusable to scroll through by hand, and it wasn't scoped to the requester's own branch
at all — every lab's items showed up regardless of what that branch actually stocks.

- **New endpoint `GET /requisitions/items/search`** (`RequisitionController::itemSearch()`) — matches
  by `name_th`/`name_en`/`item_code` (same `LIKE` approach as the item catalog's own search, FR-MD-07),
  scoped to items with at least one container that's SEALED/IN_USE with `remaining_qty_base > 0` in a
  location under the requester's own `lab_id`. A requester with no `lab_id` yet gets `[]` regardless of
  the query.
- **The add-line form's `<select name="item_id">` is now a type-to-search combobox** (hand-rolled
  Alpine.js + `fetch()`, no third-party library — same CSP constraint and "simplest tool" precedent as
  the signature pad/complete-profile page) — debounced 300ms, arrow-key navigation, Enter to choose,
  a hidden `item_id` input carries the actual selection. Picking a result still triggers the existing
  FR-RQ-05 real-time balance lookup, unchanged.
- **Server-side gate, not just a nicer picker**: `RequisitionItemRequest::withValidator()` now rejects
  an `item_id` with no stock in the requisition's own `lab_id`, independent of the UI — a direct POST
  naming an item only ever stocked in a different branch is rejected with a validation error, the same
  "own branch only" rule the rest of the multi-branch feature (stock-in, location edit, disposal/
  adjustment approval) already enforces. This changed real behavior for 5 existing tests in
  `RequisitionCrudTest.php` that posted an `item_id` with no container fixture at all — fixed by
  stocking the item into the requisition's own lab first (new `stockItemInLab()` test helper) rather
  than relaxing the check.
- **User-requested follow-up, same day: "both" modes, not type-only** — an empty `q` now browses the
  requester's own lab stock (capped at 50, vs. 20 for a narrowed search) instead of returning `[]`, so
  a requester who can't recall an item's exact name can still find it by focusing the field and looking,
  not just by typing. Opening the field always (re-)fetches on focus.
- **User-requested follow-up: the unit dropdown now matches the picked item, instead of listing every
  unit in the system regardless of dimension.** Each search result now carries the item's own
  `base_unit_id` and its dimension (MASS/VOLUME/COUNT); selecting an item defaults the unit `<select>`
  to that unit and restricts the visible options to the same dimension (still lets a requester ask in
  `kg` for a `g`-based item, same as before — just no longer offers `mL`/`pcs` for a by-mass item to
  begin with). Client-side only: `unit_id`'s server-side validation is unchanged, so the existing
  density-based cross-dimension path (`UnitConverter::toItemBase()`, T-031) still works for any caller
  that posts one directly.
- Verified: 7 new tests (`RequisitionItemSearchTest.php` — own-lab-only results, `item_code` match,
  empty-query browse-own-lab, no-lab-assigned returns nothing regardless of query, cross-lab `item_id`
  POST rejected, response carries `baseUnitId`/`dimension`), full suite green (474 tests, up from 468),
  Pint clean (358 files), PHPStan level 8 clean (0 errors), `composer audit` clean.

## Fix — Item picker broke the whole add-line form in a real browser (2026-09-17)

**User-reported, with a screenshot**: opening a requisition and going to add a line showed raw
JavaScript source text spilled across the page above the form fields, right where the add-line
form was supposed to start.

- **Root cause**: `units: @json($units->map(...))` inside the `x-data="..."` attribute
  (introduced by the same-day item-picker work above). `@json()`'s `JSON_HEX_*` options only
  escape a quote character that appears *inside a string's content* — they cannot remove JSON's
  own structural quotes (`"key":"value"`), which are unavoidable in valid JSON. So the rendered
  attribute contained real `"` characters, which closed the double-quoted `x-data="..."`
  attribute early. The browser's parser then read the rest of the JS expression as if it were
  more tag attributes, until it hit the first literal `>` inside the JS itself
  (`this.activeIndex >= 0` in `chooseActive()`) — which it took as the tag's closing bracket,
  dumping everything after as plain visible page text until the next real `>`. None of this was
  caught by any Feature test because they only assert status codes/JSON bodies, never that the
  rendered HTML is well-formed — a class of bug only a real browser (or a targeted content
  check) surfaces.
- **Fix**: switched to `{{ \Illuminate\Support\Js::from(...) }}` — Laravel's own primitive for
  embedding arbitrary data into an HTML attribute/JS context safely (wraps the payload as
  `JSON.parse('...')` with every quote hex-escaped, including the structural ones). No visible
  behavior change; the picker's browse/search/unit-matching behavior added earlier today is
  unaffected.
- Added a regression test asserting the rendered page never contains a literal `"id":"` sequence
  (the tell-tale sign of unescaped JSON leaking into the attribute) and does contain
  `JSON.parse(`. Verified live by re-rendering the view directly and diffing the raw HTML before
  and after. Full suite green (475 tests), Pint clean, PHPStan level 8 clean.

## Fix — Trim trailing zeros from displayed quantities (2026-09-17)

User-reported: the FR-RQ-05 real-time balance next to the item picker showed `1000.000000 mL` —
the full `DECIMAL(18,6)` precision the column is stored at (AGENT RULE #1), which is correct to
keep internally but unnecessarily noisy to show a requester.

- **`RequisitionController::itemBalance()`** now trims the JSON `balance` value with
  `rtrim(rtrim($balance, '0'), '.')` before returning it — `1000.000000` → `1000`,
  `42.500000` → `42.5` — the same trim-trailing-zeros convention already used for the
  container "คงเหลือ" column (`LabInventoryTable`/`items.show`, added with the Lab Inventory
  feature). Display only; the stored/computed value and every calculation that reads it are
  untouched.
- **The requisition lines table's "จำนวนที่ขอ" column** (`requisitions/show.blade.php`, right
  below the item picker on the same page) gets the same trim, for the same reason — it's the
  other raw `DECIMAL(18,6)` value visible in that immediate context.
- Scoped narrowly to what was reported: other raw-decimal displays elsewhere (GRN, disposals,
  adjustments, the issue/return page, ledger/report exports) are untouched for now — some of
  those (F-01/F-03, the ledger export) are formal/audit documents where full precision may be
  the intended behavior, not an oversight, so they weren't assumed to need the same treatment.
- Verified: updated the existing FR-RQ-05 balance test's expected value (`42.500000` →
  `42.5`) and added a whole-number case (`1000.000000` → `1000`, no trailing decimal point at
  all). Full suite green (476 tests, up from 474), Pint clean (358 files), PHPStan level 8
  clean.

## Post-launch — Container labels: QR code instead of barcode, bigger label text (2026-09-17)

User-requested: the lab doesn't own a 2D scanner yet but is buying one, and wants to switch the
printed label's symbol to a QR code ahead of that purchase; the person managing labels day-to-day
is older and asked for larger label text.

- **`ContainerLabelPdfService` now uses `QrCodeGenerator`** (already built for F-01's verify
  corner, T-023/T-037 — reused here, not rebuilt) instead of `BarcodeGenerator`/Code 128.
  `containers.barcode` itself is untouched (still the same plain string column, same value used
  everywhere else — scanning, issuing, stock take); only the printed symbol on the label changed.
  Since `BarcodeGenerator` had no other consumer left anywhere in the app, deleted it and its
  dedicated unit test rather than leave dead code behind.
- **Label text sizes increased**: item name 7pt → 9pt, code text 6pt → 8pt, lot/expiry 6pt → 7pt.
  The QR's own physical size (`qr_size` in mm, added per label size in `SIZES`) was hand-picked
  to leave enough room for the now-bigger text stacked below it without either one getting
  cramped — 12mm for the 40×25mm label, 17mm for 50×30mm. The QR's own SVG is also generated at
  a matching pixel size (96 CSS px/inch, 25.4mm/inch) as a safeguard in case mPDF doesn't scale
  an embedded SVG down from its native size to fit a smaller container — belt-and-suspenders
  with the CSS width/height on the wrapping div.
- Verified visually, not just by test assertion: rendered a real label PDF for two throwaway
  containers, converted it to a high-res PNG (`pdftoppm`, installed as one-time container
  tooling — same throwaway-tooling precedent as T-025's font build, not a runtime dependency)
  and inspected it directly. Both label sizes render cleanly — QR fully inside the dashed label
  border, text readable and clearly larger, nothing clipped or overlapping into the next label.
  Existing tests (`ContainerLabelPdfServiceTest`, `QrCodeGeneratorTest`) still pass unchanged,
  since neither asserts on the SVG's internal content.
- Verified piecemeal at first (an unrelated, already-flagged server-side fatal-redeclare bug —
  see `docs/qa_finding_2026-09-17_test_suite_fatal_redeclare.md` — blocked a single full-suite
  run at the time), then re-verified with `vendor/bin/pest` end to end once the server side
  fixed that bug and this branch merged it: 473 tests green, Pint clean (356 files), PHPStan
  level 8 clean (0 errors).

## Post-launch — Issue page: click a container to select it, trim its displayed quantities (2026-09-17)

User-requested, from a screenshot of the dispensing page: pick which container to issue from by
clicking a row instead of only typing/scanning its barcode by hand, and the same "too many
decimals" complaint (`.000000` everywhere) that FR-RQ-05's balance already got fixed for.

- **Each container row in the FEFO table is now clickable** (row click or its own radio button)
  and sets the same `barcode` field a real barcode scanner still types into — the field is now
  `x-model`-bound to a shared `selectedBarcode` Alpine property (initialized to the FEFO-
  recommended container, same as before) instead of a static server-rendered `value`. Scanning
  still works exactly as before since the scanner just types into the same focused input; the
  radio is a convenience for picking a *different* container without re-scanning or hand-typing
  its barcode.
- **Every raw `DECIMAL(18,6)` quantity on this page is now trimmed for display** (same
  `rtrim(rtrim($v, '0'), '.')` convention as the FR-RQ-05 balance/lines-table fix): the line's
  requested/issued/remaining summary line, each container row's "คงเหลือในภาชนะ", and the return
  section's "คืนได้สูงสุด". The underlying values (what's stored, what's validated, what's
  written to `stock_ledger`) are completely untouched — display only.
- Verified visually via a direct render (not just Pest assertions), checking every `x-data="..."`
  attribute in the rendered HTML for embedded literal quotes — the same class of check that
  caught the earlier `@json()` bug — both attributes came back clean (0 literal `"` inside).
  Added a regression test asserting the page renders the `x-model="selectedBarcode"` binding,
  the FEFO-recommended container's barcode as the initial selection, and never shows a raw
  `"500.000000"` for a container stocked with exactly that amount. Full suite green (474 tests,
  up from 473), Pint clean (356 files), PHPStan level 8 clean.

## Post-launch — Reports page becomes a live on-screen dashboard (2026-09-17)

User-requested: view every §7.8 report directly on the page (table + a summary chart),
live-filtered with no submit button, instead of only ever downloading a file blind. Export
stays available, reflecting whatever's currently filtered.

- **`ReportController::index()` (a plain filter-forms-only page) is replaced by a new Livewire
  component, `App\Livewire\Reports\ReportsDashboard`** — one page, six tabs (usage summary,
  expiring stock, below reorder point, dead stock, controlled substances, stock take variance),
  each with its own live filters (`#[Url]`-backed properties, `wire:model.live[.debounce]`, no
  submit button), an on-screen table (capped at 100 rows — "แสดง N จาก M รายการ — Export เพื่อ
  ดูข้อมูลทั้งหมด" for anything larger), and a small hand-rolled inline-SVG bar chart (same
  viewBox/style convention as the home dashboard's existing "monthly issuance" chart — no new
  JS library, same CSP reasoning already recorded for that one). Export buttons are unchanged
  `<a href>` links to the same download routes as before, built from the dashboard's current
  filter state.
- **Zero duplicate query logic**: every Export class (`UsageSummaryExport`,
  `ExpiringStockExport`, `BelowReorderPointExport`, `DeadStockExport`,
  `ControlledSubstancesExport`, `StockTakeVarianceExport`) gained a public `results()` method —
  the same Eloquent query `collection()` already ran, just returning the raw models instead of
  CSV-ready arrays. The dashboard calls the exact same `results()` a download would use, so a
  filter behaves identically on-screen and in the exported file, and any future change to a
  report's query only has one place to change. `Container::labNameOrEmpty()` was pulled out of
  two Export classes' private, byte-identical `labNameFor()` helpers into the model itself, now
  a third consumer (the dashboard) needed the exact same lookup.
- **Every chart is a genuinely unit-agnostic aggregate, not a made-up number**: usage summary
  ranks items by issue-transaction *count* (same reasoning as the home dashboard's own top-items
  chart — summing quantities across items in different units would be meaningless); expiring
  stock counts containers per expiry month; below-reorder charts each item's balance as a
  *percentage* of its own reorder point (a ratio, so items in different units stay comparable,
  lowest/most-urgent first); dead stock counts containers per lab; controlled substances counts
  ledger rows per transaction type; stock take variance buckets lines into เกิน/ขาด/ตรง/ยังไม่ได้นับ.
- **Lab-scoping is unchanged in substance, just relocated**: a LAB_MANAGER's own `lab_id` still
  always overrides the query string (the exact same rule `ReportController::labIdFor()`
  enforced, reimplemented as `ReportsDashboard::restrictedLabId()`), and the lab `<select>` is
  hidden entirely for a LAB_MANAGER (same `restricted_to_own_lab` message as before). The stock
  take tab additionally resets a rejected `stockTakeUlid` back to "no round selected" rather
  than silently showing an empty table, so a LAB_MANAGER guessing another branch's ulid gets the
  same prompt as picking nothing, not a hint that the round exists.
- **`expiring-stock`/`controlled-substances` now expose a lab filter in the UI** for the first
  time — the underlying Export classes already accepted a `labId` (and `ReportController` always
  resolved one via `labIdFor()`), it just had no `<select>` on the old page; this closes that gap
  rather than leave two of the five lab-filterable reports UI-inconsistent with the other three.
- Verified: 8 new tests (`ReportsDashboardTest.php` — live filtering per tab, LAB_MANAGER lab-
  and stock-take-scoping, a real chart rendering actual SVG bars, 403 without `report.view`) plus
  all 37 pre-existing report tests updated for the `results()` refactor and still green. Also
  centralized a second stray unguarded test helper (`issueOneLine()`, previously only in
  `UsageSummaryExportTest.php`) into `tests/Pest.php` — same class of fatal-redeclare risk as
  `docs/qa_finding_2026-09-17_test_suite_fatal_redeclare.md`, caught here before it shipped by
  running the new test file in isolation, not just as part of the full suite. Full suite green
  (482 tests, up from 474), Pint clean (358 files), PHPStan level 8 clean (0 errors), `composer
  audit` clean.

## Post-launch — New default tab: per-item stock/usage summary (2026-09-17)

User-requested: a scientist logging in should immediately see, per chemical, how much has
been used and how much is left — so the one closest to running out is obvious, and they know
to go restock it from the central warehouse into this system.

- **New report + Export class, `ItemStockSummaryExport`** — every active item with at least
  one real `stock_ledger` row, showing total issued in a given period (defaults to all-time if
  no date range is set) alongside its current balance, **sorted lowest-balance-first** so the
  item nearest zero is the one at the top. Same lab-scoping convention as `BelowReorderPointExport`
  (balance is global per item — spec's schema has no per-lab split — the lab filter narrows to
  items with a container physically in that lab). Ships both an Excel download
  (`reports.item-stock-summary.excel`) and the new dashboard tab, same `results()` pattern as
  every other report added this feature cycle.
- **Set as the dashboard's new default/first tab** (`item_stock_summary`, ahead of usage
  summary) — this is the "walk in and see it immediately" view the request asked for, not
  something a scientist has to know to click into.
- **Chart is % of tracked stock already consumed** (`used ÷ (used + remaining) × 100`), not a
  raw quantity — same "items in different units can't be summed or compared directly"
  reasoning as every other chart in this dashboard (see `below_reorder`'s reorder-point-ratio
  chart) — ranked highest-consumed-fraction-first, i.e. closest to depletion.
- **Real PHPStan gotcha hit again**: `Item::$baseUnit` nullsafe access (`?->code ?? ''`)
  triggered `nullsafe.neverNull` even though `items.base_unit_id` is genuinely nullable (the
  working-stock merge's own change) — same unreliable chained-relation inference CLAUDE.md
  already documents. Fixed with the documented workaround: assign to a local variable, narrow
  with an explicit `if`, not `?->`/`??`.
- Verified: 5 new Export tests (`ItemStockSummaryExportTest.php` — used/remaining values,
  no-ledger-history items excluded, lowest-balance-first sort, date-range-scoped usage, lab
  filter) + 2 new dashboard tests (default tab, live per-item table with a real rendered
  chart). Full suite green (489 tests, up from 482), Pint clean (360 files), PHPStan level 8
  clean (0 errors), `composer audit` clean.

## Fix — Chart bars showed bare numbers with no unit or context (2026-09-17)

**User-reported, with a screenshot** of the live site: the item-stock-summary chart showed
two bars labeled "17.5" and "1" with truncated item names below them ("Normal s") — no axis,
no unit, no caption anywhere explaining what the number meant.

- **`<x-bar-chart>` gained a `suffix` prop**, appended to every bar's value label (e.g. `%` for
  a ratio chart, ` ครั้ง`/` ภาชนะ`/` รายการ` for count-based ones) — `17.5` becomes `17.5%`.
- **Every tab's chart now has an explicit caption above it** (`reports.chart_caption_*` lang
  keys) stating in plain Thai exactly what the bars measure — e.g. item stock summary:
  "% ของสต็อกที่ใช้ไปแล้วในแต่ละสาร (8 อันดับที่ใกล้หมดที่สุด — ยิ่งเปอร์เซ็นต์สูง ยิ่งใกล้หมด)".
  The generic "กราฟสรุป" heading stays, now with this per-tab subtitle underneath it, wired
  through a `$chartMeta` lookup in the view (caption + suffix per tab key) rather than one
  shared, meaning-free label for every report.
- **X-axis item-name truncation loosened slightly (8 → 10 chars) and now ends in `…`**, so a
  cut-off label like "เอทานอลบ" at least visibly signals it's incomplete — full name is still
  in the SVG's own `<title>` (hover) and, unabridged, in the table row right below the chart.
- Verified: extended the existing below-reorder dashboard test to assert both the new caption
  text and a `%` suffix actually render. Full suite green (489 tests), Pint clean, PHPStan
  level 8 clean.

## Post-launch — Item stock summary: drop the chart, add a per-row low-stock warning (2026-09-17)

User-requested follow-up: for this one tab specifically, a plain list of used/remaining is
clearer than a chart — and instead, flag whichever item is actually close to running out,
computed from its own remaining percentage, right on its own row.

- **No chart on the `item_stock_summary` tab anymore** — the chart card is skipped entirely
  for this tab (every other tab's chart is untouched). `ReportsDashboard::itemStockSummaryData()`
  no longer builds a top-8 ranking; it isn't needed once nothing renders it.
- **Each row now carries its own `remaining_percent`** (`remaining ÷ (used + remaining) × 100`
  — the same ratio the old chart used, just kept per-item instead of aggregated) and a
  `low_stock` flag, true at or under a new `LOW_STOCK_REMAINING_PERCENT` threshold (20%,
  user-requested starting point — a documented class constant, easy to retune, not a magic
  number buried in Blade). A new "สถานะ" column shows "⚠️ ใกล้หมด (เหลืออีก N%)" only for
  flagged rows — silent otherwise, matching this app's existing convention of only showing a
  badge for the exceptional case (FEFO-recommended, expired-warning, etc.), not a "you're
  fine" badge on every row.
- Verified: updated the tab's existing dashboard test — a 100-received/0-used item (100%
  remaining) shows no chart and no warning; a 100-received/95-issued item (5% remaining, under
  the 20% threshold) shows "ใกล้หมด (เหลืออีก 5%)". Full suite green (489 tests), Pint clean
  (360 files), PHPStan level 8 clean (0 errors).

## Post-launch — Reports dashboard: remove the summary chart from every tab (2026-09-18)

User-requested follow-up to the item-stock-summary chart removal above ("ในหน้ารายงาน ผมว่าเอากราฟ
ออกเลยดีกว่าครับ ไม่ค่อยชอบเท่าไหร่" — remove the chart from the reports page entirely, doesn't
care for it) — extends that same removal to the other six tabs, which had kept their own
summary chart until now.

- **Every tab's chart card is gone** — `ReportsDashboard`'s six remaining per-tab data methods
  (`usageSummaryData()`, `expiringStockData()`, `belowReorderData()`, `deadStockData()`,
  `controlledSubstancesData()`, `stockTakeVarianceData()`) no longer compute a top-8 chart
  array at all; each now returns just `[rows, total]` instead of `[rows, total, chart]`. The
  Blade view's `@unless ($tab === 'item_stock_summary')` chart block and its `$chartMeta`
  lookup array are removed outright — every tab now renders straight from filters to table,
  same shape the item-stock-summary tab already had.
- **Deleted `resources/views/components/bar-chart.blade.php`** (the hand-rolled inline-SVG bar
  chart component) — confirmed via a repo-wide search it had no other consumer (the home
  dashboard's own "monthly issuance" chart is a separate, unrelated inline SVG block, not this
  component).
- **Removed the now-orphaned `reports.chart_*` lang keys** (`chart_title`, `chart_no_data`,
  `chart_unit_times`, `chart_unit_containers`, `chart_unit_rows`, and all six
  `chart_caption_*` keys) from `lang/th/reports.php`. `lang/th/home.php`'s own unrelated
  `chart_*` keys for the home dashboard are untouched.
- Verified: updated `ReportsDashboardTest` (dropped the below-reorder tab's chart/SVG/caption
  assertions; renamed the item-stock-summary test to stop calling out "with a real chart" now
  that no tab has one). Full suite green (489 tests), Pint clean (360 files), PHPStan level 8
  clean (0 errors), `composer audit` clean.

## Post-launch — Requisitions: handle recipient without email and prevent 403 on re-submit (2026-09-18)

User-reported: submitting a requisition failed with Error 403 / 500 when active scientists had empty email
addresses from SSO provisioning.

- **Safe email validation and delivery**:
  - `NotificationService::notifyInAppAndEmail()` and `emailOnly()` now validate `filter_var($email, FILTER_VALIDATE_EMAIL)`
    and catch mail transport exceptions with a logged warning, so mail delivery failures never crash application transactions.
  - `RequisitionService::mailAdvisor()` and `ReceiverOtpService::send()` similarly guard against empty/invalid email addresses.
- **Idempotent requisition submit without 403**:
  - Added dedicated `submit()` method to `RequisitionPolicy`.
  - `RequisitionController::submit()` now safely redirects to the show page if the requisition is already submitted
    rather than aborting with HTTP 403 if a user double-clicks or refreshes.
- **Client-side double submit prevention**:
  - `requisitions/show.blade.php` now disables the submit button immediately upon form submission via Alpine.js.

## Post-launch — Bulk lab (branch) assignment from a real HR roster export (2026-09-18)

The user wanted a way to set `users.lab_id` for real staff without hand-picking it one
person at a time in `/admin/users`, and asked whether it could be pulled automatically
from the MEDSCI ACC SSO. Investigated first: the SSO's verify-API response carries only
six fields (`user_id`, `username`, `name`, `pos_name`, `div_name`, `email` — confirmed
against `SsoUserData`, `SsoLoginTest`'s fixture, and both integration guides) — `div_name`
is the closest candidate but is free-text Thai ("ภาควิชาเคมี" etc.), not a stable code, and
there is no faculty/department code in the payload at all. Concluded automatic SSO-based
mapping isn't reliable right now — user-acknowledged, then supplied an actual HR export
(CSV: ลำดับ, ชื่อ-นามสกุล, ชื่อผู้ใช้งาน (UP Account), ฝ่ายงาน/สังกัด, ตำแหน่งงาน, อีเมล)
instead, which is what this feature imports.

- **New `php artisan users:import-lab-assignments <path>`** (`app/Console/Commands/
  ImportLabAssignmentsCommand.php`) maps `ฝ่ายงาน/สังกัด` (department) to an internal
  `labs` record via a fixed, hand-confirmed table (`DEPARTMENT_LAB_CODES`) — only 5 real
  academic departments were in the actual export (กายวิภาคศาสตร์, จุลชีววิทยาและปรสิตวิทยา,
  ชีวเคมี, สรีรวิทยา, โภชนาการ), so this is an explicit list, not a generic slugger.
  User-confirmed 2026-09-18: **sets only `lab_id`, never a CMIS role** — a job-title string
  ("อาจารย์"/"นักวิทยาศาสตร์"/"คณบดี"/etc.) doesn't reliably map to a CMIS permission, and
  role assignment stays an explicit ADMIN action via `/admin/users`. Also user-confirmed:
  **"สำนักงานธุรการ" (administrative office — where the ADMIN account `wittaya.su` itself
  is listed) is real staff but has no physical chemical inventory, so it's deliberately
  excluded** — any department not in the fixed map is skipped and reported by name, never
  silently guessed at or auto-created as a new Lab.
- **Idempotent**: an existing `users` row only gets `lab_id` set if it's currently
  `null` — this command never overwrites a lab an ADMIN already assigned by hand.
  Re-running against the same file a second time changes nothing further (`Lab::
  firstOrCreate` by `code`; a username that already has a `users` row, pre-created or
  real, is never duplicated).
- **User-confirmed follow-up decision (2026-09-18): don't wait for a first login at
  all — pre-create the real `users` row outright**, with `lab_id` already set, so the
  branch is usable the moment an ADMIN grants that person a role, not only after they
  first log in. This meant relaxing a real constraint: `users.sso_subject` was `NOT
  NULL` (every row assumed to be born from a real SSO login, BR-11) — a new migration
  (`2026_09_18_000001_make_users_sso_subject_nullable.php`) makes it nullable (still
  `unique()` — MariaDB allows unlimited `NULL`s in a unique index, so many un-claimed
  pre-created rows coexist safely). A pre-created row's SSO-owned fields (`full_name`/
  `email`/`pos_name`/`div_name`) are filled from the same CSV columns as placeholders
  only — `is_active` defaults `true`, no role is attached (still roleless/deny-by-
  default per BR-11, same as any brand-new account).
- **`UserProvisioningService::provision()` now claims a pre-created row instead of
  creating a duplicate one**: when no `users` row matches the SSO payload's
  `sso_subject` (i.e. this looks like a first login), it now also checks for an
  existing row with the same `username` and a still-`null` `sso_subject` before
  falling back to `new User()`. If found, that row is claimed — `sso_subject` is set
  for the first time, every SSO-owned field is overwritten with the real payload (the
  placeholder values the import guessed are gone, "SSO payload always wins" per this
  class's own doc comment, unchanged), and the pre-assigned `lab_id` survives untouched
  since nothing in `provision()` ever touches `lab_id`. A username with no pre-created
  row still gets a genuinely new one, exactly as before this change.
- **The raw HR export CSV is never committed to this repo** — it's real personal data
  (names/usernames/emails), so the command takes a filesystem `path` argument instead of
  a tracked fixture file, and persists only what the resulting `users`/`labs` rows
  actually need, nothing else from the source file. A missing email (`-` in the export)
  is not stored literally — `users.email` is `unique()`, so multiple `-` placeholders
  would collide; a synthesized `{username}@up.ac.th` placeholder is used instead
  (matching the real address pattern already visible for the rows that do have one),
  overwritten by the authoritative SSO email on first real login regardless.
  `.gitignore` gained `/storage/app/_staff_imports/` as the working-copy convention for
  any future export like this one (mirrors the existing `/download.csv` chemicals-import
  precedent) — the actual file used for the 2026-09-18 run was deleted from disk
  immediately after each import run.
- **Ran for real against the dev database** (not just tested): 5 new `Lab` records
  created (กายวิภาคศาสตร์, จุลชีววิทยาและปรสิตวิทยา, ชีวเคมี, สรีรวิทยา, โภชนาการ), 97
  real accounts pre-created with `lab_id` already set (19/28/22/17/11 respectively),
  18 สำนักงานธุรการ rows correctly skipped (no Lab, no account), 0 already-existing
  accounts matched (nobody in the 5 real departments had ever logged into CMIS before
  this ran). Each of those 97 people can be granted a role in `/admin/users` right away
  — their branch no longer waits on a first login.
- Verified: `ImportLabAssignmentsCommandTest` (8 tests: existing-user assignment,
  skip-if-already-has-a-lab, outright pre-creation for a new username with no role and
  no `sso_subject`, placeholder-email synthesis, unknown-department skip, re-run
  idempotency, in-file duplicate-username handling, missing-file failure) and two
  `SsoLoginTest` cases (a pre-created account is claimed — not duplicated — on its real
  first login, with `lab_id` surviving and placeholder fields overwritten by the real
  SSO payload; a username with no pre-created row still gets a genuinely new account).
  Full suite green (499 tests), Pint clean (363 files), PHPStan level 8 clean (0
  errors), `composer audit` clean.
- **Still open, flagged for the next step**: SCIENTIST is not yet lab-scoped the way
  LAB_MANAGER is — the user separately asked for SCIENTIST to be restricted to their own
  branch (cannot act across branches), with an explicit decision that a SCIENTIST with no
  `lab_id` set should be **blocked** from lab-scoped actions until one is assigned (not
  treated as unscoped/see-everything). This lab-assignment import is a prerequisite for
  that change (real SCIENTIST accounts need a real `lab_id` before the restriction can be
  meaningful) but the restriction itself has not been implemented yet.
- **Import Faculty Personnel from MEDSCI ACC (`users:import-msc-acc`)**:
  - Added Artisan command `users:import-msc-acc` to import all faculty personnel directly from the `account_medsci` database (SSO system database).
  - Maps divisions (`id_div`) to CMIS Labs (001-006: สำนักงานธุรการ, จุลชีววิทยา, ชีวเคมี, กายวิภาคศาสตร์, สรีรวิทยา, โภชนาการ).
  - Performs intelligent deduplication when identical usernames exist across multiple divisions, prioritizing specific academic departments over generic administrative office entries and retaining valid email addresses.
  - Generates fallback email (`{username}@up.ac.th`) for entries without valid email addresses.
  - Automatically assigns initial roles according to position: `SCIENTIST` for scientists, `STAFF` + `ADVISOR` for lecturers/deans/program chairs, and `STAFF` for other staff members.
  - Preserves existing accounts and their roles/lab configurations (e.g. `wittaya.su` super admin status and `apisit.ph`).
  - Executed live against `account_medsci`: 106 new users imported, 2 existing users updated, giving CMIS a total of 108 faculty personnel ready with roles and lab assignments across all 6 departments.
  - Verified: `ImportMscAccUsersCommandTest` (4 tests, 31 assertions covering database connection failure handling, `--dry-run` preview mode, deduplication, role assignment, lab mapping, fallback email synthesis, existing-user preservation, and `AuditLog` logging). Pint clean, PHPStan level 8 clean (0 errors).

## Post-launch — Removed `users:import-lab-assignments`, superseded by `users:import-msc-acc` (2026-09-21)

Both commands landed independently on the same day (2026-09-18) solving the same problem
(bulk-assign `users.lab_id` from a real personnel roster) with two genuinely conflicting
policies: this command excluded "สำนักงานธุรการ" from becoming a Lab and never touched
CMIS roles (both explicit user decisions at the time); `users:import-msc-acc` includes it
as Lab code `001` and auto-assigns SCIENTIST/STAFF/ADVISOR from job title. Running both
against the same database would also have silently created a **duplicate Lab** for
จุลชีววิทยา/จุลชีววิทยาและปรสิตวิทยา — `users:import-msc-acc` looks up an existing Lab by
the short name "จุลชีววิทยา", which never matches this command's Lab record (created
with the export's full department name, "จุลชีววิทยาและปรสิตวิทยา") or its `LAB-MICRO`
code (vs. its own numeric `002`).

- User-decided 2026-09-21, informed by a live comparison of both commands: standardize on
  `users:import-msc-acc` going forward — it reads the real MEDSCI ACC database directly
  (`account_medsci`'s own `user`/`position`/`division` tables, with real numeric IDs), a
  stronger source of truth than the manually-exported CSV this command was built against.
  Already run for real against a real `account_medsci` instance (106 users), unlike this
  command's own dev-database-only run.
- **Removed**: `app/Console/Commands/ImportLabAssignmentsCommand.php`,
  `tests/Feature/Labs/ImportLabAssignmentsCommandTest.php`, and the now-unused
  `/storage/app/_staff_imports/` `.gitignore` entry.
- **Kept**: `UserProvisioningService::provision()`'s "claim a pre-created row with a
  still-null `sso_subject` by username on first real login" behavior, and the
  `sso_subject`-nullable migration underneath it — both are general-purpose and harmless
  regardless of which command pre-creates a row this way. `users:import-msc-acc` doesn't
  currently need this path (it always sets a real `sso_subject` from `id_user` directly),
  but nothing about removing this command required reverting it, and doing so would have
  meant destructively cleaning up this session's own dev-database rows with a null
  `sso_subject` first for no real benefit.
- **Not otherwise reconciled**: the Lab-duplication risk above (name/code mismatch between
  the two commands' conventions) is now moot since only one command remains, so no fix was
  made to `users:import-msc-acc` itself for it — flag this if a third lab-provisioning
  path is ever added and reuses either naming convention.
- **Refactor `users:import-msc-acc` with `MscAccReader` Service & DB-Independent Tests (2026-09-21)**:
  - Extracted external `account_medsci` database query into `App\Domain\Auth\Services\MscAccReader`.
  - Injected `MscAccReader` into `ImportMscAccUsersCommand`, adhering cleanly to spec §4.2 layering rules.
  - Refactored `ImportMscAccUsersCommandTest` to mock `MscAccReader` rather than dropping and creating tables dynamically in `cmis_testing`.
  - Resolves QA finding `docs/qa_finding_2026-09-21_msc_acc_import_test_db_grants.md`: tests now pass 100% on restricted-grant environments (T-027 / MariaDB `cmis_app`) as well as environments without SQLite drivers. Full test execution dropped from ~92s to ~15s.


## Post-launch — `/admin/users`: split personnel into per-branch tabs, plus a student tab (2026-09-21)

User-requested: the flat, single paginated list mixed every account together regardless of
role or branch, making it hard to find "everyone in ชีวเคมี" or "just the students" once the
roster grew past a hundred real people (post `users:import-msc-acc`).

- **`UserRoleManager` now renders one tab per active `Lab`** (label = `name_th`, live count
  of non-student users assigned to it), **a `นิสิต` tab** that shows every STUDENT-role user
  regardless of `lab_id` (a student's own branch isn't the useful axis for finding them here
  the way it is for staff/scientists — and lab tabs deliberately exclude students via
  `whereDoesntHave('roles', ... 'STUDENT')`, so nobody appears in two tabs at once), and
  **an `ไม่ระบุสาขา` catch-all tab** for anyone with no `lab_id` who also isn't a student —
  ADMIN/AUDITOR accounts, or a freshly-provisioned roleless one with no branch assigned yet.
  Every existing action (role toggle, lab reassignment, active/inactive toggle) works
  unchanged inside any tab — only which rows are visible changed, not what can be done to
  them. `#[Url] public string $tab` keeps the selected tab bookmarkable/shareable, matching
  the same live-tab convention already used by `ReportsDashboard`.
- **Real bug caught by PHPStan, not by eye**: `Lab::firstOrCreate`/computed-array keys built
  from `(string) $lab->id` get silently coerced back to an **int** array key by PHP's own
  array semantics (a numeric-string key always becomes an int key) — so the view's original
  `$tab === $key` comparison (`$tab` a genuine string property) would have **never matched
  any lab tab**, only the string-literal `students`/`unassigned` keys, leaving every lab tab
  permanently un-highlighted regardless of which one was actually selected. Fixed by
  comparing against `(string) $key` in the view instead; regression-tested by asserting the
  rendered `<button>` for a specific lab actually carries the active CSS class when selected.
- Verified: 5 new `UserRoleManagerTest` cases (personnel correctly split per branch and never
  leak a student into a lab tab; the student tab ignores branch entirely; the unassigned tab
  catches ADMIN/AUDITOR-style accounts; the tab bar's live counts are correct; the
  active-tab-highlight regression above). Full suite green aside from the already-flagged,
  pre-existing `ImportMscAccUsersCommandTest` DB-grant failures (see the QA finding doc two
  entries up — unrelated to this change). Pint clean (363 files), PHPStan level 8 clean
  (0 errors), `composer audit` clean.

## Post-launch — AUDITOR repurposed into a second branch-scoped warehouse manager (2026-09-21)

User-reported confusion: "ผู้ดูแลระบบ" (ADMIN) sounds like it should be the one running the
warehouse, but ADMIN deliberately never touches stock (§3: "ไม่มีสิทธิ์แตะ Ledger") — the role
that actually does that is LAB_MANAGER ("หัวหน้าสาขาวิชา"). User-decided: repurpose the
under-used AUDITOR role (spec's read-only oversight role) into "ผู้ดูแลคลัง", a second,
independently-assignable flavor of branch-scoped warehouse manager — same operational grants
and own-branch-only scoping as LAB_MANAGER, confirmed explicitly rather than assumed.

- **`RoleSeeder`**: `AUDITOR`'s `name_th` changed from "ผู้ตรวจสอบ" to "ผู้ดูแลคลัง" — the role
  `code` itself stays `AUDITOR` (renaming it would mean a data migration for every existing
  `role_user`/audit-log row that references it by code, for zero real benefit).
- **`PermissionSeeder`**: AUDITOR's grants now mirror LAB_MANAGER's exactly
  (`requisition.view_all`, `requisition.issue_override`, `ledger.view`, `ledger.adjust`,
  `disposal.approve`, `item.view`, `item.manage`, `location.manage`, `report.view`,
  `lab.manage_members`) — its old read-only grants (`ledger.verify`, `audit.view`) are
  explicitly `detach()`ed, since `syncWithoutDetaching()` is additive-only and would never
  remove them from an already-seeded database otherwise. Both stay available system-wide via
  ADMIN's own grant, so FR-LG-06's "AUDITOR/ADMIN" ledger.verify wording is now a deliberate,
  documented deviation (ADMIN only), not an oversight.
- **New `User::isBranchManager()`** (`hasRole('LAB_MANAGER') || hasRole('AUDITOR')`) —
  every one of the 14 call sites across 12 files that used to gate branch-scoping on
  `hasRole('LAB_MANAGER')` alone now calls this instead, so LAB_MANAGER and the repurposed
  AUDITOR are always scoped identically: `RequisitionPolicy`, `DisposalPolicy`,
  `LocationPolicy`, `LocationRequest`, `StockInRequest`, `StockInController`, `ItemLedger`,
  `ReportsDashboard`, `RequisitionTable`, `LedgerExportController`, `ReportController` (×2),
  `IssueService`, `AdjustmentService`. `LabMemberManager`/`LabPolicy::manageMembers` needed
  no code change (already permission-gated on `lab.manage_members`, which AUDITOR now holds
  too) — only their doc comments were updated to say so.
- **User-facing error/label text updated for accuracy**: BR-04/BR-06 overage- and
  adjustment-approver messages, and the `field_overage_approver`/`field_approved_by` labels
  in `lang/th/requisitions.php`/`lang/th/adjustments.php`, now say "หัวหน้าสาขาวิชาหรือผู้ดูแลคลัง"
  instead of naming only LAB_MANAGER's job title — both roles are equally valid approvers now.
- **Real dev database updated for real**: re-ran `RoleSeeder`/`PermissionSeeder` against
  `cmis` (not just the auto-seeded `cmis_testing`) — confirmed `AUDITOR`'s `name_th` and
  permission set now match this change exactly.
- Verified: new `RoleScopingTest` (5 tests) — `isBranchManager()`'s truth table across every
  role; AUDITOR's grant set now equals LAB_MANAGER's exactly; AUDITOR lost its old grants
  while ADMIN kept them; an AUDITOR only sees requisitions filed in their own branch (not
  system-wide) exactly like a LAB_MANAGER; an AUDITOR of a different branch gets 403
  approving a disposal elsewhere, mirroring the existing LAB_MANAGER branch-scoping test.
  New `auditorUser()` Pest helper added alongside the existing `labManagerUser()`/
  `scientistUser()`/`adminUser()` ones. Full suite green (508 tests), Pint clean (365 files),
  PHPStan level 8 clean (0 errors), `composer audit` clean.
- **Fix Super Admin branch-scoping (`User::isBranchManager()`)**:
  - `User::isBranchManager()` now explicitly returns `false` if the user holds the `ADMIN` role (`if ($this->hasRole('ADMIN')) return false;`).
  - Previously, a Super Admin holding both `ADMIN` and `LAB_MANAGER`/`AUDITOR` (such as `wittaya.su`) was evaluated as a branch-scoped manager, accidentally locking their reports, ledger view, and requisitions list to their own `lab_id` instead of allowing system-wide visibility across all branches.
  - Verified: `RoleScopingTest` regression test added and passing; Pint and PHPStan level 8 clean.

## Post-launch — Lab member management: SCIENTIST was invisible on the page (2026-09-21)

User-reported bug: "จัดการสมาชิกสาขาวิชา" ("Manage branch members") showed no scientists at
all, even ones with `lab_id` already set to that branch. Root cause: `LabMemberManager` was
built for one specific purpose — the requisition whitelist (BR-11/multi-branch feature) —
so its candidate list was deliberately restricted to STUDENT/STAFF (the two roles that hold
`requisition.create`). A scientist holds neither, so despite the page's generic-sounding
name, they could never appear there, whether or not they had a `lab_id`. User-confirmed:
widen the page to also manage SCIENTIST membership, the same way as STUDENT/STAFF.

- **`LabMemberManager::CANDIDATE_ROLES`** now includes `SCIENTIST` alongside `STUDENT`/
  `STAFF` — a manager can now see, add, and remove scientists from their own branch exactly
  like a student/staff requisitioner. ADVISOR/LAB_MANAGER/AUDITOR/ADMIN are still excluded
  (an advisor isn't tied to one branch; the other three are the branch's own managers, not
  members of it).
- **`remove()` gained the same candidate-role check `assign()` already had** — previously
  `remove()` only checked `lab_id` ownership, meaning it could technically clear *any* same-
  branch user's `lab_id` (even another manager's) since nothing restricted its target to an
  actual candidate role. Caught while adding SCIENTIST to the same check in `assign()`; not
  a new hole introduced by this change, but the same underlying whitelist boundary, so fixed
  alongside it rather than left inconsistent between the two methods.
- **Each row now shows the person's role(s)** (`$candidate->roles->pluck('name_th')`,
  eager-loaded to avoid an N+1) — with three different roles now mixed into one list, a bare
  name/email/username no longer said which kind of member a row actually was.
- Verified: 3 new `LabMemberManagerTest` cases (an unassigned scientist is visible and can
  be whitelisted, with an audit log entry; a manager can remove a scientist from their own
  branch; a manager cannot poach a scientist already assigned elsewhere) — mirroring the
  existing STUDENT-focused tests exactly. `scientistUser()` Pest helper gained an
  `$overrides` parameter (matching `labManagerUser()`/`studentUser()`/`adminUser()`) so
  these tests could set `lab_id` directly. Full suite green (511 tests), Pint clean
  (365 files), PHPStan level 8 clean (0 errors), `composer audit` clean.

## Post-launch — Student / Requester Branch (Lab) Self-Selection in Complete Profile (2026-09-21)

- **Student / Requester Branch Onboarding**:
  - Requesters (students/staff/lecturers) are now prompted to select their branch/lab (`lab_id`) directly on the "Complete Profile" page (`/account/complete-profile`) during their first setup.
  - Previously, `complete-profile` did not capture `lab_id`, causing users to immediately hit `account.pending-lab` after submitting their profile, requiring manual branch assignment by an administrator or branch manager.
  - `CompleteProfileRequest`: Added validation rule requiring active `lab_id` (`required|integer|exists:labs,id`).
  - `CompleteProfileController`: Passes active labs to the view, persists `lab_id` to `users.lab_id`, and safely redirects to `intended(route('requisitions.create'))`.
  - `resources/views/auth/complete-profile.blade.php`: Added branch selection dropdown and enhanced advisor dropdown to display the advisor's branch name.
  - `lang/th/auth.php`: Added localized strings for field label, help text, placeholder, and validation error messages.
  - Verified: Unit and Feature tests in `CompleteProfileTest` passing 100%; Pint clean; PHPStan level 8 clean.

## Post-launch — Location tree: independent, private, freely-nested per branch (2026-09-21)

User-requested: each branch (lab) should build and see its own storage tree ("ผังจัดเก็บ")
without being confused by every other branch's locations mixed into the same list — and
without being forced to build a strict BUILDING→ROOM→CABINET→SHELF chain, since a lab may
just want to create a shelf record on its own with no "root" above it at all.

- **The tree list is now scoped to the viewer's own branch** (`LocationTree::render()`
  filters `where('lab_id', $user->lab_id)`) — reversing an earlier, explicit design
  decision documented in this file ("the list stays unscoped... spec never asked for a
  lab-scoped location list"). That reasoning no longer applies once `lab_id` is required
  on every location (see below) — there's no longer a shared, no-lab node whose children
  a filter could silently orphan. Confirmed this is safe for every real viewer: only
  LAB_MANAGER/AUDITOR (`User::isBranchManager()`) hold `location.manage` at all, so the
  list was never actually shared with anyone who'd need to see more than one branch.
- **`locations.lab_id` is now `NOT NULL`** (new migration) — user-confirmed: every storage
  location belongs to exactly one branch, no more shared/unscoped nodes. Safe with zero
  data loss (the table had exactly one row, already carrying a `lab_id`, at the time of
  this change).
- **Strict level-nesting removed** (user-confirmed: "อนุญาตได้อิสระ" — allow free
  parenting) — `LocationRequest::REQUIRED_PARENT_LEVEL` (which forced e.g. a CABINET's
  parent to be exactly a ROOM, and only a BUILDING could have no parent at all) is gone.
  A location at any level may now have no parent, or parent to *any* other location —
  the only remaining rule is that a parent must be in the **same branch** (a location can
  never cross into another lab's tree, which would defeat the whole point of this change).
- **The create/edit form no longer offers a lab picker** — since every `location.manage`
  holder is a branch manager with exactly one lab, letting them "choose" a lab (then
  rejecting anything but their own) was pointless friction. `lab_id` is now a hidden field
  auto-set to the manager's own branch; the `parent_id` dropdown is pre-filtered to that
  same branch's own locations. A manager with no `lab_id` assigned yet sees a clear
  "contact an administrator" message instead of a confusing empty tree or a validation
  error on submit (mirrors `LabMemberManager`'s existing `no_own_lab` pattern).
- **Removed the per-node "lab name" caption** in the tree view — with the list now scoped
  to one branch, every row is trivially the viewer's own lab, so repeating that on every
  row was noise, not information.
- Verified: rewrote `LocationCrudTest` (10 tests) — a location can be created standalone at
  any level, not just BUILDING; a location can parent to any other level in the same
  branch; a location cannot parent to a different branch's location; the tree list only
  shows the viewer's own branch; a branch manager with no `lab_id` sees the clear message
  on both the index and create pages — alongside the pre-existing code-uniqueness/BR-10-
  conflict/branch-scoped-edit tests, updated for the now-required `lab_id`. Also fixed a
  real, unrelated, newly-surfaced fixture bug while verifying: `StockInTest`'s `beforeEach`
  created a `Location` with a wrong key (`'type'` instead of `'level_type'`) and no
  `lab_id` at all — previously silent (MariaDB's non-strict defaults let it through with a
  blank `level_type` and `lab_id = NULL`), now loudly rejected by the new `NOT NULL`
  constraint; fixed alongside this change since every test in that file depended on it.
  Full suite green (513 tests), Pint clean (366 files), PHPStan level 8 clean (0 errors),
  `composer audit` clean.

## Post-launch — F-01 PDF: trim trailing-zero quantities (2026-09-21)

User-reported: the printed F-01 requisition form showed every quantity as a raw
`DECIMAL(18,6)` string (e.g. "5.000000 g"), same class of readability issue already fixed
in several on-screen places earlier this session (item tables, balance displays, the
reports dashboard) but never applied to this PDF.

- `Fr01PdfService::linesHtml()` now trims both `qty_requested` and `qty_issued_base`
  through a new `trimQty()` helper (`rtrim(rtrim($value, '0'), '.')`) — same convention
  used everywhere else in the app; display-only, the underlying stored/ledger values are
  untouched.
- Verified: new `Fr01PdfServiceTest` case renders the form's HTML directly (via
  `ReflectionMethod` on the private `buildHtml()`, since `render()` only returns the final
  compiled PDF binary) and asserts the trimmed value appears, not the raw
  "5.000000". Full suite green (514 tests), Pint clean (366 files), PHPStan level 8 clean
  (0 errors), `composer audit` clean.

## Post-launch — Stock visibility at review/issue time, and a more useful dashboard (2026-09-21)

User-asked: should the reviewer of a requisition (a SCIENTIST deciding "พิจารณาใบขอเบิก")
see the item's current stock, so they aren't deciding blind? Confirmed yes — then
user-refined the scope after discussion: keep the review page simple (current balance
only, not a cross-requisition demand total), move the actual *low-stock warning* to the
point where it's actionable (issuing, since that's who'd go restock), and make the home
dashboard show *what* is running low/expiring, not just a bare count — "เหมือนแบบว่า Login
เข้าระบบมาปุ๊บ ... เห็นเลยว่ามีอะไรใกล้จะหมดบ้าง".

- **New `StockBalanceService`** (`app/Domain/Inventory/Services/StockBalanceService.php`)
  — `currentBalance(Item)` and `isBelowReorderPoint(Item, ?balance)`. The
  `StockLedger::where('item_id', ...)->orderByDesc('id')->value('balance_base')` query
  this wraps was already duplicated inline in 7 places with no shared helper
  (`RequisitionController::itemBalance()`, `DashboardService`, `BelowReorderPointExport`
  ×2, `ReportsDashboard`, `NotifyReorderPointCommand`, `ItemStockSummaryExport`) —
  extracted now that two new consumers need it, rather than adding an 8th inline copy.
  The 7 pre-existing call sites are deliberately left alone (already tested and working;
  not a blanket refactor).
- **Requisition review page** (`requisitions/show.blade.php`): the lines table gained a
  "คงเหลือปัจจุบัน" column — each line's item's current stock balance, fetched in
  `RequisitionController::show()`. Simple balance only, per user's explicit scope call —
  no aggregate of what other pending requisitions are also asking for (that would need a
  new `requisition_items` aggregate query that doesn't exist yet; deliberately not built).
- **Requisition issue page** (`requisitions/issue.blade.php`): each line now shows a
  "⚠️ สารนี้ใกล้หมด" warning when the item's current balance has dropped below its
  `reorder_point_base` — computed per line in `RequisitionIssueController::create()` via
  `StockBalanceService::isBelowReorderPoint()`, the same reorder-point convention
  `BelowReorderPointExport`/`DashboardService` already use (not the unrelated
  `ReportsDashboard::LOW_STOCK_REMAINING_PERCENT` convention, which measures something
  different — remaining share of period usage, not balance-vs-reorder-point).
- **Dashboard**: `DashboardService::belowReorderPointCount()`/`expiringWithin30DaysCount()`
  now delegate to new `belowReorderPointItems()`/`expiringWithin30DaysContainers()`
  methods (same public count signatures, no behavior change there) that also return the
  actual rows. `DashboardController` passes the top 5 of each (most urgent first — lowest
  balance-to-reorder-point ratio for stock, soonest-expiring first for containers) to the
  view. `home.blade.php` gained two new list cards naming the actual items/containers,
  each linking to the matching Reports tab when more than 5 are flagged.
- Verified: new `StockBalanceServiceTest` (4 tests), 2 new `RequisitionIssueControllerTest`
  cases (warns below reorder point, silent above it), 1 new `ScientistDecisionTest` case
  (review page shows the real balance), 2 new `DashboardServiceTest` cases (both list
  methods, correctly ordered), 1 new `DashboardControllerTest` case (the dashboard names
  the actual flagged items, not just a count). Full suite green (524 tests), Pint clean
  (368 files), PHPStan level 8 clean (0 errors), `composer audit` clean.

## Post-launch — Sidebar: no way back to the dashboard once you navigated away (2026-09-21)

User-reported: after landing on `/` right after login, there was nothing in the sidebar
to get back to it — every other page (`items`, `requisitions`, `reports`, …) had a nav
link; the dashboard itself didn't.

- **`/` is now a named route** (`->name('dashboard')`) — it had none before, so nothing
  could `route()` to it.
- **New "หน้าแรก" (Home) link at the top of the sidebar**, above the existing nav groups,
  highlighted when active — plus the logo/app-name block at the very top of the sidebar
  (previously a plain, non-clickable `<div>`) is now also a link to the same place, the
  conventional "click the logo to go home" affordance most users already expect.
- Verified: new `DashboardControllerTest` case — the dashboard link (and its `href`)
  appears in the sidebar while viewing a completely different page (`/items`), not just on
  the dashboard itself. Full suite green (525 tests), Pint clean (368 files), PHPStan
  level 8 clean (0 errors), `composer audit` clean.

## Post-launch — Dashboard's top-items list now shows quantity, not just a count (2026-09-21)

User-requested (from a live production screenshot): "รายการที่ใช้มากที่สุด" only showed a
transaction count ("3 ครั้ง") — no sense of how much was actually issued. Confirmed this
doesn't conflict with this project's established "never sum a quantity across different
items" rule (mg/mL/pcs are incompatible) — the *ranking* still stays frequency-based; the
new number is each row's own item's own total, never combined with another item's.

- `DashboardService::topIssuedItems()` now also sums `qty_issued_base` per item (BCMath,
  AGENT RULE #2) across its own issue transactions in the trailing 3-month window, next to
  the existing issue-frequency count — same ranking as before (by count, descending),
  unchanged.
- `home.blade.php`'s top-items list now shows e.g. "3 ครั้ง (3 g)" per row instead of just
  "3 ครั้ง".
- The monthly issuance chart (the other thing asked about) is kept as-is — user confirmed
  it's likely useful for ADMIN/managers tracking workload statistics, not asked to change.
- Verified: extended the existing `DashboardServiceTest` "top issued items" case to assert
  the summed quantity for both the most- and least-frequent item. Full suite green
  (525 tests), Pint clean (368 files), PHPStan level 8 clean (0 errors), `composer audit`
  clean.

## Post-launch — SSO login crash on empty email: Duplicate entry '' for key 'users_email_unique' (2026-09-21)

User-reported bug (with production screenshot): User ID 18 (`surachet.ku`) encountered an
`Illuminate\Database\UniqueConstraintViolationException: Duplicate entry '' for key 'users_email_unique'`
upon logging in via UP SSO.

- **Root Cause**: In UP SSO, some accounts return an empty string `""` or null for the email attribute.
  `SsoUserData` previously cast `$payload['email']` directly to string without sanitization, and
  `UserProvisioningService` wrote it straight to `$user->email = $data->email`. Because another record
  (User ID 5, `apisit.ph`) previously held an empty string `''` in the database, updating any second user
  with an empty string triggered MariaDB's unique constraint `users_email_unique`.
- **Database Hygiene**: Updated User ID 5's email to `apisit.ph@up.ac.th`. Confirmed all 108 existing users
  now have valid emails and zero empty strings exist.
- **Code Fix**:
  - `SsoUserData::fromArray`: Falls back to `{$username}@up.ac.th` whenever SSO payload email is empty,
    `'-'`, or not a valid email address (matching the convention in `ImportMscAccUsersCommand`).
  - `UserProvisioningService::provision`: Ensures existing user emails are never overwritten with an empty
    or invalid string, and defaults to `{$username}@up.ac.th`.
  - Verified: 3 new `SsoLoginTest` feature tests covering missing SSO email fallback, multiple empty-email
    SSO logins without collision, and email preservation for existing accounts; Pint clean; PHPStan clean.

## Post-launch — AUDITOR (ผู้ดูแลคลัง) was not branch-scoped in LabInventoryTable and ItemController (2026-09-21)

User-reported: User ID 18 (`surachet.ku`), holding the `AUDITOR` ("ผู้ดูแลคลัง") role for Lab 005 (สรีรวิทยา),
was still able to see other branches' storage/chemical containers in "คลังสารเคมีของฉัน (สต็อกคงคลัง)" (`stock-in.index`)
and "รายละเอียดสารเคมี" (`items.show`).

- **Root Cause**: When AUDITOR was repurposed from a read-only oversight auditor to a branch-scoped warehouse manager
  (matching LAB_MANAGER), `LabInventoryTable::canPickAnyLab()` and `ItemController::show()` still contained the legacy check
  `$user->hasRole('ADMIN') || $user->hasRole('AUDITOR')`. This marked AUDITOR as cross-branch privileged, leaving `$labId` null
  and displaying containers across all branches instead of restricting to `$user->lab_id`.
- **Fix**:
  - `LabInventoryTable::canPickAnyLab()`: Restrict to `$user->hasRole('ADMIN')` only. AUDITOR is now strictly scoped
    to their own `lab_id` (matching LAB_MANAGER and SCIENTIST).
  - `ItemController::show()`: Restrict privileged cross-branch container viewing to `$user->hasRole('ADMIN')` only.
  - Verified: 2 new `RoleScopingTest` cases asserting AUDITOR only sees containers within their own branch in both
    `stock-in.index` and `items.show`; Pint clean; PHPStan clean.

## Post-launch — Allow all roles (SCIENTIST, LAB_MANAGER, AUDITOR, ADMIN, ADVISOR) to create requisitions (2026-09-21)

User-requested: "ปรับให้ทุกตำแหน่งเบิกของได้ด้วยครับ ตอนนี้เทสนักวิทย์กับผู้ดูแลเบิกของไม่ได้ ปุ่มไม่ขึ้น"
Previously, only STUDENT and STAFF had `requisition.create` and `requisition.view_own` grants in `PermissionSeeder`.
Personnel holding operational roles (such as SCIENTIST, LAB_MANAGER, AUDITOR/ผู้ดูแลคลัง, ADMIN) could not see the
"+ สร้างใบขอเบิกใหม่" button and were rejected with HTTP 403 when navigating to `/requisitions/create`.

- **`PermissionSeeder`**: Added `requisition.create` and `requisition.view_own` to `SCIENTIST`, `LAB_MANAGER`, `AUDITOR`,
  `ADMIN`, and `ADVISOR` grants. Synchronized permissions in live database.
- **`RequisitionCrudTest`**: Updated 403 test to use a roleless user and added test cases verifying SCIENTIST,
  LAB_MANAGER, and AUDITOR can access the create requisition page.
- Verified: Full suite compatibility; Pint clean.

## Post-launch — Restrict requisition review/decision to Warehouse Manager (AUDITOR) and Lab Manager (2026-09-21)

User-reported: "ในการพิจารณาใบของเบิก ให้ผู้ดูแลคลังพิจารณาเท่านั้น ตอนนี้นักวิทย์พิจารณาได้เฉยเลย"
Previously, `requisition.approve_scientist` was held by SCIENTIST, and not by AUDITOR (ผู้ดูแลคลัง) or LAB_MANAGER.
This allowed scientists to approve/reject requisitions instead of the designated warehouse manager.

- **`PermissionSeeder`**:
  - Removed `requisition.approve_scientist` from `SCIENTIST` role (detached in live database).
  - Added `requisition.approve_scientist` to `AUDITOR` (ผู้ดูแลคลัง) and `LAB_MANAGER` (หัวหน้าสาขาวิชา).
  - Catalog label updated to `พิจารณาใบขอเบิก (ผู้ดูแลคลัง)`.
- **`RequisitionPolicy`**: Added strict branch-scoping check (`if ($user->isBranchManager() && $requisition->lab_id !== $user->lab_id) return false;`)
  so a warehouse manager can only decide requisitions within their own branch.
- **`DashboardService`**: Scoped pending decision and pending issuance counts to `$user->lab_id` when the viewer is a branch manager.
- **`lang/th/requisitions.php`**: Updated `scientist_decision_title` to `'พิจารณาใบขอเบิก (ผู้ดูแลคลัง)'`.
- **`ScientistDecisionTest`**: Updated all review/decision feature tests to assert that AUDITOR can decide, non-branch AUDITOR gets 403,
  and SCIENTIST gets 403.
- Verified: Live database synced; Pint clean.

## Post-launch — Restrict requisition visibility to own requisitions except Warehouse Managers (2026-09-21)

User-reported: "ในส่วนของใบขอเบิก เห็นเฉพาะของตัวเอง ยกเว้นผู้ดูแลคลัง เห็นทั้งหมดครับ"
Previously, `requisition.view_all` was granted to `SCIENTIST`, allowing scientists to see all requisitions across the system.
Meanwhile, only warehouse managers (`AUDITOR` / `LAB_MANAGER`) should see all requisitions in their branch, and `ADMIN` across all branches.
All other users (including students, staff, and scientists) must only see their own requisitions (as requester or advisor).

- **`PermissionSeeder`**:
  - Removed `requisition.view_all` from `SCIENTIST` role (detached in live database).
  - Added `requisition.view_all` to `ADMIN` role.
  - Retained `requisition.view_all` on `AUDITOR` ("ผู้ดูแลคลัง") and `LAB_MANAGER` ("หัวหน้าสาขาวิชา") which is already branch-scoped by `User::isBranchManager()`.
- **`RequisitionPolicy`**:
  - Updated docblocks to reflect that only AUDITOR, LAB_MANAGER, and ADMIN hold `requisition.view_all`.
- **Tests**:
  - Updated `RequisitionLabScopeTest`: Verified that AUDITOR can view requisitions within their branch and gets 403 outside their branch; verified that the requisition table only shows an AUDITOR their own branch; verified that a SCIENTIST only sees their own requisitions in the table and gets 403 viewing other users' requisitions.
  - Updated `RequisitionCrudTest`: Updated `view_all` assertion to use `auditorUser` instead of `scientistUser`.
- Verified: Live database synced; 101/101 Pest tests green; Pint clean.

## Post-launch — Restrict stock-in and receiving to Warehouse Managers (2026-09-21)

User-reported: "ทำไมนักวิทย์ถึงเติมสต็อกได้หละ ผมบอกว่าให้เฉพาะผู้ดูคลังไม่ใช่ไง"
Previously, `receiving.manage` ('รับของเข้าคลัง') was assigned to `SCIENTIST` in the initial spec grants, which permitted
scientists to perform stock-in (`/stock-in/store`) and Goods Receipts (`/goods-receipts`). Furthermore, the "+ เติมสต็อก"
button on the chemical detail view (`items.show`) was rendered without a permission gate, and `StockInController::create`
lacked an explicit controller-level authorization gate.

- **`PermissionSeeder`**:
  - Removed `receiving.manage` from `SCIENTIST` role (detached in live database).
  - Granted `receiving.manage` and `stocktake.manage` to `AUDITOR` ("ผู้ดูแลคลัง"), `LAB_MANAGER` ("หัวหน้าสาขาวิชา"), and `ADMIN`.
- **`StockInController`**:
  - Added authorization check in `create()` ensuring only users with `receiving.manage`, `item.manage`, `ledger.adjust`, or `ADMIN` can access `/stock-in/create`.
- **`items.show`**:
  - Wrapped `+ เติมสต็อก` button inside an authorization condition so non-warehouse roles (including scientists) do not see the action.
- **Tests**:
  - Updated `StockInTest`: Added test asserting scientists get 403 on `/stock-in/create` and `/stock-in/store`; updated successful stock-in tests to use `AUDITOR`.
  - Updated `GoodsReceiptCrudTest`: Asserted scientists/students get 403 on GRN endpoints; updated valid operations to use `AUDITOR`.
  - Updated `ChemicalPropertiesWorkflowTest`: Used `AUDITOR` to access `/stock-in/create`.
- Verified: Live database synced; Pint clean; full test suites green.

## Post-launch — Scope Dashboard requisitions and analytics cards to branch / user (2026-09-21)

User-reported: "หน้า dashboard ด้วยนะครับ"
Following the earlier requisition and warehouse scoping changes, the main dashboard (`/`) still had:
1. `pendingRequisitionsCount`: Counted pending approved requisitions system-wide for scientists because they held `requisition.issue`, rather than counting only their own pending requisitions.
2. Analytics cards (`belowReorderPointItems`, `expiringWithin30DaysContainers`, `topIssuedItems`, `monthlyIssuanceSeries`): Were evaluated globally without branch scoping, causing warehouse managers (`AUDITOR`) to see inventory and alerts from all branches.

- **`DashboardService`**:
  - `pendingRequisitionsCount`: Non-warehouse managers (users without `requisition.view_all`) now strictly count only their own pending requisitions (plus advisees for `ADVISOR`). Warehouse managers (`AUDITOR`/`LAB_MANAGER`) count pending requisitions within their branch (`lab_id`).
  - `belowReorderPointItems`, `expiringWithin30DaysContainers`, `topIssuedItems`, `monthlyIssuanceSeries`: Added optional `$labId` parameter to filter by the user's branch.
- **`DashboardController`**:
  - Passes `$labId = $user->isBranchManager() ? $user->lab_id : null` to all dashboard analytics calls.
- **`home.blade.php`**:
  - Made the top metric cards ("ใบเบิกรอดำเนินการ", "รายการต่ำกว่าจุดสั่งซื้อ", "ภาชนะใกล้หมดอายุ") clickable links to direct users to their respective index/reports pages.
- **`DashboardControllerTest`**:
  - Added test verifying scientists only see their own pending requisitions count.
  - Added tests verifying warehouse managers (`AUDITOR`) only see pending requisitions and expiring containers within their branch.
- Verified: Pint clean; full test suite green.

## Fix — Stale tests after the 2026-09-21 warehouse-manager restructuring (2026-09-22)

Running the full suite after merging the seven server-side commits from 2026-09-21 surfaced five
failures across four files. Four of them were tests written against the *old* permission model and
one was a real bug in a newly added test. No application code changed — every failure was in the
test layer, and the behavior each test now asserts is the behavior the merged commits deliberately
introduced.

- **`DashboardServiceTest`** (2 cases): both asserted a SCIENTIST sees the system-wide approval and
  issuance queues. `requisition.approve_scientist` and `requisition.view_all` were both removed from
  SCIENTIST on 2026-09-21, so `pendingRequisitionsCount()` now correctly routes a SCIENTIST down the
  own-requisitions-only branch. Rewritten against `auditorUser()` with an explicit `lab_id`, since
  that branch is also lab-scoped via `User::isBranchManager()`.
  - The second case exercises `pendingRequisitionsCount()`'s `requisition.issue` sub-branch, which no
    seeded role can currently reach (`LAB_MANAGER`/`AUDITOR`/`ADMIN` hold `requisition.view_all` but
    only `requisition.issue_override`, never plain `requisition.issue`). It now grants that one
    permission to the AUDITOR role inside the test so the service branch itself stays covered rather
    than being asserted through a role combination that no longer exists. **If that branch is ever
    intentionally retired, delete this test with it** — it is deliberately testing code that is
    currently unreachable in production.
- **`RequisitionNotificationTest`** (2 cases): both asserted the "pending review" notification pool is
  every SCIENTIST. `NotificationService::usersWithAnyPermission('requisition.approve_scientist')` now
  resolves to AUDITOR + LAB_MANAGER only, so the fixtures were switched to those two roles. The pool
  is deliberately *not* lab-scoped (unlike the dashboard count) — that is pre-existing behavior, not
  something this fix changed.
- **`StockTakeControllerTest`** (1 case): the "a user without `stocktake.manage` gets 403" negative
  case used `labManagerUser()`, but commit `e2a9e8b` granted `stocktake.manage` to LAB_MANAGER (and
  AUDITOR) as part of consolidating warehouse duties — so the fixture no longer lacked the permission
  it was meant to lack. Confirmed with the user 2026-09-22 that the grant is intentional; the test now
  uses `staffUser()` (a requester-only role) for the negative case instead.
- **`SsoLoginTest`** — a genuine bug in a test added by commit `6c8b475`, not a stale assumption: the
  "multiple users with empty SSO email" case called `fakeSsoVerifySuccess()` twice for the same URL,
  expecting the second call to replace the first. It does not. `Http::fake()` appends stubs and
  `PendingRequest::buildStubHandler()` resolves them with `->filter()->first()`, so the **first**
  matching stub wins for every subsequent request — both callbacks received user.one's payload, the
  second user was never provisioned, and the assertion died on `firstOrFail()`. Rewritten with
  `Http::fakeSequence()`, which returns responses in call order. The test must stay a single case
  (splitting it in two would lose the point: two empty-email users coexisting in one database state).
  **Any future test that fakes the same URL more than once in one case needs `fakeSequence()`** —
  a second `Http::fake()` for an already-stubbed URL is silently ignored.
- Verified: Pest 539/539 green, Pint clean (368 files), PHPStan level 8 clean, `composer audit` clean.
  The `zipstream-php` memory-exhaustion fatal seen during the first post-merge full-suite run did not
  reproduce across three subsequent full runs — treated as transient memory pressure from running the
  whole suite in one process, not a real defect; re-investigate only if it recurs in isolation.

## Fix — Nobody could reach the issue page; stock-in ignored the unit it was received in (2026-09-22)

Two user-reported problems, found while answering "ระบบเบิกจ่ายไม่น่าจะมีปัญหาแล้วนะ".

### The dispensing workflow was unreachable

`requisition.issue` is held by **SCIENTIST only**; `requisition.view_all` is held by
**LAB_MANAGER / AUDITOR / ADMIN only** — after 2026-09-21's restructuring these two sets no
longer overlap at all, and the whole dispensing path runs through pages gated on *viewing*:

- `RequisitionTable` filtered a non-`view_all` viewer down to `requester_id = me OR advisor_id
  = me`, so an approved requisition never appeared in the dispenser's list.
- `RequisitionPolicy::view()` returned false for it, so `/requisitions/{id}` answered 403.
- The only link to `/requisitions/{id}/issue` lives on that show page, so the issue page — whose
  own gate checks `requisition.issue` and would have passed — was reachable only by typing the
  URL from memory.
- `DashboardService::pendingRequisitionsCount()` counted the issuance queue *inside* the
  `requisition.view_all` branch, so the dispenser's own card read 0 no matter how much work was
  waiting. A warehouse manager who could see everything got 403 at the issue page instead.

Fixed by making "may act on it" a reason to see it, without widening anything else:

- **`RequisitionPolicy`**: new `canAct()` (issue-or-return), consulted by `view()`. A
  requisition is visible to whoever may actually dispense or accept a return against it —
  `APPROVED`, `PARTIALLY_ISSUED`, `ISSUED` only. A DRAFT or SUBMITTED requisition still
  awaiting a decision stays invisible, so 2026-09-21's visibility restriction is intact.
- **Dispensing is own-branch work** (user-decided 2026-09-22): the new `sharesBranch()` check
  lives on `issue()` and `return()` **themselves**, not on the pages that display them — scoping
  only the visibility would have left a dispenser able to POST against another branch's
  requisition simply by knowing its URL, which is the same hidden-button-still-works hole this
  whole entry is about. `RequisitionTable` and the dashboard count mirror the same rule.
  - This is deliberately *not* routed through `User::isBranchManager()`, which excludes
    SCIENTIST by design (it means "manages a branch", not "belongs to one"). Dispensing scopes
    on plain `users.lab_id`.
  - **A dispenser with no `lab_id` can now see and dispense nothing at all.** That is an
    account-configuration gap to fix at `/admin/users`, not a case to wave through — but it is
    silent, so a SCIENTIST reporting "ใบเบิกหายไปหมด" should have their branch checked first.
    Pinned by a test so the behavior can't drift unnoticed.
- **`DashboardService::pendingRequisitionsCount()`**: the issuance queue moved out of the
  `view_all` branch, so it counts for anyone holding `requisition.issue`. This restores T-046's
  original design ("sums every action-queue the viewer's permissions make them responsible
  for") which 2c3b076 had narrowed. `DashboardControllerTest`'s "a SCIENTIST only sees their
  own" case was updated accordingly — that assertion *was* the bug.
- **Existing dispensing tests needed branch-aligned fixtures**: every `scientistUser()` in
  `RequisitionIssueControllerTest`/`RequisitionReturnControllerTest` predates branch scoping and
  had no `lab_id`, so all 13 started 403'ing. They now take the requisition's own `lab_id` —
  worth knowing that **any future dispensing test must put the scientist in the requisition's
  branch**, or it will fail for a reason that has nothing to do with what it is testing.
- **Why no test caught it**: every existing dispensing test POSTs straight to the route or calls
  the service. None walked list → show → issue link the way a person does. The new
  `IssuerVisibilityTest` walks that path — the same class of gap already recorded for T-040.

### Stock-in rescaled a real receipt into the catalog's guessed unit

User-reported: `AS197823` was received in **mL** but stored and displayed as **L** — and real
dispensing for it happens at mL scale. `StockInController::store()` only backfilled
`base_unit_id` when it was `null`, and the chemical catalog import parses a unit out of a
free-text product name ("... 1 L /ขวด") — a statement about packaging, not about the scale
people work at. `UnitConverter::toItemBase()` then faithfully converted 500 mL into 0.5 L.

- **`StockInController`**: the first receipt against an item with **no ledger history** now
  adopts the unit it was received in, replacing a guessed catalog unit. New `adoptBaseUnit()`
  also rescales `reorder_point_base` (stored in the item's own base unit) so its meaning
  survives; `package_size` is left alone (it is expressed in `package_unit_id`, not base units).
  Crossing MASS↔VOLUME without a density clears the reorder point rather than silently
  asserting a threshold nobody set (1 g quietly becoming 1 mL).
- **Bounded on purpose**: once a single `stock_ledger` row exists the base unit is frozen, because
  every `_base` column is stored in the item's own base unit and the ledger is append-only
  (AGENT RULE #6) — changing the unit later would reinterpret rows that can never be rewritten.
  **An item already stocked in the wrong unit cannot be corrected in place**; it needs a new item
  record. `AS197823` on production is in exactly that state.
- Verified: Pest 548/548 green, Pint clean, PHPStan level 8 clean, `composer audit` clean.

## Post-launch — Reports and warehouse menus handed to the warehouse managers (2026-09-22)

User-requested: "ในหน้ารายงาน ผมอยากให้จำกัดเฉพาะสาขาของตนเอง มีแค่ผู้ดูแลคลังเท่านั้นที่ดูรายงานได้ และ admin
เห็นทั้งหมด และเลือกดูแต่ละสาขาได้ด้วย และเมนู ตรวจนับสต็อก ทำลาย/ตัดจำหน่าย ปรับปรุงยอด ให้เฉพาะผู้ดูแลคลัง
และ admin เท่านั้น"

### Permissions

- **SCIENTIST loses `report.view`, `stocktake.manage` and `disposal.request`.** Together with
  2026-09-21's changes, SCIENTIST is now purely the dispensing role: create and view their own
  requisitions, issue against approved ones in their own branch, and read the item ledger.
- **LAB_MANAGER / AUDITOR gain `disposal.request`** — they already approved disposals but could
  never raise one, so handing them the menu without this would have produced a queue nobody
  could feed.
- **ADMIN gains `report.view` and `disposal.request`** (and already had `stocktake.manage`).
- All three SCIENTIST removals were added to `PermissionSeeder`'s detach list;
  `syncWithoutDetaching()` is additive-only and would otherwise leave them in place on an
  already-seeded database.

### ADMIN reads these areas but never writes the ledger

Asked explicitly, because the alternative reversed a standing decision: spec §3 says ADMIN
"ไม่มีสิทธิ์แตะ Ledger" and this project has honored it since T-005. User chose to keep it
("เห็นได้ แต่อนุมัติไม่ได้"). So ADMIN can open all four areas and request a disposal or run a
stock-take round, but holds neither `ledger.adjust` nor `disposal.approve` and therefore cannot
approve an adjustment, a stock take, or a disposal — each of those writes to `stock_ledger`.

- **`StockLedgerPolicy::viewAdjustments()`** is new, splitting "read the adjustment history" from
  `adjust()` ("create one"). Without it the adjustments menu — gated on `adjust` — would have been
  invisible to ADMIN, and granting `ledger.adjust` to make it appear was exactly the reversal being
  avoided. The nav entry and `AdjustmentController::index()` use the new ability; the "new
  adjustment" button and `create()`/`store()` still require `adjust()`.

### Disposal lost its separation of duties, deliberately

The requester/approver split used to be enforced only by the two permissions living on different
roles (T-042 chose that over a BR-06-style distinct-actor check). Moving both onto the warehouse
managers collapses it, so the user was asked whether to add the explicit check instead and chose
not to ("คนเดียวทำได้จบ"). One warehouse manager can now request and approve the same disposal.
CLAUDE.md's T-042 note has been amended so the superseded reasoning isn't re-derived later.

### Reports scoping

No code change was needed: `ReportController::labIdFor()` and `ReportsDashboard::restrictedLabId()`
already force a branch manager's own `lab_id` over anything the query string carries, and the lab
picker already renders only for non-branch-managers. Removing `report.view` from SCIENTIST is what
makes "own branch only" true in practice, since SCIENTIST was the one `report.view` holder that
`User::isBranchManager()` does not cover. ADMIN is not a branch manager either — which is the
point: they see every branch and get the picker.

### Fixed: an intermittent out-of-memory fatal that had been masking a full-suite run

`phpunit.xml` now sets `memory_limit` to 512M. The container's CLI limit is 128M, which the whole
suite in one PHP process outgrows — the Excel writer then asks zipstream-php for a 16MB chunk and
dies. It only ever appeared partway through a full run, never when the export test ran alone,
which is what accumulation looks like rather than a leak; the real queued export already raises its
own limit to 1536M. **This had been hiding test failures**: the fatal aborted the run before
`tests/Feature/Notifications` was reached, so those tests were silently not running in full-suite
runs. They pass.

- Fixtures updated across `StockTakeControllerTest`, `DisposalControllerTest`, `ReportControllerTest`,
  `ReportsDashboardTest`, `DashboardControllerTest`, `NotificationServiceTest`,
  `NotifyExpiryCommandTest` and `RoleScopingTest` — all used `scientistUser()` for work SCIENTIST no
  longer does. New `WarehouseMenuAccessTest` pins who reaches each of the four areas, that the menus
  disappear from a SCIENTIST's sidebar, and that ADMIN gets the adjustment list without the form.
- Verified: Pest 560/560 green, Pint clean (370 files), PHPStan level 8 clean, `composer audit` clean.
  `PermissionSeeder` re-run against the dev database.

## Post-launch — New report: per-chemical dispensing history (2026-09-22)

User-requested: "รายงานการขอเบิกสารเคมีแต่ละตัวด้วยครับ ว่าใครเบิก วันที่เบิก จำนวนเท่าไหร่ และสรุปยอดคงเหลือด้วยครับ" —
a stock-card view of one chemical: every dispensing against it (who, when, how much) plus what
is left right now. New tab "ประวัติการเบิกรายสาร" on the reports page, between the existing
stock-summary and usage-summary tabs.

- **`ItemIssueHistoryExport`** (new) — takes one `Item`, an optional date range and an optional
  `lab_id`. `results()` walks `IssueTransaction` (not `requisition_items`), so a requisition that
  is APPROVED but never issued correctly does not appear — it moved no stock. `totalIssued()`
  sums exactly the rows `results()` returns (not the item's all-time total), so the on-screen
  summary and the table below it always reconcile even when a date range or branch narrows
  what's shown. `remainingBalance()` reads the same `stock_ledger` tail every other report uses —
  global per item, same as `ItemStockSummaryExport`, since the schema has no per-lab split.
- **User-decided**: quantities are what was actually **dispensed**, not requested — asked directly
  because the two numbers can differ (BR-04 partial issuance), and "dispensed" is what actually
  changed the balance shown beside it.
- **User-decided**: the page picks one chemical at a time (search by name or code, the catalog
  runs to thousands of rows) rather than listing every chemical's history on one page.
- `ReportsDashboard` gained the `item_issue_history` tab, an item search/picker, and the
  date-range + lab filters every other tab already has. `ReportController::itemIssueHistoryExcel()`
  is the matching download route — same `report.view` gate, same branch-forcing via `labIdFor()`
  as every other export.
- **Real ordering bug found by the full suite, not the isolated test**: `results()` originally
  sorted by `issued_at` alone. Two dispensings created within the same second (routine under
  Pest's fast fixtures) could come back in either order, which the isolated test never triggered
  but the full suite did once — fixed by adding `id` as the tiebreaker, so the same report can't
  silently reorder itself between runs.
- Verified: Pest 568/568 green, Pint clean (372 files), PHPStan level 8 clean, `composer audit`
  clean.

## Fix — Dispensing-history item picker sourced from the chemical catalog, not real stock (2026-09-22)

User caught this immediately after the report shipped: "ทำไมไม่เอามาจากข้อมูลที่เบิกจริงหละ ไปดึงสารเคมีในทะเบียนมาทำไม".
The picker's first version listed every active `Item`, so it offered thousands of chemicals
this branch has never physically held. First fix scoped it to items with a real
`IssueTransaction`; the user then redirected once more: "ไปดึงข้อมูลในคลังสารเคมีของฉัน (สต็อกคงคลัง) ก็ได้
แบบแยกสาขาด้วยนะ" — source it from the same real in-stock inventory `LabInventoryTable`
("สต็อกคงคลังย่อยของฉัน") already shows, not from dispensing history either.

- `ReportsDashboard::historyCandidates()` now filters to items with at least one `SEALED`/
  `IN_USE` container holding `remaining_qty_base > 0`, branch-scoped via the container's
  `location.lab_id` — the exact same query shape `LabInventoryTable` already uses for "what's
  physically in my branch's warehouse right now". A chemical that has been fully dispensed,
  disposed, or never stocked in this branch is correctly absent from the picker even if it has
  stock elsewhere.
- Tests rewritten to match: the picker is asserted against real containers/locations, not
  `IssueTransaction` fixtures.
- Verified: Pest 570/570 green, Pint clean, PHPStan level 8 clean, `composer audit` clean.

## Post-launch — PDF export for the dispensing-history report, styled like F-03 (2026-09-22)

User-requested: "ในการ export รายงาน ให้อ้างอิงตามแบบฟอร์ม F-03 ได้ไหมครับ" — clarified to mean the
document's visual style (title, item info header block, bordered table), not a merge with F-03's
own ledger data. F-03 covers every ledger transaction type for an item; this report is
specifically dispensing history, so its own columns (date/doc no/requester/faculty/qty issued)
stay as they are — only the printed form now looks the same as F-03.

- New `ItemIssueHistoryPdfService`, reusing `Fr03PdfService`'s header block fields (category,
  item, brand, grade) plus this report's own summary (total issued, current balance) inline in
  the header. Uses ordinary `WriteHTML()` (like `ControlledSubstancesPdfService`), not
  `Fr03PdfService`'s `Cell()`-based renderer — that optimization exists for a 100,000-row ledger
  and this report's row count is naturally bounded to one item's dispensing history.
- `ItemIssueHistoryExport::itemFor()` (new) exposes the item to the PDF service, which needs the
  item's own fields alongside the rows the export already produces.
- New route `reports.item-issue-history.pdf`, same `report.view` gate and branch scoping via
  `labIdFor()` as the Excel route. "ดาวน์โหลด PDF" now sits next to "ดาวน์โหลด Excel" on the tab.
- Verified: Pest 572/572 green, Pint clean (373 files), PHPStan level 8 clean, `composer audit`
  clean.

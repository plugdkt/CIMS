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

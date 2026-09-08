# CMIS — Chemical & Material Inventory System

Full spec (single source of truth): [`_spec.md_AI_Agent_.md`](_spec.md_AI_Agent_.md).
SSO protocol details: [`sso_integration_guide.md`](sso_integration_guide.md), [`sso_client_quickstart.md`](sso_client_quickstart.md).

Read the full spec's `§0 AGENT OPERATING RULES`, `§13 BACKLOG`, and `§12 DEFINITION OF DONE` before implementing
any task. Work the backlog in order — do not skip ahead. If a requirement is ambiguous, stop and ask; do not guess.

## Stack

- PHP 8.3 target in spec; **actually running PHP 8.3 via Docker** (see below) — spec also said Laravel 11, but
  Laravel 11 currently has unpatched CVEs (including one affecting signed URLs, which this app uses for advisor
  approval links), so **this project runs Laravel 13** instead (user-approved deviation, 2026-08-31).
- MariaDB 11.4, Redis 7, Blade + Livewire 3 + Tailwind 3 + Alpine.js.
- Everything runs in Docker (`docker-compose.yml` + `docker/php/Dockerfile`) — no local PHP/Composer needed.
  The host also has XAMPP (PHP 8.2, unrelated `Co_education` project) — left untouched.

## Non-negotiable rules (from spec §0)

1. No `FLOAT`/`DOUBLE` for quantities — `DECIMAL(18,6)` only.
2. No native PHP float math for quantities — `BCMath` only (scale = 6).
3. No raw concatenated SQL — Query Builder / Eloquent / prepared statements only.
4. No `$guarded = []` — every Model declares `$fillable`.
5. No `{!! !!}` in Blade unless purified, with a comment explaining why.
6. `stock_ledger` and `audit_logs` are append-only — never UPDATE/DELETE, in any circumstance.
7. Every controller action needs a Form Request (validation) + a Policy (authorization).
8. Every route sits behind `auth` middleware unless spec §7.2 lists it as public.
9. Public URL identifiers are ULIDs, never auto-increment IDs.
10. Every feature ships with tests — no test means the task isn't done.
11. All UI text goes through `lang/th/*.php` — no hardcoded Thai in Blade/Controllers.
12. Commits use Conventional Commits (`feat(ledger): ...`, `fix(auth): ...`).

## Layering (spec §4.2)

Controller → Service → Model. Controllers hold no business logic. Services never see `Request`/`Response` (DTOs
in/out). `stock_ledger` is written from `LedgerService` only, nowhere else.

## Local dev environment

```bash
docker compose up -d              # app (:8090), mariadb (:3309), redis (:6379)
docker compose exec app php artisan ...
docker compose exec app composer ...
docker compose exec app vendor/bin/pest
docker compose exec app vendor/bin/pint --test
docker compose exec app vendor/bin/phpstan analyse
```

App container runs `php artisan serve` on port 8000 internally, forwarded to `localhost:8090`.
`.env` doubles as the docker-compose env file (same DB/Redis credentials on both sides).

**Two databases exist on purpose**: `cmis` (dev data — the `mariadb` container's `MARIADB_DATABASE`) and
`cmis_testing` (created manually, see deviations below). Tests use `cmis_testing` exclusively via
`phpunit.xml`'s `DB_DATABASE` override. Never point tests at `cmis` — see the `RefreshDatabase`/
`migrate:fresh` note below for why.

## Definition of Done (spec §12, summarized)

Every task needs: migration+seeder if relevant, Form Request + Policy for every input/resource, Unit + Feature
tests (happy path + error path), `php artisan test` green, `phpstan analyse --level=8` clean, `pint --test` clean
(PSR-12), `composer audit` clean of High+ vulnerabilities, no hardcoded Thai strings, no secrets in code, and
`CHANGELOG.md` updated.

## User-approved deviations / judgment calls (so future sessions don't re-litigate)

- Laravel 13, not 11 (Laravel 11 has unpatched CVEs, one of which hits signed URLs — used for
  advisor approval links). User-approved 2026-08-31.
- Livewire **3.8** and Tailwind **3.4** kept as spec pins — both install cleanly against Laravel 13
  with no advisories/conflicts, unlike Laravel 11. (The Laravel 13 skeleton defaults to Livewire-adjacent
  tooling being newer — Tailwind ships as v4 by default — but neither forced a version bump the way
  Laravel's CVEs did, so spec's pin wins.)
- Fonts (Bai Jamjuree/Sarabun/IBM Plex Mono) are self-hosted under `public/fonts/` + `resources/css/fonts.css`
  (thai+latin subsets only), not linked from Google Fonts — keeps the strict `font-src 'self'` CSP
  (§9.6) intact with zero exceptions.
- App-wide encryption cipher is `AES-256-GCM` (`config/app.php` `cipher`), not Laravel's CBC default —
  satisfies SEC-CR-05's explicit "AES-256-GCM" requirement for `phone_encrypted`/`person_code_encrypted`
  via Eloquent's built-in `encrypted` cast, rather than a bespoke encrypter for just those two columns.
- BR-11 point 4 ("กรอกข้อมูลเพิ่มเติม" / complete-profile gate) is scoped to **requisition creation only**
  (STUDENT/STAFF/lecturer roles), not a global post-login gate for every role — SCIENTIST/LAB_MANAGER/
  ADMIN/AUDITOR never touch program/faculty/student-code fields, so gating them made no sense. This
  reads BR-11 point 4 as the controlling, more specific rule over FR-AU-05's broader wording. User-approved
  2026-08-31. The actual redirect-if-incomplete enforcement lives in the requisition-creation flow (T-031),
  not in global middleware — `EnsureRoleAssigned` is the only global post-login gate.
- CSP (`SecurityHeaders` middleware) carries `'unsafe-eval'` in `script-src` — a deliberate, user-approved
  exception to spec §9.6's literal "ห้ามใส่ unsafe-inline หรือ unsafe-eval ใน script-src เด็ดขาด". Root cause:
  Livewire 3 is built directly on Alpine.js's expression engine (not just bundled alongside it) — every
  `wire:click`/`wire:model`/`x-data` evaluates its expression via the same eval-based mechanism, and neither
  project ships a CSP-strict build. There is no way to keep Livewire's reactivity (spec's own chosen stack)
  without this. `style-src` also carries `'unsafe-inline'` (spec's rule names `script-src` only) because
  Livewire's core JS sets a few inline styles itself for loading/dirty state — and per the CSP spec, a
  nonce present in a directive makes browsers ignore `'unsafe-inline'` in that *same* directive, so
  `style-src` has no nonce at all (only `script-src` does). The `script-src` nonce is still required and
  enforced — arbitrary injected `<script>` tags without it still don't run. User-approved 2026-08-31.
- Mobile nav drawer uses a plain `<input type="checkbox">` + `peer-checked:` CSS toggle, not Alpine
  `x-data`/`x-show` — written before the unsafe-eval decision above, and left as-is since it works without
  any JS at all. The complete-profile page's conditional advisor field uses a small nonce'd inline
  `<script>` (vanilla JS) for the same reason.
- The app shell layout lives at `resources/views/components/layout.blade.php` (not `layouts/`) so it works
  both ways: Livewire full-page components use `#[Layout('components.layout')]` (populates `$slot`), and
  plain Blade views (e.g. the item create/edit forms) wrap content in `<x-layout>...</x-layout>` tags —
  same file, same chrome, two invocation styles. `layouts/guest.blade.php` stays classic `@extends`/`@yield`
  since nothing Livewire-driven uses it.
- Item search (FR-MD-07) is `LIKE`, not `MATCH AGAINST` on the `ft_items` FULLTEXT index — MySQL/MariaDB's
  default FULLTEXT parser tokenizes on whitespace, and Thai text has none, so a name like
  "โซเดียมไฮดรอกไซด์" is one token and a partial-word query never matches via natural-language FULLTEXT
  search. Found via a failing test, not guessed. The index stays (spec's DDL calls for it) but future
  full-text search work should expect the same limitation for any Thai-text column.
- **Tests run against a dedicated `cmis_testing` database, never the shared dev `cmis` one.** Originally
  `phpunit.xml` had no DB override, on the theory that `RefreshDatabase`'s per-test transaction rollback
  made sharing safe. That was wrong: `RefreshDatabase`'s *first* run in a process calls `migrate:fresh`,
  which is DDL and auto-commits in MySQL/MariaDB — it is NOT undone by the later per-test rollback. Every
  `php artisan test` run was silently wiping the whole dev database. This deleted a manually-granted
  ADMIN role (and all seed data) twice in production use before being caught. Fixed 2026-08-31: created
  `cmis_testing` (`docker compose exec mariadb mariadb -uroot -pcmis_root_secret -e "CREATE DATABASE
  cmis_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON cmis_testing.*
  TO 'cmis_app'@'%';"`) and added `<env name="DB_DATABASE" value="cmis_testing"/>` to `phpunit.xml`. If
  `cmis_testing` is ever missing (e.g. a fresh clone of this repo on another machine), recreate it with
  that same command before running tests.
- **AGENT RULE #9 was violated and then fixed during T-014**: `Item` and `Attachment` had no
  `getRouteKeyName()` override, so `/items/{item}/edit` and `/attachments/{attachment}/download` were
  addressed by auto-increment `id`, not ULID. Neither PHPStan nor Pint catches this — it's a
  route-model-binding key choice, not a type or style issue — so it was only caught by eye during manual
  test setup. Fixed by adding `public function getRouteKeyName(): string { return 'ulid'; }` to both
  models; re-ran the full suite afterward (still 39/39 green). **Any new model added to a route
  parameter must get this override — it will not fail any automated check if forgotten**, so check for
  it explicitly during review of future tasks.
- **T-015 GHS/H/P statement reference data uses English official UN GHS wording, not Thai.** There is no
  verified official Thai government translation on hand for the ~160 hazard/precautionary statement
  codes (e.g. from กรมโรงงานอุตสาหกรรม) — for safety-critical text like this, shipping no translation is
  safer than shipping a guessed one. User-approved 2026-09-01. `config/ghs.php` holds the reference data
  (static, not a seeded table — it's a fixed external standard, not business data an admin edits).
- **GHS pictogram icons (`<x-ghs-icon>`) are hand-drawn SVG, not the official UN/CLP pictogram artwork.**
  User-approved 2026-09-01, to avoid downloading image files from external sources. They're simplified
  but follow the standard red-diamond-border convention with a recognizable glyph per hazard class.
- **T-016 added a `ulid` column to `locations`** — spec's §5.2 DDL has none (unlike `items`/`attachments`),
  but AGENT RULE #9 needs one for the location tree's ULID-addressed edit route. Empty table pre-T-016,
  so no backfill was needed. Same rule as the AGENT RULE #9 fix above: check for this on every new
  route-bound model, since spec's own DDL isn't a reliable source for it.
- **BR-10's incompatibility warning (FR-MD-05) is only partially wired as of T-016.** The literal rule
  ("warn when two items with conflicting storage_class end up in the same location") can't be checked
  against real data yet — no `containers`/goods-receiving flow exists until Phase 2 (T-022), so items
  are never actually assigned to a `location_id` in Phase 1. T-016 ships the reusable, unit-tested
  `LocationIncompatibilityChecker` (BR-10's pairing table, order-independent) and applies it at the one
  real signal available today — a location's own `storage_class` checked against its parent/siblings
  when the location tree itself is edited. T-022 must call the same checker again once container
  placement exists, this time against actual items sharing a location — that is the literal BR-10
  trigger and isn't satisfied by the T-016 wiring alone.
- **`labs` table is currently empty — no seeder and no CRUD UI.** Spec doesn't name any real University
  of Phayao labs to seed, and inventing fictional ones felt worse than leaving it empty (`lab_id` is
  nullable everywhere it's referenced, so this doesn't block anything). A real ADMIN/LAB_MANAGER needs
  a way to create real lab records — either a small future CRUD task or a one-time tinker seed once
  actual lab names are known.
- **T-017 ST-04 (IDOR: user A opens user B's private resource URL) has no real target to test against
  yet.** Spec's own example is a requisition, which doesn't exist until T-030. Nothing built in Phase 1
  is a per-user-owned resource addressed by URL — `Attachment` is owned by an `Item` and gated by
  role-wide `item.view`, not by uploader identity, so using it as an IDOR fixture would test the wrong
  thing. Deferred; write this test against whichever task first ships a genuinely user-owned, URL-
  addressed resource (T-030 requisition creation is the natural candidate).
- **Testing quirks worth remembering for future security tests (found during T-017):** Laravel's CSRF
  middleware (`PreventRequestForgery`) self-disables while `$app->runningUnitTests()` is true, so a
  plain `$this->post(...)` can never trigger a 419 in a Feature test — `CsrfProtectionTest` instantiates
  that exact middleware class directly with the bypass method overridden instead. Separately, hitting
  `/storage/{path}` in this app answers HTTP 403 (Laravel's built-in `storage.local` route refusing the
  private disk root), not 404 — spec's own ST-06 wording is "HTTP 404/403", so both are valid; don't
  hard-code 404 only when writing similar checks later.
- **Larastan infers every `decimal:N`-cast attribute as `float`, not the `string` Laravel actually returns
  at runtime.** Harmless until that value reaches a `bcadd`/`bcsub`/`bccomp`/`bcmul` call, which PHPStan's
  stubs require to be `numeric-string` — first hit in T-021 (`Container`/`StockLedger`'s quantity columns),
  the same class of fix `UnitConverter` (T-006) already needed for its plain string params. Fix: add
  `@property numeric-string $column` on the model (not plain `string` — that alone isn't enough) and
  `@param numeric-string $param` on every service method that feeds a bcmath call. **Any new model with a
  decimal column that gets bcmath'd directly needs this same treatment** — check for it when adding one.
- **T-022 added a small admin Labs CRUD** (`lab.manage` permission, granted to ADMIN) that spec never
  scheduled as its own backlog item. `goods_receipts.lab_id` is `NOT NULL`, and `labs` had no seeder and
  no UI (a gap flagged during T-016) — without at least one real lab, GRN creation is impossible. Asked
  the user directly: build the small CRUD vs. seed a placeholder. User-approved 2026-09-02: build the
  CRUD, still no fabricated lab names. `labs` also got a `ulid` column added (same AGENT RULE #9 gap
  `locations` had before T-016).
- **"Cancel a CONFIRMED GRN" is not implemented.** FR-RC-06 says a CONFIRMED GRN can't be edited and
  implies a mistake should be corrected by cancelling and re-creating, but by the time a GRN is CONFIRMED
  its containers and ledger `RECEIVE` rows already exist — and ledger rows can never be edited or deleted
  (AGENT RULE #6), so "cancel" at that point would need a deliberate reversal workflow (write-only, e.g.
  an `ADJUST_OUT` per container) that FR-RC-06 doesn't actually specify. `GoodsReceiptService::cancel()`
  only accepts a `DRAFT` GRN (nothing to reverse yet); `GoodsReceiptPolicy::update()` enforces the same
  DRAFT-only rule for edit/confirm/cancel alike. A real reversal mechanism needs its own explicit design
  before this can be closed — flag it if this need comes up in a later phase.
- **The dev `cmis` database now permanently contains one real (test) GRN/containers/ledger rows** from
  T-022's manual browser verification — item `CHM-GRN01` ("เอทานอล (ทดสอบรับของ)"), lab `LAB-CHEM-01`,
  and the `scientist_dev` user are all deactivated (`is_active = false`), but the GRN, its 2 containers,
  and their 2 `RECEIVE` ledger rows could not be removed — `stock_ledger` is append-only, so once written
  there's no way to delete them short of dropping the whole table. Harmless (self-evidently test data by
  name, doesn't affect any real item's balance), but don't be surprised to find it there.
- **T-023 label PDFs use mPDF's bundled `garuda` font for Thai text, not Sarabun** (left as-is — small
  barcode labels don't need brand-font consistency, and re-doing them isn't worth the churn). Real Sarabun
  in mPDF was solved properly in **T-025**: `public/fonts/` only has `.woff2`, split into separate Thai
  and Latin subsets for browser `unicode-range` (T-010) — mPDF's embedder needs one full TTF/OTF per style,
  not a browser-style split, so neither subset alone was usable. Fixed **without fetching any new font from
  anywhere** — the existing self-hosted files already contain everything needed, just packaged wrong for
  mPDF:
  1. `apt-get install woff2` (Debian's own package, gives `woff2_decompress`; a pure-Python route also
     works via `pip install fonttools brotli` — `fontTools.ttLib.TTFont(...).save(...)` decompresses
     woff2→ttf without the system tool).
  2. Decompress `sarabun-{400,700}-{thai,latin}.woff2` → four loose TTFs (400=Regular, 700=Bold).
  3. `python3 -m fontTools.merge --output-file=Sarabun-Regular.ttf latin.ttf thai.ttf` (and the same for
     Bold) — `fontTools.merge` unions non-overlapping glyph sets from multiple fonts into one; the Thai
     and Latin subsets don't share codepoints, so this cleanly produces one complete-coverage font per
     weight.
  4. Committed the two output files as `resources/fonts/pdf/Sarabun-{Regular,Bold}.ttf` (48KB each) and
     registered them with mPDF via `Mpdf\Config\FontVariables`/`fontDir` — see `MpdfFactory`.
  Confirmed empirically both ways: mPDF's own default (`dejavusanscondensed`) renders Thai as invisible
  tofu boxes; `garuda` (bundled, zero setup) renders correctly but isn't the brand font; the merged Sarabun
  TTFs render both Thai and Latin correctly, regular and bold. Any future mPDF document (T-037's F-01,
  etc.) should use `MpdfFactory::make()` rather than constructing `new Mpdf(...)` directly, to get Sarabun
  without repeating this setup. `woff2`/`python3-pip`/`fonttools`/`brotli` were installed straight into
  the running `app` container (not the Dockerfile) purely as one-time build tooling to produce those two
  TTF files — they're not a runtime dependency and won't survive a container rebuild, which is fine; the
  committed TTFs are the only durable artifact. If a different weight/style is ever needed, redo the same
  three steps against the matching `public/fonts/sarabun-*.woff2` pair.
- **`QrCodeGenerator` (T-023) has no consumer yet.** Built alongside `BarcodeGenerator` because the task
  itself is named "Barcode/QR generator", but FR-RC-04 (this task's actual feature) only calls for barcode
  labels. It's ready for T-037 (F-01 PDF, QR verify corner linking to `/verify/{ulid}`) — don't rebuild it
  there, just wire it in.
- **T-026 concurrency tests (CT-01/CT-02) deliberately do NOT use `RefreshDatabase`.** A single Pest
  test process is single-threaded — calling `LedgerService::issue()` in a loop inside one test would
  never contend the container's `lockForUpdate()` row lock the way spec's "50 requests พร้อมกัน" demands,
  since every call would trivially serialize inside that one process. `tests/Feature/Ledger/
  ConcurrencyTest.php` instead spawns real separate OS processes (`tests/Concurrency/bin/
  issue_once.php`, a standalone script — never a registered Artisan command, since an unauthenticated
  "issue stock directly" CLI command would be a permanent backdoor around the Policy/FormRequest layer
  if it ever shipped as a real command) via Symfony `Process`, each opening its own DB connection that
  genuinely races for the same row. This needs real committed fixtures visible across process
  boundaries, so these two tests skip `RefreshDatabase` entirely (Pest.php's global `$this->seed()`
  still runs and is safe — every seeder uses `updateOrCreate`). **Consequence: these tests leave
  permanent rows in `cmis_testing`** — the `stock_ledger` rows can never be deleted (append-only, and
  as of T-027 `cmis_app` no longer even has the DELETE grant), and the `Item`/`Container`/`User` rows
  those ledger rows FK-reference (`ON DELETE RESTRICT`, the migrations' default) can't be deleted either
  as a result. The test item is deactivated (`is_active = false`) for tidiness; everything else is left
  as self-evidently-test data, same trade-off as T-022's dev-DB note above. **This already broke one
  pre-existing test once**: `ItemLedgerTest.php` called `StockLedger::first()` assuming an empty table
  (previously true only because every other test's `RefreshDatabase` rolled its rows back) — fixed to
  `StockLedger::where('item_id', $item->id)->first()`. Any future test that queries `StockLedger`/
  `AuditLog`/`Container`/`Item` without scoping to its own fixtures can no longer assume the table
  starts empty — check for this same class of bug if a similar failure shows up later.
- **T-027's DB grant restriction is a manual post-migration step, not a `docker-entrypoint-initdb.d`
  script** — confirmed empirically that this MariaDB version's table-level `GRANT` requires the target
  table to already exist (`GRANT SELECT ON cmis.no_such_table TO ...` errors 1146 even when `cmis`
  itself exists), so a script placed in `docker-entrypoint-initdb.d` would fail before `php artisan
  migrate` ever creates `stock_ledger`/`audit_logs`. `docker/mariadb/restrict_app_grants.sql` must
  instead be run by hand, after migrating, against every database the app uses (`cmis` and
  `cmis_testing` both — see the command in the script's own header comment). It's idempotent and safely
  skips the `stock_ledger`/`audit_logs` grants for any database where those tables don't exist yet, so
  it's safe to run against `cmis_testing` even before that database has been created at all. Applied
  live to both databases 2026-09-02 (`cmis_app` confirmed via `SHOW GRANTS` to have only `SELECT,
  INSERT` on both append-only tables, full CRUD elsewhere, plus the DDL privileges `php artisan migrate`
  needs). **Any fresh clone of this repo must run this script by hand once, right after its first
  migration, on both databases** — nothing currently automates this reminder.
- **T-027 only restricts `cmis_app`'s own grants (SEC-DB-02's literal ask); it does NOT split the app
  into three separate DB users** (`cmis_app`/`cmis_ledger`/`cmis_report`) the way SEC-DB-01's broader
  wording suggests. That would need the app to manage multiple DB connections for what's currently one
  Eloquent connection — a real architectural change T-027's backlog title ("DB grant script") doesn't
  call for and ST-08's literal test doesn't need (ST-08 only asks that UPDATE on `stock_ledger` "ผ่าน DB
  user ของแอป" — through the app's own DB user — is rejected, which the single restricted `cmis_app`
  user already satisfies). Flag this if a later phase's spec reading calls for genuine per-purpose DB
  users.
- **"\_base" quantity columns throughout the schema (`containers.remaining_qty_base`,
  `stock_ledger.qty_*_base`/`balance_base`, `goods_receipt_items.qty_total_base`, and now
  `requisition_items.qty_*_base`) are stored in the *item's own* `base_unit_id` terms, not the
  dimension's absolute smallest unit** (mg/uL/pcs) that spec §5.1 literally names ("Dimension: MASS →
  base mg..."). This was already the real, tested, committed behavior as of T-018 —
  `GoodsReceiptService::calculateLineTotalBase()` always routes `toBase()` through the dimension's
  smallest unit only as an intermediate, then converts back out via `fromBase($dimensionBaseQty,
  $itemBaseUnit)` before storing. Confirmed during T-031 (found while reconciling this against
  `LedgerServiceTest`'s fixtures, which pass raw gram-scale numbers as "qtyBase" for an item whose
  `base_unit_id` is 'g', not mg) — not a new decision, just written down here since nothing had
  documented it before and a future task could easily "fix" it back toward the literal spec wording
  and silently break every existing `_base` value's scale. `LedgerService` itself is unit-agnostic (it
  never touches `UnitConverter` — it just does BCMath arithmetic on whatever numeric-string the caller
  already computed), so this convention lives entirely in the *callers* that compute a `_base` value
  before writing it.
- **T-031 extracted `UnitConverter::toItemBase(Item, Unit, qty)`** — the "convert to the dimension base,
  then back out to the item's own base unit, crossing dimensions via density if needed" round trip that
  `GoodsReceiptService::calculateLineTotalBase()` (T-018) already did inline. `calculateLineTotalBase()`
  now delegates to it (`bcmul(containerCount, qtyPerContainer)` then `toItemBase()`) — same public
  signature, same tested behavior, no test changes needed. Any future line-item-shaped quantity
  (T-035's issue flow, T-041's stocktake) should call `toItemBase()` directly rather than re-deriving
  this conversion a third time.
- **T-031's create/show flow is plain Controller + FormRequest + Blade + a touch of Alpine (`x-data`/
  `fetch()` for FR-RQ-05's real-time balance), not a Livewire component**, despite FR-RQ-04 asking for
  "เพิ่ม/ลบแถวได้" (add/remove rows) and FR-RQ-05 asking for a "real-time" balance display — both of
  which Livewire would handle natively. Chosen to stay consistent with T-022's GRN precedent (same
  add-line-via-full-POST, remove-line-via-DELETE-route shape) and with the project's established
  preference for the simplest tool that satisfies the requirement (same reasoning as the mobile nav's
  plain checkbox toggle and the complete-profile page's vanilla-JS conditional field, both noted
  above) — a handful of lines of Alpine calling one small JSON endpoint satisfies "real-time" without
  pulling the whole form into Livewire's request lifecycle. Reconsider this if a later requisition task
  (e.g. T-036's signature canvas, which is inherently more stateful) makes a stronger case for Livewire
  and it becomes worth converting the whole flow at once.
- **T-031's requester identity fields are never form input.** FR-RQ-01 says "auto-fill จาก profile" —
  read literally, that could mean pre-filled-but-editable fields. Instead `RequisitionController::store()`
  snapshots `requester_status`/`requester_phone`/`student_code`/`program`/`faculty`/`advisor_id`
  straight from the authenticated user's own columns server-side; the create form shows them read-only
  for the requester's own confirmation and `RequisitionRequest` doesn't even accept those keys. Chosen
  because these are exactly the fields BR-11's complete-profile page already owns as the single edit
  point — letting a student silently overwrite their own `faculty`/`program` on a random requisition
  form would undermine that gate and let a STUDENT's requisition claim a mismatched advisor. If a real
  need for a per-requisition override (e.g. a temporary contact number) surfaces later, add it as an
  explicit, separately-validated field rather than making the snapshot fields editable.
- **`Requisition::advisor_signature_hash` (T-032) has no spec-defined formula**, unlike BR-08's
  explicit ledger hash chain. Computed as `SHA256(requisition_id|advisor_id|decision|timestamp)` — a
  lightweight non-repudiation marker for a decision made without a drawn signature (that's T-036's
  e-signature canvas, for the *receiver* at issue time, not the advisor). Scoped to one decision, not
  a chain. Revisit if a later task needs to verify this hash rather than just record it.
- **BR-02's check lives in `ApprovalService::scientistDecide()` itself, checked directly against
  `advisor_signed_at`, not delegated to `RequisitionState`** — even though `RequisitionState` (T-030)
  already refuses a STUDENT's `SUBMITTED → scientistApprove` transition via its own requester-status
  check. The two checks aren't redundant: `RequisitionState` only knows the abstract state diagram
  (current `status` string + requester type), so it can't catch a hypothetical data-integrity bug
  where `status` is ADVISOR_APPROVED but `advisor_signed_at` is somehow still NULL. BR-02's literal
  wording checks the column, not the status, so `ApprovalService` checks both layers — this was
  spec's own explicit instruction ("Implement ที่ ApprovalService::scientistDecide()"), not redundancy
  to clean up later.
- **T-033's signed-URL POST target is the request's own current URL (`url()->full()`), not a freshly
  generated signed URL.** Laravel's signature verification is path+query based (via the `signed`
  middleware), independent of HTTP verb or route name — so the exact same signed URL used for the GET
  page load remains valid when that same URL is POSTed to (the form's `action` is literally `{{
  url()->full() }}`). This is why the GET and POST routes for `/approve/{requisition}` don't need to
  share a route name; only the GET one is named (it's the only one anything calls `route()` on).
- **T-033 builds both channels FR-RQ-07 names** ("อาจารย์อนุมัติผ่านระบบ หรือ Signed Link ทางอีเมล") even
  though the backlog title only says "Signed URL" — the in-system channel was cheap to add (one Policy
  ability + a form on the existing show page, reusing the same `ApprovalService::advisorDecide()`) and
  skipping it would have left half of FR-RQ-07 unimplemented. No separate task covers it elsewhere in
  the backlog.
- **T-033 does not build T-044's general notification infrastructure** (the `notifications` table,
  daily scheduled jobs, in-app bell, FR-NT-01/02/03/05/06). The advisor approval email is a direct,
  immediate `Mail::send()` call from `RequisitionService::submit()` — narrowly scoped to FR-RQ-07's own
  literal ask, not a queued digest or an in-app notification record. FR-NT-03 ("มีใบเบิกรออนุมัติ", the
  general "something needs approval" notice covering both the advisor and scientist stages) and
  FR-NT-04 (approval/rejection result notice back to the requester) are explicitly T-044's job and
  still open — the scientist stage (T-034) currently has no notification at all, by design, until then.
- **T-035 added `requisition_items.overage_approved_by`** (nullable FK to `users`) — spec's DDL has no
  column for BR-04's "over 10% needs LAB_MANAGER approval" and, unlike BR-06's adjustment approval
  (also uncovered, but that's a later phase not yet built), this rule needed enforcing *now*. Same
  pattern as T-016/T-022 adding a missing `ulid` column mid-task. A new permission,
  `requisition.issue_override` (granted to LAB_MANAGER only), gates who may be named as this approver —
  `IssueService` checks the given user actually holds it, not just that a distinct id was supplied.
- **FEFO ranking (T-035, BR-03) treats a container with a known `expiry_date` as higher priority than
  one with none, within the same status tier** — spec's own wording only defines the tie-break for two
  containers that both have NULL expiry ("received_at เก่าสุด"); it says nothing about ranking a NULL-
  expiry container against one with a real date. Read literally as FEFO ("first-expired-first-out"), a
  container that *will* expire is more urgent to use up than one that (as far as the system knows)
  never does — so known-expiry sorts first. Revisit if this reads differently once real inventory data
  makes the actual expectation clear (e.g. items that structurally never carry an expiry date, where
  this ordering might feel backwards).
- **`IssueService::issue()` defaults the receiver to the requisition's own `requester`** — spec doesn't
  detail who physically receives at issue time separately from who requested, and `issue_transactions.
  receiver_id` is `NOT NULL`. This is the common case (the student/staff picks up what they themselves
  asked for); T-036's e-signature/OTP layer is the natural place to let a *different* physical receiver
  be confirmed, if that need surfaces once signature capture exists.
- **T-035's issue page is one Blade view per requisition (not per line, not a wizard)** — every
  unfulfilled line gets its own FEFO table + barcode form on the same page, matching the project's
  established preference (GRN's add-line pattern, T-031's requisition lines) for one straightforward
  page over a multi-step flow. Each line's form POSTs independently, so issuing against one line never
  disturbs another line's in-progress input.
- **T-036's OTP (FR-RQ-11 fallback) is stored in the cache (Redis), not a database table.** It's a
  single-use, 10-minute-TTL confirmation that the named receiver is present and agrees to the issue —
  not a credential and not something that needs an audit trail of its own (the resulting
  `issue_transactions.signature_hash` is the durable record), so there was no reason to add a table
  spec's own schema never called for. `ReceiverOtpService` mirrors the shape of `RequisitionState`
  et al. — small, single-purpose, no persistence beyond what the feature strictly needs.
- **The signature pad (T-036) is a hand-rolled `<canvas>` with plain pointer/touch listeners wired
  through Alpine, not a signature-pad library.** Same reasoning already used for the mobile nav
  checkbox toggle and the complete-profile page's conditional field: the simplest tool that satisfies
  the requirement, and in this case CSP's script-src has no allowance for a third-party signature-pad
  library anyway (would need adding to the nonce'd script-src, which isn't in scope here). A real bug
  from this: the canvas set its own backing-store width from `offsetWidth` inside `x-init`, before
  Alpine had actually finished laying out the DOM, producing a 2px-wide unusable canvas — only caught
  by eye during manual browser verification, not by any automated test (headless test drivers don't
  render layout the same way). Fixed by deferring that read into `$nextTick`. **Any future canvas
  sizing that depends on layout should go through `$nextTick`, not a bare `x-init` read** — this class
  of bug won't fail a test, only look broken in a real browser.
- **T-037's F-01 PDF layout has no verified source template to match pixel-for-pixel** — unlike F-03,
  which FR-LG-01/02 describe column-by-column, spec never lays out F-01's exact fields/positions. The
  layout was designed directly from every field the `Requisition` model actually carries (requester
  info, line items, both decisions, receiver confirmation), same class of judgment call as T-015's GHS
  statement language and T-023's hand-drawn pictograms — ship the best-supported version rather than
  guess at a paper form nobody could show this session. Revisit if a real F-01 template surfaces later.
  Printable at any stage (not gated on being fully issued) since acceptance criterion §14.9 just asks
  that the PDF "ตรงตามแบบฟอร์มเดิม" (matches the original form), which is about layout fidelity, not
  about restricting *when* it can be printed — pending stages render as "รอพิจารณา" rather than blank.
- **T-038 (Feature Test FT-01..FT-10) intentionally leaves FT-05, FT-07, and FT-08's HTTP-level check
  unwritten** — this closes Phase 3, but those three scenarios exercise Return (T-040), Stock Take
  variance detection (T-041), and the Adjustment workflow's HTTP/Policy layer (T-043), all Phase 4.
  Only their underlying `LedgerService::return()`/`adjust()` primitives exist and are already tested
  (since T-021, including BR-06's same-actor rejection in `LedgerServiceTest.php`). Same judgment call
  as T-017's deferred ST-04 (no real target existed yet either) — write these three for real once
  T-040/T-041/T-043 exist, against the actual requisition-integrated flows, not the bare primitives.
  **Do not consider Phase 3 "fully tested against spec §11.2" until those three land** — flag this
  explicitly if a later review checklist asks whether FT-01..FT-10 are all green.

## Phase 4 — Operations

- **T-040 has no dedicated "return" table** — spec's schema never defines one, so `ReturnService`
  validates BR-05's "same container it was issued from" check by querying `issue_transactions` for
  which `container_id`s this `requisition_item_id` was ever issued against, rather than reversing a
  specific issue transaction by id. A return is just another `stock_ledger` RETURN row (via
  `LedgerService::return()`) plus incrementing `requisition_items.qty_returned_base` — no new table,
  matching spec's own literal schema exactly.
- **A real bug caught only by manual browser testing, not by any automated test**: `RequisitionPolicy::
  return` deliberately stays true for both PARTIALLY_ISSUED and ISSUED (returning unused material is
  still meaningful once a requisition is fully issued), but the issue/return page's own controller
  gate (`RequisitionIssueController::create()`) originally checked only the `issue` ability — so once a
  requisition became fully ISSUED, the *entire page* 403'd before the return section could ever render,
  even though the Policy correctly allowed the return itself. Every automated test constructed its own
  fixtures directly against the service layer or POSTed straight to the return route, so none of them
  ever loaded the page in the ISSUED state the way a real user would. Fixed by gating page access on
  "can issue OR can return" instead of "can issue" alone, with a regression test added afterward.
  **Any future page that serves two abilities with different eligibility windows must gate on "any of
  them", not just the one named in the controller's primary action** — this class of bug won't fail a
  test that only exercises the POST target directly, only a test (or a human) that loads the actual
  page in every state that ability is supposed to cover.
- **T-041 has no separate `StockTakeState` class**, unlike `RequisitionState` (T-030). BR-01's diagram
  has real branching (the SUBMITTED(non-student) shortcut) that justified a small, independently
  testable pure class; a stock take's OPEN→COUNTING→PENDING_APPROVAL→APPROVED/CANCELLED flow has no
  branching at all — every transition is a single straight-line check — so the abstraction wouldn't
  pull its weight here. Transitions are checked directly inline in `StockTakeService`, each with its
  own guard clause. Revisit only if a later requirement adds real branching to this flow.
- **"Active" containers for FR-ST-02's line generation means SEALED, IN_USE, or QUARANTINE** — spec's
  wording ("containers ที่ active ใน lab") doesn't enumerate which statuses count as active. EMPTY is
  excluded because its count is already trivially known (zero); DISPOSED is excluded because it no
  longer physically exists to count. QUARANTINE is included because it's still physically present and
  its true quantity is exactly what a stock take needs to confirm, even though it isn't available for
  issue. A container with no `location_id` is never included in any lab's round — same gap as T-016's
  BR-10 note, `location_id` being nullable everywhere containers reference it.
- **FR-ST-03's counted quantity is entered directly in the item's own base unit, with no unit
  selector** — unlike issuing/returning, spec's literal wording ("กรอกยอดนับจริง") never mentions
  switching units for a stock take count, so this avoids introducing a third unit-conversion path
  (`UnitConverter::toItemBase()`) where the two existing ones (issue, return) already fully cover the
  cases spec actually asks to convert.
- **T-042's disposal approval has no BR-06-style distinct-actor check beyond ordinary role
  separation.** BR-06 ("ผู้อนุมัติต้องไม่ใช่คนเดียวกับผู้สร้างรายการ") is titled "การปรับปรุงยอด" (stock
  adjustment) and is never repeated under §7.6's disposal requirement (FR-ST-05), unlike how T-041's
  stock take approval explicitly reuses it for adjustments a count produces. `disposal.request`
  (SCIENTIST) and `disposal.approve` (LAB_MANAGER) are already separate permissions on separate roles
  per `PermissionSeeder`, which is the separation-of-duties mechanism spec's §3 actually names for this
  case — adding a same-person check on top would be enforcing a rule spec never asked for here. Revisit
  if a later spec reading or a real incident shows disposal needs the same explicit protection.
- **`Disposal::reject()` stores no rejection reason** — spec's own `disposals` DDL has no column for
  one (unlike `requisitions.reject_reason`), so none is invented. The `status` flip to REJECTED plus
  `approved_by`/`approved_at` (who decided, and when) is the entire record spec's schema provides for;
  a real reason would need its own column added the same way T-016/T-022/T-035 added missing columns
  when a rule genuinely needed one — FR-ST-05 doesn't ask for a reject reason, so none was added.
- **`DisposalService::approve()` re-validates the requested quantity against the container's *current*
  remaining stock, not just what was true at request time** — a disposal can sit PENDING for a while,
  during which other transactions (an issue, another disposal, an adjustment) may have already reduced
  what's left. Approving a stale request as-is could silently try to remove more than physically
  remains; re-checking at the point of the actual ledger write catches this before it happens, the same
  defensive principle already applied at every other "write happens later than the check" point in this
  codebase (e.g. `IssueService`'s BR-04 tolerance check happens at issue time, not request time, since
  there is no separate request step there).
- **T-043 (Adjustment, BR-06) is a single-step create-and-approve form, not a two-phase request →
  approve workflow** — unlike stock take/disposal, spec's schema has no "pending adjustment request"
  table for it; BR-06's own wording ("ต้องมี approved_by เป็น LAB_MANAGER ที่ไม่ใช่คนเดียวกับ created_by")
  describes one action naming two people at once, so `AdjustmentController::store()` takes barcode +
  direction + qty + remark + approver in a single POST. `LedgerService::adjust()` (T-021) already
  enforced "approver ≠ creator" and the ≥10-char remark; `AdjustmentService` adds the one check that
  method alone can't make — the named approver must actually **hold** `ledger.adjust` (LAB_MANAGER
  only, per `PermissionSeeder`), not merely be a different user id.
- **Added `stock_ledger.approved_by` (nullable FK to `users`) via migration** — BR-06 literally requires
  an adjustment row to "have approved_by = LAB_MANAGER", but spec's own §5.2 DDL for `stock_ledger` has
  no such column at all (unlike `stock_takes`/`disposals`, both of which do carry one). Same class of
  gap as T-035's `overage_approved_by` and T-016/T-022's missing `ulid` columns — the rule needs
  enforcing now, so the column was added rather than silently discarding the already-existing
  `LedgerEntryData::$approvedBy` DTO field (which, before this task, was checked at write time but never
  actually persisted anywhere — auditors reviewing the ledger had no way to see who authorized an
  adjustment). `LedgerService::appendRow()` now writes it for every txn type (null except ADJUST_IN/
  ADJUST_OUT). BR-08's row-hash formula (`LedgerHasher::compute()`) is an explicit fixed field list per
  spec §10.3, not a hash-everything-on-the-row approach, so adding this column does not change or break
  any existing row's hash. Any future task that reads "who approved this ledger row" should use this
  column (`StockLedger::$approved_by` / the `approver()` relation) rather than assuming it's only
  available via the now-defunct one-off check.
- **The adjustment approver picker (`AdjustmentController::create()`) excludes both the current user and
  any `is_active = false` account.** This is the first raw "pick a user from a dropdown" UI in the app
  (disposal/stock-take approval are Policy-gated actions on an existing record, not a picker; the
  requisition advisor is auto-linked from the requester's own profile) — filtering inactive accounts
  follows the same convention already used for the `labs`/`items` dropdowns elsewhere (T-031), so a
  deactivated test/former-staff account never appears as a selectable approver.
- **`/adjustments` (index) is a plain paginated Blade view, not a Livewire component** — same
  "simplest tool that satisfies the requirement" precedent as T-031's decision for the requisition
  create/show flow; there's no live-updating requirement here (FR-LG-07 just asks for "หน้ารายการ
  ปรับปรุงยอด", a list page) that would justify Livewire's request lifecycle over a normal paginated
  controller response.
- **T-004 already created the `notifications` table** (spec's full §5.2 DDL, migrated at project init
  along with every other table) — T-044 found this only when its own `create_notifications_table`
  migration failed with "table already exists". No code had ever written to it before T-044. Added a
  `ulid` column via a normal `add_ulid_to_notifications_table` migration instead (same AGENT RULE #9 gap
  as `locations`/`labs`), rather than a duplicate create migration. **Lesson for future tasks**: check
  `Schema::hasTable(...)` / the migrations folder before assuming a spec table doesn't exist yet — T-004's
  backlog title ("Migration ทุกตารางตาม §5.2") means every table in the spec's DDL already has a
  migration, even ones no later task has used yet.
- **T-044's three channel combinations are three explicit `NotificationService` methods**
  (`notifyInApp`, `notifyInAppAndEmail`, `emailOnly`) rather than one method with boolean flags — spec's
  own FR-NT-01..06 table only ever uses one of exactly three combinations ("Email + In-app", "In-app",
  "Email" routed to ADMIN), so a call site reading `notifyInApp(...)` vs `emailOnly(...)` states which
  channels fire without needing to trace a flag's default. One generic `NotificationMail` (title/body/
  link) covers every notification type — mirrors the `notifications` table's own generic shape, so no
  reason to build 6 near-identical Mailable classes the way `AdvisorApprovalMail`/`ReceiverOtpMail`
  needed richer, type-specific content.
- **The advisor-pending step (FR-NT-03) does not send a second email.** T-033 already emails the
  advisor a rich, signed-URL approval link the moment a STUDENT requisition is submitted — that already
  satisfies FR-NT-03's "Email" channel for this one step. T-044 only adds the in-app half
  (`notifyInApp`, not `notifyInAppAndEmail`) so the advisor doesn't get two separate emails for the same
  event. Every other FR-NT-03/04 notification (scientist-pending, and every approve/reject decision back
  to the requester) had **no** notification of any kind before T-044 and uses the full `notifyInAppAndEmail`.
- **FR-NT-01/02/05's recipient pool is chosen by permission, not by an explicit "who gets alerts"
  setting spec never defines.** Reorder-point alerts (FR-NT-01) go to `item.manage` holders (LAB_MANAGER
  — the role actually responsible for restocking, per `PermissionSeeder`). Expiry (FR-NT-02) and
  shelf-life-after-open (FR-NT-05) alerts go to the union of `item.manage` **and** `disposal.request`
  (LAB_MANAGER + SCIENTIST) — both plausible stakeholders for a physically-expiring container, unlike
  reorder which is purely a procurement concern. No lab-scoping exists (same gap as disposal/adjustment
  approval before it) — every alert goes to every holder of the relevant permission(s) app-wide.
- **FR-NT-02 fires on the exact day a container crosses one of the 90/30/7-day thresholds, not on every
  day it happens to be "close."** Read literally ("ใกล้หมดอายุ 90/30/7 วัน"), this is three staged
  reminders per container, not an every-day-until-it-expires nag — `notifications:check-expiry` queries
  `expiry_date = today + N days` for each `N`, so (assuming the job runs daily without gaps) each
  container is flagged at most 3 times over its life. FR-NT-01 (reorder) and FR-NT-05 (shelf-life) do
  the opposite deliberately — both re-check and re-notify on every run for as long as the condition
  holds, since spec's own "Daily" cadence for those two reads as "tell me the current state every day,"
  not "tell me once when it first crosses." No dedup/suppression logic was added for any of the three;
  revisit if repeated shelf-life/reorder emails turn out to be too noisy in real use.
- **FR-NT-06 (hash chain, "Immediate") runs hourly, not on a true immediate trigger — because no such
  trigger exists.** Every `stock_ledger` write goes through `LedgerService::appendRow()`, which always
  computes the correct hash, and the DB grants (T-027) plus append-only triggers (T-017) block every
  other write to that table — so nothing in the app's own write path can ever produce a broken chain.
  A break can only come from outside the app entirely (direct DB tampering, a bad restore), which only a
  periodic scan can catch. `notifications:check-hash-chain` reuses `LedgerHasher::verifyChain()` (T-020)
  per item, exactly like the existing `ledger:verify` CLI command, but emails every ADMIN (not just
  printing to the console) when it finds a break. Scheduled hourly as the closest practical stand-in for
  "immediate" given there's no real event to hook. This command sends **no** in-app `notifications` row
  for anyone — spec's own table lists only "Email → ADMIN" for this row, unlike every other FR-NT-0x
  which lists "+ In-app" explicitly.
- **The notification bell (header, all pages) computes its unread count with one extra query per page
  load** (`auth()->user()->notifications()->whereNull('read_at')->count()`) rather than caching it —
  first raw "always-on" badge in the app, and there's no existing precedent for caching a per-user count
  like this. Simplest-tool-first; revisit if this measurably matters once the app has real traffic.
- **T-045's `ReportController` is gated on a bare `Gate::authorize('report.view')` call, not a
  dedicated `ReportPolicy` class.** Every other Policy in this app gates access to a real Eloquent
  resource (`Item`, `Requisition`, `StockLedger`, …), giving Laravel's naming-convention auto-discovery
  something to bind to; reports have no such resource. A `ReportPolicy` would need a fake marker model
  under `App\Models` just to satisfy that convention — worse than skipping the Policy class entirely.
  `AppServiceProvider`'s existing `Gate::before()` hook already bridges any seeded permission code to a
  bare ability check (`$user->roles->...->contains('code', $ability)`), so `report.view` works correctly
  as a plain string with zero extra registration. Every route in the controller still calls
  `$this->authorize('report.view')` explicitly (AGENT RULE #7's spirit — authorization checked on every
  action — is intact even without a Policy file).
- **T-045's CSV injection fix surfaced a real, already-shipped gap**: `Fr03Export` (T-025) had no
  SEC-IN-10 guard at all — `receiver_name`/`remark` are free text a requester or receiver types once,
  and neither was ever sanitized before this task. Retrofitted with the same `CsvInjectionGuard` every
  new export uses, plus a regression test (`Fr03ExportTest`) proving a `=`/`+`/`-`/`@`-prefixed value
  now gets the leading `'`. Any future export must run every free-text cell through
  `CsvInjectionGuard::sanitize()` — nothing catches a missed one automatically (no PHPStan rule, no
  linter), so a manual check for this is worth adding to review whenever a new export ships.
- **"Below reorder point" and "dead stock" (§7.8) both take an optional `lab` filter, even though item
  balance is global** (spec's schema has no per-lab stock split, same fact already noted for
  `NotifyReorderPointCommand`/T-044). The filter narrows to items/containers that have at least one
  container physically in that lab (`containers.location_id` → `locations.lab_id`), not a lab-scoped
  balance — the same interpretation T-044's daily checks already use for their own recipient scoping.
- **"Dead stock" is evaluated per container, not per item** — deliberately, since a container is the
  physically actionable unit (the one thing you'd actually go dispose of or reallocate), unlike "below
  reorder point" which is inherently item-level (the reorder decision is made per catalog item, not per
  container). A container counts as dead stock when `remaining_qty_base > 0` and no `stock_ledger` row
  has referenced its `container_id` in the last 12 months — checked directly against `stock_ledger`
  rather than via a cached "last movement" column, since nothing in the schema tracks that separately
  and the query is cheap (one `whereDoesntHave` per container).
- **PHPStan gotcha refined during T-045**: chained relation access through a nullable FK
  (`$container->location()->first()?->lab()->first()`) can trigger `nullsafe.neverNull` even when every
  intermediate step is genuinely optional (`locations.lab_id`/`containers.location_id` are both
  nullable) — neither the magic property, `->first()`, nor `firstOrFail()` reliably fixes it, unlike
  the simpler one-hop cases documented earlier in this file. What actually works: assign each hop to a
  local variable and narrow it with an explicit `if ($x === null) { return ...; }`, not `?->` or `??`
  chains — PHPStan's flow analysis trusts an explicit `if` far more consistently than nullsafe operator
  inference. See `ExpiringStockExport::labNameFor()`/`DeadStockExport::labNameFor()` for the pattern.
- **NFR-02's "PDF ledger 100,000 rows < 15s via Queue + notify-on-completion" was not built for any of
  T-045's reports.** Every export in this app (T-024's on-screen ledger, T-025's F-03 PDF/Excel, T-037's
  F-01 PDF, and all six of T-045's new reports) renders synchronously in the request/response cycle —
  none of them queue the work or notify the user when a background job finishes. This matches the
  existing precedent exactly (`Fr03PdfService` was already synchronous before this task), so T-045
  didn't regress anything, but the literal NFR-02 requirement is still open. Revisit if a real report
  turns out slow enough in practice to need it — likely only the controlled-substances or usage-summary
  exports at real scale, since neither paginates its underlying query.
- **T-046's pending-requisition count sums every action-queue the viewer's permissions make them
  responsible for, rather than picking one branch per role.** A SCIENTIST holds both
  `requisition.approve_scientist` and `requisition.issue`, so their card is genuinely "decisions I owe
  plus issuances I owe" added together — showing only one of the two would silently hide real pending
  work. A role holding none of `approve_advisor`/`approve_scientist`/`issue` (LAB_MANAGER, ADMIN,
  AUDITOR) falls back to "my own requisitions still in flight," which is correctly `0` for those roles
  since they never file requisitions themselves — not a bug, just an honest answer to a question that
  doesn't apply to them.
- **Top-10-issued-items and the 12-month chart both rank by issue-transaction *frequency*, not summed
  quantity** — same reasoning as T-045's dashboard-adjacent reports: items are measured in incompatible
  units (mg vs mL vs pcs), so summing raw base quantities across different items would produce a
  meaningless number. Both are grouped/counted in PHP via `Collection::countBy()`, not a raw SQL
  `GROUP BY`, per AGENT RULE #3 — acceptable at this app's scale since `issue_transactions` is a
  low-write-volume table; revisit if it ever needs to scale past what fits comfortably in memory.
- **The monthly issuance chart is a hand-rolled inline SVG bar chart, not a JS charting library** — same
  CSP constraint already noted for T-036's signature canvas (`script-src` is `'self' 'nonce-...'` only,
  no CDN allowance) and the same "simplest tool" precedent used throughout this app. A server-rendered
  SVG needs no JavaScript at all, so there was never a CSP question to begin with.
- **`ledger_snapshots` was already a real table since T-004** (spec's full §5.2 DDL) — same "check the
  migrations folder before assuming a table doesn't exist" lesson T-044 already documented for
  `notifications`. T-047 only needed the model/service/command, no new migration.
- **`LedgerSnapshotService::generateForItem()` always reads `closing_base` off the actual last
  `stock_ledger` row in the period, never recomputes it as `opening + total_in − total_out`.** The
  ledger's own `balance_base` (BR-07's running balance) is already the single source of truth; deriving
  `closing_base` independently by addition would risk silent drift if any edge case in the sums ever
  disagreed with the ledger — better to have one column simply *be* the same fact the ledger already
  proves, and let `total_in_base`/`total_out_base` stay purely informational.
- **An item gets a snapshot for a month it had zero movement in, as long as it has ledger activity or a
  prior snapshot from an earlier month** — deliberately, so the monthly chain has no gaps once an item
  starts being tracked (a later query can always find "last month's row" to chain from). An item with
  truly no history at all (never received) is skipped entirely for every period — there's nothing to
  summarize.
- **T-047 only ships the write side (the job that populates `ledger_snapshots`) — nothing in the app
  reads from it yet.** The backlog's own title is literally "`ledger_snapshots` monthly job
  (performance)", not "...and rewire balance lookups to use it" — every existing balance read (the
  reorder-point check, the requisition create form's real-time balance, every report) still queries
  `stock_ledger` directly via `orderByDesc('id')->value('balance_base')`, which is already correct and
  already tested. Wiring a snapshot-aware fast path into any of those is a separate, real change (with
  its own correctness risk around "is this snapshot still fresh") that wasn't asked for here — revisit
  once real data volume actually makes the direct `stock_ledger` scan slow.

## Known open items (spec §15, need a human decision before those tasks close)

- ~~CMIS not registered as an SSO client~~ — **registered 2026-08-31**: `client_id=CMIS`,
  `redirect_uri=http://localhost:8090/sso/callback`. Real credentials are in `.env` (`SSO_CLIENT_ID`/
  `SSO_CLIENT_SECRET`, gitignored — never in `.env.example`). Live redirect to the real MEDSCI ACC login
  page confirmed working end-to-end.
- Unclear whether MEDSCI ACC enforces 2FA for high-privilege roles (SEC-AU-12) — needs confirmation before
  closing T-010/T-011.
- `wittaya.su` is the current (only) real logged-in user, manually granted `ADMIN` via tinker — normal
  bootstrapping since no admin existed yet to use the admin UI (`/admin/users`) to do it. Future role
  grants for other real users should go through that UI instead.

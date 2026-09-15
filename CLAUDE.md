# CMIS — Chemical & Material Inventory System

Full spec (single source of truth): [`_spec.md_AI_Agent_.md`](_spec.md_AI_Agent_.md).
SSO protocol details: [`sso_integration_guide.md`](sso_integration_guide.md), [`sso_client_quickstart.md`](sso_client_quickstart.md).
PDPA breach response plan (SEC-PD-05, still a draft — see its own status line): [`pdpa_breach_response_plan.md`](pdpa_breach_response_plan.md).

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
`.env` doubles as the docker-compose env file (same DB/Redis credentials on both sides). All normal
dev/test work goes through this `app` container — the `fpm`/`nginx` services below are a separate,
additive pair added for T-052's load test and don't change anything about the workflow above.

**`fpm` + `nginx` (`:8091` by default, `FORWARD_NGINX_PORT`) is a second, production-representative way
to serve the exact same codebase** — `docker/php-fpm/` (php-fpm base image, `pm.max_children=60`, OPcache
shared across all pool workers) behind `docker/nginx/` (a standard `fastcgi_pass` server block). Added
during T-052 because `php artisan serve` turned out fundamentally unable to validate NFR-01 (100
concurrent users) — see CLAUDE.md's T-052 notes below. Use `curl`/a browser against `localhost:8091` for
anything that needs to look like real concurrent-request behavior; use `localhost:8090` (or `docker
compose exec app ...`) for everything else, same as always.

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

## Phase 5 — Hardening & Release

- **T-050's Privacy Notice consent gate (`EnsurePrivacyConsent`) is prepended *before*
  `EnsureRoleAssigned` in the `web` middleware group, and `SsoCallbackController`/
  `PrivacyNoticeController::accept()` both check consent before role.** SEC-PD-02 applies to a user
  regardless of what role they end up with — a STUDENT and an ADMIN both need to consent before doing
  anything else — so consent is the more fundamental gate. This mattered concretely:
  `PrivacyNoticeController::accept()` originally just did `redirect()->intended('/')` after saving
  consent, which for a roleless user would have fallen straight through to `EnsureRoleAssigned`'s 403
  on `/` instead of the pending-role page — caught before writing tests, by tracing the exact same
  two-gate interaction `SsoCallbackController` already has to handle explicitly.
  `EnsureRoleAssigned`'s own exempt-route list also needed `privacy-notice.show`/`.accept` added (an
  already-consented-but-roleless user navigating back to the notice URL would otherwise 403 there too).
- **`UserFactory` defaults every fixture to already-consented** (`privacy_consent_at` = now,
  `privacy_consent_version` = the current config value), with a new `->unconsented()` state for the
  handful of tests that specifically exercise the consent gate. Introducing a global,
  every-request middleware after 340+ existing tests already existed meant either touching every
  fixture individually or fixing it once at the factory — the factory fix meant zero of the
  pre-existing tests needed to change. **Any future global gate like this should default the factory
  the same way**, not require every existing test file to opt in.
- **SEC-PD-03's "my-data" page and export cover profile fields + the user's own requisitions, not an
  exhaustive dump of every table that references them** (every issue_transaction, every audit_log
  mention, every notification, etc.). This is a representative, defensible scope for the
  access/copy right, not the literal maximum — a fully exhaustive cross-table export would be a
  larger, separate undertaking. Revisit if PDPA compliance review calls for more.
- **SEC-PD-04's `PseudonymizeUserService` leaves `sso_subject` untouched, deliberately** — it's an
  opaque SSO identifier, not personal data on its own, and clearing it would let a future SSO
  re-provision of the same real person silently create a second "new" user sharing that identity's
  history under a fresh row, which is worse than leaving the original opaque subject string in place
  on an otherwise-scrubbed, deactivated row.
- **`php artisan users:pseudonymize` has no automatic scheduled trigger** — unlike every other
  scheduled command in this app (T-044's notification checks, T-047's snapshot job), this one is
  manual-only, gated by an interactive confirmation prompt, and has no permission check of its own
  (same reasoning as `ledger:verify`/`ledger:snapshot`: it's a CLI tool, so server access is the actual
  access-control boundary). The reason it can't be scheduled yet: **no written data-retention period
  exists** to decide *when* a given user qualifies — see Known open items below. Building the
  scheduled-trigger half without that policy decided would mean guessing at a number nobody has
  approved.
- **`pdpa_breach_response_plan.md` names roles by job title/placeholder, not real people** — same
  "don't fabricate what nobody has confirmed" judgment as T-015's GHS statements and T-037's F-01
  layout, just applied to an incident-response org chart instead of technical content. The plan is
  usable as a structural template today but needs the university to name actual people/contact
  channels before it's a real operational plan.
- **T-051 (NFR-07, WCAG 2.1 AA) is an audit task, not a "build X" task** — spec's entire guidance on
  this requirement is one terse table row with zero elaboration anywhere else in the ~1600-line spec.
  Read literally, the only honest way to "implement" it is to actually run a real accessibility scanner
  against the live app and fix whatever it finds — so this task did exactly that with axe-core 4.10.2,
  not a guess at what WCAG might require. The app's strict CSP (`connect-src 'self'`) blocks fetching
  the library from any CDN, even from console-executed JS (confirmed empirically) — worked around by
  copying axe-core into `public/` as a temporary same-origin file (deleted again once the audit
  finished), never by relaxing CSP.
- **Three systemic issues found, all pre-existing since whichever task first wrote the affected
  markup — none caught by PHPStan/Pint/any prior manual test, because none of them check this class of
  structural/visual property:**
  1. **Missing form-label association** (WCAG 1.3.1/4.1.2/3.3.2) — every form field app-wide used a
     bare sibling `<label class="block ...">` right before its `<input>`/`<select>`, no `id`/`for` at
     all. Fixed across 14 files (~82 fields). Before touching any file, each was checked for whether its
     labeled fields sit inside a `@foreach` that could produce duplicate ids on one page — two files
     genuinely did: `requisitions/issue.blade.php` (loops over line items) uses the line's own PK as an
     `id` suffix (`id="barcode-{{ $line->id }}"`); `reports/index.blade.php` (several independent static
     forms reusing generic names like `from`/`to`/`lab_id`) uses `aria-label` instead of `for`/`id`
     entirely, since it had no visible `<label>` to begin with. Every other file's labeled fields render
     exactly once per page (any surrounding `@foreach` was a read-only display table, not the form
     itself), so those got plain matching `id`s. **Any new form field added anywhere in this app must
     pair its `<label for="...">` with a matching `id="..."` on the input/select from the start** — this
     won't fail any automated check if forgotten, only a real scanner or a screen-reader user.
  2. **Scrollable regions not keyboard-focusable** (WCAG 2.1.1, axe rule `scrollable-region-focusable`)
     — every `overflow-x-auto`/`overflow-y-auto` wrapper (wide tables, the GHS hazard/precautionary
     statement pickers, the Privacy Notice's own scrollable body) had no `tabindex`, so a keyboard-only
     user could never scroll it. Fixed with `tabindex="0"` on all 16 occurrences across 15 files —
     deliberately *not* `role="region"`/`aria-label` on top, since that's a broader ARIA enhancement
     neither the literal WCAG SC nor axe's own rule asks for. Axe only flags this when the region is
     genuinely overflowing at scan time, so two of these (a table page and the Privacy Notice) only
     surfaced once real/long-enough content was loaded — a scan against an empty table won't catch it.
     **Any new `overflow-x-auto`/`overflow-y-auto` wrapper needs `tabindex="0"` from the start** — the
     new `AccessibilityStructureTest` now catches a missed one automatically (see below).
  3. **Insufficient color contrast** (WCAG 1.4.3, axe rule `color-contrast`) — the shared Tailwind token
     `ink.faint` (`#85769D` on white) measured 4.13:1, short of the 4.5:1 floor for normal-size text, and
     is used for "faint helper text" (`<dt>` labels, hints, etc.) across effectively every page. Fixed by
     changing the one token value in `tailwind.config.js` (`#85769D` → `#786A8D`, ~4.95:1 — a deliberate
     margin above the floor, not a bare pass) rather than hunting down every individual usage site, since
     it's a single design-system value, not a per-page bug. **This project's Docker Compose has no
     Node/Vite container** — `npm run build` was run from the host (which already had Node installed),
     not inside `docker compose exec app`, since `node`/`npm` aren't on that container's PATH. Any future
     Tailwind/asset change needs the same host-side build step; there's no `npm run dev` hot-reload
     server running either.
- **`AccessibilityStructureTest` (T-051) is a lightweight structural regression guard, not a full a11y
  test suite** — one test statically scans every `.blade.php` file for an `overflow-x-auto`/
  `overflow-y-auto` tag missing `tabindex`, the other hits `/items/create` (the richest real form) and
  asserts every visible `<label for="...">` has a matching `id="..."` in the rendered response. It does
  **not** re-check color contrast (that's a design-token property, not a markup pattern a Pest test can
  meaningfully assert) or re-run axe itself (no CDN access in CI, and the point of these two tests is a
  cheap regression tripwire, not re-doing the full manual audit). The full audit was verified live in a
  browser against every page type reachable by every role — see CHANGELOG.md's T-051 entry for the list.
- **T-052 (NFR-02) found that `Fr03PdfService`'s HTML/CSS-table rendering (unchanged since T-025) costs
  ~1.9ms and ~90KB *per row*** — real, measured, not estimated. At 100,000 rows that's ~190s/~9GB, nowhere
  close to NFR-02's <15s target, and chunking the `WriteHTML()` calls doesn't help at all (the cost is
  mPDF's own internal DOM/CSS layout-engine state accumulating per row, not the size of the HTML string
  fed to it — the earlier chunking fix only solved a *different* problem, a `pcre.backtrack_limit` crash
  at ~5,000 rows). **Rewrote the table body to use mPDF's raw `Cell()`/`Ln()` drawing API instead**
  (fixed column widths in mm, manual page-break + header-row-repeat logic, `GetStringWidth()`-based
  truncation-with-ellipsis for any value too long for its column) — this skips the HTML/CSS parser
  entirely for the row-count-dependent part. Re-measured: 100,000 real rows now render in **9.88s at
  761MB peak**. User-approved 2026-09-09 (asked directly: rewrite the renderer vs. document the gap and
  move on — chose the rewrite). Title/item-info block stays plain `WriteHTML()` — it's small and
  fixed-size regardless of row count, so there was nothing to fix there. **Any future mPDF report with a
  row count that can genuinely grow unbounded should draw its table body the same way (`Cell()`, not
  `WriteHTML()`)** — the HTML/CSS path is fine for anything with a small, bounded row count (every other
  export in this app).
- **T-052's queued PDF export (`LedgerExportRequest` + `GenerateLedgerPdfExportJob`) routes to the
  background once a filtered ledger exceeds 5,000 rows** (`LedgerExportController::ASYNC_ROW_THRESHOLD`)
  — picked from the same measurement: even the *old* renderer took ~9.67s for 5,000 rows (uncomfortably
  close to blocking a web request), and the new one easily clears that in under a second, so 5,000 is a
  comfortable, empirically-grounded cutover rather than a guess. The job itself raises its own
  `memory_limit` to 1536M via `ini_set()` — safe only because this runs off the request cycle entirely;
  a real deployment's queue worker should be sized the same way in its own php.ini.
- **The queued export's status/download page (`/ledger-exports/{ulid}`) is the first real, genuinely
  user-owned, URL-addressed resource this app has ever had** — `LedgerExportRequestPolicy::view()` checks
  `requested_by` and a new `IdorTest`-style check lives in `LedgerAsyncExportTest` (ST-04). T-017 deferred
  this exact test back in Phase 1/2 for lack of a real target (`Attachment` was gated by role, not
  ownership); this finally closes that gap. Any *future* per-user-owned URL-addressed resource should
  reuse this same shape (Policy checking a `requested_by`/`user_id` column, not just a role-wide ability).
- **Two real MariaDB gotchas hit while adding `ledger_export_requests` (T-052)**: (1) `foreignId(...)->
  constrained('units')` fails with errno 150 ("Foreign key constraint is incorrectly formed") because
  `units.id` is `smallIncrements` (smallint unsigned), not the `bigint unsigned` `foreignId()` assumes —
  same shape as every other `unit_id` FK in the schema (`stock_ledger.display_unit_id`, etc.), needs
  `unsignedSmallInteger()` + an explicit `foreign()->references()->on()` instead. **Any new column
  referencing `units.id` needs this same treatment** — `foreignId()` will build fine syntactically and
  only fail at actual constraint-creation time. (2) A FK failure partway through `Schema::create()`
  does **not** roll back the columns/keys that already succeeded — MySQL/MariaDB DDL auto-commits
  per-statement (Laravel compiles a create-with-foreign-keys Blueprint into a `CREATE TABLE` plus one
  `ALTER TABLE ADD CONSTRAINT` per FK, not one atomic statement), so a failed migration can leave a
  **partially-created table** behind that then blocks the next attempt with a confusing "table already
  exists" error. Fix is `Schema::dropIfExists(...)` (via tinker) before retrying, not just fixing the
  migration file and re-running.
- **A new table's grants (`docker/mariadb/restrict_app_grants.sql`) must be re-run against
  `cmis_testing` a *second* time relative to `cmis`** — running it right after `php artisan migrate`
  only covers `cmis` (the database that migration actually touched); `cmis_testing` doesn't get the new
  table until the test suite's own first `RefreshDatabase`-triggered `migrate:fresh` creates it, so the
  very first test run after adding a table fails with "SELECT command denied" (not a migration error)
  until the grants script is re-run once more, afterward. Found during T-052 adding
  `ledger_export_requests`; applies to any future new table the same way.
- **NFR-01 had never been tested until T-052, and the dev environment's only server (`php artisan
  serve`, the `app` container) turned out fundamentally unable to validate it** — 100 concurrent
  requests measured 6–13s each from the client side, while the server's own request log showed ~0.1ms
  processing time per request. The bottleneck is the dev server's own connection-handling path, not
  Laravel/app code, and is made worse by `php artisan serve --no-reload`'s multi-worker mode giving each
  worker its own **unshared** OPcache — every worker's *first* request pays a multi-second cold-compile
  tax that a real server never would (production OPcache is shared across a pool via shared memory).
  **This project had no production-representative web server at all before T-052** — added one:
  `docker/php-fpm/` + `docker/nginx/`, wired in as new `fpm`/`nginx` docker-compose services (port 8091),
  purely additive — every existing `docker compose exec app ...` workflow is untouched. User-approved
  2026-09-09 (asked directly: build this vs. accept the untested gap and move on — chose to build it).
  Re-tested the identical 100-concurrent-user scenario against nginx+fpm: P95 355ms, comfortably under
  the 2s target. **Any future performance-sensitive testing of this app should go through `localhost:8091`
  (nginx+fpm), not `localhost:8090` (`php artisan serve`)** — the latter was never meant to reflect real
  request-handling capacity and demonstrably doesn't.
- **Running `php artisan optimize` (config/route/view caching) breaks the *next* `php artisan test` run
  silently** — found while trying to test under a more production-like config during T-052 (78 tests
  failed, all CSRF 419s, no config-related error anywhere). A cached config freezes every `env()` call's
  *value* at cache time; `phpunit.xml`'s runtime env overrides (`APP_ENV=testing`, `DB_DATABASE=
  cmis_testing`) then have no effect, so `PreventRequestForgery`'s `runningUnitTests()` self-disable
  check silently returns false and CSRF actually enforces itself in tests that never expected it to.
  Fixed by `php artisan optimize:clear`. **Anyone who runs `artisan optimize`/`config:cache` for any
  reason (perf testing, deployment rehearsal) must run `optimize:clear` before the next `php artisan
  test`** — the failure mode gives no hint that config caching is the cause.
- **T-052's load-testing setup permanently added 448,000 `stock_ledger` rows to the dev `cmis`
  database** (9 throwaway items, bulk-inserted directly via `DB::table('stock_ledger')->insert()` rather
  than through `LedgerService`, purely to get a real row count for the NFR-02 timing measurement) — same
  append-only/can't-delete constraint as every other test-data note in this file, just at a much larger
  scale than usual because the whole point was measuring behavior at real scale. All 9 items are
  deactivated; the 100 throwaway `dev_loadtest_*` STAFF users created for the NFR-01 test are deactivated
  too. Harmless (self-evidently test data by item_code/username), but don't be surprised by the row count
  if a future task inspects `cmis.stock_ledger` directly.
- **T-053's ZAP baseline scan runs against the nginx+PHP-FPM stack (T-052), not `app`'s `php artisan
  serve`** — same reasoning as the load test: a real scanner should assess something production-
  representative, and `serve` was never meant to be that. Command (from the host, `zaproxy/zap-stable`
  joined to the compose network so it can resolve `nginx` by service name):
  ```
  docker run --rm --network cmis_cmis_net -v "<path>/storage/app/zap-report:/zap/wrk:rw" \
    -v "<path>/docker/zap/baseline.conf:/zap/baseline.conf:ro" \
    zaproxy/zap-stable zap-baseline.py -t http://nginx -c /zap/baseline.conf \
    -r zap-baseline-report.html -J zap-baseline-report.json -I
  ```
  On Windows/Git Bash, the `-v` host path needs `MSYS_NO_PATHCONV=1` prefixed to the command or the
  mount silently fails ("directory not mounted") — the volume flag's own path gets mangled by Git Bash's
  automatic POSIX-path translation otherwise.
- **A completely unmatched path (no route at all) never runs `web`-group middleware** — there is no
  route for `SecurityHeaders`/`ForceHttps` to attach to, so a real 404 like `/sitemap.xml` came back with
  no CSP header at all, which ZAP correctly flagged as Medium risk (found during T-053, not previously
  known). Fixed with `Route::fallback(fn () => abort(404))` in `routes/web.php` — being defined in that
  file, it automatically inherits the same `web` group middleware as every real route. **Any exception
  handling or fallback logic added later must go through an actual route (or this fallback), never a
  raw framework-level exception response** — otherwise it silently skips every security header again.
- **Two of ZAP's Medium findings are the CSP `unsafe-eval`/`unsafe-inline` exception already approved
  2026-08-31 for Livewire 3/Alpine.js** (see this file's CSP note near the top) — not a new bug, ZAP is
  correctly re-surfacing a trade-off this project already made deliberately. User-approved 2026-09-09 to
  handle this as a **documented waiver** rather than either silently passing the scan or leaving AC #10
  formally failed: `docker/zap/baseline.conf` sets ZAP rule `10055` to `IGNORE` with the reasoning and
  the 2026-08-31 decision inline, so any future re-run of the scan stays honest about exactly what's
  excluded and why — nothing is hidden, it just isn't re-litigated every scan. **If this CSP exception
  is ever removed** (e.g. a future Livewire version ships a non-eval build), remove this waiver entry
  too — don't leave a stale exception masking a real regression.
- **Several Low-risk ZAP findings were fixed as free wins while investigating the Medium ones** (T-053):
  nginx's own version string in the `Server` header (`server_tokens off` — `docker/nginx/default.conf`),
  PHP-FPM's `X-Powered-By` header (`expose_php=Off` — new `docker/php-fpm/hardening.ini`), and missing
  `X-Content-Type-Options`/`Permissions-Policy`/COEP/CORP on static assets (CSS/JS/fonts/images) served
  directly by nginx — same root cause as the fallback-route gap above (never reaches Laravel's
  middleware), fixed by adding those headers directly in nginx's own config for a matched-extension
  `location` block. That block falls through to PHP (`try_files $uri /index.php?$query_string`, not a
  hard `=404`) for any matched-extension path that isn't a real file — `/sitemap.xml` needs to keep
  getting its 404 from Laravel's own fallback route, not a bare nginx 404 that would undo the fix above.
  One Low-risk finding was left as-is: Livewire's own `/livewire/livewire.js` asset route doesn't carry
  the app's SecurityHeaders either (it's registered by the Livewire package itself, not through this
  app's normal routes), but it's Low risk, not blocking AC #10, and not worth chasing into the package's
  internals for one header on one asset file.
- **T-054 (NFR-09): enabling binlog broke every migration that creates a trigger.** MariaDB refuses
  `CREATE TRIGGER` for a DB user without the SUPER privilege once binary logging is on (error 1419 —
  "You do not have the SUPER privilege and binary logging is enabled"), and `cmis_app` deliberately
  has no SUPER (T-027's restricted grants). `stock_ledger`'s append-only triggers (T-017) are the
  only triggers in this schema, so this silently broke `migrate:fresh` everywhere, all at once — the
  whole test suite (367 tests) failed with unrelated-looking errors until traced back to this. Fixed
  with `log_bin_trust_function_creators=1` (`docker/mariadb/conf.d/backup.cnf`) — MariaDB's own
  documented alternative to granting SUPER just for trigger creation, safe here since both triggers
  are a plain deterministic `SIGNAL` with nothing that could diverge on a replica. **Any future
  MariaDB config change should re-run the full suite immediately afterward** — a config-level change
  can break every test in a way that looks like an application bug at first glance.
- **T-054's restore-drill script proves the backup+binlog mechanism without ever touching live
  data** — `mariadb-binlog --rewrite-db="cmis->cmis_restore_drill"` retargets replayed row events to
  an isolated database instead of the one they were recorded against, so a drill can run at any time
  (including against a production replica) with zero risk. A real gotcha found getting this working:
  `mariadb-dump --databases cmis` embeds its own `CREATE DATABASE`/`USE cmis` statements, which means
  the dump can *only* ever load back into a database literally named `cmis` — `mariadb --one-database
  <other-name>` does not retarget it (that flag filters by the database name already inside the
  dump's own `USE` statements, not by "whatever database the client is pointed at"). Fixed by dropping
  `--databases` from the dump entirely (just `mariadb-dump ... cmis`, a positional argument) — the
  resulting dump carries no database-context statements at all, so it loads into whatever database is
  named on the restore command line, live or drill alike.
- **A real, unplanned recovery happened the same day T-054 was built — not a drill.** While
  investigating an unrelated test failure, `php artisan migrate:fresh --env=testing --force` was run
  on the assumption that `--env=testing` would redirect the connection to `cmis_testing`. **It
  doesn't** — this project has no `.env.testing` file, so the flag is a silent no-op, and the command
  ran against whatever `.env` actually names (`cmis`) and wiped the live dev database. **`artisan
  --env=<name>` is not a safe way to redirect a single command at a different database** — a future
  task needing that must pass `--database=<connection-name>` (an actual named connection in `config/
  database.php`) explicitly, never rely on `--env` alone. Recovered live, for real, using exactly the
  mechanism this task built: the full backup taken minutes earlier, restored into a freshly recreated
  `cmis`, then binlog replayed up to (but excluding) the exact byte position where the accidental drop
  began — found via Laravel's own "generated by server" comment, which its schema builder tags onto
  every table it drops for `migrate:fresh`/`db:wipe`, making the accidental event unambiguously
  identifiable in the binlog stream against everything else. Confirmed with the user before any
  destructive recovery step (`DROP DATABASE`) was taken — the session's own auto-mode safety
  classifier blocked the first two attempts even after that confirmation, since it evaluates the
  command pattern independently of chat context; the user ran the actual recovery commands themselves
  in their own terminal. Full details, including the exact commands, in `docs/
  backup_restore_runbook.md`.
- **Separately discovered while verifying that recovery: `cmis` already had far less data than the
  project's history implies it should, *before* this incident.** The backup file itself (checked
  directly, independent of the restore process) already lacked the real ADMIN user (`wittaya.su`) and
  most historical test data — meaning something removed the richer dataset at an earlier,
  unidentified point before T-054 even started, unrelated to the same-day incident above. Not
  investigated further — user-deprioritized 2026-09-09 ("เดินหน้าต่อไปก่อนเลย admin ค่อยเพิ่มทีหลัง").
  **No admin user currently exists in `cmis`** — needs the same bootstrapping step used the very
  first time (real SSO login, then grant `ADMIN` via tinker) before anyone can use `/admin/users` for
  real again. See the runbook's own "Known open items" for the root-cause candidates worth checking
  if this is ever investigated.
- **T-055 (documentation) intentionally does not choose a Windows-native Redis solution.** Spec names
  Redis 7 (§4.1), but Redis has no official Windows Server build — `docs/iis_installation_guide.md`
  §4 lists the three real options (Memurai, WSL2, a separate host) without picking one, since it's a
  genuine infrastructure decision for whoever actually provisions the production server, not something
  this task should guess at. Same judgment-call class as T-015's GHS statements or T-037's F-01
  layout — document the real choice honestly rather than silently pick one and hide the trade-off.
- **T-055's install guide flags SEC-DB-01 (3 separate DB users: `cmis_app`/`cmis_ledger`/`cmis_report`)
  as still unbuilt**, repeating T-027's already-recorded gap at the point where it matters most —
  right before a real deployment. Nothing new was built to close it; this is a documentation pass
  making an existing gap visible at go-live time, not a re-decision.
- **`docs/user_manual.md`/`docs/admin_manual.md` were written directly against real routes/
  permissions/lang strings** (`php artisan route:list`, `PermissionSeeder`'s `$grants` array, `lang/
  th/*.php`), not from memory of what the app "should" do — every workflow claim was checked against
  actual code first. This caught one real draft error before it shipped: the disposal reason `WASTE`
  was first guessed as "ใช้หมด" (used up) but the actual lang string is "ของเสีย" (waste/spoilage) —
  fixed by checking `lang/th/disposals.php` directly rather than trusting the enum name's English
  gloss. **Any future user-facing documentation for this app should be checked against the actual
  `lang/th/*.php` strings the same way** — guessing a Thai label from an English DB enum value is an
  easy, silent way to ship a wrong instruction.
- **T-056's UAT test plan is a script for real human testers, not something this task could run
  itself** — `docs/uat_test_plan.md` maps every spec §14 acceptance criterion plus a full per-role
  end-to-end scenario list, but the actual execution needs real people acting as each role. Same
  reasoning as T-055's install guide: some deliverables are documentation *for* a human step, not a
  substitute for it.
- **T-056's "penetration test" is explicitly scoped as an agent-conducted security review, not a
  licensed third-party pentest** — `docs/penetration_test_report.md` says so on its first page.
  Ran OWASP ZAP's active scan (`zap-full-scan.py`, real attack payloads, not just T-053's passive
  baseline) — 132 rules passed (SQLi across 5 DB engines, XSS, SSRF, SSTI, XXE, RCE including
  Log4Shell/Spring4Shell/Text4Shell, command injection, path traversal, and more), 0 new confirmed
  vulnerabilities. Investigated all 4 Medium-risk alerts individually rather than blanket-accepting
  or blanket-dismissing them: 2 are the already-approved CSP exception (T-053); 1 ("Bypassing 403",
  `X-Original-URL` header) was manually confirmed a false positive — this app's nginx/Laravel never
  read that header, verified by sending it to both a protected route (identical 302-to-login with or
  without it) and an already-public one (identical 200 either way); 1 ("HTTP Only Site") is expected
  for this TLS-less local Docker environment and is explicitly flagged as needing re-verification
  against the real HTTPS production URL before go-live, not silently waived. The false-positive
  waiver is recorded in `docker/zap/baseline.conf` (rule `40038`) with the investigation, same pattern
  as T-053's CSP waiver — nothing hidden, every exclusion has a reason attached.
- **Manually verified every business-logic authorization case a generic scanner can't reason about**,
  using each test role's own real CSRF token and hitting endpoints directly (bypassing the UI, not
  just checking that a button is hidden): ST-04 (IDOR — a second student 403s opening another
  student's requisition by URL), ST-05 (a PHP webshell renamed `.pdf` is rejected — confirmed via the
  database, not just the HTTP response, that no `Attachment` row was created), ST-10 (STUDENT/
  SCIENTIST/LAB_MANAGER/AUDITOR all 403 on every endpoint outside their role, including a direct POST
  to `/adjustments` with a valid CSRF token — proves the Policy layer itself blocks it, not just that
  CSRF happens to fail first), and ST-10b (a logged-in, roleless user reaches only the pending-role
  page, 403 everywhere else including `/`).
- **Found and fixed a real availability bug while testing (T-056), specific to the `fpm`/`nginx`
  stack T-052 added**: `docker compose exec app ...` (the CLI dev container) runs as root and shares
  the same bind-mounted `storage`/`bootstrap/cache` that `fpm`'s worker needs to write to as
  `www-data` (`docker/php-fpm/www.conf`'s `user = www-data` — correct least-privilege for a real
  web-facing process). Once `app` touches those paths (any `artisan` command that writes a log line
  or compiles a view), ownership flips to root and `fpm` loses write access — every subsequent
  request needing to log or compile a view then 500s with no clear error, **including the `/up`
  health check itself**, which is what made this concrete and traceable rather than a vague "some
  pages break" report. Fixed with `chown -R www-data:www-data storage bootstrap/cache` (run from
  either container, both have root for this). **This is not a one-time fix** — it recurs every time
  `docker compose exec app` writes to those paths again, so re-run the `chown` before testing
  anything through `localhost:8091` after using `localhost:8090`/`docker compose exec app` for
  artisan commands. Not a real production concern (IIS's Application Pool identity is the only
  process touching webroot files there, per `docs/iis_installation_guide.md` — this is purely a
  side effect of two containers sharing one bind mount for local dev/test convenience).

## Post-launch feature — Multi-branch (lab-scoped) access control

Added after the 56-task backlog was complete, at the user's explicit request: split the
warehouse's access model by branch, with a manager per branch who administers only that
branch, and requisitioners restricted to their own branch. This is a real architectural
addition, not a spec task, so its judgment calls are recorded here the same way backlog
tasks' are.

- **"Branch" is the existing `labs` entity** (already had CRUD via `LabController`/
  `lab.manage`, since T-022) — user-confirmed, not a new table.
- **`users.lab_id`** — a nullable FK to `labs` that has existed since the very first
  migration (`2026_08_31_130003_create_users_and_rbac_tables.php`) but was **completely
  unused by any application code** until this feature — is now the single column serving
  two purposes at once, by user-confirmed design: "which branch does this person belong
  to" for STUDENT/STAFF requisitioners, and "which branch does this LAB_MANAGER manage" for
  managers (1 person = 1 lab, so a manager's own branch and the branch they administer are
  the same value). No second column or table was added for either purpose.
- **The branch "whitelist" is `users.lab_id` itself, not a separate table** — user-confirmed:
  a LAB_MANAGER whitelisting a member is literally an ADMIN-equivalent, scoped `setLab()`
  call (`App\Livewire\Labs\LabMemberManager`, new `lab.manage_members` permission, LAB_MANAGER
  only). Candidates are restricted to users holding **STUDENT or STAFF** — the two roles that
  actually hold `requisition.create` per `PermissionSeeder` — not "นิสิตอาจารย์บุคลากร" read
  literally; ADVISOR/SCIENTIST/etc. never requisition, so they're not part of this whitelist.
  A manager can add an unassigned user (`lab_id === null`) or remove one already in their own
  branch, but can never reassign a user already belonging to a *different* branch — that still
  requires ADMIN, via a new `UserRoleManager::setLab()` method (same admin-only
  `manageRoles` ability already used for role/active toggling).
- **Requisition creation now snapshots `lab_id` from the requester's own profile**, exactly
  like the other requester-identity fields BR-11/T-031 already established (`student_code`/
  `program`/`faculty`/`advisor_id`) — the create form's old free-choice lab `<select>` is
  gone; `RequisitionRequest` no longer accepts `lab_id` as input at all. A requester with
  `lab_id === null` is redirected to a new `account.pending-lab` page (mirroring
  `PendingRoleController`'s shape) instead of the create form — checked at both `create()`
  (the page gate) and `store()` (`abort_if($user->lab_id === null, 403)`, defense in depth,
  since `requisitions.lab_id` is `NOT NULL` and a direct POST could otherwise hit a DB
  constraint violation instead of a clean redirect).
- **Every existing LAB_MANAGER action that was system-wide is now scoped to the actor's own
  branch**, gated on `$user->hasRole('LAB_MANAGER')` specifically (via the existing
  `User::hasRole()`) so SCIENTIST/AUDITOR — who hold several of the same permission codes
  (`requisition.view_all`, `ledger.view`, `report.view`) — are entirely unaffected:
  `AdjustmentService::adjust()` (approver's lab vs. the container's lab), `DisposalPolicy::
  decide()` (same, via the disposal's container), `LocationPolicy::view()`/`update()` (the
  location's own `lab_id`) plus `LocationRequest`'s create-time validation (submitted
  `lab_id` must equal the manager's own), and `IssueService::assertWithinTolerance()`'s BR-04
  overage approver (approver's lab vs. the requisition's lab).
- **User-confirmed asymmetric rule for "no resolvable lab"**: a container with no
  `location_id`, or a location with no `lab_id`, has no branch to conflict with — so every
  **write** check above *allows* the action when the lab can't be determined (same
  reasoning already used for the pre-existing report filters treating this as "no lab
  attached"). Every **read/list** view, by contrast, *hides* such a row from a lab-scoped
  LAB_MANAGER (confirmed via AskUserQuestion) — see the next point. This is deliberately
  not the same rule in both directions; don't try to unify them.
- **LAB_MANAGER's read-only views are scoped too, user-confirmed** — not just their
  approval/edit actions: `RequisitionPolicy::view()` + `RequisitionTable`'s list query
  (`requisition.view_all`), the per-item ledger (`ItemLedger`/`LedgerQueryService`/
  `LedgerFilter`'s new `labId`, including its PDF/Excel export in `LedgerExportController`
  and the async `GenerateLedgerPdfExportJob` — the job's stored `filter` JSON needed a new
  `labId` key, and `LedgerExportRequest`'s `@property` array-shape docblock needed updating
  to match or PHPStan flags the `?? null` read as accessing a nonexistent offset), and all
  six §7.8 reports in `ReportController` (a new `labIdFor()` helper **forces** the
  LAB_MANAGER's own `lab_id`, ignoring/overriding whatever `lab_id` the query string
  carries — a LAB_MANAGER can't widen their own view by hand-editing the URL). Two reports
  (`ExpiringStockExport`, `ControlledSubstancesExport`/`ControlledSubstancesPdfService`) had
  no lab filter at all before this and gained one, matching the existing optional-filter
  pattern `BelowReorderPointExport`/`DeadStockExport` already used; `UsageSummaryExport`
  filters directly on `requisitions.lab_id` (already a real column). The two stock-take
  reports take a specific `StockTake` (which already carries its own `lab_id`) so scoping
  there is a 403 check (`ReportController::authorizeStockTakeOwnLab()`), not a query filter.
- **`item.manage` stays entirely unscoped, user-confirmed** — Items are a global catalog
  with no `lab_id` column at all (same fact already noted elsewhere in this file), so there
  is nothing to scope by. A LAB_MANAGER's item-management reach is unchanged.
- **The location tree's own listing page (`LocationTree`, `viewAny`) stays unscoped even
  though `update`/`create` are now branch-restricted** — deliberately, not an oversight.
  Locations are a 4-level hierarchy (BUILDING > ROOM > CABINET > SHELF) where `lab_id` is
  nullable at every level; an upper-level node (e.g. a shared BUILDING) may have no
  `lab_id` at all while its children do. Filtering the *list* to "rows where `lab_id`
  matches my own" would silently break the tree (a lab-scoped child rendering with its
  shared parent missing), and spec never asked for a lab-scoped location list — only the
  edit/create actions this feature explicitly targets. Revisit only if a real need for a
  per-branch location list surfaces later.
- **PHPStan gotcha found while verifying this feature (environment-specific, not caused by
  this feature's code)**: `vendor/bin/phpstan analyse` (parallel worker mode) crashes with
  `Undefined constant "Larastan\Larastan\LARAVEL_VERSION"` in this Docker/Windows
  environment — `composer install`/`dump-autoload` does not fix it. Root cause not fully
  isolated (looks like Larastan's `bootstrap.php` — which boots the real app to `define()`
  that constant — silently fails to run to completion inside a parallel worker subprocess
  on this setup, though a direct manual repro of that exact code path wasn't conclusive).
  **Workaround**: run `vendor/bin/phpstan analyse --debug --memory-limit=2G` instead — the
  `--debug` flag forces single-process mode, which sidesteps whatever breaks in the workers,
  at the cost of also needing a higher memory limit than the default worker pool would use
  per-process. If a future session hits the same crash, try this before assuming a real
  config problem.
- **Restored 2026-09-14 on top of the working-stock/single-step-requisition merge** (see that
  section below) — applied via `git stash pop` with a clean auto-merge, no manual conflict
  resolution needed, since none of the approval-flow simplification touched lab-scoping code.
  Extended at the same time to cover **stock-in** (`StockInController`/`StockInRequest`), which
  didn't exist when this feature was first built: a LAB_MANAGER's `create()` page only lists
  their own branch's locations, and `store()` rejects a `location_id` outside their own branch
  (same "submitted value must match the manager's own `lab_id`" check `LocationRequest` already
  used). This closes what would otherwise have been the one completely unscoped write path into
  inventory, now that stock-in (not GRN) is how stock actually enters the system.

## Post-launch — Working-stock replenishment & single-step requisition (server-side branch, merged 2026-09-14)

Pulled in from 13 commits authored on the server-side deployment branch — a real architectural
simplification requested by the user directly ("อยากให้เป็นแค่ระบบเบิกจ่ายย่อยๆ working stock... ไม่จำเป็นจะต้องมี
การนำเข้าจาก PO"), not a spec task. Recorded here since it changes core behavior earlier phases'
notes assumed.

- **GRN (goods receiving from a PO) is gone as the way stock enters the system.** In its place,
  **stock-in** (`StockInController`, `/stock-in`) writes directly to `stock_ledger` via
  `LedgerService::receive()` (`refType: 'WORKING_STOCK'`) — no purchase order, no receiving
  document, just "how much, of what, into which location, as one container or in bulk." Gated on
  `receiving.manage`/`item.manage`/`ledger.adjust`/ADMIN (checked in `StockInRequest::authorize()`,
  not a dedicated Policy — same reasoning as `ReportController`'s bare permission checks: no real
  Eloquent resource for a Policy to attach to at create time). `GoodsReceipt`/GRN code itself was
  **not removed** — only no longer the primary path; check before assuming it's fully retired if a
  later task touches it.
- **BR-02 (a STUDENT requisition needs advisor sign-off before a scientist may approve) is
  removed.** `RequisitionState::can()`/`apply()` now allow `SUBMITTED → scientistApprove/Reject`
  for every requester status including STUDENT; `ApprovalService::scientistDecide()` no longer
  throws for a STUDENT with `advisor_signed_at === null`. The advisor step itself still exists
  (submitting a STUDENT requisition still emails/notifies the advisor, per T-033/T-044) but is now
  informational, not a gate — a scientist can approve and issue immediately without waiting. This
  is genuinely a **single-step** requisition flow now, not the multi-stage one every earlier
  phase's notes (T-030 through T-038, BR-02's own entry near the top of this file) describe as
  enforced. Any future work touching the approval flow should treat BR-02 as removed, not merely
  relaxed.
- **`items.base_unit_id`/`package_unit_id` are now nullable** — stock-in can create a brand-new
  item on the fly without a unit chosen yet; the first stock-in against that item backfills
  `base_unit_id` (and `package_unit_id` if still unset) from the unit used in that transaction
  (`StockInController::store()`). Every other service that reads an item's `base_unit_id` to feed
  `LedgerEntryData::$displayUnitId` (a non-nullable `int`) needs an explicit null guard now —
  `AdjustmentService`, `DisposalService`, and `StockTakeService` each throw a `RuntimeException` if
  the item somehow has no base unit despite already having a real container (an invariant that
  should be impossible in practice: a container can't exist without having been stocked in first).
  **Any new code path that reads `Item::$base_unit_id` for a ledger write needs the same guard.**
- **`items.expiry_date`** is a new column (a fallback default when a specific container's own
  expiry isn't given at stock-in time) — separate from `containers.expiry_date`, which is the
  authoritative per-container value everywhere else in the app.

- ~~CMIS not registered as an SSO client~~ — **registered 2026-08-31**: `client_id=CMIS`,
  `redirect_uri=http://localhost:8090/sso/callback`. Real credentials are in `.env` (`SSO_CLIENT_ID`/
  `SSO_CLIENT_SECRET`, gitignored — never in `.env.example`). Live redirect to the real MEDSCI ACC login
  page confirmed working end-to-end.
- Unclear whether MEDSCI ACC enforces 2FA for high-privilege roles (SEC-AU-12) — needs confirmation before
  closing T-010/T-011.
- ~~`wittaya.su` is the current (only) real logged-in user, manually granted `ADMIN` via tinker~~ —
  **re-bootstrapped 2026-09-14** after the 2026-09-09 data loss noted above: `wittaya.su` logged in
  again via real SSO and was re-granted `ADMIN` via `php artisan tinker`. Future role grants for other
  real users should go through the admin UI (`/admin/users`) instead of tinker.
- **No written data-retention period exists for PDPA (SEC-PD-04)** — the university/faculty needs to
  decide how long a departed user's PII stays before `php artisan users:pseudonymize` should be run
  against them. Blocks turning that command into a scheduled, automatic job.
- **No real Data Protection Officer / breach-response contact has been named** — `pdpa_breach_response_plan.md`
  (T-050) uses job-title placeholders throughout; needs real names/phone numbers/emails before it's an
  actually-usable incident plan, not just a structural template.
- **The Privacy Notice text (`lang/th/privacy.php`, T-050) has not been reviewed by legal counsel** —
  written to be a genuine, defensible first draft (same class of judgment call as T-015's GHS statement
  wording), but the notice itself says so explicitly and should not be treated as final without review.

## Post-launch — Chemical catalog bulk import (`chemicals:import`, 2026-09-15)

The user provided a raw ~8,225-row export of the central-store chemical/material catalog
(`download.csv`, gitignored/not committed — item codes prefixed "AS", no category column,
no separate quantity/unit columns, quantity and packaging embedded as free text in the name
string, e.g. `"Asiatic Acid 500 mg /ขวด"`). Curated it down to `database/data/
chemicals_import.csv` (5,261 rows) and built `ImportChemicalsCommand` (`php artisan
chemicals:import`) to load it. Every judgment call below was made with the user, not guessed:

- **`item_code` is the "AS" code verbatim, not a CMIS-generated one** — user-confirmed, so the
  imported catalog stays directly reconcilable against the central store the codes came from.
- **"Real chemical" filtering was a genuine, iterative classification problem, not a clean
  rule** — the source data mixes true lab chemicals/reagents with dental materials, pharmacy
  products (insulin, inhalers, tablets), Thai/Chinese herbal-medicine raw materials packaged
  in ห่อ/แพ็ค, and finished cosmetic/consumer products (essential oils, Nivea, nail polish).
  A pure "has CAS number or grade marker" regex only caught ~2,200 of 8,202 distinct names;
  reaching a workable split required several rounds of showing the user concrete sample
  buckets and confirming specific category calls: microbiology media/reagents with no CAS
  (agar, broth, BSA) → **count as chemical**; industrial/high-pressure gases (Helium UHP) →
  **count as chemical**; cosmetic/consumer finished products and food/herbal raw-material
  packs → **exclude entirely**, even though some (e.g. Dimethicone, Laureth-series INCI raw
  materials) are legitimate industrial chemicals in their own right and were kept. Final
  split: 5,261 include / 1,623 exclude / 1,318 rows the classifier itself flagged as still
  ambiguous — that "unclear" bucket was handed back to the user rather than silently guessed
  either way. **Any future addition to this catalog from the same source should expect the
  same triage effort** — there is no shortcut rule that classifies this dataset cleanly.
- **23 exact-duplicate-name rows were dropped, keeping the row with the numerically higher
  "AS" code** (assumed more-recently-added in the source system) — a reasonable default with
  no stronger signal available; noted here in case a duplicate ever turns out to have been
  the wrong one to keep.
- **Quantity/unit/CAS/grade/molecular-formula were parsed out of the free-text name via
  regex**, not guessed per-row — ~92% of the 5,261 included rows got a clean quantity+unit
  match; the rest import with `package_size`/`base_unit_id` left `null` rather than a wrong
  guess (`ImportChemicalsCommand` accepts null for both, matching the working-stock/single-
  step merge's `base_unit_id`-nullable change above). The parsed `raw_name` (original,
  unparsed string) and the packaging word (ขวด/หลอด/กล่อง/...) are preserved in `items.
  specification` for anyone who wants to double-check or re-derive something the parser
  discarded.
- **Added a new `ug` (microgram) unit** (`UnitSeeder`, `factor_to_base = 0.001`) — user-
  confirmed, since ~99 rows are dosed in µg (mostly antibiotic-susceptibility discs/potency
  markers) and the system previously had nothing below `mg` for the MASS dimension. `mg`
  stays the dimension's actual base (`is_base = true`, factor `1`, unchanged) — `ug` is
  purely an additional non-base unit, so no existing stored quantity anywhere in the app
  changes meaning; re-running `UnitSeeder` (idempotent, `updateOrCreate`) is all a fresh
  environment needs to pick it up.
- **`ImportChemicalsCommand` is idempotent by `item_code`** — an existing code is skipped,
  never overwritten, so it's safe to re-run after hand-fixing a handful of rows in the CSV
  (e.g. filling in a `package_size`/`base_unit` the parser couldn't confidently extract) —
  only the newly-fixed rows will actually insert on a re-run.

## Post-launch — PubChem (NIH) chemical lookup (2026-09-15)

Followed directly from the chemical-import work above: the user asked whether PubChem
could be mapped against our catalog, then asked for two real features once feasibility was
confirmed (empirically, with live `curl` calls against the real API before writing any
code — see the conversation, not repeated here). Both share one `PubChemClient`
(`app/Domain/Chemicals/Services/PubChemClient.php`):

- **Auto-fill on the item create/edit form fills the form, never auto-saves** —
  user-confirmed explicitly: a "ค้นข้อมูลจาก PubChem" button (vanilla JS + `fetch()` to
  `ChemicalLookupController::lookup()`, matching the project's established "simplest tool"
  convention for one small JSON endpoint — same shape as T-031's real-time balance) fills
  `formula`/`name_en` and checks `ghs_codes[]`/`h_statements[]`/`p_statements[]` boxes, but
  the user still has to review and click Save themselves like any other edit.
- **The standalone `/chemicals/lookup` page never touches the `items` table at all** —
  it's a pure research tool for procurement ("is this the right compound, what hazard class
  does it carry, before we buy it"), gated on a bare `item.view` permission with no
  dedicated Policy (same reasoning as `ReportController`: no real Eloquent resource for a
  Policy to attach to).
- **PubChem needs no API key/auth** — confirmed live against the real
  `pubchem.ncbi.nlm.nih.gov/rest/pug` (compound properties) and `.../rest/pug_view`
  (GHS Classification) endpoints. `PubChemClient` never throws on a network failure, a
  timeout, or "not found" — every failure path returns `null`, so a PubChem outage
  degrades to "the user fills the field in by hand," never a broken create-item page.
- **Every lookup is cached 30 days** (`Cache::remember`, keyed by CAS or lowercased name)
  — a compound's PubChem data is effectively static, and caching keeps the same common
  reagent being looked up by several people over time from ever re-hitting the network.
- **GHS codes/H-statements/P-statements PubChem returns are filtered against this app's
  own `config/ghs.php` before being handed back** — confirmed empirically that PubChem
  knows at least one real official P-code (P265) that T-015's own reference table doesn't
  have, so a naive pass-through would let `ItemRequest`'s `Rule::in(...)` validation reject
  an auto-filled value the user never even chose. Silently dropping the unknown code (not
  erroring, not blocking the rest of the auto-fill) was the judgment call — the same
  "config/ghs.php isn't necessarily exhaustive of every official code" gap T-015 already
  flagged, just hit for the first time by a second real data source instead of guessed at.
- **PUG View's exact section nesting for "GHS Classification" isn't hardcoded** —
  `PubChemClient::findGhsInformation()` walks the `Section` tree looking for the heading by
  name, since PubChem has restructured this nesting before and a depth-hardcoded path would
  silently break (return no GHS data at all, not an error) the next time they do.
- **All 11 new tests use `Http::fake()` — none make a real network call**, matching
  `SsoLoginTest`'s existing precedent for faking an external HTTP dependency in this app.

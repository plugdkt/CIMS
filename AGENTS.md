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

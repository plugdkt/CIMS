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

### Fixed

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

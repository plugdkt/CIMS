# CMIS handoff — IMS, shop, requisition and dispensing work (Oct 2026)

Status as of 2026-10-29. Branch `master`, last commit `beb7b7e`. Full suite 673 tests green, PHPStan level 8 and Pint clean
at that commit. **Nothing below has been click-tested in a real browser by the AI session** (it cannot SSO-login) — every
UI item needs a human pass (see "Verification").

Read first: `CLAUDE.md` (project rules and every earlier judgment call), section "Post-launch — IMS layer above working stock".

## 1. Mental model

```
university warehouse report / manual entry
        │  (receipt document: DRAFT → confirm selected complete lines)
        ▼
IMS (branch central store)   ims_receipts → ims_receipt_lines → ims_lots (→ ims_lot_groups) ; ims_movements (append-only)
        │  cut automatically at dispensing, or by hand (lots.transfer)
        ▼
Working stock                containers + stock_ledger (append-only, BCMath, _base in the item's own base unit)
        │
        ▼
Requisition (shop cart) → [STUDENT only: advisor approves first, BR-02 reinstated 2026-10-30] → manager approves (may approve less) → one-click dispense
```

IMS is per branch (`labs`). Quantities are packs × catalog package size; price is per pack. Only `ims.view` holders see prices.

## 2. What has been built

### IMS
- Receipts: create manually (line by line, no file needed) or fill a DRAFT from the stock-summary PDF
  (`ImsStockReportParser` reads text *position*, not AI — the report's Thai text layer is garbled, the AS code is the identity).
- Confirm only the ticked, complete lines (partial confirm). Packaging units added (box, set, pack, … factor 1).
- Lots: storage location, photos, groups of packs (split a lot by place + expiry), pack count shown in the list.
- Catalog: every row of both central-store exports imported (`catalog:import`, chemicals + materials).
- Menus: IMS first in the inventory group; "นำเข้าวัสดุและสารเคมี" is a tab inside IMS; "Working Stock" on a second line.
- **Browsing** by ordinary users (`ims.browse`: STUDENT/STAFF/ADVISOR/SCIENTIST), read-only, no prices.
- **Storage places tab** (`ims.places.*`, `StoragePlaceOverview`, Livewire `Ims\PlaceContents`): list of places with substance
  counts (subtree rolled up), search by place or substance, place page with IMS lots + working-stock bottles,
  live "type to find → + add" and per-row "remove" (clears the lot's location; never touches stock).
- **Bulk locate** (`ims.lots.locate`): pick one place, tick many lots, save once.
- Warnings for lots with no storage place: banner + filter in the lot list, warning after confirming a receipt,
  warning on the dispense page with buttons (set location / cut into working stock).
- Partial multi-word search (`ImsLot::search` scope) on lot list, bulk-locate and place page.

### Shop and requisition
- Shop (`/shop`): search, category tabs, product detail page (locations, expiry per batch, hazard info), session cart,
  checkout creates and submits a normal Requisition. Availability = working stock in the branch + non-expired IMS lots
  (`ShopAvailability`).
- Menus split: "ใบเบิกของฉัน" (`RequisitionTable`) vs "จัดการใบเบิก" (`RequisitionManageTable`, drafts excluded,
  next-step buttons instead of "view").
- Manager can approve less than requested (earlier feature, see CLAUDE.md).

### Dispensing (`/requisitions/{id}/issue`)
- Page has **only** "จ่ายทั้งใบเบิกในครั้งเดียว": one row per line with quantity prefilled, one button (`issue-all`).
- Per line: working stock first, shortfall cut from IMS in whole packs (soonest expiry first, lot **must have a location**),
  then FEFO across containers. A failing line does not roll back the others; failures are listed.
- **No receiver signature/OTP any more** (user decision 2026-10-16). A SHA-256 marker of
  `ISSUE|requisition|issuer|receiver|time` is stored in `signature_hash` (column is NOT NULL).
- Receiver selectable (default requester; others must be active users of the same branch).
- If any dispensed line is a controlled substance, a tick-box confirmation is required (`controlled_ack`).
- Return section per line is unchanged, except: returning a controlled substance also requires the tick-box
  (`controlled_ack`; CEO decision 2026-10-30). `RequisitionReturnController::store` rejects the return (no stock change)
  if it is not ticked; the checkbox shows only for lines with `is_controlled`. Non-controlled returns need no tick.

## 3. Known leftovers and risks

1. **Dead code: resolved.** The per-line issue routes (`items.issue`, `items.issue-auto`), `receiver-otp.send` and the related
   OTP/signature-image services were deleted in commit dbf6d04 (2026-10-30). Kept on purpose: columns
   `signature_hash` / `signature_image_path` and the reading of old signatures/OTP in the F-01 print.
2. **Double counting on re-import (important).** `ImsReportImporter` imports the *closing balance* of the PDF as new lots.
   Importing a later month's report for a branch that already confirmed one **adds the whole balance again**.
   Only the identical file (SHA-256) is blocked. See next section.
3. Controlled-substance evidence is now only a tick-box + a hash; no signature. Revisit if audit requires more.
4. One full-suite run showed 1 failing test that passed on two reruns (not identified; likely random-data collision).
   If it reappears, find it before shipping.
5. `composer audit`: `league/commonmark` (high) and `laravel/framework` (low) advisories — needs a dependency update pass.
6. Packaging word per item is still parsed from `items.specification` ("หน่วยบรรจุ:"), not a real field.
7. Receipt line add form has no autocomplete by item name (code only).
8. Working-stock bottles on the place page have no remove/move action.
9. A lot that is split into groups: removing the lot-level place only affects the "unplaced packs" row; groups have their own place.
10. Item codes AS01xxxxx/AS03xxxxx (office/cleaning supplies) are not in either catalog export.

## 4. What to do next (priority order)

1. **WAIT for the warehouse custodian's answer** (asked 2026-10-19, returns the week of 2026-11-02): can the central
   store export *only incoming/replenished items* (Excel/CSV preferred; fields: code, qty, unit price, lot, expiry,
   document no., fiscal year, round)? The answer decides the design of the import:
   - incoming-only export → import only the additions, no comparison needed;
   - balance-only export (CSV/Excel or the current PDF) → compare to current IMS stock per code and label each line:
     **unchanged / increased (import only the difference) / new item / decreased (info only)**; never auto-tick;
     the manager decides. Note the IMS balance may be lower than the report because of requisitions made in CMIS.
2. **Interim guard (optional, independent of the answer):** when importing a PDF into a branch that already has confirmed
   PDF-imported lots, show a warning that confirming again may double count.
3. (Dead-code question closed, see 3.1.)
4. Dependency update pass (`composer audit`).
5. Real field for packaging word; autocomplete by name on the receipt line form.
6. Single report covering the chain receive → cut → dispense → balance per branch and fiscal year (data exists, spread over pages).
7. "Lots near expiry" list in IMS.
8. Outside the system: R2R topics 2 and 3 ethics forms (AF03/AF04.3/AF05.3); borrow-return and consumables modules.

## 5. Deployment checklist (server)

- `git pull`, `composer install` if needed, `php artisan migrate`
- run `docker/mariadb/restrict_app_grants.sql` on **every** database (new `ims_*` tables otherwise fail with INSERT denied;
  run once more on `cmis_testing` after its first test run)
- `php artisan db:seed --class=UnitSeeder` and `--class=PermissionSeeder` (new permission `ims.browse`, new packaging units)
- `npm run build` on the host/server (Tailwind CSS is gitignored; new utility classes appear in the UI)
- catalog import if the server DB has not got it yet: `php artisan catalog:import database/data/catalog_chemicals.csv --category=CHEMICAL`
  and `catalog_materials.csv --category=MATERIAL`
- IMS lots need a storage location or the automatic IMS→working-stock cut at dispensing fails (by design, with a clear message)

## 6. Verification (human pass needed)

Walk this once as: ผู้ดูแลคลัง, then a normal user.
1. Import a PDF / add a receipt line manually → confirm ticked lines → see the "no storage place" warning.
2. Bulk-locate lots; open a place page; type `alco` → press + → row appears → remove it.
3. As a user: browse IMS (no prices), open the shop, add to cart, check out.
4. As manager: approve (try approving less), open the manage page → dispense page → one button.
5. Dispense a controlled substance (tick-box required) and choose a different receiver; then return a controlled
   substance (without the tick → rejected, stock unchanged; with the tick → saved).
6. Check the stock ledger and IMS balances afterwards.

## 7. Working agreements (from the user)

- Commit + push **only** when the user says "commit + push เลย". Never commit `R2R/` (personal data) or `IMS_Micro.pdf` (prices).
- Implement exactly what is asked; tell the user which UI parts were not click-tested.
- Commit trailer: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Tooling: `docker compose exec -T app vendor/bin/pest|pint|phpstan analyse --debug --memory-limit=2G`; write multi-line
  scripts to a file instead of bash heredocs with mixed quotes; Docker Desktop may need restarting.
- All UI text via `lang/th/*.php`; labels need `for`/`id`; scrollables need `tabindex="0"`; new route-bound models need a ULID route key.

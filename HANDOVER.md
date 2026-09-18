# TAN90 ERP — Project Handover

Last updated: 2026-09-18. Written for whoever picks this project up next — a new developer, or the client's own technical team.

---

## 1. What this is

A single Laravel application covering multiple ERP modules for a cold-chain manufacturing/distribution business, merged into one codebase over several build phases. It is **not** one small app — it's six largely independent modules sharing a database, a login page, and (partially) a permissions story:

| Module | What it does | Status |
|---|---|---|
| **Gate / GRN** | Inward, Outward and Visitor gate-entry lifecycle — the core day-to-day operational flow (Guard → Store Manager → Store Executive → QC → Finance). | Actively developed this session — see §12. Most mature module. |
| **Access Control** | A second, more advanced permission engine (roles, positions, org hierarchy, dashboard builder) layered on top. | Present, partially used by Forge/Flow/Workspace. |
| **Tan90 Master Data** | Master records: items, vendors, customers, plants/warehouses/bins, UOM, tax rules, SLA policies — plus its own approval workflow and a **third** role system. | Present, own module. |
| **BOM / Recipe / Costing (BRC)** | Bill of materials, recipes, routings, standard/actual costing, engineering change orders. | Present, own module. |
| **Forge** | Manufacturing execution — work orders, job cards, machines, freezers (cold-chain), quality holds, final QC, batches. | Present, own module. |
| **Flow** | Outbound fulfillment — customer orders, allocation, picking waves, packing, dispatch/shipment, delivery, returns. Cold-chain aware (temperature excursions, shelf-life). | Present, own module. |

If you only came here for the Gate/GRN module (the one most recently worked on), read §4.1, §5, §12 and §13, then stop — you don't need the rest.

---

## 2. Tech stack

- **PHP** ^8.3, **Laravel** ^13.8
- **Livewire** ^3.6, **Livewire Volt** ^1.7 — most Gate/GRN screens are Volt single-file components (`resources/views/livewire/**/*.blade.php`, PHP class + Blade template in one file)
- **barryvdh/laravel-dompdf** ^3.1 — server-side PDF export (gate entry detail → PDF)
- **Vite** ^8, Tailwind CSS ^3 (+ `@tailwindcss/forms`), `lucide-static` for icons (hardcoded into `app/Support/Icons.php` — see note in §12)
- **pdfjs-dist** + **tesseract.js** (client-side) — Guard's Bill Scan screen uses these for OCR-based auto-fill from a scanned bill (deliberately *not* an AI/LLM API — classical OCR only, by the client's explicit instruction)
- Testing: PHPUnit ^12 + Mockery + Faker. No Pest.
- No queue backend beyond Laravel's default sync/database driver; no Redis, no Horizon.

---

## 3. Running it locally

Don't duplicate `SETUP_FOR_RECIPIENT.md` (already in this repo) — it has the full Docker-based setup, the pre-seeded demo database dump, and the complete list of demo logins across all three role systems. Start there. Short version: Docker Desktop → `docker compose up -d` → import `database/dumps/tan90_demo_seed.sql` → `demo123` as the password for any listed email.

---

## 4. Architecture

### 4.1 Gate / GRN module (the one most recently built out)

One continuous record, `gate_entries`, carries an inward delivery, an outward dispatch, or a visitor pass through its entire lifecycle. `entry_type` (`inward`/`outward`/`visitor`) and `status` together determine what stage it's at and who acts on it next. As of this session, all three journeys are fully built:

- **Inward**: PO raised (Store Manager/Admin) → vendor accepts + uploads docs → Guard logs the vehicle → Store Manager approves (Entry Approvals) → Store Executive assigns dock, then unloads → QC splits/checks → Store Manager posts GRN → Store Executive puts away to a bin → entry closes.
- **Outward**: Guard logs the dispatch → Store Manager approves + assigns dock + sets loading window/EDD (one action) → Store Executive loads + post-load QC → Guard confirms exit.
- **Visitor**: Guard creates a pass with a real "person to meet" (a staff account, not free text) → that person approves/denies → Guard allows entry (checked_in) → Guard checks the visitor out (closed).

See `resources/views/livewire/guard|vendor|unloading|qc|grn|store-manager|shared/*.blade.php` for the screens, `app/Services/GateValidationService.php`, `GrnPostingService.php`, `QcService.php`, `ThreeWayMatchService.php` for the business logic, and `app/Support/NotificationCenter.php` for the role-specific notification rules.

**7-role enum** (`App\Enums\Role`): `Guard`, `Vendor`, `StoreExec`, `Qc`, `StoreManager`, `Finance`, `Admin` — see §5.

### 4.2 Access Control

A second, independent permission engine: `AccessRole` → `AccessPermission` (many-to-many, with per-assignment scope: `self`/`team`/`unit`/`vertical`/`plant`/`location`/`warehouse`/`vendor`/`all`), assignable directly to a user or via an org hierarchy (`AccessVertical` → `AccessUnit` → `AccessTeam` → `AccessPosition`, plus `AccessShift`). Also owns saved list views, a drag-and-drop dashboard builder, and its own audit log. Core logic in `app/Services/Access/AccessControlService.php` (`can()`/`explain()`). Users opt in via `User->access_mode` (`legacy` vs `advanced`); a `super_admin` flag bypasses everything when not legacy. This is what gates Forge, Flow, and Workspace — **not** the 7-role enum.

### 4.3 Tan90 Master Data

Master records for items, vendors, customers, the location hierarchy (plant/warehouse/zone/location/bin/rack/shelf), UOM, tax/GST rules, SLA policies, number series, notification templates — plus a maker-checker governance layer (`MasterChangeRequest`, `ApprovalWorkflow`), CSV bulk import, and a data-quality scanner. Almost every screen is driven generically by one big controller (`MasterDataController`) keyed on an `{entity}` route parameter, rather than one controller per entity.

### 4.4 BOM / Recipe / Costing (BRC)

BOM and Recipe definitions with versioning, routings/operations/process parameters, standard and actual costing with roll-up and what-if simulation, and engineering change orders (ECO) with an approval/implement workflow. Same generic-entity-controller pattern as Master Data for its simpler reference screens.

### 4.5 Forge (manufacturing execution)

Shop-floor execution against BOM/Recipe/Routing data. `WorkOrder` (status ladder: `draft → released → material_reserved → material_issued → in_progress → reconciliation → final_qc_pending → released_to_fg/rework/rejected → closed`) auto-spawns one `JobCard` per routing operation. Also covers material issue, production recording (with a separate approval step), quality holds, final QC, deviations/non-conformance, machine downtime tracking, and freezer/cold-chain monitoring. Output is a `Batch`.

### 4.6 Flow (outbound fulfillment)

Order-to-delivery for finished goods coming out of Forge. `CustomerOrder` (status ladder: `draft → validated → released → atp_confirmed/atp_partial → allocated → waved → picking → picked → packing → packed → dispatch_planned → loading → in_transit → pod_received → delivered → closed`, plus `on_hold`/`cancelled`). Covers inventory receipt/putaway, allocation, picking waves, packing into handling units, dispatch/shipment with in-transit temperature-excursion tracking, delivery, and returns. Cold-chain aware throughout (temperature requirements, shelf-life constraints).

---

## 5. Three separate role/permission systems — read this before touching auth

This is the single most important thing to understand before changing anything access-related. There are **three independent systems**, not one:

1. **The 7-role enum** (`App\Enums\Role`) — gates only the Gate/GRN module, via `role:xxx` route middleware (`app/Http/Middleware/EnsureRole.php`, which now also accepts a comma-separated list, e.g. `role:admin,storeManager`). Deliberately kept separate per the client's explicit "strict portal isolation" requirement — nothing here merges with the other two systems. Admin bypasses every role check.
2. **The Access Control engine** (`app/Models/Access/*`, `AccessControlService`) — gates Forge, Flow, Workspace, and its own admin screens via permission-key checks (`can($user, 'forge.workorder.view')`), not route middleware.
3. **The Tan90 Master Data / BRC role system** (`App\Models\Tan90\MasterData\Role`/`Permission`/`UserProfile`) — a third, separate role table gating the Master Data and BRC modules, via `Tan90\MasterData\PermissionService`.

A user can hold a role in any combination of these three — `User->role` (enum), `User->tan90Profile` (system 3), and Access Control role assignments are all independent columns/relations that can all be populated (or not) on the same `User` row. **Do not assume a user's role in one system tells you anything about their access in another.** `User::tan90Profile()` and `hasNewAccess()`/`access_mode` are the two places to check when you need to know which system(s) apply to a given user.

This session's work on the Gate/GRN module surfaced a real gap here: a "Procurement Reviewer" demo persona exists only in system 3 (`role=null` on the `users` row), and code that assumed every user has an enum `role` crashed for them (see `app/Support/NotificationCenter.php`'s `forRole(?Role $role)` — now null-safe, with a `tan90Fallback()` branch). If you add anything that reads `auth()->user()->role` directly, check whether it needs the same guard.

---

## 6. Controllers (`app/Http/Controllers/`, ~56 files)

The Gate/GRN module has **no dedicated Controllers** — it's Volt components + a handful of inline closures in `routes/web.php` (PDF download, demo-login routes). Everything below is the other five modules.

| Directory | Files | Covers |
|---|---|---|
| *(root)* | `Controller.php`, `ClaudeOAuthController`, `ZohoWebhookController`, `Auth/VerifyEmailController` | Base class; in-app Claude chat widget OAuth+chat; Zoho PO webhook ingest; Breeze email verification. |
| `AccessControl/` | 7 | `AccessRoleController` (role CRUD+clone), `AccessPeopleController` (assign roles/overrides), `HierarchyController` (org tree), `SavedViewController`, `DashboardBuilderController`, `ActivityController` (audit log + CSV export), `AccessSimulatorController` ("what can this user see"). |
| `Workspace/` | 4 | `WorkspaceController` (personal home), `TaskController`, `ApprovalController`, `ExceptionController` — generic cross-module task/approval/exception inboxes. |
| `Forge/` | 11 | Work orders, job cards, machines, freezers, production plans, wastage, quality holds, final QC, deviations, batches, yield analysis. |
| `Flow/` | 8 | Inventory (receive/putaway), customer orders, picking waves, packing, dispatch, delivery, returns. |
| `Tan90/MasterData/` | 12 | `MasterDataController` (generic entity CRUD+governance), dashboard, approval queue, change requests, CSV import, data-quality scanner, GST verification, integration-connection test, attachments, audit trail, module settings, permission matrix. |
| `Tan90/BomRecipeCosting/` | 10 | Dashboard, recipe/BOM/routing CRUD+versioning, costing (rollup/approve/simulate), ECO, MRP readiness, where-used, audit trail, generic reference-master CRUD. |

Full per-file descriptions are in the codebase survey this document was built from — ask for it again if you need the file-by-file breakdown re-generated (it's not worth pasting ~56 rows of one-liners into a doc meant to be read, not scanned as a table of contents).

---

## 7. Routes

- **`routes/web.php`** (~415 lines) — the Gate/GRN module (role-gated, see §5.1), plus non-gate groups: `access-control/*`, `workspace*`, `forge/*`, `flow/*`.
- **`routes/tan90_master_data.php`** and **`routes/tan90_bom_recipe_costing.php`** — registered via their own service providers (`bootstrap/providers.php` → `Tan90\MasterDataServiceProvider`, `Tan90\BomRecipeCostingServiceProvider`), **not** included from `web.php`. Both gated by plain `['web','auth']` — no route-level role/permission middleware; access is checked inside each controller action via system 3.
- Demo login routes worth knowing about: `role-login/{role}` (system 1, the 7-role enum), `tan90-role-login/{roleCode}` (system 3), `demo-user-login/{user}` and `demo-login/{user}` (branch across all three systems depending on what the target user actually has).

---

## 8. Models by module

- **Gate/GRN + shared** (`app/Models/`, 20 files): `GateEntry`, `PurchaseOrder`(+`Line`,+`Acknowledgement`), `VendorSubmission`, `VendorStockUpdate`, `QcResult`, `GrnRecord`, `LedgerEntry`, `FinanceRecord`, `DebitNote`, `ValidationIssue`, `SkuMaster`, `VendorMaster`, `SupplierClaim`, `Rfq`, `UnloadingRecord`, `AuditLogEntry`, `User`, `ZohoEntityLink`.
- **Access Control** (`app/Models/Access/`, 18 files): `AccessRole`, `AccessPermission`, `AccessUserRole(Assignment)`, `AccessUserPermissionOverride`, `AccessPosition`, `AccessTeam`, `AccessUnit`, `AccessVertical`, `AccessShift`, `AccessSavedView`, `AccessAuditLog`, dashboard-related (`AccessRoleDashboardLayout`, `UserDashboardLayout`, `DashboardTemplate(Item)`, `DashboardWidget(Catalog)`).
- **Workspace** (`app/Models/Workspace/`, 6 files): `WorkspaceTask(Event)`, `WorkspaceApproval(Event)`, `WorkspaceException(Event)`.
- **Forge** (`app/Models/Forge/`, 15 files): `WorkOrder`, `JobCard`, `Batch`, `ProductionEntry`, `ProductionPlan`, `MaterialIssue`, `WastageRecord`, `QualityHold`, `FinalQcResult`, `Deviation`, `Machine`, `MachineDowntimeEvent`, `Freezer(Log)`, `FreezerReading`.
- **Flow** (`app/Models/Flow/`, 12 files): `CustomerOrder`, `OrderLine`, `Allocation`, `PickingWave`, `PickTask`, `HandlingUnit`, `Shipment`, `Delivery`, `TemperatureEvent`, `InventoryLot`, `InventoryMovement`, `ReturnRequest`.
- **BOM/Recipe/Costing** (`app/Models/Tan90/BomRecipeCosting/`, 33 files): BOM/Recipe/Routing + their line/version tables, `Component`(+`Alternate`), `SubstitutionRule`, `FinishedGood`, `ByProduct`, `CoProduct`, `ScrapRecovery`, `YieldRecord`, `TemperatureProfile`, `QualitySpec`, `WorkCenter`, `CostRate`, `CostSheet`, `CostRollup`, `CostVariance`, `CostSimulation`, `EngineeringChangeOrder`, `ChangeImpact`, `ReleaseGate`, `Approval`, `AuditLog`, `SyncJob`.
- **Tan90 Master Data** (`app/Models/Tan90/MasterData/`, 44 files): `Item`(+`Category`,+`UomConversion`), `Vendor`(+`Contact`), `Customer`, `Plant`, `Warehouse`(+`Zone`), `Location`(+`GstRegistration`), `Bin`, `Rack`, `Shelf`, `Machine`, `Transporter`, `Uom`, `LegalEntity`, `BusinessUnit`, `HsnTaxRule`, `TemperatureClass`, `QualityParameter`, `SlaPolicy`, `NumberSeries`, `NotificationTemplate`, `DocumentRule`, its own `Role`/`Permission`/`UserProfile` (system 3, see §5), `ApprovalWorkflow`(+`Step`,+`Progress`,+`StepDecision`), `MasterChangeRequest`(+`Version`), `DataImportJob`(+`Row`), `DataQualityIssue`(+`Rule`), `ModuleSetting`, `IntegrationConnection`, `MasterAttachment`, `MasterAuditLog`.

---

## 9. Services by module

Gate/GRN: `GateValidationService`, `GrnPostingService`, `QcService`, `ThreeWayMatchService`, `AuditLogger`, `NotificationCenter` (`app/Support/`), `ZohoService`, `ZohoInventoryService`, `Zoho/ZohoApiGate`.

Other modules:
- `Access/AccessControlService`, `Access/PermissionRegistry`, `Access/Widgets/*` (dashboard widget data providers)
- `ClaudeService` (in-app AI chat assistant — note: this is a separate, opt-in feature the user explicitly did **not** want anywhere near the Gate/GRN module's own business logic; see `CLAUDE_SETUP.md`)
- `Flow/FulfillmentService`
- `Forge/WorkOrderService`, `Forge/FreezerMonitoringService`
- `Tan90/BomRecipeCosting/*` (17 files — one service per concern: BOM/recipe validation, approval, costing, ECO, MRP readiness, revisioning, where-used, etc.)
- `Tan90/MasterData/*` (12 files — approval, CSV import, data quality, GST verification, module settings, notification dispatch, number series, permission service, entity registry/validator)

---

## 10. Database

135 migration files under `database/migrations/`. A pre-seeded demo dump is at `database/dumps/tan90_demo_seed.sql` (see §3).

---

## 11. Production deployment

Live at **tan.bookmytimes.in**, Hostinger shared hosting, PHP 8.3 (`/opt/alt/php83/usr/bin/php` — the server's default `php` binary is 8.2 and won't run this app).

**The deploy path is unusual and worth understanding**: the local development machine this was built on has no PHP installed, and Hostinger's shared hosting doesn't expose a git remote you can `git push` to directly. Deploys go: commit locally → `git bundle create` → upload the bundle over SFTP → on the server, `git fetch <bundle> HEAD:<tmp-ref>` → `git merge` → run `artisan migrate --force` + cache-clear commands over SSH. If you're setting up a saner deploy pipeline (recommended — this was a workaround, not a design choice), you'll want either a proper git remote reachable from Hostinger, or CI that can SSH in directly.

**Credentials are deliberately not in this file or the repo.** SSH host/port/username/password and the Hostinger control panel login are held outside version control — ask the project owner for them rather than looking for them here.

---

## 12. What changed in this build phase (Gate/GRN module)

The Gate/GRN module went through a large rebuild this phase, driven by the client's own stated business process (procurement → vendor → gate → approval → dock → unload → QC → GRN → putaway for inward; a mirrored outward flow; a host-approval flow for visitors) plus multiple rounds of live QA against the production instance. Highlights, newest first:

- **POD/LR-missing-at-gate** downgraded from a hard approval block to a non-blocking warning — it fired on nearly every entry and was blocking Store Manager approval on deliveries with nothing actually wrong.
- **Newest-first queues** everywhere (Entry Approvals, Loading Desk, Unloading Desk, QC Queue, Outward Loading, Putaway, Visitor Approvals) — previously oldest-first, so old test/demo entries could bury a just-created one at the bottom indefinitely.
- **PO Master warns when a vendor has no portal login** — of the ~190 vendor master records, only 3 currently have an actual `User` login; raising a PO against any other vendor is a dead end until one is provisioned (Admin → Users).
- **Fixed a real raw-JSON leak** on the gate entry detail page — eager-loaded relations (`qcResult`, `grnRecord`, `financeRecord`, `unloadingRecord`, `validationIssues`) were being dumped as literal `json_encode()` output in the generic field list, because `Eloquent::toArray()` auto-includes loaded relations and nothing excluded them.
- **Timezone bug fixed**: the generic field-describer parsed `toArray()`'s UTC-serialized datetime strings without converting back to `config('app.timezone')`.
- **Putaway is now its own step** after GRN posting, not implicit in it — a new `grn_posted` status sits between GRN posting and `closed`; Store Exec confirms (or relocates) the actual bin before the entry closes, and the final bin is now tracked in a `final_bin` column rather than only in `GrnRecord.suggested_bin` (which never updated on relocation).
- **Outward journey built from scratch** — previously a Guard save with zero downstream workflow. Now: Guard logs it → Store Manager approves + assigns dock + sets loading window/EDD in one action → Store Exec loads + post-load QC (with an early-loading-window override requirement) → Guard confirms exit.
- **Visitor journey rebuilt**: "person to meet" is now a real `User` reference (was free text nobody ever acted on), routed to that person for approval via a new shared Visitor Approvals screen; a real check-in/check-out pair (`visitor_checked_in_at`/`visitor_checked_out_at`) replaced an instant-close on "Allow entry."
- **Inward approval gate added**: every inward entry now waits for an explicit Store Manager approval (new Entry Approvals screen) before dock assignment, even with zero validation issues raised — previously a clean entry skipped straight to `validated` with no human review.
- Numerous smaller data-correctness fixes: vendor fulfilment % now reflects QC-accepted quantity (was invoiced quantity), PO status now updates on release, a stale "Submitted" badge now shows the real linked gate-entry status, QC now captures a documents-checked checklist and optional product parameters alongside the quantity split, and more — see git log for the full list (`git log --oneline` from roughly commit `61dfacd` onward covers this phase).

---

## 13. Known open items

- **QC parameters are free-form**, not a fixed checklist — the client never specified exact spec fields per SKU, so QC adds whatever parameters apply per delivery rather than picking from a predefined list.
- **General closure notifications to vendor/procurement are partial** — vendor gets notified on returns and on normal closure; there's no equivalent for every possible downstream event yet.
- **Vendor portal login provisioning is manual** — see §12. Only 3 of ~190 vendor master records currently have a login; there's no self-service or bulk-provisioning flow yet.
- **`ERP-QA-Review-Sep-2026.md`** in this repo pre-dates this build phase's rebuild (it describes the flow before the approval gate, outward journey, putaway step, and visitor check-in/out existed) — treat it as historical context on the pre-existing codebase quality, not a current bug list.
- Access Control / Master Data / BRC / Forge / Flow were **surveyed but not modified** this phase — no claim is made here about their correctness or completeness, only that they exist and roughly what they do.

---

## 14. Other documents in this repo

- `SETUP_FOR_RECIPIENT.md` — local setup (Docker), full demo login list across all three role systems.
- `CLAUDE_SETUP.md` — setup for the optional in-app Claude chat widget (separate feature, not part of core ERP logic).
- `ERP-QA-Review-Sep-2026.md` — a prior code-path audit of the Gate/GRN module; see the caveat in §13.
- `README.md` — stock Laravel boilerplate, no project-specific content.

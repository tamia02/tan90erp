# Tan90 ERP — System Review & QA Pass (Sept 2026)

Scope: Guard, Vendor, Store Executive, QC, Store Manager, Finance, Admin workspaces + the "starting eight" operational sections. Static review of the code paths behind each screen (the sandbox has no PHP/Docker, so this is a code-path audit, not a live click-through — run the walkthrough checklist at the end on the live server to confirm).

---

## 1. How the modules interlink (the flow map)

One continuous record, `gate_entries`, walks through every module. Each module reads the record at its own status and writes it forward. Nothing is duplicated between modules — each owns one step.

```
Admin
 ├─ SKU Master (mapped = gate-acceptable)   ─┐
 └─ Vendor Master (GST on file)             ─┤  feed the gate check
                                            ▼
GUARD (Bill Scan)                     Vendor portal submission (invoice/POD pre-upload)
   validates against PO Master,       ──────────────────────────────┐
   SKU Master, Vendor Master,                                      │
   duplicate invoice/vehicle                                       │
   ├─ issues raised → status = pending_validation ────► STORE MANAGER (Validation Issues) resolve/approve/escalate → re-clears to validated
   └─ no blockers  → status = validated
                            │
                            ▼
STORE EXECUTIVE (Loading Desk)   assigns a free dock → dock_assigned
                            │
                            ▼
STORE EXECUTIVE (Unloading Desk)  allots staging bay (auto) → allotted → start → complete
                            │
                            ▼
QC (QC Queue)                 accept / QC-hold / defective / rejected split
   ├─ fully rejected → status = rejected → vendor notified (purchase return)
   └─ otherwise     → status = qc_done
                            │
                            ▼
STORE MANAGER (GRN Check)     posts to ledger (available/defective/rejected/qcHold buckets)
   ── creates GrnRecord + LedgerEntry rows + FinanceRecord (payable) + DebitNotes + 3-way match
                            │
                            ▼
FINANCE (Review)              review payables, clear only after 3-way match passes
   ── AP aging / vendor claims / reports
                            │
                            ▼
ADMIN (Command Center)        sees everything; also owns SKU/Vendor/PO/RFQ masters, integrations, reports
```

Interlink summary:

| # | From | To | Handoff mechanism |
|---|------|----|-------------------|
| 1 | Vendor portal | Guard | VendorSubmission row keyed by invoice number; guard "Fetch" pulls it |
| 2 | Guard | Store Exec | GateEntry.status = validated → Loading Desk list |
| 3 | Store Exec (loading) | Store Exec (unloading) | status = dock_assigned (loading_dock + dock_assigned_at) |
| 4 | Unloading | QC | status = grn (confusingly named — this means "ready for QC Check", not GRN) |
| 5 | QC | Store Manager | QcResult row; status = qc_done (or rejected) |
| 6 | Store Manager | Finance | GrnPostingService creates FinanceRecord + DebitNote; 3-way match runs immediately |
| 7 | Store Manager | Stock | LedgerEntry rows (available/defective/rejected/qcHold buckets) |
| 8 | Admin | Everyone | SKU/Vendor masters feed gate validation; PO master feeds 3-way match |
| 9 | Zoho | App | cron pulls POs/vendors/items/customers; webhook pushes PO updates |

Status ladder a single gate entry climbs: `pending_validation → validated → dock_assigned → allotted → unloading → grn (ready for QC) → qc_done → closed`, plus `rejected` if fully rejected at QC.

---

## 2. Bugs & issues found (code-level)

### A. Confirmed, high-confidence (read straight off the code)

1. **SLA breaches count every non-closed entry, even ones on a dock** — Guard dashboard `breachedCount` = `status != closed AND sla_deadline < now`. Entries that are mid-flow (validated, dock_assigned, unloading) and simply slow but not stuck all count as "breached" with no separate "at risk" tier. Cosmetic severity, but the number will look alarming.

2. **SLA deadline is set once at gate creation using the vendor's directive, never re-armed at handoff** — so a vehicle that waited 24h for a dock then gets unloaded/QC'd is already "breached" the moment it starts its next step. No per-step SLA exists at all (nothing re-sets sla_deadline on status change).

3. **Hardcoded ₹42 rate in GrnPostingService (`RATE_PER_UNIT`)** — the ledger/finance payable is computed at ₹42 regardless of the PO's actual `list_price` or the rate the guard recorded. The 3-way match then flags "invoice rate differs from PO rate" for any real PO priced differently. This makes the entire finance ledger wrong for any vendor except the single seeded one. Confirmed in code: `private const RATE_PER_UNIT = 42`. This is the highest-impact data bug.

4. **No unique constraint on gate_no; random `GATE-####` generated client-side in the form** — two guards submitting in the same second can produce the same number; nothing enforces uniqueness at DB level. Seeder also inserts fixed gate numbers. Risk of duplicate gate numbers.

5. **Full rejection at QC leaves no path to stock/finance** — intended (nothing to post), but the gate simply becomes `rejected` and the vendor gets a notification. There is no "purchase return" workflow beyond that notification: `return_status` on QcResult is set but I found no code that consumes `return_status = pending` other than the vendor dashboard button. If the vendor never acts, the rejected goods sit in limbo with no resolution or re-inspection path.

6. **Validation Issues screen is Store Manager-only, but issues are auto-created by the Guard's own save** — the guard sees their blocked entry but cannot fix it (no edit). The Store Manager must manually Approve/Resolve/Escalate each one. This is fine as a workflow, but there's no cross-link from the guard's entry detail to the issue list, and no audit that the *guard* ever resolves anything.

7. **`documentCount`/`documentSummary` only applies to outward; inward has no documents section** — the guard's inward form never captures e-way/LR/POD checkboxes even though the validation service checks `has_lr_pod` from the vendor submission. So the "documents" story is asymmetric: outward tracks docs, inward doesn't.

8. **`fetchBillDetails` autofills invoiceQty and rate but NOT invoiceAmount** — the inward form requires invoiceAmount (`required|numeric`), so a guard who "Fetches" a vendor submission or Zoho PO must still hand-type the bill amount, which is exactly the kind of duplicate typing the fetch was meant to avoid. Minor UX bug.

9. **`fillSample` leaves the fetched flag unset for outward but sets it for inward** — outward quick-fill sets `billScanned=true` but never `fetched`; inward quick-fill sets `fetched=true`. Inward save requires fetched; outward doesn't. So the quick-fill buttons behave inconsistently per entry type (probably intentional, but easy to trip on).

10. **Rate mismatch validation compares guard-entered rate vs PO primary line, but PO primaryLine() may be null** — if the PO exists but has no lines, `$primaryLine` is null and the check is skipped silently. Same in 3-way match (it handles null with an exception status, which is correct).

### B. Likely, needs live confirmation

11. **Demo login security posture** — `routes/web.php` has a deliberate, commented "no isProduction() gate" for `/role-login/{role}` and `/tan90-role-login/{roleCode}`, which log in ANY user by role with zero credentials, including Admin. The comment says this is an explicit informed decision for the demo deployment. That means the live site at 13.207.135.250 is currently open: anyone can click "Administrator" and get full admin. If this box is internet-reachable, that's a critical exposure. Even for a demo this should be behind an IP allowlist or a toggle. The login page explicitly renders "Open any seeded account" buttons. **This is the most important finding on the list.**

12. **Email verification is effectively bypassed** — `bootstrap/app.php` does not register `verified` middleware globally, and routes under `/guard`, `/vendor`, etc. only use `['auth', 'verified']`... wait — actually they do use `['auth', 'verified']`. But `email_verified_at` is never set for the 7 seeded role users (only Tan90 seeders set it to now()). UserFactory sets it to now() for factories but the DatabaseSeeder's `firstOrCreate` does not include it. So every seeded role user has `email_verified_at = null`, and the `verified` middleware would block them... unless Laravel treats `null` as verified. In Laravel, the `verified` middleware calls `ensureEmailIsVerified()` which uses `MustVerifyEmail` — but User does NOT implement `MustVerifyEmail` (it's commented out at the top of User.php). Without that interface, `verified` middleware does nothing. So verification is a no-op: harmless for demo, but worth knowing the mechanism is off.

13. **`qc` queue uses `invoice_qty` as both poQty and invoiceQty in QcService::recordResult** — the QC form doesn't let the user confirm po/invoice quantities; it just passes `$gate->invoice_qty` twice. The PO quantity shown in the GRN Check comparison comes from the PO line, so the split can silently exceed PO qty without warning at QC time.

14. **Dock assignment allows the same dock to be double-assigned under race** — `occupiedDocks()` is computed at render; `assignDock` validates `Rule::in($this->availableDocks())` on a fresh request, but two simultaneous requests for two different gates could both see the same dock free. No DB lock/unique constraint. Low likelihood, but it's the kind of thing that shows up in a busy warehouse.

15. **Gate entry has no "edit" screen for the guard** — an entry saved with a typo (vehicle number, invoice number) can only be fixed by... nothing. There's no update path anywhere for GateEntry. If the guard fat-fingers an invoice number, that's a permanent DUP_INVOICE source for later real invoices.

### C. Lower-confidence / architectural notes

16. **Two separate audit systems** — `audit_log_entries` (GRN pipeline) and `tan90_master_audit_logs` (Master Data). Same concept, different tables, different shapes. For an owner, this means "audit trail" is not one place.

17. **Three separate RBAC systems** — legacy `role` enum, Tan90 `tan90_roles`, and Access Control `access_roles`. A user can exist in any combination. EnsureRole handles the bridging. This is deliberate per the merge plan, but it's the most likely source of "why can/can't X see Y" confusions.

18. **`grn` status name is semantically confusing** — Store Exec's "Complete & send to QC Check" sets status `grn`, which actually means "ready for QC". Anyone reading raw data will misread it.

---

## 3. Base-level issues (foundation)

1. **₹42 hardcoded rate** — one of the worst kinds of bugs because every downstream number (ledger, finance, vendor claims, debit notes) inherits it. This must be the first fix.
2. **No data integrity enforcement on gate_no / dock assignment** — no unique indexes where concurrency matters.
3. **SLA is a single deadline, not a per-step ladder** — the "SLA breached" counters will mislead as soon as flows are slow but healthy.
4. **No edit/amend path for a wrongly-entered gate entry** — combined with (2), typo'd invoice numbers become permanent duplicates.
5. **Purchase-return flow is notification-only** — rejected stock has no resolution workflow.
6. **Demo logins are open to the internet** — the single biggest real-world risk if the box is reachable.
7. **Gate number generation in the form (not the server)** — a UI element controls a business-identity field.

---

## 4. Feature recommendations (owner lens)

### High value / low effort
- **Per-step SLA ladder** — re-arm sla_deadline at each status transition (validated → dock_assigned → unloading → qc → grn). Cheap, removes the fake "breached" alarm.
- **Use real PO rate everywhere** — replace ₹42 with `$po->primaryLine()->list_price` (or the guard-entered rate with an override) across GrnPostingService, FinanceRecord, DebitNotes, 3-way match.
- **Gate entry edit/amend** — allow guard or store manager to correct a gate entry before it's posted, with an audit trail entry.
- **Dock/vehicle availability as a live status board** — it already computes occupied docks; surface it as a mini board on the Store Exec dashboard.
- **Purchase return workflow** — from QcResult.rejected, create a ReturnRequest with vendor acknowledgment, return-dispatch tracking, and closure.

### Medium value
- **Gate dashboard "at risk vs breached"** — split SLA counters so healthy slow flows don't look broken.
- **Inward document checklist** — capture e-way/LR/POD on inward too, mirroring outward.
- **Auto-fill invoice amount on Fetch** — compute `qty × rate` from the submission/PO instead of asking the guard to type it.
- **One activity/audit view across modules** — a single "everything that happened to gate X" timeline (gate → unloading → QC → GRN → finance) per entry.

### Differentiator (what makes this ERP feel owned)
- **Live dock + vehicle flow board** — a single visual pipeline (gate → dock → bay → QC → GRN) that any role sees in read-only.
- **Vendor self-service returns portal** — vendors see rejected qty with photos, acknowledge, upload return LR/POD.
- **Mobile-first guard app hardening** — offline draft for gate entries when the warehouse network drops (currently everything is online-only).
- **SLA analytics** — weekly report on SLA breach rate by vendor/location; feed vendor scorecards.

---

## 5. Live walkthrough checklist (run on the server to confirm)

1. Open `http://13.207.135.250/login`. Note which "open any account" buttons appear.
2. Login as **Security Guard** → Bill Scan → Quick Scan Autofill → Save. Check: entry appears in Guard Entries as validated; the "Send to Unloading" button leads to Store Exec dashboard.
3. As **Store Executive**: Loading Desk shows the new validated entry → assign a dock → Unloading Desk → allot → start → complete with box count.
4. As **QC**: QC Queue shows it → accept e.g. 695, hold 0, defective 5, rejected 0 → submit.
5. As **Store Manager**: GRN Check shows it with "QC quantity vs PO price" → post with a bin.
6. As **Finance**: Finance Review shows the payable, match status, debit note (defective 5 × ₹42 = ₹210). Confirm the ₹ amount is right for your real PO (it will be wrong if the PO rate ≠ 42).
7. As **Admin**: Command Center → confirm the gate appears with full timeline.
8. Re-enter the same invoice number at the gate → confirm the duplicate-invoice block fires and the entry lands in pending_validation for Store Manager to resolve.
9. Check the guard dashboard SLA Breached counter against a deliberately slow walk (leave an entry on a dock >12h) — expect it to show breached even though nothing is stuck.
10. As **Vendor**: create a submission with invoice number → go to guard Bill Scan → Fetch → confirm fields autofill (and that invoice amount is NOT autofilled).

# Flowwork `/finances/` — Full Due-Diligence & Remediation Plan
_Audit date: 2026-07-08 · HEAD `c0809c3` · read-only audit, no code changed_

## Context

You asked for a complete due-diligence audit of everything served under
`https://www.flowwork.app/finances/` — the double-entry finance suite (GL, AR, AP,
bank, VAT201, fixed assets, budgets, reports, settings) plus the `/qi` customer-
invoicing module that posts into the GL — reporting **(a) what is not correct** and
**(b) what is short/missing**, structured as a sectioned plan you can hand to Opus
4.8 to fix and extend.

A large SARS-compliance remediation already merged (PRs #55/#58, documented in
`FINANCE-SARS-FINDINGS.md`). That work is solid: the posting engine, tenant scoping,
CSRF, VAT201 core math, immutability and tie-outs are genuinely well-built. **This
audit targets what is still wrong or missing at HEAD (`c0809c3`)** — almost all of
it in the *edges* the remediation didn't reach: document lifecycle transitions, the
qi↔finances seam, reversals, reporting, provisioning, and unbuilt features.

### How this was produced
A 12-area parallel audit (GL core, AR/qi, AP, bank, VAT, fixed-assets/inventory,
reports, dashboard/master-data, security, UX-wiring, schema/tests, product-gaps).
Every critical and the load-bearing highs were **re-verified by reading the actual
code** (the automated verifier hit a usage limit; I did those by hand). ~200 raw
findings → the confirmed set below. Nothing here is speculative: each item cites a
file and the offending code.

### How to read / hand to Opus 4.8
Sections are ordered by urgency. Each item is written so it can become a work
ticket: **file:line → what breaks → fix**. Sections A–F are "not correct" (bugs,
security, schema, wiring, tests). Section G is "short" (the product roadmap).
Section H is the suggested execution order and verification approach.

> **One caveat on "live vs. tracked":** the production site is running and most of
> these do not crash it today (prod has state/columns applied over time). The
> defects bite on *specific flows* (refunds, credits, voids, FX, rule-matched VAT,
> filed-period edits) and on *rebuild/new-tenant/DR/test* provisioning. Severity
> below reflects financial-correctness impact, not "is the homepage up".

---

## SECTION A — Critical correctness bugs (money / GL / VAT wrong)

These silently produce wrong ledgers, wrong VAT201 numbers, or an AR/AP subledger
that no longer ties to its control account. Fix first.

**A1. Customer refunds post nothing to the GL.** `qi/ajax/refund_invoice.php`
inserts a negative payment + negative allocation and re-opens `balance_due`, but
never calls `PostingService`. The original receipt's `Dr Bank / Cr AR` stays, so
after any refund the GL bank is overstated by the cash paid out, AR control
disagrees with the invoice, and no s21/VAT effect is booked. (Also: no CSRF, no
role gate, leaks raw `$e->getMessage()`, and the "flag original payment"
`UPDATE … JOIN … ORDER BY … LIMIT` is invalid MySQL that fails every time.)
*Fix:* post `Dr AR (or a customer-refunds account) / Cr Bank` inside the txn via a
`PostingService::postCustomerRefund` (or reuse `postCustomerPayment` with a
negative allocation); add `Csrf::validate()` + `requireRoles`; mask the error;
rewrite the flag-payment update as a subquery.

**A2. Finance "Sync to GL" posts drafts, cancelled and soft-deleted invoices.**
`finances/ajax/ar_sync_invoice.php:30` calls `PostingService::postInvoice()`, which
(verified at `PostingService.php:333`) checks only existence + period-lock — **no
status/`deleted_at` guard**. `ar.js:86-90` renders a "Sync to GL" button for *every*
invoice with `journal_id IS NULL`, i.e. exactly drafts, voided invoices
(`unpostInvoice` clears `journal_id`) and reverted-to-draft ones; "Sync All" loops
them. Posting a draft/cancelled invoice injects revenue+VAT into the GL and VAT201
for a document that shouldn't be there. *Fix:* in `postInvoice()` reject
`status IN ('draft','cancelled','written_off','refunded','uncollectible')` and
`deleted_at IS NOT NULL` unless an explicit repost flag is passed; only show the
sync button for issued statuses.

**A3. Payments/credits can be allocated to *unposted* bills, corrupting the GL and
bricking the bill.** `finances/ap/api/payment_create.php:98` (and
`vendor_credit_post.php:90`) lock the bill row selecting only totals — **no status
check**. `payment_new.php:170-175` offers *every* bill with `balance>0` including
drafts. Paying a draft bill posts `Dr AP / Cr Bank` while the bill's own journal
(`Cr AP` + expense + input VAT) was never posted → AP control goes debit, expense &
input VAT never recognised; and once the allocation settles the balance the bill
flips to `paid`, after which `postApBill` **permanently refuses to post it**. *Fix:*
require `status IN ('posted','paid')` (and `journal_id IS NOT NULL`) in the
FOR-UPDATE validation of both endpoints; filter the payment/credit UIs to posted
bills; only promote `posted`→`paid`.

**A4. Receipt-to-bill hardcodes 15% VAT on every line.** `receipts/api/save_bill.php:116`
sets `$taxRate = 15.00;` for every `ap_bill_lines` row regardless of the actual
receipt (fuel, zero-rated, exempt, non-vendor). When posted, `postApBill` recomputes
input VAT from that stored rate → **input VAT over-claimed** on every non-standard
receipt (a SARS exposure). (Also: this endpoint and `receipts/api/post_to_gl.php`
have **no `Csrf::validate()`** — the only finance-adjacent write path missing it.)
*Fix:* capture a per-line rate in the review wizard (default 15%, allow 0), or derive
the effective rate from the OCR header tax/subtotal as `ap_from_receipt.php` does;
add CSRF to both endpoints.

**A5. Rule-matched bank VAT is invisible to the payments-basis VAT201 and the audit
file.** `bank_apply_rules.php:174` posts journals with `ref_type='bank_rule'`, but
`VatCalculator.php:423/441` (and `VatAuditFile`) select bank items with
`ref_type='bank_tx'` **only**. A payments-basis vendor who auto-matches card-sales
deposits or bank charges via rules never sees that output/input VAT in the return or
the substantiation CSV. *Fix:* post rule journals with `ref_type='bank_tx'` (keep
`source_type='bank_rule'` for traceability), or widen the VAT filters to
`ref_type IN ('bank_tx','bank_rule')`.

---

## SECTION B — High-severity integrity & security

### B-GL / reversals / year-end
**B1. Reversals lose the original journal's `module`.** `ReversalService.php:86`
hardcodes `module='fin'` on every reversal and never even SELECTs the original
module. `VatCalculator` measures VAT *by module* (`vat_settle` excluded, `vat_adjust`
/`bad_debt` measured separately). So reversing a VAT-settlement/adjustment/bad-debt
journal (the only way to undo a mistaken "Mark Filed") lands the reversal in the
wrong bucket and **corrupts a later period's VAT201**. *Fix:* carry the original
`module` onto the reversal; and block manual reversal of `vat_settle`/engine journals
except through a proper unfile/repost flow (`journal_reverse.php` currently reverses
*any* posted journal, including engine- and year-end-owned ones).

**B2. Year-end close accepts the current in-progress FY and arbitrary windows.**
`year_end_close.php:69` validates only `YYYY-MM-DD` shape; `year_end.php`'s dropdown
actively offers the current (unfinished) fiscal year. Closing it rolls a partial-year
P&L to retained earnings and plants an **undeletable future-dated period lock** that
freezes all posting, with no reopen flow. Related: already-closed checks don't
exclude *reversed* closes, so a mistaken close→reverse cycle can never be re-closed.
*Fix:* require `fy_end < today`, `fy_start < fy_end`, and boundary-match against
`finance_fiscal_year_start`; start the UI loop at the last *completed* year; add an
admin reopen flow; exclude reversed closes from the overlap check.

**B3. `journal_save` update branch can rewrite the lines of an approved/posted
journal** (check-then-act race, `journal_save.php`). *Fix:* re-assert
`status='draft'` in the UPDATE predicate + rowCount guard, mirroring the approve/post
transitions.

### B-AR / qi lifecycle (each desyncs AR ↔ GL ↔ VAT201)
**B4.** `apply_credit_note.php:87` posts the CN journal **after commit** in a
swallow-all try/catch → applied credit with no journal, AR overstated, VAT201 misses
the output-tax reduction. *Fix:* move `postCreditNote()` inside the txn (as
`record_payment.php` does).
**B5.** `issue_full_credit.php:46` bypasses the s21 credit cap (which
`save_credit_note.php` enforces) and has no status check → clicking twice
manufactures duplicate revenue/VAT reversals. *Fix:* run the same creditable-cap
FOR-UPDATE query, re-validate at approve/apply.
**B6.** `apply_credit_note.php:62` allocates a CN larger than `balance_due` by
clamping the invoice to 0 but recording the **full** allocation + full journal →
AR understated by the excess, customer's real credit lost. *Fix:* allocate
`min(total, balance_due)`, carry remainder as unapplied credit.
**B7.** `record_payment.php:73` has no status guard and `invoice_action.php`
void/cancel leaves `balance_due` intact → a payment can be recorded against a
cancelled invoice (`Dr Bank / Cr AR` with no invoice journal behind it). *Fix:* zero
`balance_due` on cancel; whitelist payable statuses in `record_payment`.
**B8.** `delete_invoice.php:33` soft-delete accepts issued/paid invoices for *any*
authenticated user with **no GL reversal** → receivable vanishes from every AR view
while its revenue/VAT/AR-debit stay posted. *Fix:* restrict soft-delete to drafts (or
require admin + reversal like force mode).
**B9.** `delete_invoice.php:54` force-delete reverses the invoice journal but leaves
the **payment** journals posted → AR control stranded negative. *Fix:* block while
payments/credit allocations exist, or reverse them in the same txn.
**B10.** `invoice_action.php:47` void guard checks payment allocations but **not
applied credit notes** → voiding a credit-settled invoice corrupts AR. *Fix:* also
check `credit_note_allocations`; add an unapply-credit action.
**B11.** Standalone credit notes (no `invoice_id`) can **never** post — the only
`postCreditNote()` caller (`apply_credit_note.php`) throws "No invoice linked". *Fix:*
post standalone CNs at approval, track unapplied customer credit.
**B12.** `run_recurring.php:78` (manual "Run now") books FX invoices at rate **1.0**
when no rate exists, while the cron path (`generate_recurring.php:84`) correctly
throws. *Fix:* mirror the cron — throw and roll back.

### B-AP / bank / FA
**B13.** FX on AP is **unimplemented**: `bill_create.php:57` & receipts `save_bill`
store a foreign `currency` but capture no rate; `postApBill` has no `resolveFx()` and
posts foreign face values as ZAR; `ap_payments.exchange_rate` never written. *Fix:*
either reject non-ZAR on AP until supported, or mirror the AR pattern (capture rate,
convert in `postApBill`, realized FX to 4950/8050 on payment).
**B14.** `bank_undo_match.php` reversals (`ref_type='reversal'`) are invisible to the
payments-basis VAT201 → undoing a match leaves phantom VAT; re-matching double-counts.
*Fix:* in the payments-basis bank query add `AND je.reversed_by_journal_id IS NULL`
and net reversal legs, or include reversals that point at a bank_tx/bank_rule origin.
**B15.** CSV import (`bank_import.php:95`) and PDF parser
(`BankStatementParser.php:517`) parse 2-digit years as **year 0026** (PHP `Y` accepts
2-digit input, so the `d/m/y` fallbacks are unreachable), and US-format CSV dates
overflow months. Standard Bank statements use 2-digit years → every row mis-dated,
sorted out of view, blocked by any period lock, missing from VAT/reports. *Fix:*
strict round-trip validation per format; explicit `d/m/y` branch; reject years <100.
**B16.** Manual inventory movements (`inventory/ajax/movement.create.php:68`) post
**no GL journal** and no period-lock check → GL inventory vs stock-on-hand diverge
permanently; endpoint reachable by the weaker `member` role at arbitrary cost. *Fix:*
route through `PostingService::postAdHocJournal` in one txn, stamp `journal_id`, check
the lock, tighten the role.
**B17.** Payments-basis VAT201 omits output VAT on fixed-asset **disposals**
(`asset_dispose.php` posts `ref_type='fa_disposal'`, which `calculatePaymentsBasis`
never scans) → a payments-basis vendor selling an asset omits output tax. *Fix:*
include posted `fa_disposal` VAT-output lines at `entry_date`; mirror in VatAuditFile.
**B18.** Account code/type rename gated only on **posted** lines
(`account_save.php:150`) while `journal_post.php` never re-validates codes → an
approved draft posts onto a now-nonexistent code and its amounts vanish from
TB/P&L/BS. *Fix:* gate on `allLineCount()` (as `account_delete.php` does) or
re-validate codes at post time.

### B-Security
**B19. The qi money surface ignores the read-only `viewer` role.** `record_payment`,
`apply_credit_note`, `approve_credit_note`, `save_credit_note`, `issue_full_credit`,
`invoice_action` (void/write-off/uncollectible), `delete_invoice` (draft),
`create_yoco_link`, `save_invoice`, `duplicate_invoice`, `save/run/toggle_recurring`,
`send_invoice` — all require auth+CSRF but **no role check**, so a finance `viewer`
can move money and alter the ledger. (Documented as an accepted product decision in
FINDINGS.md — **re-confirm or gate**.) *Fix:* add `requireRoles(['admin','bookkeeper'])`
to each, matching the AP/bank endpoints.
**B20. Yoco webhook books a full payment for any signed event.** `webhooks/yoco.php`
never checks event type/status; any signed delivery matching `yoco_reference` marks
the invoice fully paid, ignoring the actual amount. Currently latent (the checkout
flow is a placeholder — see G) but a live gateway would settle on `payment.failed`.
A stale second endpoint (`qi/ajax/yoco_webhook.php`) with weaker guarantees is still
deployed. *Fix:* gate on `payment.succeeded`, record `min(amount, balance_due)`,
retire the duplicate endpoint.

### B-Reporting / dashboard
**B21. Dashboard AR KPIs multiply by raw `exchange_rate`.** `finances/index.php:69`
computes `SUM(i.balance_due * i.exchange_rate)` — a NULL rate makes the row NULL and
`SUM` drops it. `invoices.exchange_rate` was added `NULL` with no backfill (Section C),
so every pre-migration invoice reads **R0** in Receivables Outstanding, overdue and
every aging bucket. `dashboard_stats.php` and the aging endpoint already use
`COALESCE(NULLIF(rate,0),1)`. *Fix:* same COALESCE in all five spots in `index.php`;
backfill (C2).
**B22. Cash-flow (indirect) misclassifies VAT/PAYE/UIF/SDL as financing.**
`report_cashflow_indirect.php:177` treats every non-AP liability movement as
financing → operating cash flow systematically wrong every period. *Fix:* treat
current-liability subtypes (vat_control, paye/uif/sdl, current_liability, accruals)
as operating working-capital changes; reserve financing for loans/equity.

### B-UX (systemic)
**B23. Auth/role failures 302-redirect *inside* fetch → every AJAX action dies as
"Network error."** `requireRoles()` (`permissions.php:37`) and `auth_gate.php` emit a
302 to `access_denied.php`/`login.php`, never a 401/403 JSON; `finance.js:148-165`
then runs `.json()` on returned HTML and throws. So a **session timeout mid-session**
(all users, every action) and any **insufficient-role** click surface as a misleading
"Network error. Please check your connection." — and `access_denied.php` is
unreachable through any fetch. *Fix:* detect XHR (`X-Requested-With`/`Accept: json`)
in both gates and emit `403/401` JSON; handle those in `FinanceAPI.request` (403 →
show error; opaque redirect → go to login).

---

## SECTION C — Schema, migrations & provisioning

**C1. Orphaned `qi/migrations/` directory (High — reproducibility/DR/test fidelity).**
Six migrations (`add_milestones`, `add_branding_columns`, `add_multi_currency`,
`add_customer_currency`, `add_invoice_delete_options`, `add_corporate_template`) are
applied by **nothing** — `tests/db/setup.sh:51` globs only `/Migrations/`, and the
FINDINGS.md runbook never mentions them. The columns/tables they define are absent
from both `/Migrations/` and the base dump, yet used pervasively:
`payment_milestones` & `milestone_payments` (10 qi files), `companies.qi_*` branding
columns (invoice/quote/credit-note views + PDFs), `qi_settings.default_currency`,
`crm_accounts.currency`, `payments.refunded_at/refunded_by/refund_reference`. Live
prod has them (applied by hand at some point) so the site works today, but **any new
environment, DR rebuild, or new tenant provisioned from the tracked schema fatals on
every customer-document view**, and tests validate a different schema than prod.
*Fix:* port all six into ordered `/Migrations/` files and the runbook.

**C2. `invoices.exchange_rate` added NULL with no backfill.** `2026-07-06-finance-sars-01`
adds it `DECIMAL(12,6) NULL`; the superseded qi migration had `NOT NULL DEFAULT 1`
*with* a backfill that was dropped. Root cause of B21. *Fix:* add
`UPDATE invoices SET exchange_rate=1 WHERE exchange_rate IS NULL;` (and the same for
`payments`/`ap_payments`/`credit_notes`/`quotes` for hygiene).

**C3. Missing constraints/indexes.** `UNIQUE(company_id, credit_number)` on
`vendor_credits`; FKs on AR-side children (`invoice_lines`, `payment_allocations`,
`credit_note_*`, `vendor_credit_*`, `fa_depreciation_lines`, `fa_disposals` — AP side
already has them); indexes `fa_depreciation_lines(run_id)`, `(asset_id)`,
`vendor_credit_lines(credit_id)`, `vendor_credit_allocations(credit_id, bill_id)`.
Clean up duplicate indexes on `journal_lines`/`invoices`/`journal_entries`. Money
typing is clean (all DECIMAL — no action).

**C4. Runbook/tooling risks.** FINDINGS.md runbook says "four 2026-07-06 files" but
there are **six** (05 asset-id autoincrement, 06 review-fixes omitted) — a hand-run
skips the `gl_fixed_assets.asset_id AUTO_INCREMENT` fix and the depreciation unique
backstop. Migration 06's `ADD UNIQUE uq_fa_dep_run_month` aborts (and fails setup) if
two *posted* runs share a month. Migration 01 uses MariaDB-only `ADD COLUMN IF NOT
EXISTS` DDL — confirm target is MariaDB 10.11+. *Fix:* reconcile the runbook to six
files, guard the unique-index add, document the MariaDB dependency.

---

## SECTION D — Medium correctness bugs (by subsystem)

Grouped; each is a real bug with a workaround or narrower blast radius than A/B.
Full file:line list is in the audit data; the load-bearing ones:

- **AR/statements:** customer statement omits write-offs & unapplied receipts and
  mixes currencies with no ZAR conversion (`ar_statement.php`) → closing balance
  disagrees with `balance_due`, aging and the GL; stored XSS via unescaped API
  strings in `ar/statement.php`; `save_invoice.php:341` leaks raw SQL error;
  Duplicate Invoice drops `inventory_item_id` (no COGS/stock relief); quote totals in
  float taken verbatim from client → converted invoice can drift cents;
  `apply_credit_note` accepts an arbitrary `invoice_id` override (cross-invoice
  application); emailed/downloaded PDF still prints the **blended VAT %** (C8 fix
  incomplete on the primary customer doc, `includes/pdf/qi_styled_pdf.php`).
- **AP:** duplicate-bill detection defeated by any date/total change and races
  (no unique index); `ap/ajax/bill.match.php` inserts three-way links with no
  quantity validation, bypassing the guarded path; supplier statement includes
  unposted bills & draft credits (disagrees with aging & control); no edit/void/cancel
  for any AP document ("cancelled" statuses queried everywhere but never settable);
  `bill_create`/`vendor_credit_create` accept a `supplier_id` from any tenant.
- **Bank:** zero-rated/exempt codes dropped on match & rules (Box 2/3 understated);
  closed reconciliations not protected from later undo-match/import; reconcile-close
  rewinds `current_balance_cents` when later transactions exist; substring bank
  detection can mis-route statements to the wrong parser and flip signs; bank lines
  can be matched to AR/AP control accounts (breaks tie-outs); no transfer handling
  between own accounts (matching both sides double-counts).
- **VAT:** out-of-order filing permanently blocks earlier open periods; payments-basis
  history rewritable via lock-exempt allocation tables (unapply deletes allocations in
  filed periods); monthly VAT summary excludes adjustments & s22 relief (never ties to
  VAT201); Capital-Goods (Box 7) report basis-unaware; `export/pdf/vat_summary.php`
  renders only the first line (cumulative Td offsets push the rest off-page) **and**
  ignores the `vat_settle` exclusion/basis (near-zero VAT for filed periods) yet is
  still linked from the VAT201 screen; no delete/reopen for a mis-created period.
- **Fixed assets/inventory:** declining-balance never switches to straight-line
  (assets never fully depreciate); disposal can be dated before already-depreciated
  months; depreciation run-based with no catch-up/sequence/future-month guard; no
  FA-register ↔ GL tie-out and capital AP-bill lines never create assets; s11(e)
  register skips first-year pro-rata; FA schedule totals include disposed assets
  (don't tie to BS); customer/vendor credits never restock inventory or reverse COGS
  (the one endpoint that could, `return_issue.php`, is wired to no UI); no opening
  balances/edit/delete on the register.
- **Reports/dashboard/master-data:** settings mapping validation is type-level only
  (VAT control can be mapped to the bank account, no health check); budget save
  deletes the whole year then re-inserts only rendered rows (destroys deactivated-
  account budgets) and silently drops locked-month edits while reporting success;
  accounts with any posted history can never be deactivated (contradicts the endpoint's
  own guidance); dashboard payables KPI counts unposted bills; monthly P&L widgets
  include year-end close journals; AR/AP aging ignore partial write-offs and drop
  credit balances (won't tie to controls); retrospective aging filters by *current*
  status (historically-open invoices vanish once later written off); home "VAT due"
  KPI drops to zero once a period is filed.

---

## SECTION E — UX & wiring

Beyond B23 (the systemic auth-in-fetch bug): AR invoice list silently truncates at
`LIMIT 100` and AP bills/payments/credits at `LIMIT 200` with **no pagination and no
"showing X of Y"** (search can't see truncated rows); VAT "Save Adjustment" button
isn't disabled and the endpoint isn't idempotent → double-click posts **two**
adjustment journals; several money submit buttons (manual bill, VAT save/create/file,
bank match/undo) give no in-flight feedback (mostly backstopped server-side, but no
UX signal); `qi/invoice_view.php` has **no link to the posted GL journal** (one-way
seam — drill-through only exists finance→invoice); dead backends
(`ajax/ap_from_receipt.php`, `ajax/budget_save.php`, `ajax/tax_invoice_validate.php`)
present but never called; the `{ok}` vs `{success}` envelope split across
`fa/api`/`budgets` is a latent copy-paste hazard; `reports.php` ships both an in-page
SPA and standalone report pages for the same four reports (drift risk). *Fixes are
per-item and mostly small; standardise a disable-during-request helper and add
server pagination mirroring `journal_list.php`.*

---

## SECTION F — Test coverage

Existing suite (11 files, `tests/finance/`) is strong on the **engine**: posting,
repost immutability, tenant isolation, VAT201 both bases, tie-outs, s20 validation,
invoice lifecycle, payroll (happy-path). **Zero automated coverage** on: bank
import/rules/reconciliation, budgets & budget-vs-actual, cash-flow-indirect,
year-end close, three-way match, refunds, fixed-asset depreciation & disposal, vendor
credits, credit-note application (allocation/cap), dashboard KPIs, exchange-rate
management. Note `make_invoice` inserts **without** `exchange_rate` (NULL) so the B21
dashboard path is never exercised. *Fix:* add tests for the zero-coverage subsystems,
prioritising the ones behind Section A/B bugs (bank VAT split, refunds, credit-note
application, year-end, dashboard KPIs) — a dashboard-KPI test would have caught B21.

---

## SECTION G — What's short (product-gap roadmap)

Benchmarked against Xero / Sage Business Cloud / QuickBooks for a SA SME. Already
present (don't rebuild): quotes→invoice→credit cycle, AP three-way PO/GRN match,
payroll→GL + EMP201/EMP501/IRP5, multi-currency/FX on AR, fixed assets + s11(e)/s12C
register, project dimension on GL lines, budgets + budget-vs-actual, bank recon with
rules + multi-bank CSV/PDF parsers, customer portal with Yoco "Pay Now", admin
audit-log viewer, weighted-average inventory.

**Priority 1 (daily bookkeeping / cash / controls):**
1. **Customer statement emailing + dunning ladder** — `ar/reminders.php` is a dead
   stub; statements have no send action; `qi/services/Mailer.php` exists but isn't
   wired. (M)
2. **Live bank feeds** — "Bank Feeds" is manual CSV/PDF import only; no aggregator
   (Stitch/SaltEdge). Highest impact, heaviest lift. (L)
3. **Approval-workflow depth** — SoD exists only for manual journals; bills/payments/
   write-offs/credits have no maker-checker. (M)
4. **Document attachments on journals/bills** — no `$_FILES`/attachment on bill or
   journal (SARS substantiation). `uploads/`+`download.php` already exist. (M)
5. **Customer credit limits & holds** — no `credit_limit`/`credit_hold` anywhere. (M)
6. **Recurring journals** (accruals/prepayments/auto-reversing) — recurring exists
   only for invoices. (M)

**Priority 2 (reporting / tax / dimensions):**
7. Cash-flow **forecast** (forward, from AR/AP due-dates) — only a historical
   statement exists. (M)
8. **Comparative periods** on P&L/BS (prior-period/variance columns). (M)
9. **XLSX export** + real PDF rendering — finance exports are CSV + single-page
   Courier text. (M)
10. Multi-company **consolidation**. (L)
11. **Item-level profitability** — `item_id` captured but no margin report. (M)
12. **Departments/cost-centres** as a second GL dimension (project exists; the
    reports.php project filter is even `display:none`). (M)
13. **Remittance advice** on supplier payments. (S)
14. Provisional tax **IRP6**. (M)
15. Income-tax / deferred-tax computation, book-vs-tax reconciliation. (L)
16. Denied/apportioned/notional/import **input VAT** (s17(2), s16(3)(b)) — all input
    VAT currently claimed in full. (M)
17. Open-item **FX revaluation** at period end (realized-only today). (M)
18. Customer-deposit / unallocated-receipt **VAT attribution** under payments basis. (M)

**Priority 3 (nice-to-have / niche):** loan/lease amortisation (incl. IFRS 16);
deferred-revenue schedules; supplier portal beyond compliance uploads; in-finance
audit-trail viewer for bookkeepers (+ tamper-evident hash chaining); write-off/credit
approval limits; pro-forma & delivery-note doc types; petty cash; FIFO + stock-take;
accountant API / "invite your accountant"; VAT201 eFiling export (low value — SARS
has no bulk upload); VAT period category (A/B/C) cadence; s12C/s13 allowance variants;
audit-log archival policy. **Half-built to clean up:** "Bank Feeds" label,
`reports.php` hidden project filter, dead `ar/reminders.php` stub, plain-text PDFs,
unwired `Mailer` for statements.

---

## SECTION H — Execution order & verification (for Opus 4.8)

**Suggested sequencing:**
1. **Section C first** (schema/provisioning) — C1/C2 unblock everything and stop
   silent-zero dashboards; land the migrations + backfill before code that depends on
   them.
2. **Section A** (5 critical money bugs) — each is a self-contained ticket; add a
   regression test with each (Section F).
3. **Section B** grouped by subsystem — B1/B2/B3 (GL), B4–B12 (qi lifecycle, biggest
   cluster), B13–B18 (AP/bank/FA), B19/B20 (security), B21/B22 (reports), B23 (UX
   systemic — one fix clears a whole class of "Network error" reports).
4. **Section D** by subsystem, opportunistically alongside the matching B fixes.
5. **Section E/F** as hardening.
6. **Section G** as a separate product roadmap — Priority 1 items reuse existing infra
   (Mailer, crm_accounts, uploads) and are the fastest business value.

**Verification approach** (this repo already supports it):
- `tests/db/setup.sh` builds the schema; `php tests/run.php` runs the finance suite in
  isolated subprocesses. Every A/B fix should ship with an assertion in the relevant
  `tests/finance/test_*.php` and, for the untested subsystems (F), a new test file.
- Tie-outs are the oracle: after any AR/AP/GL change, `tests/finance/test_controls.php`
  and `test_health_and_exports.php` must still show subledger==control and the VAT
  audit file footing to Box 5/9. Run `finances/tools/health.php` against seeded data.
- For VAT changes, assert both bases agree at full settlement
  (`test_vat_payments_basis.php` pattern) and that reversals net to zero in the boxes.
- For the dashboard/reporting fixes, seed a NULL-`exchange_rate` invoice and assert it
  appears in Receivables Outstanding (would have caught B21).

## Provenance
This report is the output of a read-only due-diligence audit at HEAD `c0809c3`
(no code was changed). Every critical (Section A) and the load-bearing highs were
verified by reading the cited code directly. Open this file in a fresh Opus 4.8
session and work the sections top-down per Section H; each lettered item is scoped
to be a standalone ticket with a file:line anchor.

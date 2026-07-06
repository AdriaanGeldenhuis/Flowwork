# Finance Module — SARS Compliance Due-Diligence: Findings & Remediation

**Scope:** complete front-to-end audit of `/finances/` (GL, AR, AP, VAT, bank,
fixed assets, payroll postings, reports, exports, settings) plus the `/qi/`
invoicing module that owns customer document creation.
**Outcome:** every code-fixable finding below is **fixed on this branch**, with
an automated test suite (`php tests/run.php`, see `tests/db/setup.sh`) proving
the accounting behaviour. Severity reflects the state **before** this branch.

Commits: WS0 `524ab06` (test infra + migrations) · WS1 `c8b1857` (foundations)
· WS2 `fd9e8f6` (posting engine) · WS3 `371b93c` (VAT correctness) · WS4
`351a500` (payments basis) · WS5 `de961d9` (s20 tax invoices) · WS6+7
`8d8c231` (controls + subledgers) · `97a2858` (payroll test, VAT CSV) ·
WS8+9 `00e8f32` (exports/health/wear-and-tear/repost tool) · `6b383e0`
(merge of the parallel `finance-quick-actions-sars` branch: its dashboard
quick-actions deck, SARS navigation and unique endpoint/report fixes were
adopted; its independent edits to the ~50 core engine files were superseded
by this branch's tested rewrite — that branch is now fully contained here
and can be deleted).

---

## Critical (SARS-blocking) — all fixed

| # | Finding | Fix |
|---|---------|-----|
| C1 | **The finance audit trail wrote nothing.** `finances/lib/Audit.php` inserted into columns (`details_json`, `ip_address`, `user_agent`) that do not exist in `audit_log`, and swallowed every exception. Every journal created/approved/posted/reversed event silently vanished. | Rewritten against the real schema; failures now error-logged; context injectable for webhooks/cron. Test: `test_foundations.php`, `test_repost_immutability.php`. |
| C2 | **Zero-rated/exempt sales posted 15% VAT to the GL.** The main invoice save path (`qi/ajax/save_invoice.php`) never persisted per-line `tax_rate`/`discount`/`gl_account_id`, so the DB default (15%) overrode the user's choice when the GL and VAT201 were derived. `QuoteConverter` and `save_quote` had the same bug. | All line-writing paths persist rate + tax code + GL account; header totals recomputed with the exact rounding the posting engine uses (invoice face == GL to the cent). Tests: `test_posting_engine.php`, `test_vat201.php`. |
| C3 | **Posted journals were hard-deleted on every re-post** (invoices, bills, payments, payroll, depreciation) — GL history was rewritable, violating SARS immutability. The delete also ran *outside* the new posting's transaction (a crash lost the old journal with no replacement), and a date moved out of a locked period silently double-posted. | Reposts now create a linked reversal + replacement in ONE transaction (`supersedeJournal`); posted journals are never deleted; inventory movements reverse idempotently. Test: `test_repost_immutability.php`. |
| C4 | **VAT201 was structurally wrong in three ways:** Box 5/9/10 excluded manual adjustments; adjustments were read from the control-account contra leg with inverted signs while also remaining inside the supplies totals (double-count); the control account was written as one default (2100) and read as another (2140). | One shared computation (`VatCalculator::vat201Boxes`) feeds the report page, prepare screen, UI and CSV; Box 5 = 1A+4, Box 9 = 6+7+8, Box 10 = 5−9; adjustments measured on the VAT accounts; account resolution unified through `AccountsMap`. Filed period totals now refresh at adjustment and filing time (they previously froze at prepare, before adjustments were even allowed). Test: `test_vat201.php` incl. GL tie-out of Box 5/9. |
| C5 | **Hardcoded fallback account codes pointed at the wrong SARS accounts** (e.g. payroll PAYE defaulted to the VAT-control code; VAT input default was the PAYE code on the seeded chart; UIF/SDL defaults didn't exist) — and code literals can never be right because legacy charts reuse the same codes for different roles (on the production chart 2130 is VAT Input; on the seed it is PAYE). | Single authority `AccountsMap::DEFAULTS` + per-company settings seeded **by account subtype** (`finances/tools/finance_setup.php`, which also creates missing VAT Control / Bad Debts accounts at the next free code). Payroll resolves through these mappings. |
| C6 | **Cross-tenant data leak in the printable Trial Balance / Balance Sheet.** `AsOf::trialBalance()` joined `journal_lines` on `account_code` alone; since every company shares seeded codes, other tenants' postings were summed into the report. | Lines now reached only through company-scoped `journal_entries`. Test: `test_foundations.php` (two-tenant isolation). |
| C7 | **No payments-basis VAT support** — a payments-basis vendor (s15(2)) would have filed invoice-basis figures. | Full payments-basis VAT201: receipts/payments apportioned across document tax profiles by payment date, credit notes at issue date (s21), bank items cash-dated; basis stamped per period; both bases proven to agree at full settlement. Test: `test_vat_payments_basis.php`. |
| C8 | **Customer documents were not valid tax invoices**: titled "INVOICE" (not *Tax Invoice*), supplier VAT number and address suppressible by toggle, no per-line VAT, a blended VAT% label that printed nonsense on mixed-rate invoices, and `TaxInvoiceValidator` existed but was never called. | s20 content forced for VAT-registered companies (title, VAT no., address); per-line VAT column + standard/zero/exempt VAT summary + ZAR VAT note on FX invoices; validator extended (VAT format, address, wording, R5000 full/abridged rule) and **enforced at issue time** — a non-compliant tax invoice cannot be sent. Test: `test_tax_invoice_validator.php`. |
| C9 | **Yoco payment webhook fatalled on every delivery** (required a non-existent file) *after* committing the payment: no GL entry ever posted, HTTP 500 returned, and gateway retries could duplicate the payment. Voiding an invoice likewise called a reversal method that never existed — cancelled invoices kept their revenue+VAT in the GL. | Webhook fixed (posts inside the transaction, deliveries deduped by checkout reference); void/cancel/revert-to-draft now genuinely reverse via `PostingService::unpostInvoice`. |

## High (integrity) — all fixed

| # | Finding | Fix |
|---|---------|-----|
| H1 | Three competing GL posting engines produced different journals for the same document depending on which ran last; only one booked COGS/inventory, so the default sales path never relieved stock (inventory overstated, gross profit wrong) and "Sync All" double-issued stock on each click. | One canonical engine (`finances/lib/PostingService`), both qi posters deleted, every caller retargeted; COGS/stock post on issue; movements journal-linked and reversal-safe. |
| H2 | No debits==credits assertion anywhere in system postings (payroll relied on an arithmetic identity); journals could post to account codes that don't exist and silently vanish from reports. | `insertJournal()` choke point: integer-cents balance assertion + account existence check + audit event on every journal. |
| H3 | Drafts polluted the GL and the subledgers: invoices posted while still drafts; AR/AP dashboards, statements and aging counted drafts — subledgers could never tie to control accounts. | Post-on-issue lifecycle (drafts carry no journal); draft exclusion swept through KPIs/statements/aging/`Tieout`; AR & AP subledger==GL tie-outs pass in `test_controls.php`. |
| H4 | Payments could exist without GL entries (posting ran after commit, failures only logged); AP over-allocation check was a pre-transaction race; no idempotency anywhere (an "idempotency_key" parameter was accepted and ignored). | Posting inside the payment transactions; FOR UPDATE row locks; real idempotency keys (unique per company) sent by the UI on AR and AP payments. |
| H5 | Period control: deleting a period lock didn't actually unlock (soft-delete ignored by `isLocked`), unlock was available to bookkeepers, year-end close left the closed year open for posting, no prior-year check. | `is_active` honored; unlock admin-only; close locks the FY and requires earlier years closed; SoD toggle (approver ≠ preparer) available. |
| H6 | AP bills could be re-posted after being **paid** (status reset + double stock receipt); AR credit notes had no cap against the original invoice (AP side had one); credit-note `reason_code` (s21 classification) was never captured. | Paid-bill repost always rejected, posted-bill repost needs an explicit flag; AR credit cap added; reason codes captured (full-credit flow stamps `cancellation`). |
| H7 | The Record Supplier Payment page fatalled (query used non-existent bank columns). Bank import silently dropped genuinely identical same-day transactions; bank matches with a VAT code posted the gross with only a tag — bank VAT never reached the VAT201. | Column fix; occurrence-indexed import hash (re-uploads still dedupe); VAT-inclusive bank amounts split into net + VAT output/input legs. |
| H8 | FX: `invoices.exchange_rate` did not exist in the base schema, so "captured-rate" conversion silently ran at 1.0; realized FX differences on settlement were never booked. | Schema drift reconciled; receipts booked at payment-date rate with realized FX gain/loss to the seeded 4950/8050 accounts when rates differ. |
| H9 | Write-offs only reduced `balance_due` — no bad-debt expense, no s22 VAT relief, AR control never credited. Depreciation ran across three transactions (a crash made the next month over-depreciate). | Write-off journal (DR Bad Debts, DR VAT Output s22, CR AR); depreciation run fully atomic. |
| H10 | `journal_entries.source_type` was a 4-value enum the code always overflowed (values stored as `''` — source traceability broken); several invoice statuses the code sets (`part-paid`, `written_off`, `refunded`, `uncollectible`) weren't legal enum values. | Enums widened (Migration 01); production schema drift columns reconciled. |
| H11 | API endpoints returned raw exception messages (SQL text, paths) to clients; manual journal references raced (`MAX()+1`). | `json_exception()` (business messages pass, internals logged); references via the FOR-UPDATE-locked `Sequence`, seeded from existing numbers. |

## Bookkeeper tooling added

- **VAT audit file** (`finances/export/vat_audit_file.php`): transaction-level
  CSV per VAT period, on the period's basis, footing to the VAT201 boxes —
  the substantiation file to keep with each filing.
- **Health checks** (`finances/tools/health.php`): unbalanced journals, AR/AP
  subledger-vs-control tie-outs, orphan account codes, untagged revenue,
  inventory GL vs on-hand, unallocated receipts under payments basis,
  mapping-role mismatches.
- **Reports page** now links the printable Trial Balance / Balance Sheet /
  Income Statement, GL Detail, AR/AP tie-outs, sequence-gap and the new
  **s11(e)/s12C wear-and-tear register** (tax vs book depreciation per asset).
- **`finances/tools/finance_setup.php`** — run once per environment (and per
  new company): seeds chart-safe account mappings by subtype and creates
  missing VAT Control / Bad Debts accounts.
- **`finances/tools/repost_all.php`** — CLI migration: reposts existing
  documents through the corrected engine (reversal history preserved) so
  pre-remediation data lands on the right accounts and tax codes.

## Actions required from you (not code)

1. **Rotate the production DB password and other credentials** — they are
   committed in `config.php` and were flagged by the earlier security due
   diligence (`SECURITY-REMEDIATION.md`). `config.php` now prefers
   environment variables; set them at the host and remove the fallbacks.
2. **Run once in production, in this order:** the four
   `Migrations/2026-07-06-finance-sars-0*.sql` files, then
   `php finances/tools/finance_setup.php`, then (to migrate historic data)
   `php finances/tools/repost_all.php`.
3. **Fill in company profile**: a valid VAT number (10 digits starting
   with 4), physical address, and SARS income-tax reference in Admin →
   Company (the seeded test company's VAT number is not a valid format —
   issuing will be blocked until corrected).
4. **Choose the VAT basis** in Finance Settings to match the SARS
   registration (invoice basis is the default), and enable segregation of
   duties if more than one finance user exists.
5. **Set the payroll bank account** in Payroll Settings — payroll posting now
   refuses to guess.

## Documented limitations / recommended follow-ups (not blocking daily bookkeeping)

- **Customer deposits / unallocated receipts under payments basis** carry VAT
  the VAT201 cannot attribute to a document; the health check flags them.
  (s15/s16 strictly require output tax on any consideration received.)
- **Denied/apportioned input VAT** (entertainment, motor cars s17(2)),
  **notional input VAT** on second-hand goods (s16(3)(b)) and **import VAT**
  are not modelled — all input VAT on captured bills is claimed. Bills for
  denied categories should be captured at 0% until supported.
- **VAT period category (A/B/C…) enforcement**: periods are free date ranges
  with overlap protection; the SARS category cadence is not enforced.
- **Open-item FX revaluation** at period end (unrealized differences) is not
  performed; realized differences are booked on settlement.
- **s12C variants** (accelerated allowances beyond 40/20/20/20) and s13
  buildings allowances are outside the minimal wear-and-tear register.
- The audit trail is complete but **not tamper-evident** (no hash chaining);
  an admin with direct DB access could alter rows. Consider append-only
  archiving of `audit_log` if this matters for your risk profile.
- **UI tax-rate options are static** (15%/0%): a statutory rate change is a
  data update (`gl_tax_codes`) plus one dropdown edit; the server validates
  rates against tax codes, so a stale UI fails loudly, not silently.
- Roughly fifty endpoints write `audit_log` directly with correct columns;
  they work but should eventually converge on the `Audit` helper.
- 5-year record retention is satisfied by the immutable GL + audit file
  exports, but there is no automated archival policy.

---

# PR #55 complete review (2026-07-06) — findings and fixes

Before merge, the full diff was re-reviewed by five independent specialist
passes (accounting integrity, VAT/SARS domain, endpoint security, frontend
wiring, migrations/runbook). 52 findings were reported; each was verified
against the code before acceptance, and every confirmed defect was fixed in
commits `ee92bed`, `1a172d6`, `9937522`, `7cfc1a3` with regression tests
(suite grew 190 → 228 assertions, 11/11 files green).

## Criticals found and fixed

- **`save_invoice.php` lost `$totalCalc`** in the integer-cents rewrite while
  five uses remained: every draft edit zeroed `balance_due` (payments then
  rejected as "exceeds balance due") and milestone invoices could not be
  saved. Restored. (Found independently by two review passes.)
- **`supersedeJournal` posted reversals for never-posted legacy journals.**
  The pre-2026 engines left journals in `draft`; reposting such a document
  created a POSTED reversal for amounts that were never in the posted
  reports — running `repost_all.php` against the production dump netted all
  pre-remediation trading history to zero in the trial balance, tie-outs and
  VAT201. Reversals are now skipped (and audit-logged) for non-posted
  journals; `repost_all` also neutralises legacy stock movements
  (`journal_id IS NULL`) before reposting, which previously double-issued
  inventory.

## Highs found and fixed (selection)

- **Payments-basis s21 credit notes double-counted**: recognised in full at
  issue while receipts still apportioned by the original invoice total — a
  credit against a never-paid invoice manufactured an output-tax refund.
  Recognition is now clawback-capped to output tax previously accounted for
  on receipts; per-document running-total rounding also guarantees lifetime
  cash-basis VAT equals invoice-basis VAT exactly (the old per-allocation
  rounding drifted ±1c per partial payment). `VatAuditFile` mirrors both, so
  the audit file still foots to the boxes.
- **Bill-level capital-goods flag**: one fixed-asset line marked a whole
  bill's input VAT as capital (VAT201 Box 7/8 misallocation). VAT is now
  grouped per (tax code, capital flag) on bills AND vendor credits, on both
  bases.
- **Write-off (s22) fixes**: relief moved from netting Box 1A to an explicit
  input-side bad-debts adjustment inside Box 9 (eFiling derives field 4 from
  field 1, so netting output broke the form's arithmetic); FX write-offs now
  convert to ZAR at the invoice's captured rate (face-value posting stranded
  17/18ths of a USD invoice's AR).
- **Open VAT periods showed accrual figures to payments-basis vendors**
  (`gl_vat_periods.basis` is NOT NULL DEFAULT 'invoice' and only stamped at
  prepare time). New `periodBasis()` helper: open → company election,
  prepared/filed → stamped snapshot.
- **`vat_adjust_post` could post a 1-cent-unbalanced journal** (float contra
  threshold `> 0.01`) with no period-lock check; `return_issue` committed
  stock before its transaction and left its journal in invisible `draft`;
  `receipts/post_to_gl` hand-rolled journals with hardcoded account
  fallbacks, no locks and no discounts. All three now post through the new
  public engine choke point `PostingService::postAdHocJournal` /
  `postApBill`.
- **Void/unapply asymmetries**: voiding a PAID invoice reversed the invoice
  journal but left the payment journals (AR control went negative) — void
  now requires unapplying payments first; the AR tie-out counts unapplied
  receipts as customer credits and subtracts write-offs, in ZAR.
- **Race conditions** closed with row locks + predicate/rowCount guards and
  DB backstops: journal approve/reverse, bank match, asset disposal,
  depreciation run (new unique index, migration 06), recurring generation
  (cron vs manual), Yoco webhook duplicate deliveries (idempotency key),
  three-way-match batch quantity tracker.
- **Exempt lines stored as zero-rated**: the invoice editor never submitted
  the per-line tax CODE, so both 0% options resolved to ZERO (Box 2/3
  misstatement) and edits reset custom revenue accounts. The editor now
  round-trips `tax_code` and `gl_account_id`.
- **s20 recipient address** on a full tax invoice (> R5,000) upgraded from
  warning to blocking error (s20(4)(b) requires it unconditionally; only the
  recipient VAT number is vendor-dependent).
- **Error-message hygiene**: 14 endpoints echoed raw exception text
  (including SQL detail) to clients; all now pass through business messages
  only.

## Production-runbook corrections from the review

Two steps are REQUIRED in addition to the runbook above (verified against
the production schema shape):

1. **Before `finance_setup.php`**: run `CALL seed_sars_chart_of_accounts(<company_id>);`
   for every company. On the legacy chart, nine mappings (bank, expense,
   wage/UIF/SDL expense, FX gain/loss, disposal gain/loss) otherwise stay
   unresolved and postings that need them hard-fail with "Unknown GL account
   code".
2. **After `finance_setup.php`**: open Finance Settings and resolve any
   mapping still flagged UNRESOLVED, and create at least one GL-linked bank
   account (Banking → Accounts) — customer receipts fall back to the seeded
   bank account and refuse to post if none exists.

Also note: migration 06 (review fixes) joins the ordered migration list, and
`fa_depreciation_runs` gains a unique month index.

## Review items documented, not changed

- qi financial endpoints (record payment, write-off, credit) require
  authentication + CSRF but no admin/bookkeeper role — unchanged from main;
  tightening it is a product decision about who may capture payments.
- A Yoco payment against a draft invoice that fails s20 validation rolls
  back and returns 500 (Yoco retries until the draft is fixed) — safe and
  visible, but worth knowing.
- Vendor credits have no payments-basis clawback (unlike customer credit
  notes); early recognition only OVERSTATES VAT payable — conservative.
- Standalone credit notes (no linked invoice) are recognised in full at
  issue under the payments basis; they represent actual refunds.
- `repost_all.php` reposts unconditionally (reversal + replacement per run);
  reruns are financially safe but grow `journal_entries` — run it once.

# Smoke Test Plan for /finances/

The goal of this test round is to exercise the major accounting flows end‐to‐end and verify that recent enhancements work as expected.  Follow each section in order and record any errors or inconsistencies found.

## 1. Invoice Posting with Inventory/COGS

1. **Create an Inventory Item** via `/finances/inventory_items` (or the relevant page).  Give it a clear SKU and cost price.
2. **Create an AP Bill** for the item so that stock is received: choose the item on a Bill line, enter a quantity, and post the bill.  After posting:
   - Check the asset stock level via the inventory page – quantity should increase by the bill quantity.
   - Verify that the bill has a journal entry with debits to Inventory and VAT Input and a credit to Accounts Payable.
3. **Create a Customer Invoice** via `/qi/invoice_new.php`:
   - Add a line using the same inventory item (the new “Item” selector should be visible) and a quantity less than or equal to the stock on hand.
   - Post the invoice and then view the journal entry.
4. **Verify**:
   - The inventory quantity should decrease by the issued quantity.
   - The journal entry for the invoice should debit Accounts Receivable and credit Sales and VAT Output.
   - A separate Cost of Goods Sold journal should debit COGS and credit Inventory for the cost of the goods sold.

## 2. Credit Note Allocation

1. Create a credit note against an existing invoice in QI.
2. Apply (allocate) the credit note to the invoice.
3. Post the credit note.
4. **Verify** that the journal reverses the original revenue and VAT and that the invoice balance reduces accordingly.

## 3. AP Bills, Supplier Payments & Vendor Credits

1. **Create an AP Bill** with expense lines and post it.  Ensure that the bill journal debits expense accounts and credits Accounts Payable.
2. **Create a Supplier Payment** against the bill.  Allocate the payment and post it.
3. **Create a Vendor Credit** (supplier credit) and allocate it to the bill.  Post the credit and verify that AP and expense balances are reduced.

## 4. Bank Import & Matching

1. Import a sample CSV of bank transactions via the Bank module.  Choose the appropriate bank account and confirm the import.
2. Use the matching UI to match customer payments to open invoices and supplier payments to open bills.
3. **Verify** that the dashboard’s “Bank to reconcile” counter updates appropriately and that matched transactions clear from the list.

## 5. VAT Periods and Adjustments

1. Open the VAT module and create a new VAT period.
2. Prepare the period; review the output and input VAT totals.
3. Add a small VAT adjustment via the new adjustment modal (if configured) and ensure it posts a journal entry against the VAT accounts.
4. File the VAT period and export the CSV summary.

## 6. Fixed Assets – Depreciation & Disposal

1. Create a fixed asset via `fa/asset_new.php` and verify it appears on the asset list.
2. Run a depreciation for the current month and ensure the journal posts properly and the asset’s accumulated depreciation increases.
3. Dispose the asset via the new “Asset Details” page:
   - Enter a disposal date and proceeds.
   - Verify that a journal is created which debits accumulated depreciation, debits bank (for proceeds), credits the asset cost, and posts any gain or loss.
   - The asset status should change to “disposed” and it should no longer appear in the active asset list.

## 7. Payroll Cycle

1. Create a pay run for the current month, add employees and pay items, calculate and lock the run.
2. Ensure that when auto‐post is enabled, the run posts a journal to the ledger and records the journal_id on the run.
3. Generate payslips using the new “Generate Payslips” button and verify that each employee has a payslip file or HTML preview.
4. Export the bank file for payroll and confirm it contains the correct amounts.

## 8. Reports UI

1. Navigate to `/finances/reports.php` and confirm that the new report tiles (AR Aging, AP Aging, Cash Flow, VAT Summary) appear and link to the correct pages.
2. Run the existing reports (Trial Balance, Profit & Loss, Balance Sheet, GL Detail) for a recent date and ensure totals agree with the data from the dashboards and journals.
3. Visit each of the new report pages individually and verify that filters and exports work as expected.

## 9. Overall Smoke Test

Perform a full cycle:

1. Receive stock via an AP Bill.  Post the bill.
2. Create an invoice with that item and post it.  Allocate customer payment and reconcile via bank import.
3. Create a credit note and apply it to the invoice.
4. Run depreciation for any assets and dispose one asset.  Confirm journals and asset status.
5. Prepare and file a VAT period including the recent transactions.
6. Run payroll for the period and post.
7. Check dashboard cards (cash, AR, AP, VAT, FA, Payroll) to ensure counters are correct.

Document any errors, warnings, or inconsistencies.  This smoke test ensures that the finance module’s main flows – billing, procurement, inventory, banking, VAT, fixed assets, payroll, and reporting – work together correctly after the recent enhancements.
---

## CRM improvement pass (Phases A–E, 2026-07-04) — manual verification flows

Run the SQL in `Migrations/2026-07-04-crm-settings-reconcile.sql` and
`Migrations/2026-07-04-crm-opps-win-loss.sql` against the DB first.

### Phase A — security & dead code
- [ ] `/crm/ajax/test_save.php` and `/crm/create_test.php` return 404.
- [ ] `/crm/overview.php` 404s; `/crm/?tab=overview` still renders the overview tab.
- [ ] `/crm/invoice_view.php?id=X` redirects to `/qi/invoice_view.php?id=X`.
- [ ] On an account's Compliance tab, the Download button streams the file; fetching `/uploads/compliance/<file>` directly is denied.
- [ ] As a `member` user, calling `/crm/ajax/export.php?type=accounts` directly returns a 403/permission error.
- [ ] Force a SQL error (e.g. temporarily rename a table in a dev DB): endpoints return `{"ok":false,"error":"Server error"}`, not SQL text.
- [ ] `php crm/cron/generate_recurring.php` runs the QI generator via the shim.

### Phase B — correctness
- [ ] Settings page saves; reload shows persisted values; keys in `company_settings` are `crm_require_vat_number`, `crm_default_supplier_status`, `crm_default_customer_status`.
- [ ] With "Require VAT Number" on, creating an account without VAT is rejected server-side.
- [ ] An `owner`-role user can create and edit opportunities.
- [ ] Merge two accounts where the loser has opportunities and POs: they now point at the winner; the loser shows under the "Deleted only" filter (not hard-deleted).
- [ ] Import a CSV with a `type` column: `customer` rows land on the Customers tab; industry/region names resolve; progress bar moves.
- [ ] Export with `format=xlsx` fails with a clear message instead of silently sending CSV.
- [ ] With "Block expired suppliers" on and a supplier missing a required doc, RFQ creation is refused.

### Phase C — account views
- [ ] Timeline tab loads real events (interactions, sent emails, quotes, invoices) on first open.
- [ ] Linked Items tab lists the account's quotes/invoices (and projects when linked) with working links.
- [ ] "+ Tag" adds a tag without a reload; the × on a chip removes it.
- [ ] Industry/Region filters on the suppliers/customers lists narrow results.
- [ ] Account analytics charts show real numbers; an empty account shows "No data yet", not random values.
- [ ] Supplier accounts show On-time/Response/Defect tiles (em-dash before the KPI cron has run).

### Phase D — integrations
- [ ] Schedule a follow-up 10 minutes out with a 5-minute reminder; run `php calendar/cron/send_reminders.php` (CLI): a notification row appears / email is attempted; the event is visible in /calendar/.
- [ ] Logging an interaction with "Next action" set creates a follow-up (Upcoming Follow-ups card shows it).
- [ ] Send Email modal: from/signature/template pickers populate; sending stores the mail and it appears on the Timeline tab; without a mail account the button links to /mail/settings.php.
- [ ] Customer account kebab → "Copy client portal link" copies a working portal URL.
- [ ] Converting a won opportunity creates a project that then appears under Linked Items.

### Phase E — pipeline
- [ ] Drag a card with amount ≥ R1,000,000 between columns: totals update correctly (the old text-parsing regex broke on grouped thousands).
- [ ] Dropping a card into Lost asks for a reason; Skip works; closing the modal reverts the move; the reason shows on the opportunity view.
- [ ] Owner and close-date filters narrow the board.
- [ ] Open deals with a passed close date (or no movement for 30+ days) show the amber stale flag.

# FlowWork Drive publishing (`includes/flowdrive/`)

Publishes Flowwork's documents into **FlowWork Drive** on flowdrive.co.za so
every customer/supplier has a browsable folder of their paperwork:

```
Customers/{Account Name}/Invoices/INV2026-0011.pdf
Customers/{Account Name}/Quotes/Q2026-0005.pdf
Customers/{Account Name}/Credit Notes/CN2026-0001.pdf
Customers/{Account Name}/Documents/{compliance uploads}
Suppliers/{Account Name}/Documents/{compliance uploads}
Suppliers/{Account Name}/Receipts/2026-10-14 BW-204551.pdf   (slips behind posted bills)
```

## How it works

Flowwork and Flowdrive share **one MySQL database**. Publishing writes rows
directly into Flowdrive's tables — no HTTP, no shared filesystem:

- `fd_drives` — one `company/flowwork` drive per company (created on demand).
- `fd_nodes` — folders and files. Top-level folders use `parent_id NULL`
  (the convention all Flowdrive surfaces list at the drive root).
- `fd_blobs` — the file **bytes live in the database** (`data` column,
  sha256-deduplicated). Flowdrive's `api/nodes/download.php` serves inline
  blob data as its first strategy; WebDAV (`dav.php`, used by the phone app
  and desktop mounts) reads the same rows.

Account folders carry `meta_json {"fw_account_id": N}` so they survive account
renames and case/accent-insensitive name clashes (fd_nodes is latin1; two
accounts whose names MySQL considers equal get a `"Name (id)"` suffix).

## The pieces

| File | Role |
|---|---|
| `FlowDriveRepo.php` | Low-level fd_* writer: drive/folder/file upsert, blob dedup, version snapshots, soft delete, per-company `GET_LOCK` mutex. Never throws. |
| `FlowDriveSync.php` | Business layer: maps a `crm_accounts` row to its folder tree, files/removes account documents, compliance docs and receipts. |
| `FlowDriveBackfill.php` | `runForCompany()` full sweep; `autoRunOnce()` self-heal (first-ever full backfill, then a daily bounded sweep of changed docs); stranded-file rescue; legacy-skeleton cleanup. |
| `FlowDriveSso.php` | Single sign-on: `/drive/` (the home page's Drive tile) issues a one-time, state-bound token in `fd_sso_tokens` so FlowDrive opens already signed in, in the active company. |
| `../../qi/services/DocumentPdfService.php` | Renders quote/invoice/credit-note PDFs (single recipe shared by save/send/download), writes `storage/qi/...`, updates `pdf_path`, publishes to the drive. |
| `../../receipts/lib/RcDrive.php` | Which receipts are filed (posted/paid bills only), their name (`{bill date} {supplier's number}.{ext}`), and `receipt_file.drive_node_id` (the drive file; 0 = cannot be filed: slip missing or over 15 MB). |

## Where publishing is triggered

- **Create/edit**: `qi/lib/QiWriter.php` (the editor's save, `qi/api/document.php`)
  re-renders and files the PDF after every save of a quote, invoice or credit note
- **Status/balance changes**: every action in `qi/lib/QiActions.php`
  (`QiActions::after()` — send, accept/decline, convert, issue, payments,
  refunds, credits, void/reopen, write-off, archive/restore), the customer
  pages `qi/p/quote.php` / `qi/p/invoice.php`, and `webhooks/yoco.php`
  (online payments, through `QiActions::recordPayment`)
- **Copies**: `qi/lib/QuoteConverter.php` (quote → invoice) and
  `qi/lib/QiRecurring.php` (recurring runs from the background tick and
  `qi/cron/generate_recurring.php`)
- **Downloads**: `qi/ajax/download_pdf.php` (delegates to the service)
- **Deletes**: `QiActions::deleteQuote` / `deleteInvoice` / `deleteCredit` and
  `crm/ajax/compliance_doc_delete.php` soft-delete the drive copy
- **Compliance uploads**: `crm/ajax/compliance_doc_save.php`,
  `portal/supplier/upload_compliance.php`
- **Receipts**: `RcBills::post` files the slip as the bill is posted
  (`FlowDriveSync::fileReceipt`, tagged `{"fw_receipt_file_id": N}`; a name
  another receipt holds becomes `"… (receipt N)"`). Bills from Receipts are
  only posted there, so this is the one place a receipt enters the books.
  The full backfill files every posted receipt; after it, page loads run a
  receipts catch-up at most hourly (`flowdrive_receipts_check`, same 2.5 s
  budget) for slips a drive failure missed or that predate this, and after
  filing is switched back on.
- **Self-heal**: `home.php` and `qi/index.php` call
  `FlowDriveBackfill::autoRunOnce()` — full backfill on the first-ever load
  (flag `flowdrive_backfill_done` in `company_settings`), then at most one
  daily sweep (`flowdrive_last_sweep`) that re-publishes documents whose rows
  changed since the previous sweep (≤100/day). Publishing itself preserves
  `updated_at` so the sweep only sees genuine business changes.

Every call is **best-effort**: a drive failure is logged
(`FlowDrive*::$lastError` + error_log) and never blocks invoicing.

## Operations

- **Diagnostic / force-run** (admin, own company only):
  `https://www.flowwork.app/qi/cron/flowdrive_diagnostic.php`
- **Full backfill**: browser (admin, own company) or CLI for all companies:
  `php qi/cron/backfill_flowdrive.php`
- To re-run the automatic backfill for a company, delete its
  `flowdrive_backfill_done` row from `company_settings`.

## Deliberate exclusions

- **Payslips / payroll PDFs** are NOT published: the drive is readable by the
  whole company, while Flowwork's `download.php` restricts payroll documents
  to admin/owner/manager/bookkeeper. Publishing them would leak salary PII.
- **Receipts that are not in the books** — slips still being reviewed, draft
  bills and slips set aside — are not published: until a bill is posted the
  supplier is not settled, and the drive is readable by the whole company
  while Receipts shows members only their own slips. An admin can switch
  filing off (Receipts → Settings → Sending in, `receipt_settings.publish_to_drive`);
  receipts already filed stay.
- **Files larger than 15 MB** are skipped (`FlowDriveRepo::MAX_BLOB_BYTES`)
  to stay under shared-hosting `max_allowed_packet`.

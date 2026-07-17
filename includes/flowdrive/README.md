# FlowWork Drive publishing (`includes/flowdrive/`)

Publishes Flowwork's documents into **FlowWork Drive** on flowdrive.co.za so
every customer/supplier has a browsable folder of their paperwork:

```
Customers/{Account Name}/Invoices/INV2026-0011.pdf
Customers/{Account Name}/Quotes/Q2026-0005.pdf
Customers/{Account Name}/Credit Notes/CN2026-0001.pdf
Customers/{Account Name}/Documents/{compliance uploads}
Suppliers/{Account Name}/Documents/{compliance uploads}
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
| `FlowDriveSync.php` | Business layer: maps a `crm_accounts` row to its folder tree, files/removes account documents and compliance docs. |
| `FlowDriveBackfill.php` | `runForCompany()` full sweep; `autoRunOnce()` self-heal (first-ever full backfill, then a daily bounded sweep of changed docs); stranded-file rescue; legacy-skeleton cleanup. |
| `../../qi/services/DocumentPdfService.php` | Renders quote/invoice/credit-note PDFs (single recipe shared by save/send/download), writes `storage/qi/...`, updates `pdf_path`, publishes to the drive. |

## Where publishing is triggered

- **Create/edit**: `qi/ajax/save_invoice.php`, `save_quote.php`, `save_credit_note.php`
- **Send** (re-render after issue so the PDF shows the issued status):
  `send_invoice.php`, `send_quote.php`
- **Status/balance changes**: `accept_quote.php`, `decline_quote.php`,
  `convert_to_invoice.php`, `invoice_action.php`, `record_payment.php`,
  `refund_invoice.php`, `apply_credit_note.php`, `approve_credit_note.php`,
  `issue_full_credit.php`, `webhooks/yoco.php` (online payments)
- **Copies**: `duplicate_invoice.php`, `duplicate_quote.php`,
  `run_recurring.php`, `qi/cron/generate_recurring.php`
- **Downloads**: `qi/ajax/download_pdf.php` (delegates to the service)
- **Deletes**: `delete_invoice.php`, `delete_quote.php`,
  `crm/ajax/compliance_doc_delete.php` soft-delete the drive copy
- **Compliance uploads**: `crm/ajax/compliance_doc_save.php`,
  `portal/supplier/upload_compliance.php`
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
- **Receipts** (`receipt_file`) are not yet published: an upload has no
  supplier until it is matched to a bill (`bill_id` is NULL at upload time).
  Future work: hook the bill-matching step and file under
  `Suppliers/{name}/Receipts/`.
- **Files larger than 15 MB** are skipped (`FlowDriveRepo::MAX_BLOB_BYTES`)
  to stay under shared-hosting `max_allowed_packet`.

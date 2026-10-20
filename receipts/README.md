# Receipts

Supplier slips and invoices in, supplier bills out — on the Maison design
system. Anyone in the company (not viewers) can send slips in; admins and
bookkeepers turn them into bills and post them. Members see the receipts
they sent in; admins, bookkeepers and viewers see the company's.

## What it does

* **Sending in** — drop PDFs, photos or a ZIP anywhere on any Receipts page,
  paste a screenshot (Ctrl V), or snap a photo (phone: `upload.php`, the
  camera button). Big photos are made smaller on the device first. The same
  file sent in twice is caught (SHA-1). A note says what it was for.
* **Reading** — each slip is read as it arrives (`api/read.php`, one at a
  time so a big batch never times out). Never made up: what cannot be read
  is left for a person to type. Readers (Settings → Reading, admins):
  * *AI reader* — the company's OpenAI key from Suppliers AI reads photos and
    PDFs (supplier, VAT number, number, dates, totals, lines) and picks the
    likely expense account from the chart. Only the slip and expense account
    names are sent. At most 300 slips an hour per company.
  * *Text recognition on the server* — `tesseract` (+ `pdftoppm` for scanned
    PDFs) and `RcParse` (South African slips: day-first dates, `R 1 234,50`,
    VAT numbers, "Amount due", "Incl. VAT"; lines only when they add up).
  * *Type it in* — nothing is read.
  A PDF with a text layer is always read from its text when `pdftotext` is
  installed.
* **Reviewing** (`review.php?id=`) — the slip beside the bill: zoom, drag,
  turn (PDFs drawn with PDF.js so this works in the Android app too). The
  supplier is matched by VAT number, by what was learned, or by name; a new
  supplier is added to the CRM on the spot. Lines are typed as printed (VAT
  included or not) and become exact net lines. Accounts and the project
  board come from what was learned. "Paid on the spot" records the payment
  with the posting. `j` / `k` move through the queue; Ctrl S saves, Ctrl
  Enter posts.
* **Checks** (live, `RcBills::checks`) — supplier chosen and still in the
  CRM; number; date (not in a closed period, not in the future); due date;
  lines and accounts (no control or heading accounts); duplicates (same
  supplier and number = held; same supplier, day and amount = warned); the
  bill against the slip's total (fix: make it match); currency (Finance books
  in rand); VAT claimed without a VAT number (held when the setting is on;
  fix: don't claim VAT); the slip's VAT number not on the supplier (fix: save
  it to the CRM); supplier compliance documents; purchase order from an
  amount (held only once purchase orders are in use); bill against its
  order; price jumps; the payment date. Admins can override the compliance,
  purchase order and VAT-number holds with a reason (`receipt_policy_log`).
  An override holds for the supplier it was given for (and a purchase-order
  override up to its amount): another supplier or a larger bill is held again.
* **Learning** (`RcLearn`) — printed name → supplier (`ai_hints`),
  supplier → account and board, word → account
  (`company_settings.receipts_ai_map`). Settings → Learning shows it all and
  forgets what is wrong.

## How a receipt moves

| State | Means | Comes from |
|---|---|---|
| Being read | `ocr_status = 'pending'` | sent in; read straight after |
| To review | read (or could not be read), no bill | |
| Draft bill | `ap_bills.status` draft / review / approved | Save draft |
| In the books | `ap_bills.status = 'posted'` | Post |
| Paid | `ap_bills.status = 'paid'` | Post with "paid on the spot", or paid in Finance |
| Set aside | `dismissed_at` set (duplicate, personal, not a bill…) | Set aside; can be brought back |

A bill deleted in Finance leaves its receipt to review again.

## Finance

Bills are made, changed and posted by Finance's own engine —
`FinPayables::saveBill`, `postBill` and `pay` — inside Receipts' own
transaction, so they are exactly what Finance would make: per-line net and
VAT to the cent, duplicates refused, control accounts refused, the period
lock respected. A bill from Receipts has status `review` and carries
`file_id` / `ocr_id`; Finance shows "from Receipts" and links back to
`review.php?id=`. VAT-inclusive prices become net unit prices with, where
needed, a cent or two of discount so each line's net is exact; the net is
chosen so the VAT (rounded per line) lands on the printed amount whenever a
net can. The slip is attached to the bill as a supporting document
(`FinFiles::attachCopy`, once). Project costs are added to the board's cost
column (or noted in the task's activity) after posting.

A bill made from a receipt is posted from Receipts only: Finance shows
**Check & post in Receipts** instead of Post, and `FinPayables::postBill`
refuses it from anywhere else, so the checks, an admin's holds and the
payment made on the spot cannot be skipped. It can still be edited in
Finance; Receipts then shows it as Finance has it.

The old Receipts made AR **sales invoices** out of supplier slips
(`ajax/bill_approve_post.php`), created bank rules that would have expensed
the same purchase twice, invented supplier names and amounts when reading
failed, read a `vat_number` column that does not exist (so the policy check
always failed), and served slips to anyone with the link. All of that is
retired: the old endpoints answer **410 Gone** (`lib/RcRetired.php`; the
deploy never deletes files), invented readings are cleared, and slips are
only served by `api/file.php`.

## FlowWork Drive

When a bill is posted, its slip is filed in FlowWork Drive with the
supplier's other papers (`RcDrive`, through `includes/flowdrive/`):

```
Suppliers/{Supplier}/Receipts/2026-10-14 BW-204551.pdf
```

— the bill's date and the supplier's number, the slip byte for byte, once
(`receipt_file.drive_node_id`). Only receipts in the books are filed (the
drive is open to everyone in the company); slips still being reviewed,
drafts and slips set aside stay in Receipts. Older posted receipts, and any
a drive hiccup missed, are filed in the background on page loads
(`FlowDriveBackfill`). An admin can switch it off under Settings → Sending
in. The review page shows where a receipt is filed.

## Structure

```
receipts/
  index.php        Overview: send in, what is waiting, spend by month / supplier / account / project, needs attention
  inbox.php        Every receipt as a card: to do, to review, drafts, held, in the books, set aside, all (js/inbox.js)
  review.php?id=   The slip beside the bill (js/review.js)
  upload.php       Snap a receipt — phone first (js/capture.js)
  settings.php     Reading, new bills, checks, sending in, learning (js/settings.js)
  api/             JSON (CSRF on every POST via auth_gate; errors and rate limits in _bootstrap.php)
    receipts.php   list, overview, export (CSV), note, aside, restore, delete
    upload.php     one file or a ZIP per request
    read.php       read a slip now
    file.php       the slip or its preview, to whoever may see it
    bill.php       load, check, save, post, delete draft, override, suppliers, new supplier, save VAT number, tasks
    settings.php   get, save, forget
  lib/
    Rc.php         loads everything        RcBoot.php   page bootstrap
    RcKit.php      roles, settings, states, money, rate limit, audit, notifications
    RcSchema.php   columns provisioned on first use (mirrors Migrations/2026-10-22-receipts-rebuild.sql)
    RcFiles.php    storing (content-checked, SHA-1), previews (WebP, EXIF-upright), serving
    RcParse.php    text → fields (no database; unit tested)
    RcReader.php   AI / tesseract / pdftotext readers
    RcLearn.php    the learning memory
    RcBills.php    load, prefill, checks, save / post through Finance, project costs, suppliers
    RcReceipts.php lists, counts, overview, set aside / restore / delete, CSV
    RcDrive.php    posted receipts into FlowWork Drive (Suppliers/{name}/Receipts)
    RcRetired.php  410 stubs for the old endpoints
  css/receipts.css  js/receipts.js (shared helpers, window.FWR, the uploader)  partials/ (head, foot, icons)
```

Slips live in `storage/receipts/<company>/<yyyy>/<mm>/` (closed to the web).
Receipts from before the rebuild stay in `uploads/receipts/<company>/…`,
now closed to the web too (`uploads/receipts/.htaccess`).

## Who may do what

| | member | bookkeeper | admin | viewer |
|---|---|---|---|---|
| Send slips in, add a note, delete own (until a bill is made) | ✓ | ✓ | ✓ | |
| See every receipt of the company | own only | ✓ | ✓ | ✓ (read only) |
| Make, save and post bills; set aside; add suppliers | | ✓ | ✓ | |
| Settings | | ✓ | ✓ | |
| Choose the reader (AI sends slips to OpenAI); filing in FlowWork Drive; override a hold | | | ✓ | |

Members follow their own slips (where each one is, what the bill says) but
see none of Finance: no bank accounts, chart, supplier spend, orders or
check results.

The person who sent a receipt in hears (the bell) when it is in the books,
and (the bell and the phone) when it is set aside.

## Tests

```
php tests/run.php receipts     # tests/receipts/test_receipts_parse.php, _bills, _http, _drive
```

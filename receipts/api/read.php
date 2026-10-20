<?php
// /receipts/api/read.php — read a slip now (POST id[, force]).
// The person who sent it in, or a bookkeeper, may ask; a slip already read
// is only read again with force. Answers the reading and the receipt's row.
require_once __DIR__ . '/_bootstrap.php';

if (!rc_is_post()) rc_error('POST only.', 405);
$in = rc_input();
$id = rc_int($in['id'] ?? 0);
$row = RcBills::need($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $id);
if (!RcKit::canCapture($RC_ROLE) && (int)$row['uploaded_by'] !== $RC_USER_ID) rc_error('Only the person who sent it in, or a bookkeeper, can have it read.', 403);
if ($row['b_id']) rc_error('A bill is already made from this receipt — change the bill instead.', 409);
if ($row['ocr_status'] !== 'pending' && empty($in['force'])) {
    rc_json(['read' => ['ok' => $row['ocr_status'] === 'parsed', 'engine' => $row['read_engine'], 'error' => $row['read_error'], 'again' => false],
             'receipt' => RcBills::shapeRow($DB, $row)]);
}
rc_rate_limit('rc-read', 60, 60);
@set_time_limit(150);
ignore_user_abort(true);
$res = RcReader::readAndStore($DB, $RC_COMPANY_ID, $RC_USER_ID, $row, RcKit::settings($DB, $RC_COMPANY_ID));
$row = RcBills::row($DB, $RC_COMPANY_ID, $id);
rc_json(['read' => ['ok' => $res['ok'], 'engine' => $res['engine'], 'error' => $res['error'], 'again' => true,
                    'vendor' => $res['fields']['vendor_name'], 'total' => $res['fields']['total'], 'date' => $res['fields']['invoice_date']],
         'receipt' => RcBills::shapeRow($DB, $row)]);

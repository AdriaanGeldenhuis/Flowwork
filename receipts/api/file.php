<?php
// /receipts/api/file.php?id=…[&thumb=1][&download=1] — a slip (or its preview),
// to the person who may see it. Slips are never served straight from disk.
require_once __DIR__ . '/_bootstrap.php';

$row = RcBills::need($DB, $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, rc_int($_GET['id'] ?? 0));
RcFiles::send($row, !empty($_GET['thumb']), !empty($_GET['download']));

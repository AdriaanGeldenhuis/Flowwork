<?php
// /qi/api/view.php — the document page's data (read only).
//   GET ?type=&id=                        → QiView::payload (paper html, summary, rail, allowed actions)
//   GET ?action=compose&kind=&id=         → editable email defaults (recipients, subject, message, mailboxes)
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../lib/QiView.php';

$type = qi_str($_GET, 'type', 20);
$id = qi_int($_GET, 'id');
$action = qi_str($_GET, 'action', 20) ?: 'load';

if ($action === 'compose') {
    qi_require_writer();
    $kind = qi_str($_GET, 'kind', 20);
    $c = QiMail::compose($DB, $QI_COMPANY_ID, $QI_USER_ID, $kind, $id);
    if ($c === null) qi_error('Document not found', 404);
    qi_json(['compose' => $c]);
}

if (!in_array($type, ['quote', 'invoice', 'credit_note', 'recurring'], true)) qi_error('Unknown document type');
$p = QiView::payload($DB, $QI_COMPANY_ID, $QI_USER_ID, $QI_ROLE, $type, $id);
if ($p === null) qi_error('Document not found', 404);
qi_json($p);

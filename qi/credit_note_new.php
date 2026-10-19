<?php
// /qi/credit_note_new.php — kept so existing links keep working: a new credit note now lives in /qi/edit.php.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
$q = ['type' => 'credit_note'];
if (!empty($_GET['invoice_id'])) $q['invoice_id'] = (int)$_GET['invoice_id'];
header('Location: /qi/edit.php?' . http_build_query($q), true, 302);

<?php
// /qi/invoice_new.php — kept so existing links keep working: a new invoice now lives in /qi/edit.php.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
$q = ['type' => 'invoice'];
if (!empty($_GET['edit'])) $q['id'] = (int)$_GET['edit'];
foreach (['customer_id', 'project_id'] as $k) if (!empty($_GET[$k])) $q[$k] = (int)$_GET[$k];
header('Location: /qi/edit.php?' . http_build_query($q), true, 302);

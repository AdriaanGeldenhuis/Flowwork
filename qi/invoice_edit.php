<?php
// /qi/invoice_edit.php — kept so existing links keep working: editing an invoice now lives in /qi/edit.php.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
$id = (int)($_GET['id'] ?? 0);
header('Location: /qi/edit.php?type=invoice' . ($id > 0 ? '&id=' . $id : ''), true, 302);

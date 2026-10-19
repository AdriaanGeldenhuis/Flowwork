<?php
// /crm/account_new.php?type=customer|supplier — old address for "new account"
// (Receipts still links here). Accounts are now added in the CRM itself.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
$type = ($_GET['type'] ?? 'supplier') === 'customer' ? 'customer' : 'supplier';
header('Location: /crm/?new=' . $type . '#' . $type . 's');
exit;

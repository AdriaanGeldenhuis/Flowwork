<?php
// /finances/ar/invoices_list.php
// Superseded by the Accounts Receivable workspace at /finances/ar/, which
// lists invoices inline. This standalone page was not linked anywhere in the
// app; it redirects permanently to the maintained workspace.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Location: /finances/ar/', true, 301);
exit;

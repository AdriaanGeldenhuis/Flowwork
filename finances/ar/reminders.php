<?php
// /finances/ar/reminders.php
// Superseded by the Accounts Receivable workspace at /finances/ar/ (its
// "Payment Reminders" tab lists overdue invoices inline). This standalone page
// was not linked anywhere in the app; it redirects permanently to the workspace.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Location: /finances/ar/', true, 301);
exit;

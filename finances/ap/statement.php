<?php
// /finances/ap/statement.php
// Superseded by the Accounts Payable workspace at /finances/ap/ (its
// "Statements" tab generates supplier statements inline). This standalone page
// was not linked anywhere in the app; it redirects permanently to the workspace.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Location: /finances/ap/', true, 301);
exit;

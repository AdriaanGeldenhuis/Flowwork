<?php
// /finances/ap/bills_list.php
// Superseded by the Accounts Payable workspace at /finances/ap/ (its "Bills"
// tab lists supplier bills inline). This standalone page was reachable only as
// a stale back-button target; it redirects permanently to the workspace.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Location: /finances/ap/', true, 301);
exit;

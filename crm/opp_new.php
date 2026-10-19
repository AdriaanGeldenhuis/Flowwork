<?php
// /crm/opp_new.php[?account_id=…] — old address for "new deal".
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
$accountId = (int)($_GET['account_id'] ?? 0);
header('Location: ' . ($accountId > 0 ? '/crm/account_view.php?id=' . $accountId . '#deals' : '/crm/?new=deal#pipeline'));
exit;

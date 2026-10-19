<?php
// /crm/account_edit.php?id=… — old address; the account page edits in place.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
$id = (int)($_GET['id'] ?? 0);
header('Location: ' . ($id > 0 ? '/crm/account_view.php?id=' . $id . '&edit=1' : '/crm/'));
exit;

<?php
// /qi/quote_view.php — kept so existing links keep working: a quote now lives in /qi/view.php.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
$id = (int)($_GET['id'] ?? 0);
header('Location: ' . ($id > 0 ? '/qi/view.php?type=quote&id=' . $id : '/qi/#quotes'), true, 302);

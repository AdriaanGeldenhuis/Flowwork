<?php
// /qi/recurring.php — kept so existing links keep working: the recurring invoices list now lives in /qi/#recurring.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
header('Location: /qi/#recurring', true, 302);

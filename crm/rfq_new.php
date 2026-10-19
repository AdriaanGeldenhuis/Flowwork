<?php
// /crm/rfq_new.php[?supplier_id=…] — old address. A request for quote is now
// an email from the supplier's page (New › Request for quote), or a purchase
// order in Procurement.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
$supplierId = (int)($_GET['supplier_id'] ?? $_GET['account_id'] ?? 0);
header('Location: ' . ($supplierId > 0 ? '/crm/account_view.php?id=' . $supplierId : '/crm/#suppliers'));
exit;

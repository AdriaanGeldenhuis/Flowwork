<?php
// /crm/invoice_view.php
// The invoice viewer lives in the QI module; this shim keeps old bookmarks working.
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: /qi/invoice_view.php' . ($qs !== '' ? '?' . $qs : ''));
exit;

<?php
// /payroll/api/payslip.php — one payslip as a PDF.
//   GET ?run=&employee=[&download=1]
// Finalised runs serve the stored payslip; runs being prepared are drawn on
// the fly with a DRAFT band. Admins and bookkeepers only (checked in _bootstrap).
require_once __DIR__ . '/_bootstrap.php';

[$bytes, $name] = PayPayslips::pdf($DB, $PAY_COMPANY_ID, pay_int($_GET['run'] ?? 0), pay_int($_GET['employee'] ?? 0));
header_remove('Content-Type');
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . $name . '"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, no-store');
echo $bytes;
exit;

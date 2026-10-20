<?php
// /payroll/payslips.php — payslips now live on the run itself (open a person
// for theirs; Email payslips for everyone).
$id = (int)($_GET['run_id'] ?? 0);
header('Location: ' . ($id ? '/payroll/run_view.php?id=' . $id : '/payroll/runs.php'), true, 302);
exit;

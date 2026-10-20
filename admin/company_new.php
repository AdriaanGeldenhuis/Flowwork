<?php
// /admin/company_new.php — adding a company is now a dialog on Your companies.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
header('Location: /admin/companies.php?new=1', true, 302);

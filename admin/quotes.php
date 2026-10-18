<?php
// /admin/quotes.php — Quotes & Invoices settings moved to the module itself
// (numbering, defaults, banking, email wording): /qi/settings.php. The values
// this page used to store were never read by document numbering.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
header('Location: /qi/settings.php#numbering', true, 302);

<?php
// /suppliers_ai/lib/SaBoot.php — every signed-in Suppliers AI page starts here:
// the active company, the person's role and what it allows, the settings (as
// the browser may see them) and the schema. Sets $SA_COMPANY_ID, $SA_USER_ID,
// $SA_ROLE, $SA_CAPS, $SA_COMPANY, $SA_SETTINGS (keys masked) and $e.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../includes/companies.php';
require_once __DIR__ . '/Sa.php';

$SA_COMPANY_ID = (int)$_SESSION['company_id'];
$SA_USER_ID = (int)$_SESSION['user_id'];
$SA_ROLE = SaKit::role((string)($_SESSION['role'] ?? 'viewer'));
$SA_CAPS = SaKit::caps($SA_ROLE);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$DB->exec("SET time_zone = '" . date('P') . "'"); // as Mail does: reply times are compared with Mail's
SaSchema::ensure($DB);
$SA_COMPANY = SaKit::company($DB, $SA_COMPANY_ID);
$SA_SETTINGS = SaKit::publicSettings(SaKit::settings($DB, $SA_COMPANY_ID));

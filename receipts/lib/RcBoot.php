<?php
// /receipts/lib/RcBoot.php — every Receipts page starts here: signed in, the
// active company, the person's role and what it allows, the settings and the
// schema. Sets $RC_COMPANY_ID, $RC_USER_ID, $RC_ROLE, $RC_CAPS, $RC_COMPANY,
// $RC_SETTINGS and $e.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../includes/companies.php';
require_once __DIR__ . '/Rc.php';

$RC_COMPANY_ID = (int)$_SESSION['company_id'];
$RC_USER_ID = (int)$_SESSION['user_id'];
$RC_ROLE = RcKit::role((string)($_SESSION['role'] ?? 'viewer'));
$RC_CAPS = RcKit::caps($RC_ROLE);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$st = $DB->prepare("SELECT id, name FROM companies WHERE id = ?");
$st->execute([$RC_COMPANY_ID]);
$RC_COMPANY = $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => $RC_COMPANY_ID, 'name' => 'Your company'];
RcSchema::ensure($DB);
$RC_SETTINGS = RcKit::settings($DB, $RC_COMPANY_ID);

<?php
// /shopping/lib/ShBoot.php — every signed-in Shopping page starts here: the
// active company, the person's role and what it allows, their preferences and
// the schema. Sets $SH_COMPANY_ID, $SH_USER_ID, $SH_ROLE, $SH_CAPS, $SH_COMPANY,
// $SH_PREFS and $e.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../includes/companies.php';
require_once __DIR__ . '/Sh.php';

$SH_COMPANY_ID = (int)$_SESSION['company_id'];
$SH_USER_ID = (int)$_SESSION['user_id'];
$SH_ROLE = ShKit::role((string)($_SESSION['role'] ?? 'viewer'));
$SH_CAPS = ShKit::caps($SH_ROLE);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$st = $DB->prepare("SELECT id, name FROM companies WHERE id = ?");
$st->execute([$SH_COMPANY_ID]);
$SH_COMPANY = $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => $SH_COMPANY_ID, 'name' => 'Your company'];
ShSchema::ensure($DB);
$SH_PREFS = ShKit::prefs($DB, $SH_COMPANY_ID, $SH_USER_ID);

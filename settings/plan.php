<?php
// /settings/plan.php — plan and billing now live in the admin console.
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';
header('Location: /admin/billing.php', true, 302);

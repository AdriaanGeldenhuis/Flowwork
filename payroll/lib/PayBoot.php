<?php
// /payroll/lib/PayBoot.php — every Payroll page starts here: signed in, and an
// admin or bookkeeper of the active company (payroll holds salaries, ID and
// bank numbers). Sets $PAY_COMPANY_ID, $PAY_USER_ID, $PAY_ROLE, $PAY_COMPANY,
// $PAY_SETTINGS and $e. Anyone else gets a polite "payroll is private" page.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../includes/companies.php';
require_once __DIR__ . '/Pay.php';

$PAY_COMPANY_ID = (int)$_SESSION['company_id'];
$PAY_USER_ID = (int)$_SESSION['user_id'];
$PAY_ROLE = strtolower((string)($_SESSION['role'] ?? 'viewer'));
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$st = $DB->prepare("SELECT id, name FROM companies WHERE id = ?");
$st->execute([$PAY_COMPANY_ID]);
$PAY_COMPANY = $st->fetch(PDO::FETCH_ASSOC) ?: ['id' => $PAY_COMPANY_ID, 'name' => 'Your company'];

if (!PayKit::canUse($PAY_ROLE)) {
    http_response_code(403);
    $page = 'denied';
    $pageTitle = 'Payroll is private';
    $bare = true;
    include __DIR__ . '/../partials/head.php';
    ?>
    <div class="fwm-empty" style="min-height:70vh">
        <span class="fwm-empty__art"><svg class="i"><use href="#i-lock"/></svg></span>
        <p class="fwm-empty__eyebrow"><?= $e($PAY_COMPANY['name']) ?></p>
        <h3>Payroll is private</h3>
        <p>Salaries, ID numbers and bank details are only open to your company’s admins and bookkeepers. Ask an admin if you need something from Payroll.</p>
        <a class="btn btn--primary" href="/home.php"><svg class="i"><use href="#i-home"/></svg>Back to Flowwork</a>
    </div>
    <?php
    include __DIR__ . '/../partials/foot.php';
    exit;
}
PaySchema::ensure($DB);
$PAY_SETTINGS = PayKit::settings($DB, $PAY_COMPANY_ID);

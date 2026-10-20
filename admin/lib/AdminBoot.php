<?php
// /admin/lib/AdminBoot.php — every admin page starts here: signed in, and an
// admin of the active company. Sets $ADMIN_COMPANY_ID, $ADMIN_USER_ID,
// $ADMIN_COMPANY (row with plan) and loads the admin helpers. Anyone else
// gets a polite "admins only" page instead of the console.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../includes/companies.php';
require_once __DIR__ . '/AdminKit.php';
require_once __DIR__ . '/AdminSettings.php';

$ADMIN_COMPANY_ID = (int)$_SESSION['company_id'];
$ADMIN_USER_ID = (int)$_SESSION['user_id'];
$ADMIN_COMPANY = AdminKit::company($DB, $ADMIN_COMPANY_ID);
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if (!fw_active_user_is_admin()) {
    http_response_code(403);
    $page = 'denied';
    $pageTitle = 'Admins only';
    $bare = true;
    include __DIR__ . '/../partials/head.php';
    ?>
    <div class="fwm-empty" style="min-height:70vh">
        <span class="fwm-empty__art"><svg class="i"><use href="#i-shield"/></svg></span>
        <p class="fwm-empty__eyebrow"><?= AdminKit::e($ADMIN_COMPANY['name'] ?? '') ?></p>
        <h3>This part is for administrators</h3>
        <p>Company settings, people and billing are looked after by your company’s admins. Ask one of them if you need something changed.</p>
        <a class="btn btn--primary" href="/home.php"><svg class="i"><use href="#i-home"/></svg>Back to Flowwork</a>
    </div>
    <?php
    include __DIR__ . '/../partials/foot.php';
    exit;
}

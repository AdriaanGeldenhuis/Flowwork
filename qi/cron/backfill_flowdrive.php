<?php
// /qi/cron/backfill_flowdrive.php
//
// Re-runnable backfill: renders the PDF for every existing invoice, quote and
// credit note, stores it under storage/qi/{company}/..., and publishes
// everything — including existing CRM compliance uploads — into FlowWork
// Drive so each customer/supplier folder is complete. Also hides the legacy
// seeded folder skeleton and rescues compliance files stranded by the old
// portal upload path bug. Idempotent.
//
// NOTE: normally you do NOT need to run this — the same sweep runs itself the
// first time the QI dashboard is opened after deployment (see
// FlowDriveBackfill::autoRunOnce). This URL/CLI entry point exists for
// re-runs and server-wide runs.
//
// Run from the shell (all companies):  php qi/cron/backfill_flowdrive.php
// Or in the browser as a logged-in admin/owner: /qi/cron/backfill_flowdrive.php
// (browser runs are scoped to YOUR company only).

require_once __DIR__ . '/../../init.php';

$isCli = (php_sapi_name() === 'cli');
$onlyCompanyId = null;
if (!$isCli) {
    require_once __DIR__ . '/../../auth_gate.php';
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, ['admin', 'owner'], true)) {
        http_response_code(403);
        exit('Admin access required');
    }
    // $_SESSION['role'] is a PER-COMPANY role: a tenant admin must only ever
    // backfill (and see output about) their own company. Cross-company runs
    // are CLI-only (server operator).
    $onlyCompanyId = (int)($_SESSION['company_id'] ?? 0);
    if ($onlyCompanyId <= 0) {
        http_response_code(403);
        exit('No active company');
    }
    header('Content-Type: text/plain; charset=UTF-8');
}

require_once __DIR__ . '/../../includes/flowdrive/FlowDriveBackfill.php';

set_time_limit(0);
ignore_user_abort(true);

$counts = ['ok' => 0, 'fail' => 0];
$out = function (string $line) {
    echo $line . "\n";
    @flush();
};

try {
    if ($onlyCompanyId !== null) {
        $stmt = $DB->prepare("SELECT id, name FROM companies WHERE id = ?");
        $stmt->execute([$onlyCompanyId]);
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $companies = $DB->query("SELECT id, name FROM companies")->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($companies as $company) {
        $companyId = (int)$company['id'];
        $out("== Company {$companyId}: {$company['name']} ==");
        $c = FlowDriveBackfill::runForCompany($DB, $companyId, $out);
        $counts['ok']   += $c['ok'];
        $counts['fail'] += $c['fail'];
    }

    $out("");
    $out("Done. {$counts['ok']} published, {$counts['fail']} failed.");
} catch (Throwable $e) {
    error_log('backfill_flowdrive: ' . $e->getMessage());
    $out("FATAL: " . $e->getMessage());
}

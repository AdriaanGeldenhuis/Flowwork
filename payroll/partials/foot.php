<?php
// /payroll/partials/foot.php — closes the main pane opened by head.php, then
// the toasts host, the boot data and the scripts.
// Inputs: $pageScripts (optional list of /payroll/js/*.js names), $pageBoot (optional array merged into the boot data)
$jump = [];
if (!($bare ?? false)) {
    foreach ([['Overview', '/payroll/', 'if-overview'], ['Pay runs', '/payroll/runs.php', 'ip-run'], ['Employees', '/payroll/employees.php', 'i-users'],
              ['Pay items', '/payroll/payitems.php', 'i-list'], ['SARS & reports', '/payroll/reports.php', 'ip-sars'], ['Settings', '/payroll/settings.php', 'i-settings']] as [$n, $h, $i]) {
        $jump[] = ['name' => $n, 'href' => $h, 'icon' => $i, 'group' => 'Go to', 'hint' => '', 'kw' => '', 'page' => true];
    }
    try {
        $st = $DB->prepare("SELECT id, employee_no, first_name, last_name, job_title, termination_date FROM employees WHERE company_id = ? ORDER BY first_name, last_name LIMIT 600");
        $st->execute([$PAY_COMPANY_ID]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $left = $r['termination_date'] && $r['termination_date'] < date('Y-m-d');
            $jump[] = ['name' => trim($r['first_name'] . ' ' . $r['last_name']), 'href' => '/payroll/employees.php?open=' . (int)$r['id'], 'icon' => 'i-user', 'group' => 'People',
                       'hint' => trim($r['employee_no'] . ($r['job_title'] ? ' · ' . $r['job_title'] : '') . ($left ? ' · left' : '')), 'kw' => (string)$r['employee_no']];
        }
        $st = $DB->prepare("SELECT id, run_number, name, pay_date, status FROM pay_runs WHERE company_id = ? ORDER BY pay_date DESC LIMIT 40");
        $st->execute([$PAY_COMPANY_ID]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $jump[] = ['name' => $r['name'], 'href' => '/payroll/run_view.php?id=' . (int)$r['id'], 'icon' => 'ip-run', 'group' => 'Pay runs',
                       'hint' => $r['run_number'] . ' · paid ' . PayKit::date($r['pay_date']) . ' · ' . PayKit::STAGE_LABEL[PayKit::stage($r['status'])], 'kw' => (string)$r['run_number']];
        }
    } catch (Throwable $ex) {
        error_log('[payroll] jump: ' . $ex->getMessage());
    }
}
$companies = [];
try {
    foreach (fw_user_companies($PAY_USER_ID) as $c) $companies[] = ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'active' => (int)$c['id'] === $PAY_COMPANY_ID];
} catch (Throwable $ex) {
}
$payBoot = ($pageBoot ?? []) + ['companies' => $companies, 'jump' => $jump, 'role' => $PAY_ROLE, 'admin' => PayKit::canConfigure($PAY_ROLE)];
?>
                </div>
            </div>
        </main>
    </div>
</div>
<div class="fwm-toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<script id="payBoot" type="application/json"><?= json_encode($payBoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/payroll/js/payroll.js?v=<?= $payV('payroll/js/payroll.js') ?>"></script>
<?php foreach ($pageScripts ?? [] as $js): ?>
<script src="/payroll/js/<?= $e($js) ?>.js?v=<?= $payV('payroll/js/' . $js . '.js') ?>"></script>
<?php endforeach; ?>
<script src="/shared/company_switcher.js?v=<?= $payV('shared/company_switcher.js') ?>"></script>
</body>
</html>

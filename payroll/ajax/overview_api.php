<?php
// /payroll/ajax/overview_api.php
//
// Provides aggregated metrics for the Payroll dashboard. The endpoint
// collects counts and sums from various payroll tables so that the
// overview page can display KPI cards and charts with real data. All
// queries are filtered by company_id to prevent data leaks.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

// Validate company context
$companyId = $_SESSION['company_id'] ?? 0;
if (!$companyId) {
    echo json_encode(['ok' => false, 'error' => 'Invalid company context']);
    exit;
}

try {
    // ===== KPI 1: Active Employees =====
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM employees WHERE company_id = ? AND termination_date IS NULL"
    );
    $stmt->execute([$companyId]);
    $activeEmployees = (int) $stmt->fetchColumn();

    // ===== KPI 2: Open Pay Runs (draft, calculated, review) =====
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM pay_runs WHERE company_id = ? AND status IN ('draft','calculated','review')"
    );
    $stmt->execute([$companyId]);
    $openRuns = (int) $stmt->fetchColumn();

    // ===== KPI 3: Net Payroll of the Last Posted Run =====
    $stmt = $DB->prepare(
        "SELECT SUM(pre.net_cents) AS net_cents
         FROM pay_run_employees pre
         JOIN pay_runs pr ON pre.run_id = pr.id
         WHERE pr.company_id = ? AND pr.status = 'posted'
         ORDER BY pr.pay_date DESC LIMIT 1"
    );
    $stmt->execute([$companyId]);
    $lastRunRow = $stmt->fetch();
    $lastNetPayroll = ($lastRunRow['net_cents'] ?? 0) / 100;

    // ===== KPI 4: New Employees in the last 30 days =====
    $stmt = $DB->prepare(
        "SELECT COUNT(*) FROM employees WHERE company_id = ? AND hire_date >= CURDATE() - INTERVAL 30 DAY AND termination_date IS NULL"
    );
    $stmt->execute([$companyId]);
    $newEmployees = (int) $stmt->fetchColumn();

    // ===== Monthly Payroll Cost (last 12 months) =====
    $stmt = $DB->prepare(
        "SELECT DATE_FORMAT(pr.pay_date, '%Y-%m') AS ym, SUM(pre.net_cents)/100 AS total
         FROM pay_run_employees pre
         JOIN pay_runs pr ON pre.run_id = pr.id
         WHERE pr.company_id = ? AND pr.status = 'posted' AND pr.pay_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
         GROUP BY ym
         ORDER BY ym"
    );
    $stmt->execute([$companyId]);
    $costRaw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $labels  = [];
    $costs   = [];
    for ($i = 11; $i >= 0; $i--) {
        $date = new DateTime("-{$i} months");
        $ym   = $date->format('Y-m');
        $labels[] = $date->format('M');
        $costs[]  = isset($costRaw[$ym]) ? (float) $costRaw[$ym] : 0;
    }

    // ===== Monthly Headcount (last 12 months) =====
    // Calculate headcount at end of each month by checking hire and termination dates.
    $headcounts = [];
    foreach ($labels as $idx => $monthLabel) {
        $date = new DateTime("-" . (11 - $idx) . " months");
        // End of the month date string
        $monthEnd = $date->format('Y-m-t');
        $stmt = $DB->prepare(
            "SELECT COUNT(*) FROM employees
             WHERE company_id = ?
               AND hire_date <= ?
               AND (termination_date IS NULL OR termination_date > ?)"
        );
        $stmt->execute([$companyId, $monthEnd, $monthEnd]);
        $headcounts[] = (int) $stmt->fetchColumn();
    }

    // ===== Month-To-Date Payroll (actual vs target) =====
    // Actual: Sum of net pay of posted runs in the current month
    $stmt = $DB->prepare(
        "SELECT COALESCE(SUM(pre.net_cents), 0) / 100
         FROM pay_run_employees pre
         JOIN pay_runs pr ON pre.run_id = pr.id
         WHERE pr.company_id = ? AND pr.status = 'posted'
           AND pr.pay_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
           AND pr.pay_date <= CURDATE()"
    );
    $stmt->execute([$companyId]);
    $mtdActual = (float) $stmt->fetchColumn();

    // Target: Optional company setting (payroll_dashboard_monthly_target_cents)
    $mtdTarget = 0;
    try {
        $tStmt = $DB->prepare(
            "SELECT value FROM company_settings WHERE company_id = ? AND name = 'payroll_dashboard_monthly_target_cents'"
        );
        $tStmt->execute([$companyId]);
        $val = $tStmt->fetchColumn();
        if ($val !== false) {
            $mtdTarget = ((float) $val) / 100;
        }
    } catch (Exception $e) {
        $mtdTarget = 0;
    }

    // ===== Top Employees by Net Pay (last 30 days) =====
    $stmt = $DB->prepare(
        "SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee,
                SUM(pre.net_cents)/100 AS total,
                COUNT(*) AS runs
         FROM pay_run_employees pre
         JOIN pay_runs pr ON pr.id = pre.run_id
         JOIN employees e ON e.id = pre.employee_id
         WHERE pr.company_id = ? AND pr.status = 'posted'
           AND pr.pay_date >= CURDATE() - INTERVAL 30 DAY
         GROUP BY pre.employee_id, e.first_name, e.last_name
         ORDER BY total DESC
         LIMIT 8"
    );
    $stmt->execute([$companyId]);
    $topEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ===== Employment Type Breakdown =====
    $stmt = $DB->prepare(
        "SELECT COALESCE(employment_type, 'Unknown') AS type, COUNT(*) AS count
         FROM employees
         WHERE company_id = ?
         GROUP BY type"
    );
    $stmt->execute([$companyId]);
    $employmentTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ===== Runs Volume: posted vs open (last 30 days) =====
    $stmt = $DB->prepare(
        "SELECT 'Posted' AS name, COUNT(*) AS value
         FROM pay_runs
         WHERE company_id = ? AND status = 'posted' AND pay_date >= CURDATE() - INTERVAL 30 DAY
         UNION ALL
         SELECT 'Open' AS name, COUNT(*) AS value
         FROM pay_runs
         WHERE company_id = ? AND status IN ('draft','calculated','review') AND updated_at >= CURDATE() - INTERVAL 30 DAY"
    );
    $stmt->execute([$companyId, $companyId]);
    $runsVolume = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'kpis' => [
            'employees'       => $activeEmployees,
            'open_runs'       => $openRuns,
            'last_net_payroll'=> $lastNetPayroll,
            'new_employees'   => $newEmployees,
        ],
        'cost_monthly' => [
            'labels' => $labels,
            'series' => $costs,
        ],
        'headcount_monthly' => [
            'labels' => $labels,
            'series' => $headcounts,
        ],
        'mtd' => [
            'actual' => $mtdActual,
            'target' => $mtdTarget,
        ],
        'top_employees' => $topEmployees,
        'employment_types' => $employmentTypes,
        'runs_volume' => $runsVolume,
    ]);

} catch (Exception $e) {
    error_log('Payroll overview_api error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to load overview']);
}
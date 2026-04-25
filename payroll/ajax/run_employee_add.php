<?php
// /payroll/ajax/run_employee_add.php
// Add one or more employees to a pay run and calculate them via the calc engine.
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';
require_once __DIR__ . '/../../payroll/calc_engine.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];
$runId = (int)($_POST['run_id'] ?? 0);
$ids = $_POST['employee_ids'] ?? [];

if (!$runId) {
    echo json_encode(['ok' => false, 'error' => 'Missing run_id']);
    exit;
}

if (!is_array($ids)) {
    $ids = [$ids];
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

if (empty($ids)) {
    echo json_encode(['ok' => false, 'error' => 'No employees selected']);
    exit;
}

try {
    $DB->beginTransaction();

    $stmt = $DB->prepare("
        SELECT * FROM pay_runs
        WHERE id = ? AND company_id = ? AND status IN ('draft', 'calculated', 'review')
    ");
    $stmt->execute([$runId, $companyId]);
    $run = $stmt->fetch();

    if (!$run) {
        $DB->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Run not found or not editable']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$companyId, $run['frequency']], $ids);
    $stmt = $DB->prepare("
        SELECT * FROM employees
        WHERE company_id = ?
          AND pay_frequency = ?
          AND termination_date IS NULL
          AND id IN ($placeholders)
    ");
    $stmt->execute($params);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($employees) === 0) {
        $DB->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Selected employees do not match this run']);
        exit;
    }

    $engine = new PayrollCalcEngine($DB, $companyId);
    $engine->loadTaxTables($run['period_start']);
    $engine->loadPayitems();

    $addedCount = 0;

    foreach ($employees as $employee) {
        $stmt = $DB->prepare("
            SELECT id FROM pay_run_employees
            WHERE run_id = ? AND employee_id = ?
        ");
        $stmt->execute([$runId, $employee['id']]);
        if ($stmt->fetch()) {
            continue;
        }

        $result = $engine->calculateEmployee($employee, $run);

        $stmt = $DB->prepare("
            INSERT INTO pay_run_employees (
                company_id, run_id, employee_id,
                gross_cents, taxable_income_cents, paye_cents,
                uif_employee_cents, uif_employer_cents, sdl_cents,
                other_deductions_cents, reimbursements_cents, net_cents,
                employer_cost_cents, bank_amount_cents, calc_debug_json
            ) VALUES (
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?
            )
        ");
        $stmt->execute([
            $companyId, $runId, $employee['id'],
            $result['gross_cents'],
            $result['taxable_income_cents'],
            $result['paye_cents'],
            $result['uif_employee_cents'],
            $result['uif_employer_cents'],
            $result['sdl_cents'],
            $result['other_deductions_cents'],
            $result['reimbursements_cents'],
            $result['net_cents'],
            $result['employer_cost_cents'],
            $result['bank_amount_cents'],
            json_encode($result['debug'])
        ]);

        foreach ($result['lines'] as $line) {
            $stmt = $DB->prepare("
                INSERT INTO pay_run_lines (
                    company_id, run_id, employee_id, payitem_id,
                    description, qty, rate_cents, amount_cents,
                    project_id, board_id, item_id, is_adhoc
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?
                )
            ");
            $stmt->execute([
                $companyId, $runId, $employee['id'], $line['payitem_id'],
                $line['description'], $line['qty'], $line['rate_cents'], $line['amount_cents'],
                $line['project_id'] ?? null,
                $line['board_id'] ?? null,
                $line['item_id'] ?? null,
                $line['is_adhoc'] ?? 0
            ]);
        }

        $addedCount++;
    }

    if ($run['status'] === 'draft' && $addedCount > 0) {
        $stmt = $DB->prepare("UPDATE pay_runs SET status = 'calculated', updated_at = NOW() WHERE id = ?");
        $stmt->execute([$runId]);
    }

    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'payrun_employees_added', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['run_id' => $runId, 'added' => $addedCount, 'employee_ids' => $ids]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'count' => $addedCount
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Run employee add error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Add failed: ' . $e->getMessage()]);
}

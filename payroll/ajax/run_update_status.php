<?php
// /payroll/ajax/run_update_status.php – updated for Section 10
// This version includes payslip generation when a pay run is locked or posted.  It uses
// the PayslipGenerator helper to create simple HTML payslips for each employee and
// stores the relative paths on pay_run_employees.  Auto-posting to finance still
// functions as before.

require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId    = $_SESSION['user_id'];
$runId     = $_POST['run_id'] ?? 0;
$newStatus = $_POST['status'] ?? '';

if (!$runId || !$newStatus) {
    echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
    exit;
}

$allowedStatuses = ['review', 'approved', 'locked', 'posted'];
if (!in_array($newStatus, $allowedStatuses)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid status']);
    exit;
}

try {
    $DB->beginTransaction();

    // Load payroll settings to check for auto-posting
    $stmt = $DB->prepare("SELECT auto_post_to_finance FROM payroll_settings WHERE company_id = ?");
    $stmt->execute([$companyId]);
    $payrollSettings = $stmt->fetch();
    $autoPostToFinance = isset($payrollSettings['auto_post_to_finance']) ? (int)$payrollSettings['auto_post_to_finance'] : 0;

    // PostingService needed for finance postings
    require_once __DIR__ . '/../../finances/lib/PostingService.php';

    // Fetch current run
    $stmt = $DB->prepare("SELECT * FROM pay_runs WHERE id = ? AND company_id = ?");
    $stmt->execute([$runId, $companyId]);
    $run = $stmt->fetch();

    if (!$run) {
        echo json_encode(['ok' => false, 'error' => 'Run not found']);
        exit;
    }

    // Validate transition based on current status
    $validTransitions = [
        'calculated' => ['review'],
        'review'     => ['approved'],
        'approved'   => ['locked'],
        'locked'     => ['posted']
    ];
    if (!isset($validTransitions[$run['status']]) || !in_array($newStatus, $validTransitions[$run['status']])) {
        echo json_encode(['ok' => false, 'error' => 'Invalid status transition from ' . $run['status'] . ' to ' . $newStatus]);
        exit;
    }

    // Prepare update fields
    $updateFields = ['status = ?', 'updated_at = NOW()'];
    $params = [$newStatus];

    if ($newStatus === 'approved') {
        $updateFields[] = 'approved_by = ?';
        $updateFields[] = 'approved_at = NOW()';
        $params[] = $userId;
    } elseif ($newStatus === 'locked') {
        $updateFields[] = 'locked_by = ?';
        $updateFields[] = 'locked_at = NOW()';
        $params[] = $userId;
    } elseif ($newStatus === 'posted') {
        $updateFields[] = 'posted_by = ?';
        $updateFields[] = 'posted_at = NOW()';
        $params[] = $userId;
    }

    // Final param is the run id for WHERE clause
    $params[] = $runId;

    $sql = 'UPDATE pay_runs SET ' . implode(', ', $updateFields) . ' WHERE id = ?';
    $stmt = $DB->prepare($sql);
    $stmt->execute($params);

    // Audit the change
    $stmt = $DB->prepare(
        "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp) VALUES (?, ?, 'payrun_status_changed', ?, ?, NOW())"
    );
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['run_id' => $runId, 'from' => $run['status'], 'to' => $newStatus]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    // Commit status change
    $DB->commit();

    // After committing, generate payslips when locking or posting
    if (in_array($newStatus, ['locked', 'posted'], true)) {
        try {
            require_once __DIR__ . '/../lib/PayslipGenerator.php';
            generatePayslips($DB, (int)$companyId, (int)$runId);
        } catch (Exception $psEx) {
            error_log('Payslip generation error: ' . $psEx->getMessage());
        }
    }

    // Handle auto-post logic: if locked and auto-post is enabled
    if ($newStatus === 'locked' && $autoPostToFinance) {
        try {
            // Post payroll run to finance
            $postingService = new PostingService($DB, (int)$companyId, (int)$userId);
            $postingService->postPayrollRun((int)$runId);
            // Update run to posted (outside transaction)
            $DB->beginTransaction();
            $stmt = $DB->prepare(
                "UPDATE pay_runs SET status = 'posted', posted_by = ?, posted_at = NOW(), updated_at = NOW() WHERE id = ? AND company_id = ?"
            );
            $stmt->execute([$userId, $runId, $companyId]);
            // Audit autopost
            $stmt = $DB->prepare(
                "INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp) VALUES (?, ?, 'payrun_autopost', ?, ?, NOW())"
            );
            $stmt->execute([
                $companyId,
                $userId,
                json_encode(['run_id' => $runId]),
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);
            $DB->commit();
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            $DB->rollBack();
            error_log('Auto-post payroll run error: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Auto-posting payroll failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // Manual posting: when explicitly moving to posted status
    if ($newStatus === 'posted') {
        try {
            $postingService = new PostingService($DB, (int)$companyId, (int)$userId);
            $postingService->postPayrollRun((int)$runId);
            echo json_encode(['ok' => true]);
        } catch (Exception $e) {
            error_log('Payroll post error: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Posting to finance failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // Default response for review or approved statuses
    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    // If any exception thrown, roll back and return error
    $DB->rollBack();
    error_log('Status update error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Update failed']);
}
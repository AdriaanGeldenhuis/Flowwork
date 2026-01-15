<?php
// /payroll/ajax/run_create.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$name = trim($_POST['name'] ?? '');
$frequency = $_POST['frequency'] ?? 'monthly';
$periodStart = $_POST['period_start'] ?? '';
$periodEnd = $_POST['period_end'] ?? '';
$payDate = $_POST['pay_date'] ?? '';
$notes = trim($_POST['notes'] ?? '');

// Validation
if (empty($name)) {
    echo json_encode(['ok' => false, 'error' => 'Run name required']);
    exit;
}
if (empty($periodStart) || empty($periodEnd) || empty($payDate)) {
    echo json_encode(['ok' => false, 'error' => 'All dates required']);
    exit;
}

try {
    $DB->beginTransaction();

    // Generate run number
    $stmt = $DB->prepare("
        SELECT COUNT(*) FROM pay_runs WHERE company_id = ? AND YEAR(pay_date) = YEAR(?)
    ");
    $stmt->execute([$companyId, $payDate]);
    $count = $stmt->fetchColumn();
    $runNumber = date('Y', strtotime($payDate)) . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);

    // Anchor ref
    $anchorRef = date('Y-m', strtotime($periodStart)) . ' ' . $frequency;

    // Insert run
    $stmt = $DB->prepare("
        INSERT INTO pay_runs (
            company_id, run_number, name, frequency, 
            period_start, period_end, pay_date, anchor_ref,
            status, notes, created_by, created_at
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            'draft', ?, ?, NOW()
        )
    ");
    $stmt->execute([
        $companyId, $runNumber, $name, $frequency,
        $periodStart, $periodEnd, $payDate, $anchorRef,
        $notes, $userId
    ]);
    $runId = $DB->lastInsertId();

    // Audit
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'payrun_created', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId, $userId,
        json_encode(['run_id' => $runId, 'name' => $name, 'period' => "$periodStart to $periodEnd"]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'id' => $runId
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("Run create error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Failed to create run']);
}
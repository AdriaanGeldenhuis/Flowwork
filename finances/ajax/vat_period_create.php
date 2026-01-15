<?php
// /finances/ajax/vat_period_create.php
require_once __DIR__ . '/../../init.php';
require_once __DIR__ . '/../../auth_gate.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid request method']);
    exit;
}

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);

$periodStart = $input['period_start'] ?? null;
$periodEnd = $input['period_end'] ?? null;

if (!$periodStart || !$periodEnd) {
    echo json_encode(['ok' => false, 'error' => 'Both dates required']);
    exit;
}

try {
    $DB->beginTransaction();

    // Check for overlapping periods
    $stmt = $DB->prepare("
        SELECT COUNT(*) FROM gl_vat_periods 
        WHERE company_id = ?
        AND (
            (period_start <= ? AND period_end >= ?)
            OR (period_start <= ? AND period_end >= ?)
            OR (period_start >= ? AND period_end <= ?)
        )
    ");
    $stmt->execute([
        $companyId,
        $periodStart, $periodStart,
        $periodEnd, $periodEnd,
        $periodStart, $periodEnd
    ]);

    if ($stmt->fetchColumn() > 0) {
        throw new Exception('This period overlaps with an existing VAT period');
    }

    $stmt = $DB->prepare("
        INSERT INTO gl_vat_periods (
            company_id, period_start, period_end, status,
            output_vat_cents, input_vat_cents, net_vat_cents,
            created_by, created_at
        ) VALUES (?, ?, ?, 'open', 0, 0, 0, ?, NOW())
    ");
    $stmt->execute([$companyId, $periodStart, $periodEnd, $userId]);

    $periodId = $DB->lastInsertId();

    // Audit log
    $stmt = $DB->prepare("
        INSERT INTO audit_log (company_id, user_id, action, details, ip, timestamp)
        VALUES (?, ?, 'vat_period_created', ?, ?, NOW())
    ");
    $stmt->execute([
        $companyId,
        $userId,
        json_encode(['period_id' => $periodId, 'start' => $periodStart, 'end' => $periodEnd]),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $DB->commit();

    echo json_encode([
        'ok' => true,
        'data' => ['period_id' => $periodId]
    ]);

} catch (Exception $e) {
    $DB->rollBack();
    error_log("VAT period create error: " . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_respond.php';

$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;

if ($projectId) {
    require_project_role($projectId, 'viewer');
    
    $stmt = $DB->prepare("
        SELECT i.*, c.name as client_name
        FROM invoices i
        LEFT JOIN companies c ON i.client_id = c.id
        WHERE i.project_id = ? AND i.company_id = ?
        ORDER BY i.created_at DESC
    ");
    $stmt->execute([$projectId, $COMPANY_ID]);
} else {
    if ($USER_ROLE !== 'admin' && $USER_ROLE !== 'manager') {
        respond_error('Access denied', 403);
    }
    
    $stmt = $DB->prepare("
        SELECT i.*, p.name as project_name, c.name as client_name
        FROM invoices i
        LEFT JOIN projects p ON i.project_id = p.project_id
        LEFT JOIN companies c ON i.client_id = c.id
        WHERE i.company_id = ?
        ORDER BY i.created_at DESC
    ");
    $stmt->execute([$COMPANY_ID]);
}

$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format amounts
foreach ($invoices as &$inv) {
    $inv['total'] = $inv['total_cents'] / 100;
    $inv['paid'] = $inv['paid_cents'] / 100;
    $inv['balance'] = ($inv['total_cents'] - $inv['paid_cents']) / 100;
}

respond_ok(['invoices' => $invoices]);